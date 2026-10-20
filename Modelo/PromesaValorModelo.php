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
        $filas = HanaDB::q("SELECT ID_PV_PROMESA, ID_CENTRO_OP, ID_CARGO_AUS, DESDE, HASTA, DIAS, CANTIDAD FROM pv_promesa
                         WHERE ID_CENTRO_OP IN (" . $this->marcas($idsCentros) . ")",
                       str_repeat('i', count($idsCentros)), array_map('intval', $idsCentros));
        $porCentro = array();
        foreach ((array)$filas as $fila) { $porCentro[(int)$fila['ID_CENTRO_OP']][(int)$fila['ID_CARGO_AUS']][] = $fila; }
        return $porCentro;
    }

    //Los días de la semana que se pueden marcar: 1 = lunes ... 7 = domingo, F = festivos
    public static $DIAS = array('1' => 'Lunes', '2' => 'Martes', '3' => 'Miércoles', '4' => 'Jueves', '5' => 'Viernes',
                                '6' => 'Sábado', '7' => 'Domingo', 'F' => 'Festivos');

    //¿La regla aplica ese día de la semana? Sin DIAS, aplica todos los días.
    //Un festivo cuenta si la regla marca "Festivos" o si marca su día de la semana
    public static function aplicaDia($regla, $fecha)
    {
        if (!isset($regla['DIAS']) || $regla['DIAS'] === null || $regla['DIAS'] === '') { return true; }
        $dias = explode(',', $regla['DIAS']);
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
        foreach ((array)$reglas as $regla) {
            if ($regla['DESDE'] > $fecha) { continue; }                                  //todavía no empieza a regir
            if ($regla['HASTA'] !== null && $regla['HASTA'] < $fecha) { continue; }         //un rango que ya terminó
            if (!self::aplicaDia($regla, $fecha)) { continue; }                         //no es uno de sus días
            $conDias = isset($regla['DIAS']) && $regla['DIAS'] !== null && $regla['DIAS'] !== '';
            $nivel = $regla['HASTA'] !== null ? ($conDias ? 1 : 2) : ($conDias ? 3 : 4);
            if ($gana[$nivel] === null || self::masNueva($regla, $gana[$nivel])) { $gana[$nivel] = $regla; }
        }
        foreach ($gana as $regla) { if ($regla !== null) { return (int)$regla['CANTIDAD']; } }
        return null;
    }

    //Entre dos reglas, la que empieza después; si empiezan igual, la que se guardó de última
    private static function masNueva($regla, $otra)
    {
        if ($regla['DESDE'] !== $otra['DESDE']) { return $regla['DESDE'] > $otra['DESDE']; }
        return (int)$regla['ID_PV_PROMESA'] > (int)$otra['ID_PV_PROMESA'];
    }

    //La promesa de un centro en un día: [idCargo] = personas (solo los cargos que tienen promesa definida)
    public function promesaDia($idCentro, $fecha)
    {
        $reglas = $this->reglas(array((int)$idCentro));
        $porCargo = array();
        foreach ((isset($reglas[(int)$idCentro]) ? $reglas[(int)$idCentro] : array()) as $idCargo => $lista) {
            $personas = self::resolver($lista, $fecha);
            if ($personas !== null) { $porCargo[(int)$idCargo] = $personas; }
        }
        return $porCargo;
    }

    //-----------------------------------------------------------------------
    // El ejecutado de un día
    //-----------------------------------------------------------------------
    //Lo registrado en un centro un día: [idCargo] = fila (cantidad, quién y cuándo)
    public function ejecutadoDia($idCentro, $fecha)
    {
        $filas = HanaDB::q("SELECT e.ID_CARGO_AUS, e.CANTIDAD, e.FEC_MODIFICACION, col.NOM_COLABORADOR AS QUIEN
                          FROM pv_ejecutado e LEFT JOIN colaboradores col ON col.ID_COLABORADOR = e.ID_COLABORADOR
                         WHERE e.ID_CENTRO_OP = ? AND e.FECHA = ?", 'is', array((int)$idCentro, $fecha));
        $porCargo = array();
        foreach ((array)$filas as $fila) { $porCargo[(int)$fila['ID_CARGO_AUS']] = $fila; }
        return $porCargo;
    }

    //Las ausencias que el ausentismo tiene ese día en ese centro: [idCargo] = [ausencias, motivos[]].
    //Es el "porqué" de la diferencia: no se copia, se lee de aus_registro cada vez
    public function ausenciasDia($idCentro, $fecha)
    {
        $filas = HanaDB::q("SELECT r.ID_CARGO_AUS, n.NOMBRE, n.CUENTA_AUSENCIA, r.CANTIDAD
                          FROM aus_registro r INNER JOIN aus_novedad n ON n.ID_NOVEDAD_AUS = r.ID_NOVEDAD_AUS
                         WHERE r.ID_CENTRO_OP = ? AND r.FECHA = ? ORDER BY n.ORDEN", 'is', array((int)$idCentro, $fecha));
        $porCargo = array();
        foreach ((array)$filas as $fila) {
            $idCargo = (int)$fila['ID_CARGO_AUS'];
            if (!isset($porCargo[$idCargo])) { $porCargo[$idCargo] = array('ausencias' => 0, 'motivos' => array()); }
            $cuenta = (int)$fila['CUENTA_AUSENCIA'] === 1;
            if ($cuenta) { $porCargo[$idCargo]['ausencias'] += (int)$fila['CANTIDAD']; }           //un motivo marcado como "no es ausencia" no suma
            $porCargo[$idCargo]['motivos'][] = array('nombre' => $fila['NOMBRE'], 'n' => (int)$fila['CANTIDAD'], 'cuenta' => $cuenta);
        }
        return $porCargo;
    }

    //Guarda el ejecutado de un día: $valores = [idCargo => personas].
    //Solo toca lo que cambió, para que "quién y cuándo" diga la verdad. Devuelve cuántas filas cambió
    public function guardarDia($idCentro, $fecha, $valores, $idColaborador, $ahora)
    {
        $antes = $this->ejecutadoDia($idCentro, $fecha);
        $cambiadas = 0;
        foreach ($valores as $idCargo => $personas) {
            if (isset($antes[$idCargo]) && (int)$antes[$idCargo]['CANTIDAD'] === (int)$personas) { continue; } //quedó igual
            $ok = HanaDB::q("INSERT INTO pv_ejecutado (ID_CENTRO_OP, FECHA, ID_CARGO_AUS, CANTIDAD, ID_COLABORADOR, FEC_MODIFICACION)
                             VALUES (?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE CANTIDAD = VALUES(CANTIDAD), ID_COLABORADOR = VALUES(ID_COLABORADOR), FEC_MODIFICACION = VALUES(FEC_MODIFICACION)",
                            'isiiis', array((int)$idCentro, $fecha, (int)$idCargo, (int)$personas, (int)$idColaborador, $ahora));
            if ($ok === false) { return false; }                                     //algo falló: el controlador deshace todo
            $cambiadas++;
        }
        return $cambiadas;
    }

    //Qué centros ya tienen ejecutado ese día: [idCentro] = [personas, última vez que se guardó]
    public function avanceDia($idsCentros, $fecha)
    {
        if (!count($idsCentros)) { return array(); }
        $filas = HanaDB::q("SELECT ID_CENTRO_OP, SUM(CANTIDAD) AS EJECUTADO, MAX(FEC_MODIFICACION) AS ULTIMA FROM pv_ejecutado
                         WHERE FECHA = ? AND ID_CENTRO_OP IN (" . $this->marcas($idsCentros) . ") GROUP BY ID_CENTRO_OP",
                       's' . str_repeat('i', count($idsCentros)), array_merge(array($fecha), array_map('intval', $idsCentros)));
        $avance = array();
        foreach ((array)$filas as $fila) { $avance[(int)$fila['ID_CENTRO_OP']] = array('ejecutado' => (int)$fila['EJECUTADO'], 'ultima' => $fila['ULTIMA']); }
        return $avance;
    }

    //Lo que le falta HOY a un coordinador: de los peajes de los proyectos que coordina (los suyos de verdad,
    //no los que ve por una casilla), cuántos tienen promesa ese día y cuántos ya tienen el ejecutado.
    //Devuelve [conPromesa, registrados]
    public function avanceCoordinador($idColaborador, $fecha)
    {
        $filas = HanaDB::q("SELECT c.ID_CENTRO_OP FROM centros_operacion c INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                         WHERE c.ESTADO = '1' AND p.ESTADO = '1' AND p.ID_COLABORADOR_COORDINADOR = ?", 'i', array((int)$idColaborador));
        $ids = array_map(function ($fila) { return (int)$fila['ID_CENTRO_OP']; }, $filas ? $filas : array());
        $conPromesa = 0; $listos = 0;
        foreach ($this->resumenDia($ids, $fecha) as $resumenCentro) {
            if ($resumenCentro['promesa'] <= 0) { continue; }                                    //sin promesa ese día no hay nada que registrar
            $conPromesa++;
            if ($resumenCentro['ejecutado'] !== null) { $listos++; }
        }
        return array($conPromesa, $listos);
    }

    //Para el tablero: de cada centro, la promesa del día y lo ejecutado.
    //[idCentro] = [promesa, ejecutado (null si falta registrarlo)]. Los centros sin promesa definida no salen
    public function resumenDia($idsCentros, $fecha)
    {
        $reglas = $this->reglas($idsCentros);
        $avance = $this->avanceDia($idsCentros, $fecha);
        $resumen = array();
        foreach ($idsCentros as $idCentro) {
            $idCentro = (int)$idCentro; $promesa = 0;
            foreach ((isset($reglas[$idCentro]) ? $reglas[$idCentro] : array()) as $lista) { $promesa += (int)self::resolver($lista, $fecha); }
            if ($promesa <= 0 && !isset($avance[$idCentro])) { continue; }               //ni promesa ni registro: no hay nada que mostrar
            $resumen[$idCentro] = array('promesa' => $promesa, 'ejecutado' => isset($avance[$idCentro]) ? $avance[$idCentro]['ejecutado'] : null);
        }
        return $resumen;
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
        $filas = HanaDB::q("SELECT ID_CARGO_AUS, DAY(FECHA) AS DIA, CANTIDAD FROM pv_ejecutado
                         WHERE ID_CENTRO_OP = ? AND FECHA >= ? AND FECHA < ?", 'iss', array((int)$idCentro, $desde, $hasta));
        $ejecutado = array();
        foreach ((array)$filas as $fila) { $ejecutado[(int)$fila['ID_CARGO_AUS']][(int)$fila['DIA']] = (int)$fila['CANTIDAD']; }

        $cargos = array();
        foreach ((new Ausentismo())->cargos() as $cargo) {
            $id = (int)$cargo['ID_CARGO_AUS'];
            if (!Ausentismo::cargoAplica($cargo, $tipoCentro)) { continue; }             //un cargo de báscula no sale en un peaje
            $promesa = array();
            for ($dia = 1; $dia <= $dias; $dia++) {
                $personas = self::resolver(isset($reglas[$id]) ? $reglas[$id] : array(), sprintf('%04d-%02d-%02d', $anio, $mes, $dia));
                if ($personas !== null && $personas > 0) { $promesa[$dia] = $personas; }
            }
            if (!count($promesa) && !isset($ejecutado[$id])) { continue; }                    //cargo sin promesa ni registros en el mes
            $cargos[] = array('id' => $id, 'nombre' => $cargo['NOMBRE'], 'promesa' => (object)$promesa, 'ejecutado' => (object)(isset($ejecutado[$id]) ? $ejecutado[$id] : array()));
        }
        return array('diasMes' => $dias, 'cargos' => $cargos);
    }

    //-----------------------------------------------------------------------
    // Definir la promesa de un peaje
    //-----------------------------------------------------------------------
    //Todas las reglas de un centro, para mostrarlas: primero las vigentes y lo más nuevo
    public function vigencias($idCentro)
    {
        $filas = HanaDB::q("SELECT p.ID_PV_PROMESA, p.ID_CARGO_AUS, ca.NOMBRE AS CARGO, p.DESDE, p.HASTA, p.DIAS, p.NOMBRE, p.CANTIDAD, p.FEC_MODIFICACION, col.NOM_COLABORADOR AS QUIEN
                          FROM pv_promesa p
                          INNER JOIN aus_cargo ca ON ca.ID_CARGO_AUS = p.ID_CARGO_AUS
                          LEFT JOIN colaboradores col ON col.ID_COLABORADOR = p.ID_COLABORADOR
                         WHERE p.ID_CENTRO_OP = ? ORDER BY ca.ORDEN, ca.NOMBRE, p.DESDE DESC, p.ID_PV_PROMESA DESC", 'i', array((int)$idCentro));
        return $filas ? $filas : array();
    }

    //Guarda la promesa "de aquí en adelante": $valores = [idCargo => personas], rige desde $desde.
    //Si un cargo ya tenía ese número para ese día, no se toca. Devuelve cuántos cargos cambiaron
    public function guardarBase($idCentro, $desde, $valores, $idColaborador, $ahora)
    {
        $reglas = $this->reglas(array((int)$idCentro));
        $reglas = isset($reglas[(int)$idCentro]) ? $reglas[(int)$idCentro] : array();
        $cambiados = 0;
        foreach ($valores as $idCargo => $personas) {
            $soloBase = array_filter(isset($reglas[$idCargo]) ? $reglas[$idCargo] : array(), function ($regla) { return $regla['HASTA'] === null && ($regla['DIAS'] === null || $regla['DIAS'] === ''); });
            $actual = self::resolver($soloBase, $desde);                             //lo que regía ese día, sin mirar los rangos especiales
            if ($actual === (int)$personas || ($actual === null && (int)$personas === 0)) { continue; } //nada que cambiar
            $misma = HanaDB::fila("SELECT ID_PV_PROMESA FROM pv_promesa WHERE ID_CENTRO_OP = ? AND ID_CARGO_AUS = ? AND DESDE = ? AND HASTA IS NULL AND DIAS IS NULL",
                                  'iis', array((int)$idCentro, (int)$idCargo, $desde));
            if ($misma) {                                                            //ya había una regla que empieza ese mismo día: se corrige
                $idMisma = (int)$misma['ID_PV_PROMESA'];
                $otras = array_filter($soloBase, function ($regla) use ($idMisma) { return (int)$regla['ID_PV_PROMESA'] !== $idMisma; });
                $antes = self::resolver($otras, $desde);                             //lo que regiría ese día si esta regla no existiera
                if ($antes === (int)$personas || ($antes === null && (int)$personas === 0)) {
                    //Volvió al número de antes: la regla sobra, se borra en vez de dejar dos filas que dicen lo mismo
                    $ok = HanaDB::q("DELETE FROM pv_promesa WHERE ID_PV_PROMESA = ?", 'i', array($idMisma));
                } else {
                    $ok = HanaDB::q("UPDATE pv_promesa SET CANTIDAD = ?, ID_COLABORADOR = ?, FEC_MODIFICACION = ? WHERE ID_PV_PROMESA = ?",
                                    'iisi', array((int)$personas, (int)$idColaborador, $ahora, $idMisma));
                }
            } else {
                $ok = HanaDB::q("INSERT INTO pv_promesa (ID_CENTRO_OP, ID_CARGO_AUS, DESDE, HASTA, CANTIDAD, ID_COLABORADOR, FEC_MODIFICACION)
                                 VALUES (?, ?, ?, NULL, ?, ?, ?)", 'iisiis', array((int)$idCentro, (int)$idCargo, $desde, (int)$personas, (int)$idColaborador, $ahora));
            }
            if ($ok === false) { return false; }
            $cambiados++;
        }
        return $cambiados;
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

    //Suma el acumulador $origen dentro de $destino
    private static function sumar(&$destino, $origen)
    {
        $destino['prom'] += $origen['prom']; $destino['eje'] += $origen['eje'];
        $destino['diasReg'] += $origen['diasReg']; $destino['diasEsp'] += $origen['diasEsp'];
        for ($semana = 0; $semana < 5; $semana++) { $destino['semProm'][$semana] += $origen['semProm'][$semana]; $destino['semEje'][$semana] += $origen['semEje'][$semana]; $destino['ausSem'][$semana] += $origen['ausSem'][$semana]; }
        foreach ($origen['aus'] as $idNov => $personas) { $destino['aus'][$idNov] = (isset($destino['aus'][$idNov]) ? $destino['aus'][$idNov] : 0) + $personas; }
    }

    //Convierte un acumulador en la fila que se muestra: con diferencia, %, resultado y causa principal
    private static function fila($acumulado, $motivos)
    {
        $diferencia = $acumulado['eje'] - $acumulado['prom'];                                               //positivo: se ejecutó más de lo prometido
        $aus = array(); $total = 0; $mayor = 0; $causa = null;
        foreach ($motivos as $motivo) {
            $personas = isset($acumulado['aus'][$motivo['id']]) ? (int)$acumulado['aus'][$motivo['id']] : 0;
            $aus[] = $personas;
            if (!$motivo['cuenta']) { continue; }                                         //un motivo marcado como "no es ausencia" se muestra, pero no suma
            $total += $personas;
            if ($personas > $mayor) { $mayor = $personas; $causa = $motivo['nombre']; }                 //el motivo que más pesa
        }
        $difSemanas = array();
        for ($semana = 0; $semana < 5; $semana++) { $difSemanas[] = $acumulado['semEje'][$semana] - $acumulado['semProm'][$semana]; }
        return array(
            'prom' => $acumulado['prom'], 'eje' => $acumulado['eje'], 'dif' => $diferencia,
            'pct' => $acumulado['prom'] > 0 ? round(100 * $acumulado['eje'] / $acumulado['prom'], 1) : null,
            'res' => ($acumulado['prom'] === 0 && $acumulado['eje'] === 0) ? 'SIN_DATOS' : ($diferencia > 0 ? 'MAS' : ($diferencia < 0 ? 'MENOS' : 'IGUAL')),
            'aus' => $aus, 'ausTotal' => $total, 'causa' => $causa,
            'sem' => $difSemanas, 'ausSem' => $acumulado['ausSem'], 'diasReg' => $acumulado['diasReg'], 'diasEsp' => $acumulado['diasEsp']
        );
    }

    //$proyectos: [[ID_PROYECTO, NOM_PROYECTO], ...] los que la persona puede ver. $hoy: 'AAAA-MM-DD'.
    //$soloCargos: ids de los cargos a mirar (por ejemplo, solo los recolectores); vacío = todos.
    //Con el filtro, todo (totales, semanas, días registrados y ausencias) se cuenta solo con esos cargos.
    //$diaDesde y $diaHasta: los días del mes que se suman (sin ellos, todo el mes).
    //$soloCentro: un solo peaje de esos proyectos (0 = todos sus peajes)
    public function consolidado($anio, $mes, $proyectos, $hoy, $soloCargos = array(), $diaDesde = 1, $diaHasta = 31, $soloCentro = 0)
    {
        list($inicioMes, $finMes) = self::rango($anio, $mes);
        $diasMes = (int)date('t', strtotime($inicioMes));
        //Hasta qué día se cuenta: si el mes va en curso, hasta hoy; si ya pasó, completo; si no ha llegado, nada
        $corte = $hoy < $inicioMes ? 0 : ($hoy >= $finMes ? $diasMes : (int)substr($hoy, 8, 2));
        //Los días pedidos, ajustados a los que tiene el mes: se suma de $desde a antes de $hasta
        $primerDia = max(1, min($diasMes, (int)$diaDesde));
        $ultimoDia = max($primerDia, min($diasMes, (int)$diaHasta));
        $desde = sprintf('%04d-%02d-%02d', $anio, $mes, $primerDia);
        $hasta = date('Y-m-d', strtotime(sprintf('%04d-%02d-%02d', $anio, $mes, $ultimoDia) . ' +1 day'));
        //Las semanas que tocan esos días (con todo el mes, todas las que tiene el mes)
        $semanasRango = array();
        for ($semana = 0; $semana < 5; $semana++) {
            $ini = $semana * 7 + 1; $fin = $semana === 4 ? $diasMes : $ini + 6;
            if ($ini <= $diasMes && $ini <= $ultimoDia && $fin >= $primerDia) { $semanasRango[] = $semana; }
        }

        $modeloAusentismo = new Ausentismo();
        $motivos = array();                                                          //las columnas de ausencias, en el orden del catálogo
        foreach ($modeloAusentismo->novedadesDelMes($anio, $mes) as $novedad) { $motivos[] = array('id' => (int)$novedad['ID_NOVEDAD_AUS'], 'nombre' => $novedad['NOMBRE'], 'cuenta' => (int)$novedad['CUENTA_AUSENCIA'] === 1); }
        $cuentaMotivo = array(); foreach ($motivos as $motivo) { $cuentaMotivo[$motivo['id']] = $motivo['cuenta']; }
        $todosCargos = $modeloAusentismo->cargos();
        $filtro = array_map('intval', (array)$soloCargos);
        $cargos = count($filtro) ? array_values(array_filter($todosCargos, function ($cargo) use ($filtro) { return in_array((int)$cargo['ID_CARGO_AUS'], $filtro, true); }))
                                 : $todosCargos;
        $cargosIncluidos = array(); foreach ($cargos as $cargo) { $cargosIncluidos[(int)$cargo['ID_CARGO_AUS']] = true; }   //los cargos que entran en las cuentas

        $idsProy = array_map(function ($proyecto) { return (int)$proyecto['ID_PROYECTO']; }, $proyectos);
        $vacio = array('periodo' => array('anio' => (int)$anio, 'mes' => (int)$mes, 'diasMes' => $diasMes, 'corte' => $corte, 'desde' => $primerDia, 'hasta' => $ultimoDia),
                       'semanas' => self::$SEMANAS, 'semanasRango' => $semanasRango, 'motivos' => $motivos, 'proyectos' => array(), 'roles' => array(), 'total' => self::fila(self::ceros(), $motivos),
                       'cargos' => array_map(function ($cargo) { return array('id' => (int)$cargo['ID_CARGO_AUS'], 'nombre' => $cargo['NOMBRE']); }, $todosCargos),
                       'filtroCargos' => $filtro);
        if (!count($idsProy)) { return $vacio; }

        //Los centros activos de esos proyectos (las básculas al final, como en el ausentismo)
        $centros = HanaDB::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, c.ID_PROYECTO FROM centros_operacion c
                               WHERE c.ESTADO = '1' AND c.ID_PROYECTO IN (" . $this->marcas($idsProy) . ")
                               ORDER BY c.TIPO_CENTRO = 'BASCULA', c.NOM_CENTRO_OP", str_repeat('i', count($idsProy)), $idsProy);
        $centros = $centros ? $centros : array();
        if ((int)$soloCentro > 0) { $centros = array_values(array_filter($centros, function ($centro) use ($soloCentro) { return (int)$centro['ID_CENTRO_OP'] === (int)$soloCentro; })); }
        $idsCentros = array_map(function ($centro) { return (int)$centro['ID_CENTRO_OP']; }, $centros);
        if (!count($idsCentros)) { return $vacio; }
        $reglas = $this->reglas($idsCentros);
        $marcas = $this->marcas($idsCentros);
        $tipos = 'ss' . str_repeat('i', count($idsCentros));
        $params = array_merge(array($desde, $hasta), $idsCentros);

        //Lo más fino que se acumula: cada centro con cada cargo
        $acum = array();                                                             //[idCentro][idCargo] = acumulador
        $diasCentro = array();                                                       //[idCentro][dia] = true: ese día tiene ejecutado
        $ejecutados = HanaDB::q("SELECT ID_CENTRO_OP, ID_CARGO_AUS, DAY(FECHA) AS DIA, CANTIDAD FROM pv_ejecutado
                           WHERE FECHA >= ? AND FECHA < ? AND ID_CENTRO_OP IN ($marcas)", $tipos, $params);
        foreach ((array)$ejecutados as $fila) {
            $idCentro = (int)$fila['ID_CENTRO_OP']; $idCargo = (int)$fila['ID_CARGO_AUS']; $dia = (int)$fila['DIA']; $semana = self::semana($dia);
            if (!isset($cargosIncluidos[$idCargo])) { continue; }                                    //un cargo que quedó por fuera del filtro
            if (!isset($acum[$idCentro][$idCargo])) { $acum[$idCentro][$idCargo] = self::ceros(); }
            //La promesa de ESE día para ESE cargo (solo cuenta porque el día sí se registró)
            $promesaDia = (int)self::resolver(isset($reglas[$idCentro][$idCargo]) ? $reglas[$idCentro][$idCargo] : array(), sprintf('%04d-%02d-%02d', $anio, $mes, $dia));
            $acum[$idCentro][$idCargo]['prom'] += $promesaDia; $acum[$idCentro][$idCargo]['semProm'][$semana] += $promesaDia;
            $acum[$idCentro][$idCargo]['eje'] += (int)$fila['CANTIDAD']; $acum[$idCentro][$idCargo]['semEje'][$semana] += (int)$fila['CANTIDAD'];
            $diasCentro[$idCentro][$dia] = true;
        }
        //El ausentismo de los mismos días: el porqué
        $ausencias = HanaDB::q("SELECT ID_CENTRO_OP, ID_CARGO_AUS, ID_NOVEDAD_AUS, DAY(FECHA) AS DIA, CANTIDAD FROM aus_registro
                           WHERE FECHA >= ? AND FECHA < ? AND ID_CENTRO_OP IN ($marcas)", $tipos, $params);
        foreach ((array)$ausencias as $fila) {
            $idCentro = (int)$fila['ID_CENTRO_OP']; $idCargo = (int)$fila['ID_CARGO_AUS']; $idNovedad = (int)$fila['ID_NOVEDAD_AUS'];
            if (!isset($cuentaMotivo[$idNovedad])) { continue; }                            //un motivo que ya no está activo: no tiene columna
            if (!isset($cargosIncluidos[$idCargo])) { continue; }                                    //un cargo que quedó por fuera del filtro
            if (!isset($acum[$idCentro][$idCargo])) { $acum[$idCentro][$idCargo] = self::ceros(); }
            $acum[$idCentro][$idCargo]['aus'][$idNovedad] = (isset($acum[$idCentro][$idCargo]['aus'][$idNovedad]) ? $acum[$idCentro][$idCargo]['aus'][$idNovedad] : 0) + (int)$fila['CANTIDAD'];
            if ($cuentaMotivo[$idNovedad]) { $acum[$idCentro][$idCargo]['ausSem'][self::semana((int)$fila['DIA'])] += (int)$fila['CANTIDAD']; }
        }

        //Se arma de abajo hacia arriba: cargo → peaje → proyecto → total, y aparte por cargo (rol)
        $total = self::ceros(); $porRol = array(); $porProyecto = array();
        foreach ($proyectos as $proyecto) { $porProyecto[(int)$proyecto['ID_PROYECTO']] = array('id' => (int)$proyecto['ID_PROYECTO'], 'nombre' => $proyecto['NOM_PROYECTO'], 'acum' => self::ceros(), 'centros' => array()); }
        foreach ($centros as $centro) {
            $idCentro = (int)$centro['ID_CENTRO_OP']; $idProyecto = (int)$centro['ID_PROYECTO'];
            $acumCentro = self::ceros(); $filasCargo = array(); $conPromesa = false;
            foreach ($cargos as $cargo) {
                $idCargo = (int)$cargo['ID_CARGO_AUS'];
                $tieneRegla = isset($reglas[$idCentro][$idCargo]);
                if (!isset($acum[$idCentro][$idCargo]) && !$tieneRegla) { continue; }           //ese cargo no existe en este peaje
                if ($tieneRegla) { $conPromesa = true; }
                $acumulado = isset($acum[$idCentro][$idCargo]) ? $acum[$idCentro][$idCargo] : self::ceros();
                self::sumar($acumCentro, $acumulado);
                if (!isset($porRol[$idCargo])) { $porRol[$idCargo] = array('id' => $idCargo, 'nombre' => $cargo['NOMBRE'], 'acum' => self::ceros()); }
                self::sumar($porRol[$idCargo]['acum'], $acumulado);
                $filasCargo[] = array('id' => $idCargo, 'nombre' => $cargo['NOMBRE'], 'fila' => self::fila($acumulado, $motivos));
            }
            //Días: cuántos se registraron y cuántos se esperaban (los que ya pasaron, dentro de los días pedidos, y tenían promesa)
            $acumCentro['diasReg'] = isset($diasCentro[$idCentro]) ? count($diasCentro[$idCentro]) : 0;
            if ($conPromesa) {
                for ($dia = $primerDia; $dia <= min($ultimoDia, $corte); $dia++) {
                    $promesaDelDia = 0; $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
                    foreach ($reglas[$idCentro] as $idCargoRegla => $lista) { if (isset($cargosIncluidos[(int)$idCargoRegla])) { $promesaDelDia += (int)self::resolver($lista, $fecha); } }
                    if ($promesaDelDia > 0) { $acumCentro['diasEsp']++; }
                }
            }
            $acumCentro['diasEsp'] = max($acumCentro['diasEsp'], $acumCentro['diasReg']);     //lo registrado nunca supera lo esperado
            self::sumar($porProyecto[$idProyecto]['acum'], $acumCentro);
            self::sumar($total, $acumCentro);
            $porProyecto[$idProyecto]['centros'][] = array('id' => $idCentro, 'nombre' => $centro['NOM_CENTRO_OP'], 'tipo' => $centro['TIPO_CENTRO'],
                                                    'conPromesa' => $conPromesa, 'fila' => self::fila($acumCentro, $motivos), 'cargos' => $filasCargo);
        }

        $salida = $vacio;
        foreach ($porProyecto as $proyecto) { $proyecto['fila'] = self::fila($proyecto['acum'], $motivos); unset($proyecto['acum']); $salida['proyectos'][] = $proyecto; }
        foreach ($cargos as $cargo) {                                                    //los cargos en el orden del catálogo
            $idCargo = (int)$cargo['ID_CARGO_AUS'];
            if (isset($porRol[$idCargo])) { $salida['roles'][] = array('id' => $idCargo, 'nombre' => $cargo['NOMBRE'], 'fila' => self::fila($porRol[$idCargo]['acum'], $motivos)); }
        }
        $salida['total'] = self::fila($total, $motivos);
        return $salida;
    }
}
