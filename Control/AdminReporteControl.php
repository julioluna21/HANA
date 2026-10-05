<?php
/*
  HANA — Administración del reporte diario (consulta)
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
require_once __DIR__ . "/../Modelo/HoyModelo.php";
require_once __DIR__ . "/../Modelo/ArqueoModelo.php";
require_once __DIR__ . "/../Modelo/CronogramaModelo.php";
require_once __DIR__ . "/../Modelo/VacanteModelo.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

function admError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['IdUsuarios'])) { admError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!hanaTienePermiso(PERMISO_ADMIN_REPORTE)) { admError(403, 'No tienes permiso para la administración del reporte diario.'); }

$hoy = date('Y-m-d');

//Todos los centros activos (con su proyecto), con el id como llave
$centros = array();
foreach ((array)HanaDB::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.ID_PROYECTO, p.NOM_PROYECTO
                             FROM centros_operacion c INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                            WHERE c.Estado = '1' ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP") as $c) {
    $centros[(int)$c['ID_CENTRO_OP']] = $c;
}

//Los centros a consultar según los filtros de proyecto y centro
function admCentros($centros)
{
    $proy = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
    $cen  = isset($_GET['centro'])   ? (int)$_GET['centro']   : 0;
    $ids = array();
    foreach ($centros as $id => $c) {
        if ($proy > 0 && (int)$c['ID_PROYECTO'] !== $proy) { continue; }
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
        $proyectos = HanaDB::q("SELECT p.ID_PROYECTO, p.NOM_PROYECTO, p.ID_COLABORADOR_COORDINADOR, c.NOM_COLABORADOR AS COORDINADOR
                                  FROM proyectos p LEFT JOIN colaboradores c ON c.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR
                                 WHERE p.Estado = '1' ORDER BY p.NOM_PROYECTO");
        $personas = HanaDB::q("SELECT DISTINCT c.ID_COLABORADOR, c.NOM_COLABORADOR
                                 FROM colaboradores c
                                WHERE c.ID_COLABORADOR IN (SELECT ID_COLABORADOR_COORDINADOR FROM proyectos WHERE ID_COLABORADOR_COORDINADOR IS NOT NULL)
                                   OR c.ID_COLABORADOR IN (SELECT ID_COLABORADOR FROM reporte_hoy)
                                   OR c.ID_COLABORADOR IN (SELECT ID_COLABORADOR FROM cronograma)
                                ORDER BY c.NOM_COLABORADOR");
        echo json_encode(array(
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
        $sql = "SELECT h.ID_COLABORADOR, col.NOM_COLABORADOR, h.FECHA, h.SITUACION, h.HORA_INGRESO, h.HORA_SALIDA, h.LUGAR_OTRO,
                       h.OBSERVACION, h.FEC_REGISTRO, h.FEC_MODIFICACION,
                       (SELECT GROUP_CONCAT(c.NOM_CENTRO_OP ORDER BY c.NOM_CENTRO_OP SEPARATOR ' - ')
                          FROM reporte_hoy_centro hc INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = hc.ID_CENTRO_OP
                         WHERE hc.ID_REPORTE_HOY = h.ID_REPORTE_HOY) AS LUGARES,
                       (SELECT COUNT(*) FROM reporte_hoy_hora hh WHERE hh.ID_REPORTE_HOY = h.ID_REPORTE_HOY) AS BLOQUES
                  FROM reporte_hoy h INNER JOIN colaboradores col ON col.ID_COLABORADOR = h.ID_COLABORADOR
                 WHERE h.FECHA >= ? AND h.FECHA < ?";
        $tipos = 'ss'; $params = array($desde, $hasta);
        if (isset($_GET['persona']) && (int)$_GET['persona'] > 0) { $sql .= " AND h.ID_COLABORADOR = ?"; $tipos .= 'i'; $params[] = (int)$_GET['persona']; }
        $sql .= " ORDER BY h.FECHA DESC, col.NOM_COLABORADOR";
        $f = HanaDB::q($sql, $tipos, $params);
        echo json_encode($f ? $f : array(), JSON_UNESCAPED_UNICODE);
        break;

    //Un día completo de una persona, con la bitácora por horas
    case 'hoyDetalle':
        $persona = isset($_GET['persona']) ? (int)$_GET['persona'] : 0;
        $fecha = HanaVal::fecha(isset($_GET['fecha']) ? $_GET['fecha'] : '');
        if ($persona <= 0 || $fecha === '') { admError(400, 'Faltan la persona o la fecha.'); }
        $r = (new Hoy())->obtener($persona, $fecha);
        if (!$r) { admError(404, 'No hay registro de esa persona ese día.'); }
        $r['LUGARES'] = array();
        foreach ($r['CENTROS'] as $id) { if (isset($centros[$id])) { $r['LUGARES'][] = $centros[$id]['NOM_CENTRO_OP']; } }
        $col = HanaDB::fila("SELECT NOM_COLABORADOR FROM colaboradores WHERE ID_COLABORADOR = ?", 'i', array($persona));
        $r['NOM_COLABORADOR'] = $col ? $col['NOM_COLABORADOR'] : '';
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Arqueos de todos los centros
    //-----------------------------------------------------------------------
    case 'arqueos':
        list($desde, $hasta) = admMes();
        if ($desde === '') { admError(400, 'Elige un mes.'); }
        echo json_encode((new Arqueo())->listar(admCentros($centros), $desde, $hasta, 0, isset($_GET['tipo']) ? $_GET['tipo'] : ''),
                         JSON_UNESCAPED_UNICODE);
        break;

    case 'arqueo':
        $a = (new Arqueo())->mostrar(isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if (!$a) { admError(404, 'No se encontró el arqueo.'); }
        echo json_encode($a, JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Cronograma y vehículo de una persona en un mes
    //-----------------------------------------------------------------------
    case 'cronograma':
        list($desde, $hasta) = admMes();
        if ($desde === '') { admError(400, 'Elige un mes.'); }
        $persona = isset($_GET['persona']) ? (int)$_GET['persona'] : 0;
        if ($persona <= 0) { admError(400, 'Elige la persona.'); }
        $C = new Cronograma();
        $vehiculos = array();
        foreach ($C->vehiculosDe($persona, false) as $v) {
            $v['DIAS'] = $C->vehiculoDias($v['ID_VEHICULO'], $desde, $hasta);
            if (!count($v['DIAS'])) { $v['DIAS'] = new stdClass(); }
            $vehiculos[] = $v;
        }
        echo json_encode(array(
            'desde' => $desde, 'hasta' => $hasta,
            'items' => $C->items($persona, $desde, $hasta, $hoy),
            'situaciones' => (object)$C->situaciones($persona, $desde, $hasta),
            'lugares' => (object)$C->lugares($persona, $desde, $hasta),
            'vehiculos' => $vehiculos
        ), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Vacantes y comunicaciones (mes 0 = todas las abiertas)
    //-----------------------------------------------------------------------
    case 'vacantes':
        list($desde, $hasta, $abiertas) = admMes();
        echo json_encode((new Vacante())->listar(admCentros($centros), $desde, $hasta, $abiertas, $hoy), JSON_UNESCAPED_UNICODE);
        break;

    case 'comunicaciones':
        list($desde, $hasta, $abiertas) = admMes();
        $proy = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
        $ids = array();
        foreach ((array)HanaDB::q("SELECT ID_PROYECTO FROM proyectos WHERE Estado = '1'") as $p) {
            if ($proy === 0 || (int)$p['ID_PROYECTO'] === $proy) { $ids[] = (int)$p['ID_PROYECTO']; }
        }
        echo json_encode((new Comunicacion())->listar($ids, $desde, $hasta, $abiertas, $hoy), JSON_UNESCAPED_UNICODE);
        break;

    default:
        admError(400, 'Operación no reconocida.');
}
