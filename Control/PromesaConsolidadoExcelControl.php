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
  de cargos (cargos=1,3). Deja listos $anio, $mes, $D y $soloCentro (el peaje
  pedido, o null).
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Bogota');
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/AccesoHelper.php';
require_once __DIR__ . '/../Modelo/PromesaValorModelo.php';

//Corta la descarga con un mensaje cuando la persona no tiene permiso
function negar($msg) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; }

if (!hanaPromesaConsolidado()) { negar('El consolidado necesita la casilla 40M en tu rol.'); }
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : 0;
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : 0;
if ($anio < 2020 || $anio > 2100 || $mes < 1 || $mes > 12) { negar('El mes no es válido.'); }

//Los días: sin "desde" ni "hasta", todo el mes
$dias = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes)));
$d1 = isset($_GET['desde']) && $_GET['desde'] !== '' ? (string)$_GET['desde'] : '1';
$d2 = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? (string)$_GET['hasta'] : (string)$dias;
if (!ctype_digit($d1) || !ctype_digit($d2) || (int)$d1 < 1 || (int)$d2 > $dias || (int)$d1 > (int)$d2) { negar('Los días no son válidos para ese mes.'); }

list($proyectos) = hanaProyectosConsolidado();                    //los mismos proyectos que ve en la pantalla
//Un proyecto o un peaje: tiene que estar entre los que puede ver
$soloCentro = null;
$idProy = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
$idCentro = isset($_GET['centro']) ? (int)$_GET['centro'] : 0;
if ($idCentro > 0) {
    foreach ($proyectos as $p) {
        foreach (Organizacion::centrosDeProyecto($p['ID_PROYECTO']) as $c) {
            if ((int)$c['ID_CENTRO_OP'] === $idCentro) { $soloCentro = $c; $idProy = (int)$p['ID_PROYECTO']; }
        }
    }
    if (!$soloCentro) { negar('Ese peaje no está entre los que puedes ver.'); }
}
if ($idProy > 0) {
    $proyectos = array_values(array_filter($proyectos, function ($p) use ($idProy) { return (int)$p['ID_PROYECTO'] === $idProy; }));
    if (!count($proyectos)) { negar('Ese proyecto no está entre los que puedes ver.'); }
}
//El mismo filtro de cargos de la pantalla (?cargos=1,3): vacío = todos
$soloCargos = array();
foreach (explode(',', isset($_GET['cargos']) ? (string)$_GET['cargos'] : '') as $x) { if (ctype_digit(trim($x)) && (int)$x > 0) { $soloCargos[] = (int)$x; } }
$D = (new PromesaValor())->consolidado($anio, $mes, $proyectos, date('Y-m-d'), array_values(array_unique($soloCargos)),
                                       (int)$d1, (int)$d2, $soloCentro ? $idCentro : 0);
