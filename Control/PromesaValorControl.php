<?php
/*
  HANA - Promesa de valor en horas (Reporte diario)
  ---------------------------------------------------------------------------
    op=config                          los proyectos que ve y las reglas
    op=matriz&proyecto&centro&anio&mes la matriz del mes de un peaje
    op=guardar (POST)                  las casillas que cambiaron

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
        $idCentro = isset($_GET['centro']) ? (int)$_GET['centro'] : 0;
        if (!in_array($idCentro, array_map('intval', array_column($centros, 'ID_CENTRO_OP')), true)) { $idCentro = (int)$centros[0]['ID_CENTRO_OP']; }
        $centro = pvCentro($idCentro, $proyectos);

        $totalDias = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes)));
        $dias = array();
        for ($dia = 1; $dia <= $totalDias; $dia++) {
            $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
            $dias[] = array('dia' => $dia, 'fecha' => $fecha, 'semana' => (int)date('N', strtotime($fecha)), 'festivo' => HanaFechas::esFestivo($fecha));
        }
        $coordinador = Organizacion::coordinadorDe($idProyecto);
        echo hanaJsonTexto(array(
            'centros' => $centros,
            'centro' => $centro,
            'cargos' => PromesaValor::cargos($centro['TIPO_CENTRO']),
            'dias' => $dias,
            'casillas' => PromesaValor::mes($idCentro, $anio, $mes),
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
            $listas[] = array($idCargo, $fecha, $promesa, $ejecutado, $justificacion === '' ? null : $justificacion);
        }
        //Cómo estaba cada casilla antes de guardar, para saber cuáles quedan por debajo ahora
        $antes = array();
        foreach (PromesaValor::mes((int)$centro['ID_CENTRO_OP'], $anio, $mes) as $fila) {
            $antes[(int)$fila['ID_CARGO_AUS'] . '-' . (int)$fila['DIA']] = PromesaValor::estado($fila['PROMESA_HORAS'], $fila['EJECUTADO_HORAS']);
        }
        $ahora = date('Y-m-d H:i:s');
        HanaDB::iniciar();
        foreach ($listas as $l) {
            if (!PromesaValor::guardar((int)$centro['ID_CENTRO_OP'], $l[0], $l[1], $l[2], $l[3], $l[4], $idColaborador, $ahora)) {
                HanaDB::deshacer();
                pvError(500, 'No se pudo guardar. No cambió nada; inténtalo de nuevo.');
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
                                 . ($avisados ? ' Quedó por debajo de la promesa: se avisó por correo a ' . ($avisados === 1 ? '1 persona.' : $avisados . ' personas.') : '')), JSON_UNESCAPED_UNICODE);
        break;

    default:
        pvError(400, 'Operación no reconocida.');
}
