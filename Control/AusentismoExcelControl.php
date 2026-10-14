<?php
/*
  HANA - Ausentismo en Excel: quién puede descargarlo y con qué datos
  ---------------------------------------------------------------------------
  Lo usa Vista/AusentismoExcelVista.php: este controlador revisa la sesión,
  el módulo y el proyecto pedido, y le pide los datos al modelo. Deja listos:
    $proy, $anio, $mes, $cargos, $novedades,
    $centros (cada uno con CARGOS = los cargos que aplican y VALS = el mes registrado),
    $cons (el consolidado del proyecto)
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

$idProy = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : 0;
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;
if ($anio < 2020 || $anio > 2100 || $mes < 1 || $mes > 12) { negar('El mes no es válido.'); }
$proy = Organizacion::proyectoActivo($idProy);
if (!$proy || (!$verTodos && (int)$proy['ID_COLABORADOR_COORDINADOR'] !== $idColaborador)) { negar('Ese proyecto no está entre los que puedes ver.'); }

$A = new Ausentismo();
$cargos = $A->cargos();
$novedades = $A->novedadesDelMes($anio, $mes); //las de hoy y las viejas que ese mes tienen datos
$centros = array();
foreach (Organizacion::centrosDeProyecto($idProy) as $c) {
    $c['CARGOS'] = array_values(array_filter($cargos, function ($cg) use ($c) { return Ausentismo::cargoAplica($cg, $c['TIPO_CENTRO']); }));
    $c['VALS'] = $A->mesCentro($c['ID_CENTRO_OP'], $anio, $mes);
    $centros[] = $c;
}
$cons = $A->consolidado($idProy, $anio, $mes);
