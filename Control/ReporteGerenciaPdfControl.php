<?php
/*
  HANA - Reporte diario a la Gerencia en PDF: revisa el permiso y arma los datos
  ---------------------------------------------------------------------------
  Lo usa Vista/ReporteGerenciaPdfVista.php. Revisa la sesión, la casilla 61M
  (o 24M) y que el módulo esté encendido, y deja en $reporte todo lo que el PDF
  necesita:
    - fecha, desde, la fecha y el título del informe;
    - el reporte con su estructura (encabezado, resultados, prioritarias,
      proyectos, transversal, compromisos, vacantes y su nota), los nombres
      de los proyectos;
    - las cifras de la promesa de valor: el porcentaje y los centros de operación que cumplen se
      LEEN DEL TEXTO de los resultados tal como quedó (si la dirección lo corrigió,
      el PDF muestra lo corregido); la gráfica por proyecto sale de la línea «Por
      proyecto: …» del mismo texto (o de lo registrado, si el texto no la tiene);
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
//el de la pantalla), y la gráfica por proyecto si el texto no la dice
$base = ReporteGerencia::calcular($proyectos, $centros, $fecha, $conNovedades, $desde);
$sistema = null;
if ($enviado !== null) { $contenido = ReporteGerencia::normalizar($enviado, $proyectos); }
elseif ($guardado) { $contenido = ReporteGerencia::deGuardado($guardado['datos'], $proyectos); }
else {
    $sistema = ReporteGerencia::propuesta($proyectos, $centros, $fecha, $conNovedades, $desde);
    $contenido = ReporteGerencia::normalizar($sistema + array('encabezado' => ReporteGerencia::ultimoEncabezado()), $proyectos);
}
//Un reporte guardado antes de que existiera la tabla de vacantes la toma del sistema (con su nota)
if (!is_array($contenido['vacantes'])) {
    if ($sistema === null) { $sistema = ReporteGerencia::propuesta($proyectos, $centros, $fecha, $conNovedades, $desde); }
    $contenido['vacantes'] = $sistema['vacantes'];
    $contenido['notaVacantes'] = $sistema['notaVacantes'];
}
//«con corte al …» de la presentación queda con el día del reporte
$contenido['encabezado']['presentacion'] = ReporteGerencia::presentacionAlCorte($contenido['encabezado']['presentacion'], $fecha);
//Los peajes de la tabla de vacantes escritos a mano salen con su nombre del sistema
$contenido = ReporteGerencia::corregirCentros($contenido, $centros);

//La gráfica por proyecto sale de lo que dice el texto de los resultados (la línea «Por proyecto: ICCU 97,5 %
//(4 de 5 peajes) · …»); esa línea no se repite en el párrafo. Si el texto no la tiene, sale de lo registrado
list($leidos, $resultadosSinProyectos) = ReporteGerencia::proyectosDelTexto($contenido['resultados'], $proyectos);
$sistemaPorProyecto = array();
foreach ($proyectos as $idProyecto => $nombre) { $sistemaPorProyecto[$idProyecto] = ReporteGerencia::totalesPromesa(array($base[$idProyecto])); }
$porProyecto = array();
foreach ($proyectos as $idProyecto => $nombre) {
    if (count($leidos)) {
        $l = isset($leidos[$idProyecto]) ? $leidos[$idProyecto] : null;
        if ($l === null) { $porProyecto[] = array($nombre, null, '', 'Sin promesa'); continue; }
        if ($l['pct'] === null) { $porProyecto[] = array($nombre, null, '', $l['pendiente'] ? 'Pendiente' : 'Sin promesa'); continue; }
        $rotulo = ReporteGerencia::porcentaje($l['pct'], 100) . ($l['peajes'] ? ' · ' . (int)$l['cumplen'] . ' de ' . $l['peajes'] . ' ' . ReporteGerencia::centros($l['peajes']) : '');
        $porProyecto[] = array($nombre, $l['pct'], '', $rotulo);
        continue;
    }
    $t = $sistemaPorProyecto[$idProyecto];
    if (!$t['hay']) { $rotulo = 'Sin promesa'; }
    elseif (!$t['medidos']) { $rotulo = 'Pendiente'; }
    else { $rotulo = ReporteGerencia::porcentaje($t['ejecutadas'], $t['prometidas']) . ' · ' . $t['cumplen'] . ' de ' . $t['peajes'] . ' ' . ReporteGerencia::centros($t['peajes']); }
    $porProyecto[] = array($nombre, $t['pct'], '', $rotulo);
}
//Las cifras de la promesa (la cifra grande): las del texto de los resultados o, si el texto no las dice, las registradas
$promesa = ReporteGerencia::cifrasResultados($contenido['resultados'], ReporteGerencia::totalesPromesa($base));

//Quién lo genera: la persona que lo descarga
$quien = isset($_SESSION['Nombre']) ? html_entity_decode($_SESSION['Nombre'], ENT_QUOTES, 'UTF-8') : '';
$filaRol = HanaDB::fila("SELECT NOM_ROL_USUARIO_SISTEMA AS ROL FROM rol_usuarios_sistemas WHERE ID_ROL_USUARIO_SISTEMA = ?", 'i', array(hanaIdRol()));
$rol = $filaRol ? html_entity_decode((string)$filaRol['ROL'], ENT_QUOTES, 'UTF-8') : '';
$reporte = array(
    'fecha'        => $fecha,
    'desde'        => $desde,          //igual a fecha cuando es un solo día
    'esRango'      => $desde < $fecha,
    'fechaLarga'   => ReporteGerencia::periodoLargo($desde, $fecha), //"jueves, 29 de octubre de 2026" o "del lunes 26 al jueves 29 de octubre de 2026"
    'fechaInforme' => ReporteGerencia::fechaInforme($desde, $fecha), //"Corte: 20 de noviembre de 2026" o "Periodo: del 14 al 20 de …"
    'corte'        => ReporteGerencia::diaMesAnio($fecha),
    'titulo'       => ReporteGerencia::tituloInforme($desde, $fecha),
    'subtitulo'    => ReporteGerencia::SUBTITULO,
    'tituloPromesa' => ReporteGerencia::tituloPromesa($desde, $fecha, $centros),
    'secciones'    => ReporteGerencia::SECCIONES + ReporteGerencia::SECCIONES_SEGUIMIENTO,
    'nombres'      => $proyectos,
    'contenido'    => $contenido,
    'promesa'      => $promesa,
    'promesaProyectos' => $porProyecto,
    //El párrafo de los resultados sin la línea por proyecto (esa va en la gráfica) ni los «Plan de cobertura:»
    //que quedaron sin llenar
    'resultadosTexto' => trim(preg_replace('/^[ \t]*' . preg_quote(ReporteGerencia::PLAN_COBERTURA, '/') . '[ \t]*$\n?/imu', '',
                                           count($leidos) ? $resultadosSinProyectos : $contenido['resultados'])),
    'origen'       => $enviado !== null ? 'pantalla' : ($guardado ? 'guardado' : 'sistema'),
    'guardado'     => $guardado ? array('quien' => (string)$guardado['quien'], 'fecha' => $guardado['fecha']) : null,
);
