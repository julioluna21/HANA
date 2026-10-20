<?php
/*
  HANA - Administración del reporte diario (consulta)
  ---------------------------------------------------------------------------
  Para el administrador técnico (permiso 21M): ver TODO lo que llenan los
  coordinadores, de todos los proyectos, sin ser coordinador. Es de solo
  lectura: cada coordinador sigue llenando lo suyo.
  Usa los mismos modelos que las pantallas del coordinador, así que los
  cálculos (acuerdo de servicio, estado del cronograma, diferencias) son
  exactamente los mismos.
*/
session_start();
require_once __DIR__ . "/../Modelo/HanaDB.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //las respuestas JSON llevan los textos como se escribieron (sin &amp;)
require_once __DIR__ . "/../Modelo/HoyModelo.php";
require_once __DIR__ . "/../Modelo/ArqueoModelo.php";
require_once __DIR__ . "/../Modelo/CronogramaModelo.php";
require_once __DIR__ . "/../Modelo/VacanteModelo.php";
require_once __DIR__ . "/AccesoHelper.php"; //incluye OrganizacionModelo

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

//Responde con un código de error y un mensaje en JSON, y corta la ejecución
function admError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['IdUsuarios'])) { admError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!hanaTienePermiso(PERMISO_ADMIN_REPORTE)) { admError(403, 'No tienes permiso para la administración del reporte diario.'); }

$hoy = date('Y-m-d');

//Todos los centros activos (con su proyecto), con el id como llave
$centros = array();
foreach (Organizacion::centrosActivos() as $centro) {
    $centros[(int)$centro['ID_CENTRO_OP']] = $centro;
}

//Los centros a consultar según los filtros de proyecto y centro
function admCentros($centros)
{
    $proyectoPedido = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
    $cen  = isset($_GET['centro'])   ? (int)$_GET['centro']   : 0;
    $ids = array();
    foreach ($centros as $id => $centro) {
        if ($proyectoPedido > 0 && (int)$centro['ID_PROYECTO'] !== $proyectoPedido) { continue; }
        if ($cen > 0 && $id !== $cen) { continue; }
        $ids[] = $id;
    }
    return $ids;
}

//Rango de un mes (?anio=&mes=); mes 0 = "abiertas" en vacantes y comunicaciones
function admMes()
{
    $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
    $mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
    if ($anio < 2000 || $anio > 2100 || $mes < 0 || $mes > 12) { admError(400, 'El periodo no es válido.'); }
    if ($mes === 0) { return array('', '', true); }
    $desde = sprintf('%04d-%02d-01', $anio, $mes);
    return array($desde, date('Y-m-d', strtotime("$desde +1 month")), false);
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //Filtros: proyectos, centros y las personas que llenan el reporte del
    //coordinador (los coordinadores actuales y quien haya registrado algo)
    case 'config':
        $proyectos = Organizacion::proyectos();
        $personas = Organizacion::personasDelReporte();
        echo hanaJsonTexto(array(
            'hoy' => $hoy, 'proyectos' => $proyectos ? $proyectos : array(), 'centros' => array_values($centros),
            'personas' => $personas ? $personas : array(),
            'situaciones' => Hoy::$SITUACIONES, 'tiposArqueo' => Arqueo::$TIPOS, 'efectivo' => Arqueo::$EFECTIVO,
            'documentos' => Arqueo::$DOCUMENTOS, 'estadosVh' => Cronograma::$ESTADOS_VH, 'transportes' => Cronograma::$TRANSPORTES, 'tiposCrono' => Cronograma::$TIPOS,
            'estadosVacante' => Vacante::$ESTADOS, 'estadosGh' => Vacante::$ESTADOS_GH,
            'tiposCom' => Comunicacion::$TIPOS, 'medios' => Comunicacion::$MEDIOS, 'pendiente' => Comunicacion::$PENDIENTE
        ), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Hoy en qué estás: los registros de un mes, de todos o de una persona
    //-----------------------------------------------------------------------
    case 'hoy':
        list($desde, $hasta) = admMes();
        if ($desde === '') { admError(400, 'Elige un mes.'); }
        $filas = (new Hoy())->mesTodos($desde, $hasta, isset($_GET['persona']) ? max(0, (int)$_GET['persona']) : 0);
        echo hanaJsonTexto($filas ? $filas : array(), JSON_UNESCAPED_UNICODE);
        break;

    //Un día completo de una persona, con lo que hizo
    case 'hoyDetalle':
        $persona = isset($_GET['persona']) ? (int)$_GET['persona'] : 0;
        $fecha = HanaVal::fecha(isset($_GET['fecha']) ? $_GET['fecha'] : '');
        if ($persona <= 0 || $fecha === '') { admError(400, 'Faltan la persona o la fecha.'); }
        $registro = (new Hoy())->obtener($persona, $fecha);
        if (!$registro) { admError(404, 'No hay registro de esa persona ese día.'); }
        $registro['LUGARES'] = array();
        foreach ($registro['CENTROS'] as $id) { if (isset($centros[$id])) { $registro['LUGARES'][] = $centros[$id]['NOM_CENTRO_OP']; } }
        $registro['NOM_COLABORADOR'] = Organizacion::nombre($persona);
        echo hanaJsonTexto($registro, JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Arqueos de todos los centros
    //-----------------------------------------------------------------------
    case 'arqueos':
        list($desde, $hasta) = admMes();
        if ($desde === '') { admError(400, 'Elige un mes.'); }
        echo hanaJsonTexto((new Arqueo())->listar(admCentros($centros), $desde, $hasta, 0, isset($_GET['tipo']) ? $_GET['tipo'] : ''),
                         JSON_UNESCAPED_UNICODE);
        break;

    //El detalle de un arqueo, para abrirlo desde el reporte
    case 'arqueo':
        $arqueo = (new Arqueo())->mostrar(isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if (!$arqueo) { admError(404, 'No se encontró el arqueo.'); }
        echo hanaJsonTexto($arqueo, JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Cronograma y vehículo de una persona en un mes
    //-----------------------------------------------------------------------
    case 'cronograma':
        list($desde, $hasta) = admMes();
        if ($desde === '') { admError(400, 'Elige un mes.'); }
        $persona = isset($_GET['persona']) ? (int)$_GET['persona'] : 0;
        if ($persona <= 0) { admError(400, 'Elige la persona.'); }
        $modeloCronograma = new Cronograma();
        $vehiculos = array();
        foreach ($modeloCronograma->vehiculosDe($persona, false) as $vehiculo) {
            $vehiculo['DIAS'] = $modeloCronograma->vehiculoDias($vehiculo['ID_VEHICULO'], $desde, $hasta);
            if (!count($vehiculo['DIAS'])) { $vehiculo['DIAS'] = new stdClass(); }
            $vehiculos[] = $vehiculo;
        }
        echo hanaJsonTexto(array(
            'desde' => $desde, 'hasta' => $hasta,
            'items' => $modeloCronograma->items($persona, $desde, $hasta, $hoy),
            'situaciones' => (object)$modeloCronograma->situaciones($persona, $desde, $hasta),
            'lugares' => (object)$modeloCronograma->lugares($persona, $desde, $hasta),
            'vehiculos' => $vehiculos
        ), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Vacantes y comunicaciones (mes 0 = todas las abiertas)
    //-----------------------------------------------------------------------
    case 'vacantes':
        list($desde, $hasta, $abiertas) = admMes();
        echo hanaJsonTexto((new Vacante())->listar(admCentros($centros), $desde, $hasta, $abiertas, $hoy), JSON_UNESCAPED_UNICODE);
        break;

    //Comunicaciones del mes (o las abiertas) de los proyectos activos
    case 'comunicaciones':
        list($desde, $hasta, $abiertas) = admMes();
        $proyectoPedido = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
        $ids = array();
        foreach (Organizacion::idsProyectosActivos() as $idProyecto) {
            if ($proyectoPedido === 0 || $idProyecto === $proyectoPedido) { $ids[] = $idProyecto; }
        }
        echo hanaJsonTexto((new Comunicacion())->listar($ids, $desde, $hasta, $abiertas, $hoy), JSON_UNESCAPED_UNICODE);
        break;

    default:
        admError(400, 'Operación no reconocida.');
}
