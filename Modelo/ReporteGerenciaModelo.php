<?php
/*
  HANA - Reporte a la Gerencia (Informe de gestión diaria de la Dirección de Operaciones)
  ---------------------------------------------------------------------------
  Lo arma la dirección cada día (también sábados, domingos y festivos), o para
  un rango de días, con lo que registran los coordinadores en el Reporte diario.
  Sigue la estructura del informe que se envía a la Gerencia:

    Encabezado   fecha, título, destinatario (trato, nombre y cargo), «Cordial
                 saludo:» y un párrafo de presentación
    1. RESULTADOS DE LA OPERACIÓN
                 Cumplimiento de la promesa de valor del día anterior (o del último
                 día que tenga lo ejecutado registrado; en un periodo, corrido un
                 día) de todos los centros de operación (peajes y básculas), el de cada proyecto (línea
                 «Por proyecto: …», de donde sale la gráfica) y el detalle de los
                 que no cumplieron con su justificación (pv_dia)
    2. GESTIONES PRIORITARIAS Y COMPROMISOS CON CLIENTES
                 Un apartado «PROYECTO | Asunto» por cada tema abierto con el cliente
                 (con todo lo que se llenó y en qué va) y por cada oficio o
                 comunicación en gestión
    3. AVANCES DE GESTIÓN POR PROYECTO
                 Por proyecto, lo que se movió el día del informe y el anterior: los
                 temas internos (con Regency) abiertos, con sus actualizaciones; las
                 actualizaciones de los temas con el cliente; los oficios atendidos;
                 las vacantes cubiertas o canceladas; y las novedades atendidas y
                 reportadas en sus peajes
    4. GESTIÓN TRANSVERSAL | TALENTO HUMANO Y SST
                 Los temas de personal (clase PERSONAL) y la tabla de vacantes
                 abiertas por centro de operación (con sus cargos)
    5. COMPROMISOS Y SEGUIMIENTO
                 Tabla de gestión y fecha objetivo: lo programado en el cronograma
                 para los próximos días y las fechas comprometidas por Gestión Humana
    Cierre       «Atentamente,», nombre y cargo de quien firma

  Todo lo propone el sistema (propuesta) y la dirección lo corrige, agrega o
  quita antes de guardar. El destinatario, la presentación y la firma se toman
  del último reporte guardado, para no escribirlos cada día.

  No tiene tabla propia. Se guarda en historial, una fila por cada versión:
    MODULO = 'GERENCIA', ID_REGISTRO = el último día como número AAAAMMDD (20261101),
    TIPO = 'REPORTE', TEXTO = el reporte en JSON, ID_COLABORADOR y FECHA.
  El JSON empieza con version, fecha y desde (así se reconoce el periodo sin abrir
  todo el texto) y sigue con encabezado, resultados, prioritarias, proyectos,
  transversal, compromisos y nombres (el nombre de cada proyecto que aparece).
  Los reportes guardados con la estructura de puntos por proyecto (version 2)
  se leen convertidos a esta (deVersion2): no se pierde nada de lo escrito.
  Cada guardado que cambia algo agrega una fila NUEVA: el historial nunca se
  modifica ni se borra. La pantalla y el PDF usan la versión más reciente.
*/
require_once __DIR__ . "/HanaDB.php";
require_once __DIR__ . "/HanaConfig.php";
require_once __DIR__ . "/TemaClienteModelo.php";
require_once __DIR__ . "/VacanteModelo.php";
require_once __DIR__ . "/PromesaValorModelo.php";

class ReporteGerencia
{
    const MODULO  = 'GERENCIA';
    const TIPO    = 'REPORTE';
    const VERSION = 3;
    const LARGO_TEXTO  = 8000;   //caracteres del texto de cada apartado (y de los resultados)
    const LARGO_TITULO = 200;    //caracteres del título de cada apartado
    const MAX_APARTADOS = 80;    //apartados por sección (y renglones de compromisos)
    const LARGO_TOTAL = 60000;   //bytes del reporte completo (historial.TEXTO es TEXT: 65.535 bytes)
    const DIAS_COMPROMISOS = 30; //cuántos días hacia adelante se buscan compromisos en el cronograma
    const MAX_FILAS_VACANTES = 300; //renglones de la tabla de vacantes (uno por centro de operación)
    const LARGO_CARGO = 120;        //el cargo de la vacante más antigua de un centro, en la tabla de vacantes
    const LARGO_NOTA_VACANTES = 1000; //la nota que va debajo de la tabla de vacantes
    const LARGO_NOTA = 1500;        //cada cosa que se escribió en un tema, en el informe

    //Los títulos de las secciones, como van en el informe. Las gestiones prioritarias van al
    //comienzo de los avances por proyecto, y las vacantes al final, en su propia hoja
    const SECCIONES = array(
        'resultados'   => 'PROMESA DE VALOR',
        'prioritarias' => 'Gestiones prioritarias',
        'proyectos'    => 'AVANCES Y GESTIÓN POR PROYECTO',
        'transversal'  => 'GESTIONES TRANSVERSALES',
        'compromisos'  => 'COMPROMISOS Y SEGUIMIENTO',
        'vacantes'     => 'Estado de vacantes'
    );
    const SUBTITULO = 'Operación de Peajes y Básculas';
    //El renglón que queda debajo de cada peaje que no cumplió, para que la Dirección diga cómo se cubre
    const PLAN_COBERTURA = 'Plan de cobertura:';
    //Las secciones nuevas del seguimiento
    const SECCIONES_SEGUIMIENTO = array(
        'planes'        => 'AVANCE DE LOS PLANES DE TRABAJO',
        'escalamientos' => 'ASUNTOS QUE REQUIEREN DECISIÓN DE LA GERENCIA'
    );

    //El encabezado y la firma la primera vez (después se toman del último reporte guardado).
    //En la presentación, {corte} se cambia por el día del reporte
    const ENCABEZADO = array(
        'trato'        => 'Dra.',
        'destinatario' => 'Johanna Vera Martínez',
        'cargo'        => 'Gerente Unidad de Negocio – Operación Infraestructura',
        'presentacion' => 'Se presenta el balance de gestión de la Dirección Operativa, con corte al {corte}, destacando los resultados operativos, avances por proyecto, compromisos con los clientes y asuntos prioritarios que requieren seguimiento.',
        'firma'        => 'LILLEY KARINA SALGUERO GÓMEZ',
        'firmaCargo'   => 'Directora de Operaciones'
    );
    //Cómo venía el encabezado de fábrica antes: si alguien no lo cambió, se pasa al de ahora
    const ENCABEZADO_ANTERIOR = array(
        'trato'        => 'Doctora',
        'destinatario' => 'JOHANNA VERA MARTÍNEZ',
        'cargo'        => 'Gerente de Unidad de Infraestructura',
        'presentacion' => 'Se presenta el balance de gestión de la Dirección de Operaciones, con los principales resultados, avances por proyecto, compromisos con los clientes y asuntos que requieren seguimiento.'
    );
    //Cuántos caracteres admite cada dato del encabezado
    const LARGO_ENCABEZADO = array('trato' => 40, 'destinatario' => 120, 'cargo' => 150, 'presentacion' => 1500, 'firma' => 120, 'firmaCargo' => 150);

    private static $DIAS  = array('dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb');
    private static $DIAS_LARGOS = array('domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado');
    private static $MESES = array(1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                                  'septiembre', 'octubre', 'noviembre', 'diciembre');

    //-----------------------------------------------------------------------
    // Fechas para leer
    //-----------------------------------------------------------------------

    //El día como número para historial.ID_REGISTRO: 2026-11-01 -> 20261101
    public static function numeroDia($fecha) { return (int)str_replace('-', '', $fecha); }

    //"2026-10-27" -> "mar 27/10"
    public static function diaCorto($fecha)
    {
        $t = strtotime($fecha);
        return self::$DIAS[(int)date('w', $t)] . ' ' . date('d/m', $t);
    }

    //"2026-10-27" -> "martes 27 de octubre"
    public static function diaLargo($fecha)
    {
        $t = strtotime($fecha);
        return self::$DIAS_LARGOS[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' de ' . self::$MESES[(int)date('n', $t)];
    }

    //"2026-11-11" -> "11 de noviembre"
    public static function diaMes($fecha)
    {
        $t = strtotime($fecha);
        return (int)date('j', $t) . ' de ' . self::$MESES[(int)date('n', $t)];
    }

    //Un nombre como se escribe en el informe: «PEREZ GOMEZ ANA» -> «Perez Gomez Ana» (si viene todo en mayúscula)
    private static function nombrePersona($nombre)
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string)$nombre, ENT_QUOTES, 'UTF-8')));
        return $nombre === mb_strtoupper($nombre, 'UTF-8') ? mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8') : $nombre;
    }

    //"2026-11-19" -> "19 de nov"
    public static function diaMesCorto($fecha)
    {
        $t = strtotime($fecha);
        return (int)date('j', $t) . ' de ' . mb_substr(self::$MESES[(int)date('n', $t)], 0, 3, 'UTF-8');
    }

    //"2026-11-20" -> "20 de noviembre de 2026"
    public static function diaMesAnio($fecha)
    {
        $t = strtotime($fecha);
        return (int)date('j', $t) . ' de ' . self::$MESES[(int)date('n', $t)] . ' de ' . date('Y', $t);
    }

    //"2026-11-23" -> "23-nov" (las fechas comprometidas de la tabla de vacantes)
    public static function diaMesTabla($fecha)
    {
        $t = strtotime($fecha);
        return date('d', $t) . '-' . mb_substr(self::$MESES[(int)date('n', $t)], 0, 3, 'UTF-8');
    }

    //"2026-10-29" -> "jueves, 29 de octubre de 2026"
    public static function fechaLarga($fecha)
    {
        $t = strtotime($fecha);
        return self::$DIAS_LARGOS[(int)date('w', $t)] . ', ' . (int)date('j', $t) . ' de ' . self::$MESES[(int)date('n', $t)] . ' de ' . date('Y', $t);
    }

    //El periodo para leer: un día -> "jueves, 29 de octubre de 2026";
    //un rango -> "del lunes 26 al jueves 29 de octubre de 2026" (o "del viernes 30 de octubre al martes 3 de noviembre de 2026")
    public static function periodoLargo($desde, $hasta)
    {
        if ($desde === null || $desde === '' || $desde >= $hasta) { return self::fechaLarga($hasta); }
        $d = strtotime($desde); $h = strtotime($hasta);
        $inicio = self::$DIAS_LARGOS[(int)date('w', $d)] . ' ' . (int)date('j', $d);
        if (date('n', $d) !== date('n', $h) || date('Y', $d) !== date('Y', $h)) { $inicio .= ' de ' . self::$MESES[(int)date('n', $d)]; }
        if (date('Y', $d) !== date('Y', $h)) { $inicio .= ' de ' . date('Y', $d); }
        return 'del ' . $inicio . ' al ' . self::$DIAS_LARGOS[(int)date('w', $h)] . ' ' . (int)date('j', $h) . ' de ' . self::$MESES[(int)date('n', $h)] . ' de ' . date('Y', $h);
    }

    //El periodo corto: "mar 27/10" o "lun 26/10 – jue 29/10"
    public static function periodoCorto($desde, $hasta)
    {
        if ($desde === null || $desde === '' || $desde >= $hasta) { return self::diaCorto($hasta); }
        return self::diaCorto($desde) . ' – ' . self::diaCorto($hasta);
    }

    //La fecha de arriba del informe: "Corte: 20 de noviembre de 2026" o "Periodo: del 14 al 20 de noviembre de 2026"
    public static function fechaInforme($desde, $hasta)
    {
        $fin = self::diaMesAnio($hasta);
        if ($desde === null || $desde === '' || $desde >= $hasta) { return 'Corte: ' . $fin; }
        $d = strtotime($desde); $h = strtotime($hasta);
        $inicio = (int)date('j', $d);
        if (date('n', $d) !== date('n', $h) || date('Y', $d) !== date('Y', $h)) { $inicio .= ' de ' . self::$MESES[(int)date('n', $d)]; }
        if (date('Y', $d) !== date('Y', $h)) { $inicio .= ' de ' . date('Y', $d); }
        return 'Periodo: del ' . $inicio . ' al ' . $fin;
    }

    //El título del informe
    public static function tituloInforme($desde, $hasta)
    {
        return ($desde !== null && $desde !== '' && $desde < $hasta) ? 'REPORTE DE GESTIÓN – DIRECCIÓN OPERATIVA' : 'REPORTE DIARIO – DIRECCIÓN OPERATIVA';
    }

    //La presentación con el día del reporte: «con corte al {corte}» o «con corte al 19 de noviembre de 2026»
    //quedan con la fecha del reporte, para que no haya que cambiarla cada día
    public static function presentacionAlCorte($texto, $fecha)
    {
        $dia = self::diaMesAnio($fecha);
        $texto = str_replace('{corte}', $dia, (string)$texto);
        return preg_replace('/(con\s+corte\s+al?\s+)\d{1,2}\s+de\s+\p{L}+\s+de\s+\d{4}/iu', '${1}' . $dia, $texto);
    }

    //El día anterior: "2026-11-12" -> "2026-11-11"
    public static function diaAnterior($fecha) { return date('Y-m-d', strtotime($fecha . ' -1 day')); }

    //Desde cuándo se cuenta lo que se movió: en un rango, desde su primer día; en un solo día, desde el
    //día anterior (lo que pasó después de enviar el informe de ayer también va)
    public static function desdeAvances($fecha, $desde)
    {
        return ($desde !== null && $desde !== '' && $desde < $fecha) ? $desde : self::diaAnterior($fecha);
    }

    //El día de la promesa de valor de un informe de un solo día: el día anterior o, si ese día no tiene lo
    //ejecutado registrado en ningún peaje, el último día que sí lo tenga (hasta un mes antes), para que el
    //informe siempre traiga los peajes registrados en la promesa de valor
    public static function diaPromesa($centros, $fecha)
    {
        $ayer = self::diaAnterior($fecha);
        $ultima = self::ultimaPromesa(array_map(function ($c) { return (int)$c['ID_CENTRO_OP']; }, array_values($centros)), $ayer);
        return $ultima !== null && $ultima >= date('Y-m-d', strtotime($ayer . ' -31 days')) ? $ultima : $ayer;
    }

    //El subtítulo de la promesa de valor. La promesa del informe es la del día anterior (en un rango, cada
    //día un día antes): el informe del 20 dice "Cumplimiento – 19 de noviembre", el del 19 y 20,
    //"– 18 y 19 de noviembre" y el del 10 al 12, "– 9 al 11 de noviembre"
    //$centros: los de la persona, para saber de qué día es la promesa de un informe de un solo día (diaPromesa)
    public static function tituloPromesa($desde, $hasta, $centros = null)
    {
        $rango = $desde !== null && $desde !== '' && $desde < $hasta;
        $hasta = !$rango && $centros !== null ? self::diaPromesa($centros, $hasta) : self::diaAnterior($hasta);
        $desde = $rango ? self::diaAnterior($desde) : $hasta;
        if ($desde === null || $desde === '' || $desde >= $hasta) { return 'Cumplimiento – ' . self::diaMes($hasta); }
        $d = strtotime($desde); $h = strtotime($hasta);
        $inicio = date('n', $d) === date('n', $h) ? (string)(int)date('j', $d) : self::diaMes($desde);
        return 'Cumplimiento – ' . $inicio . (self::diaAnterior($hasta) === $desde ? ' y ' : ' al ') . self::diaMes($hasta);
    }

    //Porcentaje para leer: (518, 527) -> "98,3 %". Sin base, null
    public static function porcentaje($parte, $total)
    {
        if ((float)$total <= 0) { return null; }
        $valor = 100 * (float)$parte / (float)$total;
        //Si faltó aunque sea un poco, no se redondea a 100 % (99,96 % sale como 99,9 %)
        if ((float)$parte < (float)$total && round($valor, 1) >= 100) { $valor = 99.9; }
        return rtrim(rtrim(number_format($valor, 1, ',', '.'), '0'), ',') . ' %';
    }

    private static function recortar($texto, $max)
    {
        $texto = trim(preg_replace('/\s+/u', ' ', (string)$texto));
        return mb_strlen($texto, 'UTF-8') > $max ? rtrim(mb_substr($texto, 0, $max - 1, 'UTF-8')) . '…' : $texto;
    }

    //Igual que recortar, pero respeta los saltos de línea (viñetas que escribió la persona)
    private static function recortarParrafos($texto, $max)
    {
        $lineas = array();
        foreach (preg_split('/\R/u', (string)$texto) as $linea) {
            $linea = trim(preg_replace('/[^\S\r\n]+/u', ' ', $linea));
            if ($linea !== '') { $lineas[] = $linea; }
        }
        $texto = implode("\n", $lineas);
        return mb_strlen($texto, 'UTF-8') > $max ? rtrim(mb_substr($texto, 0, $max - 1, 'UTF-8')) . '…' : $texto;
    }

    //«cubre fusca» -> «Cubre fusca»
    private static function primeraMayus($texto)
    {
        return mb_strtoupper(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($texto, 1, null, 'UTF-8');
    }

    //Un texto que termina en punto (sin duplicarlo)
    private static function conPunto($texto)
    {
        $texto = rtrim(trim((string)$texto), " \t");
        return $texto === '' ? '' : (preg_match('/[.!?…:]$/u', $texto) ? $texto : $texto . '.');
    }

    //Horas para leer: 8 -> "8", 7.5 -> "7,5", 1040 -> "1.040"
    private static function horas($valor)
    {
        return rtrim(rtrim(number_format((float)$valor, 2, ',', '.'), '0'), ',');
    }

    //"1 hora" / "8 horas"
    private static function enHoras($valor) { return self::horas($valor) . ((float)$valor == 1 ? ' hora' : ' horas'); }

    //"CONTROLADORES/SUPERVISOR" -> "Controladores/supervisor"
    private static function nombreCargo($nombre)
    {
        $nombre = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$nombre)), 'UTF-8');
        return mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($nombre, 1, null, 'UTF-8');
    }

    //-----------------------------------------------------------------------
    // Nombres escritos a mano (cargos, peajes): la misma cosa se escribe de muchas
    // formas («RECOLECTOR», «recolector », «Recol.», «Recolectora»). Para agrupar y
    // contar bien se comparan por su llave: en minúscula, sin tildes, sin puntos ni
    // signos y con un solo espacio entre palabras
    //-----------------------------------------------------------------------
    public static function llaveNombre($texto)
    {
        $texto = mb_strtolower(trim((string)$texto), 'UTF-8');
        $texto = strtr($texto, array('á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u'));
        $texto = preg_replace('/[^a-z0-9]+/u', ' ', $texto);
        return trim(preg_replace('/\s+/', ' ', $texto));
    }

    //¿$corta es otra forma de escribir $larga? Palabra por palabra, cada una empieza igual que la de
    //la otra: «recol» de «recolector», «contr oper» de «controlador operativo», «recolectora» y
    //«recolector» (se compara la más corta con el comienzo de la más larga). Una palabra de menos
    //de 3 letras no alcanza para decidir
    private static function mismaPalabra($corta, $larga)
    {
        //Las palabras de unión no cuentan: «Aux. recaudo» es «Auxiliar de recaudo»
        $sinUniones = function ($texto) { return array_values(array_diff(explode(' ', $texto), array('de', 'del', 'la', 'el', 'los', 'las', 'y', 'en', 'por', 'para'))); };
        $a = $sinUniones($corta); $b = $sinUniones($larga);
        if (!count($a) || !count($b)) { return false; }
        if (count($a) !== count($b)) { return false; }
        foreach ($a as $i => $palabra) {
            $x = strlen($palabra) <= strlen($b[$i]) ? $palabra : $b[$i];
            $y = strlen($palabra) <= strlen($b[$i]) ? $b[$i] : $palabra;
            if ($x === $y) { continue; }
            if (strlen($x) < 3 || strpos($y, $x) !== 0) { return false; }
        }
        return true;
    }

    //¿$larga es la misma palabra que $corta en femenino o en plural? («recolectora», «recolectores»)
    private static function variante($corta, $larga)
    {
        $a = explode(' ', $corta); $b = explode(' ', $larga);
        if (count($a) !== count($b)) { return false; }
        foreach ($a as $i => $palabra) {
            if ($palabra === $b[$i]) { continue; }
            if (strlen($palabra) < 4 || strpos($b[$i], $palabra) !== 0 || !in_array(substr($b[$i], strlen($palabra)), array('a', 's', 'es', 'as', 'os'), true)) { return false; }
        }
        return true;
    }

    //Une las formas de escribir un mismo nombre. Recibe los nombres como se escribieron y devuelve
    //[nombre escrito => nombre unificado]. Mayúsculas, tildes, puntos y espacios no cuentan; una
    //abreviatura («Recol.») se vuelve el nombre completo y el femenino o el plural («Recolectora»,
    //«Recolectores») se vuelve la forma más corta. De cada grupo queda la
    //forma más usada (o, si empatan, la que tiene tildes o la más larga)
    public static function unificarNombres($nombres)
    {
        $porLlave = array();
        foreach ($nombres as $nombre) {
            $llave = self::llaveNombre($nombre);
            if ($llave === '') { continue; }
            if (!isset($porLlave[$llave])) { $porLlave[$llave] = array(); }
            $porLlave[$llave][$nombre] = isset($porLlave[$llave][$nombre]) ? $porLlave[$llave][$nombre] + 1 : 1;
        }
        //A qué llave va cada una: las abreviaturas, a la completa; las casi iguales, a la más corta
        $llaves = array_keys($porLlave);
        usort($llaves, function ($a, $b) { return strlen($b) - strlen($a); });
        $destino = array();
        foreach ($llaves as $llave) { $destino[$llave] = $llave; }
        foreach ($llaves as $i => $llave) {
            $completas = array();
            foreach ($llaves as $j => $otra) {
                if ($i === $j || strlen($otra) < strlen($llave) || !self::mismaPalabra($llave, $otra)) { continue; }
                if (self::variante($llave, $otra)) { $destino[$otra] = $destino[$llave]; } //«recolectora» -> «recolector»
                else { $completas[$destino[$otra]] = true; }
            }
            //Una abreviatura se une solo si es de un único nombre: «rec» con «recolector» y «recaudador» se deja como está
            if (count($completas) === 1) { $destino[$llave] = key($completas); }
        }
        //El nombre que queda de cada grupo
        $grupos = array();
        foreach ($porLlave as $llave => $formas) {
            $final = $destino[$llave];
            while ($destino[$final] !== $final) { $final = $destino[$final]; }
            foreach ($formas as $forma => $veces) { $grupos[$final][$forma] = (isset($grupos[$final][$forma]) ? $grupos[$final][$forma] : 0) + $veces + ($llave === $final ? 1000 : 0); }
        }
        //Una forma escrita, ya limpia: sin espacios de más; si está toda en mayúscula o toda en
        //minúscula, con solo la primera letra en mayúscula
        $limpia = function ($forma) {
            $forma = trim(preg_replace('/\s+/u', ' ', $forma));
            if ($forma === mb_strtoupper($forma, 'UTF-8') || $forma === mb_strtolower($forma, 'UTF-8')) { $forma = self::nombreCargo($forma); }
            return $forma;
        };
        $resultado = array();
        foreach ($grupos as $final => $formasEscritas) {
            $formas = array();
            foreach ($formasEscritas as $forma => $peso) { $f = $limpia($forma); $formas[$f] = (isset($formas[$f]) ? $formas[$f] : 0) + $peso; }
            //Si empatan, la que tiene tildes («Técnico» antes que «Tecnico») y luego la más larga
            $tildes = function ($texto) { return strlen($texto) - mb_strlen($texto, 'UTF-8'); };
            uksort($formas, function ($a, $b) use ($formas, $tildes) {
                if ($formas[$b] !== $formas[$a]) { return $formas[$b] - $formas[$a]; }
                return $tildes($b) !== $tildes($a) ? $tildes($b) - $tildes($a) : mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
            });
            $elegido = key($formas);
            foreach ($porLlave as $llave => $originales) {
                $f = $destino[$llave];
                while ($destino[$f] !== $f) { $f = $destino[$f]; }
                if ($f !== $final) { continue; }
                foreach ($originales as $original => $veces) { $resultado[$original] = $elegido; }
            }
        }
        return $resultado;
    }

    //-----------------------------------------------------------------------
    // Los datos del periodo, por proyecto. De aquí salen los textos que propone
    // el sistema y la gráfica de la promesa de valor del PDF.
    //   $proyectos     [idProyecto => nombre]
    //   $centros       [idCentro => fila de hanaMisCentros()] (los peajes de la persona)
    //   $conNovedades  si se incluyen las novedades (quien puede verlas)
    //   $desde         primer día del periodo. Sin él, el reporte es solo de $fecha.
    //                  Los temas que se movieron y las novedades se cuentan en todo el periodo; los
    //                  oficios en gestión y las vacantes, como estaban el último día. La promesa de
    //                  valor, un día antes: la del día anterior a cada día del periodo
    // Devuelve [idProyecto => [
    //   'promesa'   => prometidas, ejecutadas, peajes [nombre, prometidas, ejecutadas, debajo[], encima[], sinRegistro[dia => cargos]]
    //   'oficios'   => lista [tipo, radicado, asunto, remitente, peaje, recepcion, dias, fueraPlazo, pendiente, vaEn]
    //   'temas'     => los temas (con el cliente y con Regency) abiertos al final del periodo o que se
    //                  cerraron en él, con lo que se escribió en el periodo (ver TemaCliente::paraInforme)
    //   'novedades' => lista [peaje, prioridad, titulo, descripcion]
    //   'vacantes'  => abiertas y cubiertas en el periodo
    //   'agenda'    => lo programado en el cronograma para los próximos días
    // ]]
    //-----------------------------------------------------------------------
    public static function calcular($proyectos, $centros, $fecha, $conNovedades, $desde = null)
    {
        $desde = ($desde !== null && $desde !== '' && $desde < $fecha) ? $desde : $fecha;
        $ids = array_map('intval', array_keys($proyectos));
        $datos = array();
        foreach ($ids as $id) {
            $datos[$id] = array('promesa' => array('prometidas' => 0, 'ejecutadas' => 0, 'peajes' => array()),
                                'oficios' => array(), 'atendidos' => array(), 'temas' => array(), 'novedades' => array(), 'cerradas' => array(),
                                'vacantes' => array(), 'agenda' => array());
        }
        if (!count($ids)) { return $datos; }
        $centrosPorProyecto = array();
        foreach ($centros as $centro) { $centrosPorProyecto[(int)$centro['ID_PROYECTO']][(int)$centro['ID_CENTRO_OP']] = self::nombrePeaje($centro['NOM_CENTRO_OP']); }

        //La promesa de valor es la del día anterior (se verifica que se cumplió lo de ayer); en un informe de un
        //solo día, si ayer no tiene nada registrado, la del último día que sí tenga
        $hastaPromesa = $desde < $fecha ? self::diaAnterior($fecha) : self::diaPromesa($centros, $fecha);
        self::datosPromesa($datos, $centrosPorProyecto, $hastaPromesa, $desde < $fecha ? self::diaAnterior($desde) : $hastaPromesa);
        //Lo que se movió (actualizaciones de temas, oficios atendidos, novedades, vacantes cubiertas o
        //canceladas): en un informe de un solo día, lo del día y lo del día anterior
        $desdeAvances = self::desdeAvances($fecha, $desde);
        self::datosOficios($datos, $ids, $fecha, $desdeAvances);
        foreach (TemaCliente::paraInforme($ids, $fecha, $desdeAvances) as $id => $temas) { if (isset($datos[$id])) { $datos[$id]['temas'] = $temas; } }
        if ($conNovedades) { self::datosNovedades($datos, $centros, $fecha, $desdeAvances); }
        self::datosVacantes($datos, $centros, $fecha, $desdeAvances);
        self::datosAgenda($datos, $centros, $fecha);
        return $datos;
    }

    //-----------------------------------------------------------------------
    // Lo que propone el sistema, con la estructura del informe:
    //   resultados (texto), prioritarias, proyectos, transversal y compromisos.
    // Cada apartado: [proyecto, titulo, texto]; cada compromiso: [proyecto, gestion, fecha]
    //-----------------------------------------------------------------------
    public static function propuesta($proyectos, $centros, $fecha, $conNovedades, $desde = null)
    {
        $desde = ($desde !== null && $desde !== '' && $desde < $fecha) ? $desde : $fecha;
        $datos = self::calcular($proyectos, $centros, $fecha, $conNovedades, $desde);
        $rango = $desde < $fecha;
        $prioritarias = array(); $porProyecto = array(); $transversal = array(); $compromisos = array();
        $vacantes = array();

        foreach ($datos as $id => $d) {
            $porProyecto[$id] = array('apartados' => array());
            //Temas abiertos, con todo lo que se llenó:
            //  con el cliente => gestiones prioritarias (lo que es y en qué va); si se actualizó en el periodo,
            //                    las actualizaciones van en los avances del proyecto, como en el seguimiento
            //  con Regency    => avances del proyecto, con sus actualizaciones
            //  de personal    => talento humano y SST (de cualquiera de los dos), con sus actualizaciones
            foreach ($d['temas'] as $tema) {
                $titulo = self::tituloConPeaje($tema['ASUNTO'], $tema['NOM_CENTRO_OP']);
                $compromisos[] = self::compromisoDeTema($tema, $id, $titulo, $fecha);
                if ($tema['CLASE'] === 'PERSONAL') {
                    $transversal[] = array('proyecto' => $id, 'titulo' => $titulo, 'texto' => self::textoTema($tema, $fecha, $desde, true));
                } elseif ($tema['CON'] === 'REGENCY') {
                    $porProyecto[$id]['apartados'][] = array('titulo' => $titulo, 'texto' => self::textoTema($tema, $fecha, $desde, true));
                } else {
                    $prioritarias[] = array('proyecto' => $id, 'titulo' => $titulo, 'texto' => self::textoTema($tema, $fecha, $desde, false));
                    if (count($tema['actualizaciones'])) {
                        $porProyecto[$id]['apartados'][] = array('titulo' => $titulo . ' (con el cliente)', 'texto' => self::textoActualizaciones($tema));
                    }
                }
            }
            //Oficios y comunicaciones en gestión: son compromisos con el cliente
            foreach ($d['oficios'] as $oficio) {
                $prioritarias[] = array('proyecto' => $id, 'titulo' => self::tituloConPeaje($oficio['asunto'], $oficio['peaje']), 'texto' => self::textoOficio($oficio));
            }
            //Avances del proyecto: oficios atendidos, vacantes cubiertas o canceladas, novedades atendidas y reportadas
            if (count($d['atendidos'])) {
                $porProyecto[$id]['apartados'][] = array('titulo' => 'Oficios y comunicaciones atendidos', 'texto' => self::textoAtendidos($d['atendidos']));
            }
            $cerradasVacantes = self::textoVacantes($d['vacantes']);
            if ($cerradasVacantes !== '') { $porProyecto[$id]['apartados'][] = array('titulo' => 'Vacantes', 'texto' => $cerradasVacantes); }
            if (count($d['cerradas'])) {
                $porProyecto[$id]['apartados'][] = array('titulo' => 'Novedades atendidas', 'texto' => self::textoCerradas($d['cerradas']));
            }
            if (count($d['novedades'])) {
                $porProyecto[$id]['apartados'][] = array('titulo' => $rango ? 'Novedades reportadas en el periodo' : 'Novedades reportadas',
                                                         'texto' => self::textoNovedades($d['novedades']));
            }
            foreach ($d['vacantes'] as $vacante) { $vacantes[] = $vacante + array('proyecto' => $proyectos[$id]); }
            foreach ($d['agenda'] as $actividad) {
                $compromisos[] = array('proyecto' => $id, 'gestion' => $actividad['gestion'], 'accion' => 'Programado en el cronograma.',
                                       'responsable' => self::recortar(implode(', ', $actividad['responsables']), 120),
                                       'fecha' => $actividad['fecha'], 'cumplido' => false, 'detalle' => '', 'origen' => 'agenda', 'orden' => $actividad['fecha']);
            }
        }

        //Vacantes: las abiertas van en la tabla por centro de operación (con la fecha que comprometió Gestión
        //Humana) y las cubiertas o canceladas, en los avances de su proyecto
        //Los compromisos: primero los vencidos y los que vencen antes, los cumplidos al final
        usort($compromisos, function ($a, $b) { return strcmp(($a['cumplido'] ? '1' : '0') . $a['orden'] . $a['gestion'], ($b['cumplido'] ? '1' : '0') . $b['orden'] . $b['gestion']); });
        foreach ($compromisos as $i => $c) { unset($compromisos[$i]['orden']); }
        $tabla = self::tablaVacantes($vacantes, $proyectos, $fecha);

        return array('resultados' => self::textoResultados($datos, $proyectos, $fecha, $desde),
                     'prioritarias' => $prioritarias, 'proyectos' => $porProyecto,
                     'transversal' => $transversal, 'vacantes' => $tabla, 'notaVacantes' => self::notaVacantes($tabla, $proyectos),
                     'compromisos' => array_values($compromisos), 'escalamientos' => array(), 'planes' => array());
    }

    //Un tema con el cliente o con Regency como renglón de «Compromisos y seguimiento»: qué se está
    //haciendo (lo último que se escribió), quién responde, la fecha comprometida de cierre y, en el
    //detalle, cuántos días lleva abierto y cuántas veces se ha corrido la fecha
    private static function compromisoDeTema($tema, $idProyecto, $titulo, $fecha)
    {
        $c = isset($tema['compromiso']) ? $tema['compromiso'] : TemaCliente::sinCompromiso();
        $cerrado = $tema['FECHA_ATENCION'] !== null && $tema['FECHA_ATENCION'] <= $fecha;
        if ($cerrado) { $detalle = 'Se cerró el ' . self::diaMes($tema['FECHA_ATENCION']) . '.'; }
        else {
            $dias = self::diasDesde($tema['FECHA_RECEPCION'], $fecha);
            $detalle = 'Abierto hace ' . $dias . ($dias === 1 ? ' día' : ' días') . '.';
        }
        if ($c['veces']) {
            $detalle .= ' Fecha corrida ' . ($c['veces'] === 1 ? '1 vez' : $c['veces'] . ' veces') . ' (antes: ' . implode(', ', array_map(array('ReporteGerencia', 'diaMesTabla'), $c['anteriores'])) . ')' .
                        ($c['motivo'] !== '' ? '; último motivo: ' . self::conPunto(self::recortar($c['motivo'], 200)) : '.');
        }
        $accion = trim((string)$tema['ULTIMO_TEXTO']) !== '' ? self::conPunto(self::recortar($tema['ULTIMO_TEXTO'], 260)) : '';
        return array('proyecto' => $idProyecto, 'gestion' => $titulo . ($tema['CON'] === 'REGENCY' ? ' (con Regency)' : ''), 'accion' => $accion,
                     'responsable' => $c['responsable'], 'fecha' => $cerrado ? $tema['FECHA_ATENCION'] : $c['fecha'], 'cumplido' => $cerrado,
                     'detalle' => $detalle, 'origen' => 'tema:' . (int)$tema['ID_COMUNICACION'], 'orden' => $c['fecha'] !== null ? $c['fecha'] : '9999');
    }

    //En qué va un compromiso el día del reporte: [texto, clase] con clase cumplido, vencido, hoy, tiempo o sinfecha
    public static function estadoCompromiso($c, $fecha)
    {
        if (!empty($c['cumplido'])) { return array('Cumplido', 'cumplido'); }
        if (empty($c['fecha'])) { return array('Sin fecha', 'sinfecha'); }
        if ($c['fecha'] < $fecha) {
            $dias = self::diasDesde($c['fecha'], $fecha);
            return array('Vencido (' . $dias . ($dias === 1 ? ' día)' : ' días)'), 'vencido');
        }
        return $c['fecha'] === $fecha ? array('Vence hoy', 'hoy') : array('En tiempo', 'tiempo');
    }

    //La nota de debajo de la tabla de vacantes: los proyectos que no tienen ninguna abierta
    //(«GICA no presenta vacantes.», «GICA y VINUS no presentan vacantes.»)
    public static function notaVacantes($tabla, $proyectos)
    {
        $con = array();
        foreach (is_array($tabla) ? $tabla : array() as $fila) { $con[(int)$fila['proyecto']] = true; }
        $sin = array();
        foreach ($proyectos as $id => $nombre) { if (!isset($con[(int)$id])) { $sin[] = $nombre; } }
        if (!count($sin) || !count($con)) { return ''; }
        $ultimo = array_pop($sin);
        return (count($sin) ? implode(', ', $sin) . ' y ' . $ultimo . ' no presentan' : $ultimo . ' no presenta') . ' vacantes.';
    }

    //-----------------------------------------------------------------------
    // Tabla de vacantes abiertas: un renglón por centro de operación que tenga alguna
    // (los que no tienen no salen): {proyecto, centro, total, antigua, cargo, compromiso}
    //   antigua     la fecha de la vacante más antigua (AAAA-MM-DD); los días se cuentan al mostrarla
    //   cargo       el cargo de esa vacante más antigua
    //   compromiso  la fecha que comprometió Gestión Humana para cubrirlas: la más cercana que
    //               no haya pasado o, si todas pasaron, la última (AAAA-MM-DD)
    //-----------------------------------------------------------------------
    private static function tablaVacantes($vacantes, $proyectos, $fecha)
    {
        $grupos = array();
        foreach ($vacantes as $v) {
            if ($v['estado'] !== 'ABIERTA') { continue; }
            $llave = (int)$v['idProyecto'] . '|' . $v['peaje'];
            if (!isset($grupos[$llave])) { $grupos[$llave] = array('proyecto' => (int)$v['idProyecto'], 'centro' => (string)$v['peaje'], 'lista' => array()); }
            $grupos[$llave]['lista'][] = $v;
        }
        $filas = array();
        foreach ($grupos as $g) {
            $antigua = null; $cargo = ''; $proximo = null; $vencido = null;
            foreach ($g['lista'] as $v) {
                if ($antigua === null || $v['desde'] < $antigua) { $antigua = $v['desde']; $cargo = $v['cargo']; }
                if ($v['compromiso'] === null) { continue; }
                if ($v['compromiso'] >= $fecha) { if ($proximo === null || $v['compromiso'] < $proximo) { $proximo = $v['compromiso']; } }
                elseif ($vencido === null || $v['compromiso'] > $vencido) { $vencido = $v['compromiso']; }
            }
            $filas[] = array('proyecto' => $g['proyecto'], 'centro' => $g['centro'], 'total' => count($g['lista']), 'antigua' => $antigua,
                             'cargo' => mb_strtoupper($cargo, 'UTF-8'), 'compromiso' => $proximo !== null ? $proximo : $vencido);
        }
        //En el orden de los proyectos (por nombre) y, dentro de cada uno, por centro de operación
        $orden = array_flip(array_map('intval', array_keys($proyectos)));
        usort($filas, function ($a, $b) use ($orden) {
            $pa = isset($orden[$a['proyecto']]) ? $orden[$a['proyecto']] : 9999; $pb = isset($orden[$b['proyecto']]) ? $orden[$b['proyecto']] : 9999;
            return $pa !== $pb ? $pa - $pb : strcmp($a['centro'], $b['centro']);
        });
        return $filas;
    }

    //El último día, hasta $hasta, en que alguno de esos peajes tiene lo ejecutado registrado en la
    //promesa de valor (null si ninguno): para avisar cuando el día del informe no tiene nada
    public static function ultimaPromesa($idsCentros, $hasta)
    {
        $idsCentros = array_map('intval', array_values($idsCentros));
        if (!count($idsCentros)) { return null; }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $fila = HanaDB::fila("SELECT MAX(FECHA) AS FECHA FROM pv_dia WHERE ID_CENTRO_OP IN ($marcas) AND FECHA <= ? AND PROMESA_HORAS IS NOT NULL AND EJECUTADO_HORAS IS NOT NULL",
                             str_repeat('i', count($idsCentros)) . 's', array_merge($idsCentros, array($hasta)));
        return $fila && $fila['FECHA'] ? $fila['FECHA'] : null;
    }

    //Horas para mostrar en las tablas: 1040 -> "1.040", 7.5 -> "7,5"
    public static function horasTexto($valor) { return self::horas($valor); }

    //Cuántos días lleva abierta una vacante desde $desde hasta $hasta
    public static function diasDesde($desde, $hasta)
    {
        if (!$desde || !$hasta) { return null; }
        return max(0, (int)round((strtotime($hasta) - strtotime($desde)) / 86400));
    }

    //"Tarifarios y facturación" + "LA BALSA" -> "Tarifarios y facturación – Peaje La Balsa"
    private static function tituloConPeaje($asunto, $peaje)
    {
        $titulo = self::recortar($asunto, 150);
        $peaje = trim((string)$peaje);
        if ($peaje === '') { return $titulo; }
        $nombre = mb_convert_case(mb_strtolower($peaje, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        return $titulo . ' – ' . (preg_match('/^(peaje|b[aá]scula)\b/iu', $nombre) ? $nombre : 'Peaje ' . $nombre);
    }

    //«centro de operación» o «centros de operación», según cuántos (peajes y básculas)
    public static function centros($cuantos) { return (int)$cuantos === 1 ? 'centro de operación' : 'centros de operación'; }

    //El nombre de un peaje como sale en el informe: en mayúscula y sin espacios de más, se haya
    //registrado como se haya registrado («Cocora», «COCORA », «cocora» -> «COCORA»)
    public static function nombrePeaje($nombre)
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', html_entity_decode((string)$nombre, ENT_QUOTES, 'UTF-8'))), 'UTF-8');
    }

    //El nombre dentro de una frase: «Recolector» -> «recolector». Si empieza con una sigla («SIG»,
    //«TIE») se deja como está, y si está todo en mayúscula va todo en minúscula
    private static function minusInicial($texto)
    {
        $texto = trim((string)$texto);
        if ($texto === mb_strtoupper($texto, 'UTF-8') && preg_match('/\p{L}{4}/u', $texto)) { return mb_strtolower($texto, 'UTF-8'); }
        if (preg_match('/^\p{Lu}{2,}\b/u', $texto)) { return $texto; }
        return mb_strtolower(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($texto, 1, null, 'UTF-8');
    }

    //-----------------------------------------------------------------------
    // 1. Resultados: la promesa de valor por peaje y cargo, entre $desde y $fecha (calcular()
    // los pasa ya corridos un día atrás). Solo se leen las casillas de pv_dia de esos días:
    // nada de antes ni de después entra en las cuentas.
    //-----------------------------------------------------------------------
    private static function datosPromesa(&$datos, $centrosPorProyecto, $fecha, $desde)
    {
        $idsCentros = array();
        foreach ($centrosPorProyecto as $lista) { foreach (array_keys($lista) as $idCentro) { $idsCentros[] = (int)$idCentro; } }
        if (!count($idsCentros)) { return; }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $filas = HanaDB::q("SELECT d.ID_CENTRO_OP, d.ID_CARGO_AUS, d.FECHA, a.NOMBRE AS CARGO, d.PROMESA_HORAS, d.EJECUTADO_HORAS, d.JUSTIFICACION
                              FROM pv_dia d INNER JOIN aus_cargo a ON a.ID_CARGO_AUS = d.ID_CARGO_AUS
                             WHERE d.ID_CENTRO_OP IN ($marcas) AND d.FECHA BETWEEN ? AND ? AND d.PROMESA_HORAS IS NOT NULL
                             ORDER BY d.FECHA, a.ORDEN, a.NOMBRE",
                           str_repeat('i', count($idsCentros)) . 'ss', array_merge($idsCentros, array($desde, $fecha)));
        $porCentro = array();
        foreach (is_array($filas) ? $filas : array() as $fila) { $porCentro[(int)$fila['ID_CENTRO_OP']][] = $fila; }
        //Lo que se cubrió con encargos o desde otro peaje (opciones marcadas en la justificación; ver
        //PromesaValor::coberturas). Para nombrar de dónde vino: los nombres de los peajes y los cargos
        $coberturas = PromesaValor::coberturas(is_array($filas) ? $filas : array());
        $nombresCentro = array(); $nombresCargo = array();
        foreach ($centrosPorProyecto as $lista) { foreach ($lista as $idCentro => $nombre) { $nombresCentro[(int)$idCentro] = $nombre; } }
        foreach (is_array($filas) ? $filas : array() as $fila) { $nombresCargo[(int)$fila['ID_CARGO_AUS']] = self::nombreCargo($fila['CARGO']); }

        foreach ($centrosPorProyecto as $idProyecto => $lista) {
            if (!isset($datos[(int)$idProyecto])) { continue; }
            $p = &$datos[(int)$idProyecto]['promesa'];
            foreach ($lista as $idCentro => $nombreCentro) {
                //Van todos los centros de operación: los que no tienen nada en la promesa ese día también
                //cuentan en el total («16 de 20 peajes») y se nombran como pendientes
                $peaje = array('nombre' => $nombreCentro, 'prometidas' => 0, 'ejecutadas' => 0, 'debajo' => array(), 'encima' => array(), 'sinRegistro' => array(),
                               'cubiertos' => array(), 'dias' => array(), 'sinPromesa' => !isset($porCentro[$idCentro]));
                if ($peaje['sinPromesa']) { $p['peajes'][] = $peaje; continue; }
                foreach ($porCentro[$idCentro] as $casilla) {
                    if ($casilla['EJECUTADO_HORAS'] === null) { $peaje['sinRegistro'][$casilla['FECHA']][] = self::nombreCargo($casilla['CARGO']); continue; }
                    $promesa = (float)$casilla['PROMESA_HORAS']; $ejecutado = (float)$casilla['EJECUTADO_HORAS'];
                    //Lo que se le cubrió con un encargo o desde otro peaje, y lo que este cargo cubrió a otro
                    $llaveCob = (int)$idCentro . '-' . (int)$casilla['ID_CARGO_AUS'] . '-' . $casilla['FECHA'];
                    $cob = isset($coberturas[$llaveCob]) ? $coberturas[$llaveCob] : null;
                    $cubiertas = $cob ? (float)$cob['cubiertas'] : 0; $cubrio = $cob ? (float)$cob['cubrio'] : 0;
                    //Las horas de más de un cargo no tapan las que le faltaron a otro: de cada cargo cuenta
                    //lo ejecutado hasta lo prometido, así el cumplimiento no pasa del 100 % y un peaje
                    //con un cargo por debajo no sale cumpliendo. La excepción son los encargos y los apoyos
                    //entre peajes que se marcaron en la justificación: esas horas sí cuentan como cubiertas
                    $peaje['prometidas'] += $promesa; $peaje['ejecutadas'] += min($ejecutado + $cubiertas, $promesa);
                    //Lo de cada día, para decir el cumplimiento de cada uno («19 de nov: 92,8 %»)
                    if (!isset($peaje['dias'][$casilla['FECHA']])) { $peaje['dias'][$casilla['FECHA']] = array('prometidas' => 0, 'ejecutadas' => 0); }
                    $peaje['dias'][$casilla['FECHA']]['prometidas'] += $promesa;
                    $peaje['dias'][$casilla['FECHA']]['ejecutadas'] += min($ejecutado + $cubiertas, $promesa);
                    if ($cubiertas > 0) {
                        $de = array();
                        foreach ($cob['de'] as $d) {
                            $de[] = $d[1] === 'encargo' ? 'con encargo de ' . self::minusInicial(isset($nombresCargo[$d[3]]) ? $nombresCargo[$d[3]] : '')
                                                        : 'desde ' . self::nombrePeaje(isset($nombresCentro[$d[2]]) ? $nombresCentro[$d[2]] : '');
                        }
                        $peaje['cubiertos'][] = array('fecha' => $casilla['FECHA'], 'cargo' => self::nombreCargo($casilla['CARGO']), 'horas' => $cubiertas, 'de' => implode(' y ', array_unique($de)));
                    }
                    $diferencia = round($promesa - $ejecutado, 2);
                    //Lo que queda después de los encargos y apoyos: las horas que de verdad faltaron o sobraron
                    $resto = $diferencia > 0 ? round($diferencia - $cubiertas, 2) : ($diferencia < 0 ? round(-$diferencia - $cubrio, 2) : 0);
                    if ($resto <= 0) { continue; }
                    //En las horas de más que quedan, lo de «cubrió a…» ya se dice en el renglón del que recibió
                    $texto = $cubrio > 0 ? PromesaValor::sinEnlaces($casilla['JUSTIFICACION']) : trim((string)$casilla['JUSTIFICACION']);
                    $caso = array('fecha' => $casilla['FECHA'], 'cargo' => self::nombreCargo($casilla['CARGO']), 'promesa' => $promesa, 'ejecutado' => $ejecutado,
                                  'diferencia' => $resto, 'cubiertas' => $cubiertas, 'justificacion' => $texto);
                    if ($diferencia > 0) { $peaje['debajo'][] = $caso; } else { $peaje['encima'][] = $caso; }
                }
                $p['prometidas'] += $peaje['prometidas']; $p['ejecutadas'] += $peaje['ejecutadas'];
                $p['peajes'][] = $peaje;
            }
            unset($p);
        }
    }

    //Las cifras de la promesa de todos los proyectos: horas, porcentaje y peajes que cumplen.
    //  peajes      todos los centros de operación (el total de «16 de 20 peajes»)
    //  medidos     los que tienen lo ejecutado registrado: de ellos sale el porcentaje
    //  cumplen     los medidos sin ningún cargo por debajo de lo prometido
    //  pendientes  los que no tienen lo ejecutado registrado (o nada en la promesa ese día): no cumplen
    //  hay         si algún centro tiene algo en la promesa ese día
    public static function totalesPromesa($datos)
    {
        $t = array('prometidas' => 0, 'ejecutadas' => 0, 'peajes' => 0, 'medidos' => 0, 'cumplen' => 0, 'pendientes' => 0, 'hay' => false);
        foreach ($datos as $d) {
            foreach ($d['promesa']['peajes'] as $peaje) {
                $t['peajes']++;
                if (empty($peaje['sinPromesa'])) { $t['hay'] = true; }
                if ($peaje['prometidas'] <= 0) { $t['pendientes']++; continue; }
                $t['prometidas'] += $peaje['prometidas']; $t['ejecutadas'] += $peaje['ejecutadas'];
                $t['medidos']++;
                if (!count($peaje['debajo'])) { $t['cumplen']++; }
            }
        }
        $t['pct'] = $t['prometidas'] > 0 ? 100 * $t['ejecutadas'] / $t['prometidas'] : null;
        return $t;
    }

    //El texto de la promesa de valor, como en el informe:
    //   Cumplimiento real de horas: 98,3 % (4.906 de 4.990 horas). 16 de 20 centros de operación cumplieron el 100 %.
    //   Por proyecto: ACCENORTE 96,6 % (2 de 3 centros de operación) · ICCU 100 % (…)
    //   ANDES (ACCENORTE):
    //   • 19 de nov: 92,8 %. Recolector: 236 de 260 horas ejecutadas, 24 horas pendientes. Motivo: …
    //   Plan de cobertura:              (lo completa la Dirección: qué se hace mientras se cubre)
    //y al final los que tienen pendiente registrar lo ejecutado. El cumplimiento real (las horas) y las
    //estaciones que cumplieron van separados, para que un 100 % de estaciones no se lea como 100 % de horas
    private static function textoResultados($datos, $proyectos, $fecha, $desde)
    {
        $rango = $desde < $fecha;
        $t = self::totalesPromesa($datos);
        if (!$t['hay']) { return $rango ? 'No se registró promesa de valor para el periodo.' : 'No se registró promesa de valor para la fecha.'; }
        if (!$t['medidos']) { return 'Está pendiente el registro de lo ejecutado frente a la promesa de valor en todos los centros de operación.'; }
        $lineas = array('Cumplimiento real de horas: ' . self::porcentaje($t['ejecutadas'], $t['prometidas']) . ' (' . self::horas($t['ejecutadas']) . ' de ' . self::enHoras($t['prometidas']) . '). ' .
                        $t['cumplen'] . ' de ' . $t['peajes'] . ' ' . self::centros($t['peajes']) . ' cumplieron el 100 %.');
        //Una línea con el cumplimiento de cada proyecto (con el total de sus centros): de ahí sale la gráfica por proyecto del PDF
        $porProyecto = array();
        foreach ($datos as $id => $d) {
            $tp = self::totalesPromesa(array($d));
            if (!$tp['hay']) { continue; }
            $porProyecto[] = $proyectos[$id] . ' ' . ($tp['medidos'] ? self::porcentaje($tp['ejecutadas'], $tp['prometidas']) . ' (' . $tp['cumplen'] . ' de ' . $tp['peajes'] . ' ' . self::centros($tp['peajes']) . ')' : 'pendiente');
        }
        if (count($porProyecto) > 1) { $lineas[] = 'Por proyecto: ' . implode(' · ', $porProyecto) . '.'; }
        $pendientes = array(); $apoyos = array();
        foreach ($datos as $id => $d) {
            foreach ($d['promesa']['peajes'] as $peaje) {
                $nombre = $peaje['nombre'] . ' (' . $proyectos[$id] . ')';
                if ($peaje['prometidas'] <= 0) { $pendientes[] = $nombre; continue; }
                $cubierto = self::textoCubiertos($nombre, $peaje['cubiertos']);
                if ($cubierto !== '') { $apoyos[] = $cubierto; }
                $apoyo = self::textoApoyo($nombre, $peaje['encima']);
                if ($apoyo !== '') { $apoyos[] = $apoyo; }
                if (!count($peaje['debajo'])) { continue; }
                //El peaje y, debajo, un renglón por cada día en que no cumplió, con lo de cada cargo
                $porDia = array();
                foreach ($peaje['debajo'] as $c) {
                    $porDia[$c['fecha']][] = $c['cargo'] . ': ' . self::horas($c['ejecutado']) . ' de ' . self::enHoras($c['promesa']) . ' ejecutadas, ' .
                                             (!empty($c['cubiertas']) ? self::horas($c['cubiertas']) . ' cubiertas con encargo o apoyo, ' : '') .
                                             self::horas($c['diferencia']) . ($c['diferencia'] == 1 ? ' hora pendiente. ' : ' horas pendientes. ') .
                                             ($c['justificacion'] !== '' ? 'Motivo: ' . self::conPunto(self::recortar($c['justificacion'], 350)) : 'Sin motivo registrado.');
                }
                $lineas[] = $nombre . ':';
                foreach ($porDia as $dia => $casos) {
                    $d = isset($peaje['dias'][$dia]) ? $peaje['dias'][$dia] : array('prometidas' => 0, 'ejecutadas' => 0);
                    $lineas[] = '• ' . self::diaMesCorto($dia) . ': ' . self::porcentaje($d['ejecutadas'], $d['prometidas']) . '. ' . implode(' ', $casos);
                }
                $lineas[] = self::PLAN_COBERTURA;
            }
        }
        //Los peajes que hicieron horas de más y dijeron por qué (casi siempre, porque cubrieron a otro)
        if (count($apoyos)) {
            $lineas[] = 'Apoyos, encargos y horas de más:';
            foreach ($apoyos as $apoyo) { $lineas[] = $apoyo; }
        }
        if (count($pendientes)) { $lineas[] = 'Pendiente el registro de lo ejecutado en: ' . implode(', ', $pendientes) . '.'; }
        return implode("\n", $lineas);
    }

    //Un renglón de «Apoyos, encargos y horas de más» para un peaje con horas que se cubrieron con un
    //encargo o desde otro peaje: «• CHICORAL (APP GICA): 23 de nov, 9 horas de controladores/supervisor
    //cubiertas con encargo de recolectores.» '' si no hay
    private static function textoCubiertos($nombre, $cubiertos)
    {
        if (!count($cubiertos)) { return ''; }
        $partes = array();
        foreach ($cubiertos as $c) {
            $partes[] = self::diaMesCorto($c['fecha']) . ', ' . self::horas($c['horas']) . ($c['horas'] == 1 ? ' hora' : ' horas') . ' de ' .
                        self::minusInicial($c['cargo']) . ' ' . ($c['horas'] == 1 ? 'cubierta ' : 'cubiertas ') . $c['de'];
        }
        return '• ' . $nombre . ': ' . implode('; ', $partes) . '.';
    }

    //Un renglón de «Apoyos y horas de más» para un peaje: los días, las horas de más y lo que escribieron
    //(«• UNISABANA (ACCENORTE): 20, 21 y 22 de nov, 32 horas de más en recolectores y controladores/
    //supervisor. Cubre Fusca · cubren en Fusca el faltante.»). Solo cuenta lo que tiene comentario: las
    //horas de más sin explicación no dicen nada a quien lee. '' si no hay nada
    private static function textoApoyo($nombre, $encima)
    {
        $dias = array(); $cargos = array(); $horas = 0; $comentarios = array();
        foreach ($encima as $c) {
            if ($c['justificacion'] === '') { continue; }
            $dias[$c['fecha']] = true;
            $cargos[self::minusInicial($c['cargo'])] = true;
            $horas += $c['diferencia'];
            $llave = self::llaveNombre($c['justificacion']);
            if (!isset($comentarios[$llave])) { $comentarios[$llave] = self::recortar(rtrim($c['justificacion'], '. '), 160); }
        }
        if (!count($dias)) { return ''; }
        ksort($dias);
        $fechas = array_keys($dias);
        $numeros = array_map(function ($f) { return (string)(int)date('j', strtotime($f)); }, $fechas);
        $ultimo = array_pop($numeros);
        $textoDias = (count($numeros) ? implode(', ', $numeros) . ' y ' : '') . $ultimo . ' de ' . mb_substr(self::$MESES[(int)date('n', strtotime(end($fechas)))], 0, 3, 'UTF-8');
        $textoCargos = array_keys($cargos);
        $ultimoCargo = array_pop($textoCargos);
        $textoCargos = (count($textoCargos) ? implode(', ', $textoCargos) . ' y ' : '') . $ultimoCargo;
        $comentarios = array_slice(array_values($comentarios), 0, 3);
        return '• ' . $nombre . ': ' . $textoDias . ', ' . self::horas($horas) . ($horas == 1 ? ' hora' : ' horas') . ' de más en ' . $textoCargos . '. ' .
               self::conPunto(self::primeraMayus(implode(' · ', $comentarios)));
    }

    //-----------------------------------------------------------------------
    // 2. Oficios y comunicaciones en gestión el último día: recibidos hasta ese día y sin cerrar ese día
    //-----------------------------------------------------------------------
    private static function datosOficios(&$datos, $ids, $fecha, $desde)
    {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $manana = date('Y-m-d', strtotime("$fecha +1 day"));
        $filas = HanaDB::q("SELECT m.ID_PROYECTO, m.TIPO, m.RADICADO, m.ASUNTO, m.REMITENTE, m.FECHA_RECEPCION, m.PENDIENTE_DE, c.NOM_CENTRO_OP,
                                   DATEDIFF(?, m.FECHA_RECEPCION) AS DIAS,
                                   (SELECT h.TEXTO FROM historial h
                                     WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = m.ID_COMUNICACION AND h.FECHA < ?
                                       AND h.TEXTO IS NOT NULL AND h.TEXTO <> ''
                                     ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS VA_EN
                              FROM comunicacion m LEFT JOIN centros_operacion c ON c.ID_CENTRO_OP = m.ID_CENTRO_OP
                             WHERE m.ESTADO = 1 AND m.TIPO <> '" . TemaCliente::TIPO . "' AND m.ID_PROYECTO IN ($marcas)
                               AND m.FECHA_RECEPCION <= ? AND (m.FECHA_ATENCION IS NULL OR m.FECHA_ATENCION > ?)
                             ORDER BY m.FECHA_RECEPCION, m.ID_COMUNICACION",
                           'ss' . str_repeat('i', count($ids)) . 'ss', array_merge(array($fecha, $manana), $ids, array($fecha, $fecha)));
        $pendientes = HanaConfig::opciones('LISTA_PENDIENTE');
        $plazo = max(0, HanaConfig::num('COMUNICACION_DIAS_RESPUESTA', 2));
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $datos[(int)$fila['ID_PROYECTO']]['oficios'][] = array(
                'tipo' => $fila['TIPO'], 'radicado' => (string)$fila['RADICADO'], 'asunto' => $fila['ASUNTO'], 'remitente' => $fila['REMITENTE'],
                'peaje' => (string)$fila['NOM_CENTRO_OP'], 'recepcion' => $fila['FECHA_RECEPCION'], 'dias' => (int)$fila['DIAS'], 'fueraPlazo' => (int)$fila['DIAS'] > $plazo,
                'pendiente' => $fila['PENDIENTE_DE'] ? (isset($pendientes[$fila['PENDIENTE_DE']]) ? $pendientes[$fila['PENDIENTE_DE']] : $fila['PENDIENTE_DE']) : '',
                'vaEn' => (string)$fila['VA_EN']);
        }
        //Los que se atendieron (resolvieron o enviaron) en el periodo, con lo último que se escribió
        $filas = HanaDB::q("SELECT m.ID_PROYECTO, m.TIPO, m.RADICADO, m.ASUNTO, m.REMITENTE, m.FECHA_ATENCION, c.NOM_CENTRO_OP,
                                   (SELECT h.TEXTO FROM historial h
                                     WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = m.ID_COMUNICACION AND h.FECHA < ?
                                       AND h.TEXTO IS NOT NULL AND h.TEXTO <> ''
                                     ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS VA_EN
                              FROM comunicacion m LEFT JOIN centros_operacion c ON c.ID_CENTRO_OP = m.ID_CENTRO_OP
                             WHERE m.ESTADO = 1 AND m.TIPO <> '" . TemaCliente::TIPO . "' AND m.ID_PROYECTO IN ($marcas)
                               AND m.FECHA_ATENCION BETWEEN ? AND ?
                             ORDER BY m.FECHA_ATENCION, m.ID_COMUNICACION",
                           's' . str_repeat('i', count($ids)) . 'ss', array_merge(array($manana), $ids, array($desde, $fecha)));
        foreach (is_array($filas) ? $filas : array() as $fila) {
            if (!isset($datos[(int)$fila['ID_PROYECTO']])) { continue; }
            $datos[(int)$fila['ID_PROYECTO']]['atendidos'][] = array('tipo' => $fila['TIPO'], 'radicado' => (string)$fila['RADICADO'], 'asunto' => $fila['ASUNTO'],
                'remitente' => (string)$fila['REMITENTE'], 'peaje' => (string)$fila['NOM_CENTRO_OP'], 'fecha' => $fila['FECHA_ATENCION'], 'vaEn' => (string)$fila['VA_EN']);
        }
    }

    //«• Se atendió «Queja de usuario por demora» – VILLALOBOS (Concesionaria Ruta al Sur, radicado RAS-1) el 13 de
    //noviembre: Se respondió al remitente con el oficio de soporte.»
    private static function textoAtendidos($atendidos)
    {
        $lineas = array();
        foreach ($atendidos as $a) {
            $quien = array();
            if (trim($a['remitente']) !== '') { $quien[] = self::recortar($a['remitente'], 80); }
            if ($a['radicado'] !== '') { $quien[] = 'radicado ' . $a['radicado']; }
            $lineas[] = '• ' . ($a['tipo'] === 'POR_ENVIAR' ? 'Se envió' : 'Se atendió') . ' «' . self::recortar($a['asunto'], 200) . '»' .
                        ($a['peaje'] !== '' ? ' – ' . self::nombrePeaje($a['peaje']) : '') . (count($quien) ? ' (' . implode(', ', $quien) . ')' : '') .
                        ' el ' . self::diaMes($a['fecha']) . ($a['vaEn'] !== '' ? ': ' . self::conPunto(preg_replace('/\s*\R\s*/u', ' ', self::recortar($a['vaEn'], 400))) : '.');
        }
        return implode("\n", $lineas);
    }

    //«Comunicación recibida de Interventoría el 10 de noviembre (radicado RAD-1); lleva 7 días en gestión,
    //fuera del plazo de respuesta. Último avance: … El siguiente paso corresponde a Dirección.»
    private static function textoOficio($c)
    {
        $porEnviar = $c['tipo'] === 'POR_ENVIAR';
        $texto = ($porEnviar ? 'Comunicación por enviar a ' : 'Comunicación recibida de ') . self::recortar($c['remitente'], 80) .
                 ($porEnviar ? ', registrada el ' : ' el ') . self::diaMes($c['recepcion']) .
                 ($c['radicado'] !== '' ? ' (radicado ' . $c['radicado'] . ')' : '') . '; ' .
                 ($c['dias'] === 0 ? 'se registró en la fecha' : 'lleva ' . $c['dias'] . ($c['dias'] === 1 ? ' día' : ' días') . ' en gestión') .
                 ($c['fueraPlazo'] ? ', fuera del plazo de respuesta.' : ', dentro del plazo de respuesta.');
        if ($c['vaEn'] !== '') { $texto .= ' Último avance: ' . self::conPunto(self::recortarParrafos($c['vaEn'], 600)); }
        if ($c['pendiente'] !== '') { $texto .= ' El siguiente paso corresponde a ' . $c['pendiente'] . '.'; }
        return $texto;
    }

    //-----------------------------------------------------------------------
    // Temas (con el cliente y con Regency): todo lo que se llenó del tema y en qué va.
    //   Con quién se trata: Interventoría · De qué se trata: Contractual · Pendiente de: Dirección
    //   Abierto desde el 13 de noviembre (6 días) · Lo registró: Diana Patricia Cruz Ortiz
    //   y después, si $conActualizaciones y se escribió algo en el periodo, cada actualización
    //   («• Diana Patricia Cruz Ortiz · 18/11/2026 07:00: …»); si no, en qué va (lo último que
    //   se escribió). En los temas con el cliente que se actualizaron, las actualizaciones van
    //   en los avances del proyecto, y aquí solo se dice cuándo
    //-----------------------------------------------------------------------
    private static function textoTema($tema, $fecha, $desde, $conActualizaciones)
    {
        $clases = TemaCliente::clases(); $pendientes = TemaCliente::pendientes();
        $datos = array();
        if (trim((string)$tema['REMITENTE']) !== '') { $datos[] = 'Con quién se trata: ' . self::recortar($tema['REMITENTE'], 120); }
        if ((string)$tema['CLASE'] !== '') { $datos[] = 'De qué se trata: ' . (isset($clases[$tema['CLASE']]) ? $clases[$tema['CLASE']] : $tema['CLASE']); }
        if ((string)$tema['PENDIENTE_DE'] !== '') { $datos[] = 'Pendiente de: ' . (isset($pendientes[$tema['PENDIENTE_DE']]) ? $pendientes[$tema['PENDIENTE_DE']] : $tema['PENDIENTE_DE']); }
        $lineas = array();
        if (count($datos)) { $lineas[] = implode(' · ', $datos) . '.'; }
        $abierto = 'Abierto desde el ' . self::diaMes($tema['FECHA_RECEPCION']);
        if ($tema['FECHA_ATENCION'] !== null && $tema['FECHA_ATENCION'] <= $fecha) { $abierto .= '; se cerró el ' . self::diaMes($tema['FECHA_ATENCION']); }
        else {
            $dias = self::diasDesde($tema['FECHA_RECEPCION'], $fecha);
            $abierto .= ' (' . $dias . ($dias === 1 ? ' día)' : ' días)');
        }
        if (trim((string)$tema['REGISTRO']) !== '') { $abierto .= ' · Lo registró: ' . $tema['REGISTRO']; }
        $lineas[] = $abierto . '.';
        if (count($tema['actualizaciones'])) {
            if ($conActualizaciones) { $lineas[] = self::textoActualizaciones($tema); }
            else {
                $ultima = end($tema['actualizaciones']);
                $lineas[] = 'Se actualizó el ' . self::diaMes(substr($ultima['FECHA'], 0, 10)) . ': el detalle está en los avances de gestión del proyecto.';
            }
        } elseif (trim((string)$tema['ULTIMO_TEXTO']) !== '') {
            $lineas[] = 'En qué va (' . self::diaMes(substr($tema['ULTIMO_FECHA'], 0, 10)) . '): ' . self::conPunto(self::recortar($tema['ULTIMO_TEXTO'], self::LARGO_NOTA));
        } else {
            $lineas[] = 'Sin seguimiento registrado.';
        }
        return implode("\n", $lineas);
    }

    //Lo que se escribió en el periodo en un tema, como en su seguimiento: quién, cuándo y qué
    private static function textoActualizaciones($tema)
    {
        $lineas = array();
        foreach ($tema['actualizaciones'] as $a) {
            $momento = date('d/m/Y H:i', strtotime($a['FECHA']));
            $que = $a['TIPO'] === 'RESUELTA' ? ' (cerró el tema)' : ($a['TIPO'] === 'REABIERTA' ? ' (reabrió el tema)' : '');
            $lineas[] = '• ' . (trim((string)$a['QUIEN']) !== '' ? $a['QUIEN'] . ' · ' : '') . $momento . $que . ': ' .
                        self::conPunto(preg_replace('/\s*\R\s*/u', ' ', self::recortar($a['TEXTO'], self::LARGO_NOTA)));
        }
        return implode("\n", $lineas);
    }

    //-----------------------------------------------------------------------
    // 3. Novedades reportadas en el periodo, en los peajes de la persona
    //-----------------------------------------------------------------------
    private static function datosNovedades(&$datos, $centros, $fecha, $desde)
    {
        $proyectoDe = array();
        foreach ($centros as $centro) { $proyectoDe[(int)$centro['ID_CENTRO_OP']] = (int)$centro['ID_PROYECTO']; }
        if (!count($proyectoDe)) { return; }
        $ids = array_keys($proyectoDe);
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $manana = date('Y-m-d', strtotime("$fecha +1 day"));
        $filas = HanaDB::q("SELECT n.ID_CENTRO_OP, c.NOM_CENTRO_OP, n.PRIORIDAD, n.DESCRIPCION, t.NOM_TITULO_NOVEDADES_HALLAZGOS AS TITULO
                              FROM novedades_hallazgos n
                             INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = n.ID_CENTRO_OP
                              LEFT JOIN titulo_novedades_hallazgos t ON t.ID_TITULO_NOVEDADES_HALLAZGOS = n.ID_TITULO
                             WHERE n.ID_CENTRO_OP IN ($marcas) AND n.FEC_CREACION >= ? AND n.FEC_CREACION < ?
                             ORDER BY FIELD(n.PRIORIDAD, 'ALTO', 'MEDIO', 'BAJO'), n.FEC_CREACION",
                           str_repeat('i', count($ids)) . 'ss', array_merge($ids, array($desde, $manana)));
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $idProyecto = $proyectoDe[(int)$fila['ID_CENTRO_OP']];
            if (!isset($datos[$idProyecto])) { continue; }
            //Las novedades se guardan codificadas para HTML
            $datos[$idProyecto]['novedades'][] = array('peaje' => self::nombrePeaje($fila['NOM_CENTRO_OP']), 'prioridad' => (string)$fila['PRIORIDAD'],
                'titulo' => html_entity_decode((string)$fila['TITULO'], ENT_QUOTES, 'UTF-8'),
                'descripcion' => html_entity_decode((string)$fila['DESCRIPCION'], ENT_QUOTES, 'UTF-8'));
        }
        //Las que se finalizaron o cerraron en el periodo, con la última respuesta
        $filas = HanaDB::q("SELECT n.ID_CENTRO_OP, c.NOM_CENTRO_OP, n.DESCRIPCION, n.FEC_CIERRE, n.ESTADO_NOVEDAD, t.NOM_TITULO_NOVEDADES_HALLAZGOS AS TITULO,
                                   (SELECT h.TEXTO FROM historial h WHERE h.MODULO = 'NOVEDAD' AND h.ID_REGISTRO = n.ID_NOVEDADES_HALLAZGOS
                                       AND h.TEXTO IS NOT NULL AND h.TEXTO <> '' ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS RESPUESTA
                              FROM novedades_hallazgos n
                             INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = n.ID_CENTRO_OP
                              LEFT JOIN titulo_novedades_hallazgos t ON t.ID_TITULO_NOVEDADES_HALLAZGOS = n.ID_TITULO
                             WHERE n.ID_CENTRO_OP IN ($marcas) AND n.ESTADO_NOVEDAD IN (3, 4) AND n.FEC_CIERRE >= ? AND n.FEC_CIERRE < ?
                             ORDER BY n.FEC_CIERRE",
                           str_repeat('i', count($ids)) . 'ss', array_merge($ids, array($desde, $manana)));
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $idProyecto = $proyectoDe[(int)$fila['ID_CENTRO_OP']];
            if (!isset($datos[$idProyecto])) { continue; }
            $datos[$idProyecto]['cerradas'][] = array('peaje' => self::nombrePeaje($fila['NOM_CENTRO_OP']), 'fecha' => substr($fila['FEC_CIERRE'], 0, 10),
                'titulo' => html_entity_decode((string)$fila['TITULO'], ENT_QUOTES, 'UTF-8'),
                'descripcion' => html_entity_decode((string)$fila['DESCRIPCION'], ENT_QUOTES, 'UTF-8'),
                'respuesta' => html_entity_decode((string)$fila['RESPUESTA'], ENT_QUOTES, 'UTF-8'));
        }
    }

    //«• Se atendió la novedad «Baños sin aseo» – GUALANDAY (Aseo y presentación) el 14 de noviembre: Atendida. …»
    private static function textoCerradas($cerradas)
    {
        $lineas = array();
        foreach ($cerradas as $n) {
            $lineas[] = '• Se atendió la novedad «' . self::recortar($n['descripcion'], 160) . '» – ' . $n['peaje'] . ($n['titulo'] !== '' ? ' (' . $n['titulo'] . ')' : '') .
                        ' el ' . self::diaMes($n['fecha']) . (trim($n['respuesta']) !== '' ? ': ' . self::conPunto(preg_replace('/\s*\R\s*/u', ' ', self::recortar($n['respuesta'], 300))) : '.');
        }
        return implode("\n", $lineas);
    }

    private static function textoNovedades($novedades)
    {
        $prioridades = array('ALTO' => 'prioridad alta', 'MEDIO' => 'prioridad media', 'BAJO' => 'prioridad baja');
        $lineas = array();
        foreach ($novedades as $n) {
            $lineas[] = '• ' . $n['peaje'] . ($n['titulo'] !== '' ? ' – ' . $n['titulo'] : '') .
                        (isset($prioridades[$n['prioridad']]) ? ' (' . $prioridades[$n['prioridad']] . ')' : '') . ': ' . self::conPunto(self::recortar($n['descripcion'], 300));
        }
        return implode("\n", $lineas);
    }

    //-----------------------------------------------------------------------
    // 4. Vacantes: las abiertas el último día y las que se cubrieron en el periodo
    //-----------------------------------------------------------------------
    private static function datosVacantes(&$datos, $centros, $fecha, $desde)
    {
        $proyectoDe = array();
        foreach ($centros as $centro) { $proyectoDe[(int)$centro['ID_CENTRO_OP']] = (int)$centro['ID_PROYECTO']; }
        if (!count($proyectoDe)) { return; }
        $ids = array_keys($proyectoDe);
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $filas = HanaDB::q("SELECT v.ID_VACANTE, v.ID_CENTRO_OP, c.NOM_CENTRO_OP, v.CARGO, v.MOTIVO, v.FECHA_VACANTE, v.FECHA_CIERRE, v.ESTADO_VACANTE, v.OBSERVACION,
                                   v.NOMBRE_REEMPLAZO, v.FECHA_COMPROMISO_GH, v.FECHA_ANS,
                                   (SELECT h.TEXTO FROM historial h WHERE h.MODULO = 'VACANTE' AND h.ID_REGISTRO = v.ID_VACANTE AND h.TIPO = 'SALE_CARGO'
                                     ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS NOMBRE_SALE
                              FROM vacante v INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = v.ID_CENTRO_OP
                             WHERE v.ID_CENTRO_OP IN ($marcas) AND v.FECHA_VACANTE <= ?
                               AND ((v.ESTADO_VACANTE = 'ABIERTA' AND (v.FECHA_CIERRE IS NULL OR v.FECHA_CIERRE > ?))
                                    OR (v.ESTADO_VACANTE IN ('CUBIERTA', 'CANCELADA') AND v.FECHA_CIERRE BETWEEN ? AND ?))
                             ORDER BY v.FECHA_VACANTE, c.NOM_CENTRO_OP, v.CARGO",
                           str_repeat('i', count($ids)) . 'ssss', array_merge($ids, array($fecha, $fecha, $desde, $fecha)));
        $filas = is_array($filas) ? $filas : array();
        //El cargo se escribe a mano: las formas distintas de un mismo cargo («RECOLECTOR», «Recol.») se unen
        $cargos = self::unificarNombres(array_map(function ($fila) { return self::nombreCargo($fila['CARGO']); }, $filas));
        foreach ($filas as $fila) {
            $idProyecto = $proyectoDe[(int)$fila['ID_CENTRO_OP']];
            if (!isset($datos[$idProyecto])) { continue; }
            $cargo = self::nombreCargo($fila['CARGO']);
            $datos[$idProyecto]['vacantes'][] = array('id' => (int)$fila['ID_VACANTE'], 'idProyecto' => $idProyecto, 'peaje' => self::nombrePeaje($fila['NOM_CENTRO_OP']), 'cargo' => isset($cargos[$cargo]) ? $cargos[$cargo] : $cargo,
                'motivo' => (string)$fila['MOTIVO'], 'desde' => $fila['FECHA_VACANTE'], 'estado' => $fila['ESTADO_VACANTE'],
                'reemplazo' => (string)$fila['NOMBRE_REEMPLAZO'], 'sale' => (string)$fila['NOMBRE_SALE'],
                //La fecha comprometida por Gestión Humana: si nadie la cambió, la del acuerdo de servicio
                'compromiso' => $fila['FECHA_COMPROMISO_GH'] ?: ($fila['FECHA_ANS'] ?: null),
                'cierre' => $fila['FECHA_CIERRE'] ?: null, 'observacion' => trim((string)$fila['OBSERVACION']));
        }
    }

    //Las vacantes de un proyecto que se cubrieron o se cancelaron en el periodo (las abiertas van en la tabla
    //por centro de operación): «• Se cubrió la vacante de recolector – LABERINTO con Kevin Molina (5 de noviembre).»
    private static function textoVacantes($vacantes)
    {
        $lineas = array();
        foreach ($vacantes as $v) {
            if ($v['estado'] === 'CUBIERTA') {
                $lineas[] = '• Se cubrió la vacante de ' . self::minusInicial($v['cargo']) . ' – ' . $v['peaje'] .
                            ($v['reemplazo'] !== '' ? ' con ' . self::recortar($v['reemplazo'], 80) : '') . ($v['cierre'] ? ' (' . self::diaMes($v['cierre']) . ')' : '') . '.';
            } elseif ($v['estado'] === 'CANCELADA') {
                $lineas[] = '• Se canceló la vacante de ' . self::minusInicial($v['cargo']) . ' – ' . $v['peaje'] . ($v['cierre'] ? ' (' . self::diaMes($v['cierre']) . ')' : '') .
                            ($v['observacion'] !== '' ? ': ' . self::conPunto(self::recortar($v['observacion'], 300)) : '.');
            }
        }
        return implode("\n", $lineas);
    }

    //-----------------------------------------------------------------------
    // 5. Lo programado en el cronograma para los próximos días (visitas, reuniones y
    //    demás actividades de trabajo; no los descansos ni las vacaciones)
    //-----------------------------------------------------------------------
    private static function datosAgenda(&$datos, $centros, $fecha)
    {
        $proyectoDe = array();
        foreach ($centros as $centro) { $proyectoDe[(int)$centro['ID_CENTRO_OP']] = (int)$centro['ID_PROYECTO']; }
        if (!count($proyectoDe)) { return; }
        $ids = array_keys($proyectoDe);
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $hasta = date('Y-m-d', strtotime($fecha . ' +' . self::DIAS_COMPROMISOS . ' days'));
        $filas = HanaDB::q("SELECT k.ID_CENTRO_OP, c.NOM_CENTRO_OP, k.FECHA, k.TIPO, k.DESCRIPCION, col.NOM_COLABORADOR
                              FROM cronograma k INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = k.ID_CENTRO_OP
                              LEFT JOIN colaboradores col ON col.ID_COLABORADOR = k.ID_COLABORADOR
                             WHERE k.ID_CENTRO_OP IN ($marcas) AND k.ESTADO = 'PROGRAMADA' AND k.TIPO NOT IN ('DESCANSO', 'VACACIONES')
                               AND k.FECHA > ? AND k.FECHA <= ?
                             ORDER BY k.FECHA, c.NOM_CENTRO_OP",
                           str_repeat('i', count($ids)) . 'ss', array_merge($ids, array($fecha, $hasta)));
        $tipos = HanaConfig::opciones('LISTA_TIPOS_CRONO');
        $vistos = array();
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $idProyecto = $proyectoDe[(int)$fila['ID_CENTRO_OP']];
            if (!isset($datos[$idProyecto])) { continue; }
            $tipo = isset($tipos[$fila['TIPO']]) ? $tipos[$fila['TIPO']] : self::nombreCargo($fila['TIPO']);
            $descripcion = trim((string)$fila['DESCRIPCION']);
            $gestion = ($descripcion !== '' ? self::recortar($descripcion, 120) : $tipo) . ' – ' . self::nombrePeaje($fila['NOM_CENTRO_OP']);
            //La misma actividad de varias personas el mismo día sale una vez, con todas ellas como responsables
            $clave = $fila['FECHA'] . '|' . self::llaveNombre($gestion);
            $nombre = self::nombrePersona($fila['NOM_COLABORADOR']);
            if (isset($vistos[$clave])) {
                $actividad = &$datos[$vistos[$clave][0]]['agenda'][$vistos[$clave][1]];
                if ($nombre !== '' && !in_array($nombre, $actividad['responsables'], true)) { $actividad['responsables'][] = $nombre; }
                unset($actividad);
                continue;
            }
            $vistos[$clave] = array($idProyecto, count($datos[$idProyecto]['agenda']));
            $datos[$idProyecto]['agenda'][] = array('fecha' => $fila['FECHA'], 'gestion' => $gestion, 'responsables' => $nombre !== '' ? array($nombre) : array());
        }
    }

    //-----------------------------------------------------------------------
    // El reporte como lo maneja la pantalla y el PDF (estructura de la versión 3)
    //-----------------------------------------------------------------------

    //El encabezado del último reporte guardado que tenga destinatario o firma (para no escribirlo cada día);
    //si no hay ninguno, el de fábrica
    public static function ultimoEncabezado()
    {
        $filas = HanaDB::q("SELECT TEXTO FROM historial WHERE MODULO = ? AND TIPO = ? ORDER BY FECHA DESC, ID_HISTORIAL DESC LIMIT 20",
                           'ss', array(self::MODULO, self::TIPO));
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $datos = json_decode((string)$fila['TEXTO'], true);
            if (!is_array($datos) || !isset($datos['encabezado']) || !is_array($datos['encabezado'])) { continue; }
            $encabezado = self::limpiarEncabezado($datos['encabezado']);
            if ($encabezado['destinatario'] !== '' || $encabezado['firma'] !== '') { return $encabezado; }
        }
        return self::ENCABEZADO;
    }

    private static function limpiarEncabezado($e)
    {
        $limpio = array();
        foreach (self::ENCABEZADO as $clave => $fabrica) {
            $valor = isset($e[$clave]) && is_string($e[$clave]) ? trim(str_replace("\r\n", "\n", $e[$clave])) : $fabrica;
            $limpio[$clave] = mb_substr(self::sinFabricaAnterior($clave, $valor), 0, self::LARGO_ENCABEZADO[$clave], 'UTF-8');
        }
        return $limpio;
    }

    //Un dato del encabezado que quedó como venía de fábrica antes («Doctora», «Gerente de Unidad de
    //Infraestructura»…) pasa al de fábrica de ahora; lo que la dirección escribió queda igual
    private static function sinFabricaAnterior($clave, $valor)
    {
        return isset(self::ENCABEZADO_ANTERIOR[$clave]) && $valor === self::ENCABEZADO_ANTERIOR[$clave] ? self::ENCABEZADO[$clave] : $valor;
    }

    //Un texto que llegó de la pantalla o de lo guardado: sin espacios de más y sin pasar del largo
    private static function textoLimpio($valor, $largo)
    {
        $valor = is_string($valor) || is_numeric($valor) ? trim(str_replace("\r\n", "\n", (string)$valor)) : '';
        return mb_strlen($valor, 'UTF-8') > $largo ? mb_substr($valor, 0, $largo, 'UTF-8') : $valor;
    }

    //Revisa y limpia un reporte (de la pantalla o de lo guardado). Solo quedan los apartados que tienen algo
    //escrito y los proyectos que están en $proyectos ([id => nombre]) o, con proyecto 0, los generales.
    //Si $estricto, en vez de recortar avisa qué pasa del largo (para guardar): devuelve [reporte, error]
    public static function normalizar($entrada, $proyectos, $estricto = false)
    {
        $error = null;
        $limite = function ($valor, $largo, $nombre) use ($estricto, &$error) {
            $valor = is_string($valor) || is_numeric($valor) ? trim(str_replace("\r\n", "\n", (string)$valor)) : '';
            if (mb_strlen($valor, 'UTF-8') > $largo) {
                if ($estricto && $error === null) { $error = '«' . $nombre . '» pasa de ' . $largo . ' caracteres. Acórtalo un poco.'; }
                $valor = mb_substr($valor, 0, $largo, 'UTF-8');
            }
            return $valor;
        };
        //El proyecto de un apartado: 0 si es general, y -1 si es de un proyecto que no está en $proyectos (de otro
        //director): esos no se toman aquí; al guardar, paraGuardar los conserva tal como estaban
        $proyectoValido = function ($id) use ($proyectos) { $id = (int)$id; return $id <= 0 ? 0 : (isset($proyectos[$id]) ? $id : -1); };
        $lista = function ($items, $conProyecto, $nombreSeccion) use ($limite, $proyectoValido) {
            $salida = array();
            foreach (is_array($items) ? array_slice(array_values($items), 0, self::MAX_APARTADOS) : array() as $item) {
                if (!is_array($item)) { continue; }
                $titulo = $limite(isset($item['titulo']) ? $item['titulo'] : '', self::LARGO_TITULO, 'Título en ' . $nombreSeccion);
                $texto = $limite(isset($item['texto']) ? $item['texto'] : '', self::LARGO_TEXTO, ($titulo !== '' ? $titulo : $nombreSeccion));
                if ($titulo === '' && $texto === '') { continue; }
                $nuevo = array('titulo' => $titulo, 'texto' => $texto);
                if ($conProyecto) {
                    $proyecto = $proyectoValido(isset($item['proyecto']) ? $item['proyecto'] : 0);
                    if ($proyecto < 0) { continue; }
                    $nuevo = array('proyecto' => $proyecto) + $nuevo;
                }
                $salida[] = $nuevo;
            }
            return $salida;
        };
        //Sin encabezado (por ejemplo, un reporte viejo), va el del último informe guardado
        $e = isset($entrada['encabezado']) && is_array($entrada['encabezado']) ? $entrada['encabezado'] : self::ultimoEncabezado();
        $encabezado = array();
        foreach (self::ENCABEZADO as $clave => $fabrica) {
            $valor = $limite(isset($e[$clave]) ? $e[$clave] : '', self::LARGO_ENCABEZADO[$clave], 'Encabezado');
            $encabezado[$clave] = mb_substr(self::sinFabricaAnterior($clave, $valor), 0, self::LARGO_ENCABEZADO[$clave], 'UTF-8');
        }
        $porProyecto = array();
        $recibidos = isset($entrada['proyectos']) && is_array($entrada['proyectos']) ? $entrada['proyectos'] : array();
        foreach ($proyectos as $id => $nombre) {
            $items = isset($recibidos[$id]['apartados']) ? $recibidos[$id]['apartados'] : (isset($recibidos[(string)$id]['apartados']) ? $recibidos[(string)$id]['apartados'] : array());
            $porProyecto[$id] = array('apartados' => $lista($items, false, $nombre));
        }
        //Compromisos y seguimiento: {proyecto, gestion, accion, responsable, fecha (AAAA-MM-DD o null), cumplido,
        //detalle (lo arma el sistema), origen ('tema:N', 'agenda' o 'manual')}. Los guardados antes traían la
        //fecha escrita («25 de noviembre»): se pasa a fecha si se puede
        $compromisos = array();
        foreach (isset($entrada['compromisos']) && is_array($entrada['compromisos']) ? array_slice(array_values($entrada['compromisos']), 0, self::MAX_APARTADOS) : array() as $c) {
            if (!is_array($c)) { continue; }
            $gestion = $limite(isset($c['gestion']) ? $c['gestion'] : '', 300, 'Asunto de un compromiso');
            $accion = $limite(isset($c['accion']) ? $c['accion'] : '', 600, 'Qué se está haciendo (compromisos)');
            $responsable = $limite(isset($c['responsable']) ? $c['responsable'] : '', 120, 'Responsable de un compromiso');
            $fechaObjetivo = HanaVal::fecha(isset($c['fecha']) ? (string)$c['fecha'] : '');
            if ($fechaObjetivo === '' && isset($c['fecha'])) { $fechaObjetivo = self::fechaDeTexto((string)$c['fecha'], isset($entrada['fecha']) ? (string)$entrada['fecha'] : ''); }
            if ($gestion === '' && $accion === '') { continue; }
            $proyecto = $proyectoValido(isset($c['proyecto']) ? $c['proyecto'] : 0);
            if ($proyecto < 0) { continue; }
            $origen = isset($c['origen']) && preg_match('/^(tema:\d+|agenda|manual)$/', (string)$c['origen']) ? (string)$c['origen'] : 'manual';
            $compromisos[] = array('proyecto' => $proyecto, 'gestion' => $gestion, 'accion' => $accion, 'responsable' => $responsable,
                                   'fecha' => $fechaObjetivo !== '' ? $fechaObjetivo : null, 'cumplido' => !empty($c['cumplido']) && $c['cumplido'] !== 'false',
                                   'detalle' => self::textoLimpio(isset($c['detalle']) ? $c['detalle'] : '', 400), 'origen' => $origen);
        }
        //Asuntos que requieren decisión de la Gerencia: {proyecto, asunto, impacto, gestiones, decision}
        $escalamientos = array();
        foreach (isset($entrada['escalamientos']) && is_array($entrada['escalamientos']) ? array_slice(array_values($entrada['escalamientos']), 0, self::MAX_APARTADOS) : array() as $x) {
            if (!is_array($x)) { continue; }
            $fila = array('proyecto' => $proyectoValido(isset($x['proyecto']) ? $x['proyecto'] : 0));
            if ($fila['proyecto'] < 0) { continue; }
            foreach (array('asunto' => array(300, 'Asunto a escalar'), 'impacto' => array(1500, 'Impacto contractual'), 'gestiones' => array(1500, 'Gestiones adelantadas'),
                           'decision' => array(1000, 'Decisión requerida')) as $clave => $l) {
                $fila[$clave] = $limite(isset($x[$clave]) ? $x[$clave] : '', $l[0], $l[1]);
            }
            if ($fila['asunto'] === '' && $fila['decision'] === '' && $fila['impacto'] === '' && $fila['gestiones'] === '') { continue; }
            $escalamientos[] = $fila;
        }
        //Avance de los planes de trabajo (por ejemplo, el de RDV, que no es un proyecto de HANA):
        //{proyecto (escrito), entregable, programado y real (0 a 100, o null), fecha (AAAA-MM-DD o null)}
        $planes = array();
        $porcentaje = function ($valor) {
            $valor = str_replace(array('%', ','), array('', '.'), trim((string)$valor));
            return is_numeric($valor) ? max(0, min(100, round((float)$valor, 1))) : null;
        };
        foreach (isset($entrada['planes']) && is_array($entrada['planes']) ? array_slice(array_values($entrada['planes']), 0, self::MAX_APARTADOS) : array() as $x) {
            if (!is_array($x)) { continue; }
            $entregable = $limite(isset($x['entregable']) ? $x['entregable'] : '', 300, 'Entregable del plan de trabajo');
            if ($entregable === '') { continue; }
            $fechaPlan = HanaVal::fecha(isset($x['fecha']) ? (string)$x['fecha'] : '');
            $planes[] = array('proyecto' => self::textoLimpio(isset($x['proyecto']) ? $x['proyecto'] : '', 60), 'entregable' => $entregable,
                              'programado' => $porcentaje(isset($x['programado']) ? $x['programado'] : ''), 'real' => $porcentaje(isset($x['real']) ? $x['real'] : ''),
                              'fecha' => $fechaPlan !== '' ? $fechaPlan : null);
        }
        //La tabla de vacantes: si el reporte no la trae (los guardados antes de que existiera) queda en null,
        //y quien lo muestre la llena con lo del sistema
        $vacantes = null;
        if (isset($entrada['vacantes']) && is_array($entrada['vacantes'])) {
            $vacantes = array();
            foreach (array_slice(array_values($entrada['vacantes']), 0, self::MAX_FILAS_VACANTES) as $v) {
                if (!is_array($v)) { continue; }
                $centro = self::textoLimpio(isset($v['centro']) ? $v['centro'] : '', 120);
                $total = isset($v['total']) && is_numeric($v['total']) ? max(0, min(999, (int)$v['total'])) : 0;
                if ($centro === '' || $total === 0) { continue; }
                $antigua = HanaVal::fecha(isset($v['antigua']) ? (string)$v['antigua'] : '');
                $compromiso = HanaVal::fecha(isset($v['compromiso']) ? (string)$v['compromiso'] : '');
                $proyecto = $proyectoValido(isset($v['proyecto']) ? $v['proyecto'] : 0);
                if ($proyecto < 0) { continue; }
                //Los guardados antes traían todos los cargos («Recolector (9) · Controlador (2)»): queda el primero
                $cargo = isset($v['cargo']) ? $v['cargo'] : (isset($v['cargos']) ? preg_replace('/\s*(\(\d+\))?\s*(·.*)?$/u', '', (string)$v['cargos']) : '');
                $vacantes[] = array('proyecto' => $proyecto, 'centro' => $centro, 'total' => $total, 'antigua' => $antigua !== '' ? $antigua : null,
                                    'cargo' => self::textoLimpio($cargo, self::LARGO_CARGO), 'compromiso' => $compromiso !== '' ? $compromiso : null);
            }
        }
        $reporte = array(
            'encabezado'   => $encabezado,
            'resultados'   => self::conHorasPendientes($limite(isset($entrada['resultados']) ? $entrada['resultados'] : '', self::LARGO_TEXTO, 'Promesa de valor')),
            'prioritarias' => $lista(isset($entrada['prioritarias']) ? $entrada['prioritarias'] : array(), true, 'Gestiones prioritarias'),
            'proyectos'    => $porProyecto,
            'transversal'  => $lista(isset($entrada['transversal']) ? $entrada['transversal'] : array(), true, 'Gestión transversal'),
            'vacantes'     => $vacantes,
            'notaVacantes' => $limite(isset($entrada['notaVacantes']) ? $entrada['notaVacantes'] : '', self::LARGO_NOTA_VACANTES, 'Nota de las vacantes'),
            'compromisos'  => $compromisos,
            'escalamientos' => $escalamientos,
            'planes'       => $planes
        );
        return $estricto ? array($reporte, $error) : $reporte;
    }

    //Una fecha escrita en un reporte viejo («25 de noviembre», «3 de dic de 2026») como AAAA-MM-DD, o ''.
    //Sin año, el del reporte ($fechaReporte) o el de hoy
    public static function fechaDeTexto($texto, $fechaReporte = '')
    {
        if (!preg_match('/(\d{1,2})\s+de\s+(\p{L}+)(?:\s+de\s+(\d{4}))?/u', mb_strtolower((string)$texto, 'UTF-8'), $m)) { return ''; }
        $mes = 0;
        foreach (self::$MESES as $numero => $nombre) { if ($nombre !== '' && mb_substr($nombre, 0, 3, 'UTF-8') === mb_substr($m[2], 0, 3, 'UTF-8')) { $mes = $numero; break; } }
        $anio = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : (int)substr($fechaReporte !== '' ? $fechaReporte : date('Y-m-d'), 0, 4);
        return $mes && checkdate($mes, (int)$m[1], $anio) ? sprintf('%04d-%02d-%02d', $anio, $mes, (int)$m[1]) : '';
    }

    //-----------------------------------------------------------------------
    // Un reporte nuevo (sin nada guardado ese día) sigue el seguimiento del último guardado antes de
    // $fecha: se pasan los planes de trabajo, los asuntos para la Gerencia y los compromisos escritos a
    // mano que no se han cumplido.
    // Así no hay que volver a escribir todo: solo se actualiza lo que cambió
    //-----------------------------------------------------------------------
    public static function conSeguimientoAnterior($reporte, $fecha, $proyectos)
    {
        $fila = HanaDB::fila("SELECT TEXTO FROM historial WHERE MODULO = ? AND TIPO = ? AND ID_REGISTRO < ? ORDER BY ID_REGISTRO DESC, FECHA DESC, ID_HISTORIAL DESC LIMIT 1",
                             'ssi', array(self::MODULO, self::TIPO, self::numeroDia($fecha)));
        $anterior = $fila ? self::deGuardado(json_decode((string)$fila['TEXTO'], true), $proyectos) : null;
        if (!$anterior) { return $reporte; }
        $reporte['planes'] = $anterior['planes'];
        $reporte['escalamientos'] = $anterior['escalamientos'];
        //Los escritos a mano van primero: son los de la Dirección y no se pueden quedar por fuera si la
        //lista llega al máximo de renglones
        $aMano = array();
        foreach ($anterior['compromisos'] as $c) {
            if ($c['origen'] === 'manual' && !$c['cumplido']) { $aMano[] = $c; }
        }
        $reporte['compromisos'] = array_slice(array_merge($aMano, $reporte['compromisos']), 0, self::MAX_APARTADOS);
        //Lo que la Dirección escribió en un compromiso que viene de un tema (qué se hace, quién) se conserva
        //si ese día lo cambió a mano
        $escrito = array();
        foreach ($anterior['compromisos'] as $c) { if (strpos($c['origen'], 'tema:') === 0) { $escrito[$c['origen']] = $c; } }
        foreach ($reporte['compromisos'] as $i => $c) {
            if (!isset($escrito[$c['origen']])) { continue; }
            if ($c['responsable'] === '' && $escrito[$c['origen']]['responsable'] !== '') { $reporte['compromisos'][$i]['responsable'] = $escrito[$c['origen']]['responsable']; }
        }
        return $reporte;
    }

    //Lo guardado, como reporte de la versión 3 con los proyectos de la persona (y sus nombres de hoy).
    //Los de la versión 2 (puntos por proyecto) se convierten
    //Los reportes guardados antes decían «8 de 16 horas ejecutadas, 8 pendientes.»: se leen como «8 horas pendientes.»
    private static function conHorasPendientes($texto)
    {
        if (!is_string($texto) || $texto === '') { return $texto; }
        return preg_replace_callback('/(ejecutadas, )([\d.,]+) (pendientes?)\./u', function ($m) {
            return $m[1] . $m[2] . ($m[2] === '1' ? ' hora pendiente.' : ' horas pendientes.');
        }, $texto);
    }

    public static function deGuardado($datos, $proyectos)
    {
        if (!is_array($datos)) { return null; }
        if (!isset($datos['version']) || (int)$datos['version'] < self::VERSION) { $datos = self::deVersion2($datos, $proyectos); }
        //Un encabezado guardado sin destinatario ni firma se completa con el del último informe
        $e = isset($datos['encabezado']) && is_array($datos['encabezado']) ? $datos['encabezado'] : array();
        if (trim(isset($e['destinatario']) ? (string)$e['destinatario'] : '') === '' && trim(isset($e['firma']) ? (string)$e['firma'] : '') === '') {
            unset($datos['encabezado']);
        }
        return self::normalizar($datos, $proyectos);
    }

    //Un reporte de la versión 2 (cada proyecto con sus puntos y unas notas adicionales) con la estructura de hoy:
    //  - la promesa de valor de cada proyecto va a «Resultados de la operación», con el nombre del proyecto;
    //  - los demás puntos (oficios, novedades y los que se hayan agregado) van como apartados del proyecto;
    //  - las notas adicionales van a la gestión transversal.
    //La disponibilidad de flota (FLOTA) no se pasa: no hace parte del informe
    public static function deVersion2($datos, $proyectos)
    {
        $titulos = isset($datos['puntos']) && is_array($datos['puntos']) ? $datos['puntos'] : array();
        $resultados = array(); $porProyecto = array();
        foreach (isset($datos['proyectos']) && is_array($datos['proyectos']) ? $datos['proyectos'] : array() as $id => $p) {
            $textos = isset($p['textos']) && is_array($p['textos']) ? $p['textos'] : array();
            $nombre = isset($proyectos[(int)$id]) ? $proyectos[(int)$id] : (isset($p['nombre']) ? (string)$p['nombre'] : '');
            if (isset($textos['PROMESA']) && trim($textos['PROMESA']) !== '') { $resultados[] = $nombre . ': ' . trim($textos['PROMESA']); }
            $apartados = array();
            foreach ($textos as $codigo => $texto) {
                if ($codigo === 'PROMESA' || $codigo === 'FLOTA' || trim((string)$texto) === '') { continue; }
                $apartados[] = array('titulo' => isset($titulos[$codigo]) ? (string)$titulos[$codigo] : (string)$codigo, 'texto' => trim((string)$texto));
            }
            $porProyecto[(int)$id] = array('apartados' => $apartados);
        }
        $transversal = array();
        if (isset($datos['otros']) && trim((string)$datos['otros']) !== '') {
            $transversal[] = array('proyecto' => 0, 'titulo' => 'Notas adicionales', 'texto' => trim((string)$datos['otros']));
        }
        return array('version' => self::VERSION, 'fecha' => isset($datos['fecha']) ? $datos['fecha'] : '', 'desde' => isset($datos['desde']) ? $datos['desde'] : '',
                     'encabezado' => self::ultimoEncabezado(), 'resultados' => implode("\n\n", $resultados), 'prioritarias' => array(),
                     'proyectos' => $porProyecto, 'transversal' => $transversal, 'compromisos' => array());
    }

    //Lo que se guarda: lo de la persona, más lo que traía la versión anterior de los proyectos que ella
    //no tiene (otro director con otros peajes). $anterior es el arreglo guardado (cualquier versión)
    public static function paraGuardar($fecha, $desde, $reporte, $proyectos, $anterior)
    {
        $otrosNombres = array(); $otrosProyectos = array();
        $previo = null;
        if (is_array($anterior)) {
            $nombresPrevios = isset($anterior['nombres']) && is_array($anterior['nombres']) ? $anterior['nombres'] : array();
            if (!isset($anterior['version']) || (int)$anterior['version'] < self::VERSION) {
                foreach (isset($anterior['proyectos']) && is_array($anterior['proyectos']) ? $anterior['proyectos'] : array() as $id => $p) {
                    if (isset($p['nombre'])) { $nombresPrevios[$id] = (string)$p['nombre']; }
                }
                $anterior = self::deVersion2($anterior, array());
            }
            foreach ($nombresPrevios as $id => $nombre) { if (!isset($proyectos[(int)$id])) { $otrosNombres[(int)$id] = (string)$nombre; } }
            $previo = self::normalizar($anterior, $otrosNombres);
        }
        $ajeno = function ($item) use ($otrosNombres) { return (int)$item['proyecto'] > 0 && isset($otrosNombres[(int)$item['proyecto']]); };
        if (!is_array($reporte['vacantes'])) { $reporte['vacantes'] = array(); }
        if ($previo && !is_array($previo['vacantes'])) { $previo['vacantes'] = array(); }
        foreach (array('prioritarias', 'transversal', 'vacantes', 'compromisos', 'escalamientos') as $seccion) {
            if ($previo) { $reporte[$seccion] = array_merge($reporte[$seccion], array_values(array_filter($previo[$seccion], $ajeno))); }
        }
        if ($previo) { foreach ($previo['proyectos'] as $id => $p) { if (count($p['apartados'])) { $otrosProyectos[$id] = $p; } } }
        //Los resultados (las cifras de todos los centros) y el encabezado son de todo el informe: si la versión
        //anterior tiene proyectos que esta persona no ve, se conservan los de esa versión, para que quien ve
        //menos proyectos no cambie las cifras de todos con las de los suyos
        if ($previo && count($otrosNombres) && trim((string)$previo['resultados']) !== '') {
            $reporte['resultados'] = $previo['resultados'];
            $reporte['encabezado'] = $previo['encabezado'];
            $reporte['notaVacantes'] = $previo['notaVacantes'];
            $reporte['planes'] = $previo['planes'];
        }
        //"desde" va al principio (justo después de "fecha"): así se reconoce el periodo sin abrir todo el texto
        return array('version' => self::VERSION, 'fecha' => $fecha, 'desde' => $desde,
                     'encabezado' => $reporte['encabezado'], 'resultados' => $reporte['resultados'],
                     'prioritarias' => $reporte['prioritarias'], 'proyectos' => $reporte['proyectos'] + $otrosProyectos,
                     'transversal' => $reporte['transversal'], 'vacantes' => $reporte['vacantes'], 'notaVacantes' => $reporte['notaVacantes'],
                     'compromisos' => $reporte['compromisos'],
                     'escalamientos' => $reporte['escalamientos'], 'planes' => $reporte['planes'], 'nombres' => $proyectos + $otrosNombres);
    }

    //Las cifras de la promesa para el PDF, leídas del texto de los resultados tal como quedó (si la dirección
    //corrigió el porcentaje, el PDF muestra el corregido). Lo que el texto no diga se toma de $totales (la base)
    public static function cifrasResultados($texto, $totales)
    {
        $medidos = isset($totales['medidos']) ? $totales['medidos'] : $totales['peajes'];
        $c = array('pct' => $totales['pct'], 'cumplen' => $medidos ? $totales['cumplen'] : null, 'peajes' => $medidos ? $totales['peajes'] : null);
        //Se acepta como se escriba: «cumplimiento del 98,5 %», «Cumplimiento: 98.5%», «cumplimiento fue de 98 %»,
        //«98,5 % de cumplimiento»; «18 de 19 peajes», «18/19 Peajes», «18 de 19 estaciones», «18 de 19 pjs»
        $texto = (string)$texto;
        if (preg_match('/cumplimiento\s+real(?:\s+de(?:\s+las)?\s+horas)?\W{0,3}(\d{1,3}(?:[.,]\d+)?)\s*%/iu', $texto, $m)
            || preg_match('/cumplimiento\W{0,3}(?:(?:fue|es|qued[oó])\s+)?(?:(?:del?|un|en)\s+)?(?:el\s+)?(\d{1,3}(?:[.,]\d+)?)\s*%/iu', $texto, $m)
            || preg_match('/(\d{1,3}(?:[.,]\d+)?)\s*%\s*(?:de\s+)?cumplimiento/iu', $texto, $m)) { $c['pct'] = (float)str_replace(',', '.', $m[1]); }
        if (preg_match('/(\d+)\s*(?:de(?:\s+los)?|\/)\s*(\d+)\s+(?:peajes?|centros?|estaci(?:o|ó)n(?:es)?|pjs?)\b/iu', $texto, $m)) { $c['cumplen'] = (int)$m[1]; $c['peajes'] = (int)$m[2]; }
        return $c;
    }

    //-----------------------------------------------------------------------
    // El cumplimiento de cada proyecto como lo dice el texto de los resultados, para la gráfica
    // del PDF. Se lee la línea «Por proyecto: ICCU 97,5 % (4 de 5 peajes) · ACCENORTE 96,6 % …»
    // y también los renglones que empiezan con el nombre de un proyecto («ICCU: 97,5 %»). El
    // nombre se reconoce aunque esté en minúscula, sin tildes o abreviado.
    // Devuelve [lo leído por proyecto => {pct, cumplen, peajes, pendiente}, el texto sin esos renglones]
    //-----------------------------------------------------------------------
    public static function proyectosDelTexto($texto, $proyectos)
    {
        $llaves = array();
        foreach ($proyectos as $id => $nombre) { $llaves[(int)$id] = self::llaveNombre($nombre); }
        $deQuien = function ($nombre) use ($llaves) {
            $llave = self::llaveNombre($nombre);
            if ($llave === '') { return null; }
            $id = array_search($llave, $llaves, true);
            if ($id !== false) { return $id; }
            $parecidos = array_keys(array_filter($llaves, function ($l) use ($llave) {
                return strpos(' ' . $l . ' ', ' ' . $llave . ' ') !== false || strpos(' ' . $llave . ' ', ' ' . $l . ' ') !== false || self::mismaPalabra($llave, $l);
            }));
            return count($parecidos) === 1 ? $parecidos[0] : null;
        };
        $leer = function ($pedazo) use ($deQuien) {
            $pedazo = trim($pedazo, " \t.;");
            if (preg_match('/^(.+?)\s*:?\s+(\d{1,3}(?:[.,]\d+)?)\s*%\s*(?:\(?\s*(\d+)\s*(?:de|\/)\s*(\d+)[^)]*\)?)?\s*$/iu', $pedazo, $m)) {
                $id = $deQuien($m[1]);
                if ($id === null) { return null; }
                return array($id, array('pct' => (float)str_replace(',', '.', $m[2]), 'cumplen' => isset($m[3]) && $m[3] !== '' ? (int)$m[3] : null,
                                        'peajes' => isset($m[4]) && $m[4] !== '' ? (int)$m[4] : null, 'pendiente' => false));
            }
            if (preg_match('/^(.+?)\s*:?\s+(pendiente|sin promesa|sin registro)\b/iu', $pedazo, $m)) {
                $id = $deQuien($m[1]);
                return $id === null ? null : array($id, array('pct' => null, 'cumplen' => null, 'peajes' => null, 'pendiente' => mb_strtolower($m[2], 'UTF-8') === 'pendiente'));
            }
            return null;
        };
        $leidos = array(); $quedan = array();
        foreach (preg_split('/\r?\n/', (string)$texto) as $renglon) {
            $limpio = preg_replace('/^\s*[•\-\*·]\s*/u', '', $renglon);
            if (preg_match('/^por\s+proyectos?\s*:?\s*(.*)$/iu', $limpio, $m)) {
                foreach (preg_split('/\s*[·;|]\s*/u', $m[1]) as $pedazo) {
                    $l = $leer($pedazo);
                    if ($l) { $leidos[$l[0]] = $l[1]; }
                }
                continue;
            }
            $l = strpos($limpio, '(') === false ? $leer($limpio) : null;
            if ($l) { $leidos[$l[0]] = $l[1]; continue; }
            $quedan[] = $renglon;
        }
        return array($leidos, trim(implode("\n", $quedan)));
    }

    //-----------------------------------------------------------------------
    // Los peajes de la tabla de vacantes se pueden escribir a mano («andes», «Peaje Andes»,
    // «UNISAB.»): cada uno se cambia por el nombre como está en el sistema si se reconoce
    // sin dudas entre los centros de la persona ($centros: filas con ID_CENTRO_OP,
    // NOM_CENTRO_OP e ID_PROYECTO), primero entre los de su proyecto. Si la fila no tenía
    // proyecto, queda con el del peaje. Lo que no se reconoce queda como se escribió.
    // Si al final dos filas son el mismo peaje del mismo proyecto, quedan en una sola
    //-----------------------------------------------------------------------
    public static function corregirCentros($reporte, $centros)
    {
        if (!isset($reporte['vacantes']) || !is_array($reporte['vacantes'])) { return $reporte; }
        $llaveCentro = function ($nombre) { return preg_replace('/^peaje\s+/', '', self::llaveNombre($nombre)); };
        $oficiales = array();
        foreach ($centros as $centro) {
            $oficiales[] = array('llave' => $llaveCentro($centro['NOM_CENTRO_OP']), 'nombre' => self::nombrePeaje($centro['NOM_CENTRO_OP']), 'proyecto' => (int)$centro['ID_PROYECTO']);
        }
        foreach ($reporte['vacantes'] as $i => $v) {
            $llave = $llaveCentro($v['centro']);
            if ($llave === '') { continue; }
            $buscar = function ($soloProyecto) use ($oficiales, $llave, $v) {
                $iguales = array(); $parecidos = array();
                foreach ($oficiales as $o) {
                    if ($soloProyecto && $o['proyecto'] !== (int)$v['proyecto']) { continue; }
                    if ($o['llave'] === $llave) { $iguales[] = $o; }
                    elseif (strlen($llave) < strlen($o['llave']) && self::mismaPalabra($llave, $o['llave'])) { $parecidos[] = $o; }
                }
                if (count($iguales) === 1) { return $iguales[0]; }
                return !count($iguales) && count($parecidos) === 1 ? $parecidos[0] : null;
            };
            //Primero entre los peajes de su proyecto; si no está ahí, entre todos (y la fila pasa al proyecto del peaje)
            $encontrado = (int)$v['proyecto'] > 0 ? $buscar(true) : null;
            if (!$encontrado) { $encontrado = $buscar(false); }
            if (!$encontrado) { continue; }
            $reporte['vacantes'][$i]['centro'] = $encontrado['nombre'];
            $reporte['vacantes'][$i]['proyecto'] = $encontrado['proyecto'];
        }
        //Filas repetidas: se suman las vacantes; quedan la fecha más antigua (con su cargo) y la fecha
        //comprometida más cercana
        $unidas = array();
        foreach ($reporte['vacantes'] as $v) {
            $llave = (int)$v['proyecto'] . '|' . $llaveCentro($v['centro']);
            if (!isset($unidas[$llave])) { $unidas[$llave] = $v; continue; }
            $u = &$unidas[$llave];
            $u['total'] = min(999, (int)$u['total'] + (int)$v['total']);
            if ((string)$v['antigua'] !== '' && ((string)$u['antigua'] === '' || $v['antigua'] < $u['antigua'])) {
                $u['antigua'] = $v['antigua'];
                if ((string)$v['cargo'] !== '') { $u['cargo'] = $v['cargo']; }
            }
            if ((string)$u['cargo'] === '') { $u['cargo'] = $v['cargo']; }
            if ((string)$v['compromiso'] !== '' && ((string)$u['compromiso'] === '' || $v['compromiso'] < $u['compromiso'])) { $u['compromiso'] = $v['compromiso']; }
            unset($u);
        }
        $reporte['vacantes'] = array_values($unidas);
        return $reporte;
    }

    //-----------------------------------------------------------------------
    // Guardado (historial)
    //-----------------------------------------------------------------------

    //El primer día del periodo de un reporte guardado. Si no lo trae, o es igual al último día,
    //el reporte es de un solo día
    public static function desdeDe($datos, $hasta)
    {
        $desde = is_array($datos) && isset($datos['desde']) ? HanaVal::fecha((string)$datos['desde']) : '';
        return ($desde !== '' && $desde < $hasta) ? $desde : $hasta;
    }

    //Lo mismo, leído del comienzo del texto guardado (sin decodificar todo el JSON: el reporte puede ser
    //grande). El JSON lleva "desde" al principio, justo después de "fecha"
    private static function desdeDelTexto($inicio, $hasta)
    {
        $desde = preg_match('/"desde"\s*:\s*"(\d{4}-\d{2}-\d{2})"/', (string)$inicio, $m) ? $m[1] : '';
        return ($desde !== '' && $desde < $hasta) ? $desde : $hasta;
    }

    //Lo guardado de un periodo (o de un día): ['datos' => arreglo, 'quien' => nombre, 'fecha' => cuándo] o null.
    //Cada reporte se identifica por su último día (ID_REGISTRO) y su primer día ("desde" dentro del texto);
    //de cada uno vale la versión más reciente (las anteriores quedan como trazabilidad)
    public static function guardado($fecha, $desde = null)
    {
        $desde = ($desde !== null && $desde !== '' && $desde < $fecha) ? $desde : $fecha;
        $filas = HanaDB::q("SELECT h.ID_HISTORIAL, LEFT(h.TEXTO, 120) AS INICIO
                              FROM historial h
                             WHERE h.MODULO = ? AND h.ID_REGISTRO = ? AND h.TIPO = ?
                             ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC",
                           'sis', array(self::MODULO, self::numeroDia($fecha), self::TIPO));
        foreach (is_array($filas) ? $filas : array() as $fila) {
            if (self::desdeDelTexto($fila['INICIO'], $fecha) !== $desde) { continue; } //es de otro periodo con el mismo último día
            $guardada = HanaDB::fila("SELECT h.TEXTO, h.FECHA, col.NOM_COLABORADOR AS QUIEN
                                        FROM historial h LEFT JOIN colaboradores col ON col.ID_COLABORADOR = h.ID_COLABORADOR
                                       WHERE h.ID_HISTORIAL = ?", 'i', array((int)$fila['ID_HISTORIAL']));
            $datos = $guardada ? json_decode((string)$guardada['TEXTO'], true) : null;
            if (!is_array($datos)) { return null; }
            return array('datos' => $datos, 'quien' => $guardada['QUIEN'], 'fecha' => $guardada['FECHA']);
        }
        return null;
    }

    //¿Cabe el reporte en historial.TEXTO? (TEXT: 65.535 bytes)
    public static function cabe($datos)
    {
        $texto = json_encode($datos, JSON_UNESCAPED_UNICODE);
        return $texto !== false && strlen($texto) <= self::LARGO_TOTAL;
    }

    //Guarda el reporte de un periodo (o de un día). Nunca modifica ni borra filas del historial: si el
    //reporte cambió, agrega una fila nueva (la más reciente es la que vale); si quedó igual a la última
    //versión de ESE periodo, no hace nada. Devuelve true si quedó guardado
    public static function guardar($fecha, $datos, $idColaborador, $ahora)
    {
        if (!self::cabe($datos)) { return false; }
        $texto = json_encode($datos, JSON_UNESCAPED_UNICODE);
        $ultima = self::guardado($fecha, self::desdeDe($datos, $fecha));
        if ($ultima && json_encode($ultima['datos'], JSON_UNESCAPED_UNICODE) === $texto) { return true; } //no cambió nada
        return HanaDB::q("INSERT INTO historial (MODULO, ID_REGISTRO, TIPO, TEXTO, ID_COLABORADOR, FECHA) VALUES (?, ?, ?, ?, NULLIF(?, 0), ?)",
                         'sissis', array(self::MODULO, self::numeroDia($fecha), self::TIPO, $texto, (int)$idColaborador, $ahora)) === true;
    }

    //Los meses que tienen al menos un día guardado, del más reciente al más viejo: ["2026-11", "2026-10", ...].
    //Son pocos (doce por año): la pantalla los muestra en una lista y, del mes elegido, sus días
    public static function mesesGuardados()
    {
        $filas = HanaDB::q("SELECT DISTINCT FLOOR(ID_REGISTRO / 100) AS MES FROM historial WHERE MODULO = ? AND TIPO = ? ORDER BY MES DESC",
                           'ss', array(self::MODULO, self::TIPO));
        $meses = array();
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $n = (string)(int)$fila['MES'];
            if (strlen($n) === 6) { $meses[] = substr($n, 0, 4) . '-' . substr($n, 4, 2); }
        }
        return $meses;
    }

    //Los reportes guardados de un mes (por su último día), del primero al último:
    //[["desde" => "2026-11-10", "hasta" => "2026-11-14"], ["desde" => "2026-11-14", "hasta" => "2026-11-14"], ...]
    public static function guardadosMes($mes)
    {
        $inicio = $mes . '-01'; $fin = date('Y-m-t', strtotime($inicio));
        $filas = HanaDB::q("SELECT DISTINCT ID_REGISTRO, LEFT(TEXTO, 120) AS INICIO FROM historial
                             WHERE MODULO = ? AND TIPO = ? AND ID_REGISTRO BETWEEN ? AND ?",
                           'ssii', array(self::MODULO, self::TIPO, self::numeroDia($inicio), self::numeroDia($fin)));
        $periodos = array();
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $n = (string)$fila['ID_REGISTRO'];
            if (strlen($n) !== 8) { continue; }
            $hasta = substr($n, 0, 4) . '-' . substr($n, 4, 2) . '-' . substr($n, 6, 2);
            $desde = self::desdeDelTexto($fila['INICIO'], $hasta);
            $periodos[$desde . '|' . $hasta] = array('desde' => $desde, 'hasta' => $hasta);
        }
        usort($periodos, function ($a, $b) { return strcmp($a['hasta'] . $a['desde'], $b['hasta'] . $b['desde']); });
        return array_values($periodos);
    }
}
