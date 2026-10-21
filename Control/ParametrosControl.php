<?php
/*
  HANA - Parámetros del sistema y catálogos de ausentismo
  Permiso 24M (el administrador técnico). Aquí se cambian las decisiones del
  negocio sin tocar el código.
*/
session_start();
require_once __DIR__ . "/../Modelo/HanaConfig.php";
require_once __DIR__ . "/../Modelo/HanaFechas.php"; //el día de hoy en la hora de Colombia
require_once __DIR__ . "/../Modelo/ParametrosModelo.php";
require_once __DIR__ . "/../Modelo/BitacoraModelo.php";
require_once __DIR__ . "/AccesoHelper.php"; //incluye OrganizacionModelo

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

//Responde con un código de error y un mensaje en JSON, y corta la ejecución
function parError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { parError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!hanaTienePermiso('24M')) { parError(403, 'No tienes permiso para los parámetros del sistema.'); }
$idColaborador = (int)$_SESSION['Idcolaborador'];
$ahora = date('Y-m-d H:i:s');
$modeloParametros = new Parametros();

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //Todos los parámetros, más los cargos y novedades de ausentismo, para la pantalla de Parámetros
    case 'listar':
        $par = HanaConfig::lista();
        $cargos = $modeloParametros->cargos();
        $novedades = $modeloParametros->novedades();
        echo json_encode(array('parametros' => $par ? $par : array(), 'cargos' => $cargos ? $cargos : array(),
                               'novedades' => $novedades ? $novedades : array()), JSON_UNESCAPED_UNICODE);
        break;

    //Guarda los parámetros que cambiaron: valores[CLAVE] = VALOR
    case 'guardar':
        $valores = isset($_POST['valores']) && is_array($_POST['valores']) ? $_POST['valores'] : array();
        $cambios = 0;
        foreach ($valores as $clave => $valor) {
            $definicion = HanaConfig::definicion((string)$clave);
            if (!$definicion) { parError(400, 'Parámetro desconocido: ' . $clave); }
            $valor = trim((string)$valor);
            if ($definicion['TIPO'] === 'SI_NO') {
                if ($valor !== '0' && $valor !== '1') { parError(400, '"' . $definicion['NOMBRE'] . '" debe ser sí o no.'); }
            } else {
                if (!preg_match('/^\d{1,4}$/', $valor)) { parError(400, '"' . $definicion['NOMBRE'] . '" debe ser un número entero.'); }
                if (($definicion['MINIMO'] !== null && (int)$valor < (int)$definicion['MINIMO']) || ($definicion['MAXIMO'] !== null && (int)$valor > (int)$definicion['MAXIMO'])) {
                    parError(400, '"' . $definicion['NOMBRE'] . '" debe estar entre ' . $definicion['MINIMO'] . ' y ' . $definicion['MAXIMO'] . '.');
                }
            }
            if ($valor === (string)HanaConfig::valor($clave, $definicion['DEFECTO'])) { continue; }
            HanaConfig::guardar($clave, $valor, $idColaborador, $ahora);
            $cambios++;
        }
        echo json_encode(array('ok' => true, 'mensaje' => $cambios ? ($cambios === 1 ? 'Se guardó 1 cambio.' : "Se guardaron $cambios cambios.") : 'No había cambios.'),
                         JSON_UNESCAPED_UNICODE);
        break;

    //Catálogos de ausentismo: crear o editar un cargo o una novedad
    case 'cargo':
    case 'novedad':
        $esCargo = $_GET['op'] === 'cargo';
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $crudo = isset($_POST['nombre']) ? trim((string)$_POST['nombre']) : '';
        $crudo = function_exists('mb_strtoupper') ? mb_strtoupper($crudo, 'UTF-8')
               : strtr(strtoupper($crudo), array('á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ñ' => 'Ñ'));
        $nombre = HanaVal::texto($crudo, 80);
        if ($nombre === null) { parError(400, 'Escribe el nombre.'); }
        $orden = isset($_POST['orden']) ? (int)$_POST['orden'] : 0;
        $estado = isset($_POST['estado']) && $_POST['estado'] === '0' ? 0 : 1;
        if ($modeloParametros->nombreRepetido($esCargo, $nombre, $id)) { parError(400, 'Ya existe uno con ese nombre.'); }
        if ($esCargo) {
            $aplica = isset($_POST['aplica']) ? $_POST['aplica'] : 'PEAJE';
            if (!in_array($aplica, array('PEAJE', 'BASCULA', 'AMBOS'), true)) { parError(400, 'Elige en qué centros aplica.'); }
            $ok = $modeloParametros->guardarCargo($id, $nombre, $aplica, $orden, $estado);
        } else {
            $cuenta = isset($_POST['cuenta']) && $_POST['cuenta'] === '0' ? 0 : 1;
            $cargo = isset($_POST['cargo']) && (int)$_POST['cargo'] > 0 ? (int)$_POST['cargo'] : null;
            $ok = $modeloParametros->guardarNovedad($id, $nombre, $cuenta, $cargo, $orden, $estado);
        }
        if (!$ok) { parError(500, 'No se pudo guardar.'); }
        $msg = $esCargo ? ($id ? 'Cargo actualizado.' : 'Cargo creado.') : ($id ? 'Novedad actualizada.' : 'Novedad creada.');
        echo json_encode(array('ok' => true, 'mensaje' => $msg), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Días habilitados: el reporte diario solo se llena HOY. Si alguien
    // necesita registrar o corregir otro día, el administrador se lo abre
    // aquí y después se lo cierra (tabla dia_habilitado)
    //-----------------------------------------------------------------------
    case 'diasHabilitados':
        //Las personas a las que se les puede abrir un día: primero quienes coordinan un proyecto
        $personas = Organizacion::personasConProyectos();
        //Los días: arriba los que siguen abiertos; debajo los ya cerrados, para que quede el rastro
        $dias = $modeloParametros->diasHabilitados();
        if ($dias === false) {
            error_log('HANA - no se pudo leer la tabla dia_habilitado (script 15)'); //el detalle queda para el administrador
            parError(500, 'Los días habilitados no están disponibles en este momento. Avísale al administrador del sistema.');
        }
        echo json_encode(array('hoy' => HanaFechas::hoy(), 'personas' => $personas ? $personas : array(), 'dias' => $dias),
                         JSON_UNESCAPED_UNICODE);
        break;

    //Abre un día a una persona. Si ya se le había abierto y cerrado, se vuelve a abrir la misma fila
    case 'habilitarDia':
        $persona = isset($_POST['persona']) ? (int)$_POST['persona'] : 0;
        $fecha = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        $motivo = HanaVal::texto(isset($_POST['motivo']) ? $_POST['motivo'] : '', 200);
        if (!Organizacion::colaboradorActivo($persona)) {
            parError(400, 'Elige a quién se le habilita el día.');
        }
        if ($fecha === '') { parError(400, 'Elige el día que se va a habilitar.'); }
        $hoyCol = HanaFechas::hoy();
        if ($fecha === $hoyCol) { parError(400, 'Hoy siempre está abierto: no hace falta habilitarlo.'); }
        //Un tope razonable, para que un error de dedo no abra un día de otro año
        if ($fecha < date('Y-m-d', strtotime("$hoyCol -366 days")) || $fecha > date('Y-m-d', strtotime("$hoyCol +31 days"))) {
            parError(400, 'El día debe estar entre un año atrás y un mes adelante.');
        }
        if ($modeloParametros->estadoDia($persona, $fecha) === 1) { parError(400, 'Esa persona ya tiene habilitado ese día.'); }
        $ok = $modeloParametros->habilitarDia($persona, $fecha, $motivo, $idColaborador, $ahora);
        if (!$ok) { parError(500, 'No se pudo habilitar el día.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Día habilitado. La persona ya puede registrar o corregir ese día.'), JSON_UNESCAPED_UNICODE);
        break;

    //Cierra un día que estaba abierto. La fila se conserva con quién y cuándo lo cerró
    case 'deshabilitarDia':
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $estadoDia = $modeloParametros->estadoDiaPorId($id);
        if ($estadoDia === null) { parError(404, 'Ese día habilitado no existe.'); }
        if ($estadoDia === 0) { parError(400, 'Ese día ya estaba deshabilitado.'); }
        $ok = $modeloParametros->deshabilitarDia($id, $idColaborador, $ahora);
        if (!$ok) { parError(500, 'No se pudo deshabilitar el día.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Día deshabilitado. Vuelve a quedar solo de consulta.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        parError(400, 'Operación no reconocida.');
}
