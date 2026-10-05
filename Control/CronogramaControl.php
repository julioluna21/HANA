<?php
/*
  HANA — Controlador del cronograma y el vehículo (Reporte diario, Fase 2)
    - Cada persona planea y marca SU cronograma (11M). El colaborador sale de
      la sesión, nunca de la pantalla.
    - El estado del vehículo lo registra su responsable, o quien administra (5M).
    - El catálogo de vehículos lo maneja quien administra centros (5M).
*/
session_start();
require_once __DIR__ . "/../Modelo/CronogramaModelo.php";
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/../Modelo/HanaConfig.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

//Lo pasado se marca hasta ayer (HanaFechas::MARGEN_DIAS). Planear sí se puede
//con más anticipación: para eso es el cronograma
define('CRONO_MESES_ADELANTE', 12); //se planea hasta un año adelante

function cronoError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//Si el administrador apagó este módulo (Parámetros del sistema), no se registra nada
require_once __DIR__ . '/../Modelo/HanaConfig.php';
if (!HanaConfig::modulo('CRONOGRAMA')) { cronoError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
HanaDB::usarModulo('CRONOGRAMA'); //la casilla CRONOGRAMA de Roles da acceso en todos los proyectos
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { cronoError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
//El cronograma lo llena el coordinador; el catálogo de vehículos lo maneja quien
//administra centros (5M); el jefe mayor (20M) y la administración (21M) consultan todos
$esCoordinador = HanaDB::esCoordinador((int)$_SESSION['Idcolaborador']);
//22M: consultar todos los proyectos (por ejemplo, un coordinador que ve los de los demás)
//30M (Cronograma y vehículo en Roles): consultar el de todos los proyectos (cada coordinador llena el suyo)
$verTodos      = hanaTienePermiso('20M') || hanaTienePermiso('21M') || hanaTienePermiso('22M') || hanaTienePermiso('30M');
if (!$esCoordinador && !hanaTienePermiso('5M') && !$verTodos) { cronoError(403, 'El cronograma lo llena el coordinador del proyecto. Si eres coordinador, pide que te asignen en Configuración → Proyectos.'); }

$idUsuario     = (int)$_SESSION['IdUsuarios'];
$idColaborador = (int)$_SESSION['Idcolaborador'];
$usaCrono      = $esCoordinador;
$adminVh       = hanaTienePermiso('5M');
$C             = new Cronograma();
$hoy           = date('Y-m-d');
list($primerEditable, $ultimoEditable) = HanaFechas::ventana();
$ultimoPlan    = date('Y-m-d', strtotime('+' . CRONO_MESES_ADELANTE . ' months'));

//El vehículo pedido, solo si esta persona lo puede reportar
function cronoVehiculo($C, $idVehiculo, $idColaborador, $adminVh)
{
    foreach ($C->vehiculosDe($idColaborador, $adminVh) as $v) {
        if ((int)$v['ID_VEHICULO'] === (int)$idVehiculo) { return $v; }
    }
    return null;
}

//Un ítem del cronograma de esta persona, que todavía se pueda modificar
function cronoItemPropio($C, $idItem, $idColaborador, $primerEditable)
{
    $it = $C->item($idItem);
    if (!$it || (int)$it['ID_COLABORADOR'] !== $idColaborador) { cronoError(404, 'No se encontró esa actividad en tu cronograma.'); }
    if ($it['FECHA'] < $primerEditable) { cronoError(400, 'Ese día ya no se puede modificar.'); }
    return $it;
}

//Los proyectos que esta persona puede ver en el cronograma: los que coordina y,
//con 20M o 21M, todos. Cada uno con su coordinador, sus centros y sus vehículos.
//Solo el cronograma propio se edita; el de otro proyecto queda en consulta
function cronoProyectos($C, $idColaborador, $verTodos)
{
    $f = HanaDB::q("SELECT p.ID_PROYECTO, p.NOM_PROYECTO, p.ID_COLABORADOR_COORDINADOR, col.NOM_COLABORADOR AS COORDINADOR
                      FROM proyectos p LEFT JOIN colaboradores col ON col.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR
                     WHERE p.Estado = '1' AND (? = 1 OR p.ID_COLABORADOR_COORDINADOR = ?)
                     ORDER BY p.NOM_PROYECTO", 'ii', array($verTodos ? 1 : 0, (int)$idColaborador));
    $res = array();
    foreach ((array)$f as $p) {
        $id = (int)$p['ID_PROYECTO'];
        $coord = (int)$p['ID_COLABORADOR_COORDINADOR'];
        $p['EDITABLE'] = $coord > 0 && $coord === (int)$idColaborador;
        $cen = HanaDB::q("SELECT ID_CENTRO_OP, NOM_CENTRO_OP, TIPO_CENTRO FROM centros_operacion
                           WHERE Estado = '1' AND ID_PROYECTO_CENTRO_OP = ? ORDER BY NOM_CENTRO_OP", 'i', array($id));
        $p['CENTROS'] = $cen ? $cen : array();
        $p['VEHICULOS'] = $C->vehiculosProyecto($id, $coord);
        $res[$id] = $p;
    }
    return $res;
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'config':
        $proyectos = cronoProyectos($C, $idColaborador, $verTodos);
        //Arranca en el proyecto que coordina; si no coordina ninguno, en el primero que ve
        $inicial = 0;
        foreach ($proyectos as $id => $p) { if ($p['EDITABLE']) { $inicial = $id; break; } }
        if (!$inicial && count($proyectos)) { $inicial = (int)key($proyectos); }
        echo json_encode(array(
            'hoy' => $hoy, 'primerEditable' => $primerEditable, 'ultimoEditable' => $ultimoEditable, 'ultimoPlan' => $ultimoPlan,
            'tipos' => Cronograma::$TIPOS, 'estadosVh' => Cronograma::$ESTADOS_VH, 'transportes' => Cronograma::$TRANSPORTES,
            'proyectos' => array_values($proyectos), 'proyectoInicial' => $inicial,
            'centros' => HanaDB::centrosCoordinador($idColaborador), //para planear visitas: los de sus proyectos
            'usaCrono' => $usaCrono, 'adminVh' => $adminVh
        ), JSON_UNESCAPED_UNICODE);
        break;

    //El mes (op=mes) o los próximos 15 días desde hoy (op=proximos): lo
    //planeado, la situación de cada día y el estado del vehículo
    case 'mes':
    case 'proximos':
        if ($_GET['op'] === 'proximos') {
            $desde = $hoy;
            $hasta = date('Y-m-d', strtotime('+15 days'));
        } else {
            $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
            $mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
            if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) { cronoError(400, 'El mes no es válido.'); }
            $desde = sprintf('%04d-%02d-01', $anio, $mes);
            $hasta = date('Y-m-d', strtotime("$desde +1 month"));
        }

        //El proyecto que se está viendo: su coordinador es la persona del cronograma
        $proyectos = cronoProyectos($C, $idColaborador, $verTodos);
        $idProy = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
        if (!isset($proyectos[$idProy])) { cronoError(403, 'Ese proyecto no está entre los que puedes ver.'); }
        $proy = $proyectos[$idProy];
        $persona = (int)$proy['ID_COLABORADOR_COORDINADOR'];
        $conPersona = $persona > 0;

        //El vehículo tiene que ser de ese proyecto
        $vh = array();
        $idVh = isset($_GET['vehiculo']) ? (int)$_GET['vehiculo'] : 0;
        foreach ($proy['VEHICULOS'] as $v) { if ((int)$v['ID_VEHICULO'] === $idVh) { $vh = $C->vehiculoDias($idVh, $desde, $hasta); break; } }

        echo json_encode(array(
            'editable'    => $proy['EDITABLE'],
            'coordinador' => $proy['COORDINADOR'],
            'visitados'   => $conPersona ? (object)$C->centrosVisitados($persona, $desde, $hasta) : new stdClass(),
            'desde'       => $desde,
            'hasta'       => $hasta, //sin incluir
            'items'       => $conPersona ? $C->items($persona, $desde, $hasta, $hoy) : array(),
            'situaciones' => $conPersona ? (object)$C->situaciones($persona, $desde, $hasta) : new stdClass(),
            //En qué peaje estuvo cada día (de Hoy en qué estás)
            'lugares'     => $conPersona ? (object)$C->lugares($persona, $desde, $hasta) : new stdClass(),
            'vehiculo'    => count($vh) ? $vh : new stdClass()
        ), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Cronograma
    //-----------------------------------------------------------------------
    case 'agregar':
        if (!$usaCrono) { cronoError(403, 'No tienes permiso para el cronograma.'); }
        $fecha = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($fecha === '') { cronoError(400, 'La fecha no es válida.'); }
        if ($fecha < $primerEditable) { cronoError(400, 'Ese día ya no se puede modificar.'); }
        if ($fecha > $ultimoPlan) { cronoError(400, 'Solo se planea hasta ' . CRONO_MESES_ADELANTE . ' meses adelante.'); }

        $tipo = isset($_POST['tipo']) ? $_POST['tipo'] : '';
        if (!isset(Cronograma::$TIPOS[$tipo])) { cronoError(400, 'Elige qué vas a hacer ese día.'); }

        $idCentro = null;
        $descripcion = HanaVal::texto(isset($_POST['descripcion']) ? $_POST['descripcion'] : '', 150);
        if ($tipo === 'VISITA') {
            $idCentro = isset($_POST['centro']) ? (int)$_POST['centro'] : 0;
            $valido = false;
            foreach (HanaDB::centrosCoordinador($idColaborador) as $c) { if ((int)$c['ID_CENTRO_OP'] === $idCentro) { $valido = true; break; } }
            if (!$valido) { cronoError(400, 'Elige uno de los peajes de los proyectos que coordinas.'); }
        } elseif (in_array($tipo, array('REUNION', 'OTRO')) && $descripcion === null) {
            cronoError(400, 'Describe la actividad: con quién es la reunión, o qué vas a hacer.');
        }

        $id = $C->agregar($idColaborador, $fecha, $tipo, $idCentro, $descripcion, date('Y-m-d H:i:s'));
        if (!$id) { cronoError(500, 'No se pudo agregar. Intenta de nuevo.'); }
        echo json_encode(array('ok' => true, 'id' => $id, 'mensaje' => 'Agregado al cronograma.'), JSON_UNESCAPED_UNICODE);
        break;

    //Marcar realizada, cancelada, o devolverla a programada
    case 'estado':
        if (!$usaCrono) { cronoError(403, 'No tienes permiso para el cronograma.'); }
        $it = cronoItemPropio($C, isset($_POST['id']) ? (int)$_POST['id'] : 0, $idColaborador, $primerEditable);
        $estado = isset($_POST['estado']) ? $_POST['estado'] : '';
        if (!in_array($estado, array('PROGRAMADA', 'REALIZADA', 'CANCELADA'), true)) { cronoError(400, 'Estado no válido.'); }
        if ($estado === 'REALIZADA' && $it['FECHA'] > $ultimoEditable) { cronoError(400, 'Solo se marca como realizado lo de ' . HanaFechas::textoVentana() . '.'); }
        $obs = HanaVal::texto(isset($_POST['observacion']) ? $_POST['observacion'] : '', 300);
        if ($estado === 'CANCELADA' && $obs === null) { cronoError(400, 'Explica por qué se cancela.'); }
        if (!$C->cambiarEstado($it['ID_CRONOGRAMA'], $estado, $obs)) { cronoError(500, 'No se pudo guardar. Intenta de nuevo.'); }
        echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
        break;

    //Quitar algo agregado por error: solo lo que todavía no ha pasado
    case 'quitar':
        if (!$usaCrono) { cronoError(403, 'No tienes permiso para el cronograma.'); }
        $it = cronoItemPropio($C, isset($_POST['id']) ? (int)$_POST['id'] : 0, $idColaborador, $primerEditable);
        if ($it['FECHA'] < $hoy) { cronoError(400, 'Lo de días pasados no se quita: se marca como cancelado, con el motivo.'); }
        if (!$C->quitar($it['ID_CRONOGRAMA'])) { cronoError(500, 'No se pudo quitar. Intenta de nuevo.'); }
        echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Estado del vehículo en un día
    //-----------------------------------------------------------------------
    case 'vehiculoDia':
        $v = cronoVehiculo($C, isset($_POST['vehiculo']) ? (int)$_POST['vehiculo'] : 0, $idColaborador, $adminVh);
        if (!$v) { cronoError(403, 'Ese vehículo no está a tu cargo.'); }
        $fecha = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($fecha === '' || !HanaFechas::enVentana($fecha)) {
            cronoError(400, 'El estado del vehículo se registra para ' . HanaFechas::textoVentana() . '.');
        }
        $estado = isset($_POST['estado']) ? $_POST['estado'] : '';
        if (!isset(Cronograma::$ESTADOS_VH[$estado])) { cronoError(400, 'Elige el estado del vehículo.'); }
        $obs = HanaVal::texto(isset($_POST['observacion']) ? $_POST['observacion'] : '', 300);
        if ($estado !== 'OPERATIVO' && $obs === null) { cronoError(400, 'Explica qué le pasa al vehículo.'); }
        //Si el vehículo no estaba operativo, cómo se transportó el coordinador ese día
        $transporte = null;
        if ($estado !== 'OPERATIVO') {
            $transporte = isset($_POST['transporte']) ? $_POST['transporte'] : '';
            if ($transporte === '' && !HanaConfig::si('CRONO_TRANSPORTE_OBLIGATORIO')) { $transporte = null; } //opcional
            elseif (!isset(Cronograma::$TRANSPORTES[$transporte])) { cronoError(400, 'Elige cómo te transportaste ese día (bus, taxi, aplicación...).'); }
        }
        if (!$C->guardarVehiculoDia($v['ID_VEHICULO'], $fecha, $estado, $obs, $idColaborador, date('Y-m-d H:i:s'), $transporte)) {
            cronoError(500, 'No se pudo guardar. Intenta de nuevo.');
        }
        echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Catálogo de vehículos (5M)
    //-----------------------------------------------------------------------
    case 'catalogo':
        if (!$adminVh) { cronoError(403, 'No permitido: tu rol no administra vehículos.'); }
        echo json_encode($C->catalogo(), JSON_UNESCAPED_UNICODE);
        break;

    case 'guardarVehiculo':
        if (!$adminVh) { cronoError(403, 'No permitido: tu rol no administra vehículos.'); }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', isset($_POST['placa']) ? $_POST['placa'] : ''));
        if (strlen($placa) < 5 || strlen($placa) > 10) { cronoError(400, 'La placa no es válida.'); }
        if ($C->placaUsada($placa, $id)) { cronoError(400, "Ya existe un vehículo con la placa $placa."); }
        $desc = HanaVal::texto(isset($_POST['descripcion']) ? $_POST['descripcion'] : '', 80);
        $proy = isset($_POST['proyecto']) && (int)$_POST['proyecto'] > 0 ? (int)$_POST['proyecto'] : null;
        $resp = isset($_POST['responsable']) && (int)$_POST['responsable'] > 0 ? (int)$_POST['responsable'] : null;
        if ($resp && !HanaDB::fila("SELECT 1 AS ok FROM colaboradores WHERE ID_COLABORADOR = ? AND ESTADO = 1", 'i', array($resp))) {
            cronoError(400, 'El responsable elegido no existe o está inactivo.');
        }
        $estado = isset($_POST['estado']) && (int)$_POST['estado'] === 0 ? 0 : 1;
        if (!$C->guardarVehiculo($id, $placa, $desc, $proy, $resp, $estado)) { cronoError(500, 'No se pudo guardar el vehículo.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Vehículo guardado.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        cronoError(400, 'Operación no reconocida.');
}
