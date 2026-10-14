<?php
/*
  HANA - Consolidado de promesa de valor, ejecutado y ausentismo, en Excel
  Con la misma forma del archivo "CONSOLIDADO" que se armaba a mano: una hoja
  RESUMEN con todos los proyectos (por proyecto, por semana, por cargo y por
  peaje) y una hoja por proyecto (por peaje, por semana y por peaje y cargo).
  Sale de la misma consulta que la pantalla, así que las cifras son iguales.
  Los números van como valores: es una foto del mes, no una plantilla.
*/
require_once __DIR__ . '/../Control/PromesaConsolidadoExcelControl.php'; //revisa el acceso y trae el consolidado ($D)
require_once __DIR__ . '/../vendors/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$MESES = array('', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');
$RES = array('MAS' => 'MÁS', 'MENOS' => 'MENOS', 'IGUAL' => 'IGUAL', 'SIN_DATOS' => 'SIN DATOS');
$VINO = '6E1A1E'; $VINO2 = '8C3A3E'; $ROSA = 'F5E4E5';

//Letra de la columna de Excel a partir de su número (1 = A, 27 = AA)
function col($n) { return Coordinate::stringFromColumnIndex($n); }
//Nombre de hoja válido para Excel (máx. 31 caracteres, sin : \ / ? * [ ])
function nombreHoja($t) { return mb_substr(preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', $t), 0, 31); }

//Los títulos de las columnas de una tabla de resumen. Devuelve cuántas columnas ocupa
function titulos($h, $fila, $primeras, $D, $conDias, $VINO, $VINO2)
{
    $c = 1;
    foreach ($primeras as $t) { $h->setCellValue(col($c++) . $fila, $t); }
    foreach (array('PROMESA DE VALOR', 'EJECUTADO', 'DIFERENCIA', '% EJECUCIÓN', 'RESULTADO') as $t) { $h->setCellValue(col($c++) . $fila, $t); }
    $desdeAus = $c;
    foreach ($D['motivos'] as $m) { if ($m['cuenta']) { $h->setCellValue(col($c++) . $fila, $m['nombre']); } }
    $h->setCellValue(col($c++) . $fila, 'TOTAL AUSENCIAS');
    $h->setCellValue(col($c++) . $fila, 'CAUSA PRINCIPAL');
    foreach ($D['motivos'] as $m) { if (!$m['cuenta']) { $h->setCellValue(col($c++) . $fila, $m['nombre']); } } //motivos marcados como "no es ausencia": se ven, pero no suman
    if ($conDias) { $h->setCellValue(col($c++) . $fila, 'DÍAS REGISTRADOS'); $h->setCellValue(col($c++) . $fila, 'DÍAS ESPERADOS'); }
    $ultima = $c - 1;
    $h->getStyle('A' . $fila . ':' . col($ultima) . $fila)->applyFromArray(array(
        'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF'), 'size' => 9),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => $VINO)),
        'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true)));
    //El grupo de ausencias va en un vino más claro, para separarlo de la promesa y el ejecutado
    $h->getStyle(col($desdeAus) . $fila . ':' . col($ultima - ($conDias ? 2 : 0)) . $fila)->getFill()->getStartColor()->setRGB($VINO2);
    $h->getRowDimension($fila)->setRowHeight(30);
    return $ultima;
}

//Una fila de datos después de las columnas de nombre. $c0: primera columna libre
function datos($h, $fila, $c0, $f, $D, $conDias, $RES)
{
    $c = $c0; $hay = $f['res'] !== 'SIN_DATOS';
    $h->getStyle(col($c0) . $fila . ':' . col($c0 + 40) . $fila)->getNumberFormat()->setFormatCode('#,##0'); //miles con punto; abajo se cambian la diferencia y el %
    $h->setCellValue(col($c++) . $fila, $hay ? $f['prom'] : null);
    $h->setCellValue(col($c++) . $fila, $hay ? $f['eje'] : null);
    $h->setCellValue(col($c) . $fila, $hay ? $f['dif'] : null);
    $h->getStyle(col($c++) . $fila)->getNumberFormat()->setFormatCode('+#,##0;-#,##0;0');         //la diferencia siempre con su signo
    $h->setCellValue(col($c) . $fila, $f['pct'] === null ? null : $f['pct'] / 100);
    $h->getStyle(col($c++) . $fila)->getNumberFormat()->setFormatCode('0.0%');
    $h->setCellValue(col($c) . $fila, $RES[$f['res']]);
    $h->getStyle(col($c++) . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    foreach ($D['motivos'] as $i => $m) { if ($m['cuenta']) { $h->setCellValue(col($c++) . $fila, $f['aus'][$i]); } }
    $h->setCellValue(col($c++) . $fila, $f['ausTotal']);
    $h->setCellValue(col($c++) . $fila, $f['causa'] ? $f['causa'] : 'SIN AUSENCIAS');
    foreach ($D['motivos'] as $i => $m) { if (!$m['cuenta']) { $h->setCellValue(col($c++) . $fila, $f['aus'][$i]); } }
    if ($conDias) { $h->setCellValue(col($c++) . $fila, $f['diasReg']); $h->setCellValue(col($c++) . $fila, $f['diasEsp']); }
    //Faltó gente: la diferencia en vino. Hubo de más: en verde
    if ($hay && $f['dif'] !== 0) { $h->getStyle(col($c0 + 2) . $fila)->getFont()->setBold(true)->getColor()->setRGB($f['dif'] < 0 ? '6E1A1E' : '0F7A64'); }
}

//Escribe el nombre de una fila que ocupa las columnas A y B juntas
function nombre($h, $fila, $texto)
{
    $h->setCellValue('A' . $fila, $texto);
    $h->mergeCells('A' . $fila . ':B' . $fila);
}

//Deja una fila como total: en negrita y con fondo
function filaTotal($h, $fila, $ultima, $ROSA)
{
    $h->getStyle('A' . $fila . ':' . col($ultima) . $fila)->applyFromArray(array('font' => array('bold' => true),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => $ROSA))));
}

//Bordes finos de una tabla y números con separador de miles
function cerrarTabla($h, $desde, $hasta, $ultima)
{
    $h->getStyle('A' . $desde . ':' . col($ultima) . $hasta)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D9D9');
}

//Escribe un subtítulo en negrilla y color vino en la fila indicada
function subtitulo($h, $fila, $texto, $VINO)
{
    $h->setCellValue('A' . $fila, $texto);
    $h->getStyle('A' . $fila)->getFont()->setBold(true)->setSize(12)->getColor()->setRGB($VINO);
}

//La tabla "por semana": la diferencia de cada semana y, al lado, las ausencias. Devuelve la siguiente fila libre
function tablaSemanas($h, $fila, $titulo, $filas, $total, $nombreTotal, $D, $VINO, $VINO2, $ROSA)
{
    $h->setCellValue('A' . $fila, $titulo);
    $h->setCellValue('B' . $fila, 'DIFERENCIA (EJECUTADO − PROMESA DE VALOR)'); $h->mergeCells('B' . $fila . ':F' . $fila);
    $h->setCellValue('G' . $fila, 'AUSENCIAS (sin las que no cuentan)');         $h->mergeCells('G' . $fila . ':K' . $fila);
    foreach ($D['semanas'] as $i => $s) { $h->setCellValue(col(2 + $i) . ($fila + 1), mb_strtoupper($s)); $h->setCellValue(col(7 + $i) . ($fila + 1), mb_strtoupper($s)); }
    $h->mergeCells('A' . $fila . ':A' . ($fila + 1));
    $h->getStyle('A' . $fila . ':K' . ($fila + 1))->applyFromArray(array(
        'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF'), 'size' => 9),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => $VINO)),
        'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER)));
    $h->getStyle('G' . $fila . ':K' . ($fila + 1))->getFill()->getStartColor()->setRGB($VINO2);
    $r = $fila + 2;
    $una = function ($nombre, $f) use ($h, &$r) {
        $h->setCellValue('A' . $r, $nombre);
        for ($i = 0; $i < 5; $i++) {
            $h->setCellValue(col(2 + $i) . $r, $f['res'] === 'SIN_DATOS' ? null : $f['sem'][$i]);
            $h->setCellValue(col(7 + $i) . $r, $f['ausSem'][$i]);
        }
        $h->getStyle('B' . $r . ':F' . $r)->getNumberFormat()->setFormatCode('+#,##0;-#,##0;0');   //la diferencia, con su signo
        $h->getStyle('G' . $r . ':K' . $r)->getNumberFormat()->setFormatCode('#,##0');
        $r++;
    };
    foreach ($filas as $x) { $una($x['nombre'], $x['fila']); }
    $una($nombreTotal, $total);
    filaTotal($h, $r - 1, 11, $ROSA);
    cerrarTabla($h, $fila, $r - 1, 11);
    return $r + 1;
}

//El encabezado de una hoja: título, periodo y la explicación de la diferencia
function encabezado($h, $titulo, $D, $MESES, $VINO)
{
    $p = $D['periodo'];
    $h->setCellValue('A1', $titulo);
    $h->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($VINO);
    $corte = $p['corte'] === 0 ? 'el mes todavía no empieza' : ($p['corte'] < $p['diasMes'] ? 'va en curso: cifras hasta el día ' . $p['corte'] : 'mes completo');
    //Si se filtró por cargo, se dice cuáles entran en las cifras
    $solo = '';
    if (!empty($D['filtroCargos'])) {
        $nombres = array();
        foreach ($D['cargos'] as $k) { if (in_array($k['id'], $D['filtroCargos'], true)) { $nombres[] = $k['nombre']; } }
        $solo = ' Solo los cargos: ' . implode(', ', $nombres) . '.';
    }
    $h->setCellValue('A2', ucfirst($MESES[$p['mes']]) . ' de ' . $p['anio'] . ' (' . $corte . ').' . $solo . ' Generado por HANA el ' . date('d/m/Y') . ' a las ' . date('H:i') . '.');
    $h->setCellValue('A3', 'DIFERENCIA = EJECUTADO − PROMESA DE VALOR (positivo = se ejecutó MÁS de lo prometido; negativo = MENOS). El porqué se lee en las ausencias del mismo mes. '
                         . 'La promesa y el ejecutado se comparan solo en los días con ejecutado registrado.');
    $h->getStyle('A2:A3')->getFont()->setSize(9)->getColor()->setRGB('6B7076');
}

//Anchos de columna y que la primera se quede quieta al correr la hoja
function formatoHoja($h, $ultima, $anchoA, $colCausa)
{
    $h->getColumnDimension('A')->setWidth($anchoA);
    $h->getColumnDimension('B')->setWidth(26);
    for ($c = 3; $c <= $ultima; $c++) { $h->getColumnDimension(col($c))->setWidth(15); }
    $h->getColumnDimension(col($colCausa))->setWidth(24);                 //la causa principal es texto: necesita más ancho
    $h->freezePane('B5');
    $h->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
}

//En qué columna cae "CAUSA PRINCIPAL": 2 de nombre + 5 de promesa y ejecutado + los motivos que cuentan + el total
$colCausa = 2 + 5 + count(array_filter($D['motivos'], function ($m) { return $m['cuenta']; })) + 2;

$libro = new Spreadsheet();
$libro->removeSheetByIndex(0);
$libro->getProperties()->setCreator('HANA - Grupo Regency')->setTitle('Consolidado de promesa de valor, ejecutado y ausentismo');
$libro->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

//---------------------------------------------------------------------------
// Hoja RESUMEN: todos los proyectos
//---------------------------------------------------------------------------
$h = $libro->createSheet()->setTitle('RESUMEN');
encabezado($h, 'INFORME CONSOLIDADO: PROMESA DE VALOR, EJECUTADO Y AUSENTISMO — ' . count($D['proyectos']) . (count($D['proyectos']) === 1 ? ' PROYECTO' : ' PROYECTOS'), $D, $MESES, $VINO);

//1. Resumen por proyecto
$r = 5; subtitulo($h, $r, '1. RESUMEN POR PROYECTO', $VINO); $r++;
$ini = $r; $ultima = titulos($h, $r, array('PROYECTO', ''), $D, true, $VINO, $VINO2); $h->mergeCells('A' . $r . ':B' . $r); $r++;
foreach ($D['proyectos'] as $p) { nombre($h, $r, $p['nombre']); datos($h, $r, 3, $p['fila'], $D, true, $RES); $r++; }
nombre($h, $r, 'TOTAL'); datos($h, $r, 3, $D['total'], $D, true, $RES); filaTotal($h, $r, $ultima, $ROSA);
cerrarTabla($h, $ini, $r, $ultima); $anchoMax = $ultima; $r += 2;

//2. Por semana
subtitulo($h, $r, '2. POR SEMANA (semana 1 = días 1 a 7, semana 2 = 8 a 14, semana 3 = 15 a 21, semana 4 = 22 a 28)', $VINO); $r++;
$r = tablaSemanas($h, $r, 'PROYECTO', $D['proyectos'], $D['total'], 'TOTAL', $D, $VINO, $VINO2, $ROSA);

//3. Resumen por cargo
subtitulo($h, $r, '3. RESUMEN POR CARGO (todos los proyectos)', $VINO); $r++;
$ini = $r; $ult = titulos($h, $r, array('CARGO', ''), $D, false, $VINO, $VINO2); $h->mergeCells('A' . $r . ':B' . $r); $r++;
foreach ($D['roles'] as $x) { nombre($h, $r, $x['nombre']); datos($h, $r, 3, $x['fila'], $D, false, $RES); $r++; }
nombre($h, $r, 'TOTAL'); datos($h, $r, 3, $D['total'], $D, false, $RES); filaTotal($h, $r, $ult, $ROSA);
cerrarTabla($h, $ini, $r, $ult); $r += 2;

//4. Detalle por peaje
subtitulo($h, $r, '4. DETALLE POR PEAJE', $VINO); $r++;
$ini = $r; titulos($h, $r, array('PROYECTO', 'PEAJE'), $D, true, $VINO, $VINO2); $r++;
foreach ($D['proyectos'] as $p) {
    foreach ($p['centros'] as $c) { $h->setCellValue('A' . $r, $p['nombre']); $h->setCellValue('B' . $r, $c['nombre']); datos($h, $r, 3, $c['fila'], $D, true, $RES); $r++; }
}
$h->setCellValue('A' . $r, 'TOTAL'); datos($h, $r, 3, $D['total'], $D, true, $RES); filaTotal($h, $r, $ultima, $ROSA);
cerrarTabla($h, $ini, $r, $ultima);
formatoHoja($h, $anchoMax, 24, $colCausa);

//---------------------------------------------------------------------------
// Una hoja por proyecto
//---------------------------------------------------------------------------
foreach ($D['proyectos'] as $p) {
    $h = $libro->createSheet()->setTitle(nombreHoja($p['nombre']));
    encabezado($h, $p['nombre'] . ': PROMESA DE VALOR, EJECUTADO Y AUSENTISMO', $D, $MESES, $VINO);

    //1. Resumen por peaje
    $r = 5; subtitulo($h, $r, '1. RESUMEN POR PEAJE', $VINO); $r++;
    $ini = $r; $ultima = titulos($h, $r, array('PEAJE', ''), $D, true, $VINO, $VINO2); $h->mergeCells('A' . $r . ':B' . $r); $r++;
    foreach ($p['centros'] as $c) { nombre($h, $r, $c['nombre']); datos($h, $r, 3, $c['fila'], $D, true, $RES); $r++; }
    nombre($h, $r, 'TOTAL ' . $p['nombre']); datos($h, $r, 3, $p['fila'], $D, true, $RES); filaTotal($h, $r, $ultima, $ROSA);
    cerrarTabla($h, $ini, $r, $ultima); $r += 2;

    //2. Por semana
    subtitulo($h, $r, '2. POR SEMANA', $VINO); $r++;
    $r = tablaSemanas($h, $r, 'PEAJE', $p['centros'], $p['fila'], 'TOTAL ' . $p['nombre'], $D, $VINO, $VINO2, $ROSA);

    //3. Detalle por peaje y cargo
    subtitulo($h, $r, '3. DETALLE POR PEAJE Y CARGO', $VINO); $r++;
    $ini = $r; $ult = titulos($h, $r, array('PEAJE', 'CARGO'), $D, false, $VINO, $VINO2); $r++;
    foreach ($p['centros'] as $c) {
        foreach ($c['cargos'] as $k) { $h->setCellValue('A' . $r, $c['nombre']); $h->setCellValue('B' . $r, $k['nombre']); datos($h, $r, 3, $k['fila'], $D, false, $RES); $r++; }
    }
    $h->setCellValue('A' . $r, 'TOTAL ' . $p['nombre']); datos($h, $r, 3, $p['fila'], $D, false, $RES); filaTotal($h, $r, $ult, $ROSA);
    cerrarTabla($h, $ini, $r, $ult);
    formatoHoja($h, $ultima, 22, $colCausa);
}

$libro->setActiveSheetIndex(0);

$nombre = 'Consolidado promesa de valor y ausentismo ' . $MESES[$mes] . ' ' . $anio . '.xlsx';
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: max-age=0');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
