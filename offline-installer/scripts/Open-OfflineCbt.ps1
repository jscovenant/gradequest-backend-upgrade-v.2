param(
    [string]$InstallDir = (Resolve-Path "$PSScriptRoot\..").Path
)

$ErrorActionPreference = "SilentlyContinue"

$statusUrl = "http://127.0.0.1:8088/api/offline-cbt/status"
$runnerUrl = "http://localhost:8088/cbt/offline-runner"
$StartScript = Join-Path $InstallDir "scripts\Start-GradeQuestOffline.ps1"

function Test-ServerOnline {
    try {
        $response = Invoke-WebRequest -Uri $statusUrl -UseBasicParsing -TimeoutSec 2
        return ($response.StatusCode -ge 200 -and $response.StatusCode -lt 400)
    } catch {
        return $false
    }
}

# 1. Check if server is already running and healthy
if (Test-ServerOnline) {
    Write-Host "GradeQuest Offline Server is already running. Opening CBT runner..." -ForegroundColor Green
    Start-Process $runnerUrl
    exit 0
}

# 2. Server is offline. Auto-start the server.
Write-Host ""
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "         GradeQuest Offline CBT Server Launcher           " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Server is offline. Automatically starting CBT services..." -ForegroundColor Yellow
Write-Host ""

if (Test-Path $StartScript) {
    & powershell.exe -ExecutionPolicy Bypass -File $StartScript -InstallDir $InstallDir
} else {
    Write-Host "ERROR: Start script not found at $StartScript" -ForegroundColor Red
}

# 3. Poll until server is ready
Write-Host "Waiting for offline server to initialize..." -NoNewline
$maxWaitSeconds = 30
$serverReady = $false

for ($i = 0; $i -lt $maxWaitSeconds; $i++) {
    Start-Sleep -Seconds 1
    Write-Host "." -NoNewline
    if (Test-ServerOnline) {
        $serverReady = $true
        break
    }
}

Write-Host ""
if ($serverReady) {
    Write-Host "[OK] GradeQuest Offline Server is online and ready!" -ForegroundColor Green
    Write-Host "Opening GradeQuest Offline CBT in your browser..." -ForegroundColor Green
    Start-Sleep -Milliseconds 600
    Start-Process $runnerUrl
    Start-Sleep -Seconds 2
} else {
    Write-Host "[WARNING] Offline server is taking longer than usual to respond." -ForegroundColor Yellow
    Write-Host "Opening CBT runner in browser anyway..." -ForegroundColor Yellow
    Start-Process $runnerUrl
    Start-Sleep -Seconds 3
}
