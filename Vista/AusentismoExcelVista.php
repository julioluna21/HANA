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
$columnasDias = $diaHasta - $diaDesde + 1;      //columnas de días: la B es el día $diaDesde
$todoMes = $diaDesde === 1 && $diaHasta === $dias; //la 1ª quincena solo tiene sentido con el mes completo
$textoDias = $todoMes ? '' : ($diaDesde === $diaHasta ? ' — DÍA ' . $diaDesde : ' — DÍAS ' . $diaDesde . ' A ' . $diaHasta);
$periodo = $MESES[$mes] . ' ' . $anio . $textoDias;

$libro = new Spreadsheet();
$libro->removeSheetByIndex(0);
$libro->getProperties()->setCreator('HANA - Grupo Regency')->setTitle('Reporte nacional de ausentismo ' . $proyectoPedido['NOM_PROYECTO']);

//Letra de la columna de Excel a partir de su número (1 = A, 27 = AA)
function col($numero) { return Coordinate::stringFromColumnIndex($numero); }

//Hoja nueva con un nombre válido para Excel, que no se repita en el libro.
//Excel no acepta nombres vacíos, de más de 31 caracteres, con : \ / ? * [ ] ni que
//empiecen o terminen con comilla: se quitan, y si no queda nada la hoja se llama «Sin nombre»
function hojaNueva($libro, $titulo)
{
    $base = trim(preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', (string)$titulo));
    $base = trim(mb_substr(trim($base, " '"), 0, 31), " '");
    if ($base === '') { $base = 'Sin nombre'; }
    $nombre = $base; $i = 2;
    while ($libro->sheetNameExists($nombre)) { $nombre = rtrim(mb_substr($base, 0, 26), " '") . ' (' . $i++ . ')'; }
    $hoja = $libro->createSheet();
    $hoja->setTitle($nombre);
    return $hoja;
}

//«SAN MIGUEL» → «San Miguel», respetando tildes y eñes («PEÑA» → «Peña»)
function nombrePropio($texto) { return mb_convert_case((string)$texto, MB_CASE_TITLE, 'UTF-8'); }

//Título de la hoja, en la fila 2
function tituloHoja($hoja, $texto, $VINO)
{
    $hoja->setCellValue('A2', $texto);
    $hoja->getStyle('A2')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB($VINO);
}

//Encabezado de un bloque: el cargo, los días del rango y "TOTAL" (y "1ª QUINCENA" si se pide)
function cabecera($hoja, $fila, $titulo, $diaDesde, $diaHasta, $VINO, $conQuincena = false)
{
    $columnasDias = $diaHasta - $diaDesde + 1;
    $hoja->setCellValue('A' . $fila, $titulo);
    for ($dia = $diaDesde; $dia <= $diaHasta; $dia++) { $hoja->setCellValue(col($dia - $diaDesde + 2) . $fila, $dia); }
    $hoja->setCellValue(col($columnasDias + 2) . $fila, 'TOTAL');
    $ultima = $columnasDias + 2;
    if ($conQuincena) { $hoja->setCellValue(col($columnasDias + 3) . $fila, '1ª QUINCENA'); $ultima++; }
    $hoja->getStyle('A' . $fila . ':' . col($ultima) . $fila)->applyFromArray(array(
        'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => $VINO)),
        'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER)));
    $hoja->getStyle('A' . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
}

//Escribe un bloque (cargo) con sus valores [idNovedad][dia] del día $diaDesde al $diaHasta;
//devuelve [fila del total del día, siguiente fila libre]
function bloque($hoja, $fila, $cargo, $novedades, $vals, $diaDesde, $diaHasta, $VINO)
{
    $columnasDias = $diaHasta - $diaDesde + 1;
    cabecera($hoja, $fila, $cargo['NOMBRE'], $diaDesde, $diaHasta, $VINO);
    $filasCuenta = array();
    $filaActual = $fila + 1;
    foreach ($novedades as $novedad) {
        if ($novedad['ID_CARGO_AUS'] && (int)$novedad['ID_CARGO_AUS'] !== (int)$cargo['ID_CARGO_AUS']) { continue; }
        $hoja->setCellValue('A' . $filaActual, $novedad['NOMBRE']);
        $valoresNovedad = isset($vals[(int)$novedad['ID_NOVEDAD_AUS']]) ? $vals[(int)$novedad['ID_NOVEDAD_AUS']] : array();
        for ($dia = $diaDesde; $dia <= $diaHasta; $dia++) { if (!empty($valoresNovedad[$dia])) { $hoja->setCellValue(col($dia - $diaDesde + 2) . $filaActual, $valoresNovedad[$dia]); } }
        $hoja->setCellValue(col($columnasDias + 2) . $filaActual, '=SUM(B' . $filaActual . ':' . col($columnasDias + 1) . $filaActual . ')');
        if ((int)$novedad['CUENTA_AUSENCIA'] === 1) { $filasCuenta[] = $filaActual; }
        else { $hoja->getStyle('A' . $filaActual . ':' . col($columnasDias + 2) . $filaActual)->getFont()->setItalic(true)->getColor()->setRGB('6B7076'); }
        $filaActual++;
    }
    //Total del día: solo las filas que son ausencia
    $hoja->setCellValue('A' . $filaActual, 'TOTAL DEL DÍA');
    for ($posicion = 1; $posicion <= $columnasDias + 1; $posicion++) {
        $letra = col($posicion + 1);
        $hoja->setCellValue($letra . $filaActual, count($filasCuenta) ? '=' . implode('+', array_map(function ($numFila) use ($letra) { return $letra . $numFila; }, $filasCuenta)) : 0);
    }
    $hoja->getStyle('A' . $filaActual . ':' . col($columnasDias + 2) . $filaActual)->applyFromArray(array('font' => array('bold' => true),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => 'F2F2F2'))));
    $hoja->getStyle('A' . $fila . ':' . col($columnasDias + 2) . $filaActual)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D9D9');
    return array($filaActual, $filaActual + 3);
}

//Anchos de columna y formato común de cada hoja del Excel ($columnasDias = columnas de días)
function formatoHoja($hoja, $columnasDias, $extra = 0)
{
    $hoja->getColumnDimension('A')->setWidth(34);
    for ($posicion = 1; $posicion <= $columnasDias; $posicion++) { $hoja->getColumnDimension(col($posicion + 1))->setWidth(4.5); }
    for ($i = 0; $i <= $extra; $i++) { $hoja->getColumnDimension(col($columnasDias + 2 + $i))->setWidth(12); }
    $hoja->freezePane('B1');
    $hoja->getStyle('B1:' . col($columnasDias + 3 + $extra) . $hoja->getHighestRow())->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

//Hoja de un consolidado: un bloque por cargo con datos y la tabla TOTAL por novedad.
//Devuelve [hoja, filas de la tabla TOTAL: idNovedad => fila]
function hojaConsolidado($libro, $nombreHoja, $titulo, $porCargo, $cargos, $novedades, $diaDesde, $diaHasta, $todoMes, $VINO)
{
    $columnasDias = $diaHasta - $diaDesde + 1;
    $colTotal = col($columnasDias + 2);
    $hoja = hojaNueva($libro, $nombreHoja);
    tituloHoja($hoja, $titulo, $VINO);
    $fila = 4;
    $filasNov = array(); //[idNovedad] = filas de esa novedad en todos los bloques
    foreach ($cargos as $cargo) {
        $idCargo = (int)$cargo['ID_CARGO_AUS'];
        if (empty($porCargo[$idCargo])) { continue; } //solo los cargos con datos
        $inicio = $fila + 1;
        list($filaTotalDia, $siguienteFila) = bloque($hoja, $fila, $cargo, $novedades, $porCargo[$idCargo], $diaDesde, $diaHasta, $VINO);
        for ($filaActual = $inicio; $filaActual < $filaTotalDia; $filaActual++) {
            foreach ($novedades as $novedad) { if ($hoja->getCell('A' . $filaActual)->getValue() === $novedad['NOMBRE'] && (int)$novedad['CUENTA_AUSENCIA'] === 1) { $filasNov[(int)$novedad['ID_NOVEDAD_AUS']][] = $filaActual; } }
        }
        $fila = $siguienteFila;
    }
    //TOTAL por novedad: cada día, el total y, con el mes completo, la 1ª quincena (días 1 a 14)
    cabecera($hoja, $fila, 'TOTAL', $diaDesde, $diaHasta, $VINO, $todoMes);
    $filaActual = $fila + 1;
    $filasTotal = array();
    foreach ($novedades as $novedad) {
        if ((int)$novedad['CUENTA_AUSENCIA'] !== 1) { continue; }
        $hoja->setCellValue('A' . $filaActual, $novedad['NOMBRE']);
        $filasNovedad = isset($filasNov[(int)$novedad['ID_NOVEDAD_AUS']]) ? $filasNov[(int)$novedad['ID_NOVEDAD_AUS']] : array();
        for ($posicion = 1; $posicion <= $columnasDias; $posicion++) {
            $letra = col($posicion + 1);
            $hoja->setCellValue($letra . $filaActual, count($filasNovedad) ? '=' . implode('+', array_map(function ($numFila) use ($letra) { return $letra . $numFila; }, $filasNovedad)) : 0);
        }
        $hoja->setCellValue($colTotal . $filaActual, '=SUM(B' . $filaActual . ':' . col($columnasDias + 1) . $filaActual . ')');
        if ($todoMes) { $hoja->setCellValue(col($columnasDias + 3) . $filaActual, '=SUM(B' . $filaActual . ':' . col(15) . $filaActual . ')'); }
        $filasTotal[(int)$novedad['ID_NOVEDAD_AUS']] = $filaActual;
        $filaActual++;
    }
    $hoja->getStyle('A' . $fila . ':' . col($columnasDias + ($todoMes ? 3 : 2)) . ($filaActual - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D9D9');
    formatoHoja($hoja, $columnasDias, $todoMes ? 1 : 0);
    return array($hoja, $filasTotal);
}

if (!$todos) {
    //----- Un proyecto: una hoja por centro -----
    foreach ((array)$centros as $centro) {
        $hoja = hojaNueva($libro, nombrePropio($centro['NOM_CENTRO_OP']));
        tituloHoja($hoja, 'NOVEDADES DE AUSENTISMO — ' . $proyectoPedido['NOM_PROYECTO'] . ' — ' . $centro['NOM_CENTRO_OP'] . ' — ' . $periodo, $VINO);
        $vals = $centro['VALS'];
        $fila = 4; $totales = array();
        foreach ($centro['CARGOS'] as $cargo) {
            list($filaTotalDia, $fila) = bloque($hoja, $fila, $cargo, $novedades, isset($vals[(int)$cargo['ID_CARGO_AUS']]) ? $vals[(int)$cargo['ID_CARGO_AUS']] : array(), $diaDesde, $diaHasta, $VINO);
            $totales[] = $filaTotalDia;
        }
        //Ausencias diarias: la suma de los "total del día" de todos los cargos
        $hoja->setCellValue('A' . $fila, 'AUSENCIAS DIARIAS');
        for ($posicion = 1; $posicion <= $columnasDias + 1; $posicion++) {
            $letra = col($posicion + 1);
            $hoja->setCellValue($letra . $fila, count($totales) ? '=' . implode('+', array_map(function ($numFila) use ($letra) { return $letra . $numFila; }, $totales)) : 0);
        }
        $hoja->getStyle('A' . $fila . ':' . col($columnasDias + 2) . $fila)->applyFromArray(array('font' => array('bold' => true),
            'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => 'F5E4E5'))));
        formatoHoja($hoja, $columnasDias);
    }
    //----- Y al final la hoja del proyecto (o del peaje): el consolidado -----
    if ($soloCentro) {
        hojaConsolidado($libro, 'Total ' . nombrePropio($soloCentro['NOM_CENTRO_OP']),
                        'REPORTE NACIONAL DE AUSENTISMO — ' . $proyectoPedido['NOM_PROYECTO'] . ' — ' . $soloCentro['NOM_CENTRO_OP'] . ' — ' . $periodo,
                        $consolidado['porCargo'], $cargos, $novedades, $diaDesde, $diaHasta, $todoMes, $VINO);
    } else {
        hojaConsolidado($libro, $proyectoPedido['NOM_PROYECTO'], 'REPORTE NACIONAL DE AUSENTISMO — ' . $proyectoPedido['NOM_PROYECTO'] . ' — ' . $periodo,
                        $consolidado['porCargo'], $cargos, $novedades, $diaDesde, $diaHasta, $todoMes, $VINO);
    }
} else {
    //----- Todos los proyectos: una hoja por proyecto con su consolidado -----
    $refs = array(); //[proyecto, nombre de su hoja, filas de su tabla TOTAL]
    foreach ($proyectos as $proyecto) {
        list($hojaProyecto, $filas) = hojaConsolidado($libro, $proyecto['NOM_PROYECTO'], 'REPORTE NACIONAL DE AUSENTISMO — ' . $proyecto['NOM_PROYECTO'] . ' — ' . $periodo,
                                            $proyecto['CONS']['porCargo'], $cargos, $novedades, $diaDesde, $diaHasta, $todoMes, $VINO);
        $refs[] = array($proyecto, $hojaProyecto->getTitle(), $filas);
    }
    //----- Por proyecto: el total de cada novedad en cada proyecto, tomado de su hoja -----
    $cuentan = array_values(array_filter($novedades, function ($novedad) { return (int)$novedad['CUENTA_AUSENCIA'] === 1; }));
    $numNovedades = count($cuentan);
    $colTot = col($columnasDias + 2); //la columna TOTAL de las hojas de proyecto
    $hoja = hojaNueva($libro, 'Por proyecto');
    tituloHoja($hoja, 'AUSENCIAS POR PROYECTO — ' . $periodo, $VINO);
    $hoja->setCellValue('A4', 'PROYECTO');
    foreach ($cuentan as $i => $novedad) { $hoja->setCellValue(col($i + 2) . '4', $novedad['NOMBRE']); }
    $hoja->setCellValue(col($numNovedades + 2) . '4', 'TOTAL');
    $hoja->getStyle('A4:' . col($numNovedades + 2) . '4')->applyFromArray(array(
        'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => $VINO)),
        'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true)));
    $filaActual = 5;
    foreach ($refs as $ref) {
        list($proyecto, $nombre, $filas) = $ref;
        $hoja->setCellValue('A' . $filaActual, $proyecto['NOM_PROYECTO']);
        foreach ($cuentan as $i => $novedad) {
            $idNovedad = (int)$novedad['ID_NOVEDAD_AUS'];
            $hoja->setCellValue(col($i + 2) . $filaActual, isset($filas[$idNovedad]) ? "='" . str_replace("'", "''", $nombre) . "'!" . $colTot . $filas[$idNovedad] : 0);
        }
        $hoja->setCellValue(col($numNovedades + 2) . $filaActual, $numNovedades ? '=SUM(B' . $filaActual . ':' . col($numNovedades + 1) . $filaActual . ')' : 0);
        $filaActual++;
    }
    $hoja->setCellValue('A' . $filaActual, 'TOTAL');
    for ($posicion = 2; $posicion <= $numNovedades + 2; $posicion++) { $hoja->setCellValue(col($posicion) . $filaActual, $filaActual > 5 ? '=SUM(' . col($posicion) . '5:' . col($posicion) . ($filaActual - 1) . ')' : 0); }
    $hoja->getStyle('A' . $filaActual . ':' . col($numNovedades + 2) . $filaActual)->applyFromArray(array('font' => array('bold' => true),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => 'F5E4E5'))));
    $hoja->getStyle('A4:' . col($numNovedades + 2) . $filaActual)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D9D9');
    $hoja->getColumnDimension('A')->setWidth(34);
    for ($posicion = 2; $posicion <= $numNovedades + 2; $posicion++) { $hoja->getColumnDimension(col($posicion))->setWidth(14); }
    $hoja->getRowDimension(4)->setRowHeight(45);
    $hoja->getStyle('B5:' . col($numNovedades + 2) . $filaActual)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $hoja->freezePane('B5');
    //----- Y al final la de todos los proyectos juntos -----
    hojaConsolidado($libro, 'Todos los proyectos', 'REPORTE NACIONAL DE AUSENTISMO — TODOS LOS PROYECTOS — ' . $periodo,
                    $consolidado['porCargo'], $cargos, $novedades, $diaDesde, $diaHasta, $todoMes, $VINO);
}
$libro->setActiveSheetIndex($libro->getSheetCount() - 1);

$nombre = 'Reporte nacional de ausentismo ' . ($todos ? 'todos los proyectos' : $proyectoPedido['NOM_PROYECTO'] . ($soloCentro ? ' ' . $soloCentro['NOM_CENTRO_OP'] : ''))
        . ' ' . strtolower($MESES[$mes]) . ' ' . $anio
        . ($todoMes ? '' : ($diaDesde === $diaHasta ? ' dia ' . $diaDesde : ' del ' . $diaDesde . ' al ' . $diaHasta)) . '.xlsx';
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: max-age=0');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
