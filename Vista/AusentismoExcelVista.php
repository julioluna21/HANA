<?php
/*
  HANA - Ausentismo: descargar el "Reporte nacional de ausentismo" en Excel
  Con la misma estructura del archivo que se usa hoy: una hoja por centro, con
  un bloque por cargo (novedades × días) y las ausencias diarias; y al final
  la hoja del proyecto con el consolidado y el TOTAL (mes y 1ª quincena).
  Con un solo peaje: su hoja y su TOTAL.
  Con todos los proyectos: una hoja por proyecto con su consolidado, la hoja
  "Por proyecto" con el total de cada uno y al final la de todos juntos.
  Con un rango de días, las hojas traen solo las columnas de esos días y los
  totales son de esos días; la 1ª quincena sale solo con el mes completo.
  Los totales van como fórmulas de Excel: si se corrige una celda en el
  archivo, se recalculan.
*/
require_once __DIR__ . '/../Control/AusentismoExcelControl.php'; //revisa el acceso y trae los datos
require_once __DIR__ . '/../vendors/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$MESES = array('', 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE');
$VINO = '6E1A1E';
$n = $d2 - $d1 + 1;                    //columnas de días: la B es el día $d1
$todoMes = $d1 === 1 && $d2 === $dias; //la 1ª quincena solo tiene sentido con el mes completo
$textoDias = $todoMes ? '' : ($d1 === $d2 ? ' — DÍA ' . $d1 : ' — DÍAS ' . $d1 . ' A ' . $d2);
$periodo = $MESES[$mes] . ' ' . $anio . $textoDias;

$libro = new Spreadsheet();
$libro->removeSheetByIndex(0);
$libro->getProperties()->setCreator('HANA - Grupo Regency')->setTitle('Reporte nacional de ausentismo ' . $proy['NOM_PROYECTO']);

//Letra de la columna de Excel a partir de su número (1 = A, 27 = AA)
function col($i) { return Coordinate::stringFromColumnIndex($i); }

//Hoja nueva con un nombre válido para Excel (máx. 31 caracteres, sin : \ / ? * [ ]) que no se repita en el libro
function hojaNueva($libro, $t)
{
    $base = mb_substr(trim(preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', $t)), 0, 31);
    $nombre = $base; $i = 2;
    while ($libro->sheetNameExists($nombre)) { $nombre = mb_substr($base, 0, 26) . ' (' . $i++ . ')'; }
    $h = $libro->createSheet();
    $h->setTitle($nombre);
    return $h;
}

//Título de la hoja, en la fila 2
function tituloHoja($h, $texto, $VINO)
{
    $h->setCellValue('A2', $texto);
    $h->getStyle('A2')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB($VINO);
}

//Encabezado de un bloque: el cargo, los días del rango y "TOTAL" (y "1ª QUINCENA" si se pide)
function cabecera($h, $fila, $titulo, $d1, $d2, $VINO, $conQuincena = false)
{
    $n = $d2 - $d1 + 1;
    $h->setCellValue('A' . $fila, $titulo);
    for ($d = $d1; $d <= $d2; $d++) { $h->setCellValue(col($d - $d1 + 2) . $fila, $d); }
    $h->setCellValue(col($n + 2) . $fila, 'TOTAL');
    $ultima = $n + 2;
    if ($conQuincena) { $h->setCellValue(col($n + 3) . $fila, '1ª QUINCENA'); $ultima++; }
    $h->getStyle('A' . $fila . ':' . col($ultima) . $fila)->applyFromArray(array(
        'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => $VINO)),
        'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER)));
    $h->getStyle('A' . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
}

//Escribe un bloque (cargo) con sus valores [idNovedad][dia] del día $d1 al $d2;
//devuelve [fila del total del día, siguiente fila libre]
function bloque($h, $fila, $cargo, $novs, $vals, $d1, $d2, $VINO)
{
    $n = $d2 - $d1 + 1;
    cabecera($h, $fila, $cargo['NOMBRE'], $d1, $d2, $VINO);
    $filasCuenta = array();
    $r = $fila + 1;
    foreach ($novs as $nv) {
        if ($nv['ID_CARGO_AUS'] && (int)$nv['ID_CARGO_AUS'] !== (int)$cargo['ID_CARGO_AUS']) { continue; }
        $h->setCellValue('A' . $r, $nv['NOMBRE']);
        $v = isset($vals[(int)$nv['ID_NOVEDAD_AUS']]) ? $vals[(int)$nv['ID_NOVEDAD_AUS']] : array();
        for ($d = $d1; $d <= $d2; $d++) { if (!empty($v[$d])) { $h->setCellValue(col($d - $d1 + 2) . $r, $v[$d]); } }
        $h->setCellValue(col($n + 2) . $r, '=SUM(B' . $r . ':' . col($n + 1) . $r . ')');
        if ((int)$nv['CUENTA_AUSENCIA'] === 1) { $filasCuenta[] = $r; }
        else { $h->getStyle('A' . $r . ':' . col($n + 2) . $r)->getFont()->setItalic(true)->getColor()->setRGB('6B7076'); }
        $r++;
    }
    //Total del día: solo las filas que son ausencia
    $h->setCellValue('A' . $r, 'TOTAL DEL DÍA');
    for ($k = 1; $k <= $n + 1; $k++) {
        $c = col($k + 1);
        $h->setCellValue($c . $r, count($filasCuenta) ? '=' . implode('+', array_map(function ($x) use ($c) { return $c . $x; }, $filasCuenta)) : 0);
    }
    $h->getStyle('A' . $r . ':' . col($n + 2) . $r)->applyFromArray(array('font' => array('bold' => true),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => 'F2F2F2'))));
    $h->getStyle('A' . $fila . ':' . col($n + 2) . $r)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D9D9');
    return array($r, $r + 3);
}

//Anchos de columna y formato común de cada hoja del Excel ($n = columnas de días)
function formatoHoja($h, $n, $extra = 0)
{
    $h->getColumnDimension('A')->setWidth(34);
    for ($k = 1; $k <= $n; $k++) { $h->getColumnDimension(col($k + 1))->setWidth(4.5); }
    for ($i = 0; $i <= $extra; $i++) { $h->getColumnDimension(col($n + 2 + $i))->setWidth(12); }
    $h->freezePane('B1');
    $h->getStyle('B1:' . col($n + 3 + $extra) . $h->getHighestRow())->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

//Hoja de un consolidado: un bloque por cargo con datos y la tabla TOTAL por novedad.
//Devuelve [hoja, filas de la tabla TOTAL: idNovedad => fila]
function hojaConsolidado($libro, $nombreHoja, $titulo, $porCargo, $cargos, $novedades, $d1, $d2, $todoMes, $VINO)
{
    $n = $d2 - $d1 + 1;
    $colTotal = col($n + 2);
    $h = hojaNueva($libro, $nombreHoja);
    tituloHoja($h, $titulo, $VINO);
    $fila = 4;
    $filasNov = array(); //[idNovedad] = filas de esa novedad en todos los bloques
    foreach ($cargos as $cg) {
        $idc = (int)$cg['ID_CARGO_AUS'];
        if (empty($porCargo[$idc])) { continue; } //solo los cargos con datos
        $inicio = $fila + 1;
        list($ft, $sig) = bloque($h, $fila, $cg, $novedades, $porCargo[$idc], $d1, $d2, $VINO);
        for ($r = $inicio; $r < $ft; $r++) {
            foreach ($novedades as $nv) { if ($h->getCell('A' . $r)->getValue() === $nv['NOMBRE'] && (int)$nv['CUENTA_AUSENCIA'] === 1) { $filasNov[(int)$nv['ID_NOVEDAD_AUS']][] = $r; } }
        }
        $fila = $sig;
    }
    //TOTAL por novedad: cada día, el total y, con el mes completo, la 1ª quincena (días 1 a 14)
    cabecera($h, $fila, 'TOTAL', $d1, $d2, $VINO, $todoMes);
    $r = $fila + 1;
    $filasTotal = array();
    foreach ($novedades as $nv) {
        if ((int)$nv['CUENTA_AUSENCIA'] !== 1) { continue; }
        $h->setCellValue('A' . $r, $nv['NOMBRE']);
        $fs = isset($filasNov[(int)$nv['ID_NOVEDAD_AUS']]) ? $filasNov[(int)$nv['ID_NOVEDAD_AUS']] : array();
        for ($k = 1; $k <= $n; $k++) {
            $cc = col($k + 1);
            $h->setCellValue($cc . $r, count($fs) ? '=' . implode('+', array_map(function ($x) use ($cc) { return $cc . $x; }, $fs)) : 0);
        }
        $h->setCellValue($colTotal . $r, '=SUM(B' . $r . ':' . col($n + 1) . $r . ')');
        if ($todoMes) { $h->setCellValue(col($n + 3) . $r, '=SUM(B' . $r . ':' . col(15) . $r . ')'); }
        $filasTotal[(int)$nv['ID_NOVEDAD_AUS']] = $r;
        $r++;
    }
    $h->getStyle('A' . $fila . ':' . col($n + ($todoMes ? 3 : 2)) . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D9D9');
    formatoHoja($h, $n, $todoMes ? 1 : 0);
    return array($h, $filasTotal);
}

if (!$todos) {
    //----- Un proyecto: una hoja por centro -----
    foreach ((array)$centros as $c) {
        $h = hojaNueva($libro, ucwords(strtolower($c['NOM_CENTRO_OP'])));
        tituloHoja($h, 'NOVEDADES DE AUSENTISMO — ' . $proy['NOM_PROYECTO'] . ' — ' . $c['NOM_CENTRO_OP'] . ' — ' . $periodo, $VINO);
        $vals = $c['VALS'];
        $fila = 4; $totales = array();
        foreach ($c['CARGOS'] as $cg) {
            list($ft, $fila) = bloque($h, $fila, $cg, $novedades, isset($vals[(int)$cg['ID_CARGO_AUS']]) ? $vals[(int)$cg['ID_CARGO_AUS']] : array(), $d1, $d2, $VINO);
            $totales[] = $ft;
        }
        //Ausencias diarias: la suma de los "total del día" de todos los cargos
        $h->setCellValue('A' . $fila, 'AUSENCIAS DIARIAS');
        for ($k = 1; $k <= $n + 1; $k++) {
            $cc = col($k + 1);
            $h->setCellValue($cc . $fila, count($totales) ? '=' . implode('+', array_map(function ($x) use ($cc) { return $cc . $x; }, $totales)) : 0);
        }
        $h->getStyle('A' . $fila . ':' . col($n + 2) . $fila)->applyFromArray(array('font' => array('bold' => true),
            'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => 'F5E4E5'))));
        formatoHoja($h, $n);
    }
    //----- Y al final la hoja del proyecto (o del peaje): el consolidado -----
    if ($soloCentro) {
        hojaConsolidado($libro, 'Total ' . ucwords(strtolower($soloCentro['NOM_CENTRO_OP'])),
                        'REPORTE NACIONAL DE AUSENTISMO — ' . $proy['NOM_PROYECTO'] . ' — ' . $soloCentro['NOM_CENTRO_OP'] . ' — ' . $periodo,
                        $cons['porCargo'], $cargos, $novedades, $d1, $d2, $todoMes, $VINO);
    } else {
        hojaConsolidado($libro, $proy['NOM_PROYECTO'], 'REPORTE NACIONAL DE AUSENTISMO — ' . $proy['NOM_PROYECTO'] . ' — ' . $periodo,
                        $cons['porCargo'], $cargos, $novedades, $d1, $d2, $todoMes, $VINO);
    }
} else {
    //----- Todos los proyectos: una hoja por proyecto con su consolidado -----
    $refs = array(); //[proyecto, nombre de su hoja, filas de su tabla TOTAL]
    foreach ($proyectos as $p) {
        list($hp, $filas) = hojaConsolidado($libro, $p['NOM_PROYECTO'], 'REPORTE NACIONAL DE AUSENTISMO — ' . $p['NOM_PROYECTO'] . ' — ' . $periodo,
                                            $p['CONS']['porCargo'], $cargos, $novedades, $d1, $d2, $todoMes, $VINO);
        $refs[] = array($p, $hp->getTitle(), $filas);
    }
    //----- Por proyecto: el total de cada novedad en cada proyecto, tomado de su hoja -----
    $cuentan = array_values(array_filter($novedades, function ($nv) { return (int)$nv['CUENTA_AUSENCIA'] === 1; }));
    $m = count($cuentan);
    $colTot = col($n + 2); //la columna TOTAL de las hojas de proyecto
    $h = hojaNueva($libro, 'Por proyecto');
    tituloHoja($h, 'AUSENCIAS POR PROYECTO — ' . $periodo, $VINO);
    $h->setCellValue('A4', 'PROYECTO');
    foreach ($cuentan as $i => $nv) { $h->setCellValue(col($i + 2) . '4', $nv['NOMBRE']); }
    $h->setCellValue(col($m + 2) . '4', 'TOTAL');
    $h->getStyle('A4:' . col($m + 2) . '4')->applyFromArray(array(
        'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => $VINO)),
        'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true)));
    $r = 5;
    foreach ($refs as $ref) {
        list($p, $nombre, $filas) = $ref;
        $h->setCellValue('A' . $r, $p['NOM_PROYECTO']);
        foreach ($cuentan as $i => $nv) {
            $id = (int)$nv['ID_NOVEDAD_AUS'];
            $h->setCellValue(col($i + 2) . $r, isset($filas[$id]) ? "='" . str_replace("'", "''", $nombre) . "'!" . $colTot . $filas[$id] : 0);
        }
        $h->setCellValue(col($m + 2) . $r, $m ? '=SUM(B' . $r . ':' . col($m + 1) . $r . ')' : 0);
        $r++;
    }
    $h->setCellValue('A' . $r, 'TOTAL');
    for ($k = 2; $k <= $m + 2; $k++) { $h->setCellValue(col($k) . $r, $r > 5 ? '=SUM(' . col($k) . '5:' . col($k) . ($r - 1) . ')' : 0); }
    $h->getStyle('A' . $r . ':' . col($m + 2) . $r)->applyFromArray(array('font' => array('bold' => true),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => 'F5E4E5'))));
    $h->getStyle('A4:' . col($m + 2) . $r)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D9D9');
    $h->getColumnDimension('A')->setWidth(34);
    for ($k = 2; $k <= $m + 2; $k++) { $h->getColumnDimension(col($k))->setWidth(14); }
    $h->getRowDimension(4)->setRowHeight(45);
    $h->getStyle('B5:' . col($m + 2) . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $h->freezePane('B5');
    //----- Y al final la de todos los proyectos juntos -----
    hojaConsolidado($libro, 'Todos los proyectos', 'REPORTE NACIONAL DE AUSENTISMO — TODOS LOS PROYECTOS — ' . $periodo,
                    $cons['porCargo'], $cargos, $novedades, $d1, $d2, $todoMes, $VINO);
}
$libro->setActiveSheetIndex($libro->getSheetCount() - 1);

$nombre = 'Reporte nacional de ausentismo ' . ($todos ? 'todos los proyectos' : $proy['NOM_PROYECTO'] . ($soloCentro ? ' ' . $soloCentro['NOM_CENTRO_OP'] : ''))
        . ' ' . strtolower($MESES[$mes]) . ' ' . $anio
        . ($todoMes ? '' : ($d1 === $d2 ? ' dia ' . $d1 : ' del ' . $d1 . ' al ' . $d2)) . '.xlsx';
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: max-age=0');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
