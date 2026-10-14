<?php
/*
  HANA - Consolidado de promesa de valor en Excel: acceso y datos
  ---------------------------------------------------------------------------
  Lo usa Vista/PromesaConsolidadoExcelVista.php. Revisa la casilla 40M, valida
  el mes y le pide el consolidado al modelo con los mismos proyectos que se
  ven en la pantalla. Deja listos $anio, $mes y $D.
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

list($proyectos) = hanaProyectosConsolidado();                    //los mismos proyectos que ve en la pantalla
//El mismo filtro de cargos de la pantalla (?cargos=1,3): vacío = todos
$soloCargos = array();
foreach (explode(',', isset($_GET['cargos']) ? (string)$_GET['cargos'] : '') as $x) { if (ctype_digit(trim($x)) && (int)$x > 0) { $soloCargos[] = (int)$x; } }
$D = (new PromesaValor())->consolidado($anio, $mes, $proyectos, date('Y-m-d'), array_values(array_unique($soloCargos)));
