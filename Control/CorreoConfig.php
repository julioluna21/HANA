<?php
/*
  HANA — Cuenta de correo del sistema
  ---------------------------------------------------------------------------
  Todos los envíos de correo (novedades, listas, recuperar contraseña,
  recordatorios) toman la cuenta de aquí.

  Es la misma cuenta que usaba el sistema original: regencysa.net, puerto 465
  con SSL, usuario no-reply@regencysa.net. Sus datos quedan escritos aquí
  (como en el original) para que el correo funcione siempre, en el hosting y
  en XAMPP. Si Conexion/Global.php define MAIL_HOST, MAIL_USER y
  MAIL_PASSWORD con datos reales, se usan esos en vez de estos.
*/

require_once __DIR__ . '/../Conexion/Global.php';

//La cuenta del original (se usa si Global.php no trae una cuenta real)
define('HANA_CORREO_HOST', 'regencysa.net');
define('HANA_CORREO_PORT', 465);
define('HANA_CORREO_SECURE', 'ssl');
define('HANA_CORREO_USER', 'no-reply@regencysa.net');
define('HANA_CORREO_PASSWORD', 'Pr0t1nc0315*');

//¿HANA está corriendo en el computador de alguien (XAMPP) y no en el servidor?
function hanaEsLocal()
{
    $h = isset($_SERVER['HTTP_HOST']) ? strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'])) : '';
    return in_array($h, array('localhost', '127.0.0.1', '::1'), true) || (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows');
}

//Los datos de la cuenta que se van a usar: los de Global.php si son reales;
//si no están o traen la contraseña de ejemplo, los del original
function hanaCorreoDatos()
{
    $real = defined('MAIL_HOST') && defined('MAIL_USER') && defined('MAIL_PASSWORD')
         && MAIL_PASSWORD !== '' && stripos(MAIL_PASSWORD, 'ESCRIBE_AQUI') === false && stripos(MAIL_PASSWORD, 'LA_CONTRASENA') === false;
    if ($real) {
        return array('host' => MAIL_HOST, 'port' => defined('MAIL_PORT') ? (int)MAIL_PORT : 465,
                     'secure' => defined('MAIL_SECURE') ? MAIL_SECURE : 'ssl', 'user' => MAIL_USER, 'pass' => MAIL_PASSWORD);
    }
    return array('host' => HANA_CORREO_HOST, 'port' => HANA_CORREO_PORT, 'secure' => HANA_CORREO_SECURE,
                 'user' => HANA_CORREO_USER, 'pass' => HANA_CORREO_PASSWORD);
}

//Pone en $mail el servidor, la cuenta y el remitente (siempre hay una cuenta: devuelve true)
function hanaConfigurarSmtp($mail)
{
    $d = hanaCorreoDatos();
    $mail->isSMTP();
    $mail->SMTPAuth   = true;
    $mail->Host       = $d['host'];
    $mail->Port       = $d['port'];
    $mail->SMTPSecure = $d['secure'];
    $mail->Username   = $d['user'];
    $mail->Password   = $d['pass'];
    $mail->From       = $d['user'];
    $mail->CharSet    = 'UTF-8'; //tildes y ñ bien escritas
    $mail->isHTML(true);
    return true;
}

//"JAIME ANTONIO MARÍN" -> "Jaime Antonio Marín", para saludar a la persona por su nombre
function hanaNombreBonito($nombre)
{
    $n = trim(html_entity_decode((string)$nombre, ENT_QUOTES, 'UTF-8'));
    if ($n === '') { return ''; }
    return function_exists('mb_convert_case') ? mb_convert_case(mb_strtolower($n, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : ucwords(strtolower($n));
}

//La dirección de una pantalla del sistema, para los botones de los correos
//(la del servidor donde está corriendo; si se llama sin navegador, la de producción)
function hanaUrlSistema($vista = 'login.php')
{
    if (empty($_SERVER['HTTP_HOST'])) { return 'https://hana.cloudregencyapps.com/Vista/' . $vista; }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/Vista/' . $vista;
}

/*
  La plantilla de todos los correos (hanaCorreoHtml): encabezado con la marca, saludo a la
  persona, el contenido, un botón opcional y el pie. Se arma con tablas y
  estilos en línea porque así se ve bien en Outlook, Gmail y el celular.
    $nombre     a quién va (vacío = "Hola:")
    $titulo     el asunto, grande arriba
    $contenido  HTML del mensaje
    $boton      array('texto' => ..., 'url' => ...) o null
*/
function hanaCorreoHtml($nombre, $titulo, $contenido, $boton = null)
{
    //En el saludo, nombre y segundo nombre ("Jaime Antonio"); el nombre completo va en el campo Para
    $n = implode(' ', array_slice(preg_split('/\s+/', hanaNombreBonito($nombre)), 0, 2));
    $saludo = $n !== '' ? 'Hola, ' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . ':' : 'Hola:';
    $btn = '';
    if ($boton && !empty($boton['url'])) {
        $btn = '<p style="margin:22px 0 6px;"><a href="' . htmlspecialchars($boton['url'], ENT_QUOTES, 'UTF-8') . '" style="background:#149C83;color:#ffffff;'
             . 'padding:11px 20px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;">'
             . htmlspecialchars($boton['texto'], ENT_QUOTES, 'UTF-8') . '</a></p>';
    }
    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
         . '<body style="margin:0;padding:0;background:#F7F4F1;">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F4F1;padding:24px 10px;"><tr><td align="center">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #ECE6E1;">'
         . '<tr><td style="background:#6E1A1E;padding:18px 24px;color:#ffffff;font-family:Arial,sans-serif;font-size:18px;font-weight:bold;">HANA · Grupo Regency</td></tr>'
         . '<tr><td style="padding:24px;font-family:Arial,sans-serif;color:#2B2525;font-size:15px;line-height:1.55;">'
         . '<p style="margin:0 0 6px;font-size:13px;color:#7A716E;">' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</p>'
         . '<p style="margin:0 0 14px;font-size:17px;font-weight:bold;">' . $saludo . '</p>'
         . $contenido . $btn
         . '</td></tr>'
         . '<tr><td style="padding:14px 24px;background:#FBF8F6;font-family:Arial,sans-serif;font-size:12px;color:#7A716E;">'
         . 'Este correo lo envía HANA automáticamente. Por favor no lo respondas.</td></tr>'
         . '</table></td></tr></table></body></html>';
}

/*
  ---------------------------------------------------------------------------
  hanaEnviar($mail, $modulo): envía con "plan B"
  ---------------------------------------------------------------------------
  Antes cada módulo llamaba $mail->send() una sola vez: si la cuenta SMTP no
  respondía (el hosting bloquea la salida, el certificado no coincide, el
  puerto está cerrado...), el correo se perdía y solo quedaba una línea en el
  error_log. Ahora se prueban varias formas, en este orden, y se queda con la
  primera que funcione:

    1. La cuenta SMTP tal como está en Global.php (por ejemplo 465 con SSL).
    2. El otro puerto: 587 con TLS (o 465 con SSL, si el primero era 587).
    3. Lo mismo sin verificar el certificado (solo si el administrador lo
       permitió en Parámetros: CORREO_SIN_CERTIFICADO).
    4. El correo del propio servidor, mail() de PHP (CORREO_SERVIDOR_LOCAL).
       cPanel casi siempre lo deja salir, aunque puede caer en spam si el
       dominio del remitente no autoriza al servidor (registro SPF).

  Cada intento queda en la tabla correo_log con su resultado. En
  Parámetros → Correo hay un botón para enviar una prueba y ver qué pasó.

  $diagnostico (opcional): si se pasa un arreglo, se llena con cada intento y
  la conversación con el servidor (sin la contraseña), para la prueba.
*/
//Convierte cada <img src="data:image/png;base64,..."> del cuerpo en una imagen
//incrustada del correo (cid:). Se hace una sola vez por correo
function hanaIncrustarImagenes($mail)
{
    if (!empty($mail->hanaImagenesListas) || stripos((string)$mail->Body, 'data:image') === false) { return; }
    $n = 0;
    $mail->Body = preg_replace_callback('#src=(["\'])data:image/(png|jpe?g|gif);base64,([A-Za-z0-9+/=\s]+)\1#i', function ($m) use ($mail, &$n) {
        $binario = base64_decode(preg_replace('/\s+/', '', $m[3]), true);
        if ($binario === false || $binario === '') { return $m[0]; } //si no se puede leer, se deja como estaba
        $n++;
        $ext = strtolower($m[2]) === 'jpg' ? 'jpeg' : strtolower($m[2]);
        $cid = 'hana_img_' . $n . '_' . substr(md5($binario), 0, 8);
        $mail->addStringEmbeddedImage($binario, $cid, 'firma_' . $n . '.' . ($ext === 'jpeg' ? 'jpg' : $ext), 'base64', 'image/' . $ext);
        return 'src=' . $m[1] . 'cid:' . $cid . $m[1];
    }, (string)$mail->Body);
    $mail->hanaImagenesListas = true;
}

function hanaEnviar($mail, $modulo = 'GENERAL', &$diagnostico = null)
{
    require_once __DIR__ . '/../Modelo/HanaConfig.php';
    //La cuenta: la de Global.php si es real; si no, la del sistema original
    $cta = hanaCorreoDatos();

    //Para que los filtros de spam (Gmail, Outlook) confíen más en el correo:
    // - el identificador del mensaje y el saludo al servidor con el dominio real, no "localhost"
    $dominio = substr(strrchr($cta['user'], '@'), 1);
    if ($dominio) { $mail->Hostname = $dominio; $mail->Helo = $dominio; }
    // - los rebotes ("no se pudo entregar") llegan al buzón de la cuenta
    $mail->Sender = $cta['user'];
    // - siempre una versión en texto simple además del HTML (los correos solo HTML suman spam)
    if (trim((string)$mail->AltBody) === '' && $mail->ContentType === 'text/html') {
        $texto = preg_replace('/<(br|\/p|\/tr|\/li|\/h\d|\/div)[^>]*>/i', "\n", (string)$mail->Body);
        $mail->AltBody = trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($texto), ENT_QUOTES, 'UTF-8')));
    }

    // - las imágenes escritas dentro del HTML (las firmas, src="data:image/...") se
    //   vuelven imágenes incrustadas (cid:): Gmail y Outlook bloquean las primeras y
    //   muestran un cuadro roto; las incrustadas sí se ven y se pueden ampliar
    hanaIncrustarImagenes($mail);

    //Los intentos, en orden
    $puerto = (int)$cta['port'];
    $seguro = $cta['secure'];
    $intentos = array(array('smtp', $puerto, $seguro, true));
    if (HanaConfig::si('CORREO_PLAN_B')) {
        $intentos[] = $puerto === 587 ? array('smtp', 465, 'ssl', true) : array('smtp', 587, 'tls', true);
    }
    //Sin verificar el certificado: si el administrador lo permitió, o si HANA corre en un
    //computador con XAMPP (en Windows a veces faltan los certificados raíz y PHP rechaza el SSL)
    if (HanaConfig::si('CORREO_SIN_CERTIFICADO', false) || hanaEsLocal()) {
        $intentos[] = array('smtp', $puerto, $seguro, false);
        if (HanaConfig::si('CORREO_PLAN_B')) { $intentos[] = $puerto === 587 ? array('smtp', 465, 'ssl', false) : array('smtp', 587, 'tls', false); }
    }
    if (HanaConfig::si('CORREO_SERVIDOR_LOCAL')) { $intentos[] = array('local', 0, '', true); }

    foreach ($intentos as $it) {
        list($tipo, $p, $s, $verificar) = $it;
        $conversacion = '';
        $mail->ErrorInfo = '';
        if ($tipo === 'smtp') {
            $mail->isSMTP();
            $mail->Host = $cta['host'];
            $mail->Port = $p;
            $mail->SMTPSecure = $s;
            $mail->SMTPAuth = true;
            $mail->Username = $cta['user'];
            $mail->Password = $cta['pass'];
            $mail->Timeout = 8; //si no responde, a los 8 segundos se pasa al siguiente intento
            $mail->SMTPOptions = $verificar ? array()
                : array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
            $metodo = 'SMTP ' . $p . '/' . $s . ($verificar ? '' : ' sin verificar certificado');
        } else {
            $mail->isMail();
            $mail->Sender = $cta['user']; //el remitente del sobre, para que el rebote llegue a la cuenta
            $metodo = 'correo del servidor (mail de PHP)';
        }
        //En la prueba de correo se guarda la conversación con el servidor, sin la contraseña
        if (is_array($diagnostico)) {
            $mail->SMTPDebug = 3;
            $mail->Debugoutput = function ($texto) use (&$conversacion) {
                if (stripos($texto, 'AUTH') !== false || preg_match('/^CLIENT -> SERVER: [A-Za-z0-9+\/=]{12,}\s*$/', trim($texto))) {
                    $texto = "CLIENT -> SERVER: [credenciales ocultas]\n";
                }
                $conversacion .= $texto;
            };
        }
        try {
            $ok = $mail->send();
        } catch (\Throwable $e) {
            $ok = false;
            $mail->ErrorInfo = $e->getMessage();
        }
        if ($tipo === 'smtp') { $mail->smtpClose(); }
        hanaCorreoLog($mail, $modulo, $metodo, $ok, $ok ? null : $mail->ErrorInfo);
        if (is_array($diagnostico)) { $diagnostico[] = array('metodo' => $metodo, 'ok' => $ok, 'error' => $ok ? '' : $mail->ErrorInfo, 'conversacion' => $conversacion); }
        if ($ok) { return true; }
        error_log('HANA - correo (' . $modulo . ') falló con ' . $metodo . ': ' . $mail->ErrorInfo);
        //Solo se reintenta por otra vía si falló ANTES de entregar el mensaje (al conectar,
        //cifrar o iniciar sesión). Si el servidor ya lo había recibido y falló después,
        //reintentar mandaría el correo repetido
        if (stripos($mail->ErrorInfo, 'data not accepted') !== false || stripos($mail->ErrorInfo, 'DATA END') !== false) { return false; }
    }
    return false;
}

//Anota un intento de envío en correo_log (si la tabla no existe, no pasa nada)
function hanaCorreoLog($mail, $modulo, $metodo, $ok, $error)
{
    try {
        require_once __DIR__ . '/../Modelo/HanaDB.php';
        $para = implode(', ', array_map(function ($a) { return $a[0]; }, $mail->getToAddresses()));
        HanaDB::q("INSERT INTO correo_log (FECHA, MODULO, PARA, ASUNTO, METODO, OK, ERROR) VALUES (NOW(), ?, ?, ?, ?, ?, ?)",
                  'ssssis', array(substr($modulo, 0, 30), substr($para, 0, 500), substr((string)$mail->Subject, 0, 250),
                                  $metodo, $ok ? 1 : 0, $error === null ? null : substr((string)$error, 0, 500)));
    } catch (\Throwable $e) {
        error_log('HANA - no se pudo anotar el envío de correo: ' . $e->getMessage());
    }
}
