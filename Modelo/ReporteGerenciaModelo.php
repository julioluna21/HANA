<?php
/*
  HANA - Reporte diario a la Gerencia
  ---------------------------------------------------------------------------
  Lo arma la dirección cada día (también sábados, domingos y festivos), por
  proyecto, con lo que llenan los coordinadores en el Reporte diario. Por
  defecto tiene cuatro puntos, que se llenan solos y luego se corrigen a mano:

    FLOTA      Disponibilidad de flota: cada vehículo del proyecto, operativo o no
               (Cronograma y vehículo, tabla vehiculo_dia).
    PROMESA    Cumplimiento de la promesa de valor en la semana, de lunes al día
               del reporte, por peaje: cumple o no cumple y qué días (pv_dia).
    OFICIOS    Oficios y comunicaciones en gestión ese día (comunicacion).
    NOVEDADES  Novedades relevantes: los temas con el cliente que se movieron ese
               día y las novedades reportadas ese día en sus peajes. Si no hay,
               «Sin novedades relevantes en la jornada».

  Los textos se escriben en tono formal, porque van para la Gerencia. Las
  cifras de cada punto (vehículos operativos, horas ejecutadas contra las
  prometidas, oficios fuera de plazo...) también las usa el PDF del reporte
  (Vista/pdf/ReporteGerenciaPdf.php) para los porcentajes y las gráficas.

  Los nombres y el orden de los puntos se cambian en Parámetros del sistema →
  Listas (LISTA_PUNTOS_GERENCIA). Los que se agreguen allí salen en blanco.

  No tiene tabla propia. Cada vez que se guarda queda una fila nueva en historial:
    MODULO = 'GERENCIA', ID_REGISTRO = el día como número AAAAMMDD (20261101),
    TIPO = 'REPORTE', TEXTO = el reporte en JSON, ID_COLABORADOR y FECHA.
  El reporte de un día es la última fila guardada de ese día; las anteriores
  quedan como historia (quién lo cambió y cuándo).
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

    //El lunes de la semana de un día
    public static function lunesDe($fecha)
    {
        return date('Y-m-d', strtotime($fecha . ' -' . (((int)date('N', strtotime($fecha))) - 1) . ' days'));
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
    // Promesa de valor de la semana (lunes al día del reporte), por peaje y cargo
    //-----------------------------------------------------------------------
    private static function datosPromesa(&$datos, $centrosPorProyecto, $fecha)
    {
        $lunes = self::lunesDe($fecha);
        $idsCentros = array();
        foreach ($centrosPorProyecto as $lista) { foreach (array_keys($lista) as $idCentro) { $idsCentros[] = (int)$idCentro; } }
        if (!count($idsCentros)) { return; }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $filas = HanaDB::q("SELECT d.ID_CENTRO_OP, d.FECHA, a.NOMBRE AS CARGO, d.PROMESA_HORAS, d.EJECUTADO_HORAS, d.JUSTIFICACION
                              FROM pv_dia d INNER JOIN aus_cargo a ON a.ID_CARGO_AUS = d.ID_CARGO_AUS
                             WHERE d.ID_CENTRO_OP IN ($marcas) AND d.FECHA >= ? AND d.FECHA <= ? AND d.PROMESA_HORAS IS NOT NULL
                             ORDER BY d.FECHA, a.ORDEN, a.NOMBRE",
                           str_repeat('i', count($idsCentros)) . 'ss', array_merge($idsCentros, array($lunes, $fecha)));
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

    private static function textoPromesa($p, $fecha)
    {
        if (!count($p['peajes'])) { return 'No hay promesa de valor registrada para la semana.'; }
        $lunes = self::lunesDe($fecha);
        $semana = $lunes === $fecha ? 'Semana que inicia el ' . self::diaLargo($lunes) : 'Semana del ' . self::diaLargo($lunes) . ' al ' . self::diaLargo($fecha);
        $lineas = array($semana . ': ' . ($p['prometidas'] > 0
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
                $linea .= "\n   – " . self::mayusInicial(self::diaLargo($c['fecha'])) . ', ' . $c['cargo'] . ': se ejecutaron ' . self::horas($c['ejecutado']) . ' de ' . self::enHoras($c['promesa']) .
                          ' (' . ($c['diferencia'] == 1 ? 'faltó 1' : 'faltaron ' . self::horas($c['diferencia'])) . '). ' . ($c['justificacion'] !== '' ? 'Justificación: ' . rtrim(self::recortar($c['justificacion'], 160), '.') . '.' : 'Sin justificación registrada.');
            }
            foreach ($peaje['encima'] as $c) {
                $linea .= "\n   – " . self::mayusInicial(self::diaLargo($c['fecha'])) . ', ' . $c['cargo'] . ': se ejecutaron ' . self::horas($c['ejecutado']) . ' de ' . self::enHoras($c['promesa']) .
                          ' (' . self::enHoras($c['diferencia']) . ' adicional' . ($c['diferencia'] == 1 ? '' : 'es') . '). ' .
                          ($c['justificacion'] !== '' ? 'Justificación: ' . rtrim(self::recortar($c['justificacion'], 160), '.') . '.' : 'Sin justificación registrada.');
            }
            if (count($peaje['sinRegistro'])) {
                $partes = array();
                foreach ($peaje['sinRegistro'] as $dia => $cargos) {
                    $partes[] = self::diaLargo($dia) . (count($cargos) === $peaje['cargosDia'][$dia] ? '' : ' (' . implode(', ', $cargos) . ')');
                }
                $linea .= "\n   – Pendiente registrar lo ejecutado del " . implode('; ', $partes) . '.';
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
    // Guardado (historial)
    //-----------------------------------------------------------------------

    //La última versión guardada de un día: ['datos' => arreglo, 'quien' => nombre, 'fecha' => cuándo, 'versiones' => n] o null
    public static function guardado($fecha)
    {
        $fila = HanaDB::fila("SELECT h.TEXTO, h.FECHA, col.NOM_COLABORADOR AS QUIEN,
                                     (SELECT COUNT(*) FROM historial h2 WHERE h2.MODULO = h.MODULO AND h2.ID_REGISTRO = h.ID_REGISTRO AND h2.TIPO = h.TIPO) AS VERSIONES
                                FROM historial h LEFT JOIN colaboradores col ON col.ID_COLABORADOR = h.ID_COLABORADOR
                               WHERE h.MODULO = ? AND h.ID_REGISTRO = ? AND h.TIPO = ?
                               ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1",
                             'sis', array(self::MODULO, self::numeroDia($fecha), self::TIPO));
        if (!$fila) { return null; }
        $datos = json_decode((string)$fila['TEXTO'], true);
        if (!is_array($datos)) { return null; }
        return array('datos' => $datos, 'quien' => $fila['QUIEN'], 'fecha' => $fila['FECHA'], 'versiones' => (int)$fila['VERSIONES']);
    }

    //Guarda una versión nueva del reporte de un día
    public static function guardar($fecha, $datos, $idColaborador, $ahora)
    {
        $texto = json_encode($datos, JSON_UNESCAPED_UNICODE);
        if ($texto === false || strlen($texto) > self::LARGO_TOTAL) { return false; }
        return HanaDB::q("INSERT INTO historial (MODULO, ID_REGISTRO, TIPO, TEXTO, ID_COLABORADOR, FECHA) VALUES (?, ?, ?, ?, NULLIF(?, 0), ?)",
                         'sissis', array(self::MODULO, self::numeroDia($fecha), self::TIPO, $texto, (int)$idColaborador, $ahora));
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
