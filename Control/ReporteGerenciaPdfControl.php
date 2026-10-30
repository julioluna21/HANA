<?php
/*
  HANA - Reporte diario a la Gerencia en PDF: revisa el permiso y arma los datos
  ---------------------------------------------------------------------------
  Lo usa Vista/ReporteGerenciaPdfVista.php. Revisa la sesión, la casilla 61M
  (o 24M) y que el módulo esté encendido, y deja en $reporte todo lo que el PDF
  necesita:
    - fecha, fechaLarga y los puntos del reporte (títulos en su orden);
    - por proyecto: su nombre, el texto de cada punto y las cifras del día
      (ReporteGerencia::calcular), que el PDF usa para porcentajes y gráficas;
    - cómo quedó el texto: 'guardado' (quién y cuándo), 'pantalla' (lo que se
      estaba editando, sin guardar) o 'sistema' (lo que propone el sistema).

  Dos formas de pedirlo:
    GET  ?fecha=AAAA-MM-DD           lo guardado ese día, o lo que propone el sistema
    POST reporte={fecha, textos}     lo que está en la pantalla en ese momento (el mismo
                                     formato de «Guardar»): sale tal cual, sin guardarlo
  Solo salen los proyectos de los peajes asignados a la persona.
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Bogota');

//Responde un mensaje sencillo cuando no se puede generar
function gerPdfError($codigo, $mensaje)
{
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    echo $mensaje;
    exit;
}

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/AccesoHelper.php';
require_once __DIR__ . '/../Modelo/ReporteGerenciaModelo.php';
require_once __DIR__ . '/../Modelo/HanaFechas.php';

if (!HanaConfig::modulo('GERENCIA')) { gerPdfError(403, 'El reporte a la Gerencia está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
hanaUsarModulo('GERENCIA');
if (!hanaPuede('GERENCIA', 'armar')) { gerPdfError(403, 'Tu rol no tiene la casilla «Reporte diario a la Gerencia» (Configuración → Permisos de usuario).'); }

$hoy = HanaFechas::hoy();
$centros = array(); $proyectos = array();
foreach (hanaMisCentros() as $centro) {
    $centros[(int)$centro['ID_CENTRO_OP']] = $centro;
    $proyectos[(int)$centro['ID_PROYECTO']] = $centro['NOM_PROYECTO'];
}
if (!count($proyectos)) { gerPdfError(403, 'No tienes peajes asignados. Pide que te los asignen en Configuración → Colaboradores.'); }
asort($proyectos);
$conNovedades = hanaTienePermiso('16M') || hanaTienePermiso('24M');

//Lo que llegó de la pantalla (POST) o solo la fecha (GET)
$enviado = null;
if (isset($_POST['reporte'])) {
    $enviado = json_decode((string)$_POST['reporte'], true);
    if (!is_array($enviado) || !isset($enviado['textos']) || !is_array($enviado['textos'])) { gerPdfError(400, 'No llegó el reporte. Recarga la página e intenta de nuevo.'); }
    $fecha = HanaVal::fecha(isset($enviado['fecha']) ? $enviado['fecha'] : '');
} else {
    $fecha = HanaVal::fecha(isset($_GET['fecha']) ? $_GET['fecha'] : $hoy);
}
if ($fecha === '' || $fecha > $hoy || $fecha < '2020-01-01') { gerPdfError(400, 'Elige un día válido (hasta hoy).'); }

//Los puntos: los de la lista de hoy, más los que traiga lo guardado ese día y ya no estén en la lista
$puntos = ReporteGerencia::puntos();
$guardado = ReporteGerencia::guardado($fecha);
if ($guardado && isset($guardado['datos']['puntos']) && is_array($guardado['datos']['puntos'])) {
    foreach ($guardado['datos']['puntos'] as $codigo => $titulo) { if (!isset($puntos[$codigo])) { $puntos[$codigo] = (string)$titulo; } }
}

$cifras = ReporteGerencia::calcular($proyectos, $centros, $fecha, $conNovedades);
$sistema = ReporteGerencia::textos($cifras, $fecha);
$lista = array();
foreach ($proyectos as $idProyecto => $nombre) {
    $textosGuardados = $guardado && isset($guardado['datos']['proyectos'][$idProyecto]['textos']) ? $guardado['datos']['proyectos'][$idProyecto]['textos'] : null;
    $textos = array();
    foreach ($puntos as $codigo => $titulo) {
        if ($enviado !== null) {
            $valor = isset($enviado['textos'][$idProyecto][$codigo]) ? (string)$enviado['textos'][$idProyecto][$codigo] : '';
        } elseif (is_array($textosGuardados)) {
            $valor = isset($textosGuardados[$codigo]) ? (string)$textosGuardados[$codigo] : '';
        } else {
            $valor = isset($sistema[$idProyecto][$codigo]) ? $sistema[$idProyecto][$codigo] : '';
        }
        $valor = trim(str_replace("\r\n", "\n", $valor));
        if (mb_strlen($valor, 'UTF-8') > ReporteGerencia::LARGO_PUNTO) { $valor = mb_substr($valor, 0, ReporteGerencia::LARGO_PUNTO, 'UTF-8'); }
        $textos[$codigo] = $valor;
    }
    $lista[] = array('id' => $idProyecto, 'nombre' => $nombre, 'textos' => $textos, 'cifras' => $cifras[$idProyecto]);
}

//Quién lo genera: la persona que lo descarga, con su rol (salen en el pie de cada hoja)
$quien = isset($_SESSION['Nombre']) ? html_entity_decode($_SESSION['Nombre'], ENT_QUOTES, 'UTF-8') : '';
$filaRol = HanaDB::fila("SELECT NOM_ROL_USUARIO_SISTEMA AS ROL FROM rol_usuarios_sistemas WHERE ID_ROL_USUARIO_SISTEMA = ?", 'i', array(hanaIdRol()));
$rol = $filaRol ? html_entity_decode((string)$filaRol['ROL'], ENT_QUOTES, 'UTF-8') : '';
$reporte = array(
    'fecha'      => $fecha,
    'fechaLarga' => ReporteGerencia::fechaLarga($fecha),
    'lunes'      => ReporteGerencia::lunesDe($fecha),
    'puntos'     => $puntos,
    'proyectos'  => $lista,
    'origen'     => $enviado !== null ? 'pantalla' : ($guardado ? 'guardado' : 'sistema'),
    'guardado'   => $guardado ? array('quien' => (string)$guardado['quien'], 'fecha' => $guardado['fecha']) : null,
    'plazoOficios' => max(0, HanaConfig::num('COMUNICACION_DIAS_RESPUESTA', 2)),
    'conNovedades' => $conNovedades,
);
