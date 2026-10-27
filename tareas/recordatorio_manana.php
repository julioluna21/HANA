<?php
/*
  HANA - Recordatorio de la mañana a cada persona (por defecto, los coordinadores)
  ---------------------------------------------------------------------------
  Lo normal es que la haga tareas/programador.php (una sola línea de cron cada hora),
  con la hora, los días y los destinatarios de Reporte diario → Correos automáticos.
  Este archivo sirve para correrla a mano:
      php tareas/recordatorio_manana.php --simular    a quién le llegaría, sin enviar nada
      php tareas/recordatorio_manana.php              la envía, si no se envió hoy
      php tareas/recordatorio_manana.php --forzar     la envía aunque ya se haya enviado hoy
  Lo que hace está en tareas/libreria.php.
*/
require_once __DIR__ . '/comun.php';
tareaAMano('RECORDATORIO_MANANA');
