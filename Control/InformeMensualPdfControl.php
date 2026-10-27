<?php
/*
  HANA - Informe de gestión en PDF: el periodo, los proyectos y los datos
  ---------------------------------------------------------------------------
  Lo usa Vista/InformeMensualPdfVista.php: este controlador revisa la sesión y
  el permiso (37M), valida el periodo y le pide los datos al modelo. Deja
  $datos y $quien listos para dibujar.
  Cada quien recibe los proyectos de sus peajes asignados; el ADMIN TEC (24M),
  todos
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Bogota');

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { header('Location: login.php'); exit; }
$modulos = explode(',', $_SESSION['Modulos']);

//Responde un mensaje sencillo cuando no se puede generar
function infError($codigo, $mensaje)
{
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    echo $mensaje;
    exit;
}
if (!in_array('37M', $modulos) && !in_array('24M', $modulos)) { infError(403, 'Tu rol no tiene el informe mensual de gestión (casilla 37M).'); }

require_once __DIR__ . '/AccesoHelper.php'; //los proyectos de sus peajes asignados
require_once __DIR__ . '/../Modelo/InformeMensualModelo.php';
require_once __DIR__ . '/../Modelo/HanaFechas.php';

//El periodo pedido. Dos formas: desde y hasta (AAAA-MM-DD), o anio y mes para un mes completo.
//Sin nada, el mes actual
$hoy = HanaFechas::hoy();
if (isset($_GET['desde']) || isset($_GET['hasta'])) {
    $desde = HanaVal::fecha(isset($_GET['desde']) ? $_GET['desde'] : '');
    $hasta = HanaVal::fecha(isset($_GET['hasta']) ? $_GET['hasta'] : '');
    if ($desde === '' || $hasta === '') { infError(400, 'Las fechas del periodo no son válidas.'); }
    if ($hasta < $desde) { infError(400, 'La fecha final no puede ser anterior a la inicial.'); }
    if ($desde > $hoy) { infError(400, 'Ese periodo todavía no empieza.'); }
    if ($desde < '2020-01-01') { infError(400, 'La fecha inicial es demasiado antigua.'); }
    //Un tope para que el informe no se vuelva eterno: máximo 12 meses
    if ((strtotime($hasta) - strtotime($desde)) / 86400 > 366) { infError(400, 'El periodo no puede pasar de 12 meses.'); }
    $periodo = InformeMensual::rango($desde, $hasta);
} else {
    $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)substr($hoy, 0, 4);
    $mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)substr($hoy, 5, 2);
    if ($anio < 2020 || $anio > 2100 || $mes < 1 || $mes > 12) { infError(400, 'El mes no es válido.'); }
    if (sprintf('%04d-%02d', $anio, $mes) > substr($hoy, 0, 7)) { infError(400, 'Ese mes todavía no empieza.'); }
    $periodo = InformeMensual::periodo($anio, $mes);
}

$modeloInforme = new InformeMensual();
$todos = in_array('24M', $modulos); //el ADMIN TEC ve todos los proyectos
$misProyectos = hanaMisProyectos();
$proyectos = array_values(array_filter($modeloInforme->proyectos((int)$_SESSION['Idcolaborador'], true), function ($proyecto) use ($misProyectos) { return isset($misProyectos[(int)$proyecto['ID_PROYECTO']]); }));
if (!count($proyectos)) {
    infError(403, 'No tienes proyectos para el informe: pide que te asignen peajes en Configuración → Colaboradores.');
}
//Los proyectos que se eligieron en la pantalla (proyectos=1,4,5). Solo cuentan los que la persona
//puede ver; sin el dato, salen todos los suyos
if (isset($_GET['proyectos']) && trim((string)$_GET['proyectos']) !== '') {
    $elegidos = array_map('intval', explode(',', (string)$_GET['proyectos']));
    $proyectos = array_values(array_filter($proyectos, function ($proyecto) use ($elegidos) { return in_array((int)$proyecto['ID_PROYECTO'], $elegidos, true); }));
    if (!count($proyectos)) { infError(400, 'Elige al menos uno de tus proyectos para el informe.'); }
}

//El nombre de quien lo genera viene de la sesión (guardado ya escapado para HTML)
$quien = isset($_SESSION['Nombre']) ? html_entity_decode($_SESSION['Nombre'], ENT_QUOTES, 'UTF-8') : '';

$datos = $modeloInforme->datos($periodo, $proyectos);
$datos['ceros'] = InformeMensual::ceros(); //lo que se muestra de un peaje sin datos
