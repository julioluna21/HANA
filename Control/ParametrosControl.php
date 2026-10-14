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
$P = new Parametros();

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //Todos los parámetros, más los cargos y novedades de ausentismo, para la pantalla de Parámetros
    case 'listar':
        $par = HanaConfig::lista();
        $cargos = $P->cargos();
        $novedades = $P->novedades();
        echo json_encode(array('parametros' => $par ? $par : array(), 'cargos' => $cargos ? $cargos : array(),
                               'novedades' => $novedades ? $novedades : array()), JSON_UNESCAPED_UNICODE);
        break;

    //Guarda los parámetros que cambiaron: valores[CLAVE] = VALOR
    case 'guardar':
        $valores = isset($_POST['valores']) && is_array($_POST['valores']) ? $_POST['valores'] : array();
        $cambios = 0;
        foreach ($valores as $clave => $valor) {
            $d = HanaConfig::definicion((string)$clave);
            if (!$d) { parError(400, 'Parámetro desconocido: ' . $clave); }
            $valor = trim((string)$valor);
            if ($d['TIPO'] === 'SI_NO') {
                if ($valor !== '0' && $valor !== '1') { parError(400, '"' . $d['NOMBRE'] . '" debe ser sí o no.'); }
            } else {
                if (!preg_match('/^\d{1,4}$/', $valor)) { parError(400, '"' . $d['NOMBRE'] . '" debe ser un número entero.'); }
                if (($d['MINIMO'] !== null && (int)$valor < (int)$d['MINIMO']) || ($d['MAXIMO'] !== null && (int)$valor > (int)$d['MAXIMO'])) {
                    parError(400, '"' . $d['NOMBRE'] . '" debe estar entre ' . $d['MINIMO'] . ' y ' . $d['MAXIMO'] . '.');
                }
            }
            //Prioridad y observador de las novedades automáticas: el número tiene que existir en su catálogo
            if ($clave === 'NOV_AUTO_PRIORIDAD'
                && !$P->existePrioridad((int)$valor)) {
                parError(400, 'No existe una prioridad con el número ' . (int)$valor . ' (Configuración → Estados de relevancia).');
            }
            if ($clave === 'NOV_AUTO_OBSERVADOR'
                && !$P->existeObservador((int)$valor)) {
                parError(400, 'No existe un observador con el número ' . (int)$valor . ' (Configuración → Observadores).');
            }
            if ($valor === (string)HanaConfig::valor($clave, $d['DEFECTO'])) { continue; }
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
        if ($P->nombreRepetido($esCargo, $nombre, $id)) { parError(400, 'Ya existe uno con ese nombre.'); }
        if ($esCargo) {
            $aplica = isset($_POST['aplica']) ? $_POST['aplica'] : 'PEAJE';
            if (!in_array($aplica, array('PEAJE', 'BASCULA', 'AMBOS'), true)) { parError(400, 'Elige en qué centros aplica.'); }
            $ok = $P->guardarCargo($id, $nombre, $aplica, $orden, $estado);
        } else {
            $cuenta = isset($_POST['cuenta']) && $_POST['cuenta'] === '0' ? 0 : 1;
            $cargo = isset($_POST['cargo']) && (int)$_POST['cargo'] > 0 ? (int)$_POST['cargo'] : null;
            $ok = $P->guardarNovedad($id, $nombre, $cuenta, $cargo, $orden, $estado);
        }
        if (!$ok) { parError(500, 'No se pudo guardar.'); }
        $msg = $esCargo ? ($id ? 'Cargo actualizado.' : 'Cargo creado.') : ($id ? 'Novedad actualizada.' : 'Novedad creada.');
        echo json_encode(array('ok' => true, 'mensaje' => $msg), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Prueba de correo: revisa el servidor y envía un correo real mostrando
    // cada intento (ver hanaEnviar en CorreoConfig.php)
    //-----------------------------------------------------------------------
    case 'probarCorreo':
        $para = isset($_POST['para']) ? trim($_POST['para']) : '';
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) { parError(400, 'Escribe un correo válido para la prueba.'); }
        require_once __DIR__ . '/CorreoConfig.php';
        require_once __DIR__ . '/../public/PHPMailer-master/src/Exception.php';
        require_once __DIR__ . '/../public/PHPMailer-master/src/PHPMailer.php';
        require_once __DIR__ . '/../public/PHPMailer-master/src/SMTP.php';

        //1. Lo que se puede revisar sin enviar nada
        $rev = array();
        $cta = hanaCorreoDatos(); //la cuenta que se usa: la de Global.php si es real; si no, la del sistema original
        $host = $cta['host'];
        $rev[] = array('Cuenta de correo', true, 'Servidor ' . $host . ':' . $cta['port'] . ' (' . $cta['secure'] . '), cuenta ' . $cta['user']
                       . ($cta['pass'] === HANA_CORREO_PASSWORD ? ' (la del sistema original)' : ' (la de Global.php)'));
        $rev[] = array('Extensión openssl de PHP', extension_loaded('openssl'), extension_loaded('openssl') ? 'Activa' : 'Sin ella no se puede usar SSL ni TLS');
        if ($host !== '') {
            $ip = gethostbyname($host);
            $rev[] = array('El nombre del servidor se encuentra (DNS)', $ip !== $host, $ip !== $host ? $host . ' = ' . $ip : 'No se encontró ' . $host);
            //¿El hosting deja salir conexiones a esos puertos? Si no, es un bloqueo del servidor
            foreach (array(465, 587, 25) as $pt) {
                $t0 = microtime(true);
                $sock = @fsockopen($host, $pt, $errno, $errstr, 5);
                $rev[] = array('Conexión a ' . $host . ':' . $pt, (bool)$sock,
                               $sock ? 'Abre en ' . round((microtime(true) - $t0) * 1000) . ' ms' : 'No abre (' . ($errstr ?: 'sin respuesta') . '). Si ninguno abre, el hosting bloquea la salida de correo');
                if ($sock) { fclose($sock); }
            }
        }
        $rev[] = array('mail() de PHP (correo del servidor)', function_exists('mail') && !in_array('mail', array_map('trim', explode(',', (string)ini_get('disable_functions')))),
                       function_exists('mail') ? 'Disponible' : 'Deshabilitado en este servidor');

        //2. El envío real, con todos los intentos
        $mail = new \PHPMailer\PHPMailer\PHPMailer();
        $mail->CharSet = 'UTF-8';
        hanaConfigurarSmtp($mail);
        $mail->FromName = 'HANA - Prueba de correo';
        $mail->addAddress($para);
        $mail->isHTML(true);
        $mail->Subject = 'Prueba de correo de HANA';
        $mail->Body = hanaCorreoHtml('', 'Prueba de correo de HANA', '<p style="margin:0 0 10px;">Este es un correo de prueba enviado desde <strong>Parámetros del sistema</strong> el '
                    . date('d/m/Y') . ' a las ' . date('H:i') . '.</p><p style="margin:0;">Si te llegó, el correo del sistema está funcionando.</p>');
        $diag = array();
        $ok = hanaEnviar($mail, 'PRUEBA', $diag);
        echo json_encode(array('ok' => $ok, 'revision' => $rev, 'intentos' => $diag), JSON_UNESCAPED_UNICODE);
        break;

    //Los últimos envíos de correo, con su resultado
    case 'correoLog':
        $f = Bitacora::ultimosCorreos(60);
        echo json_encode($f ? $f : array(), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Días habilitados: el reporte diario solo se llena HOY. Si alguien
    // necesita registrar o corregir otro día, el administrador se lo abre
    // aquí y después se lo cierra (tabla dia_habilitado, script 15)
    //-----------------------------------------------------------------------
    case 'diasHabilitados':
        //Las personas a las que se les puede abrir un día: primero quienes coordinan un proyecto
        $personas = Organizacion::personasConProyectos();
        //Los días: arriba los que siguen abiertos; debajo los ya cerrados, para que quede el rastro
        $dias = $P->diasHabilitados();
        if ($dias === false) { parError(500, 'Falta la tabla de días habilitados. Corre el script 15_DIA_HABILITADO_Y_HOY.sql.'); }
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
        if ($P->estadoDia($persona, $fecha) === 1) { parError(400, 'Esa persona ya tiene habilitado ese día.'); }
        $ok = $P->habilitarDia($persona, $fecha, $motivo, $idColaborador, $ahora);
        if (!$ok) { parError(500, 'No se pudo habilitar el día.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Día habilitado. La persona ya puede registrar o corregir ese día.'), JSON_UNESCAPED_UNICODE);
        break;

    //Cierra un día que estaba abierto. La fila se conserva con quién y cuándo lo cerró
    case 'deshabilitarDia':
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $d = $P->estadoDiaPorId($id);
        if ($d === null) { parError(404, 'Ese día habilitado no existe.'); }
        if ($d === 0) { parError(400, 'Ese día ya estaba deshabilitado.'); }
        $ok = $P->deshabilitarDia($id, $idColaborador, $ahora);
        if (!$ok) { parError(500, 'No se pudo deshabilitar el día.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Día deshabilitado. Vuelve a quedar solo de consulta.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        parError(400, 'Operación no reconocida.');
}
