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
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //las respuestas JSON llevan los textos como se escribieron (sin &amp;)
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

//Responde con un código de error y un mensaje en JSON, y corta la ejecución
function ausError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
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
$modeloAusentismo = new Ausentismo();
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
    $listaProyectos = Organizacion::proyectos();
    $porId = array();
    foreach ((array)$listaProyectos as $proyecto) {
        $id = (int)$proyecto['ID_PROYECTO'];
        $coordina = (int)$proyecto['ID_COLABORADOR_COORDINADOR'] === (int)$idColaborador;
        $esJefeAqui = in_array($id, $jefeDe, true);
        if (!$todos && !$coordina && !$esJefeAqui) { continue; }
        $proyecto['COORDINA'] = $coordina;
        $proyecto['VE_PROYECTO'] = $coordina || $todos;
        $centrosProyecto = Organizacion::centrosDeProyecto($id);
        $centros = array();
        foreach ((array)$centrosProyecto as $centro) {
            $suyo = isset($jefeDe[(int)$centro['ID_CENTRO_OP']]);
            if (!$proyecto['VE_PROYECTO'] && !$suyo) { continue; } //el jefe solo ve su peaje
            $centro['EDITABLE'] = $coordina || $editaTodo || $suyo;
            $centros[] = $centro;
        }
        $proyecto['CENTROS'] = $centros;
        $proyecto['EDITABLE'] = count(array_filter($centros, function ($fila) { return $fila['EDITABLE']; })) > 0;
        $porId[$id] = $proyecto;
    }
    return $porId;
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
    $diaDesde = isset($_GET['desde']) && $_GET['desde'] !== '' ? (string)$_GET['desde'] : '1';
    $diaHasta = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? (string)$_GET['hasta'] : (string)$dias;
    if (!ctype_digit($diaDesde) || !ctype_digit($diaHasta) || (int)$diaDesde < 1 || (int)$diaHasta > $dias) { ausError(400, 'Los días deben estar entre el 1 y el ' . $dias . '.'); }
    if ((int)$diaDesde > (int)$diaHasta) { ausError(400, 'El primer día no puede ser después del último.'); }
    return array((int)$diaDesde, (int)$diaHasta);
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
function ausMotivo($proyecto, $centro, $cerrado, $anio, $mes, $editaTodo)
{
    if (!$centro['EDITABLE']) { return 'Solo consulta: este peaje lo registran su jefe y ' . ($proyecto['COORDINADOR'] ?: 'el coordinador del proyecto') . '.'; }
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
function ausDiaEditable($modeloAusentismo, $proyecto, $centro, $valor, $editaTodo)
{
    global $limiteFuturo, $diasFuturo;
    $fecha = HanaVal::fecha($valor);
    if ($fecha === '') { ausError(400, 'Elige el día de la ausencia.'); }
    if ($fecha > $limiteFuturo) {
        ausError(400, $diasFuturo > 0 ? 'Las ausencias se registran hasta ' . $diasFuturo . ($diasFuturo === 1 ? ' día' : ' días') . ' adelante (hasta el ' . date('d/m/Y', strtotime($limiteFuturo)) . ').'
                                      : 'Las ausencias se registran hasta hoy: todavía no se pueden anotar días que no han llegado.');
    }
    $anio = (int)substr($fecha, 0, 4); $mes = (int)substr($fecha, 5, 2);
    $cierre = $modeloAusentismo->cierre($proyecto['ID_PROYECTO'], $anio, $mes);
    $motivo = ausMotivo($proyecto, $centro, $cierre && (int)$cierre['CERRADO'] === 1, $anio, $mes, $editaTodo);
    if ($motivo !== '') { ausError(403, $motivo); } //mes cerrado, sin permiso o fuera de lo corregible
    return array($fecha, $anio, $mes, (int)substr($fecha, 8, 2));
}

//El cargo y el motivo pedidos, que existan y apliquen a ese centro. Devuelve [idCargo, idNovedad]
function ausCargoMotivo($modeloAusentismo, $centro, $cargo, $novedad)
{
    $cargo = (int)$cargo; $novedad = (int)$novedad;
    $cargos = array(); foreach ($modeloAusentismo->cargos() as $filaCargo) { $cargos[(int)$filaCargo['ID_CARGO_AUS']] = $filaCargo; }
    $novedades = array(); foreach ($modeloAusentismo->novedades() as $filaNovedad) { $novedades[(int)$filaNovedad['ID_NOVEDAD_AUS']] = $filaNovedad; } //solo los motivos que se usan hoy
    if (!isset($cargos[$cargo]) || !Ausentismo::cargoAplica($cargos[$cargo], $centro['TIPO_CENTRO'])) { ausError(400, 'Elige un cargo de este centro.'); }
    if (!isset($novedades[$novedad])) {
        //Un motivo apagado (por ejemplo los de ausencia, que ahora están en la marcación de turnos)
        foreach ($modeloAusentismo->todasNovedades() as $filaNovedad) {
            if ((int)$filaNovedad['ID_NOVEDAD_AUS'] === $novedad) { ausError(400, 'El motivo «' . $filaNovedad['NOMBRE'] . '» ya no se usa: escoge uno de los actuales. Lo ya anotado con él se puede borrar o pasar a uno actual.'); }
        }
    }
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
function ausCentro($proyecto)
{
    $id = isset($_REQUEST['centro']) ? (int)$_REQUEST['centro'] : 0;
    foreach ($proyecto['CENTROS'] as $centro) { if ((int)$centro['ID_CENTRO_OP'] === $id) { return $centro; } }
    ausError(400, 'Ese peaje no es del proyecto o no está entre los que puedes registrar.');
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //Lo que necesita la pantalla al abrir: proyectos, peajes y en cuál arrancar
    case 'config':
        $inicial = 0;
        foreach ($proyectos as $id => $proyecto) { if ($proyecto['EDITABLE']) { $inicial = $id; break; } }
        if (!$inicial && count($proyectos)) { $inicial = (int)key($proyectos); }
        echo hanaJsonTexto(array(
            'hoy' => $hoy, 'proyectos' => array_values($proyectos), 'proyectoInicial' => $inicial,
            //Todos los motivos: los que ya no se usan (ESTADO 0) solo salen en los meses que tienen datos con ellos
            'cargos' => $modeloAusentismo->cargos(), 'novedades' => $modeloAusentismo->todasNovedades(),
            'mesesCorregibles' => HanaConfig::num('AUS_MESES_CORREGIBLES', 1), 'puedeReabrir' => $puedeReabrir,
            'veTodos' => $verTodos || $editaTodo, //puede ver el consolidado de todos los proyectos juntos
            'diasFuturo' => $diasFuturo, 'limiteFuturo' => $limiteFuturo
        ), JSON_UNESCAPED_UNICODE);
        break;

    //Un centro en un mes: lo registrado y si se puede editar
    case 'mes':
        $proyecto = ausProyecto($proyectos);
        $centro = ausCentro($proyecto);
        list($anio, $mes) = ausPeriodo();
        $cierre = $modeloAusentismo->cierre($proyecto['ID_PROYECTO'], $anio, $mes);
        $cerrado = $cierre && (int)$cierre['CERRADO'] === 1;
        $motivo = ausMotivo($proyecto, $centro, $cerrado, $anio, $mes, $editaTodo);
        echo hanaJsonTexto(array(
            'registros' => (object)$modeloAusentismo->mesCentro($centro['ID_CENTRO_OP'], $anio, $mes),
            'diasMes' => (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes))),
            'editable' => $motivo === '', 'motivo' => $motivo,
            'cerrado' => $cerrado, 'cierre' => $cierre, 'coordinador' => $proyecto['COORDINADOR'],
            'puedeCerrar' => ($proyecto['COORDINA'] || $editaTodo) && !$cerrado && ausMesCorregible($anio, $mes, $editaTodo),
            'veProyecto' => $proyecto['VE_PROYECTO'], 'editaTodo' => $editaTodo && $cerrado,
            'avance' => (object)$modeloAusentismo->avanceCentros($proyecto['ID_PROYECTO'], $anio, $mes)
        ), JSON_UNESCAPED_UNICODE);
        break;

    //Guarda las celdas que cambiaron: celdas = [{cargo, novedad, dia, cantidad}]
    case 'guardar':
        $proyecto = ausProyecto($proyectos);
        $centro = ausCentro($proyecto);
        list($anio, $mes) = ausPeriodo();
        $cierre = $modeloAusentismo->cierre($proyecto['ID_PROYECTO'], $anio, $mes);
        $motivo = ausMotivo($proyecto, $centro, $cierre && (int)$cierre['CERRADO'] === 1, $anio, $mes, $editaTodo);
        if ($motivo !== '') { ausError(403, $motivo); }

        $crudas = json_decode(isset($_POST['celdas']) ? $_POST['celdas'] : '[]', true);
        if (!is_array($crudas) || count($crudas) > 5000) { ausError(400, 'Los datos enviados no son válidos.'); }
        $dias = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes)));
        $cargos = array(); foreach ($modeloAusentismo->cargos() as $filaCargo) { $cargos[(int)$filaCargo['ID_CARGO_AUS']] = $filaCargo; }
        $novedades = array(); foreach ($modeloAusentismo->todasNovedades() as $filaNovedad) { $novedades[(int)$filaNovedad['ID_NOVEDAD_AUS']] = $filaNovedad; }
        $yaHay = $modeloAusentismo->mesCentro($centro['ID_CENTRO_OP'], $anio, $mes); //lo que ya estaba guardado: un motivo que ya no se usa solo se corrige ahí
        $celdas = array();
        foreach ($crudas as $fila) {
            $idCargo = isset($fila['cargo']) ? (int)$fila['cargo'] : 0; $idNovedad = isset($fila['novedad']) ? (int)$fila['novedad'] : 0;
            $dia = isset($fila['dia']) ? (int)$fila['dia'] : 0;     $cantidad = isset($fila['cantidad']) ? $fila['cantidad'] : '';
            if (!isset($cargos[$idCargo]) || !Ausentismo::cargoAplica($cargos[$idCargo], $centro['TIPO_CENTRO'])) { ausError(400, 'Hay un cargo que no aplica a este centro.'); }
            if (!isset($novedades[$idNovedad]) || ($novedades[$idNovedad]['ID_CARGO_AUS'] && (int)$novedades[$idNovedad]['ID_CARGO_AUS'] !== $idCargo)) { ausError(400, 'Hay una novedad que no aplica a ese cargo.'); }
            //Un motivo que ya no se usa: se puede corregir o borrar lo que ya tenía ese mes, pero no anotar ausencias nuevas con él
            if ((int)$novedades[$idNovedad]['ESTADO'] !== 1 && empty($yaHay[$idCargo][$idNovedad])) { ausError(400, 'El motivo «' . $novedades[$idNovedad]['NOMBRE'] . '» ya no se usa: escoge uno de los actuales.'); }
            if ($dia < 1 || $dia > $dias) { ausError(400, 'Hay un día fuera del mes.'); }
            if (!preg_match('/^\d{0,3}$/', (string)$cantidad)) { ausError(400, 'Las cantidades deben ser números enteros de 0 a 999.'); }
            if ((int)$cantidad > 0 && sprintf('%04d-%02d-%02d', $anio, $mes, $dia) > $limiteFuturo) {
                ausError(400, 'El día ' . $dia . ' todavía está muy adelante: las ausencias se registran hasta el ' . date('d/m/Y', strtotime($limiteFuturo)) . '.');
            }
            $celdas[] = array('cargo' => $idCargo, 'novedad' => $idNovedad, 'dia' => $dia, 'cantidad' => (int)$cantidad);
        }
        $guardadas = $modeloAusentismo->guardarCeldas($centro['ID_CENTRO_OP'], $anio, $mes, $celdas, $idColaborador, $ahora);
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Ausentismo de ' . $centro['NOM_CENTRO_OP'] . ' guardado (' . $guardadas . ($guardadas === 1 ? ' celda).' : ' celdas).')),
                         JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Registro rápido: una ausencia a la vez
    //-----------------------------------------------------------------------
    //Registrar: suma las personas a lo que ya hubiera ese día con ese cargo y ese motivo.
    //Con "hasta", la misma ausencia se anota en todos los días del rango (por ejemplo, una
    //incapacidad de hoy al sábado). Primero se revisan todos los días y luego se guarda todo junto
    case 'ausencia':
        $proyecto = ausProyecto($proyectos);
        $centro = ausCentro($proyecto);
        $desdeTxt = isset($_POST['fecha']) ? $_POST['fecha'] : '';
        $hastaTxt = isset($_POST['hasta']) && trim($_POST['hasta']) !== '' ? $_POST['hasta'] : $desdeTxt;
        list($cargo, $novedad) = ausCargoMotivo($modeloAusentismo, $centro, isset($_POST['cargo']) ? $_POST['cargo'] : 0, isset($_POST['novedad']) ? $_POST['novedad'] : 0);
        $personas = ausPersonas(isset($_POST['cantidad']) ? $_POST['cantidad'] : '');
        $primerDia = HanaVal::fecha($desdeTxt); $ultimoDia = HanaVal::fecha($hastaTxt);
        if ($primerDia === '') { ausError(400, 'Elige el día de la ausencia.'); }
        if ($ultimoDia === '') { ausError(400, 'El último día no es válido.'); }
        if ($ultimoDia < $primerDia) { ausError(400, 'El último día no puede ser antes del primero.'); }
        if ((strtotime($ultimoDia) - strtotime($primerDia)) / 86400 > 30) { ausError(400, 'Un rango de ausencia es de máximo 31 días.'); }
        $celdas = array(); //[año-mes] = celdas de ese mes
        for ($momento = strtotime($primerDia); $momento <= strtotime($ultimoDia); $momento = strtotime('+1 day', $momento)) {
            list($fecha, $anio, $mes, $dia) = ausDiaEditable($modeloAusentismo, $proyecto, $centro, date('Y-m-d', $momento), $editaTodo);
            $total = $modeloAusentismo->cantidad($centro['ID_CENTRO_OP'], $fecha, $cargo, $novedad) + $personas; //lo que había más lo nuevo
            if ($total > 999) { ausError(400, 'Con esas personas el ' . date('d/m', $momento) . ' pasaría de 999. Revisa lo que ya está registrado.'); }
            $celdas[sprintf('%04d-%02d', $anio, $mes)][] = array('cargo' => $cargo, 'novedad' => $novedad, 'dia' => $dia, 'cantidad' => $total);
        }
        HanaDB::iniciar();                                                  //todos los días o ninguno
        foreach ($celdas as $anioMes => $lista) {
            $modeloAusentismo->guardarCeldas($centro['ID_CENTRO_OP'], (int)substr($anioMes, 0, 4), (int)substr($anioMes, 5, 2), $lista, $idColaborador, $ahora);
        }
        HanaDB::confirmar();
        $nDias = array_sum(array_map('count', $celdas));
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => $nDias === 1 ? 'Ausencia registrada en ' . $centro['NOM_CENTRO_OP'] . '.'
                                                                        : 'Ausencia registrada en ' . $centro['NOM_CENTRO_OP'] . ' en ' . $nDias . ' días.'), JSON_UNESCAPED_UNICODE);
        break;

    //Cambiar una ausencia ya registrada: el día, el cargo, el motivo o cuántas personas.
    //Llega la celda original (fecha0, cargo0, novedad0) y cómo debe quedar
    case 'ausenciaCambiar':
        $proyecto = ausProyecto($proyectos);
        $centro = ausCentro($proyecto);
        list($fechaAnterior, $anioAnterior, $mesAnterior, $diaAnterior) = ausDiaEditable($modeloAusentismo, $proyecto, $centro, isset($_POST['fecha0']) ? $_POST['fecha0'] : '', $editaTodo);
        list($fecha, $anio, $mes, $dia)     = ausDiaEditable($modeloAusentismo, $proyecto, $centro, isset($_POST['fecha']) ? $_POST['fecha'] : '', $editaTodo);
        $cargoAnterior = isset($_POST['cargo0']) ? (int)$_POST['cargo0'] : 0;
        $novedadAnterior = isset($_POST['novedad0']) ? (int)$_POST['novedad0'] : 0;
        if ($modeloAusentismo->cantidad($centro['ID_CENTRO_OP'], $fechaAnterior, $cargoAnterior, $novedadAnterior) < 1) { ausError(404, 'Esa ausencia ya no está registrada. Actualiza la página.'); }
        list($cargo, $novedad) = ausCargoMotivo($modeloAusentismo, $centro, isset($_POST['cargo']) ? $_POST['cargo'] : 0, isset($_POST['novedad']) ? $_POST['novedad'] : 0);
        $personas = ausPersonas(isset($_POST['cantidad']) ? $_POST['cantidad'] : '');
        $misma = $fecha === $fechaAnterior && $cargo === $cargoAnterior && $novedad === $novedadAnterior;
        if ($misma) {
            //Mismo día, cargo y motivo: solo cambió cuántas personas
            $modeloAusentismo->guardarCeldas($centro['ID_CENTRO_OP'], $anio, $mes, array(array('cargo' => $cargo, 'novedad' => $novedad, 'dia' => $dia, 'cantidad' => $personas)), $idColaborador, $ahora);
        } else {
            //Cambió de día, cargo o motivo: sale de donde estaba y se suma en el destino
            $total = $modeloAusentismo->cantidad($centro['ID_CENTRO_OP'], $fecha, $cargo, $novedad) + $personas;
            if ($total > 999) { ausError(400, 'Con esas personas el día pasaría de 999. Revisa lo que ya está registrado.'); }
            $modeloAusentismo->guardarCeldas($centro['ID_CENTRO_OP'], $anioAnterior, $mesAnterior, array(array('cargo' => $cargoAnterior, 'novedad' => $novedadAnterior, 'dia' => $diaAnterior, 'cantidad' => 0)), $idColaborador, $ahora);
            $modeloAusentismo->guardarCeldas($centro['ID_CENTRO_OP'], $anio, $mes, array(array('cargo' => $cargo, 'novedad' => $novedad, 'dia' => $dia, 'cantidad' => $total)), $idColaborador, $ahora);
        }
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Ausencia actualizada.'), JSON_UNESCAPED_UNICODE);
        break;

    //Borrar una ausencia: la celda vuelve a cero (el cambio queda en el historial)
    case 'ausenciaBorrar':
        $proyecto = ausProyecto($proyectos);
        $centro = ausCentro($proyecto);
        list($fecha, $anio, $mes, $dia) = ausDiaEditable($modeloAusentismo, $proyecto, $centro, isset($_POST['fecha']) ? $_POST['fecha'] : '', $editaTodo);
        $cargo = isset($_POST['cargo']) ? (int)$_POST['cargo'] : 0;
        $novedad = isset($_POST['novedad']) ? (int)$_POST['novedad'] : 0;
        if ($modeloAusentismo->cantidad($centro['ID_CENTRO_OP'], $fecha, $cargo, $novedad) < 1) { ausError(404, 'Esa ausencia ya no está registrada. Actualiza la página.'); }
        $modeloAusentismo->guardarCeldas($centro['ID_CENTRO_OP'], $anio, $mes, array(array('cargo' => $cargo, 'novedad' => $novedad, 'dia' => $dia, 'cantidad' => 0)), $idColaborador, $ahora);
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Ausencia borrada.'), JSON_UNESCAPED_UNICODE);
        break;

    //El coordinador cierra el mes al entregar el reporte
    case 'cerrar':
        $proyecto = ausProyecto($proyectos);
        list($anio, $mes) = ausPeriodo();
        if (!$proyecto['COORDINA'] && !$editaTodo) { ausError(403, 'El mes lo cierra el coordinador del proyecto.'); }
        $modeloAusentismo->marcarCierre($proyecto['ID_PROYECTO'], $anio, $mes, true, $idColaborador, $ahora);
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Mes cerrado. Ya no se puede corregir, salvo que lo reabra quien tenga el permiso.'), JSON_UNESCAPED_UNICODE);
        break;

    //Reabre un mes que ya se había cerrado (necesita el permiso 23M)
    case 'reabrir':
        if (!$puedeReabrir) { ausError(403, 'Reabrir un mes necesita el permiso 23M.'); }
        $proyecto = ausProyecto($proyectos);
        list($anio, $mes) = ausPeriodo();
        $modeloAusentismo->marcarCierre($proyecto['ID_PROYECTO'], $anio, $mes, false, $idColaborador, $ahora);
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Mes reabierto: el coordinador ya puede corregirlo.'), JSON_UNESCAPED_UNICODE);
        break;

    //El historial de cambios de un centro en un mes (quién cambió qué y cuándo)
    case 'historial':
        $proyecto = ausProyecto($proyectos);
        $centro = ausCentro($proyecto);
        list($anio, $mes) = ausPeriodo();
        echo hanaJsonTexto($modeloAusentismo->historial($centro['ID_CENTRO_OP'], $anio, $mes), JSON_UNESCAPED_UNICODE);
        break;

    //El consolidado (la última hoja del Excel): todo el proyecto o uno de sus peajes (centro), que
    //lo ven el coordinador y la administración, o todos los proyectos juntos (todos=1, con 20M,
    //21M, 22M o 25M). Del día "desde" al día "hasta" del mes; sin días, todo el mes
    case 'consolidado':
        list($anio, $mes) = ausPeriodo();
        list($diaDesde, $diaHasta) = ausDias($anio, $mes);
        $cierre = null; $soloCentro = 0;
        if (isset($_GET['todos']) && $_GET['todos'] === '1') {
            if (!$verTodos && !$editaTodo) { ausError(403, 'El consolidado de todos los proyectos lo ven quienes consultan todos los proyectos.'); }
            $ids = array_keys($proyectos);
        } else {
            $proyecto = ausProyecto($proyectos);
            if (!$proyecto['VE_PROYECTO']) { ausError(403, 'El consolidado del proyecto lo ven el coordinador y la administración.'); }
            $ids = array($proyecto['ID_PROYECTO']);
            if (!empty($_GET['centro'])) { $centroPedido = ausCentro($proyecto); $soloCentro = (int)$centroPedido['ID_CENTRO_OP']; }  //solo ese peaje
            $cierre = $modeloAusentismo->cierre($proyecto['ID_PROYECTO'], $anio, $mes);
        }
        $consolidado = $modeloAusentismo->consolidado($ids, $anio, $mes, $diaDesde, $diaHasta, $soloCentro);
        echo hanaJsonTexto(array(
            'porCargo' => (object)$consolidado['porCargo'], 'porCentro' => (object)$consolidado['porCentro'], 'porProyecto' => (object)$consolidado['porProyecto'],
            'diasMes' => (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes))), 'desde' => $diaDesde, 'hasta' => $diaHasta,
            'cerrado' => $cierre && (int)$cierre['CERRADO'] === 1, 'cierre' => $cierre,
            'puedeReabrir' => $puedeReabrir
        ), JSON_UNESCAPED_UNICODE);
        break;

    default:
        ausError(400, 'Operación no reconocida.');
}
