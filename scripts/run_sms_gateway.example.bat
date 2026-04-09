@echo off
REM Copy this file to run_sms_gateway.bat and edit the values.
REM Keep this window OPEN while testing — closing it stops SMS sending.
title SIM800 SMS Gateway
cd /d "%~dp0.."

REM Hosted: HTTPS URL from Admin > Parent SMS > Gateway API.
REM Local XAMPP: use http://127.0.0.1/.../worker/sms_queue.php (not localhost).
REM HTTP 403 from peek = wrong SMS_TENANT_SLUG or SMS_POLL_SECRET — copy both from that page after Save.
set "SMS_QUEUE_URL=https://mensaheko.com/public/worker/sms_queue.php"
set "SMS_TENANT_SLUG=default-school"
set "SMS_POLL_SECRET=16fbd78f3baceed3c60044a8c6880593d1fc776769adf4b9"
set "SMS_SERIAL_PORTS=COM8"
set "SMS_BAUD=115200"
REM CH340 + SIM800: use 115200 first. Empty AT/CPIN replies usually mean wrong baud (9600 often wrong).
REM If CMGS -> ERROR but AT works: set SMS_SMSC=+63... (carrier SMS center), try SMS_CMGS_NO_PLUS=1, SMS_SET_CSMP=1
REM SMS_NO_AUTO_BAUD=1 to disable baud probing. SMS_OPEN_DELAY_SEC=2 if module resets on USB open.
REM With SMS_LOCAL_AUTO_SEND in config.php, PHP can spawn this script per scan — .bat optional for testing.

echo.
echo SMS_QUEUE_URL=%SMS_QUEUE_URL%
echo Leave this window open. Press Ctrl+C to stop.
echo.

where python >nul 2>&1
if %ERRORLEVEL% equ 0 (
    python scripts\sms_gateway.py
) else (
    py -3 scripts\sms_gateway.py
)

echo.
pause
