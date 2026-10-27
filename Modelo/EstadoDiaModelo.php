<?php
/*
  HANA - Estado del día de una persona en el reporte diario
  ---------------------------------------------------------------------------
  Lo usan las alertas pequeñas ("ya registraste hoy" / "falta hoy") de la
  pantalla principal del reporte y de cada módulo.
  Que una persona revisó una pantalla queda en bitacora_sistema
  (TIPO = 'REVISION', la persona en ID_COLABORADOR): una fila por persona,
  pantalla y día, con la hora de la primera vez que la abrió.
*/
require_once __DIR__ . "/HanaDB.php";

class EstadoDia
{
    //Todo lo del día de una persona, en una sola consulta
    public function resumen($idColaborador, $fecha)
    {
        $diaSiguiente = date('Y-m-d', strtotime("$fecha +1 day"));
        $idPersona = (int)$idColaborador;
        $fila = HanaDB::fila("SELECT
                (SELECT SITUACION FROM reporte_hoy WHERE ID_COLABORADOR = ? AND FECHA = ?) AS HOY,
                (SELECT COUNT(*) FROM lista_chequeo WHERE ID_COLABORADOR = ?
                         AND FEC_REGISTRO >= ? AND FEC_REGISTRO < ?) AS LISTAS,
                (SELECT MAX(FEC_REGISTRO) FROM lista_chequeo WHERE ID_COLABORADOR = ?
                         AND FEC_REGISTRO >= ? AND FEC_REGISTRO < ?) AS LISTAS_ULTIMA,
                (SELECT COUNT(*) FROM arqueo WHERE ID_COLABORADOR_ARQUEA = ? AND FECHA = ? AND ESTADO = 1) AS ARQUEOS,
                (SELECT MAX(HORA) FROM arqueo WHERE ID_COLABORADOR_ARQUEA = ? AND FECHA = ? AND ESTADO = 1) AS ARQUEOS_ULTIMA,
                (SELECT MIN(FECHA) FROM bitacora_sistema WHERE TIPO = 'REVISION' AND MODULO = 'CRONOGRAMA' AND ID_COLABORADOR = ?
                         AND FECHA >= ? AND FECHA < ?) AS CRONO_REVISADO,
                (SELECT COUNT(*) FROM vehiculo WHERE ESTADO = 1 AND ID_COLABORADOR_RESPONSABLE = ?) AS VEHICULOS,
                (SELECT COUNT(*) FROM vehiculo v INNER JOIN vehiculo_dia d ON d.ID_VEHICULO = v.ID_VEHICULO AND d.FECHA = ?
                  WHERE v.ESTADO = 1 AND v.ID_COLABORADOR_RESPONSABLE = ?) AS VEHICULOS_REG",
            'isississisisissisi',
            array($idPersona, $fecha, $idPersona, $fecha, $diaSiguiente, $idPersona, $fecha, $diaSiguiente, $idPersona, $fecha, $idPersona, $fecha, $idPersona, $fecha, $diaSiguiente, $idPersona, $fecha, $idPersona));
        if (!$fila) { return null; }
        return array(
            'hoy'        => array('ok' => $fila['HOY'] !== null, 'situacion' => $fila['HOY']),
            'listas'     => array('ok' => (int)$fila['LISTAS'] > 0, 'n' => (int)$fila['LISTAS'], 'ultima' => $fila['LISTAS_ULTIMA']),
            'arqueos'    => array('ok' => (int)$fila['ARQUEOS'] > 0, 'n' => (int)$fila['ARQUEOS'], 'ultima' => $fila['ARQUEOS_ULTIMA']),
            'cronograma' => array('ok' => $fila['CRONO_REVISADO'] !== null, 'revisado' => $fila['CRONO_REVISADO'],
                                  'vehiculos' => (int)$fila['VEHICULOS'], 'vehiculosReg' => (int)$fila['VEHICULOS_REG'])
        );
    }

    //Vacantes y comunicaciones abiertas de sus peajes asignados, y si hoy ya las atendió
    //(abrió la pantalla, o registró o actualizó una). Lo usa el recordatorio de las 9 a. m.
    public function abiertasCoordinador($idColaborador, $fecha, $diasRespuesta)
    {
        $diaSiguiente = date('Y-m-d', strtotime("$fecha +1 day"));
        $idPersona = (int)$idColaborador;
        $fila = HanaDB::fila("SELECT
                (SELECT COUNT(*) FROM vacante v INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = v.ID_CENTRO_OP
                   INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                  WHERE v.ID_CENTRO_OP IN (SELECT ID_CENTRO_OP FROM asoc_usuarios_sistemas_x_cop WHERE ID_COLABORADOR = ?) AND p.ESTADO = '1' AND v.ESTADO_VACANTE = 'ABIERTA') AS VAC_ABIERTAS,
                (SELECT COUNT(*) FROM vacante v INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = v.ID_CENTRO_OP
                   INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                  WHERE v.ID_CENTRO_OP IN (SELECT ID_CENTRO_OP FROM asoc_usuarios_sistemas_x_cop WHERE ID_COLABORADOR = ?) AND p.ESTADO = '1' AND v.ESTADO_VACANTE = 'ABIERTA' AND v.FECHA_ANS < ?) AS VAC_VENCIDAS,
                (SELECT COUNT(*) FROM vacante WHERE ID_COLABORADOR_REGISTRA = ?
                    AND ((FEC_REGISTRO >= ? AND FEC_REGISTRO < ?) OR (FEC_MODIFICACION >= ? AND FEC_MODIFICACION < ?))) AS VAC_HOY,
                (SELECT COUNT(*) FROM comunicacion m INNER JOIN proyectos p ON p.ID_PROYECTO = m.ID_PROYECTO
                  WHERE m.ID_PROYECTO IN (SELECT c2.ID_PROYECTO FROM asoc_usuarios_sistemas_x_cop a2 INNER JOIN centros_operacion c2 ON c2.ID_CENTRO_OP = a2.ID_CENTRO_OP WHERE a2.ID_COLABORADOR = ?) AND p.ESTADO = '1' AND m.ESTADO = 1 AND m.FECHA_ATENCION IS NULL) AS COM_ABIERTAS,
                (SELECT COUNT(*) FROM comunicacion m INNER JOIN proyectos p ON p.ID_PROYECTO = m.ID_PROYECTO
                  WHERE m.ID_PROYECTO IN (SELECT c2.ID_PROYECTO FROM asoc_usuarios_sistemas_x_cop a2 INNER JOIN centros_operacion c2 ON c2.ID_CENTRO_OP = a2.ID_CENTRO_OP WHERE a2.ID_COLABORADOR = ?) AND p.ESTADO = '1' AND m.ESTADO = 1 AND m.FECHA_ATENCION IS NULL
                    AND m.FECHA_RECEPCION < DATE_SUB(?, INTERVAL ? DAY)) AS COM_VENCIDAS,
                (SELECT COUNT(*) FROM comunicacion WHERE ID_COLABORADOR_REGISTRA = ?
                    AND ((FEC_REGISTRO >= ? AND FEC_REGISTRO < ?) OR (FEC_MODIFICACION >= ? AND FEC_MODIFICACION < ?))) AS COM_HOY,
                (SELECT COUNT(*) FROM bitacora_sistema WHERE TIPO = 'REVISION' AND MODULO = 'VACANTES' AND ID_COLABORADOR = ?
                    AND FECHA >= ? AND FECHA < ?) AS VAC_REVISADO,
                (SELECT COUNT(*) FROM bitacora_sistema WHERE TIPO = 'REVISION' AND MODULO = 'COMUNICACIONES' AND ID_COLABORADOR = ?
                    AND FECHA >= ? AND FECHA < ?) AS COM_REVISADO",
            'iisissssiisiissssississ',
            array($idPersona, $idPersona, $fecha, $idPersona, $fecha, $diaSiguiente, $fecha, $diaSiguiente,
                  $idPersona, $idPersona, $fecha, (int)$diasRespuesta, $idPersona, $fecha, $diaSiguiente, $fecha, $diaSiguiente,
                  $idPersona, $fecha, $diaSiguiente, $idPersona, $fecha, $diaSiguiente));
        if (!$fila) { return null; }
        return array(
            'vacantes'       => array('abiertas' => (int)$fila['VAC_ABIERTAS'], 'vencidas' => (int)$fila['VAC_VENCIDAS'],
                                      'atendidas' => ((int)$fila['VAC_HOY'] + (int)$fila['VAC_REVISADO']) > 0),
            'comunicaciones' => array('abiertas' => (int)$fila['COM_ABIERTAS'], 'vencidas' => (int)$fila['COM_VENCIDAS'],
                                      'atendidas' => ((int)$fila['COM_HOY'] + (int)$fila['COM_REVISADO']) > 0)
        );
    }

    //Deja constancia de que la persona abrió una pantalla hoy (solo la primera vez del día)
    public function visto($idColaborador, $modulo, $ahora)
    {
        $dia = substr($ahora, 0, 10);
        $diaSiguiente = date('Y-m-d', strtotime("$dia +1 day"));
        $quien = (int)$idColaborador;
        return HanaDB::q("INSERT INTO bitacora_sistema (TIPO, FECHA, MODULO, QUIEN, ID_COLABORADOR, DETALLE, OK)
                          SELECT 'REVISION', ?, ?, '', ?, '', 1 FROM DUAL
                           WHERE NOT EXISTS (SELECT 1 FROM bitacora_sistema
                                              WHERE TIPO = 'REVISION' AND MODULO = ? AND ID_COLABORADOR = ? AND FECHA >= ? AND FECHA < ?)",
                         'ssisiss', array($ahora, $modulo, $quien, $modulo, $quien, $dia, $diaSiguiente));
    }

    //Quiénes revisaron un módulo un día: [idColaborador => primera vez]
    public function revisaron($idsPersonas, $modulo, $fecha)
    {
        if (!count($idsPersonas)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsPersonas), '?'));
        $diaSiguiente = date('Y-m-d', strtotime("$fecha +1 day"));
        $filas = HanaDB::q("SELECT ID_COLABORADOR, MIN(FECHA) AS PRIMERA FROM bitacora_sistema
                         WHERE TIPO = 'REVISION' AND MODULO = ? AND FECHA >= ? AND FECHA < ? AND ID_COLABORADOR IN ($marcas)
                         GROUP BY ID_COLABORADOR",
                       'sss' . str_repeat('i', count($idsPersonas)),
                       array_merge(array($modulo, $fecha, $diaSiguiente), array_map('intval', $idsPersonas)));
        $primeraVez = array();
        foreach ((array)$filas as $fila) { $primeraVez[(int)$fila['ID_COLABORADOR']] = $fila['PRIMERA']; }
        return $primeraVez;
    }
}
