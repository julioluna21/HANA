<?php
/*
  HANA — Controlador de comunicaciones y oficios (Reporte diario, Fase 2)
  Permiso 11M, y solo en los proyectos de los centros asignados al usuario.
*/
session_start();
require_once __DIR__ . "/../Modelo/VacanteModelo.php";
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

function comError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//Si el administrador apagó este módulo (Parámetros del sistema), no se registra nada
require_once __DIR__ . '/../Modelo/HanaConfig.php';
if (!HanaConfig::modulo('COMUNICACIONES')) { comError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
HanaDB::usarModulo('COMUNICACIONES'); //la casilla COMUNICACIONES de Roles da acceso en todos los proyectos
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { comError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
$idColaborador = (int)$_SESSION['Idcolaborador'];
$idUsuario     = (int)$_SESSION['IdUsuarios'];
$M   = new Comunicacion();
$hoy = date('Y-m-d');
$ultimoEditable = HanaFechas::ventana()[1]; //las fechas se pueden adelantar hasta mañana

/*
  Oficios como las RQ: cualquiera los crea y los responde, pero solo en SUS proyectos:
    - los que coordina (o todos, si es ADMIN TEC o tiene 32M);
    - los de los peajes de los que es jefe (Centros de operación);
    - los de los peajes que tiene asignados en Usuarios.
  Marcar como resuelta o reabrir: el coordinador del proyecto, el ADMIN TEC o quien tenga 32M.
*/
$resuelveTodo = hanaTienePermiso('24M') || hanaTienePermiso('32M');
$centros = array(); $proyectos = array();
$agregar = function ($filas) use (&$centros, &$proyectos) {
    foreach ((array)$filas as $c) {
        $centros[(int)$c['ID_CENTRO_OP']] = $c;
        $proyectos[(int)$c['ID_PROYECTO']] = array('ID_PROYECTO' => (int)$c['ID_PROYECTO'], 'NOM_PROYECTO' => $c['NOM_PROYECTO']);
    }
};
$agregar(HanaDB::centrosCoordinador($idColaborador)); //los que coordina (todos si ADMIN TEC o 32M)
$sel = "SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.ID_PROYECTO, p.NOM_PROYECTO
          FROM centros_operacion c INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
         WHERE p.Estado = '1' AND c.Estado = '1' AND ";
$agregar(HanaDB::q($sel . "c.ID_COLABORADOR_JEFE = ?", 'i', array($idColaborador))); //los peajes de los que es jefe
$agregar(HanaDB::q($sel . "c.ID_CENTRO_OP IN (SELECT ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP FROM asoc_usuarios_sistemas_x_cop
                                               WHERE ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = ?)", 'i', array($idUsuario))); //asignados en Usuarios
if (!count($proyectos)) { comError(403, 'No tienes proyectos asignados. Pide que te asignen un peaje en Configuración → Usuarios o Centros de operación.'); }

//¿Puede marcar como resuelta (o reabrir) una comunicación de este proyecto?
$puedeResolver = function ($idProyecto) use ($resuelveTodo, $idColaborador) {
    if ($resuelveTodo) { return true; }
    $p = HanaDB::fila("SELECT ID_COLABORADOR_COORDINADOR AS C FROM proyectos WHERE ID_PROYECTO = ?", 'i', array((int)$idProyecto));
    return $p && (int)$p['C'] === $idColaborador;
};

function comCargar($M, $proyectos, $id)
{
    $m = $M->mostrar($id);
    if (!$m || (int)$m['ESTADO'] !== 1 || !isset($proyectos[(int)$m['ID_PROYECTO']])) {
        comError(404, 'No se encontró la comunicación, o no es de tus proyectos.');
    }
    return $m;
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'config':
        //El nombre de quien entra, para proponerlo como responsable
        $yo = HanaDB::fila("SELECT NOM_COLABORADOR FROM colaboradores WHERE ID_COLABORADOR = ?", 'i', array($idColaborador));
        echo json_encode(array('hoy' => $hoy, 'ultimoEditable' => $ultimoEditable, 'proyectos' => array_values($proyectos), 'centros' => array_values($centros),
                               'tipos' => Comunicacion::$TIPOS, 'medios' => Comunicacion::$MEDIOS,
                               'pendiente' => Comunicacion::$PENDIENTE, 'diasRespuesta' => Comunicacion::diasRespuesta(),
                               'miNombre' => $yo ? html_entity_decode($yo['NOM_COLABORADOR'], ENT_QUOTES, 'UTF-8') : '',
                               'proyectosResuelve' => array_values(array_filter(array_keys($proyectos), $puedeResolver))),
                         JSON_UNESCAPED_UNICODE);
        break;

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
        $filas = $M->listar($ids, $desde, $hasta, $abiertas, $hoy);
        foreach ($filas as $i => $f) {
            //Lo que esta persona puede hacer con cada una (la pantalla muestra los botones según esto)
            $resuelve = $resuelveTodo || (int)$f['COORDINADOR_PROYECTO'] === $idColaborador;
            $filas[$i]['PUEDE_RESOLVER'] = $resuelve;
            $filas[$i]['PUEDE_EDITAR'] = $resuelve || (int)$f['ID_COLABORADOR_REGISTRA'] === $idColaborador;
        }
        echo json_encode($filas, JSON_UNESCAPED_UNICODE);
        break;

    //La conversación de una comunicación
    case 'hilo':
        $m = comCargar($M, $proyectos, isset($_GET['id']) ? (int)$_GET['id'] : 0);
        echo json_encode(array('hilo' => $M->hilo($m['ID_COMUNICACION']), 'puedeResolver' => $puedeResolver($m['ID_PROYECTO']),
                               'resuelta' => $m['FECHA_ATENCION'] !== null), JSON_UNESCAPED_UNICODE);
        break;

    //Cualquiera con el proyecto asignado responde
    case 'responder':
        $m = comCargar($M, $proyectos, isset($_POST['id']) ? (int)$_POST['id'] : 0);
        $texto = HanaVal::texto(isset($_POST['texto']) ? $_POST['texto'] : '', 2000);
        if ($texto === null) { comError(400, 'Escribe la respuesta.'); }
        $M->agregarHilo($m['ID_COMUNICACION'], $idColaborador, 'RESPUESTA', $texto, date('Y-m-d H:i:s'));
        echo json_encode(array('ok' => true, 'mensaje' => 'Respuesta agregada.'), JSON_UNESCAPED_UNICODE);
        break;

    //El coordinador del proyecto, el ADMIN TEC o 32M: marcar como resuelta o reabrir
    case 'resolver':
    case 'reabrir':
        $m = comCargar($M, $proyectos, isset($_POST['id']) ? (int)$_POST['id'] : 0);
        if (!$puedeResolver($m['ID_PROYECTO'])) { comError(403, 'La marca como resuelta el coordinador del proyecto o el administrador.'); }
        $nota = HanaVal::texto(isset($_POST['texto']) ? $_POST['texto'] : '', 2000);
        $ahora = date('Y-m-d H:i:s');
        if ($_GET['op'] === 'resolver') {
            if ($m['FECHA_ATENCION'] !== null) { comError(400, 'Ya está resuelta.'); }
            $M->resolver($m['ID_COMUNICACION'], $nota, $hoy, $ahora);
            $M->agregarHilo($m['ID_COMUNICACION'], $idColaborador, 'RESUELTA', $nota, $ahora);
            $msg = 'Marcada como resuelta.';
        } else {
            if ($m['FECHA_ATENCION'] === null) { comError(400, 'Todavía está abierta.'); }
            $M->reabrir($m['ID_COMUNICACION'], $ahora);
            $M->agregarHilo($m['ID_COMUNICACION'], $idColaborador, 'REABIERTA', $nota, $ahora);
            $msg = 'Reabierta.';
        }
        echo json_encode(array('ok' => true, 'mensaje' => $msg), JSON_UNESCAPED_UNICODE);
        break;

    case 'guardar':
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $antes = null;
        if ($id > 0) {
            $antes = comCargar($M, $proyectos, $id);
            //Los datos los cambia quien la registró o quien la puede resolver; los demás responden
            if ((int)$antes['ID_COLABORADOR_REGISTRA'] !== $idColaborador && !$puedeResolver($antes['ID_PROYECTO'])) {
                comError(403, 'Los datos los cambia quien la registró o el coordinador. Tú puedes responder en la conversación.');
            }
        }
        $d = array();
        $d['proyecto'] = isset($_POST['proyecto']) ? (int)$_POST['proyecto'] : 0;
        if (!isset($proyectos[$d['proyecto']])) { comError(400, 'Elige uno de tus proyectos.'); }
        $d['centro'] = isset($_POST['centro']) && (int)$_POST['centro'] > 0 ? (int)$_POST['centro'] : null;
        if ($d['centro'] !== null && (!isset($centros[$d['centro']]) || (int)$centros[$d['centro']]['ID_PROYECTO'] !== $d['proyecto'])) {
            comError(400, 'El centro elegido no pertenece a ese proyecto o no está asignado a tu usuario.');
        }
        $d['tipo'] = isset($_POST['tipo']) ? $_POST['tipo'] : '';
        if (!isset(Comunicacion::$TIPOS[$d['tipo']])) { comError(400, 'Elige si es por atender o por enviar.'); }
        $d['medio'] = isset($_POST['medio']) ? $_POST['medio'] : '';
        if (!isset(Comunicacion::$MEDIOS[$d['medio']])) { comError(400, 'Elige el medio por el que llegó.'); }
        $d['fecha'] = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($d['fecha'] === '' || $d['fecha'] > $ultimoEditable) { comError(400, 'La fecha de recepción no es válida o es posterior a mañana.'); }
        $d['remitente'] = HanaVal::texto(isset($_POST['remitente']) ? $_POST['remitente'] : '', 120);
        if ($d['remitente'] === null) { comError(400, 'Escribe el remitente o el área.'); }
        $d['asunto'] = HanaVal::texto(isset($_POST['asunto']) ? $_POST['asunto'] : '', 300);
        if ($d['asunto'] === null) { comError(400, 'Escribe el asunto o la solicitud.'); }
        $d['responsable'] = HanaVal::texto(isset($_POST['responsable']) ? $_POST['responsable'] : '', 120);
        if ($d['responsable'] === null) { comError(400, 'Escribe quién es el responsable.'); }
        $d['radicado'] = HanaVal::texto(isset($_POST['radicado']) ? $_POST['radicado'] : '', 40);
        $d['pendiente'] = isset($_POST['pendiente']) && $_POST['pendiente'] !== '' ? $_POST['pendiente'] : null;
        if ($d['pendiente'] !== null && !isset(Comunicacion::$PENDIENTE[$d['pendiente']])) { comError(400, '"Pendiente de" no es válido.'); }
        $d['atencion'] = null;
        if (isset($_POST['atencion']) && trim($_POST['atencion']) !== '') {
            $d['atencion'] = HanaVal::fecha($_POST['atencion']);
            if ($d['atencion'] === '' || $d['atencion'] < $d['fecha'] || $d['atencion'] > $ultimoEditable) {
                comError(400, 'La fecha de atención debe estar entre la fecha de recepción y mañana.');
            }
        }
        //Ya atendida, no está pendiente de nadie
        if ($d['atencion'] !== null) { $d['pendiente'] = null; }
        $d['respuesta'] = HanaVal::texto(isset($_POST['respuesta']) ? $_POST['respuesta'] : '', 2000);
        //La atención (resolverla) solo la marca quien puede resolver; para los demás se conserva como estaba
        if (!$puedeResolver($d['proyecto'])) {
            $d['atencion']  = $antes ? $antes['FECHA_ATENCION'] : null;
            $d['respuesta'] = $antes ? $antes['RESPUESTA'] : null;
            if ($d['atencion'] !== null) { $d['pendiente'] = null; }
        }

        $nuevo = $M->guardar($id, $d, $idColaborador, date('Y-m-d H:i:s'));
        if (!$nuevo) { comError(500, 'No se pudo guardar. Intenta de nuevo.'); }
        echo json_encode(array('ok' => true, 'id' => $nuevo, 'mensaje' => $id ? 'Comunicación actualizada.' : 'Comunicación registrada.'),
                         JSON_UNESCAPED_UNICODE);
        break;

    case 'anular':
        $m = comCargar($M, $proyectos, isset($_POST['id']) ? (int)$_POST['id'] : 0);
        if ((int)$m['ID_COLABORADOR_REGISTRA'] !== $idColaborador && !hanaTienePermiso('5M')) {
            comError(403, 'Solo quien la registró puede anularla.');
        }
        $motivo = HanaVal::texto(isset($_POST['motivo']) ? $_POST['motivo'] : '', 300);
        if ($motivo === null) { comError(400, 'Explica por qué se anula.'); }
        if (!$M->anular($m['ID_COMUNICACION'], $motivo)) { comError(500, 'No se pudo anular. Intenta de nuevo.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Comunicación anulada.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        comError(400, 'Operación no reconocida.');
}
