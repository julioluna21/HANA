<?php
/*
  HANA - Promesa de valor en horas (Reporte diario)
  ---------------------------------------------------------------------------
  Lo que iba en las hojas «(PROMESA DE VALOR)» del Excel: por cada peaje,
  cargo y día, las horas prometidas y las ejecutadas, en una sola matriz.

  Una tabla, pv_dia: una fila por peaje, cargo y día con PROMESA_HORAS,
  EJECUTADO_HORAS y JUSTIFICACION. La diferencia y las semanas no se guardan:
  se calculan (aquí, en la pantalla y en el correo de la 1 a. m.).

  Cómo se lee un día (igual que los colores de la matriz):
    CUMPLE        ejecutado = promesa                    verde
    DEBAJO        ejecutado < promesa                    rojo
    ENCIMA        ejecutado > promesa                    naranja
    SIN_REGISTRO  hay promesa y todavía no hay ejecutado
  La diferencia es promesa − ejecutado, como en el Excel: positiva = faltaron horas.
  Los cargos son los del ausentismo (aus_cargo), según sea peaje o báscula.
*/
require_once __DIR__ . "/HanaDB.php";

class PromesaValor
{
    //Las semanas del mes, igual que en el Excel: 1 a 7, 8 a 14, 15 a 21, 22 a 28 y lo que sobra
    public static $SEMANAS = array('Semana 1', 'Semana 2', 'Semana 3', 'Semana 4', 'Días 29–31');

    //El estado de un día a partir de sus horas
    public static function estado($promesa, $ejecutado)
    {
        if ($promesa === null || $promesa === '') { return $ejecutado === null || $ejecutado === '' ? null : 'SIN_PROMESA'; }
        if ($ejecutado === null || $ejecutado === '') { return 'SIN_REGISTRO'; }
        $diferencia = round((float)$promesa - (float)$ejecutado, 2);
        return $diferencia == 0 ? 'CUMPLE' : ($diferencia > 0 ? 'DEBAJO' : 'ENCIMA');
    }

    //Los cargos de un peaje o una báscula (los activos del ausentismo)
    public static function cargos($tipoCentro)
    {
        $filas = HanaDB::q("SELECT ID_CARGO_AUS, NOMBRE FROM aus_cargo
                             WHERE ESTADO = 1 AND (APLICA = ? OR APLICA = 'AMBOS')
                             ORDER BY ORDEN, NOMBRE", 's', array($tipoCentro === 'BASCULA' ? 'BASCULA' : 'PEAJE'));
        return $filas ? $filas : array();
    }

    //¿El cargo se puede usar en ese peaje? (activo y del tipo del centro)
    public static function cargoValido($idCargo, $tipoCentro)
    {
        foreach (self::cargos($tipoCentro) as $cargo) { if ((int)$cargo['ID_CARGO_AUS'] === (int)$idCargo) { return true; } }
        return false;
    }

    //Las casillas guardadas de un peaje en un mes: lista de filas
    public static function mes($idCentro, $anio, $mes)
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-d', strtotime("$desde +1 month"));
        $filas = HanaDB::q("SELECT d.ID_CARGO_AUS, DAY(d.FECHA) AS DIA, d.PROMESA_HORAS, d.EJECUTADO_HORAS, d.JUSTIFICACION,
                                   d.FEC_MODIFICACION, c.NOM_COLABORADOR AS MODIFICO
                              FROM pv_dia d LEFT JOIN colaboradores c ON c.ID_COLABORADOR = d.ID_COLABORADOR
                             WHERE d.ID_CENTRO_OP = ? AND d.FECHA >= ? AND d.FECHA < ?",
                           'iss', array((int)$idCentro, $desde, $hasta));
        return $filas ? $filas : array();
    }

    //Cómo va el mes en cada peaje de un proyecto (para los botones de peaje):
    //días por debajo, por encima y sin justificar hasta ayer
    public static function resumenCentros($idsCentros, $anio, $mes, $hoy)
    {
        if (!count($idsCentros)) { return array(); }
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = min(date('Y-m-d', strtotime("$desde +1 month")), date('Y-m-d', strtotime("$hoy +1 day")));
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $filas = HanaDB::q("SELECT ID_CENTRO_OP,
                                   SUM(PROMESA_HORAS IS NOT NULL AND EJECUTADO_HORAS IS NOT NULL AND EJECUTADO_HORAS < PROMESA_HORAS) AS DEBAJO,
                                   SUM(PROMESA_HORAS IS NOT NULL AND EJECUTADO_HORAS IS NOT NULL AND EJECUTADO_HORAS > PROMESA_HORAS) AS ENCIMA,
                                   SUM(PROMESA_HORAS IS NOT NULL AND EJECUTADO_HORAS IS NOT NULL AND EJECUTADO_HORAS <> PROMESA_HORAS
                                       AND (JUSTIFICACION IS NULL OR JUSTIFICACION = '')) AS SIN_JUSTIFICAR,
                                   SUM(PROMESA_HORAS IS NOT NULL) AS CON_PROMESA
                              FROM pv_dia WHERE ID_CENTRO_OP IN ($marcas) AND FECHA >= ? AND FECHA < ?
                             GROUP BY ID_CENTRO_OP",
                           str_repeat('i', count($idsCentros)) . 'ss', array_merge(array_map('intval', $idsCentros), array($desde, $hasta)));
        $resumen = array();
        foreach ((array)$filas as $fila) {
            $resumen[(int)$fila['ID_CENTRO_OP']] = array('debajo' => (int)$fila['DEBAJO'], 'encima' => (int)$fila['ENCIMA'],
                                                         'sinJustificar' => (int)$fila['SIN_JUSTIFICAR'], 'conPromesa' => (int)$fila['CON_PROMESA']);
        }
        return $resumen;
    }

    //Guarda una casilla (peaje, cargo, día). Los valores null dejan la casilla vacía.
    //Si las tres cosas quedan vacías, la fila se borra
    public static function guardar($idCentro, $idCargo, $fecha, $promesa, $ejecutado, $justificacion, $idColaborador, $ahora)
    {
        if ($promesa === null && $ejecutado === null && ($justificacion === null || $justificacion === '')) {
            return HanaDB::q("DELETE FROM pv_dia WHERE ID_CENTRO_OP = ? AND FECHA = ? AND ID_CARGO_AUS = ?",
                             'isi', array((int)$idCentro, $fecha, (int)$idCargo));
        }
        return HanaDB::q("INSERT INTO pv_dia (ID_CENTRO_OP, ID_CARGO_AUS, FECHA, PROMESA_HORAS, EJECUTADO_HORAS, JUSTIFICACION, ID_COLABORADOR, FEC_MODIFICACION)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE PROMESA_HORAS = VALUES(PROMESA_HORAS), EJECUTADO_HORAS = VALUES(EJECUTADO_HORAS),
                                                  JUSTIFICACION = VALUES(JUSTIFICACION), ID_COLABORADOR = VALUES(ID_COLABORADOR),
                                                  FEC_MODIFICACION = VALUES(FEC_MODIFICACION)",
                         'iisddsis', array((int)$idCentro, (int)$idCargo, $fecha, $promesa, $ejecutado, $justificacion, (int)$idColaborador, $ahora));
    }

    //Todo lo de un día en los peajes activos, para el correo de la 1 a. m.:
    //las casillas con promesa o con ejecutado, con su proyecto, peaje y cargo
    public static function dia($fecha)
    {
        $filas = HanaDB::q("SELECT p.ID_PROYECTO, p.NOM_PROYECTO, c.ID_CENTRO_OP, c.NOM_CENTRO_OP, a.NOMBRE AS CARGO,
                                   d.PROMESA_HORAS, d.EJECUTADO_HORAS, d.JUSTIFICACION
                              FROM pv_dia d
                             INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = d.ID_CENTRO_OP AND c.ESTADO = '1'
                             INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO AND p.ESTADO = '1'
                             INNER JOIN aus_cargo a ON a.ID_CARGO_AUS = d.ID_CARGO_AUS
                             WHERE d.FECHA = ? AND (d.PROMESA_HORAS IS NOT NULL OR d.EJECUTADO_HORAS IS NOT NULL)
                             ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP, a.ORDEN, a.NOMBRE", 's', array($fecha));
        return $filas ? $filas : array();
    }

    //Lo que quedó por debajo de la promesa un día en los proyectos que coordina una persona:
    //una fila por cargo, con su peaje y si tiene justificación. Lo usa el recordatorio de la mañana
    public static function debajoDeCoordinador($idColaborador, $fecha)
    {
        $filas = HanaDB::q("SELECT p.ID_PROYECTO, c.ID_CENTRO_OP, c.NOM_CENTRO_OP, a.NOMBRE AS CARGO, d.PROMESA_HORAS, d.EJECUTADO_HORAS, d.JUSTIFICACION
                              FROM pv_dia d
                             INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = d.ID_CENTRO_OP AND c.ESTADO = '1'
                             INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO AND p.ESTADO = '1'
                             INNER JOIN aus_cargo a ON a.ID_CARGO_AUS = d.ID_CARGO_AUS
                             WHERE d.FECHA = ? AND d.ID_CENTRO_OP IN (SELECT ID_CENTRO_OP FROM asoc_usuarios_sistemas_x_cop WHERE ID_COLABORADOR = ?)
                               AND d.PROMESA_HORAS IS NOT NULL AND d.EJECUTADO_HORAS IS NOT NULL AND d.EJECUTADO_HORAS < d.PROMESA_HORAS
                             ORDER BY c.NOM_CENTRO_OP, a.ORDEN", 'si', array($fecha, (int)$idColaborador));
        return is_array($filas) ? $filas : array();
    }
}
