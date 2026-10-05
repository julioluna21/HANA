<?php
/*
  HANA — Estado del día de una persona en el reporte diario
  Lo usan las alertas pequeñas ("ya registraste hoy" / "falta hoy") de la
  pantalla principal del reporte y de cada módulo.
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
                (SELECT PRIMERA FROM reporte_revision WHERE ID_COLABORADOR = ? AND MODULO = 'CRONOGRAMA' AND FECHA = ?) AS CRONO_REVISADO,
                (SELECT COUNT(*) FROM vehiculo WHERE ESTADO = 1 AND ID_COLABORADOR_RESPONSABLE = ?) AS VEHICULOS,
                (SELECT COUNT(*) FROM vehiculo v INNER JOIN vehiculo_dia d ON d.ID_VEHICULO = v.ID_VEHICULO AND d.FECHA = ?
                  WHERE v.ESTADO = 1 AND v.ID_COLABORADOR_RESPONSABLE = ?) AS VEHICULOS_REG",
            'isississisisisisi',
            array($c, $fecha, $c, $fecha, $sig, $c, $fecha, $sig, $c, $fecha, $c, $fecha, $c, $fecha, $c, $fecha, $c));
        if (!$f) { return null; }
        return array(
            'hoy'        => array('ok' => $f['HOY'] !== null, 'situacion' => $f['HOY']),
            'listas'     => array('ok' => (int)$f['LISTAS'] > 0, 'n' => (int)$f['LISTAS'], 'ultima' => $f['LISTAS_ULTIMA']),
            'arqueos'    => array('ok' => (int)$f['ARQUEOS'] > 0, 'n' => (int)$f['ARQUEOS'], 'ultima' => $f['ARQUEOS_ULTIMA']),
            'cronograma' => array('ok' => $f['CRONO_REVISADO'] !== null, 'revisado' => $f['CRONO_REVISADO'],
                                  'vehiculos' => (int)$f['VEHICULOS'], 'vehiculosReg' => (int)$f['VEHICULOS_REG'])
        );
    }

    //Deja constancia de que la persona abrió una pantalla hoy
    public function visto($idColaborador, $modulo, $ahora)
    {
        return HanaDB::q("INSERT INTO reporte_revision (ID_COLABORADOR, MODULO, FECHA, PRIMERA, ULTIMA, VECES)
                          VALUES (?, ?, ?, ?, ?, 1)
                          ON DUPLICATE KEY UPDATE ULTIMA = VALUES(ULTIMA), VECES = VECES + 1",
                         'issss', array((int)$idColaborador, $modulo, substr($ahora, 0, 10), $ahora, $ahora));
    }

    //Quiénes revisaron un módulo un día: [idColaborador => primera vez]
    public function revisaron($idsPersonas, $modulo, $fecha)
    {
        if (!count($idsPersonas)) { return array(); }
        $m = implode(',', array_fill(0, count($idsPersonas), '?'));
        $f = HanaDB::q("SELECT ID_COLABORADOR, PRIMERA FROM reporte_revision WHERE MODULO = ? AND FECHA = ? AND ID_COLABORADOR IN ($m)",
                       'ss' . str_repeat('i', count($idsPersonas)), array_merge(array($modulo, $fecha), array_map('intval', $idsPersonas)));
        $res = array();
        foreach ((array)$f as $x) { $res[(int)$x['ID_COLABORADOR']] = $x['PRIMERA']; }
        return $res;
    }
}
