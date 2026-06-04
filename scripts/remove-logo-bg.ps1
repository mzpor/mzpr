Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$backup = Join-Path $root "images\logo-with-bg.png"
$output = Join-Path $root "images\logo.png"

$loaded = [System.Drawing.Bitmap]::FromFile($backup)
$bmp = New-Object System.Drawing.Bitmap $loaded.Width, $loaded.Height, ([System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
$gfx = [System.Drawing.Graphics]::FromImage($bmp)
$gfx.Clear([System.Drawing.Color]::Transparent)
$gfx.DrawImage($loaded, 0, 0)
$gfx.Dispose()
$loaded.Dispose()

$w = $bmp.Width
$h = $bmp.Height
$rect = New-Object System.Drawing.Rectangle 0, 0, $w, $h
$data = $bmp.LockBits($rect, [System.Drawing.Imaging.ImageLockMode]::ReadWrite, $bmp.PixelFormat)
$stride = $data.Stride
$bytes = New-Object byte[] ($stride * $h)
[System.Runtime.InteropServices.Marshal]::Copy($data.Scan0, $bytes, 0, $bytes.Length)

for ($y = 0; $y -lt $h; $y++) {
    for ($x = 0; $x -lt $w; $x++) {
        $i = $y * $stride + $x * 4
        $b = $bytes[$i]
        $g = $bytes[$i + 1]
        $r = $bytes[$i + 2]

        $maxC = [Math]::Max($r, [Math]::Max($g, $b))
        $minC = [Math]::Min($r, [Math]::Min($g, $b))
        $sat = $maxC - $minC
        $l = 0.299 * $r + 0.587 * $g + 0.114 * $b

        # طلایی/برنزی: اشباع یا روشنایی بالا
        $isLogo = ($sat -ge 22) -or ($l -ge 72) -or (($r -gt $b + 12) -and ($l -ge 38))

        $alpha = 255
        if (-not $isLogo) {
            if ($l -le 32) {
                $alpha = 0
            }
            elseif ($l -le 58) {
                $t = ($l - 32) / 26
                $alpha = [int]($t * 200)
            }
            else {
                $alpha = [int]([Math]::Min(255, (($l - 58) / 30) * 255))
            }
        }

        $bytes[$i + 3] = [byte][Math]::Max(0, [Math]::Min(255, $alpha))
    }
}

[System.Runtime.InteropServices.Marshal]::Copy($bytes, 0, $data.Scan0, $bytes.Length)
$bmp.UnlockBits($data)
$bmp.Save($output, [System.Drawing.Imaging.ImageFormat]::Png)
$bmp.Dispose()

Write-Host "Done: $output"
