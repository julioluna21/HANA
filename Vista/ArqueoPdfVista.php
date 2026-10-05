<?php
//Reporte diario — Descargar el arqueo en PDF
//Con ?ver=1 se abre en el navegador; sin eso, se descarga
session_start();
date_default_timezone_set('America/Bogota');

if (!isset($_SESSION['IdUsuarios'])) { header('Location: login.php'); exit; }
$modulos = explode(',', $_SESSION['Modulos']);
if (!isset($_SESSION['Idcolaborador'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/../Modelo/ArqueoModelo.php';
require_once __DIR__ . '/../Modelo/ArqueoPdf.php';

$A = new Arqueo();
$a = $A->mostrar(isset($_GET['id']) ? (int)$_GET['id'] : 0);

//Se puede ver si el centro es de un proyecto que coordina, o si está en lo que
//ve en el tablero (19M: coordinador de ese proyecto, jefe de ese centro o 20M)
$suyos = array();
foreach (HanaDB::centrosCoordinador((int)$_SESSION['Idcolaborador']) as $c) { $suyos[(int)$c['ID_CENTRO_OP']] = true; }
//La administración del reporte diario (21M) ve los arqueos de todos los centros
if ($a && in_array('21M', $modulos)) { $suyos[(int)$a['ID_CENTRO_OP']] = true; }
if ($a && !isset($suyos[(int)$a['ID_CENTRO_OP']]) && in_array('19M', $modulos) && isset($_SESSION['Idcolaborador'])) {
    require_once __DIR__ . '/../Modelo/TableroModelo.php';
    $T = new Tablero();
    $alcance = $T->alcance((int)$_SESSION['Idcolaborador'], in_array('20M', $modulos));
    $suyos = $suyos + $alcance['centros'];
}
if (!$a || !isset($suyos[(int)$a['ID_CENTRO_OP']])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se encontró el arqueo, o no es de tus centros.';
    exit;
}

$pdf = new ArqueoPdf();
$pdf->SetTitle('Arqueo ' . $a['NOM_CENTRO_OP'] . ' ' . $a['FECHA'], true);
$pdf->SetAuthor('HANA - Grupo Regency', true);
if ($a['TIPO'] === 'CAJA_MENOR') { $pdf->cajaMenor($a); }
elseif ($a['TIPO'] === 'CASETA') { $pdf->caseta($a); } //arqueo de caseta, con los campos del peaje
else { $pdf->recambio($a); }

//Un aviso de PHP antes del PDF lo dañaría: se descarta lo que se haya escrito
while (ob_get_level() > 0) { ob_end_clean(); }
$pdf->Output(isset($_GET['ver']) ? 'I' : 'D', ArqueoPdf::nombreArchivo($a));
