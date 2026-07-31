<?php

namespace App\Support;

/**
 * Busca un archivo de imagen (logo de empresa, o imagen de producto)
 * en las rutas donde históricamente puede estar guardado, y lo entrega
 * como data-URI base64 listo para <img src>.
 *
 * Esta misma lógica estaba duplicada en 20 archivos para el logo
 * (dashboard.php, caja*.php, clientes.php, productos.php, etc. — según
 * el propio comentario del original, "COMO EN CAJA.PHP") y, con una
 * lista de carpetas distinta, en caja.php para imágenes de producto
 * (obtenerImagenProducto). Vive en un solo lugar ahora.
 */
class LogoResolver
{
    private const EXTENSIONES_VALIDAS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    private const RUTAS_LOGO = [
        '',
        '../', '../../',
        'admin/', '../admin/',
        'logos/', 'img/', 'images/', 'assets/', 'uploads/',
        '../logos/', '../img/', '../images/', '../assets/', '../uploads/',
    ];

    private const RUTAS_PRODUCTO = [
        '',
        '../', '../../',
        'admin/', '../admin/',
        'img/productos/', 'images/productos/', 'uploads/productos/', 'assets/productos/', 'productos/',
        '../img/productos/', '../images/productos/', '../uploads/productos/', '../assets/productos/', '../productos/',
    ];

    /** @return array{path: ?string, base64: ?string} */
    public static function resolver(?string $logoRelativo): array
    {
        return self::buscar($logoRelativo, self::RUTAS_LOGO);
    }

    /** Misma búsqueda que resolver(), pero en las carpetas típicas de imágenes de producto. */
    public static function resolverImagenProducto(?string $imagenRelativa): array
    {
        return self::buscar($imagenRelativa, self::RUTAS_PRODUCTO);
    }

    /** @return array{path: ?string, base64: ?string} */
    private static function buscar(?string $archivoRelativo, array $rutasBase): array
    {
        if (empty($archivoRelativo)) {
            return ['path' => null, 'base64' => null];
        }

        $rutaEncontrada = null;
        foreach ($rutasBase as $base) {
            $ruta = $base . $archivoRelativo;
            if (file_exists($ruta) && is_file($ruta)) {
                $rutaEncontrada = $ruta;
                break;
            }
        }

        if ($rutaEncontrada === null) {
            return ['path' => null, 'base64' => null];
        }

        $extension = strtolower(pathinfo($rutaEncontrada, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONES_VALIDAS, true)) {
            return ['path' => $rutaEncontrada, 'base64' => null];
        }

        $datos = base64_encode((string) file_get_contents($rutaEncontrada));

        return [
            'path'   => $rutaEncontrada,
            'base64' => "data:image/{$extension};base64,{$datos}",
        ];
    }
}
