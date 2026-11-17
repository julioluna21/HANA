<?php
/*
  HANA - Avisos por respuesta de las preguntas (Editar listas)
  ---------------------------------------------------------------------------
    op=obtener&pregunta=ID   el aviso de una pregunta, sus respuestas posibles,
                             y las personas y roles para elegir
    op=guardar (POST)        pregunta, on (1/0), respuestas[], personas[], roles[]

  Lo cambia quien edita las listas (12M) o el ADMIN TEC (24M).
  Cómo se guarda y cómo se envía: Modelo/AvisoRespuestaModelo.php y
  Control/EnvioNotificacionesControl.php (hanaNotificarPorRespuesta).
*/
session_start();
require_once __DIR__ . '/Guardia.php';
hanaGuardia(array('12M', '24M'));
require_once __DIR__ . '/../Conexion/ConexionDB.php';
require_once __DIR__ . '/../Modelo/AvisoRespuestaModelo.php';
require_once __DIR__ . '/../Modelo/HanaTexto.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

function avisoError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

$idColaborador = isset($_SESSION['Idcolaborador']) ? (int)$_SESSION['Idcolaborador'] : 0;
$idPregunta = isset($_REQUEST['pregunta']) ? (int)$_REQUEST['pregunta'] : 0;
$pregunta = $idPregunta > 0 ? AvisoRespuesta::pregunta($idPregunta) : null;
if (!$pregunta) { avisoError(404, 'Esa pregunta no existe.'); }
$opciones = AvisoRespuesta::respuestasDeTipo($pregunta['TIPO_RESPUESTA']);

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'obtener':
        echo hanaJsonTexto(array(
            'pregunta'  => $pregunta,
            'opciones'  => $opciones,             //vacío: el tipo de respuesta no tiene opciones fijas
            'aviso'     => AvisoRespuesta::obtener($idPregunta),
            'personas'  => AvisoRespuesta::personas(),
            'roles'     => AvisoRespuesta::roles()
        ), JSON_UNESCAPED_UNICODE);
        break;

    case 'guardar':
        if (!count($opciones)) { avisoError(400, 'Los avisos son para preguntas de Sí/No o de Lista: las demás no tienen respuestas fijas.'); }
        $on = isset($_POST['on']) && (string)$_POST['on'] === '1';
        $respuestas = array();
        foreach ((array)(isset($_POST['respuestas']) ? $_POST['respuestas'] : array()) as $valor) {
            if (isset($opciones[(string)$valor])) { $respuestas[] = (string)$valor; }
        }
        $personas = array();
        $validas = array();
        foreach (AvisoRespuesta::personas() as $persona) { $validas[(int)$persona['id']] = true; }
        foreach ((array)(isset($_POST['personas']) ? $_POST['personas'] : array()) as $id) { if (isset($validas[(int)$id])) { $personas[] = (int)$id; } }
        $roles = array();
        $rolesValidos = array();
        foreach (AvisoRespuesta::roles() as $rol) { $rolesValidos[(int)$rol['id']] = true; }
        foreach ((array)(isset($_POST['roles']) ? $_POST['roles'] : array()) as $id) { if (isset($rolesValidos[(int)$id])) { $roles[] = (int)$id; } }
        $personas = array_values(array_unique($personas)); $roles = array_values(array_unique($roles));
        if ($on && !count($respuestas)) { avisoError(400, 'Elige con qué respuesta se envía el aviso.'); }
        if ($on && !count($personas) && !count($roles)) { avisoError(400, 'Elige al menos una persona o un rol que reciba el aviso.'); }
        $resultado = AvisoRespuesta::guardar($idPregunta, $on, $respuestas, $personas, $roles, $idColaborador, date('Y-m-d H:i:s'));
        if ($resultado !== true) { avisoError(400, $resultado); }
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => $on ? 'Aviso guardado. Llegará cada vez que respondan eso en esta pregunta.' : 'Aviso apagado. Ya no se envía.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        avisoError(400, 'Operación no válida.');
}
