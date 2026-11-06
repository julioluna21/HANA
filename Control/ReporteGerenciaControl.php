<?php
/*
  HANA - Controlador del Reporte diario a la Gerencia (Consultas)
  ---------------------------------------------------------------------------
  Casilla 61M (Configuración → Permisos de usuario): armar, corregir y guardar el
  reporte del día, con los proyectos de los peajes asignados a la persona. El
  ADMIN TEC (24M) también, con todos los proyectos.

  Operaciones:
    config    los puntos del reporte y los meses que tienen días guardados
    abrir     el reporte de un día: lo guardado (si hay), lo que propone el sistema
              y los días guardados del mes de ese día
    guardados los días guardados de un mes (mes=AAAA-MM), para la lista de la pantalla
    guardar   guarda el reporte del día en su fila de historial (MODULO = 'GERENCIA')
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
//Las novedades del día salen en «Novedades relevantes» para quien puede ver todas las de sus peajes
$conNovedades = hanaTienePermiso('16M') || hanaTienePermiso('24M');

//La fecha pedida: un día válido que no pase de hoy
function gerFecha($valor, $hoy)
{
    $fecha = HanaVal::fecha($valor);
    if ($fecha === '' || $fecha > $hoy || $fecha < '2020-01-01') { gerError(400, 'Elige un día válido (hasta hoy).'); }
    return $fecha;
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'config':
        //Los días guardados no van todos aquí (con el tiempo serían cientos): van los meses, y la
        //pantalla pide los días del mes que se esté viendo
        echo hanaJsonTexto(array('hoy' => $hoy, 'puntos' => ReporteGerencia::puntos(), 'automaticos' => array('FLOTA', 'PROMESA', 'OFICIOS', 'NOVEDADES'),
                                 'meses' => ReporteGerencia::mesesGuardados()),
                           JSON_UNESCAPED_UNICODE);
        break;

    //Los días guardados de un mes: {mes: "2026-11", dias: ["2026-11-02", ...]}
    case 'guardados':
        $mes = isset($_GET['mes']) ? (string)$_GET['mes'] : '';
        if (!preg_match('/^(20[2-9]\d)-(0[1-9]|1[0-2])$/', $mes)) { gerError(400, 'Elige un mes válido.'); }
        echo hanaJsonTexto(array('mes' => $mes, 'dias' => ReporteGerencia::diasGuardadosMes($mes)), JSON_UNESCAPED_UNICODE);
        break;

    //El reporte de un día. Si ya se guardó, va lo guardado; «sistema» trae siempre lo que propone el
    //sistema con los datos de ESE día (el elegido, no el de hoy), para volver a traer un punto o todo el reporte
    case 'abrir':
        $fecha = gerFecha(isset($_GET['fecha']) ? $_GET['fecha'] : '', $hoy);
        $puntos = ReporteGerencia::puntos();
        $sistema = ReporteGerencia::prellenar($proyectos, $centros, $fecha, $conNovedades);
        $guardado = ReporteGerencia::guardado($fecha);
        $titulosGuardados = $guardado && isset($guardado['datos']['puntos']) && is_array($guardado['datos']['puntos']) ? $guardado['datos']['puntos'] : array();
        //Los puntos que tenía lo guardado y ya no están en la lista siguen saliendo, para no perder lo escrito
        foreach ($titulosGuardados as $codigo => $titulo) { if (!isset($puntos[$codigo])) { $puntos[$codigo] = (string)$titulo; } }
        $lista = array();
        foreach ($proyectos as $idProyecto => $nombre) {
            $textosGuardados = $guardado && isset($guardado['datos']['proyectos'][$idProyecto]['textos']) ? $guardado['datos']['proyectos'][$idProyecto]['textos'] : null;
            $textos = array(); $propuestos = array();
            foreach ($puntos as $codigo => $titulo) {
                $propuestos[$codigo] = isset($sistema[$idProyecto][$codigo]) ? $sistema[$idProyecto][$codigo] : '';
                $textos[$codigo] = is_array($textosGuardados) ? (isset($textosGuardados[$codigo]) ? (string)$textosGuardados[$codigo] : '') : $propuestos[$codigo];
            }
            $lista[] = array('id' => $idProyecto, 'nombre' => $nombre, 'textos' => $textos, 'sistema' => $propuestos,
                             'enGuardado' => is_array($textosGuardados));
        }
        //De lo guardado solo va quién y cuándo (no cuántas veces se ha guardado: no le sirve a nadie)
        $mes = substr($fecha, 0, 7);
        //Notas adicionales: lo que se haya escrito ese día (no lo propone el sistema: es texto libre)
        $otros = $guardado && isset($guardado['datos']['otros']) ? (string)$guardado['datos']['otros'] : '';
        echo hanaJsonTexto(array('fecha' => $fecha, 'diaCorto' => ReporteGerencia::diaCorto($fecha), 'puntos' => $puntos, 'proyectos' => $lista,
                                 'otros' => $otros, 'tituloOtros' => ReporteGerencia::TITULO_OTROS,
                                 'guardado' => $guardado ? array('quien' => $guardado['quien'], 'fecha' => $guardado['fecha']) : null,
                                 'mes' => $mes, 'guardadosMes' => ReporteGerencia::diasGuardadosMes($mes)),
                           JSON_UNESCAPED_UNICODE);
        break;

    //Guarda el reporte del día (reemplaza lo que había de ese día). Llega como JSON:
    //{fecha, textos: {idProyecto: {CODIGO: texto}}, otros: texto}
    case 'guardar':
        $entrada = json_decode(isset($_POST['reporte']) ? (string)$_POST['reporte'] : '', true);
        if (!is_array($entrada) || !isset($entrada['textos']) || !is_array($entrada['textos'])) { gerError(400, 'No llegó el reporte. Recarga la página e intenta de nuevo.'); }
        $fecha = gerFecha(isset($entrada['fecha']) ? $entrada['fecha'] : '', $hoy);
        $puntos = ReporteGerencia::puntos();
        $anterior = ReporteGerencia::guardado($fecha);
        //Los títulos: los de la lista de hoy, más los que traía lo guardado y ya no están en la lista
        if ($anterior && isset($anterior['datos']['puntos']) && is_array($anterior['datos']['puntos'])) {
            foreach ($anterior['datos']['puntos'] as $codigo => $titulo) { if (!isset($puntos[$codigo])) { $puntos[$codigo] = (string)$titulo; } }
        }
        //Se conservan los proyectos de la versión anterior que esta persona no tiene (otro director con otros peajes)
        $guardarProyectos = array();
        if ($anterior && isset($anterior['datos']['proyectos']) && is_array($anterior['datos']['proyectos'])) {
            foreach ($anterior['datos']['proyectos'] as $idProyecto => $datosProyecto) {
                if (!isset($proyectos[(int)$idProyecto])) { $guardarProyectos[(int)$idProyecto] = $datosProyecto; }
            }
        }
        foreach ($proyectos as $idProyecto => $nombre) {
            if (!isset($entrada['textos'][$idProyecto]) || !is_array($entrada['textos'][$idProyecto])) { continue; }
            $textos = array();
            foreach ($puntos as $codigo => $titulo) {
                $valor = isset($entrada['textos'][$idProyecto][$codigo]) ? (string)$entrada['textos'][$idProyecto][$codigo] : '';
                $valor = str_replace("\r\n", "\n", $valor);
                if (mb_strlen($valor, 'UTF-8') > ReporteGerencia::LARGO_PUNTO) {
                    gerError(400, '«' . $titulo . '» de ' . $nombre . ' pasa de ' . ReporteGerencia::LARGO_PUNTO . ' caracteres. Acórtalo un poco.');
                }
                $textos[$codigo] = trim($valor);
            }
            $guardarProyectos[$idProyecto] = array('nombre' => $nombre, 'textos' => $textos);
        }
        //Notas adicionales: texto libre de todo el reporte (cualquier cosa que se quiera agregar)
        $otros = trim(str_replace("\r\n", "\n", isset($entrada['otros']) ? (string)$entrada['otros'] : ''));
        if (mb_strlen($otros, 'UTF-8') > ReporteGerencia::LARGO_PUNTO) {
            gerError(400, '«' . ReporteGerencia::TITULO_OTROS . '» pasa de ' . ReporteGerencia::LARGO_PUNTO . ' caracteres. Acórtalo un poco.');
        }
        $datos = array('version' => 1, 'fecha' => $fecha, 'puntos' => $puntos, 'proyectos' => $guardarProyectos, 'otros' => $otros);
        $ahora = date('Y-m-d H:i:s');
        if (!ReporteGerencia::cabe($datos)) {
            gerError(400, 'El reporte quedó demasiado largo para guardarlo. Acorta algunos puntos e intenta de nuevo.');
        }
        if (!ReporteGerencia::guardar($fecha, $datos, $idColaborador, $ahora)) {
            gerError(500, 'No se pudo guardar el reporte. Intenta de nuevo en un momento.');
        }
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Reporte del ' . ReporteGerencia::diaCorto($fecha) . ' guardado.', 'fecha' => $ahora), JSON_UNESCAPED_UNICODE);
        break;

    default:
        gerError(400, 'Operación no reconocida.');
}
