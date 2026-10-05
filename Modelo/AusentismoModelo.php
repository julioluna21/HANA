<?php
/*
  HANA — Ausentismo (Fase 3)
  ---------------------------------------------------------------------------
  El "Reporte nacional de ausentismo" dentro de HANA. Cada hoja del Excel es un
  centro (peaje o báscula); cada bloque, un cargo; cada fila, una novedad; cada
  columna, un día del mes; y cada celda, cuántas personas de ese cargo tuvieron
  esa novedad ese día. Aquí se guarda eso mismo en aus_registro.
*/
require_once __DIR__ . "/HanaDB.php";
require_once __DIR__ . "/HanaConfig.php";

class Ausentismo
{
    //Cargos activos que salen en un centro, según su tipo (peaje o báscula)
    public function cargos()
    {
        $f = HanaDB::q("SELECT ID_CARGO_AUS, NOMBRE, APLICA, ORDEN FROM aus_cargo WHERE ESTADO = 1 ORDER BY ORDEN, NOMBRE");
        return $f ? $f : array();
    }

    public static function cargoAplica($cargo, $tipoCentro)
    {
        $tipo = $tipoCentro === 'BASCULA' ? 'BASCULA' : 'PEAJE';
        return $cargo['APLICA'] === 'AMBOS' || $cargo['APLICA'] === $tipo;
    }

    //Novedades activas (con el cargo al que se limitan, si es el caso)
    public function novedades()
    {
        $f = HanaDB::q("SELECT ID_NOVEDAD_AUS, NOMBRE, CUENTA_AUSENCIA, ID_CARGO_AUS, ORDEN FROM aus_novedad WHERE ESTADO = 1 ORDER BY ORDEN, NOMBRE");
        return $f ? $f : array();
    }

    private static function rango($anio, $mes)
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        return array($desde, date('Y-m-d', strtotime("$desde +1 month")));
    }

    //Lo registrado en un centro un mes: [idCargo][idNovedad][dia] = cantidad
    public function mesCentro($idCentro, $anio, $mes)
    {
        list($desde, $hasta) = self::rango($anio, $mes);
        $f = HanaDB::q("SELECT ID_CARGO_AUS, ID_NOVEDAD_AUS, DAY(FECHA) AS DIA, CANTIDAD FROM aus_registro
                         WHERE ID_CENTRO_OP = ? AND FECHA >= ? AND FECHA < ?", 'iss', array((int)$idCentro, $desde, $hasta));
        $res = array();
        foreach ((array)$f as $x) { $res[(int)$x['ID_CARGO_AUS']][(int)$x['ID_NOVEDAD_AUS']][(int)$x['DIA']] = (int)$x['CANTIDAD']; }
        return $res;
    }

    //Guarda las celdas que cambiaron: cantidad 0 = se borra la celda.
    //Cada cambio real queda en aus_cambio (de cuánto a cuánto, quién y cuándo)
    public function guardarCeldas($idCentro, $anio, $mes, $celdas, $idColaborador, $ahora)
    {
        $n = 0;
        foreach ($celdas as $c) {
            $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $c['dia']);
            $prev = HanaDB::fila("SELECT CANTIDAD FROM aus_registro WHERE ID_CENTRO_OP = ? AND FECHA = ? AND ID_CARGO_AUS = ? AND ID_NOVEDAD_AUS = ?",
                                 'isii', array((int)$idCentro, $fecha, $c['cargo'], $c['novedad']));
            $antes = $prev ? (int)$prev['CANTIDAD'] : 0;
            if ($antes === (int)$c['cantidad']) { continue; } //no cambió nada
            HanaDB::q("INSERT INTO aus_cambio (ID_CENTRO_OP, FECHA, ID_CARGO_AUS, ID_NOVEDAD_AUS, ANTES, DESPUES, ID_COLABORADOR, CUANDO)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 'isiiiiis',
                      array((int)$idCentro, $fecha, $c['cargo'], $c['novedad'], $antes, (int)$c['cantidad'], (int)$idColaborador, $ahora));
            if ($c['cantidad'] > 0) {
                $ok = HanaDB::q("INSERT INTO aus_registro (ID_CENTRO_OP, FECHA, ID_CARGO_AUS, ID_NOVEDAD_AUS, CANTIDAD, ID_COLABORADOR, FEC_MODIFICACION)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE CANTIDAD = VALUES(CANTIDAD), ID_COLABORADOR = VALUES(ID_COLABORADOR), FEC_MODIFICACION = VALUES(FEC_MODIFICACION)",
                                'isiiiis', array((int)$idCentro, $fecha, $c['cargo'], $c['novedad'], $c['cantidad'], (int)$idColaborador, $ahora));
            } else {
                $ok = HanaDB::q("DELETE FROM aus_registro WHERE ID_CENTRO_OP = ? AND FECHA = ? AND ID_CARGO_AUS = ? AND ID_NOVEDAD_AUS = ?",
                                'isii', array((int)$idCentro, $fecha, $c['cargo'], $c['novedad']));
            }
            if ($ok !== false) { $n++; }
        }
        return $n;
    }

    //Los últimos cambios de un centro en un mes (para el auditor)
    public function historial($idCentro, $anio, $mes, $limite = 80)
    {
        list($desde, $hasta) = self::rango($anio, $mes);
        $f = HanaDB::q("SELECT h.FECHA, DAY(h.FECHA) AS DIA, h.ANTES, h.DESPUES, h.CUANDO, ca.NOMBRE AS CARGO, n.NOMBRE AS NOVEDAD, col.NOM_COLABORADOR AS QUIEN
                          FROM aus_cambio h
                          INNER JOIN aus_cargo ca ON ca.ID_CARGO_AUS = h.ID_CARGO_AUS
                          INNER JOIN aus_novedad n ON n.ID_NOVEDAD_AUS = h.ID_NOVEDAD_AUS
                          LEFT JOIN colaboradores col ON col.ID_COLABORADOR = h.ID_COLABORADOR
                         WHERE h.ID_CENTRO_OP = ? AND h.FECHA >= ? AND h.FECHA < ?
                         ORDER BY h.ID_AUS_CAMBIO DESC LIMIT " . (int)$limite, 'iss', array((int)$idCentro, $desde, $hasta));
        return $f ? $f : array();
    }

    //-----------------------------------------------------------------------
    // Cierre del mes por proyecto
    //-----------------------------------------------------------------------
    public function cierre($idProyecto, $anio, $mes)
    {
        return HanaDB::fila("SELECT c.*, col.NOM_COLABORADOR AS QUIEN FROM aus_cierre c
                              LEFT JOIN colaboradores col ON col.ID_COLABORADOR = c.ID_COLABORADOR
                              WHERE c.ID_PROYECTO = ? AND c.ANIO = ? AND c.MES = ?", 'iii', array((int)$idProyecto, (int)$anio, (int)$mes));
    }

    public function marcarCierre($idProyecto, $anio, $mes, $cerrado, $idColaborador, $ahora)
    {
        return HanaDB::q("INSERT INTO aus_cierre (ID_PROYECTO, ANIO, MES, CERRADO, ID_COLABORADOR, FEC_MODIFICACION) VALUES (?, ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE CERRADO = VALUES(CERRADO), ID_COLABORADOR = VALUES(ID_COLABORADOR), FEC_MODIFICACION = VALUES(FEC_MODIFICACION)",
                         'iiiiis', array((int)$idProyecto, (int)$anio, (int)$mes, $cerrado ? 1 : 0, (int)$idColaborador, $ahora));
    }

    //-----------------------------------------------------------------------
    // Consolidado del proyecto (la última hoja del Excel)
    //   porCargo:  [idCargo][idNovedad][dia] = suma de todos los centros
    //   porCentro: [idCentro][idNovedad] = total del mes
    //-----------------------------------------------------------------------
    public function consolidado($idProyecto, $anio, $mes)
    {
        list($desde, $hasta) = self::rango($anio, $mes);
        $f = HanaDB::q("SELECT r.ID_CENTRO_OP, r.ID_CARGO_AUS, r.ID_NOVEDAD_AUS, DAY(r.FECHA) AS DIA, r.CANTIDAD
                          FROM aus_registro r INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                         WHERE c.ID_PROYECTO_CENTRO_OP = ? AND r.FECHA >= ? AND r.FECHA < ?", 'iss', array((int)$idProyecto, $desde, $hasta));
        $porCargo = array(); $porCentro = array();
        foreach ((array)$f as $x) {
            $c = (int)$x['ID_CARGO_AUS']; $n = (int)$x['ID_NOVEDAD_AUS']; $d = (int)$x['DIA']; $ce = (int)$x['ID_CENTRO_OP'];
            $porCargo[$c][$n][$d] = (isset($porCargo[$c][$n][$d]) ? $porCargo[$c][$n][$d] : 0) + (int)$x['CANTIDAD'];
            $porCentro[$ce][$n] = (isset($porCentro[$ce][$n]) ? $porCentro[$ce][$n] : 0) + (int)$x['CANTIDAD'];
        }
        return array('porCargo' => $porCargo, 'porCentro' => $porCentro);
    }

    //Los centros de un proyecto con lo registrado en el mes (para saber cuáles faltan)
    public function avanceCentros($idProyecto, $anio, $mes)
    {
        list($desde, $hasta) = self::rango($anio, $mes);
        $f = HanaDB::q("SELECT c.ID_CENTRO_OP, COUNT(r.FECHA) AS CELDAS, MAX(r.FEC_MODIFICACION) AS ULTIMA
                          FROM centros_operacion c
                          LEFT JOIN aus_registro r ON r.ID_CENTRO_OP = c.ID_CENTRO_OP AND r.FECHA >= ? AND r.FECHA < ?
                         WHERE c.ID_PROYECTO_CENTRO_OP = ? AND c.Estado = '1'
                         GROUP BY c.ID_CENTRO_OP", 'ssi', array($desde, $hasta, (int)$idProyecto));
        $res = array();
        foreach ((array)$f as $x) { $res[(int)$x['ID_CENTRO_OP']] = array('celdas' => (int)$x['CELDAS'], 'ultima' => $x['ULTIMA']); }
        return $res;
    }
}
