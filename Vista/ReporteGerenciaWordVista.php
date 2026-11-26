<?php
//Consultas - Reporte a la Gerencia en Word (.docx)
//El controlador revisa el permiso y arma los datos ($reporte, $quien), los mismos del PDF; aquí se arma el Word.
//Siempre se descarga: el navegador no lo puede mostrar.
require_once __DIR__ . '/../Control/ReporteGerenciaPdfControl.php';
require_once __DIR__ . '/word/ReporteGerenciaWord.php';

$word = new ReporteGerenciaWord($reporte, $quien);
$bytes = $word->armar();

//Un aviso de PHP antes del archivo lo dañaría: se descarta lo que se haya escrito
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . ReporteGerenciaWord::nombreArchivo($reporte['fecha'], $reporte['desde']) . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $bytes;
