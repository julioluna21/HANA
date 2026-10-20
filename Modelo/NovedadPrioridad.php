<?php
/*
  HANA - Prioridades de las novedades
  ---------------------------------------------------------------------------
  Las tres prioridades viven aquí, en el código, y la novedad guarda el nombre
  (novedades_hallazgos.PRIORIDAD = ALTO, MEDIO o BAJO). Los días para atender
  cada una se cambian en Configuración → Parámetros del sistema (NOV_DIAS_ALTO,
  NOV_DIAS_MEDIO y NOV_DIAS_BAJO).

  La fecha límite se calcula una sola vez, al registrar la novedad: si después
  cambian los días, las novedades que ya existen conservan su fecha.
*/
require_once __DIR__ . '/HanaConfig.php';

class NovedadPrioridad
{
    //En el orden en que salen en el formulario, de la más urgente a la menos urgente
    const LISTA = array('ALTO', 'MEDIO', 'BAJO');

    //¿Es una de las tres?
    public static function valida($prioridad)
    {
        return in_array((string)$prioridad, self::LISTA, true);
    }

    //Días para atender una novedad de esa prioridad (los de Parámetros del sistema)
    public static function dias($prioridad)
    {
        $clave = 'NOV_DIAS_' . $prioridad;
        if (!self::valida($prioridad) || !isset(HanaConfig::PARAMETROS[$clave])) { return 0; }
        return HanaConfig::num($clave, HanaConfig::PARAMETROS[$clave][6]);
    }
}
