<?php
/*
  HANA - Informe mensual de gestión (los datos)
  ---------------------------------------------------------------------------
  Reúne en un solo arreglo todo lo que pasó en un periodo (un mes, varios
  meses, una quincena o el rango de fechas que se elija), por proyecto y por
  peaje, para pintarlo en el PDF (Vista/pdf/InformeMensualPdf.php):
  requisiciones, novedades, listas de chequeo, arqueos, vacantes, oficios,
  ausentismo y el reporte diario de cada coordinador.

  "Hasta el momento": si el periodo pedido llega más allá de hoy, se cuenta
  solo hasta hoy. Lo que todavía no ha pasado no entra.

  No guarda nada: solo lee. Todas las consultas son preparadas (HanaDB) y los
  números de proyecto y de peaje se pasan siempre como enteros.
*/
require_once __DIR__ . "/HanaDB.php";
require_once __DIR__ . "/HanaConfig.php";
require_once __DIR__ . "/HanaFechas.php";
require_once __DIR__ . "/RQEstados.php";
require_once __DIR__ . "/ArqueoModelo.php";

class InformeMensual
{
    //Los nombres de los meses (1 = enero)
    public static $MESES = array(1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                                 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');

    //Las cuentas de un peaje (o de un proyecto, o del total): todo arranca en cero.
    //  rq: RQ pedidas en el periodo; rqAprob aprobadas, rqRech rechazadas, rqPend sin decidir, rqUrg urgentes
    //  nov: novedades registradas; novCerr ya atendidas (finalizadas o cerradas), novAb abiertas
    //  listas: listas de chequeo diligenciadas
    //  arq: arqueos; arqDif los que no cuadraron, arqFalt suma de faltantes, arqSobr suma de sobrantes
    //  vacNuevas: vacantes abiertas en el periodo; vacCub cubiertas; vacAb abiertas hoy; vacFuera abiertas y fuera de plazo
    //  ofRec: oficios recibidos; ofRes resueltos; ofPend pendientes; ofVenc pendientes y fuera de plazo
    //  aus: ausencias (personas por día)
    public static function ceros()
    {
        return array(
            'rq' => 0, 'rqAprob' => 0, 'rqRech' => 0, 'rqPend' => 0, 'rqUrg' => 0,
            'nov' => 0, 'novCerr' => 0, 'novAb' => 0,
            'listas' => 0,
            'arq' => 0, 'arqDif' => 0, 'arqFalt' => 0.0, 'arqSobr' => 0.0,
            'vacNuevas' => 0, 'vacCub' => 0, 'vacAb' => 0, 'vacFuera' => 0,
            'ofRec' => 0, 'ofRes' => 0, 'ofPend' => 0, 'ofVenc' => 0,
            'aus' => 0
        );
    }

    //-----------------------------------------------------------------------
    // Los proyectos que esta persona puede poner en el informe
    //   $todos = true: todos los proyectos activos (20M o 24M)
    //   si no: solo los que coordina
    //-----------------------------------------------------------------------
    public function proyectos($idColaborador, $todos)
    {
        $sql = "SELECT p.ID_PROYECTO, p.NOM_PROYECTO, p.ID_COLABORADOR_COORDINADOR, col.NOM_COLABORADOR AS COORDINADOR
                  FROM proyectos p
                  LEFT JOIN colaboradores col ON col.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR
                 WHERE p.ESTADO = '1'";
        $filas = $todos ? HanaDB::q($sql . " ORDER BY p.NOM_PROYECTO")
                    : HanaDB::q($sql . " AND p.ID_COLABORADOR_COORDINADOR = ? ORDER BY p.NOM_PROYECTO", 'i', array((int)$idColaborador));
        return $filas ? $filas : array();
    }

    //-----------------------------------------------------------------------
    // El periodo del informe. Puede ser un mes, varios meses, una quincena o
    // cualquier rango de fechas. Si el rango llega más allá de hoy, se corta
    // en hoy: lo que no ha pasado no entra
    //-----------------------------------------------------------------------

    //Un mes completo
    public static function periodo($anio, $mes)
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        return self::rango($desde, date('Y-m-t', strtotime($desde)));
    }

    //De $desde a $hasta, los dos días incluidos (AAAA-MM-DD)
    public static function rango($desde, $hasta)
    {
        $hoy = HanaFechas::hoy();
        $fin = date('Y-m-d', strtotime("$hasta +1 day"));                 //el día siguiente al último pedido
        $manana = date('Y-m-d', strtotime("$hoy +1 day"));
        $tope = $fin < $manana ? $fin : $manana;                          //hasta dónde hay datos (sin incluir ese día)
        $corte = date('Y-m-d', strtotime("$tope -1 day"));                //el último día que entra
        $completo = $tope === $fin;                                       //false = el periodo va en curso
        list($nombre, $mensual) = self::nombreRango($desde, $hasta);
        $formatoFecha = function ($fecha) { return substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2) . '/' . substr($fecha, 0, 4); };
        //La línea de abajo del título: qué días cubre de verdad
        if ($mensual) { $detalle = $completo ? 'Mes completo' : 'Del 1 al ' . (int)substr($corte, 8, 2) . ' (mes en curso)'; }
        else          { $detalle = 'Del ' . $formatoFecha($desde) . ' al ' . $formatoFecha($corte) . ($completo ? '' : ' (periodo en curso)'); }
        return array(
            'anio' => (int)substr($desde, 0, 4), 'mes' => (int)substr($desde, 5, 2), 'hoy' => $hoy,
            'desde' => $desde, 'hasta' => $tope, 'corte' => $corte, 'completo' => $completo,
            'dias' => max(0, (int)round((strtotime($tope) - strtotime($desde)) / 86400)), //días transcurridos
            'nombre' => $nombre, 'detalle' => $detalle, 'mensual' => $mensual,
            'titulo' => $mensual ? 'INFORME MENSUAL DE GESTIÓN' : 'INFORME DE GESTIÓN'
        );
    }

    //El nombre de un rango y si es exactamente un mes:
    //"Octubre de 2026", "Agosto a octubre de 2026", "Primera quincena de octubre de 2026" o "Del 05/10/2026 al 20/10/2026"
    private static function nombreRango($desde, $hasta)
    {
        $mesDesde = self::$MESES[(int)substr($desde, 5, 2)]; $anioDesde = (int)substr($desde, 0, 4);
        $mesHasta = self::$MESES[(int)substr($hasta, 5, 2)]; $anioHasta = (int)substr($hasta, 0, 4);
        $diaDesde = (int)substr($desde, 8, 2); $diaHasta = (int)substr($hasta, 8, 2);
        $finMes = (int)date('t', strtotime($hasta));                      //último día del mes de $hasta
        $mismoMes = substr($desde, 0, 7) === substr($hasta, 0, 7);
        if ($diaDesde === 1 && $diaHasta === $finMes) {                               //meses completos
            if ($mismoMes)   { return array(ucfirst($mesDesde) . ' de ' . $anioDesde, true); }
            if ($anioDesde === $anioHasta) { return array(ucfirst($mesDesde) . ' a ' . $mesHasta . ' de ' . $anioDesde, false); }
            return array(ucfirst($mesDesde) . ' de ' . $anioDesde . ' a ' . $mesHasta . ' de ' . $anioHasta, false);
        }
        if ($mismoMes && $diaDesde === 1 && $diaHasta === 15)        { return array('Primera quincena de ' . $mesDesde . ' de ' . $anioDesde, false); }
        if ($mismoMes && $diaDesde === 16 && $diaHasta === $finMes)  { return array('Segunda quincena de ' . $mesDesde . ' de ' . $anioDesde, false); }
        $formatoFecha = function ($fecha) { return substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2) . '/' . substr($fecha, 0, 4); };
        return array('Del ' . $formatoFecha($desde) . ' al ' . $formatoFecha($hasta), false);
    }

    //Suma a las cuentas de cada peaje los valores de una consulta agrupada por peaje.
    //La consulta trae el peaje en ID_CENTRO; $mapa dice a qué cuenta va cada columna
    private static function sumar(&$porCentro, $filas, $mapa)
    {
        foreach ((array)$filas as $fila) {
            $id = (int)$fila['ID_CENTRO'];
            if (!isset($porCentro[$id])) { continue; } //un peaje que no es de estos proyectos
            foreach ($mapa as $columna => $llave) { $porCentro[$id][$llave] += $fila[$columna] + 0; }
        }
    }

    //-----------------------------------------------------------------------
    // Todo el informe
    //   $per: el periodo, como lo devuelve periodo() o rango()
    //   $proyectos: lo que devuelve proyectos()
    //   Devuelve: periodo, total (cuentas de todo), proyectos[] (cada uno con
    //   sus cuentas, sus peajes, su ausentismo por motivo, el reporte del
    //   coordinador y lo que sigue pendiente) y ausMotivos (del total)
    //-----------------------------------------------------------------------
    public function datos($per, $proyectos)
    {
        $desde = $per['desde']; $hasta = $per['hasta'];
        $informe = array('periodo' => $per, 'total' => self::ceros(), 'proyectos' => array(), 'ausMotivos' => array(),
                     'centros' => 0, 'plazoRq' => RqEstado::dias(RqEstado::SOLICITADA),
                     'plazoOficio' => max(0, HanaConfig::num('COMUNICACION_DIAS_RESPUESTA', 2)));
        if (!count($proyectos) || $per['dias'] === 0) { return $informe; } //sin proyectos, o el mes todavía no empieza

        //Los proyectos pedidos, con su número como llave
        $porProyecto = array();
        foreach ($proyectos as $proyecto) {
            $proyecto['kpi'] = self::ceros(); $proyecto['centros'] = array(); $proyecto['ausMotivos'] = array();
            $porProyecto[(int)$proyecto['ID_PROYECTO']] = $proyecto;
        }
        $idsProyectos = implode(',', array_map('intval', array_keys($porProyecto))); //solo números: va directo en el SQL

        //Los peajes y básculas activos de esos proyectos
        $porCentro = array(); $proyectoDeCentro = array();
        $centros = HanaDB::q("SELECT ID_CENTRO_OP, NOM_CENTRO_OP, TIPO_CENTRO, ID_PROYECTO
                            FROM centros_operacion
                           WHERE ESTADO = '1' AND ID_PROYECTO IN ($idsProyectos)
                           ORDER BY TIPO_CENTRO = 'BASCULA', NOM_CENTRO_OP");
        foreach ((array)$centros as $centro) {
            $id = (int)$centro['ID_CENTRO_OP'];
            $porCentro[$id] = self::ceros();
            $proyectoDeCentro[$id] = (int)$centro['ID_PROYECTO'];
            $porProyecto[(int)$centro['ID_PROYECTO']]['centros'][$id] = array('nombre' => $centro['NOM_CENTRO_OP'], 'tipo' => $centro['TIPO_CENTRO']);
        }
        $informe['centros'] = count($porCentro);
        if (!count($porCentro)) { $informe['proyectos'] = array_values($porProyecto); return $informe; }
        $idsCentros = implode(',', array_keys($porCentro));

        //---- Requisiciones pedidas en el mes (las anuladas no cuentan)
        self::sumar($porCentro, HanaDB::q(
            "SELECT ID_CENTRO_OP AS ID_CENTRO, COUNT(*) AS TOTAL,
                    SUM(ID_RQ_ESTADO = " . RqEstado::APROBADA . ") AS APROBADAS, SUM(ID_RQ_ESTADO = " . RqEstado::RECHAZADA . ") AS RECHAZADAS,
                    SUM(ID_RQ_ESTADO = " . RqEstado::SOLICITADA . ") AS PENDIENTES, SUM(TIPO_RQ = 'U') AS URGENTES
               FROM rq
              WHERE FECHA_RQ >= ? AND FECHA_RQ < ? AND ID_RQ_ESTADO <> " . RqEstado::ANULADA . " AND ID_CENTRO_OP IN ($idsCentros)
              GROUP BY ID_CENTRO_OP", 'ss', array($desde, $hasta)),
            array('TOTAL' => 'rq', 'APROBADAS' => 'rqAprob', 'RECHAZADAS' => 'rqRech', 'PENDIENTES' => 'rqPend', 'URGENTES' => 'rqUrg'));

        //---- Novedades registradas en el mes (3 finalizada y 4 cerrada = ya atendidas)
        self::sumar($porCentro, HanaDB::q(
            "SELECT ID_CENTRO_OP AS ID_CENTRO, COUNT(*) AS TOTAL,
                    SUM(ESTADO_NOVEDAD IN (3, 4)) AS ATENDIDAS, SUM(ESTADO_NOVEDAD NOT IN (3, 4)) AS ABIERTAS
               FROM novedades_hallazgos
              WHERE FEC_CREACION >= ? AND FEC_CREACION < ?
                AND ID_CENTRO_OP IN ($idsCentros)
              GROUP BY ID_CENTRO_OP", 'ss', array($desde, $hasta)),
            array('TOTAL' => 'nov', 'ATENDIDAS' => 'novCerr', 'ABIERTAS' => 'novAb'));

        //---- Listas de chequeo diligenciadas en el mes
        self::sumar($porCentro, HanaDB::q(
            "SELECT ID_CENTRO_OP AS ID_CENTRO, COUNT(*) AS TOTAL
               FROM lista_chequeo
              WHERE FEC_REGISTRO >= ? AND FEC_REGISTRO < ? AND ID_CENTRO_OP IN ($idsCentros)
              GROUP BY ID_CENTRO_OP", 'ss', array($desde, $hasta)),
            array('TOTAL' => 'listas'));

        //---- Arqueos del mes (sin los anulados). Diferencia negativa = faltante; positiva = sobrante
        self::sumar($porCentro, HanaDB::q(
            "SELECT arq.ID_CENTRO, COUNT(*) AS TOTAL, SUM(arq.DIF <> 0) AS CON_DIFERENCIA,
                    SUM(CASE WHEN arq.DIF < 0 THEN -arq.DIF ELSE 0 END) AS FALTANTE,
                    SUM(CASE WHEN arq.DIF > 0 THEN arq.DIF ELSE 0 END) AS SOBRANTE
               FROM (SELECT a.ID_CENTRO_OP AS ID_CENTRO, " . Arqueo::sqlDiferencia() . " AS DIF
                       FROM arqueo a
                      WHERE a.ESTADO = 1 AND a.FECHA >= ? AND a.FECHA < ? AND a.ID_CENTRO_OP IN ($idsCentros)) arq
              GROUP BY arq.ID_CENTRO", 'ss', array($desde, $hasta)),
            array('TOTAL' => 'arq', 'CON_DIFERENCIA' => 'arqDif', 'FALTANTE' => 'arqFalt', 'SOBRANTE' => 'arqSobr'));

        //---- Vacantes: las que se abrieron y las que se cubrieron en el mes; las que siguen
        //abiertas hoy (de este mes o de antes) y cuántas de esas ya pasaron su fecha del acuerdo de servicio
        self::sumar($porCentro, HanaDB::q(
            "SELECT ID_CENTRO_OP AS ID_CENTRO,
                    SUM(FECHA_VACANTE >= ? AND FECHA_VACANTE < ? AND ESTADO_VACANTE <> 'CANCELADA') AS NUEVAS,
                    SUM(ESTADO_VACANTE = 'CUBIERTA' AND FECHA_CIERRE >= ? AND FECHA_CIERRE < ?) AS CUBIERTAS,
                    SUM(ESTADO_VACANTE = 'ABIERTA' AND FECHA_VACANTE < ?) AS ABIERTAS,
                    SUM(ESTADO_VACANTE = 'ABIERTA' AND FECHA_VACANTE < ? AND FECHA_ANS IS NOT NULL AND FECHA_ANS < ?) AS FUERA_DE_PLAZO
               FROM vacante
              WHERE ID_CENTRO_OP IN ($idsCentros)
              GROUP BY ID_CENTRO_OP", 'sssssss', array($desde, $hasta, $desde, $hasta, $hasta, $hasta, $per['hoy'])),
            array('NUEVAS' => 'vacNuevas', 'CUBIERTAS' => 'vacCub', 'ABIERTAS' => 'vacAb', 'FUERA_DE_PLAZO' => 'vacFuera'));

        //El ausentismo (matriz de cubrimiento) se retiró del sistema: el informe ya no lo incluye.

        //Lo de cada peaje sube a su proyecto
        foreach ($porCentro as $id => $cuentas) {
            $idProyecto = $proyectoDeCentro[$id];
            $porProyecto[$idProyecto]['centros'][$id]['kpi'] = $cuentas;
            foreach ($cuentas as $llave => $valor) { $porProyecto[$idProyecto]['kpi'][$llave] += $valor; }
        }

        //---- Oficios y comunicaciones: van por proyecto (no siempre tienen peaje)
        $plazo = $informe['plazoOficio'];
        $oficios = HanaDB::q(
            "SELECT ID_PROYECTO,
                    SUM(FECHA_RECEPCION >= ? AND FECHA_RECEPCION < ?) AS RECIBIDOS,
                    SUM(FECHA_RECEPCION >= ? AND FECHA_RECEPCION < ? AND FECHA_ATENCION IS NOT NULL) AS RESUELTOS,
                    SUM(FECHA_ATENCION IS NULL AND FECHA_RECEPCION < ?) AS PENDIENTES,
                    SUM(FECHA_ATENCION IS NULL AND FECHA_RECEPCION < ? AND DATEDIFF(?, FECHA_RECEPCION) > ?) AS VENCIDOS
               FROM comunicacion
              WHERE ESTADO = 1 AND TIPO <> 'TEMA_CLIENTE' AND ID_PROYECTO IN ($idsProyectos)
              GROUP BY ID_PROYECTO", 'sssssssi', array($desde, $hasta, $desde, $hasta, $hasta, $hasta, $per['hoy'], $plazo));
        foreach ((array)$oficios as $fila) {
            $cuentasProyecto = &$porProyecto[(int)$fila['ID_PROYECTO']]['kpi'];
            $cuentasProyecto['ofRec'] += (int)$fila['RECIBIDOS']; $cuentasProyecto['ofRes'] += (int)$fila['RESUELTOS'];
            $cuentasProyecto['ofPend'] += (int)$fila['PENDIENTES']; $cuentasProyecto['ofVenc'] += (int)$fila['VENCIDOS'];
            unset($cuentasProyecto);
        }

        //El ausentismo por motivo se retiró: ausMotivos queda vacío (lo conserva ceros()/el arreglo base).

        //---- El reporte diario de cada coordinador y lo que sigue pendiente
        foreach ($porProyecto as $idProyecto => $proyecto) {
            $porProyecto[$idProyecto]['reporte'] = $this->reporteCoordinador((int)$proyecto['ID_COLABORADOR_COORDINADOR'], $idProyecto, $per);
            $porProyecto[$idProyecto]['pendientes'] = $this->pendientes($idProyecto, $per, $informe['plazoRq'], $plazo);
            $porProyecto[$idProyecto]['arqueosDif'] = $this->arqueosDif($idProyecto, $per);
            foreach ($porProyecto[$idProyecto]['kpi'] as $llave => $valor) { $informe['total'][$llave] += $valor; } //y todo sube al total
        }
        $informe['proyectos'] = array_values($porProyecto);
        return $informe;
    }

    //-----------------------------------------------------------------------
    // Cómo le fue al coordinador con su reporte diario en el periodo:
    // días con "Hoy en qué estás", visitas del cronograma y estado del vehículo
    //-----------------------------------------------------------------------
    private function reporteCoordinador($idCoordinador, $idProyecto, $per)
    {
        $desde = $per['desde']; $hasta = $per['hasta'];
        $reporte = array('hoyDias' => 0, 'hoyLaboral' => 0, 'dias' => $per['dias'], 'cumple' => 0, 'sinHoy' => array(),
                   'visProg' => 0, 'visReal' => 0, 'vehOk' => 0, 'vehNo' => 0);
        //Los días del periodo en que no registró su "Hoy en qué estás" (el número del día: 3, 7, 15...)
        $diasConHoy = array();
        if ($idCoordinador > 0) {
            foreach ((array)HanaDB::q("SELECT FECHA FROM reporte_hoy WHERE ID_COLABORADOR = ? AND FECHA >= ? AND FECHA < ?", 'iss', array($idCoordinador, $desde, $hasta)) as $fila) { $diasConHoy[$fila['FECHA']] = true; }
        }
        for ($dia = $desde; $dia < $hasta; $dia = date('Y-m-d', strtotime("$dia +1 day"))) { if (!isset($diasConHoy[$dia])) { $reporte['sinHoy'][] = (int)substr($dia, 8, 2); } }
        if ($idCoordinador > 0) {
            //Días en que registró su "Hoy en qué estás" (cualquier situación cuenta: lo que importa es que reportó)
            $fila = HanaDB::fila("SELECT COUNT(*) AS DIAS, SUM(SITUACION = 'LABORAL') AS LABORALES FROM reporte_hoy
                                WHERE ID_COLABORADOR = ? AND FECHA >= ? AND FECHA < ?", 'iss', array($idCoordinador, $desde, $hasta));
            if ($fila) { $reporte['hoyDias'] = (int)$fila['DIAS']; $reporte['hoyLaboral'] = (int)$fila['LABORALES']; }
            //Visitas planeadas en el cronograma y las que se cumplieron: marcadas como realizadas,
            //o confirmadas porque ese día registró ese peaje en "Hoy en qué estás"
            $fila = HanaDB::fila("SELECT COUNT(*) AS PROGRAMADAS,
                                      SUM(cr.ESTADO = 'REALIZADA' OR EXISTS (
                                            SELECT 1 FROM reporte_hoy hh INNER JOIN reporte_hoy_centro hc ON hc.ID_REPORTE_HOY = hh.ID_REPORTE_HOY
                                             WHERE hh.ID_COLABORADOR = cr.ID_COLABORADOR AND hh.FECHA = cr.FECHA AND hc.ID_CENTRO_OP = cr.ID_CENTRO_OP)) AS REALIZADAS
                                 FROM cronograma cr
                                WHERE cr.ID_COLABORADOR = ? AND cr.TIPO = 'VISITA' AND cr.ESTADO <> 'CANCELADA' AND cr.FECHA >= ? AND cr.FECHA < ?",
                              'iss', array($idCoordinador, $desde, $hasta));
            if ($fila) { $reporte['visProg'] = (int)$fila['PROGRAMADAS']; $reporte['visReal'] = (int)$fila['REALIZADAS']; }
        }
        $reporte['cumple'] = $reporte['dias'] > 0 ? (int)round(100 * min($reporte['hoyDias'], $reporte['dias']) / $reporte['dias']) : 0;
        //Vehículos del proyecto: días operativo y días que no lo estuvo
        $fila = HanaDB::fila("SELECT SUM(vd.ESTADO_VH = 'OPERATIVO') AS OPERATIVO, SUM(vd.ESTADO_VH <> 'OPERATIVO') AS NO_OPERATIVO
                             FROM vehiculo_dia vd INNER JOIN vehiculo v ON v.ID_VEHICULO = vd.ID_VEHICULO
                            WHERE v.ID_PROYECTO = ? AND vd.FECHA >= ? AND vd.FECHA < ?", 'iss', array((int)$idProyecto, $desde, $hasta));
        if ($fila) { $reporte['vehOk'] = (int)$fila['OPERATIVO']; $reporte['vehNo'] = (int)$fila['NO_OPERATIVO']; }
        return $reporte;
    }

    //-----------------------------------------------------------------------
    // Los arqueos del periodo que no cuadraron, del proyecto. Primero los de mayor diferencia
    //-----------------------------------------------------------------------
    private function arqueosDif($idProyecto, $per)
    {
        $dif = Arqueo::sqlDiferencia();
        $filas = HanaDB::q("SELECT a.FECHA, a.TIPO, a.CASETA, $dif AS DIFERENCIA, a.RESPONSABLE, c.NOM_CENTRO_OP AS CENTRO
                          FROM arqueo a INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = a.ID_CENTRO_OP
                         WHERE c.ID_PROYECTO = ? AND a.ESTADO = 1 AND $dif <> 0 AND a.FECHA >= ? AND a.FECHA < ?
                         ORDER BY ABS($dif) DESC, a.FECHA LIMIT 40", 'iss', array((int)$idProyecto, $per['desde'], $per['hasta']));
        return $filas ? $filas : array();
    }

    //-----------------------------------------------------------------------
    // Lo que sigue pendiente HOY en el proyecto (de este mes o de antes):
    // RQ sin aprobar, vacantes abiertas y oficios sin atender. Lo más viejo primero
    //-----------------------------------------------------------------------
    private function pendientes($idProyecto, $per, $plazoRq, $plazoOficio)
    {
        $hasta = $per['hasta']; $hoy = $per['hoy']; $numProyecto = (int)$idProyecto;
        $rqPendientes = HanaDB::q("SELECT r.NUMERO_RQ, r.TIPO_RQ, r.FECHA_RQ, c.NOM_CENTRO_OP AS CENTRO, DATEDIFF(?, " . RqEstado::sqlDesde('r.ID_RQ') . ") AS DIAS
                           FROM rq r INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                          WHERE c.ID_PROYECTO = ? AND r.ID_RQ_ESTADO = " . RqEstado::SOLICITADA . " AND r.FECHA_RQ < ?
                          ORDER BY (r.TIPO_RQ = 'U') DESC, DIAS DESC LIMIT 40", 'sis', array($hoy, $numProyecto, $hasta));
        $vacantes = HanaDB::q("SELECT v.CARGO, v.FECHA_VACANTE, v.FECHA_ANS, c.NOM_CENTRO_OP AS CENTRO, DATEDIFF(?, v.FECHA_VACANTE) AS DIAS,
                                 (v.FECHA_ANS IS NOT NULL AND v.FECHA_ANS < ?) AS FUERA
                            FROM vacante v INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = v.ID_CENTRO_OP
                           WHERE c.ID_PROYECTO = ? AND v.ESTADO_VACANTE = 'ABIERTA' AND v.FECHA_VACANTE < ?
                           ORDER BY v.FECHA_VACANTE LIMIT 40", 'ssis', array($hoy, $hoy, $numProyecto, $hasta));
        $oficios = HanaDB::q("SELECT m.ASUNTO, m.RADICADO, m.FECHA_RECEPCION, DATEDIFF(?, m.FECHA_RECEPCION) AS DIAS
                           FROM comunicacion m
                          WHERE m.ID_PROYECTO = ? AND m.ESTADO = 1 AND m.TIPO <> 'TEMA_CLIENTE' AND m.FECHA_ATENCION IS NULL AND m.FECHA_RECEPCION < ?
                          ORDER BY m.FECHA_RECEPCION LIMIT 40", 'sis', array($hoy, $numProyecto, $hasta));
        return array('rq' => $rqPendientes ? $rqPendientes : array(), 'vacantes' => $vacantes ? $vacantes : array(), 'oficios' => $oficios ? $oficios : array(),
                     'plazoRq' => (int)$plazoRq, 'plazoOficio' => (int)$plazoOficio);
    }
}
