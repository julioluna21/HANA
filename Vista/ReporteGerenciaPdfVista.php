<?php
//Consultas - Reporte diario a la Gerencia en PDF
//El controlador revisa el permiso y arma los datos ($reporte, $quien, $rol); aquí solo se dibuja.
//Con ?ver=1 (o enviado desde la pantalla) se abre en el navegador; sin eso, se descarga.
require_once __DIR__ . '/../Control/ReporteGerenciaPdfControl.php';
require_once __DIR__ . '/pdf/ReporteGerenciaPdf.php';

$pdf = new ReporteGerenciaPdf($reporte, $quien, $rol);
$pdf->armar();

//Un aviso de PHP antes del PDF lo dañaría: se descarta lo que se haya escrito
while (ob_get_level() > 0) { ob_end_clean(); }
$pdf->Output(isset($_GET['ver']) || isset($_POST['reporte']) ? 'I' : 'D', ReporteGerenciaPdf::nombreArchivo($reporte['fecha']));
