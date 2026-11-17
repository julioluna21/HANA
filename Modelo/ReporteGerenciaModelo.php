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
                 Cumplimiento de la promesa de valor del día (o del periodo) de
                 todos los peajes, y el detalle de los que no cumplieron (pv_dia)
    2. GESTIONES PRIORITARIAS Y COMPROMISOS CON CLIENTES
                 Un apartado «PROYECTO | Asunto» por cada tema con el cliente que
                 se movió en el periodo y por cada oficio o comunicación en gestión
    3. AVANCES DE GESTIÓN POR PROYECTO
                 Por proyecto: los temas internos (con Regency) que se movieron y
                 las novedades reportadas en sus peajes
    4. GESTIÓN TRANSVERSAL | TALENTO HUMANO Y SST
                 Los temas de personal (clase PERSONAL) y las vacantes en proceso
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

class ReporteGerencia
{
    const MODULO  = 'GERENCIA';
    const TIPO    = 'REPORTE';
    const VERSION = 3;
    const LARGO_TEXTO  = 4000;   //caracteres del texto de cada apartado (y de los resultados)
    const LARGO_TITULO = 200;    //caracteres del título de cada apartado
    const MAX_APARTADOS = 80;    //apartados por sección (y renglones de compromisos)
    const LARGO_TOTAL = 60000;   //bytes del reporte completo (historial.TEXTO es TEXT: 65.535 bytes)
    const DIAS_COMPROMISOS = 30; //cuántos días hacia adelante se buscan compromisos en el cronograma

    //Los títulos de las secciones, como van en el informe
    const SECCIONES = array(
        'resultados'   => 'RESULTADOS DE LA OPERACIÓN',
        'prioritarias' => 'GESTIONES PRIORITARIAS Y COMPROMISOS CON CLIENTES',
        'proyectos'    => 'AVANCES DE GESTIÓN POR PROYECTO',
        'transversal'  => 'GESTIÓN TRANSVERSAL | TALENTO HUMANO Y SST',
        'compromisos'  => 'COMPROMISOS Y SEGUIMIENTO'
    );

    //El encabezado y la firma la primera vez (después se toman del último reporte guardado)
    const ENCABEZADO = array(
        'trato'        => 'Doctora',
        'destinatario' => 'JOHANNA VERA MARTÍNEZ',
        'cargo'        => 'Gerente de Unidad de Infraestructura',
        'presentacion' => 'Se presenta el balance de gestión de la Dirección de Operaciones, con los principales resultados, avances por proyecto, compromisos con los clientes y asuntos que requieren seguimiento.',
        'firma'        => 'LILLEY KARINA SALGUERO GÓMEZ',
        'firmaCargo'   => 'Directora de Operaciones'
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

    //La fecha de arriba del informe: "12 DE NOVIEMBRE DE 2026" o "DEL 10 AL 12 DE NOVIEMBRE DE 2026"
    public static function fechaInforme($desde, $hasta)
    {
        $h = strtotime($hasta);
        $fin = (int)date('j', $h) . ' de ' . self::$MESES[(int)date('n', $h)] . ' de ' . date('Y', $h);
        if ($desde === null || $desde === '' || $desde >= $hasta) { return mb_strtoupper($fin, 'UTF-8'); }
        $d = strtotime($desde);
        $inicio = (int)date('j', $d);
        if (date('n', $d) !== date('n', $h) || date('Y', $d) !== date('Y', $h)) { $inicio .= ' de ' . self::$MESES[(int)date('n', $d)]; }
        if (date('Y', $d) !== date('Y', $h)) { $inicio .= ' de ' . date('Y', $d); }
        return mb_strtoupper('del ' . $inicio . ' al ' . $fin, 'UTF-8');
    }

    //El título del informe
    public static function tituloInforme($desde, $hasta)
    {
        return ($desde !== null && $desde !== '' && $desde < $hasta) ? 'INFORME DE GESTIÓN DE LA DIRECCIÓN OPERATIVA P&B'
                                                                     : 'INFORME DE GESTIÓN DIARIA DE LA DIRECCIÓN OPERATIVA P&B';
    }

    //El subtítulo de los resultados: "Cumplimiento de la Promesa de Valor – 11 de noviembre" (o "– 10 al 12 de noviembre")
    public static function tituloPromesa($desde, $hasta)
    {
        if ($desde === null || $desde === '' || $desde >= $hasta) { return 'Cumplimiento de la Promesa de Valor – ' . self::diaMes($hasta); }
        $d = strtotime($desde); $h = strtotime($hasta);
        $inicio = date('n', $d) === date('n', $h) ? (string)(int)date('j', $d) : self::diaMes($desde);
        return 'Cumplimiento de la Promesa de Valor – ' . $inicio . ' al ' . self::diaMes($hasta);
    }

    //Porcentaje para leer: (518, 527) -> "98,3 %". Sin base, null
    public static function porcentaje($parte, $total)
    {
        if ((float)$total <= 0) { return null; }
        return rtrim(rtrim(number_format(100 * (float)$parte / (float)$total, 1, ',', '.'), '0'), ',') . ' %';
    }

    private static function recortar($texto, $max)
    {
        $texto = trim(preg_replace('/\s+/u', ' ', (string)$texto));
        return mb_strlen($texto, 'UTF-8') > $max ? rtrim(mb_substr($texto, 0, $max - 1, 'UTF-8')) . '…' : $texto;
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
        $nombre = mb_strtolower(trim((string)$nombre), 'UTF-8');
        return mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($nombre, 1, null, 'UTF-8');
    }

    //-----------------------------------------------------------------------
    // Los datos del periodo, por proyecto. De aquí salen los textos que propone
    // el sistema y la gráfica de la promesa de valor del PDF.
    //   $proyectos     [idProyecto => nombre]
    //   $centros       [idCentro => fila de hanaMisCentros()] (los peajes de la persona)
    //   $conNovedades  si se incluyen las novedades (quien puede verlas)
    //   $desde         primer día del periodo. Sin él, el reporte es solo de $fecha.
    //                  La promesa, los temas que se movieron y las novedades se cuentan en todo el
    //                  periodo; los oficios en gestión y las vacantes, como estaban el último día
    // Devuelve [idProyecto => [
    //   'promesa'   => prometidas, ejecutadas, peajes [nombre, prometidas, ejecutadas, debajo[], encima[], sinRegistro[dia => cargos]]
    //   'oficios'   => lista [tipo, radicado, asunto, remitente, peaje, recepcion, dias, fueraPlazo, pendiente, vaEn]
    //   'temas'     => los temas (con el cliente y con Regency) que se movieron en el periodo
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
                                'oficios' => array(), 'temas' => array(), 'novedades' => array(), 'vacantes' => array(), 'agenda' => array());
        }
        if (!count($ids)) { return $datos; }
        $centrosPorProyecto = array();
        foreach ($centros as $centro) { $centrosPorProyecto[(int)$centro['ID_PROYECTO']][(int)$centro['ID_CENTRO_OP']] = $centro['NOM_CENTRO_OP']; }

        self::datosPromesa($datos, $centrosPorProyecto, $fecha, $desde);
        self::datosOficios($datos, $ids, $fecha);
        foreach (TemaCliente::movimientosDelDia($ids, $fecha, $desde) as $id => $movimientos) { if (isset($datos[$id])) { $datos[$id]['temas'] = $movimientos; } }
        if ($conNovedades) { self::datosNovedades($datos, $centros, $fecha, $desde); }
        self::datosVacantes($datos, $centros, $fecha, $desde);
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
            //Temas: con el cliente => gestiones prioritarias; con Regency => avances del proyecto;
            //los de personal (de cualquiera de los dos) => talento humano y SST
            foreach ($d['temas'] as $tema) {
                $apartado = array('proyecto' => $id, 'titulo' => self::tituloConPeaje($tema['ASUNTO'], $tema['NOM_CENTRO_OP']),
                                  'texto' => self::textoTema($tema, $fecha, $desde));
                if (isset($tema['CLASE']) && $tema['CLASE'] === 'PERSONAL') { $transversal[] = $apartado; }
                elseif (isset($tema['CON']) && $tema['CON'] === 'REGENCY') { $porProyecto[$id]['apartados'][] = array('titulo' => $apartado['titulo'], 'texto' => $apartado['texto']); }
                else { $prioritarias[] = $apartado; }
            }
            //Oficios y comunicaciones en gestión: son compromisos con el cliente
            foreach ($d['oficios'] as $oficio) {
                $prioritarias[] = array('proyecto' => $id, 'titulo' => self::tituloConPeaje($oficio['asunto'], $oficio['peaje']), 'texto' => self::textoOficio($oficio));
            }
            //Novedades reportadas en los peajes del proyecto
            if (count($d['novedades'])) {
                $porProyecto[$id]['apartados'][] = array('titulo' => $rango ? 'Novedades reportadas en el periodo' : 'Novedades reportadas en la jornada',
                                                         'texto' => self::textoNovedades($d['novedades']));
            }
            foreach ($d['vacantes'] as $vacante) { $vacantes[] = $vacante + array('proyecto' => $proyectos[$id]); }
            foreach ($d['agenda'] as $actividad) {
                $compromisos[] = array('proyecto' => $id, 'gestion' => $actividad['gestion'] . ' (' . $proyectos[$id] . ')',
                                       'fecha' => self::diaMes($actividad['fecha']), 'orden' => $actividad['fecha']);
            }
        }

        //Vacantes: un apartado de talento humano con todas, y las fechas comprometidas por Gestión Humana como compromisos
        if (count($vacantes)) {
            $transversal[] = array('proyecto' => 0, 'titulo' => 'Vacantes en proceso de cobertura', 'texto' => self::textoVacantes($vacantes, $fecha, $desde));
            foreach ($vacantes as $v) {
                if ($v['estado'] === 'ABIERTA' && $v['compromiso'] !== null && $v['compromiso'] >= $desde) {
                    $compromisos[] = array('proyecto' => (int)$v['idProyecto'], 'gestion' => 'Ingreso de ' . self::minusInicial($v['cargo']) . ' – ' . $v['peaje'] . ' (' . $v['proyecto'] . ')',
                                           'fecha' => self::diaMes($v['compromiso']), 'orden' => $v['compromiso']);
                }
            }
        }
        usort($compromisos, function ($a, $b) { return strcmp($a['orden'] . $a['gestion'], $b['orden'] . $b['gestion']); });
        foreach ($compromisos as $i => $c) { unset($compromisos[$i]['orden']); }

        return array('resultados' => self::textoResultados($datos, $proyectos, $fecha, $desde),
                     'prioritarias' => $prioritarias, 'proyectos' => $porProyecto,
                     'transversal' => $transversal, 'compromisos' => array_values($compromisos));
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

    private static function minusInicial($texto)
    {
        return mb_strtolower(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($texto, 1, null, 'UTF-8');
    }

    //-----------------------------------------------------------------------
    // 1. Resultados: la promesa de valor del periodo, por peaje y cargo.
    // Solo se leen las casillas de pv_dia del periodo (desde - hasta; si es un
    // solo día, solo ese día): nada de antes ni de después entra en las cuentas.
    //-----------------------------------------------------------------------
    private static function datosPromesa(&$datos, $centrosPorProyecto, $fecha, $desde)
    {
        $idsCentros = array();
        foreach ($centrosPorProyecto as $lista) { foreach (array_keys($lista) as $idCentro) { $idsCentros[] = (int)$idCentro; } }
        if (!count($idsCentros)) { return; }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $filas = HanaDB::q("SELECT d.ID_CENTRO_OP, d.FECHA, a.NOMBRE AS CARGO, d.PROMESA_HORAS, d.EJECUTADO_HORAS, d.JUSTIFICACION
                              FROM pv_dia d INNER JOIN aus_cargo a ON a.ID_CARGO_AUS = d.ID_CARGO_AUS
                             WHERE d.ID_CENTRO_OP IN ($marcas) AND d.FECHA BETWEEN ? AND ? AND d.PROMESA_HORAS IS NOT NULL
                             ORDER BY d.FECHA, a.ORDEN, a.NOMBRE",
                           str_repeat('i', count($idsCentros)) . 'ss', array_merge($idsCentros, array($desde, $fecha)));
        $porCentro = array();
        foreach (is_array($filas) ? $filas : array() as $fila) { $porCentro[(int)$fila['ID_CENTRO_OP']][] = $fila; }

        foreach ($centrosPorProyecto as $idProyecto => $lista) {
            if (!isset($datos[(int)$idProyecto])) { continue; }
            $p = &$datos[(int)$idProyecto]['promesa'];
            foreach ($lista as $idCentro => $nombreCentro) {
                if (!isset($porCentro[$idCentro])) { continue; }
                $peaje = array('nombre' => $nombreCentro, 'prometidas' => 0, 'ejecutadas' => 0, 'debajo' => array(), 'encima' => array(), 'sinRegistro' => array());
                foreach ($porCentro[$idCentro] as $casilla) {
                    if ($casilla['EJECUTADO_HORAS'] === null) { $peaje['sinRegistro'][$casilla['FECHA']][] = self::nombreCargo($casilla['CARGO']); continue; }
                    $promesa = (float)$casilla['PROMESA_HORAS']; $ejecutado = (float)$casilla['EJECUTADO_HORAS'];
                    $peaje['prometidas'] += $promesa; $peaje['ejecutadas'] += $ejecutado;
                    $diferencia = round($promesa - $ejecutado, 2);
                    if ($diferencia == 0) { continue; }
                    $caso = array('fecha' => $casilla['FECHA'], 'cargo' => self::nombreCargo($casilla['CARGO']), 'promesa' => $promesa, 'ejecutado' => $ejecutado,
                                  'diferencia' => abs($diferencia), 'justificacion' => trim((string)$casilla['JUSTIFICACION']));
                    if ($diferencia > 0) { $peaje['debajo'][] = $caso; } else { $peaje['encima'][] = $caso; }
                }
                $p['prometidas'] += $peaje['prometidas']; $p['ejecutadas'] += $peaje['ejecutadas'];
                $p['peajes'][] = $peaje;
            }
            unset($p);
        }
    }

    //Las cifras de la promesa de todos los proyectos: horas, porcentaje y peajes que cumplen.
    //Un peaje cumple si tiene lo ejecutado registrado y ningún cargo quedó por debajo de lo prometido.
    //Los peajes sin lo ejecutado registrado no entran en la cuenta: se nombran aparte como pendientes
    public static function totalesPromesa($datos)
    {
        $t = array('prometidas' => 0, 'ejecutadas' => 0, 'peajes' => 0, 'cumplen' => 0, 'pendientes' => 0, 'hay' => false);
        foreach ($datos as $d) {
            foreach ($d['promesa']['peajes'] as $peaje) {
                $t['hay'] = true;
                if ($peaje['prometidas'] <= 0) { $t['pendientes']++; continue; }
                $t['prometidas'] += $peaje['prometidas']; $t['ejecutadas'] += $peaje['ejecutadas'];
                $t['peajes']++;
                if (!count($peaje['debajo'])) { $t['cumplen']++; }
            }
        }
        $t['pct'] = $t['prometidas'] > 0 ? 100 * $t['ejecutadas'] / $t['prometidas'] : null;
        return $t;
    }

    //El texto de los resultados, como en el informe: «Se registró un cumplimiento del 100 %, con 19 de 19
    //peajes cumpliendo el indicador de horas en punto de cobro.» y, debajo, los peajes que no cumplieron
    //con lo que les faltó, y los que tienen pendiente registrar lo ejecutado
    private static function textoResultados($datos, $proyectos, $fecha, $desde)
    {
        $rango = $desde < $fecha;
        $t = self::totalesPromesa($datos);
        if (!$t['hay']) { return $rango ? 'No se registró promesa de valor para el periodo.' : 'No se registró promesa de valor para la fecha.'; }
        if (!$t['peajes']) { return 'Está pendiente el registro de lo ejecutado frente a la promesa de valor en todos los peajes.'; }
        $lineas = array(($rango ? 'En el periodo se' : 'Se') . ' registró un cumplimiento del ' . self::porcentaje($t['ejecutadas'], $t['prometidas']) .
                        ', con ' . $t['cumplen'] . ' de ' . $t['peajes'] . ($t['peajes'] === 1 ? ' peaje' : ' peajes') . ' cumpliendo el indicador de horas en punto de cobro.');
        $pendientes = array();
        foreach ($datos as $id => $d) {
            foreach ($d['promesa']['peajes'] as $peaje) {
                $nombre = $peaje['nombre'] . ' (' . $proyectos[$id] . ')';
                if ($peaje['prometidas'] <= 0) { $pendientes[] = $nombre; continue; }
                if (!count($peaje['debajo'])) { continue; }
                $casos = array();
                foreach ($peaje['debajo'] as $c) {
                    $casos[] = ($rango ? date('d/m', strtotime($c['fecha'])) . ' · ' : '') . $c['cargo'] . ': se ejecutaron ' . self::horas($c['ejecutado']) . ' de ' . self::enHoras($c['promesa']) .
                               ' (' . ($c['diferencia'] == 1 ? 'faltó 1' : 'faltaron ' . self::horas($c['diferencia'])) . '). ' .
                               ($c['justificacion'] !== '' ? 'Justificación: ' . self::conPunto(self::recortar($c['justificacion'], 200)) : 'Sin justificación registrada.');
                }
                $lineas[] = '• ' . $nombre . ': ' . self::porcentaje($peaje['ejecutadas'], $peaje['prometidas']) . '. ' . implode(' ', $casos);
            }
        }
        if (count($pendientes)) { $lineas[] = '• Pendiente el registro de lo ejecutado en: ' . implode(', ', $pendientes) . '.'; }
        return implode("\n", $lineas);
    }

    //-----------------------------------------------------------------------
    // 2. Oficios y comunicaciones en gestión el último día: recibidos hasta ese día y sin cerrar ese día
    //-----------------------------------------------------------------------
    private static function datosOficios(&$datos, $ids, $fecha)
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
        if ($c['vaEn'] !== '') { $texto .= ' Último avance: ' . self::conPunto(self::recortar($c['vaEn'], 600)); }
        if ($c['pendiente'] !== '') { $texto .= ' El siguiente paso corresponde a ' . $c['pendiente'] . '.'; }
        return $texto;
    }

    //-----------------------------------------------------------------------
    // Temas (con el cliente y con Regency): lo que se escribió en el periodo y
    // si se abrió o se cerró en él
    //-----------------------------------------------------------------------
    private static function textoTema($tema, $fecha, $desde)
    {
        $rango = $desde < $fecha;
        $enPeriodo = function ($dia) use ($desde, $fecha) { return $dia !== null && $dia >= $desde && $dia <= $fecha; };
        $nuevo = $enPeriodo($tema['FECHA_RECEPCION']); $cerrado = $enPeriodo($tema['FECHA_ATENCION']);
        $con = self::recortar($tema['REMITENTE'], 80);
        $nota = trim((string)$tema['TEXTO_DIA']) !== '' ? self::conPunto(self::recortar($tema['TEXTO_DIA'], 1500)) : '';
        $partes = array();
        if ($nuevo && $cerrado) { $partes[] = 'Se abrió y se cerró el tema con ' . $con . ($rango ? ' en el periodo.' : ' en la fecha.'); }
        elseif ($nuevo) { $partes[] = 'Se abrió el tema con ' . $con . '.'; }
        if ($nota !== '') { $partes[] = $nota; }
        if ($cerrado && !$nuevo) { $partes[] = 'El tema quedó cerrado el ' . self::diaMes($tema['FECHA_ATENCION']) . '.'; }
        if (!count($partes)) { $partes[] = 'Se mantiene seguimiento con ' . $con . '.'; }
        return implode(' ', $partes);
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
            $datos[$idProyecto]['novedades'][] = array('peaje' => $fila['NOM_CENTRO_OP'], 'prioridad' => (string)$fila['PRIORIDAD'],
                'titulo' => html_entity_decode((string)$fila['TITULO'], ENT_QUOTES, 'UTF-8'),
                'descripcion' => html_entity_decode((string)$fila['DESCRIPCION'], ENT_QUOTES, 'UTF-8'));
        }
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
        $filas = HanaDB::q("SELECT v.ID_CENTRO_OP, c.NOM_CENTRO_OP, v.CARGO, v.MOTIVO, v.FECHA_VACANTE, v.FECHA_CIERRE, v.ESTADO_VACANTE,
                                   v.NOMBRE_REEMPLAZO, v.ESTADO_GH, v.FECHA_COMPROMISO_GH,
                                   (SELECT h.TEXTO FROM historial h WHERE h.MODULO = 'VACANTE' AND h.ID_REGISTRO = v.ID_VACANTE AND h.TIPO = 'SALE_CARGO'
                                     ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS NOMBRE_SALE
                              FROM vacante v INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = v.ID_CENTRO_OP
                             WHERE v.ID_CENTRO_OP IN ($marcas) AND v.FECHA_VACANTE <= ?
                               AND ((v.ESTADO_VACANTE = 'ABIERTA' AND (v.FECHA_CIERRE IS NULL OR v.FECHA_CIERRE > ?))
                                    OR (v.ESTADO_VACANTE = 'CUBIERTA' AND v.FECHA_CIERRE BETWEEN ? AND ?))
                             ORDER BY v.FECHA_VACANTE, c.NOM_CENTRO_OP, v.CARGO",
                           str_repeat('i', count($ids)) . 'ssss', array_merge($ids, array($fecha, $fecha, $desde, $fecha)));
        $estadosGh = HanaConfig::opciones('LISTA_ESTADOS_GH');
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $idProyecto = $proyectoDe[(int)$fila['ID_CENTRO_OP']];
            if (!isset($datos[$idProyecto])) { continue; }
            $datos[$idProyecto]['vacantes'][] = array('idProyecto' => $idProyecto, 'peaje' => $fila['NOM_CENTRO_OP'], 'cargo' => self::nombreCargo($fila['CARGO']),
                'motivo' => (string)$fila['MOTIVO'], 'desde' => $fila['FECHA_VACANTE'], 'estado' => $fila['ESTADO_VACANTE'],
                'reemplazo' => (string)$fila['NOMBRE_REEMPLAZO'], 'sale' => (string)$fila['NOMBRE_SALE'],
                'gh' => $fila['ESTADO_GH'] ? (isset($estadosGh[$fila['ESTADO_GH']]) ? $estadosGh[$fila['ESTADO_GH']] : $fila['ESTADO_GH']) : '',
                'compromiso' => $fila['FECHA_COMPROMISO_GH'] ?: null);
        }
    }

    private static function textoVacantes($vacantes, $fecha, $desde)
    {
        $abiertas = array_values(array_filter($vacantes, function ($v) { return $v['estado'] === 'ABIERTA'; }));
        $cubiertas = array_values(array_filter($vacantes, function ($v) { return $v['estado'] === 'CUBIERTA'; }));
        $lineas = array();
        if (count($abiertas)) {
            $lineas[] = count($abiertas) === 1 ? 'Se encuentra una vacante en proceso de cobertura:' : 'Se encuentran ' . count($abiertas) . ' vacantes en proceso de cobertura:';
            foreach ($abiertas as $v) {
                $linea = '• ' . $v['cargo'] . ' – ' . $v['peaje'] . ' (' . $v['proyecto'] . '): vacante desde el ' . self::diaMes($v['desde']) .
                         ($v['motivo'] !== '' ? ' por ' . self::minusInicial(rtrim(self::recortar($v['motivo'], 120), '.')) : '') .
                         ($v['sale'] !== '' ? ' de ' . self::recortar($v['sale'], 80) : '');
                if ($v['gh'] !== '') { $linea .= '; en ' . self::minusInicial($v['gh']) . ' con Gestión Humana'; }
                if ($v['compromiso'] !== null) { $linea .= ', con fecha comprometida para el ' . self::diaMes($v['compromiso']); }
                $lineas[] = $linea . '.';
            }
        }
        foreach ($cubiertas as $v) {
            $lineas[] = '• Se cubrió la vacante de ' . self::minusInicial($v['cargo']) . ' – ' . $v['peaje'] . ' (' . $v['proyecto'] . ')' .
                        ($v['reemplazo'] !== '' ? ' con ' . self::recortar($v['reemplazo'], 80) : '') . '.';
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
        $filas = HanaDB::q("SELECT k.ID_CENTRO_OP, c.NOM_CENTRO_OP, k.FECHA, k.TIPO, k.DESCRIPCION
                              FROM cronograma k INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = k.ID_CENTRO_OP
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
            $gestion = ($descripcion !== '' ? self::recortar($descripcion, 120) : $tipo) . ' – ' . $fila['NOM_CENTRO_OP'];
            //La misma actividad de varias personas el mismo día sale una vez
            $clave = $fila['FECHA'] . '|' . mb_strtolower($gestion, 'UTF-8');
            if (isset($vistos[$clave])) { continue; }
            $vistos[$clave] = true;
            $datos[$idProyecto]['agenda'][] = array('fecha' => $fila['FECHA'], 'gestion' => $gestion);
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
            $limpio[$clave] = mb_substr($valor, 0, self::LARGO_ENCABEZADO[$clave], 'UTF-8');
        }
        return $limpio;
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
        $proyectoValido = function ($id) use ($proyectos) { $id = (int)$id; return isset($proyectos[$id]) ? $id : 0; };
        $lista = function ($items, $conProyecto, $nombreSeccion) use ($limite, $proyectoValido) {
            $salida = array();
            foreach (is_array($items) ? array_slice(array_values($items), 0, self::MAX_APARTADOS) : array() as $item) {
                if (!is_array($item)) { continue; }
                $titulo = $limite(isset($item['titulo']) ? $item['titulo'] : '', self::LARGO_TITULO, 'Título en ' . $nombreSeccion);
                $texto = $limite(isset($item['texto']) ? $item['texto'] : '', self::LARGO_TEXTO, ($titulo !== '' ? $titulo : $nombreSeccion));
                if ($titulo === '' && $texto === '') { continue; }
                $nuevo = array('titulo' => $titulo, 'texto' => $texto);
                if ($conProyecto) { $nuevo = array('proyecto' => $proyectoValido(isset($item['proyecto']) ? $item['proyecto'] : 0)) + $nuevo; }
                $salida[] = $nuevo;
            }
            return $salida;
        };
        //Sin encabezado (por ejemplo, un reporte viejo), va el del último informe guardado
        $e = isset($entrada['encabezado']) && is_array($entrada['encabezado']) ? $entrada['encabezado'] : self::ultimoEncabezado();
        $encabezado = array();
        foreach (self::ENCABEZADO as $clave => $fabrica) {
            $encabezado[$clave] = $limite(isset($e[$clave]) ? $e[$clave] : '', self::LARGO_ENCABEZADO[$clave], 'Encabezado');
        }
        $porProyecto = array();
        $recibidos = isset($entrada['proyectos']) && is_array($entrada['proyectos']) ? $entrada['proyectos'] : array();
        foreach ($proyectos as $id => $nombre) {
            $items = isset($recibidos[$id]['apartados']) ? $recibidos[$id]['apartados'] : (isset($recibidos[(string)$id]['apartados']) ? $recibidos[(string)$id]['apartados'] : array());
            $porProyecto[$id] = array('apartados' => $lista($items, false, $nombre));
        }
        $compromisos = array();
        foreach (isset($entrada['compromisos']) && is_array($entrada['compromisos']) ? array_slice(array_values($entrada['compromisos']), 0, self::MAX_APARTADOS) : array() as $c) {
            if (!is_array($c)) { continue; }
            $gestion = $limite(isset($c['gestion']) ? $c['gestion'] : '', 300, 'Gestión de un compromiso');
            $fechaObjetivo = $limite(isset($c['fecha']) ? $c['fecha'] : '', 60, 'Fecha objetivo de un compromiso');
            if ($gestion === '' && $fechaObjetivo === '') { continue; }
            $compromisos[] = array('proyecto' => $proyectoValido(isset($c['proyecto']) ? $c['proyecto'] : 0), 'gestion' => $gestion, 'fecha' => $fechaObjetivo);
        }
        $reporte = array(
            'encabezado'   => $encabezado,
            'resultados'   => $limite(isset($entrada['resultados']) ? $entrada['resultados'] : '', self::LARGO_TEXTO, 'Resultados de la operación'),
            'prioritarias' => $lista(isset($entrada['prioritarias']) ? $entrada['prioritarias'] : array(), true, 'Gestiones prioritarias'),
            'proyectos'    => $porProyecto,
            'transversal'  => $lista(isset($entrada['transversal']) ? $entrada['transversal'] : array(), true, 'Gestión transversal'),
            'compromisos'  => $compromisos
        );
        return $estricto ? array($reporte, $error) : $reporte;
    }

    //Lo guardado, como reporte de la versión 3 con los proyectos de la persona (y sus nombres de hoy).
    //Los de la versión 2 (puntos por proyecto) se convierten
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
        foreach (array('prioritarias', 'transversal', 'compromisos') as $seccion) {
            if ($previo) { $reporte[$seccion] = array_merge($reporte[$seccion], array_values(array_filter($previo[$seccion], $ajeno))); }
        }
        if ($previo) { foreach ($previo['proyectos'] as $id => $p) { if (count($p['apartados'])) { $otrosProyectos[$id] = $p; } } }
        //"desde" va al principio (justo después de "fecha"): así se reconoce el periodo sin abrir todo el texto
        return array('version' => self::VERSION, 'fecha' => $fecha, 'desde' => $desde,
                     'encabezado' => $reporte['encabezado'], 'resultados' => $reporte['resultados'],
                     'prioritarias' => $reporte['prioritarias'], 'proyectos' => $reporte['proyectos'] + $otrosProyectos,
                     'transversal' => $reporte['transversal'], 'compromisos' => $reporte['compromisos'],
                     'nombres' => $proyectos + $otrosNombres);
    }

    //Las cifras de la promesa para el PDF, leídas del texto de los resultados tal como quedó (si la dirección
    //corrigió el porcentaje, el PDF muestra el corregido). Lo que el texto no diga se toma de $totales (la base)
    public static function cifrasResultados($texto, $totales)
    {
        $c = array('pct' => $totales['pct'], 'cumplen' => $totales['peajes'] ? $totales['cumplen'] : null, 'peajes' => $totales['peajes'] ?: null);
        if (preg_match('/cumplimiento\s+(?:del?\s+)?(\d{1,3}(?:[.,]\d+)?)\s*%/iu', (string)$texto, $m)) { $c['pct'] = (float)str_replace(',', '.', $m[1]); }
        if (preg_match('/(\d+)\s+de\s+(\d+)\s+peajes?\b/iu', (string)$texto, $m)) { $c['cumplen'] = (int)$m[1]; $c['peajes'] = (int)$m[2]; }
        return $c;
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
