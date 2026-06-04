Add-Type -AssemblyName System.Drawing

$ref = Join-Path (Split-Path -Parent $PSScriptRoot) "images\categories-reference.png"
$outDir = Join-Path (Split-Path -Parent $PSScriptRoot) "images\categories"
New-Item -ItemType Directory -Force -Path $outDir | Out-Null

$src = [System.Drawing.Bitmap]::FromFile($ref)
$w = $src.Width
$h = $src.Height

# برش تقریبی کارت‌ها از بنر مرجع (۱۰۰۰×۱۰۰۰)
$crops = @(
  @{ Name = "rahat";    X = 0.02;  Y = 0.30; W = 0.31; H = 0.24 },
  @{ Name = "steel";    X = 0.345; Y = 0.30; W = 0.31; H = 0.24 },
  @{ Name = "classic";  X = 0.67;  Y = 0.30; W = 0.31; H = 0.24 },
  @{ Name = "bedroom";  X = 0.02;  Y = 0.56; W = 0.31; H = 0.24 },
  @{ Name = "dining";   X = 0.345; Y = 0.56; W = 0.31; H = 0.24 },
  @{ Name = "tv";       X = 0.67;  Y = 0.56; W = 0.31; H = 0.24 },
  @{ Name = "wardrobe"; X = 0.02;  Y = 0.82; W = 0.96; H = 0.15 }
)

function Save-Crop($crop) {
  $x = [int]($w * $crop.X)
  $y = [int]($h * $crop.Y)
  $cw = [int]($w * $crop.W)
  $ch = [int]($h * $crop.H)
  $rect = New-Object System.Drawing.Rectangle $x, $y, $cw, $ch
  $dest = New-Object System.Drawing.Bitmap $cw, $ch
  $g = [System.Drawing.Graphics]::FromImage($dest)
  $g.DrawImage($src, 0, 0, $rect, [System.Drawing.GraphicsUnit]::Pixel)
  $g.Dispose()
  $path = Join-Path $outDir "$($crop.Name).jpg"
  $dest.Save($path, [System.Drawing.Imaging.ImageFormat]::Jpeg)
  $dest.Dispose()
  Write-Host "Saved $path"
}

foreach ($c in $crops) { Save-Crop $c }
$src.Dispose()
