<?php
/*
  HANA - Controlador de vacantes (Reporte diario)
  Permiso 11M, y solo en los centros asignados al usuario.
*/
session_start();
require_once __DIR__ . "/../Modelo/VacanteModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //las respuestas JSON llevan los textos como se escribieron (sin &amp;)
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

//Responde con un código de error y un mensaje en JSON, y corta la ejecución
function vacError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//Si el administrador apagó este módulo (Parámetros del sistema), no se registra nada
require_once __DIR__ . '/../Modelo/HanaConfig.php';
if (!HanaConfig::modulo('VACANTES')) { vacError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
hanaUsarModulo('VACANTES');
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { vacError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
//Casillas de Vacantes (Permisos de usuario), en los peajes asignados: llenar (31M), ver (48M) y
//cambiar la fecha comprometida por Gestión Humana (49M). El ADMIN TEC (24M) todo
if (!hanaEntra('VACANTES')) { vacError(403, 'Tu rol no tiene acceso a Vacantes. Se da en Configuración → Permisos de usuario.'); }
if (!count(hanaMisCentros())) { vacError(403, 'No tienes peajes asignados. Pide que te los asignen en Configuración → Colaboradores.'); }

$idColaborador = (int)$_SESSION['Idcolaborador'];
$puedeLlenar     = hanaPuede('VACANTES', 'llenar');
$puedeCompromiso = hanaPuede('VACANTES', 'compromiso');
$modeloVacante   = new Vacante();
$hoy = date('Y-m-d');
$ultimoEditable = HanaFechas::ventana()[1]; //las fechas no pueden pasar de hoy
$centros = array();
foreach (hanaMisCentros() as $centro) { $centros[(int)$centro['ID_CENTRO_OP']] = $centro; } //sus peajes

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //Lo que necesita la pantalla al abrir: peajes, estados, días de ANS y motivos
    case 'config':
        echo hanaJsonTexto(array('hoy' => $hoy, 'ultimoEditable' => $ultimoEditable, 'centros' => array_values($centros), 'estados' => Vacante::$ESTADOS,
                               'estadosGh' => Vacante::$ESTADOS_GH, 'diasAns' => Vacante::diasAns(), 'editaCompromiso' => $puedeCompromiso && $puedeLlenar, 'puedeLlenar' => $puedeLlenar,
                               'motivos' => $modeloVacante->motivos(array_keys($centros))), JSON_UNESCAPED_UNICODE);
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
        echo hanaJsonTexto($modeloVacante->listar($ids, $desde, $hasta, $abiertas, $hoy), JSON_UNESCAPED_UNICODE);
        break;

    //Crea una vacante nueva o guarda los cambios de una existente
    case 'guardar':
        if (!$puedeLlenar) { vacError(403, 'Tu rol solo consulta las vacantes.'); }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $antes = $modeloVacante->mostrar($id);
            if (!$antes || !isset($centros[(int)$antes['ID_CENTRO_OP']])) { vacError(404, 'No se encontró la vacante, o no es de tus centros.'); }
        }
        $datos = array();
        $datos['centro'] = isset($_POST['centro']) ? (int)$_POST['centro'] : 0;
        if (!isset($centros[$datos['centro']])) { vacError(400, 'Elige uno de tus peajes asignados.'); }
        $datos['cargo'] = HanaVal::texto(isset($_POST['cargo']) ? $_POST['cargo'] : '', 120);
        if ($datos['cargo'] === null) { vacError(400, 'Escribe el cargo de la vacante.'); }
        $datos['motivo'] = HanaVal::texto(isset($_POST['motivo']) ? $_POST['motivo'] : '', 150);
        if ($datos['motivo'] === null) { vacError(400, 'Escribe el motivo de la vacante.'); }
        $datos['fecha'] = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($datos['fecha'] === '' || $datos['fecha'] > $ultimoEditable) { vacError(400, 'La fecha de la vacante no es válida o es posterior a hoy.'); }

        //El acuerdo de servicio: 5 días hábiles desde la vacante, sin festivos.
        //Al editar se conserva el que tenía, salvo que cambie la fecha de la vacante
        $datos['ans'] = ($id > 0 && $antes['FECHA_VACANTE'] === $datos['fecha']) ? $antes['FECHA_ANS']
                                                                         : HanaFechas::sumarHabiles($datos['fecha'], Vacante::diasAns());

        $datos['estado'] = isset($_POST['estado']) ? $_POST['estado'] : 'ABIERTA';
        if (!isset(Vacante::$ESTADOS[$datos['estado']])) { vacError(400, 'Estado no válido.'); }
        //La fecha de cierre se pone sola: el día en que la vacante pasa a cubierta o cancelada.
        //Si ya estaba cerrada, conserva la que tenía; si vuelve a abierta, se borra
        if ($datos['estado'] === 'ABIERTA') { $datos['cierre'] = null; }
        elseif ($id > 0 && $antes['ESTADO_VACANTE'] !== 'ABIERTA' && $antes['FECHA_CIERRE']) { $datos['cierre'] = $antes['FECHA_CIERRE']; }
        else { $datos['cierre'] = max($hoy, $datos['fecha']); }

        $datos['rq']     = HanaVal::texto(isset($_POST['rq']) ? $_POST['rq'] : '', 20);
        $datos['nombre'] = HanaVal::texto(isset($_POST['nombre']) ? $_POST['nombre'] : '', 120);
        if ($datos['estado'] === 'CUBIERTA' && $datos['nombre'] === null) { vacError(400, 'Escribe el nombre de la persona que cubrió la vacante.'); }
        $datos['gh'] = isset($_POST['gh']) && $_POST['gh'] !== '' ? $_POST['gh'] : null;
        if ($datos['gh'] !== null && !isset(Vacante::$ESTADOS_GH[$datos['gh']])) { vacError(400, 'Estado de Gestión Humana no válido.'); }
        //La fecha comprometida por Gestión Humana es automática: la del acuerdo de servicio.
        //Solo el administrador (24M) la cambia. Si él ya la había cambiado, se conserva
        $automatica = $datos['ans'];
        $datos['compromiso'] = $automatica;
        if ($puedeCompromiso && isset($_POST['compromiso']) && trim($_POST['compromiso']) !== '') {
            $datos['compromiso'] = HanaVal::fecha($_POST['compromiso']);
            if ($datos['compromiso'] === '') { vacError(400, 'La fecha del compromiso de Gestión Humana no es válida.'); }
        } elseif (!$puedeCompromiso && $id > 0 && $antes['FECHA_COMPROMISO_GH'] && $antes['FECHA_COMPROMISO_GH'] !== $antes['FECHA_ANS']) {
            $datos['compromiso'] = $antes['FECHA_COMPROMISO_GH'];
        }
        $datos['obs'] = HanaVal::texto(isset($_POST['obs']) ? $_POST['obs'] : '', 300);

        $nuevo = $modeloVacante->guardar($id, $datos, $idColaborador, date('Y-m-d H:i:s'));
        if (!$nuevo) { vacError(500, 'No se pudo guardar la vacante. Intenta de nuevo.'); }
        echo hanaJsonTexto(array('ok' => true, 'id' => $nuevo, 'mensaje' => $id ? 'Vacante actualizada.'
                         : 'Vacante registrada. Fecha límite del acuerdo de servicio: ' . date('d/m/Y', strtotime($datos['ans'])) . '.'),
                         JSON_UNESCAPED_UNICODE);
        break;

    default:
        vacError(400, 'Operación no reconocida.');
}
