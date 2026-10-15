<?php
/*
  HANA - Controlador de ausentismo
    - Registra el coordinador del proyecto, en los centros de su proyecto.
    - El jefe de peaje registra el de su peaje (si AUS_JEFES_EDITAN está
      encendido en Parámetros). No ve los demás peajes ni el consolidado.
    - Con 25M (ADMIN TEC, auditor) se edita el de todos los proyectos,
      incluso con el mes cerrado. Cada cambio queda en el historial (aus_cambio).
    - Consultan todos los proyectos: 20M (gerente de operaciones), 21M (administración)
      y 22M (consultar todos los proyectos). Ellos y 25M ven también el consolidado
      de todos los proyectos juntos.
    - El consolidado se puede pedir de todo el mes o de un rango de días (desde, hasta),
      y de todo el proyecto o de un solo peaje (centro).
    - Se corrige el mes actual y los anteriores que diga el parámetro
      AUS_MESES_CORREGIBLES, mientras el mes no esté cerrado.
    - El coordinador cierra el mes al entregarlo; lo reabre quien tenga 23M.
    - Registro rápido (ausencia, ausenciaCambiar, ausenciaBorrar): una ausencia
      a la vez, eligiendo día, cargo y motivo. Escribe en las mismas celdas de
      la matriz y con las mismas reglas, así que el consolidado, el Excel,
      Power BI y el historial no cambian.
*/
session_start();
require_once __DIR__ . "/../Modelo/AusentismoModelo.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

//Responde con un código de error y un mensaje en JSON, y corta la ejecución
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
$editaTodo = hanaTienePermiso('25M'); //ADMIN TEC y auditor
//Los peajes de los que es jefe: [idCentro] = idProyecto (si el administrador deja que los jefes registren)
$jefeDe = array();
if (HanaConfig::si('AUS_JEFES_EDITAN')) {
    $jefeDe = Organizacion::centrosDeJefe($idColaborador);
}
if (!$verTodos && !$editaTodo && !hanaEsCoordinador($idColaborador) && !count($jefeDe)) {
    ausError(403, 'No tienes acceso al ausentismo. Lo registran el coordinador del proyecto y, si el administrador lo permite, el jefe de cada peaje.');
}
$A = new Ausentismo();
$hoy = date('Y-m-d');
$ahora = date('Y-m-d H:i:s');
//Hasta qué día se puede anotar una ausencia que ya se sabe (una incapacidad, un permiso): hoy + N días
$diasFuturo = max(0, min(31, HanaConfig::num('AUS_DIAS_FUTURO', 7)));
$limiteFuturo = date('Y-m-d', strtotime("$hoy +$diasFuturo day"));

//Los proyectos que ve, con sus centros y lo que puede hacer en cada uno:
//  COORDINA      es el coordinador del proyecto
//  VE_PROYECTO   ve todo el proyecto: el consolidado y el Excel (coordinador, 20M/21M/22M, 25M)
//  CENTROS[].EDITABLE  puede registrar en ese centro (coordinador, 25M, o el jefe de ese peaje)
//El jefe de peaje solo ve (y edita) sus peajes
function ausProyectos($idColaborador, $verTodos, $editaTodo, $jefeDe)
{
    $todos = $verTodos || $editaTodo;
    $f = Organizacion::proyectos();
    $res = array();
    foreach ((array)$f as $p) {
        $id = (int)$p['ID_PROYECTO'];
        $coordina = (int)$p['ID_COLABORADOR_COORDINADOR'] === (int)$idColaborador;
        $esJefeAqui = in_array($id, $jefeDe, true);
        if (!$todos && !$coordina && !$esJefeAqui) { continue; }
        $p['COORDINA'] = $coordina;
        $p['VE_PROYECTO'] = $coordina || $todos;
        $c = Organizacion::centrosDeProyecto($id);
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

//Los días pedidos del mes para el consolidado (desde y hasta). Sin ellos, todo el mes
function ausDias($anio, $mes)
{
    $dias = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes)));
    $d1 = isset($_GET['desde']) && $_GET['desde'] !== '' ? (string)$_GET['desde'] : '1';
    $d2 = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? (string)$_GET['hasta'] : (string)$dias;
    if (!ctype_digit($d1) || !ctype_digit($d2) || (int)$d1 < 1 || (int)$d2 > $dias) { ausError(400, 'Los días deben estar entre el 1 y el ' . $dias . '.'); }
    if ((int)$d1 > (int)$d2) { ausError(400, 'El primer día no puede ser después del último.'); }
    return array((int)$d1, (int)$d2);
}

//El último mes que se puede tocar: el de hoy, o el siguiente si los días hacia adelante llegan hasta él
function ausUltimoMes()
{
    global $limiteFuturo;
    return (int)substr($limiteFuturo, 0, 4) * 12 + (int)substr($limiteFuturo, 5, 2);
}

//¿El mes está dentro de lo que se puede corregir? (el actual y los N anteriores; del futuro, solo
//hasta donde lleguen los días hacia adelante). Con 25M, cualquier mes que ya empezó
function ausMesCorregible($anio, $mes, $editaTodo = false)
{
    $actual = (int)date('Y') * 12 + (int)date('n');
    $pedido = $anio * 12 + $mes;
    if ($pedido > ausUltimoMes()) { return false; }
    return $editaTodo || $pedido >= $actual - max(0, HanaConfig::num('AUS_MESES_CORREGIBLES', 1));
}

//¿Por qué no se puede editar este centro este mes? '' = sí se puede
function ausMotivo($proy, $centro, $cerrado, $anio, $mes, $editaTodo)
{
    if (!$centro['EDITABLE']) { return 'Solo consulta: este peaje lo registran su jefe y ' . ($proy['COORDINADOR'] ?: 'el coordinador del proyecto') . '.'; }
    if ($anio * 12 + $mes > ausUltimoMes()) { return 'Este mes todavía no empieza.'; }
    if ($editaTodo) { return ''; } //25M corrige aunque el mes esté cerrado
    if ($cerrado) { return 'El mes está cerrado. Para corregirlo, hay que reabrirlo (permiso 23M).'; }
    if (!ausMesCorregible($anio, $mes)) { return 'Este mes ya no se puede corregir.'; }
    return '';
}

//---------------------------------------------------------------------------
// Registro rápido: lo que comparten registrar, cambiar y borrar una ausencia
//---------------------------------------------------------------------------
//Un día pedido (AAAA-MM-DD) que se pueda editar en ese centro: revisa el permiso,
//el cierre del mes y los meses corregibles. Devuelve [fecha, año, mes, día]
function ausDiaEditable($A, $proy, $centro, $valor, $editaTodo)
{
    global $limiteFuturo, $diasFuturo;
    $fecha = HanaVal::fecha($valor);
    if ($fecha === '') { ausError(400, 'Elige el día de la ausencia.'); }
    if ($fecha > $limiteFuturo) {
        ausError(400, $diasFuturo > 0 ? 'Las ausencias se registran hasta ' . $diasFuturo . ($diasFuturo === 1 ? ' día' : ' días') . ' adelante (hasta el ' . date('d/m/Y', strtotime($limiteFuturo)) . ').'
                                      : 'Las ausencias se registran hasta hoy: todavía no se pueden anotar días que no han llegado.');
    }
    $anio = (int)substr($fecha, 0, 4); $mes = (int)substr($fecha, 5, 2);
    $cierre = $A->cierre($proy['ID_PROYECTO'], $anio, $mes);
    $motivo = ausMotivo($proy, $centro, $cierre && (int)$cierre['CERRADO'] === 1, $anio, $mes, $editaTodo);
    if ($motivo !== '') { ausError(403, $motivo); } //mes cerrado, sin permiso o fuera de lo corregible
    return array($fecha, $anio, $mes, (int)substr($fecha, 8, 2));
}

//El cargo y el motivo pedidos, que existan y apliquen a ese centro. Devuelve [idCargo, idNovedad]
function ausCargoMotivo($A, $centro, $cargo, $novedad)
{
    $cargo = (int)$cargo; $novedad = (int)$novedad;
    $cargos = array(); foreach ($A->cargos() as $c) { $cargos[(int)$c['ID_CARGO_AUS']] = $c; }
    $novedades = array(); foreach ($A->novedades() as $n) { $novedades[(int)$n['ID_NOVEDAD_AUS']] = $n; } //solo los motivos que se usan hoy
    if (!isset($cargos[$cargo]) || !Ausentismo::cargoAplica($cargos[$cargo], $centro['TIPO_CENTRO'])) { ausError(400, 'Elige un cargo de este centro.'); }
    if (!isset($novedades[$novedad]) || ($novedades[$novedad]['ID_CARGO_AUS'] && (int)$novedades[$novedad]['ID_CARGO_AUS'] !== $cargo)) {
        ausError(400, 'Elige un motivo que aplique a ese cargo.');
    }
    return array($cargo, $novedad);
}

//Cuántas personas: un entero de 1 a 999
function ausPersonas($valor)
{
    if (!preg_match('/^\d{1,3}$/', (string)$valor) || (int)$valor < 1) { ausError(400, 'Escribe cuántas personas (de 1 a 999).'); }
    return (int)$valor;
}

$proyectos = ausProyectos($idColaborador, $verTodos, $editaTodo, $jefeDe);
//Toma el proyecto que llegó en la petición y revisa que la persona lo pueda ver
function ausProyecto($proyectos)
{
    $id = isset($_REQUEST['proyecto']) ? (int)$_REQUEST['proyecto'] : 0;
    if (!isset($proyectos[$id])) { ausError(403, 'Ese proyecto no está entre los que puedes ver.'); }
    return $proyectos[$id];
}
//Toma el peaje que llegó en la petición y revisa que sea de ese proyecto
function ausCentro($proy)
{
    $id = isset($_REQUEST['centro']) ? (int)$_REQUEST['centro'] : 0;
    foreach ($proy['CENTROS'] as $c) { if ((int)$c['ID_CENTRO_OP'] === $id) { return $c; } }
    ausError(400, 'Ese peaje no es del proyecto o no está entre los que puedes registrar.');
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //Lo que necesita la pantalla al abrir: proyectos, peajes y en cuál arrancar
    case 'config':
        $inicial = 0;
        foreach ($proyectos as $id => $p) { if ($p['EDITABLE']) { $inicial = $id; break; } }
        if (!$inicial && count($proyectos)) { $inicial = (int)key($proyectos); }
        echo json_encode(array(
            'hoy' => $hoy, 'proyectos' => array_values($proyectos), 'proyectoInicial' => $inicial,
            //Todos los motivos: los que ya no se usan (ESTADO 0) solo salen en los meses que tienen datos con ellos
            'cargos' => $A->cargos(), 'novedades' => $A->todasNovedades(),
            'mesesCorregibles' => HanaConfig::num('AUS_MESES_CORREGIBLES', 1), 'puedeReabrir' => $puedeReabrir,
            'veTodos' => $verTodos || $editaTodo, //puede ver el consolidado de todos los proyectos juntos
            'diasFuturo' => $diasFuturo, 'limiteFuturo' => $limiteFuturo
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
        $novedades = array(); foreach ($A->todasNovedades() as $n) { $novedades[(int)$n['ID_NOVEDAD_AUS']] = $n; }
        $yaHay = $A->mesCentro($centro['ID_CENTRO_OP'], $anio, $mes); //lo que ya estaba guardado: un motivo que ya no se usa solo se corrige ahí
        $celdas = array();
        foreach ($crudas as $x) {
            $c = isset($x['cargo']) ? (int)$x['cargo'] : 0; $n = isset($x['novedad']) ? (int)$x['novedad'] : 0;
            $d = isset($x['dia']) ? (int)$x['dia'] : 0;     $v = isset($x['cantidad']) ? $x['cantidad'] : '';
            if (!isset($cargos[$c]) || !Ausentismo::cargoAplica($cargos[$c], $centro['TIPO_CENTRO'])) { ausError(400, 'Hay un cargo que no aplica a este centro.'); }
            if (!isset($novedades[$n]) || ($novedades[$n]['ID_CARGO_AUS'] && (int)$novedades[$n]['ID_CARGO_AUS'] !== $c)) { ausError(400, 'Hay una novedad que no aplica a ese cargo.'); }
            //Un motivo que ya no se usa: se puede corregir o borrar lo que ya tenía ese mes, pero no anotar ausencias nuevas con él
            if ((int)$novedades[$n]['ESTADO'] !== 1 && empty($yaHay[$c][$n])) { ausError(400, 'El motivo «' . $novedades[$n]['NOMBRE'] . '» ya no se usa: escoge uno de los actuales.'); }
            if ($d < 1 || $d > $dias) { ausError(400, 'Hay un día fuera del mes.'); }
            if (!preg_match('/^\d{0,3}$/', (string)$v)) { ausError(400, 'Las cantidades deben ser números enteros de 0 a 999.'); }
            if ((int)$v > 0 && sprintf('%04d-%02d-%02d', $anio, $mes, $d) > $limiteFuturo) {
                ausError(400, 'El día ' . $d . ' todavía está muy adelante: las ausencias se registran hasta el ' . date('d/m/Y', strtotime($limiteFuturo)) . '.');
            }
            $celdas[] = array('cargo' => $c, 'novedad' => $n, 'dia' => $d, 'cantidad' => (int)$v);
        }
        $n = $A->guardarCeldas($centro['ID_CENTRO_OP'], $anio, $mes, $celdas, $idColaborador, $ahora);
        echo json_encode(array('ok' => true, 'mensaje' => 'Ausentismo de ' . $centro['NOM_CENTRO_OP'] . ' guardado (' . $n . ($n === 1 ? ' celda).' : ' celdas).')),
                         JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Registro rápido: una ausencia a la vez
    //-----------------------------------------------------------------------
    //Registrar: suma las personas a lo que ya hubiera ese día con ese cargo y ese motivo.
    //Con "hasta", la misma ausencia se anota en todos los días del rango (por ejemplo, una
    //incapacidad de hoy al sábado). Primero se revisan todos los días y luego se guarda todo junto
    case 'ausencia':
        $proy = ausProyecto($proyectos);
        $centro = ausCentro($proy);
        $desdeTxt = isset($_POST['fecha']) ? $_POST['fecha'] : '';
        $hastaTxt = isset($_POST['hasta']) && trim($_POST['hasta']) !== '' ? $_POST['hasta'] : $desdeTxt;
        list($cargo, $novedad) = ausCargoMotivo($A, $centro, isset($_POST['cargo']) ? $_POST['cargo'] : 0, isset($_POST['novedad']) ? $_POST['novedad'] : 0);
        $personas = ausPersonas(isset($_POST['cantidad']) ? $_POST['cantidad'] : '');
        $f1 = HanaVal::fecha($desdeTxt); $f2 = HanaVal::fecha($hastaTxt);
        if ($f1 === '') { ausError(400, 'Elige el día de la ausencia.'); }
        if ($f2 === '') { ausError(400, 'El último día no es válido.'); }
        if ($f2 < $f1) { ausError(400, 'El último día no puede ser antes del primero.'); }
        if ((strtotime($f2) - strtotime($f1)) / 86400 > 30) { ausError(400, 'Un rango de ausencia es de máximo 31 días.'); }
        $celdas = array(); //[año-mes] = celdas de ese mes
        for ($t = strtotime($f1); $t <= strtotime($f2); $t = strtotime('+1 day', $t)) {
            list($fecha, $anio, $mes, $dia) = ausDiaEditable($A, $proy, $centro, date('Y-m-d', $t), $editaTodo);
            $total = $A->cantidad($centro['ID_CENTRO_OP'], $fecha, $cargo, $novedad) + $personas; //lo que había más lo nuevo
            if ($total > 999) { ausError(400, 'Con esas personas el ' . date('d/m', $t) . ' pasaría de 999. Revisa lo que ya está registrado.'); }
            $celdas[sprintf('%04d-%02d', $anio, $mes)][] = array('cargo' => $cargo, 'novedad' => $novedad, 'dia' => $dia, 'cantidad' => $total);
        }
        HanaDB::iniciar();                                                  //todos los días o ninguno
        foreach ($celdas as $am => $lista) {
            $A->guardarCeldas($centro['ID_CENTRO_OP'], (int)substr($am, 0, 4), (int)substr($am, 5, 2), $lista, $idColaborador, $ahora);
        }
        HanaDB::confirmar();
        $nDias = array_sum(array_map('count', $celdas));
        echo json_encode(array('ok' => true, 'mensaje' => $nDias === 1 ? 'Ausencia registrada en ' . $centro['NOM_CENTRO_OP'] . '.'
                                                                        : 'Ausencia registrada en ' . $centro['NOM_CENTRO_OP'] . ' en ' . $nDias . ' días.'), JSON_UNESCAPED_UNICODE);
        break;

    //Cambiar una ausencia ya registrada: el día, el cargo, el motivo o cuántas personas.
    //Llega la celda original (fecha0, cargo0, novedad0) y cómo debe quedar
    case 'ausenciaCambiar':
        $proy = ausProyecto($proyectos);
        $centro = ausCentro($proy);
        list($fecha0, $anio0, $mes0, $dia0) = ausDiaEditable($A, $proy, $centro, isset($_POST['fecha0']) ? $_POST['fecha0'] : '', $editaTodo);
        list($fecha, $anio, $mes, $dia)     = ausDiaEditable($A, $proy, $centro, isset($_POST['fecha']) ? $_POST['fecha'] : '', $editaTodo);
        $cargo0 = isset($_POST['cargo0']) ? (int)$_POST['cargo0'] : 0;
        $novedad0 = isset($_POST['novedad0']) ? (int)$_POST['novedad0'] : 0;
        if ($A->cantidad($centro['ID_CENTRO_OP'], $fecha0, $cargo0, $novedad0) < 1) { ausError(404, 'Esa ausencia ya no está registrada. Actualiza la página.'); }
        list($cargo, $novedad) = ausCargoMotivo($A, $centro, isset($_POST['cargo']) ? $_POST['cargo'] : 0, isset($_POST['novedad']) ? $_POST['novedad'] : 0);
        $personas = ausPersonas(isset($_POST['cantidad']) ? $_POST['cantidad'] : '');
        $misma = $fecha === $fecha0 && $cargo === $cargo0 && $novedad === $novedad0;
        if ($misma) {
            //Mismo día, cargo y motivo: solo cambió cuántas personas
            $A->guardarCeldas($centro['ID_CENTRO_OP'], $anio, $mes, array(array('cargo' => $cargo, 'novedad' => $novedad, 'dia' => $dia, 'cantidad' => $personas)), $idColaborador, $ahora);
        } else {
            //Cambió de día, cargo o motivo: sale de donde estaba y se suma en el destino
            $total = $A->cantidad($centro['ID_CENTRO_OP'], $fecha, $cargo, $novedad) + $personas;
            if ($total > 999) { ausError(400, 'Con esas personas el día pasaría de 999. Revisa lo que ya está registrado.'); }
            $A->guardarCeldas($centro['ID_CENTRO_OP'], $anio0, $mes0, array(array('cargo' => $cargo0, 'novedad' => $novedad0, 'dia' => $dia0, 'cantidad' => 0)), $idColaborador, $ahora);
            $A->guardarCeldas($centro['ID_CENTRO_OP'], $anio, $mes, array(array('cargo' => $cargo, 'novedad' => $novedad, 'dia' => $dia, 'cantidad' => $total)), $idColaborador, $ahora);
        }
        echo json_encode(array('ok' => true, 'mensaje' => 'Ausencia actualizada.'), JSON_UNESCAPED_UNICODE);
        break;

    //Borrar una ausencia: la celda vuelve a cero (el cambio queda en el historial)
    case 'ausenciaBorrar':
        $proy = ausProyecto($proyectos);
        $centro = ausCentro($proy);
        list($fecha, $anio, $mes, $dia) = ausDiaEditable($A, $proy, $centro, isset($_POST['fecha']) ? $_POST['fecha'] : '', $editaTodo);
        $cargo = isset($_POST['cargo']) ? (int)$_POST['cargo'] : 0;
        $novedad = isset($_POST['novedad']) ? (int)$_POST['novedad'] : 0;
        if ($A->cantidad($centro['ID_CENTRO_OP'], $fecha, $cargo, $novedad) < 1) { ausError(404, 'Esa ausencia ya no está registrada. Actualiza la página.'); }
        $A->guardarCeldas($centro['ID_CENTRO_OP'], $anio, $mes, array(array('cargo' => $cargo, 'novedad' => $novedad, 'dia' => $dia, 'cantidad' => 0)), $idColaborador, $ahora);
        echo json_encode(array('ok' => true, 'mensaje' => 'Ausencia borrada.'), JSON_UNESCAPED_UNICODE);
        break;

    //El coordinador cierra el mes al entregar el reporte
    case 'cerrar':
        $proy = ausProyecto($proyectos);
        list($anio, $mes) = ausPeriodo();
        if (!$proy['COORDINA'] && !$editaTodo) { ausError(403, 'El mes lo cierra el coordinador del proyecto.'); }
        $A->marcarCierre($proy['ID_PROYECTO'], $anio, $mes, true, $idColaborador, $ahora);
        echo json_encode(array('ok' => true, 'mensaje' => 'Mes cerrado. Ya no se puede corregir, salvo que lo reabra quien tenga el permiso.'), JSON_UNESCAPED_UNICODE);
        break;

    //Reabre un mes que ya se había cerrado (necesita el permiso 23M)
    case 'reabrir':
        if (!$puedeReabrir) { ausError(403, 'Reabrir un mes necesita el permiso 23M.'); }
        $proy = ausProyecto($proyectos);
        list($anio, $mes) = ausPeriodo();
        $A->marcarCierre($proy['ID_PROYECTO'], $anio, $mes, false, $idColaborador, $ahora);
        echo json_encode(array('ok' => true, 'mensaje' => 'Mes reabierto: el coordinador ya puede corregirlo.'), JSON_UNESCAPED_UNICODE);
        break;

    //El historial de cambios de un centro en un mes (quién cambió qué y cuándo)
    case 'historial':
        $proy = ausProyecto($proyectos);
        $centro = ausCentro($proy);
        list($anio, $mes) = ausPeriodo();
        echo json_encode($A->historial($centro['ID_CENTRO_OP'], $anio, $mes), JSON_UNESCAPED_UNICODE);
        break;

    //El consolidado (la última hoja del Excel): todo el proyecto o uno de sus peajes (centro), que
    //lo ven el coordinador y la administración, o todos los proyectos juntos (todos=1, con 20M,
    //21M, 22M o 25M). Del día "desde" al día "hasta" del mes; sin días, todo el mes
    case 'consolidado':
        list($anio, $mes) = ausPeriodo();
        list($d1, $d2) = ausDias($anio, $mes);
        $cierre = null; $soloCentro = 0;
        if (isset($_GET['todos']) && $_GET['todos'] === '1') {
            if (!$verTodos && !$editaTodo) { ausError(403, 'El consolidado de todos los proyectos lo ven quienes consultan todos los proyectos.'); }
            $ids = array_keys($proyectos);
        } else {
            $proy = ausProyecto($proyectos);
            if (!$proy['VE_PROYECTO']) { ausError(403, 'El consolidado del proyecto lo ven el coordinador y la administración.'); }
            $ids = array($proy['ID_PROYECTO']);
            if (!empty($_GET['centro'])) { $c = ausCentro($proy); $soloCentro = (int)$c['ID_CENTRO_OP']; }  //solo ese peaje
            $cierre = $A->cierre($proy['ID_PROYECTO'], $anio, $mes);
        }
        $c = $A->consolidado($ids, $anio, $mes, $d1, $d2, $soloCentro);
        echo json_encode(array(
            'porCargo' => (object)$c['porCargo'], 'porCentro' => (object)$c['porCentro'], 'porProyecto' => (object)$c['porProyecto'],
            'diasMes' => (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes))), 'desde' => $d1, 'hasta' => $d2,
            'cerrado' => $cierre && (int)$cierre['CERRADO'] === 1, 'cierre' => $cierre,
            'puedeReabrir' => $puedeReabrir
        ), JSON_UNESCAPED_UNICODE);
        break;

    default:
        ausError(400, 'Operación no reconocida.');
}
