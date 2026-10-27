<?php
/*
  HANA - Lo que comparten los archivos de la carpeta tareas/ que se corren con cron
  ---------------------------------------------------------------------------
  Se corren por línea de comandos; si alguien entra por la dirección, responde 403
  y no hace nada (además, tareas/.htaccess bloquea la carpeta). Lo que hace cada
  tarea está en tareas/libreria.php; lo que se puede configurar de cada una (hora,
  días, a quién le llega...), en Reporte diario → Correos automáticos.

  Lo que escriben (cuántos correos salieron y si alguno falló) sale en la pantalla
  cuando se prueban a mano, o en el correo o archivo que cron tenga configurado.
  Cada correo queda en bitacora_sistema (TIPO 'CORREO') y cada vez que corre una
  tarea, también (TIPO 'TAREA').
*/
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso no permitido.";
    exit;
}
//Algunos archivos del sistema cargan otros con rutas relativas a la carpeta Control
chdir(__DIR__ . '/../Control');
require_once __DIR__ . '/libreria.php';

//¿Se pidió --simular? Entonces la tarea solo dice a quién le enviaría, sin enviar nada
function tareaSimular()
{
    global $argv;
    return in_array('--simular', (array)$argv, true);
}

//Corre una tarea a mano (los archivos de cada tarea). Si ya corrió hoy, no la repite,
//salvo con --forzar: así no llega dos veces si también está el programador en cron
function tareaAMano($clave)
{
    global $argv;
    $simular = tareaSimular();
    if (!$simular && !in_array('--forzar', (array)$argv, true) && Tareas::corrioHoy($clave, date('Y-m-d'))) {
        tareaLinea(Tareas::DEFINICIONES[$clave]['nombre'] . ': ya se envió hoy. Para enviarla otra vez: --forzar');
        exit(0);
    }
    $resultado = tareaEjecutar($clave, $simular, 'a mano (línea de comandos)');
    exit($resultado['fallos'] ? 1 : 0);
}
