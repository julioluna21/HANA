<?php
/*
  HANA - Controlador del cronograma y el vehículo (Reporte diario)
    Casillas de Cronograma y vehículo (Permisos de usuario), en los proyectos de los
    peajes asignados a la persona:
    - 30M: llenar el cronograma del proyecto (el de su coordinador) y el estado del vehículo.
    - 34M: ver el cronograma y el vehículo.
    - 47M: agregar, cambiar y quitar los vehículos de sus proyectos.
*/
session_start();
require_once __DIR__ . "/../Modelo/CronogramaModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //las respuestas JSON llevan los textos como se escribieron (sin &amp;)
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/../Modelo/HanaConfig.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

//Lo pasado no se modifica, salvo un día que el administrador haya habilitado.
//Planear sí se puede con anticipación: para eso es el cronograma
define('CRONO_MESES_ADELANTE', 12); //se planea hasta un año adelante

function cronoError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//Si el administrador apagó este módulo (Parámetros del sistema), no se registra nada
require_once __DIR__ . '/../Modelo/HanaConfig.php';
if (!HanaConfig::modulo('CRONOGRAMA')) { cronoError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
hanaUsarModulo('CRONOGRAMA');
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { cronoError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
//Casillas de Cronograma y vehículo (Permisos de usuario), en los proyectos de los peajes asignados:
//  llenar el cronograma y el estado del vehículo (30M), verlo (34M), agregar, cambiar y quitar
//  vehículos (47M). El cronograma de cada proyecto es el de su coordinador (Configuración → Proyectos):
//  quien llena lo escribe a nombre de él. El ADMIN TEC (24M) todo, en todos los proyectos
if (!hanaEntra('CRONOGRAMA')) { cronoError(403, 'Tu rol no tiene acceso al cronograma. Se da en Configuración → Permisos de usuario.'); }
if (!count(hanaMisCentros())) { cronoError(403, 'No tienes peajes asignados. Pide que te los asignen en Configuración → Colaboradores.'); }
$idUsuario     = (int)$_SESSION['Idcolaborador']; //el usuario es la persona (colaboradores)
$idColaborador = (int)$_SESSION['Idcolaborador'];
$usaCrono      = hanaPuede('CRONOGRAMA', 'llenar');
$cronoTodo     = $usaCrono; //escribe en el cronograma del coordinador de sus proyectos
$adminVh       = hanaPuede('CRONOGRAMA', 'vehiculos');
$verTodos      = false;
$esCoordinador = $usaCrono;
$modeloCronograma             = new Cronograma();
$hoy           = date('Y-m-d');
list($primerEditable, $ultimoEditable) = HanaFechas::ventana();
$ultimoPlan    = date('Y-m-d', strtotime('+' . CRONO_MESES_ADELANTE . ' months'));

//El vehículo pedido, solo si esta persona lo puede reportar: los de sus proyectos (o los que tiene a cargo)
function cronoVehiculo($modeloCronograma, $idVehiculo, $idColaborador, $adminVh)
{
    foreach ($modeloCronograma->vehiculosDeProyectos(array_keys(hanaMisProyectos()), $idColaborador, hanaVeTodo()) as $vehiculo) {
        if ((int)$vehiculo['ID_VEHICULO'] === (int)$idVehiculo) { return $vehiculo; }
    }
    return null;
}

//El último día en que se puede dejar registrado por adelantado el vehículo de un sábado, domingo o
//festivo. Siempre llega, como mínimo, hasta el fin del mes actual (así todo el mes de esos tres
//días queda editable); el parámetro CRONO_VH_FINDE_DIAS puede extenderlo más allá del mes.
function cronoFindeHasta($hoy)
{
    $dias = max(0, min(366, HanaConfig::num('CRONO_VH_FINDE_DIAS', 60)));
    $porParametro = $dias > 0 ? date('Y-m-d', strtotime("$hoy +$dias day")) : '';
    $finDeMes = date('Y-m-t', strtotime($hoy)); //último día del mes de hoy
    return ($porParametro !== '' && $porParametro > $finDeMes) ? $porParametro : $finDeMes;
}

//El primer día pasado en que todavía se registra o corrige el vehículo de un sábado, domingo o
//festivo. Siempre llega, como mínimo, hasta el inicio del mes actual (todo el mes queda editable);
//el parámetro CRONO_VH_FINDE_ATRAS puede extenderlo más atrás del mes.
function cronoFindeDesde($hoy)
{
    $dias = max(0, min(60, HanaConfig::num('CRONO_VH_FINDE_ATRAS', 7)));
    $porParametro = $dias > 0 ? date('Y-m-d', strtotime("$hoy -$dias day")) : '';
    $inicioDeMes = date('Y-m-01', strtotime($hoy)); //primer día del mes de hoy
    return ($porParametro !== '' && $porParametro < $inicioDeMes) ? $porParametro : $inicioDeMes;
}

//¿Se puede registrar el estado del vehículo ese día? Hoy (o un día que el administrador habilitó);
//y los sábados, domingos y festivos, por adelantado y también después (dentro de los días de los parámetros)
function cronoDiaVehiculo($fecha, $hoy, $idColaborador)
{
    if (HanaFechas::enVentana($fecha, $idColaborador)) { return true; }
    if (HanaFechas::esHabil($fecha)) { return false; }             //entre semana, solo hoy
    $hasta = cronoFindeHasta($hoy); $desde = cronoFindeDesde($hoy);
    if ($fecha > $hoy) { return $hasta !== '' && $fecha <= $hasta; } //por adelantado
    return $desde !== '' && $fecha >= $desde;                       //después
}

//Cómo se explica la regla cuando un día no se puede
function cronoTextoVehiculo($hoy)
{
    $hasta = cronoFindeHasta($hoy); $desde = cronoFindeDesde($hoy);
    $partes = array();
    if ($hasta !== '') { $partes[] = 'por adelantado hasta el ' . date('d/m/Y', strtotime($hasta)); }
    if ($desde !== '') { $partes[] = 'después, desde el ' . date('d/m/Y', strtotime($desde)); }
    return 'El estado del vehículo se registra para ' . HanaFechas::textoVentana()
         . (count($partes) ? '. Los sábados, domingos y festivos se pueden registrar o corregir en todo el mes (' . implode(' y ', $partes) . ').' : '.');
}

//Un ítem del cronograma propio o del de un coordinador de sus proyectos, que todavía se pueda modificar
function cronoItemPropio($modeloCronograma, $idItem, $idColaborador, $primerEditable, $todo = false)
{
    $actividad = $modeloCronograma->item($idItem);
    $duenios = array($idColaborador => true);
    if ($todo) { foreach (cronoProyectos($modeloCronograma, $idColaborador, false) as $proyecto) { if ((int)$proyecto['ID_COLABORADOR_COORDINADOR'] > 0) { $duenios[(int)$proyecto['ID_COLABORADOR_COORDINADOR']] = true; } } }
    if (!$actividad || !isset($duenios[(int)$actividad['ID_COLABORADOR']])) { cronoError(404, 'No se encontró esa actividad en el cronograma de tus proyectos.'); }
    //Un día pasado solo se toca si el administrador se lo habilitó a la persona
    if ($actividad['FECHA'] < $primerEditable && !HanaFechas::habilitado($actividad['FECHA'], $idColaborador)) { cronoError(400, 'Ese día ya no se puede modificar.'); }
    return $actividad;
}

//Los proyectos que esta persona ve en el cronograma: los de sus peajes asignados (el ADMIN TEC, todos).
//Cada uno con su coordinador, sus centros y sus vehículos. El cronograma de un proyecto es el de su
//coordinador; lo edita quien llena el cronograma (30M) en ese proyecto
function cronoProyectos($modeloCronograma, $idColaborador, $verTodos)
{
    $llena = hanaPuede('CRONOGRAMA', 'llenar');
    $mios = hanaMisProyectos();
    $listaProyectos = array_filter(Organizacion::proyectos($idColaborador, true), function ($proyecto) use ($mios) { return isset($mios[(int)$proyecto['ID_PROYECTO']]); });
    $porId = array();
    foreach ((array)$listaProyectos as $proyecto) {
        $id = (int)$proyecto['ID_PROYECTO'];
        $idCoordinador = (int)$proyecto['ID_COLABORADOR_COORDINADOR'];
        $proyecto['PROPIO'] = $idCoordinador > 0 && $idCoordinador === (int)$idColaborador;   //el cronograma es de quien lo está viendo
        $proyecto['EDITABLE'] = $idCoordinador > 0 && $llena;
        $proyecto['CENTROS'] = Organizacion::centrosDeProyecto($id, false);
        $proyecto['VEHICULOS'] = $modeloCronograma->vehiculosProyecto($id, $idCoordinador);
        $porId[$id] = $proyecto;
    }
    return $porId;
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //Lo que necesita la pantalla al abrir: proyectos que ve la persona y en cuál arrancar
    case 'config':
        $proyectos = cronoProyectos($modeloCronograma, $idColaborador, $verTodos);
        //Arranca en el proyecto que coordina; si no coordina ninguno, en el primero que ve
        $inicial = 0;
        foreach ($proyectos as $id => $proyecto) { if ($proyecto['EDITABLE']) { $inicial = $id; break; } }
        if (!$inicial && count($proyectos)) { $inicial = (int)key($proyectos); }
        echo hanaJsonTexto(array(
            'hoy' => $hoy, 'primerEditable' => $primerEditable, 'ultimoEditable' => $ultimoEditable, 'ultimoPlan' => $ultimoPlan,
            'habilitados' => HanaFechas::habilitados($idColaborador), //los días pasados que el administrador le abrió
            'tipos' => Cronograma::$TIPOS, 'estadosVh' => Cronograma::$ESTADOS_VH, 'transportes' => Cronograma::$TRANSPORTES,
            'proyectos' => array_values($proyectos), 'proyectoInicial' => $inicial,
            'centros' => hanaMisCentros(), //para planear visitas: sus peajes
            'usaCrono' => $usaCrono, 'adminVh' => $adminVh,
            //Sábados, domingos y festivos: el vehículo se registra por adelantado hasta este día y después desde este otro
            'vhFindeHasta' => cronoFindeHasta($hoy), 'vhFindeDesde' => cronoFindeDesde($hoy),
            //Festivos del año anterior, este y el siguiente (para corregir hacia atrás en enero y registrar por adelantado en diciembre)
            'festivos' => array_keys(HanaFechas::festivos((int)substr($hoy, 0, 4) - 1) + HanaFechas::festivos((int)substr($hoy, 0, 4)) + HanaFechas::festivos((int)substr($hoy, 0, 4) + 1))
        ), JSON_UNESCAPED_UNICODE);
        break;

    //El mes (op=mes) o los próximos 15 días desde hoy (op=próximos): lo
    //planeado, la situación de cada día y el estado del vehículo
    case 'mes':
    case 'proximos':
        if ($_GET['op'] === 'proximos') {
            $desde = $hoy;
            $hasta = date('Y-m-d', strtotime('+15 days'));
        } else {
            $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
            $mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
            if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) { cronoError(400, 'El mes no es válido.'); }
            $desde = sprintf('%04d-%02d-01', $anio, $mes);
            $hasta = date('Y-m-d', strtotime("$desde +1 month"));
        }

        //El proyecto que se está viendo: su coordinador es la persona del cronograma
        $proyectos = cronoProyectos($modeloCronograma, $idColaborador, $verTodos);
        $idProyectoPedido = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
        if (!isset($proyectos[$idProyectoPedido])) { cronoError(403, 'Ese proyecto no está entre los que puedes ver.'); }
        $proyecto = $proyectos[$idProyectoPedido];
        $persona = (int)$proyecto['ID_COLABORADOR_COORDINADOR'];
        $conPersona = $persona > 0;

        //El vehículo tiene que ser de ese proyecto
        $diasVehiculo = array();
        $idVehiculoPedido = isset($_GET['vehiculo']) ? (int)$_GET['vehiculo'] : 0;
        foreach ($proyecto['VEHICULOS'] as $vehiculo) { if ((int)$vehiculo['ID_VEHICULO'] === $idVehiculoPedido) { $diasVehiculo = $modeloCronograma->vehiculoDias($idVehiculoPedido, $desde, $hasta); break; } }

        echo hanaJsonTexto(array(
            'editable'    => $proyecto['EDITABLE'],
            'propio'      => $proyecto['PROPIO'],
            'coordinador' => $proyecto['COORDINADOR'],
            'visitados'   => $conPersona ? (object)$modeloCronograma->centrosVisitados($persona, $desde, $hasta) : new stdClass(),
            'desde'       => $desde,
            'hasta'       => $hasta, //sin incluir
            'items'       => $conPersona ? $modeloCronograma->items($persona, $desde, $hasta, $hoy) : array(),
            'situaciones' => $conPersona ? (object)$modeloCronograma->situaciones($persona, $desde, $hasta) : new stdClass(),
            //En qué peaje estuvo cada día (de Hoy en qué estás)
            'lugares'     => $conPersona ? (object)$modeloCronograma->lugares($persona, $desde, $hasta) : new stdClass(),
            'vehiculo'    => count($diasVehiculo) ? $diasVehiculo : new stdClass()
        ), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Cronograma
    //-----------------------------------------------------------------------
    case 'agregar':
        if (!$usaCrono) { cronoError(403, 'No tienes permiso para el cronograma.'); }
        //De quién es el cronograma: el del coordinador del proyecto que está viendo (si es él, el propio)
        $duenio = $idColaborador;
        $idProyectoAgregar = 0;
        if ($cronoTodo && !empty($_POST['proyecto'])) {
            $proyectos = cronoProyectos($modeloCronograma, $idColaborador, true);
            $idProyectoAgregar = (int)$_POST['proyecto'];
            $idProyectoPedido = (int)$_POST['proyecto'];
            if (!isset($proyectos[$idProyectoPedido]) || (int)$proyectos[$idProyectoPedido]['ID_COLABORADOR_COORDINADOR'] <= 0) {
                cronoError(400, 'Ese proyecto no tiene coordinador asignado en Configuración → Proyectos.');
            }
            $duenio = (int)$proyectos[$idProyectoPedido]['ID_COLABORADOR_COORDINADOR'];
        }
        $fecha = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($fecha === '') { cronoError(400, 'La fecha no es válida.'); }
        if ($fecha < $primerEditable && !HanaFechas::habilitado($fecha, $idColaborador)) { cronoError(400, 'Ese día ya no se puede modificar.'); }
        if ($fecha > $ultimoPlan) { cronoError(400, 'Solo se planea hasta ' . CRONO_MESES_ADELANTE . ' meses adelante.'); }

        $tipo = isset($_POST['tipo']) ? $_POST['tipo'] : '';
        if (!isset(Cronograma::$TIPOS[$tipo])) { cronoError(400, 'Elige qué vas a hacer ese día.'); }

        $idCentro = null;
        $descripcion = HanaVal::texto(isset($_POST['descripcion']) ? $_POST['descripcion'] : '', 150);
        if ($tipo === 'VISITA') {
            $idCentro = isset($_POST['centro']) ? (int)$_POST['centro'] : 0;
            $valido = false;
            foreach (hanaMisCentros() as $centro) {
                if ((int)$centro['ID_CENTRO_OP'] === $idCentro && (!$idProyectoAgregar || (int)$centro['ID_PROYECTO'] === $idProyectoAgregar)) { $valido = true; break; }
            }
            if (!$valido) { cronoError(400, 'Elige uno de tus peajes de ese proyecto.'); }
        } elseif (!in_array($tipo, array('DESCANSO', 'VACACIONES'), true) && $descripcion === null) {
            //Reunión, Otro y los tipos que agregó el administrador piden la descripción
            cronoError(400, 'Describe la actividad: con quién es la reunión, o qué vas a hacer.');
        }

        $id = $modeloCronograma->agregar($duenio, $fecha, $tipo, $idCentro, $descripcion, date('Y-m-d H:i:s'));
        if (!$id) { cronoError(500, 'No se pudo agregar. Intenta de nuevo.'); }
        echo hanaJsonTexto(array('ok' => true, 'id' => $id, 'mensaje' => 'Agregado al cronograma.'), JSON_UNESCAPED_UNICODE);
        break;

    //Marcar realizada, cancelada, o devolverla a programada
    case 'estado':
        if (!$usaCrono) { cronoError(403, 'No tienes permiso para el cronograma.'); }
        $actividad = cronoItemPropio($modeloCronograma, isset($_POST['id']) ? (int)$_POST['id'] : 0, $idColaborador, $primerEditable, $cronoTodo);
        $estado = isset($_POST['estado']) ? $_POST['estado'] : '';
        if (!in_array($estado, array('PROGRAMADA', 'REALIZADA', 'CANCELADA'), true)) { cronoError(400, 'Estado no válido.'); }
        if ($estado === 'REALIZADA' && $actividad['FECHA'] > $ultimoEditable) { cronoError(400, 'Ese día todavía no llega: no se puede marcar como realizado.'); }
        $observacion = HanaVal::texto(isset($_POST['observacion']) ? $_POST['observacion'] : '', 300);
        if ($estado === 'CANCELADA' && $observacion === null) { cronoError(400, 'Explica por qué se cancela.'); }
        if (!$modeloCronograma->cambiarEstado($actividad['ID_CRONOGRAMA'], $estado, $observacion)) { cronoError(500, 'No se pudo guardar. Intenta de nuevo.'); }
        echo hanaJsonTexto(array('ok' => true), JSON_UNESCAPED_UNICODE);
        break;

    //Quitar algo agregado por error: solo lo que todavía no ha pasado
    case 'quitar':
        if (!$usaCrono) { cronoError(403, 'No tienes permiso para el cronograma.'); }
        $actividad = cronoItemPropio($modeloCronograma, isset($_POST['id']) ? (int)$_POST['id'] : 0, $idColaborador, $primerEditable, $cronoTodo);
        if ($actividad['FECHA'] < $hoy) { cronoError(400, 'Lo de días pasados no se quita: se marca como cancelado, con el motivo.'); }
        if (!$modeloCronograma->quitar($actividad['ID_CRONOGRAMA'])) { cronoError(500, 'No se pudo quitar. Intenta de nuevo.'); }
        echo hanaJsonTexto(array('ok' => true), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Estado del vehículo en un día
    //-----------------------------------------------------------------------
    case 'vehiculoDia':
        $vehiculo = cronoVehiculo($modeloCronograma, isset($_POST['vehiculo']) ? (int)$_POST['vehiculo'] : 0, $idColaborador, $adminVh);
        if (!$usaCrono) { cronoError(403, 'Tu rol solo consulta el cronograma.'); }
        if (!$vehiculo) { cronoError(403, 'Ese vehículo no es de tus proyectos.'); }
        $fecha = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($fecha === '' || !cronoDiaVehiculo($fecha, $hoy, $idColaborador)) {
            cronoError(400, cronoTextoVehiculo($hoy));
        }
        $estado = isset($_POST['estado']) ? $_POST['estado'] : '';
        if (!isset(Cronograma::$ESTADOS_VH[$estado])) { cronoError(400, 'Elige el estado del vehículo.'); }
        $observacion = HanaVal::texto(isset($_POST['observacion']) ? $_POST['observacion'] : '', 300);
        if ($estado !== 'OPERATIVO' && $observacion === null) { cronoError(400, 'Explica qué le pasa al vehículo.'); }
        //Si el vehículo no estaba operativo, cómo se transportó el coordinador ese día
        $transporte = null;
        if ($estado !== 'OPERATIVO') {
            $transporte = isset($_POST['transporte']) ? $_POST['transporte'] : '';
            if ($transporte === '' && !HanaConfig::si('CRONO_TRANSPORTE_OBLIGATORIO')) { $transporte = null; } //opcional
            elseif (!isset(Cronograma::$TRANSPORTES[$transporte])) { cronoError(400, 'Elige cómo te transportaste ese día (bus, taxi, aplicación...).'); }
        }
        if (!$modeloCronograma->guardarVehiculoDia($vehiculo['ID_VEHICULO'], $fecha, $estado, $observacion, $idColaborador, date('Y-m-d H:i:s'), $transporte)) {
            cronoError(500, 'No se pudo guardar. Intenta de nuevo.');
        }
        echo hanaJsonTexto(array('ok' => true), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Vehículos: agregar, cambiar y quitar (casilla 47M), los de sus proyectos
    //-----------------------------------------------------------------------
    case 'catalogo':
        if (!$adminVh) { cronoError(403, 'Tu rol no agrega ni cambia vehículos. Se da en Configuración → Permisos de usuario.'); }
        echo hanaJsonTexto($modeloCronograma->catalogo(hanaVeTodo() ? null : array_keys(hanaMisProyectos())), JSON_UNESCAPED_UNICODE);
        break;

    //Crea o edita un vehículo (solo los roles que administran vehículos)
    case 'guardarVehiculo':
        if (!$adminVh) { cronoError(403, 'Tu rol no agrega ni cambia vehículos. Se da en Configuración → Permisos de usuario.'); }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        //Solo vehículos de sus proyectos (el ADMIN TEC, de todos)
        if ($id > 0 && !hanaVeTodo()) {
            $existente = $modeloCronograma->vehiculo($id);
            if (!$existente || !isset(hanaMisProyectos()[(int)$existente['ID_PROYECTO']])) { cronoError(403, 'Ese vehículo no es de tus proyectos.'); }
        }
        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', isset($_POST['placa']) ? $_POST['placa'] : ''));
        if (strlen($placa) < 5 || strlen($placa) > 10) { cronoError(400, 'La placa no es válida.'); }
        if ($modeloCronograma->placaUsada($placa, $id)) { cronoError(400, "Ya existe un vehículo con la placa $placa."); }
        $descripcionVehiculo = HanaVal::texto(isset($_POST['descripcion']) ? $_POST['descripcion'] : '', 80);
        $idProyecto = isset($_POST['proyecto']) && (int)$_POST['proyecto'] > 0 ? (int)$_POST['proyecto'] : null;
        if (!hanaVeTodo() && ($idProyecto === null || !isset(hanaMisProyectos()[$idProyecto]))) { cronoError(400, 'Elige uno de tus proyectos para el vehículo.'); }
        $idResponsable = isset($_POST['responsable']) && (int)$_POST['responsable'] > 0 ? (int)$_POST['responsable'] : null;
        if ($idResponsable && !Organizacion::colaboradorActivo($idResponsable)) {
            cronoError(400, 'El responsable elegido no existe o está inactivo.');
        }
        $estado = isset($_POST['estado']) && (int)$_POST['estado'] === 0 ? 0 : 1;
        if (!$modeloCronograma->guardarVehiculo($id, $placa, $descripcionVehiculo, $idProyecto, $idResponsable, $estado)) { cronoError(500, 'No se pudo guardar el vehículo.'); }
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Vehículo guardado.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        cronoError(400, 'Operación no reconocida.');
}
