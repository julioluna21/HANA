<?php
/*
  HANA - Controlador de comunicaciones y oficios (Reporte diario)
  Permiso 11M, y solo en los proyectos de los centros asignados al usuario.
*/
session_start();
require_once __DIR__ . "/../Modelo/VacanteModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //las respuestas JSON llevan los textos como se escribieron (sin &amp;)
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

//Responde con un código de error y un mensaje en JSON, y corta la ejecución
function comError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//Si el administrador apagó este módulo (Parámetros del sistema), no se registra nada
require_once __DIR__ . '/../Modelo/HanaConfig.php';
if (!HanaConfig::modulo('COMUNICACIONES')) { comError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
hanaUsarModulo('COMUNICACIONES');
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { comError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
$idColaborador = (int)$_SESSION['Idcolaborador'];
$idUsuario     = (int)$_SESSION['Idcolaborador']; //el usuario es la persona (colaboradores)
$modeloComunicacion   = new Comunicacion();
$hoy = date('Y-m-d');
$ultimoEditable = HanaFechas::ventana()[1]; //las fechas no pueden pasar de hoy

/*
  Casillas de Comunicaciones y oficios (Permisos de usuario), en los peajes asignados a la persona:
    - 32M: registrar comunicaciones y responderlas;
    - 50M: verlas;
    - 51M: marcarlas como resueltas, reabrirlas y corregir o anular las que registraron otros.
  Quien la registró la puede corregir y anular. El ADMIN TEC (24M) todo, en todos los proyectos.
*/
if (!hanaEntra('COMUNICACIONES')) { comError(403, 'Tu rol no tiene acceso a Comunicaciones y oficios. Se da en Configuración → Permisos de usuario.'); }
$puedeLlenar  = hanaPuede('COMUNICACIONES', 'llenar');
$resuelveTodo = hanaPuede('COMUNICACIONES', 'otros');
$centros = array(); $proyectos = array();
foreach (hanaMisCentros() as $centro) {
    $centros[(int)$centro['ID_CENTRO_OP']] = $centro;
    $proyectos[(int)$centro['ID_PROYECTO']] = array('ID_PROYECTO' => (int)$centro['ID_PROYECTO'], 'NOM_PROYECTO' => $centro['NOM_PROYECTO']);
}
if (!count($proyectos)) { comError(403, 'No tienes peajes asignados. Pide que te los asignen en Configuración → Colaboradores.'); }

//¿Puede marcar como resuelta (o reabrir) una comunicación de este proyecto? (51M, en sus proyectos)
$puedeResolver = function ($idProyecto) use ($resuelveTodo, $proyectos) {
    return $resuelveTodo && isset($proyectos[(int)$idProyecto]);
};

//Trae una comunicación y revisa que esté activa y que sea de un proyecto de la persona
function comCargar($modeloComunicacion, $proyectos, $id)
{
    $comunicacion = $modeloComunicacion->mostrar($id);
    if (!$comunicacion || (int)$comunicacion['ESTADO'] !== 1 || !isset($proyectos[(int)$comunicacion['ID_PROYECTO']])) {
        comError(404, 'No se encontró la comunicación, o no es de tus proyectos.');
    }
    return $comunicacion;
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'config':
        echo hanaJsonTexto(array('hoy' => $hoy, 'ultimoEditable' => $ultimoEditable, 'proyectos' => array_values($proyectos), 'centros' => array_values($centros),
                               'tipos' => Comunicacion::$TIPOS, 'medios' => Comunicacion::$MEDIOS,
                               'pendiente' => Comunicacion::$PENDIENTE, 'diasRespuesta' => Comunicacion::diasRespuesta(),
                               'proyectosResuelve' => array_values(array_filter(array_keys($proyectos), $puedeResolver)),
                               'puedeLlenar' => $puedeLlenar),
                         JSON_UNESCAPED_UNICODE);
        break;

    //Comunicaciones del mes elegido, o las que siguen abiertas si no se elige mes
    case 'listar':
        $abiertas = !isset($_GET['mes']) || (int)$_GET['mes'] === 0;
        $desde = $hasta = '';
        if (!$abiertas) {
            $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : 0; $mes = (int)$_GET['mes'];
            if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) { comError(400, 'El periodo no es válido.'); }
            $desde = sprintf('%04d-%02d-01', $anio, $mes);
            $hasta = date('Y-m-d', strtotime("$desde +1 month"));
        }
        $ids = array_keys($proyectos);
        if (isset($_GET['proyecto']) && (int)$_GET['proyecto'] > 0) {
            $ids = isset($proyectos[(int)$_GET['proyecto']]) ? array((int)$_GET['proyecto']) : array();
        }
        $filas = $modeloComunicacion->listar($ids, $desde, $hasta, $abiertas, $hoy);
        foreach ($filas as $i => $fila) {
            //Lo que esta persona puede hacer con cada una (la pantalla muestra los botones según esto)
            $resuelve = $puedeResolver($fila['ID_PROYECTO']);
            $filas[$i]['PUEDE_RESOLVER'] = $resuelve;
            $filas[$i]['PUEDE_EDITAR'] = $resuelve || ($puedeLlenar && (int)$fila['ID_COLABORADOR_REGISTRA'] === $idColaborador);
            $filas[$i]['PUEDE_RESPONDER'] = $puedeLlenar;
        }
        echo hanaJsonTexto($filas, JSON_UNESCAPED_UNICODE);
        break;

    //La conversación de una comunicación
    case 'hilo':
        $comunicacion = comCargar($modeloComunicacion, $proyectos, isset($_GET['id']) ? (int)$_GET['id'] : 0);
        echo hanaJsonTexto(array('hilo' => $modeloComunicacion->hilo($comunicacion['ID_COMUNICACION']), 'puedeResolver' => $puedeResolver($comunicacion['ID_PROYECTO']),
                               'resuelta' => $comunicacion['FECHA_ATENCION'] !== null, 'fechaCierre' => $comunicacion['FECHA_ATENCION']), JSON_UNESCAPED_UNICODE);
        break;

    //Responde quien registra comunicaciones (32M), en sus proyectos
    case 'responder':
        if (!$puedeLlenar) { comError(403, 'Tu rol solo consulta las comunicaciones.'); }
        $comunicacion = comCargar($modeloComunicacion, $proyectos, isset($_POST['id']) ? (int)$_POST['id'] : 0);
        $texto = HanaVal::texto(isset($_POST['texto']) ? $_POST['texto'] : '', 2000);
        if ($texto === null) { comError(400, 'Escribe la respuesta.'); }
        $modeloComunicacion->agregarHilo($comunicacion['ID_COMUNICACION'], $idColaborador, 'RESPUESTA', $texto, date('Y-m-d H:i:s'));
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Respuesta agregada.'), JSON_UNESCAPED_UNICODE);
        break;

    //Marcar como resuelta o reabrir: casilla 51M, en sus proyectos
    case 'resolver':
    case 'reabrir':
        $comunicacion = comCargar($modeloComunicacion, $proyectos, isset($_POST['id']) ? (int)$_POST['id'] : 0);
        if (!$puedeResolver($comunicacion['ID_PROYECTO'])) { comError(403, 'Marcarla como resuelta necesita su casilla en Permisos de usuario.'); }
        $nota = HanaVal::texto(isset($_POST['texto']) ? $_POST['texto'] : '', 2000);
        $ahora = date('Y-m-d H:i:s');
        if ($_GET['op'] === 'resolver') {
            if ($comunicacion['FECHA_ATENCION'] !== null) { comError(400, 'Ya está resuelta.'); }
            $modeloComunicacion->resolver($comunicacion['ID_COMUNICACION'], $nota, $hoy, $ahora);
            $modeloComunicacion->agregarHilo($comunicacion['ID_COMUNICACION'], $idColaborador, 'RESUELTA', $nota, $ahora);
            $msg = 'Marcada como resuelta.';
        } else {
            if ($comunicacion['FECHA_ATENCION'] === null) { comError(400, 'Todavía está abierta.'); }
            $modeloComunicacion->reabrir($comunicacion['ID_COMUNICACION'], $ahora);
            $modeloComunicacion->agregarHilo($comunicacion['ID_COMUNICACION'], $idColaborador, 'REABIERTA', $nota, $ahora);
            $msg = 'Reabierta.';
        }
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => $msg), JSON_UNESCAPED_UNICODE);
        break;

    //Crea una comunicación nueva o guarda los cambios de una existente
    case 'guardar':
        if (!$puedeLlenar && !$resuelveTodo) { comError(403, 'Tu rol solo consulta las comunicaciones.'); }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $antes = null;
        if ($id > 0) {
            $antes = comCargar($modeloComunicacion, $proyectos, $id);
            //Los datos los cambia quien la registró o quien la puede resolver; los demás responden
            if ((int)$antes['ID_COLABORADOR_REGISTRA'] !== $idColaborador && !$puedeResolver($antes['ID_PROYECTO'])) {
                comError(403, 'Los datos los cambia quien la registró o el coordinador. Tú puedes responder en la conversación.');
            }
        }
        $datos = array();
        $datos['proyecto'] = isset($_POST['proyecto']) ? (int)$_POST['proyecto'] : 0;
        if (!isset($proyectos[$datos['proyecto']])) { comError(400, 'Elige uno de tus proyectos.'); }
        $datos['centro'] = isset($_POST['centro']) && (int)$_POST['centro'] > 0 ? (int)$_POST['centro'] : null;
        if ($datos['centro'] !== null && (!isset($centros[$datos['centro']]) || (int)$centros[$datos['centro']]['ID_PROYECTO'] !== $datos['proyecto'])) {
            comError(400, 'El centro elegido no pertenece a ese proyecto o no está asignado a tu usuario.');
        }
        $datos['tipo'] = isset($_POST['tipo']) ? $_POST['tipo'] : '';
        if (!isset(Comunicacion::$TIPOS[$datos['tipo']])) { comError(400, 'Elige si es por atender o por enviar.'); }
        $datos['medio'] = isset($_POST['medio']) ? $_POST['medio'] : '';
        if (!isset(Comunicacion::$MEDIOS[$datos['medio']])) { comError(400, 'Elige el medio por el que llegó.'); }
        $datos['fecha'] = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($datos['fecha'] === '' || $datos['fecha'] > $ultimoEditable) { comError(400, 'La fecha de recepción no es válida o es posterior a hoy.'); }
        $datos['remitente'] = HanaVal::texto(isset($_POST['remitente']) ? $_POST['remitente'] : '', 120);
        if ($datos['remitente'] === null) { comError(400, 'Escribe el remitente o el área.'); }
        $datos['asunto'] = HanaVal::texto(isset($_POST['asunto']) ? $_POST['asunto'] : '', 300);
        if ($datos['asunto'] === null) { comError(400, 'Escribe el asunto o la solicitud.'); }
        $datos['radicado'] = HanaVal::texto(isset($_POST['radicado']) ? $_POST['radicado'] : '', 40);
        $datos['pendiente'] = isset($_POST['pendiente']) && $_POST['pendiente'] !== '' ? $_POST['pendiente'] : null;
        if ($datos['pendiente'] !== null && !isset(Comunicacion::$PENDIENTE[$datos['pendiente']])) { comError(400, '"Pendiente de" no es válido.'); }
        $datos['atencion'] = null;
        if (isset($_POST['atencion']) && trim($_POST['atencion']) !== '') {
            $datos['atencion'] = HanaVal::fecha($_POST['atencion']);
            if ($datos['atencion'] === '' || $datos['atencion'] < $datos['fecha'] || $datos['atencion'] > $ultimoEditable) {
                comError(400, 'La fecha de cierre debe estar entre la fecha de recepción y hoy.');
            }
        }
        //Ya atendida, no está pendiente de nadie
        if ($datos['atencion'] !== null) { $datos['pendiente'] = null; }
        $datos['respuesta'] = HanaVal::texto(isset($_POST['respuesta']) ? $_POST['respuesta'] : '', 2000);
        //La atención (resolverla) solo la marca quien puede resolver; para los demás se conserva como estaba
        if (!$puedeResolver($datos['proyecto'])) {
            $datos['atencion']  = $antes ? $antes['FECHA_ATENCION'] : null;
            $datos['respuesta'] = $antes ? $antes['RESPUESTA'] : null;
            if ($datos['atencion'] !== null) { $datos['pendiente'] = null; }
        }

        $nuevo = $modeloComunicacion->guardar($id, $datos, $idColaborador, date('Y-m-d H:i:s'));
        if (!$nuevo) { comError(500, 'No se pudo guardar. Intenta de nuevo.'); }
        //"Respuesta o gestión" se guarda como un mensaje más de la conversación, y solo si cambió:
        //así el texto queda en un solo sitio (historial) y no repetido en la tabla del oficio
        $respAntes = $antes && isset($antes['RESPUESTA']) ? (string)$antes['RESPUESTA'] : '';
        if ($puedeResolver($datos['proyecto']) && (string)$datos['respuesta'] !== '' && (string)$datos['respuesta'] !== $respAntes) {
            $modeloComunicacion->agregarHilo($nuevo, $idColaborador, $datos['atencion'] !== null ? 'RESUELTA' : 'RESPUESTA', $datos['respuesta'], date('Y-m-d H:i:s'));
        }
        echo hanaJsonTexto(array('ok' => true, 'id' => $nuevo, 'mensaje' => $id ? 'Comunicación actualizada.' : 'Comunicación registrada.'),
                         JSON_UNESCAPED_UNICODE);
        break;

    //Anula una comunicación (quien la registró, o quien tenga la casilla 51M)
    case 'anular':
        $comunicacion = comCargar($modeloComunicacion, $proyectos, isset($_POST['id']) ? (int)$_POST['id'] : 0);
        if (!($puedeLlenar && (int)$comunicacion['ID_COLABORADOR_REGISTRA'] === $idColaborador) && !$puedeResolver($comunicacion['ID_PROYECTO'])) {
            comError(403, 'Solo quien la registró puede anularla.');
        }
        $motivo = HanaVal::texto(isset($_POST['motivo']) ? $_POST['motivo'] : '', 300);
        if ($motivo === null) { comError(400, 'Explica por qué se anula.'); }
        if (!$modeloComunicacion->anular($comunicacion['ID_COMUNICACION'], $motivo)) { comError(500, 'No se pudo anular. Intenta de nuevo.'); }
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Comunicación anulada.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        comError(400, 'Operación no reconocida.');
}
