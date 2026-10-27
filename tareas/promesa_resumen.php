<?php
/*
  HANA - Correo con el resultado de la promesa de valor de ayer
  ---------------------------------------------------------------------------
  Lo normal es que la haga tareas/programador.php (una sola línea de cron cada hora),
  con la hora, los días y los destinatarios de Reporte diario → Correos automáticos.
  Este archivo sirve para correrla a mano:
      php tareas/promesa_resumen.php --simular    a quién le llegaría, sin enviar nada
      php tareas/promesa_resumen.php              la envía, si no se envió hoy
      php tareas/promesa_resumen.php --forzar     la envía aunque ya se haya enviado hoy
  Lo que hace está en tareas/libreria.php.
*/
require_once __DIR__ . '/comun.php';
tareaAMano('PROMESA_RESUMEN');
