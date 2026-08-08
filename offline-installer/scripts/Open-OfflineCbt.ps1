$InstallDir = (Resolve-Path "$PSScriptRoot\..").Path
$StartScript = Join-Path $InstallDir "scripts\Start-GradeQuestOffline.ps1"

try {
    $response = Invoke-WebRequest -Uri "http://127.0.0.1:8088/api/offline-cbt/status" -UseBasicParsing -TimeoutSec 3
    if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 500) {
        throw "Offline server is not ready."
    }
} catch {
    powershell.exe -ExecutionPolicy Bypass -File $StartScript
}

Start-Process "http://localhost:8088/cbt/offline-runner"
