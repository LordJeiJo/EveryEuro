# EveryEuro

App ligera para registrar movimientos financieros personales con PHP + SQLite.

## Requisitos

- PHP 8+
- SQLite habilitado
- Servidor Apache o similar

## Instalación rápida

1. Copia la carpeta al hosting.
2. Asegúrate de que `/data` y `/backups` tengan permisos de escritura.
3. Edita `config.php` y actualiza las credenciales de admin. Por defecto es `admin` / `admin123` (cámbialo inmediatamente):

```php
'admin_user' => 'tu_usuario',
'admin_pass_hash' => password_hash('tu_clave', PASSWORD_DEFAULT),
```

4. Accede a `/index.php` y haz login.

## Backup

- **Exportar:** botón en la sección Backup. Descarga un JSON con movimientos, categorías y reglas.
- **Importar:** sube un JSON exportado. La importación sobrescribe todo.

## Seguridad

- CSRF tokens en todos los formularios.
- `/data` y `/backups` protegidos con `.htaccess`.
- Errores no visibles en pantalla.

## Estructura

```
/index.php
/config.php
/src/bootstrap.php
/assets/styles.css
/assets/app.js
/data/
/backups/
```

## Nota

La app inicia con un set mínimo de categorías que puedes editar o reordenar desde la sección Categorías.
