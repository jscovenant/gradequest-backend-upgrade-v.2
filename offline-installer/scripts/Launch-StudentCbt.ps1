# GradeQuest Student CBT - Auto-Connect Launcher
# Automatically discovers and launches GradeQuest Student CBT on WiFi / Hotspot / TP-Link LAN

$ErrorActionPreference = "SilentlyContinue"

$port = 8088
$configDir = Join-Path $env:LOCALAPPDATA "GradeQuest"
$configFile = Join-Path $configDir "cbt_server.txt"

if (-not (Test-Path $configDir)) {
    New-Item -ItemType Directory -Path $configDir -Force | Out-Null
}

function Test-CbtServer($ip) {
    if (-not $ip) { return $false }
    $ip = $ip.Trim()
    if ($ip -notmatch "^https?://") {
        $testUrl = "http://${ip}:${port}/api/offline-cbt/ping"
    } else {
        $testUrl = "$ip/api/offline-cbt/ping"
    }
    try {
        $res = Invoke-RestMethod -Uri $testUrl -Method Get -TimeoutSec 1 -ErrorAction Stop
        if ($res.status -eq "connected" -or $res.server) {
            return $true
        }
    } catch {}
    return $false
}

function Open-Browser($targetUrl) {
    Write-Host "[OK] Connected to GradeQuest CBT Server!" -ForegroundColor Green
    Write-Host "Opening Exam Runner in browser ($targetUrl)..." -ForegroundColor Cyan

    # Try Google Chrome first in app mode if available
    $chromePaths = @(
        "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
        "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
    )
    $launched = $false
    foreach ($cp in $chromePaths) {
        if (Test-Path $cp) {
            Start-Process $cp -ArgumentList "--app=$targetUrl"
            $launched = $true
            break
        }
    }

    # Try Microsoft Edge in app mode
    if (-not $launched) {
        $edgePaths = @(
            "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
            "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
        )
        foreach ($ep in $edgePaths) {
            if (Test-Path $ep) {
                Start-Process $ep -ArgumentList "--app=$targetUrl"
                $launched = $true
                break
            }
        }
    }

    # Fallback to system default browser
    if (-not $launched) {
        Start-Process $targetUrl
    }
}

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "                  GradeQuest Student CBT                  " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "Searching for CBT Server on local network (WiFi / Hotspot)..." -ForegroundColor Yellow

$serverIp = $null

# 1. Check localhost first (if student is on the host server PC)
if (Test-CbtServer "127.0.0.1") {
    $serverIp = "localhost"
}

# 2. Check previously saved/cached server IP
if (-not $serverIp -and (Test-Path $configFile)) {
    $cached = (Get-Content $configFile -ErrorAction SilentlyContinue | Select-Object -First 1)
    if ($cached -and (Test-CbtServer $cached)) {
        Write-Host "Found previously connected CBT Server: $cached" -ForegroundColor Green
        $serverIp = $cached
    }
}

# 3. Check Default Gateway (TP-Link Router / Hotspot Host IP)
if (-not $serverIp) {
    try {
        $gateways = Get-NetRoute -DestinationPrefix '0.0.0.0/0' | Select-Object -ExpandProperty NextHop -Unique
        foreach ($gw in $gateways) {
            if ($gw -and (Test-CbtServer $gw)) {
                $serverIp = $gw
                break
            }
        }
    } catch {}
}

# 4. Probe local subnet (Fast check of first 30 IPs or adjacent)
if (-not $serverIp) {
    try {
        $myIps = Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike "127.*" -and $_.IPAddress -notlike "169.254.*" }
        foreach ($myIpObj in $myIps) {
            $myIp = $myIpObj.IPAddress
            $parts = $myIp.Split('.')
            if ($parts.Length -eq 4) {
                $subnet = "$($parts[0]).$($parts[1]).$($parts[2])"
                # Test the most common server host IPs in hotspot/router pools: .1, .2, .100, .10, .15, .20
                $commonHosts = @(1, 2, 100, 10, 15, 20, 50, 101, 102, 105)
                foreach ($h in $commonHosts) {
                    $probeIp = "$subnet.$h"
                    if ($probeIp -ne $myIp -and (Test-CbtServer $probeIp)) {
                        $serverIp = $probeIp
                        break
                    }
                }
                if ($serverIp) { break }
            }
        }
    } catch {}
}

# 5. If server was found, save and launch
if ($serverIp) {
    Set-Content -Path $configFile -Value $serverIp -Force
    $fullUrl = "http://${serverIp}:${port}/cbt/student"
    Open-Browser $fullUrl
    Start-Sleep -Seconds 3
    exit 0
}

# 6. Server not auto-detected: Ask student or teacher to enter IP
Write-Host ""
Write-Host "[!] Could not automatically detect CBT Server on this WiFi." -ForegroundColor Yellow
Write-Host "Please look at the Teacher's screen or whiteboard for the CBT Server IP." -ForegroundColor Cyan
Write-Host "Example: 192.168.43.15 or 192.168.1.100" -ForegroundColor Gray
Write-Host ""

$enteredIp = Read-Host "Enter CBT Server IP address"

if ($enteredIp) {
    $enteredIp = $enteredIp.Trim().Replace("http://", "").Replace(":8088", "").Replace("/cbt/student", "").Replace("/cbt/offline-runner", "").Trim('/')
    if (Test-CbtServer $enteredIp) {
        Set-Content -Path $configFile -Value $enteredIp -Force
        $fullUrl = "http://${enteredIp}:${port}/cbt/student"
        Open-Browser $fullUrl
        Start-Sleep -Seconds 3
        exit 0
    } else {
        Write-Host ""
        Write-Host "[ERROR] Could not connect to $enteredIp on port $port." -ForegroundColor Red
        Write-Host "Please ensure this computer is connected to the exact same WiFi / Hotspot as the teacher's server." -ForegroundColor Yellow
        Write-Host ""
        Read-Host "Press Enter to exit..."
        exit 1
    }
}
