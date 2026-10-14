<?php
//Reporte diario - Descargar el arqueo en PDF
//Con ?ver=1 se abre en el navegador; sin eso, se descarga.
//El controlador revisa el acceso y trae el arqueo ($arqueo); aquí solo se dibuja
require_once __DIR__ . '/../Control/ArqueoPdfControl.php';
require_once __DIR__ . '/pdf/ArqueoPdf.php';

$pdf = new ArqueoPdf();
$pdf->SetTitle('Arqueo ' . $arqueo['NOM_CENTRO_OP'] . ' ' . $arqueo['FECHA'], true);
$pdf->SetAuthor('HANA - Grupo Regency', true);
if ($arqueo['TIPO'] === 'CAJA_MENOR') { $pdf->cajaMenor($arqueo); }
elseif ($arqueo['TIPO'] === 'CASETA') { $pdf->caseta($arqueo); } //arqueo de caseta, con los campos del peaje
else { $pdf->recambio($arqueo); }

//Un aviso de PHP antes del PDF lo dañaría: se descarta lo que se haya escrito
while (ob_get_level() > 0) { ob_end_clean(); }
$pdf->Output(isset($_GET['ver']) ? 'I' : 'D', ArqueoPdf::nombreArchivo($arqueo));
