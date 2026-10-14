<?php
//Reporte diario - Informe de gestión en PDF (un mes, varios meses, una quincena o un rango de fechas)
//Con ?ver=1 se abre en el navegador; sin eso, se descarga.
//El controlador revisa el permiso, valida el periodo y trae los datos ($datos, $quien); aquí solo se dibuja
require_once __DIR__ . '/../Control/InformeMensualPdfControl.php';
require_once __DIR__ . '/pdf/InformeMensualPdf.php';

$pdf = new InformeMensualPdf($datos, $quien);
$pdf->armar();

//Un aviso de PHP antes del PDF lo dañaría: se descarta lo que se haya escrito
while (ob_get_level() > 0) { ob_end_clean(); }
$pdf->Output(isset($_GET['ver']) ? 'I' : 'D', InformeMensualPdf::nombreArchivo($datos['periodo']));
