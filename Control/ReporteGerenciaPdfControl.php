<?php
/*
  HANA - Reporte diario a la Gerencia en PDF: revisa el permiso y arma los datos
  ---------------------------------------------------------------------------
  Lo usa Vista/ReporteGerenciaPdfVista.php. Revisa la sesión, la casilla 61M
  (o 24M) y que el módulo esté encendido, y deja en $reporte todo lo que el PDF
  necesita:
    - fecha, desde, la fecha y el título del informe;
    - el reporte con su estructura (encabezado, resultados, prioritarias,
      proyectos, transversal y compromisos), los nombres de los proyectos;
    - las cifras de la promesa de valor: el porcentaje y los peajes que cumplen se
      LEEN DEL TEXTO de los resultados tal como quedó (si la dirección lo corrigió,
      el PDF muestra lo corregido); la gráfica por proyecto sale de la base;
    - cómo quedó el texto: 'guardado' (quién y cuándo), 'pantalla' (lo que se
      estaba editando, sin guardar) o 'sistema' (lo que propone el sistema).

  Dos formas de pedirlo:
    GET  ?fecha=AAAA-MM-DD[&desde=AAAA-MM-DD]  lo guardado de ese día o periodo, o lo que propone el sistema
    POST reporte={fecha, desde, encabezado, ...}  lo que está en la pantalla en ese momento (el mismo
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
    if (!is_array($enviado) || !isset($enviado['resultados'])) { gerPdfError(400, 'No llegó el reporte. Recarga la página e intenta de nuevo.'); }
    $fecha = HanaVal::fecha(isset($enviado['fecha']) ? $enviado['fecha'] : '');
    $desde = HanaVal::fecha(isset($enviado['desde']) ? (string)$enviado['desde'] : '');
} else {
    $fecha = HanaVal::fecha(isset($_GET['fecha']) ? $_GET['fecha'] : $hoy);
    $desde = HanaVal::fecha(isset($_GET['desde']) ? (string)$_GET['desde'] : '');
}
if ($fecha === '' || $fecha > $hoy || $fecha < '2020-01-01') { gerPdfError(400, 'Elige un día válido (hasta hoy).'); }
//Rango de días: sin «desde» (o igual al último día) es un solo día. Máximo 31 días
if ($desde === '' || $desde >= $fecha) { $desde = $fecha; }
if ((strtotime($fecha) - strtotime($desde)) / 86400 + 1 > 31) { gerPdfError(400, 'El reporte puede cubrir hasta 31 días. Acorta el rango.'); }

$guardado = ReporteGerencia::guardado($fecha, $desde);
//Lo que propone el sistema con los datos de la base: es el reporte cuando no hay nada guardado (ni llegó
//el de la pantalla), y de ahí salen las cifras que el texto no diga y la gráfica de la promesa por proyecto
$base = ReporteGerencia::calcular($proyectos, $centros, $fecha, $conNovedades, $desde);
if ($enviado !== null) { $contenido = ReporteGerencia::normalizar($enviado, $proyectos); }
elseif ($guardado) { $contenido = ReporteGerencia::deGuardado($guardado['datos'], $proyectos); }
else { $contenido = ReporteGerencia::normalizar(ReporteGerencia::propuesta($proyectos, $centros, $fecha, $conNovedades, $desde) + array('encabezado' => ReporteGerencia::ultimoEncabezado()), $proyectos); }

//Las cifras de la promesa: las del texto de los resultados (o de la base, si el texto no las dice)
$promesa = ReporteGerencia::cifrasResultados($contenido['resultados'], ReporteGerencia::totalesPromesa($base));
//La promesa de cada proyecto para la gráfica: [nombre, porcentaje o null, nota, rótulo («87,5 % · 1 de 2 peajes»)]
$porProyecto = array();
foreach ($proyectos as $idProyecto => $nombre) {
    $totales = ReporteGerencia::totalesPromesa(array($base[$idProyecto]));
    if (!$totales['hay']) { $rotulo = 'Sin promesa'; }
    elseif (!$totales['peajes']) { $rotulo = 'Pendiente'; }
    else { $rotulo = ReporteGerencia::porcentaje($totales['ejecutadas'], $totales['prometidas']) . ' · ' . $totales['cumplen'] . ' de ' . $totales['peajes'] . ($totales['peajes'] === 1 ? ' peaje' : ' peajes'); }
    $porProyecto[] = array($nombre, $totales['pct'], '', $rotulo);
}

//Quién lo genera: la persona que lo descarga
$quien = isset($_SESSION['Nombre']) ? html_entity_decode($_SESSION['Nombre'], ENT_QUOTES, 'UTF-8') : '';
$filaRol = HanaDB::fila("SELECT NOM_ROL_USUARIO_SISTEMA AS ROL FROM rol_usuarios_sistemas WHERE ID_ROL_USUARIO_SISTEMA = ?", 'i', array(hanaIdRol()));
$rol = $filaRol ? html_entity_decode((string)$filaRol['ROL'], ENT_QUOTES, 'UTF-8') : '';
$reporte = array(
    'fecha'        => $fecha,
    'desde'        => $desde,          //igual a fecha cuando es un solo día
    'esRango'      => $desde < $fecha,
    'fechaLarga'   => ReporteGerencia::periodoLargo($desde, $fecha), //"jueves, 29 de octubre de 2026" o "del lunes 26 al jueves 29 de octubre de 2026"
    'fechaInforme' => ReporteGerencia::fechaInforme($desde, $fecha),
    'titulo'       => ReporteGerencia::tituloInforme($desde, $fecha),
    'tituloPromesa' => ReporteGerencia::tituloPromesa($desde, $fecha),
    'secciones'    => ReporteGerencia::SECCIONES,
    'nombres'      => $proyectos,
    'contenido'    => $contenido,
    'promesa'      => $promesa,
    'promesaProyectos' => $porProyecto,
    'origen'       => $enviado !== null ? 'pantalla' : ($guardado ? 'guardado' : 'sistema'),
    'guardado'     => $guardado ? array('quien' => (string)$guardado['quien'], 'fecha' => $guardado['fecha']) : null,
);
