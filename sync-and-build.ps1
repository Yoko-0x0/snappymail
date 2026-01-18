# Script para sincronizar plugins y recompilar SnappyMail
# Uso: .\sync-and-build.ps1

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  SnappyMail - Sync & Build Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# 1. Limpiar cache
Write-Host "[1/3] Limpiando cache..." -ForegroundColor Yellow
$cachePath = "data\_data_\_default_\cache"
if (Test-Path $cachePath) {
    Remove-Item -Path "$cachePath\*" -Recurse -Force -ErrorAction SilentlyContinue
    Write-Host "  Cache limpiado" -ForegroundColor Green
} else {
    Write-Host "  Carpeta de cache no encontrada" -ForegroundColor Yellow
}

# 2. Sincronizar archivos del plugin
Write-Host "[2/3] Sincronizando archivos del plugin..." -ForegroundColor Yellow
$pluginName = "login-google-saludplus"
$sourceDir = "plugins\$pluginName"
$targetDir = "data\_data_\_default_\plugins\$pluginName"

if (Test-Path $sourceDir) {
    if (-not (Test-Path $targetDir)) {
        New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
        Write-Host "  Directorio creado: $targetDir" -ForegroundColor Green
    }
    
    $files = Get-ChildItem -Path $sourceDir -File -Recurse
    $syncedCount = 0
    foreach ($file in $files) {
        $relativePath = $file.FullName.Substring((Resolve-Path $sourceDir).Path.Length + 1)
        $targetPath = Join-Path $targetDir $relativePath
        
        $targetParent = Split-Path $targetPath -Parent
        if (-not (Test-Path $targetParent)) {
            New-Item -ItemType Directory -Path $targetParent -Force | Out-Null
        }
        
        Copy-Item -Path $file.FullName -Destination $targetPath -Force
        $syncedCount++
    }
    Write-Host "  $syncedCount archivo(s) sincronizado(s)" -ForegroundColor Green
} else {
    Write-Host "  Carpeta de origen no encontrada: $sourceDir" -ForegroundColor Yellow
}

# Sincronizar mark-external
$pluginName = "mark-external"
$sourceDir = "plugins\$pluginName"
$targetDir = "data\_data_\_default_\plugins\$pluginName"
if (Test-Path $sourceDir) {
    if (-not (Test-Path $targetDir)) { New-Item -ItemType Directory -Path $targetDir -Force | Out-Null }
    Copy-Item -Path "$sourceDir\*" -Destination $targetDir -Recurse -Force
    Write-Host "  mark-external sincronizado" -ForegroundColor Green
}

# Sincronizar ai-overview
$pluginName = "ai-overview"
$sourceDir = "plugins\$pluginName"
$targetDir = "data\_data_\_default_\plugins\$pluginName"
if (Test-Path $sourceDir) {
    if (-not (Test-Path $targetDir)) { New-Item -ItemType Directory -Path $targetDir -Force | Out-Null }
    Copy-Item -Path "$sourceDir\*" -Destination $targetDir -Recurse -Force
    Write-Host "  ai-overview sincronizado" -ForegroundColor Green
}

# 3. Recompilar proyecto
Write-Host "[3/3] Recompilando proyecto..." -ForegroundColor Yellow
$gulpCheck = Get-Command npx -ErrorAction SilentlyContinue
if ($gulpCheck) {
    Write-Host "  Ejecutando: npx gulp build" -ForegroundColor Gray
    & npx gulp build
    if ($LASTEXITCODE -eq 0 -or $LASTEXITCODE -eq $null) {
        Write-Host "  Compilacion completada" -ForegroundColor Green
    } else {
        Write-Host "  Error en la compilacion (codigo: $LASTEXITCODE)" -ForegroundColor Red
    }
} else {
    Write-Host "  npx no encontrado. Instala Node.js y npm primero." -ForegroundColor Yellow
    Write-Host "  Omitiendo compilacion..." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Proceso completado!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Proximos pasos:" -ForegroundColor Yellow
Write-Host "  1. Recarga la pagina con Ctrl + Shift + R" -ForegroundColor White
Write-Host "  2. Verifica que el plugin funcione correctamente" -ForegroundColor White
Write-Host ""
