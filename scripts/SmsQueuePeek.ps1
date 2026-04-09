# Quick API check: does not send SMS or claim jobs. Edit the three values, then:
#   powershell -ExecutionPolicy Bypass -File scripts\SmsQueuePeek.ps1

$SmsQueueUrl = "http://localhost:8000/public/api/sms_queue.php"
$TenantSlug  = "default-school"
$PollSecret  = "PASTE_SECRET_FROM_ADMIN_PARENT_SMS_PAGE"

$body = @{
    action = "peek"
    tenant = $TenantSlug
    secret = $PollSecret
} | ConvertTo-Json

try {
    $r = Invoke-RestMethod -Method Post -Uri $SmsQueueUrl -Body $body -ContentType "application/json; charset=utf-8"
    $r | ConvertTo-Json -Depth 5
} catch {
    Write-Host "Request failed: $_" -ForegroundColor Red
    if ($_.Exception.Response) {
        $reader = [System.IO.StreamReader]::new($_.Exception.Response.GetResponseStream())
        Write-Host $reader.ReadToEnd()
    }
    exit 1
}
