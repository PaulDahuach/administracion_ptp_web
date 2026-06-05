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

**Menú dashboard = tabstrip estilo legacy** (`app/index.php` + `.menu-tab*`/`.mpanel-*` en
`app.css`): una solapa por sección del config `menu`; click muestra solo ese grupo; el buscador
(Ctrl+K) entra en "modo búsqueda" (todas las solapas filtradas) y vuelve a la activa al limpiar.
Estrategia elegida con Paul: **listar el mapa COMPLETO del legacy**, con los construidos como link
y los no portados como `'disabled'=>true` (gris, badge "pronto"). Sub-secciones del legacy
(ACTUALIZACIONES/PROCESOS/LISTADOS) con `['head'=>..]`. Tarjetas admin-only con `'admin'=>true`
(config `admin_users`; vacía = nadie). Contador de la solapa = construidos/total. El `menu` vive en
`config/system.php` (NO versionado → replicar a mano en deploy); flags documentados en
`system.example.php`. **Mapa legacy completo poblado** en las 6 solapas (orden Access, omitiendo
Producción/Sucursales vacías): Imputaciones Contables (4/28), Stock (0/18), Acreedores (2/15),
Deudores (3/35), Resumen (3/8), Varios (3/22) = 15 módulos construidos. IVA Ventas vive en Deudores
e IVA Compras en Varios (no hay solapa IVA aparte, como el legacy). "Búsqueda de Comprobantes" y
"Estadísticas de Uso" (admin) van como extras web bajo sub-encabezados "(versión web)". Un
sub-encabezado que queda sin opciones (p.ej. tras filtrar admin) no se renderiza.

**Módulos HECHOS (solo lectura):**
- `modules/resumen_cuenta/` + `modules/resumen_cuenta_acr/` — Resumen de cta cte (deudor /
  acreedor). Autocomplete + desde/hasta + selector **Libro (Todos/Blanco/Negro)** + stats + saldo
  corrido + imprimir. Validado vs SOPCUE. Ref: `RDN/resumen`+`RDN/cuentas/api.php`.
- `modules/saldos_actuales/` + `modules/saldos_actuales_acr/` — "Quién me debe / a quién le debo":
  fila por cuenta con **Blanco/Negro/Total** (DataTable, click → resumen). Deudores: 166, Total
  Blanco $27.9M/Negro $81.4M. Acreedores: 42, Total a Pagar $27.1M.
- **Acreedores = espejo de deudores** con `CODORI='A'` + codopes **310 CP,320 NC,330 ND,340 OP,
  350 CancAntic** (300 Remito no mueve). Misma fórmula saldo=Σ(DEBMOV−CREMOV); aquí **negativo =
  le debemos** (color invertido). Validado vs SOPCUE 42/45.
- **OJO DataTables:** las columnas numéricas con formato es-AR necesitan `columnDefs:[{targets,
  type:'num'}]` o el orden sale mal (no respeta data-order). Aplicado en ambos saldos.
- `modules/iva_ventas/` — Libro **I.V.A. Ventas** por período + libro blanco/negro/todos. Porta
  `Rpt CD IVA`. Inclusión por **`Tbl Operaciones Auxiliares.IVAAUX=True`** (JOIN por CODAUX, NO por
  codope). Columnas (NC=460 negado): Neto=NETMOV, IVA=IRIMOV, NoGrav=NOGMOV, Ajuste=ABIMOV+ARDMOV,
  Percep.IIBB=PIXMOV, Total=TOTMOV. Cond.IVA=INICRI (Tbl Categorias Resp. IVA por CODCRI). Resumen
  por comprobante+alícuota EXACTO desde **`Tbl Movimientos IVA`** (cols NUMMOV/ALIMOV/NETMOV/IRIMOV/
  DECMOV; join por NUMMOV, GROUP BY CICMOV/CODOPE/ALIMOV — `resumen_alicuotas()`). Reconcilia: Σ IVA
  por alícuota = IVA del header. Validado vs PDF Ago-2023 al centavo (los 60 comps que existen; 2 FV
  del PDF ya no existen en el backend 2025 = data drift, NO bug). PDFs: `_ProcesadoraTextilParque\
  2023-08 IVA Ventas *.pdf`. (OJO: en ACE NO usar `NZ()`/`CCur()` vía ADO → com_exception.)
- `modules/iva_compras/` — Libro **I.V.A. Compras** (Crédito Fiscal). Porta `Rpt 00 IVA` (Caption
  "I.V.A. Compras"). Diferencias vs ventas: fecha=**FIXMOV**, **CODORI IN ('A','I')** (acreedores +
  internos), inclusión=**A.IVAAUX=True OR O.IVAOPE=True** (join a Tbl Operaciones por CODOPE),
  negación cuando **CODAUX=139 o CODOPE=330**, dos percepciones **IP1MOV (Percep IVA) / IP2MOV
  (Percep IIBB)**. Sin PDF de validación (solo ventas) → revisado por coherencia. Multi-alícuota
  (10.5/21/27%). Resumen por alícuota EXACTO desde `Tbl Movimientos IVA` (LEFT JOIN; ALIMOV null =
  monotributo/exento → 0%). Reconcilia Σ IVA = header.

### Hallazgos del modelo de datos (CLAVE para los próximos módulos)
- `Tbl Movimientos` deudores: `CODORI='D'`, `CODCUE`, `CODOPE` (410=Remito RV no mueve cta cte,
  **420=Factura FV debe, 440=ND debe, 460=NC haber, 480=Recibo RC haber**, 500=Devolución),
  `CICMOV`(cod RV/FV/NC/ND/RC)+`CIIMOV`(letra)+`CIPMOV`(pdv)+`CINMOV`(nº), `FEXMOV` (**serial
  Access entero**, comparar numérico: `iso_to_serial`), `DEBMOV`/`CREMOV`, `DETMOV`, `DENMOV`,
  `CAEMOV`. Saldo cta cte = Σ(DEBMOV−CREMOV) sobre codopes 420/440/460/480.
- **`ESTMOV` = dual-ledger BLANCO (−1/True) / NEGRO (0/False)** — NO es capacitación como en
  inside. CONFIRMADO en el VBA (`Frm CD Facturas/Recibos`): `SOCMOV = DSum(DEBMOV) − DSum(CREMOV)`
  **filtrado por `[ESTMOV]=IIf(Me.ESTMOV,-1,0)`** → el saldo se lleva por libro SEPARADO. El
  `chkEst` del Menú elige en cuál se opera. SOPCUE (cacheado) = blanco + negro = total.
  ⇒ Los reportes de cta cte respetan estmov: `saldos_actuales` muestra Blanco/Negro/Total por
  columna; `resumen_cuenta` tiene selector Todos/Blanco/Negro (param `libro` en la API).
- `SOPCUE` (en `Tbl Cuentas Corrientes`, CODORI='D') = saldo operativo cacheado. ~7% de cuentas
  (alto volumen o especiales como cc=127 "Pendientes de Facturación") tienen drift vs el ledger
  calculado; el ledger de comprobantes es la fuente de verdad.
- `Tbl Operaciones`: CODOPE→DENOPE + ICCOPE (cod corto). Deudores=410..500, acreedores=300..350,
  internos=100..160, stock=200.

Próximo: confirmar con Paul el siguiente módulo (Emisiones requieren readwrite + AFIP).

### Imputaciones Contables (IC)
- `Tbl Cuentas Contables` (480) = Plan de Cuentas jerárquico 5 niveles (CN1CUE..CN5CUE; nivel = nº de
  CNxCUE no nulos). `IMPCUE`=true → imputable (hoja, 441). Saldo cacheado = INICUE + DEBCUE − CRECUE.
- `Tbl Movimientos Imputaciones` (517k) = asientos: NUMMOV+ORDMOV, CODCUE (cuenta contable, string),
  DEBMOV/CREMOV, CODCDC (centro de costo → `Tbl Centros de Costo`). La FECHA viene del `Tbl Movimientos`
  padre (join por NUMMOV): FEXMOV (comprobante) o FIXMOV (movimiento). VALIDADO: Σ imputaciones por
  cuenta = DEBCUE/CRECUE cacheados (CAJA al centavo).
- `modules/plan_cuentas/` — listado jerárquico (padres en negrita, hojas indentadas, click→mayor).
- `modules/mayor/` — Libro Mayor x cuenta+período. Saldo anterior = INICUE + Σ(DEB−CRE) antes de desde;
  asientos con saldo corrido. Selector Fecha Comprobante/Movimiento. Validado: CAJA full = 74.737.790,82
  = INICUE+DEB−CRE. Porta "Imputaciones Contables x Cuenta".
- `modules/balance/` — **Balance de Sumas y Saldos** (Rpt IC Balance de Sumas y Saldos). Por cuenta y
  período (FEXMOV): saldo anterior=Σ(DEB−CRE) antes de desde, debe/haber del período, saldo. **SIN INICUE**
  (el legacy no lo suma; Σ INICUE=145.070,86≠0 lo desbalancearía) → cierra: Σ Debe=Σ Haber, Σ Saldo≈0
  (0,11 de redondeo del dato legacy). Roll-up jerárquico en PHP: cada hoja se acumula en sus CN1..CN5.
  OJO: el saldo del Balance difiere del de Plan/Mayor por el INICUE de cada cuenta (ej CAJA 2.205).
  Query pesada (~10s, escanea 517k imputaciones hasta hasta) — aceptable para reporte mensual.
- `modules/bancos/` — **Bancos / Conciliación**: ledger de una cuenta bancaria (Cuentas Contables con
  CODCBX no null: 6 bancos + 6 posdatados). Saldo corrido + detalle de cheque (LEFT JOIN Tbl Cheques
  por CODCHQ → banco+SYNCHQ) + FAXMOV (acreditación) + estado CONMOV. Fecha Comp(FEXMOV en M)/Acred
  (FAXMOV en MI). Validado: BANCO SANTANDER full = 71.317,93 = Plan; ago-2023 ant −837.034,02 = Balance.
  OJO: en este backend `CONMOV` está TODO en false (nunca conciliaron) → la columna conciliación está
  lista pero todo figura pendiente. La "conciliación" real (marcar CONMOV) sería readwrite (Frm IC Conciliacion).

### Varios
- `modules/comprobantes/` — **Búsqueda de Comprobantes** sobre `Tbl Movimientos`. Filtros: texto
  (DENMOV/CITMOV/CAEMOV LIKE; si es numérico también CINMOV/NUMMOV exacto), tipo (CICMOV, dropdown
  server-side), importe (TOTMOV ±0,5), rango FEXMOV, libro (ESTMOV). TOP 200, orden FEXMOV DESC.
  Operación = CODOPE→DENOPE (Tbl Operaciones). Filas clickeables → resumen_cuenta (D) / _acr (A).
  Marca anulados (ANUMOV). Tipos CICMOV: FV/RV/NC/ND/RC/CP/OP/AD/AA/DB/BD/TB/CV/SI/RS/AS/DC/CA/VC/RG/RA.
- `modules/cheques/` — **Cheques** (de terceros) sobre `Tbl Cheques` (50.985). Estados: A Depositar
  (VADCHQ=true, 15 = $4,24M = cuenta VALORES A DEPOSITAR), Diferidos (DIFCHQ=true, 16), En cartera
  (ambos, 31), Todos. Cols: CODBAN→banco (Tbl Bancos), SYNCHQ(nº), FEXCHQ(emisión)/FAXCHQ(acred),
  LIBCHQ(librador)/CITCHQ, LOCCHQ('E CHEQ'=echeq), IMPCHQ. Filtros texto/importe/fecha(emi|acred). TOP 500.

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

## HECHO: teclado estilo Access (UX de adopción) — portado de produccion_ptp
Para los usuarios que vienen del legacy de escritorio (teclado rapidísimo) y rechazan Tab + los
combos nativos del browser. Dos helpers vanilla (sin dependencias) en `assets/js/app.js`
(+ estilos `.iwk-combo*` en `assets/css/app.css`); se activan en cualquier form con `[data-keynav]`:

- **IWK.keynav** — Enter avanza al campo siguiente, Shift+Enter retrocede, select-all al
  enfocar (escribir reemplaza el contenido), y tras el último campo el foco salta al botón de
  submit (`data-keynav-submit="#btnX"`). En memos (textarea): Enter avanza igual y **Ctrl+Enter**
  inserta el salto de línea.
- **IWK.combo** — convierte los `<select>` del form en combos **buscables** (filtran por subcadena
  sin acentos al tipear), manteniendo el `<select>` nativo como fuente de verdad (intercepta
  `.value` + MutationObserver) → NO hay que tocar la lógica de cada módulo. `enhanceForm` monta
  un observer que también potencia los `<select>` agregados en runtime (grids, hijos inline).
  Excluir un select puntual con `[data-nocombo]`.

`includes/layout.php` versiona los includes (`app.css?v=2`, `app.js?v=2`) para cache-bust; subir N
al tocar esos archivos. Validado en Chrome (combo filtra sin acentos, elige, el select real se
actualiza, y el Enter avanza al campo siguiente saltando el select oculto).

**Activado en:** `modules/abm/index.php` (`#mainForm`, el form de alta/edición de los maestros,
con `data-keynav-submit="#btnGuardar"`; el botón solo existe en readwrite). Al sumar pantallas de
**carga** nuevas, agregar `data-keynav` al contenedor del form.

**OJO con las consultas:** varios módulos de Administración son de filtro y ya manejan Enter
(`Enter → load()` en el campo de búsqueda). NO pongas `data-keynav` en esos forms tal cual (el
Enter avanzaría en vez de filtrar). Aplicalo a forms de **alta/edición**; en filtros, si querés
los combos buscables, evaluá caso por caso.

## HECHO: tracking de uso (adopción) — portado de produccion_ptp
Para medir adopción del sistema nuevo (qué páginas, cuánto, desde qué máquinas, quién) y saber
dónde empujar a los usuarios a dejar el legacy.
- **`includes/track.php`** (`track_hit()`): log append en `logs/usage-YYYY-MM.csv` — NO toca
  la mdb, funciona en readonly. Registra fecha/hora · usuario (sesión) · IP · host (DNS
  inverso cacheado en `logs/hosts.json`) · módulo (deriva de la ruta, distingue `?modo=`/`?m=`) · ruta.
- Enganche: `track_hit()` al inicio de `module_head()` (`includes/layout.php`, cubre todos los
  módulos) y en `app/index.php` (dashboard). Solo cuenta cargas de página, no los fetch/AJAX.
- Visor **`modules/uso/`**: filtra por fechas + sistema y agrega por módulo / usuario / máquina /
  día (KPIs + gráfico de barras + tablas). Lee y agrega los CSV en PHP. **Agrega cross-sistema**:
  `uso_sistemas()` suma los `logs/` de los 3 fronts hermanos (administracion/produccion/supervisores)
  que existan; override por config `uso_sistemas`. Restringido a admin (`auth_require_admin`).
- `logs/.gitignore` (`*` salvo el propio .gitignore) → los CSV no se versionan.
- **Auth admin** (`auth_is_admin`/`auth_require_admin`, también portados a `includes/auth.php`):
  lista en config `admin_users` (CODUSR o DENUSR); vacía = nadie es admin (default seguro).

**Config (en `config/system.php`, NO versionado → agregar a mano en cada deploy):** la entrada de
menú "Estadísticas de Uso" (grupo `Sistema`, url `/modules/uso/`) y `'admin_users' => ['PAUL']`.
Validado en Chrome: registra hits y el visor agrega Administración + Producción.

## NO confundir con la carpeta `ptp` (MySQL, patrón A)
`C:\wamp64\www\ptp\` es un intento ANTERIOR de migrar Administración a MySQL (clon de
inforemp-template). Quedó **parado** (referencia / meta a largo plazo). La vía activa para
Administración es ESTA (patrón B). El de MySQL sigue registrado como id=34 en inside_desarrollos.

## Historia general PTP
Memoria de **inforemp_inside**:
`C:\Users\pauld\.claude\projects\C--wamp64-www-inforemp-inside\memory\inforemp_web_kit.md`.
