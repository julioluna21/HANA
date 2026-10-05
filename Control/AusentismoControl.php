<?php
/*
  HANA — Controlador de ausentismo (Fase 3)
    - Registra el coordinador del proyecto, en los centros de su proyecto.
    - El jefe de peaje registra el de su peaje (si AUS_JEFES_EDITAN está
      encendido en Parámetros). No ve los demás peajes ni el consolidado.
    - Con 25M (ADMIN TEC, auditor interno) se edita el de todos los proyectos,
      incluso con el mes cerrado. Cada cambio queda en el historial (aus_cambio).
    - Consultan todos los proyectos: 20M (jefe mayor), 21M (administración)
      y 22M (consultar todos los proyectos).
    - Se corrige el mes actual y los anteriores que diga el parámetro
      AUS_MESES_CORREGIBLES, mientras el mes no esté cerrado.
    - El coordinador cierra el mes al entregarlo; lo reabre quien tenga 23M.
*/
session_start();
require_once __DIR__ . "/../Modelo/AusentismoModelo.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

function ausError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!HanaConfig::modulo('AUSENTISMO')) { ausError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { ausError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }

$idColaborador = (int)$_SESSION['Idcolaborador'];
$verTodos = hanaTienePermiso('20M') || hanaTienePermiso('21M') || hanaTienePermiso('22M');
$puedeReabrir = hanaTienePermiso('23M');
$editaTodo = hanaTienePermiso('25M'); //ADMIN TEC y auditor interno
//Los peajes de los que es jefe: [idCentro] = idProyecto (si el administrador deja que los jefes registren)
$jefeDe = array();
if (HanaConfig::si('AUS_JEFES_EDITAN')) {
    foreach ((array)HanaDB::q("SELECT ID_CENTRO_OP, ID_PROYECTO_CENTRO_OP FROM centros_operacion WHERE Estado = '1' AND ID_COLABORADOR_JEFE = ?", 'i', array($idColaborador)) as $c) {
        $jefeDe[(int)$c['ID_CENTRO_OP']] = (int)$c['ID_PROYECTO_CENTRO_OP'];
    }
}
if (!$verTodos && !$editaTodo && !HanaDB::esCoordinador($idColaborador) && !count($jefeDe)) {
    ausError(403, 'No tienes acceso al ausentismo. Lo registran el coordinador del proyecto y, si el administrador lo permite, el jefe de cada peaje.');
}
$A = new Ausentismo();
$hoy = date('Y-m-d');
$ahora = date('Y-m-d H:i:s');

//Los proyectos que ve, con sus centros y lo que puede hacer en cada uno:
//  COORDINA      es el coordinador del proyecto
//  VE_PROYECTO   ve todo el proyecto: el consolidado y el Excel (coordinador, 20M/21M/22M, 25M)
//  CENTROS[].EDITABLE  puede registrar en ese centro (coordinador, 25M, o el jefe de ese peaje)
//El jefe de peaje solo ve (y edita) sus peajes
function ausProyectos($idColaborador, $verTodos, $editaTodo, $jefeDe)
{
    $todos = $verTodos || $editaTodo;
    $f = HanaDB::q("SELECT p.ID_PROYECTO, p.NOM_PROYECTO, p.ID_COLABORADOR_COORDINADOR, col.NOM_COLABORADOR AS COORDINADOR
                      FROM proyectos p LEFT JOIN colaboradores col ON col.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR
                     WHERE p.Estado = '1' ORDER BY p.NOM_PROYECTO");
    $res = array();
    foreach ((array)$f as $p) {
        $id = (int)$p['ID_PROYECTO'];
        $coordina = (int)$p['ID_COLABORADOR_COORDINADOR'] === (int)$idColaborador;
        $esJefeAqui = in_array($id, $jefeDe, true);
        if (!$todos && !$coordina && !$esJefeAqui) { continue; }
        $p['COORDINA'] = $coordina;
        $p['VE_PROYECTO'] = $coordina || $todos;
        $c = HanaDB::q("SELECT ID_CENTRO_OP, NOM_CENTRO_OP, TIPO_CENTRO FROM centros_operacion
                         WHERE Estado = '1' AND ID_PROYECTO_CENTRO_OP = ? ORDER BY TIPO_CENTRO = 'BASCULA', NOM_CENTRO_OP",
                       'i', array($id));
        $centros = array();
        foreach ((array)$c as $x) {
            $suyo = isset($jefeDe[(int)$x['ID_CENTRO_OP']]);
            if (!$p['VE_PROYECTO'] && !$suyo) { continue; } //el jefe solo ve su peaje
            $x['EDITABLE'] = $coordina || $editaTodo || $suyo;
            $centros[] = $x;
        }
        $p['CENTROS'] = $centros;
        $p['EDITABLE'] = count(array_filter($centros, function ($x) { return $x['EDITABLE']; })) > 0;
        $res[$id] = $p;
    }
    return $res;
}

//El proyecto y el periodo pedidos, validados
function ausPeriodo()
{
    $anio = isset($_REQUEST['anio']) ? (int)$_REQUEST['anio'] : (int)date('Y');
    $mes  = isset($_REQUEST['mes'])  ? (int)$_REQUEST['mes']  : (int)date('n');
    if ($anio < 2020 || $anio > 2100 || $mes < 1 || $mes > 12) { ausError(400, 'El mes no es válido.'); }
    return array($anio, $mes);
}

//¿El mes está dentro de lo que se puede corregir? (el actual y los N anteriores; nunca el futuro).
//Con 25M, cualquier mes que ya empezó
function ausMesCorregible($anio, $mes, $editaTodo = false)
{
    $actual = (int)date('Y') * 12 + (int)date('n');
    $pedido = $anio * 12 + $mes;
    if ($pedido > $actual) { return false; }
    return $editaTodo || $pedido >= $actual - max(0, HanaConfig::num('AUS_MESES_CORREGIBLES', 1));
}

//¿Por qué no se puede editar este centro este mes? '' = sí se puede
function ausMotivo($proy, $centro, $cerrado, $anio, $mes, $editaTodo)
{
    if (!$centro['EDITABLE']) { return 'Solo consulta: este peaje lo registran su jefe y ' . ($proy['COORDINADOR'] ?: 'el coordinador del proyecto') . '.'; }
    if ($anio * 12 + $mes > (int)date('Y') * 12 + (int)date('n')) { return 'Este mes todavía no empieza.'; }
    if ($editaTodo) { return ''; } //25M corrige aunque el mes esté cerrado
    if ($cerrado) { return 'El mes está cerrado. Para corregirlo, hay que reabrirlo (permiso 23M).'; }
    if (!ausMesCorregible($anio, $mes)) { return 'Este mes ya no se puede corregir.'; }
    return '';
}

$proyectos = ausProyectos($idColaborador, $verTodos, $editaTodo, $jefeDe);
function ausProyecto($proyectos)
{
    $id = isset($_REQUEST['proyecto']) ? (int)$_REQUEST['proyecto'] : 0;
    if (!isset($proyectos[$id])) { ausError(403, 'Ese proyecto no está entre los que puedes ver.'); }
    return $proyectos[$id];
}
function ausCentro($proy)
{
    $id = isset($_REQUEST['centro']) ? (int)$_REQUEST['centro'] : 0;
    foreach ($proy['CENTROS'] as $c) { if ((int)$c['ID_CENTRO_OP'] === $id) { return $c; } }
    ausError(400, 'Ese peaje no es del proyecto o no está entre los que puedes registrar.');
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'config':
        $inicial = 0;
        foreach ($proyectos as $id => $p) { if ($p['EDITABLE']) { $inicial = $id; break; } }
        if (!$inicial && count($proyectos)) { $inicial = (int)key($proyectos); }
        echo json_encode(array(
            'hoy' => $hoy, 'proyectos' => array_values($proyectos), 'proyectoInicial' => $inicial,
            'cargos' => $A->cargos(), 'novedades' => $A->novedades(),
            'mesesCorregibles' => HanaConfig::num('AUS_MESES_CORREGIBLES', 1), 'puedeReabrir' => $puedeReabrir
        ), JSON_UNESCAPED_UNICODE);
        break;

    //Un centro en un mes: lo registrado y si se puede editar
    case 'mes':
        $proy = ausProyecto($proyectos);
        $centro = ausCentro($proy);
        list($anio, $mes) = ausPeriodo();
        $cierre = $A->cierre($proy['ID_PROYECTO'], $anio, $mes);
        $cerrado = $cierre && (int)$cierre['CERRADO'] === 1;
        $motivo = ausMotivo($proy, $centro, $cerrado, $anio, $mes, $editaTodo);
        echo json_encode(array(
            'registros' => (object)$A->mesCentro($centro['ID_CENTRO_OP'], $anio, $mes),
            'diasMes' => (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes))),
            'editable' => $motivo === '', 'motivo' => $motivo,
            'cerrado' => $cerrado, 'cierre' => $cierre, 'coordinador' => $proy['COORDINADOR'],
            'puedeCerrar' => ($proy['COORDINA'] || $editaTodo) && !$cerrado && ausMesCorregible($anio, $mes, $editaTodo),
            'veProyecto' => $proy['VE_PROYECTO'], 'editaTodo' => $editaTodo && $cerrado,
            'avance' => (object)$A->avanceCentros($proy['ID_PROYECTO'], $anio, $mes)
        ), JSON_UNESCAPED_UNICODE);
        break;

    //Guarda las celdas que cambiaron: celdas = [{cargo, novedad, dia, cantidad}]
    case 'guardar':
        $proy = ausProyecto($proyectos);
        $centro = ausCentro($proy);
        list($anio, $mes) = ausPeriodo();
        $cierre = $A->cierre($proy['ID_PROYECTO'], $anio, $mes);
        $motivo = ausMotivo($proy, $centro, $cierre && (int)$cierre['CERRADO'] === 1, $anio, $mes, $editaTodo);
        if ($motivo !== '') { ausError(403, $motivo); }

        $crudas = json_decode(isset($_POST['celdas']) ? $_POST['celdas'] : '[]', true);
        if (!is_array($crudas) || count($crudas) > 5000) { ausError(400, 'Los datos enviados no son válidos.'); }
        $dias = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes)));
        $cargos = array(); foreach ($A->cargos() as $c) { $cargos[(int)$c['ID_CARGO_AUS']] = $c; }
        $novedades = array(); foreach ($A->novedades() as $n) { $novedades[(int)$n['ID_NOVEDAD_AUS']] = $n; }
        $celdas = array();
        foreach ($crudas as $x) {
            $c = isset($x['cargo']) ? (int)$x['cargo'] : 0; $n = isset($x['novedad']) ? (int)$x['novedad'] : 0;
            $d = isset($x['dia']) ? (int)$x['dia'] : 0;     $v = isset($x['cantidad']) ? $x['cantidad'] : '';
            if (!isset($cargos[$c]) || !Ausentismo::cargoAplica($cargos[$c], $centro['TIPO_CENTRO'])) { ausError(400, 'Hay un cargo que no aplica a este centro.'); }
            if (!isset($novedades[$n]) || ($novedades[$n]['ID_CARGO_AUS'] && (int)$novedades[$n]['ID_CARGO_AUS'] !== $c)) { ausError(400, 'Hay una novedad que no aplica a ese cargo.'); }
            if ($d < 1 || $d > $dias) { ausError(400, 'Hay un día fuera del mes.'); }
            if (!preg_match('/^\d{0,3}$/', (string)$v)) { ausError(400, 'Las cantidades deben ser números enteros de 0 a 999.'); }
            $celdas[] = array('cargo' => $c, 'novedad' => $n, 'dia' => $d, 'cantidad' => (int)$v);
        }
        $n = $A->guardarCeldas($centro['ID_CENTRO_OP'], $anio, $mes, $celdas, $idColaborador, $ahora);
        echo json_encode(array('ok' => true, 'mensaje' => 'Ausentismo de ' . $centro['NOM_CENTRO_OP'] . ' guardado (' . $n . ($n === 1 ? ' celda).' : ' celdas).')),
                         JSON_UNESCAPED_UNICODE);
        break;

    //El coordinador cierra el mes al entregar el reporte
    case 'cerrar':
        $proy = ausProyecto($proyectos);
        list($anio, $mes) = ausPeriodo();
        if (!$proy['COORDINA'] && !$editaTodo) { ausError(403, 'El mes lo cierra el coordinador del proyecto.'); }
        $A->marcarCierre($proy['ID_PROYECTO'], $anio, $mes, true, $idColaborador, $ahora);
        echo json_encode(array('ok' => true, 'mensaje' => 'Mes cerrado. Ya no se puede corregir, salvo que lo reabra quien tenga el permiso.'), JSON_UNESCAPED_UNICODE);
        break;

    case 'reabrir':
        if (!$puedeReabrir) { ausError(403, 'Reabrir un mes necesita el permiso 23M.'); }
        $proy = ausProyecto($proyectos);
        list($anio, $mes) = ausPeriodo();
        $A->marcarCierre($proy['ID_PROYECTO'], $anio, $mes, false, $idColaborador, $ahora);
        echo json_encode(array('ok' => true, 'mensaje' => 'Mes reabierto: el coordinador ya puede corregirlo.'), JSON_UNESCAPED_UNICODE);
        break;

    //El consolidado del proyecto (la última hoja del Excel)
    //El historial de cambios de un centro en un mes (quién cambió qué y cuándo)
    case 'historial':
        $proy = ausProyecto($proyectos);
        $centro = ausCentro($proy);
        list($anio, $mes) = ausPeriodo();
        echo json_encode($A->historial($centro['ID_CENTRO_OP'], $anio, $mes), JSON_UNESCAPED_UNICODE);
        break;

    case 'consolidado':
        $proy = ausProyecto($proyectos);
        if (!$proy['VE_PROYECTO']) { ausError(403, 'El consolidado del proyecto lo ven el coordinador y la administración.'); }
        list($anio, $mes) = ausPeriodo();
        $c = $A->consolidado($proy['ID_PROYECTO'], $anio, $mes);
        $cierre = $A->cierre($proy['ID_PROYECTO'], $anio, $mes);
        echo json_encode(array(
            'porCargo' => (object)$c['porCargo'], 'porCentro' => (object)$c['porCentro'],
            'diasMes' => (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes))),
            'cerrado' => $cierre && (int)$cierre['CERRADO'] === 1, 'cierre' => $cierre,
            'puedeReabrir' => $puedeReabrir
        ), JSON_UNESCAPED_UNICODE);
        break;

    default:
        ausError(400, 'Operación no reconocida.');
}
