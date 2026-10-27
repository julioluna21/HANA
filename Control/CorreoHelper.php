<?php
/*
  HANA - Envío de los correos
  ---------------------------------------------------------------------------
  Todos los correos del sistema (novedades, RQ, listas de chequeo, usuarios,
  recuperar la contraseña, recordatorios y resúmenes) salen por aquí.

  La cuenta de correo es del servidor, no del sistema: se escribe una sola vez en
  Conexion/Global.php, que es distinto en cada servidor y no viaja en los paquetes:
      MAIL_HOST      el servidor de correo (por ejemplo regencysa.net)
      MAIL_PORT      465 si va con SSL, 587 si va con TLS
      MAIL_SECURE    'ssl' (puerto 465) o 'tls' (puerto 587)
      MAIL_USER      la cuenta que envía (por ejemplo no-reply@regencysa.net)
      MAIL_PASSWORD  su contraseña
  Opcional, solo si el certificado del servidor de correo no coincide con su nombre:
      define('MAIL_VERIFICAR_CERTIFICADO', false);
  En un equipo con XAMPP no se revisa el certificado (en Windows suelen faltar los
  certificados raíz y PHP rechazaría el SSL).

  Cada correo se envía una sola vez, con esa cuenta. Si no sale, no se prueban otras
  formas: queda anotado en bitacora_sistema (TIPO 'CORREO') con el motivo, y en el
  error_log de PHP.

  Este archivo reemplaza a Control/CorreoConfig.php, que traía la cuenta escrita en el
  código y los intentos de respaldo que se prendían desde Parámetros del sistema.
*/
if (function_exists('hanaEnviar')) { return; } //ya se cargó

require_once __DIR__ . '/../Conexion/Global.php';
require_once __DIR__ . '/../Modelo/BitacoraModelo.php'; //cada envío queda anotado (se carga aquí para que la conexión a la base quede disponible para todo)

//¿HANA está corriendo en el computador de alguien (XAMPP) y no en el servidor?
function hanaEsLocal()
{
    $h = isset($_SERVER['HTTP_HOST']) ? strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'])) : '';
    return in_array($h, array('localhost', '127.0.0.1', '::1'), true) || (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows');
}

//La cuenta de Conexion/Global.php, o null si no está escrita (falta algún dato o la contraseña es la del ejemplo)
function hanaCorreoDatos()
{
    if (!defined('MAIL_HOST') || !defined('MAIL_USER') || !defined('MAIL_PASSWORD')) { return null; }
    if (MAIL_HOST === '' || MAIL_USER === '' || MAIL_PASSWORD === '' || stripos(MAIL_PASSWORD, 'ESCRIBE_AQUI') !== false) { return null; }
    return array('host' => MAIL_HOST, 'port' => defined('MAIL_PORT') ? (int)MAIL_PORT : 465,
                 'secure' => defined('MAIL_SECURE') ? MAIL_SECURE : 'ssl', 'user' => MAIL_USER, 'pass' => MAIL_PASSWORD);
}

//Pone en $mail el servidor, la cuenta y el remitente. Devuelve false si la cuenta no está escrita en Global.php
function hanaConfigurarSmtp($mail)
{
    $mail->CharSet = 'UTF-8'; //tildes y ñ bien escritas
    $mail->isHTML(true);
    $d = hanaCorreoDatos();
    if (!$d) { return false; }
    $mail->isSMTP();
    $mail->SMTPAuth   = true;
    $mail->Host       = $d['host'];
    $mail->Port       = $d['port'];
    $mail->SMTPSecure = $d['secure'];
    $mail->Username   = $d['user'];
    $mail->Password   = $d['pass'];
    $mail->From       = $d['user'];
    return true;
}

//"MARÍA JOSÉ PÉREZ" -> "María José Pérez", para saludar a la persona por su nombre
function hanaNombreBonito($nombre)
{
    $n = trim(html_entity_decode((string)$nombre, ENT_QUOTES, 'UTF-8'));
    if ($n === '') { return ''; }
    return function_exists('mb_convert_case') ? mb_convert_case(mb_strtolower($n, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : ucwords(strtolower($n));
}

//La dirección de una pantalla del sistema, para los botones de los correos: la del servidor
//donde está corriendo. Desde una tarea programada (sin navegador) es HANA_URL de Global.php
//o, si no está, la de producción
function hanaUrlSistema($vista = 'login.php')
{
    if (empty($_SERVER['HTTP_HOST'])) {
        $base = defined('HANA_URL') ? rtrim(HANA_URL, '/') : 'https://hana.cloudregencyapps.com';
        return $base . '/Vista/' . $vista;
    }
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
    //En el saludo, nombre y segundo nombre ("María José"); el nombre completo va en el campo Para
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

//Convierte cada <img src="data:image/png;base64,..."> del cuerpo en una imagen
//incrustada del correo (cid:): Gmail y Outlook bloquean las primeras y muestran un
//cuadro roto; las incrustadas sí se ven. Se hace una sola vez por correo
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

//Envía un correo con la cuenta de Global.php y deja constancia en la bitácora.
//Devuelve true si salió. $modulo dice de dónde sale (LISTAS, NOVEDADES, USUARIOS...)
function hanaEnviar($mail, $modulo = 'GENERAL')
{
    //Modo prueba (solo lo enciende tareas/probar_correos.php, por línea de comandos): todo correo va
    //únicamente al buzón de prueba, con una nota de a quién le habría llegado en el uso normal
    if (defined('HANA_CORREO_SOLO_A') && PHP_SAPI === 'cli') {
        $antes = array();
        foreach (array_merge($mail->getToAddresses(), $mail->getCcAddresses(), $mail->getBccAddresses()) as $destino) {
            if (trim((string)$destino[1]) !== '') { $antes[] = $destino[1]; } //solo el nombre, sin el correo
        }
        $mail->clearAllRecipients();
        $mail->addAddress(HANA_CORREO_SOLO_A);
        if (strpos($mail->Subject, '[PRUEBA] ') !== 0) { $mail->Subject = '[PRUEBA] ' . $mail->Subject; }
        if (count($antes) && $mail->ContentType === 'text/html') {
            $nota = '<div style="font-family:Arial,sans-serif;font-size:12px;background:#FFF8E1;border:1px solid #F0D58C;padding:8px 10px;margin:0 0 10px;">'
                  . 'Correo de prueba. En el uso normal era para: ' . htmlspecialchars(implode(', ', array_unique($antes)), ENT_QUOTES, 'UTF-8') . '</div>';
            $mail->Body = preg_match('/<body[^>]*>/i', $mail->Body) ? preg_replace('/(<body[^>]*>)/i', '$1' . $nota, $mail->Body, 1) : $nota . $mail->Body;
            $mail->AltBody = '';
        }
    }

    //Los correos de ejemplo (dominios reservados como ejemplo.test) no se envían: se quitan de los
    //destinatarios. Si no queda ninguno real, no sale nada y queda anotado en la bitácora
    $reales = array();
    foreach ($mail->getToAddresses() as $destino) { if (!hanaCorreoDeEjemplo($destino[0])) { $reales[] = $destino; } }
    if (!count($reales)) {
        $mail->ErrorInfo = 'solo había correos de ejemplo (.test): no se envió';
        hanaCorreoLog($mail, $modulo, 'sin enviar', false, $mail->ErrorInfo);
        return false;
    }
    if (count($reales) !== count($mail->getToAddresses())) {
        $mail->clearAddresses();
        foreach ($reales as $destino) { $mail->addAddress($destino[0], $destino[1]); }
    }
    $cta = hanaCorreoDatos();
    if (!$cta) {
        $motivo = 'La cuenta de correo no está escrita en Conexion/Global.php (MAIL_HOST, MAIL_USER y MAIL_PASSWORD).';
        hanaCorreoLog($mail, $modulo, 'sin cuenta', false, $motivo);
        error_log('HANA - correo (' . $modulo . ') no salió: ' . $motivo);
        return false;
    }

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
    hanaIncrustarImagenes($mail);

    //La cuenta y el servidor, tal como están en Global.php
    $verificar = !(defined('MAIL_VERIFICAR_CERTIFICADO') && MAIL_VERIFICAR_CERTIFICADO === false) && !hanaEsLocal();
    $mail->isSMTP();
    $mail->Host       = $cta['host'];
    $mail->Port       = (int)$cta['port'];
    $mail->SMTPSecure = $cta['secure'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cta['user'];
    $mail->Password   = $cta['pass'];
    $mail->Timeout    = 15; //si el servidor de correo no responde, se deja de esperar
    $mail->SMTPOptions = $verificar ? array()
        : array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
    $metodo = 'SMTP ' . (int)$cta['port'] . '/' . ($cta['secure'] !== '' ? $cta['secure'] : 'sin cifrar') . ($verificar ? '' : ' sin revisar el certificado');
    try {
        $ok = $mail->send();
    } catch (\Throwable $e) {
        $ok = false;
        $mail->ErrorInfo = $e->getMessage();
    }
    $mail->smtpClose();
    hanaCorreoLog($mail, $modulo, $metodo, $ok, $ok ? null : $mail->ErrorInfo);
    if (!$ok) { error_log('HANA - correo (' . $modulo . ') no salió por ' . $metodo . ': ' . $mail->ErrorInfo); }
    return $ok;
}

//¿Es un correo de ejemplo? Los dominios reservados (.test, .example, .invalid, .localhost)
//nunca entregan: se usan en los datos de prueba para que nada le llegue a nadie por error
function hanaCorreoDeEjemplo($correo)
{
    return (bool)preg_match('/\.(test|example|invalid|localhost)$/i', trim((string)$correo));
}

//Anota un envío en la bitácora del sistema (si no se puede anotar, el envío sigue igual)
function hanaCorreoLog($mail, $modulo, $metodo, $ok, $error)
{
    try {
        require_once __DIR__ . '/../Modelo/BitacoraModelo.php';
        $para = implode(', ', array_map(function ($a) { return $a[0]; }, $mail->getToAddresses()));
        Bitacora::correo($modulo, $para, (string)$mail->Subject, (string)$metodo, $ok, $error);
    } catch (\Throwable $e) {
        error_log('HANA - no se pudo anotar el envío de correo: ' . $e->getMessage());
    }
}
