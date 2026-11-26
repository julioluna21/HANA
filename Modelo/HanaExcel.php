<?php
/*
  HANA - Descargas en Excel (.xlsx)
  ---------------------------------------------------------------------------
  Arma un libro de Excel con una o varias hojas de tabla y lo descarga de una vez
  (no se abre ninguna pantalla). Lo usan los controladores de Promesa de valor y
  de Vacantes (op=excel); quién puede descargar lo revisa cada controlador con la
  casilla 63M de Permisos de usuario (hanaPuede('EXCEL', 'descargar')).

  Cada hoja: array(
      'nombre'    => nombre de la pestaña (máx. 31 caracteres),
      'titulo'    => primera línea de la hoja,
      'subtitulo' => segunda línea (periodo, filtros, quién la descargó),
      'columnas'  => array(array(título, ancho, tipo), ...)
                     tipo: texto, fecha (AAAA-MM-DD), fechaHora (AAAA-MM-DD HH:MM:SS),
                           entero, horas (con 2 decimales si los tiene), pct (98.5 => 98,5 %)
      'filas'     => array(array(valor, valor, ...), ...)   null o '' = celda vacía
      'total'     => opcional: una fila de totales al final (mismos tipos)
  )
  Las hojas salen con la cabecera en vino, filtros y la cabecera congelada.
*/
require_once __DIR__ . '/../vendors/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class HanaExcel
{
    const VINO = '6E1A1E';
    const VINO_SUAVE = 'F5E4E5';
    const LINEA = 'E6DCD8';
    const GRIS = '6B7076';

    //Corta con un mensaje en la pestaña del navegador (la descarga se abre con un enlace)
    public static function error($codigo, $mensaje)
    {
        http_response_code($codigo);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>HANA</title></head><body style="font-family:Arial,sans-serif;padding:30px;color:#333;">' .
             '<p>' . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') . '</p><p><a href="javascript:history.back()">Volver</a></p></body></html>';
        exit;
    }

    //Nombre de hoja válido para Excel: sin : \ / ? * [ ], hasta 31 caracteres y sin repetirse
    private static function nombreHoja($libro, $texto)
    {
        $base = trim(mb_substr(trim(preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', (string)$texto), " '"), 0, 31, 'UTF-8'), " '");
        if ($base === '') { $base = 'Hoja'; }
        $nombre = $base; $i = 2;
        while ($libro->sheetNameExists($nombre)) { $nombre = rtrim(mb_substr($base, 0, 26, 'UTF-8'), " '") . ' (' . $i++ . ')'; }
        return $nombre;
    }

    //El texto como se escribió (en la base algunos textos están codificados para HTML)
    private static function texto($valor)
    {
        return html_entity_decode((string)$valor, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    //Pone un valor en la celda según el tipo de la columna
    private static function poner($hoja, $celda, $valor, $tipo)
    {
        if ($valor === null || $valor === '') { return; }
        switch ($tipo) {
            case 'fecha':
            case 'fechaHora':
                $marca = strtotime((string)$valor);
                if ($marca === false) { $hoja->setCellValueExplicit($celda, self::texto($valor), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING); return; }
                $hoja->setCellValue($celda, FechaExcel::PHPToExcel($tipo === 'fecha' ? date('Y-m-d', $marca) : date('Y-m-d H:i:s', $marca)));
                $hoja->getStyle($celda)->getNumberFormat()->setFormatCode($tipo === 'fecha' ? 'dd/mm/yyyy' : 'dd/mm/yyyy hh:mm');
                return;
            case 'entero':
                $hoja->setCellValue($celda, (int)$valor);
                return;
            case 'horas':
                $hoja->setCellValue($celda, (float)$valor);
                $hoja->getStyle($celda)->getNumberFormat()->setFormatCode('#,##0.##');
                return;
            case 'pct':
                $hoja->setCellValue($celda, round((float)$valor / 100, 4));
                $hoja->getStyle($celda)->getNumberFormat()->setFormatCode('0.0%');
                return;
            default:
                //Como texto, para que Excel no lo convierta (números de RQ, radicados, =...)
                $hoja->setCellValueExplicit($celda, self::texto($valor), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }

    //Arma el libro y lo descarga como $archivo (sin la extensión)
    public static function descargar($archivo, $hojas)
    {
        $libro = new Spreadsheet();
        $libro->getProperties()->setCreator('HANA')->setTitle($archivo);
        $libro->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
        $libro->removeSheetByIndex(0);
        foreach ($hojas as $h) {
            $hoja = $libro->createSheet();
            $hoja->setTitle(self::nombreHoja($libro, $h['nombre']));
            $columnas = $h['columnas'];
            $ultima = Coordinate::stringFromColumnIndex(count($columnas));
            //Título y subtítulo
            $hoja->setCellValue('A1', $h['titulo']);
            $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB(self::VINO);
            if (!empty($h['subtitulo'])) {
                $hoja->setCellValue('A2', $h['subtitulo']);
                $hoja->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB(self::GRIS);
            }
            //Cabecera de la tabla en la fila 4
            $filaCab = 4;
            foreach ($columnas as $i => $col) {
                $letra = Coordinate::stringFromColumnIndex($i + 1);
                $hoja->setCellValue($letra . $filaCab, $col[0]);
                $hoja->getColumnDimension($letra)->setWidth($col[1]);
            }
            $rango = 'A' . $filaCab . ':' . $ultima . $filaCab;
            $hoja->getStyle($rango)->applyFromArray(array(
                'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
                'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => self::VINO)),
                'alignment' => array('vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true)));
            $hoja->getRowDimension($filaCab)->setRowHeight(30);
            //Las filas
            $fila = $filaCab;
            foreach ($h['filas'] as $datos) {
                $fila++;
                foreach ($columnas as $i => $col) {
                    self::poner($hoja, Coordinate::stringFromColumnIndex($i + 1) . $fila, isset($datos[$i]) ? $datos[$i] : null, $col[2]);
                }
            }
            if (!count($h['filas'])) {
                $fila++;
                $hoja->setCellValue('A' . $fila, 'No hay datos para lo que se pidió.');
                $hoja->getStyle('A' . $fila)->getFont()->setItalic(true)->getColor()->setRGB(self::GRIS);
            }
            $finDatos = $fila;
            //Bordes suaves, texto ajustado y arriba
            if ($finDatos > $filaCab) {
                $hoja->getStyle('A' . ($filaCab + 1) . ':' . $ultima . $finDatos)->applyFromArray(array(
                    'borders' => array('allBorders' => array('borderStyle' => Border::BORDER_THIN, 'color' => array('rgb' => self::LINEA))),
                    'alignment' => array('vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true)));
            }
            //Fila de totales
            if (!empty($h['total']) && count($h['filas'])) {
                $fila++;
                foreach ($columnas as $i => $col) {
                    self::poner($hoja, Coordinate::stringFromColumnIndex($i + 1) . $fila, isset($h['total'][$i]) ? $h['total'][$i] : null, $col[2]);
                }
                $hoja->getStyle('A' . $fila . ':' . $ultima . $fila)->applyFromArray(array(
                    'font' => array('bold' => true, 'color' => array('rgb' => self::VINO)),
                    'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => self::VINO_SUAVE))));
            }
            //Filtros en la cabecera y la cabecera fija al bajar
            if (count($h['filas'])) { $hoja->setAutoFilter('A' . $filaCab . ':' . $ultima . $finDatos); }
            $hoja->freezePane('A' . ($filaCab + 1));
            $hoja->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        }
        $libro->setActiveSheetIndex(0);

        $nombre = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $archivo) . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Cache-Control: max-age=0');
        (new Xlsx($libro))->save('php://output');
        exit;
    }
}
