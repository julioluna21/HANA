<?php
/*
  HANA — Parámetros del sistema y catálogos de ausentismo
  Permiso 24M (el administrador técnico). Aquí se cambian las decisiones del
  negocio sin tocar el código.
*/
session_start();
require_once __DIR__ . "/../Modelo/HanaConfig.php";
require_once __DIR__ . "/../Modelo/HanaFechas.php"; //el día de hoy en la hora de Colombia
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
            //Prioridad y observador de las novedades automáticas: el número tiene que existir en su catálogo
            if ($clave === 'NOV_AUTO_PRIORIDAD'
                && !HanaDB::fila("SELECT 1 AS ok FROM estados_relevancia WHERE ID_ESTADOS_RELEVANCIA = ?", 'i', array((int)$valor))) {
                parError(400, 'No existe una prioridad con el número ' . (int)$valor . ' (Configuración → Estados de relevancia).');
            }
            if ($clave === 'NOV_AUTO_OBSERVADOR'
                && !HanaDB::fila("SELECT 1 AS ok FROM observador_novedades_hallazgos WHERE ID_OBSERVADOR_NOVEDADES_HALLAZGOS = ?", 'i', array((int)$valor))) {
                parError(400, 'No existe un observador con el número ' . (int)$valor . ' (Configuración → Observadores).');
            }
            if ($valor === (string)$d['VALOR']) { continue; }
            HanaDB::q("UPDATE configuracion SET VALOR = ?, ID_COLABORADOR_MODIFICA = ?, FEC_MODIFICACION = ? WHERE CLAVE = ?",
                      'siss', array($valor, $idColaborador, $ahora, $clave));
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
        $f = HanaDB::q("SELECT FECHA, MODULO, QUIEN AS PARA, ASUNTO, DETALLE AS METODO, OK, ERROR
                          FROM bitacora_sistema WHERE TIPO = 'CORREO'
                         ORDER BY FECHA DESC, ID_BITACORA DESC LIMIT 60");
        echo json_encode($f ? $f : array(), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Días habilitados: el reporte diario solo se llena HOY. Si alguien
    // necesita registrar o corregir otro día, el administrador se lo abre
    // aquí y después se lo cierra (tabla dia_habilitado, script 15)
    //-----------------------------------------------------------------------
    case 'diasHabilitados':
        //Las personas a las que se les puede abrir un día: primero quienes coordinan un proyecto
        $personas = HanaDB::q("SELECT c.ID_COLABORADOR, c.NOM_COLABORADOR,
                                      (SELECT GROUP_CONCAT(p.NOM_PROYECTO ORDER BY p.NOM_PROYECTO SEPARATOR ', ')
                                         FROM proyectos p
                                        WHERE p.Estado = '1' AND p.ID_COLABORADOR_COORDINADOR = c.ID_COLABORADOR) AS COORDINA
                                 FROM colaboradores c
                                WHERE c.ESTADO = 1
                                ORDER BY (COORDINA IS NULL), c.NOM_COLABORADOR");
        //Los días: arriba los que siguen abiertos; debajo los ya cerrados, para que quede el rastro
        $dias = HanaDB::q("SELECT d.ID_DIA_HABILITADO, d.FECHA, d.MOTIVO, d.ESTADO, d.FEC_HABILITA, d.FEC_DESHABILITA,
                                  c.NOM_COLABORADOR AS PERSONA, h.NOM_COLABORADOR AS HABILITO, x.NOM_COLABORADOR AS DESHABILITO
                             FROM dia_habilitado d
                             INNER JOIN colaboradores c ON c.ID_COLABORADOR = d.ID_COLABORADOR
                             LEFT  JOIN colaboradores h ON h.ID_COLABORADOR = d.ID_COLABORADOR_HABILITA
                             LEFT  JOIN colaboradores x ON x.ID_COLABORADOR = d.ID_COLABORADOR_DESHABILITA
                            ORDER BY d.ESTADO DESC, d.FECHA DESC, d.ID_DIA_HABILITADO DESC
                            LIMIT 200");
        if ($dias === false) { parError(500, 'Falta la tabla de días habilitados. Corre el script 15_DIA_HABILITADO_Y_HOY.sql.'); }
        echo json_encode(array('hoy' => HanaFechas::hoy(), 'personas' => $personas ? $personas : array(), 'dias' => $dias),
                         JSON_UNESCAPED_UNICODE);
        break;

    //Abre un día a una persona. Si ya se le había abierto y cerrado, se vuelve a abrir la misma fila
    case 'habilitarDia':
        $persona = isset($_POST['persona']) ? (int)$_POST['persona'] : 0;
        $fecha = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        $motivo = HanaVal::texto(isset($_POST['motivo']) ? $_POST['motivo'] : '', 200);
        if (!HanaDB::fila("SELECT 1 AS ok FROM colaboradores WHERE ID_COLABORADOR = ? AND ESTADO = 1", 'i', array($persona))) {
            parError(400, 'Elige a quién se le habilita el día.');
        }
        if ($fecha === '') { parError(400, 'Elige el día que se va a habilitar.'); }
        $hoyCol = HanaFechas::hoy();
        if ($fecha === $hoyCol) { parError(400, 'Hoy siempre está abierto: no hace falta habilitarlo.'); }
        //Un tope razonable, para que un error de dedo no abra un día de otro año
        if ($fecha < date('Y-m-d', strtotime("$hoyCol -366 days")) || $fecha > date('Y-m-d', strtotime("$hoyCol +31 days"))) {
            parError(400, 'El día debe estar entre un año atrás y un mes adelante.');
        }
        $ya = HanaDB::fila("SELECT ESTADO FROM dia_habilitado WHERE ID_COLABORADOR = ? AND FECHA = ?", 'is', array($persona, $fecha));
        if ($ya && (int)$ya['ESTADO'] === 1) { parError(400, 'Esa persona ya tiene habilitado ese día.'); }
        $ok = HanaDB::q("INSERT INTO dia_habilitado (ID_COLABORADOR, FECHA, MOTIVO, ESTADO, ID_COLABORADOR_HABILITA, FEC_HABILITA)
                         VALUES (?, ?, ?, 1, ?, ?)
                         ON DUPLICATE KEY UPDATE ESTADO = 1, MOTIVO = VALUES(MOTIVO),
                             ID_COLABORADOR_HABILITA = VALUES(ID_COLABORADOR_HABILITA), FEC_HABILITA = VALUES(FEC_HABILITA),
                             ID_COLABORADOR_DESHABILITA = NULL, FEC_DESHABILITA = NULL",
                        'issis', array($persona, $fecha, $motivo, $idColaborador, $ahora));
        if (!$ok) { parError(500, 'No se pudo habilitar el día.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Día habilitado. La persona ya puede registrar o corregir ese día.'), JSON_UNESCAPED_UNICODE);
        break;

    //Cierra un día que estaba abierto. La fila se conserva con quién y cuándo lo cerró
    case 'deshabilitarDia':
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $d = HanaDB::fila("SELECT ESTADO FROM dia_habilitado WHERE ID_DIA_HABILITADO = ?", 'i', array($id));
        if (!$d) { parError(404, 'Ese día habilitado no existe.'); }
        if ((int)$d['ESTADO'] === 0) { parError(400, 'Ese día ya estaba deshabilitado.'); }
        $ok = HanaDB::q("UPDATE dia_habilitado SET ESTADO = 0, ID_COLABORADOR_DESHABILITA = ?, FEC_DESHABILITA = ? WHERE ID_DIA_HABILITADO = ?",
                        'isi', array($idColaborador, $ahora, $id));
        if (!$ok) { parError(500, 'No se pudo deshabilitar el día.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Día deshabilitado. Vuelve a quedar solo de consulta.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        parError(400, 'Operación no reconocida.');
}
