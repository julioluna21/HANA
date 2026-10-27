<?php
/*
  HANA - Recordatorio de la tarde: listas de chequeo y arqueos
  ---------------------------------------------------------------------------
  Lo normal es que la haga tareas/programador.php (una sola línea de cron cada hora),
  con la hora, los días y los destinatarios de Reporte diario → Correos automáticos.
  Este archivo sirve para correrla a mano:
      php tareas/recordatorio_tarde.php --simular    a quién le llegaría, sin enviar nada
      php tareas/recordatorio_tarde.php              la envía, si no se envió hoy
      php tareas/recordatorio_tarde.php --forzar     la envía aunque ya se haya enviado hoy
  Lo que hace está en tareas/libreria.php.
*/
require_once __DIR__ . '/comun.php';
tareaAMano('RECORDATORIO_TARDE');
