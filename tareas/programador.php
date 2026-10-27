<?php
/*
  HANA - Tarea programada: el programador de los correos automáticos
  ---------------------------------------------------------------------------
  Se pone UNA sola línea en cron, cada hora:
      0 * * * * /usr/bin/php /home/USUARIO/public_html/tareas/programador.php

  Cada vez que corre revisa las tareas de Reporte diario → Correos automáticos y hace
  las que tocan: encendidas, de un día marcado y desde su hora (hasta 3 horas
  después, por si cron no corrió a la hora exacta). Cada una se hace una sola vez
  al día. Así, la hora, los días y a quién le llega se cambian en HANA, sin tocar cron.

  Para ver qué haría ahora, sin enviar nada:
      php tareas/programador.php --simular
*/
require_once __DIR__ . '/comun.php';

$simular = tareaSimular();
$ahora = time();
$tocaron = 0; $fallos = 0;
foreach (array_keys(Tareas::DEFINICIONES) as $clave) {
    if (!Tareas::leToca($clave, $ahora)) { continue; }
    $tocaron++;
    $resultado = tareaEjecutar($clave, $simular, 'programador');
    $fallos += $resultado['fallos'];
}
if (!$tocaron) { tareaLinea('Programador · ' . date('Y-m-d H:i') . ': a esta hora no toca ninguna tarea.'); }
exit($fallos ? 1 : 0);
