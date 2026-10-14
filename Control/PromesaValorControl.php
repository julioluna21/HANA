<?php
/*
  HANA - Controlador de promesa de valor y ejecutado (Reporte diario)
    - El ejecutado lo registra el coordinador del proyecto, en los peajes de su
      proyecto. El jefe de peaje, el de su peaje, solo si PV_JEFES_REGISTRAN
      está encendido. Con la casilla 38M (o siendo ADMIN TEC) se registra en
      todos los proyectos.
    - La promesa la define el coordinador en sus peajes (parámetro
      PV_COORDINADOR_DEFINE) o quien tenga la casilla 39M, en todos.
    - Consultan todos los proyectos, sin editar: 20M, 21M, 22M y 40M.
    - Se registra HOY. Otro día, solo si el administrador lo habilitó o si
      PV_DIAS_ATRAS deja llenar días anteriores.
    - El consolidado del mes (op=consolidado) pide la casilla 40M.
  Todo lo que es decisión del negocio se cambia en Parámetros o en Roles.
*/
session_start();
require_once __DIR__ . "/../Modelo/PromesaValorModelo.php";
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota'); //para que "hoy" sea el de Colombia

function pvError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!HanaConfig::modulo('PROMESA')) { pvError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { pvError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }

$idColaborador = (int)$_SESSION['Idcolaborador'];
hanaUsarModulo('PROMESA');                                    //la casilla 38M cuenta como coordinador de todos los proyectos
$registraTodo = hanaVeTodo($idColaborador);              //ADMIN TEC (24M) o casilla 38M
$verTodos     = hanaTienePermiso('20M') || hanaTienePermiso('21M') || hanaTienePermiso('22M') || hanaTienePermiso('40M');
$defineTodo   = hanaTienePermiso('39M') || hanaTienePermiso('24M');
$coordDefine  = HanaConfig::si('PV_COORDINADOR_DEFINE', true);    //¿el coordinador define la promesa de sus peajes?
$diasAtras    = max(0, min(7, HanaConfig::num('PV_DIAS_ATRAS', 0))); //días anteriores que todavía se pueden llenar
//Los peajes de los que es jefe: [idCentro] = idProyecto (solo si el administrador deja que los jefes registren)
$jefeDe = array();
if (HanaConfig::si('PV_JEFES_REGISTRAN', false)) {
    $jefeDe = Organizacion::centrosDeJefe($idColaborador);
}

$PV    = new PromesaValor();
$hoy   = date('Y-m-d');
$ahora = date('Y-m-d H:i:s');

//Los proyectos que ve, con sus centros y lo que puede hacer en cada uno:
//  CENTROS[].EDITABLE  puede registrar el ejecutado ahí
//  CENTROS[].DEFINE    puede definir la promesa de ese centro
function pvProyectos($idColaborador, $registraTodo, $verTodos, $defineTodo, $coordDefine, $jefeDe)
{
    $todos = $registraTodo || $verTodos || $defineTodo;
    $f = Organizacion::proyectos();
    $res = array();
    foreach ((array)$f as $p) {
        $id = (int)$p['ID_PROYECTO'];
        $coordina = (int)$p['ID_COLABORADOR_COORDINADOR'] === (int)$idColaborador;
        $esJefeAqui = in_array($id, $jefeDe, true);
        if (!$todos && !$coordina && !$esJefeAqui) { continue; }          //ese proyecto no es suyo
        $p['COORDINA'] = $coordina;
        $veProyecto = $coordina || $todos;
        $c = Organizacion::centrosDeProyecto($id);
        $centros = array();
        foreach ((array)$c as $x) {
            $suyo = isset($jefeDe[(int)$x['ID_CENTRO_OP']]);
            if (!$veProyecto && !$suyo) { continue; }                     //el jefe de peaje solo ve el suyo
            $x['EDITABLE'] = $coordina || $registraTodo || $suyo;
            $x['DEFINE']   = $defineTodo || ($coordina && $coordDefine);
            $centros[] = $x;
        }
        if (!count($centros)) { continue; }
        $p['CENTROS'] = $centros;
        $p['EDITABLE'] = count(array_filter($centros, function ($x) { return $x['EDITABLE']; })) > 0;
        $res[$id] = $p;
    }
    return $res;
}

//El centro pedido, si está entre los que puede ver. Devuelve [proyecto, centro]
function pvCentro($proyectos)
{
    $id = isset($_REQUEST['centro']) ? (int)$_REQUEST['centro'] : 0;
    foreach ($proyectos as $p) {
        foreach ($p['CENTROS'] as $c) { if ((int)$c['ID_CENTRO_OP'] === $id) { return array($p, $c); } }
    }
    pvError(403, 'Ese peaje no está entre los que puedes ver.');
}

//¿Por qué NO se puede registrar ese día en ese centro? '' = sí se puede
function pvMotivoDia($fecha, $proy, $centro, $hoy, $diasAtras, $idColaborador)
{
    if (!$centro['EDITABLE']) { return 'Solo consulta: el ejecutado de este peaje lo registra ' . ($proy['COORDINADOR'] ?: 'el coordinador del proyecto') . '.'; }
    if ($fecha > $hoy) { return 'Ese día todavía no llega.'; }
    if ($fecha === $hoy) { return ''; }
    if (HanaFechas::habilitado($fecha, $idColaborador)) { return ''; }                   //el administrador le abrió ese día
    if ($diasAtras > 0 && $fecha >= date('Y-m-d', strtotime("$hoy -$diasAtras day"))) { return ''; } //dentro de los días anteriores permitidos
    return 'Ese día ya no se puede cambiar: solo se registra ' . HanaFechas::textoVentana() . '.';
}

//Los días que la persona puede llenar ahora mismo, del más nuevo al más viejo
function pvDiasPermitidos($hoy, $diasAtras, $idColaborador)
{
    $dias = array($hoy => 'HOY');
    for ($i = 1; $i <= $diasAtras; $i++) { $dias[date('Y-m-d', strtotime("$hoy -$i day"))] = 'ATRAS'; }
    foreach (HanaFechas::habilitados($idColaborador) as $f) { if ($f < $hoy && !isset($dias[$f])) { $dias[$f] = 'HABILITADO'; } }
    krsort($dias);
    $res = array();
    foreach ($dias as $f => $tipo) { $res[] = array('fecha' => $f, 'tipo' => $tipo); }
    return $res;
}

//Un entero de 0 a 999, o null si no lo es
function pvNumero($v)
{
    return preg_match('/^\d{1,3}$/', trim((string)$v)) ? (int)$v : null;
}

//El año y el mes pedidos, validados
function pvPeriodo()
{
    $anio = isset($_REQUEST['anio']) ? (int)$_REQUEST['anio'] : (int)date('Y');
    $mes  = isset($_REQUEST['mes'])  ? (int)$_REQUEST['mes']  : (int)date('n');
    if ($anio < 2020 || $anio > 2100 || $mes < 1 || $mes > 12) { pvError(400, 'El mes no es válido.'); }
    return array($anio, $mes);
}

//El filtro de cargos (?cargos=1,3): una lista de ids; vacía = todos
function pvFiltroCargos()
{
    $res = array();
    foreach (explode(',', isset($_REQUEST['cargos']) ? (string)$_REQUEST['cargos'] : '') as $x) {
        if (ctype_digit(trim($x)) && (int)$x > 0) { $res[] = (int)$x; }
    }
    return array_values(array_unique($res));
}

//Los cargos que aplican a un centro, por id (un cargo de báscula no va en un peaje)
function pvCargosCentro($centro)
{
    $res = array();
    foreach ((new Ausentismo())->cargos() as $c) {
        if (Ausentismo::cargoAplica($c, $centro['TIPO_CENTRO'])) { $res[(int)$c['ID_CARGO_AUS']] = $c; }
    }
    return $res;
}

//Las filas del día en un centro: cada cargo con su promesa, su ejecutado y las ausencias que explican la diferencia
function pvFilasDia($PV, $centro, $fecha)
{
    $prom = $PV->promesaDia($centro['ID_CENTRO_OP'], $fecha);
    $eje  = $PV->ejecutadoDia($centro['ID_CENTRO_OP'], $fecha);
    $aus  = $PV->ausenciasDia($centro['ID_CENTRO_OP'], $fecha);
    $filas = array();
    foreach (pvCargosCentro($centro) as $id => $c) {
        $p = isset($prom[$id]) ? $prom[$id] : 0;
        if ($p <= 0 && !isset($eje[$id])) { continue; }                   //cargo sin promesa ese día y sin registro: no sale
        $filas[] = array('id' => $id, 'nombre' => $c['NOMBRE'], 'promesa' => $p,
                         'ejecutado' => isset($eje[$id]) ? (int)$eje[$id]['CANTIDAD'] : null,
                         'ausencias' => isset($aus[$id]) ? $aus[$id]['ausencias'] : 0,
                         'motivos'   => isset($aus[$id]) ? $aus[$id]['motivos'] : array());
    }
    return array($filas, $eje);
}

$op = isset($_GET['op']) ? $_GET['op'] : '';

//---------------------------------------------------------------------------
// El consolidado tiene su propia casilla (40M) y sus propios proyectos:
// todos con 20M o 24M; si no, solo los que coordina. Va antes del resto
// porque quien lo consulta puede no registrar nada
//---------------------------------------------------------------------------
if ($op === 'consolidado') {
    if (!hanaTienePermiso('40M')) { pvError(403, 'El consolidado necesita la casilla 40M en tu rol.'); }
    list($anio, $mes) = pvPeriodo();
    list($lista, $todosCons) = hanaProyectosConsolidado();    //la misma regla que usa el Excel
    $uno = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;         //0 = todos los que puede ver
    if ($uno > 0) {
        $lista = array_values(array_filter($lista, function ($p) use ($uno) { return (int)$p['ID_PROYECTO'] === $uno; }));
        if (!count($lista)) { pvError(403, 'Ese proyecto no está entre los que puedes ver.'); }
    }
    $r = $PV->consolidado($anio, $mes, $lista, $hoy, pvFiltroCargos());
    $r['hoy'] = $hoy; $r['todos'] = $todosCons;
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

$proyectos = pvProyectos($idColaborador, $registraTodo, $verTodos, $defineTodo, $coordDefine, $jefeDe);
if (!count($proyectos)) {
    pvError(403, 'No tienes peajes para la promesa de valor. La registra el coordinador del proyecto; si eres coordinador, pide que te asignen en Configuración → Proyectos.');
}

switch ($op) {

    //Lo que la pantalla necesita al abrir
    case 'config':
        $inicial = 0;
        foreach ($proyectos as $id => $p) { if ($p['EDITABLE']) { $inicial = $id; break; } } //primero uno donde pueda registrar
        if (!$inicial) { $inicial = (int)key($proyectos); }
        echo json_encode(array(
            'hoy' => $hoy, 'proyectos' => array_values($proyectos), 'proyectoInicial' => $inicial,
            'dias' => pvDiasPermitidos($hoy, $diasAtras, $idColaborador), 'diasAtras' => $diasAtras,
            'consolidado' => hanaTienePermiso('40M')
        ), JSON_UNESCAPED_UNICODE);
        break;

    //Un día de un peaje: la promesa, lo ejecutado y las ausencias de cada cargo
    case 'dia':
        list($proy, $centro) = pvCentro($proyectos);
        $fecha = HanaVal::fecha(isset($_GET['fecha']) ? $_GET['fecha'] : $hoy);
        if ($fecha === '') { pvError(400, 'La fecha no es válida.'); }
        if ($fecha > $hoy) { pvError(400, 'Ese día todavía no llega.'); }
        list($filas, $eje) = pvFilasDia($PV, $centro, $fecha);
        $motivo = pvMotivoDia($fecha, $proy, $centro, $hoy, $diasAtras, $idColaborador);
        $ultima = null;                                                   //quién guardó de último y cuándo
        foreach ($eje as $x) { if ($ultima === null || $x['FEC_MODIFICACION'] > $ultima['FEC_MODIFICACION']) { $ultima = $x; } }
        $ids = array_map(function ($c) { return (int)$c['ID_CENTRO_OP']; }, $proy['CENTROS']);
        echo json_encode(array(
            'fecha' => $fecha, 'filas' => $filas, 'registrado' => count($eje) > 0,
            'editable' => $motivo === '', 'motivo' => $motivo, 'define' => $centro['DEFINE'],
            'quien' => $ultima ? $ultima['QUIEN'] : null, 'cuando' => $ultima ? $ultima['FEC_MODIFICACION'] : null,
            'coordinador' => $proy['COORDINADOR'],
            'avance' => (object)$PV->avanceDia($ids, $fecha),             //qué peajes del proyecto ya están ese día
            'conPromesa' => array_keys($PV->resumenDia($ids, $fecha)),    //cuáles tienen promesa definida
            'cargos' => array_values(array_map(function ($c) { return array('id' => (int)$c['ID_CARGO_AUS'], 'nombre' => $c['NOMBRE']); }, pvCargosCentro($centro)))
        ), JSON_UNESCAPED_UNICODE);
        break;

    //Guarda el ejecutado del día: valores = {idCargo: personas}
    case 'guardar':
        list($proy, $centro) = pvCentro($proyectos);
        $fecha = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($fecha === '') { pvError(400, 'La fecha no es válida.'); }
        $motivo = pvMotivoDia($fecha, $proy, $centro, $hoy, $diasAtras, $idColaborador);
        if ($motivo !== '') { pvError(403, $motivo); }
        $crudos = json_decode(isset($_POST['valores']) ? $_POST['valores'] : '{}', true);
        if (!is_array($crudos)) { pvError(400, 'Los datos enviados no son válidos.'); }
        list($filas) = pvFilasDia($PV, $centro, $fecha);                  //los cargos que de verdad van ese día
        if (!count($filas)) { pvError(400, 'Este peaje todavía no tiene promesa de valor para ese día. Primero hay que definirla.'); }
        $valores = array();
        foreach ($filas as $f) {
            $n = isset($crudos[$f['id']]) ? pvNumero($crudos[$f['id']]) : null;
            if ($n === null) { pvError(400, 'Escribe cuántas personas trabajaron en ' . $f['nombre'] . ' (de 0 a 999). Si no fue nadie, escribe 0.'); }
            $valores[$f['id']] = $n;
        }
        HanaDB::iniciar();                                                //todo el día o nada
        $n = $PV->guardarDia($centro['ID_CENTRO_OP'], $fecha, $valores, $idColaborador, $ahora);
        if ($n === false) { HanaDB::deshacer(); pvError(500, 'No se pudo guardar. No cambió nada; intenta de nuevo.'); }
        HanaDB::confirmar();
        echo json_encode(array('ok' => true, 'mensaje' => $n ? 'Ejecutado de ' . $centro['NOM_CENTRO_OP'] . ' guardado.' : 'No había cambios por guardar.'), JSON_UNESCAPED_UNICODE);
        break;

    //El mes de un peaje, como la hoja del Excel
    case 'mes':
        list($proy, $centro) = pvCentro($proyectos);
        list($anio, $mes) = pvPeriodo();
        $r = $PV->mesCentro($centro['ID_CENTRO_OP'], $centro['TIPO_CENTRO'], $anio, $mes);
        $r['semanas'] = PromesaValor::$SEMANAS;
        $r['festivos'] = array_keys(HanaFechas::festivos($anio));
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        break;

    //La promesa de un peaje: lo que rige hoy por cargo y todas las reglas guardadas
    case 'promesa':
        list($proy, $centro) = pvCentro($proyectos);
        $vigente = $PV->promesaDia($centro['ID_CENTRO_OP'], $hoy);
        $cargos = array();
        foreach (pvCargosCentro($centro) as $id => $c) { $cargos[] = array('id' => $id, 'nombre' => $c['NOMBRE'], 'hoy' => isset($vigente[$id]) ? $vigente[$id] : null); }
        echo json_encode(array('cargos' => $cargos, 'reglas' => $PV->vigencias($centro['ID_CENTRO_OP']), 'define' => $centro['DEFINE'],
                               'coordinador' => $proy['COORDINADOR'], 'coordDefine' => $coordDefine,
                               'dias' => PromesaValor::$DIAS, 'festivos' => array_keys(HanaFechas::festivos((int)substr($hoy, 0, 4)) + HanaFechas::festivos((int)substr($hoy, 0, 4) + 1))),
                         JSON_UNESCAPED_UNICODE);
        break;

    //Define la promesa "de aquí en adelante": valores = {idCargo: personas}, rige desde la fecha dada
    case 'promesaGuardar':
        list($proy, $centro) = pvCentro($proyectos);
        if (!$centro['DEFINE']) { pvError(403, 'No tienes permiso para definir la promesa de valor de este peaje.'); }
        $desde = HanaVal::fecha(isset($_POST['desde']) ? $_POST['desde'] : '');
        if ($desde === '') { pvError(400, 'Elige desde qué día rige.'); }
        //Ni muy atrás ni muy adelante: un año para cada lado
        if ($desde < date('Y-m-d', strtotime("$hoy -366 day")) || $desde > date('Y-m-d', strtotime("$hoy +366 day"))) { pvError(400, 'La fecha debe estar dentro de un año hacia atrás o hacia adelante.'); }
        $crudos = json_decode(isset($_POST['valores']) ? $_POST['valores'] : '{}', true);
        if (!is_array($crudos) || !count($crudos)) { pvError(400, 'Los datos enviados no son válidos.'); }
        $aplican = pvCargosCentro($centro);
        $valores = array();
        foreach ($crudos as $idCargo => $v) {
            $idCargo = (int)$idCargo;
            if (!isset($aplican[$idCargo])) { pvError(400, 'Hay un cargo que no aplica a este centro.'); }
            if (trim((string)$v) === '') { continue; }                    //casilla vacía: ese cargo no se toca
            $n = pvNumero($v);
            if ($n === null) { pvError(400, 'La promesa de ' . $aplican[$idCargo]['NOMBRE'] . ' debe ser un número de 0 a 999.'); }
            $valores[$idCargo] = $n;
        }
        if (!count($valores)) { pvError(400, 'Escribe la promesa de al menos un cargo.'); }
        HanaDB::iniciar();
        $n = $PV->guardarBase($centro['ID_CENTRO_OP'], $desde, $valores, $idColaborador, $ahora);
        if ($n === false) { HanaDB::deshacer(); pvError(500, 'No se pudo guardar la promesa. No cambió nada; intenta de nuevo.'); }
        HanaDB::confirmar();
        echo json_encode(array('ok' => true, 'mensaje' => $n ? 'Promesa de valor de ' . $centro['NOM_CENTRO_OP'] . ' guardada (' . $n . ($n === 1 ? ' cargo).' : ' cargos).')
                                                             : 'La promesa ya estaba así: no cambió nada.'), JSON_UNESCAPED_UNICODE);
        break;

    //Un rango especial (puente festivo, temporada): esos días la promesa de un cargo es otra
    case 'promesaRango':
        list($proy, $centro) = pvCentro($proyectos);
        if (!$centro['DEFINE']) { pvError(403, 'No tienes permiso para definir la promesa de valor de este peaje.'); }
        $aplican = pvCargosCentro($centro);
        $idCargo = isset($_POST['cargo']) ? (int)$_POST['cargo'] : 0;
        if (!isset($aplican[$idCargo])) { pvError(400, 'Elige un cargo de este centro.'); }
        $desde = HanaVal::fecha(isset($_POST['desde']) ? $_POST['desde'] : '');
        $hasta = HanaVal::fecha(isset($_POST['hasta']) ? $_POST['hasta'] : '');
        if ($desde === '' || $hasta === '') { pvError(400, 'Elige el primer día y el último.'); }
        if ($hasta < $desde) { pvError(400, 'El último día no puede ser antes del primero.'); }
        if ((strtotime($hasta) - strtotime($desde)) / 86400 > 92) { pvError(400, 'Un rango especial es de máximo tres meses. Si es para siempre, cambia la promesa de arriba.'); }
        if ($desde < date('Y-m-d', strtotime("$hoy -366 day")) || $hasta > date('Y-m-d', strtotime("$hoy +366 day"))) { pvError(400, 'Las fechas deben estar dentro de un año hacia atrás o hacia adelante.'); }
        $n = pvNumero(isset($_POST['cantidad']) ? $_POST['cantidad'] : '');
        if ($n === null) { pvError(400, 'Escribe cuántas personas se prometen esos días (de 0 a 999).'); }
        if ($PV->guardarRango($centro['ID_CENTRO_OP'], $idCargo, $desde, $hasta, $n, $idColaborador, $ahora) === false) { pvError(500, 'No se pudo guardar el rango. Intenta de nuevo.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Rango guardado para ' . $aplican[$idCargo]['NOMBRE'] . '.'), JSON_UNESCAPED_UNICODE);
        break;

    //Días que se repiten (contingencia): los días marcados, cada cargo tiene ese número de personas.
    //dias[] = 1..7 y F (festivos); valores = {idCargo: personas}; desde obligatorio; hasta opcional
    case 'promesaDias':
        list($proy, $centro) = pvCentro($proyectos);
        if (!$centro['DEFINE']) { pvError(403, 'No tienes permiso para definir la promesa de valor de este peaje.'); }
        $marcados = isset($_POST['dias']) && is_array($_POST['dias']) ? $_POST['dias'] : array();
        $dias = array();
        foreach (array_keys(PromesaValor::$DIAS) as $k) { if (in_array((string)$k, array_map('strval', $marcados), true)) { $dias[] = (string)$k; } }
        if (!count($dias)) { pvError(400, 'Marca al menos un día (por ejemplo viernes, sábado, domingo o festivos).'); }
        if (count($dias) === 8) { pvError(400, 'Marcaste todos los días: para eso usa «Personas por día en cada cargo» o un rango.'); }
        $desde = HanaVal::fecha(isset($_POST['desde']) ? $_POST['desde'] : '');
        if ($desde === '') { pvError(400, 'Elige desde qué día rige.'); }
        $hasta = trim(isset($_POST['hasta']) ? (string)$_POST['hasta'] : '') === '' ? null : HanaVal::fecha($_POST['hasta']);
        if ($hasta === '') { pvError(400, 'La fecha final no es válida.'); }
        if ($hasta !== null && $hasta < $desde) { pvError(400, 'La fecha final no puede ser antes de la inicial.'); }
        if ($desde < date('Y-m-d', strtotime("$hoy -366 day")) || $desde > date('Y-m-d', strtotime("$hoy +366 day"))
            || ($hasta !== null && $hasta > date('Y-m-d', strtotime("$desde +366 day")))) {
            pvError(400, 'Las fechas deben estar dentro de un año hacia atrás o hacia adelante.');
        }
        $nombre = HanaVal::texto(isset($_POST['nombre']) ? $_POST['nombre'] : '', 60);
        $crudos = json_decode(isset($_POST['valores']) ? $_POST['valores'] : '{}', true);
        if (!is_array($crudos)) { pvError(400, 'Los datos enviados no son válidos.'); }
        $aplican = pvCargosCentro($centro);
        $valores = array();
        foreach ($crudos as $idCargo => $v) {
            $idCargo = (int)$idCargo;
            if (!isset($aplican[$idCargo])) { pvError(400, 'Hay un cargo que no aplica a este centro.'); }
            if (trim((string)$v) === '') { continue; }                    //casilla vacía: ese cargo sigue igual esos días
            $n = pvNumero($v);
            if ($n === null) { pvError(400, 'Las personas de ' . $aplican[$idCargo]['NOMBRE'] . ' deben ser un número de 0 a 999.'); }
            $valores[$idCargo] = $n;
        }
        if (!count($valores)) { pvError(400, 'Escribe cuántas personas van esos días en al menos un cargo.'); }
        HanaDB::iniciar();
        foreach ($valores as $idCargo => $n) {
            if ($PV->guardarDias($centro['ID_CENTRO_OP'], $idCargo, $desde, $hasta, implode(',', $dias), $nombre, $n, $idColaborador, $ahora) === false) {
                HanaDB::deshacer(); pvError(500, 'No se pudo guardar. No cambió nada; intenta de nuevo.');
            }
        }
        HanaDB::confirmar();
        echo json_encode(array('ok' => true, 'mensaje' => 'Días que se repiten guardados para ' . count($valores) . (count($valores) === 1 ? ' cargo.' : ' cargos.')), JSON_UNESCAPED_UNICODE);
        break;

    //Borra una regla de la promesa: vuelve a regir la anterior
    case 'promesaBorrar':
        list($proy, $centro) = pvCentro($proyectos);
        if (!$centro['DEFINE']) { pvError(403, 'No tienes permiso para definir la promesa de valor de este peaje.'); }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$PV->regla($id, $centro['ID_CENTRO_OP'])) { pvError(404, 'Esa regla ya no existe. Actualiza la página.'); }
        if ($PV->borrarRegla($id, $centro['ID_CENTRO_OP']) === false) { pvError(500, 'No se pudo borrar. Intenta de nuevo.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Regla borrada. Vuelve a regir la anterior.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        pvError(400, 'Operación no reconocida.');
}
