<?php
/*
  HANA - Ausentismo
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
        $filas = HanaDB::q("SELECT ID_CARGO_AUS, NOMBRE, APLICA, ORDEN FROM aus_cargo WHERE ESTADO = 1 ORDER BY ORDEN, NOMBRE");
        return $filas ? $filas : array();
    }

    //¿Este cargo se usa en este tipo de centro? (peaje, báscula o ambos)
    public static function cargoAplica($cargo, $tipoCentro)
    {
        $tipo = $tipoCentro === 'BASCULA' ? 'BASCULA' : 'PEAJE';
        return $cargo['APLICA'] === 'AMBOS' || $cargo['APLICA'] === $tipo;
    }

    //Novedades (motivos) activas, con el cargo al que se limitan si es el caso. Son las que se pueden escoger
    public function novedades()
    {
        $filas = HanaDB::q("SELECT ID_NOVEDAD_AUS, NOMBRE, CUENTA_AUSENCIA, ID_CARGO_AUS, ORDEN, ESTADO FROM aus_novedad WHERE ESTADO = 1 ORDER BY ORDEN, NOMBRE");
        return $filas ? $filas : array();
    }

    //Todas, también las que ya no se usan (ESTADO 0): la pantalla las muestra solo en los meses que tienen datos con ellas
    public function todasNovedades()
    {
        $filas = HanaDB::q("SELECT ID_NOVEDAD_AUS, NOMBRE, CUENTA_AUSENCIA, ID_CARGO_AUS, ORDEN, ESTADO FROM aus_novedad ORDER BY ESTADO DESC, ORDEN, NOMBRE");
        return $filas ? $filas : array();
    }

    //Las de un mes: las activas y las que ya no se usan pero tienen ausencias registradas ese mes.
    //Así los meses de antes (consolidado, Excel) se siguen viendo con los motivos con que se registraron
    public function novedadesDelMes($anio, $mes)
    {
        list($desde, $hasta) = self::rango($anio, $mes);
        $filas = HanaDB::q("SELECT n.ID_NOVEDAD_AUS, n.NOMBRE, n.CUENTA_AUSENCIA, n.ID_CARGO_AUS, n.ORDEN, n.ESTADO FROM aus_novedad n
                         WHERE n.ESTADO = 1
                            OR EXISTS (SELECT 1 FROM aus_registro r WHERE r.ID_NOVEDAD_AUS = n.ID_NOVEDAD_AUS AND r.FECHA >= ? AND r.FECHA < ?)
                         ORDER BY n.ESTADO DESC, n.ORDEN, n.NOMBRE", 'ss', array($desde, $hasta));
        return $filas ? $filas : array();
    }

    //Primer día del mes y primer día del mes siguiente, para filtrar por fechas
    private static function rango($anio, $mes)
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        return array($desde, date('Y-m-d', strtotime("$desde +1 month")));
    }

    //Lo registrado en un centro un mes: [idCargo][idNovedad][dia] = cantidad
    public function mesCentro($idCentro, $anio, $mes)
    {
        list($desde, $hasta) = self::rango($anio, $mes);
        $filas = HanaDB::q("SELECT ID_CARGO_AUS, ID_NOVEDAD_AUS, DAY(FECHA) AS DIA, CANTIDAD FROM aus_registro
                         WHERE ID_CENTRO_OP = ? AND FECHA >= ? AND FECHA < ?", 'iss', array((int)$idCentro, $desde, $hasta));
        $celdas = array();
        foreach ((array)$filas as $fila) { $celdas[(int)$fila['ID_CARGO_AUS']][(int)$fila['ID_NOVEDAD_AUS']][(int)$fila['DIA']] = (int)$fila['CANTIDAD']; }
        return $celdas;
    }

    //Cuántas personas hay registradas en una celda (centro, día, cargo y novedad). 0 si no hay nada
    public function cantidad($idCentro, $fecha, $idCargo, $idNovedad)
    {
        $fila = HanaDB::fila("SELECT CANTIDAD FROM aus_registro WHERE ID_CENTRO_OP = ? AND FECHA = ? AND ID_CARGO_AUS = ? AND ID_NOVEDAD_AUS = ?",
                          'isii', array((int)$idCentro, $fecha, (int)$idCargo, (int)$idNovedad));
        return $fila ? (int)$fila['CANTIDAD'] : 0;
    }

    //Guarda las celdas que cambiaron: cantidad 0 = se borra la celda.
    //Cada cambio real queda en aus_cambio (de cuánto a cuánto, quién y cuándo)
    public function guardarCeldas($idCentro, $anio, $mes, $celdas, $idColaborador, $ahora)
    {
        $guardadas = 0;
        foreach ($celdas as $celda) {
            $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $celda['dia']);
            $anterior = HanaDB::fila("SELECT CANTIDAD FROM aus_registro WHERE ID_CENTRO_OP = ? AND FECHA = ? AND ID_CARGO_AUS = ? AND ID_NOVEDAD_AUS = ?",
                                 'isii', array((int)$idCentro, $fecha, $celda['cargo'], $celda['novedad']));
            $antes = $anterior ? (int)$anterior['CANTIDAD'] : 0;
            if ($antes === (int)$celda['cantidad']) { continue; } //no cambió nada
            HanaDB::q("INSERT INTO aus_cambio (ID_CENTRO_OP, FECHA, ID_CARGO_AUS, ID_NOVEDAD_AUS, ANTES, DESPUES, ID_COLABORADOR, CUANDO)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 'isiiiiis',
                      array((int)$idCentro, $fecha, $celda['cargo'], $celda['novedad'], $antes, (int)$celda['cantidad'], (int)$idColaborador, $ahora));
            if ($celda['cantidad'] > 0) {
                $ok = HanaDB::q("INSERT INTO aus_registro (ID_CENTRO_OP, FECHA, ID_CARGO_AUS, ID_NOVEDAD_AUS, CANTIDAD, ID_COLABORADOR, FEC_MODIFICACION)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE CANTIDAD = VALUES(CANTIDAD), ID_COLABORADOR = VALUES(ID_COLABORADOR), FEC_MODIFICACION = VALUES(FEC_MODIFICACION)",
                                'isiiiis', array((int)$idCentro, $fecha, $celda['cargo'], $celda['novedad'], $celda['cantidad'], (int)$idColaborador, $ahora));
            } else {
                $ok = HanaDB::q("DELETE FROM aus_registro WHERE ID_CENTRO_OP = ? AND FECHA = ? AND ID_CARGO_AUS = ? AND ID_NOVEDAD_AUS = ?",
                                'isii', array((int)$idCentro, $fecha, $celda['cargo'], $celda['novedad']));
            }
            if ($ok !== false) { $guardadas++; }
        }
        return $guardadas;
    }

    //Los últimos cambios de un centro en un mes (para el auditor)
    public function historial($idCentro, $anio, $mes, $limite = 80)
    {
        list($desde, $hasta) = self::rango($anio, $mes);
        $filas = HanaDB::q("SELECT h.FECHA, DAY(h.FECHA) AS DIA, h.ANTES, h.DESPUES, h.CUANDO, ca.NOMBRE AS CARGO, n.NOMBRE AS NOVEDAD, col.NOM_COLABORADOR AS QUIEN
                          FROM aus_cambio h
                          INNER JOIN aus_cargo ca ON ca.ID_CARGO_AUS = h.ID_CARGO_AUS
                          INNER JOIN aus_novedad n ON n.ID_NOVEDAD_AUS = h.ID_NOVEDAD_AUS
                          LEFT JOIN colaboradores col ON col.ID_COLABORADOR = h.ID_COLABORADOR
                         WHERE h.ID_CENTRO_OP = ? AND h.FECHA >= ? AND h.FECHA < ?
                         ORDER BY h.ID_AUS_CAMBIO DESC LIMIT " . (int)$limite, 'iss', array((int)$idCentro, $desde, $hasta));
        return $filas ? $filas : array();
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

    //Cierra o reabre el mes de un proyecto (si ya había registro, lo actualiza)
    public function marcarCierre($idProyecto, $anio, $mes, $cerrado, $idColaborador, $ahora)
    {
        return HanaDB::q("INSERT INTO aus_cierre (ID_PROYECTO, ANIO, MES, CERRADO, ID_COLABORADOR, FEC_MODIFICACION) VALUES (?, ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE CERRADO = VALUES(CERRADO), ID_COLABORADOR = VALUES(ID_COLABORADOR), FEC_MODIFICACION = VALUES(FEC_MODIFICACION)",
                         'iiiiis', array((int)$idProyecto, (int)$anio, (int)$mes, $cerrado ? 1 : 0, (int)$idColaborador, $ahora));
    }

    //-----------------------------------------------------------------------
    // Consolidado (la última hoja del Excel): la suma de los centros de uno o
    // varios proyectos, del día $diaDesde al día $diaHasta del mes (sin días, todo el mes).
    // Con $soloCentro, solo ese peaje
    //   porCargo:    [idCargo][idNovedad][dia] = suma de todos los centros
    //   porCentro:   [idCentro][idNovedad] = total de esos días
    //   porProyecto: [idProyecto][idNovedad] = total de esos días
    //-----------------------------------------------------------------------
    public function consolidado($proyectos, $anio, $mes, $diaDesde = 1, $diaHasta = 31, $soloCentro = 0)
    {
        $ids = array_values(array_unique(array_map('intval', (array)$proyectos)));
        $porCargo = array(); $porCentro = array(); $porProyecto = array();
        if (!count($ids)) { return array('porCargo' => $porCargo, 'porCentro' => $porCentro, 'porProyecto' => $porProyecto); }
        list($desde, $hasta) = self::rangoDias($anio, $mes, $diaDesde, $diaHasta);
        $marcas = implode(', ', array_fill(0, count($ids), '?'));
        $tipos = str_repeat('i', count($ids)) . 'ss';
        $params = array_merge($ids, array($desde, $hasta));
        $centro = '';
        if ((int)$soloCentro > 0) { $centro = ' AND r.ID_CENTRO_OP = ?'; $tipos .= 'i'; $params[] = (int)$soloCentro; }
        $filas = HanaDB::q("SELECT c.ID_PROYECTO, r.ID_CENTRO_OP, r.ID_CARGO_AUS, r.ID_NOVEDAD_AUS, DAY(r.FECHA) AS DIA, r.CANTIDAD
                          FROM aus_registro r INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                         WHERE c.ID_PROYECTO IN ($marcas) AND r.FECHA >= ? AND r.FECHA < ?$centro", $tipos, $params);
        foreach ((array)$filas as $fila) {
            $idCargo = (int)$fila['ID_CARGO_AUS']; $idNovedad = (int)$fila['ID_NOVEDAD_AUS']; $dia = (int)$fila['DIA'];
            $idCentro = (int)$fila['ID_CENTRO_OP']; $idProyecto = (int)$fila['ID_PROYECTO']; $cantidad = (int)$fila['CANTIDAD'];
            $porCargo[$idCargo][$idNovedad][$dia] = (isset($porCargo[$idCargo][$idNovedad][$dia]) ? $porCargo[$idCargo][$idNovedad][$dia] : 0) + $cantidad;
            $porCentro[$idCentro][$idNovedad] = (isset($porCentro[$idCentro][$idNovedad]) ? $porCentro[$idCentro][$idNovedad] : 0) + $cantidad;
            $porProyecto[$idProyecto][$idNovedad] = (isset($porProyecto[$idProyecto][$idNovedad]) ? $porProyecto[$idProyecto][$idNovedad] : 0) + $cantidad;
        }
        return array('porCargo' => $porCargo, 'porCentro' => $porCentro, 'porProyecto' => $porProyecto);
    }

    //Del día $diaDesde al día $diaHasta del mes, ajustados a los días que tiene: [primer día, día siguiente al último]
    private static function rangoDias($anio, $mes, $diaDesde, $diaHasta)
    {
        $dias = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes)));
        $diaDesde = max(1, min($dias, (int)$diaDesde));
        $diaHasta = max($diaDesde, min($dias, (int)$diaHasta));
        return array(sprintf('%04d-%02d-%02d', $anio, $mes, $diaDesde), date('Y-m-d', strtotime(sprintf('%04d-%02d-%02d', $anio, $mes, $diaHasta) . ' +1 day')));
    }

    //Los centros de un proyecto con lo registrado en el mes (para saber cuáles faltan)
    public function avanceCentros($idProyecto, $anio, $mes)
    {
        list($desde, $hasta) = self::rango($anio, $mes);
        $filas = HanaDB::q("SELECT c.ID_CENTRO_OP, COUNT(r.FECHA) AS CELDAS, MAX(r.FEC_MODIFICACION) AS ULTIMA
                          FROM centros_operacion c
                          LEFT JOIN aus_registro r ON r.ID_CENTRO_OP = c.ID_CENTRO_OP AND r.FECHA >= ? AND r.FECHA < ?
                         WHERE c.ID_PROYECTO = ? AND c.ESTADO = '1'
                         GROUP BY c.ID_CENTRO_OP", 'ssi', array($desde, $hasta, (int)$idProyecto));
        $avance = array();
        foreach ((array)$filas as $fila) { $avance[(int)$fila['ID_CENTRO_OP']] = array('celdas' => (int)$fila['CELDAS'], 'ultima' => $fila['ULTIMA']); }
        return $avance;
    }
}
