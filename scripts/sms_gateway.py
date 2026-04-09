#!/usr/bin/env python3
"""
Poll your hosted School RFID app for pending parent SMS jobs and send them via
SIM800C USB modem(s) using AT commands. Works on Windows (laptop), Linux, or
any OS supported by pyserial.

Install:
  pip install pyserial

Windows (PowerShell) — find the port in Device Manager (e.g. COM5):
  $env:SMS_QUEUE_URL="http://localhost:8000/public/worker/sms_queue.php"
  $env:SMS_TENANT_SLUG="default-school"
  $env:SMS_POLL_SECRET="from admin Parent SMS page"
  $env:SMS_SERIAL_PORTS="COM8"
  $env:SMS_BAUD="115200"
  python scripts/sms_gateway.py

If jobs stay "pending" in the admin screen, this script is not running or cannot
reach the API / open the COM port. Set SMS_VERBOSE=1 for every poll line.

Optional: SMS_SKIP_MODEM=1 — only test API (peek); does not send SMS or open serial.

If sends fail with ERROR after CMGS, typical fixes:
  - CH340 USB boards: leave DTR/RTS low (default). Try SMS_OPEN_DELAY_SEC=2 if the module resets on open.
  - Set SMS center for your carrier: SMS_SMSC="+63..." (get the exact number from your SIM provider)
  - Try SMS_BAUD=9600 only if 115200 gives garbage; SIM800 usually defaults to 115200 (9600 often yields empty AT replies).
  - Ensure SIM has signal (antenna), no PIN lock, load/airtime for SMS
  - Some boards: try SMS_CMGS_NO_PLUS=1 (number as 639XXXXXXXXX inside quotes)

Linux (two modems, round-robin):
  export SMS_SERIAL_PORTS="/dev/ttyUSB0,/dev/ttyUSB1"
  python3 scripts/sms_gateway.py

If the modem does not respond, try SMS_BAUD=9600.

GSM: use basic Latin in templates; long texts may split into multiple SMS on the network.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import ssl
import sys
import time
from pathlib import Path
import urllib.error
import urllib.request
from typing import Any, Optional

try:
    import serial
except ImportError:
    print("Install pyserial: pip install pyserial", file=sys.stderr)
    sys.exit(1)


def normalize_queue_url(url: str) -> str:
    """Avoid Windows localhost -> ::1 when Apache (XAMPP) only listens on IPv4 127.0.0.1."""
    from urllib.parse import urlparse, urlunparse

    u = url.strip()
    if not u:
        return u
    p = urlparse(u)
    if not p.scheme or not p.hostname:
        return u
    if p.hostname.lower() != "localhost":
        return u
    host = "127.0.0.1" if not p.port else f"127.0.0.1:{p.port}"
    if p.username is not None:
        auth = p.username
        if p.password:
            auth += ":" + p.password
        netloc = f"{auth}@{host}"
    else:
        netloc = host
    return urlunparse((p.scheme, netloc, p.path, p.params, p.query, p.fragment))


def env(name: str, default: str | None = None) -> str:
    v = os.environ.get(name, "").strip()
    if v:
        return v
    if default is not None:
        return default
    print(f"Missing environment variable: {name}", file=sys.stderr)
    sys.exit(1)


def truthy(name: str) -> bool:
    return os.environ.get(name, "").strip().lower() in ("1", "true", "yes", "on")


def apply_serial_line_settings(ser: serial.Serial) -> None:
    """CH340 + SIM800: toggling DTR/RTS can reset the module; default both low."""
    ser.dtr = truthy("SMS_DTR")
    ser.rts = truthy("SMS_RTS")


def at_read_bytes(
    ser: serial.Serial,
    timeout: float = 4.0,
    *,
    cmgs_prompt: bool = False,
) -> bytes:
    """
    Blocking read until OK/ERROR (normal AT) or \\r\\n> (CMGS prompt), or timeout.
    Uses short per-read timeout so we do not rely on in_waiting (fixes empty reads on Windows/CH340).
    """
    deadline = time.monotonic() + timeout
    buf = b""
    saved = ser.timeout
    ser.timeout = 0.15
    try:
        while time.monotonic() < deadline:
            chunk = ser.read(512)
            if chunk:
                buf += chunk
                if cmgs_prompt:
                    if b"\r\n>" in buf or b"\n>" in buf:
                        break
                else:
                    if (
                        b"OK\r\n" in buf
                        or buf.endswith(b"OK\r\n")
                        or b"\nOK\r\n" in buf
                        or b"ERROR\r\n" in buf
                        or b"\nERROR\r\n" in buf
                        or b"\r\nERROR" in buf
                    ):
                        break
        return buf
    finally:
        ser.timeout = saved


def at_read(ser: serial.Serial, timeout: float = 4.0, *, cmgs_prompt: bool = False) -> str:
    return at_read_bytes(ser, timeout, cmgs_prompt=cmgs_prompt).decode("utf-8", errors="replace")


def ensure_modem_baud(ser: serial.Serial, preferred: int) -> None:
    """If AT is silent at preferred rate, try 115200 / 9600 / others (SIM800 is usually 115200)."""
    if truthy("SMS_NO_AUTO_BAUD"):
        return
    order: list[int] = []
    for b in (preferred, 115200, 9600, 57600, 38400):
        if b not in order:
            order.append(b)
    for baud in order:
        ser.baudrate = baud
        time.sleep(0.25)
        ser.reset_input_buffer()
        ser.reset_output_buffer()
        ser.write(b"AT\r\n")
        raw = at_read_bytes(ser, timeout=3.0)
        if b"OK" in raw:
            if baud != preferred:
                print(
                    f"[modem] Auto-baud: modem answers at {baud} baud (SMS_BAUD was {preferred}). "
                    f"Set SMS_BAUD={baud} to skip probing.",
                    flush=True,
                )
            return
    print(
        f"[modem] WARNING: no OK from AT at bauds {order}. Wrong COM port, USB cable, or module still booting.",
        flush=True,
    )


def http_post_json(url: str, payload: dict[str, Any]) -> dict[str, Any]:
    body = json.dumps(payload).encode("utf-8")
    ctx = ssl.create_default_context()
    req = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={"Content-Type": "application/json; charset=utf-8"},
    )
    try:
        with urllib.request.urlopen(req, timeout=60, context=ctx) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", errors="replace")
        extra = ""
        try:
            j = json.loads(raw)
            if isinstance(j, dict) and j.get("message"):
                extra = f" — {j['message']}"
        except json.JSONDecodeError:
            if raw.strip():
                extra = f" — {raw.strip()[:400]}"
        raise ValueError(f"HTTP {e.code}{extra}") from e
    data = json.loads(raw)
    if not isinstance(data, dict):
        raise ValueError("API returned non-object JSON")
    return data


def read_cmgs_result(ser: serial.Serial, total_timeout: float = 90.0) -> str:
    """
    After sending Ctrl+Z to CMGS, the modem may inject URCs (+CREG, +CGREG, etc.)
    before +CMGS: / +CMS ERROR / OK. Do not stop on short idle after a URC only.
    """
    deadline = time.monotonic() + total_timeout
    buf = b""
    saved = ser.timeout
    ser.timeout = 0.12
    saw_cmgs_ref = False
    saw_cmgs_at = 0.0
    try:
        while time.monotonic() < deadline:
            buf += ser.read(512)
            d = buf.decode("utf-8", errors="replace")
            if "+CMS ERROR" in d:
                break
            if "+CME ERROR:" in d:
                break
            if re.search(r"\+CMGS:\s*\d+", d):
                saw_cmgs_ref = True
                saw_cmgs_at = time.monotonic()
                if "OK" in d:
                    break
            elif saw_cmgs_ref and (time.monotonic() - saw_cmgs_at) > 3.0:
                break
            time.sleep(0.02)
        return buf.decode("utf-8", errors="replace")
    finally:
        ser.timeout = saved


def at_read_all(ser: serial.Serial, total_timeout: float = 45.0, idle_ms: float = 0.35) -> str:
    """Read until idle (no bytes) for idle_ms, or total_timeout."""
    deadline = time.monotonic() + total_timeout
    buf = b""
    last_data = time.monotonic()
    saved = ser.timeout
    ser.timeout = 0.12
    try:
        while time.monotonic() < deadline:
            chunk = ser.read(256)
            if chunk:
                buf += chunk
                last_data = time.monotonic()
            elif buf and (time.monotonic() - last_data) >= idle_ms:
                break
            else:
                time.sleep(0.02)
        return buf.decode("utf-8", errors="replace")
    finally:
        ser.timeout = saved


def parse_cms_cme_errors(text: str) -> list[str]:
    out: list[str] = []
    for m in re.finditer(r"\+CMS ERROR:\s*([^\r\n]+)", text):
        tail = m.group(1).strip()
        if tail.isdigit():
            code = int(tail)
            out.append(f"+CMS ERROR: {code} ({_cms_meaning(code)})")
        else:
            low = tail.lower()
            hint = ""
            if "short message transfer rejected" in low or "rejected" in low:
                hint = " — carrier/network rejected MO-SMS (load/SMS plan, APN, or try SMSC/format)"
            out.append(f"+CMS ERROR: {tail}{hint}")
    for m in re.finditer(r"\+CME ERROR:\s*(\d+)", text):
        out.append(f"+CME ERROR: {m.group(1)}")
    return out


def _cms_meaning(code: int) -> str:
    # Common GSM 07.05 SMS failure codes (short hints)
    hints = {
        1: "unassigned number",
        8: "operator determined barring",
        21: "short message rejected",
        27: "destination out of service",
        28: "unidentified subscriber / no coverage",
        29: "facility rejected",
        30: "unknown subscriber",
        38: "memory capacity exceeded",
        50: "storage failed",
        69: "semantic error",
        81: "invalid transaction id",
        95: "invalid message",
        96: "invalid mandatory info",
        97: "message type non-existent",
        111: "protocol error",
        127: "interworking error",
    }
    return hints.get(code, "see modem manual / carrier")


def modem_prepare(ser: serial.Serial, smsc: str | None) -> None:
    """Run once after opening port: verbose errors, SIM/network check, optional SMSC."""
    ser.reset_input_buffer()
    ser.reset_output_buffer()
    ser.write(b"AT\r\n")
    at_read(ser)
    ser.write(b"AT+GMR\r\n")
    gmr = at_read(ser, timeout=3.0)
    if gmr.strip():
        print(f"[modem] AT+GMR {gmr.replace(chr(10), ' ').strip()!r}", flush=True)
    ser.write(b"AT+CMEE=2\r\n")
    at_read(ser)
    ser.write(b"AT+CMGF=1\r\n")
    at_read(ser)
    ser.write(b'AT+CSCS="GSM"\r\n')
    at_read(ser)
    # Preferred storage (some modules need this for MO SMS)
    ser.write(b'AT+CPMS="SM","SM","SM"\r\n')
    at_read(ser, timeout=3.0)
    if truthy("SMS_SET_CSMP"):
        ser.write(b"AT+CSMP=17,167,0,0\r\n")
        at_read(ser, timeout=3.0)
    if smsc:
        # e.g. AT+CSCA="+639170000130",145 — 145 = type for international address
        esc = smsc.replace("\\", "\\\\").replace('"', '\\"')
        ser.write(f'AT+CSCA="{esc}",145\r\n'.encode("ascii", errors="replace"))
        at_read(ser, timeout=5.0)

    ser.write(b"AT+CPIN?\r\n")
    pin_r = at_read(ser, timeout=5.0)
    if not pin_r.strip():
        print("[modem] WARNING AT+CPIN?: (empty) — check baud (use 115200 or enable auto-baud).", flush=True)
    elif "READY" not in pin_r:
        print(f"[modem] WARNING AT+CPIN?: {pin_r.strip()!r}", flush=True)

    ser.write(b"AT+CREG?\r\n")
    reg_r = at_read(ser, timeout=3.0)
    print(f"[modem] AT+CREG? {reg_r.replace(chr(10), ' ').strip()!r}", flush=True)

    ser.write(b"AT+CSQ\r\n")
    sq_r = at_read(ser, timeout=3.0)
    print(f"[modem] AT+CSQ {sq_r.replace(chr(10), ' ').strip()!r}", flush=True)


def modem_print_diagnostics(ser: serial.Serial) -> None:
    ser.reset_input_buffer()
    time.sleep(0.08)
    for cmd, label in (
        (b"AT+CPIN?\r\n", "CPIN"),
        (b"AT+CREG?\r\n", "CREG"),
        (b"AT+CGREG?\r\n", "CGREG"),
        (b"AT+CSQ\r\n", "CSQ"),
        (b"AT+COPS?\r\n", "COPS"),
        (b"AT+CSCA?\r\n", "CSCA (SMSC)"),
    ):
        try:
            ser.write(cmd)
            r = at_read_all(ser, total_timeout=4.0)
            print(f"[modem] {label}: {r.replace(chr(10), ' ').strip()!r}", flush=True)
        except Exception as e:  # noqa: BLE001
            print(f"[modem] {label}: (read error {e})", flush=True)


def send_sms_modem(
    ser: serial.Serial,
    e164: str,
    text: str,
    *,
    cmgs_no_plus: bool,
) -> None:
    """Send SMS using text mode. e164 should look like +639171234567."""
    digits = re.sub(r"\D", "", e164)
    if not digits:
        raise RuntimeError("Empty phone number")
    if digits.startswith("0"):
        digits = "63" + digits[1:]
    if not digits.startswith("63") and len(digits) == 10:
        digits = "63" + digits
    phone_for_cmd = digits if cmgs_no_plus else ("+" + digits)

    ser.reset_input_buffer()
    ser.reset_output_buffer()
    ser.write(b"AT+CMGF=1\r\n")
    at_read(ser)

    cmd = f'AT+CMGS="{phone_for_cmd}"\r\n'.encode("ascii", errors="replace")
    ser.write(cmd)
    r = at_read(ser, timeout=12.0, cmgs_prompt=True)
    if "\r\n>" not in r and "\n>" not in r and not r.rstrip().endswith(">"):
        modem_print_diagnostics(ser)
        raise RuntimeError(f"Modem did not prompt for message (check number format): {r!r}")

    body = text.encode("utf-8", errors="replace")
    if len(body) > 160:
        print("[modem] WARNING message > 160 bytes; may fail on some carriers.", flush=True)
    ser.write(body)
    ser.write(b"\x1a")
    r2 = read_cmgs_result(ser, total_timeout=90.0)

    if re.search(r"\+CMGS:\s*\d+", r2) and ("OK" in r2 or "ok" in r2.lower()):
        return
    if re.search(r"\+CMGS:\s*\d+", r2) and "+CMS ERROR" not in r2:
        return

    errs = parse_cms_cme_errors(r2)
    if errs:
        modem_print_diagnostics(ser)
        raise RuntimeError("Send failed: " + "; ".join(errs) + f" | raw={r2!r}")

    if "ERROR" in r2:
        modem_print_diagnostics(ser)
        raise RuntimeError(
            "Send failed (generic ERROR). Often: no signal, SIM needs SMSC "
            "(set SMS_SMSC), SIM PIN, or wrong COM port. Raw: "
            + repr(r2.strip())
        )

    if "OK" in r2:
        return

    modem_print_diagnostics(ser)
    raise RuntimeError(f"Unexpected modem response: {r2!r}")


def _pid_is_alive(pid: int) -> bool:
    if pid <= 0:
        return False
    if os.name == "nt":
        import ctypes

        PROCESS_QUERY_LIMITED_INFORMATION = 0x1000
        h = ctypes.windll.kernel32.OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, 0, ctypes.c_uint32(pid))
        if h:
            ctypes.windll.kernel32.CloseHandle(h)
            return True
        return False
    try:
        os.kill(pid, 0)
    except OSError:
        return False
    return True


def try_exclusive_lock(path: Path) -> Optional[int]:
    path.parent.mkdir(parents=True, exist_ok=True)

    def read_lock_pid() -> Optional[int]:
        try:
            raw = path.read_text(encoding="ascii", errors="replace").strip()
            return int(raw) if raw.isdigit() else None
        except OSError:
            return None

    for _ in range(4):
        try:
            fd = os.open(str(path), os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o644)
            os.write(fd, str(os.getpid()).encode("ascii", errors="replace"))
            return fd
        except FileExistsError:
            old = read_lock_pid()
            if old is None or not _pid_is_alive(old):
                try:
                    path.unlink()
                except OSError:
                    pass
                continue
            return None
    return None


def release_lock(fd: int, path: Path) -> None:
    try:
        os.close(fd)
    except OSError:
        pass
    try:
        os.unlink(str(path))
    except OSError:
        pass


def gateway_open_serial() -> tuple[list[serial.Serial], bool]:
    ports_raw = env("SMS_SERIAL_PORTS", "")
    baud = int(env("SMS_BAUD", "115200"))
    ports = [p.strip() for p in ports_raw.split(",") if p.strip()]
    if not ports:
        print("SMS_SERIAL_PORTS is empty (e.g. set COM5 on Windows)", file=sys.stderr)
        sys.exit(1)

    smsc = os.environ.get("SMS_SMSC", "").strip() or None
    if smsc:
        print(f"Using SMS service center from SMS_SMSC: {smsc}", flush=True)
    cmgs_no_plus = truthy("SMS_CMGS_NO_PLUS")

    connections: list[serial.Serial] = []
    try:
        open_delay = float(os.environ.get("SMS_OPEN_DELAY_SEC", "1.5"))
        for p in ports:
            print(f"Opening serial: {p} @ {baud}", flush=True)
            ser = serial.Serial(p, baudrate=baud, timeout=2, write_timeout=5)
            apply_serial_line_settings(ser)
            if open_delay > 0:
                time.sleep(open_delay)
            ensure_modem_baud(ser, baud)
            modem_prepare(ser, smsc)
            connections.append(ser)
    except serial.SerialException as e:
        print(f"Serial open failed: {e}", file=sys.stderr, flush=True)
        print("Close other apps using the modem; check Device Manager for the COM port.", file=sys.stderr)
        sys.exit(1)

    return connections, cmgs_no_plus


def process_job_batch(
    url: str,
    tenant: str,
    secret: str,
    connections: list[serial.Serial],
    cmgs_no_plus: bool,
    rr: int,
    *,
    verbose: bool,
) -> tuple[int, bool]:
    res = http_post_json(
        url,
        {"action": "poll", "tenant": tenant, "secret": secret, "limit": 10},
    )
    if not res.get("ok"):
        if verbose:
            print(f"[poll] rejected: {res}", flush=True)
        return rr, False

    jobs = res.get("jobs") or []
    if verbose or jobs:
        print(f"[poll] jobs this round: {len(jobs)}", flush=True)

    if not jobs:
        return rr, False

    for job in jobs:
        jid = int(job["id"])
        dest = str(job["destination_phone"])
        msg = str(job["message_body"])
        ser = connections[rr % len(connections)]
        rr += 1
        try:
            print(f"Sending job {jid} -> {dest} ...", flush=True)
            send_sms_modem(ser, dest, msg, cmgs_no_plus=cmgs_no_plus)
            ack = http_post_json(
                url,
                {
                    "action": "ack",
                    "tenant": tenant,
                    "secret": secret,
                    "job_id": jid,
                    "status": "sent",
                },
            )
            if not ack.get("ok"):
                print(f"Ack odd response for {jid}: {ack}", flush=True)
            else:
                print(f"Sent job {jid} -> {dest}", flush=True)
        except Exception as ex:  # noqa: BLE001
            print(f"Job {jid} failed: {ex}", flush=True)
            try:
                http_post_json(
                    url,
                    {
                        "action": "ack",
                        "tenant": tenant,
                        "secret": secret,
                        "job_id": jid,
                        "status": "failed",
                        "error": str(ex)[:400],
                    },
                )
            except Exception as ack_e:  # noqa: BLE001
                print(f"Ack failed for {jid}: {ack_e}", flush=True)
    return rr, True


def main_once() -> None:
    """Single drain pass for PHP: lock, poll/send until empty, exit."""
    lock_path = Path(__file__).resolve().parent.parent / "storage" / "sms_once.lock"
    fd = try_exclusive_lock(lock_path)
    if fd is None:
        sys.exit(0)

    url = normalize_queue_url(env("SMS_QUEUE_URL"))
    tenant = env("SMS_TENANT_SLUG")
    secret = env("SMS_POLL_SECRET")

    try:
        try:
            peek = http_post_json(
                url,
                {"action": "peek", "tenant": tenant, "secret": secret},
            )
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, ValueError) as e:
            print(f"[once] API peek failed: {e}", file=sys.stderr, flush=True)
            return

        if not peek.get("ok") or int(peek.get("pending") or 0) < 1:
            return

        connections, cmgs_no_plus = gateway_open_serial()
        rr = 0
        vo = truthy("SMS_VERBOSE")
        try:
            for _ in range(50):
                rr, had_jobs = process_job_batch(
                    url,
                    tenant,
                    secret,
                    connections,
                    cmgs_no_plus,
                    rr,
                    verbose=vo,
                )
                if not had_jobs:
                    break
                time.sleep(0.12)
        finally:
            for c in connections:
                try:
                    c.close()
                except Exception:
                    pass
    finally:
        release_lock(fd, lock_path)


def main() -> None:
    url = normalize_queue_url(env("SMS_QUEUE_URL"))
    tenant = env("SMS_TENANT_SLUG")
    secret = env("SMS_POLL_SECRET")
    verbose = truthy("SMS_VERBOSE")
    skip_modem = truthy("SMS_SKIP_MODEM")

    poll_interval = float(env("SMS_POLL_INTERVAL_SEC", "3"))

    print(f"SMS gateway — API: {url}", flush=True)
    print(f"Tenant: {tenant}", flush=True)

    try:
        peek = http_post_json(
            url,
            {"action": "peek", "tenant": tenant, "secret": secret},
        )
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, ValueError) as e:
        print(f"API error (peek): {e}", file=sys.stderr, flush=True)
        print("Check SMS_QUEUE_URL, HTTPS, firewall, and that PHP is reachable.", file=sys.stderr)
        sys.exit(1)

    if not peek.get("ok"):
        print(f"Peek rejected (wrong tenant slug or SMS_POLL_SECRET?): {peek}", file=sys.stderr, flush=True)
        sys.exit(1)

    pending_n = int(peek.get("pending") or 0)
    proc_n = int(peek.get("processing") or 0)
    print(
        f"Queue: {pending_n} pending, {proc_n} processing (ids: {peek.get('pending_job_ids')})",
        flush=True,
    )

    if skip_modem:
        print("SMS_SKIP_MODEM=1 — not opening serial. Exiting.", flush=True)
        return

    connections, cmgs_no_plus = gateway_open_serial()

    rr = 0
    idle_streak = 0
    print(f"Polling every {poll_interval}s — leave this window open while testing.", flush=True)

    try:
        while True:
            try:
                rr, had_jobs = process_job_batch(
                    url,
                    tenant,
                    secret,
                    connections,
                    cmgs_no_plus,
                    rr,
                    verbose=verbose,
                )
            except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, ValueError) as e:
                print(f"Poll error: {e}", flush=True)
                time.sleep(poll_interval)
                continue

            if not had_jobs:
                idle_streak += 1
                if verbose or idle_streak % 15 == 0:
                    print("[poll] no jobs (if admin shows pending, check same URL/secret as peek).", flush=True)
                time.sleep(poll_interval)
                continue

            idle_streak = 0
            time.sleep(0.2)
    finally:
        for c in connections:
            try:
                c.close()
            except Exception:
                pass


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="SIM800 SMS queue worker")
    parser.add_argument(
        "--once-config",
        metavar="JSON_FILE",
        help="Load env vars from JSON then drain queue once (used by PHP local auto-send)",
    )
    args = parser.parse_args()
    if args.once_config:
        cfg_path = args.once_config
        try:
            with open(cfg_path, encoding="utf-8") as f:
                cfg = json.load(f)
            for k, v in cfg.items():
                if v is not None and str(v).strip() != "":
                    os.environ[str(k)] = str(v).strip()
            qu = os.environ.get("SMS_QUEUE_URL", "").strip()
            if qu:
                os.environ["SMS_QUEUE_URL"] = normalize_queue_url(qu)
            main_once()
        finally:
            try:
                os.unlink(cfg_path)
            except OSError:
                pass
    else:
        main()
