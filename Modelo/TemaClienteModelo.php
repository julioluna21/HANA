<?php
/*
  HANA - Temas con el cliente (Reporte diario)
  ---------------------------------------------------------------------------
  Los temas importantes que cada coordinador está tratando con el cliente:
  cuándo se abrió el tema, en qué va y cuándo se cerró.

  No tiene tabla propia: cada tema es una fila de comunicacion con
  TIPO = 'TEMA_CLIENTE', y su seguimiento va en historial, igual que la
  conversación de un oficio (MODULO = 'COMUNICACION'). Las columnas se usan así:

    FECHA_RECEPCION   fecha de apertura del tema
    ASUNTO            el tema
    REMITENTE         con quién se está tratando (persona o área del cliente)
    MEDIO             de qué se trata (lista LISTA_CLASES_TEMA de Parámetros)
    PENDIENTE_DE      a quién le toca el siguiente paso (la misma lista de los oficios)
    FECHA_ATENCION    fecha de cierre (vacía = el tema sigue abierto)
    RADICADO          sin uso en los temas

  historial.TIPO de un tema: SEGUIMIENTO (en qué va), RESUELTA (se cerró) y
  REABIERTA. Las pantallas de comunicaciones, el tablero, el informe mensual y
  los recordatorios no cuentan los temas (filtran TIPO <> 'TEMA_CLIENTE').
*/
require_once __DIR__ . "/HanaDB.php";
require_once __DIR__ . "/HanaConfig.php";

class TemaCliente
{
    const TIPO = 'TEMA_CLIENTE';

    //De qué se trata un tema: la edita el administrador en Parámetros del sistema → Listas
    public static function clases() { return HanaConfig::opciones('LISTA_CLASES_TEMA'); }

    //A quién le toca el siguiente paso: la misma lista de comunicaciones y oficios
    public static function pendientes() { return HanaConfig::opciones('LISTA_PENDIENTE'); }

    //Columnas que se leen de un tema, con lo último que se escribió en el seguimiento
    private static function columnas()
    {
        return "m.ID_COMUNICACION, m.ID_PROYECTO, m.ID_CENTRO_OP, m.FECHA_RECEPCION, m.ASUNTO, m.REMITENTE, m.MEDIO,
                m.PENDIENTE_DE, m.FECHA_ATENCION, m.ESTADO, m.ID_COLABORADOR_REGISTRA, m.FEC_REGISTRO, m.FEC_MODIFICACION,
                p.NOM_PROYECTO, c.NOM_CENTRO_OP, reg.NOM_COLABORADOR AS REGISTRO,
                (SELECT COUNT(*) FROM historial h WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = m.ID_COMUNICACION AND h.TIPO = 'SEGUIMIENTO') AS SEGUIMIENTOS,
                (SELECT h.TEXTO FROM historial h
                  WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = m.ID_COMUNICACION AND h.TEXTO IS NOT NULL AND h.TEXTO <> ''
                  ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS ULTIMO_TEXTO,
                (SELECT MAX(h.FECHA) FROM historial h WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = m.ID_COMUNICACION) AS ULTIMO_MOVIMIENTO";
    }

    private static function desde()
    {
        return " FROM comunicacion m
                 INNER JOIN proyectos p ON p.ID_PROYECTO = m.ID_PROYECTO
                 LEFT JOIN centros_operacion c ON c.ID_CENTRO_OP = m.ID_CENTRO_OP
                 LEFT JOIN colaboradores reg ON reg.ID_COLABORADOR = m.ID_COLABORADOR_REGISTRA ";
    }

    //Temas de los proyectos indicados.
    //  $soloAbiertos  true: los que siguen abiertos hoy.
    //                 false: los que estuvieron abiertos en algún momento entre $desde y $hasta
    //                 (se abrieron antes de $hasta y no se habían cerrado antes de $desde)
    public static function listar($idsProyectos, $soloAbiertos, $desde, $hasta, $hoy)
    {
        if (!count($idsProyectos)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsProyectos), '?'));
        $sql = "SELECT " . self::columnas() . self::desde() .
               " WHERE m.TIPO = '" . self::TIPO . "' AND m.ESTADO = 1 AND m.ID_PROYECTO IN ($marcas)";
        $tipos = str_repeat('i', count($idsProyectos));
        $params = array_map('intval', $idsProyectos);
        if ($soloAbiertos) { $sql .= " AND m.FECHA_ATENCION IS NULL"; }
        else {
            $sql .= " AND m.FECHA_RECEPCION < ? AND (m.FECHA_ATENCION IS NULL OR m.FECHA_ATENCION >= ?)";
            $tipos .= 'ss'; $params[] = $hasta; $params[] = $desde;
        }
        $sql .= " ORDER BY (m.FECHA_ATENCION IS NULL) DESC, p.NOM_PROYECTO, m.FECHA_RECEPCION, m.ID_COMUNICACION";
        $filas = HanaDB::q($sql, $tipos, $params);
        return array_map(function ($tema) use ($hoy) { return TemaCliente::calcular($tema, $hoy); }, is_array($filas) ? $filas : array());
    }

    //Días que lleva abierto (o que duró) y días desde el último movimiento
    public static function calcular($tema, $hoy)
    {
        $dias = function ($desde, $hasta) { return (int)round((strtotime(substr($hasta, 0, 10)) - strtotime(substr($desde, 0, 10))) / 86400); };
        $tema['ABIERTO'] = $tema['FECHA_ATENCION'] ? 0 : 1;
        $tema['DIAS'] = $dias($tema['FECHA_RECEPCION'], $tema['FECHA_ATENCION'] ? $tema['FECHA_ATENCION'] : $hoy);
        $ultimo = $tema['ULTIMO_MOVIMIENTO'] ? $tema['ULTIMO_MOVIMIENTO'] : $tema['FEC_REGISTRO'];
        $tema['DIAS_SIN_MOVIMIENTO'] = $tema['FECHA_ATENCION'] ? null : max(0, $dias($ultimo, $hoy));
        return $tema;
    }

    //Un tema (vigente o anulado), o null si no existe o no es un tema
    public static function mostrar($id)
    {
        $fila = HanaDB::fila("SELECT " . self::columnas() . self::desde() . " WHERE m.ID_COMUNICACION = ? AND m.TIPO = '" . self::TIPO . "'",
                             'i', array((int)$id));
        return $fila ? $fila : null;
    }

    //Crea o edita un tema. Devuelve el número del tema, o 0 si no se pudo
    public static function guardar($id, $datos, $idColaborador, $ahora)
    {
        if ((int)$id > 0) {
            $ok = HanaDB::q("UPDATE comunicacion SET ID_PROYECTO = ?, ID_CENTRO_OP = ?, FECHA_RECEPCION = ?, MEDIO = ?, REMITENTE = ?, ASUNTO = ?,
                                    PENDIENTE_DE = ?, FEC_MODIFICACION = ?
                              WHERE ID_COMUNICACION = ? AND TIPO = '" . self::TIPO . "'", 'iissssssi',
                            array($datos['proyecto'], $datos['centro'], $datos['apertura'], $datos['clase'], $datos['conQuien'], $datos['tema'],
                                  $datos['pendiente'], $ahora, (int)$id));
            return $ok ? (int)$id : 0;
        }
        $ok = HanaDB::q("INSERT INTO comunicacion (ID_PROYECTO, ID_CENTRO_OP, TIPO, RADICADO, FECHA_RECEPCION, MEDIO, REMITENTE, ASUNTO,
                                                   PENDIENTE_DE, FECHA_ATENCION, ESTADO, ID_COLABORADOR_REGISTRA, FEC_REGISTRO)
                         VALUES (?, ?, '" . self::TIPO . "', NULL, ?, ?, ?, ?, ?, NULL, 1, ?, ?)", 'iisssssis',
                        array($datos['proyecto'], $datos['centro'], $datos['apertura'], $datos['clase'], $datos['conQuien'], $datos['tema'],
                              $datos['pendiente'], (int)$idColaborador, $ahora));
        return $ok ? (int)HanaDB::id() : 0;
    }

    //El seguimiento de un tema, del más viejo al más nuevo
    public static function seguimiento($id)
    {
        $filas = HanaDB::q("SELECT h.ID_HISTORIAL, h.TIPO, h.TEXTO, h.FECHA, col.NOM_COLABORADOR AS QUIEN
                              FROM historial h LEFT JOIN colaboradores col ON col.ID_COLABORADOR = h.ID_COLABORADOR
                             WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = ?
                             ORDER BY h.FECHA, h.ID_HISTORIAL", 'i', array((int)$id));
        return is_array($filas) ? $filas : array();
    }

    //Agrega una entrada al seguimiento (SEGUIMIENTO, RESUELTA o REABIERTA)
    public static function anotar($id, $idColaborador, $tipo, $texto, $ahora)
    {
        return HanaDB::q("INSERT INTO historial (MODULO, ID_REGISTRO, TIPO, TEXTO, ID_COLABORADOR, FECHA)
                          VALUES ('COMUNICACION', ?, ?, ?, NULLIF(?, 0), ?)",
                         'issis', array((int)$id, $tipo, $texto, (int)$idColaborador, $ahora));
    }

    //Cierra el tema en la fecha indicada (ya no queda pendiente de nadie)
    public static function cerrar($id, $fecha, $ahora)
    {
        return HanaDB::q("UPDATE comunicacion SET FECHA_ATENCION = ?, PENDIENTE_DE = NULL, FEC_MODIFICACION = ?
                           WHERE ID_COMUNICACION = ? AND TIPO = '" . self::TIPO . "'", 'ssi', array($fecha, $ahora, (int)$id));
    }

    //Lo vuelve a abrir
    public static function reabrir($id, $ahora)
    {
        return HanaDB::q("UPDATE comunicacion SET FECHA_ATENCION = NULL, FEC_MODIFICACION = ?
                           WHERE ID_COMUNICACION = ? AND TIPO = '" . self::TIPO . "'", 'si', array($ahora, (int)$id));
    }

    //Lo anula (no se borra): queda con su motivo
    public static function anular($id, $motivo, $ahora)
    {
        return HanaDB::q("UPDATE comunicacion SET ESTADO = 0, MOTIVO_ANULACION = ?, FEC_MODIFICACION = ?
                           WHERE ID_COMUNICACION = ? AND TIPO = '" . self::TIPO . "' AND ESTADO = 1", 'ssi', array($motivo, $ahora, (int)$id));
    }

    //Para el reporte a la Gerencia: por proyecto, los temas que se movieron ese día
    //(se abrieron, tuvieron seguimiento, se cerraron o se reabrieron), con lo que se escribió
    public static function movimientosDelDia($idsProyectos, $fecha)
    {
        if (!count($idsProyectos)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsProyectos), '?'));
        $manana = date('Y-m-d', strtotime("$fecha +1 day"));
        $filas = HanaDB::q("SELECT m.ID_COMUNICACION, m.ID_PROYECTO, m.ASUNTO, m.REMITENTE, m.FECHA_RECEPCION, m.FECHA_ATENCION, c.NOM_CENTRO_OP,
                                   (SELECT h.TEXTO FROM historial h
                                     WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = m.ID_COMUNICACION AND h.FECHA >= ? AND h.FECHA < ?
                                       AND h.TEXTO IS NOT NULL AND h.TEXTO <> ''
                                     ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS TEXTO_DIA
                              FROM comunicacion m LEFT JOIN centros_operacion c ON c.ID_CENTRO_OP = m.ID_CENTRO_OP
                             WHERE m.TIPO = '" . self::TIPO . "' AND m.ESTADO = 1 AND m.ID_PROYECTO IN ($marcas)
                               AND (m.FECHA_RECEPCION = ? OR m.FECHA_ATENCION = ?
                                    OR EXISTS (SELECT 1 FROM historial h2 WHERE h2.MODULO = 'COMUNICACION' AND h2.ID_REGISTRO = m.ID_COMUNICACION
                                                  AND h2.FECHA >= ? AND h2.FECHA < ?))
                             ORDER BY m.FECHA_RECEPCION, m.ID_COMUNICACION",
                           'ss' . str_repeat('i', count($idsProyectos)) . 'ssss',
                           array_merge(array($fecha, $manana), array_map('intval', $idsProyectos), array($fecha, $fecha, $fecha, $manana)));
        $porProyecto = array();
        foreach (is_array($filas) ? $filas : array() as $fila) { $porProyecto[(int)$fila['ID_PROYECTO']][] = $fila; }
        return $porProyecto;
    }
}
