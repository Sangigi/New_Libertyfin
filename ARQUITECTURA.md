# Arquitectura — LibertyFin

Capas y dónde vive cada cosa. `app/` es el código nuevo (autoload PSR-4,
`App\`); los scripts en la raíz (`login.php`, `dashboard.php`,
`caja_apertura.php`, etc.) y las carpetas de módulo (`Administracion/`,
`Facturacion/`, `EmidaServicios/`, `Service/`) se mantienen donde están
y se convierten, una sección a la vez, en controladores delgados que
llaman a `app/`.

## Regla de migración

**Cuando un archivo se toca, queda completamente migrado a la nueva
arquitectura — no solo la conexión a BD.** Nada de queries SQL sueltas
dentro de un script ya tocado; si el script las necesita, se extraen a
un Repository (y a un Service si hay alguna regla de negocio de por
medio). Un archivo puede quedar sin tocar todavía (esperando su
sección), pero no puede quedar a medias una vez que se tocó.

Por esto, al completar la Sección 1 se extrajeron también las 4
queries que `dashboard.php` todavía tenía inline en la primera entrega
(config visual, estadísticas, plan/timbres, caja actual) — quedaron en
`SistemaConfigRepository`, `DashboardRepository`, `EmpresaRepository` y
`CajaRepository` respectivamente, orquestadas por `DashboardService`.

## Regla de capas

**Cada capa nueva debe resolver un problema real — si una clase solo
envuelve otra sin aportar lógica, esa capa probablemente no hace
falta.** Por esto el carrito de `caja.php` (que vive en
`$_SESSION['carrito']`, no en una tabla) tiene `CarritoService` pero
NO un `CarritoRepository` — no hay nada que abstraer ahí, la sesión ya
es simple y directa. Y `Database::forEmpresa()` (alias sin uso real de
`pdo()`) se quitó al aplicar esta regla retroactivamente.

## `.htaccess` (nuevo — no existía en ningún commit)

El código lleva años redirigiendo a rutas "limpias" (`Location: Login`,
`Location: Inicio`, `<a href="Clientes">`, etc. — 14 nombres distintos
encontrados por grep) que dependen de reglas de reescritura de Apache.
Esas reglas nunca estuvieron en git — vivían puestas a mano en el
servidor de producción. Por eso `test2.grupoideasmx.com`, al ser un
entorno nuevo creado desde el repo, no las tiene: `/login` y `/inicio`
daban 404 aunque `login.php`/`dashboard.php` funcionaran perfectamente
por su ruta directa.

Se agregó `.htaccess` en la raíz con los 14 mapeos confirmados por
grep contra el código real, más una regla genérica para casos futuros,
más el bloqueo de acceso directo a `.env`, `storage/`, `vendor/` y
`.git/`. Dos mapeos (`Ventas`, `CortesCaja`) son inferencia por
convención de nombres, no una coincidencia exacta confirmada — están
marcados en el archivo. `Administracion/` usa el mismo patrón de rutas
limpias con su propio login/sesión — le toca su propio `.htaccess` (o
reglas adicionales) cuando se migre esa sección.

```
app/
├── Config/
│   ├── Env.php          → carga .env (reemplaza env_loader.php)
│   └── Config.php       → config tipada por dominio (reemplaza config.php)
├── Core/
│   ├── Database.php     → PDO singleton + pool (reemplaza config/database.php)
│   └── Logger.php       → logging centralizado en storage/logs/
├── Support/
│   ├── LogoResolver.php → resolución de logo/imagen de producto a base64 (duplicado en 20+ archivos del original)
│   └── Formato.php      → fecha/moneda/clase-CSS-diferencia/hexARgb
├── Http/Middleware/
│   ├── Cors.php          → whitelist de orígenes (reemplaza Access-Control-Allow-Origin: *)
│   └── Auth.php           → start() / requireLogin() / requireRole() / requireLoginForPage()
├── Contracts/Repositories/
│   ├── EmpresaRepositoryInterface.php     → incluye facturapi_organization_id
│   ├── UsuarioRepositoryInterface.php
│   ├── SucursalRepositoryInterface.php
│   ├── SistemaConfigRepositoryInterface.php → incluye caché de facturapi_test_api_key
│   ├── DashboardRepositoryInterface.php
│   ├── CajaRepositoryInterface.php        → abiertaPara, abiertaPorId, encontrarPorId, crear, cerrar, historialFiltrado, registrarVenta
│   ├── VentaRepositoryInterface.php       → totalesPorMetodoPago, crear, agregarDetalle, actualizarFacturapiReceipt
│   ├── MovimientoCajaRepositoryInterface.php
│   ├── ProductoRepositoryInterface.php    → listar (consolida 4 variantes), precios (mayoreo incl. lista completa), imagen, stock (por sucursal), mayoreo por lote, facturapi, generación de código
│   ├── CategoriaRepositoryInterface.php
│   ├── ClienteRepositoryInterface.php
│   └── GastoRepositoryInterface.php       → arranca con 1 método (costo de venta)
│   └── MovimientoInventarioRepositoryInterface.php → distinta tabla de MovimientoCajaRepositoryInterface
├── Repositories/
│   ├── EmpresaRepository.php
│   ├── UsuarioRepository.php
│   ├── SucursalRepository.php
│   ├── SistemaConfigRepository.php
│   ├── DashboardRepository.php
│   ├── CajaRepository.php
│   ├── VentaRepository.php
│   ├── MovimientoCajaRepository.php
│   ├── ProductoRepository.php
│   ├── CategoriaRepository.php
│   ├── ClienteRepository.php
│   └── GastoRepository.php
│   └── MovimientoInventarioRepository.php
└── Services/
    ├── AuthService.php               → login multi-empresa (antes inline en login.php)
    ├── SucursalService.php
    ├── DashboardService.php           → orquesta el resumen del dashboard
    ├── CajaService.php                → abrir/cerrar/calcular resumen/detalle/historial de caja
    ├── CarritoService.php             → las 8 acciones del carrito (sesión, no BD — sin Repository propio)
    ├── ProductoService.php            → eliminar (cascada + dependencias), transferirStock, listarParaGestion (paginado)
    ├── CategoriaService.php           → crear/actualizar/eliminar (desactivar) con validación de nombre único
    ├── VentaService.php               → procesar_pago: transacción completa
    ├── FacturapiKeyService.php        → api key de prueba (antes con key maestra hardcodeada)
    ├── FacturapiReceiptService.php    → generación de recibo Facturapi de una venta
    └── Exceptions/
        ├── InvalidCredentialsException.php
        ├── CajaYaAbiertaException.php
        └── CajaNoAbiertaException.php
```

## Cómo se conecta cada punto del plan de acción

1. **PDO exclusivo** → `app/Core/Database.php`. Los `getDBConnection()` /
   `getEmpresaDBConnection()` globales se mantienen como compatibilidad
   mientras se migran los archivos que aún abren conexión propia.
2. **.env** → `app/Config/Env.php` + `app/Config/Config.php` +
   `.env.example`. Sin defaults hardcodeados para credenciales.
3. **Rendimiento** → connection pool en `Database::pdo()` (ya no se abre
   una conexión nueva por cada llamada); `Logger` escribe con
   `FILE_APPEND`, no reescribe el archivo completo.
4. **Modularización en capas** → estructura de arriba: Http → Services →
   Repositories → PDO.
5. **Interfaces** → `app/Contracts/Repositories/`. Ya hay 11 contratos
   reales (Empresa, Usuario, Sucursal, SistemaConfig, Dashboard, Caja,
   Venta, MovimientoCaja, Producto, Categoria, Cliente); se agrega uno
   nuevo — o se extiende uno existente, como pasó con
   `CajaRepositoryInterface` — conforme avanza el roadmap por sección,
   y para las integraciones externas (`PrepaidServiceInterface` para
   Emida, `PaymentGatewayInterface` para SPEI/PayPal).
6. **Patrón de diseño** → Repository + Service Layer, con inyección de
   dependencias por constructor (sin contenedor DI — no hace falta a este
   tamaño de proyecto).
7. **Seguridad en transacciones / CORS cerrado** → `app/Http/Middleware/Cors.php`
   + `Auth.php`. Pendiente de aplicar en los 9 endpoints que hoy usan
   `Access-Control-Allow-Origin: *` (4 en `Service/`, 5 en `EmidaServicios/`)
   — entra en la Sección 8 (Pagos) y Sección 9 (Emida) del roadmap.
8. **Logs centralizados** → `app/Core/Logger.php` escribe en
   `storage/logs/YYYY-MM-DD.log`. `AuthService`/`login.php` ya loguean ahí
   (canal `auth`). Falta configurar la directiva `error_log` de PHP hacia
   esa misma carpeta para que los errores nativos dejen de crear un
   archivo `error_log` por carpeta.

## Activar la estructura

```bash
composer dump-autoload
cp .env.example .env   # y llenar con los valores reales
```

Cualquier script que use clases de `App\` necesita, una sola vez al
inicio:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

## Roadmap por sección

Orden pensado para poder probar cada bloque de forma aislada antes de
seguir con el siguiente. El orden es una propuesta — se puede
reprioritizar.

| # | Sección | Estado | Contenido |
|---|---------|--------|-----------|
| 1 | **Auth + Dashboard** | ✅ Completo (por probar) | `login.php`, `logout.php`, `dashboard.php`, `AuthService`, `DashboardService`, repos de Empresa/Usuario/Sucursal/SistemaConfig/Dashboard/Caja |
| 2 | Caja | ✅ Completo (por probar) | `caja_apertura.php`, `caja_cierre.php`, `caja_resumen.php`, `caja_historial.php`, `caja.php` — `CajaService`, `CarritoService`, `VentaService`, `FacturapiKeyService`, `FacturapiReceiptService` |
| 3 | Productos / Inventario | 🔶 En progreso (11/17) | Resultó más grande de lo que parecía: `productos.php` tiene 8572 líneas (más del doble de `caja.php`) e `inventario.php` 2961 — se dejan para el final. ✅ `cargar_precios_mayoreo.php`, `obtener_stock_actualizado.php`, `generar_codigo.php`, `ajax_productos_stats.php`, `buscar_producto.php`, `buscar_productos_ajax.php`, `buscar_productos_tiempo_real.php`, `eliminar_producto.php`, `transferir_stock.php`, `ajax_productos.php`, `categorias.php` — `ProductoService`/`CategoriaService` nuevos. Faltan 6: `generar_plantilla_productos.php`, 3 reportes de inventario, `importar_productos.php`, y los 2 grandes |
| 4 | Ventas / Clientes | Pendiente | `clientes.php`, `ventas_lista.php`, `agregar_al_carrito.php`, `generar_pdf_ticket*.php`, `imprimir_ticket.php`, `obtener_historial.php` |
| 5 | Distribuidores | Pendiente | `distribuidores.php`, `distribuidor_editar.php`, `distribuidor_nuevo.php`, `guardar_distribuidor.php` (`guardar_sucursal.php` ya migrado como ejemplo) |
| 6 | Reportes / Exportación | Pendiente | `reportes.php`, `get_reporte_ajax.php`, `exportar_excel.php`, `exportar_pdf.php` |
| 7 | Facturación (CFDI) | Pendiente | `Facturacion/` completo (vía Facturapi) |
| 8 | Pagos SPEI/CLABE | Pendiente | `Service/generar_clabe.php`, `pago_clabe.php`, `cancelar_pago.php`, `consultar_clabe.php` — aquí se cierra el CORS de verdad + `PaymentGatewayInterface` |
| 9 | Emida (servicios prepago) | Pendiente | `EmidaServicios/` completo — `PrepaidServiceInterface` + proxies con CORS/auth |
| 10 | Administración (panel super-admin) | Pendiente | `Administracion/` — vocabulario de roles propio (`administrador`, `supervisor`...), login/sesión separados |
| 11 | Gastos / Comisiones / Proveedores / Suscripciones | Pendiente | `gastos.php`, `comisiones_config.php`, `proveedores.php`, `suscripciones.php` |
| 12 | Alta de nuevas empresas | Pendiente | `registro.php`, `registroEmpresa.php`, `solicitudEmpresa.php` |
| 13 | Cron jobs | Pendiente | `Administracion/Cron/` |

### Nota: `precio` vs `subprecio` no son lo mismo

`productos.subprecio` = precio de lista (sin descuento). `productos.precio`
= precio final ya con el `descuento` del producto aplicado — se
recalculan juntos cada vez que se edita el producto (`productos.php`).
Son iguales solo cuando el producto no tiene descuento activo.

`caja.php` usa `subprecio` a propósito, porque el carrito recalcula su
propio descuento; el resto de los archivos (`buscar_producto.php`,
`inventario.php`, reportes) usa `precio` porque solo necesitan mostrar
el precio final. **No son intercambiables** — al migrar cualquier
archivo nuevo de esta sección, hay que fijarse cuál de los dos usaba
el original, no asumir que da igual.

### Sección 3 — hallazgos hasta ahora

- La misma ruta de sesión personalizada de `Administracion/`
  (`/home2/juanc141/tmp_sessions`) aparecía también en
  `cargar_precios_mayoreo.php` y `buscar_productos_ajax.php` — dos
  archivos del sistema principal, donde `login.php` NUNCA la usa. Como
  la sesión que abre el login vive en la ruta por defecto, estos dos
  endpoints probablemente devolvían "No autorizado" siempre a un
  usuario real. Se migraron con el arranque de sesión estándar
  (`Auth::requireLogin()`), igual que el resto del sistema principal.
- `buscar_productos_ajax.php` resultó ser una búsqueda administrativa
  más completa que la del punto de venta (filtra también por
  proveedor y por sucursal-con-stock, incluye inactivos opcionalmente,
  y trae un resumen de sucursales/stock total por producto) — se
  agregó `ProductoRepository::buscarAdministracion()` en vez de
  forzarlo dentro de `listar()`/`buscarTiempoReal()`, que son para el
  punto de venta y tienen otra forma.
- `ProductoService` (nuevo) agrupa `eliminar()` (borrado en cascada con
  chequeo de dependencias) y `transferirStock()` (transacción entre
  sucursales con movimientos de inventario) — mismo patrón que
  `VentaService`: recibe el PDO de la empresa además de los
  repositorios, porque necesita `beginTransaction()`/`commit()` sobre
  varias llamadas a distintos repositorios.
- `movimientos_inventario` es una tabla distinta de `movimientos_caja`
  — se creó `MovimientoInventarioRepository` aparte, no se reutilizó
  `MovimientoCajaRepository`.
- `ajax_productos.php` resultó ser una TERCERA variante de listado de
  productos (paginada, con imágenes/mayoreo/stock-por-sucursal de cada
  producto de la página, más estadísticas y valor de inventario) —
  distinta de `buscarAdministracion()` (sin paginar, para un filtro
  rápido). Comparten el armado de filtros (`whereAdministracion()`,
  privado) para no duplicar esa parte otra vez.
- `categorias.php` (la gestión de categorías) cuenta productos
  DIFERENTE que el punto de venta: todos los activos de la categoría,
  sin filtrar por sucursal ni stock. Es un método nuevo
  (`todasConConteoTotal()`), no una variante de `conConteoProductos()`
  que ya usa `caja.php`/`dashboard.php` — los números no deberían
  coincidir entre esas pantallas y eso es correcto, no un bug.


## `caja.php` — completado

Resultó bastante más grande que el resto de la Sección 2 junta: 1761
líneas de lógica antes del HTML (contra ~200 de los otros archivos de
Caja), repartidas en carga inicial de página + 8 acciones de carrito +
`procesar_pago`, todas dentro del mismo archivo por `$_POST` — no se
pudo entregar por partes porque cada acción corta con `exit()` antes
de llegar al HTML, así que dividirlo en "Parte A/B/C" habría dejado el
archivo roto hasta tener las tres. Se hizo completo de una vez.

**🔴 Hallazgo de seguridad, más urgente que la contraseña de BD:**
líneas ~273 y ~334 tenían una API key MAESTRA de Facturapi hardcodeada
(`sk_user_...` y un fallback `sk_test_...`) — una clave de ese tipo
suele poder gestionar facturación de **varias organizaciones**, no
solo esta, y estuvo en un repo que fue público. Ya no está en el
código (vive en `FACTURAPI_MASTER_API_KEY` / `FACTURAPI_TEST_API_KEY_FALLBACK`
en `.env`) pero **rotarla en el panel de Facturapi sigue pendiente y
no espera al despliegue.**

**⚠️ Hallazgo de comportamiento, se conservó tal cual:** `procesar_pago`
no revalida el stock disponible antes de descontarlo — el código
original tenía un comentario `// [Mantén tu código de validación de
stock aquí]` sin completar. No se agregó una validación que no estaba
para no meter un cambio de negocio no pedido, pero vale la pena
decidir a propósito si debería agregarse (dos cajeros cobrando el
mismo producto casi al mismo tiempo podrían dejar el stock en
negativo).

Además, aprovechando que se tocó el archivo completo:
- Las 4 variantes de query de productos → un solo `ProductoRepository::listar()`.
- El chequeo "¿tiene mayoreo?" por producto (N+1, y duplicado en la
  vista de escritorio y la de móvil) → una sola consulta por lote
  (`idsConMayoreo()`).
- El dropdown de categorías, que se volvía a consultar dos veces más
  en el HTML (una por vista de escritorio, otra por móvil) además de
  la carga inicial → reusa los datos ya cargados.
- `sistema_config` se consultaba 2 veces con columnas distintas → 1 sola.
- Se agregaron `facturapi_organization_id` a `EmpresaRepository` y la
  caché de `facturapi_test_api_key` a `SistemaConfigRepository` (con
  la creación dinámica de columna preservada — sigue sin ser una
  migración de esquema real, pero ya no está mezclada con lógica de
  request).

## Cómo probar la Sección 1 (Auth + Dashboard)

1. `composer dump-autoload`
2. `cp .env.example .env` y llenar con las credenciales reales.
3. Copiar `app/`, `login.php`, `logout.php`, `dashboard.php`,
   `guardar_sucursal.php`, `.env`, `composer.json`, `vendor/` a un
   entorno de pruebas (no producción todavía).
4. Entrar a `login.php` con un usuario real → debe redirigir a
   `Inicio` (=`dashboard.php`) igual que antes.
5. Verificar en el dashboard: nombre/logo de la empresa, las 5
   estadísticas (productos, clientes, usuarios, ventas de hoy,
   ingresos de hoy) y el estado de caja — deben coincidir con lo que
   mostraba el dashboard.php original.
6. Cerrar sesión (`logout.php`) y confirmar que ya no se puede volver a
   `dashboard.php` sin loguearse de nuevo.
7. Revisar `storage/logs/<fecha>.log` — debe aparecer la línea
   `auth.INFO: Login exitoso ...` (y `auth.WARNING`/`auth.ERROR` si se
   provoca un intento fallido).
8. Probar de nuevo `guardar_sucursal.php` (Sección previa, ya
   entregada) para confirmar que sigue funcionando igual bajo el mismo
   `Auth`/`Database` — es el primer chequeo de regresión entre
   secciones. Nota: visitar esa URL directo en el navegador (GET) da
   `{"success":false,"message":"Solicitud no válida"}` — es correcto,
   el endpoint exige POST con `accion=crear` porque lo llama el
   formulario de "nueva sucursal" por AJAX, no la barra de direcciones.

Si algo de esto no coincide con el comportamiento actual del sistema
en producción, es más fácil corregirlo ahora — con una sola sección
migrada — que después de varias.

## Cómo probar la Sección 2 (completa)

1. Copiar también `caja_apertura.php`, `caja_cierre.php`,
   `caja_resumen.php`, `caja_historial.php`, `caja.php` y el
   `.htaccess` nuevo al entorno de pruebas.
2. Sin caja abierta: entrar a `Caja` (o `caja_apertura.php`) → debe
   mostrarse el formulario de apertura con el nombre de sucursal
   correcto.
3. Abrir caja con un monto → redirige a `caja.php` con mensaje de
   éxito, y ahí debe cargar el catálogo de productos/categorías/
   clientes con los mismos filtros que antes.
4. Intentar abrir caja de nuevo (ej. yendo directo a
   `caja_apertura.php`) → debe redirigir solo, sin mostrar el
   formulario, porque ya hay una abierta.
5. En `caja.php`: agregar un producto al carrito, cambiar cantidad
   (probar uno que tenga precio de mayoreo configurado, para ver que
   repriceé solo), aplicar un descuento, quitar un producto, vaciar el
   carrito — cada acción debe responder igual que antes (mismo JSON,
   mismos mensajes).
6. Cobrar una venta completa (`procesar_pago`) con cada método de
   pago. Si la empresa es plan premium, confirmar que se genera el
   recibo de Facturapi; si falla Facturapi, la venta debe quedar
   registrada de todas formas con un mensaje de advertencia (no
   cancela la venta — es el comportamiento original).
7. Ir a `caja_cierre.php` → debe mostrar los totales de ventas por
   método de pago y el monto esperado calculado.
8. Cerrar caja con un monto contado → confirmar que `diferencia` en la
   fila de `caja` (tabla) quede correcta (monto contado − monto
   esperado) y que redirija a `caja_resumen.php?id=X` mostrando lo
   mismo que se acaba de cerrar.
9. Desde `caja_resumen.php`, confirmar que la lista de movimientos se
   ve igual que antes (ahí se adaptó `$movimientos->fetch_assoc()` de
   mysqli a PDO).
10. Ir a `caja_historial.php` (o `CortesCaja` con el `.htaccess`
    nuevo) → debe listar las cajas de la sucursal, con los filtros de
    fecha, usuario y estado funcionando igual que antes.
11. Revisar `storage/logs/<fecha>.log` — deben aparecer líneas
    `caja.INFO`/`ventas.INFO`/`facturapi.INFO` para cada operación.

## Seguridad — pendiente urgente (independiente del roadmap por sección)

Esto no depende de en qué sección vamos; aplica ya, sin importar el
orden de migración:

- Rotar la contraseña de BD expuesta (`juanc141_alexis`) — sigue
  comprometida aunque se saque del código, porque ya quedó en el
  historial de git de un repo público.
- Rotar la API key MAESTRA de Facturapi (`sk_user_MD3D8...`, y el
  fallback `sk_test_3NGWy62...`) encontrada en `caja.php` — mismo
  motivo: expuesta en un repo público, y a diferencia de la
  contraseña de BD, puede tener alcance sobre varias organizaciones.
- Purgar del historial de git los ~64 archivos con credenciales
  hardcodeadas y los documentos en `documentos_empresas/` (constancias,
  credenciales) antes de que el repo sea — o vuelva a ser — público.
- Mover `logSpeiTransaction()` (hoy en `config/database.php`) a un
  `SpeiTransaccionRepository` propio cuando se migre la Sección 8 — no
  es responsabilidad de la capa de conexión.
- Decidir si `procesar_pago` debe revalidar stock antes de descontarlo
  (ver nota en la sección de `caja.php` arriba) — hoy no lo hace, igual
  que el original.
