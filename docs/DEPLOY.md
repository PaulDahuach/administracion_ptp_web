# Administración PTP — Instalación y puesta en producción

Front web del sistema **Administración / contable** de PTP (Procesadora Textil Parque).
Corre **en una PC Windows** (la del cliente o un servidor de su red) y abre la **misma
`.mdb`** que usa el sistema de escritorio, vía COM/ADODB. **No migra datos**: el escritorio
y la web conviven sobre el mismo archivo.

```
Navegador ──HTTP(S)──> Apache+PHP (Windows) ──COM/ADODB──> ProcesadoraTextilParque_d.mdb
                                                              (+ backend ...­_d.mdb vinculado)
                                                            <── App de escritorio (Access)
```

> **Solo Windows.** El driver `Microsoft.ACE.OLEDB.12.0` no existe en Linux (por eso NO va en
> ferozo). Va en una PC con la `.mdb`. Es el **mismo patrón que Producción/Supervisores PTP**, que
> ya corren en esa PC — esto se suma al lado.

---

## 1. Requisitos en la PC (ya cumplidos si Producción PTP corre ahí)

1. **WAMP** (Apache + PHP). En la PC de PTP es **PHP 5.5** → este código es compatible 5.5.
2. Extensión **COM** habilitada en `php.ini`: `extension=php_com_dotnet`.
3. **Access Database Engine** (`Microsoft.ACE.OLEDB.12.0`) de arquitectura que coincida con PHP
   (PHP 32-bit → ACE **x86**). Ya instalado para Producción.
4. La cuenta que corre Apache con acceso de **lectura** al `.mdb` administrativo y su backend.

## 2. Instalación (primer deploy)

1. Copiar la carpeta `administracion_ptp/` a la raíz web del WAMP del cliente
   (ej. `C:\wamp64\www\administracion_ptp\`), o descomprimir el ZIP de deploy ahí.
2. Editar `config/system.php` → **`mdb_path`** con la ruta REAL del archivo de DATOS en esa PC:
   **`ProcesadoraTextilParque_d.mdb`** (el backend con las tablas reales). Apuntamos DIRECTO al
   `_d.mdb`, no al front-end `_w2.mdb` — así no depende de que el vínculo resuelva.
   Default del paquete: `C:\_Inforemp\ProcesadoraTextilParque_d.mdb` (verificar que sea la ruta real).
3. Listo: PHP + librerías por CDN, sin build ni dependencias.

## 3. Configuración (`config/system.php`)

```php
return [
    'base_url'    => '/administracion_ptp',                 // o '' si vive en la raíz del host
    'name'        => 'Administración PTP',
    'mdb_path'    => 'C:\\ruta\\real\\ProcesadoraTextilParque_d.mdb',  // ⟨EDITAR: ruta del cliente⟩
    'mdb_provider'=> 'Microsoft.ACE.OLEDB.12.0',
    'mode'        => 'readonly',                            // ARRANCA EN SOLO LECTURA (cero riesgo)
    'auth' => ['table'=>'Tbl Usuarios','col_id'=>'CODUSR','col_name'=>'DENUSR','col_pass'=>'ACCUSR'],
    'deploy_key'  => '⟨clave-larga-única⟩',                 // para futuras actualizaciones por deploy.php
    // ...
];
```

- **Arranca en `readonly`**: todos los módulos son de consulta (cta cte, IVA, contabilidad,
  cheques, bancos), así que readonly es lo correcto — **no escribe nada sobre el dato del cliente**.
- Login: por clave (ACCUSR de `Tbl Usuarios`). Probar con un usuario real del cliente.

## 4. Verificación post-instalación

1. Abrir `http://localhost/administracion_ptp/` en esa PC → login.
2. Probar **Saldos Actuales (Deudores)** y un **Resumen de Cuenta** → comparar contra el Access.
3. Probar **IVA Ventas** de un mes cerrado → comparar el total con el del escritorio.
4. Si todo cuadra, sumar el acceso al **Portal de Sistemas** (`ptp_portal`, array `$SISTEMAS`).

## 5. Actualizaciones (después del primer deploy)

Una vez instalado, las actualizaciones van por HTTP a `deploy.php` con la `deploy_key`:
```bash
curl -X POST http://<pc>/administracion_ptp/deploy.php -F "key=CLAVE" -F "zipfile=@deploy.zip"
```

## Notas

- 14 módulos de consulta, todos **validados** contra los datos reales y cruzando entre sí
  (cta cte ↔ IVA ↔ contabilidad ↔ cheques ↔ bancos). Ver `CLAUDE.md`.
- El IVA Ventas/Compras trae el split exacto por alícuota (base F2002).
- `config/system.php` NO se versiona (lleva la ruta y la clave de cada instalación).
