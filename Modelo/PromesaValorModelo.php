<?php
/*
  HANA - Promesa de valor y ejecutado (Reporte diario)
  ---------------------------------------------------------------------------
  Lo que iba en las hojas "(PROMESA DE VALOR)" del Excel: por cada peaje y
  cada cargo, cuántas personas se prometieron para el día y cuántas trabajaron.

  Dos tablas, y nada repetido:
    pv_promesa    la promesa, guardada UNA vez con su vigencia ("desde tal día
                  son tantas personas"). No hay un número por cada día.
                  DIAS dice en qué días de la semana aplica ("5,6,7,F" = viernes,
                  sábado, domingo y festivos); vacío = todos los días.
    pv_ejecutado  lo ejecutado: una fila por peaje, día y cargo.
  La diferencia, las semanas y el % de ejecución se calculan aquí, no se guardan.
  Los cargos y los motivos de ausencia son los del ausentismo.
*/
require_once __DIR__ . "/AusentismoModelo.php"; //de aquí salen los cargos y los motivos
require_once __DIR__ . "/HanaFechas.php";       //los festivos, para los días que se repiten

class PromesaValor
{
    //Las semanas del mes, igual que en el Excel: 1 a 7, 8 a 14, 15 a 21, 22 a 28 y lo que sobra
    public static $SEMANAS = array('Semana 1', 'Semana 2', 'Semana 3', 'Semana 4', 'Días 29–31');

    //En qué semana cae un día del mes (0 a 4)
    public static function semana($dia) { return min(4, intdiv((int)$dia - 1, 7)); }

    private function marcas($ids) { return implode(',', array_fill(0, count($ids), '?')); } //"?,?,?" para un IN

    //Quién entra (menú, mosaicos y pantallas) lo decide el controlador con la sesión:
    //hanaPromesaEntrar, hanaPromesaConsolidado y hanaProyectosConsolidado en Control/AccesoHelper.php

    //[primer día, día siguiente al último] de un mes
    private static function rango($anio, $mes)
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        return array($desde, date('Y-m-d', strtotime("$desde +1 month")));
    }

    //-----------------------------------------------------------------------
    // La promesa: las reglas guardadas y cómo se sabe la de un día
    //-----------------------------------------------------------------------
    //Todas las reglas de unos centros: [idCentro][idCargo] = lista de filas
    public function reglas($idsCentros)
    {
        if (!count($idsCentros)) { return array(); }
        $f = HanaDB::q("SELECT ID_PV_PROMESA, ID_CENTRO_OP, ID_CARGO_AUS, DESDE, HASTA, DIAS, CANTIDAD FROM pv_promesa
                         WHERE ID_CENTRO_OP IN (" . $this->marcas($idsCentros) . ")",
                       str_repeat('i', count($idsCentros)), array_map('intval', $idsCentros));
        $res = array();
        foreach ((array)$f as $r) { $res[(int)$r['ID_CENTRO_OP']][(int)$r['ID_CARGO_AUS']][] = $r; }
        return $res;
    }

    //Los días de la semana que se pueden marcar: 1 = lunes ... 7 = domingo, F = festivos
    public static $DIAS = array('1' => 'Lunes', '2' => 'Martes', '3' => 'Miércoles', '4' => 'Jueves', '5' => 'Viernes',
                                '6' => 'Sábado', '7' => 'Domingo', 'F' => 'Festivos');

    //¿La regla aplica ese día de la semana? Sin DIAS, aplica todos los días.
    //Un festivo cuenta si la regla marca "Festivos" o si marca su día de la semana
    public static function aplicaDia($r, $fecha)
    {
        if (!isset($r['DIAS']) || $r['DIAS'] === null || $r['DIAS'] === '') { return true; }
        $dias = explode(',', $r['DIAS']);
        if (in_array(date('N', strtotime($fecha)), $dias, true)) { return true; }
        return in_array('F', $dias, true) && HanaFechas::esFestivo($fecha);
    }

    //La promesa de un día, mirando las reglas de UN cargo en UN centro.
    //Si varias cubren el día, manda la más específica:
    //  1. un rango (DESDE y HASTA) con días marcados
    //  2. un rango, todos los días (un puente festivo, una temporada)
    //  3. una regla con días marcados, sin fecha final (la contingencia de cada viernes)
    //  4. la promesa de siempre
    //Dentro de cada nivel, la que empieza después (o la última que se guardó).
    //El número de la que gana es el total del día: no se suma a las demás.
    //Devuelve null cuando para ese día todavía no se ha definido nada
    public static function resolver($reglas, $fecha)
    {
        $gana = array(1 => null, 2 => null, 3 => null, 4 => null);
        foreach ((array)$reglas as $r) {
            if ($r['DESDE'] > $fecha) { continue; }                                  //todavía no empieza a regir
            if ($r['HASTA'] !== null && $r['HASTA'] < $fecha) { continue; }         //un rango que ya terminó
            if (!self::aplicaDia($r, $fecha)) { continue; }                         //no es uno de sus días
            $conDias = isset($r['DIAS']) && $r['DIAS'] !== null && $r['DIAS'] !== '';
            $nivel = $r['HASTA'] !== null ? ($conDias ? 1 : 2) : ($conDias ? 3 : 4);
            if ($gana[$nivel] === null || self::masNueva($r, $gana[$nivel])) { $gana[$nivel] = $r; }
        }
        foreach ($gana as $r) { if ($r !== null) { return (int)$r['CANTIDAD']; } }
        return null;
    }

    //Entre dos reglas, la que empieza después; si empiezan igual, la que se guardó de última
    private static function masNueva($a, $b)
    {
        if ($a['DESDE'] !== $b['DESDE']) { return $a['DESDE'] > $b['DESDE']; }
        return (int)$a['ID_PV_PROMESA'] > (int)$b['ID_PV_PROMESA'];
    }

    //La promesa de un centro en un día: [idCargo] = personas (solo los cargos que tienen promesa definida)
    public function promesaDia($idCentro, $fecha)
    {
        $reglas = $this->reglas(array((int)$idCentro));
        $res = array();
        foreach ((isset($reglas[(int)$idCentro]) ? $reglas[(int)$idCentro] : array()) as $idCargo => $lista) {
            $n = self::resolver($lista, $fecha);
            if ($n !== null) { $res[(int)$idCargo] = $n; }
        }
        return $res;
    }

    //-----------------------------------------------------------------------
    // El ejecutado de un día
    //-----------------------------------------------------------------------
    //Lo registrado en un centro un día: [idCargo] = fila (cantidad, quién y cuándo)
    public function ejecutadoDia($idCentro, $fecha)
    {
        $f = HanaDB::q("SELECT e.ID_CARGO_AUS, e.CANTIDAD, e.FEC_MODIFICACION, col.NOM_COLABORADOR AS QUIEN
                          FROM pv_ejecutado e LEFT JOIN colaboradores col ON col.ID_COLABORADOR = e.ID_COLABORADOR
                         WHERE e.ID_CENTRO_OP = ? AND e.FECHA = ?", 'is', array((int)$idCentro, $fecha));
        $res = array();
        foreach ((array)$f as $x) { $res[(int)$x['ID_CARGO_AUS']] = $x; }
        return $res;
    }

    //Las ausencias que el ausentismo tiene ese día en ese centro: [idCargo] = [ausencias, motivos[]].
    //Es el "porqué" de la diferencia: no se copia, se lee de aus_registro cada vez
    public function ausenciasDia($idCentro, $fecha)
    {
        $f = HanaDB::q("SELECT r.ID_CARGO_AUS, n.NOMBRE, n.CUENTA_AUSENCIA, r.CANTIDAD
                          FROM aus_registro r INNER JOIN aus_novedad n ON n.ID_NOVEDAD_AUS = r.ID_NOVEDAD_AUS
                         WHERE r.ID_CENTRO_OP = ? AND r.FECHA = ? ORDER BY n.ORDEN", 'is', array((int)$idCentro, $fecha));
        $res = array();
        foreach ((array)$f as $x) {
            $c = (int)$x['ID_CARGO_AUS'];
            if (!isset($res[$c])) { $res[$c] = array('ausencias' => 0, 'motivos' => array()); }
            $cuenta = (int)$x['CUENTA_AUSENCIA'] === 1;
            if ($cuenta) { $res[$c]['ausencias'] += (int)$x['CANTIDAD']; }           //un motivo marcado como "no es ausencia" no suma
            $res[$c]['motivos'][] = array('nombre' => $x['NOMBRE'], 'n' => (int)$x['CANTIDAD'], 'cuenta' => $cuenta);
        }
        return $res;
    }

    //Guarda el ejecutado de un día: $valores = [idCargo => personas].
    //Solo toca lo que cambió, para que "quién y cuándo" diga la verdad. Devuelve cuántas filas cambió
    public function guardarDia($idCentro, $fecha, $valores, $idColaborador, $ahora)
    {
        $antes = $this->ejecutadoDia($idCentro, $fecha);
        $n = 0;
        foreach ($valores as $idCargo => $cant) {
            if (isset($antes[$idCargo]) && (int)$antes[$idCargo]['CANTIDAD'] === (int)$cant) { continue; } //quedó igual
            $ok = HanaDB::q("INSERT INTO pv_ejecutado (ID_CENTRO_OP, FECHA, ID_CARGO_AUS, CANTIDAD, ID_COLABORADOR, FEC_MODIFICACION)
                             VALUES (?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE CANTIDAD = VALUES(CANTIDAD), ID_COLABORADOR = VALUES(ID_COLABORADOR), FEC_MODIFICACION = VALUES(FEC_MODIFICACION)",
                            'isiiis', array((int)$idCentro, $fecha, (int)$idCargo, (int)$cant, (int)$idColaborador, $ahora));
            if ($ok === false) { return false; }                                     //algo falló: el controlador deshace todo
            $n++;
        }
        return $n;
    }

    //Qué centros ya tienen ejecutado ese día: [idCentro] = [personas, última vez que se guardó]
    public function avanceDia($idsCentros, $fecha)
    {
        if (!count($idsCentros)) { return array(); }
        $f = HanaDB::q("SELECT ID_CENTRO_OP, SUM(CANTIDAD) AS EJECUTADO, MAX(FEC_MODIFICACION) AS ULTIMA FROM pv_ejecutado
                         WHERE FECHA = ? AND ID_CENTRO_OP IN (" . $this->marcas($idsCentros) . ") GROUP BY ID_CENTRO_OP",
                       's' . str_repeat('i', count($idsCentros)), array_merge(array($fecha), array_map('intval', $idsCentros)));
        $res = array();
        foreach ((array)$f as $x) { $res[(int)$x['ID_CENTRO_OP']] = array('ejecutado' => (int)$x['EJECUTADO'], 'ultima' => $x['ULTIMA']); }
        return $res;
    }

    //Lo que le falta HOY a un coordinador: de los peajes de los proyectos que coordina (los suyos de verdad,
    //no los que ve por una casilla), cuántos tienen promesa ese día y cuántos ya tienen el ejecutado.
    //Devuelve [conPromesa, registrados]
    public function avanceCoordinador($idColaborador, $fecha)
    {
        $f = HanaDB::q("SELECT c.ID_CENTRO_OP FROM centros_operacion c INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                         WHERE c.Estado = '1' AND p.Estado = '1' AND p.ID_COLABORADOR_COORDINADOR = ?", 'i', array((int)$idColaborador));
        $ids = array_map(function ($x) { return (int)$x['ID_CENTRO_OP']; }, $f ? $f : array());
        $con = 0; $listos = 0;
        foreach ($this->resumenDia($ids, $fecha) as $r) {
            if ($r['promesa'] <= 0) { continue; }                                    //sin promesa ese día no hay nada que registrar
            $con++;
            if ($r['ejecutado'] !== null) { $listos++; }
        }
        return array($con, $listos);
    }

    //Para el tablero: de cada centro, la promesa del día y lo ejecutado.
    //[idCentro] = [promesa, ejecutado (null si falta registrarlo)]. Los centros sin promesa definida no salen
    public function resumenDia($idsCentros, $fecha)
    {
        $reglas = $this->reglas($idsCentros);
        $hecho = $this->avanceDia($idsCentros, $fecha);
        $res = array();
        foreach ($idsCentros as $idCentro) {
            $idCentro = (int)$idCentro; $prom = 0;
            foreach ((isset($reglas[$idCentro]) ? $reglas[$idCentro] : array()) as $lista) { $prom += (int)self::resolver($lista, $fecha); }
            if ($prom <= 0 && !isset($hecho[$idCentro])) { continue; }               //ni promesa ni registro: no hay nada que mostrar
            $res[$idCentro] = array('promesa' => $prom, 'ejecutado' => isset($hecho[$idCentro]) ? $hecho[$idCentro]['ejecutado'] : null);
        }
        return $res;
    }

    //-----------------------------------------------------------------------
    // El mes de un peaje (la hoja del Excel): por cargo, la promesa y el
    // ejecutado de cada día. La pantalla saca la diferencia y las semanas
    //-----------------------------------------------------------------------
    public function mesCentro($idCentro, $tipoCentro, $anio, $mes)
    {
        list($desde, $hasta) = self::rango($anio, $mes);
        $dias = (int)date('t', strtotime($desde));
        $reglas = $this->reglas(array((int)$idCentro));
        $reglas = isset($reglas[(int)$idCentro]) ? $reglas[(int)$idCentro] : array();
        $f = HanaDB::q("SELECT ID_CARGO_AUS, DAY(FECHA) AS DIA, CANTIDAD FROM pv_ejecutado
                         WHERE ID_CENTRO_OP = ? AND FECHA >= ? AND FECHA < ?", 'iss', array((int)$idCentro, $desde, $hasta));
        $eje = array();
        foreach ((array)$f as $x) { $eje[(int)$x['ID_CARGO_AUS']][(int)$x['DIA']] = (int)$x['CANTIDAD']; }

        $cargos = array();
        foreach ((new Ausentismo())->cargos() as $c) {
            $id = (int)$c['ID_CARGO_AUS'];
            if (!Ausentismo::cargoAplica($c, $tipoCentro)) { continue; }             //un cargo de báscula no sale en un peaje
            $prom = array();
            for ($d = 1; $d <= $dias; $d++) {
                $n = self::resolver(isset($reglas[$id]) ? $reglas[$id] : array(), sprintf('%04d-%02d-%02d', $anio, $mes, $d));
                if ($n !== null && $n > 0) { $prom[$d] = $n; }
            }
            if (!count($prom) && !isset($eje[$id])) { continue; }                    //cargo sin promesa ni registros en el mes
            $cargos[] = array('id' => $id, 'nombre' => $c['NOMBRE'], 'promesa' => (object)$prom, 'ejecutado' => (object)(isset($eje[$id]) ? $eje[$id] : array()));
        }
        return array('diasMes' => $dias, 'cargos' => $cargos);
    }

    //-----------------------------------------------------------------------
    // Definir la promesa de un peaje
    //-----------------------------------------------------------------------
    //Todas las reglas de un centro, para mostrarlas: primero las vigentes y lo más nuevo
    public function vigencias($idCentro)
    {
        $f = HanaDB::q("SELECT p.ID_PV_PROMESA, p.ID_CARGO_AUS, ca.NOMBRE AS CARGO, p.DESDE, p.HASTA, p.DIAS, p.NOMBRE, p.CANTIDAD, p.FEC_MODIFICACION, col.NOM_COLABORADOR AS QUIEN
                          FROM pv_promesa p
                          INNER JOIN aus_cargo ca ON ca.ID_CARGO_AUS = p.ID_CARGO_AUS
                          LEFT JOIN colaboradores col ON col.ID_COLABORADOR = p.ID_COLABORADOR
                         WHERE p.ID_CENTRO_OP = ? ORDER BY ca.ORDEN, ca.NOMBRE, p.DESDE DESC, p.ID_PV_PROMESA DESC", 'i', array((int)$idCentro));
        return $f ? $f : array();
    }

    //Guarda la promesa "de aquí en adelante": $valores = [idCargo => personas], rige desde $desde.
    //Si un cargo ya tenía ese número para ese día, no se toca. Devuelve cuántos cargos cambiaron
    public function guardarBase($idCentro, $desde, $valores, $idColaborador, $ahora)
    {
        $reglas = $this->reglas(array((int)$idCentro));
        $reglas = isset($reglas[(int)$idCentro]) ? $reglas[(int)$idCentro] : array();
        $n = 0;
        foreach ($valores as $idCargo => $cant) {
            $soloBase = array_filter(isset($reglas[$idCargo]) ? $reglas[$idCargo] : array(), function ($r) { return $r['HASTA'] === null && ($r['DIAS'] === null || $r['DIAS'] === ''); });
            $actual = self::resolver($soloBase, $desde);                             //lo que regía ese día, sin mirar los rangos especiales
            if ($actual === (int)$cant || ($actual === null && (int)$cant === 0)) { continue; } //nada que cambiar
            $misma = HanaDB::fila("SELECT ID_PV_PROMESA FROM pv_promesa WHERE ID_CENTRO_OP = ? AND ID_CARGO_AUS = ? AND DESDE = ? AND HASTA IS NULL AND DIAS IS NULL",
                                  'iis', array((int)$idCentro, (int)$idCargo, $desde));
            if ($misma) {                                                            //ya había una regla que empieza ese mismo día: se corrige
                $idMisma = (int)$misma['ID_PV_PROMESA'];
                $otras = array_filter($soloBase, function ($r) use ($idMisma) { return (int)$r['ID_PV_PROMESA'] !== $idMisma; });
                $antes = self::resolver($otras, $desde);                             //lo que regiría ese día si esta regla no existiera
                if ($antes === (int)$cant || ($antes === null && (int)$cant === 0)) {
                    //Volvió al número de antes: la regla sobra, se borra en vez de dejar dos filas que dicen lo mismo
                    $ok = HanaDB::q("DELETE FROM pv_promesa WHERE ID_PV_PROMESA = ?", 'i', array($idMisma));
                } else {
                    $ok = HanaDB::q("UPDATE pv_promesa SET CANTIDAD = ?, ID_COLABORADOR = ?, FEC_MODIFICACION = ? WHERE ID_PV_PROMESA = ?",
                                    'iisi', array((int)$cant, (int)$idColaborador, $ahora, $idMisma));
                }
            } else {
                $ok = HanaDB::q("INSERT INTO pv_promesa (ID_CENTRO_OP, ID_CARGO_AUS, DESDE, HASTA, CANTIDAD, ID_COLABORADOR, FEC_MODIFICACION)
                                 VALUES (?, ?, ?, NULL, ?, ?, ?)", 'iisiis', array((int)$idCentro, (int)$idCargo, $desde, (int)$cant, (int)$idColaborador, $ahora));
            }
            if ($ok === false) { return false; }
            $n++;
        }
        return $n;
    }

    //Guarda un rango especial (un puente festivo, una temporada): esos días la promesa es otra
    public function guardarRango($idCentro, $idCargo, $desde, $hasta, $cantidad, $idColaborador, $ahora)
    {
        $misma = HanaDB::fila("SELECT ID_PV_PROMESA FROM pv_promesa WHERE ID_CENTRO_OP = ? AND ID_CARGO_AUS = ? AND DESDE = ? AND HASTA = ? AND DIAS IS NULL",
                              'iiss', array((int)$idCentro, (int)$idCargo, $desde, $hasta));
        if ($misma) {                                                                //el mismo rango ya existía: se corrige el número
            return HanaDB::q("UPDATE pv_promesa SET CANTIDAD = ?, ID_COLABORADOR = ?, FEC_MODIFICACION = ? WHERE ID_PV_PROMESA = ?",
                             'iisi', array((int)$cantidad, (int)$idColaborador, $ahora, (int)$misma['ID_PV_PROMESA']));
        }
        return HanaDB::q("INSERT INTO pv_promesa (ID_CENTRO_OP, ID_CARGO_AUS, DESDE, HASTA, CANTIDAD, ID_COLABORADOR, FEC_MODIFICACION)
                          VALUES (?, ?, ?, ?, ?, ?, ?)", 'iissiis', array((int)$idCentro, (int)$idCargo, $desde, $hasta, (int)$cantidad, (int)$idColaborador, $ahora));
    }

    //Guarda una regla de días que se repiten (por ejemplo, la contingencia de viernes, sábado, domingo
    //y festivos). $dias = "5,6,7,F"; $hasta = null si sigue rigiendo. Si ya había una regla igual
    //(mismo cargo, mismos días y mismas fechas), se corrige su número en vez de repetirla
    public function guardarDias($idCentro, $idCargo, $desde, $hasta, $dias, $nombre, $cantidad, $idColaborador, $ahora)
    {
        $misma = HanaDB::fila("SELECT ID_PV_PROMESA FROM pv_promesa
                                WHERE ID_CENTRO_OP = ? AND ID_CARGO_AUS = ? AND DESDE = ? AND DIAS = ? AND (HASTA = ? OR (HASTA IS NULL AND ? IS NULL))",
                              'iissss', array((int)$idCentro, (int)$idCargo, $desde, $dias, $hasta, $hasta));
        if ($misma) {
            return HanaDB::q("UPDATE pv_promesa SET CANTIDAD = ?, NOMBRE = ?, ID_COLABORADOR = ?, FEC_MODIFICACION = ? WHERE ID_PV_PROMESA = ?",
                             'isisi', array((int)$cantidad, $nombre, (int)$idColaborador, $ahora, (int)$misma['ID_PV_PROMESA']));
        }
        return HanaDB::q("INSERT INTO pv_promesa (ID_CENTRO_OP, ID_CARGO_AUS, DESDE, HASTA, DIAS, NOMBRE, CANTIDAD, ID_COLABORADOR, FEC_MODIFICACION)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", 'iissssiis',
                         array((int)$idCentro, (int)$idCargo, $desde, $hasta, $dias, $nombre, (int)$cantidad, (int)$idColaborador, $ahora));
    }

    //Una regla de ese centro, o null (para revisar antes de borrarla)
    public function regla($idRegla, $idCentro)
    {
        return HanaDB::fila("SELECT * FROM pv_promesa WHERE ID_PV_PROMESA = ? AND ID_CENTRO_OP = ?", 'ii', array((int)$idRegla, (int)$idCentro));
    }

    //Borra una regla: vuelve a regir la anterior
    public function borrarRegla($idRegla, $idCentro)
    {
        return HanaDB::q("DELETE FROM pv_promesa WHERE ID_PV_PROMESA = ? AND ID_CENTRO_OP = ?", 'ii', array((int)$idRegla, (int)$idCentro));
    }

    //-----------------------------------------------------------------------
    // Consolidado del mes (lo que ve el gerente de operaciones): promesa, ejecutado y
    // ausentismo por proyecto, por semana, por cargo y por peaje.
    //
    // Regla de cuentas: la promesa y el ejecutado se comparan SOLO en los días
    // que tienen ejecutado registrado. Así, un día que nadie llenó no aparece
    // como si hubiera faltado todo el personal: aparece como día sin registrar.
    //-----------------------------------------------------------------------
    //Un acumulador en ceros
    private static function ceros()
    {
        return array('prom' => 0, 'eje' => 0, 'semProm' => array(0, 0, 0, 0, 0), 'semEje' => array(0, 0, 0, 0, 0),
                     'aus' => array(), 'ausSem' => array(0, 0, 0, 0, 0), 'diasReg' => 0, 'diasEsp' => 0);
    }

    //Suma el acumulador $b dentro de $a
    private static function sumar(&$a, $b)
    {
        $a['prom'] += $b['prom']; $a['eje'] += $b['eje'];
        $a['diasReg'] += $b['diasReg']; $a['diasEsp'] += $b['diasEsp'];
        for ($s = 0; $s < 5; $s++) { $a['semProm'][$s] += $b['semProm'][$s]; $a['semEje'][$s] += $b['semEje'][$s]; $a['ausSem'][$s] += $b['ausSem'][$s]; }
        foreach ($b['aus'] as $idNov => $n) { $a['aus'][$idNov] = (isset($a['aus'][$idNov]) ? $a['aus'][$idNov] : 0) + $n; }
    }

    //Convierte un acumulador en la fila que se muestra: con diferencia, %, resultado y causa principal
    private static function fila($a, $motivos)
    {
        $dif = $a['eje'] - $a['prom'];                                               //positivo: se ejecutó más de lo prometido
        $aus = array(); $total = 0; $mayor = 0; $causa = null;
        foreach ($motivos as $m) {
            $n = isset($a['aus'][$m['id']]) ? (int)$a['aus'][$m['id']] : 0;
            $aus[] = $n;
            if (!$m['cuenta']) { continue; }                                         //un motivo marcado como "no es ausencia" se muestra, pero no suma
            $total += $n;
            if ($n > $mayor) { $mayor = $n; $causa = $m['nombre']; }                 //el motivo que más pesa
        }
        $sem = array();
        for ($s = 0; $s < 5; $s++) { $sem[] = $a['semEje'][$s] - $a['semProm'][$s]; }
        return array(
            'prom' => $a['prom'], 'eje' => $a['eje'], 'dif' => $dif,
            'pct' => $a['prom'] > 0 ? round(100 * $a['eje'] / $a['prom'], 1) : null,
            'res' => ($a['prom'] === 0 && $a['eje'] === 0) ? 'SIN_DATOS' : ($dif > 0 ? 'MAS' : ($dif < 0 ? 'MENOS' : 'IGUAL')),
            'aus' => $aus, 'ausTotal' => $total, 'causa' => $causa,
            'sem' => $sem, 'ausSem' => $a['ausSem'], 'diasReg' => $a['diasReg'], 'diasEsp' => $a['diasEsp']
        );
    }

    //$proyectos: [[ID_PROYECTO, NOM_PROYECTO], ...] los que la persona puede ver. $hoy: 'AAAA-MM-DD'.
    //$soloCargos: ids de los cargos a mirar (por ejemplo, solo los recolectores); vacío = todos.
    //Con el filtro, todo (totales, semanas, días registrados y ausencias) se cuenta solo con esos cargos
    public function consolidado($anio, $mes, $proyectos, $hoy, $soloCargos = array())
    {
        list($desde, $hasta) = self::rango($anio, $mes);
        $diasMes = (int)date('t', strtotime($desde));
        //Hasta qué día se cuenta: si el mes va en curso, hasta hoy; si ya pasó, completo; si no ha llegado, nada
        $corte = $hoy < $desde ? 0 : ($hoy >= $hasta ? $diasMes : (int)substr($hoy, 8, 2));

        $A = new Ausentismo();
        $motivos = array();                                                          //las columnas de ausencias, en el orden del catálogo
        foreach ($A->novedadesDelMes($anio, $mes) as $n) { $motivos[] = array('id' => (int)$n['ID_NOVEDAD_AUS'], 'nombre' => $n['NOMBRE'], 'cuenta' => (int)$n['CUENTA_AUSENCIA'] === 1); }
        $cuentaMotivo = array(); foreach ($motivos as $m) { $cuentaMotivo[$m['id']] = $m['cuenta']; }
        $todosCargos = $A->cargos();
        $filtro = array_map('intval', (array)$soloCargos);
        $cargos = count($filtro) ? array_values(array_filter($todosCargos, function ($k) use ($filtro) { return in_array((int)$k['ID_CARGO_AUS'], $filtro, true); }))
                                 : $todosCargos;
        $mira = array(); foreach ($cargos as $k) { $mira[(int)$k['ID_CARGO_AUS']] = true; }   //los cargos que entran en las cuentas

        $idsProy = array_map(function ($p) { return (int)$p['ID_PROYECTO']; }, $proyectos);
        $vacio = array('periodo' => array('anio' => (int)$anio, 'mes' => (int)$mes, 'diasMes' => $diasMes, 'corte' => $corte),
                       'semanas' => self::$SEMANAS, 'motivos' => $motivos, 'proyectos' => array(), 'roles' => array(), 'total' => self::fila(self::ceros(), $motivos),
                       'cargos' => array_map(function ($k) { return array('id' => (int)$k['ID_CARGO_AUS'], 'nombre' => $k['NOMBRE']); }, $todosCargos),
                       'filtroCargos' => $filtro);
        if (!count($idsProy)) { return $vacio; }

        //Los centros activos de esos proyectos (las básculas al final, como en el ausentismo)
        $centros = HanaDB::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, c.ID_PROYECTO_CENTRO_OP AS ID_PROYECTO FROM centros_operacion c
                               WHERE c.Estado = '1' AND c.ID_PROYECTO_CENTRO_OP IN (" . $this->marcas($idsProy) . ")
                               ORDER BY c.TIPO_CENTRO = 'BASCULA', c.NOM_CENTRO_OP", str_repeat('i', count($idsProy)), $idsProy);
        $centros = $centros ? $centros : array();
        $idsCentros = array_map(function ($c) { return (int)$c['ID_CENTRO_OP']; }, $centros);
        if (!count($idsCentros)) { return $vacio; }
        $reglas = $this->reglas($idsCentros);
        $m = $this->marcas($idsCentros);
        $tipos = 'ss' . str_repeat('i', count($idsCentros));
        $params = array_merge(array($desde, $hasta), $idsCentros);

        //Lo más fino que se acumula: cada centro con cada cargo
        $acum = array();                                                             //[idCentro][idCargo] = acumulador
        $diasCentro = array();                                                       //[idCentro][dia] = true: ese día tiene ejecutado
        $eje = HanaDB::q("SELECT ID_CENTRO_OP, ID_CARGO_AUS, DAY(FECHA) AS DIA, CANTIDAD FROM pv_ejecutado
                           WHERE FECHA >= ? AND FECHA < ? AND ID_CENTRO_OP IN ($m)", $tipos, $params);
        foreach ((array)$eje as $x) {
            $ce = (int)$x['ID_CENTRO_OP']; $ca = (int)$x['ID_CARGO_AUS']; $d = (int)$x['DIA']; $s = self::semana($d);
            if (!isset($mira[$ca])) { continue; }                                    //un cargo que quedó por fuera del filtro
            if (!isset($acum[$ce][$ca])) { $acum[$ce][$ca] = self::ceros(); }
            //La promesa de ESE día para ESE cargo (solo cuenta porque el día sí se registró)
            $p = (int)self::resolver(isset($reglas[$ce][$ca]) ? $reglas[$ce][$ca] : array(), sprintf('%04d-%02d-%02d', $anio, $mes, $d));
            $acum[$ce][$ca]['prom'] += $p; $acum[$ce][$ca]['semProm'][$s] += $p;
            $acum[$ce][$ca]['eje'] += (int)$x['CANTIDAD']; $acum[$ce][$ca]['semEje'][$s] += (int)$x['CANTIDAD'];
            $diasCentro[$ce][$d] = true;
        }
        //El ausentismo del mismo mes: el porqué
        $aus = HanaDB::q("SELECT ID_CENTRO_OP, ID_CARGO_AUS, ID_NOVEDAD_AUS, DAY(FECHA) AS DIA, CANTIDAD FROM aus_registro
                           WHERE FECHA >= ? AND FECHA < ? AND ID_CENTRO_OP IN ($m)", $tipos, $params);
        foreach ((array)$aus as $x) {
            $ce = (int)$x['ID_CENTRO_OP']; $ca = (int)$x['ID_CARGO_AUS']; $nv = (int)$x['ID_NOVEDAD_AUS'];
            if (!isset($cuentaMotivo[$nv])) { continue; }                            //un motivo que ya no está activo: no tiene columna
            if (!isset($mira[$ca])) { continue; }                                    //un cargo que quedó por fuera del filtro
            if (!isset($acum[$ce][$ca])) { $acum[$ce][$ca] = self::ceros(); }
            $acum[$ce][$ca]['aus'][$nv] = (isset($acum[$ce][$ca]['aus'][$nv]) ? $acum[$ce][$ca]['aus'][$nv] : 0) + (int)$x['CANTIDAD'];
            if ($cuentaMotivo[$nv]) { $acum[$ce][$ca]['ausSem'][self::semana((int)$x['DIA'])] += (int)$x['CANTIDAD']; }
        }

        //Se arma de abajo hacia arriba: cargo → peaje → proyecto → total, y aparte por cargo (rol)
        $total = self::ceros(); $porRol = array(); $porProyecto = array();
        foreach ($proyectos as $p) { $porProyecto[(int)$p['ID_PROYECTO']] = array('id' => (int)$p['ID_PROYECTO'], 'nombre' => $p['NOM_PROYECTO'], 'acum' => self::ceros(), 'centros' => array()); }
        foreach ($centros as $c) {
            $ce = (int)$c['ID_CENTRO_OP']; $idP = (int)$c['ID_PROYECTO'];
            $aCentro = self::ceros(); $filasCargo = array(); $conPromesa = false;
            foreach ($cargos as $k) {
                $ca = (int)$k['ID_CARGO_AUS'];
                $tieneRegla = isset($reglas[$ce][$ca]);
                if (!isset($acum[$ce][$ca]) && !$tieneRegla) { continue; }           //ese cargo no existe en este peaje
                if ($tieneRegla) { $conPromesa = true; }
                $a = isset($acum[$ce][$ca]) ? $acum[$ce][$ca] : self::ceros();
                self::sumar($aCentro, $a);
                if (!isset($porRol[$ca])) { $porRol[$ca] = array('id' => $ca, 'nombre' => $k['NOMBRE'], 'acum' => self::ceros()); }
                self::sumar($porRol[$ca]['acum'], $a);
                $filasCargo[] = array('id' => $ca, 'nombre' => $k['NOMBRE'], 'fila' => self::fila($a, $motivos));
            }
            //Días: cuántos se registraron y cuántos se esperaban (los que ya pasaron y tenían promesa)
            $aCentro['diasReg'] = isset($diasCentro[$ce]) ? count($diasCentro[$ce]) : 0;
            if ($conPromesa) {
                for ($d = 1; $d <= $corte; $d++) {
                    $hay = 0; $f = sprintf('%04d-%02d-%02d', $anio, $mes, $d);
                    foreach ($reglas[$ce] as $idCa => $lista) { if (isset($mira[(int)$idCa])) { $hay += (int)self::resolver($lista, $f); } }
                    if ($hay > 0) { $aCentro['diasEsp']++; }
                }
            }
            $aCentro['diasEsp'] = max($aCentro['diasEsp'], $aCentro['diasReg']);     //lo registrado nunca supera lo esperado
            self::sumar($porProyecto[$idP]['acum'], $aCentro);
            self::sumar($total, $aCentro);
            $porProyecto[$idP]['centros'][] = array('id' => $ce, 'nombre' => $c['NOM_CENTRO_OP'], 'tipo' => $c['TIPO_CENTRO'],
                                                    'conPromesa' => $conPromesa, 'fila' => self::fila($aCentro, $motivos), 'cargos' => $filasCargo);
        }

        $salida = $vacio;
        foreach ($porProyecto as $p) { $p['fila'] = self::fila($p['acum'], $motivos); unset($p['acum']); $salida['proyectos'][] = $p; }
        foreach ($cargos as $k) {                                                    //los cargos en el orden del catálogo
            $ca = (int)$k['ID_CARGO_AUS'];
            if (isset($porRol[$ca])) { $salida['roles'][] = array('id' => $ca, 'nombre' => $k['NOMBRE'], 'fila' => self::fila($porRol[$ca]['acum'], $motivos)); }
        }
        $salida['total'] = self::fila($total, $motivos);
        return $salida;
    }
}
