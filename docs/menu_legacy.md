# Mapa del menú legacy — Administración PTP

Relevado de la `.mdb` real (2026-06-01): `Tbl Menu` (120 opciones) + `Tbl Modulos` +
inventario de Forms (104) y Reports (159) en `_ProcesadoraTextilParque\Administracion\`.

El menú legacy se organiza por **TABMEN** (solapa/módulo). Cada opción es Actualización
(ABM/transaccional), Listado (reporte), Emisión (comprobante) o Proceso (export/cierre).

## Solapas (TABMEN)

| Tab | Módulo | Opciones | Equivale en Inforemp Inside |
|-----|--------|---------:|------------------------------|
| **CD** | Cuentas Deudores (Clientes / Ventas) | 38 | Deudores |
| **IC** | Imputaciones Contables | 32 | Imputaciones Contables |
| **SI** | Stock / Inventario | 18 | Stock |
| **CA** | Cuentas a Pagar (Acreedores) | 14 | Acreedores |
| **VS** | Varios / Sistema | 15 | Varios |
| **RE** | Resúmenes / Reportes globales | 6 | (dashboard/reportes) |

`Tbl Modulos` (tipos de movimiento, distinto de las solapas): COMPRAS, VENTAS, INGRESOS
CAJA, EGRESOS CAJA Y BANCOS, CUENTAS CORRIENTES, FONDO FIJO, AJUSTES STOCK.

## CD — Clientes / Ventas (el más grande y operativo)
- **Emisiones (transaccional, el núcleo):** Facturas, Notas de Débito, Notas de Crédito,
  Recibos, Remitos. Forms: `Frm CD Facturas NF`, `Frm CD Recibos`, `Frm CD Debitos NF`,
  `Frm CD Creditos NF`, `Frm CD Remitos NF` (+ subforms Productos/Cheques/Vencimientos/
  Referencias/Documento).
- **Maestros:** Cuentas Corrientes (clientes), Categorías, Zonas, Transportes, Vendedores,
  Formas de Pago Cta.Cte.
- **Consultas/Listados:** Resumen de Cuenta, Saldos Actuales/Periódicos/Históricos, CC x
  Categoría/Condición/Denominación/Localidad/Vendedor/Zona, **I.V.A. Ventas**, Operaciones
  Pendientes de Facturación, Saldos Operaciones.
- **Procesos:** Exportación Percepciones IIBB, Retenciones IVA/SUSS/IIBB/Ganancias, IVA Ventas
  a Holistor, Regularización Operaciones Pendientes.

## IC — Imputaciones Contables (columna vertebral contable)
- **Actualizaciones:** Bancos, Operaciones, Cuentas Bancarias, Centros de Costo, Cuentas
  Contables (Plan de Cuentas), Imputaciones. Forms `Frm IC *`.
- **Procesos:** Conciliación, Cierre.
- **Listados:** Mayor (Imputaciones Contables x Cuenta, Fec.Mov/Fec.Com, Periódico/Histórico),
  Balance de Sumas y Saldos, Plan de Cuentas, Saldos Actuales/Periódicos, Movimientos
  Cheques/Bancarios, Valores a Depositar, Parte Diario de Caja, Gastos x Fecha, Cheques
  Diferidos a Devengar, Pre-Conciliación.

## CA — Acreedores / Cuentas a Pagar
- **Actualizaciones:** Cuentas Corrientes (proveedores), Remitos, Comprobantes a Pagar,
  Notas de Crédito, Notas de Débito, Órdenes de Pago, Cancelación de Anticipos. Forms `Frm CA *`.
- **Listados:** Cuentas Corrientes, Remitos Pendientes, Saldos Actuales/Periódicos/Históricos,
  Resumen de Cuenta, Saldos Operaciones.
- **Procesos:** Exportación Agente Rec. IIBB - Retenciones.

## SI — Stock / Inventario
- **Actualizaciones:** Rubros (+Subrubros), Líneas, Unidades de Medida, Productos (+Precios,
  Proveedores, Stock, Unidades), Ajustes. Forms `Frm SI *`.
- **Listados:** Stocks Mínimos, Precios de Venta, Inventario Actual/Periódico, Reposiciones,
  Productos x Código/Denominación/Nivel, Movimientos x Producto, Exclusiones.

## VS — Varios / Sistema
Localidades, Usuarios, Claves de Acceso, Período de Control, Período Predeterminado en
Listados, **I.V.A. Compras**, Reporte Contable, Cotización u$s, Tipos de Movimientos Holistor,
Exportación Percepciones/IVA Compras (Holistor), Importación Padrón Contribuyentes IIBB Bs As.

## RE — Resúmenes globales
Movimientos de Caja x Fecha, Movimientos Deudores x Cuenta, Movimientos Acreedores x Cuenta,
Cheques de Terceros x Fecha Acreditación/Entrada, Ventas x Fecha.

## Notas para el porting
- Mismo dominio que **Inforemp Inside** (CD/CA/IC/SI) → la lógica de comprobantes
  (codope, Tbl Movimientos + Vencimientos/Imputaciones/IVA/Referencias/Retenciones) es la
  versión legacy de lo que inside ya implementa en MySQL. Buena fuente cruzada.
- RI → **WSFE/AFIP** relevante para las Emisiones de CD (Facturas/NC/ND electrónicas).
- "Holistor" = sistema contable externo al que se exporta (IVA, tipos de movimiento).
- `OPTMEN` con prefijo `optXXNNN` o `OpciónNNNN` = nombre del control/macro en el Menu legacy.
