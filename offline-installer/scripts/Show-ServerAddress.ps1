$addresses = Get-NetIPAddress -AddressFamily IPv4 |
    Where-Object {
        $_.IPAddress -notlike "127.*" -and
        $_.IPAddress -notlike "169.254.*" -and
        $_.PrefixOrigin -ne "WellKnown"
    } |
    Select-Object -ExpandProperty IPAddress

Write-Host ""
Write-Host "GradeQuest Offline CBT local URLs"
Write-Host "--------------------------------"
Write-Host "On this server computer:"
Write-Host "  http://localhost:8088/cbt/offline-runner"
Write-Host ""
Write-Host "On student devices connected to the same WiFi:"
foreach ($address in $addresses) {
    Write-Host "  http://$address`:8088/cbt/offline-runner"
}
Write-Host ""
Read-Host "Press Enter to close"
