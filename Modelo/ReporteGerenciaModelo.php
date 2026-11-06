<?php
/*
  HANA - Reporte diario a la Gerencia
  ---------------------------------------------------------------------------
  Lo arma la dirección cada día (también sábados, domingos y festivos), por
  proyecto, con lo que llenan los coordinadores en el Reporte diario. Por
  defecto tiene cuatro puntos, que se llenan solos y luego se corrigen a mano:

    FLOTA      Disponibilidad de flota: cada vehículo del proyecto, operativo o no
               (Cronograma y vehículo, tabla vehiculo_dia).
    PROMESA    Cumplimiento de la promesa de valor SOLO del día del reporte, por
               peaje: cumple o no cumple y qué cargos quedaron por debajo o por
               encima ese día (pv_dia). Antes sumaba la semana (lunes al día del
               reporte); se cambió porque el reporte es diario.
    OFICIOS    Oficios y comunicaciones en gestión ese día (comunicacion).
    NOVEDADES  Novedades relevantes: los temas con el cliente que se movieron ese
               día y las novedades reportadas ese día en sus peajes. Si no hay,
               «Sin novedades relevantes en la jornada».

  Los textos se escriben en tono formal, porque van para la Gerencia. El PDF
  del reporte (Vista/pdf/ReporteGerenciaPdf.php) NO usa los datos de la base:
  lee las cifras de los textos tal como quedaron (cifrasDeTextos), así que si
  la dirección corrige un número en un punto, el PDF muestra el corregido.

  Los nombres y el orden de los puntos se cambian en Parámetros del sistema →
  Listas (LISTA_PUNTOS_GERENCIA). Los que se agreguen allí salen en blanco.

  No tiene tabla propia. Se guarda en historial, UNA sola fila por día:
    MODULO = 'GERENCIA', ID_REGISTRO = el día como número AAAAMMDD (20261101),
    TIPO = 'REPORTE', TEXTO = el reporte en JSON, ID_COLABORADOR y FECHA.
  Cada vez que se guarda, esa fila se actualiza: quedan el reporte de ahora,
  quién lo guardó por última vez y cuándo. Antes se agregaba una fila nueva
  por cada guardado (el reporte completo repetido decenas de veces por día y
  un conteo de «se ha guardado N veces» que no le servía a nadie): al guardar
  un día, sus copias viejas se borran y queda una sola.
*/
require_once __DIR__ . "/HanaDB.php";
require_once __DIR__ . "/HanaConfig.php";
require_once __DIR__ . "/TemaClienteModelo.php";

class ReporteGerencia
{
    const MODULO = 'GERENCIA';
    const TIPO   = 'REPORTE';
    const LARGO_PUNTO = 4000;     //caracteres de cada punto de cada proyecto
    const LARGO_TOTAL = 60000;    //bytes del reporte completo (historial.TEXTO es TEXT: 65.535 bytes)

    private static $DIAS  = array('dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb');
    private static $DIAS_LARGOS = array('domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado');
    private static $MESES = array(1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                                  'septiembre', 'octubre', 'noviembre', 'diciembre');

    //Los puntos del reporte, en su orden: [CODIGO => Nombre]
    public static function puntos() { return HanaConfig::opciones('LISTA_PUNTOS_GERENCIA'); }

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

    //"2026-10-29" -> "jueves, 29 de octubre de 2026"
    public static function fechaLarga($fecha)
    {
        $t = strtotime($fecha);
        return self::$DIAS_LARGOS[(int)date('w', $t)] . ', ' . (int)date('j', $t) . ' de ' . self::$MESES[(int)date('n', $t)] . ' de ' . date('Y', $t);
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
    // Los datos del día, por proyecto. De aquí salen los textos que propone
    // el sistema (prellenar) y las cifras, porcentajes y gráficas del PDF.
    //   $proyectos     [idProyecto => nombre]
    //   $centros       [idCentro => fila de hanaMisCentros()] (los peajes de la persona)
    //   $conNovedades  si se incluyen las novedades del día (quien puede verlas)
    // Devuelve [idProyecto => [
    //   'flota'     => total, operativos, fuera, sinRegistro, vehiculos [placa, descripcion, estado, transporte, observacion]
    //   'promesa'   => prometidas, ejecutadas, diasDebajo, diasEncima, sinJustificar, pendientes,
    //                  peajes [nombre, prometidas, ejecutadas, debajo[], encima[], sinRegistro[dia => cargos]]
    //   'oficios'   => total, fueraPlazo, porEnviar, lista [...]
    //   'temas'     => abiertos, movimientos [...]
    //   'novedades' => lista [...]
    // ]]
    //-----------------------------------------------------------------------
    public static function calcular($proyectos, $centros, $fecha, $conNovedades)
    {
        $ids = array_map('intval', array_keys($proyectos));
        $datos = array();
        foreach ($ids as $id) {
            $datos[$id] = array(
                'flota'     => array('total' => 0, 'operativos' => 0, 'fuera' => 0, 'sinRegistro' => 0, 'vehiculos' => array()),
                'promesa'   => array('prometidas' => 0, 'ejecutadas' => 0, 'diasDebajo' => 0, 'diasEncima' => 0, 'sinJustificar' => 0, 'pendientes' => 0, 'peajes' => array()),
                'oficios'   => array('total' => 0, 'fueraPlazo' => 0, 'porEnviar' => 0, 'lista' => array()),
                'temas'     => array('abiertos' => 0, 'movimientos' => array()),
                'novedades' => array());
        }
        if (!count($ids)) { return $datos; }
        $centrosPorProyecto = array();
        foreach ($centros as $centro) { $centrosPorProyecto[(int)$centro['ID_PROYECTO']][(int)$centro['ID_CENTRO_OP']] = $centro['NOM_CENTRO_OP']; }

        self::datosFlota($datos, $ids, $fecha);
        self::datosPromesa($datos, $centrosPorProyecto, $fecha);
        self::datosOficios($datos, $ids, $fecha);
        foreach (TemaCliente::movimientosDelDia($ids, $fecha) as $id => $movimientos) { if (isset($datos[$id])) { $datos[$id]['temas']['movimientos'] = $movimientos; } }
        self::temasAbiertos($datos, $ids, $fecha);
        if ($conNovedades) { self::datosNovedades($datos, $centros, $fecha); }
        return $datos;
    }

    //Lo que el sistema propone escribir en cada punto: [idProyecto => [CODIGO => texto]]
    public static function prellenar($proyectos, $centros, $fecha, $conNovedades)
    {
        return self::textos(self::calcular($proyectos, $centros, $fecha, $conNovedades), $fecha);
    }

    //Los textos de cada punto a partir de los datos del día, en tono formal
    public static function textos($datos, $fecha)
    {
        $textos = array();
        foreach ($datos as $id => $d) {
            $textos[$id] = array('FLOTA' => self::textoFlota($d['flota']), 'PROMESA' => self::textoPromesa($d['promesa'], $fecha),
                                 'OFICIOS' => self::textoOficios($d['oficios']), 'NOVEDADES' => self::textoNovedades($d['temas'], $d['novedades'], $fecha));
        }
        return $textos;
    }

    //-----------------------------------------------------------------------
    // Flota: cada vehículo activo del proyecto y su estado ese día
    //-----------------------------------------------------------------------
    private static function datosFlota(&$datos, $ids, $fecha)
    {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $filas = HanaDB::q("SELECT v.ID_PROYECTO, v.PLACA, v.DESCRIPCION, d.ESTADO_VH, d.TRANSPORTE, d.OBSERVACION
                              FROM vehiculo v LEFT JOIN vehiculo_dia d ON d.ID_VEHICULO = v.ID_VEHICULO AND d.FECHA = ?
                             WHERE v.ESTADO = 1 AND v.ID_PROYECTO IN ($marcas)
                             ORDER BY v.PLACA", 's' . str_repeat('i', count($ids)), array_merge(array($fecha), $ids));
        $transportes = HanaConfig::opciones('LISTA_TRANSPORTES');
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $f = &$datos[(int)$fila['ID_PROYECTO']]['flota'];
            $f['total']++;
            if ($fila['ESTADO_VH'] === 'OPERATIVO') { $f['operativos']++; $estado = 'OPERATIVO'; }
            elseif ($fila['ESTADO_VH'] === 'INOPERATIVO') { $f['fuera']++; $estado = 'FUERA'; }
            else { $f['sinRegistro']++; $estado = 'SIN_REGISTRO'; }
            $f['vehiculos'][] = array('placa' => $fila['PLACA'], 'descripcion' => (string)$fila['DESCRIPCION'], 'estado' => $estado,
                                      'transporte' => $fila['TRANSPORTE'] ? (isset($transportes[$fila['TRANSPORTE']]) ? $transportes[$fila['TRANSPORTE']] : $fila['TRANSPORTE']) : '',
                                      'observacion' => (string)$fila['OBSERVACION']);
            unset($f);
        }
    }

    //Flota: cuántos vehículos están operativos y el estado de cada uno (operativo o inoperativo).
    //Si está inoperativo y el coordinador escribió el motivo, va como dato adicional
    private static function textoFlota($f)
    {
        if (!$f['total']) { return 'El proyecto no tiene vehículos asignados.'; }
        $lineas = array('Vehículos operativos: ' . $f['operativos'] . ' de ' . $f['total'] . '.');
        foreach ($f['vehiculos'] as $v) {
            if ($v['estado'] === 'OPERATIVO') { $linea = '• ' . $v['placa'] . ': operativo.'; }
            elseif ($v['estado'] === 'FUERA') {
                $linea = '• ' . $v['placa'] . ': inoperativo.';
                if ($v['observacion'] !== '') { $linea .= ' Motivo: ' . rtrim(self::recortar($v['observacion'], 160), '.') . '.'; }
            } else { $linea = '• ' . $v['placa'] . ': sin reporte de estado para la fecha.'; }
            $lineas[] = $linea;
        }
        return implode("\n", $lineas);
    }

    //-----------------------------------------------------------------------
    // Promesa de valor del día del reporte, por peaje y cargo.
    // Solo se leen las casillas de pv_dia con FECHA = día del reporte: ni los
    // días anteriores de la semana ni los siguientes entran en las cuentas.
    //-----------------------------------------------------------------------
    private static function datosPromesa(&$datos, $centrosPorProyecto, $fecha)
    {
        $idsCentros = array();
        foreach ($centrosPorProyecto as $lista) { foreach (array_keys($lista) as $idCentro) { $idsCentros[] = (int)$idCentro; } }
        if (!count($idsCentros)) { return; }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $filas = HanaDB::q("SELECT d.ID_CENTRO_OP, d.FECHA, a.NOMBRE AS CARGO, d.PROMESA_HORAS, d.EJECUTADO_HORAS, d.JUSTIFICACION
                              FROM pv_dia d INNER JOIN aus_cargo a ON a.ID_CARGO_AUS = d.ID_CARGO_AUS
                             WHERE d.ID_CENTRO_OP IN ($marcas) AND d.FECHA = ? AND d.PROMESA_HORAS IS NOT NULL
                             ORDER BY a.ORDEN, a.NOMBRE",
                           str_repeat('i', count($idsCentros)) . 's', array_merge($idsCentros, array($fecha)));
        $porCentro = array();
        foreach (is_array($filas) ? $filas : array() as $fila) { $porCentro[(int)$fila['ID_CENTRO_OP']][] = $fila; }

        foreach ($centrosPorProyecto as $idProyecto => $lista) {
            if (!isset($datos[(int)$idProyecto])) { continue; }
            $p = &$datos[(int)$idProyecto]['promesa'];
            foreach ($lista as $idCentro => $nombreCentro) {
                if (!isset($porCentro[$idCentro])) { continue; }
                $peaje = array('nombre' => $nombreCentro, 'prometidas' => 0, 'ejecutadas' => 0, 'debajo' => array(), 'encima' => array(), 'sinRegistro' => array(), 'cargosDia' => array());
                foreach ($porCentro[$idCentro] as $casilla) {
                    $peaje['cargosDia'][$casilla['FECHA']] = isset($peaje['cargosDia'][$casilla['FECHA']]) ? $peaje['cargosDia'][$casilla['FECHA']] + 1 : 1;
                    if ($casilla['EJECUTADO_HORAS'] === null) { $peaje['sinRegistro'][$casilla['FECHA']][] = self::nombreCargo($casilla['CARGO']); continue; }
                    $promesa = (float)$casilla['PROMESA_HORAS']; $ejecutado = (float)$casilla['EJECUTADO_HORAS'];
                    $peaje['prometidas'] += $promesa; $peaje['ejecutadas'] += $ejecutado;
                    $diferencia = round($promesa - $ejecutado, 2);
                    if ($diferencia == 0) { continue; }
                    $caso = array('fecha' => $casilla['FECHA'], 'cargo' => self::nombreCargo($casilla['CARGO']), 'promesa' => $promesa, 'ejecutado' => $ejecutado,
                                  'diferencia' => abs($diferencia), 'justificacion' => trim((string)$casilla['JUSTIFICACION']));
                    if ($caso['justificacion'] === '') { $p['sinJustificar']++; }
                    if ($diferencia > 0) { $peaje['debajo'][] = $caso; $p['diasDebajo']++; } else { $peaje['encima'][] = $caso; $p['diasEncima']++; }
                }
                $p['prometidas'] += $peaje['prometidas']; $p['ejecutadas'] += $peaje['ejecutadas'];
                $p['pendientes'] += count($peaje['sinRegistro']);
                $p['peajes'][] = $peaje;
            }
            unset($p);
        }
    }

    //Texto de la promesa del día. Un renglón con el total del día («Jornada del martes 27 de octubre: se ejecutaron
    //518 de 527 horas prometidas (98,3 %).»), una viñeta por peaje y, debajo, cada cargo que quedó por debajo o por
    //encima. Como todo es del mismo día, los casos ya no llevan la fecha delante, solo el cargo.
    //OJO: cifrasDeTextos() lee estos textos para el PDF; si se cambia la redacción, revisar partesPromesa()
    private static function textoPromesa($p, $fecha)
    {
        if (!count($p['peajes'])) { return 'No hay promesa de valor registrada para la fecha.'; }
        $lineas = array('Jornada del ' . self::diaLargo($fecha) . ': ' . ($p['prometidas'] > 0
            ? 'se ejecutaron ' . self::horas($p['ejecutadas']) . ' de ' . self::horas($p['prometidas']) . ' horas prometidas (' . self::porcentaje($p['ejecutadas'], $p['prometidas']) . ').'
            : 'todavía no se ha registrado lo ejecutado.'));
        foreach ($p['peajes'] as $peaje) {
            if ($peaje['prometidas'] == 0) { $estado = 'pendiente de registrar lo ejecutado'; }
            elseif (count($peaje['debajo'])) { $estado = 'no cumple'; }
            elseif (count($peaje['encima'])) { $estado = 'cumple, con horas adicionales'; }
            else { $estado = 'cumple'; }
            if (count($peaje['sinRegistro']) && $peaje['prometidas'] > 0 && !count($peaje['debajo'])) { $estado .= ' en lo registrado'; }
            $linea = '• ' . $peaje['nombre'] . ': ' . $estado . ($peaje['prometidas'] > 0 ? ' (' . self::porcentaje($peaje['ejecutadas'], $peaje['prometidas']) . ').' : '.');
            foreach ($peaje['debajo'] as $c) {
                $linea .= "\n   – " . $c['cargo'] . ': se ejecutaron ' . self::horas($c['ejecutado']) . ' de ' . self::enHoras($c['promesa']) .
                          ' (' . ($c['diferencia'] == 1 ? 'faltó 1' : 'faltaron ' . self::horas($c['diferencia'])) . '). ' . ($c['justificacion'] !== '' ? 'Justificación: ' . rtrim(self::recortar($c['justificacion'], 160), '.') . '.' : 'Sin justificación registrada.');
            }
            foreach ($peaje['encima'] as $c) {
                $linea .= "\n   – " . $c['cargo'] . ': se ejecutaron ' . self::horas($c['ejecutado']) . ' de ' . self::enHoras($c['promesa']) .
                          ' (' . self::enHoras($c['diferencia']) . ' adicional' . ($c['diferencia'] == 1 ? '' : 'es') . '). ' .
                          ($c['justificacion'] !== '' ? 'Justificación: ' . rtrim(self::recortar($c['justificacion'], 160), '.') . '.' : 'Sin justificación registrada.');
            }
            //Cargos del día sin lo ejecutado. Si no se registró ninguno, el estado del peaje ya lo dice
            //(«pendiente de registrar lo ejecutado»); si faltan solo algunos, se nombran
            foreach ($peaje['sinRegistro'] as $dia => $cargos) {
                if (count($cargos) < $peaje['cargosDia'][$dia]) {
                    $linea .= "\n   – Pendiente registrar lo ejecutado de: " . implode(', ', $cargos) . '.';
                }
            }
            $lineas[] = $linea;
        }
        return implode("\n", $lineas);
    }

    private static function minusInicial($texto)
    {
        return mb_strtolower(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($texto, 1, null, 'UTF-8');
    }

    private static function mayusInicial($texto)
    {
        return mb_strtoupper(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($texto, 1, null, 'UTF-8');
    }

    //-----------------------------------------------------------------------
    // Oficios y comunicaciones en gestión ese día: recibidos hasta ese día y sin cerrar ese día
    //-----------------------------------------------------------------------
    private static function datosOficios(&$datos, $ids, $fecha)
    {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $manana = date('Y-m-d', strtotime("$fecha +1 day"));
        $filas = HanaDB::q("SELECT m.ID_PROYECTO, m.TIPO, m.RADICADO, m.ASUNTO, m.REMITENTE, m.FECHA_RECEPCION, m.PENDIENTE_DE,
                                   DATEDIFF(?, m.FECHA_RECEPCION) AS DIAS,
                                   (SELECT h.TEXTO FROM historial h
                                     WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = m.ID_COMUNICACION AND h.FECHA < ?
                                       AND h.TEXTO IS NOT NULL AND h.TEXTO <> ''
                                     ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS VA_EN
                              FROM comunicacion m
                             WHERE m.ESTADO = 1 AND m.TIPO <> '" . TemaCliente::TIPO . "' AND m.ID_PROYECTO IN ($marcas)
                               AND m.FECHA_RECEPCION <= ? AND (m.FECHA_ATENCION IS NULL OR m.FECHA_ATENCION > ?)
                             ORDER BY m.FECHA_RECEPCION, m.ID_COMUNICACION",
                           'ss' . str_repeat('i', count($ids)) . 'ss', array_merge(array($fecha, $manana), $ids, array($fecha, $fecha)));
        $pendientes = HanaConfig::opciones('LISTA_PENDIENTE');
        $plazo = max(0, HanaConfig::num('COMUNICACION_DIAS_RESPUESTA', 2));
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $o = &$datos[(int)$fila['ID_PROYECTO']]['oficios'];
            $o['total']++;
            if ((int)$fila['DIAS'] > $plazo) { $o['fueraPlazo']++; }
            if ($fila['TIPO'] === 'POR_ENVIAR') { $o['porEnviar']++; }
            $o['lista'][] = array('tipo' => $fila['TIPO'], 'radicado' => (string)$fila['RADICADO'], 'asunto' => $fila['ASUNTO'], 'remitente' => $fila['REMITENTE'],
                                  'recepcion' => $fila['FECHA_RECEPCION'], 'dias' => (int)$fila['DIAS'], 'fueraPlazo' => (int)$fila['DIAS'] > $plazo,
                                  'pendiente' => $fila['PENDIENTE_DE'] ? (isset($pendientes[$fila['PENDIENTE_DE']]) ? $pendientes[$fila['PENDIENTE_DE']] : $fila['PENDIENTE_DE']) : '',
                                  'vaEn' => (string)$fila['VA_EN']);
            unset($o);
        }
    }

    private static function textoOficios($o)
    {
        if (!$o['total']) { return 'No hay oficios ni comunicaciones en gestión.'; }
        $lineas = array('Hay ' . $o['total'] . ' en gestión' . ($o['fueraPlazo'] ? ($o['fueraPlazo'] === $o['total'] ? ($o['total'] === 1 ? ', fuera del plazo de respuesta' : ', todos fuera del plazo de respuesta')
                                                                                    : '; ' . $o['fueraPlazo'] . ' fuera del plazo de respuesta')
                                                            : ($o['total'] === 1 ? ', dentro del plazo de respuesta' : ', todos dentro del plazo de respuesta')) . '.');
        foreach ($o['lista'] as $c) {
            $linea = '• ' . ($c['tipo'] === 'POR_ENVIAR' ? '[Por enviar] ' : '[Por atender] ') . self::recortar($c['asunto'], 160) . ' — ' . self::recortar($c['remitente'], 60) .
                     ($c['radicado'] !== '' ? ' (rad. ' . $c['radicado'] . ')' : '') . '. ' .
                     ($c['dias'] === 0 ? 'Recibido en la fecha.' : 'Recibido el ' . date('d/m', strtotime($c['recepcion'])) . '; ' . $c['dias'] . ($c['dias'] === 1 ? ' día' : ' días') . ' en gestión' . ($c['fueraPlazo'] ? ' (fuera de plazo)' : '') . '.');
            if ($c['pendiente'] !== '') { $linea .= ' Responsable del siguiente paso: ' . $c['pendiente'] . '.'; }
            if ($c['vaEn'] !== '') { $linea .= ' Estado: ' . rtrim(self::recortar($c['vaEn'], 200), '.') . '.'; }
            $lineas[] = $linea;
        }
        return implode("\n", $lineas);
    }

    //-----------------------------------------------------------------------
    // Temas con el cliente abiertos ese día, y novedades reportadas ese día
    //-----------------------------------------------------------------------
    private static function temasAbiertos(&$datos, $ids, $fecha)
    {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $filas = HanaDB::q("SELECT ID_PROYECTO, COUNT(*) AS N FROM comunicacion
                             WHERE TIPO = '" . TemaCliente::TIPO . "' AND ESTADO = 1 AND ID_PROYECTO IN ($marcas)
                               AND FECHA_RECEPCION <= ? AND (FECHA_ATENCION IS NULL OR FECHA_ATENCION > ?)
                             GROUP BY ID_PROYECTO", str_repeat('i', count($ids)) . 'ss', array_merge($ids, array($fecha, $fecha)));
        foreach (is_array($filas) ? $filas : array() as $fila) { $datos[(int)$fila['ID_PROYECTO']]['temas']['abiertos'] = (int)$fila['N']; }
    }

    private static function datosNovedades(&$datos, $centros, $fecha)
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
                           str_repeat('i', count($ids)) . 'ss', array_merge($ids, array($fecha, $manana)));
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $idProyecto = $proyectoDe[(int)$fila['ID_CENTRO_OP']];
            if (!isset($datos[$idProyecto])) { continue; }
            //Las novedades se guardan codificadas para HTML
            $datos[$idProyecto]['novedades'][] = array('peaje' => $fila['NOM_CENTRO_OP'], 'prioridad' => (string)$fila['PRIORIDAD'],
                'titulo' => html_entity_decode((string)$fila['TITULO'], ENT_QUOTES, 'UTF-8'),
                'descripcion' => html_entity_decode((string)$fila['DESCRIPCION'], ENT_QUOTES, 'UTF-8'));
        }
    }

    private static function textoNovedades($temas, $novedades, $fecha)
    {
        $lineas = array();
        foreach ($temas['movimientos'] as $tema) {
            $nuevo = $tema['FECHA_RECEPCION'] === $fecha; $cerrado = $tema['FECHA_ATENCION'] === $fecha;
            $estado = $nuevo && $cerrado ? 'se abrió y se cerró en la fecha' : ($nuevo ? 'tema nuevo' : ($cerrado ? 'tema cerrado' : 'seguimiento'));
            $lineas[] = '• Tema con el cliente: ' . self::recortar($tema['ASUNTO'], 160) . ' (con ' . self::recortar($tema['REMITENTE'], 60) . '; ' . $estado . ')' .
                        ($tema['TEXTO_DIA'] ? '. ' . rtrim(self::recortar($tema['TEXTO_DIA'], 300), '.') . '.' : '.');
        }
        $prioridades = array('ALTO' => 'prioridad alta', 'MEDIO' => 'prioridad media', 'BAJO' => 'prioridad baja');
        foreach ($novedades as $n) {
            $lineas[] = '• Novedad en ' . $n['peaje'] . ($n['titulo'] !== '' ? ' — ' . $n['titulo'] : '') .
                        (isset($prioridades[$n['prioridad']]) ? ' (' . $prioridades[$n['prioridad']] . ')' : '') . ': ' . rtrim(self::recortar($n['descripcion'], 200), '.') . '.';
        }
        return count($lineas) ? implode("\n", $lineas) : 'Sin novedades relevantes en la jornada.';
    }

    //-----------------------------------------------------------------------
    // Las cifras del PDF, leídas de los textos tal como quedaron
    // (lo que propuso el sistema o lo que la dirección corrigió). Si en el texto
    // dice «5 de 6» vehículos operativos o «Hay 4 en gestión», el PDF muestra
    // eso, aunque el sistema haya calculado otra cosa. Lo que no se pueda leer
    // del texto queda en null y el PDF lo muestra como «—» (sin dato).
    //   $textos     [idProyecto => [CODIGO => texto]]
    //   $propuestos lo que propone el sistema (textos()) y $base los datos de la base (calcular()): solo se usan
    //               en la promesa de valor, para saber qué renglón corrigió la persona (ver leerPromesa)
    // Devuelve [idProyecto => [
    //   'flota'     => total, operativos, fuera, sinRegistro, vehiculos [placa, estado]
    //   'promesa'   => prometidas, ejecutadas, pct, debajo, encima, sinJustificar, hay (false = sin promesa)
    //   'oficios'   => total, fueraPlazo, porEnviar
    //   'temas'     => abiertos, hoy
    //   'novedades' => cuántas
    // ]]
    //-----------------------------------------------------------------------
    public static function cifrasDeTextos($textos, $propuestos = array(), $base = array())
    {
        $cifras = array();
        foreach ($textos as $id => $t) {
            $nov = self::leerNovedades(isset($t['NOVEDADES']) ? $t['NOVEDADES'] : '');
            $cifras[$id] = array(
                'flota'     => self::segunVinetas('leerFlota', isset($t['FLOTA']) ? $t['FLOTA'] : '', isset($propuestos[$id]['FLOTA']) ? $propuestos[$id]['FLOTA'] : null,
                                                  array('total', 'operativos'), array('total', 'operativos', 'fuera', 'sinRegistro')),
                'promesa'   => self::leerPromesa(isset($t['PROMESA']) ? $t['PROMESA'] : '',
                                                 isset($propuestos[$id]['PROMESA']) ? $propuestos[$id]['PROMESA'] : null,
                                                 isset($base[$id]['promesa']) ? $base[$id]['promesa'] : null),
                'oficios'   => self::segunVinetas('leerOficios', isset($t['OFICIOS']) ? $t['OFICIOS'] : '', isset($propuestos[$id]['OFICIOS']) ? $propuestos[$id]['OFICIOS'] : null,
                                                  array('total', 'fueraPlazo'), array('total', 'fueraPlazo', 'porEnviar')),
                'temas'     => array('abiertos' => $nov['temasAbiertos'], 'hoy' => $nov['temasHoy']),
                'novedades' => $nov['novedades']);
        }
        return $cifras;
    }

    //Flota y oficios tienen un renglón de arriba con las cifras («Vehículos operativos: 3 de 4», «Hay 3 en gestión») y una
    //viñeta por vehículo u oficio. Si la persona cambió el renglón de arriba, vale ese; si lo dejó como lo propuso el
    //sistema pero cambió las viñetas (puso un vehículo inoperativo, quitó o agregó un oficio), valen las viñetas
    //$arriba: las cifras que dice el renglón de arriba; $campos: todas las que se toman de las viñetas si cambiaron
    private static function segunVinetas($leer, $texto, $propuesto, $arriba, $campos)
    {
        $u = self::$leer($texto);
        if ($propuesto === null || trim($propuesto) === '' || trim($texto) === '') { return $u; }
        $s = self::$leer($propuesto);
        foreach ($arriba as $c) { if ($u[$c] !== $s[$c]) { return $u; } }            //se cambió el renglón de arriba
        $soloVinetas = function ($t) { return implode("\n", array_filter(preg_split('/\r?\n/u', (string)$t), function ($l) { return preg_match('/^\s*[•\-\*–—]/u', $l); })); };
        $vu = $soloVinetas($texto); $vs = $soloVinetas($propuesto);
        if ($vu === '' || $vs === '') { return $u; }
        $cu = self::$leer($vu); $cs = self::$leer($vs);
        foreach ($campos as $c) {
            if ($cu[$c] !== $cs[$c]) {                                               //se cambiaron las viñetas
                foreach ($campos as $k) { $u[$k] = $cu[$k]; }
                return $u;
            }
        }
        return $u;
    }

    //Un número escrito en el texto: «5», «1.040», «7,5» o en letras («cinco», «una», «ninguno»). Si no es número, null
    const NUM = '(?<![\p{L}\d.,])(\d[\d.]*(?:,\d+)?|cero|ning[uú]n[oa]?|un[oa]?|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince|diecis[eé]is|diecisiete|dieciocho|diecinueve|veinte)';
    private static function numero($texto)
    {
        $texto = mb_strtolower(trim((string)$texto), 'UTF-8');
        if (preg_match('/^\d[\d.]*(,\d+)?$/u', $texto)) {
            //El punto separa miles y la coma los decimales (como se escriben en los textos): 1.040 -> 1040, 7,5 -> 7.5
            return (float)str_replace(array('.', ','), array('', '.'), $texto);
        }
        $letras = array('cero' => 0, 'ninguno' => 0, 'ninguna' => 0, 'ningun' => 0, 'ningún' => 0, 'un' => 1, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3,
                        'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10, 'once' => 11, 'doce' => 12,
                        'trece' => 13, 'catorce' => 14, 'quince' => 15, 'dieciseis' => 16, 'dieciséis' => 16, 'diecisiete' => 17, 'dieciocho' => 18,
                        'diecinueve' => 19, 'veinte' => 20);
        return isset($letras[$texto]) ? (float)$letras[$texto] : null;
    }
    private static function entero($texto) { $n = self::numero($texto); return $n === null ? null : (int)round($n); }

    //Separa un texto en renglones: [['vineta' => bool, 'nivel' => 1|2, 'texto' => sin la viñeta]]
    private static function renglonesDe($texto)
    {
        $lista = array();
        foreach (preg_split('/\r?\n/u', (string)$texto) as $linea) {
            if (trim($linea) === '') { continue; }
            if (preg_match('/^\s*[–—]\s*(.*)$/u', $linea, $m) || preg_match('/^\s{2,}[•\-\*]\s*(.*)$/u', $linea, $m)) { $lista[] = array('vineta' => true, 'nivel' => 2, 'texto' => trim($m[1])); }
            elseif (preg_match('/^\s*[•\-\*]\s*(.*)$/u', $linea, $m)) { $lista[] = array('vineta' => true, 'nivel' => 1, 'texto' => trim($m[1])); }
            else { $lista[] = array('vineta' => false, 'nivel' => 0, 'texto' => trim($linea)); }
        }
        return $lista;
    }

    //Flota: «Vehículos operativos: 3 de 4.» y una viñeta por vehículo («• ABC123: operativo.»)
    private static function leerFlota($texto)
    {
        $f = array('total' => null, 'operativos' => null, 'fuera' => null, 'sinRegistro' => null, 'vehiculos' => array());
        if (trim($texto) === '') { return $f; }
        if (preg_match('/no tiene veh[ií]culos|sin veh[ií]culos asignados/iu', $texto)) {
            return array('total' => 0, 'operativos' => 0, 'fuera' => 0, 'sinRegistro' => 0, 'vehiculos' => array());
        }
        $cuenta = array('OPERATIVO' => 0, 'FUERA' => 0, 'SIN_REGISTRO' => 0);
        $encabezado = '';
        foreach (self::renglonesDe($texto) as $r) {
            if (!$r['vineta']) { $encabezado .= ' ' . $r['texto']; continue; }
            if ($r['nivel'] !== 1 || !preg_match('/^([^:]{1,40}):\s*(.*)$/u', $r['texto'], $m)) { continue; }
            $estado = $m[2];
            if (preg_match('/inoperativ|no operativ|fuera de servicio|varad|da[ñn]ad|taller|mantenimiento/iu', $estado)) { $e = 'FUERA'; }
            elseif (preg_match('/sin reporte|sin registro|no report|sin estado/iu', $estado)) { $e = 'SIN_REGISTRO'; }
            elseif (preg_match('/operativ|disponible|funcionando|en servicio/iu', $estado)) { $e = 'OPERATIVO'; }
            else { $e = 'SIN_REGISTRO'; }
            $cuenta[$e]++;
            $f['vehiculos'][] = array('placa' => trim(preg_replace('/\s*\(.*$/u', '', $m[1])), 'estado' => $e);
        }
        $n = self::NUM;
        //«operativos: 3 de 4» o «3 de 4 vehículos operativos»
        if (preg_match('/operativos?\s*:?\s*' . $n . '\s+de\s+' . $n . '/iu', $encabezado, $m) || preg_match('/' . $n . '\s+de\s+' . $n . '\s+veh[ií]culos?\s+(?:est[aá]n\s+)?operativ/iu', $encabezado, $m)) {
            $f['operativos'] = self::entero($m[1]); $f['total'] = self::entero($m[2]);
        }
        if ($f['total'] !== null && $f['operativos'] !== null) {
            $f['operativos'] = min($f['operativos'], $f['total']);
            $resto = $f['total'] - $f['operativos'];
            //Si el encabezado dice cuántos están inoperativos o sin reporte, vale eso; si no, se toma de las viñetas
            $fuera = preg_match('/' . $n . '\s+(?:veh[ií]culos?\s+)?(?:inoperativos?|fuera de servicio)/iu', $encabezado, $m) ? self::entero($m[1]) : null;
            $sin = preg_match('/' . $n . '\s+(?:veh[ií]culos?\s+)?sin reporte/iu', $encabezado, $m) ? self::entero($m[1]) : null;
            if ($fuera === null && $sin === null) { $sin = min($resto, $cuenta['SIN_REGISTRO']); $fuera = $resto - $sin; }
            elseif ($fuera === null) { $sin = min($resto, $sin); $fuera = $resto - $sin; }
            else { $fuera = min($resto, $fuera); $sin = $resto - $fuera; }
            $f['fuera'] = $fuera; $f['sinRegistro'] = $sin;
        } elseif (count($f['vehiculos'])) {
            $f['total'] = count($f['vehiculos']); $f['operativos'] = $cuenta['OPERATIVO'];
            $f['fuera'] = $cuenta['FUERA']; $f['sinRegistro'] = $cuenta['SIN_REGISTRO'];
        }
        return $f;
    }

    //Promesa: «…: se ejecutaron 518 de 527 horas prometidas (98,3 %).», una viñeta por peaje («• ANDES: no cumple (97,8 %).»)
    //y debajo de cada peaje sus casos («– Recaudador: se ejecutaron 16 de 24 horas (faltaron 8)…»).
    //También lee los reportes viejos que se guardaron con la redacción semanal («Semana del…», «– Martes 27…, Recaudador:»)
    //Para saber qué corrigió la persona se compara con lo que propone el sistema ($propuesto) y con las horas de
    //cada peaje en la base ($base): manda lo que se haya cambiado, del nivel que sea.
    //  - si se cambió el renglón del total del día: valen sus horas y su porcentaje (si solo se cambiaron las horas,
    //    el porcentaje se recalcula con ellas);
    //  - si no, pero se cambió el porcentaje de un peaje o las horas de un caso: se recalcula el total del día con eso;
    //  - si no se cambió nada: lo que dice el texto.
    private static function leerPromesa($texto, $propuesto = null, $base = null)
    {
        $p = array('prometidas' => null, 'ejecutadas' => null, 'pct' => null, 'debajo' => null, 'encima' => null, 'sinJustificar' => null, 'hay' => true,
                   'peajes' => 0, 'peajesCumplen' => 0, 'peajesSinEstado' => 0);
        if (trim($texto) === '') { return $p; }
        if (preg_match('/no hay promesa|sin promesa/iu', $texto)) { $p['hay'] = false; $p['debajo'] = 0; $p['encima'] = 0; $p['sinJustificar'] = 0; return $p; }
        $u = self::partesPromesa($texto);
        $p['prometidas'] = $u['prometidas']; $p['ejecutadas'] = $u['ejecutadas'];
        $p['debajo'] = $u['debajo']; $p['encima'] = $u['encima']; $p['sinJustificar'] = $u['sinJustificar'];
        $s = ($propuesto !== null && trim($propuesto) !== '' && !preg_match('/no hay promesa/iu', $propuesto)) ? self::partesPromesa($propuesto) : null;

        if ($s === null) {
            //Sin con qué comparar: el porcentaje escrito; si no hay, con las horas; si tampoco, con los peajes
            $p['pct'] = $u['pct'] !== null ? $u['pct'] : self::pctDeHoras($u['ejecutadas'], $u['prometidas']);
            if ($p['pct'] === null) { $p['pct'] = self::pctDePeajes($u['peajes'], $base); }
            return self::conPeajes($p, $u);
        }

        $cambiaHoras = !self::igual($u['ejecutadas'], $s['ejecutadas']) || !self::igual($u['prometidas'], $s['prometidas']);
        $cambiaPct = !self::igual($u['pct'], $s['pct']);
        if ($cambiaHoras || $cambiaPct) {
            //Se corrigió el renglón del total del día
            if ($cambiaHoras && !$cambiaPct) { $p['pct'] = self::pctDeHoras($u['ejecutadas'], $u['prometidas']); }
            else { $p['pct'] = $u['pct'] !== null ? $u['pct'] : self::pctDeHoras($u['ejecutadas'], $u['prometidas']); }
            if ($p['pct'] === null) { $p['pct'] = self::pctDePeajes($u['peajes'], $base); }
            //Si el porcentaje escrito no cuadra con las horas escritas, las horas no se muestran (vale el porcentaje)
            $deHoras = self::pctDeHoras($u['ejecutadas'], $u['prometidas']);
            if ($deHoras !== null && $p['pct'] !== null && abs($deHoras - $p['pct']) > 0.15) { $p['ejecutadas'] = null; }
            return self::conPeajes($p, $u);
        }

        //El renglón del total del día sigue igual: se mira si cambió algún peaje o algún caso
        $horasBase = array();
        foreach (isset($base['peajes']) ? $base['peajes'] : array() as $peaje) {
            $horasBase[self::clave($peaje['nombre'])] = array((float)$peaje['prometidas'], (float)$peaje['ejecutadas']);
        }
        $peajesS = array();
        foreach ($s['peajes'] as $peaje) { $peajesS[self::clave($peaje['nombre'])] = $peaje; }
        $prometidas = 0; $ejecutadas = 0; $cambio = false;
        $vistos = array();
        foreach ($u['peajes'] as $peaje) {
            $k = self::clave($peaje['nombre']); $vistos[$k] = true;
            $antes = isset($peajesS[$k]) ? $peajesS[$k] : null;
            if (!isset($horasBase[$k])) {
                continue;   //un peaje que no está en la base (escrito a mano): no tiene horas con qué contarlo
            }
            list($pp, $ee) = $horasBase[$k];
            if ($antes !== null && !self::igual($peaje['pct'], $antes['pct']) && $peaje['pct'] !== null) {
                //Se corrigió el porcentaje del peaje
                $ee = $pp * $peaje['pct'] / 100; $cambio = true;
            } elseif ($antes !== null) {
                //Se corrigieron las horas de algún caso (o se agregó o quitó uno): se suma la diferencia
                $dp = 0; $de = 0;
                $casosS = array();
                foreach ($antes['casos'] as $c) { $casosS[$c['clave']][] = $c; }
                foreach ($peaje['casos'] as $c) {
                    if (isset($casosS[$c['clave']]) && count($casosS[$c['clave']])) {
                        $a = array_shift($casosS[$c['clave']]);
                        if ($c['prometidas'] !== null && $a['prometidas'] !== null) { $dp += $c['prometidas'] - $a['prometidas']; }
                        $de += ($c['desvio'] - $a['desvio']) + (($c['prometidas'] !== null && $a['prometidas'] !== null) ? $c['prometidas'] - $a['prometidas'] : 0);
                    } else {
                        $de += $c['desvio'];                       //un caso nuevo
                    }
                }
                foreach ($casosS as $quedan) { foreach ($quedan as $a) { $de -= $a['desvio']; } }   //casos que se quitaron
                if (abs($dp) > 0.001 || abs($de) > 0.001) { $cambio = true; }
                $pp += $dp; $ee += $de;
            }
            $prometidas += $pp; $ejecutadas += $ee;
        }
        //Los peajes que se borraron del texto siguen contando con lo de la base
        foreach ($horasBase as $k => $h) { if (!isset($vistos[$k])) { $prometidas += $h[0]; $ejecutadas += $h[1]; } }
        if ($cambio && $prometidas > 0) {
            $p['prometidas'] = round($prometidas, 2); $p['ejecutadas'] = round(max(0, $ejecutadas), 2);
            $p['pct'] = 100 * $p['ejecutadas'] / $p['prometidas'];
        } else {
            $p['pct'] = $u['pct'] !== null ? $u['pct'] : self::pctDeHoras($u['ejecutadas'], $u['prometidas']);
            if ($p['pct'] === null) { $p['pct'] = self::pctDePeajes($u['peajes'], $base); }
        }
        return self::conPeajes($p, $u);
    }

    //Lee las partes del texto de la promesa: el renglón del total del día, cada peaje y sus casos
    private static function partesPromesa($texto)
    {
        $n = self::NUM;
        $r = array('ejecutadas' => null, 'prometidas' => null, 'pct' => null, 'peajes' => array(), 'debajo' => null, 'encima' => null, 'sinJustificar' => null);
        $encabezado = ''; $debajo = 0; $encima = 0; $sinJust = 0; $noCumple = 0;
        foreach (self::renglonesDe($texto) as $renglon) {
            $t = $renglon['texto'];
            if (!$renglon['vineta']) { $encabezado .= ' ' . $t; continue; }
            if ($renglon['nivel'] === 1) {
                $nombre = preg_match('/^([^:]{1,60}):/u', $t, $m) ? trim($m[1]) : $t;
                $estado = preg_match('/no cumple|incumpl/iu', $t) ? 'NO' : (preg_match('/\bcumple/iu', $t) ? 'SI' : (preg_match('/pendiente/iu', $t) ? 'PENDIENTE' : ''));
                if ($estado === 'NO') { $noCumple++; }
                //Si el renglón del peaje dice cuántas horas faltaron o sobraron («faltaron 10 h»), también se lee
                $desvio = null;
                if (preg_match('/(?:falt[oó]|faltaron)\s+' . $n . '\s*(?:h\b|horas?)/iu', $t, $m) && self::numero($m[1]) !== null) { $desvio = -self::numero($m[1]); }
                elseif (preg_match('/' . $n . '\s*(?:h\b|horas?)\s+(?:adicional|de m[aá]s)/iu', $t, $m) && self::numero($m[1]) !== null) { $desvio = self::numero($m[1]); }
                $r['peajes'][] = array('nombre' => $nombre, 'estado' => $estado, 'desvio' => $desvio,
                                       'pct' => preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $t, $m) ? (float)str_replace(',', '.', $m[1]) : null, 'casos' => array());
                continue;
            }
            //Un caso de un peaje (día y cargo)
            $tipo = preg_match('/falt[oóa]|faltaron|por debajo|menos/iu', $t) ? 'DEBAJO' : (preg_match('/adicional|por encima|de m[aá]s/iu', $t) ? 'ENCIMA' : '');
            if ($tipo === '') { continue; }
            if ($tipo === 'DEBAJO') { $debajo++; } else { $encima++; }
            if (preg_match('/sin justificaci/iu', $t)) { $sinJust++; }
            $caso = array('clave' => self::clave(preg_match('/^([^:]{1,80}):/u', $t, $m) ? $m[1] : $t), 'prometidas' => null, 'desvio' => 0.0);
            if (preg_match('/' . $n . '\s+de\s+' . $n . '\s+horas?/iu', $t, $m)) {
                $e = self::numero($m[1]); $pr = self::numero($m[2]);
                if ($e !== null && $pr !== null) { $caso['prometidas'] = $pr; $caso['desvio'] = $e - $pr; }
            } elseif (preg_match('/(?:falt[oó]|faltaron)\s+' . $n . '/iu', $t, $m) && self::numero($m[1]) !== null) {
                $caso['desvio'] = -self::numero($m[1]);
            } elseif (preg_match('/' . $n . '\s+horas?\s+adicional/iu', $t, $m) && self::numero($m[1]) !== null) {
                $caso['desvio'] = self::numero($m[1]);
            }
            if (count($r['peajes'])) { $r['peajes'][count($r['peajes']) - 1]['casos'][] = $caso; }
        }
        if (preg_match('/' . $n . '\s+de\s+' . $n . '\s+horas/iu', $encabezado, $m)) { $r['ejecutadas'] = self::numero($m[1]); $r['prometidas'] = self::numero($m[2]); }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $encabezado, $m)) { $r['pct'] = (float)str_replace(',', '.', $m[1]); }
        $peajes = count($r['peajes']);
        //Los casos por debajo: si el texto dice cuántos («3 casos por debajo»), vale eso; si no, se cuentan
        $r['debajo'] = preg_match('/' . $n . '\s+(?:casos?|d[ií]as?)\s+por\s+debajo/iu', $encabezado, $m) ? self::entero($m[1]) : ($debajo ? $debajo : ($peajes ? $noCumple : null));
        $r['encima'] = $peajes || $encima ? $encima : null;
        $r['sinJustificar'] = $peajes || $debajo || $encima ? $sinJust : null;
        return $r;
    }

    private static function pctDeHoras($e, $p) { return ($e !== null && $p) ? 100 * $e / $p : null; }

    //El porcentaje del día sacado de los peajes, cuando el renglón del total del día no lo dice. Para cada peaje:
    //el porcentaje que tenga escrito; si dice «cumple», 100 %; si dice cuántas horas faltaron («faltaron 10 h», en su
    //renglón o en sus casos) y se tienen sus horas prometidas en la base, se calcula. Se pesa por las horas prometidas
    //de cada peaje (si no se tienen, todos pesan igual). Si de algún peaje no se puede saber, null
    private static function pctDePeajes($peajes, $base)
    {
        if (!count($peajes)) { return null; }
        $horas = array();
        foreach (isset($base['peajes']) ? $base['peajes'] : array() as $b) { $horas[self::clave($b['nombre'])] = (float)$b['prometidas']; }
        $suma = 0; $peso = 0;
        foreach ($peajes as $peaje) {
            $prom = isset($horas[self::clave($peaje['nombre'])]) ? $horas[self::clave($peaje['nombre'])] : 0;
            $desvio = $peaje['desvio'];
            if ($desvio === null && count($peaje['casos'])) { $desvio = 0; foreach ($peaje['casos'] as $c) { $desvio += $c['desvio']; } }
            if ($peaje['pct'] !== null) { $pct = $peaje['pct']; }
            elseif ($peaje['estado'] === 'SI' && !$desvio) { $pct = 100.0; }
            elseif ($desvio !== null && $prom > 0) { $pct = 100 * max(0, $prom + $desvio) / $prom; }
            else { return null; }
            $w = $prom > 0 ? $prom : 1;
            $suma += $pct * $w; $peso += $w;
        }
        return $peso ? $suma / $peso : null;
    }

    //Cuántos peajes cumplen según el texto: el PDF lo muestra («2 de 3 peajes») cuando no hay porcentaje
    private static function conPeajes($p, $u)
    {
        $p['peajes'] = count($u['peajes']); $p['peajesCumplen'] = 0; $p['peajesSinEstado'] = 0;
        foreach ($u['peajes'] as $peaje) {
            if ($peaje['estado'] === 'SI') { $p['peajesCumplen']++; }
            elseif ($peaje['estado'] !== 'NO') { $p['peajesSinEstado']++; }
        }
        return $p;
    }

    private static function igual($a, $b) { return ($a === null && $b === null) || ($a !== null && $b !== null && abs((float)$a - (float)$b) < 0.05); }
    private static function clave($texto) { return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$texto)), 'UTF-8'); }

    //Oficios: «Hay 3 en gestión; 1 fuera del plazo de respuesta.» y una viñeta por oficio («• [Por enviar] …»)
    private static function leerOficios($texto)
    {
        $o = array('total' => null, 'fueraPlazo' => null, 'porEnviar' => null);
        if (trim($texto) === '') { return $o; }
        $n = self::NUM;
        $encabezado = ''; $vinetas = 0; $fueraV = 0; $enviarV = 0;
        $plazo = max(0, HanaConfig::num('COMUNICACION_DIAS_RESPUESTA', 2));
        foreach (self::renglonesDe($texto) as $r) {
            if (!$r['vineta']) { $encabezado .= ' ' . $r['texto']; continue; }
            if ($r['nivel'] !== 1) { continue; }
            $vinetas++;
            if (preg_match('/fuera de(l)? plazo/iu', $r['texto'])) { $fueraV++; }
            elseif (!preg_match('/dentro de(l)? plazo/iu', $r['texto']) && preg_match('/(\d+)\s+d[ií]as?(?:\s+en\s+gesti[oó]n)?\s*[.;,)]/u', $r['texto'], $m) && (int)$m[1] > $plazo) { $fueraV++; }
            if (preg_match('/por enviar/iu', $r['texto'])) { $enviarV++; }
        }
        if (preg_match('/no hay (oficios|comunicaciones)|sin (oficios|comunicaciones)|ning[uú]n (oficio|comunicaci)/iu', $encabezado)) {
            $o['total'] = 0;
        } elseif (preg_match('/hay\s+' . $n . '\b/iu', $encabezado, $m) || preg_match('/' . $n . '\s+(?:oficios?|comunicaciones?)?\s*(?:y\s+comunicaciones?\s+)?en gesti[oó]n/iu', $encabezado, $m)) {
            $o['total'] = self::entero($m[1]);
        } elseif ($vinetas) {
            $o['total'] = $vinetas;
        }
        if ($o['total'] === 0) { return array('total' => 0, 'fueraPlazo' => 0, 'porEnviar' => 0); }
        if (preg_match('/' . $n . '\s+(?:de ellos\s+)?fuera de(l)? plazo/iu', $encabezado, $m)) { $o['fueraPlazo'] = self::entero($m[1]); }
        elseif (preg_match('/todos?\s+fuera de(l)? plazo/iu', $encabezado)) { $o['fueraPlazo'] = $o['total']; }
        elseif (preg_match('/fuera de(l)? plazo/iu', $encabezado) && $o['total'] === 1) { $o['fueraPlazo'] = 1; }
        elseif (preg_match('/dentro de(l)? plazo/iu', $encabezado)) { $o['fueraPlazo'] = 0; }
        elseif ($vinetas) { $o['fueraPlazo'] = $fueraV; }
        $o['porEnviar'] = preg_match('/' . $n . '\s+por enviar/iu', $encabezado, $m) ? self::entero($m[1]) : ($vinetas ? $enviarV : null);
        if ($o['total'] !== null) {
            if ($o['fueraPlazo'] !== null) { $o['fueraPlazo'] = min($o['fueraPlazo'], $o['total']); }
            if ($o['porEnviar'] !== null) { $o['porEnviar'] = min($o['porEnviar'], $o['total']); }
        }
        return $o;
    }

    //Novedades: una viñeta por tema con el cliente o novedad, «Sin novedades relevantes…» y «Temas con el cliente abiertos: 2.»
    private static function leerNovedades($texto)
    {
        $r = array('novedades' => null, 'temasHoy' => null, 'temasAbiertos' => null);
        if (trim($texto) === '') { return $r; }
        $n = self::NUM;
        $temas = 0; $novedades = 0; $parrafos = 0; $sin = false; $libre = '';
        foreach (self::renglonesDe($texto) as $renglon) {
            $t = $renglon['texto'];
            //Los temas abiertos: «Temas con el cliente abiertos: 2» o «2 temas con el cliente abiertos» (en un renglón sin viñeta)
            if (!$renglon['vineta'] && (preg_match('/temas?\s+(?:con\s+el\s+cliente\s+)?abiertos?\s*:\s*' . $n . '/iu', $t, $m) || preg_match('/' . $n . '\s+temas?\s+(?:con\s+el\s+cliente\s+)?abiertos?/iu', $t, $m))) {
                $r['temasAbiertos'] = self::entero($m[1]); continue;
            }
            if (preg_match('/sin novedad|no (hay|hubo|se presentaron|se reportaron) novedad|ninguna novedad/iu', $t)) { $sin = true; continue; }
            if ($renglon['nivel'] === 2) { continue; }
            if (!$renglon['vineta']) { $parrafos++; $libre .= ' ' . $t; continue; }
            if (preg_match('/^tema/iu', $t) || preg_match('/\(con [^;)]+;\s*(seguimiento|tema nuevo|tema cerrado|se cerr[oó]|se abri[oó])/iu', $t)) { $temas++; } else { $novedades++; }
        }
        $r['temasHoy'] = $temas;
        //Si un renglón sin viñeta dice cuántas («Se reportaron 5 novedades»), vale eso; si no, se cuentan las viñetas
        if (preg_match('/' . $n . '\s+novedad(es)?/iu', $libre, $m) && self::entero($m[1]) !== null) { $r['novedades'] = self::entero($m[1]); }
        elseif ($novedades || $temas) { $r['novedades'] = $novedades; }
        elseif ($sin) { $r['novedades'] = 0; }
        elseif ($parrafos) { $r['novedades'] = $parrafos; }
        return $r;
    }

    //-----------------------------------------------------------------------
    // Guardado (historial)
    //-----------------------------------------------------------------------

    //Lo guardado de un día: ['datos' => arreglo, 'quien' => nombre, 'fecha' => cuándo] o null.
    //Si quedan copias viejas de antes (de cuando se guardaba una fila por cada guardado), vale la última
    public static function guardado($fecha)
    {
        $fila = HanaDB::fila("SELECT h.TEXTO, h.FECHA, col.NOM_COLABORADOR AS QUIEN
                                FROM historial h LEFT JOIN colaboradores col ON col.ID_COLABORADOR = h.ID_COLABORADOR
                               WHERE h.MODULO = ? AND h.ID_REGISTRO = ? AND h.TIPO = ?
                               ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1",
                             'sis', array(self::MODULO, self::numeroDia($fecha), self::TIPO));
        if (!$fila) { return null; }
        $datos = json_decode((string)$fila['TEXTO'], true);
        if (!is_array($datos)) { return null; }
        return array('datos' => $datos, 'quien' => $fila['QUIEN'], 'fecha' => $fila['FECHA']);
    }

    //¿Cabe el reporte en historial.TEXTO? (TEXT: 65.535 bytes)
    public static function cabe($datos)
    {
        $texto = json_encode($datos, JSON_UNESCAPED_UNICODE);
        return $texto !== false && strlen($texto) <= self::LARGO_TOTAL;
    }

    //Guarda el reporte de un día en SU fila: si ya existe se actualiza (texto, quién y cuándo) y,
    //si quedaban copias viejas del mismo día, se borran. Todo en una transacción: si algo falla,
    //queda lo que había. Devuelve true si se guardó
    public static function guardar($fecha, $datos, $idColaborador, $ahora)
    {
        if (!self::cabe($datos)) { return false; }
        $texto = json_encode($datos, JSON_UNESCAPED_UNICODE);
        $dia = self::numeroDia($fecha);
        $filas = HanaDB::q("SELECT ID_HISTORIAL FROM historial WHERE MODULO = ? AND ID_REGISTRO = ? AND TIPO = ?
                             ORDER BY FECHA DESC, ID_HISTORIAL DESC", 'sis', array(self::MODULO, $dia, self::TIPO));
        if ($filas === false) { return false; }
        if (!count($filas)) {
            return HanaDB::q("INSERT INTO historial (MODULO, ID_REGISTRO, TIPO, TEXTO, ID_COLABORADOR, FECHA) VALUES (?, ?, ?, ?, NULLIF(?, 0), ?)",
                             'sissis', array(self::MODULO, $dia, self::TIPO, $texto, (int)$idColaborador, $ahora)) === true;
        }
        $idFila = (int)$filas[0]['ID_HISTORIAL'];
        HanaDB::iniciar();
        $ok = HanaDB::q("UPDATE historial SET TEXTO = ?, ID_COLABORADOR = NULLIF(?, 0), FECHA = ? WHERE ID_HISTORIAL = ?",
                        'sisi', array($texto, (int)$idColaborador, $ahora, $idFila)) === true;
        if ($ok && count($filas) > 1) {
            //Las copias viejas de ese mismo día (solo las del reporte a la Gerencia)
            $ok = HanaDB::q("DELETE FROM historial WHERE MODULO = ? AND ID_REGISTRO = ? AND TIPO = ? AND ID_HISTORIAL <> ?",
                            'sisi', array(self::MODULO, $dia, self::TIPO, $idFila)) === true;
        }
        if ($ok) { HanaDB::confirmar(); } else { HanaDB::deshacer(); }
        return $ok;
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

    //Los días guardados de un mes ("2026-11"), del primero al último
    public static function diasGuardadosMes($mes)
    {
        $dias = self::diasGuardados($mes . '-01', date('Y-m-t', strtotime($mes . '-01')));
        sort($dias);
        return $dias;
    }

    //Los días que ya tienen reporte guardado entre dos fechas (incluidas): ["2026-11-01", ...]
    public static function diasGuardados($desde, $hasta)
    {
        $filas = HanaDB::q("SELECT DISTINCT ID_REGISTRO FROM historial WHERE MODULO = ? AND TIPO = ? AND ID_REGISTRO BETWEEN ? AND ?
                             ORDER BY ID_REGISTRO DESC", 'ssii', array(self::MODULO, self::TIPO, self::numeroDia($desde), self::numeroDia($hasta)));
        $dias = array();
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $n = (string)$fila['ID_REGISTRO'];
            if (strlen($n) === 8) { $dias[] = substr($n, 0, 4) . '-' . substr($n, 4, 2) . '-' . substr($n, 6, 2); }
        }
        return $dias;
    }
}
