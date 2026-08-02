param(
    [string]$InstallDir = (Resolve-Path "$PSScriptRoot\..").Path
)

$RunDir = Join-Path $InstallDir "run"
$MysqlAdmin = Join-Path $InstallDir "runtime\mariadb\bin\mysqladmin.exe"
$PidFiles = @("nginx.pid", "php-cgi.pid", "mariadb.pid")

if (Test-Path $MysqlAdmin) {
    & $MysqlAdmin -h 127.0.0.1 -P 3307 -u gradequest -pgradequest_offline shutdown 2>$null
    Start-Sleep -Seconds 2
}

foreach ($PidFile in $PidFiles) {
    $Path = Join-Path $RunDir $PidFile
    if (Test-Path $Path) {
        $PidValue = Get-Content $Path -ErrorAction SilentlyContinue
        if ($PidValue) {
            Stop-Process -Id ([int]$PidValue) -Force -ErrorAction SilentlyContinue
        }
        Remove-Item $Path -Force -ErrorAction SilentlyContinue
    }
}

Write-Host "GradeQuest Offline CBT Server stopped."
