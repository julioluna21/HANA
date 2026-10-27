<?php
/*
  Controlador retirado: el ausentismo (matriz de cubrimiento) se quitó del sistema.
  En esa sección queda solo la promesa de valor (Control/PromesaValorControl.php).
  Responde 410 y no ejecuta nada; el código de abajo se conserva solo como referencia.
*/
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('error' => 'El ausentismo se retiró del sistema.'), JSON_UNESCAPED_UNICODE);
exit;
?>
<?php
/*
  HANA - Ausentismo en Excel: quién puede descargarlo y con qué datos
  ---------------------------------------------------------------------------
  Lo usa Vista/AusentismoExcelVista.php: este controlador revisa la sesión,
  el módulo y lo pedido, y le pide los datos al modelo. Se puede pedir:
    - un proyecto (proyecto=ID): una hoja por centro y la del consolidado;
    - un solo peaje (proyecto=ID y centro=ID): su hoja y su total;
    - todos sus proyectos (todos=1, con la casilla de ver el ausentismo): una hoja por
      proyecto con su consolidado y al final la de todos juntos;
  Siempre con los peajes asignados a la persona (el ADMIN TEC, todos).
  de todo el mes o de un rango de días (desde, hasta). Deja listos:
    $todos, $proyectoPedido (el proyecto; con todos, solo el nombre), $anio, $mes,
    $dias (los días del mes), $diaDesde y $diaHasta (el rango de días pedido),
    $cargos, $novedades,
    $centros (un proyecto: cada centro con CARGOS = los cargos que aplican y VALS = el mes registrado),
    $proyectos (todos: cada proyecto con CONS = su consolidado),
    $soloCentro (un solo peaje: ese centro; si no, null),
    $consolidado (el consolidado de lo pedido)
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Bogota');
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/AccesoHelper.php';
require_once __DIR__ . '/../Modelo/AusentismoModelo.php';

//Corta la descarga con un mensaje cuando la persona no tiene permiso
function negar($mensaje) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo $mensaje; exit; }

if (!HanaConfig::modulo('AUSENTISMO')) { negar('El módulo de ausentismo está desactivado.'); }
$idColaborador = hanaIdColaborador();
if (!hanaEntra('AUSENTISMO')) { negar('Tu rol no tiene acceso al ausentismo.'); }
$verTodos = hanaPuede('AUSENTISMO', 'ver') || hanaPuede('AUSENTISMO', 'cerrado');
$misCentros = hanaMisCentrosIds();
$centrosPermitidos = hanaVeTodo() ? null : array_map('intval', array_keys($misCentros));
$misProyectos = hanaMisProyectos();
$todos = isset($_GET['todos']) && $_GET['todos'] === '1';

$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : 0;
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;
if ($anio < 2020 || $anio > 2100 || $mes < 1 || $mes > 12) { negar('El mes no es válido.'); }

//Los días: sin "desde" ni "hasta", todo el mes
$dias = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes)));
$diaDesde = isset($_GET['desde']) && $_GET['desde'] !== '' ? (string)$_GET['desde'] : '1';
$diaHasta = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? (string)$_GET['hasta'] : (string)$dias;
if (!ctype_digit($diaDesde) || !ctype_digit($diaHasta) || (int)$diaDesde < 1 || (int)$diaHasta > $dias || (int)$diaDesde > (int)$diaHasta) { negar('Los días no son válidos para ese mes.'); }
$diaDesde = (int)$diaDesde;
$diaHasta = (int)$diaHasta;

$modeloAusentismo = new Ausentismo();
$cargos = $modeloAusentismo->cargos();
$novedades = $modeloAusentismo->novedadesDelMes($anio, $mes); //las de hoy y las viejas que ese mes tienen datos
$centros = array();
$proyectos = array();
$soloCentro = null;

if ($todos) {
    if (!$verTodos) { negar('El consolidado de todos tus proyectos juntos necesita la casilla de ver el ausentismo.'); }
    $proyectoPedido = array('ID_PROYECTO' => 0, 'NOM_PROYECTO' => 'TODOS LOS PROYECTOS');
    foreach (Organizacion::proyectos() as $proyecto) {
        if (!isset($misProyectos[(int)$proyecto['ID_PROYECTO']])) { continue; }
        $proyecto['CONS'] = $modeloAusentismo->consolidado($proyecto['ID_PROYECTO'], $anio, $mes, $diaDesde, $diaHasta, 0, $centrosPermitidos);
        $proyectos[] = $proyecto;
    }
    $consolidado = $modeloAusentismo->consolidado(array_map(function ($proyecto) { return $proyecto['ID_PROYECTO']; }, $proyectos), $anio, $mes, $diaDesde, $diaHasta, 0, $centrosPermitidos);
} else {
    $idProyecto = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
    $proyectoPedido = Organizacion::proyectoActivo($idProyecto);
    if (!$proyectoPedido || !isset($misProyectos[$idProyecto])) { negar('Ese proyecto no está entre los que puedes ver.'); }
    $idCentro = isset($_GET['centro']) ? (int)$_GET['centro'] : 0; //0 = todos los peajes del proyecto
    foreach (Organizacion::centrosDeProyecto($idProyecto) as $centro) {
        if ($idCentro && (int)$centro['ID_CENTRO_OP'] !== $idCentro) { continue; }
        if ($centrosPermitidos !== null && !isset($misCentros[(int)$centro['ID_CENTRO_OP']])) { continue; } //solo sus peajes
        $centro['CARGOS'] = array_values(array_filter($cargos, function ($cargo) use ($centro) { return Ausentismo::cargoAplica($cargo, $centro['TIPO_CENTRO']); }));
        $centro['VALS'] = $modeloAusentismo->mesCentro($centro['ID_CENTRO_OP'], $anio, $mes);
        $centros[] = $centro;
    }
    if ($idCentro) {
        if (!count($centros)) { negar('Ese peaje no es del proyecto.'); }
        $soloCentro = $centros[0];
    }
    $consolidado = $modeloAusentismo->consolidado($idProyecto, $anio, $mes, $diaDesde, $diaHasta, $idCentro, $centrosPermitidos);
}

//Los nombres de proyectos, centros, cargos y motivos pueden venir guardados con códigos
//(«Peajes &amp; Vías»). En el Excel van como se escribieron
require_once __DIR__ . '/../Modelo/HanaTexto.php';
list($proyectoPedido, $centros, $proyectos, $soloCentro, $cargos, $novedades, $consolidado) =
    hanaTextoVisibleTodo(array($proyectoPedido, $centros, $proyectos, $soloCentro, $cargos, $novedades, $consolidado));
