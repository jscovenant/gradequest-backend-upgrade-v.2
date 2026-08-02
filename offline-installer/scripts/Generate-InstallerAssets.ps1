param(
    [string]$LogoPath = (Resolve-Path "$PSScriptRoot\..\..\public\frontend\logo\gradequest_log.png").Path,
    [string]$OutputDir = (Resolve-Path "$PSScriptRoot\..\assets").Path
)

$ErrorActionPreference = "Stop"

Add-Type -AssemblyName System.Drawing

function New-Canvas($Width, $Height, $Background) {
    $bitmap = New-Object System.Drawing.Bitmap($Width, $Height)
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $graphics.Clear($Background)
    return @($bitmap, $graphics)
}

function Draw-CenteredImage($Graphics, $Image, $BoxX, $BoxY, $BoxWidth, $BoxHeight) {
    $ratio = [Math]::Min($BoxWidth / $Image.Width, $BoxHeight / $Image.Height)
    $drawWidth = [int]($Image.Width * $ratio)
    $drawHeight = [int]($Image.Height * $ratio)
    $x = [int]($BoxX + (($BoxWidth - $drawWidth) / 2))
    $y = [int]($BoxY + (($BoxHeight - $drawHeight) / 2))
    $Graphics.DrawImage($Image, $x, $y, $drawWidth, $drawHeight)
}

New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

$logo = [System.Drawing.Image]::FromFile($LogoPath)
$primary = [System.Drawing.Color]::FromArgb(211, 0, 176)
$dark = [System.Drawing.Color]::FromArgb(18, 24, 38)
$white = [System.Drawing.Color]::White

$large = New-Canvas 164 314 $dark
$largeBitmap = $large[0]
$largeGraphics = $large[1]
$brush = New-Object System.Drawing.SolidBrush($primary)
$largeGraphics.FillRectangle($brush, 0, 248, 164, 66)
Draw-CenteredImage $largeGraphics $logo 18 44 128 128
$font = New-Object System.Drawing.Font("Segoe UI", 11, [System.Drawing.FontStyle]::Bold)
$textBrush = New-Object System.Drawing.SolidBrush($white)
$format = New-Object System.Drawing.StringFormat
$format.Alignment = [System.Drawing.StringAlignment]::Center
$largeGraphics.DrawString("Offline CBT", $font, $textBrush, (New-Object System.Drawing.RectangleF(8, 210, 148, 30)), $format)
$largeGraphics.DrawString("Server", $font, $textBrush, (New-Object System.Drawing.RectangleF(8, 268, 148, 26)), $format)
$largeBitmap.Save((Join-Path $OutputDir "gradequest-installer-large.bmp"), [System.Drawing.Imaging.ImageFormat]::Bmp)
$largeGraphics.Dispose()
$largeBitmap.Dispose()

$small = New-Canvas 55 55 $white
$smallBitmap = $small[0]
$smallGraphics = $small[1]
Draw-CenteredImage $smallGraphics $logo 5 5 45 45
$smallBitmap.Save((Join-Path $OutputDir "gradequest-installer-small.bmp"), [System.Drawing.Imaging.ImageFormat]::Bmp)
$smallGraphics.Dispose()
$smallBitmap.Dispose()

$iconBitmap = New-Object System.Drawing.Bitmap(64, 64)
$iconGraphics = [System.Drawing.Graphics]::FromImage($iconBitmap)
$iconGraphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$iconGraphics.Clear([System.Drawing.Color]::Transparent)
$iconGraphics.FillEllipse((New-Object System.Drawing.SolidBrush($primary)), 0, 0, 64, 64)
Draw-CenteredImage $iconGraphics $logo 10 10 44 44
$handle = $iconBitmap.GetHicon()
$icon = [System.Drawing.Icon]::FromHandle($handle)
$stream = [System.IO.File]::Create((Join-Path $OutputDir "gradequest.ico"))
$icon.Save($stream)
$stream.Dispose()
$icon.Dispose()
$iconGraphics.Dispose()
$iconBitmap.Dispose()
$logo.Dispose()

Write-Host "Installer assets generated in $OutputDir"

