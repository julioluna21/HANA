<?php
/*
  HANA - Ausentismo en Excel: quién puede descargarlo y con qué datos
  ---------------------------------------------------------------------------
  Lo usa Vista/AusentismoExcelVista.php: este controlador revisa la sesión,
  el módulo y lo pedido, y le pide los datos al modelo. Se puede pedir:
    - un proyecto (proyecto=ID): una hoja por centro y la del consolidado;
    - un solo peaje (proyecto=ID y centro=ID): su hoja y su total;
    - todos los proyectos (todos=1, con 20M, 21M, 22M o 25M): una hoja por
      proyecto con su consolidado y al final la de todos juntos;
  de todo el mes o de un rango de días (desde, hasta). Deja listos:
    $todos, $proy (el proyecto; con todos, solo el nombre), $anio, $mes,
    $dias (los días del mes), $d1 y $d2 (del día que se pidió al día que se pidió),
    $cargos, $novedades,
    $centros (un proyecto: cada centro con CARGOS = los cargos que aplican y VALS = el mes registrado),
    $proyectos (todos: cada proyecto con CONS = su consolidado),
    $soloCentro (un solo peaje: ese centro; si no, null),
    $cons (el consolidado de lo pedido)
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Bogota');
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/AccesoHelper.php';
require_once __DIR__ . '/../Modelo/AusentismoModelo.php';

//Corta la descarga con un mensaje cuando la persona no tiene permiso
function negar($msg) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; }

if (!HanaConfig::modulo('AUSENTISMO')) { negar('El módulo de ausentismo está desactivado.'); }
$idColaborador = hanaIdColaborador();
$verTodos = hanaTienePermiso('20M') || hanaTienePermiso('21M') || hanaTienePermiso('22M') || hanaTienePermiso('25M');
$todos = isset($_GET['todos']) && $_GET['todos'] === '1';

$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : 0;
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;
if ($anio < 2020 || $anio > 2100 || $mes < 1 || $mes > 12) { negar('El mes no es válido.'); }

//Los días: sin "desde" ni "hasta", todo el mes
$dias = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes)));
$d1 = isset($_GET['desde']) && $_GET['desde'] !== '' ? (string)$_GET['desde'] : '1';
$d2 = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? (string)$_GET['hasta'] : (string)$dias;
if (!ctype_digit($d1) || !ctype_digit($d2) || (int)$d1 < 1 || (int)$d2 > $dias || (int)$d1 > (int)$d2) { negar('Los días no son válidos para ese mes.'); }
$d1 = (int)$d1;
$d2 = (int)$d2;

$A = new Ausentismo();
$cargos = $A->cargos();
$novedades = $A->novedadesDelMes($anio, $mes); //las de hoy y las viejas que ese mes tienen datos
$centros = array();
$proyectos = array();
$soloCentro = null;

if ($todos) {
    if (!$verTodos) { negar('El consolidado de todos los proyectos lo ven quienes consultan todos los proyectos.'); }
    $proy = array('ID_PROYECTO' => 0, 'NOM_PROYECTO' => 'TODOS LOS PROYECTOS');
    foreach (Organizacion::proyectos() as $p) {
        $p['CONS'] = $A->consolidado($p['ID_PROYECTO'], $anio, $mes, $d1, $d2);
        $proyectos[] = $p;
    }
    $cons = $A->consolidado(array_map(function ($p) { return $p['ID_PROYECTO']; }, $proyectos), $anio, $mes, $d1, $d2);
} else {
    $idProy = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
    $proy = Organizacion::proyectoActivo($idProy);
    if (!$proy || (!$verTodos && (int)$proy['ID_COLABORADOR_COORDINADOR'] !== $idColaborador)) { negar('Ese proyecto no está entre los que puedes ver.'); }
    $idCentro = isset($_GET['centro']) ? (int)$_GET['centro'] : 0; //0 = todos los peajes del proyecto
    foreach (Organizacion::centrosDeProyecto($idProy) as $c) {
        if ($idCentro && (int)$c['ID_CENTRO_OP'] !== $idCentro) { continue; }
        $c['CARGOS'] = array_values(array_filter($cargos, function ($cg) use ($c) { return Ausentismo::cargoAplica($cg, $c['TIPO_CENTRO']); }));
        $c['VALS'] = $A->mesCentro($c['ID_CENTRO_OP'], $anio, $mes);
        $centros[] = $c;
    }
    if ($idCentro) {
        if (!count($centros)) { negar('Ese peaje no es del proyecto.'); }
        $soloCentro = $centros[0];
    }
    $cons = $A->consolidado($idProy, $anio, $mes, $d1, $d2, $idCentro);
}
