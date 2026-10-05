<?php
/*
  HANA — Días hábiles y festivos de Colombia
  ---------------------------------------------------------------------------
  No depende de la extensión "calendar" de PHP (easter_date), que no siempre
  está activa en los servidores: la Semana Santa se calcula aquí mismo.

  Festivos (Ley 51 de 1983, "Ley Emiliani"):
    - Fijos: 1 ene, 1 may, 20 jul, 7 ago, 8 dic, 25 dic
    - Se pasan al lunes siguiente: 6 ene, 19 mar, 29 jun, 15 ago, 12 oct,
      1 nov, 11 nov
    - Según la Pascua: jueves y viernes santo; Ascensión (+43 días),
      Corpus Christi (+64) y Sagrado Corazón (+71), ya en lunes
*/

class HanaFechas
{
    private static $cache = array();

    //-----------------------------------------------------------------------
    // Margen para registrar y corregir en el reporte diario: por defecto un
    // día atrás y uno adelante (ayer, hoy y mañana). Lo usan Hoy en qué
    // estás, listas, arqueos, cronograma, vehículo, vacantes y comunicaciones.
    // Lo cambia el administrador en Parámetros del sistema
    // (REPORTE_MARGEN_DIAS), sin tocar el código
    //-----------------------------------------------------------------------
    public static function margen()
    {
        require_once __DIR__ . '/HanaConfig.php';
        return max(0, min(7, HanaConfig::num('REPORTE_MARGEN_DIAS', 1)));
    }

    //[primer día editable, último día editable], en la hora de Colombia
    public static function ventana()
    {
        $zona = date_default_timezone_get();
        date_default_timezone_set('America/Bogota');
        $m = self::margen();
        $v = array(date('Y-m-d', strtotime('-' . $m . ' days')),
                   date('Y-m-d', strtotime('+' . $m . ' days')));
        date_default_timezone_set($zona);
        return $v;
    }

    public static function enVentana($fecha)
    {
        $v = self::ventana();
        $f = substr((string)$fecha, 0, 10);
        return $f >= $v[0] && $f <= $v[1];
    }

    //"ayer, hoy y mañana": para los mensajes de error
    public static function textoVentana()
    {
        $m = self::margen();
        if ($m === 0) { return 'hoy'; }
        return $m === 1 ? 'ayer, hoy y mañana' : 'desde ' . $m . ' días atrás hasta ' . $m . ' días adelante';
    }

    //Domingo de Pascua (algoritmo gregoriano anónimo), como 'AAAA-MM-DD'
    public static function pascua($anio)
    {
        $a = $anio % 19; $b = intdiv($anio, 100); $c = $anio % 100;
        $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }

    //Los festivos de un año: ['AAAA-MM-DD' => true]
    public static function festivos($anio)
    {
        $anio = (int)$anio;
        if (isset(self::$cache[$anio])) { return self::$cache[$anio]; }
        $f = array();
        foreach (array('01-01', '05-01', '07-20', '08-07', '12-08', '12-25') as $md) { $f["$anio-$md"] = true; }

        //Al lunes siguiente, si no cae en lunes
        foreach (array('01-06', '03-19', '06-29', '08-15', '10-12', '11-01', '11-11') as $md) {
            $t = strtotime("$anio-$md");
            $dow = (int)date('N', $t); //1 = lunes
            if ($dow !== 1) { $t = strtotime('next monday', $t); }
            $f[date('Y-m-d', $t)] = true;
        }

        $p = strtotime(self::pascua($anio));
        foreach (array(-3, -2, 43, 64, 71) as $dias) { $f[date('Y-m-d', strtotime("$dias days", $p))] = true; }

        self::$cache[$anio] = $f;
        return $f;
    }

    public static function esHabil($fecha)
    {
        $t = strtotime($fecha);
        if ((int)date('N', $t) >= 6) { return false; } //sábado o domingo
        $f = self::festivos((int)date('Y', $t));
        return !isset($f[date('Y-m-d', $t)]);
    }

    //La fecha que resulta de sumar $n días hábiles (como WORKDAY de Excel,
    //pero sin contar los festivos de Colombia)
    public static function sumarHabiles($fecha, $n)
    {
        $t = strtotime($fecha);
        $sumados = 0;
        while ($sumados < $n) {
            $t = strtotime('+1 day', $t);
            if (self::esHabil(date('Y-m-d', $t))) { $sumados++; }
        }
        return date('Y-m-d', $t);
    }
}
