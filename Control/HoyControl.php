<?php
/*
  HANA - Controlador de "Hoy en qué estás" (Reporte diario)
  Cada persona registra su propio día. Nadie registra el día de otro: el
  colaborador sale siempre de la sesión, nunca de lo que envíe la pantalla.
  Quien tiene la casilla 33M (Reporte diario: consultar), como el director de
  operaciones, VE el día de cada coordinador (op=config, obtener y mes con
  &persona=), pero no guarda nada: guardar es solo del coordinador, y de su día.
*/
session_start();
require_once __DIR__ . "/../Modelo/HoyModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //las respuestas JSON llevan los textos como se escribieron (sin &amp;)
require_once __DIR__ . "/../Modelo/HanaDB.php";
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');

//La hora de Colombia. Sin esto, si el servidor está en otra zona horaria, a
//las 7 de la noche "hoy" ya sería mañana
date_default_timezone_set('America/Bogota');

//Solo se registra y corrige HOY. Otro día, únicamente si el administrador se lo
//habilitó a la persona (Parámetros del sistema → Días habilitados). Lo demás queda de consulta
define('HOY_MAX_ACTIVIDAD', 3000); //caracteres del texto "qué vas a hacer hoy"

function hoyError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//---------------------------------------------------------------------------
// Sesión y permiso: 11M es el permiso de diligenciar el reporte diario
//---------------------------------------------------------------------------
//Si el administrador apagó este módulo (Parámetros del sistema), no se registra nada
require_once __DIR__ . '/../Modelo/HanaConfig.php';
if (!HanaConfig::modulo('HOY')) { hoyError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
hanaUsarModulo('HOY');
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) {
    hoyError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.');
}
//Llena su propio día quien tiene la casilla de llenar (28M) y peajes asignados.
//Ve el día de los demás (de sus peajes) quien tiene la casilla de ver (33M)
$esCoordinador = hanaPuede('HOY', 'llenar') && count(hanaMisCentros()) > 0;
$consulta      = hanaPuede('HOY', 'ver');
if (!$esCoordinador && !$consulta) {
    hoyError(403, hanaPuede('HOY', 'llenar') ? 'No tienes peajes asignados. Pide que te los asignen en Configuración → Colaboradores.'
                                            : 'Tu rol no tiene acceso a Hoy en qué estás. Se da en Configuración → Permisos de usuario.');
}
//A quién puede consultar: los que trabajan en sus peajes (el ADMIN TEC, a todos)
$idsConsulta = hanaVeTodo() ? null : array_keys(hanaMisCentrosIds());

$idUsuario     = (int)$_SESSION['Idcolaborador']; //el usuario es la persona (colaboradores)
$idColaborador = (int)$_SESSION['Idcolaborador'];
$Hoy           = new Hoy();

$hoy          = date('Y-m-d');
list($primerEditable, $ultimoEditable) = HanaFechas::ventana();

//De quién es el día que se ve: el propio, o (con 33M) el de uno de los coordinadores de la lista.
//Si no se pide nadie, el propio; quien no coordina nada no tiene día propio y tiene que elegir
function hoyPersona($Hoy, $idColaborador, $esCoordinador, $consulta)
{
    $pedida = isset($_GET['persona']) ? (int)$_GET['persona'] : 0;
    if ($pedida <= 0 || $pedida === $idColaborador) {
        if (!$esCoordinador) { hoyError(400, 'Elige de quién quieres ver el día.'); }
        return $idColaborador;
    }
    if (!$consulta) { hoyError(403, 'Solo puedes ver tu propio día.'); }
    foreach ($Hoy->personasConsulta($GLOBALS['idsConsulta']) as $persona) {
        if ((int)$persona['ID_COLABORADOR'] === $pedida) { return $pedida; }
    }
    hoyError(403, 'Esa persona no trabaja en tus peajes.');
}

//Una fecha AAAA-MM-DD válida, o '' si no lo es
function hoyFecha($valor)
{
    $valor = trim((string)$valor);
    $partes = explode('-', $valor);
    if (count($partes) !== 3 || !ctype_digit($partes[0] . $partes[1] . $partes[2])) { return ''; }
    return checkdate((int)$partes[1], (int)$partes[2], (int)$partes[0]) ? sprintf('%04d-%02d-%02d', $partes[0], $partes[1], $partes[2]) : '';
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
    $texto = trim((string)$valor);
    if (function_exists('mb_substr')) { return mb_substr($texto, 0, $max, 'UTF-8'); }
    return substr($texto, 0, $max);
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //-----------------------------------------------------------------------
    // Lo que la pantalla necesita al abrir
    //-----------------------------------------------------------------------
    case 'config':
        echo hanaJsonTexto(array(
            'hoy'           => $hoy,
            'primerEditable'=> $primerEditable,
            'ultimoEditable'=> $ultimoEditable,
            'habilitados'   => HanaFechas::habilitados($idColaborador), //los días que el administrador le abrió
            'situaciones'   => Hoy::$SITUACIONES,
            'centros'       => hanaMisCentros(), //sus peajes asignados
            'yo'            => $idColaborador,
            'esCoordinador' => $esCoordinador, //tiene día propio y lo llena
            'consulta'      => $consulta,      //ve el día de los demás (solo consulta)
            'personas'      => $consulta ? $Hoy->personasConsulta($idsConsulta) : array()
        ), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Un día
    //-----------------------------------------------------------------------
    case 'obtener':
        $fecha = hoyFecha(isset($_GET['fecha']) ? $_GET['fecha'] : '');
        if ($fecha === '') { hoyError(400, 'La fecha no es válida.'); }
        $persona = hoyPersona($Hoy, $idColaborador, $esCoordinador, $consulta);
        $propio  = $persona === $idColaborador;
        //Un día que no ha llegado solo se abre si el administrador lo habilitó
        if ($fecha > $hoy && (!$propio || !HanaFechas::habilitado($fecha, $idColaborador))) { hoyError(400, 'Ese día todavía no llega: solo se registra hoy.'); }
        echo hanaJsonTexto(array(
            'fecha'    => $fecha,
            'propio'   => $propio,
            //El día de otro nunca se edita: es solo consulta
            'editable' => $propio && HanaFechas::enVentana($fecha, $idColaborador), //hoy, o un día habilitado
            //Para pintar el día de otro: sus peajes asignados
            'centros'  => $propio ? null : hanaMisCentros($persona),
            'registro' => $Hoy->obtener($persona, $fecha)
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
        $persona = hoyPersona($Hoy, $idColaborador, $esCoordinador, $consulta);
        echo hanaJsonTexto(array(
            'hoy'   => $hoy,
            'filas' => $Hoy->mes($persona, $desde, $hasta)
        ), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Guardar el día
    //-----------------------------------------------------------------------
    case 'guardar':
        //Solo guarda quien llena (28M), y solo su propio día. Quien consulta (33M) no escribe nada
        if (!$esCoordinador) { hoyError(403, 'Hoy en qué estás es solo de consulta para ti.'); }
        $fecha = hoyFecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($fecha === '') { hoyError(400, 'La fecha no es válida.'); }
        if (!HanaFechas::enVentana($fecha, $idColaborador)) {
            hoyError(400, 'Ese día no se puede modificar: solo se registra ' . HanaFechas::textoVentana() . '.');
        }

        $situacion = isset($_POST['situacion']) ? strtoupper(trim($_POST['situacion'])) : '';
        if (!isset(Hoy::$SITUACIONES[$situacion])) { hoyError(400, 'Elige la situación del día.'); }

        $datos = array(
            'situacion'   => $situacion,
            'ingreso'     => null,
            'salida'      => null,
            'lugarOtro'   => null,
            'actividad'   => null, //lo que va a hacer en el día: un solo texto
            'observacion' => hoyTexto(isset($_POST['observacion']) ? $_POST['observacion'] : '', 2000),
            'centros'     => array()
        );
        if ($datos['observacion'] === '') { $datos['observacion'] = null; }

        //Solo un día laboral lleva lugares, horario y actividad. En descanso,
        //incapacidad, vacaciones o permiso se guarda solo la situación
        if ($situacion === 'LABORAL') {
            $datos['ingreso'] = hoyHora(isset($_POST['ingreso']) ? $_POST['ingreso'] : '');
            $datos['salida']  = hoyHora(isset($_POST['salida'])  ? $_POST['salida']  : '');
            if ($datos['ingreso'] === null) { hoyError(400, 'Escribe la hora de ingreso.'); }
            //La salida es opcional: el día se puede ir llenando mientras transcurre
            if ($datos['salida'] !== null && $datos['salida'] <= $datos['ingreso']) {
                hoyError(400, 'La hora de salida debe ser después de la hora de ingreso.');
            }

            //Centros: solo los asignados al usuario, sin repetir
            $permitidos = array();
            foreach (hanaMisCentros() as $centro) { $permitidos[(int)$centro['ID_CENTRO_OP']] = true; }
            foreach ((array)(isset($_POST['centros']) ? $_POST['centros'] : array()) as $idCentro) {
                $idCentro = (int)$idCentro;
                if (!isset($permitidos[$idCentro])) { hoyError(400, 'Uno de los peajes elegidos no está asignado a tu usuario.'); }
                $datos['centros'][$idCentro] = $idCentro;
            }
            $datos['centros'] = array_values($datos['centros']);

            $datos['lugarOtro'] = hoyTexto(isset($_POST['lugarOtro']) ? $_POST['lugarOtro'] : '', 150);
            if ($datos['lugarOtro'] === '') { $datos['lugarOtro'] = null; }
            if (!count($datos['centros']) && $datos['lugarOtro'] === null) {
                hoyError(400, 'Marca al menos un peaje o escribe en qué otro lugar vas a estar.');
            }

            //Qué va a hacer en el día: un solo texto, obligatorio (es de lo que trata esta pantalla)
            $datos['actividad'] = hoyTexto(isset($_POST['actividad']) ? $_POST['actividad'] : '', HOY_MAX_ACTIVIDAD);
            if ($datos['actividad'] === '') { hoyError(400, 'Escribe qué vas a hacer hoy.'); }
        }

        //Todo o nada
        $Hoy->iniciar();
        $id = $Hoy->guardar($idColaborador, $fecha, $datos, date('Y-m-d H:i:s'));
        if (!$id) {
            $Hoy->deshacer();
            hoyError(500, 'No se pudo guardar el día. No cambió nada; intenta de nuevo.');
        }
        $Hoy->confirmar();

        echo hanaJsonTexto(array('ok' => true, 'id' => $id,
                               'mensaje' => 'Día guardado con éxito.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        hoyError(400, 'Operación no reconocida.');
}
