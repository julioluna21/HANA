<?php
/*
  HANA — Ausentismo: descargar el "Reporte nacional de ausentismo" en Excel
  Con la misma estructura del archivo que se usa hoy: una hoja por centro, con
  un bloque por cargo (novedades × días) y las ausencias diarias; y al final
  la hoja del proyecto con el consolidado y el TOTAL (mes y 1ª quincena).
  Los totales van como fórmulas de Excel: si se corrige una celda en el
  archivo, se recalculan.
*/
session_start();
date_default_timezone_set('America/Bogota');
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/../Modelo/AusentismoModelo.php';
require_once __DIR__ . '/../vendors/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

function negar($msg) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; }

$modulos = explode(',', $_SESSION['Modulos']);
if (!HanaConfig::modulo('AUSENTISMO')) { negar('El módulo de ausentismo está desactivado.'); }
$idColaborador = (int)$_SESSION['Idcolaborador'];
$verTodos = in_array('20M', $modulos) || in_array('21M', $modulos) || in_array('22M', $modulos) || in_array('25M', $modulos);

$idProy = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : 0;
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;
if ($anio < 2020 || $anio > 2100 || $mes < 1 || $mes > 12) { negar('El mes no es válido.'); }
$proy = HanaDB::fila("SELECT ID_PROYECTO, NOM_PROYECTO, ID_COLABORADOR_COORDINADOR FROM proyectos WHERE ID_PROYECTO = ? AND Estado = '1'", 'i', array($idProy));
if (!$proy || (!$verTodos && (int)$proy['ID_COLABORADOR_COORDINADOR'] !== $idColaborador)) { negar('Ese proyecto no está entre los que puedes ver.'); }

$A = new Ausentismo();
$cargos = $A->cargos();
$novedades = $A->novedades();
$centros = HanaDB::q("SELECT ID_CENTRO_OP, NOM_CENTRO_OP, TIPO_CENTRO FROM centros_operacion WHERE Estado = '1' AND ID_PROYECTO_CENTRO_OP = ?
                       ORDER BY TIPO_CENTRO = 'BASCULA', NOM_CENTRO_OP", 'i', array($idProy));
$dias = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes)));
$MESES = array('', 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE');
$VINO = '6E1A1E';

$libro = new Spreadsheet();
$libro->removeSheetByIndex(0);
$libro->getProperties()->setCreator('HANA - Grupo Regency')->setTitle('Reporte nacional de ausentismo ' . $proy['NOM_PROYECTO']);

function col($n) { return Coordinate::stringFromColumnIndex($n); }
$colTotal = col($dias + 2);
$colQuin = col($dias + 3);

//Nombre de hoja válido para Excel (máx. 31 caracteres, sin : \ / ? * [ ])
function nombreHoja($t) { return mb_substr(preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', $t), 0, 31); }

//Encabezado de un bloque: el cargo, los días y "TOTAL"
function cabecera($h, $fila, $titulo, $dias, $VINO, $conQuincena = false)
{
    $h->setCellValue('A' . $fila, $titulo);
    for ($d = 1; $d <= $dias; $d++) { $h->setCellValue(col($d + 1) . $fila, $d); }
    $h->setCellValue(col($dias + 2) . $fila, 'TOTAL');
    $ultima = $dias + 2;
    if ($conQuincena) { $h->setCellValue(col($dias + 3) . $fila, '1ª QUINCENA'); $ultima++; }
    $h->getStyle('A' . $fila . ':' . col($ultima) . $fila)->applyFromArray(array(
        'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => $VINO)),
        'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER)));
    $h->getStyle('A' . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
}

//Escribe un bloque (cargo) con sus valores [idNovedad][dia]; devuelve [fila del total del día, siguiente fila libre]
function bloque($h, $fila, $cargo, $novs, $vals, $dias, $VINO)
{
    cabecera($h, $fila, $cargo['NOMBRE'], $dias, $VINO);
    $filasCuenta = array();
    $r = $fila + 1;
    foreach ($novs as $n) {
        if ($n['ID_CARGO_AUS'] && (int)$n['ID_CARGO_AUS'] !== (int)$cargo['ID_CARGO_AUS']) { continue; }
        $h->setCellValue('A' . $r, $n['NOMBRE']);
        $v = isset($vals[(int)$n['ID_NOVEDAD_AUS']]) ? $vals[(int)$n['ID_NOVEDAD_AUS']] : array();
        for ($d = 1; $d <= $dias; $d++) { if (!empty($v[$d])) { $h->setCellValue(col($d + 1) . $r, $v[$d]); } }
        $h->setCellValue(col($dias + 2) . $r, '=SUM(B' . $r . ':' . col($dias + 1) . $r . ')');
        if ((int)$n['CUENTA_AUSENCIA'] === 1) { $filasCuenta[] = $r; }
        else { $h->getStyle('A' . $r . ':' . col($dias + 2) . $r)->getFont()->setItalic(true)->getColor()->setRGB('6B7076'); }
        $r++;
    }
    //Total del día: solo las filas que son ausencia
    $h->setCellValue('A' . $r, 'TOTAL DEL DÍA');
    for ($d = 1; $d <= $dias + 1; $d++) {
        $c = col($d + 1);
        $h->setCellValue($c . $r, count($filasCuenta) ? '=' . implode('+', array_map(function ($x) use ($c) { return $c . $x; }, $filasCuenta)) : 0);
    }
    $h->getStyle('A' . $r . ':' . col($dias + 2) . $r)->applyFromArray(array('font' => array('bold' => true),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => 'F2F2F2'))));
    $h->getStyle('A' . $fila . ':' . col($dias + 2) . $r)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D9D9');
    return array($r, $r + 3);
}

function formatoHoja($h, $dias, $extra = 0)
{
    $h->getColumnDimension('A')->setWidth(34);
    for ($d = 1; $d <= $dias; $d++) { $h->getColumnDimension(col($d + 1))->setWidth(4.5); }
    for ($i = 0; $i <= $extra; $i++) { $h->getColumnDimension(col($dias + 2 + $i))->setWidth(12); }
    $h->freezePane('B1');
    $h->getStyle('B1:' . col($dias + 3 + $extra) . $h->getHighestRow())->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

//----- Una hoja por centro -----
foreach ((array)$centros as $c) {
    $h = $libro->createSheet();
    $h->setTitle(nombreHoja(ucwords(strtolower($c['NOM_CENTRO_OP']))));
    $h->setCellValue('A2', 'NOVEDADES DE AUSENTISMO — ' . $proy['NOM_PROYECTO'] . ' — ' . $c['NOM_CENTRO_OP'] . ' — ' . $MESES[$mes] . ' ' . $anio);
    $h->getStyle('A2')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB($VINO);
    $vals = $A->mesCentro($c['ID_CENTRO_OP'], $anio, $mes);
    $fila = 4; $totales = array();
    foreach ($cargos as $cg) {
        if (!Ausentismo::cargoAplica($cg, $c['TIPO_CENTRO'])) { continue; }
        list($ft, $fila) = bloque($h, $fila, $cg, $novedades, isset($vals[(int)$cg['ID_CARGO_AUS']]) ? $vals[(int)$cg['ID_CARGO_AUS']] : array(), $dias, $VINO);
        $totales[] = $ft;
    }
    //Ausencias diarias: la suma de los "total del día" de todos los cargos
    $h->setCellValue('A' . $fila, 'AUSENCIAS DIARIAS');
    for ($d = 1; $d <= $dias + 1; $d++) {
        $cc = col($d + 1);
        $h->setCellValue($cc . $fila, count($totales) ? '=' . implode('+', array_map(function ($x) use ($cc) { return $cc . $x; }, $totales)) : 0);
    }
    $h->getStyle('A' . $fila . ':' . col($dias + 2) . $fila)->applyFromArray(array('font' => array('bold' => true),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => 'F5E4E5'))));
    formatoHoja($h, $dias);
}

//----- La hoja del proyecto: el consolidado -----
$cons = $A->consolidado($idProy, $anio, $mes);
$h = $libro->createSheet();
$h->setTitle(nombreHoja($proy['NOM_PROYECTO']));
$h->setCellValue('A2', 'REPORTE NACIONAL DE AUSENTISMO — ' . $proy['NOM_PROYECTO'] . ' — ' . $MESES[$mes] . ' ' . $anio);
$h->getStyle('A2')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB($VINO);
$fila = 4;
$filasNov = array(); //[idNovedad] = filas de esa novedad en todos los bloques
foreach ($cargos as $cg) {
    $idc = (int)$cg['ID_CARGO_AUS'];
    if (empty($cons['porCargo'][$idc])) { continue; } //solo los cargos con datos
    $inicio = $fila + 1;
    list($ft, $sig) = bloque($h, $fila, $cg, $novedades, $cons['porCargo'][$idc], $dias, $VINO);
    for ($r = $inicio; $r < $ft; $r++) {
        foreach ($novedades as $n) { if ($h->getCell('A' . $r)->getValue() === $n['NOMBRE'] && (int)$n['CUENTA_AUSENCIA'] === 1) { $filasNov[(int)$n['ID_NOVEDAD_AUS']][] = $r; } }
    }
    $fila = $sig;
}
//TOTAL por novedad: cada día, el total del mes y la 1ª quincena (días 1 a 14)
cabecera($h, $fila, 'TOTAL', $dias, $VINO, true);
$r = $fila + 1;
foreach ($novedades as $n) {
    if ((int)$n['CUENTA_AUSENCIA'] !== 1) { continue; }
    $h->setCellValue('A' . $r, $n['NOMBRE']);
    $fs = isset($filasNov[(int)$n['ID_NOVEDAD_AUS']]) ? $filasNov[(int)$n['ID_NOVEDAD_AUS']] : array();
    for ($d = 1; $d <= $dias; $d++) {
        $cc = col($d + 1);
        $h->setCellValue($cc . $r, count($fs) ? '=' . implode('+', array_map(function ($x) use ($cc) { return $cc . $x; }, $fs)) : 0);
    }
    $h->setCellValue($colTotal . $r, '=SUM(B' . $r . ':' . col($dias + 1) . $r . ')');
    $h->setCellValue($colQuin . $r, '=SUM(B' . $r . ':' . col(15) . $r . ')');
    $r++;
}
$h->getStyle('A' . $fila . ':' . $colQuin . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D9D9');
formatoHoja($h, $dias, 1);
$libro->setActiveSheetIndex($libro->getSheetCount() - 1);

$nombre = 'Reporte nacional de ausentismo ' . $proy['NOM_PROYECTO'] . ' ' . strtolower($MESES[$mes]) . ' ' . $anio . '.xlsx';
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: max-age=0');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
