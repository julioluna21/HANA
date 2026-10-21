<?php
/*
  HANA - Tarea programada: resumen semanal de las listas de chequeo
  ---------------------------------------------------------------------------
  Envía, a cada aviso de "Correos de listas" que tenga la frecuencia
  "resumen semanal", un solo correo con las listas de chequeo de los últimos 7 días.
  Si en ese tiempo nadie llenó esa lista, no se envía correo vacío.
  Quién recibe cada resumen se sigue configurando en Reporte diario → Correos de listas.

  Cron, por ejemplo los lunes a las 6:00 a. m. (resume los últimos 7 días):
      0 6 * * 1 /usr/bin/php /home/USUARIO/RUTA_DE_HANA/tareas/resumen_semanal.php
*/
require_once __DIR__ . '/comun.php';
tareaRevisarCorreo();
require_once __DIR__ . '/../Control/EnvioNotificacionesControl.php';

tareaLinea('Resumen semanal de las listas de chequeo');
$enviados = hanaEnviarResumen('semanal');
$fallidos = isset($GLOBALS['hanaResumenFallidos']) ? $GLOBALS['hanaResumenFallidos'] : array();
foreach ($fallidos as $fallido) { tareaLinea('  NO SALIÓ: ' . $fallido); }
tareaLinea('Listo: ' . $enviados . ' correo(s) enviado(s)' . ($fallidos ? ', ' . count($fallidos) . ' con error.' : '.'));
exit($fallidos ? 1 : 0); //cron lo reporta como error si alguno no salió
