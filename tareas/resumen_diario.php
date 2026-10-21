<?php
/*
  HANA - Tarea programada: resumen diario de las listas de chequeo
  ---------------------------------------------------------------------------
  Envía, a cada aviso de "Correos de listas" que tenga la frecuencia
  "resumen diario", un solo correo con las listas de chequeo que se llenaron AYER.
  Si en ese tiempo nadie llenó esa lista, no se envía correo vacío.
  Quién recibe cada resumen se sigue configurando en Reporte diario → Correos de listas.

  Cron, por ejemplo todos los días a las 6:00 a. m. (resume lo de AYER):
      0 6 * * * /usr/bin/php /home/USUARIO/RUTA_DE_HANA/tareas/resumen_diario.php
*/
require_once __DIR__ . '/comun.php';
tareaRevisarCorreo();
require_once __DIR__ . '/../Control/EnvioNotificacionesControl.php';

tareaLinea('Resumen diario de las listas de chequeo');
$enviados = hanaEnviarResumen('diario');
$fallidos = isset($GLOBALS['hanaResumenFallidos']) ? $GLOBALS['hanaResumenFallidos'] : array();
foreach ($fallidos as $fallido) { tareaLinea('  NO SALIÓ: ' . $fallido); }
tareaLinea('Listo: ' . $enviados . ' correo(s) enviado(s)' . ($fallidos ? ', ' . count($fallidos) . ' con error.' : '.'));
exit($fallidos ? 1 : 0); //cron lo reporta como error si alguno no salió
