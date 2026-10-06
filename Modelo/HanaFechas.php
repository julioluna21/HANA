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
    private static $habilitados = array(); //días habilitados ya consultados, por persona

    //-----------------------------------------------------------------------
    // Qué días se pueden registrar o corregir en el reporte diario.
    // La regla es una sola: HOY. Lo usan Hoy en qué estás, listas, arqueos,
    // cronograma y vehículo.
    // La excepción la pone el ADMIN TEC: en Parámetros del sistema → Días
    // habilitados le abre un día específico a una persona y después se lo
    // cierra (tabla dia_habilitado). No hay margen de días que configurar
    //-----------------------------------------------------------------------

    //El día de hoy en la hora de Colombia, como 'AAAA-MM-DD'
    public static function hoy()
    {
        $zona = date_default_timezone_get();
        date_default_timezone_set('America/Bogota');
        $h = date('Y-m-d');
        date_default_timezone_set($zona); //se deja la zona como estaba
        return $h;
    }

    //Se conserva para lo que todavía la consulta: ya no hay días de margen
    public static function margen()
    {
        return 0;
    }

    //[primer día editable, último día editable]: hoy y nada más.
    //Los días habilitados van aparte (ver habilitados y enVentana)
    public static function ventana()
    {
        $h = self::hoy();
        return array($h, $h);
    }

    //Los días que la persona tiene habilitados en este momento, de más viejo a más nuevo.
    //Sin $idColaborador se toma la persona que tiene la sesión
    public static function habilitados($idColaborador = null)
    {
        if ($idColaborador === null) { $idColaborador = isset($_SESSION['Idcolaborador']) ? $_SESSION['Idcolaborador'] : 0; }
        $id = (int)$idColaborador;
        if ($id <= 0) { return array(); }
        if (isset(self::$habilitados[$id])) { return self::$habilitados[$id]; } //ya se consultó en esta petición
        require_once __DIR__ . '/HanaDB.php';
        $dias = array();
        //Si la tabla todavía no existe (falta correr el script 15), q() devuelve false: no hay días habilitados
        $f = HanaDB::q("SELECT FECHA FROM dia_habilitado WHERE ID_COLABORADOR = ? AND ESTADO = 1 ORDER BY FECHA", 'i', array($id));
        if (is_array($f)) { foreach ($f as $x) { $dias[] = $x['FECHA']; } }
        self::$habilitados[$id] = $dias;
        return $dias;
    }

    //¿La persona tiene habilitado ese día?
    public static function habilitado($fecha, $idColaborador = null)
    {
        return in_array(substr((string)$fecha, 0, 10), self::habilitados($idColaborador), true);
    }

    //¿Ese día se puede registrar o corregir? Hoy siempre; otro día, solo si
    //el administrador se lo habilitó a la persona
    public static function enVentana($fecha, $idColaborador = null)
    {
        $f = substr((string)$fecha, 0, 10);
        return $f === self::hoy() || self::habilitado($f, $idColaborador);
    }

    //Para los mensajes de error: "solo se registran arqueos de hoy (o un día que...)"
    public static function textoVentana()
    {
        return 'hoy (o un día que el administrador te haya habilitado)';
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
