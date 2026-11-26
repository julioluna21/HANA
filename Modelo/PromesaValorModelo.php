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

    //El mes de todo un proyecto: sus peajes ($centros, filas con ID_CENTRO_OP, NOM_CENTRO_OP y TIPO_CENTRO)
    //sumados por cargo y día. Devuelve [cargos, casillas] con la misma forma de cargos() y mes():
    //  PROMESA_HORAS    la suma de lo prometido en los peajes que tienen promesa ese día
    //  EJECUTADO_HORAS  la suma de lo ejecutado; queda vacío si a algún peaje con promesa le falta
    //                   registrar lo ejecutado (ese día todavía no se puede dar por cumplido o no)
    //  JUSTIFICACION    lo que escribió cada peaje («PEAJE: …», uno por renglón) y, en los días ya
    //                   pasados, los peajes que tienen pendiente el ejecutado
    public static function mesProyecto($centros, $anio, $mes, $hoy)
    {
        $nombres = array(); $cargos = array();
        foreach ($centros as $centro) {
            $nombres[(int)$centro['ID_CENTRO_OP']] = $centro['NOM_CENTRO_OP'];
            foreach (self::cargos($centro['TIPO_CENTRO']) as $cargo) { $cargos[(int)$cargo['ID_CARGO_AUS']] = $cargo; }
        }
        if (!count($nombres)) { return array('cargos' => array(), 'casillas' => array()); }
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-d', strtotime("$desde +1 month"));
        $marcas = implode(',', array_fill(0, count($nombres), '?'));
        $filas = HanaDB::q("SELECT d.ID_CENTRO_OP, d.ID_CARGO_AUS, DAY(d.FECHA) AS DIA, d.FECHA, d.PROMESA_HORAS, d.EJECUTADO_HORAS, d.JUSTIFICACION
                              FROM pv_dia d INNER JOIN aus_cargo a ON a.ID_CARGO_AUS = d.ID_CARGO_AUS
                             WHERE d.ID_CENTRO_OP IN ($marcas) AND d.FECHA >= ? AND d.FECHA < ?
                             ORDER BY d.FECHA, a.ORDEN",
                           str_repeat('i', count($nombres)) . 'ss', array_merge(array_keys($nombres), array($desde, $hasta)));
        $suma = array();
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $idCargo = (int)$fila['ID_CARGO_AUS'];
            if (!isset($cargos[$idCargo])) { continue; } //un cargo que ya no está activo
            $llave = $idCargo . '-' . (int)$fila['DIA'];
            if (!isset($suma[$llave])) {
                $suma[$llave] = array('ID_CARGO_AUS' => $idCargo, 'DIA' => (int)$fila['DIA'], 'FECHA' => $fila['FECHA'], 'p' => null, 'e' => null, 'pendientes' => array(), 'textos' => array());
            }
            $s = &$suma[$llave];
            $nombre = $nombres[(int)$fila['ID_CENTRO_OP']];
            if ($fila['PROMESA_HORAS'] !== null) {
                $s['p'] = (float)$s['p'] + (float)$fila['PROMESA_HORAS'];
                if ($fila['EJECUTADO_HORAS'] === null) { $s['pendientes'][] = $nombre; }
            }
            if ($fila['EJECUTADO_HORAS'] !== null) { $s['e'] = (float)$s['e'] + (float)$fila['EJECUTADO_HORAS']; }
            if (trim((string)$fila['JUSTIFICACION']) !== '') { $s['textos'][] = $nombre . ': ' . trim((string)$fila['JUSTIFICACION']); }
            unset($s);
        }
        $casillas = array();
        foreach ($suma as $s) {
            sort($s['textos']); sort($s['pendientes']);
            $textos = $s['textos'];
            if (count($s['pendientes']) && $s['FECHA'] <= $hoy) { $textos[] = 'Sin ejecutado registrado: ' . implode(', ', $s['pendientes']) . '.'; }
            $casillas[] = array('ID_CARGO_AUS' => $s['ID_CARGO_AUS'], 'DIA' => $s['DIA'], 'PROMESA_HORAS' => $s['p'],
                                'EJECUTADO_HORAS' => count($s['pendientes']) ? null : $s['e'],
                                'JUSTIFICACION' => count($textos) ? implode("\n", $textos) : null, 'FEC_MODIFICACION' => null, 'MODIFICO' => null);
        }
        //Los cargos en su orden (el de la tabla aus_cargo)
        $orden = array();
        foreach (array_merge(self::cargos('PEAJE'), self::cargos('BASCULA')) as $cargo) {
            if (isset($cargos[(int)$cargo['ID_CARGO_AUS']]) && !isset($orden[(int)$cargo['ID_CARGO_AUS']])) { $orden[(int)$cargo['ID_CARGO_AUS']] = $cargo; }
        }
        return array('cargos' => array_values($orden), 'casillas' => $casillas);
    }

    //-----------------------------------------------------------------------
    // Encargos y apoyos entre peajes
    //-----------------------------------------------------------------------
    //En la ventanita de la justificación se pueden marcar, además del texto libre, opciones fijas. Cada
    //una se guarda como un pedazo del texto, separado por « · », siempre escrito igual:
    //  por debajo:  «2 VACACIONES», «1 INCAPACIDAD», «1 VACANTE», «1 RESTRICCIÓN», «1 PERMISO O LICENCIA»
    //               «1 CUBIERTO CON ENCARGO DE RECOLECTORES»      (lo cubrió otro cargo del mismo peaje)
    //               «1 CUBIERTO DESDE ANDES (RECOLECTORES)»        (lo cubrió ese cargo de otro peaje)
    //  por encima:  «1 INDUCCIÓN», «1 SUPERNUMERARIO», «1 PROGRAMACIÓN»
    //               «1 EN ENCARGO CUBRIENDO CONTROLADORES/SUPERVISOR» (cubrió otro cargo del mismo peaje)
    //               «1 CUBRIENDO A FUSCA (RECOLECTORES)»             (cubrió ese cargo en otro peaje)
    //Solo esas opciones cuentan para cubrir horas; lo que se escribe a mano no, porque puede venir mal escrito.
    public static $MOTIVOS_DEBAJO = array('VACACIONES', 'INCAPACIDAD', 'VACANTE', 'RESTRICCIÓN', 'PERMISO O LICENCIA');
    public static $MOTIVOS_ENCIMA = array('INDUCCIÓN', 'SUPERNUMERARIO', 'PROGRAMACIÓN');

    public static function mayus($texto)
    {
        return mb_strtoupper(trim(html_entity_decode((string)$texto, ENT_QUOTES, 'UTF-8')), 'UTF-8');
    }

    //-----------------------------------------------------------------------
    // Comentario espejo: cuando una casilla dice quién cubrió a quién, la otra casilla recibe el
    // comentario que le toca (lo mismo que espejoDe/cambiosEspejo en Ajax/PromesaValorAjax.js):
    //   «1 CUBIERTO CON ENCARGO DE X»  ⇄  «1 EN ENCARGO CUBRIENDO <este cargo>»            (mismo peaje)
    //   «1 CUBIERTO DESDE P (X)»       ⇄  «1 CUBRIENDO A <este peaje> (<este cargo>)»     (otro peaje)
    // Los del mismo peaje los anota la pantalla; los de otro peaje, PromesaValorControl al guardar.
    //-----------------------------------------------------------------------
    //La opción sin la cantidad («CUBIERTO CON ENCARGO DE X»), para comparar
    public static function baseOpcion($pedazo)
    {
        return preg_replace('/^CUBIERTOS /u', 'CUBIERTO ', preg_replace('/^\d+ /u', '', (string)$pedazo));
    }
    private static function conCantidad($base, $n)
    {
        return ($n > 1 && strpos($base, 'CUBIERTO ') === 0) ? $n . ' CUBIERTOS ' . substr($base, 9) : $n . ' ' . $base;
    }
    private static function pedazos($justificacion)
    {
        $lista = array();
        foreach (explode('·', (string)$justificacion) as $pedazo) { if (trim($pedazo) !== '') { $lista[] = trim($pedazo); } }
        return $lista;
    }

    //Lo que le toca a la otra casilla por un pedazo: [peaje (en mayúsculas; null = el mismo peaje), cargo, pedazo] o null
    public static function espejo($pedazo, $miPeaje, $miCargo)
    {
        $pedazo = self::mayus($pedazo);
        if (preg_match('/^(\d+) CUBIERTOS? CON ENCARGO DE (.+)$/u', $pedazo, $m)) { return array(null, trim($m[2]), $m[1] . ' EN ENCARGO CUBRIENDO ' . $miCargo); }
        if (preg_match('/^(\d+) EN ENCARGO CUBRIENDO (.+)$/u', $pedazo, $m)) { return array(null, trim($m[2]), self::conCantidad('CUBIERTO CON ENCARGO DE ' . $miCargo, (int)$m[1])); }
        if (preg_match('/^(\d+) CUBIERTOS? DESDE (.+) \(([^()]+)\)$/u', $pedazo, $m)) { return array(trim($m[2]), trim($m[3]), $m[1] . ' CUBRIENDO A ' . $miPeaje . ' (' . $miCargo . ')'); }
        if (preg_match('/^(\d+) CUBRIENDO A (.+) \(([^()]+)\)$/u', $pedazo, $m)) { return array(trim($m[2]), trim($m[3]), self::conCantidad('CUBIERTO DESDE ' . $miPeaje . ' (' . $miCargo . ')', (int)$m[1])); }
        return null;
    }

    //Qué hay que poner o quitar en las otras casillas cuando una justificación pasa de $antes a $ahora:
    //[[accion 'poner'|'quitar', peaje o null, cargo, pedazo], ...]
    public static function cambiosEspejo($antes, $ahora, $miPeaje, $miCargo)
    {
        $viejas = array(); $nuevas = array(); $basesNuevas = array(); $lista = array();
        foreach (self::pedazos($antes) as $p) { $viejas[] = self::mayus($p); }
        foreach (self::pedazos($ahora) as $p) { $nuevas[] = self::mayus($p); $basesNuevas[] = self::baseOpcion(self::mayus($p)); }
        foreach ($nuevas as $p) {
            $e = self::espejo($p, $miPeaje, $miCargo);
            if ($e && !in_array($p, $viejas, true)) { $lista[] = array('poner', $e[0], $e[1], $e[2]); }
        }
        foreach ($viejas as $p) {
            $e = self::espejo($p, $miPeaje, $miCargo);
            if ($e && !in_array(self::baseOpcion($p), $basesNuevas, true)) { $lista[] = array('quitar', $e[0], $e[1], $e[2]); }
        }
        return $lista;
    }

    //Pone o quita una opción en una justificación (la misma opción con otra cantidad se reemplaza).
    //Las opciones van primero y el texto libre al final, como las arma la ventanita
    public static function cambiarOpcion($justificacion, $pedazo, $poner)
    {
        $base = self::baseOpcion($pedazo); $opciones = array(); $libre = array();
        foreach (self::pedazos($justificacion) as $p) {
            if (!preg_match('/^\d+ /u', $p)) { $libre[] = $p; }
            elseif (self::baseOpcion(self::mayus($p)) !== $base) { $opciones[] = $p; }
        }
        if ($poner) { $opciones[] = $pedazo; }
        return implode(' · ', array_merge($opciones, $libre));
    }

    //Los pedazos de una justificación que dicen quién cubrió a quién: lista de [tipo, peaje o null, cargo]
    //  tipo 'recibe': esta casilla fue cubierta por (peaje, cargo); 'da': esta casilla cubrió a (peaje, cargo)
    //  peaje null = el mismo peaje (encargo)
    public static function enlaces($justificacion)
    {
        $lista = array();
        foreach (explode('·', (string)$justificacion) as $pedazo) {
            $pedazo = self::mayus($pedazo);
            if (preg_match('/^\d+ CUBIERTOS? CON ENCARGO DE (.+)$/u', $pedazo, $m)) { $lista[] = array('recibe', null, trim($m[1])); }
            elseif (preg_match('/^\d+ CUBIERTOS? DESDE (.+) \(([^()]+)\)$/u', $pedazo, $m)) { $lista[] = array('recibe', trim($m[1]), trim($m[2])); }
            elseif (preg_match('/^\d+ EN ENCARGO CUBRIENDO (.+)$/u', $pedazo, $m)) { $lista[] = array('da', null, trim($m[1])); }
            elseif (preg_match('/^\d+ CUBRIENDO A (.+) \(([^()]+)\)$/u', $pedazo, $m)) { $lista[] = array('da', trim($m[1]), trim($m[2])); }
        }
        return $lista;
    }

    //La justificación sin los pedazos de «quién cubrió a quién» (para cuando eso ya se dice aparte)
    public static function sinEnlaces($justificacion)
    {
        $quedan = array();
        foreach (explode('·', (string)$justificacion) as $pedazo) {
            if (trim($pedazo) !== '' && !count(self::enlaces($pedazo))) { $quedan[] = trim($pedazo); }
        }
        return implode(' · ', $quedan);
    }

    //Cuántas horas de cada casilla se cubrieron con un encargo o desde otro peaje, según esas opciones.
    //$filas: casillas con ID_CENTRO_OP, ID_CARGO_AUS, FECHA, PROMESA_HORAS, EJECUTADO_HORAS y JUSTIFICACION
    //(para que cuenten los apoyos entre peajes, deben venir los dos peajes).
    //Reglas: solo el mismo día; un encargo, dentro del mismo peaje; entre peajes, el cargo que se marcó.
    //Basta con marcarlo en una de las dos casillas (la que quedó corta o la que tuvo horas de más). Se
    //cubre lo menor entre lo que le faltó a una y lo que le sobró a la otra: nunca pasa de lo prometido
    //y lo que sobre sigue siendo horas de más.
    //Devuelve, por casilla («centro-cargo-fecha»):
    //  cubiertas  horas que le faltaban y se cubrieron      de  [[horas, 'encargo'|'peaje', idCentro, idCargo], ...]
    //  cubrio     horas de más que se usaron para cubrir   a   [[horas, 'encargo'|'peaje', idCentro, idCargo], ...]
    public static function coberturas($filas)
    {
        $centros = array(); $cargos = array();
        foreach ((array)HanaDB::q("SELECT ID_CENTRO_OP, NOM_CENTRO_OP FROM centros_operacion") as $c) { $centros[self::mayus($c['NOM_CENTRO_OP'])] = (int)$c['ID_CENTRO_OP']; }
        foreach ((array)HanaDB::q("SELECT ID_CARGO_AUS, NOMBRE FROM aus_cargo") as $c) { $cargos[self::mayus($c['NOMBRE'])] = (int)$c['ID_CARGO_AUS']; }
        $casillas = array(); $resultado = array();
        foreach ((array)$filas as $f) {
            if ($f['PROMESA_HORAS'] === null || $f['EJECUTADO_HORAS'] === null) { continue; }
            $llave = (int)$f['ID_CENTRO_OP'] . '-' . (int)$f['ID_CARGO_AUS'] . '-' . $f['FECHA'];
            $diferencia = round((float)$f['PROMESA_HORAS'] - (float)$f['EJECUTADO_HORAS'], 2);
            $casillas[$llave] = array('centro' => (int)$f['ID_CENTRO_OP'], 'cargo' => (int)$f['ID_CARGO_AUS'], 'fecha' => $f['FECHA'],
                                      'falta' => max(0, $diferencia), 'sobra' => max(0, -$diferencia), 'j' => $f['JUSTIFICACION']);
        }
        //Quién cubre a quién: [llave de la que da, llave de la que recibe, tipo]
        $parejas = array();
        foreach ($casillas as $llave => $c) {
            foreach (self::enlaces($c['j']) as $e) {
                $centro = $e[1] === null ? $c['centro'] : (isset($centros[$e[1]]) ? $centros[$e[1]] : 0);
                $cargo = isset($cargos[$e[2]]) ? $cargos[$e[2]] : 0;
                if (!$centro || !$cargo) { continue; }
                $otra = $centro . '-' . $cargo . '-' . $c['fecha'];
                if ($otra === $llave) { continue; }
                $pareja = $e[0] === 'recibe' ? array($otra, $llave) : array($llave, $otra);
                $pareja[] = $e[1] === null ? 'encargo' : 'peaje';
                $parejas[$pareja[0] . '>' . $pareja[1]] = $pareja;
            }
        }
        foreach ($parejas as $pareja) {
            list($da, $recibe, $tipo) = $pareja;
            if (!isset($casillas[$da], $casillas[$recibe])) { continue; }
            $horas = round(min($casillas[$da]['sobra'], $casillas[$recibe]['falta']), 2);
            if ($horas <= 0) { continue; }
            $casillas[$da]['sobra'] = round($casillas[$da]['sobra'] - $horas, 2);
            $casillas[$recibe]['falta'] = round($casillas[$recibe]['falta'] - $horas, 2);
            foreach (array($da, $recibe) as $llave) {
                if (!isset($resultado[$llave])) { $resultado[$llave] = array('cubiertas' => 0, 'cubrio' => 0, 'de' => array(), 'a' => array()); }
            }
            $resultado[$recibe]['cubiertas'] += $horas;
            $resultado[$recibe]['de'][] = array($horas, $tipo, $casillas[$da]['centro'], $casillas[$da]['cargo']);
            $resultado[$da]['cubrio'] += $horas;
            $resultado[$da]['a'][] = array($horas, $tipo, $casillas[$recibe]['centro'], $casillas[$recibe]['cargo']);
        }
        return $resultado;
    }

    //Las casillas de un mes de todos los peajes activos (para calcular los apoyos entre peajes)
    public static function mesTodos($anio, $mes)
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-d', strtotime("$desde +1 month"));
        $filas = HanaDB::q("SELECT ID_CENTRO_OP, ID_CARGO_AUS, FECHA, PROMESA_HORAS, EJECUTADO_HORAS, JUSTIFICACION FROM pv_dia
                             WHERE FECHA >= ? AND FECHA < ? AND PROMESA_HORAS IS NOT NULL AND EJECUTADO_HORAS IS NOT NULL",
                           'ss', array($desde, $hasta));
        return $filas ? $filas : array();
    }

    //Para la matriz de un peaje: lo cubierto de cada casilla del mes, con un texto para el que mira
    //[{cargo, dia, cubiertas, cubrio, texto}]
    public static function coberturasDelPeaje($idCentro, $anio, $mes)
    {
        $nombresCentro = array(); $nombresCargo = array();
        foreach ((array)HanaDB::q("SELECT ID_CENTRO_OP, NOM_CENTRO_OP FROM centros_operacion") as $c) { $nombresCentro[(int)$c['ID_CENTRO_OP']] = html_entity_decode($c['NOM_CENTRO_OP'], ENT_QUOTES, 'UTF-8'); }
        foreach ((array)HanaDB::q("SELECT ID_CARGO_AUS, NOMBRE FROM aus_cargo") as $c) { $nombresCargo[(int)$c['ID_CARGO_AUS']] = $c['NOMBRE']; }
        $lista = array();
        foreach (self::coberturas(self::mesTodos($anio, $mes)) as $llave => $c) {
            list($centro, $cargo, $fecha) = explode('-', $llave, 3);
            if ((int)$centro !== (int)$idCentro) { continue; }
            $textos = array();
            foreach ($c['de'] as $d) {
                $textos[] = self::horasTexto($d[0]) . ' cubiertas ' . ($d[1] === 'encargo' ? 'con encargo de ' . $nombresCargo[$d[3]] : 'desde ' . $nombresCentro[$d[2]] . ' (' . $nombresCargo[$d[3]] . ')');
            }
            foreach ($c['a'] as $a) {
                $textos[] = 'Cubrió ' . self::horasTexto($a[0]) . ' de ' . ($a[1] === 'encargo' ? $nombresCargo[$a[3]] . ' (encargo)' : $nombresCargo[$a[3]] . ' en ' . $nombresCentro[$a[2]]);
            }
            $lista[] = array('cargo' => (int)$cargo, 'dia' => (int)substr($fecha, 8, 2), 'cubiertas' => $c['cubiertas'], 'cubrio' => $c['cubrio'], 'texto' => implode('. ', $textos) . '.');
        }
        return $lista;
    }

    //Los peajes y básculas activos (para escoger «cubrió a otro peaje» / «lo cubrió otro peaje»)
    public static function peajesActivos()
    {
        $filas = HanaDB::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, p.NOM_PROYECTO FROM centros_operacion c
                              LEFT JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                             WHERE c.ESTADO = '1' ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP");
        return $filas ? $filas : array();
    }

    private static function horasTexto($horas)
    {
        $numero = rtrim(rtrim(number_format((float)$horas, 2, ',', '.'), '0'), ',');
        return $numero . ((float)$horas == 1 ? ' hora' : ' horas');
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

    //Para el Excel: cada casilla del mes de esos peajes (cargo y día), con su proyecto, la justificación
    //y quién la cambió por última vez. Ordenado por proyecto, peaje, día y cargo
    public static function paraExcel($idsCentros, $anio, $mes)
    {
        $idsCentros = array_map('intval', array_values($idsCentros));
        if (!count($idsCentros)) { return array(); }
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-t', strtotime($desde));
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $filas = HanaDB::q("SELECT d.FECHA, p.NOM_PROYECTO, c.NOM_CENTRO_OP, c.TIPO_CENTRO, a.NOMBRE AS CARGO, d.PROMESA_HORAS, d.EJECUTADO_HORAS,
                                   d.JUSTIFICACION, q.NOM_COLABORADOR AS QUIEN, d.FEC_MODIFICACION
                              FROM pv_dia d
                             INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = d.ID_CENTRO_OP
                             INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                             INNER JOIN aus_cargo a ON a.ID_CARGO_AUS = d.ID_CARGO_AUS
                              LEFT JOIN colaboradores q ON q.ID_COLABORADOR = d.ID_COLABORADOR
                             WHERE d.ID_CENTRO_OP IN ($marcas) AND d.FECHA BETWEEN ? AND ?
                               AND (d.PROMESA_HORAS IS NOT NULL OR d.EJECUTADO_HORAS IS NOT NULL OR (d.JUSTIFICACION IS NOT NULL AND d.JUSTIFICACION <> ''))
                             ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP, d.FECHA, a.ORDEN, a.NOMBRE",
                           str_repeat('i', count($idsCentros)) . 'ss', array_merge($idsCentros, array($desde, $hasta)));
        return is_array($filas) ? $filas : array();
    }

    //Las hojas del Excel del mes (para HanaExcel::descargar):
    //  «Por peaje»: horas prometidas, ejecutadas y que faltaron, cumplimiento, días sin cumplir y días sin
    //               registro de cada peaje, con el total. El cumplimiento cuenta lo ejecutado de cada cargo
    //               hasta lo prometido (las horas de más de un cargo no tapan las que le faltaron a otro)
    //  «Detalle»:   cada casilla (día y cargo) con lo prometido, lo ejecutado, la diferencia, el cumplimiento
    //               (ejecutado sobre prometido), la justificación y quién la cambió por última vez
    public static function hojasExcel($idsCentros, $anio, $mes, $subtitulo, $hoy)
    {
        $meses = array('', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');
        $periodo = $meses[$mes] . ' de ' . $anio;
        $detalle = array(); $porPeaje = array();
        foreach (self::paraExcel($idsCentros, $anio, $mes) as $c) {
            $p = $c['PROMESA_HORAS'] !== null ? (float)$c['PROMESA_HORAS'] : null;
            $e = $c['EJECUTADO_HORAS'] !== null ? (float)$c['EJECUTADO_HORAS'] : null;
            $detalle[] = array($c['FECHA'], $c['NOM_PROYECTO'], $c['NOM_CENTRO_OP'], $c['CARGO'], $p, $e, $p !== null && $e !== null ? round($p - $e, 2) : null,
                               $p > 0 && $e !== null ? 100 * $e / $p : null, $c['JUSTIFICACION'], $c['QUIEN'], $c['FEC_MODIFICACION']);
            $llave = $c['NOM_PROYECTO'] . '|' . $c['NOM_CENTRO_OP'];
            if (!isset($porPeaje[$llave])) {
                $porPeaje[$llave] = array('proyecto' => $c['NOM_PROYECTO'], 'peaje' => $c['NOM_CENTRO_OP'], 'prometidas' => 0, 'ejecutadas' => 0, 'cuentan' => 0,
                                          'diasMal' => array(), 'sinRegistro' => array());
            }
            $g = &$porPeaje[$llave];
            if ($p !== null && $e === null && $c['FECHA'] <= $hoy) { $g['sinRegistro'][$c['FECHA']] = true; }
            if ($p !== null && $e !== null) {
                $g['prometidas'] += $p; $g['ejecutadas'] += $e; $g['cuentan'] += min($e, $p);
                if ($e < $p) { $g['diasMal'][$c['FECHA']] = true; }
            }
            unset($g);
        }
        $resumen = array(); $t = array('prometidas' => 0, 'ejecutadas' => 0, 'cuentan' => 0, 'faltaron' => 0, 'diasMal' => 0, 'sinRegistro' => 0);
        foreach ($porPeaje as $g) {
            $faltaron = round($g['prometidas'] - $g['cuentan'], 2);
            $resumen[] = array($g['proyecto'], $g['peaje'], $g['prometidas'], $g['ejecutadas'], $faltaron,
                               $g['prometidas'] > 0 ? 100 * $g['cuentan'] / $g['prometidas'] : null, count($g['diasMal']), count($g['sinRegistro']));
            $t['prometidas'] += $g['prometidas']; $t['ejecutadas'] += $g['ejecutadas']; $t['cuentan'] += $g['cuentan'];
            $t['faltaron'] += $faltaron; $t['diasMal'] += count($g['diasMal']); $t['sinRegistro'] += count($g['sinRegistro']);
        }
        return array(
            array('nombre' => 'Por peaje', 'titulo' => 'Promesa de valor por peaje — ' . $periodo, 'subtitulo' => $subtitulo,
                  'columnas' => array(array('Proyecto', 16, 'texto'), array('Peaje', 22, 'texto'), array('Horas prometidas', 12, 'horas'), array('Horas ejecutadas', 12, 'horas'),
                                      array('Horas que faltaron', 12, 'horas'), array('Cumplimiento', 12, 'pct'), array('Días sin cumplir', 10, 'entero'), array('Días sin registro', 10, 'entero')),
                  'filas' => $resumen,
                  'total' => array('TOTAL', '', $t['prometidas'], $t['ejecutadas'], $t['faltaron'], $t['prometidas'] > 0 ? 100 * $t['cuentan'] / $t['prometidas'] : null, $t['diasMal'], $t['sinRegistro'])),
            array('nombre' => 'Detalle', 'titulo' => 'Promesa de valor — detalle por día y cargo — ' . $periodo, 'subtitulo' => $subtitulo,
                  'columnas' => array(array('Fecha', 11, 'fecha'), array('Proyecto', 14, 'texto'), array('Peaje', 20, 'texto'), array('Cargo', 22, 'texto'),
                                      array('Prometidas', 10, 'horas'), array('Ejecutadas', 10, 'horas'), array('Diferencia', 10, 'horas'), array('Cumplimiento', 11, 'pct'),
                                      array('Justificación', 40, 'texto'), array('Último cambio por', 22, 'texto'), array('Último cambio', 15, 'fechaHora')),
                  'filas' => $detalle));
    }

    //Guarda una casilla (peaje, cargo, día). Los valores null dejan la casilla vacía.
    //Si las tres cosas quedan vacías, la fila NO se borra (es trazabilidad): se deja en blanco y
    //queda quién la vació y cuándo. Si no existía, no se crea una fila vacía
    public static function guardar($idCentro, $idCargo, $fecha, $promesa, $ejecutado, $justificacion, $idColaborador, $ahora)
    {
        if ($promesa === null && $ejecutado === null && ($justificacion === null || $justificacion === '')) {
            return HanaDB::q("UPDATE pv_dia SET PROMESA_HORAS = NULL, EJECUTADO_HORAS = NULL, JUSTIFICACION = NULL,
                                                ID_COLABORADOR = ?, FEC_MODIFICACION = ?
                               WHERE ID_CENTRO_OP = ? AND FECHA = ? AND ID_CARGO_AUS = ?",
                             'isisi', array((int)$idColaborador, $ahora, (int)$idCentro, $fecha, (int)$idCargo));
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
