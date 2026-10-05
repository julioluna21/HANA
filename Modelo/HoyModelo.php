<?php
/*
  HANA — Modelo de "Hoy en qué estás" (Reporte diario, Fase 2)
  ---------------------------------------------------------------------------
  La bitácora diaria de cada persona: dónde estuvo, a qué hora entró y salió,
  y qué hizo en cada bloque de una hora. Reemplaza la hoja "Hoy en qué estás"
  del Excel del reporte diario.

  Todas las consultas son preparadas (los datos viajan aparte del SQL): no se
  usa limpiarCadena(), así que el texto se guarda tal cual lo escribió la
  persona y la pantalla lo escapa al mostrarlo.
*/
require_once __DIR__ . "/../Conexion/ConexionDB.php";

class Hoy
{
    //Situaciones posibles del día. Solo LABORAL lleva lugares y horas
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
            $st = $conexion->prepare($sql);
            if (!$st) { error_log('HANA Hoy - no se pudo preparar: ' . $conexion->error); return false; }
            if ($tipos !== '') { $st->bind_param($tipos, ...$params); }
            if (!$st->execute()) { error_log('HANA Hoy - error SQL: ' . $st->error); $st->close(); return false; }
            $r = $st->get_result();
            if ($r === false) { $st->close(); return true; } //no era un SELECT
            $filas = $r->fetch_all(MYSQLI_ASSOC);
            $st->close();
            return $filas;
        } catch (Throwable $e) {
            error_log('HANA Hoy - ' . $e->getMessage());
            return false;
        }
    }

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
                             INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                             INNER JOIN asoc_usuarios_sistemas_x_cop a
                                     ON a.ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP = c.ID_CENTRO_OP
                            WHERE a.ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = ?
                              AND c.Estado = '1'
                            ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP", 'i', array((int)$idUsuario));
        return $filas ? $filas : array();
    }

    //-----------------------------------------------------------------------
    // El registro de un día, con sus centros y sus horas. null si no existe
    //-----------------------------------------------------------------------
    public function obtener($idColaborador, $fecha)
    {
        $f = $this->q("SELECT * FROM reporte_hoy WHERE ID_COLABORADOR = ? AND FECHA = ? LIMIT 1",
                      'is', array((int)$idColaborador, $fecha));
        if (!$f) { return null; }
        $reg = $f[0];
        $id = (int)$reg['ID_REPORTE_HOY'];

        $reg['CENTROS'] = array();
        foreach ((array)$this->q("SELECT ID_CENTRO_OP FROM reporte_hoy_centro WHERE ID_REPORTE_HOY = ?", 'i', array($id)) as $c) {
            $reg['CENTROS'][] = (int)$c['ID_CENTRO_OP'];
        }
        $reg['HORAS'] = array();
        foreach ((array)$this->q("SELECT HORA, ACTIVIDAD FROM reporte_hoy_hora WHERE ID_REPORTE_HOY = ? ORDER BY HORA", 'i', array($id)) as $h) {
            $reg['HORAS'][(int)$h['HORA']] = $h['ACTIVIDAD'];
        }
        return $reg;
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
                                  (SELECT COUNT(*) FROM reporte_hoy_hora hh
                                    WHERE hh.ID_REPORTE_HOY = h.ID_REPORTE_HOY) AS BLOQUES
                             FROM reporte_hoy h
                            WHERE h.ID_COLABORADOR = ? AND h.FECHA >= ? AND h.FECHA < ?
                            ORDER BY h.FECHA", 'iss', array((int)$idColaborador, $desde, $hasta));
        return $filas ? $filas : array();
    }

    //-----------------------------------------------------------------------
    // Guarda el día completo. Si ya existía, lo reemplaza.
    // Se llama dentro de una transacción (ver HoyControl): todo o nada
    //   $d: situacion, ingreso, salida, lugarOtro, observacion, centros[], horas[hora => texto]
    //-----------------------------------------------------------------------
    public function guardar($idColaborador, $fecha, $d, $ahora)
    {
        $idColaborador = (int)$idColaborador;

        //Crea el registro del día o actualiza el que ya estaba (la llave única
        //persona + día impide que queden dos)
        $ok = $this->q("INSERT INTO reporte_hoy
                            (ID_COLABORADOR, FECHA, SITUACION, HORA_INGRESO, HORA_SALIDA, LUGAR_OTRO, OBSERVACION, FEC_REGISTRO)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            SITUACION = VALUES(SITUACION), HORA_INGRESO = VALUES(HORA_INGRESO),
                            HORA_SALIDA = VALUES(HORA_SALIDA), LUGAR_OTRO = VALUES(LUGAR_OTRO),
                            OBSERVACION = VALUES(OBSERVACION), FEC_MODIFICACION = ?",
                       'issssssss',
                       array($idColaborador, $fecha, $d['situacion'], $d['ingreso'], $d['salida'],
                             $d['lugarOtro'], $d['observacion'], $ahora, $ahora));
        if (!$ok) { return 0; }

        $f = $this->q("SELECT ID_REPORTE_HOY FROM reporte_hoy WHERE ID_COLABORADOR = ? AND FECHA = ?",
                      'is', array($idColaborador, $fecha));
        if (!$f) { return 0; }
        $id = (int)$f[0]['ID_REPORTE_HOY'];

        //Centros y horas se reemplazan completos
        if (!$this->q("DELETE FROM reporte_hoy_centro WHERE ID_REPORTE_HOY = ?", 'i', array($id))) { return 0; }
        if (!$this->q("DELETE FROM reporte_hoy_hora   WHERE ID_REPORTE_HOY = ?", 'i', array($id))) { return 0; }

        foreach ($d['centros'] as $idCentro) {
            if (!$this->q("INSERT INTO reporte_hoy_centro (ID_REPORTE_HOY, ID_CENTRO_OP) VALUES (?, ?)",
                          'ii', array($id, (int)$idCentro))) { return 0; }
        }
        foreach ($d['horas'] as $hora => $texto) {
            if (!$this->q("INSERT INTO reporte_hoy_hora (ID_REPORTE_HOY, HORA, ACTIVIDAD) VALUES (?, ?, ?)",
                          'iis', array($id, (int)$hora, $texto))) { return 0; }
        }
        return $id;
    }
}
