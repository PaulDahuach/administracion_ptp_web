# inforemp-web-kit

Esqueleto reutilizable para dar un **front web** a los sistemas legacy de Inforemp
(VB6 / Access) **sin migrar datos**: el PHP abre la misma `.mdb`/`.accdb` que usa
el sistema de escritorio, vía COM/ADODB. Ambas interfaces conviven sobre el mismo
dato vivo → los clientes migran a la web de a poco.

Extraído del patrón ya probado en RDN (`rdnoroeste2.ddns.net`).

---

## Arquitectura

```
Navegador ──HTTPS──> WAMP/Apache+PHP ──COM/ADODB──> Sistema.mdb  <──── App legacy (VB6/Access)
                         (Windows)                  (mismo archivo)
```

- **Solo Windows**: el driver `Microsoft.ACE.OLEDB.12.0` no existe en Linux.
- **Modo `readonly`** por defecto: cero riesgo de bloqueos con el legacy. La
  escritura se habilita pantalla por pantalla cambiando `mode` a `readwrite`.

## Estructura

```
config/
  system.example.php   ← plantilla; copiar a system.php (NO se versiona)
  system.php           ← ÚNICO archivo a editar por sistema
includes/
  db.php               ← conexión ADO + db_query/db_row/db_lookup/db_exec
  helpers.php          ← ok/fail, fechas Access, money, h()
  auth.php             ← login contra la tabla de usuarios del legacy
  layout.php           ← shell de página (module_head/module_foot)
api/auth.php           ← endpoint login/logout
app/login.php          ← pantalla de login (branding desde config)
app/index.php          ← dashboard (menú desde config)
modules/_template/     ← plantilla de módulo (copiar para cada pantalla)
modules/abm/           ← ABM genérico config-driven (CRUD form-first; editar defs.php)
modules/reportes/      ← motor de listados config-driven (DataTable + impresión; editar defs.php)
assets/                ← css/js compartidos
deploy.php             ← subida de archivos por curl (clave desde config)
```

## Requisitos en el servidor (Windows)

1. **WAMP** (o Apache + PHP 7.4). Habilitar la extensión COM en `php.ini`:
   ```
   extension=php_com_dotnet
   ```
2. **Access Database Engine** (provee `Microsoft.ACE.OLEDB.12.0`):
   descargar "Microsoft Access Database Engine 2016 Redistributable".
   La arquitectura (x86/x64) debe coincidir con la de PHP.
3. Acceso de lectura (y escritura si `readwrite`) al archivo `.mdb`.

## Poner en marcha un sistema nuevo

```powershell
# 1. Clonar el kit a la carpeta del cliente
git clone <repo> C:\wamp64\www\<sistema>
cd C:\wamp64\www\<sistema>

# 2. Configurar
copy config\system.example.php config\system.php
#   editar config\system.php: name, mdb_path, auth, menu, deploy_key
```

Probar en local: `http://localhost/<sistema>/` → debería redirigir al login.

## Modelo de infraestructura (mixto)

Según el cliente:

- **En la PC del cliente** (como RDN): WAMP local + la `.mdb` que ya usan.
  Exponer con **DDNS** (No-IP / DuckDNS) + **Certbot** (win-acme) para HTTPS.
  Deploy remoto con `deploy.php`.
- **En servidor central tuyo**: traer/sincronizar la `.mdb` del cliente.
  Más fácil de mantener, pero centraliza el riesgo y requiere copiar datos.

### HTTPS con Certbot (win-acme)
Apuntar el DDNS al IP del servidor, abrir el puerto 443, y emitir el certificado
con win-acme contra el vhost de Apache. (Ver `c:\Certbot` en el server de RDN
como referencia de una instalación que ya funciona.)

## Flujo para portar una pantalla (con IA)

1. Exportar el VBA del form con `export_vba.ps1` (ya hay carpetas
   `VBA_Source_*` para varios sistemas).
2. Copiar `modules/_template` → `modules/<pantalla>`.
3. Darle a la IA el `.frm`/VBA + el esquema de tablas; que genere `api.php`
   (consultas) y la vista. Empezar **solo-lectura**.
4. Registrar la pantalla en `config/system.php → menu`.
5. Validar contra una **copia** de la `.mdb` antes de tocar la viva.

## Seguridad

- La clave de usuarios viaja/queda en texto plano en el legacy: **HTTPS obligatorio**.
- `config/system.php` y `config/certs/` están en `.gitignore`.
- Cambiar `deploy_key` por instalación.
