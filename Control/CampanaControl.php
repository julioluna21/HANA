<?php
//Controlador de la campana de notificaciones
session_start();
require_once __DIR__ . "/../Modelo/CampanaModelo.php";
require_once __DIR__ . "/AccesoHelper.php"; //para saber el rol de la persona

//Siempre se responde en JSON y en UTF-8, para que las tildes lleguen bien
header('Content-Type: application/json; charset=utf-8');

//Sin sesión no hay notificaciones que mostrar. Se responde 401 para que la
//pantalla sepa que debe volver a iniciar sesión, en vez de quedarse vacía
if (!isset($_SESSION['IdUsuarios']) || !isset($_SESSION['Idcolaborador'])) {
    http_response_code(401);
    echo json_encode(array('error' => 'sesion'));
    exit;
}

$idUsuario     = $_SESSION['IdUsuarios'];
$idColaborador = $_SESSION['Idcolaborador'];
$idRol         = hanaIdRol();
$aprueba       = hanaTienePermiso(PERMISO_APROBAR_RQ); //le llegan las RQ pendientes de aprobación
$campana       = new Campana();

//Convierte un resultado de MySQL en un arreglo de filas
function hanaFilas($rspta)
{
    $filas = array();
    if ($rspta) {
        while ($f = $rspta->fetch_assoc()) { $filas[] = $f; }
    }
    return $filas;
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //-----------------------------------------------------------------------
    // Lo que muestra la campana: conteo por color y las últimas sin abrir
    //-----------------------------------------------------------------------
    case 'resumen':
        $conteo = array('alta' => 0, 'media' => 0, 'baja' => 0);
        foreach (hanaFilas($campana->conteo($idUsuario, $idColaborador, $idRol, $aprueba)) as $f) {
            if (isset($conteo[$f['prioridad']])) {
                $conteo[$f['prioridad']] = intval($f['cantidad']);
            }
        }
        echo json_encode(array(
            'conteo' => $conteo,
            'total'  => $conteo['alta'] + $conteo['media'] + $conteo['baja'],
            //Solo las 8 más importantes: el menú desplegable no debe crecer sin fin
            'items'  => hanaFilas($campana->lista($idUsuario, $idColaborador, $idRol, true, 8, $aprueba))
        ), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // La pantalla completa: leídas y sin leer, de las últimas semanas
    //-----------------------------------------------------------------------
    case 'lista':
        echo json_encode(hanaFilas($campana->lista($idUsuario, $idColaborador, $idRol, false, 200, $aprueba)),
                         JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Marcar una como leída (al hacer clic en ella)
    //-----------------------------------------------------------------------
    case 'leer':
        //El aviso puede ser de una novedad o de una RQ
        $tipo = (isset($_POST['tipo']) && $_POST['tipo'] === 'rq') ? 'rq' : 'novedad';
        $id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_POST['idNovedad']) ? intval($_POST['idNovedad']) : 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(array('error' => 'Falta el aviso'));
            break;
        }
        echo json_encode(array('ok' => (bool)$campana->leer($idUsuario, $tipo, $id)));
        break;

    //-----------------------------------------------------------------------
    // Marcar todas como leídas
    //-----------------------------------------------------------------------
    case 'leerTodas':
        echo json_encode(array('ok' => (bool)$campana->leerTodas($idUsuario, $idColaborador, $idRol, $aprueba)));
        break;

    default:
        http_response_code(400);
        echo json_encode(array('error' => 'Operacion no reconocida'));
}
