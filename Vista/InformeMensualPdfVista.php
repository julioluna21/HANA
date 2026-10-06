<?php
//Reporte diario — Informe de gestión en PDF (un mes, varios meses, una quincena o un rango de fechas)
//Con ?ver=1 se abre en el navegador; sin eso, se descarga.
//Permiso 37M. Quien además tiene 20M (todos los proyectos) o 24M (administrador)
//recibe todos los proyectos; los demás, solo los que coordinan
session_start();
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
if (!in_array('37M', $modulos)) { infError(403, 'Tu rol no tiene el informe mensual de gestión (casilla 37M).'); }

require_once __DIR__ . '/../Modelo/InformeMensualModelo.php';
require_once __DIR__ . '/../Modelo/InformeMensualPdf.php';

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

$I = new InformeMensual();
$todos = in_array('20M', $modulos) || in_array('24M', $modulos); //ve todos los proyectos
$proyectos = $I->proyectos((int)$_SESSION['Idcolaborador'], $todos);
if (!count($proyectos)) {
    infError(403, 'No tienes proyectos para el informe: no coordinas ninguno y tu rol no tiene "Tablero: ver todos los proyectos" (20M).');
}

//El nombre de quien lo genera viene de la sesión (guardado ya escapado para HTML)
$quien = isset($_SESSION['Nombre']) ? html_entity_decode($_SESSION['Nombre'], ENT_QUOTES, 'UTF-8') : '';

$datos = $I->datos($periodo, $proyectos);
$pdf = new InformeMensualPdf($datos, $quien);
$pdf->armar();

//Un aviso de PHP antes del PDF lo dañaría: se descarta lo que se haya escrito
while (ob_get_level() > 0) { ob_end_clean(); }
$pdf->Output(isset($_GET['ver']) ? 'I' : 'D', InformeMensualPdf::nombreArchivo($datos['periodo']));
