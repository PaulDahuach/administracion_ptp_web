# Administración PTP — contexto para Claude

Front web PHP del sistema de **Administración / contable** de PTP (Procesadora Textil
Parque, codcue=18). Construido con `inforemp-web-kit` (**patrón B**: abre la `.mdb` legacy
vía COM/ADODB, sin migrar datos). Hermano de `produccion_ptp` y `supervisores_ptp`, pero
del lado administrativo (no producción).

- **Repo:** (pendiente crear) github.com/PaulDahuach/administracion_ptp_web.git
- **base_url:** `/administracion_ptp` · **mode:** `readonly` (arranque seguro; pasar a
  readwrite pantalla por pantalla cuando se porten transaccionales).
- **Front-end dev:** `C:\_Inforemp\_dev_ptp\ProcesadoraTextilParque_w2.mdb` (copia del
  `2025-08-01-a-ProcesadoraTextilParque_w2.mdb`). Solo tiene tablas `Tmp/Rec` locales.
- **Backend de datos (vinculado):** `C:\_Inforemp\ProcesadoraTextilParque_d.mdb` — acá viven
  las 59 tablas `Tbl *` reales. ADO resuelve los vínculos al consultar por el front. ⚠️ Es el
  archivo de datos REAL: en `readonly` es seguro; **antes de pasar a readwrite en dev, copiar
  el backend a una ubicación dev y re-apuntar los vínculos** para no tocar el dato bueno.
- **Login:** por clave (ACCUSR único, estilo RDN). `Tbl Usuarios` cols CODUSR/DENUSR/INIUSR/
  ACCUSR/CATUSR(O=operador,S=supervisor,C=capacitación,A=auditor)/CAPUSR. Test:
  **PAUL / `dnaluap`** (supervisor) · **OPERADOR / `DNALUAPO`**. 33 usuarios.
- **VBA fuente (exportado a texto):** `C:\_inforemp\_sistemas\_ProcesadoraTextilParque\
  Administracion\{Forms,Modules,Reports}` → leer para portar lógica fiel.

## Alcance (sistema contable completo)
Tablas reales incluyen: `Tbl Movimientos` (+ IVA, Imputaciones, Referencias, Remitos,
Retenciones, Stock, Vencimientos, Anticipos), `Tbl Cuentas Corrientes` (+ Categorias,
Exclusiones), `Tbl Cuentas Contables`, `Tbl Cuentas Bancarias`, `Tbl Cheques`, `Tbl Productos`
(+ Composicion, Proveedores), `Tbl Cierres`, `Tbl Condiciones de Venta`, `Tbl Formas de Pago`,
`Tbl Monedas`, `Tbl Operaciones`, `Tbl Modelos`, `Tbl Documentos`, etc. RI → relevante WSFE/AFIP.

## Estado
Recién instanciado (login + dashboard funcionando, conectado a datos reales en readonly).
**Sin módulos propios todavía** (menú = template de ejemplo). Próximo: portar módulos desde
el VBA, empezando por los más usados — confirmar prioridad con Paul (en Producción el orden lo
marcó él: el "corazón" primero).

## Reglas técnicas (ver también CLAUDE.md del kit y de produccion_ptp)
- **PHP 5.5** target (server cliente Win 2008 R2 + WAMP 32-bit): NO `??`, `intdiv`, arrow fns,
  `match`, spread. JS ES6 OK (usan Chrome).
- **CRLF** EOL (`.gitattributes` puesto).
- **PK legacy = Long MANUAL** → `next_number('ULT<ENT>')` (lee `[Rec Control]`), NUNCA MAX+1.
- **Fechas Access = serial OLE** (base 1899-12-30): helpers del kit; escribir `#mm/dd/YYYY#`.
- **ACE NO soporta:** `UNION` en subquery de FROM (UNION ALL al tope + agregar en PHP),
  `Count(DISTINCT)`, `Nz()`. Joins anidados a la izquierda estilo Access; contar paréntesis.
- Escrituras en transacción (`db_begin/commit/rollback`); portar fiel del VBA (SetData A/M/B).
- `git -C C:\wamp64\www\administracion_ptp`. `config/system.php` NO se versiona.

## NO confundir con la carpeta `ptp` (MySQL, patrón A)
`C:\wamp64\www\ptp\` es un intento ANTERIOR de migrar Administración a MySQL (clon de
inforemp-template). Quedó **parado** (referencia / meta a largo plazo). La vía activa para
Administración es ESTA (patrón B). El de MySQL sigue registrado como id=34 en inside_desarrollos.

## Historia general PTP
Memoria de **inforemp_inside**:
`C:\Users\pauld\.claude\projects\C--wamp64-www-inforemp-inside\memory\inforemp_web_kit.md`.
