<?php
/*
  HANA — Estado del día de quien tiene la sesión (alertas del reporte diario)
    op=estado        lo del día: Hoy en qué estás, listas, arqueos, cronograma
    op=visto&modulo  deja constancia de que abrió una pantalla (el cronograma)
*/
session_start();
require_once __DIR__ . "/../Modelo/EstadoDiaModelo.php";
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) {
    http_response_code(401);
    echo json_encode(array('error' => 'Tu sesión terminó. Vuelve a iniciar sesión.'), JSON_UNESCAPED_UNICODE);
    exit;
}
$E = new EstadoDia();
$idColaborador = (int)$_SESSION['Idcolaborador'];

switch (isset($_GET['op']) ? $_GET['op'] : '') {
    case 'estado':
        echo json_encode(array('fecha' => date('Y-m-d'), 'estado' => $E->resumen($idColaborador, date('Y-m-d'))), JSON_UNESCAPED_UNICODE);
        break;

    case 'visto':
        //Solo los módulos que se revisan (no se llenan) llevan constancia de visita
        $modulo = isset($_POST['modulo']) ? $_POST['modulo'] : '';
        if (!in_array($modulo, array('CRONOGRAMA'), true)) { http_response_code(400); echo json_encode(array('error' => 'Módulo no válido.')); exit; }
        $E->visto($idColaborador, $modulo, date('Y-m-d H:i:s'));
        echo json_encode(array('ok' => true));
        break;

    default:
        http_response_code(400);
        echo json_encode(array('error' => 'Operación no reconocida.'));
}
