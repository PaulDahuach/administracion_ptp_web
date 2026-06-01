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
Login + dashboard OK, conectado a datos reales (readonly). Mapa del menú legacy en
`docs/menu_legacy.md` (120 opciones, solapas CD/IC/SI/CA/VS/RE).

**Módulo HECHO: `modules/resumen_cuenta/`** (Resumen de Cuenta de Deudores, solo lectura).
Referencia: `RDN/resumen` + `RDN/cuentas/api.php`. Autocomplete cliente + desde/hasta + stats
(saldo anterior/débitos/créditos/saldo) + grilla con saldo corrido + imprimir. Validado vs
`SOPCUE`: 112/120 cuentas coinciden al centavo.

### Hallazgos del modelo de datos (CLAVE para los próximos módulos)
- `Tbl Movimientos` deudores: `CODORI='D'`, `CODCUE`, `CODOPE` (410=Remito RV no mueve cta cte,
  **420=Factura FV debe, 440=ND debe, 460=NC haber, 480=Recibo RC haber**, 500=Devolución),
  `CICMOV`(cod RV/FV/NC/ND/RC)+`CIIMOV`(letra)+`CIPMOV`(pdv)+`CINMOV`(nº), `FEXMOV` (**serial
  Access entero**, comparar numérico: `iso_to_serial`), `DEBMOV`/`CREMOV`, `DETMOV`, `DENMOV`,
  `CAEMOV`. Saldo cta cte = Σ(DEBMOV−CREMOV) sobre codopes 420/440/460/480.
- **`ESTMOV` NO es el flag de capacitación de inside.** Es booleano (True/False) y AMBOS valores
  llevan saldo real (prob. blanco/negro). Filtrar por ESTMOV da resultados INCORRECTOS → el
  resumen NO filtra por ESTMOV (validado: neto sin filtro = SOPCUE en 93%).
- `SOPCUE` (en `Tbl Cuentas Corrientes`, CODORI='D') = saldo operativo cacheado. ~7% de cuentas
  (alto volumen o especiales como cc=127 "Pendientes de Facturación") tienen drift vs el ledger
  calculado; el ledger de comprobantes es la fuente de verdad.
- `Tbl Operaciones`: CODOPE→DENOPE + ICCOPE (cod corto). Deudores=410..500, acreedores=300..350,
  internos=100..160, stock=200.

Próximo: confirmar con Paul el siguiente módulo (Emisiones requieren readwrite + AFIP).

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
