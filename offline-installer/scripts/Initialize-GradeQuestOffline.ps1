param(
    [string]$InstallDir = (Resolve-Path "$PSScriptRoot\..").Path
)

$ErrorActionPreference = "Stop"

$BackendDir = Join-Path $InstallDir "app\backend"
$PhpExe = Join-Path $InstallDir "runtime\php\php.exe"
$MysqlExe = Join-Path $InstallDir "runtime\mariadb\bin\mysql.exe"
$MysqldExe = Join-Path $InstallDir "runtime\mariadb\bin\mysqld.exe"
$InstallDbExe = Join-Path $InstallDir "runtime\mariadb\bin\mariadb-install-db.exe"
$DataDir = Join-Path $InstallDir "data\mariadb"
$PidDir = Join-Path $InstallDir "run"
$LogDir = Join-Path $InstallDir "logs"

New-Item -ItemType Directory -Force -Path $PidDir | Out-Null
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
New-Item -ItemType Directory -Force -Path $DataDir | Out-Null

if (!(Test-Path (Join-Path $DataDir "mysql"))) {
    Write-Host "Initializing MariaDB data directory..."
    & $InstallDbExe --datadir="$DataDir" --password=""
}

Write-Host "Starting temporary MariaDB..."
$DbProcess = Start-Process -FilePath $MysqldExe -ArgumentList @(
    "--datadir=$DataDir",
    "--port=3307",
    "--bind-address=127.0.0.1",
    "--console"
) -PassThru -WindowStyle Hidden

Start-Sleep -Seconds 8

try {
    Write-Host "Creating GradeQuest offline database and user..."
    & $MysqlExe -h 127.0.0.1 -P 3307 -u root -e "CREATE DATABASE IF NOT EXISTS gradequest_offline CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'gradequest'@'127.0.0.1' IDENTIFIED BY 'gradequest_offline'; CREATE USER IF NOT EXISTS 'gradequest'@'localhost' IDENTIFIED BY 'gradequest_offline'; ALTER USER 'gradequest'@'127.0.0.1' IDENTIFIED BY 'gradequest_offline'; ALTER USER 'gradequest'@'localhost' IDENTIFIED BY 'gradequest_offline'; GRANT ALL PRIVILEGES ON gradequest_offline.* TO 'gradequest'@'127.0.0.1'; GRANT ALL PRIVILEGES ON gradequest_offline.* TO 'gradequest'@'localhost'; FLUSH PRIVILEGES;"

    Push-Location $BackendDir
    & $PhpExe artisan key:generate --force
    & $PhpExe artisan migrate --force
    & $PhpExe artisan storage:link
    & $PhpExe artisan optimize:clear
    Pop-Location
}
finally {
    if ($DbProcess -and !$DbProcess.HasExited) {
        Stop-Process -Id $DbProcess.Id -Force
    }
}

Write-Host "GradeQuest Offline CBT initialization complete."
