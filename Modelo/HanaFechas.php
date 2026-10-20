<?php
/*
  HANA - Días hábiles y festivos de Colombia
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
        $hoy = date('Y-m-d');
        date_default_timezone_set($zona); //se deja la zona como estaba
        return $hoy;
    }

    //Días de margen para registrar fuera de fecha: siempre 0 (algunas partes la consultan)
    public static function margen()
    {
        return 0;
    }

    //[primer día editable, último día editable]: hoy y nada más.
    //Los días habilitados van aparte (ver habilitados y enVentana)
    public static function ventana()
    {
        $hoy = self::hoy();
        return array($hoy, $hoy);
    }

    //Los días que la persona tiene habilitados en este momento, de más viejo a más nuevo.
    //El controlador dice de quién (hanaEnVentana en AccesoHelper usa la persona de la sesión)
    public static function habilitados($idColaborador = 0)
    {
        $id = (int)$idColaborador;
        if ($id <= 0) { return array(); }
        if (isset(self::$habilitados[$id])) { return self::$habilitados[$id]; } //ya se consultó en esta petición
        require_once __DIR__ . '/HanaDB.php';
        $dias = array();
        //Si la tabla dia_habilitado no existe (base sin actualizar), q() devuelve false: no hay días habilitados
        $filas = HanaDB::q("SELECT FECHA FROM dia_habilitado WHERE ID_COLABORADOR = ? AND ESTADO = 1 ORDER BY FECHA", 'i', array($id));
        if (is_array($filas)) { foreach ($filas as $fila) { $dias[] = $fila['FECHA']; } }
        self::$habilitados[$id] = $dias;
        return $dias;
    }

    //¿La persona tiene habilitado ese día?
    public static function habilitado($fecha, $idColaborador = 0)
    {
        return in_array(substr((string)$fecha, 0, 10), self::habilitados($idColaborador), true);
    }

    //¿Ese día se puede registrar o corregir? Hoy siempre; otro día, solo si
    //el administrador se lo habilitó a la persona
    public static function enVentana($fecha, $idColaborador = 0)
    {
        $dia = substr((string)$fecha, 0, 10);
        return $dia === self::hoy() || self::habilitado($dia, $idColaborador);
    }

    //Para los mensajes de error: "solo se registran arqueos de hoy (o un día que...)"
    public static function textoVentana()
    {
        return 'hoy (o un día que el administrador te haya habilitado)';
    }

    //Domingo de Pascua (algoritmo gregoriano anónimo, de Meeus/Jones/Butcher), como 'AAAA-MM-DD'.
    //Las letras $a a $m son los pasos intermedios del algoritmo con los mismos nombres con que
    //se publica, para poder compararlo con la fórmula original
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

    //Los festivos de Colombia de un año: ['AAAA-MM-DD' => true]
    //  Fijos (no se mueven): 1 ene, 1 may, 20 jul, 7 ago, 8 dic y 25 dic.
    //  Ley Emiliani (Ley 51 de 1983), pasan al lunes siguiente: Reyes (6 ene), San José (19 mar),
    //  San Pedro y San Pablo (29 jun), Asunción (15 ago), Día de la Raza (12 oct), Todos los Santos (1 nov),
    //  Independencia de Cartagena (11 nov) y, desde 2026, Nuestra Señora del Rosario de Chiquinquirá
    //  (9 jul, Ley 2578 de 2026).
    //  Según la Pascua: Jueves y Viernes Santo, Ascensión, Corpus Christi y Sagrado Corazón (al lunes).
    //Comprobado contra la librería de referencia de festivos (holidays, Colombia), años 2020 a 2035: mismas fechas.
    //Si una ley crea o quita un festivo, se agrega o se quita aquí
    public static function festivos($anio)
    {
        $anio = (int)$anio;
        if (isset(self::$cache[$anio])) { return self::$cache[$anio]; }
        $festivos = array();
        foreach (array('01-01', '05-01', '07-20', '08-07', '12-08', '12-25') as $mesDia) { $festivos["$anio-$mesDia"] = true; }

        //Al lunes siguiente, si no cae en lunes
        $emiliani = array('01-06', '03-19', '06-29', '08-15', '10-12', '11-01', '11-11');
        if ($anio >= 2026) { $emiliani[] = '07-09'; } //Nuestra Señora del Rosario de Chiquinquirá (Ley 2578 de 2026)
        foreach ($emiliani as $mesDia) {
            $momento = strtotime("$anio-$mesDia");
            $diaSemana = (int)date('N', $momento); //1 = lunes
            if ($diaSemana !== 1) { $momento = strtotime('next monday', $momento); }
            $festivos[date('Y-m-d', $momento)] = true;
        }

        $pascua = strtotime(self::pascua($anio));
        foreach (array(-3, -2, 43, 64, 71) as $dias) { $festivos[date('Y-m-d', strtotime("$dias days", $pascua))] = true; }

        self::$cache[$anio] = $festivos;
        return $festivos;
    }

    //¿Ese día es festivo en Colombia?
    public static function esFestivo($fecha)
    {
        $momento = strtotime($fecha);
        $festivos = self::festivos((int)date('Y', $momento));
        return isset($festivos[date('Y-m-d', $momento)]);
    }

    //¿Es día hábil? No lo es si cae sábado, domingo o festivo
    public static function esHabil($fecha)
    {
        $momento = strtotime($fecha);
        if ((int)date('N', $momento) >= 6) { return false; } //sábado o domingo
        $festivos = self::festivos((int)date('Y', $momento));
        return !isset($festivos[date('Y-m-d', $momento)]);
    }

    //La fecha que resulta de sumar $cuantos días hábiles (como WORKDAY de Excel,
    //pero sin contar los festivos de Colombia)
    public static function sumarHabiles($fecha, $cuantos)
    {
        $momento = strtotime($fecha);
        $sumados = 0;
        while ($sumados < $cuantos) {
            $momento = strtotime('+1 day', $momento);
            if (self::esHabil(date('Y-m-d', $momento))) { $sumados++; }
        }
        return date('Y-m-d', $momento);
    }
}
