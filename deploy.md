# Deploy en servidor Linux Debian

Guía completa para instalar y poner en marcha el servicio `sync.php` en un servidor Debian (10/11/12).

---

## Requisitos previos

- Acceso SSH al servidor con usuario `sudo` o `root`
- Debian 10 (Buster), 11 (Bullseye) o 12 (Bookworm)
- El archivo `datos.db` debe existir en el servidor (copiarlo o generarlo allí)

---

## 1. Instalar PHP 8.4 CLI

Debian no incluye PHP 8.4 en sus repos oficiales. Se usa el repositorio de Sury (el más confiable para PHP en Debian).

```bash
# Dependencias para agregar el repo
sudo apt update
sudo apt install -y lsb-release apt-transport-https ca-certificates curl

# Agregar la clave GPG y el repositorio de Sury
curl -sSLo /tmp/debsuryorg-archive-keyring.gpg https://packages.sury.org/php/apt.gpg
sudo cp /tmp/debsuryorg-archive-keyring.gpg /usr/share/keyrings/debsuryorg-archive-keyring.gpg

echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
    | sudo tee /etc/apt/sources.list.d/php.list

# Instalar PHP 8.4 CLI y las extensiones necesarias
sudo apt update
sudo apt install -y php8.4-cli php8.4-sqlite3 php8.4-curl php8.4-mbstring
```

Verificar instalación:
```bash
php8.4 --version
# PHP 8.4.x (cli) ...
```

---

## 2. Instalar Composer

```bash
# Descargar e instalar Composer globalmente
curl -sS https://getcomposer.org/installer | php8.4
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer

composer --version
# Composer version 2.x.x
```

---

## 3. Crear la carpeta del proyecto

```bash
# Crear directorio (ajustar la ruta según prefieras)
sudo mkdir -p /opt/servicio-envio
sudo chown $USER:$USER /opt/servicio-envio
```

---

## 4. Subir los archivos al servidor

Desde tu máquina local (Windows), usar `scp` o `rsync`:

```bash
# Con scp — desde PowerShell o Git Bash en tu PC
scp -r C:/Users/erebo/Herd/servicio-envio/* usuario@IP_SERVIDOR:/opt/servicio-envio/

# O con rsync (excluye vendor/ y storage/ para transferir solo el código)
rsync -avz --exclude='vendor/' --exclude='storage/' --exclude='.env' \
    C:/Users/erebo/Herd/servicio-envio/ usuario@IP_SERVIDOR:/opt/servicio-envio/
```

> **Nota:** El archivo `datos.db` debe copiarse también, o bien ya existirá en el servidor si el sistema sensor corre ahí.

---

## 5. Instalar dependencias con Composer

```bash
cd /opt/servicio-envio
composer install --no-dev --optimize-autoloader
```

---

## 6. Crear el directorio de storage

```bash
mkdir -p /opt/servicio-envio/storage
chmod 755 /opt/servicio-envio/storage
```

---

## 7. Configurar el archivo .env

```bash
cp /opt/servicio-envio/.env.example /opt/servicio-envio/.env
nano /opt/servicio-envio/.env
```

Completar con los valores reales (rutas en Linux con `/`):

```dotenv
API_URL=https://tu-api.ejemplo.com/api/measurements
API_KEY_HEADER_NAME=X-Api-Key
API_KEY=tu-api-key-real

BATCH_SIZE=50
HTTP_TIMEOUT=8

DB_PATH=/opt/servicio-envio/datos.db
LOCK_FILE=/opt/servicio-envio/storage/sync.lock
LOG_FILE=/opt/servicio-envio/storage/sync.log
LOG_MAX_BYTES=1048576
```

Asegurarse de que el `.env` no sea legible por otros usuarios:
```bash
chmod 600 /opt/servicio-envio/.env
```

---

## 8. Ajustar permisos del proyecto

```bash
# El usuario que corre el cron debe poder leer/escribir datos.db y storage/
sudo chown -R $USER:$USER /opt/servicio-envio
chmod 664 /opt/servicio-envio/datos.db
chmod 755 /opt/servicio-envio/storage
```

---

## 9. Probar el script manualmente

```bash
cd /opt/servicio-envio
php8.4 sync.php
```

Revisar el log:
```bash
cat /opt/servicio-envio/storage/sync.log
```

Salida esperada:
```
2026-04-12 10:00:00 [INFO] Run completo. Procesados=50 Enviados=50 Fallidos=0
```

Si hay errores de conexión a la API durante la prueba, verás:
```
2026-04-12 10:00:00 [WARN] Fallo al enviar ID=1
...
2026-04-12 10:00:00 [INFO] Run completo. Procesados=50 Enviados=0 Fallidos=50
```

---

## 10. Configurar Crontab (cada 10 segundos)

El cron mínimo es 1 minuto, por eso se encadenan 6 entradas con `sleep`:

```bash
crontab -e
```

Agregar al final (ajustar la ruta de PHP si es diferente):

```crontab
* * * * * /usr/bin/php8.4 /opt/servicio-envio/sync.php
* * * * * sleep 10 && /usr/bin/php8.4 /opt/servicio-envio/sync.php
* * * * * sleep 20 && /usr/bin/php8.4 /opt/servicio-envio/sync.php
* * * * * sleep 30 && /usr/bin/php8.4 /opt/servicio-envio/sync.php
* * * * * sleep 40 && /usr/bin/php8.4 /opt/servicio-envio/sync.php
* * * * * sleep 50 && /usr/bin/php8.4 /opt/servicio-envio/sync.php
```

Verificar que el cron quedó registrado:
```bash
crontab -l
```

---

## 11. Verificar que el servicio corre

Esperar 1 minuto después de guardar el crontab y revisar el log:

```bash
tail -f /opt/servicio-envio/storage/sync.log
```

También se puede verificar cuántos registros ya fueron enviados:
```bash
sqlite3 /opt/servicio-envio/datos.db "SELECT COUNT(*) FROM mediciones WHERE sync_status = 1;"
```

---

## Comandos útiles de mantenimiento

```bash
# Ver los últimos registros del log
tail -50 /opt/servicio-envio/storage/sync.log

# Cuántos registros faltan por enviar
sqlite3 /opt/servicio-envio/datos.db "SELECT COUNT(*) FROM mediciones WHERE sync_status = 0;"

# Cuántos ya fueron enviados
sqlite3 /opt/servicio-envio/datos.db "SELECT COUNT(*) FROM mediciones WHERE sync_status = 1;"

# Eliminar el lock manualmente si quedó trabado
rm -f /opt/servicio-envio/storage/sync.lock

# Pausar el servicio (comentar las líneas del cron)
crontab -e

# Reenviar TODOS los registros desde cero (¡usar con cuidado!)
sqlite3 /opt/servicio-envio/datos.db "UPDATE mediciones SET sync_status = 0;"
```

---

## Solución de problemas comunes

| Síntoma | Causa probable | Solución |
|---|---|---|
| `vendor/autoload.php: No such file` | No se corrió `composer install` | Correr `composer install` en el directorio del proyecto |
| `Cannot open database` | Ruta incorrecta en `.env` o permisos | Verificar `DB_PATH` y permisos con `ls -la datos.db` |
| `[WARN] Fallo al enviar ID=X` repetido | API inaccesible o clave incorrecta | Verificar `API_URL` y `API_KEY` en `.env` |
| Lock file stale frecuente | Script tardando más de 10s | Reducir `BATCH_SIZE` o aumentar `HTTP_TIMEOUT` |
| Crontab no ejecuta | PHP no encontrado en la ruta | Usar ruta absoluta: `which php8.4` para verificarla |
| `Permission denied` en datos.db | El usuario del cron no tiene acceso | `chown usuario:usuario datos.db` |
