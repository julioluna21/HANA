<?php
/*
  HANA - Campana de notificaciones (modelo)
  ---------------------------------------------------------------------------
  Avisa de:
    - novedades asignadas a la persona o dirigidas a su rol;
    - RQ pendientes de aprobación, a quien tiene el permiso de aprobar (18M):
      amarillo la normal, rojo la urgente (RQ U);
    - el resultado de sus propias RQ (aprobada o rechazada), a quien la pidió;
    - los peajes que quedaron por debajo de la promesa de valor (aviso PROMESA_AVISO de
      Correos automáticos): a las personas y los roles elegidos allí (todos los proyectos) y,
      si se marca, al coordinador del proyecto (sus peajes). Aparecen en cuanto se registran,
      o al día siguiente desde la hora elegida.
  Lo usa Control/CampanaControl.php.
*/
require_once __DIR__ . "/../Conexion/ConexionDB.php";
require_once __DIR__ . "/RQEstados.php";
require_once __DIR__ . "/TareasModelo.php";
require_once __DIR__ . "/HanaDB.php";

class Campana
{
    //Solo se avisa de lo reciente. Sin este límite, la primera vez que alguien
    //entrara vería todo su histórico como "nuevo"
    const DIAS_VENTANA = 30;

    //Condición SQL "el peaje es de los de la persona" (la pone el controlador con AccesoHelper:
    //sus peajes asignados; el ADMIN TEC, todos). Sin ella, no se limita
    public $enMisCentros = null;

    public function __construct() {}

    private function enCentros($campo)
    {
        return is_callable($this->enMisCentros) ? call_user_func($this->enMisCentros, $campo) : '1 = 1';
    }

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
    private function sqlNovedades($idUsuario, $idColaborador, $idRol, $dias = self::DIAS_VENTANA)
    {
        $numUsuario = intval($idUsuario); $numPersona = intval($idColaborador); $rol = intval($idRol);
        $dias = intval($dias);
        return "SELECT 'novedad' AS tipo,
                       n.ID_NOVEDADES_HALLAZGOS AS id,
                       t.NOM_TITULO_NOVEDADES_HALLAZGOS AS titulo,
                       c.NOM_CENTRO_OP AS estacion,
                       c.ID_CENTRO_OP AS idCentro,
                       pr.NOM_PROYECTO AS proyecto,
                       n.FEC_CREACION AS fecha,
                       CASE WHEN n.ESTADO_NOVEDAD IN (3, 4) THEN 'baja' ELSE " . $this->sqlPrioridad() . " END AS prioridad,
                       CASE WHEN n.ESTADO_NOVEDAD IN (3, 4) THEN 'Resuelta' ELSE n.PRIORIDAD END AS relevancia,
                       -- Ya resuelta (finalizada o cerrada): deja de estar pendiente para todos, aunque no la hayan abierto
                       CASE WHEN l.ID_REGISTRO IS NOT NULL OR n.ESTADO_NOVEDAD IN (3, 4) THEN 1 ELSE 0 END AS leida
                  FROM novedades_hallazgos n
                  INNER JOIN titulo_novedades_hallazgos t ON t.ID_TITULO_NOVEDADES_HALLAZGOS = n.ID_TITULO
                  INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = n.ID_CENTRO_OP
                  LEFT  JOIN proyectos pr ON pr.ID_PROYECTO = c.ID_PROYECTO
                  LEFT  JOIN notificacion_leida l ON l.TIPO = 'NOVEDAD' AND l.ID_REGISTRO = n.ID_NOVEDADES_HALLAZGOS AND l.ID_COLABORADOR = $numUsuario
                 WHERE (n.ID_COLABORADOR_ASIGNADO = $numPersona OR (n.ID_ROL_DESTINO = $rol AND " . $this->enCentros('n.ID_CENTRO_OP') . "))
                   AND n.ID_COLABORADOR_REPORTA <> $numPersona
                   AND n.FEC_CREACION >= DATE_SUB(NOW(), INTERVAL $dias DAY)";
    }

    //RQ. Dos casos:
    //  - Quien aprueba ve las pendientes ("Solicitada") que pidieron otros.
    //    Urgente = alta (rojo); normal = media (amarillo)
    //  - Quien pidió la RQ ve su resultado: aprobada o rechazada (blanco).
    //Cuenta desde que entró a su estado actual: si una RQ rechazada se corrige
    //y vuelve a "Solicitada", vuelve a avisar
    private function sqlRQ($idUsuario, $idColaborador, $idRol, $aprueba = false, $dias = self::DIAS_VENTANA)
    {
        $numUsuario = intval($idUsuario); $numPersona = intval($idColaborador);
        $dias = intval($dias);
        //Quien aprueba ve las RQ de los demás: pendientes (sin leer) y, si ya se
        //decidieron, como leídas y marcadas "Resuelta", aunque no las haya abierto
        $pendientes = $aprueba ? "(q.ID_COLABORADOR_SOLICITA <> $numPersona AND " . $this->enCentros('q.ID_CENTRO_OP') . ")" : "0 = 1";
        $solicitada = RqEstado::SOLICITADA;
        $decididas  = RqEstado::APROBADA . ", " . RqEstado::RECHAZADA;
        return "SELECT 'rq' AS tipo,
                       q.ID_RQ AS id,
                       CONCAT(IF(q.TIPO_RQ = 'U', 'RQ U-', 'RQ-'), q.NUMERO_RQ, ' · ', " . RqEstado::sqlCampo('q.ID_RQ_ESTADO', 'NOM_RQ_ESTADO') . ") AS titulo,
                       c.NOM_CENTRO_OP AS estacion,
                       c.ID_CENTRO_OP AS idCentro,
                       pr.NOM_PROYECTO AS proyecto,
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
                  LEFT  JOIN proyectos pr ON pr.ID_PROYECTO = c.ID_PROYECTO
                  LEFT  JOIN notificacion_leida l ON l.TIPO = 'RQ' AND l.ID_REGISTRO = q.ID_RQ AND l.ID_COLABORADOR = $numUsuario
                 WHERE q.ID_RQ_ESTADO <> " . RqEstado::ANULADA . "
                   AND " . RqEstado::sqlDesde('q.ID_RQ') . " >= DATE_SUB(NOW(), INTERVAL $dias DAY)
                   AND ($pendientes
                        OR (q.ID_COLABORADOR_SOLICITA = $numPersona AND q.ID_RQ_ESTADO IN ($decididas)))";
    }

    //El número del aviso de la promesa: un peaje en un día. No hay un registro propio,
    //así que se arma con los días desde el 1/1/2000 y el peaje: 9792 días y peaje 15 -> 97920015.
    //Así se puede marcar como leído en notificacion_leida (TIPO 'PROMESA')
    const SQL_ID_PROMESA = "(DATEDIFF(d.FECHA, '2000-01-01') * 10000 + d.ID_CENTRO_OP)";

    //Promesa de valor por debajo: un aviso por peaje y día, con los cargos y las horas.
    //Se ven los de la última semana. Cuándo aparece: en cuanto se registra (INMEDIATO) o al día
    //siguiente desde la hora elegida (SIGUIENTE_DIA).
    //A quién: a las personas y los roles elegidos (todos los proyectos) y, si se marca, al
    //coordinador del proyecto (sus peajes). Si el aviso está apagado o la persona no está entre
    //ellos, no hay consulta (null)
    private function sqlPromesa($idUsuario, $idColaborador, $idRol, $dias = 7)
    {
        $config = Tareas::config('PROMESA_AVISO');
        $todos = in_array(intval($idRol), $config['roles'], true) || in_array(intval($idColaborador), $config['personas'], true);
        if (!$config['activo'] || (!$todos && !$config['coordinador'])) { return null; }
        $numUsuario = intval($idUsuario);
        //Las personas y roles elegidos: los de sus peajes. El coordinador (si se marca): los de su proyecto
        $filtro = $todos ? "(" . $this->enCentros('d.ID_CENTRO_OP') . ($config['coordinador'] ? " OR p.ID_COLABORADOR_COORDINADOR = " . intval($idColaborador) : "") . ")"
                         : "p.ID_COLABORADOR_COORDINADOR = " . intval($idColaborador);
        $inmediato = $config['momento'] === 'INMEDIATO';
        //Desde cuándo se ve: cuando se registró la casilla, o la hora elegida del día siguiente
        $desde = $inmediato ? "MAX(d.FEC_MODIFICACION)" : "(TIMESTAMP(d.FECHA) + INTERVAL 1 DAY + INTERVAL " . intval($config['hora']) . " HOUR)";
        //8.00 -> "8"; 7.50 -> "7,5"
        $horas = function ($campo) { return "REPLACE(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM $campo)), '.', ',')"; };
        $sinJustificar = "SUM(d.JUSTIFICACION IS NULL OR TRIM(d.JUSTIFICACION) = '')";
        return "SELECT 'promesa' AS tipo,
                       " . self::SQL_ID_PROMESA . " AS id,
                       CONCAT('Por debajo de la promesa el ', DATE_FORMAT(d.FECHA, '%d/%m'), ': ',
                              GROUP_CONCAT(CONCAT(a.NOMBRE, ' ', " . $horas('d.EJECUTADO_HORAS') . ", ' de ', " . $horas('d.PROMESA_HORAS') . ", ' h')
                                           ORDER BY a.ORDEN, a.NOMBRE SEPARATOR ', ')) AS titulo,
                       c.NOM_CENTRO_OP AS estacion,
                       c.ID_CENTRO_OP AS idCentro,
                       p.NOM_PROYECTO AS proyecto,
                       $desde AS fecha,
                       IF($sinJustificar > 0, 'media', 'baja') AS prioridad,
                       IF($sinJustificar > 0, 'Promesa de valor', 'Justificada') AS relevancia,
                       IF(MAX(l.ID_REGISTRO) IS NULL, 0, 1) AS leida
                  FROM pv_dia d
                  INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = d.ID_CENTRO_OP AND c.ESTADO = '1'
                  INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO AND p.ESTADO = '1'
                  INNER JOIN aus_cargo a ON a.ID_CARGO_AUS = d.ID_CARGO_AUS
                  LEFT  JOIN notificacion_leida l ON l.TIPO = 'PROMESA' AND l.ID_REGISTRO = " . self::SQL_ID_PROMESA . " AND l.ID_COLABORADOR = $numUsuario
                 WHERE d.FECHA >= CURDATE() - INTERVAL " . intval($dias) . " DAY
                   AND " . ($inmediato ? "d.FECHA <= CURDATE()" : "d.FECHA < CURDATE() AND $desde <= NOW()") . "
                   AND d.PROMESA_HORAS IS NOT NULL AND d.EJECUTADO_HORAS IS NOT NULL AND d.EJECUTADO_HORAS < d.PROMESA_HORAS
                   AND $filtro
                 GROUP BY d.FECHA, d.ID_CENTRO_OP, c.NOM_CENTRO_OP, p.NOM_PROYECTO";
    }

    //Todas juntas
    //$dias: de cuántos días atrás (null = lo normal: 30 días; la promesa, 7)
    private function sqlTodo($idUsuario, $idColaborador, $idRol, $aprueba, $dias = null)
    {
        $promesa = $this->sqlPromesa($idUsuario, $idColaborador, $idRol, $dias === null ? 7 : $dias);
        $dias = $dias === null ? self::DIAS_VENTANA : $dias;
        return "(" . $this->sqlNovedades($idUsuario, $idColaborador, $idRol, $dias) . ")
                UNION ALL
                (" . $this->sqlRQ($idUsuario, $idColaborador, $idRol, $aprueba, $dias) . ")" .
               ($promesa ? " UNION ALL (" . $promesa . ")" : "");
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

    //La lista de la pantalla «Mis notificaciones» con filtros. $filtros (todos opcionales):
    //  desde, hasta  fechas AAAA-MM-DD (cuándo llegó el aviso). Sin «desde», los últimos 30 días;
    //                con «desde», se puede ir hasta un año atrás
    //  tipo          novedad, rq o promesa
    //  prioridad     alta, media o baja (baja = respondida o resuelta)
    //  centro        el número del peaje
    //  estado        nuevas (sin leer) o leidas
    //  texto         una parte del título o del peaje
    //Lo de más de 30 días cuenta como leído: ya no es «nuevo» (la campana tampoco lo cuenta).
    //Devuelve los avisos y los peajes que aparecen en el periodo (para el filtro de peaje)
    public function listaFiltrada($idUsuario, $idColaborador, $idRol, $aprueba, $filtros, $limite = 500)
    {
        $fecha = function ($valor) {
            $valor = trim((string)$valor);
            return (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && strtotime($valor)) ? $valor : '';
        };
        $desde = $fecha(isset($filtros['desde']) ? $filtros['desde'] : '');
        $hasta = $fecha(isset($filtros['hasta']) ? $filtros['hasta'] : '');
        $dias = null;
        if ($desde !== '') {
            $dias = (int)ceil((time() - strtotime($desde)) / 86400) + 1;
            $dias = max(1, min(366, $dias));
        }
        $ventana = intval(self::DIAS_VENTANA);
        $base = "SELECT x0.tipo, x0.id, x0.titulo, x0.estacion, x0.idCentro, x0.proyecto, x0.fecha, x0.prioridad, x0.relevancia,
                        IF(x0.fecha < NOW() - INTERVAL $ventana DAY, 1, x0.leida) AS leida
                   FROM (" . $this->sqlTodo($idUsuario, $idColaborador, $idRol, $aprueba, $dias) . ") x0";

        $donde = array();
        if ($desde !== '') { $donde[] = "x.fecha >= '" . $desde . "'"; }
        if ($hasta !== '') { $donde[] = "x.fecha < '" . $hasta . "' + INTERVAL 1 DAY"; }
        $tipo = isset($filtros['tipo']) ? (string)$filtros['tipo'] : '';
        if (in_array($tipo, array('novedad', 'rq', 'promesa'), true)) { $donde[] = "x.tipo = '" . $tipo . "'"; }
        $prioridad = isset($filtros['prioridad']) ? (string)$filtros['prioridad'] : '';
        if (in_array($prioridad, array('alta', 'media', 'baja'), true)) { $donde[] = "x.prioridad = '" . $prioridad . "'"; }
        $centro = isset($filtros['centro']) ? intval($filtros['centro']) : 0;
        if ($centro > 0) { $donde[] = "x.idCentro = " . $centro; }
        $estado = isset($filtros['estado']) ? (string)$filtros['estado'] : '';
        if ($estado === 'nuevas') { $donde[] = "x.leida = 0"; }
        if ($estado === 'leidas') { $donde[] = "x.leida = 1"; }
        $texto = isset($filtros['texto']) ? trim((string)$filtros['texto']) : '';
        $tipos = ''; $valores = array();
        if ($texto !== '') {
            $donde[] = "(x.titulo LIKE ? OR x.estacion LIKE ? OR x.proyecto LIKE ?)";
            $patron = '%' . addcslashes(mb_substr($texto, 0, 60), '%_\\') . '%';
            $tipos = 'sss'; $valores = array($patron, $patron, $patron);
        }

        $sql = "SELECT * FROM ($base) x" . (count($donde) ? " WHERE " . implode(' AND ', $donde) : "") . "
                 ORDER BY x.leida ASC, FIELD(x.prioridad, 'alta', 'media', 'baja'), x.fecha DESC
                 LIMIT " . intval($limite);
        $items = HanaDB::q($sql, $tipos, $valores);

        //Los peajes de todo el periodo (sin los demás filtros), para elegir uno
        $peajes = HanaDB::q("SELECT DISTINCT x.idCentro AS id, x.estacion AS nombre, x.proyecto
                               FROM ($base) x" . ($desde !== '' ? " WHERE x.fecha >= '" . $desde . "'" : "") . "
                              ORDER BY x.proyecto, x.estacion");
        return array('items' => $items ? $items : array(), 'peajes' => $peajes ? $peajes : array());
    }

    //Marca un aviso como leído. INSERT IGNORE: si ya estaba, no pasa nada.
    //Las leídas de novedades, RQ y promesa van en la misma tabla; TIPO dice de cuál es
    public function leer($idUsuario, $tipo, $id)
    {
        $numUsuario = intval($idUsuario); $id = intval($id);
        $tipoAviso = ($tipo === 'rq') ? 'RQ' : (($tipo === 'promesa') ? 'PROMESA' : 'NOVEDAD');
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
        $promesa = $this->sqlPromesa($idUsuario, $idColaborador, $idRol);
        $promesaOk = !$promesa || ejecutarConsulta("INSERT IGNORE INTO notificacion_leida (ID_COLABORADOR, TIPO, ID_REGISTRO, FEC_LEIDA)
                               SELECT $numUsuario, 'PROMESA', x.id, NOW() FROM (" . $promesa . ") x");
        return $novedadesOk && $rqOk && $promesaOk;
    }
}
