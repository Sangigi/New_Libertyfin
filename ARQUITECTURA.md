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

```
app/
├── Config/
│   ├── Env.php          → carga .env (reemplaza env_loader.php)
│   └── Config.php       → config tipada por dominio (reemplaza config.php)
├── Core/
│   ├── Database.php     → PDO singleton + pool (reemplaza config/database.php)
│   └── Logger.php       → logging centralizado en storage/logs/
├── Support/
│   └── LogoResolver.php → resolución de logo a base64 (antes duplicado dashboard.php/caja.php)
├── Http/Middleware/
│   ├── Cors.php          → whitelist de orígenes (reemplaza Access-Control-Allow-Origin: *)
│   └── Auth.php           → start() / requireLogin() / requireRole() / requireLoginForPage()
├── Contracts/Repositories/
│   ├── EmpresaRepositoryInterface.php
│   ├── UsuarioRepositoryInterface.php
│   ├── SucursalRepositoryInterface.php
│   ├── SistemaConfigRepositoryInterface.php
│   ├── DashboardRepositoryInterface.php
│   └── CajaRepositoryInterface.php        → arranca con 1 método; crece en Sección 2
├── Repositories/
│   ├── EmpresaRepository.php
│   ├── UsuarioRepository.php
│   ├── SucursalRepository.php
│   ├── SistemaConfigRepository.php
│   ├── DashboardRepository.php
│   └── CajaRepository.php
└── Services/
    ├── AuthService.php               → login multi-empresa (antes inline en login.php)
    ├── SucursalService.php
    ├── DashboardService.php           → orquesta el resumen del dashboard
    └── Exceptions/
        └── InvalidCredentialsException.php
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
5. **Interfaces** → `app/Contracts/Repositories/`. Ya hay 6 contratos
   reales (Empresa, Usuario, Sucursal, SistemaConfig, Dashboard, Caja);
   se agrega uno nuevo — o se extiende uno existente, como
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
| 2 | Caja | Siguiente | `caja.php`, `caja_apertura.php`, `caja_cierre.php`, `caja_historial.php`, `caja_resumen.php` — `CajaRepository` ya existe con `abiertaPara()`, aquí se le agregan `abrir()`, `cerrar()`, `historial()`, etc. |
| 3 | Productos / Inventario | Pendiente | `productos*.php`, `categorias.php`, `inventario.php`, `buscar_producto*.php`, `crear_producto.php`, `importar_productos.php`, `transferir_stock.php`, `reporte_inventario*.php` |
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
   secciones.

Si algo de esto no coincide con el comportamiento actual del sistema
en producción, es más fácil corregirlo ahora — con una sola sección
migrada — que después de varias.

## Seguridad — pendiente urgente (independiente del roadmap por sección)

Esto no depende de en qué sección vamos; aplica ya, sin importar el
orden de migración:

- Rotar la contraseña de BD expuesta (`juanc141_alexis`) — sigue
  comprometida aunque se saque del código, porque ya quedó en el
  historial de git de un repo público.
- Purgar del historial de git los ~64 archivos con credenciales
  hardcodeadas y los documentos en `documentos_empresas/` (constancias,
  credenciales) antes de que el repo sea — o vuelva a ser — público.
- Mover `logSpeiTransaction()` (hoy en `config/database.php`) a un
  `SpeiTransaccionRepository` propio cuando se migre la Sección 8 — no
  es responsabilidad de la capa de conexión.
