# Inventario — Producción PTP (piloto)

Fuente legacy: `C:\_inforemp\_sistemas\_ProcesadoraTextilParque\`
- Front-end (programa): `Produccion PTP_w2.mdb` ← apuntar el kit acá
- Backend datos producción: `Produccion PTP_d2.mdb` (vinculado, tablas `Tbl *`)
- Backend administración/contable: vinculado, tablas `ADM_*`
- VBA exportado: `VBA_Source_Produccion\` (107 forms, 77 reportes, 83 queries)

Validado (solo lectura, vía ACE.OLEDB sobre el front-end): los vínculos resuelven
y se leen datos reales — 9.313 órdenes de proceso, 195 operarios, 489 procesos,
309 clientes, 661 cuentas corrientes (ADM).

## Tablas núcleo (datos en backend `Tbl *`)
- **Órdenes de Proceso**: `Tbl Ordenes De Proceso` (header, PK NUMODP), `... Procesos`,
  `... Lotes`, `... Operarios`, `... Insumos`, `... Productos`, `... Adelantos`,
  `Tbl Ordenes En Proceso`.
- **Órdenes de Muestra**: `Tbl Ordenes De Muestra` (+ Procesos/Prendas/Prototipos/Remitos).
- **PTP** (presupuesto/proyecto): `Tbl PTP`, `Tbl PTP Procesos`, `Tbl Presupuestos PTP (+Procesos)`.
- **Maestros**: `Tbl Clientes`(+Marcas), `Tbl Marcas`, `Tbl Operarios`(+Sectores),
  `Tbl Supervisores`(+Sectores), `Tbl Maquinas`(+Procesos/Mantenimiento), `Tbl Procesos`,
  `Tbl Etapas`, `Tbl Subetapas`, `Tbl Sectores Personal`, `Tbl Prendas`, `Tbl Telas`,
  `Tbl Colores Tela`, `Tbl Colores De Proceso`, `Tbl Proveedores De Tela`, `Tbl Talleres`,
  `Tbl Unidades De Medida`, `Tbl Bases De Producto`.
- **Recetas**: `Tbl Recetas Proceso (+Composicion/Procesos)`, `Tbl Recetas Producto (+...)`.
- **Programación**: `Tbl Programacion`, `Tbl Dias Programacion`, `Tbl Feriados`, `Tbl Huecos Programacion`.
- **Destajo/Liquidación**: `Tbl Compensaciones Destajo (+Detalle)`.
- **Sistema**: `Tbl Usuarios`(+Etapas/Menu), `Tbl Menu (+Grupos)`, `Tbl Opciones`, `Tbl Registro Operaciones`.

## Estructura de menú (legacy)
- **Configuración/Maestros**: Datos de Configuración, Sectores Personal, Sectores, Procesos,
  Operarios, Supervisores, Máquinas, Usuarios, Prendas, Proveedores de Tela, Colores de Tela,
  Telas, Bases de Producto, Colores de Proceso, Marcas, Clientes, Talleres, Unidades de Medida,
  Recetas de Proceso, Recetas de Producto.
- **Consultas** (solo lectura ★): Órdenes de Proceso x Lote, x Sector, x Etapa; PTP.
- **Procesos** (escritura): Recepción, Definición, Adelantos, Remitos NF, Confirmación/Entrega
  Órdenes de Muestra, Programación, Carga Diferida Operarios, Compensaciones Destajo, Presupuestos.
- **Reportes**: Producción x Planta, x Operario, Periódica, Liquidación x Destajo, etc.

## Roadmap de porteo propuesto (piloto)
**v1 — solo lectura (cero riesgo, alto valor):**
1. Instancia del kit `produccion_ptp/` → login + dashboard, `mode=readonly`.
2. Consulta Órdenes de Proceso x Lote (hay query guardada `sql Consulta Ordenes de Proceso x Lote*`).
3. Consulta Órdenes de Proceso x Sector.
4. Consulta Órdenes de Proceso x Etapa.
5. Maestros de consulta rápida: Operarios, Procesos, Clientes.

**v2 — escritura pantalla por pantalla** (`mode=readwrite`): Recepción, Definición, etc.
