<?php
/*
  HANA - Probar todos los correos automáticos de una vez
  ---------------------------------------------------------------------------
  Envía ya, aunque no sea su hora ni su día, cada correo automático de
  Reporte diario → Correos automáticos que esté encendido:
    - un correo de prueba de la cuenta (para saber si el correo sale);
    - el recordatorio de la mañana y el de la tarde;
    - el correo con el balance de la promesa de valor (el de ayer);
    - los resúmenes diario y semanal de las listas de chequeo.
  Todos llegan SOLO al buzón de prueba (aprendiz.peajes2@regency.com.co), con el asunto
  «[PRUEBA] …» y una nota arriba que dice a quién le habría llegado en el uso normal.
  A nadie más le llega nada. Sirve para probar, no para el uso diario (de eso se
  encarga tareas/programador.php).

  Se corre en el servidor, por línea de comandos (en XAMPP, desde la carpeta de HANA):
      C:\xampp\php\php.exe DEV\tareas\probar_correos.php --simular    dice qué saldría, sin enviar
      C:\xampp\php\php.exe DEV\tareas\probar_correos.php              los envía, solo al buzón de prueba
      C:\xampp\php\php.exe DEV\tareas\probar_correos.php --a-todos    los envía a quienes estén configurados

  Los botones de los correos («Entrar a HANA», «Ver la promesa de valor») abren HANA en
  este mismo equipo: http://localhost/HANA/DEV/... La carpeta y el puerto se sacan solos
  de la configuración de XAMPP (apache/conf/httpd.conf). Si hace falta otra dirección:
      C:\xampp\php\php.exe DEV\tareas\probar_correos.php --url=http://localhost:8080/HANA/DEV
  Cada envío queda en la bitácora del sistema.
*/
require_once __DIR__ . '/comun.php';

$simular = tareaSimular();
$prueba = 'aprendiz.peajes2@regency.com.co'; //el buzón de prueba
$aTodos = in_array('--a-todos', (array)$argv, true);
if (!$aTodos) { define('HANA_CORREO_SOLO_A', $prueba); }
tareaLinea('HANA - prueba de los correos automáticos · ' . date('Y-m-d H:i') . ($simular ? ' (simulación: no se envía nada)' : '')
           . ($aTodos ? '' : ' · todo va solo a ' . $prueba));

//Los enlaces de los correos: a HANA en este equipo (localhost), porque todavía no está publicado
$urlPedida = '';
foreach ((array)$argv as $arg) { if (strpos($arg, '--url=') === 0) { $urlPedida = rtrim(substr($arg, 6), '/'); } }
if (defined('HANA_URL')) {
    tareaLinea('Los enlaces de los correos abren en ' . rtrim(HANA_URL, '/') . ' (HANA_URL de Conexion/Global.php).');
} else {
    define('HANA_URL', $urlPedida !== '' ? $urlPedida : pcUrlLocal());
    tareaLinea('Los enlaces de los correos abren en ' . HANA_URL . '/Vista/login.php');
}

//La dirección de HANA en este equipo según XAMPP: http://localhost[:puerto]/<carpeta desde htdocs>
function pcUrlLocal()
{
    $hana = str_replace('\\', '/', (string)realpath(__DIR__ . '/..')); //por ejemplo C:/xampp/htdocs/HANA/DEV
    $puerto = 80;
    $raiz = '';
    $conf = dirname(PHP_BINARY) . '/../apache/conf/httpd.conf'; //C:\xampp\php\php.exe -> C:\xampp\apache\conf\httpd.conf
    if (is_file($conf)) {
        $texto = (string)file_get_contents($conf);
        if (preg_match('/^\s*Listen\s+(?:[\d.]+:)?(\d+)/mi', $texto, $m)) { $puerto = (int)$m[1]; }
        if (preg_match('/^\s*DocumentRoot\s+"?([^"\r\n]+)"?/mi', $texto, $m)) { $raiz = rtrim(str_replace('\\', '/', trim($m[1])), '/'); }
    }
    if ($raiz !== '' && stripos($hana, $raiz . '/') === 0) {
        $carpeta = substr($hana, strlen($raiz));
    } elseif (($i = stripos($hana, '/htdocs/')) !== false) {
        $carpeta = substr($hana, $i + 7);
    } else {
        $carpeta = '/HANA/DEV';
    }
    return 'http://localhost' . ($puerto !== 80 ? ':' . $puerto : '') . $carpeta;
}

//1. Que la cuenta de correo sirva
if (!$simular) {
    if (!tareaHayCorreo()) { exit(1); }
    $mail = new \PHPMailer\PHPMailer\PHPMailer();
    hanaConfigurarSmtp($mail);
    $mail->FromName = 'HANA';
    $mail->addAddress($prueba);
    $mail->Subject = 'HANA: correo de prueba de la cuenta';
    $mail->Body = hanaCorreoHtml('', 'Prueba', '<p style="margin:0;">Si te llegó este correo, la cuenta de correo de HANA está bien configurada. '
                                 . 'A continuación llegan los correos automáticos, uno por cada aviso encendido.</p>');
    tareaLinea(hanaEnviar($mail, 'PRUEBA') ? 'Correo de prueba de la cuenta: enviado a ' . $prueba . '.'
                                           : 'Correo de prueba de la cuenta: NO SALIÓ (' . trim(preg_replace('/\s+/', ' ', $mail->ErrorInfo)) . '). Revisa MAIL_ en Conexion/Global.php.');
}

//2. Cada correo automático encendido
$fallos = 0;
foreach (Tareas::DEFINICIONES as $clave => $definicion) {
    if ($definicion['entrega'] !== 'CORREO') { continue; }
    tareaLinea('');
    if (!Tareas::config($clave)['activo']) { tareaLinea($definicion['nombre'] . ': está apagado en Correos automáticos. No se envía.'); continue; }
    $resultado = tareaEjecutar($clave, $simular, 'prueba de todos los correos');
    $fallos += $resultado['fallos'];
}
exit($fallos ? 1 : 0);
