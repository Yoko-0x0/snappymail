# Guía de Deploy de SnappyMail

## Archivos y carpetas que DEBES subir al servidor

### Estructura mínima requerida:

```
/var/www/webmail/                    (o tu directorio web)
├── index.php                        ✅ OBLIGATORIO - Punto de entrada
├── _include.php                     ✅ Si existe
├── snappymail/                      ✅ OBLIGATORIO - Código de la aplicación
│   └── v/
│       └── 0.0.0/                   (o tu versión)
│           ├── include.php
│           ├── app/
│           ├── static/
│           └── ...
└── data/                            ✅ OBLIGATORIO - Datos de la aplicación
    └── _data_/
        └── _default_/
            ├── plugins/             (plugins instalados)
            ├── configs/
            ├── cache/
            └── ...
```

### Detalle de carpetas:

#### ✅ **Carpetas OBLIGATORIAS:**

1. **`index.php`** (raíz)
   - Archivo principal de entrada
   - **Debe estar accesible desde el navegador**

2. **`snappymail/`** (carpeta completa)
   - Contiene todo el código de la aplicación
   - **Incluye `snappymail/v/0.0.0/` con todo su contenido**

3. **`data/`** (carpeta completa)
   - Datos de configuración
   - Plugins instalados: `data/_data_/_default_/plugins/`
   - Cache: `data/_data_/_default_/cache/`
   - **Debe tener permisos de escritura (755 o 775)**

#### ❌ **NO subir al servidor:**

- `dev/` - Archivos de desarrollo
- `node_modules/` - Solo para compilar
- `build/` - Archivos de build
- `plugins/` - Código fuente (solo se suben los instalados)
- `vendors/` - Si existe (solo para desarrollo)
- `examples/` - Ejemplos
- `test/` - Tests
- `cli/` - Scripts de línea de comandos
- `integrations/` - Integraciones (NextCloud, OwnCloud, etc.)
- `deploy.ps1`, `sync-and-build.ps1` - Scripts de desarrollo
- `package.json`, `gulpfile.js`, etc. - Solo para desarrollo
- `.git/` - Control de versiones
- Archivos `.md` de documentación

## Proceso de Deploy

### 1. Preparar archivos localmente:

```powershell
# 1. Ejecutar deploy para compilar y sincronizar
.\deploy.ps1

# Esto hará:
# - Limpiar cache
# - Sincronizar plugins a data/_data_/_default_/plugins/
# - Compilar assets (JS/CSS minificados)
```

### 2. Subir al servidor:

**Opción A: Subir todo manualmente**
```
Subir estas carpetas/archivos:
├── index.php
├── _include.php (si existe)
├── snappymail/ (completa)
└── data/ (completa)
```

**Opción B: Usar FTP/SFTP/SCP**
```bash
# Ejemplo con SCP (Linux/Mac)
scp -r index.php snappymail/ data/ usuario@servidor:/var/www/webmail/

# Ejemplo con WinSCP (Windows)
# Arrastrar las carpetas: index.php, snappymail/, data/
```

**Opción C: Crear ZIP para subir**
```powershell
# Script para crear ZIP de deploy (crear create-deploy-zip.ps1)
Compress-Archive -Path index.php,snappymail,data -DestinationPath snappymail-deploy.zip -Force
```

### 3. Configurar permisos en el servidor:

```bash
# Permisos para archivos
find /var/www/webmail/snappymail -type f -exec chmod 644 {} \;
find /var/www/webmail/snappymail -type d -exec chmod 755 {} \;

# Permisos para carpeta data (debe ser escribible)
chmod -R 755 /var/www/webmail/data
# O mejor aún, 775 si el servidor web lo permite
chmod -R 775 /var/www/webmail/data

# Propietario (ajustar según tu configuración)
chown -R www-data:www-data /var/www/webmail/data
chown -R www-data:www-data /var/www/webmail/snappymail
```

### 4. Configurar servidor web:

#### **Apache (.htaccess):**
SnappyMail incluye `.htaccess` en:
- `snappymail/v/0.0.0/static/apache.htaccess`
- `snappymail/v/0.0.0/app/.htaccess`

Asegúrate de que `mod_rewrite` esté activado:
```apache
a2enmod rewrite
```

#### **Nginx:**
Ver configuración en `.docker/release/files/etc/nginx/nginx.conf`

#### **IIS (Windows Server):**
- Configurar `web.config` para rewrite
- PHP debe estar instalado (7.4+)

## Verificación post-deploy

1. **Acceder a la aplicación:**
   ```
   http://tu-servidor.com/webmail/
   ```

2. **Acceder al panel de admin:**
   ```
   http://tu-servidor.com/webmail/?admin
   ```

3. **Verificar plugins instalados:**
   - Admin Panel → Extensions
   - Deberías ver: `login-google-saludplus`, `mark-external`, `ai-overview`

4. **Verificar permisos:**
   - Asegúrate de que `data/` sea escribible
   - Los logs deberían generarse en `data/_data_/_default_/logs/`

## Actualización futura

Para actualizar SnappyMail en producción:

1. **Backup:**
   ```bash
   cp -r data/_data_/_default_/ data/_data_/_default_.backup.$(date +%Y%m%d)
   ```

2. **Subir nueva versión:**
   - Reemplazar `snappymail/` completa
   - Reemplazar `index.php` (si cambió)
   - **NO reemplazar `data/`** (mantener configuración y plugins)

3. **Limpiar cache:**
   ```bash
   rm -rf data/_data_/_default_/cache/*
   ```

4. **Verificar:**
   - Probar login
   - Verificar que los plugins sigan funcionando

## Notas importantes

- **`data/` debe estar fuera del webroot si es posible** (más seguro)
- Los plugins personalizados están en `data/_data_/_default_/plugins/`
- El cache se genera automáticamente en `data/_data_/_default_/cache/`
- No subas la carpeta `dev/` al servidor (es muy pesada y innecesaria)

## Troubleshooting

**Error: "Missing snappymail/v/0.0.0/include.php"**
- Verifica que la carpeta `snappymail/` esté completa
- Verifica permisos de lectura

**Error: "Permission denied"**
- Verifica permisos de escritura en `data/`
- Verifica propietario de archivos

**Plugins no aparecen:**
- Verifica que estén en `data/_data_/_default_/plugins/`
- Limpia cache: `rm -rf data/_data_/_default_/cache/*`
