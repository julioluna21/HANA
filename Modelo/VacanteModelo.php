<?php
/*
  HANA - Vacantes y comunicaciones (Reporte diario)
  ---------------------------------------------------------------------------
  Las hojas "Vacantes" y "Comunicaciones y SOLIC" del Excel del reporte diario.
  Los resultados que en Excel eran fórmulas (días, cumple / no cumple, abierto
  o cerrado) se calculan aquí: una vacante abierta no da días
  negativos ni "CUMPLE" por error.
  En una comunicación, el responsable es quien la registró (ID_COLABORADOR_REGISTRA):
  a cada jefe le llegan las suyas y él mismo las anota para llevarles el seguimiento.
*/
require_once __DIR__ . "/HanaDB.php";
require_once __DIR__ . "/HanaFechas.php";
require_once __DIR__ . "/HanaConfig.php";

class Vacante
{
    //Días hábiles para cubrir una vacante (Parámetros del sistema: VACANTE_DIAS_ANS)
    public static function diasAns() { return max(1, HanaConfig::num('VACANTE_DIAS_ANS', 5)); }

    //Estados de la vacante y del proceso en Gestión Humana (lo que se guarda => cómo se muestra).
    //Los de Gestión Humana los edita el administrador en Parámetros del sistema → Listas
    public static $ESTADOS = array('ABIERTA' => 'Abierta', 'CUBIERTA' => 'Cubierta', 'CANCELADA' => 'Cancelada');
    public static $ESTADOS_GH = array(); //se llena al final del archivo (HanaConfig::LISTAS)

    //Las vacantes de unos centros. $soloAbiertas: todas las abiertas, de
    //cualquier fecha; si no, las que se abrieron en el rango de fechas
    public function listar($idsCentros, $desde, $hasta, $soloAbiertas, $hoy)
    {
        if (!count($idsCentros)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $sql = "SELECT v.*, c.NOM_CENTRO_OP, p.NOM_PROYECTO, p.ID_PROYECTO
                  FROM vacante v
                  INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = v.ID_CENTRO_OP
                  INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                 WHERE v.ID_CENTRO_OP IN ($marcas)";
        $tipos = str_repeat('i', count($idsCentros));
        $params = array_map('intval', $idsCentros);
        if ($soloAbiertas) { $sql .= " AND v.ESTADO_VACANTE = 'ABIERTA'"; }
        else { $sql .= " AND v.FECHA_VACANTE >= ? AND v.FECHA_VACANTE < ?"; $tipos .= 'ss'; $params[] = $desde; $params[] = $hasta; }
        $sql .= " ORDER BY (v.ESTADO_VACANTE = 'ABIERTA') DESC, v.FECHA_VACANTE, v.ID_VACANTE";
        $filas = HanaDB::q($sql, $tipos, $params);
        return array_map(function ($vacante) use ($hoy) { return Vacante::calcular($vacante, $hoy); }, $filas ? $filas : array());
    }

    //Lo que en el Excel eran fórmulas
    //  DIAS_A_HOY      días desde la vacante (solo mientras está abierta)
    //  TIEMPO_CIERRE   días que tardó en cubrirse
    //  RESULTADO_ANS   CUMPLE / NO_CUMPLE (cerradas); EN_TIEMPO / VENCIDA (abiertas)
    //  FECHA_COMPROMISO_GH  si está vacía, la del acuerdo de servicio
    public static function calcular($vacante, $hoy)
    {
        $dias = function ($desde, $hasta) { return (int)round((strtotime($hasta) - strtotime($desde)) / 86400); };
        $vacante['DIAS_A_HOY'] = $vacante['ESTADO_VACANTE'] === 'ABIERTA' ? $dias($vacante['FECHA_VACANTE'], $hoy) : null;
        $vacante['TIEMPO_CIERRE'] = $vacante['FECHA_CIERRE'] ? $dias($vacante['FECHA_VACANTE'], $vacante['FECHA_CIERRE']) : null;
        if ($vacante['ESTADO_VACANTE'] === 'CANCELADA') { $vacante['RESULTADO_ANS'] = null; }
        elseif ($vacante['FECHA_CIERRE'])               { $vacante['RESULTADO_ANS'] = $vacante['FECHA_CIERRE'] <= $vacante['FECHA_ANS'] ? 'CUMPLE' : 'NO_CUMPLE'; }
        else                                      { $vacante['RESULTADO_ANS'] = $hoy <= $vacante['FECHA_ANS'] ? 'EN_TIEMPO' : 'VENCIDA'; }
        //La fecha comprometida por Gestión Humana es la del acuerdo de servicio mientras el administrador
        //no la cambie (las vacantes registradas antes de que fuera automática la tienen vacía)
        if (empty($vacante['FECHA_COMPROMISO_GH'])) { $vacante['FECHA_COMPROMISO_GH'] = $vacante['FECHA_ANS']; }
        return $vacante;
    }

    //Una vacante con el nombre de su peaje
    public function mostrar($id)
    {
        return HanaDB::fila("SELECT v.*, c.NOM_CENTRO_OP FROM vacante v
                              INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = v.ID_CENTRO_OP
                              WHERE v.ID_VACANTE = ?", 'i', array((int)$id));
    }

    //Crea o edita una vacante
    public function guardar($id, $datos, $idColaborador, $ahora)
    {
        if ((int)$id > 0) {
            return HanaDB::q("UPDATE vacante SET ID_CENTRO_OP = ?, NUMERO_RQ_GH = ?, CARGO = ?, FECHA_VACANTE = ?, FECHA_ANS = ?,
                                     FECHA_CIERRE = ?, MOTIVO = ?, ESTADO_VACANTE = ?, NOMBRE_REEMPLAZO = ?, ESTADO_GH = ?,
                                     FECHA_COMPROMISO_GH = ?, OBSERVACION = ?, FEC_MODIFICACION = ?
                               WHERE ID_VACANTE = ?", 'issssssssssssi',
                             array($datos['centro'], $datos['rq'], $datos['cargo'], $datos['fecha'], $datos['ans'], $datos['cierre'], $datos['motivo'],
                                   $datos['estado'], $datos['nombre'], $datos['gh'], $datos['compromiso'], $datos['obs'], $ahora, (int)$id)) ? (int)$id : 0;
        }
        $ok = HanaDB::q("INSERT INTO vacante (ID_CENTRO_OP, NUMERO_RQ_GH, CARGO, FECHA_VACANTE, FECHA_ANS, FECHA_CIERRE, MOTIVO,
                                              ESTADO_VACANTE, NOMBRE_REEMPLAZO, ESTADO_GH, FECHA_COMPROMISO_GH, OBSERVACION,
                                              ID_COLABORADOR_REGISTRA, FEC_REGISTRO)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", 'isssssssssssis',
                        array($datos['centro'], $datos['rq'], $datos['cargo'], $datos['fecha'], $datos['ans'], $datos['cierre'], $datos['motivo'],
                              $datos['estado'], $datos['nombre'], $datos['gh'], $datos['compromiso'], $datos['obs'], (int)$idColaborador, $ahora));
        return $ok ? HanaDB::id() : 0;
    }

    //Motivos ya usados, para sugerirlos al escribir
    public function motivos($idsCentros)
    {
        if (!count($idsCentros)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $filas = HanaDB::q("SELECT MOTIVO, COUNT(*) n FROM vacante WHERE ID_CENTRO_OP IN ($marcas) GROUP BY MOTIVO ORDER BY n DESC LIMIT 30",
                       str_repeat('i', count($idsCentros)), array_map('intval', $idsCentros));
        return array_map(function ($fila) { return $fila['MOTIVO']; }, $filas ? $filas : array());
    }
}

class Comunicacion
{
    //Cumple si se atiende en este plazo (Parámetros del sistema: COMUNICACION_DIAS_RESPUESTA)
    public static function diasRespuesta() { return max(0, HanaConfig::num('COMUNICACION_DIAS_RESPUESTA', 2)); }

    //Tipos de comunicación (lo que se guarda => cómo se muestra)
    public static $TIPOS = array('POR_ATENDER' => 'Por atender', 'POR_ENVIAR' => 'Por enviar');
    //Medios y «Pendiente de»: los edita el administrador en Parámetros del sistema → Listas.
    //Medios por los que llega un oficio. WhatsApp no es uno de ellos: los oficios que llegaron
    //por ese medio quedaron como "Otro"
    public static $MEDIOS = array(); //se llena al final del archivo (HanaConfig::LISTAS)
    //A quién le corresponde el siguiente paso
    public static $PENDIENTE = array(); //se llena al final del archivo (HanaConfig::LISTAS)

    //Comunicaciones de los proyectos indicados: las del rango de fechas o las que siguen abiertas
    public function listar($idsProyectos, $desde, $hasta, $soloAbiertas, $hoy)
    {
        if (!count($idsProyectos)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsProyectos), '?'));
        $sql = "SELECT m.*, p.NOM_PROYECTO, c.NOM_CENTRO_OP,
                       (SELECT COUNT(*) FROM adjunto a WHERE a.MODULO = 'COMUNICACION' AND a.ID_REGISTRO = m.ID_COMUNICACION AND a.ESTADO = 1) AS ADJUNTOS,
                       (SELECT COUNT(*) FROM historial h WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = m.ID_COMUNICACION AND h.TIPO = 'RESPUESTA') AS RESPUESTAS,
                       -- La última respuesta o gestión: se lee de la conversación (la tabla comunicacion ya no la repite)
                       (SELECT h.TEXTO FROM historial h
                         WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = m.ID_COMUNICACION AND h.TIPO IN ('RESPUESTA', 'RESUELTA')
                           AND h.TEXTO IS NOT NULL AND h.TEXTO <> ''
                         ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS RESPUESTA,
                       p.ID_COLABORADOR_COORDINADOR AS COORDINADOR_PROYECTO,
                       reg.NOM_COLABORADOR AS REGISTRO -- quien la registró, que es quien le hace el seguimiento
                  FROM comunicacion m
                  INNER JOIN proyectos p ON p.ID_PROYECTO = m.ID_PROYECTO
                  LEFT JOIN centros_operacion c ON c.ID_CENTRO_OP = m.ID_CENTRO_OP
                  LEFT JOIN colaboradores reg ON reg.ID_COLABORADOR = m.ID_COLABORADOR_REGISTRA
                 WHERE m.ID_PROYECTO IN ($marcas) AND m.ESTADO = 1 AND m.TIPO <> 'TEMA_CLIENTE'";
        $tipos = str_repeat('i', count($idsProyectos));
        $params = array_map('intval', $idsProyectos);
        if ($soloAbiertas) { $sql .= " AND m.FECHA_ATENCION IS NULL"; }
        else { $sql .= " AND m.FECHA_RECEPCION >= ? AND m.FECHA_RECEPCION < ?"; $tipos .= 'ss'; $params[] = $desde; $params[] = $hasta; }
        $sql .= " ORDER BY (m.FECHA_ATENCION IS NULL) DESC, m.FECHA_RECEPCION, m.ID_COMUNICACION";
        $filas = HanaDB::q($sql, $tipos, $params);
        return array_map(function ($comunicacion) use ($hoy) { return Comunicacion::calcular($comunicacion, $hoy); }, $filas ? $filas : array());
    }

    //Lo que en el Excel eran fórmulas
    //  ABIERTA         sin fecha de atención
    //  TIEMPO_CIERRE   días entre recepción y atención
    //  RESULTADO       CUMPLE / NO_CUMPLE (cerradas); EN_TIEMPO / VENCIDA (abiertas)
    public static function calcular($comunicacion, $hoy)
    {
        $dias = function ($desde, $hasta) { return (int)round((strtotime($hasta) - strtotime($desde)) / 86400); };
        $comunicacion['ABIERTA'] = $comunicacion['FECHA_ATENCION'] ? 0 : 1;
        $comunicacion['TIEMPO_CIERRE'] = $comunicacion['FECHA_ATENCION'] ? $dias($comunicacion['FECHA_RECEPCION'], $comunicacion['FECHA_ATENCION']) : null;
        $lleva = $comunicacion['FECHA_ATENCION'] ? $comunicacion['TIEMPO_CIERRE'] : $dias($comunicacion['FECHA_RECEPCION'], $hoy);
        $comunicacion['DIAS_ABIERTA'] = $comunicacion['FECHA_ATENCION'] ? null : $lleva;
        $plazo = self::diasRespuesta();
        if ($comunicacion['FECHA_ATENCION']) { $comunicacion['RESULTADO'] = $lleva <= $plazo ? 'CUMPLE' : 'NO_CUMPLE'; }
        else                      { $comunicacion['RESULTADO'] = $lleva <= $plazo ? 'EN_TIEMPO' : 'VENCIDA'; }
        return $comunicacion;
    }

    //Una comunicación con la última respuesta que se le dio
    public function mostrar($id)
    {
        //RESPUESTA es lo último que se respondió en la conversación (no es una columna de la tabla comunicacion)
        return HanaDB::fila("SELECT m.*, (SELECT h.TEXTO FROM historial h
                         WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = m.ID_COMUNICACION AND h.TIPO IN ('RESPUESTA', 'RESUELTA')
                           AND h.TEXTO IS NOT NULL AND h.TEXTO <> ''
                         ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS RESPUESTA
                               FROM comunicacion m WHERE m.ID_COMUNICACION = ? AND m.TIPO <> 'TEMA_CLIENTE'", 'i', array((int)$id));
    }

    //Crea o edita una comunicación
    public function guardar($id, $datos, $idColaborador, $ahora)
    {
        if ((int)$id > 0) {
            return HanaDB::q("UPDATE comunicacion SET ID_PROYECTO = ?, ID_CENTRO_OP = ?, TIPO = ?, RADICADO = ?, FECHA_RECEPCION = ?,
                                     MEDIO = ?, REMITENTE = ?, ASUNTO = ?, PENDIENTE_DE = ?, FECHA_ATENCION = ?,
                                     FEC_MODIFICACION = ?
                               WHERE ID_COMUNICACION = ?", 'iisssssssssi',
                             array($datos['proyecto'], $datos['centro'], $datos['tipo'], $datos['radicado'], $datos['fecha'], $datos['medio'], $datos['remitente'],
                                   $datos['asunto'], $datos['pendiente'], $datos['atencion'], $ahora, (int)$id)) ? (int)$id : 0;
        }
        $ok = HanaDB::q("INSERT INTO comunicacion (ID_PROYECTO, ID_CENTRO_OP, TIPO, RADICADO, FECHA_RECEPCION, MEDIO, REMITENTE, ASUNTO,
                                                   PENDIENTE_DE, FECHA_ATENCION, ESTADO, ID_COLABORADOR_REGISTRA, FEC_REGISTRO)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)", 'iissssssssis',
                        array($datos['proyecto'], $datos['centro'], $datos['tipo'], $datos['radicado'], $datos['fecha'], $datos['medio'], $datos['remitente'],
                              $datos['asunto'], $datos['pendiente'], $datos['atencion'], (int)$idColaborador, $ahora));
        return $ok ? HanaDB::id() : 0;
    }

    //-----------------------------------------------------------------------
    // La conversación del oficio: respuestas y cuándo se resolvió o reabrió.
    // Vive en historial (MODULO = 'COMUNICACION'); TIPO es RESPUESTA,
    // RESUELTA o REABIERTA
    //-----------------------------------------------------------------------
    public function hilo($id)
    {
        $filas = HanaDB::q("SELECT h.ID_HISTORIAL, h.TIPO, h.TEXTO, h.FECHA, col.NOM_COLABORADOR AS QUIEN, h.ID_COLABORADOR
                          FROM historial h LEFT JOIN colaboradores col ON col.ID_COLABORADOR = h.ID_COLABORADOR
                         WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = ?
                         ORDER BY h.FECHA, h.ID_HISTORIAL", 'i', array((int)$id));
        return $filas ? $filas : array();
    }

    //Agrega una entrada a la conversación de una comunicación (respuesta, nota, etc.)
    public function agregarHilo($id, $idColaborador, $tipo, $texto, $ahora)
    {
        return HanaDB::q("INSERT INTO historial (MODULO, ID_REGISTRO, TIPO, TEXTO, ID_COLABORADOR, FECHA)
                          VALUES ('COMUNICACION', ?, ?, ?, NULLIF(?, 0), ?)",
                         'issis', array((int)$id, $tipo, $texto, (int)$idColaborador, $ahora));
    }

    //Resuelta: queda atendida hoy y ya no está pendiente de nadie.
    //La nota de cierre NO se guarda aquí: va a la conversación (agregarHilo con TIPO RESUELTA), una sola vez
    public function resolver($id, $nota, $hoy, $ahora)
    {
        return HanaDB::q("UPDATE comunicacion SET FECHA_ATENCION = ?, PENDIENTE_DE = NULL, FEC_MODIFICACION = ? WHERE ID_COMUNICACION = ?",
                         'ssi', array($hoy, $ahora, (int)$id));
    }

    //Quita la fecha de atención para que la comunicación vuelva a quedar abierta
    public function reabrir($id, $ahora)
    {
        return HanaDB::q("UPDATE comunicacion SET FECHA_ATENCION = NULL, FEC_MODIFICACION = ? WHERE ID_COMUNICACION = ?", 'si', array($ahora, (int)$id));
    }

    //Anula una comunicación dejando el motivo
    public function anular($id, $motivo)
    {
        return HanaDB::q("UPDATE comunicacion SET ESTADO = 0, MOTIVO_ANULACION = ? WHERE ID_COMUNICACION = ? AND ESTADO = 1",
                         'si', array($motivo, (int)$id));
    }
}

//Las listas que el administrador edita en Parámetros del sistema → Listas (las de fábrica están en HanaConfig::LISTAS)
Vacante::$ESTADOS_GH     = HanaConfig::opciones('LISTA_ESTADOS_GH');
Comunicacion::$MEDIOS    = HanaConfig::opciones('LISTA_MEDIOS');
Comunicacion::$PENDIENTE = HanaConfig::opciones('LISTA_PENDIENTE');
