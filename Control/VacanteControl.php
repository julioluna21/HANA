<?php
/*
  HANA — Controlador de vacantes (Reporte diario, Fase 2)
  Permiso 11M, y solo en los centros asignados al usuario.
*/
session_start();
require_once __DIR__ . "/../Modelo/VacanteModelo.php";
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

function vacError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//Si el administrador apagó este módulo (Parámetros del sistema), no se registra nada
require_once __DIR__ . '/../Modelo/HanaConfig.php';
if (!HanaConfig::modulo('VACANTES')) { vacError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
HanaDB::usarModulo('VACANTES'); //la casilla VACANTES de Roles da acceso en todos los proyectos
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { vacError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!HanaDB::esCoordinador((int)$_SESSION['Idcolaborador'])) { vacError(403, 'Las vacantes las llena el coordinador del proyecto. Si eres coordinador, pide que te asignen en Configuración → Proyectos.'); }

$idColaborador = (int)$_SESSION['Idcolaborador'];
$V   = new Vacante();
$hoy = date('Y-m-d');
$ultimoEditable = HanaFechas::ventana()[1]; //las fechas no pueden pasar de hoy
$centros = array();
foreach (HanaDB::centrosCoordinador($idColaborador) as $c) { $centros[(int)$c['ID_CENTRO_OP']] = $c; } //los de sus proyectos

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'config':
        echo json_encode(array('hoy' => $hoy, 'ultimoEditable' => $ultimoEditable, 'centros' => array_values($centros), 'estados' => Vacante::$ESTADOS,
                               'estadosGh' => Vacante::$ESTADOS_GH, 'diasAns' => Vacante::diasAns(),
                               'motivos' => $V->motivos(array_keys($centros))), JSON_UNESCAPED_UNICODE);
        break;

    //Sin mes: todas las abiertas. Con mes: las que se abrieron ese mes
    case 'listar':
        $abiertas = !isset($_GET['mes']) || (int)$_GET['mes'] === 0;
        $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
        $mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : 0;
        $desde = $hasta = '';
        if (!$abiertas) {
            if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) { vacError(400, 'El periodo no es válido.'); }
            $desde = sprintf('%04d-%02d-01', $anio, $mes);
            $hasta = date('Y-m-d', strtotime("$desde +1 month"));
        }
        $ids = array_keys($centros);
        if (isset($_GET['centro']) && (int)$_GET['centro'] > 0) {
            $ids = isset($centros[(int)$_GET['centro']]) ? array((int)$_GET['centro']) : array();
        }
        echo json_encode($V->listar($ids, $desde, $hasta, $abiertas, $hoy), JSON_UNESCAPED_UNICODE);
        break;

    case 'guardar':
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $antes = $V->mostrar($id);
            if (!$antes || !isset($centros[(int)$antes['ID_CENTRO_OP']])) { vacError(404, 'No se encontró la vacante, o no es de tus centros.'); }
        }
        $d = array();
        $d['centro'] = isset($_POST['centro']) ? (int)$_POST['centro'] : 0;
        if (!isset($centros[$d['centro']])) { vacError(400, 'Elige uno de los centros de los proyectos que coordinas.'); }
        $d['cargo'] = HanaVal::texto(isset($_POST['cargo']) ? $_POST['cargo'] : '', 120);
        if ($d['cargo'] === null) { vacError(400, 'Escribe el cargo de la vacante.'); }
        $d['motivo'] = HanaVal::texto(isset($_POST['motivo']) ? $_POST['motivo'] : '', 150);
        if ($d['motivo'] === null) { vacError(400, 'Escribe el motivo de la vacante.'); }
        $d['fecha'] = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($d['fecha'] === '' || $d['fecha'] > $ultimoEditable) { vacError(400, 'La fecha de la vacante no es válida o es posterior a hoy.'); }

        //El acuerdo de servicio: 5 días hábiles desde la vacante, sin festivos.
        //Al editar se conserva el que tenía, salvo que cambie la fecha de la vacante
        $d['ans'] = ($id > 0 && $antes['FECHA_VACANTE'] === $d['fecha']) ? $antes['FECHA_ANS']
                                                                         : HanaFechas::sumarHabiles($d['fecha'], Vacante::diasAns());

        $d['estado'] = isset($_POST['estado']) ? $_POST['estado'] : 'ABIERTA';
        if (!isset(Vacante::$ESTADOS[$d['estado']])) { vacError(400, 'Estado no válido.'); }
        $d['cierre'] = null;
        if (isset($_POST['cierre']) && trim($_POST['cierre']) !== '') {
            $d['cierre'] = HanaVal::fecha($_POST['cierre']);
            if ($d['cierre'] === '' || $d['cierre'] < $d['fecha'] || $d['cierre'] > $ultimoEditable) {
                vacError(400, 'La fecha de cierre debe estar entre la fecha de la vacante y hoy.');
            }
        }
        //Coherencia: cubierta o cancelada lleva fecha de cierre; abierta no
        if ($d['estado'] === 'ABIERTA') { $d['cierre'] = null; }
        elseif ($d['cierre'] === null) { vacError(400, 'Una vacante ' . strtolower(Vacante::$ESTADOS[$d['estado']]) . ' necesita la fecha de cierre.'); }

        $d['rq']     = HanaVal::texto(isset($_POST['rq']) ? $_POST['rq'] : '', 20);
        $d['nombre'] = HanaVal::texto(isset($_POST['nombre']) ? $_POST['nombre'] : '', 120);
        if ($d['estado'] === 'CUBIERTA' && $d['nombre'] === null) { vacError(400, 'Escribe el nombre de la persona que cubrió la vacante.'); }
        $d['gh'] = isset($_POST['gh']) && $_POST['gh'] !== '' ? $_POST['gh'] : null;
        if ($d['gh'] !== null && !isset(Vacante::$ESTADOS_GH[$d['gh']])) { vacError(400, 'Estado de Gestión Humana no válido.'); }
        $d['compromiso'] = null;
        if (isset($_POST['compromiso']) && trim($_POST['compromiso']) !== '') {
            $d['compromiso'] = HanaVal::fecha($_POST['compromiso']);
            if ($d['compromiso'] === '') { vacError(400, 'La fecha del compromiso de Gestión Humana no es válida.'); }
        }
        $d['obs'] = HanaVal::texto(isset($_POST['obs']) ? $_POST['obs'] : '', 300);

        $nuevo = $V->guardar($id, $d, $idColaborador, date('Y-m-d H:i:s'));
        if (!$nuevo) { vacError(500, 'No se pudo guardar la vacante. Intenta de nuevo.'); }
        echo json_encode(array('ok' => true, 'id' => $nuevo, 'mensaje' => $id ? 'Vacante actualizada.'
                         : 'Vacante registrada. Fecha límite del acuerdo de servicio: ' . date('d/m/Y', strtotime($d['ans'])) . '.'),
                         JSON_UNESCAPED_UNICODE);
        break;

    default:
        vacError(400, 'Operación no reconocida.');
}
