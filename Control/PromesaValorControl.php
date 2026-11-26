<?php
/*
  HANA - Promesa de valor en horas (Reporte diario)
  ---------------------------------------------------------------------------
    op=config                          los proyectos que ve y las reglas
    op=matriz&proyecto&centro&anio&mes la matriz del mes de un peaje; con centro=-1, la de todo el
                                       proyecto (sus peajes sumados), solo para consultar
    op=guardar (POST)                  las casillas que cambiaron
    op=excel&proyecto&anio&mes         descarga el mes en Excel (proyecto=0: todos los que ve). Con la
                                       casilla 63M (o el administrador, 24M)

  Quién: quien tiene 54M llena promesa, ejecutado y justificación de sus peajes
  (desde hoy menos PROMESA_DIAS_CORREGIBLES en adelante; el ejecutado, hasta
  hoy). Con 55M se consulta, también solo en sus peajes. Con 56M (o el
  administrador, 24M) se corrige cualquier día.
  Las reglas están en Control/AccesoHelper.php (hanaPromesa...).
*/
session_start();
require_once __DIR__ . "/../Modelo/PromesaValorModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php';
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

function pvError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { pvError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!HanaConfig::modulo('PROMESA')) { pvError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
if (!hanaPromesaEntrar()) { pvError(403, 'No tienes acceso a la promesa de valor.'); }

$idColaborador = (int)$_SESSION['Idcolaborador'];
$hoy = HanaFechas::hoy();
$proyectos = array();
foreach (hanaPromesaProyectos() as $proyecto) { $proyectos[(int)$proyecto['ID_PROYECTO']] = $proyecto; }

//El mes pedido (del año pasado al siguiente)
function pvMes()
{
    $anio = isset($_REQUEST['anio']) ? (int)$_REQUEST['anio'] : (int)date('Y');
    $mes  = isset($_REQUEST['mes'])  ? (int)$_REQUEST['mes']  : (int)date('n');
    if ($mes < 1 || $mes > 12 || $anio < (int)date('Y') - 1 || $anio > (int)date('Y') + 1) { pvError(400, 'El mes no es válido.'); }
    return array($anio, $mes);
}

//Un peaje activo de un proyecto que la persona ve
function pvCentro($idCentro, $proyectos)
{
    $centro = HanaDB::fila("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, c.ID_PROYECTO FROM centros_operacion c
                             WHERE c.ID_CENTRO_OP = ? AND c.ESTADO = '1'", 'i', array((int)$idCentro));
    if (!$centro || !isset($proyectos[(int)$centro['ID_PROYECTO']]) || !isset(hanaMisCentrosIds()[(int)$centro['ID_CENTRO_OP']])) { pvError(403, 'No tienes acceso a ese peaje.'); }
    return $centro;
}

//Horas escritas en la casilla: vacío = null; si no, un número entre 0 y 9999 con hasta dos decimales
function pvHoras($valor, $que, $dia)
{
    $valor = str_replace(',', '.', trim((string)$valor));
    if ($valor === '') { return null; }
    if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $valor)) { pvError(400, "El día $dia tiene un valor de $que que no es un número de horas válido: «{$valor}»."); }
    return round((float)$valor, 2);
}

//El proyecto de un peaje, solo si es uno de los proyectos que la persona puede ver (o null)
function pvProyectoDelCentro($idCentro, $proyectos)
{
    if ($idCentro <= 0) { return null; }
    $fila = HanaDB::fila("SELECT ID_PROYECTO FROM centros_operacion WHERE ID_CENTRO_OP = ?", 'i', array($idCentro));
    return ($fila && isset($proyectos[(int)$fila['ID_PROYECTO']]) && isset(hanaMisCentrosIds()[$idCentro])) ? (int)$fila['ID_PROYECTO'] : null;
}

//Comentario espejo: pone o quita el pedazo en la casilla del otro peaje. $c = [accion, peaje, cargo, pedazo]
//(ver PromesaValor::cambiosEspejo). Solo en un peaje que la persona llena y en un día que puede cambiar
//allá; si no, queda anotado en $resultado['no'] y no se toca. Devuelve false solo si falla la base de datos
function pvAnotarEspejo($c, $fecha, $idCentroPropio, $idColaborador, $ahora, &$resultado)
{
    list($accion, $peaje, $cargo, $pedazo) = $c;
    $donde = $peaje . ' · ' . $cargo . ' (día ' . (int)substr($fecha, 8, 2) . ')';
    $destino = null;
    foreach ((array)HanaDB::q("SELECT ID_CENTRO_OP, NOM_CENTRO_OP, TIPO_CENTRO, ID_PROYECTO FROM centros_operacion WHERE ESTADO = '1'") as $f) {
        if (PromesaValor::mayus($f['NOM_CENTRO_OP']) === $peaje) { $destino = $f; break; }
    }
    if (!$destino || (int)$destino['ID_CENTRO_OP'] === $idCentroPropio) { $resultado['no'][] = $donde . ': no se encontró ese peaje'; return true; }
    $idCargo = 0;
    foreach (PromesaValor::cargos($destino['TIPO_CENTRO']) as $f) { if (PromesaValor::mayus($f['NOMBRE']) === $cargo) { $idCargo = (int)$f['ID_CARGO_AUS']; } }
    if (!$idCargo) { $resultado['no'][] = $donde . ': ese cargo no aplica allá'; return true; }
    $desde = hanaPromesaDesde((int)$destino['ID_PROYECTO'], (int)$destino['ID_CENTRO_OP']);
    if ($desde === null) { $resultado['no'][] = $donde . ': ese peaje no lo llenas tú, que lo anote quien lo llena'; return true; }
    if ($fecha < $desde) { $resultado['no'][] = $donde . ': ese día ya no se puede cambiar'; return true; }
    $fila = HanaDB::fila("SELECT PROMESA_HORAS, EJECUTADO_HORAS, JUSTIFICACION FROM pv_dia WHERE ID_CENTRO_OP = ? AND ID_CARGO_AUS = ? AND FECHA = ?",
                         'iis', array((int)$destino['ID_CENTRO_OP'], $idCargo, $fecha));
    if (!$fila && $accion === 'quitar') { return true; }
    $antes = $fila ? (string)$fila['JUSTIFICACION'] : '';
    $nuevo = PromesaValor::cambiarOpcion($antes, $pedazo, $accion === 'poner');
    if ($nuevo === $antes) { return true; }
    if (mb_strlen($nuevo, 'UTF-8') > 300) { $resultado['no'][] = $donde . ': no cabe (la justificación pasaría de 300 letras)'; return true; }
    $ok = PromesaValor::guardar((int)$destino['ID_CENTRO_OP'], $idCargo, $fecha,
                                $fila && $fila['PROMESA_HORAS'] !== null ? (float)$fila['PROMESA_HORAS'] : null,
                                $fila && $fila['EJECUTADO_HORAS'] !== null ? (float)$fila['EJECUTADO_HORAS'] : null,
                                $nuevo === '' ? null : $nuevo, $idColaborador, $ahora);
    if ($ok) { $resultado['ok'][] = $donde; }
    return (bool)$ok;
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'config':
        echo hanaJsonTexto(array(
            'hoy' => $hoy,
            'proyectos' => array_values($proyectos),
            'semanas' => PromesaValor::$SEMANAS,
            'diasCorregibles' => HanaConfig::num('PROMESA_DIAS_CORREGIBLES', 7),
            'corrigeTodo' => hanaPuede('PROMESA', 'pasados'),
            //Si viene de un aviso de la campana (?centro=), el proyecto de ese peaje, si lo puede ver
            'proyectoDelCentro' => pvProyectoDelCentro(isset($_GET['centro']) ? (int)$_GET['centro'] : 0, $proyectos)
        ), JSON_UNESCAPED_UNICODE);
        break;

    //Descarga el mes en Excel: no responde JSON sino el archivo .xlsx
    case 'excel':
        require_once __DIR__ . '/../Modelo/HanaExcel.php';
        if (!hanaPuede('EXCEL', 'descargar')) { HanaExcel::error(403, 'Tu rol no tiene el permiso para descargar en Excel (Permisos de usuario, casilla 63M).'); }
        $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
        $mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
        if ($mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2100) { HanaExcel::error(400, 'El mes no es válido.'); }
        $idProyecto = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
        $ids = array(); $alcance = 'Todos los proyectos';
        foreach (hanaMisCentros() as $centro) {
            if ($idProyecto > 0 && (int)$centro['ID_PROYECTO'] !== $idProyecto) { continue; }
            $ids[] = (int)$centro['ID_CENTRO_OP'];
            if ($idProyecto > 0) { $alcance = html_entity_decode((string)$centro['NOM_PROYECTO'], ENT_QUOTES, 'UTF-8'); }
        }
        if ($idProyecto > 0 && !count($ids)) { HanaExcel::error(403, 'No tienes acceso a ese proyecto.'); }
        $quien = isset($_SESSION['Nombre']) ? html_entity_decode($_SESSION['Nombre'], ENT_QUOTES, 'UTF-8') : '';
        $subtitulo = $alcance . ' · Descargado de HANA el ' . date('d/m/Y H:i') . ($quien !== '' ? ' por ' . $quien : '');
        HanaExcel::descargar('Promesa_de_valor_' . ($idProyecto > 0 ? $alcance . '_' : '') . sprintf('%04d-%02d', $anio, $mes),
                             PromesaValor::hojasExcel($ids, $anio, $mes, $subtitulo, $hoy));
        break;

    case 'matriz':
        list($anio, $mes) = pvMes();
        $idProyecto = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
        if (!isset($proyectos[$idProyecto])) { pvError(403, 'No tienes acceso a ese proyecto.'); }
        //Solo sus peajes de ese proyecto
        $mios = hanaMisCentrosIds();
        $centros = array_values(array_filter(Organizacion::centrosDeProyecto($idProyecto), function ($c) use ($mios) { return isset($mios[(int)$c['ID_CENTRO_OP']]); }));
        if (!count($centros)) { echo hanaJsonTexto(array('centros' => array()), JSON_UNESCAPED_UNICODE); break; }
        $resumen = PromesaValor::resumenCentros(array_map('intval', array_column($centros, 'ID_CENTRO_OP')), $anio, $mes, $hoy);
        foreach ($centros as $i => $c) {
            $centros[$i]['RESUMEN'] = isset($resumen[(int)$c['ID_CENTRO_OP']]) ? $resumen[(int)$c['ID_CENTRO_OP']] : null;
        }
        $totalDias = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes)));
        $dias = array();
        for ($dia = 1; $dia <= $totalDias; $dia++) {
            $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
            $dias[] = array('dia' => $dia, 'fecha' => $fecha, 'semana' => (int)date('N', strtotime($fecha)), 'festivo' => HanaFechas::esFestivo($fecha));
        }
        $coordinador = Organizacion::coordinadorDe($idProyecto);

        //Todo el proyecto: sus peajes sumados, por cargo y día. Es solo para consultar
        if (isset($_GET['centro']) && (int)$_GET['centro'] === -1) {
            $todo = PromesaValor::mesProyecto($centros, $anio, $mes, $hoy);
            echo hanaJsonTexto(array(
                'centros' => $centros,
                'centro' => array('ID_CENTRO_OP' => -1, 'NOM_CENTRO_OP' => 'Todo ' . $proyectos[$idProyecto]['NOM_PROYECTO'], 'TIPO_CENTRO' => 'PEAJE', 'ID_PROYECTO' => $idProyecto),
                'todo' => true,
                'cargos' => $todo['cargos'],
                'dias' => $dias,
                'casillas' => $todo['casillas'],
                'desde' => null,
                'hoy' => $hoy,
                'coordinador' => $coordinador ? Organizacion::nombre($coordinador) : null
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $idCentro = isset($_GET['centro']) ? (int)$_GET['centro'] : 0;
        if (!in_array($idCentro, array_map('intval', array_column($centros, 'ID_CENTRO_OP')), true)) { $idCentro = (int)$centros[0]['ID_CENTRO_OP']; }
        $centro = pvCentro($idCentro, $proyectos);
        echo hanaJsonTexto(array(
            'centros' => $centros,
            'centro' => $centro,
            'cargos' => PromesaValor::cargos($centro['TIPO_CENTRO']),
            'dias' => $dias,
            'casillas' => PromesaValor::mes($idCentro, $anio, $mes),
            //Lo que se cubrió con encargos o desde otro peaje (y lo que este peaje cubrió a otros)
            'coberturas' => PromesaValor::coberturasDelPeaje($idCentro, $anio, $mes),
            //Para las opciones de la justificación
            'peajes' => PromesaValor::peajesActivos(),
            'motivos' => array('debajo' => PromesaValor::$MOTIVOS_DEBAJO, 'encima' => PromesaValor::$MOTIVOS_ENCIMA),
            'desde' => hanaPromesaDesde($idProyecto, $idCentro),     //null = solo consulta
            'hoy' => $hoy,
            'coordinador' => $coordinador ? Organizacion::nombre($coordinador) : null
        ), JSON_UNESCAPED_UNICODE);
        break;

    //Llegan solo las casillas que cambiaron: celdas = JSON [{cargo, dia, promesa, ejecutado, justificacion}]
    case 'guardar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { pvError(405, 'Usa el botón Guardar.'); }
        list($anio, $mes) = pvMes();
        $centro = pvCentro(isset($_POST['centro']) ? (int)$_POST['centro'] : 0, $proyectos);
        $desde = hanaPromesaDesde((int)$centro['ID_PROYECTO'], (int)$centro['ID_CENTRO_OP']);
        if ($desde === null) { pvError(403, 'Este peaje lo llena el coordinador de su proyecto. Tú lo puedes consultar.'); }
        $celdas = json_decode(isset($_POST['celdas']) ? (string)$_POST['celdas'] : '', true);
        if (!is_array($celdas) || !count($celdas)) { pvError(400, 'No hay cambios para guardar.'); }
        if (count($celdas) > 31 * 20) { pvError(400, 'Son demasiadas casillas en un solo envío.'); }
        $totalDias = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes)));

        //Se revisa todo antes de guardar: o se guarda todo, o nada
        $listas = array();
        foreach ($celdas as $celda) {
            $dia = isset($celda['dia']) ? (int)$celda['dia'] : 0;
            $idCargo = isset($celda['cargo']) ? (int)$celda['cargo'] : 0;
            if ($dia < 1 || $dia > $totalDias) { pvError(400, 'Hay un día que no es del mes.'); }
            if (!PromesaValor::cargoValido($idCargo, $centro['TIPO_CENTRO'])) { pvError(400, 'Hay un cargo que no aplica a este peaje.'); }
            $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
            if ($fecha < $desde) { pvError(400, 'El día ' . $dia . ' ya no se puede cambiar: se corrige hasta ' . HanaConfig::num('PROMESA_DIAS_CORREGIBLES', 7) . ' días atrás.'); }
            $promesa = pvHoras(isset($celda['promesa']) ? $celda['promesa'] : '', 'promesa', $dia);
            $ejecutado = pvHoras(isset($celda['ejecutado']) ? $celda['ejecutado'] : '', 'ejecutado', $dia);
            if ($ejecutado !== null && $fecha > $hoy) { pvError(400, 'El día ' . $dia . ' todavía no ha pasado: el ejecutado se registra desde ese día.'); }
            $justificacion = trim(preg_replace('/\s+/u', ' ', isset($celda['justificacion']) ? (string)$celda['justificacion'] : ''));
            if (mb_strlen($justificacion, 'UTF-8') > 300) { pvError(400, 'La justificación del día ' . $dia . ' pasa de 300 letras.'); }
            //espejo: anotar el comentario que le toca a la casilla de otro peaje (lo pide la pantalla)
            $listas[] = array($idCargo, $fecha, $promesa, $ejecutado, $justificacion === '' ? null : $justificacion, !empty($celda['espejo']));
        }
        //Cómo estaba cada casilla antes de guardar, para saber cuáles quedan por debajo ahora y qué
        //cambió en la justificación (comentario espejo)
        $antes = array(); $antesJ = array();
        foreach (PromesaValor::mes((int)$centro['ID_CENTRO_OP'], $anio, $mes) as $fila) {
            $antes[(int)$fila['ID_CARGO_AUS'] . '-' . (int)$fila['DIA']] = PromesaValor::estado($fila['PROMESA_HORAS'], $fila['EJECUTADO_HORAS']);
            $antesJ[(int)$fila['ID_CARGO_AUS'] . '-' . (int)$fila['DIA']] = (string)$fila['JUSTIFICACION'];
        }
        $ahora = date('Y-m-d H:i:s');
        HanaDB::iniciar();
        foreach ($listas as $l) {
            if (!PromesaValor::guardar((int)$centro['ID_CENTRO_OP'], $l[0], $l[1], $l[2], $l[3], $l[4], $idColaborador, $ahora)) {
                HanaDB::deshacer();
                pvError(500, 'No se pudo guardar. No cambió nada; inténtalo de nuevo.');
            }
        }
        //Comentario espejo en otro peaje (el del mismo peaje ya viene en las casillas: lo anota la pantalla)
        $espejos = array('ok' => array(), 'no' => array());
        $nombresCargo = array();
        foreach (PromesaValor::cargos($centro['TIPO_CENTRO']) as $c) { $nombresCargo[(int)$c['ID_CARGO_AUS']] = PromesaValor::mayus($c['NOMBRE']); }
        foreach ($listas as $l) {
            if (!$l[5]) { continue; }
            $llave = $l[0] . '-' . (int)substr($l[1], 8, 2);
            $cambios = PromesaValor::cambiosEspejo(isset($antesJ[$llave]) ? $antesJ[$llave] : '', (string)$l[4], PromesaValor::mayus($centro['NOM_CENTRO_OP']), $nombresCargo[$l[0]]);
            foreach ($cambios as $c) {
                if ($c[1] === null) { continue; }
                if (!pvAnotarEspejo($c, $l[1], (int)$centro['ID_CENTRO_OP'], $idColaborador, $ahora, $espejos)) {
                    HanaDB::deshacer();
                    pvError(500, 'No se pudo guardar. No cambió nada; inténtalo de nuevo.');
                }
            }
        }
        HanaDB::confirmar();
        //Las casillas que acaban de quedar por debajo: aviso por correo si así está configurado
        $debajo = array();
        foreach ($listas as $l) {
            $llave = $l[0] . '-' . (int)substr($l[1], 8, 2);
            if (PromesaValor::estado($l[2], $l[3]) === 'DEBAJO' && (!isset($antes[$llave]) || $antes[$llave] !== 'DEBAJO')) { $debajo[] = $l; }
        }
        $avisados = 0;
        if (count($debajo)) {
            require_once __DIR__ . '/PromesaAvisoHelper.php';
            try { $avisados = pvAvisarPorCorreo($centro, $debajo, $idColaborador); }
            catch (Throwable $error) { error_log('HANA - aviso de promesa por correo: ' . $error->getMessage()); } //lo guardado queda guardado
        }
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => (count($listas) === 1 ? 'Se guardó 1 casilla.' : 'Se guardaron ' . count($listas) . ' casillas.')
                                 . ($avisados ? ' Quedó por debajo de la promesa: se avisó por correo a ' . ($avisados === 1 ? '1 persona.' : $avisados . ' personas.') : '')
                                 . (count($espejos['ok']) ? ' También se anotó el comentario en ' . implode('; ', array_unique($espejos['ok'])) . '.' : '')
                                 . (count($espejos['no']) ? ' No se anotó en ' . implode('; ', array_unique($espejos['no'])) . '.' : '')), JSON_UNESCAPED_UNICODE);
        break;

    default:
        pvError(400, 'Operación no reconocida.');
}
