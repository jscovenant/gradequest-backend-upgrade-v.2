param(
    [Parameter(Mandatory = $true)]
    [string]$FrontendRoot,

    [string]$BackendRoot = "",
    [string]$OutputRoot = ""
)

$ErrorActionPreference = "Stop"

function Assert-File($Path, $Message) {
    if (!(Test-Path $Path)) {
        throw $Message
    }
}

$InstallerRoot = (Resolve-Path "$PSScriptRoot\..").Path
if ([string]::IsNullOrWhiteSpace($BackendRoot)) {
    $BackendRoot = (Resolve-Path (Join-Path $InstallerRoot "..")).Path
}
if ([string]::IsNullOrWhiteSpace($OutputRoot)) {
    $OutputRoot = Join-Path $InstallerRoot "dist\GradeQuestOfflinePayloadFast"
}

$BackendRoot = (Resolve-Path $BackendRoot).Path
$FrontendRoot = (Resolve-Path $FrontendRoot).Path

Assert-File "$FrontendRoot\package.json" "Frontend package.json was not found."
Assert-File "$BackendRoot\artisan" "Laravel artisan file was not found."
Assert-File "$InstallerRoot\runtime\php\php.exe" "Missing runtime/php/php.exe. Add portable PHP before packaging."
Assert-File "$InstallerRoot\runtime\php\php-cgi.exe" "Missing runtime/php/php-cgi.exe. Add portable PHP before packaging."
Assert-File "$InstallerRoot\runtime\mariadb\bin\mysqld.exe" "Missing runtime/mariadb/bin/mysqld.exe. Add portable MariaDB before packaging."
Assert-File "$InstallerRoot\runtime\mariadb\bin\mysql.exe" "Missing runtime/mariadb/bin/mysql.exe. Add portable MariaDB before packaging."
Assert-File "$InstallerRoot\runtime\nginx\nginx.exe" "Missing runtime/nginx/nginx.exe. Add portable Nginx before packaging."

Write-Host "Building frontend..."
Push-Location $FrontendRoot
npm.cmd run build
Pop-Location

Write-Host "Preparing package folder..."
if (Test-Path $OutputRoot) {
    Remove-Item $OutputRoot -Recurse -Force
}

New-Item -ItemType Directory -Force -Path "$OutputRoot\app\backend" | Out-Null
New-Item -ItemType Directory -Force -Path "$OutputRoot\app\frontend" | Out-Null
New-Item -ItemType Directory -Force -Path "$OutputRoot\runtime" | Out-Null
New-Item -ItemType Directory -Force -Path "$OutputRoot\config" | Out-Null
New-Item -ItemType Directory -Force -Path "$OutputRoot\scripts" | Out-Null
New-Item -ItemType Directory -Force -Path "$OutputRoot\data\mariadb" | Out-Null
New-Item -ItemType Directory -Force -Path "$OutputRoot\logs" | Out-Null
New-Item -ItemType Directory -Force -Path "$OutputRoot\backups" | Out-Null

Write-Host "Copying backend..."
$BackendDest = "$OutputRoot\app\backend"
$RoboFlags = @("/MIR", "/R:1", "/W:1", "/MT:16", "/NFL", "/NDL", "/NJH", "/NJS", "/NP")
$BackendDirs = @("app", "bootstrap", "config", "database", "public", "resources", "routes", "vendor")
foreach ($dir in $BackendDirs) {
    $SourceDir = Join-Path $BackendRoot $dir
    if (Test-Path $SourceDir) {
        $ExcludeDirs = @()
        if ($dir -eq "public") {
            $ExcludeDirs = @("uploads", "build", "hot")
        }

        $Arguments = @($SourceDir, (Join-Path $BackendDest $dir)) + $RoboFlags
        if ($ExcludeDirs.Count -gt 0) {
            $Arguments += "/XD"
            $Arguments += $ExcludeDirs
        }

        robocopy @Arguments | Out-Host
        if ($LASTEXITCODE -gt 7) { throw "Backend $dir copy failed with robocopy exit code $LASTEXITCODE" }
    }
}

$BackendFiles = @("artisan", "composer.json", "composer.lock", ".htaccess")
foreach ($file in $BackendFiles) {
    $SourceFile = Join-Path $BackendRoot $file
    if (Test-Path $SourceFile) {
        Copy-Item $SourceFile (Join-Path $BackendDest $file) -Force
    }
}

$StorageDirs = @(
    "storage\app",
    "storage\app\public",
    "storage\framework",
    "storage\framework\cache",
    "storage\framework\cache\data",
    "storage\framework\sessions",
    "storage\framework\views",
    "storage\logs"
)
foreach ($dir in $StorageDirs) {
    New-Item -ItemType Directory -Force -Path (Join-Path $BackendDest $dir) | Out-Null
}

Write-Host "Copying frontend build..."
robocopy "$FrontendRoot\dist" "$OutputRoot\app\frontend" @RoboFlags | Out-Host
if ($LASTEXITCODE -gt 7) { throw "Frontend copy failed with robocopy exit code $LASTEXITCODE" }

Write-Host "Copying runtimes and scripts..."
robocopy "$InstallerRoot\runtime" "$OutputRoot\runtime" @RoboFlags /XD _downloads | Out-Host
if ($LASTEXITCODE -gt 7) { throw "Runtime copy failed with robocopy exit code $LASTEXITCODE" }

Copy-Item "$InstallerRoot\config\nginx.conf.template" "$OutputRoot\config\nginx.conf.template" -Force
Copy-Item "$InstallerRoot\config\nginx.conf.template" "$OutputRoot\runtime\nginx\conf\nginx.conf" -Force
Copy-Item "$InstallerRoot\config\php.ini.template" "$OutputRoot\runtime\php\php.ini" -Force
Copy-Item "$InstallerRoot\config\.env.offline.example" "$OutputRoot\app\backend\.env" -Force
Copy-Item "$InstallerRoot\scripts\Initialize-GradeQuestOffline.ps1" "$OutputRoot\scripts\Initialize-GradeQuestOffline.ps1" -Force
Copy-Item "$InstallerRoot\scripts\Start-GradeQuestOffline.ps1" "$OutputRoot\scripts\Start-GradeQuestOffline.ps1" -Force
Copy-Item "$InstallerRoot\scripts\Stop-GradeQuestOffline.ps1" "$OutputRoot\scripts\Stop-GradeQuestOffline.ps1" -Force
Copy-Item "$InstallerRoot\scripts\Open-OfflineCbt.ps1" "$OutputRoot\scripts\Open-OfflineCbt.ps1" -Force
Copy-Item "$InstallerRoot\scripts\Show-ServerAddress.ps1" "$OutputRoot\scripts\Show-ServerAddress.ps1" -Force

Write-Host "Package prepared at $OutputRoot"
