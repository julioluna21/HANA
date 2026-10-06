<?php
/*
  HANA — Controlador de "Hoy en qué estás" (Reporte diario, Fase 2)
  Cada persona registra su propio día. Nadie registra el día de otro: el
  colaborador sale siempre de la sesión, nunca de lo que envíe la pantalla.
*/
session_start();
require_once __DIR__ . "/../Modelo/HoyModelo.php";
require_once __DIR__ . "/../Modelo/HanaDB.php";
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');

//La hora de Colombia. Sin esto, si el servidor está en otra zona horaria, a
//las 7 de la noche "hoy" ya sería mañana
date_default_timezone_set('America/Bogota');

//Solo se registra y corrige HOY. Otro día, únicamente si el administrador se lo
//habilitó a la persona (Parámetros del sistema → Días habilitados). Lo demás queda de consulta
define('HOY_MAX_ACTIVIDAD', 3000); //caracteres del texto "qué hiciste hoy"

function hoyError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//---------------------------------------------------------------------------
// Sesión y permiso: 11M es el permiso de diligenciar el reporte diario
//---------------------------------------------------------------------------
//Si el administrador apagó este módulo (Parámetros del sistema), no se registra nada
require_once __DIR__ . '/../Modelo/HanaConfig.php';
if (!HanaConfig::modulo('HOY')) { hoyError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
HanaDB::usarModulo('HOY'); //la casilla HOY de Roles da acceso en todos los proyectos
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) {
    hoyError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.');
}
//Hoy en qué estás es del coordinador: lo llena quien coordina un proyecto
if (!HanaDB::esCoordinador((int)$_SESSION['Idcolaborador'])) {
    hoyError(403, 'Hoy en qué estás lo llena el coordinador del proyecto. Si eres coordinador, pide que te asignen en Configuración → Proyectos.');
}

$idUsuario     = (int)$_SESSION['IdUsuarios'];
$idColaborador = (int)$_SESSION['Idcolaborador'];
$Hoy           = new Hoy();

$hoy          = date('Y-m-d');
list($primerEditable, $ultimoEditable) = HanaFechas::ventana();

//Una fecha AAAA-MM-DD válida, o '' si no lo es
function hoyFecha($valor)
{
    $valor = trim((string)$valor);
    $p = explode('-', $valor);
    if (count($p) !== 3 || !ctype_digit($p[0] . $p[1] . $p[2])) { return ''; }
    return checkdate((int)$p[1], (int)$p[2], (int)$p[0]) ? sprintf('%04d-%02d-%02d', $p[0], $p[1], $p[2]) : '';
}

//Una hora HH:MM válida, o null
function hoyHora($valor)
{
    $valor = trim((string)$valor);
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $valor)) { return null; }
    return $valor . ':00';
}

//Texto limpio: sin espacios sobrantes y con un largo máximo
function hoyTexto($valor, $max)
{
    $t = trim((string)$valor);
    if (function_exists('mb_substr')) { return mb_substr($t, 0, $max, 'UTF-8'); }
    return substr($t, 0, $max);
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //-----------------------------------------------------------------------
    // Lo que la pantalla necesita al abrir
    //-----------------------------------------------------------------------
    case 'config':
        echo json_encode(array(
            'hoy'           => $hoy,
            'primerEditable'=> $primerEditable,
            'ultimoEditable'=> $ultimoEditable,
            'habilitados'   => HanaFechas::habilitados($idColaborador), //los días que el administrador le abrió
            'situaciones'   => Hoy::$SITUACIONES,
            'centros'       => HanaDB::centrosCoordinador($idColaborador) //los peajes de sus proyectos
        ), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Un día
    //-----------------------------------------------------------------------
    case 'obtener':
        $fecha = hoyFecha(isset($_GET['fecha']) ? $_GET['fecha'] : '');
        if ($fecha === '') { hoyError(400, 'La fecha no es válida.'); }
        //Un día que no ha llegado solo se abre si el administrador lo habilitó
        if ($fecha > $hoy && !HanaFechas::habilitado($fecha, $idColaborador)) { hoyError(400, 'Ese día todavía no llega: solo se registra hoy.'); }
        echo json_encode(array(
            'fecha'    => $fecha,
            'editable' => HanaFechas::enVentana($fecha, $idColaborador), //hoy, o un día habilitado
            'registro' => $Hoy->obtener($idColaborador, $fecha)
        ), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Un mes: lo registrado y qué días faltan
    //-----------------------------------------------------------------------
    case 'mes':
        $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
        $mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
        if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) { hoyError(400, 'El mes no es válido.'); }
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-d', strtotime("$desde +1 month"));
        echo json_encode(array(
            'hoy'   => $hoy,
            'filas' => $Hoy->mes($idColaborador, $desde, $hasta)
        ), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Guardar el día
    //-----------------------------------------------------------------------
    case 'guardar':
        $fecha = hoyFecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($fecha === '') { hoyError(400, 'La fecha no es válida.'); }
        if (!HanaFechas::enVentana($fecha)) {
            hoyError(400, 'Ese día no se puede modificar: solo se registra ' . HanaFechas::textoVentana() . '.');
        }

        $situacion = isset($_POST['situacion']) ? strtoupper(trim($_POST['situacion'])) : '';
        if (!isset(Hoy::$SITUACIONES[$situacion])) { hoyError(400, 'Elige la situación del día.'); }

        $d = array(
            'situacion'   => $situacion,
            'ingreso'     => null,
            'salida'      => null,
            'lugarOtro'   => null,
            'actividad'   => null, //lo que hizo en el día: un solo texto (antes era un bloque por hora)
            'observacion' => hoyTexto(isset($_POST['observacion']) ? $_POST['observacion'] : '', 2000),
            'centros'     => array()
        );
        if ($d['observacion'] === '') { $d['observacion'] = null; }

        //Solo un día laboral lleva lugares, horario y actividad. En descanso,
        //incapacidad, vacaciones o permiso se guarda solo la situación
        if ($situacion === 'LABORAL') {
            $d['ingreso'] = hoyHora(isset($_POST['ingreso']) ? $_POST['ingreso'] : '');
            $d['salida']  = hoyHora(isset($_POST['salida'])  ? $_POST['salida']  : '');
            if ($d['ingreso'] === null) { hoyError(400, 'Escribe la hora de ingreso.'); }
            //La salida es opcional: el día se puede ir llenando mientras transcurre
            if ($d['salida'] !== null && $d['salida'] <= $d['ingreso']) {
                hoyError(400, 'La hora de salida debe ser después de la hora de ingreso.');
            }

            //Centros: solo los asignados al usuario, sin repetir
            $permitidos = array();
            foreach (HanaDB::centrosCoordinador($idColaborador) as $c) { $permitidos[(int)$c['ID_CENTRO_OP']] = true; }
            foreach ((array)(isset($_POST['centros']) ? $_POST['centros'] : array()) as $idC) {
                $idC = (int)$idC;
                if (!isset($permitidos[$idC])) { hoyError(400, 'Uno de los peajes elegidos no está asignado a tu usuario.'); }
                $d['centros'][$idC] = $idC;
            }
            $d['centros'] = array_values($d['centros']);

            $d['lugarOtro'] = hoyTexto(isset($_POST['lugarOtro']) ? $_POST['lugarOtro'] : '', 150);
            if ($d['lugarOtro'] === '') { $d['lugarOtro'] = null; }
            if (!count($d['centros']) && $d['lugarOtro'] === null) {
                hoyError(400, 'Marca al menos un peaje o escribe en qué otro lugar estuviste.');
            }

            //Qué hizo en el día: un solo texto, obligatorio (es de lo que trata esta pantalla)
            $d['actividad'] = hoyTexto(isset($_POST['actividad']) ? $_POST['actividad'] : '', HOY_MAX_ACTIVIDAD);
            if ($d['actividad'] === '') { hoyError(400, 'Escribe qué hiciste hoy.'); }
        }

        //Todo o nada
        $Hoy->iniciar();
        $id = $Hoy->guardar($idColaborador, $fecha, $d, date('Y-m-d H:i:s'));
        if (!$id) {
            $Hoy->deshacer();
            hoyError(500, 'No se pudo guardar el día. No cambió nada; intenta de nuevo.');
        }
        $Hoy->confirmar();

        echo json_encode(array('ok' => true, 'id' => $id,
                               'mensaje' => 'Día guardado con éxito.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        hoyError(400, 'Operación no reconocida.');
}
