<?php
/*
  HANA - Controlador de Temas con el cliente (Reporte diario)
  ---------------------------------------------------------------------------
  Casillas (Configuración → Permisos de usuario), en los proyectos de los peajes
  asignados a la persona (Configuración → Colaboradores):
    - 59M Llenar: registrar temas, escribir en qué van, cerrarlos y reabrirlos;
    - 60M Ver: consultarlos.
  Los datos del tema (proyecto, tema, con quién...) los corrige y lo anula quien
  lo registró. El ADMIN TEC (24M) puede todo, en todos los proyectos.
  Los temas se guardan en comunicacion (TIPO = 'TEMA_CLIENTE'); ver Modelo/TemaClienteModelo.php
*/
session_start();
require_once __DIR__ . "/../Modelo/TemaClienteModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //las respuestas JSON llevan los textos como se escribieron
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

//Responde con un código de error y un mensaje en JSON, y corta la ejecución
function temaError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!HanaConfig::modulo('TEMAS')) { temaError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
hanaUsarModulo('TEMAS');
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { temaError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!hanaEntra('TEMAS')) { temaError(403, 'Tu rol no tiene acceso a Temas con el cliente. Se da en Configuración → Permisos de usuario.'); }

$idColaborador = (int)$_SESSION['Idcolaborador'];
$puedeLlenar   = hanaPuede('TEMAS', 'llenar');
$esAdmin       = hanaTienePermiso('24M');
$hoy   = HanaFechas::hoy();
$ahora = date('Y-m-d H:i:s');

$centros = array(); $proyectos = array();
foreach (hanaMisCentros() as $centro) {
    $centros[(int)$centro['ID_CENTRO_OP']] = $centro;
    $proyectos[(int)$centro['ID_PROYECTO']] = array('ID_PROYECTO' => (int)$centro['ID_PROYECTO'], 'NOM_PROYECTO' => $centro['NOM_PROYECTO']);
}
if (!count($proyectos)) { temaError(403, 'No tienes peajes asignados. Pide que te los asignen en Configuración → Colaboradores.'); }

//Trae un tema vigente de los proyectos de la persona, o corta con 404
function temaCargar($proyectos, $id)
{
    $tema = TemaCliente::mostrar($id);
    if (!$tema || (int)$tema['ESTADO'] !== 1 || !isset($proyectos[(int)$tema['ID_PROYECTO']])) {
        temaError(404, 'No se encontró el tema, o no es de tus proyectos.');
    }
    return $tema;
}

//¿Puede corregir los datos del tema o anularlo? Quien lo registró (con Llenar) o el ADMIN TEC
$puedeCorregir = function ($tema) use ($puedeLlenar, $esAdmin, $idColaborador) {
    return $esAdmin || ($puedeLlenar && (int)$tema['ID_COLABORADOR_REGISTRA'] === $idColaborador);
};

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'config':
        echo hanaJsonTexto(array('hoy' => $hoy, 'proyectos' => array_values($proyectos), 'centros' => array_values($centros),
                                 'clases' => TemaCliente::clases(), 'pendiente' => TemaCliente::pendientes(),
                                 'puedeLlenar' => $puedeLlenar), JSON_UNESCAPED_UNICODE);
        break;

    //Los abiertos (mes 0) o los que estuvieron abiertos en un mes
    case 'listar':
        $abiertos = !isset($_GET['mes']) || (int)$_GET['mes'] === 0;
        $desde = $hasta = '';
        if (!$abiertos) {
            $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : 0; $mes = (int)$_GET['mes'];
            if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) { temaError(400, 'El periodo no es válido.'); }
            $desde = sprintf('%04d-%02d-01', $anio, $mes);
            $hasta = date('Y-m-d', strtotime("$desde +1 month"));
        }
        $ids = array_keys($proyectos);
        if (isset($_GET['proyecto']) && (int)$_GET['proyecto'] > 0) {
            $ids = isset($proyectos[(int)$_GET['proyecto']]) ? array((int)$_GET['proyecto']) : array();
        }
        $filas = TemaCliente::listar($ids, $abiertos, $desde, $hasta, $hoy);
        foreach ($filas as $i => $fila) { $filas[$i]['PUEDE_CORREGIR'] = $puedeCorregir($fila); }
        echo hanaJsonTexto($filas, JSON_UNESCAPED_UNICODE);
        break;

    //El seguimiento de un tema
    case 'seguimiento':
        $tema = temaCargar($proyectos, isset($_GET['id']) ? (int)$_GET['id'] : 0);
        echo hanaJsonTexto(array('seguimiento' => TemaCliente::seguimiento($tema['ID_COMUNICACION']), 'cerrado' => $tema['FECHA_ATENCION'] !== null,
                                 'fechaCierre' => $tema['FECHA_ATENCION'], 'apertura' => $tema['FECHA_RECEPCION']), JSON_UNESCAPED_UNICODE);
        break;

    //Crea un tema o corrige sus datos
    case 'guardar':
        if (!$puedeLlenar) { temaError(403, 'Tu rol solo consulta los temas.'); }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $antes = temaCargar($proyectos, $id);
            if (!$puedeCorregir($antes)) { temaError(403, 'Los datos del tema los corrige quien lo registró. Tú puedes escribir en qué va.'); }
        }
        $datos = array();
        $datos['proyecto'] = isset($_POST['proyecto']) ? (int)$_POST['proyecto'] : 0;
        if (!isset($proyectos[$datos['proyecto']])) { temaError(400, 'Elige uno de tus proyectos.'); }
        $datos['centro'] = isset($_POST['centro']) && (int)$_POST['centro'] > 0 ? (int)$_POST['centro'] : null;
        if ($datos['centro'] !== null && (!isset($centros[$datos['centro']]) || (int)$centros[$datos['centro']]['ID_PROYECTO'] !== $datos['proyecto'])) {
            temaError(400, 'El peaje elegido no es de ese proyecto o no lo tienes asignado.');
        }
        $datos['tema'] = HanaVal::texto(isset($_POST['tema']) ? $_POST['tema'] : '', 300);
        if ($datos['tema'] === null) { temaError(400, 'Escribe cuál es el tema.'); }
        $datos['conQuien'] = HanaVal::texto(isset($_POST['conQuien']) ? $_POST['conQuien'] : '', 120);
        if ($datos['conQuien'] === null) { temaError(400, 'Escribe con quién se está tratando (persona o área del cliente).'); }
        $clases = TemaCliente::clases();
        $datos['clase'] = isset($_POST['clase']) ? (string)$_POST['clase'] : '';
        if (!isset($clases[$datos['clase']])) { temaError(400, 'Elige de qué se trata el tema.'); }
        $datos['apertura'] = HanaVal::fecha(isset($_POST['apertura']) ? $_POST['apertura'] : '');
        if ($datos['apertura'] === '' || $datos['apertura'] > $hoy) { temaError(400, 'La fecha de apertura no es válida o es posterior a hoy.'); }
        if ($id > 0 && $antes['FECHA_ATENCION'] !== null && $datos['apertura'] > $antes['FECHA_ATENCION']) {
            temaError(400, 'La fecha de apertura no puede ser posterior a la de cierre (' . $antes['FECHA_ATENCION'] . ').');
        }
        $pendientes = TemaCliente::pendientes();
        $datos['pendiente'] = isset($_POST['pendiente']) && $_POST['pendiente'] !== '' ? (string)$_POST['pendiente'] : null;
        if ($datos['pendiente'] !== null && !isset($pendientes[$datos['pendiente']])) { temaError(400, '«Pendiente de» no es válido.'); }
        if ($id > 0 && $antes['FECHA_ATENCION'] !== null) { $datos['pendiente'] = null; } //cerrado: no está pendiente de nadie

        $nuevo = TemaCliente::guardar($id, $datos, $idColaborador, $ahora);
        if (!$nuevo) { temaError(500, 'No se pudo guardar. Intenta de nuevo.'); }
        //En un tema nuevo se puede escribir de una vez en qué va
        $primero = $id > 0 ? null : HanaVal::texto(isset($_POST['inicio']) ? $_POST['inicio'] : '', 2000);
        if ($primero !== null) { TemaCliente::anotar($nuevo, $idColaborador, 'SEGUIMIENTO', $primero, $ahora); }
        echo hanaJsonTexto(array('ok' => true, 'id' => $nuevo, 'mensaje' => $id ? 'Tema actualizado.' : 'Tema registrado.'), JSON_UNESCAPED_UNICODE);
        break;

    //Escribir en qué va el tema
    case 'anotar':
        if (!$puedeLlenar) { temaError(403, 'Tu rol solo consulta los temas.'); }
        $tema = temaCargar($proyectos, isset($_POST['id']) ? (int)$_POST['id'] : 0);
        $texto = HanaVal::texto(isset($_POST['texto']) ? $_POST['texto'] : '', 2000);
        if ($texto === null) { temaError(400, 'Escribe en qué va el tema.'); }
        TemaCliente::anotar($tema['ID_COMUNICACION'], $idColaborador, 'SEGUIMIENTO', $texto, $ahora);
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Seguimiento guardado.'), JSON_UNESCAPED_UNICODE);
        break;

    //Cerrar (con fecha de cierre y una nota opcional) o reabrir
    case 'cerrar':
    case 'reabrir':
        if (!$puedeLlenar) { temaError(403, 'Tu rol solo consulta los temas.'); }
        $tema = temaCargar($proyectos, isset($_POST['id']) ? (int)$_POST['id'] : 0);
        $nota = HanaVal::texto(isset($_POST['texto']) ? $_POST['texto'] : '', 2000);
        if ($_GET['op'] === 'cerrar') {
            if ($tema['FECHA_ATENCION'] !== null) { temaError(400, 'El tema ya está cerrado.'); }
            $fecha = HanaVal::fecha(isset($_POST['fecha']) && $_POST['fecha'] !== '' ? $_POST['fecha'] : $hoy);
            if ($fecha === '' || $fecha < $tema['FECHA_RECEPCION'] || $fecha > $hoy) {
                temaError(400, 'La fecha de cierre debe estar entre la apertura (' . $tema['FECHA_RECEPCION'] . ') y hoy.');
            }
            TemaCliente::cerrar($tema['ID_COMUNICACION'], $fecha, $ahora);
            TemaCliente::anotar($tema['ID_COMUNICACION'], $idColaborador, 'RESUELTA', $nota, $ahora);
            $mensaje = 'Tema cerrado.';
        } else {
            if ($tema['FECHA_ATENCION'] === null) { temaError(400, 'El tema sigue abierto.'); }
            TemaCliente::reabrir($tema['ID_COMUNICACION'], $ahora);
            TemaCliente::anotar($tema['ID_COMUNICACION'], $idColaborador, 'REABIERTA', $nota, $ahora);
            $mensaje = 'Tema reabierto.';
        }
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => $mensaje), JSON_UNESCAPED_UNICODE);
        break;

    //Anular (quien lo registró o el ADMIN TEC). No se borra
    case 'anular':
        $tema = temaCargar($proyectos, isset($_POST['id']) ? (int)$_POST['id'] : 0);
        if (!$puedeCorregir($tema)) { temaError(403, 'Solo quien registró el tema puede anularlo.'); }
        $motivo = HanaVal::texto(isset($_POST['motivo']) ? $_POST['motivo'] : '', 300);
        if ($motivo === null) { temaError(400, 'Explica por qué se anula.'); }
        if (!TemaCliente::anular($tema['ID_COMUNICACION'], $motivo, $ahora)) { temaError(500, 'No se pudo anular. Intenta de nuevo.'); }
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Tema anulado.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        temaError(400, 'Operación no reconocida.');
}
