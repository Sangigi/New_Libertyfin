<?php
// reporte_inventario_completo.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\CategoriaRepository;
use App\Repositories\ProductoRepository;
use App\Repositories\SistemaConfigRepository;
use App\Repositories\SucursalRepository;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

Auth::requireLoginForPage('login.php');

function getStockFilterName($filter)
{
    switch ($filter) {
        case 'bajo':
            return 'Bajo Stock';
        case 'sin':
            return 'Sin Stock';
        case 'normal':
            return 'Stock Normal';
        default:
            return 'Todos';
    }
}

$sucursal_id = isset($_GET['sucursal_id']) && is_numeric($_GET['sucursal_id']) ? (int) $_GET['sucursal_id'] : null;
$categoria_id = isset($_GET['categoria_id']) && is_numeric($_GET['categoria_id']) ? (int) $_GET['categoria_id'] : null;
$stock_filter = $_GET['stock_filter'] ?? '';

try {
    $pdoEmpresa = Database::pdo($_SESSION['empresa_db']);

    $empresa_info = (new SistemaConfigRepository($pdoEmpresa))->actual();

    $sucursal_nombre = 'Todas las sucursales';
    if ($sucursal_id) {
        $sucursal = (new SucursalRepository($pdoEmpresa))->findActiveById($sucursal_id);
        $sucursal_nombre = $sucursal['nombre'] ?? $sucursal_nombre;
    }

    $categoria_nombre = 'Todas las categorías';
    if ($categoria_id) {
        $categoria = (new CategoriaRepository($pdoEmpresa))->encontrarActivaPorId($categoria_id);
        $categoria_nombre = $categoria['nombre'] ?? $categoria_nombre;
    }

    $productoRepository = new ProductoRepository($pdoEmpresa);
    $productos = $productoRepository->inventarioCompleto($sucursal_id, $categoria_id, $stock_filter);
    $contadores = $productoRepository->contarBajoYSinStock($sucursal_id, $categoria_id);

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator($empresa_info['nombre_empresa'] ?? 'Sistema POS')
        ->setTitle('Reporte de Inventario Completo')
        ->setSubject('Reporte generado automáticamente')
        ->setDescription('Reporte completo del inventario de productos');

    $sheet = $spreadsheet->getActiveSheet();

    $sheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);

    $sheet->mergeCells('A1:J1');
    $sheet->setCellValue('A1', strtoupper($empresa_info['nombre_empresa'] ?? 'MI EMPRESA'));
    $sheet->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '2E86C1']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    $row = 2;
    $sheet->mergeCells('A2:J2');
    $sheet->setCellValue('A2', 'REPORTE DE INVENTARIO COMPLETO');
    $sheet->getStyle('A2')->applyFromArray([
        'font' => ['bold' => true, 'size' => 14],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    $row++;
    $sheet->mergeCells('A3:J3');
    $sheet->setCellValue('A3', 'Generado el: ' . date('d/m/Y H:i:s'));

    $row++;
    $sheet->mergeCells('A4:J4');
    $sheet->setCellValue('A4', 'Sucursal: ' . $sucursal_nombre);

    $row++;
    $sheet->mergeCells('A5:J5');
    $sheet->setCellValue('A5', 'Categoría: ' . $categoria_nombre);

    $row++;
    $sheet->mergeCells('A6:J6');
    $sheet->setCellValue('A6', 'Filtro de Stock: ' . getStockFilterName($stock_filter));

    $row++;
    $sheet->mergeCells('A7:J7');
    $sheet->setCellValue('A7', 'Generado por: ' . ($_SESSION['usuario_nombre'] ?? 'Usuario'));

    $row += 2;

    $headers = [
        'Código', 'Producto', 'Categoría', 'Descripción', 'Precio',
        $sucursal_id ? 'Stock (Sucursal)' : 'Stock (Total)',
        'Stock Mínimo', 'Valor en Stock', 'Estado', 'Última Actualización',
    ];

    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E86C1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $col++;
    }
    $row++;

    $sheet->getStyle('A')->getNumberFormat()->setFormatCode('@');

    $total_productos = 0;
    $total_stock = 0;
    $total_valor_inventario = 0;
    $productos_activos = 0;
    $productos_inactivos = 0;

    foreach ($productos as $row_data) {
        $stock = $row_data['stock'];
        $valor_stock = $row_data['precio'] * $stock;

        $sheet->setCellValueExplicit('A' . $row, (string) $row_data['codigo'], DataType::TYPE_STRING);
        $sheet->setCellValue('B' . $row, $row_data['nombre']);
        $sheet->setCellValue('C' . $row, $row_data['categoria_nombre'] ?? 'Sin categoría');
        $sheet->setCellValue('D' . $row, $row_data['descripcion'] ?? '');
        $sheet->setCellValue('E' . $row, $row_data['precio']);
        $sheet->setCellValue('F' . $row, $stock);
        $sheet->setCellValue('G' . $row, $row_data['stock_minimo_total']);
        $sheet->setCellValue('H' . $row, $valor_stock);
        $sheet->setCellValue('I' . $row, $row_data['estado']);
        $sheet->setCellValue('J' . $row, $row_data['fecha_actualizacion']);

        $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');

        if ($row_data['estado'] == 'Activo') {
            $sheet->getStyle('I' . $row)->getFont()->getColor()->setARGB('008000');
            $productos_activos++;
        } else {
            $sheet->getStyle('I' . $row)->getFont()->getColor()->setARGB('FF0000');
            $productos_inactivos++;
        }

        if ($stock == 0) {
            $sheet->getStyle('F' . $row)->getFont()->getColor()->setARGB('FF0000');
        } elseif ($stock <= $row_data['stock_minimo_total']) {
            $sheet->getStyle('F' . $row)->getFont()->getColor()->setARGB('FF8C00');
        }

        $total_productos++;
        $total_stock += $stock;
        $total_valor_inventario += $valor_stock;
        $row++;
    }

    if ($total_productos > 0) {
        $dataRange = 'A9:J' . ($row - 1);
        $sheet->getStyle($dataRange)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ]);
    }

    $row += 2;

    $sheet->mergeCells('A' . $row . ':D' . $row);
    $sheet->setCellValue('A' . $row, 'RESUMEN ESTADÍSTICO');
    $sheet->getStyle('A' . $row)->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '27ae60']],
    ]);
    $row++;

    $resumen_data = [
        ['Total de Productos', $total_productos],
        ['Productos Activos', $productos_activos],
        ['Productos Inactivos', $productos_inactivos],
        ['Total Stock', $total_stock],
        ['Productos Bajo Stock', $contadores['bajo_stock']],
        ['Productos Sin Stock', $contadores['sin_stock']],
        ['Valor Total del Inventario', $total_valor_inventario],
    ];

    foreach ($resumen_data as $index => $item) {
        $currentRow = $row + $index;
        $sheet->setCellValue('A' . $currentRow, $item[0]);
        $sheet->setCellValue('B' . $currentRow, $item[1]);

        if ($item[0] == 'Valor Total del Inventario') {
            $sheet->getStyle('B' . $currentRow)->getNumberFormat()->setFormatCode('$#,##0.00');
        }

        $cellRange = 'A' . $currentRow . ':B' . $currentRow;
        $sheet->getStyle($cellRange)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $index % 2 == 0 ? 'F2F2F2' : 'FFFFFF']],
        ]);
    }

    foreach (range('A', 'J') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $filename = 'inventario_completo_' . date('Ymd_His') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Cache-Control: max-age=1');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: cache, must-revalidate');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
