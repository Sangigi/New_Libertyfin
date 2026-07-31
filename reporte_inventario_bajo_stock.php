<?php
// reporte_inventario_bajo_stock.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\ProductoRepository;
use App\Repositories\SistemaConfigRepository;
use App\Repositories\SucursalRepository;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

Auth::requireLoginForPage('login.php');

$sucursal_id = isset($_GET['sucursal_id']) && is_numeric($_GET['sucursal_id']) ? (int) $_GET['sucursal_id'] : null;

try {
    $pdoEmpresa = Database::pdo($_SESSION['empresa_db']);

    $empresa_info = (new SistemaConfigRepository($pdoEmpresa))->actual();

    $sucursal_nombre = 'Todas las sucursales';
    if ($sucursal_id) {
        $sucursal = (new SucursalRepository($pdoEmpresa))->findActiveById($sucursal_id);
        $sucursal_nombre = $sucursal['nombre'] ?? $sucursal_nombre;
    }

    $productos = (new ProductoRepository($pdoEmpresa))->bajoStock($sucursal_id);

    // Crear un nuevo libro de Excel
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator($empresa_info['nombre_empresa'] ?? 'Sistema POS')
        ->setTitle('Reporte de Productos Bajo Stock')
        ->setSubject('Reporte generado automáticamente')
        ->setDescription('Reporte de productos que requieren reabastecimiento');

    $sheet = $spreadsheet->getActiveSheet();

    $sheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);

    // Título del reporte
    $sheet->mergeCells('A1:H1');
    $sheet->setCellValue('A1', strtoupper($empresa_info['nombre_empresa'] ?? 'MI EMPRESA'));
    $sheet->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '2E86C1']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    $row = 2;
    $sheet->mergeCells('A2:H2');
    $sheet->setCellValue('A2', 'REPORTE DE PRODUCTOS BAJO STOCK');
    $sheet->getStyle('A2')->applyFromArray([
        'font' => ['bold' => true, 'size' => 14],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    $row++;
    $sheet->mergeCells('A3:H3');
    $sheet->setCellValue('A3', 'Generado el: ' . date('d/m/Y H:i:s'));

    $row++;
    $sheet->mergeCells('A4:H4');
    $sheet->setCellValue('A4', 'Sucursal: ' . $sucursal_nombre);

    $row++;
    $sheet->mergeCells('A5:H5');
    $sheet->setCellValue('A5', 'Generado por: ' . ($_SESSION['usuario_nombre'] ?? 'Usuario'));

    $row += 2;

    $headers = ['Código', 'Producto', 'Categoría', 'Descripción', 'Precio', 'Stock', 'Stock Mínimo', 'Estado'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FF6B6B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $col++;
    }
    $row++;

    $sheet->getStyle('A:A')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

    $total_productos = 0;
    $productos_agotados = 0;
    $productos_bajo_stock = 0;
    $valor_total = 0;

    foreach ($productos as $row_data) {
        $stock = $row_data['stock'];
        $stock_minimo = $row_data['stock_minimo'];
        $valor_producto = $row_data['precio'] * $stock;

        $sheet->setCellValueExplicit('A' . $row, $row_data['codigo'], DataType::TYPE_STRING);
        $sheet->setCellValue('B' . $row, $row_data['nombre']);
        $sheet->setCellValue('C' . $row, $row_data['categoria'] ?? 'Sin categoría');
        $sheet->setCellValue('D' . $row, $row_data['descripcion'] ?? '');
        $sheet->setCellValue('E' . $row, $row_data['precio']);
        $sheet->setCellValue('F' . $row, $stock);
        $sheet->setCellValue('G' . $row, $stock_minimo);
        $sheet->setCellValue('H' . $row, $row_data['estado_stock']);

        $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');

        $estado = $row_data['estado_stock'];
        if ($estado == 'AGOTADO') {
            $productos_agotados++;
            $sheet->getStyle('H' . $row)->getFont()->getColor()->setARGB('FF0000');
        } elseif ($estado == 'BAJO STOCK') {
            $productos_bajo_stock++;
            $sheet->getStyle('H' . $row)->getFont()->getColor()->setARGB('FF8C00');
        }

        $total_productos++;
        $valor_total += $valor_producto;
        $row++;
    }

    if ($total_productos > 0) {
        $dataRange = 'A7:H' . ($row - 1);
        $sheet->getStyle($dataRange)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ]);
    }

    $row += 2;

    $sheet->mergeCells('A' . $row . ':D' . $row);
    $sheet->setCellValue('A' . $row, 'RESUMEN DEL REPORTE');
    $sheet->getStyle('A' . $row)->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E86C1']],
    ]);
    $row++;

    $resumen_data = [
        ['Total de Productos con Problemas', $total_productos],
        ['Productos Agotados', $productos_agotados],
        ['Productos Bajo Stock', $productos_bajo_stock],
        ['Valor Total del Stock', $valor_total],
    ];

    foreach ($resumen_data as $index => $item) {
        $currentRow = $row + $index;
        $sheet->setCellValue('A' . $currentRow, $item[0]);
        $sheet->setCellValue('B' . $currentRow, $item[1]);

        if ($item[0] == 'Valor Total del Stock') {
            $sheet->getStyle('B' . $currentRow)->getNumberFormat()->setFormatCode('$#,##0.00');
        }

        $cellRange = 'A' . $currentRow . ':B' . $currentRow;
        $sheet->getStyle($cellRange)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $index % 2 == 0 ? 'F2F2F2' : 'FFFFFF']],
        ]);
    }

    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $filename = 'productos_bajo_stock_' . date('Ymd_His') . '.xlsx';

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
