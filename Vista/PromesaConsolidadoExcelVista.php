<?php
/*
  HANA - Consolidado de promesa de valor, ejecutado y ausentismo, en Excel
  Con la misma forma del archivo "CONSOLIDADO" que se armaba a mano: una hoja
  RESUMEN con todos los proyectos (por proyecto, por semana, por cargo y por
  peaje) y una hoja por proyecto (por peaje, por semana y por peaje y cargo).
  Con un solo proyecto, el RESUMEN y su hoja; con un solo peaje, una hoja con
  el peaje, sus semanas y sus cargos. Con un rango de días, todas las cifras
  son de esos días y las semanas, solo las que los tocan.
  Sale de la misma consulta que la pantalla, así que las cifras son iguales.
  Los números van como valores: es una foto del mes, no una plantilla.
*/
require_once __DIR__ . '/../Control/PromesaConsolidadoExcelControl.php'; //revisa el acceso y trae el consolidado ($consolidado)
require_once __DIR__ . '/../vendors/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$MESES = array('', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');
$RESULTADOS = array('MAS' => 'MÁS', 'MENOS' => 'MENOS', 'IGUAL' => 'IGUAL', 'SIN_DATOS' => 'SIN DATOS');
$VINO = '6E1A1E'; $VINO_CLARO = '8C3A3E'; $ROSA = 'F5E4E5';

//Letra de la columna de Excel a partir de su número (1 = A, 27 = AA)
function col($numero) { return Coordinate::stringFromColumnIndex($numero); }
//Nombre de hoja válido para Excel (máx. 31 caracteres, sin : \ / ? * [ ])
//Excel no acepta nombres de hoja vacíos, de más de 31 caracteres, con : \ / ? * [ ] ni que
//empiecen o terminen con comilla: se quitan, y si no queda nada la hoja se llama «Sin nombre»
function nombreHoja($texto)
{
    $texto = trim(preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', (string)$texto));
    $texto = trim(mb_substr(trim($texto, " '"), 0, 31), " '");
    return $texto === '' ? 'Sin nombre' : $texto;
}
//Hoja nueva con ese nombre; si ya hay una igual en el libro (dos proyectos o peajes con
//el mismo nombre), se le agrega un número: «ICCU (2)»
function hojaNueva($libro, $titulo)
{
    $base = nombreHoja($titulo);
    $nombre = $base; $i = 2;
    while ($libro->sheetNameExists($nombre)) { $nombre = rtrim(mb_substr($base, 0, 26), " '") . ' (' . $i++ . ')'; }
    return $libro->createSheet()->setTitle($nombre);
}

//Los títulos de las columnas de una tabla de resumen. Devuelve cuántas columnas ocupa
function titulos($hoja, $fila, $primeras, $consolidado, $conDias, $VINO, $VINO_CLARO)
{
    $columna = 1;
    foreach ($primeras as $textoTitulo) { $hoja->setCellValue(col($columna++) . $fila, $textoTitulo); }
    foreach (array('PROMESA DE VALOR', 'EJECUTADO', 'DIFERENCIA', '% EJECUCIÓN', 'RESULTADO') as $textoTitulo) { $hoja->setCellValue(col($columna++) . $fila, $textoTitulo); }
    $desdeAus = $columna;
    foreach ($consolidado['motivos'] as $motivo) { if ($motivo['cuenta']) { $hoja->setCellValue(col($columna++) . $fila, $motivo['nombre']); } }
    $hoja->setCellValue(col($columna++) . $fila, 'TOTAL AUSENCIAS');
    $hoja->setCellValue(col($columna++) . $fila, 'CAUSA PRINCIPAL');
    foreach ($consolidado['motivos'] as $motivo) { if (!$motivo['cuenta']) { $hoja->setCellValue(col($columna++) . $fila, $motivo['nombre']); } } //motivos marcados como "no es ausencia": se ven, pero no suman
    if ($conDias) { $hoja->setCellValue(col($columna++) . $fila, 'DÍAS REGISTRADOS'); $hoja->setCellValue(col($columna++) . $fila, 'DÍAS ESPERADOS'); }
    $ultima = $columna - 1;
    $hoja->getStyle('A' . $fila . ':' . col($ultima) . $fila)->applyFromArray(array(
        'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF'), 'size' => 9),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => $VINO)),
        'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true)));
    //El grupo de ausencias va en un vino más claro, para separarlo de la promesa y el ejecutado
    $hoja->getStyle(col($desdeAus) . $fila . ':' . col($ultima - ($conDias ? 2 : 0)) . $fila)->getFill()->getStartColor()->setRGB($VINO_CLARO);
    $hoja->getRowDimension($fila)->setRowHeight(30);
    return $ultima;
}

//Una fila de datos después de las columnas de nombre. $primeraColumna: primera columna libre
function datos($hoja, $fila, $primeraColumna, $filaDatos, $consolidado, $conDias, $RESULTADOS)
{
    $columna = $primeraColumna; $hayDatos = $filaDatos['res'] !== 'SIN_DATOS';
    $hoja->getStyle(col($primeraColumna) . $fila . ':' . col($primeraColumna + 40) . $fila)->getNumberFormat()->setFormatCode('#,##0'); //miles con punto; abajo se cambian la diferencia y el %
    $hoja->setCellValue(col($columna++) . $fila, $hayDatos ? $filaDatos['prom'] : null);
    $hoja->setCellValue(col($columna++) . $fila, $hayDatos ? $filaDatos['eje'] : null);
    $hoja->setCellValue(col($columna) . $fila, $hayDatos ? $filaDatos['dif'] : null);
    $hoja->getStyle(col($columna++) . $fila)->getNumberFormat()->setFormatCode('+#,##0;-#,##0;0');         //la diferencia siempre con su signo
    $hoja->setCellValue(col($columna) . $fila, $filaDatos['pct'] === null ? null : $filaDatos['pct'] / 100);
    $hoja->getStyle(col($columna++) . $fila)->getNumberFormat()->setFormatCode('0.0%');
    $hoja->setCellValue(col($columna) . $fila, $RESULTADOS[$filaDatos['res']]);
    $hoja->getStyle(col($columna++) . $fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    foreach ($consolidado['motivos'] as $i => $motivo) { if ($motivo['cuenta']) { $hoja->setCellValue(col($columna++) . $fila, $filaDatos['aus'][$i]); } }
    $hoja->setCellValue(col($columna++) . $fila, $filaDatos['ausTotal']);
    $hoja->setCellValue(col($columna++) . $fila, $filaDatos['causa'] ? $filaDatos['causa'] : 'SIN AUSENCIAS');
    foreach ($consolidado['motivos'] as $i => $motivo) { if (!$motivo['cuenta']) { $hoja->setCellValue(col($columna++) . $fila, $filaDatos['aus'][$i]); } }
    if ($conDias) { $hoja->setCellValue(col($columna++) . $fila, $filaDatos['diasReg']); $hoja->setCellValue(col($columna++) . $fila, $filaDatos['diasEsp']); }
    //Faltó gente: la diferencia en vino. Hubo de más: en verde
    if ($hayDatos && $filaDatos['dif'] !== 0) { $hoja->getStyle(col($primeraColumna + 2) . $fila)->getFont()->setBold(true)->getColor()->setRGB($filaDatos['dif'] < 0 ? '6E1A1E' : '0F7A64'); }
}

//Escribe el nombre de una fila que ocupa las columnas A y B juntas
function nombre($hoja, $fila, $texto)
{
    $hoja->setCellValue('A' . $fila, $texto);
    $hoja->mergeCells('A' . $fila . ':B' . $fila);
}

//Deja una fila como total: en negrita y con fondo
function filaTotal($hoja, $fila, $ultima, $ROSA)
{
    $hoja->getStyle('A' . $fila . ':' . col($ultima) . $fila)->applyFromArray(array('font' => array('bold' => true),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => $ROSA))));
}

//Bordes finos de una tabla y números con separador de miles
function cerrarTabla($hoja, $desde, $hasta, $ultima)
{
    $hoja->getStyle('A' . $desde . ':' . col($ultima) . $hasta)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D9D9');
}

//Escribe un subtítulo en negrilla y color vino en la fila indicada
function subtitulo($hoja, $fila, $texto, $VINO)
{
    $hoja->setCellValue('A' . $fila, $texto);
    $hoja->getStyle('A' . $fila)->getFont()->setBold(true)->setSize(12)->getColor()->setRGB($VINO);
}

//La tabla "por semana": la diferencia de cada semana y, al lado, las ausencias. Solo las semanas que
//tocan los días pedidos ($consolidado['semanasRango']). Devuelve la siguiente fila libre
function tablaSemanas($hoja, $fila, $titulo, $filas, $total, $nombreTotal, $consolidado, $VINO, $VINO_CLARO, $ROSA)
{
    $semanas = $consolidado['semanasRango']; $numSemanas = count($semanas);
    $ultima = 1 + 2 * $numSemanas;                                                   //A = nombre; luego las diferencias y las ausencias
    $hoja->setCellValue('A' . $fila, $titulo);
    $hoja->setCellValue('B' . $fila, 'DIFERENCIA (EJECUTADO − PROMESA DE VALOR)'); if ($numSemanas > 1) { $hoja->mergeCells('B' . $fila . ':' . col(1 + $numSemanas) . $fila); }
    $hoja->setCellValue(col(2 + $numSemanas) . $fila, 'AUSENCIAS (sin las que no cuentan)'); if ($numSemanas > 1) { $hoja->mergeCells(col(2 + $numSemanas) . $fila . ':' . col($ultima) . $fila); }
    foreach ($semanas as $j => $semana) {
        $hoja->setCellValue(col(2 + $j) . ($fila + 1), mb_strtoupper($consolidado['semanas'][$semana]));
        $hoja->setCellValue(col(2 + $numSemanas + $j) . ($fila + 1), mb_strtoupper($consolidado['semanas'][$semana]));
    }
    $hoja->mergeCells('A' . $fila . ':A' . ($fila + 1));
    $hoja->getStyle('A' . $fila . ':' . col($ultima) . ($fila + 1))->applyFromArray(array(
        'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF'), 'size' => 9),
        'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => $VINO)),
        'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true)));
    $hoja->getStyle(col(2 + $numSemanas) . $fila . ':' . col($ultima) . ($fila + 1))->getFill()->getStartColor()->setRGB($VINO_CLARO);
    $filaActual = $fila + 2;
    $escribirFila = function ($nombre, $filaDatos) use ($hoja, &$filaActual, $semanas, $numSemanas, $ultima) {
        $hoja->setCellValue('A' . $filaActual, $nombre);
        foreach ($semanas as $j => $semana) {
            $hoja->setCellValue(col(2 + $j) . $filaActual, $filaDatos['res'] === 'SIN_DATOS' ? null : $filaDatos['sem'][$semana]);
            $hoja->setCellValue(col(2 + $numSemanas + $j) . $filaActual, $filaDatos['ausSem'][$semana]);
        }
        $hoja->getStyle('B' . $filaActual . ':' . col(1 + $numSemanas) . $filaActual)->getNumberFormat()->setFormatCode('+#,##0;-#,##0;0');   //la diferencia, con su signo
        $hoja->getStyle(col(2 + $numSemanas) . $filaActual . ':' . col($ultima) . $filaActual)->getNumberFormat()->setFormatCode('#,##0');
        $filaActual++;
    };
    foreach ($filas as $item) { $escribirFila($item['nombre'], $item['fila']); }
    $escribirFila($nombreTotal, $total);
    filaTotal($hoja, $filaActual - 1, $ultima, $ROSA);
    cerrarTabla($hoja, $fila, $filaActual - 1, $ultima);
    return $filaActual + 1;
}

//El encabezado de una hoja: título, periodo y la explicación de la diferencia
function encabezado($hoja, $titulo, $consolidado, $MESES, $VINO)
{
    $periodo = $consolidado['periodo'];
    $hoja->setCellValue('A1', $titulo);
    $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($VINO);
    $corte = $periodo['corte'] === 0 ? 'el mes todavía no empieza' : ($periodo['corte'] < $periodo['diasMes'] ? 'va en curso: cifras hasta el día ' . $periodo['corte'] : 'mes completo');
    //Los días que se suman: todo el mes, un día o un rango
    $todoMes = $periodo['desde'] === 1 && $periodo['hasta'] === $periodo['diasMes'];
    $rango = $todoMes ? '' : ($periodo['desde'] === $periodo['hasta'] ? ', solo el día ' . $periodo['desde'] : ', del día ' . $periodo['desde'] . ' al ' . $periodo['hasta']);
    //Si se filtró por cargo, se dice cuáles entran en las cifras
    $solo = '';
    if (!empty($consolidado['filtroCargos'])) {
        $nombres = array();
        foreach ($consolidado['cargos'] as $cargo) { if (in_array($cargo['id'], $consolidado['filtroCargos'], true)) { $nombres[] = $cargo['nombre']; } }
        $solo = ' Solo los cargos: ' . implode(', ', $nombres) . '.';
    }
    $hoja->setCellValue('A2', ucfirst($MESES[$periodo['mes']]) . ' de ' . $periodo['anio'] . $rango . ' (' . $corte . ').' . $solo . ' Generado por HANA el ' . date('d/m/Y') . ' a las ' . date('H:i') . '.');
    $hoja->setCellValue('A3', 'DIFERENCIA = EJECUTADO − PROMESA DE VALOR (positivo = se ejecutó MÁS de lo prometido; negativo = MENOS). El porqué se lee en las ausencias de los mismos días. '
                         . 'La promesa y el ejecutado se comparan solo en los días con ejecutado registrado.');
    $hoja->getStyle('A2:A3')->getFont()->setSize(9)->getColor()->setRGB('6B7076');
}

//Anchos de columna y que la primera se quede quieta al correr la hoja
function formatoHoja($hoja, $ultima, $anchoA, $colCausa)
{
    $hoja->getColumnDimension('A')->setWidth($anchoA);
    $hoja->getColumnDimension('B')->setWidth(26);
    for ($columna = 3; $columna <= $ultima; $columna++) { $hoja->getColumnDimension(col($columna))->setWidth(15); }
    $hoja->getColumnDimension(col($colCausa))->setWidth(24);                 //la causa principal es texto: necesita más ancho
    $hoja->freezePane('B5');
    $hoja->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
}

//En qué columna cae "CAUSA PRINCIPAL": 2 de nombre + 5 de promesa y ejecutado + los motivos que cuentan + el total
$colCausa = 2 + 5 + count(array_filter($consolidado['motivos'], function ($motivo) { return $motivo['cuenta']; })) + 2;

$libro = new Spreadsheet();
$libro->removeSheetByIndex(0);
$libro->getProperties()->setCreator('HANA - Grupo Regency')->setTitle('Consolidado de promesa de valor, ejecutado y ausentismo');
$libro->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

//---------------------------------------------------------------------------
// Un solo peaje: una hoja con el peaje, sus semanas y sus cargos
//---------------------------------------------------------------------------
$proyectoPedido = null; $centroPedido = null;                                                   //el proyecto y el peaje pedidos
if ($soloCentro && count($consolidado['proyectos']) && count($consolidado['proyectos'][0]['centros'])) { $proyectoPedido = $consolidado['proyectos'][0]; $centroPedido = $proyectoPedido['centros'][0]; }
if ($centroPedido) {
    $hoja = hojaNueva($libro, $centroPedido['nombre']);
    encabezado($hoja, ($centroPedido['tipo'] === 'BASCULA' ? 'BÁSCULA ' : 'PEAJE ') . $centroPedido['nombre'] . ' (' . $proyectoPedido['nombre'] . '): PROMESA DE VALOR, EJECUTADO Y AUSENTISMO', $consolidado, $MESES, $VINO);

    //1. Resumen del peaje
    $filaActual = 5; subtitulo($hoja, $filaActual, '1. RESUMEN DEL PEAJE', $VINO); $filaActual++;
    $filaInicio = $filaActual; $ultima = titulos($hoja, $filaActual, array('PEAJE', ''), $consolidado, true, $VINO, $VINO_CLARO); $hoja->mergeCells('A' . $filaActual . ':B' . $filaActual); $filaActual++;
    nombre($hoja, $filaActual, $centroPedido['nombre']); datos($hoja, $filaActual, 3, $centroPedido['fila'], $consolidado, true, $RESULTADOS); filaTotal($hoja, $filaActual, $ultima, $ROSA);
    cerrarTabla($hoja, $filaInicio, $filaActual, $ultima); $filaActual += 2;

    //2. Por semana, cargo por cargo
    subtitulo($hoja, $filaActual, '2. POR SEMANA', $VINO); $filaActual++;
    $filaActual = tablaSemanas($hoja, $filaActual, 'CARGO', $centroPedido['cargos'], $centroPedido['fila'], 'TOTAL ' . $centroPedido['nombre'], $consolidado, $VINO, $VINO_CLARO, $ROSA);

    //3. Por cargo
    subtitulo($hoja, $filaActual, '3. DETALLE POR CARGO', $VINO); $filaActual++;
    $filaInicio = $filaActual; $ultimaCargo = titulos($hoja, $filaActual, array('CARGO', ''), $consolidado, false, $VINO, $VINO_CLARO); $hoja->mergeCells('A' . $filaActual . ':B' . $filaActual); $filaActual++;
    foreach ($centroPedido['cargos'] as $cargo) { nombre($hoja, $filaActual, $cargo['nombre']); datos($hoja, $filaActual, 3, $cargo['fila'], $consolidado, false, $RESULTADOS); $filaActual++; }
    nombre($hoja, $filaActual, 'TOTAL ' . $centroPedido['nombre']); datos($hoja, $filaActual, 3, $centroPedido['fila'], $consolidado, false, $RESULTADOS); filaTotal($hoja, $filaActual, $ultimaCargo, $ROSA);
    cerrarTabla($hoja, $filaInicio, $filaActual, $ultimaCargo);
    formatoHoja($hoja, $ultima, 30, $colCausa);
} else {

    //---------------------------------------------------------------------------
    // Hoja RESUMEN: todos los proyectos
    //---------------------------------------------------------------------------
    $hoja = hojaNueva($libro, 'RESUMEN');
    encabezado($hoja, 'INFORME CONSOLIDADO: PROMESA DE VALOR, EJECUTADO Y AUSENTISMO — ' . count($consolidado['proyectos']) . (count($consolidado['proyectos']) === 1 ? ' PROYECTO' : ' PROYECTOS'), $consolidado, $MESES, $VINO);

    //1. Resumen por proyecto
    $filaActual = 5; subtitulo($hoja, $filaActual, '1. RESUMEN POR PROYECTO', $VINO); $filaActual++;
    $filaInicio = $filaActual; $ultima = titulos($hoja, $filaActual, array('PROYECTO', ''), $consolidado, true, $VINO, $VINO_CLARO); $hoja->mergeCells('A' . $filaActual . ':B' . $filaActual); $filaActual++;
    foreach ($consolidado['proyectos'] as $proyecto) { nombre($hoja, $filaActual, $proyecto['nombre']); datos($hoja, $filaActual, 3, $proyecto['fila'], $consolidado, true, $RESULTADOS); $filaActual++; }
    nombre($hoja, $filaActual, 'TOTAL'); datos($hoja, $filaActual, 3, $consolidado['total'], $consolidado, true, $RESULTADOS); filaTotal($hoja, $filaActual, $ultima, $ROSA);
    cerrarTabla($hoja, $filaInicio, $filaActual, $ultima); $anchoMax = $ultima; $filaActual += 2;

    //2. Por semana
    subtitulo($hoja, $filaActual, '2. POR SEMANA (semana 1 = días 1 a 7, semana 2 = 8 a 14, semana 3 = 15 a 21, semana 4 = 22 a 28)', $VINO); $filaActual++;
    $filaActual = tablaSemanas($hoja, $filaActual, 'PROYECTO', $consolidado['proyectos'], $consolidado['total'], 'TOTAL', $consolidado, $VINO, $VINO_CLARO, $ROSA);

    //3. Resumen por cargo
    subtitulo($hoja, $filaActual, '3. RESUMEN POR CARGO (todos los proyectos)', $VINO); $filaActual++;
    $filaInicio = $filaActual; $ultimaCargo = titulos($hoja, $filaActual, array('CARGO', ''), $consolidado, false, $VINO, $VINO_CLARO); $hoja->mergeCells('A' . $filaActual . ':B' . $filaActual); $filaActual++;
    foreach ($consolidado['roles'] as $rol) { nombre($hoja, $filaActual, $rol['nombre']); datos($hoja, $filaActual, 3, $rol['fila'], $consolidado, false, $RESULTADOS); $filaActual++; }
    nombre($hoja, $filaActual, 'TOTAL'); datos($hoja, $filaActual, 3, $consolidado['total'], $consolidado, false, $RESULTADOS); filaTotal($hoja, $filaActual, $ultimaCargo, $ROSA);
    cerrarTabla($hoja, $filaInicio, $filaActual, $ultimaCargo); $filaActual += 2;

    //4. Detalle por peaje
    subtitulo($hoja, $filaActual, '4. DETALLE POR PEAJE', $VINO); $filaActual++;
    $filaInicio = $filaActual; titulos($hoja, $filaActual, array('PROYECTO', 'PEAJE'), $consolidado, true, $VINO, $VINO_CLARO); $filaActual++;
    foreach ($consolidado['proyectos'] as $proyecto) {
        foreach ($proyecto['centros'] as $centro) { $hoja->setCellValue('A' . $filaActual, $proyecto['nombre']); $hoja->setCellValue('B' . $filaActual, $centro['nombre']); datos($hoja, $filaActual, 3, $centro['fila'], $consolidado, true, $RESULTADOS); $filaActual++; }
    }
    $hoja->setCellValue('A' . $filaActual, 'TOTAL'); datos($hoja, $filaActual, 3, $consolidado['total'], $consolidado, true, $RESULTADOS); filaTotal($hoja, $filaActual, $ultima, $ROSA);
    cerrarTabla($hoja, $filaInicio, $filaActual, $ultima);
    formatoHoja($hoja, $anchoMax, 24, $colCausa);

    //---------------------------------------------------------------------------
    // Una hoja por proyecto
    //---------------------------------------------------------------------------
    foreach ($consolidado['proyectos'] as $proyecto) {
        $hoja = hojaNueva($libro, $proyecto['nombre']);
        encabezado($hoja, $proyecto['nombre'] . ': PROMESA DE VALOR, EJECUTADO Y AUSENTISMO', $consolidado, $MESES, $VINO);

        //1. Resumen por peaje
        $filaActual = 5; subtitulo($hoja, $filaActual, '1. RESUMEN POR PEAJE', $VINO); $filaActual++;
        $filaInicio = $filaActual; $ultima = titulos($hoja, $filaActual, array('PEAJE', ''), $consolidado, true, $VINO, $VINO_CLARO); $hoja->mergeCells('A' . $filaActual . ':B' . $filaActual); $filaActual++;
        foreach ($proyecto['centros'] as $centro) { nombre($hoja, $filaActual, $centro['nombre']); datos($hoja, $filaActual, 3, $centro['fila'], $consolidado, true, $RESULTADOS); $filaActual++; }
        nombre($hoja, $filaActual, 'TOTAL ' . $proyecto['nombre']); datos($hoja, $filaActual, 3, $proyecto['fila'], $consolidado, true, $RESULTADOS); filaTotal($hoja, $filaActual, $ultima, $ROSA);
        cerrarTabla($hoja, $filaInicio, $filaActual, $ultima); $filaActual += 2;

        //2. Por semana
        subtitulo($hoja, $filaActual, '2. POR SEMANA', $VINO); $filaActual++;
        $filaActual = tablaSemanas($hoja, $filaActual, 'PEAJE', $proyecto['centros'], $proyecto['fila'], 'TOTAL ' . $proyecto['nombre'], $consolidado, $VINO, $VINO_CLARO, $ROSA);

        //3. Detalle por peaje y cargo
        subtitulo($hoja, $filaActual, '3. DETALLE POR PEAJE Y CARGO', $VINO); $filaActual++;
        $filaInicio = $filaActual; $ultimaCargo = titulos($hoja, $filaActual, array('PEAJE', 'CARGO'), $consolidado, false, $VINO, $VINO_CLARO); $filaActual++;
        foreach ($proyecto['centros'] as $centro) {
            foreach ($centro['cargos'] as $cargo) { $hoja->setCellValue('A' . $filaActual, $centro['nombre']); $hoja->setCellValue('B' . $filaActual, $cargo['nombre']); datos($hoja, $filaActual, 3, $cargo['fila'], $consolidado, false, $RESULTADOS); $filaActual++; }
        }
        $hoja->setCellValue('A' . $filaActual, 'TOTAL ' . $proyecto['nombre']); datos($hoja, $filaActual, 3, $proyecto['fila'], $consolidado, false, $RESULTADOS); filaTotal($hoja, $filaActual, $ultimaCargo, $ROSA);
        cerrarTabla($hoja, $filaInicio, $filaActual, $ultimaCargo);
        formatoHoja($hoja, $ultima, 22, $colCausa);
    }
}

$libro->setActiveSheetIndex(0);

//El nombre del archivo dice qué se bajó: el peaje o el proyecto, el mes y los días
$periodo = $consolidado['periodo'];
$quien = $centroPedido ? ' ' . $centroPedido['nombre'] : (count($consolidado['proyectos']) === 1 && !empty($_GET['proyecto']) ? ' ' . $consolidado['proyectos'][0]['nombre'] : '');
$textoDias = ($periodo['desde'] === 1 && $periodo['hasta'] === $periodo['diasMes']) ? '' : ($periodo['desde'] === $periodo['hasta'] ? ' dia ' . $periodo['desde'] : ' del ' . $periodo['desde'] . ' al ' . $periodo['hasta']);
$nombre = 'Consolidado promesa de valor y ausentismo' . $quien . ' ' . $MESES[$mes] . ' ' . $anio . $textoDias . '.xlsx';
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: max-age=0');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
