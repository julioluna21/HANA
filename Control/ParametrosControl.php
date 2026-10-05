<?php
/*
  HANA — Parámetros del sistema y catálogos de ausentismo
  Permiso 24M (el administrador técnico). Aquí se cambian las decisiones del
  negocio sin tocar el código.
*/
session_start();
require_once __DIR__ . "/../Modelo/HanaConfig.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

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

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'listar':
        $par = HanaDB::q("SELECT c.*, col.NOM_COLABORADOR AS MODIFICO FROM configuracion c
                            LEFT JOIN colaboradores col ON col.ID_COLABORADOR = c.ID_COLABORADOR_MODIFICA
                           ORDER BY c.ORDEN");
        $cargos = HanaDB::q("SELECT * FROM aus_cargo ORDER BY ORDEN, NOMBRE");
        $novedades = HanaDB::q("SELECT n.*, c.NOMBRE AS CARGO FROM aus_novedad n LEFT JOIN aus_cargo c ON c.ID_CARGO_AUS = n.ID_CARGO_AUS ORDER BY n.ORDEN, n.NOMBRE");
        echo json_encode(array('parametros' => $par ? $par : array(), 'cargos' => $cargos ? $cargos : array(),
                               'novedades' => $novedades ? $novedades : array()), JSON_UNESCAPED_UNICODE);
        break;

    //Guarda los parámetros que cambiaron: valores[CLAVE] = VALOR
    case 'guardar':
        $valores = isset($_POST['valores']) && is_array($_POST['valores']) ? $_POST['valores'] : array();
        $defs = array();
        foreach ((array)HanaDB::q("SELECT * FROM configuracion") as $p) { $defs[$p['CLAVE']] = $p; }
        $cambios = 0;
        foreach ($valores as $clave => $valor) {
            if (!isset($defs[$clave])) { parError(400, 'Parámetro desconocido: ' . $clave); }
            $d = $defs[$clave];
            $valor = trim((string)$valor);
            if ($d['TIPO'] === 'SI_NO') {
                if ($valor !== '0' && $valor !== '1') { parError(400, '"' . $d['NOMBRE'] . '" debe ser sí o no.'); }
            } else {
                if (!preg_match('/^\d{1,4}$/', $valor)) { parError(400, '"' . $d['NOMBRE'] . '" debe ser un número entero.'); }
                if (($d['MINIMO'] !== null && (int)$valor < (int)$d['MINIMO']) || ($d['MAXIMO'] !== null && (int)$valor > (int)$d['MAXIMO'])) {
                    parError(400, '"' . $d['NOMBRE'] . '" debe estar entre ' . $d['MINIMO'] . ' y ' . $d['MAXIMO'] . '.');
                }
            }
            if ($valor === (string)$d['VALOR']) { continue; }
            HanaDB::q("UPDATE configuracion SET VALOR = ?, ID_COLABORADOR_MODIFICA = ?, FEC_MODIFICACION = ? WHERE CLAVE = ?",
                      'siss', array($valor, $idColaborador, $ahora, $clave));
            //El plazo de las RQ vive en su tabla de estados: se sincroniza
            if ($clave === 'RQ_DIAS_APROBACION') {
                HanaDB::q("UPDATE rq_estado SET DIAS_ESPERADOS = ? WHERE ID_RQ_ESTADO = 1", 'i', array((int)$valor));
            }
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
        $tabla = $esCargo ? 'aus_cargo' : 'aus_novedad';
        $pk = $esCargo ? 'ID_CARGO_AUS' : 'ID_NOVEDAD_AUS';
        $rep = HanaDB::fila("SELECT $pk AS ID FROM $tabla WHERE NOMBRE = ? AND $pk <> ?", 'si', array($nombre, $id));
        if ($rep) { parError(400, 'Ya existe uno con ese nombre.'); }
        if ($esCargo) {
            $aplica = isset($_POST['aplica']) ? $_POST['aplica'] : 'PEAJE';
            if (!in_array($aplica, array('PEAJE', 'BASCULA', 'AMBOS'), true)) { parError(400, 'Elige en qué centros aplica.'); }
            $ok = $id ? HanaDB::q("UPDATE aus_cargo SET NOMBRE = ?, APLICA = ?, ORDEN = ?, ESTADO = ? WHERE ID_CARGO_AUS = ?", 'ssiii', array($nombre, $aplica, $orden, $estado, $id))
                      : HanaDB::q("INSERT INTO aus_cargo (NOMBRE, APLICA, ORDEN, ESTADO) VALUES (?, ?, ?, ?)", 'ssii', array($nombre, $aplica, $orden, $estado));
        } else {
            $cuenta = isset($_POST['cuenta']) && $_POST['cuenta'] === '0' ? 0 : 1;
            $cargo = isset($_POST['cargo']) && (int)$_POST['cargo'] > 0 ? (int)$_POST['cargo'] : null;
            $ok = $id ? HanaDB::q("UPDATE aus_novedad SET NOMBRE = ?, CUENTA_AUSENCIA = ?, ID_CARGO_AUS = ?, ORDEN = ?, ESTADO = ? WHERE ID_NOVEDAD_AUS = ?", 'siiiii', array($nombre, $cuenta, $cargo, $orden, $estado, $id))
                      : HanaDB::q("INSERT INTO aus_novedad (NOMBRE, CUENTA_AUSENCIA, ID_CARGO_AUS, ORDEN, ESTADO) VALUES (?, ?, ?, ?, ?)", 'siiii', array($nombre, $cuenta, $cargo, $orden, $estado));
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
        $f = HanaDB::q("SELECT FECHA, MODULO, PARA, ASUNTO, METODO, OK, ERROR FROM correo_log ORDER BY ID_CORREO_LOG DESC LIMIT 60");
        echo json_encode($f ? $f : array(), JSON_UNESCAPED_UNICODE);
        break;

    default:
        parError(400, 'Operación no reconocida.');
}
