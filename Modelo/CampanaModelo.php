<?php
/*
  HANA - Campana de notificaciones (modelo)
  ---------------------------------------------------------------------------
  Avisa de:
    - novedades asignadas a la persona o dirigidas a su rol;
    - RQ pendientes de aprobación, a quien tiene el permiso de aprobar (18M):
      amarillo la normal, rojo la urgente (RQ U);
    - el resultado de sus propias RQ (aprobada o rechazada), a quien la pidió.
  Lo usa Control/CampanaControl.php.
*/
require_once __DIR__ . "/../Conexion/ConexionDB.php";
require_once __DIR__ . "/RQEstados.php";

class Campana
{
    //Solo se avisa de lo reciente. Sin este límite, la primera vez que alguien
    //entrara vería todo su histórico como "nuevo"
    const DIAS_VENTANA = 30;

    //No necesita preparar nada al crearse
    public function __construct() {}

    //Color del aviso según la prioridad de la novedad (ALTO, MEDIO o BAJO)
    private function sqlPrioridad()
    {
        return "CASE n.PRIORIDAD WHEN 'ALTO' THEN 'alta' WHEN 'MEDIO' THEN 'media' ELSE 'baja' END";
    }

    //-----------------------------------------------------------------------
    // Las dos consultas base: una fila por aviso, con el mismo formato
    //-----------------------------------------------------------------------

    //Novedades para esta persona: asignadas a ella o dirigidas a su rol,
    //que no haya creado ella, recientes y sin cerrar
    private function sqlNovedades($idUsuario, $idColaborador, $idRol)
    {
        $numUsuario = intval($idUsuario); $numPersona = intval($idColaborador); $rol = intval($idRol);
        $dias = intval(self::DIAS_VENTANA);
        return "SELECT 'novedad' AS tipo,
                       n.ID_NOVEDADES_HALLAZGOS AS id,
                       t.NOM_TITULO_NOVEDADES_HALLAZGOS AS titulo,
                       c.NOM_CENTRO_OP AS estacion,
                       n.FEC_CREACION AS fecha,
                       CASE WHEN n.ESTADO_NOVEDAD IN (3, 4) THEN 'baja' ELSE " . $this->sqlPrioridad() . " END AS prioridad,
                       CASE WHEN n.ESTADO_NOVEDAD IN (3, 4) THEN 'Resuelta' ELSE n.PRIORIDAD END AS relevancia,
                       -- Ya resuelta (finalizada o cerrada): deja de estar pendiente para todos, aunque no la hayan abierto
                       CASE WHEN l.ID_REGISTRO IS NOT NULL OR n.ESTADO_NOVEDAD IN (3, 4) THEN 1 ELSE 0 END AS leida
                  FROM novedades_hallazgos n
                  INNER JOIN titulo_novedades_hallazgos t ON t.ID_TITULO_NOVEDADES_HALLAZGOS = n.ID_TITULO
                  INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = n.ID_CENTRO_OP
                  LEFT  JOIN notificacion_leida l ON l.TIPO = 'NOVEDAD' AND l.ID_REGISTRO = n.ID_NOVEDADES_HALLAZGOS AND l.ID_COLABORADOR = $numUsuario
                 WHERE (n.ID_COLABORADOR_ASIGNADO = $numPersona OR n.ID_ROL_DESTINO = $rol)
                   AND n.ID_COLABORADOR_REPORTA <> $numPersona
                   AND n.FEC_CREACION >= DATE_SUB(NOW(), INTERVAL $dias DAY)";
    }

    //RQ. Dos casos:
    //  - Quien aprueba ve las pendientes ("Solicitada") que pidieron otros.
    //    Urgente = alta (rojo); normal = media (amarillo)
    //  - Quien pidió la RQ ve su resultado: aprobada o rechazada (blanco).
    //Cuenta desde que entró a su estado actual: si una RQ rechazada se corrige
    //y vuelve a "Solicitada", vuelve a avisar
    private function sqlRQ($idUsuario, $idColaborador, $idRol, $aprueba = false)
    {
        $numUsuario = intval($idUsuario); $numPersona = intval($idColaborador);
        $dias = intval(self::DIAS_VENTANA);
        //Quien aprueba ve las RQ de los demás: pendientes (sin leer) y, si ya se
        //decidieron, como leídas y marcadas "Resuelta", aunque no las haya abierto
        $pendientes = $aprueba ? "(q.ID_COLABORADOR_SOLICITA <> $numPersona)" : "0 = 1";
        $solicitada = RqEstado::SOLICITADA;
        $decididas  = RqEstado::APROBADA . ", " . RqEstado::RECHAZADA;
        return "SELECT 'rq' AS tipo,
                       q.ID_RQ AS id,
                       CONCAT(IF(q.TIPO_RQ = 'U', 'RQ U-', 'RQ-'), q.NUMERO_RQ, ' · ', " . RqEstado::sqlCampo('q.ID_RQ_ESTADO', 'NOM_RQ_ESTADO') . ") AS titulo,
                       c.NOM_CENTRO_OP AS estacion,
                       " . RqEstado::sqlDesde('q.ID_RQ') . " AS fecha,
                       CASE WHEN q.ID_RQ_ESTADO <> $solicitada THEN 'baja'
                            WHEN q.TIPO_RQ = 'U'     THEN 'alta'
                            ELSE 'media' END AS prioridad,
                       CASE WHEN q.ID_RQ_ESTADO <> $solicitada AND q.ID_COLABORADOR_SOLICITA = $numPersona THEN 'Tu RQ'
                            WHEN q.ID_RQ_ESTADO <> $solicitada THEN 'Resuelta'
                            WHEN q.TIPO_RQ = 'U'     THEN 'RQ urgente'
                            ELSE 'RQ normal' END AS relevancia,
                       -- Leída si la abrió, o si ya se decidió y no es suya (a quien la pidió sí le llega la respuesta)
                       CASE WHEN l.ID_REGISTRO IS NOT NULL OR (q.ID_RQ_ESTADO <> $solicitada AND q.ID_COLABORADOR_SOLICITA <> $numPersona) THEN 1 ELSE 0 END AS leida
                  FROM rq q
                  INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = q.ID_CENTRO_OP
                  LEFT  JOIN notificacion_leida l ON l.TIPO = 'RQ' AND l.ID_REGISTRO = q.ID_RQ AND l.ID_COLABORADOR = $numUsuario
                 WHERE q.ID_RQ_ESTADO <> " . RqEstado::ANULADA . "
                   AND " . RqEstado::sqlDesde('q.ID_RQ') . " >= DATE_SUB(NOW(), INTERVAL $dias DAY)
                   AND ($pendientes
                        OR (q.ID_COLABORADOR_SOLICITA = $numPersona AND q.ID_RQ_ESTADO IN ($decididas)))";
    }

    //Las dos juntas
    private function sqlTodo($idUsuario, $idColaborador, $idRol, $aprueba)
    {
        return "(" . $this->sqlNovedades($idUsuario, $idColaborador, $idRol) . ")
                UNION ALL
                (" . $this->sqlRQ($idUsuario, $idColaborador, $idRol, $aprueba) . ")";
    }

    //-----------------------------------------------------------------------
    // Lo que usa el controlador
    //-----------------------------------------------------------------------

    //Cuántos avisos sin abrir hay de cada prioridad
    //$aprueba: si la persona tiene el permiso de aprobar RQ (18M)
    public function conteo($idUsuario, $idColaborador, $idRol, $aprueba = false)
    {
        $sql = "SELECT prioridad, COUNT(*) AS cantidad
                  FROM (" . $this->sqlTodo($idUsuario, $idColaborador, $idRol, $aprueba) . ") x
                 WHERE x.leida = 0
                 GROUP BY prioridad";
        return ejecutarConsulta($sql);
    }

    //Los avisos. Con $soloNuevos, solo los que no ha abierto
    public function lista($idUsuario, $idColaborador, $idRol, $soloNuevos, $limite, $aprueba = false)
    {
        $limite = intval($limite);
        $sql = "SELECT * FROM (" . $this->sqlTodo($idUsuario, $idColaborador, $idRol, $aprueba) . ") x
                 " . ($soloNuevos ? "WHERE x.leida = 0" : "") . "
                 ORDER BY x.leida ASC, FIELD(x.prioridad, 'alta', 'media', 'baja'), x.fecha DESC
                 LIMIT $limite";
        return ejecutarConsulta($sql);
    }

    //Marca un aviso como leído. INSERT IGNORE: si ya estaba, no pasa nada.
    //Las leídas de novedades y de RQ van en la misma tabla; TIPO dice de cuál es
    public function leer($idUsuario, $tipo, $id)
    {
        $numUsuario = intval($idUsuario); $id = intval($id);
        $tipoAviso = ($tipo === 'rq') ? 'RQ' : 'NOVEDAD';
        return ejecutarConsulta("INSERT IGNORE INTO notificacion_leida (ID_COLABORADOR, TIPO, ID_REGISTRO, FEC_LEIDA) VALUES ($numUsuario, '$tipoAviso', $id, NOW())");
    }

    //Marca como leídos todos los avisos que hoy aparecen en la campana
    public function leerTodas($idUsuario, $idColaborador, $idRol, $aprueba = false)
    {
        $numUsuario = intval($idUsuario);
        $novedadesOk = ejecutarConsulta("INSERT IGNORE INTO notificacion_leida (ID_COLABORADOR, TIPO, ID_REGISTRO, FEC_LEIDA)
                               SELECT $numUsuario, 'NOVEDAD', x.id, NOW() FROM (" . $this->sqlNovedades($idUsuario, $idColaborador, $idRol) . ") x");
        $rqOk = ejecutarConsulta("INSERT IGNORE INTO notificacion_leida (ID_COLABORADOR, TIPO, ID_REGISTRO, FEC_LEIDA)
                               SELECT $numUsuario, 'RQ', x.id, NOW() FROM (" . $this->sqlRQ($idUsuario, $idColaborador, $idRol, $aprueba) . ") x");
        return $novedadesOk && $rqOk;
    }
}
