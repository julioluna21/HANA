<?php
/*
  HANA - Campana de notificaciones (controlador)
  ---------------------------------------------------------------------------
  Atiende a Ajax/CampanaAjax.js: cuántos avisos hay sin abrir, la lista de
  avisos y marcarlos como leídos. Los avisos los arma Modelo/CampanaModelo.php.
*/
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

$idUsuario     = $_SESSION['Idcolaborador']; //el usuario es la persona (colaboradores)
$idColaborador = $_SESSION['Idcolaborador'];
$idRol         = hanaIdRol();
$aprueba       = hanaTienePermiso(PERMISO_APROBAR_RQ); //le llegan las RQ pendientes de aprobación
$campana       = new Campana();
$campana->enMisCentros = 'hanaSqlMisCentros'; //solo lo de sus peajes asignados

//Convierte un resultado de MySQL en un arreglo de filas
function hanaFilas($resultado)
{
    $filas = array();
    if ($resultado) {
        while ($fila = $resultado->fetch_assoc()) { $filas[] = $fila; }
    }
    return $filas;
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //-----------------------------------------------------------------------
    // Lo que muestra la campana: conteo por color y las últimas sin abrir
    //-----------------------------------------------------------------------
    case 'resumen':
        $conteo = array('alta' => 0, 'media' => 0, 'baja' => 0);
        foreach (hanaFilas($campana->conteo($idUsuario, $idColaborador, $idRol, $aprueba)) as $fila) {
            if (isset($conteo[$fila['prioridad']])) {
                $conteo[$fila['prioridad']] = intval($fila['cantidad']);
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
    // La pantalla completa con filtros: fecha, tipo, prioridad, peaje, leídas
    // o no y texto (Modelo/CampanaModelo.php → listaFiltrada)
    //-----------------------------------------------------------------------
    case 'filtrar':
        $filtros = array();
        foreach (array('desde', 'hasta', 'tipo', 'prioridad', 'centro', 'estado', 'texto') as $campo) {
            $filtros[$campo] = isset($_GET[$campo]) ? (string)$_GET[$campo] : '';
        }
        $resultado = $campana->listaFiltrada($idUsuario, $idColaborador, $idRol, $aprueba, $filtros);
        $resultado['limite'] = 500;
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Marcar una como leída (al hacer clic en ella)
    //-----------------------------------------------------------------------
    case 'leer':
        //El aviso puede ser de una novedad, de una RQ o de la promesa de valor
        $tipo = (isset($_POST['tipo']) && in_array($_POST['tipo'], array('rq', 'promesa'), true)) ? $_POST['tipo'] : 'novedad';
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
        echo json_encode(array('error' => 'Operación no reconocida'), JSON_UNESCAPED_UNICODE);
}
