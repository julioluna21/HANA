<?php
/*
  HANA - Estado del día de quien tiene la sesión (alertas del reporte diario)
  ---------------------------------------------------------------------------
    op=estado        lo del día: Hoy en qué estás, listas, arqueos, cronograma
    op=visto&modulo  deja constancia de que abrió una pantalla (cronograma, vacantes
                     o comunicaciones; el recordatorio de las 9 a. m. lo tiene en cuenta)
*/
session_start();
require_once __DIR__ . "/../Modelo/EstadoDiaModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //las respuestas JSON llevan los textos como se escribieron (sin &amp;)
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) {
    http_response_code(401);
    echo hanaJsonTexto(array('error' => 'Tu sesión terminó. Vuelve a iniciar sesión.'), JSON_UNESCAPED_UNICODE);
    exit;
}
$estadoDia = new EstadoDia();
$idColaborador = (int)$_SESSION['Idcolaborador'];

switch (isset($_GET['op']) ? $_GET['op'] : '') {
    //Cómo va hoy la persona de la sesión: qué ha registrado y qué le falta
    case 'estado':
        echo hanaJsonTexto(array('fecha' => date('Y-m-d'), 'estado' => $estadoDia->resumen($idColaborador, date('Y-m-d'))), JSON_UNESCAPED_UNICODE);
        break;

    //Deja constancia de que la persona abrió una pantalla hoy
    case 'visto':
        //Solo los módulos que se revisan (no se llenan) llevan constancia de visita
        $modulo = isset($_POST['modulo']) ? $_POST['modulo'] : '';
        if (!in_array($modulo, array('CRONOGRAMA', 'VACANTES', 'COMUNICACIONES'), true)) { http_response_code(400); echo hanaJsonTexto(array('error' => 'Módulo no válido.')); exit; }
        $estadoDia->visto($idColaborador, $modulo, date('Y-m-d H:i:s'));
        echo hanaJsonTexto(array('ok' => true));
        break;

    default:
        http_response_code(400);
        echo hanaJsonTexto(array('error' => 'Operación no reconocida.'));
}
