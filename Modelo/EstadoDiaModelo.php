<?php
/*
  HANA — Estado del día de una persona en el reporte diario
  Lo usan las alertas pequeñas ("ya registraste hoy" / "falta hoy") de la
  pantalla principal del reporte y de cada módulo.
  Que una persona revisó una pantalla queda en bitacora_sistema
  (TIPO = 'REVISION'): una fila por persona, pantalla y día, con la hora de
  la primera vez que la abrió.
*/
require_once __DIR__ . "/HanaDB.php";

class EstadoDia
{
    //Todo lo del día de una persona, en una sola consulta
    public function resumen($idColaborador, $fecha)
    {
        $sig = date('Y-m-d', strtotime("$fecha +1 day"));
        $c = (int)$idColaborador;
        $f = HanaDB::fila("SELECT
                (SELECT SITUACION FROM reporte_hoy WHERE ID_COLABORADOR = ? AND FECHA = ?) AS HOY,
                (SELECT COUNT(*) FROM lista_chequeo WHERE ID_COLABORADOR_LISTA_CHEQUEO = ?
                         AND FEC_REGISTRO_LISTA_CHEQUEO >= ? AND FEC_REGISTRO_LISTA_CHEQUEO < ?) AS LISTAS,
                (SELECT MAX(FEC_REGISTRO_LISTA_CHEQUEO) FROM lista_chequeo WHERE ID_COLABORADOR_LISTA_CHEQUEO = ?
                         AND FEC_REGISTRO_LISTA_CHEQUEO >= ? AND FEC_REGISTRO_LISTA_CHEQUEO < ?) AS LISTAS_ULTIMA,
                (SELECT COUNT(*) FROM arqueo WHERE ID_COLABORADOR_ARQUEA = ? AND FECHA = ? AND ESTADO = 1) AS ARQUEOS,
                (SELECT MAX(HORA) FROM arqueo WHERE ID_COLABORADOR_ARQUEA = ? AND FECHA = ? AND ESTADO = 1) AS ARQUEOS_ULTIMA,
                (SELECT MIN(FECHA) FROM bitacora_sistema WHERE TIPO = 'REVISION' AND MODULO = 'CRONOGRAMA' AND QUIEN = ?
                         AND FECHA >= ? AND FECHA < ?) AS CRONO_REVISADO,
                (SELECT COUNT(*) FROM vehiculo WHERE ESTADO = 1 AND ID_COLABORADOR_RESPONSABLE = ?) AS VEHICULOS,
                (SELECT COUNT(*) FROM vehiculo v INNER JOIN vehiculo_dia d ON d.ID_VEHICULO = v.ID_VEHICULO AND d.FECHA = ?
                  WHERE v.ESTADO = 1 AND v.ID_COLABORADOR_RESPONSABLE = ?) AS VEHICULOS_REG",
            'isississisissssisi',
            array($c, $fecha, $c, $fecha, $sig, $c, $fecha, $sig, $c, $fecha, $c, $fecha, (string)$c, $fecha, $sig, $c, $fecha, $c));
        if (!$f) { return null; }
        return array(
            'hoy'        => array('ok' => $f['HOY'] !== null, 'situacion' => $f['HOY']),
            'listas'     => array('ok' => (int)$f['LISTAS'] > 0, 'n' => (int)$f['LISTAS'], 'ultima' => $f['LISTAS_ULTIMA']),
            'arqueos'    => array('ok' => (int)$f['ARQUEOS'] > 0, 'n' => (int)$f['ARQUEOS'], 'ultima' => $f['ARQUEOS_ULTIMA']),
            'cronograma' => array('ok' => $f['CRONO_REVISADO'] !== null, 'revisado' => $f['CRONO_REVISADO'],
                                  'vehiculos' => (int)$f['VEHICULOS'], 'vehiculosReg' => (int)$f['VEHICULOS_REG'])
        );
    }

    //Deja constancia de que la persona abrió una pantalla hoy (solo la primera vez del día)
    public function visto($idColaborador, $modulo, $ahora)
    {
        $dia = substr($ahora, 0, 10);
        $sig = date('Y-m-d', strtotime("$dia +1 day"));
        $quien = (string)(int)$idColaborador;
        return HanaDB::q("INSERT INTO bitacora_sistema (TIPO, FECHA, MODULO, QUIEN, DETALLE, OK)
                          SELECT 'REVISION', ?, ?, ?, '', 1 FROM DUAL
                           WHERE NOT EXISTS (SELECT 1 FROM bitacora_sistema
                                              WHERE TIPO = 'REVISION' AND MODULO = ? AND QUIEN = ? AND FECHA >= ? AND FECHA < ?)",
                         'sssssss', array($ahora, $modulo, $quien, $modulo, $quien, $dia, $sig));
    }

    //Quiénes revisaron un módulo un día: [idColaborador => primera vez]
    public function revisaron($idsPersonas, $modulo, $fecha)
    {
        if (!count($idsPersonas)) { return array(); }
        $m = implode(',', array_fill(0, count($idsPersonas), '?'));
        $sig = date('Y-m-d', strtotime("$fecha +1 day"));
        $f = HanaDB::q("SELECT QUIEN, MIN(FECHA) AS PRIMERA FROM bitacora_sistema
                         WHERE TIPO = 'REVISION' AND MODULO = ? AND FECHA >= ? AND FECHA < ? AND QUIEN IN ($m)
                         GROUP BY QUIEN",
                       'sss' . str_repeat('s', count($idsPersonas)),
                       array_merge(array($modulo, $fecha, $sig), array_map(function ($x) { return (string)(int)$x; }, $idsPersonas)));
        $res = array();
        foreach ((array)$f as $x) { $res[(int)$x['QUIEN']] = $x['PRIMERA']; }
        return $res;
    }
}
