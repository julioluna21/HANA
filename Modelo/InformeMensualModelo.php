<?php
/*
  HANA — Informe mensual de gestión (los datos)
  ---------------------------------------------------------------------------
  Reúne en un solo arreglo todo lo que pasó en un periodo (un mes, varios
  meses, una quincena o el rango de fechas que se elija), por proyecto y por
  peaje, para pintarlo en el PDF (Modelo/InformeMensualPdf.php):
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
    public static $MESES = array(1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                                 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');

    //Las cuentas de un peaje (o de un proyecto, o del total): todo arranca en cero
    public static function ceros()
    {
        return array(
            'rq' => 0, 'rqAprob' => 0, 'rqRech' => 0, 'rqPend' => 0, 'rqUrg' => 0,       //requisiciones pedidas en el mes
            'nov' => 0, 'novCerr' => 0, 'novAb' => 0,                                      //novedades registradas en el mes
            'listas' => 0,                                                                 //listas de chequeo diligenciadas
            'arq' => 0, 'arqDif' => 0, 'arqFalt' => 0.0, 'arqSobr' => 0.0,                 //arqueos y sus diferencias
            'vacNuevas' => 0, 'vacCub' => 0, 'vacAb' => 0, 'vacFuera' => 0,                //vacantes
            'ofRec' => 0, 'ofRes' => 0, 'ofPend' => 0, 'ofVenc' => 0,                      //oficios y comunicaciones
            'aus' => 0                                                                     //ausencias (personas por día)
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
                 WHERE p.Estado = '1'";
        $f = $todos ? HanaDB::q($sql . " ORDER BY p.NOM_PROYECTO")
                    : HanaDB::q($sql . " AND p.ID_COLABORADOR_COORDINADOR = ? ORDER BY p.NOM_PROYECTO", 'i', array((int)$idColaborador));
        return $f ? $f : array();
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
        $f = function ($x) { return substr($x, 8, 2) . '/' . substr($x, 5, 2) . '/' . substr($x, 0, 4); };
        //La línea de abajo del título: qué días cubre de verdad
        if ($mensual) { $detalle = $completo ? 'Mes completo' : 'Del 1 al ' . (int)substr($corte, 8, 2) . ' (mes en curso)'; }
        else          { $detalle = 'Del ' . $f($desde) . ' al ' . $f($corte) . ($completo ? '' : ' (periodo en curso)'); }
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
        $m1 = self::$MESES[(int)substr($desde, 5, 2)]; $a1 = (int)substr($desde, 0, 4);
        $m2 = self::$MESES[(int)substr($hasta, 5, 2)]; $a2 = (int)substr($hasta, 0, 4);
        $d1 = (int)substr($desde, 8, 2); $d2 = (int)substr($hasta, 8, 2);
        $finMes = (int)date('t', strtotime($hasta));                      //último día del mes de $hasta
        $mismoMes = substr($desde, 0, 7) === substr($hasta, 0, 7);
        if ($d1 === 1 && $d2 === $finMes) {                               //meses completos
            if ($mismoMes)   { return array(ucfirst($m1) . ' de ' . $a1, true); }
            if ($a1 === $a2) { return array(ucfirst($m1) . ' a ' . $m2 . ' de ' . $a1, false); }
            return array(ucfirst($m1) . ' de ' . $a1 . ' a ' . $m2 . ' de ' . $a2, false);
        }
        if ($mismoMes && $d1 === 1 && $d2 === 15)        { return array('Primera quincena de ' . $m1 . ' de ' . $a1, false); }
        if ($mismoMes && $d1 === 16 && $d2 === $finMes)  { return array('Segunda quincena de ' . $m1 . ' de ' . $a1, false); }
        $f = function ($x) { return substr($x, 8, 2) . '/' . substr($x, 5, 2) . '/' . substr($x, 0, 4); };
        return array('Del ' . $f($desde) . ' al ' . $f($hasta), false);
    }

    //Suma a las cuentas de un peaje los valores de una consulta agrupada por peaje
    private static function sumar(&$porCentro, $filas, $mapa)
    {
        foreach ((array)$filas as $x) {
            $id = (int)$x['C'];
            if (!isset($porCentro[$id])) { continue; } //un peaje que no es de estos proyectos
            foreach ($mapa as $columna => $llave) { $porCentro[$id][$llave] += $x[$columna] + 0; }
        }
    }

    //-----------------------------------------------------------------------
    // Todo el informe
    //   $per: lo que devuelve periodo() o rango()
    //   $proyectos: lo que devuelve proyectos()
    //   Devuelve: periodo, total (cuentas de todo), proyectos[] (cada uno con
    //   sus cuentas, sus peajes, su ausentismo por motivo, el reporte del
    //   coordinador y lo que sigue pendiente) y ausMotivos (del total)
    //-----------------------------------------------------------------------
    public function datos($per, $proyectos)
    {
        $d = $per['desde']; $h = $per['hasta'];
        $res = array('periodo' => $per, 'total' => self::ceros(), 'proyectos' => array(), 'ausMotivos' => array(),
                     'centros' => 0, 'plazoRq' => RqEstado::dias(RqEstado::SOLICITADA),
                     'plazoOficio' => max(0, HanaConfig::num('COMUNICACION_DIAS_RESPUESTA', 2)));
        if (!count($proyectos) || $per['dias'] === 0) { return $res; } //sin proyectos, o el mes todavía no empieza

        //Los proyectos pedidos, con su número como llave
        $proy = array();
        foreach ($proyectos as $p) {
            $p['kpi'] = self::ceros(); $p['centros'] = array(); $p['ausMotivos'] = array();
            $proy[(int)$p['ID_PROYECTO']] = $p;
        }
        $idsP = implode(',', array_map('intval', array_keys($proy))); //solo números: va directo en el SQL

        //Los peajes y básculas activos de esos proyectos
        $porCentro = array(); $proyDe = array();
        $cen = HanaDB::q("SELECT ID_CENTRO_OP, NOM_CENTRO_OP, TIPO_CENTRO, ID_PROYECTO_CENTRO_OP AS P
                            FROM centros_operacion
                           WHERE Estado = '1' AND ID_PROYECTO_CENTRO_OP IN ($idsP)
                           ORDER BY TIPO_CENTRO = 'BASCULA', NOM_CENTRO_OP");
        foreach ((array)$cen as $c) {
            $id = (int)$c['ID_CENTRO_OP'];
            $porCentro[$id] = self::ceros();
            $proyDe[$id] = (int)$c['P'];
            $proy[(int)$c['P']]['centros'][$id] = array('nombre' => $c['NOM_CENTRO_OP'], 'tipo' => $c['TIPO_CENTRO']);
        }
        $res['centros'] = count($porCentro);
        if (!count($porCentro)) { $res['proyectos'] = array_values($proy); return $res; }
        $idsC = implode(',', array_keys($porCentro));

        //---- Requisiciones pedidas en el mes (las anuladas no cuentan)
        self::sumar($porCentro, HanaDB::q(
            "SELECT ID_CENTRO_OP AS C, COUNT(*) AS T,
                    SUM(ID_RQ_ESTADO = " . RqEstado::APROBADA . ") AS A, SUM(ID_RQ_ESTADO = " . RqEstado::RECHAZADA . ") AS R,
                    SUM(ID_RQ_ESTADO = " . RqEstado::SOLICITADA . ") AS P, SUM(TIPO_RQ = 'U') AS U
               FROM rq
              WHERE FECHA_RQ >= ? AND FECHA_RQ < ? AND ID_RQ_ESTADO <> " . RqEstado::ANULADA . " AND ID_CENTRO_OP IN ($idsC)
              GROUP BY ID_CENTRO_OP", 'ss', array($d, $h)),
            array('T' => 'rq', 'A' => 'rqAprob', 'R' => 'rqRech', 'P' => 'rqPend', 'U' => 'rqUrg'));

        //---- Novedades registradas en el mes (3 finalizada y 4 cerrada = ya atendidas)
        self::sumar($porCentro, HanaDB::q(
            "SELECT ID_CENTRO_OP_NOVEDADES_HALLAZGOS AS C, COUNT(*) AS T,
                    SUM(ESTADO_NOVEDAD IN (3, 4)) AS X, SUM(ESTADO_NOVEDAD NOT IN (3, 4)) AS A
               FROM novedades_hallazgos
              WHERE FEC_CREACION_NOVEDADES_HALLAZGOS >= ? AND FEC_CREACION_NOVEDADES_HALLAZGOS < ?
                AND ID_CENTRO_OP_NOVEDADES_HALLAZGOS IN ($idsC)
              GROUP BY ID_CENTRO_OP_NOVEDADES_HALLAZGOS", 'ss', array($d, $h)),
            array('T' => 'nov', 'X' => 'novCerr', 'A' => 'novAb'));

        //---- Listas de chequeo diligenciadas en el mes
        self::sumar($porCentro, HanaDB::q(
            "SELECT ID_CENTRO_OP_LISTA_CHEQUEO AS C, COUNT(*) AS T
               FROM lista_chequeo
              WHERE FEC_REGISTRO_LISTA_CHEQUEO >= ? AND FEC_REGISTRO_LISTA_CHEQUEO < ? AND ID_CENTRO_OP_LISTA_CHEQUEO IN ($idsC)
              GROUP BY ID_CENTRO_OP_LISTA_CHEQUEO", 'ss', array($d, $h)),
            array('T' => 'listas'));

        //---- Arqueos del mes (sin los anulados). Diferencia negativa = faltante; positiva = sobrante
        self::sumar($porCentro, HanaDB::q(
            "SELECT x.C, COUNT(*) AS T, SUM(x.DIF <> 0) AS D,
                    SUM(CASE WHEN x.DIF < 0 THEN -x.DIF ELSE 0 END) AS F,
                    SUM(CASE WHEN x.DIF > 0 THEN x.DIF ELSE 0 END) AS S
               FROM (SELECT a.ID_CENTRO_OP AS C, " . Arqueo::sqlDiferencia() . " AS DIF
                       FROM arqueo a
                      WHERE a.ESTADO = 1 AND a.FECHA >= ? AND a.FECHA < ? AND a.ID_CENTRO_OP IN ($idsC)) x
              GROUP BY x.C", 'ss', array($d, $h)),
            array('T' => 'arq', 'D' => 'arqDif', 'F' => 'arqFalt', 'S' => 'arqSobr'));

        //---- Vacantes: las que se abrieron y las que se cubrieron en el mes; las que siguen
        //abiertas hoy (de este mes o de antes) y cuántas de esas ya pasaron su fecha del acuerdo de servicio
        self::sumar($porCentro, HanaDB::q(
            "SELECT ID_CENTRO_OP AS C,
                    SUM(FECHA_VACANTE >= ? AND FECHA_VACANTE < ? AND ESTADO_VACANTE <> 'CANCELADA') AS N,
                    SUM(ESTADO_VACANTE = 'CUBIERTA' AND FECHA_CIERRE >= ? AND FECHA_CIERRE < ?) AS X,
                    SUM(ESTADO_VACANTE = 'ABIERTA' AND FECHA_VACANTE < ?) AS A,
                    SUM(ESTADO_VACANTE = 'ABIERTA' AND FECHA_VACANTE < ? AND FECHA_ANS IS NOT NULL AND FECHA_ANS < ?) AS F
               FROM vacante
              WHERE ID_CENTRO_OP IN ($idsC)
              GROUP BY ID_CENTRO_OP", 'sssssss', array($d, $h, $d, $h, $h, $h, $per['hoy'])),
            array('N' => 'vacNuevas', 'X' => 'vacCub', 'A' => 'vacAb', 'F' => 'vacFuera'));

        //---- Ausentismo del mes: personas por día, solo las novedades que cuentan como ausencia
        self::sumar($porCentro, HanaDB::q(
            "SELECT r.ID_CENTRO_OP AS C, SUM(r.CANTIDAD) AS T
               FROM aus_registro r INNER JOIN aus_novedad n ON n.ID_NOVEDAD_AUS = r.ID_NOVEDAD_AUS
              WHERE n.CUENTA_AUSENCIA = 1 AND r.FECHA >= ? AND r.FECHA < ? AND r.ID_CENTRO_OP IN ($idsC)
              GROUP BY r.ID_CENTRO_OP", 'ss', array($d, $h)),
            array('T' => 'aus'));

        //Lo de cada peaje sube a su proyecto
        foreach ($porCentro as $id => $k) {
            $p = $proyDe[$id];
            $proy[$p]['centros'][$id]['kpi'] = $k;
            foreach ($k as $llave => $v) { $proy[$p]['kpi'][$llave] += $v; }
        }

        //---- Oficios y comunicaciones: van por proyecto (no siempre tienen peaje)
        $plazo = $res['plazoOficio'];
        $of = HanaDB::q(
            "SELECT ID_PROYECTO AS P,
                    SUM(FECHA_RECEPCION >= ? AND FECHA_RECEPCION < ?) AS R,
                    SUM(FECHA_RECEPCION >= ? AND FECHA_RECEPCION < ? AND FECHA_ATENCION IS NOT NULL) AS X,
                    SUM(FECHA_ATENCION IS NULL AND FECHA_RECEPCION < ?) AS A,
                    SUM(FECHA_ATENCION IS NULL AND FECHA_RECEPCION < ? AND DATEDIFF(?, FECHA_RECEPCION) > ?) AS V
               FROM comunicacion
              WHERE ESTADO = 1 AND ID_PROYECTO IN ($idsP)
              GROUP BY ID_PROYECTO", 'sssssssi', array($d, $h, $d, $h, $h, $h, $per['hoy'], $plazo));
        foreach ((array)$of as $x) {
            $k = &$proy[(int)$x['P']]['kpi'];
            $k['ofRec'] += (int)$x['R']; $k['ofRes'] += (int)$x['X']; $k['ofPend'] += (int)$x['A']; $k['ofVenc'] += (int)$x['V'];
            unset($k);
        }

        //---- Ausentismo por motivo, de cada proyecto y del total
        $am = HanaDB::q(
            "SELECT c.ID_PROYECTO_CENTRO_OP AS P, n.NOMBRE, n.ORDEN, SUM(r.CANTIDAD) AS T
               FROM aus_registro r
               INNER JOIN aus_novedad n ON n.ID_NOVEDAD_AUS = r.ID_NOVEDAD_AUS
               INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
              WHERE n.CUENTA_AUSENCIA = 1 AND r.FECHA >= ? AND r.FECHA < ? AND r.ID_CENTRO_OP IN ($idsC)
              GROUP BY c.ID_PROYECTO_CENTRO_OP, n.ID_NOVEDAD_AUS, n.NOMBRE, n.ORDEN
              ORDER BY n.ORDEN, n.NOMBRE", 'ss', array($d, $h));
        foreach ((array)$am as $x) {
            $proy[(int)$x['P']]['ausMotivos'][$x['NOMBRE']] = (int)$x['T'];
            $res['ausMotivos'][$x['NOMBRE']] = (isset($res['ausMotivos'][$x['NOMBRE']]) ? $res['ausMotivos'][$x['NOMBRE']] : 0) + (int)$x['T'];
        }

        //---- El reporte diario de cada coordinador y lo que sigue pendiente
        foreach ($proy as $idP => $p) {
            $proy[$idP]['reporte'] = $this->reporteCoordinador((int)$p['ID_COLABORADOR_COORDINADOR'], $idP, $per);
            $proy[$idP]['pendientes'] = $this->pendientes($idP, $per, $res['plazoRq'], $plazo);
            $proy[$idP]['arqueosDif'] = $this->arqueosDif($idP, $per);
            foreach ($proy[$idP]['kpi'] as $llave => $v) { $res['total'][$llave] += $v; } //y todo sube al total
        }
        $res['proyectos'] = array_values($proy);
        return $res;
    }

    //-----------------------------------------------------------------------
    // Cómo le fue al coordinador con su reporte diario en el periodo:
    // días con "Hoy en qué estás", visitas del cronograma y estado del vehículo
    //-----------------------------------------------------------------------
    private function reporteCoordinador($idCoordinador, $idProyecto, $per)
    {
        $d = $per['desde']; $h = $per['hasta'];
        $r = array('hoyDias' => 0, 'hoyLaboral' => 0, 'dias' => $per['dias'], 'cumple' => 0, 'sinHoy' => array(),
                   'visProg' => 0, 'visReal' => 0, 'vehOk' => 0, 'vehNo' => 0);
        //Los días del periodo en que no registró su "Hoy en qué estás" (el número del día: 3, 7, 15...)
        $con = array();
        if ($idCoordinador > 0) {
            foreach ((array)HanaDB::q("SELECT FECHA FROM reporte_hoy WHERE ID_COLABORADOR = ? AND FECHA >= ? AND FECHA < ?", 'iss', array($idCoordinador, $d, $h)) as $x) { $con[$x['FECHA']] = true; }
        }
        for ($f = $d; $f < $h; $f = date('Y-m-d', strtotime("$f +1 day"))) { if (!isset($con[$f])) { $r['sinHoy'][] = (int)substr($f, 8, 2); } }
        if ($idCoordinador > 0) {
            //Días en que registró su "Hoy en qué estás" (cualquier situación cuenta: lo que importa es que reportó)
            $f = HanaDB::fila("SELECT COUNT(*) AS T, SUM(SITUACION = 'LABORAL') AS L FROM reporte_hoy
                                WHERE ID_COLABORADOR = ? AND FECHA >= ? AND FECHA < ?", 'iss', array($idCoordinador, $d, $h));
            if ($f) { $r['hoyDias'] = (int)$f['T']; $r['hoyLaboral'] = (int)$f['L']; }
            //Visitas planeadas en el cronograma y las que se cumplieron: marcadas como realizadas,
            //o confirmadas porque ese día registró ese peaje en "Hoy en qué estás"
            $f = HanaDB::fila("SELECT COUNT(*) AS T,
                                      SUM(cr.ESTADO = 'REALIZADA' OR EXISTS (
                                            SELECT 1 FROM reporte_hoy hh INNER JOIN reporte_hoy_centro hc ON hc.ID_REPORTE_HOY = hh.ID_REPORTE_HOY
                                             WHERE hh.ID_COLABORADOR = cr.ID_COLABORADOR AND hh.FECHA = cr.FECHA AND hc.ID_CENTRO_OP = cr.ID_CENTRO_OP)) AS X
                                 FROM cronograma cr
                                WHERE cr.ID_COLABORADOR = ? AND cr.TIPO = 'VISITA' AND cr.ESTADO <> 'CANCELADA' AND cr.FECHA >= ? AND cr.FECHA < ?",
                              'iss', array($idCoordinador, $d, $h));
            if ($f) { $r['visProg'] = (int)$f['T']; $r['visReal'] = (int)$f['X']; }
        }
        $r['cumple'] = $r['dias'] > 0 ? (int)round(100 * min($r['hoyDias'], $r['dias']) / $r['dias']) : 0;
        //Vehículos del proyecto: días operativo y días que no lo estuvo
        $f = HanaDB::fila("SELECT SUM(vd.ESTADO_VH = 'OPERATIVO') AS O, SUM(vd.ESTADO_VH <> 'OPERATIVO') AS N
                             FROM vehiculo_dia vd INNER JOIN vehiculo v ON v.ID_VEHICULO = vd.ID_VEHICULO
                            WHERE v.ID_PROYECTO = ? AND vd.FECHA >= ? AND vd.FECHA < ?", 'iss', array((int)$idProyecto, $d, $h));
        if ($f) { $r['vehOk'] = (int)$f['O']; $r['vehNo'] = (int)$f['N']; }
        return $r;
    }

    //-----------------------------------------------------------------------
    // Los arqueos del periodo que no cuadraron, del proyecto. Primero los de mayor diferencia
    //-----------------------------------------------------------------------
    private function arqueosDif($idProyecto, $per)
    {
        $dif = Arqueo::sqlDiferencia();
        $f = HanaDB::q("SELECT a.FECHA, a.TIPO, a.CASETA, $dif AS DIFERENCIA, a.RESPONSABLE, c.NOM_CENTRO_OP AS CENTRO
                          FROM arqueo a INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = a.ID_CENTRO_OP
                         WHERE c.ID_PROYECTO_CENTRO_OP = ? AND a.ESTADO = 1 AND $dif <> 0 AND a.FECHA >= ? AND a.FECHA < ?
                         ORDER BY ABS($dif) DESC, a.FECHA LIMIT 40", 'iss', array((int)$idProyecto, $per['desde'], $per['hasta']));
        return $f ? $f : array();
    }

    //-----------------------------------------------------------------------
    // Lo que sigue pendiente HOY en el proyecto (de este mes o de antes):
    // RQ sin aprobar, vacantes abiertas y oficios sin atender. Lo más viejo primero
    //-----------------------------------------------------------------------
    private function pendientes($idProyecto, $per, $plazoRq, $plazoOficio)
    {
        $h = $per['hasta']; $hoy = $per['hoy']; $idP = (int)$idProyecto;
        $rq = HanaDB::q("SELECT r.NUMERO_RQ, r.TIPO_RQ, r.FECHA_RQ, c.NOM_CENTRO_OP AS CENTRO, DATEDIFF(?, r.FEC_ESTADO) AS DIAS
                           FROM rq r INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                          WHERE c.ID_PROYECTO_CENTRO_OP = ? AND r.ID_RQ_ESTADO = " . RqEstado::SOLICITADA . " AND r.FECHA_RQ < ?
                          ORDER BY (r.TIPO_RQ = 'U') DESC, r.FEC_ESTADO LIMIT 40", 'sis', array($hoy, $idP, $h));
        $vac = HanaDB::q("SELECT v.CARGO, v.FECHA_VACANTE, v.FECHA_ANS, c.NOM_CENTRO_OP AS CENTRO, DATEDIFF(?, v.FECHA_VACANTE) AS DIAS,
                                 (v.FECHA_ANS IS NOT NULL AND v.FECHA_ANS < ?) AS FUERA
                            FROM vacante v INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = v.ID_CENTRO_OP
                           WHERE c.ID_PROYECTO_CENTRO_OP = ? AND v.ESTADO_VACANTE = 'ABIERTA' AND v.FECHA_VACANTE < ?
                           ORDER BY v.FECHA_VACANTE LIMIT 40", 'ssis', array($hoy, $hoy, $idP, $h));
        $of = HanaDB::q("SELECT m.ASUNTO, m.RADICADO, m.FECHA_RECEPCION, DATEDIFF(?, m.FECHA_RECEPCION) AS DIAS
                           FROM comunicacion m
                          WHERE m.ID_PROYECTO = ? AND m.ESTADO = 1 AND m.FECHA_ATENCION IS NULL AND m.FECHA_RECEPCION < ?
                          ORDER BY m.FECHA_RECEPCION LIMIT 40", 'sis', array($hoy, $idP, $h));
        return array('rq' => $rq ? $rq : array(), 'vacantes' => $vac ? $vac : array(), 'oficios' => $of ? $of : array(),
                     'plazoRq' => (int)$plazoRq, 'plazoOficio' => (int)$plazoOficio);
    }
}
