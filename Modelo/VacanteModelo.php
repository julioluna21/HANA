<?php
/*
  HANA — Vacantes y comunicaciones (Reporte diario, Fase 2)
  Las hojas "Vacantes" y "Comunicaciones y SOLIC" del Excel del reporte diario.
  Los resultados que en Excel eran fórmulas (días, cumple / no cumple, abierto
  o cerrado) se calculan aquí, bien: una vacante abierta ya no da días
  negativos ni "CUMPLE" por error.
*/
require_once __DIR__ . "/HanaDB.php";
require_once __DIR__ . "/HanaFechas.php";
require_once __DIR__ . "/HanaConfig.php";

class Vacante
{
    //Días hábiles para cubrir una vacante (Parámetros del sistema: VACANTE_DIAS_ANS)
    public static function diasAns() { return max(1, HanaConfig::num('VACANTE_DIAS_ANS', 5)); }

    public static $ESTADOS = array('ABIERTA' => 'Abierta', 'CUBIERTA' => 'Cubierta', 'CANCELADA' => 'Cancelada');
    public static $ESTADOS_GH = array('RECLUTAMIENTO' => 'Reclutamiento', 'EMO' => 'EMO', 'DOCUMENTOS' => 'Documentos', 'CONTRATACION' => 'Contratación');

    //Las vacantes de unos centros. $soloAbiertas: todas las abiertas, de
    //cualquier fecha; si no, las que se abrieron en el rango de fechas
    public function listar($idsCentros, $desde, $hasta, $soloAbiertas, $hoy)
    {
        if (!count($idsCentros)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $sql = "SELECT v.*, c.NOM_CENTRO_OP, p.NOM_PROYECTO, p.ID_PROYECTO
                  FROM vacante v
                  INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = v.ID_CENTRO_OP
                  INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                 WHERE v.ID_CENTRO_OP IN ($marcas)";
        $tipos = str_repeat('i', count($idsCentros));
        $params = array_map('intval', $idsCentros);
        if ($soloAbiertas) { $sql .= " AND v.ESTADO_VACANTE = 'ABIERTA'"; }
        else { $sql .= " AND v.FECHA_VACANTE >= ? AND v.FECHA_VACANTE < ?"; $tipos .= 'ss'; $params[] = $desde; $params[] = $hasta; }
        $sql .= " ORDER BY (v.ESTADO_VACANTE = 'ABIERTA') DESC, v.FECHA_VACANTE, v.ID_VACANTE";
        $f = HanaDB::q($sql, $tipos, $params);
        return array_map(function ($v) use ($hoy) { return Vacante::calcular($v, $hoy); }, $f ? $f : array());
    }

    //Lo que en el Excel eran fórmulas
    //  DIAS_A_HOY      días desde la vacante (solo mientras está abierta)
    //  TIEMPO_CIERRE   días que tardó en cubrirse
    //  RESULTADO_ANS   CUMPLE / NO_CUMPLE (cerradas); EN_TIEMPO / VENCIDA (abiertas)
    public static function calcular($v, $hoy)
    {
        $dias = function ($a, $b) { return (int)round((strtotime($b) - strtotime($a)) / 86400); };
        $v['DIAS_A_HOY'] = $v['ESTADO_VACANTE'] === 'ABIERTA' ? $dias($v['FECHA_VACANTE'], $hoy) : null;
        $v['TIEMPO_CIERRE'] = $v['FECHA_CIERRE'] ? $dias($v['FECHA_VACANTE'], $v['FECHA_CIERRE']) : null;
        if ($v['ESTADO_VACANTE'] === 'CANCELADA') { $v['RESULTADO_ANS'] = null; }
        elseif ($v['FECHA_CIERRE'])               { $v['RESULTADO_ANS'] = $v['FECHA_CIERRE'] <= $v['FECHA_ANS'] ? 'CUMPLE' : 'NO_CUMPLE'; }
        else                                      { $v['RESULTADO_ANS'] = $hoy <= $v['FECHA_ANS'] ? 'EN_TIEMPO' : 'VENCIDA'; }
        return $v;
    }

    public function mostrar($id)
    {
        return HanaDB::fila("SELECT v.*, c.NOM_CENTRO_OP FROM vacante v
                              INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = v.ID_CENTRO_OP
                              WHERE v.ID_VACANTE = ?", 'i', array((int)$id));
    }

    public function guardar($id, $d, $idColaborador, $ahora)
    {
        if ((int)$id > 0) {
            return HanaDB::q("UPDATE vacante SET ID_CENTRO_OP = ?, NUMERO_RQ_GH = ?, CARGO = ?, FECHA_VACANTE = ?, FECHA_ANS = ?,
                                     FECHA_CIERRE = ?, MOTIVO = ?, ESTADO_VACANTE = ?, NOMBRE_REEMPLAZO = ?, ESTADO_GH = ?,
                                     FECHA_COMPROMISO_GH = ?, OBSERVACION = ?, FEC_MODIFICACION = ?
                               WHERE ID_VACANTE = ?", 'issssssssssssi',
                             array($d['centro'], $d['rq'], $d['cargo'], $d['fecha'], $d['ans'], $d['cierre'], $d['motivo'],
                                   $d['estado'], $d['nombre'], $d['gh'], $d['compromiso'], $d['obs'], $ahora, (int)$id)) ? (int)$id : 0;
        }
        $ok = HanaDB::q("INSERT INTO vacante (ID_CENTRO_OP, NUMERO_RQ_GH, CARGO, FECHA_VACANTE, FECHA_ANS, FECHA_CIERRE, MOTIVO,
                                              ESTADO_VACANTE, NOMBRE_REEMPLAZO, ESTADO_GH, FECHA_COMPROMISO_GH, OBSERVACION,
                                              ID_COLABORADOR_REGISTRA, FEC_REGISTRO)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", 'isssssssssssis',
                        array($d['centro'], $d['rq'], $d['cargo'], $d['fecha'], $d['ans'], $d['cierre'], $d['motivo'],
                              $d['estado'], $d['nombre'], $d['gh'], $d['compromiso'], $d['obs'], (int)$idColaborador, $ahora));
        return $ok ? HanaDB::id() : 0;
    }

    //Motivos ya usados, para sugerirlos al escribir
    public function motivos($idsCentros)
    {
        if (!count($idsCentros)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $f = HanaDB::q("SELECT MOTIVO, COUNT(*) n FROM vacante WHERE ID_CENTRO_OP IN ($marcas) GROUP BY MOTIVO ORDER BY n DESC LIMIT 30",
                       str_repeat('i', count($idsCentros)), array_map('intval', $idsCentros));
        return array_map(function ($x) { return $x['MOTIVO']; }, $f ? $f : array());
    }
}

class Comunicacion
{
    //Cumple si se atiende en este plazo (Parámetros del sistema: COMUNICACION_DIAS_RESPUESTA)
    public static function diasRespuesta() { return max(0, HanaConfig::num('COMUNICACION_DIAS_RESPUESTA', 2)); }

    public static $TIPOS = array('POR_ATENDER' => 'Por atender', 'POR_ENVIAR' => 'Por enviar');
    public static $MEDIOS = array('CORREO' => 'Correo electrónico', 'OFICIO' => 'Oficio físico', 'WHATSAPP' => 'WhatsApp',
                                  'LLAMADA' => 'Llamada', 'OTRO' => 'Otro');
    public static $PENDIENTE = array('COORDINADOR_OP' => 'Coordinador operativo', 'DIRECCION' => 'Dirección',
                                     'COMUNICACIONES' => 'Comunicaciones', 'GH' => 'Gestión Humana', 'CONTABLE' => 'Contable');

    public function listar($idsProyectos, $desde, $hasta, $soloAbiertas, $hoy)
    {
        if (!count($idsProyectos)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsProyectos), '?'));
        $sql = "SELECT m.*, p.NOM_PROYECTO, c.NOM_CENTRO_OP,
                       (SELECT COUNT(*) FROM adjunto a WHERE a.MODULO = 'COMUNICACION' AND a.ID_REGISTRO = m.ID_COMUNICACION AND a.ESTADO = 1) AS ADJUNTOS,
                       (SELECT COUNT(*) FROM comunicacion_respuesta r WHERE r.ID_COMUNICACION = m.ID_COMUNICACION AND r.TIPO = 'RESPUESTA') AS RESPUESTAS,
                       p.ID_COLABORADOR_COORDINADOR AS COORDINADOR_PROYECTO
                  FROM comunicacion m
                  INNER JOIN proyectos p ON p.ID_PROYECTO = m.ID_PROYECTO
                  LEFT JOIN centros_operacion c ON c.ID_CENTRO_OP = m.ID_CENTRO_OP
                 WHERE m.ID_PROYECTO IN ($marcas) AND m.ESTADO = 1";
        $tipos = str_repeat('i', count($idsProyectos));
        $params = array_map('intval', $idsProyectos);
        if ($soloAbiertas) { $sql .= " AND m.FECHA_ATENCION IS NULL"; }
        else { $sql .= " AND m.FECHA_RECEPCION >= ? AND m.FECHA_RECEPCION < ?"; $tipos .= 'ss'; $params[] = $desde; $params[] = $hasta; }
        $sql .= " ORDER BY (m.FECHA_ATENCION IS NULL) DESC, m.FECHA_RECEPCION, m.ID_COMUNICACION";
        $f = HanaDB::q($sql, $tipos, $params);
        return array_map(function ($m) use ($hoy) { return Comunicacion::calcular($m, $hoy); }, $f ? $f : array());
    }

    //  ABIERTA         sin fecha de atención
    //  TIEMPO_CIERRE   días entre recepción y atención
    //  RESULTADO       CUMPLE / NO_CUMPLE (cerradas); EN_TIEMPO / VENCIDA (abiertas)
    public static function calcular($m, $hoy)
    {
        $dias = function ($a, $b) { return (int)round((strtotime($b) - strtotime($a)) / 86400); };
        $m['ABIERTA'] = $m['FECHA_ATENCION'] ? 0 : 1;
        $m['TIEMPO_CIERRE'] = $m['FECHA_ATENCION'] ? $dias($m['FECHA_RECEPCION'], $m['FECHA_ATENCION']) : null;
        $lleva = $m['FECHA_ATENCION'] ? $m['TIEMPO_CIERRE'] : $dias($m['FECHA_RECEPCION'], $hoy);
        $m['DIAS_ABIERTA'] = $m['FECHA_ATENCION'] ? null : $lleva;
        $plazo = self::diasRespuesta();
        if ($m['FECHA_ATENCION']) { $m['RESULTADO'] = $lleva <= $plazo ? 'CUMPLE' : 'NO_CUMPLE'; }
        else                      { $m['RESULTADO'] = $lleva <= $plazo ? 'EN_TIEMPO' : 'VENCIDA'; }
        return $m;
    }

    public function mostrar($id)
    {
        return HanaDB::fila("SELECT * FROM comunicacion WHERE ID_COMUNICACION = ?", 'i', array((int)$id));
    }

    public function guardar($id, $d, $idColaborador, $ahora)
    {
        if ((int)$id > 0) {
            return HanaDB::q("UPDATE comunicacion SET ID_PROYECTO = ?, ID_CENTRO_OP = ?, TIPO = ?, RADICADO = ?, FECHA_RECEPCION = ?,
                                     MEDIO = ?, REMITENTE = ?, ASUNTO = ?, RESPONSABLE = ?, PENDIENTE_DE = ?, FECHA_ATENCION = ?,
                                     RESPUESTA = ?, FEC_MODIFICACION = ?
                               WHERE ID_COMUNICACION = ?", 'iisssssssssssi',
                             array($d['proyecto'], $d['centro'], $d['tipo'], $d['radicado'], $d['fecha'], $d['medio'], $d['remitente'],
                                   $d['asunto'], $d['responsable'], $d['pendiente'], $d['atencion'], $d['respuesta'], $ahora, (int)$id)) ? (int)$id : 0;
        }
        $ok = HanaDB::q("INSERT INTO comunicacion (ID_PROYECTO, ID_CENTRO_OP, TIPO, RADICADO, FECHA_RECEPCION, MEDIO, REMITENTE, ASUNTO,
                                                   RESPONSABLE, PENDIENTE_DE, FECHA_ATENCION, RESPUESTA, ESTADO, ID_COLABORADOR_REGISTRA, FEC_REGISTRO)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)", 'iissssssssssis',
                        array($d['proyecto'], $d['centro'], $d['tipo'], $d['radicado'], $d['fecha'], $d['medio'], $d['remitente'],
                              $d['asunto'], $d['responsable'], $d['pendiente'], $d['atencion'], $d['respuesta'], (int)$idColaborador, $ahora));
        return $ok ? HanaDB::id() : 0;
    }

    //-----------------------------------------------------------------------
    // La conversación del oficio: respuestas y cuándo se resolvió o reabrió
    //-----------------------------------------------------------------------
    public function hilo($id)
    {
        $f = HanaDB::q("SELECT r.ID_RESPUESTA, r.TIPO, r.TEXTO, r.FECHA, col.NOM_COLABORADOR AS QUIEN, r.ID_COLABORADOR
                          FROM comunicacion_respuesta r LEFT JOIN colaboradores col ON col.ID_COLABORADOR = r.ID_COLABORADOR
                         WHERE r.ID_COMUNICACION = ? ORDER BY r.FECHA, r.ID_RESPUESTA", 'i', array((int)$id));
        return $f ? $f : array();
    }

    public function agregarHilo($id, $idColaborador, $tipo, $texto, $ahora)
    {
        return HanaDB::q("INSERT INTO comunicacion_respuesta (ID_COMUNICACION, ID_COLABORADOR, TIPO, TEXTO, FECHA) VALUES (?, ?, ?, ?, ?)",
                         'iisss', array((int)$id, (int)$idColaborador, $tipo, $texto, $ahora));
    }

    //Resuelta: queda atendida hoy, ya no está pendiente de nadie y la nota queda como respuesta
    public function resolver($id, $nota, $hoy, $ahora)
    {
        return HanaDB::q("UPDATE comunicacion SET FECHA_ATENCION = ?, PENDIENTE_DE = NULL,
                                 RESPUESTA = COALESCE(?, RESPUESTA), FEC_MODIFICACION = ? WHERE ID_COMUNICACION = ?",
                         'sssi', array($hoy, $nota, $ahora, (int)$id));
    }

    public function reabrir($id, $ahora)
    {
        return HanaDB::q("UPDATE comunicacion SET FECHA_ATENCION = NULL, FEC_MODIFICACION = ? WHERE ID_COMUNICACION = ?", 'si', array($ahora, (int)$id));
    }

    public function anular($id, $motivo)
    {
        return HanaDB::q("UPDATE comunicacion SET ESTADO = 0, MOTIVO_ANULACION = ? WHERE ID_COMUNICACION = ? AND ESTADO = 1",
                         'si', array($motivo, (int)$id));
    }
}
