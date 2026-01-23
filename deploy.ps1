# Script de Deploy para SnappyMail
# Uso: .\deploy.ps1
# Opciones:
#   .\deploy.ps1 -SkipBuild      # Omitir compilacion de assets

param(
    [switch]$SkipBuild = $false
)

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  SnappyMail - Deploy Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Lista de plugins personalizados a sincronizar
$customPlugins = @(
    "login-google-saludplus",
    "mark-external",
    "ai-overview"
)

# 1. Limpiar cache
Write-Host "[1/3] Limpiando cache..." -ForegroundColor Yellow
$cachePath = "data\_data_\_default_\cache"
if (Test-Path $cachePath) {
    Remove-Item -Path "$cachePath\*" -Recurse -Force -ErrorAction SilentlyContinue
    Write-Host "  [OK] Cache limpiado" -ForegroundColor Green
} else {
    Write-Host "  [WARN] Carpeta de cache no encontrada" -ForegroundColor Yellow
}

# 2. Sincronizar plugins personalizados
Write-Host "[2/3] Sincronizando plugins personalizados..." -ForegroundColor Yellow
$totalSynced = 0

foreach ($pluginName in $customPlugins) {
    $sourceDir = "plugins\$pluginName"
    $targetDir = "data\_data_\_default_\plugins\$pluginName"
    
    if (Test-Path $sourceDir) {
        if (-not (Test-Path $targetDir)) {
            New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
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
        $totalSynced += $syncedCount
        Write-Host "  [OK] $pluginName - $syncedCount archivo(s) sincronizado(s)" -ForegroundColor Green
    } else {
        Write-Host "  [WARN] Plugin no encontrado: $pluginName" -ForegroundColor Yellow
    }
}

Write-Host "  Total: $totalSynced archivo(s) sincronizado(s)" -ForegroundColor Cyan

# 3. Recompilar assets (si no se omite)
Write-Host "[3/4] Recompilando assets..." -ForegroundColor Yellow
if (-not $SkipBuild) {
    $gulpCheck = Get-Command npx -ErrorAction SilentlyContinue
    if ($gulpCheck) {
        Write-Host "  Ejecutando: npx gulp build" -ForegroundColor Gray
        & npx gulp build
        if ($LASTEXITCODE -eq 0 -or $LASTEXITCODE -eq $null) {
            Write-Host "  [OK] Compilacion completada" -ForegroundColor Green
        } else {
            Write-Host "  [ERROR] Error en la compilacion (codigo: $LASTEXITCODE)" -ForegroundColor Red
            exit 1
        }
    } else {
        Write-Host "  [WARN] npx no encontrado. Instala Node.js y npm primero." -ForegroundColor Yellow
        Write-Host "  [WARN] Omitiendo compilacion..." -ForegroundColor Yellow
    }
} else {
    Write-Host "  [SKIP] Omitiendo compilacion de assets..." -ForegroundColor Yellow
}

# 4. Crear carpeta release
Write-Host "[4/4] Creando carpeta release..." -ForegroundColor Yellow
$releaseDir = "release"

# Limpiar carpeta release si existe
if (Test-Path $releaseDir) {
    Remove-Item -Path $releaseDir -Recurse -Force
    Write-Host "  Carpeta release limpiada" -ForegroundColor Gray
}

New-Item -ItemType Directory -Path $releaseDir -Force | Out-Null

# Copiar archivos necesarios
Write-Host "  Copiando index.php..." -ForegroundColor Gray
Copy-Item -Path "index.php" -Destination "$releaseDir\index.php" -Force

if (Test-Path "_include.php") {
    Write-Host "  Copiando _include.php..." -ForegroundColor Gray
    Copy-Item -Path "_include.php" -Destination "$releaseDir\_include.php" -Force
}

#if (Test-Path ".release.env") {
#    Write-Host "  Copiando .release.env..." -ForegroundColor Gray
#    Copy-Item -Path ".release.env" -Destination "$releaseDir\.release.env" -Force
#}

Write-Host "  Copiando carpeta snappymail..." -ForegroundColor Gray
Copy-Item -Path "snappymail" -Destination "$releaseDir\snappymail" -Recurse -Force

Write-Host "  Copiando carpeta data (con plugins sincronizados)..." -ForegroundColor Gray
Copy-Item -Path "data" -Destination "$releaseDir\data" -Recurse -Force

# Limpiar cache en la carpeta release (no es necesario subirlo)
if (Test-Path "$releaseDir\data\_data_\_default_\cache") {
    Remove-Item -Path "$releaseDir\data\_data_\_default_\cache\*" -Recurse -Force -ErrorAction SilentlyContinue
    Write-Host "  Cache limpiado en release" -ForegroundColor Gray
}

# Calcular tamaño total
$totalSize = (Get-ChildItem -Path $releaseDir -Recurse -File | Measure-Object -Property Length -Sum).Sum / 1MB

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  [OK] Deploy completado!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Carpeta release creada: $releaseDir" -ForegroundColor Green
Write-Host "Tamaño total: $([math]::Round($totalSize, 2)) MB" -ForegroundColor White
Write-Host ""
Write-Host "Contenido de release:" -ForegroundColor Cyan
Write-Host "  - index.php" -ForegroundColor White
if (Test-Path "_include.php") {
    Write-Host "  - _include.php" -ForegroundColor White
}
#if (Test-Path ".release.env") {
#    Write-Host "  - .release.env" -ForegroundColor White
#}
Write-Host "  - snappymail/ (carpeta completa)" -ForegroundColor White
Write-Host "  - data/ (con plugins sincronizados)" -ForegroundColor White
Write-Host ""
Write-Host "Proximos pasos:" -ForegroundColor Yellow
Write-Host "  1. Copiar toda la carpeta '$releaseDir' al servidor IIS" -ForegroundColor White
Write-Host "  2. Configurar permisos de escritura en data/ (IIS_IUSRS)" -ForegroundColor White
Write-Host "  3. Verificar que funcione correctamente" -ForegroundColor White
Write-Host ""
Write-Host "Plugins incluidos en release:" -ForegroundColor Cyan
foreach ($plugin in $customPlugins) {
    Write-Host ('  - ' + $plugin) -ForegroundColor White
}
Write-Host ""
