<?php
/*
  HANA — Diagnóstico del correo
  ---------------------------------------------------------------------------
  Abre en el navegador:  http://localhost/DEV/Control/DiagnosticoCorreo.php
  (cambia DEV por el nombre de tu carpeta). Revisa todo lo que necesita el
  correo y envía una prueba mostrando la conversación con el servidor.

  Seguridad: sin iniciar sesión solo funciona desde el mismo computador
  (localhost). Desde otro equipo pide sesión con el permiso 24M (ADMIN TEC).
*/
session_start();
date_default_timezone_set('America/Bogota');
header('Content-Type: text/html; charset=utf-8');

$local = in_array(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', array('127.0.0.1', '::1'), true);
$admin = isset($_SESSION['Modulos']) && in_array('24M', explode(',', $_SESSION['Modulos']), true);
if (!$local && !$admin) {
    http_response_code(403);
    echo '<p style="font-family:Arial">Esta página solo se abre desde el mismo computador del servidor, o con sesión de ADMIN TEC.</p>';
    exit;
}

require_once __DIR__ . '/CorreoConfig.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/SMTP.php';

function e($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
$filas = array(); //revisiones: [bien?, qué, detalle, qué hacer si está mal]
$agregar = function ($ok, $que, $detalle, $siMal = '') use (&$filas) { $filas[] = array($ok, $que, $detalle, $siMal); };

//1. PHP
$agregar(version_compare(PHP_VERSION, '7.4', '>='), 'Versión de PHP', PHP_VERSION);
$agregar(extension_loaded('openssl'), 'Extensión openssl', extension_loaded('openssl') ? 'Activa (' . OPENSSL_VERSION_TEXT . ')' : 'NO está activa',
         'En XAMPP: abre php.ini (Panel de XAMPP → Apache → Config → PHP (php.ini)), busca ;extension=openssl, quítale el ; del comienzo, guarda y reinicia Apache.');
$ca = ini_get('openssl.cafile');
$agregar(true, 'Certificados raíz (openssl.cafile)', $ca ? $ca . (is_file($ca) ? '' : ' (el archivo no existe)') : 'Sin configurar: HANA igual intenta sin verificar el certificado en este computador');

//2. La cuenta que se usa
$c = hanaCorreoDatos();
$usaOriginal = $c['pass'] === HANA_CORREO_PASSWORD;
$agregar(true, 'Cuenta de correo', $c['user'] . ' en ' . $c['host'] . ':' . $c['port'] . ' (' . $c['secure'] . ') — contraseña de ' . strlen($c['pass']) . ' caracteres, '
         . ($usaOriginal ? 'la del código (CorreoConfig.php)' : 'la de Conexion/Global.php'));

//3. Red: nombre del servidor y puertos
$ip = gethostbyname($c['host']);
$agregar($ip !== $c['host'], 'El nombre ' . $c['host'] . ' se encuentra (DNS)', $ip !== $c['host'] ? 'Sí: ' . $ip : 'No se encontró',
         'Este computador no resuelve el nombre del servidor de correo: revisa la conexión a internet.');
$algunPuerto = false;
//El puerto configurado primero, y los de correo de siempre
foreach (array_unique(array((int)$c['port'], 465, 587, 25)) as $pt) {
    $t0 = microtime(true);
    $s = @fsockopen($c['host'], $pt, $en, $es, 6);
    if ($s) { fclose($s); $algunPuerto = true; }
    $agregar((bool)$s, 'Conexión a ' . $c['host'] . ':' . $pt, $s ? 'Abre (' . round((microtime(true) - $t0) * 1000) . ' ms)' : 'NO abre: ' . ($es ?: 'sin respuesta'),
             $pt === 25 ? 'El 25 casi siempre está bloqueado; no importa si el 465 o el 587 abren.' :
             'Si ni el 465 ni el 587 abren, algo bloquea la salida de correo: el antivirus (su "escudo de correo"), el firewall de Windows o la red de la empresa. Prueba desde otra red (por ejemplo, datos del celular) para confirmarlo.');
}

//4. La prueba de envío (si se pidió)
$para = isset($_POST['para']) ? trim($_POST['para']) : '';
$intentos = array(); $enviado = null;
if ($para !== '') {
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) { $enviado = false; $intentos[] = array('metodo' => '—', 'ok' => false, 'error' => 'El correo escrito no es válido', 'conversacion' => ''); }
    else {
        $mail = new \PHPMailer\PHPMailer\PHPMailer();
        hanaConfigurarSmtp($mail);
        $mail->FromName = 'HANA - Diagnóstico';
        $mail->addAddress($para);
        $mail->Subject = 'Prueba de correo de HANA (' . date('H:i') . ')';
        $mail->Body = hanaCorreoHtml('', 'Prueba de correo de HANA', '<p style="margin:0;">Si te llegó este correo, el envío desde HANA funciona.</p>');
        $enviado = hanaEnviar($mail, 'DIAGNOSTICO', $intentos);
    }
}
?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diagnóstico del correo · HANA</title>
<style>
 body{font-family:Arial,sans-serif;background:#F7F4F1;color:#2B2525;margin:0;padding:24px}
 .caja{max-width:900px;margin:0 auto;background:#fff;border:1px solid #ECE6E1;border-radius:12px;padding:20px 24px}
 h1{color:#6E1A1E;font-size:22px;margin:0 0 4px} h2{font-size:16px;margin:22px 0 8px;color:#6E1A1E}
 table{width:100%;border-collapse:collapse;font-size:14px} td{padding:7px 8px;border-bottom:1px solid #F0EAE5;vertical-align:top}
 .ok{color:#149C83;font-weight:bold} .mal{color:#6E1A1E;font-weight:bold} .ayuda{color:#7A716E;font-size:12.5px}
 input[type=email]{padding:9px 12px;border:1px solid #E2DAD4;border-radius:8px;width:320px;font-size:14px}
 button{background:#149C83;color:#fff;border:0;border-radius:8px;padding:10px 16px;font-weight:bold;cursor:pointer}
 .intento{border-left:4px solid #ccc;background:#FAFBFB;padding:8px 12px;margin:8px 0;border-radius:0 8px 8px 0}
 .intento.ok{border-left-color:#149C83} .intento.mal{border-left-color:#6E1A1E}
 pre{white-space:pre-wrap;font-size:11.5px;max-height:320px;overflow:auto;background:#2B2525;color:#F7F4F1;padding:10px;border-radius:8px}
 .res{padding:12px 14px;border-radius:10px;margin:10px 0;font-weight:bold}
 .res.ok{background:#E6F5F1} .res.mal{background:#F6ECEC}
</style></head><body><div class="caja">
<h1>Diagnóstico del correo</h1>
<p class="ayuda"><?php echo e(date('d/m/Y H:i')); ?> · Mándale una captura de toda esta página a quien te ayuda con HANA.</p>

<h2>1. Revisión del computador y de la cuenta</h2>
<table>
<?php foreach ($filas as $f) { ?>
  <tr><td style="width:26px;" class="<?php echo $f[0] ? 'ok' : 'mal'; ?>"><?php echo $f[0] ? '✔' : '✘'; ?></td>
      <td><strong><?php echo e($f[1]); ?></strong><br><?php echo e($f[2]); ?>
      <?php if (!$f[0] && $f[3] !== '') { ?><br><span class="ayuda">Qué hacer: <?php echo e($f[3]); ?></span><?php } ?></td></tr>
<?php } ?>
</table>
<?php if (!$algunPuerto) { ?><div class="res mal">Este computador no puede conectarse al servidor de correo por ningún puerto: el problema es de red o antivirus, no de HANA.</div><?php } ?>

<h2>2. Enviar un correo de prueba</h2>
<form method="post" onsubmit="var b=this.querySelector('button'); b.disabled=true; b.textContent='Enviando...';"><input type="email" name="para" placeholder="tu.correo@regency.com.co" value="<?php echo e($para); ?>" required> <button type="submit">Enviar prueba</button></form>
<?php if ($enviado !== null) { ?>
  <div class="res <?php echo $enviado ? 'ok' : 'mal'; ?>"><?php echo $enviado ? 'El servidor de correo aceptó el mensaje. Revisa la bandeja de ' . e($para) . ' y la carpeta de spam (puede tardar unos minutos).'
                                                                       : 'No se pudo enviar por ninguna vía. Abajo está la respuesta de cada intento.'; ?></div>
  <?php foreach ($intentos as $i => $x) { ?>
    <div class="intento <?php echo $x['ok'] ? 'ok' : 'mal'; ?>"><strong><?php echo ($i + 1) . '. ' . e($x['metodo']); ?></strong> — <?php echo $x['ok'] ? 'funcionó' : e($x['error'] ?: 'falló'); ?>
      <?php if ($x['conversacion'] !== '') { ?><details><summary>Ver la conversación con el servidor</summary><pre><?php echo e($x['conversacion']); ?></pre></details><?php } ?></div>
  <?php } ?>
<?php } ?>
</div>
<script>
  //Recargar la página (F5) no vuelve a enviar el correo de prueba
  if (window.history.replaceState) { window.history.replaceState(null, '', window.location.href); }
</script>
</body></html>
