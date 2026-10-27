<?php
/*
  HANA - Modelo de "Hoy en qué estás" (Reporte diario)
  ---------------------------------------------------------------------------
  El día de cada persona: dónde va a estar, a qué hora entra y sale, y qué va a hacer,
  en un solo texto. Es la hoja "Hoy en qué estás" del reporte diario.

  Todas las consultas son preparadas (los datos viajan aparte del SQL): no se
  usa limpiarCadena(), así que el texto se guarda tal cual lo escribió la
  persona y la pantalla lo escapa al mostrarlo.
*/
require_once __DIR__ . "/../Conexion/ConexionDB.php";

class Hoy
{
    //Situaciones posibles del día. Solo LABORAL lleva lugares, horario y actividad
    public static $SITUACIONES = array(
        'LABORAL'     => 'Laboral',
        'DESCANSO'    => 'Descanso',
        'INCAPACIDAD' => 'Incapacidad',
        'VACACIONES'  => 'Vacaciones',
        'PERMISO'     => 'Permiso'
    );

    //-----------------------------------------------------------------------
    // Utilidad: ejecuta una consulta preparada.
    //   Devuelve un arreglo de filas (SELECT), true (INSERT/UPDATE/DELETE) o
    //   false si falló. Desde PHP 8.1, mysqli lanza excepciones al fallar: se
    //   atrapan aquí para que un error no deje la pantalla sin respuesta
    //-----------------------------------------------------------------------
    private function q($sql, $tipos = '', $params = array())
    {
        global $conexion;
        try {
            $sentencia = $conexion->prepare($sql);
            if (!$sentencia) { error_log('HANA Hoy - no se pudo preparar: ' . $conexion->error); return false; }
            if ($tipos !== '') { $sentencia->bind_param($tipos, ...$params); }
            if (!$sentencia->execute()) { error_log('HANA Hoy - error SQL: ' . $sentencia->error); $sentencia->close(); return false; }
            $resultado = $sentencia->get_result();
            if ($resultado === false) { $sentencia->close(); return true; } //no era un SELECT
            $filas = $resultado->fetch_all(MYSQLI_ASSOC);
            $sentencia->close();
            return $filas;
        } catch (Throwable $error) {
            error_log('HANA Hoy - ' . $error->getMessage());
            return false;
        }
    }

    //Transacciones: guardar todo el reporte del día o nada
    public function iniciar()   { global $conexion; $conexion->begin_transaction(); }
    public function confirmar() { global $conexion; $conexion->commit(); }
    public function deshacer()  { global $conexion; $conexion->rollback(); }

    //-----------------------------------------------------------------------
    // Los centros (peajes, básculas...) asignados al usuario. Son los únicos
    // que puede marcar como visitados, igual que en RQ y listas de chequeo
    //-----------------------------------------------------------------------
    public function centrosUsuario($idUsuario)
    {
        $filas = $this->q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.ID_PROYECTO, p.NOM_PROYECTO
                             FROM centros_operacion c
                             INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                             INNER JOIN asoc_usuarios_sistemas_x_cop a
                                     ON a.ID_CENTRO_OP = c.ID_CENTRO_OP
                            WHERE a.ID_COLABORADOR = ?
                              AND c.ESTADO = '1'
                            ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP", 'i', array((int)$idUsuario));
        return $filas ? $filas : array();
    }

    //-----------------------------------------------------------------------
    // El registro de un día, con sus centros. null si no existe
    //-----------------------------------------------------------------------
    public function obtener($idColaborador, $fecha)
    {
        $filas = $this->q("SELECT * FROM reporte_hoy WHERE ID_COLABORADOR = ? AND FECHA = ? LIMIT 1",
                      'is', array((int)$idColaborador, $fecha));
        if (!$filas) { return null; }
        $registro = $filas[0];
        $id = (int)$registro['ID_REPORTE_HOY'];

        $registro['CENTROS'] = array();
        //Con su nombre y proyecto: quien consulta el día de otro (33M) no tiene esos peajes en su lista
        $registro['CENTROS_DET'] = array();
        $marcados = $this->q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.NOM_PROYECTO
                                FROM reporte_hoy_centro hc
                                INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = hc.ID_CENTRO_OP
                                LEFT JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                               WHERE hc.ID_REPORTE_HOY = ?
                               ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP", 'i', array($id));
        foreach ((array)$marcados as $centro) {
            $registro['CENTROS'][] = (int)$centro['ID_CENTRO_OP'];
            $registro['CENTROS_DET'][] = $centro;
        }
        return $registro; //ACTIVIDAD (qué va a hacer en el día) ya viene en la fila
    }

    //-----------------------------------------------------------------------
    // Los registros de un mes: para la tabla "Mi mes" y para ver qué días faltan
    //-----------------------------------------------------------------------
    public function mes($idColaborador, $desde, $hasta)
    {
        $filas = $this->q("SELECT h.ID_REPORTE_HOY, h.FECHA, h.SITUACION, h.HORA_INGRESO, h.HORA_SALIDA, h.LUGAR_OTRO,
                                  (SELECT GROUP_CONCAT(c.NOM_CENTRO_OP ORDER BY c.NOM_CENTRO_OP SEPARATOR ' - ')
                                     FROM reporte_hoy_centro hc
                                     INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = hc.ID_CENTRO_OP
                                    WHERE hc.ID_REPORTE_HOY = h.ID_REPORTE_HOY) AS LUGARES,
                                  h.ACTIVIDAD
                             FROM reporte_hoy h
                            WHERE h.ID_COLABORADOR = ? AND h.FECHA >= ? AND h.FECHA < ?
                            ORDER BY h.FECHA", 'iss', array((int)$idColaborador, $desde, $hasta));
        return $filas ? $filas : array();
    }

    //-----------------------------------------------------------------------
    // A quién se puede consultar (33M): las personas que llenan Hoy en qué estás
    // (su rol tiene la casilla 28M, o ya tienen días registrados) y que trabajan en
    // alguno de los peajes de quien consulta. $idsCentros: esos peajes (null = todos,
    // para el ADMIN TEC). Cada una con los proyectos de sus peajes
    //-----------------------------------------------------------------------
    public function personasConsulta($idsCentros = null)
    {
        $enMisPeajes = '1 = 1';
        if ($idsCentros !== null) {
            $ids = array_map('intval', (array)$idsCentros);
            if (!count($ids)) { return array(); }
            $enMisPeajes = "EXISTS (SELECT 1 FROM asoc_usuarios_sistemas_x_cop a2 WHERE a2.ID_COLABORADOR = col.ID_COLABORADOR
                                    AND a2.ID_CENTRO_OP IN (" . implode(',', $ids) . "))";
        }
        $filas = $this->q("SELECT col.ID_COLABORADOR, col.NOM_COLABORADOR,
                                  (SELECT GROUP_CONCAT(DISTINCT p.NOM_PROYECTO ORDER BY p.NOM_PROYECTO SEPARATOR ', ')
                                     FROM asoc_usuarios_sistemas_x_cop a
                                     INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = a.ID_CENTRO_OP AND c.ESTADO = '1'
                                     INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO AND p.ESTADO = '1'
                                    WHERE a.ID_COLABORADOR = col.ID_COLABORADOR) AS PROYECTOS
                             FROM colaboradores col
                             LEFT JOIN usuarios_sistema u ON u.ID_COLABORADOR = col.ID_COLABORADOR
                             LEFT JOIN rol_usuarios_sistemas ro ON ro.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA
                            WHERE col.ESTADO = 1
                              AND (CONCAT(',', IFNULL(ro.PERMISOS, ''), ',') LIKE '%,28M,%'
                                   OR col.ID_COLABORADOR IN (SELECT DISTINCT ID_COLABORADOR FROM reporte_hoy))
                              AND $enMisPeajes
                            ORDER BY (PROYECTOS IS NULL), col.NOM_COLABORADOR");
        return $filas ? $filas : array();
    }

    //Los registros de un mes de todas las personas (o de una), para la administración (21M)
    public function mesTodos($desde, $hasta, $idColaborador = 0)
    {
        $sql = "SELECT h.ID_COLABORADOR, col.NOM_COLABORADOR, h.FECHA, h.SITUACION, h.HORA_INGRESO, h.HORA_SALIDA, h.LUGAR_OTRO,
                       h.OBSERVACION, h.FEC_REGISTRO, h.FEC_MODIFICACION,
                       (SELECT GROUP_CONCAT(c.NOM_CENTRO_OP ORDER BY c.NOM_CENTRO_OP SEPARATOR ' - ')
                          FROM reporte_hoy_centro hc INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = hc.ID_CENTRO_OP
                         WHERE hc.ID_REPORTE_HOY = h.ID_REPORTE_HOY) AS LUGARES,
                       h.ACTIVIDAD
                  FROM reporte_hoy h INNER JOIN colaboradores col ON col.ID_COLABORADOR = h.ID_COLABORADOR
                 WHERE h.FECHA >= ? AND h.FECHA < ? AND (? = 0 OR h.ID_COLABORADOR = ?)
                 ORDER BY h.FECHA DESC, col.NOM_COLABORADOR";
        $filas = $this->q($sql, 'ssii', array($desde, $hasta, (int)$idColaborador, (int)$idColaborador));
        return $filas ? $filas : array();
    }

    //-----------------------------------------------------------------------
    // Guarda el día completo. Si ya existía, lo reemplaza.
    // Se llama dentro de una transacción (ver HoyControl): todo o nada
    //   $datos: situación, ingreso, salida, lugarOtro, actividad, observación, centros[]
    //-----------------------------------------------------------------------
    public function guardar($idColaborador, $fecha, $datos, $ahora)
    {
        $idColaborador = (int)$idColaborador;

        //Crea el registro del día o actualiza el que ya estaba (la llave única
        //persona + día impide que queden dos)
        $ok = $this->q("INSERT INTO reporte_hoy
                            (ID_COLABORADOR, FECHA, SITUACION, HORA_INGRESO, HORA_SALIDA, LUGAR_OTRO, ACTIVIDAD, OBSERVACION, FEC_REGISTRO)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            SITUACION = VALUES(SITUACION), HORA_INGRESO = VALUES(HORA_INGRESO),
                            HORA_SALIDA = VALUES(HORA_SALIDA), LUGAR_OTRO = VALUES(LUGAR_OTRO),
                            ACTIVIDAD = VALUES(ACTIVIDAD), OBSERVACION = VALUES(OBSERVACION), FEC_MODIFICACION = ?",
                       'isssssssss',
                       array($idColaborador, $fecha, $datos['situacion'], $datos['ingreso'], $datos['salida'],
                             $datos['lugarOtro'], $datos['actividad'], $datos['observacion'], $ahora, $ahora));
        if (!$ok) { return 0; }

        $filas = $this->q("SELECT ID_REPORTE_HOY FROM reporte_hoy WHERE ID_COLABORADOR = ? AND FECHA = ?",
                      'is', array($idColaborador, $fecha));
        if (!$filas) { return 0; }
        $id = (int)$filas[0]['ID_REPORTE_HOY'];

        //Los centros visitados se reemplazan completos
        if (!$this->q("DELETE FROM reporte_hoy_centro WHERE ID_REPORTE_HOY = ?", 'i', array($id))) { return 0; }

        foreach ($datos['centros'] as $idCentro) {
            if (!$this->q("INSERT INTO reporte_hoy_centro (ID_REPORTE_HOY, ID_CENTRO_OP) VALUES (?, ?)",
                          'ii', array($id, (int)$idCentro))) { return 0; }
        }
        return $id;
    }
}
