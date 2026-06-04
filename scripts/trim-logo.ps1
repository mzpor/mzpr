Add-Type -AssemblyName System.Drawing

$path = Join-Path (Split-Path -Parent $PSScriptRoot) "images\logo.png"
$bmp = [System.Drawing.Bitmap]::FromFile($path)
$w = $bmp.Width
$h = $bmp.Height

$minX = $w; $minY = $h; $maxX = 0; $maxY = 0
for ($y = 0; $y -lt $h; $y++) {
    for ($x = 0; $x -lt $w; $x++) {
        if ($bmp.GetPixel($x, $y).A -gt 12) {
            if ($x -lt $minX) { $minX = $x }
            if ($y -lt $minY) { $minY = $y }
            if ($x -gt $maxX) { $maxX = $x }
            if ($y -gt $maxY) { $maxY = $y }
        }
    }
}

$pad = 8
$minX = [Math]::Max(0, $minX - $pad)
$minY = [Math]::Max(0, $minY - $pad)
$maxX = [Math]::Min($w - 1, $maxX + $pad)
$maxY = [Math]::Min($h - 1, $maxY + $pad)
$cropW = $maxX - $minX + 1
$cropH = $maxY - $minY + 1

$out = New-Object System.Drawing.Bitmap $cropW, $cropH, ([System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
$g = [System.Drawing.Graphics]::FromImage($out)
$g.Clear([System.Drawing.Color]::Transparent)
$g.DrawImage($bmp, (New-Object System.Drawing.Rectangle 0, 0, $cropW, $cropH), $minX, $minY, $cropW, $cropH, [System.Drawing.GraphicsUnit]::Pixel)
$g.Dispose()
$bmp.Dispose()
$out.Save($path, [System.Drawing.Imaging.ImageFormat]::Png)
$out.Dispose()
Write-Host "Trimmed to ${cropW}x${cropH}"
