param(
    [string]$InstallDir = (Resolve-Path "$PSScriptRoot\..").Path
)

$ErrorActionPreference = "Stop"

$BackendDir = Join-Path $InstallDir "app\backend"
$PhpCgi = Join-Path $InstallDir "runtime\php\php-cgi.exe"
$Mysqld = Join-Path $InstallDir "runtime\mariadb\bin\mysqld.exe"
$AriaChk = Join-Path $InstallDir "runtime\mariadb\bin\aria_chk.exe"
$Nginx = Join-Path $InstallDir "runtime\nginx\nginx.exe"
$DataDir = Join-Path $InstallDir "data\mariadb"
$RunDir = Join-Path $InstallDir "run"
$LogDir = Join-Path $InstallDir "logs"
$NginxConfTemplate = Join-Path $InstallDir "config\nginx.conf.template"
$NginxDir = Join-Path $InstallDir "runtime\nginx"
$NginxConf = Join-Path $NginxDir "conf\nginx.conf"

New-Item -ItemType Directory -Force -Path $RunDir | Out-Null
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Stop-ListenerOnPort($Port) {
    $lines = netstat -ano | Select-String ":$Port\s+.*LISTENING"
    foreach ($line in $lines) {
        $parts = ($line.ToString() -split "\s+") | Where-Object { $_ }
        $processId = [int]$parts[-1]
        Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue
    }
}

Stop-ListenerOnPort 8088
Stop-ListenerOnPort 9123
Stop-ListenerOnPort 3307

$NormalizedInstallDir = ($InstallDir -replace "\\", "/")
$NormalizedNginxDir = ($NginxDir -replace "\\", "/")
(Get-Content $NginxConfTemplate) `
    -replace "__INSTALL_DIR__", $NormalizedInstallDir `
    -replace "__NGINX_DIR__", $NormalizedNginxDir |
    Set-Content $NginxConf -Encoding ASCII

Write-Host "Starting MariaDB..."
if (Test-Path $AriaChk) {
    Push-Location $DataDir
    Get-ChildItem $DataDir -Recurse -Filter "*.MAI" -ErrorAction SilentlyContinue |
        ForEach-Object { & $AriaChk -r $_.FullName | Out-Null }
    Pop-Location
}
Get-ChildItem $DataDir -Filter "aria_log.*" -ErrorAction SilentlyContinue |
    Remove-Item -Force -ErrorAction SilentlyContinue

$DbProcess = Start-Process -FilePath $Mysqld -ArgumentList @(
    "--datadir=$DataDir",
    "--port=3307",
    "--bind-address=127.0.0.1",
    "--console"
) -PassThru -WindowStyle Hidden
$DbProcess.Id | Set-Content (Join-Path $RunDir "mariadb.pid")

Start-Sleep -Seconds 4

Write-Host "Starting PHP FastCGI..."
$PhpProcess = Start-Process -FilePath $PhpCgi -ArgumentList @(
    "-b",
    "127.0.0.1:9123",
    "-c",
    (Join-Path $InstallDir "runtime\php")
) -WorkingDirectory $BackendDir -PassThru -WindowStyle Hidden
$PhpProcess.Id | Set-Content (Join-Path $RunDir "php-cgi.pid")

Write-Host "Starting Nginx..."
$NginxProcess = Start-Process -FilePath $Nginx -ArgumentList @(
    "-p",
    $NginxDir,
    "-c",
    "conf\nginx.conf"
) -PassThru -WindowStyle Hidden
$NginxProcess.Id | Set-Content (Join-Path $RunDir "nginx.pid")

Write-Host "GradeQuest Offline CBT Server is running."
Write-Host "Open http://localhost:8088/cbt/offline-runner on this computer."
