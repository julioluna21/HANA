<?php
/*
  HANA - Lo que comparten las tareas programadas (carpeta tareas/)
  ---------------------------------------------------------------------------
  Las tareas las corre el servidor con cron, por línea de comandos:

      /usr/bin/php /home/USUARIO/RUTA_DE_HANA/tareas/resumen_diario.php

  No se pueden abrir desde el navegador: si alguien entra por la dirección,
  responde 403 y no hace nada (además, tareas/.htaccess bloquea la carpeta).
  Por eso no llevan clave en la dirección ni dependen de una sesión.

  Lo que escriben (cuántos correos salieron y si alguno falló) sale
  en la pantalla cuando se prueban a mano, o en el correo / archivo que cron
  tenga configurado. Cada correo también queda en bitacora_sistema (TIPO 'CORREO').
*/
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso no permitido.";
    exit;
}

date_default_timezone_set('America/Bogota'); //"hoy" y "ayer" son los de Colombia
//Algunos archivos del sistema cargan otros con rutas relativas a la carpeta Control
chdir(__DIR__ . '/../Control');

require_once __DIR__ . '/../Conexion/Global.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/../Control/CorreoHelper.php';

//Escribe una línea con la fecha y la hora, para que el registro de cron se entienda
function tareaLinea($texto)
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $texto . PHP_EOL;
}

//Si la cuenta de correo no está en Global.php, la tarea avisa y termina con error (cron lo reporta)
function tareaRevisarCorreo()
{
    if (!hanaCorreoDatos()) {
        tareaLinea('No se envía nada: falta la cuenta de correo en Conexion/Global.php (MAIL_HOST, MAIL_USER y MAIL_PASSWORD).');
        exit(1);
    }
}
