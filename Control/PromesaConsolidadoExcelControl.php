<?php
/*
  Archivo sin uso: el consolidado de promesa de valor se quitó del sistema junto con la promesa de valor.
  Responde 410 ("Esta dirección ya no se usa.") y no ejecuta nada; el código de
  abajo se conserva solo como referencia.
*/
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('error' => 'Esta dirección ya no se usa.'), JSON_UNESCAPED_UNICODE);
exit;
?>
<?php
/*
  HANA - Consolidado de promesa de valor en Excel: acceso y datos
  ---------------------------------------------------------------------------
  Lo usa Vista/PromesaConsolidadoExcelVista.php. Revisa la casilla 40M, valida
  el mes y le pide el consolidado al modelo con los mismos proyectos que se
  ven en la pantalla. Baja lo que se está viendo:
    - todos los proyectos que puede ver (sin proyecto ni centro);
    - un proyecto (proyecto=ID);
    - un solo peaje (centro=ID);
  de todo el mes o de un rango de días (desde, hasta), y con el mismo filtro
  de cargos (cargos=1,3). Deja listos $anio, $mes, $consolidado y $soloCentro (el peaje
  pedido, o null).
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Bogota');
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/AccesoHelper.php';
require_once __DIR__ . '/../Modelo/PromesaValorModelo.php';

//Corta la descarga con un mensaje cuando la persona no tiene permiso
function negar($mensaje) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo $mensaje; exit; }

if (!hanaPromesaConsolidado()) { negar('El consolidado necesita la casilla 40M en tu rol.'); }
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : 0;
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : 0;
if ($anio < 2020 || $anio > 2100 || $mes < 1 || $mes > 12) { negar('El mes no es válido.'); }

//Los días: sin "desde" ni "hasta", todo el mes
$dias = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes)));
$diaDesde = isset($_GET['desde']) && $_GET['desde'] !== '' ? (string)$_GET['desde'] : '1';
$diaHasta = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? (string)$_GET['hasta'] : (string)$dias;
if (!ctype_digit($diaDesde) || !ctype_digit($diaHasta) || (int)$diaDesde < 1 || (int)$diaHasta > $dias || (int)$diaDesde > (int)$diaHasta) { negar('Los días no son válidos para ese mes.'); }

list($proyectos) = hanaProyectosConsolidado();                    //los mismos proyectos que ve en la pantalla
//Un proyecto o un peaje: tiene que estar entre los que puede ver
$soloCentro = null;
$idProyecto = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
$idCentro = isset($_GET['centro']) ? (int)$_GET['centro'] : 0;
if ($idCentro > 0) {
    foreach ($proyectos as $proyecto) {
        foreach (Organizacion::centrosDeProyecto($proyecto['ID_PROYECTO']) as $centro) {
            if ((int)$centro['ID_CENTRO_OP'] === $idCentro) { $soloCentro = $centro; $idProyecto = (int)$proyecto['ID_PROYECTO']; }
        }
    }
    if (!$soloCentro) { negar('Ese peaje no está entre los que puedes ver.'); }
}
if ($idProyecto > 0) {
    $proyectos = array_values(array_filter($proyectos, function ($proyecto) use ($idProyecto) { return (int)$proyecto['ID_PROYECTO'] === $idProyecto; }));
    if (!count($proyectos)) { negar('Ese proyecto no está entre los que puedes ver.'); }
}
//El mismo filtro de cargos de la pantalla (?cargos=1,3): vacío = todos
$soloCargos = array();
foreach (explode(',', isset($_GET['cargos']) ? (string)$_GET['cargos'] : '') as $valor) { if (ctype_digit(trim($valor)) && (int)$valor > 0) { $soloCargos[] = (int)$valor; } }
$consolidado = (new PromesaValor())->consolidado($anio, $mes, $proyectos, date('Y-m-d'), array_values(array_unique($soloCargos)),
                                       (int)$diaDesde, (int)$diaHasta, $soloCentro ? $idCentro : 0);

//Los nombres de proyectos, centros, cargos y motivos pueden venir guardados con códigos
//(«Peajes &amp; Vías»). En el Excel van como se escribieron
require_once __DIR__ . '/../Modelo/HanaTexto.php';
list($consolidado, $proyectos, $soloCentro) = hanaTextoVisibleTodo(array($consolidado, $proyectos, $soloCentro));
