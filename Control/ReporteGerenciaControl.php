<?php
/*
  HANA - Controlador del Reporte diario a la Gerencia (Consultas)
  ---------------------------------------------------------------------------
  Casilla 61M (Configuración → Permisos de usuario): armar, corregir y guardar el
  reporte del día, con los proyectos de los peajes asignados a la persona. El
  ADMIN TEC (24M) también, con todos los proyectos.

  El reporte es de un día o de un RANGO DE DÍAS (desde - hasta, máximo 31 días).
  Se identifica por su último día («fecha») y su primer día («desde»).

  Operaciones:
    config    hoy y los meses que tienen reportes guardados
    abrir     el reporte de un periodo (fecha y, si es un rango, desde): lo guardado (si hay),
              lo que propone el sistema y los reportes guardados del mes del último día
    guardados los reportes guardados de un mes (mes=AAAA-MM), para la lista de la pantalla
    guardar   guarda el reporte como una versión nueva en historial (MODULO = 'GERENCIA')
*/
session_start();
require_once __DIR__ . "/../Modelo/ReporteGerenciaModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php';
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

function gerError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!HanaConfig::modulo('GERENCIA')) { gerError(403, 'El reporte a la Gerencia está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
hanaUsarModulo('GERENCIA');
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { gerError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!hanaPuede('GERENCIA', 'armar')) { gerError(403, 'Tu rol no tiene la casilla «Reporte diario a la Gerencia». Se da en Configuración → Permisos de usuario.'); }

$idColaborador = (int)$_SESSION['Idcolaborador'];
$hoy = HanaFechas::hoy();
$centros = array(); $proyectos = array();
foreach (hanaMisCentros() as $centro) {
    $centros[(int)$centro['ID_CENTRO_OP']] = $centro;
    $proyectos[(int)$centro['ID_PROYECTO']] = $centro['NOM_PROYECTO'];
}
if (!count($proyectos)) { gerError(403, 'No tienes peajes asignados. Pide que te los asignen en Configuración → Colaboradores.'); }
asort($proyectos);
//Las novedades salen en los avances de cada proyecto para quien puede ver todas las de sus peajes
$conNovedades = hanaTienePermiso('16M') || hanaTienePermiso('24M');

//La fecha pedida: un día válido que no pase de hoy
function gerFecha($valor, $hoy)
{
    $fecha = HanaVal::fecha($valor);
    if ($fecha === '' || $fecha > $hoy || $fecha < '2020-01-01') { gerError(400, 'Elige un día válido (hasta hoy).'); }
    return $fecha;
}

//El primer día del periodo: vacío o igual al último = un solo día. Máximo 31 días en total
const GER_MAX_DIAS = 31;
function gerDesde($valor, $fecha)
{
    $desde = HanaVal::fecha((string)$valor);
    if ($desde === '' || $desde >= $fecha) { return $fecha; }
    if ((strtotime($fecha) - strtotime($desde)) / 86400 + 1 > GER_MAX_DIAS) { gerError(400, 'El reporte puede cubrir hasta ' . GER_MAX_DIAS . ' días. Acorta el rango.'); }
    return $desde;
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'config':
        //Los días guardados no van todos aquí (con el tiempo serían cientos): van los meses, y la
        //pantalla pide los días del mes que se esté viendo
        echo hanaJsonTexto(array('hoy' => $hoy, 'secciones' => ReporteGerencia::SECCIONES, 'meses' => ReporteGerencia::mesesGuardados()),
                           JSON_UNESCAPED_UNICODE);
        break;

    //Los días guardados de un mes: {mes: "2026-11", guardados: [{desde, hasta}, ...]}
    case 'guardados':
        $mes = isset($_GET['mes']) ? (string)$_GET['mes'] : '';
        if (!preg_match('/^(20[2-9]\d)-(0[1-9]|1[0-2])$/', $mes)) { gerError(400, 'Elige un mes válido.'); }
        echo hanaJsonTexto(array('mes' => $mes, 'guardados' => ReporteGerencia::guardadosMes($mes)), JSON_UNESCAPED_UNICODE);
        break;

    //El reporte de un periodo: lo guardado (si hay) y lo que propone el sistema con los datos de ESE periodo,
    //para «Traer del sistema» en cada sección. Si no hay nada guardado, lo que se ve es lo que propone el sistema
    case 'abrir':
        $fecha = gerFecha(isset($_GET['fecha']) ? $_GET['fecha'] : '', $hoy);
        $desde = gerDesde(isset($_GET['desde']) ? $_GET['desde'] : '', $fecha);
        $sistema = ReporteGerencia::propuesta($proyectos, $centros, $fecha, $conNovedades, $desde);
        $guardado = ReporteGerencia::guardado($fecha, $desde);
        $reporte = $guardado ? ReporteGerencia::deGuardado($guardado['datos'], $proyectos) : null;
        if (!$reporte) { $reporte = ReporteGerencia::normalizar($sistema + array('encabezado' => ReporteGerencia::ultimoEncabezado()), $proyectos); }
        $mes = substr($fecha, 0, 7);
        $lista = array();
        foreach ($proyectos as $idProyecto => $nombre) { $lista[] = array('id' => $idProyecto, 'nombre' => $nombre); }
        echo hanaJsonTexto(array('fecha' => $fecha, 'desde' => $desde, 'diaCorto' => ReporteGerencia::periodoCorto($desde, $fecha),
                                 'periodoLargo' => ReporteGerencia::periodoLargo($desde, $fecha),
                                 'fechaInforme' => ReporteGerencia::fechaInforme($desde, $fecha), 'titulo' => ReporteGerencia::tituloInforme($desde, $fecha),
                                 'tituloPromesa' => ReporteGerencia::tituloPromesa($desde, $fecha),
                                 'proyectos' => $lista, 'reporte' => $reporte, 'sistema' => $sistema,
                                 'guardado' => $guardado ? array('quien' => $guardado['quien'], 'fecha' => $guardado['fecha']) : null,
                                 'mes' => $mes, 'guardadosMes' => ReporteGerencia::guardadosMes($mes)),
                           JSON_UNESCAPED_UNICODE);
        break;

    //Guarda el reporte del periodo (una versión nueva; la anterior queda como trazabilidad). Llega como JSON:
    //{fecha, desde, encabezado, resultados, prioritarias, proyectos, transversal, compromisos}
    case 'guardar':
        $entrada = json_decode(isset($_POST['reporte']) ? (string)$_POST['reporte'] : '', true);
        if (!is_array($entrada) || !isset($entrada['resultados'])) { gerError(400, 'No llegó el reporte. Recarga la página e intenta de nuevo.'); }
        $fecha = gerFecha(isset($entrada['fecha']) ? $entrada['fecha'] : '', $hoy);
        $desde = gerDesde(isset($entrada['desde']) ? $entrada['desde'] : '', $fecha);
        list($reporte, $error) = ReporteGerencia::normalizar($entrada, $proyectos, true);
        if ($error !== null) { gerError(400, $error); }
        $anterior = ReporteGerencia::guardado($fecha, $desde);
        $datos = ReporteGerencia::paraGuardar($fecha, $desde, $reporte, $proyectos, $anterior ? $anterior['datos'] : null);
        $ahora = date('Y-m-d H:i:s');
        if (!ReporteGerencia::cabe($datos)) {
            gerError(400, 'El reporte quedó demasiado largo para guardarlo. Acorta algunos apartados e intenta de nuevo.');
        }
        if (!ReporteGerencia::guardar($fecha, $datos, $idColaborador, $ahora)) {
            gerError(500, 'No se pudo guardar el reporte. Intenta de nuevo en un momento.');
        }
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Reporte ' . ($desde < $fecha ? 'del periodo ' . ReporteGerencia::periodoCorto($desde, $fecha) : 'del ' . ReporteGerencia::diaCorto($fecha)) . ' guardado.', 'fecha' => $ahora), JSON_UNESCAPED_UNICODE);
        break;

    default:
        gerError(400, 'Operación no reconocida.');
}
