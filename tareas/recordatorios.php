<?php
/*
  HANA - Ya no se usa. Los correos automáticos los hace tareas/programador.php: en cron
  basta una línea cada hora, y la hora de cada correo se elige en Reporte diario →
  Correos automáticos.
*/
require_once __DIR__ . '/comun.php';
tareaLinea('tareas/recordatorios.php ya no se usa: programe tareas/programador.php cada hora (0 * * * *).');
exit(1);
