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
$verTodos = hanaTienePermiso('20M') || hanaTienePermiso('21M') || hanaTienePermiso('22M') || hanaTienePermiso('25M');
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
    if (!$verTodos) { negar('El consolidado de todos los proyectos lo ven quienes consultan todos los proyectos.'); }
    $proyectoPedido = array('ID_PROYECTO' => 0, 'NOM_PROYECTO' => 'TODOS LOS PROYECTOS');
    foreach (Organizacion::proyectos() as $proyecto) {
        $proyecto['CONS'] = $modeloAusentismo->consolidado($proyecto['ID_PROYECTO'], $anio, $mes, $diaDesde, $diaHasta);
        $proyectos[] = $proyecto;
    }
    $consolidado = $modeloAusentismo->consolidado(array_map(function ($proyecto) { return $proyecto['ID_PROYECTO']; }, $proyectos), $anio, $mes, $diaDesde, $diaHasta);
} else {
    $idProyecto = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
    $proyectoPedido = Organizacion::proyectoActivo($idProyecto);
    if (!$proyectoPedido || (!$verTodos && (int)$proyectoPedido['ID_COLABORADOR_COORDINADOR'] !== $idColaborador)) { negar('Ese proyecto no está entre los que puedes ver.'); }
    $idCentro = isset($_GET['centro']) ? (int)$_GET['centro'] : 0; //0 = todos los peajes del proyecto
    foreach (Organizacion::centrosDeProyecto($idProyecto) as $centro) {
        if ($idCentro && (int)$centro['ID_CENTRO_OP'] !== $idCentro) { continue; }
        $centro['CARGOS'] = array_values(array_filter($cargos, function ($cargo) use ($centro) { return Ausentismo::cargoAplica($cargo, $centro['TIPO_CENTRO']); }));
        $centro['VALS'] = $modeloAusentismo->mesCentro($centro['ID_CENTRO_OP'], $anio, $mes);
        $centros[] = $centro;
    }
    if ($idCentro) {
        if (!count($centros)) { negar('Ese peaje no es del proyecto.'); }
        $soloCentro = $centros[0];
    }
    $consolidado = $modeloAusentismo->consolidado($idProyecto, $anio, $mes, $diaDesde, $diaHasta, $idCentro);
}

//Los nombres de proyectos, centros, cargos y motivos pueden venir guardados con códigos
//(«Peajes &amp; Vías»). En el Excel van como se escribieron
require_once __DIR__ . '/../Modelo/HanaTexto.php';
list($proyectoPedido, $centros, $proyectos, $soloCentro, $cargos, $novedades, $consolidado) =
    hanaTextoVisibleTodo(array($proyectoPedido, $centros, $proyectos, $soloCentro, $cargos, $novedades, $consolidado));
