<?php

namespace App\Support;

/**
 * Busca el logo de la empresa en las rutas donde históricamente puede
 * estar guardado y lo entrega como data-URI base64 listo para <img src>.
 *
 * Esta misma lógica estaba duplicada en dashboard.php y (según su
 * propio comentario, "COMO EN CAJA.PHP") en caja.php. Vive en un solo
 * lugar ahora — cuando se migre caja.php (Sección 2) se reutiliza esta
 * misma clase en vez de volver a copiarla.
 */
class LogoResolver
{
    private const EXTENSIONES_VALIDAS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    private const RUTAS_BASE = [
        '',
        '../', '../../',
        'admin/', '../admin/',
        'logos/', 'img/', 'images/', 'assets/', 'uploads/',
        '../logos/', '../img/', '../images/', '../assets/', '../uploads/',
    ];

    /** @return array{path: ?string, base64: ?string} */
    public static function resolver(?string $logoRelativo): array
    {
        if (empty($logoRelativo)) {
            return ['path' => null, 'base64' => null];
        }

        $logoPath = null;
        foreach (self::RUTAS_BASE as $base) {
            $ruta = $base . $logoRelativo;
            if (file_exists($ruta) && is_file($ruta)) {
                $logoPath = $ruta;
                break;
            }
        }

        if ($logoPath === null) {
            return ['path' => null, 'base64' => null];
        }

        $extension = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONES_VALIDAS, true)) {
            return ['path' => $logoPath, 'base64' => null];
        }

        $datos = base64_encode((string) file_get_contents($logoPath));

        return [
            'path'   => $logoPath,
            'base64' => "data:image/{$extension};base64,{$datos}",
        ];
    }
}
