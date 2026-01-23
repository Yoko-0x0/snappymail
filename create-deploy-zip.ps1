# Script para crear ZIP de deploy
# Uso: .\create-deploy-zip.ps1

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Creando ZIP de Deploy" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Primero ejecutar deploy para asegurar que todo esté actualizado
Write-Host "[1/3] Ejecutando deploy..." -ForegroundColor Yellow
& .\deploy.ps1 -SkipBuild
if ($LASTEXITCODE -ne 0 -and $LASTEXITCODE -ne $null) {
    Write-Host "  [ERROR] Deploy fallo. Continuando de todos modos..." -ForegroundColor Yellow
}

# Crear carpeta temporal para el ZIP
$tempDir = "deploy-temp"
$zipFile = "snappymail-deploy-$(Get-Date -Format 'yyyyMMdd-HHmmss').zip"

Write-Host "[2/3] Preparando archivos..." -ForegroundColor Yellow

# Limpiar carpeta temporal si existe
if (Test-Path $tempDir) {
    Remove-Item -Path $tempDir -Recurse -Force
}
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null

# Copiar archivos necesarios
Write-Host "  Copiando index.php..." -ForegroundColor Gray
Copy-Item -Path "index.php" -Destination "$tempDir\index.php" -Force

if (Test-Path "_include.php") {
    Write-Host "  Copiando _include.php..." -ForegroundColor Gray
    Copy-Item -Path "_include.php" -Destination "$tempDir\_include.php" -Force
}

Write-Host "  Copiando carpeta snappymail..." -ForegroundColor Gray
Copy-Item -Path "snappymail" -Destination "$tempDir\snappymail" -Recurse -Force

Write-Host "  Copiando carpeta data..." -ForegroundColor Gray
Copy-Item -Path "data" -Destination "$tempDir\data" -Recurse -Force

# Limpiar cache antes de comprimir
if (Test-Path "$tempDir\data\_data_\_default_\cache") {
    Write-Host "  Limpiando cache en ZIP..." -ForegroundColor Gray
    Remove-Item -Path "$tempDir\data\_data_\_default_\cache\*" -Recurse -Force -ErrorAction SilentlyContinue
}

# Crear ZIP
Write-Host "[3/3] Creando archivo ZIP..." -ForegroundColor Yellow
Compress-Archive -Path "$tempDir\*" -DestinationPath $zipFile -Force

# Limpiar carpeta temporal
Remove-Item -Path $tempDir -Recurse -Force

# Calcular tamaño
$zipSize = (Get-Item $zipFile).Length / 1MB
Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  [OK] ZIP creado exitosamente!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Archivo: $zipFile" -ForegroundColor White
Write-Host "Tamaño: $([math]::Round($zipSize, 2)) MB" -ForegroundColor White
Write-Host ""
Write-Host "Proximos pasos:" -ForegroundColor Yellow
Write-Host "  1. Subir el ZIP al servidor" -ForegroundColor White
Write-Host "  2. Descomprimir en el directorio web" -ForegroundColor White
Write-Host "  3. Configurar permisos (chmod 755 para data/)" -ForegroundColor White
Write-Host "  4. Verificar que funcione correctamente" -ForegroundColor White
Write-Host ""
