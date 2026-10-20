<?php
/*
  HANA - Cronograma de visitas y vehículo (Reporte diario)
  ---------------------------------------------------------------------------
  Es la hoja "Cronograma visitas y vehículo" del Excel: a la izquierda lo que
  la persona planea cada día, a la derecha el estado del vehículo.
*/
require_once __DIR__ . "/HanaDB.php";

class Cronograma
{
    //Tipos de actividad (lo que se guarda => cómo se muestra)
    public static $TIPOS = array('VISITA' => 'Visita', 'REUNION' => 'Reunión', 'DESCANSO' => 'Descanso',
                                 'VACACIONES' => 'Vacaciones', 'OTRO' => 'Otro');
    //Estado del vehículo cada día. Inoperativo incluye la falla reportada a flota
    public static $ESTADOS_VH = array('OPERATIVO' => 'Operativo', 'INOPERATIVO' => 'Inoperativo');
    //Cómo se transportó el coordinador cuando el vehículo no estaba operativo
    public static $TRANSPORTES = array('BUS' => 'Bus', 'TAXI' => 'Taxi', 'APP' => 'Aplicación (Didi, Uber…)',
                                       'PARTICULAR' => 'Vehículo particular', 'OTRO' => 'Otro', 'NO_VIAJO' => 'No se desplazó');

    //-----------------------------------------------------------------------
    // Lo planeado en un rango de fechas, con su estado real:
    //   CANCELADA    la cancelaron
    //   REALIZADA    la marcaron, o (visitas) ese día en "Hoy en qué estás"
    //                la persona marcó ese mismo peaje
    //   NO_REALIZADA ya pasó el día y no se hizo
    //   PROGRAMADA   todavía no llega el día
    //   AUSENCIA     descanso o vacaciones: no es algo que se "realice"
    //-----------------------------------------------------------------------
    public function items($idColaborador, $desde, $hasta, $hoy)
    {
        $filas = HanaDB::q("SELECT cr.ID_CRONOGRAMA, cr.FECHA, cr.TIPO, cr.ID_CENTRO_OP, cr.DESCRIPCION, cr.ESTADO,
                               cr.OBSERVACION, c.NOM_CENTRO_OP, c.TIPO_CENTRO,
                               (cr.TIPO = 'VISITA' AND EXISTS (
                                    SELECT 1 FROM reporte_hoy h
                                    INNER JOIN reporte_hoy_centro hc ON hc.ID_REPORTE_HOY = h.ID_REPORTE_HOY
                                     WHERE h.ID_COLABORADOR = cr.ID_COLABORADOR AND h.FECHA = cr.FECHA
                                       AND hc.ID_CENTRO_OP = cr.ID_CENTRO_OP)) AS CONFIRMADA_HOY
                          FROM cronograma cr
                          LEFT JOIN centros_operacion c ON c.ID_CENTRO_OP = cr.ID_CENTRO_OP
                         WHERE cr.ID_COLABORADOR = ? AND cr.FECHA >= ? AND cr.FECHA < ?
                         ORDER BY cr.FECHA, cr.ID_CRONOGRAMA",
                       'iss', array((int)$idColaborador, $desde, $hasta));
        $filas = $filas ? $filas : array();
        foreach ($filas as $i => $fila) {
            if ($fila['ESTADO'] === 'CANCELADA')                                   { $real = 'CANCELADA'; }
            elseif (in_array($fila['TIPO'], array('DESCANSO', 'VACACIONES')))      { $real = 'AUSENCIA'; }
            elseif ($fila['ESTADO'] === 'REALIZADA' || (int)$fila['CONFIRMADA_HOY'])  { $real = 'REALIZADA'; }
            elseif ($fila['FECHA'] < $hoy)                                        { $real = 'NO_REALIZADA'; }
            else                                                               { $real = 'PROGRAMADA'; }
            $filas[$i]['ESTADO_REAL'] = $real;
        }
        return $filas;
    }

    //La situación registrada en "Hoy en qué estás" cada día (laboral, descanso...)
    public function situaciones($idColaborador, $desde, $hasta)
    {
        $filas = HanaDB::q("SELECT FECHA, SITUACION FROM reporte_hoy WHERE ID_COLABORADOR = ? AND FECHA >= ? AND FECHA < ?",
                       'iss', array((int)$idColaborador, $desde, $hasta));
        $situaciones = array();
        foreach ((array)$filas as $fila) { $situaciones[$fila['FECHA']] = $fila['SITUACION']; }
        return $situaciones;
    }

    //Dónde estuvo cada día, según Hoy en qué estás: [fecha] = "TRAPICHE - CISNEROS"
    //(más el "otro lugar", si escribió uno)
    public function lugares($idColaborador, $desde, $hasta)
    {
        $filas = HanaDB::q("SELECT h.FECHA, h.LUGAR_OTRO,
                               (SELECT GROUP_CONCAT(c.NOM_CENTRO_OP ORDER BY c.NOM_CENTRO_OP SEPARATOR ' - ')
                                  FROM reporte_hoy_centro hc INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = hc.ID_CENTRO_OP
                                 WHERE hc.ID_REPORTE_HOY = h.ID_REPORTE_HOY) AS LUGARES
                          FROM reporte_hoy h
                         WHERE h.ID_COLABORADOR = ? AND h.FECHA >= ? AND h.FECHA < ? AND h.SITUACION = 'LABORAL'",
                       'iss', array((int)$idColaborador, $desde, $hasta));
        $lugares = array();
        foreach ((array)$filas as $fila) {
            $texto = implode(' · ', array_filter(array($fila['LUGARES'], $fila['LUGAR_OTRO'])));
            if ($texto !== '') { $lugares[$fila['FECHA']] = $texto; }
        }
        return $lugares;
    }

    //Una actividad del cronograma por su id
    public function item($idCronograma)
    {
        return HanaDB::fila("SELECT * FROM cronograma WHERE ID_CRONOGRAMA = ?", 'i', array((int)$idCronograma));
    }

    //Agrega una actividad al cronograma (queda como PROGRAMADA)
    public function agregar($idColaborador, $fecha, $tipo, $idCentro, $descripcion, $ahora)
    {
        $ok = HanaDB::q("INSERT INTO cronograma (ID_COLABORADOR, FECHA, TIPO, ID_CENTRO_OP, DESCRIPCION, ESTADO, FEC_REGISTRO)
                         VALUES (?, ?, ?, ?, ?, 'PROGRAMADA', ?)",
                        'ississ', array((int)$idColaborador, $fecha, $tipo, $idCentro, $descripcion, $ahora));
        return $ok ? HanaDB::id() : 0;
    }

    //Cambia el estado de una actividad y guarda la observación
    public function cambiarEstado($idCronograma, $estado, $observacion)
    {
        return HanaDB::q("UPDATE cronograma SET ESTADO = ?, OBSERVACION = ? WHERE ID_CRONOGRAMA = ?",
                         'ssi', array($estado, $observacion, (int)$idCronograma));
    }

    //Borra una actividad del cronograma
    public function quitar($idCronograma)
    {
        return HanaDB::q("DELETE FROM cronograma WHERE ID_CRONOGRAMA = ?", 'i', array((int)$idCronograma));
    }

    //-----------------------------------------------------------------------
    // Vehículos
    //-----------------------------------------------------------------------

    //Los vehículos que esta persona reporta: los suyos, o todos si administra (5M)
    public function vehiculosDe($idColaborador, $todos)
    {
        $sql = "SELECT v.ID_VEHICULO, v.PLACA, v.DESCRIPCION, v.ID_COLABORADOR_RESPONSABLE, col.NOM_COLABORADOR AS RESPONSABLE
                  FROM vehiculo v
                  LEFT JOIN colaboradores col ON col.ID_COLABORADOR = v.ID_COLABORADOR_RESPONSABLE
                 WHERE v.ESTADO = 1";
        $filas = $todos ? HanaDB::q($sql . " ORDER BY v.PLACA")
                    : HanaDB::q($sql . " AND v.ID_COLABORADOR_RESPONSABLE = ? ORDER BY v.PLACA", 'i', array((int)$idColaborador));
        return $filas ? $filas : array();
    }

    //Un vehículo por su id
    public function vehiculo($idVehiculo)
    {
        return HanaDB::fila("SELECT * FROM vehiculo WHERE ID_VEHICULO = ?", 'i', array((int)$idVehiculo));
    }

    //El estado de un vehículo cada día del rango: [fecha] = {ESTADO_VH, TRANSPORTE, OBSERVACION}
    public function vehiculoDias($idVehiculo, $desde, $hasta)
    {
        $filas = HanaDB::q("SELECT FECHA, ESTADO_VH, TRANSPORTE, OBSERVACION FROM vehiculo_dia
                         WHERE ID_VEHICULO = ? AND FECHA >= ? AND FECHA < ?",
                       'iss', array((int)$idVehiculo, $desde, $hasta));
        $dias = array();
        foreach ((array)$filas as $fila) {
            $dias[$fila['FECHA']] = array('ESTADO_VH' => $fila['ESTADO_VH'], 'TRANSPORTE' => $fila['TRANSPORTE'], 'OBSERVACION' => $fila['OBSERVACION']);
        }
        return $dias;
    }

    //Guarda cómo estuvo un vehículo un día (si ya había registro de ese día, lo reemplaza)
    public function guardarVehiculoDia($idVehiculo, $fecha, $estado, $observacion, $idColaborador, $ahora, $transporte = null)
    {
        return HanaDB::q("INSERT INTO vehiculo_dia (ID_VEHICULO, FECHA, ESTADO_VH, TRANSPORTE, OBSERVACION, ID_COLABORADOR, FEC_REGISTRO)
                          VALUES (?, ?, ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE ESTADO_VH = VALUES(ESTADO_VH), TRANSPORTE = VALUES(TRANSPORTE), OBSERVACION = VALUES(OBSERVACION),
                                                  ID_COLABORADOR = VALUES(ID_COLABORADOR), FEC_REGISTRO = VALUES(FEC_REGISTRO)",
                         'issssis', array((int)$idVehiculo, $fecha, $estado, $transporte, $observacion, (int)$idColaborador, $ahora));
    }

    //Los peajes o básculas que visitó cada día (de Hoy en qué estás): [fecha] = [idCentro, ...]
    public function centrosVisitados($idColaborador, $desde, $hasta)
    {
        $filas = HanaDB::q("SELECT h.FECHA, hc.ID_CENTRO_OP FROM reporte_hoy h
                         INNER JOIN reporte_hoy_centro hc ON hc.ID_REPORTE_HOY = h.ID_REPORTE_HOY
                         WHERE h.ID_COLABORADOR = ? AND h.FECHA >= ? AND h.FECHA < ?",
                       'iss', array((int)$idColaborador, $desde, $hasta));
        $visitados = array();
        foreach ((array)$filas as $fila) { $visitados[$fila['FECHA']][] = (int)$fila['ID_CENTRO_OP']; }
        return $visitados;
    }

    //Los vehículos de un proyecto: los del proyecto y los que tiene a cargo su coordinador
    public function vehiculosProyecto($idProyecto, $idCoordinador)
    {
        $filas = HanaDB::q("SELECT v.ID_VEHICULO, v.PLACA, v.DESCRIPCION, v.ID_COLABORADOR_RESPONSABLE, col.NOM_COLABORADOR AS RESPONSABLE
                          FROM vehiculo v
                          LEFT JOIN colaboradores col ON col.ID_COLABORADOR = v.ID_COLABORADOR_RESPONSABLE
                         WHERE v.ESTADO = 1 AND (v.ID_PROYECTO = ? OR (v.ID_COLABORADOR_RESPONSABLE = ? AND ? > 0))
                         ORDER BY v.PLACA", 'iii', array((int)$idProyecto, (int)$idCoordinador, (int)$idCoordinador));
        return $filas ? $filas : array();
    }

    //Catálogo completo, para quien administra (5M)
    public function catalogo()
    {
        $filas = HanaDB::q("SELECT v.*, p.NOM_PROYECTO, col.NOM_COLABORADOR AS RESPONSABLE
                          FROM vehiculo v
                          LEFT JOIN proyectos p ON p.ID_PROYECTO = v.ID_PROYECTO
                          LEFT JOIN colaboradores col ON col.ID_COLABORADOR = v.ID_COLABORADOR_RESPONSABLE
                         ORDER BY v.ESTADO DESC, v.PLACA");
        return $filas ? $filas : array();
    }

    //Crea o edita un vehículo
    public function guardarVehiculo($id, $placa, $descripcion, $idProyecto, $idResponsable, $estado)
    {
        if ((int)$id > 0) {
            return HanaDB::q("UPDATE vehiculo SET PLACA = ?, DESCRIPCION = ?, ID_PROYECTO = ?, ID_COLABORADOR_RESPONSABLE = ?, ESTADO = ?
                               WHERE ID_VEHICULO = ?", 'ssiiii',
                             array($placa, $descripcion, $idProyecto, $idResponsable, (int)$estado, (int)$id));
        }
        return HanaDB::q("INSERT INTO vehiculo (PLACA, DESCRIPCION, ID_PROYECTO, ID_COLABORADOR_RESPONSABLE, ESTADO)
                          VALUES (?, ?, ?, ?, 1)", 'ssii', array($placa, $descripcion, $idProyecto, $idResponsable));
    }

    //¿Ya hay otro vehículo con esta placa?
    public function placaUsada($placa, $idExcepto)
    {
        return (bool)HanaDB::fila("SELECT 1 AS ok FROM vehiculo WHERE PLACA = ? AND ID_VEHICULO <> ?", 'si', array($placa, (int)$idExcepto));
    }
}
