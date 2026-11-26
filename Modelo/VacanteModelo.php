<?php
/*
  HANA - Vacantes y comunicaciones (Reporte diario)
  ---------------------------------------------------------------------------
  Las hojas "Vacantes" y "Comunicaciones y SOLIC" del Excel del reporte diario.
  Los resultados que en Excel eran fórmulas (días, cumple / no cumple, abierto
  o cerrado) se calculan aquí: una vacante abierta no da días
  negativos ni "CUMPLE" por error.
  En una comunicación, el responsable es quien la registró (ID_COLABORADOR_REGISTRA):
  a cada jefe le llegan las suyas y él mismo las anota para llevarles el seguimiento.
*/
require_once __DIR__ . "/HanaDB.php";
require_once __DIR__ . "/HanaFechas.php";
require_once __DIR__ . "/HanaConfig.php";

class Vacante
{
    //Días hábiles para cubrir una vacante (Parámetros del sistema: VACANTE_DIAS_ANS)
    public static function diasAns() { return max(1, HanaConfig::num('VACANTE_DIAS_ANS', 5)); }

    //Estados de la vacante y del proceso en Gestión Humana (lo que se guarda => cómo se muestra).
    //Los de Gestión Humana los edita el administrador en Parámetros del sistema → Listas
    public static $ESTADOS = array('ABIERTA' => 'Abierta', 'CUBIERTA' => 'Cubierta', 'CANCELADA' => 'Cancelada');
    public static $ESTADOS_GH = array(); //se llena al final del archivo (HanaConfig::LISTAS)

    //Las vacantes de unos centros. $soloAbiertas: todas las abiertas, de
    //cualquier fecha; si no, las que se abrieron en el rango de fechas
    public function listar($idsCentros, $desde, $hasta, $soloAbiertas, $hoy)
    {
        if (!count($idsCentros)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $sql = "SELECT v.*, c.NOM_CENTRO_OP, p.NOM_PROYECTO, p.ID_PROYECTO,
                       (SELECT h.TEXTO FROM historial h WHERE h.MODULO = 'VACANTE' AND h.ID_REGISTRO = v.ID_VACANTE AND h.TIPO = 'SALE_CARGO'
                         ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS NOMBRE_SALE, " . self::SQL_RESPUESTA_GH . "
                  FROM vacante v
                  INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = v.ID_CENTRO_OP
                  INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                 WHERE v.ID_CENTRO_OP IN ($marcas)";
        $tipos = str_repeat('i', count($idsCentros));
        $params = array_map('intval', $idsCentros);
        if ($soloAbiertas) { $sql .= " AND v.ESTADO_VACANTE = 'ABIERTA'"; }
        else { $sql .= " AND v.FECHA_VACANTE >= ? AND v.FECHA_VACANTE < ?"; $tipos .= 'ss'; $params[] = $desde; $params[] = $hasta; }
        $sql .= " ORDER BY (v.ESTADO_VACANTE = 'ABIERTA') DESC, v.FECHA_VACANTE, v.ID_VACANTE";
        $filas = HanaDB::q($sql, $tipos, $params);
        return array_map(function ($vacante) use ($hoy) { return Vacante::calcular($vacante, $hoy); }, $filas ? $filas : array());
    }

    //La hoja del Excel (para HanaExcel::descargar): una fila por vacante de $lista (lo que devuelve listar),
    //con todo lo que se registró de ella, el acuerdo de servicio y lo de Gestión Humana
    public static function hojaExcel($lista, $titulo, $subtitulo)
    {
        $resultados = array('CUMPLE' => 'Cubierta a tiempo', 'NO_CUMPLE' => 'Cubierta tarde', 'EN_TIEMPO' => 'Dentro del acuerdo', 'VENCIDA' => 'Fuera del acuerdo');
        $filas = array();
        foreach ($lista as $v) {
            $filas[] = array($v['NUMERO_RQ_GH'], $v['NOM_PROYECTO'], $v['NOM_CENTRO_OP'], $v['CARGO'], $v['FECHA_VACANTE'], $v['FECHA_ANS'], $v['FECHA_CIERRE'],
                $v['ESTADO_VACANTE'] === 'ABIERTA' ? $v['DIAS_A_HOY'] : $v['TIEMPO_CIERRE'], $v['MOTIVO'], isset($v['NOMBRE_SALE']) ? $v['NOMBRE_SALE'] : '',
                isset(self::$ESTADOS[$v['ESTADO_VACANTE']]) ? self::$ESTADOS[$v['ESTADO_VACANTE']] : $v['ESTADO_VACANTE'],
                isset($resultados[$v['RESULTADO_ANS']]) ? $resultados[$v['RESULTADO_ANS']] : '', $v['NOMBRE_REEMPLAZO'],
                $v['ESTADO_GH'] ? (isset(self::$ESTADOS_GH[$v['ESTADO_GH']]) ? self::$ESTADOS_GH[$v['ESTADO_GH']] : $v['ESTADO_GH']) : '',
                $v['FECHA_COMPROMISO_GH'], isset($v['FECHA_RESPUESTA_GH']) ? $v['FECHA_RESPUESTA_GH'] : null, isset($v['RESPUESTA_GH']) ? $v['RESPUESTA_GH'] : '',
                isset($v['QUIEN_RESPUESTA_GH']) ? $v['QUIEN_RESPUESTA_GH'] : '', $v['OBSERVACION']);
        }
        return array('nombre' => 'Vacantes', 'titulo' => $titulo, 'subtitulo' => $subtitulo, 'filas' => $filas,
            'columnas' => array(array('#RQ', 13, 'texto'), array('Proyecto', 14, 'texto'), array('Centro', 18, 'texto'), array('Cargo', 22, 'texto'),
                                array('Fecha vacante', 11, 'fecha'), array('Acuerdo hasta', 11, 'fecha'), array('Cerrada el', 11, 'fecha'),
                                array('Días (abierta a hoy o en cerrarse)', 12, 'entero'), array('Motivo', 26, 'texto'), array('Quién lo deja', 20, 'texto'),
                                array('Estado', 11, 'texto'), array('Resultado', 16, 'texto'), array('Quién la cubre', 20, 'texto'),
                                array('Estado en Gestión Humana', 16, 'texto'), array('Fecha comprometida GH', 13, 'fecha'),
                                array('Fecha respuesta GH', 12, 'fecha'), array('Respuesta de Gestión Humana', 40, 'texto'),
                                array('Respondió', 20, 'texto'), array('Observación', 34, 'texto')));
    }

    //Lo que en el Excel eran fórmulas
    //  DIAS_A_HOY      días desde la vacante (solo mientras está abierta)
    //  TIEMPO_CIERRE   días que tardó en cubrirse
    //  RESULTADO_ANS   CUMPLE / NO_CUMPLE (cerradas); EN_TIEMPO / VENCIDA (abiertas)
    //  FECHA_COMPROMISO_GH  si está vacía, la del acuerdo de servicio
    public static function calcular($vacante, $hoy)
    {
        $dias = function ($desde, $hasta) { return (int)round((strtotime($hasta) - strtotime($desde)) / 86400); };
        $vacante['DIAS_A_HOY'] = $vacante['ESTADO_VACANTE'] === 'ABIERTA' ? $dias($vacante['FECHA_VACANTE'], $hoy) : null;
        $vacante['TIEMPO_CIERRE'] = $vacante['FECHA_CIERRE'] ? $dias($vacante['FECHA_VACANTE'], $vacante['FECHA_CIERRE']) : null;
        if ($vacante['ESTADO_VACANTE'] === 'CANCELADA') { $vacante['RESULTADO_ANS'] = null; }
        elseif ($vacante['FECHA_CIERRE'])               { $vacante['RESULTADO_ANS'] = $vacante['FECHA_CIERRE'] <= $vacante['FECHA_ANS'] ? 'CUMPLE' : 'NO_CUMPLE'; }
        else                                      { $vacante['RESULTADO_ANS'] = $hoy <= $vacante['FECHA_ANS'] ? 'EN_TIEMPO' : 'VENCIDA'; }
        //La fecha comprometida por Gestión Humana es la del acuerdo de servicio mientras el administrador
        //no la cambie (las vacantes registradas antes de que fuera automática la tienen vacía)
        if (empty($vacante['FECHA_COMPROMISO_GH'])) { $vacante['FECHA_COMPROMISO_GH'] = $vacante['FECHA_ANS']; }
        return $vacante;
    }

    //Una vacante con el nombre de su peaje
    public function mostrar($id)
    {
        return HanaDB::fila("SELECT v.*, c.NOM_CENTRO_OP,
                                    (SELECT h.TEXTO FROM historial h WHERE h.MODULO = 'VACANTE' AND h.ID_REGISTRO = v.ID_VACANTE AND h.TIPO = 'SALE_CARGO'
                         ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS NOMBRE_SALE, " . self::SQL_RESPUESTA_GH . "
                               FROM vacante v
                              INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = v.ID_CENTRO_OP
                              WHERE v.ID_VACANTE = ?", 'i', array((int)$id));
    }

    //Crea o edita una vacante
    public function guardar($id, $datos, $idColaborador, $ahora)
    {
        if ((int)$id > 0) {
            return HanaDB::q("UPDATE vacante SET ID_CENTRO_OP = ?, NUMERO_RQ_GH = ?, CARGO = ?, FECHA_VACANTE = ?, FECHA_ANS = ?,
                                     FECHA_CIERRE = ?, MOTIVO = ?, ESTADO_VACANTE = ?, NOMBRE_REEMPLAZO = ?, ESTADO_GH = ?,
                                     FECHA_COMPROMISO_GH = ?, OBSERVACION = ?, FEC_MODIFICACION = ?
                               WHERE ID_VACANTE = ?", 'issssssssssssi',
                             array($datos['centro'], $datos['rq'], $datos['cargo'], $datos['fecha'], $datos['ans'], $datos['cierre'], $datos['motivo'],
                                   $datos['estado'], $datos['nombre'], $datos['gh'], $datos['compromiso'], $datos['obs'], $ahora, (int)$id)) ? (int)$id : 0;
        }
        $ok = HanaDB::q("INSERT INTO vacante (ID_CENTRO_OP, NUMERO_RQ_GH, CARGO, FECHA_VACANTE, FECHA_ANS, FECHA_CIERRE, MOTIVO,
                                              ESTADO_VACANTE, NOMBRE_REEMPLAZO, ESTADO_GH, FECHA_COMPROMISO_GH, OBSERVACION,
                                              ID_COLABORADOR_REGISTRA, FEC_REGISTRO)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", 'isssssssssssis',
                        array($datos['centro'], $datos['rq'], $datos['cargo'], $datos['fecha'], $datos['ans'], $datos['cierre'], $datos['motivo'],
                              $datos['estado'], $datos['nombre'], $datos['gh'], $datos['compromiso'], $datos['obs'], (int)$idColaborador, $ahora));
        return $ok ? HanaDB::id() : 0;
    }

    //-----------------------------------------------------------------------
    // Quién deja el puesto (quien renunció, se trasladó, salió...)
    // La tabla vacante no tiene esa columna y la base no se toca: se guarda en
    // historial, como un evento de la vacante:
    //   MODULO = 'VACANTE', ID_REGISTRO = ID_VACANTE, TIPO = 'SALE_CARGO',
    //   TEXTO = el nombre, ID_COLABORADOR = quién lo anotó, FECHA = cuándo.
    // Vale la fila más reciente (se lee como NOMBRE_SALE en listar() y mostrar()).
    // Si se corrige el nombre, se agrega una fila nueva: no se modifica ni se
    // borra nada (queda la trazabilidad). Para quitarlo se anota un texto vacío.
    // Si algún día se crea la columna en vacante, basta copiar la última fila
    // de cada vacante (la consulta está en LEEME_CAMBIOS.md).
    //-----------------------------------------------------------------------
    public function guardarSale($idVacante, $nombre, $idColaborador, $ahora)
    {
        return $this->guardarEvento($idVacante, 'SALE_CARGO', $nombre, $idColaborador, $ahora);
    }

    //-----------------------------------------------------------------------
    // La respuesta de Gestión Humana: qué dijo GH de la vacante, en qué va el proceso
    // («candidato en exámenes médicos, ingresa el 24»). Igual que quién deja el puesto,
    // vive en historial (TIPO = 'RESPUESTA_GH') y vale la más reciente; cada cambio
    // agrega una fila, así queda el historial de lo que fue respondiendo GH.
    // Se lee como RESPUESTA_GH, FECHA_RESPUESTA_GH y QUIEN_RESPUESTA_GH.
    //-----------------------------------------------------------------------
    const LARGO_RESPUESTA_GH = 350;
    const SQL_RESPUESTA_GH = "(SELECT h.TEXTO FROM historial h WHERE h.MODULO = 'VACANTE' AND h.ID_REGISTRO = v.ID_VACANTE AND h.TIPO = 'RESPUESTA_GH'
                                ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS RESPUESTA_GH,
                              (SELECT h.FECHA FROM historial h WHERE h.MODULO = 'VACANTE' AND h.ID_REGISTRO = v.ID_VACANTE AND h.TIPO = 'RESPUESTA_GH'
                                ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS FECHA_RESPUESTA_GH,
                              (SELECT col.NOM_COLABORADOR FROM historial h LEFT JOIN colaboradores col ON col.ID_COLABORADOR = h.ID_COLABORADOR
                                WHERE h.MODULO = 'VACANTE' AND h.ID_REGISTRO = v.ID_VACANTE AND h.TIPO = 'RESPUESTA_GH'
                                ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS QUIEN_RESPUESTA_GH";

    public function guardarRespuestaGh($idVacante, $texto, $idColaborador, $ahora)
    {
        return $this->guardarEvento($idVacante, 'RESPUESTA_GH', $texto, $idColaborador, $ahora);
    }

    //Las respuestas de Gestión Humana de una vacante, de la más reciente a la más vieja
    public function respuestasGh($idVacante)
    {
        $filas = HanaDB::q("SELECT h.TEXTO, h.FECHA, col.NOM_COLABORADOR AS QUIEN
                              FROM historial h LEFT JOIN colaboradores col ON col.ID_COLABORADOR = h.ID_COLABORADOR
                             WHERE h.MODULO = 'VACANTE' AND h.ID_REGISTRO = ? AND h.TIPO = 'RESPUESTA_GH' AND h.TEXTO <> ''
                             ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 30", 'i', array((int)$idVacante));
        return is_array($filas) ? $filas : array();
    }

    //-----------------------------------------------------------------------
    // Cambios de la fecha comprometida por Gestión Humana. La tabla vacante solo guarda la
    // vigente: cada cambio queda en historial (MODULO = 'VACANTE', TIPO = 'COMPROMISO_GH',
    // TEXTO = 'anterior|nueva|motivo', fechas AAAA-MM-DD), así se sabe cuántas veces se corrió
    //-----------------------------------------------------------------------
    public function guardarCambioCompromiso($idVacante, $anterior, $nueva, $motivo, $idColaborador, $ahora)
    {
        return HanaDB::q("INSERT INTO historial (MODULO, ID_REGISTRO, TIPO, TEXTO, ID_COLABORADOR, FECHA) VALUES ('VACANTE', ?, 'COMPROMISO_GH', ?, NULLIF(?, 0), ?)",
                         'isis', array((int)$idVacante, $anterior . '|' . $nueva . '|' . $motivo, (int)$idColaborador, $ahora)) === true;
    }

    //Los cambios de varias vacantes hasta $hasta (sin incluirlo; null = hasta hoy):
    //[idVacante => {veces, anteriores (fechas, de la más vieja a la más nueva), motivo (el último)}]
    public static function cambiosCompromiso($ids, $hasta = null)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!count($ids)) { return array(); }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT ID_REGISTRO, TEXTO FROM historial WHERE MODULO = 'VACANTE' AND TIPO = 'COMPROMISO_GH' AND ID_REGISTRO IN ($marcas)";
        $tipos = str_repeat('i', count($ids)); $params = $ids;
        if ($hasta !== null) { $sql .= " AND FECHA < ?"; $tipos .= 's'; $params[] = $hasta; }
        $filas = HanaDB::q($sql . " ORDER BY FECHA, ID_HISTORIAL", $tipos, $params);
        $lista = array();
        foreach (is_array($filas) ? $filas : array() as $f) {
            $partes = explode('|', (string)$f['TEXTO'], 3);
            $id = (int)$f['ID_REGISTRO'];
            if (!isset($lista[$id])) { $lista[$id] = array('veces' => 0, 'anteriores' => array(), 'motivo' => ''); }
            $lista[$id]['veces']++;
            if ($partes[0] !== '') { $lista[$id]['anteriores'][] = $partes[0]; }
            $lista[$id]['motivo'] = isset($partes[2]) ? $partes[2] : '';
        }
        return $lista;
    }

    //Anota un dato de la vacante en historial, solo si cambió (un texto vacío lo quita)
    private function guardarEvento($idVacante, $tipo, $texto, $idColaborador, $ahora)
    {
        $texto = $texto === null ? '' : (string)$texto;
        $actual = HanaDB::fila("SELECT TEXTO FROM historial WHERE MODULO = 'VACANTE' AND ID_REGISTRO = ? AND TIPO = ?
                                 ORDER BY FECHA DESC, ID_HISTORIAL DESC LIMIT 1", 'is', array((int)$idVacante, $tipo));
        if (($actual ? (string)$actual['TEXTO'] : '') === $texto) { return true; } //no cambió
        return HanaDB::q("INSERT INTO historial (MODULO, ID_REGISTRO, TIPO, TEXTO, ID_COLABORADOR, FECHA) VALUES ('VACANTE', ?, ?, ?, NULLIF(?, 0), ?)",
                         'issis', array((int)$idVacante, $tipo, $texto, (int)$idColaborador, $ahora)) === true;
    }

    //Motivos ya usados, para sugerirlos al escribir
    public function motivos($idsCentros)
    {
        if (!count($idsCentros)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $filas = HanaDB::q("SELECT MOTIVO, COUNT(*) n FROM vacante WHERE ID_CENTRO_OP IN ($marcas) GROUP BY MOTIVO ORDER BY n DESC LIMIT 30",
                       str_repeat('i', count($idsCentros)), array_map('intval', $idsCentros));
        return array_map(function ($fila) { return $fila['MOTIVO']; }, $filas ? $filas : array());
    }

    //Cargos para sugerir al escribir el cargo, los más usados primero, para que el mismo cargo no
    //quede escrito de muchas formas. Las formas de un mismo cargo (mayúsculas, tildes, espacios,
    //abreviaturas como «Recol.») salen una sola vez, como las une el reporte a la gerencia
    public function cargos($idsCentros)
    {
        if (!count($idsCentros)) { return array(); }
        require_once __DIR__ . '/ReporteGerenciaModelo.php';
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        //BINARY: cada forma escrita por aparte («Técnico» y «Tecnico» no se juntan aquí)
        $filas = HanaDB::q("SELECT CARGO, COUNT(*) n FROM vacante WHERE ID_CENTRO_OP IN ($marcas) AND CARGO <> '' GROUP BY BINARY CARGO ORDER BY n DESC LIMIT 300",
                       str_repeat('i', count($idsCentros)), array_map('intval', $idsCentros));
        $escritos = array(); $usos = array();
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $cargo = html_entity_decode($fila['CARGO'], ENT_QUOTES, 'UTF-8');
            for ($i = 0; $i < min(50, (int)$fila['n']); $i++) { $escritos[] = $cargo; }
            $usos[$cargo] = (isset($usos[$cargo]) ? $usos[$cargo] : 0) + (int)$fila['n'];
        }
        $total = array();
        foreach (ReporteGerencia::unificarNombres($escritos) as $escrito => $unificado) {
            $total[$unificado] = (isset($total[$unificado]) ? $total[$unificado] : 0) + $usos[$escrito];
        }
        arsort($total);
        return array_slice(array_keys($total), 0, 60);
    }

    //El cargo para compararlo: en minúscula, sin tildes, sin puntos ni signos y con un solo espacio
    public static function llaveCargo($cargo)
    {
        $llave = strtr(mb_strtolower(trim((string)$cargo), 'UTF-8'), array('á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $llave));
    }

    //Nombres para sugerir en «Quién la cubre»: las personas activas y los nombres que ya se han
    //escrito en las vacantes de esos centros (el campo sigue siendo de texto libre: puede ser
    //alguien que no está registrado en HANA, por ejemplo un recaudador)
    public function nombresCubren($idsCentros)
    {
        $nombres = array();
        $filas = HanaDB::q("SELECT NOM_COLABORADOR AS NOMBRE FROM colaboradores WHERE ESTADO = 1 ORDER BY NOM_COLABORADOR");
        foreach (is_array($filas) ? $filas : array() as $fila) { $nombres[] = html_entity_decode($fila['NOMBRE'], ENT_QUOTES, 'UTF-8'); }
        if (count($idsCentros)) {
            $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
            $filas = HanaDB::q("SELECT DISTINCT NOMBRE_REEMPLAZO AS NOMBRE FROM vacante WHERE ID_CENTRO_OP IN ($marcas) AND NOMBRE_REEMPLAZO IS NOT NULL AND NOMBRE_REEMPLAZO <> ''",
                               str_repeat('i', count($idsCentros)), array_map('intval', $idsCentros));
            foreach (is_array($filas) ? $filas : array() as $fila) { $nombres[] = html_entity_decode($fila['NOMBRE'], ENT_QUOTES, 'UTF-8'); }
        }
        $nombres = array_values(array_unique($nombres));
        sort($nombres);
        return $nombres;
    }
}

class Comunicacion
{
    //Cumple si se atiende en este plazo (Parámetros del sistema: COMUNICACION_DIAS_RESPUESTA)
    public static function diasRespuesta() { return max(0, HanaConfig::num('COMUNICACION_DIAS_RESPUESTA', 2)); }

    //Tipos de comunicación (lo que se guarda => cómo se muestra)
    public static $TIPOS = array('POR_ATENDER' => 'Por atender', 'POR_ENVIAR' => 'Por enviar');
    //Medios y «Pendiente de»: los edita el administrador en Parámetros del sistema → Listas.
    //Medios por los que llega un oficio. WhatsApp no es uno de ellos: los oficios que llegaron
    //por ese medio quedaron como "Otro"
    public static $MEDIOS = array(); //se llena al final del archivo (HanaConfig::LISTAS)
    //A quién le corresponde el siguiente paso
    public static $PENDIENTE = array(); //se llena al final del archivo (HanaConfig::LISTAS)

    //Comunicaciones de los proyectos indicados: las del rango de fechas o las que siguen abiertas
    public function listar($idsProyectos, $desde, $hasta, $soloAbiertas, $hoy)
    {
        if (!count($idsProyectos)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsProyectos), '?'));
        $sql = "SELECT m.*, p.NOM_PROYECTO, c.NOM_CENTRO_OP,
                       (SELECT COUNT(*) FROM adjunto a WHERE a.MODULO = 'COMUNICACION' AND a.ID_REGISTRO = m.ID_COMUNICACION AND a.ESTADO = 1) AS ADJUNTOS,
                       (SELECT COUNT(*) FROM historial h WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = m.ID_COMUNICACION AND h.TIPO = 'RESPUESTA') AS RESPUESTAS,
                       -- La última respuesta o gestión: se lee de la conversación (la tabla comunicacion ya no la repite)
                       (SELECT h.TEXTO FROM historial h
                         WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = m.ID_COMUNICACION AND h.TIPO IN ('RESPUESTA', 'RESUELTA')
                           AND h.TEXTO IS NOT NULL AND h.TEXTO <> ''
                         ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS RESPUESTA,
                       p.ID_COLABORADOR_COORDINADOR AS COORDINADOR_PROYECTO,
                       reg.NOM_COLABORADOR AS REGISTRO -- quien la registró, que es quien le hace el seguimiento
                  FROM comunicacion m
                  INNER JOIN proyectos p ON p.ID_PROYECTO = m.ID_PROYECTO
                  LEFT JOIN centros_operacion c ON c.ID_CENTRO_OP = m.ID_CENTRO_OP
                  LEFT JOIN colaboradores reg ON reg.ID_COLABORADOR = m.ID_COLABORADOR_REGISTRA
                 WHERE m.ID_PROYECTO IN ($marcas) AND m.ESTADO = 1 AND m.TIPO <> 'TEMA_CLIENTE'";
        $tipos = str_repeat('i', count($idsProyectos));
        $params = array_map('intval', $idsProyectos);
        if ($soloAbiertas) { $sql .= " AND m.FECHA_ATENCION IS NULL"; }
        else { $sql .= " AND m.FECHA_RECEPCION >= ? AND m.FECHA_RECEPCION < ?"; $tipos .= 'ss'; $params[] = $desde; $params[] = $hasta; }
        $sql .= " ORDER BY (m.FECHA_ATENCION IS NULL) DESC, m.FECHA_RECEPCION, m.ID_COMUNICACION";
        $filas = HanaDB::q($sql, $tipos, $params);
        return array_map(function ($comunicacion) use ($hoy) { return Comunicacion::calcular($comunicacion, $hoy); }, $filas ? $filas : array());
    }

    //Lo que en el Excel eran fórmulas
    //  ABIERTA         sin fecha de atención
    //  TIEMPO_CIERRE   días entre recepción y atención
    //  RESULTADO       CUMPLE / NO_CUMPLE (cerradas); EN_TIEMPO / VENCIDA (abiertas)
    public static function calcular($comunicacion, $hoy)
    {
        $dias = function ($desde, $hasta) { return (int)round((strtotime($hasta) - strtotime($desde)) / 86400); };
        $comunicacion['ABIERTA'] = $comunicacion['FECHA_ATENCION'] ? 0 : 1;
        $comunicacion['TIEMPO_CIERRE'] = $comunicacion['FECHA_ATENCION'] ? $dias($comunicacion['FECHA_RECEPCION'], $comunicacion['FECHA_ATENCION']) : null;
        $lleva = $comunicacion['FECHA_ATENCION'] ? $comunicacion['TIEMPO_CIERRE'] : $dias($comunicacion['FECHA_RECEPCION'], $hoy);
        $comunicacion['DIAS_ABIERTA'] = $comunicacion['FECHA_ATENCION'] ? null : $lleva;
        $plazo = self::diasRespuesta();
        if ($comunicacion['FECHA_ATENCION']) { $comunicacion['RESULTADO'] = $lleva <= $plazo ? 'CUMPLE' : 'NO_CUMPLE'; }
        else                      { $comunicacion['RESULTADO'] = $lleva <= $plazo ? 'EN_TIEMPO' : 'VENCIDA'; }
        return $comunicacion;
    }

    //Una comunicación con la última respuesta que se le dio
    public function mostrar($id)
    {
        //RESPUESTA es lo último que se respondió en la conversación (no es una columna de la tabla comunicacion)
        return HanaDB::fila("SELECT m.*, (SELECT h.TEXTO FROM historial h
                         WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = m.ID_COMUNICACION AND h.TIPO IN ('RESPUESTA', 'RESUELTA')
                           AND h.TEXTO IS NOT NULL AND h.TEXTO <> ''
                         ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC LIMIT 1) AS RESPUESTA
                               FROM comunicacion m WHERE m.ID_COMUNICACION = ? AND m.TIPO <> 'TEMA_CLIENTE'", 'i', array((int)$id));
    }

    //Crea o edita una comunicación
    public function guardar($id, $datos, $idColaborador, $ahora)
    {
        if ((int)$id > 0) {
            return HanaDB::q("UPDATE comunicacion SET ID_PROYECTO = ?, ID_CENTRO_OP = ?, TIPO = ?, RADICADO = ?, FECHA_RECEPCION = ?,
                                     MEDIO = ?, REMITENTE = ?, ASUNTO = ?, PENDIENTE_DE = ?, FECHA_ATENCION = ?,
                                     FEC_MODIFICACION = ?
                               WHERE ID_COMUNICACION = ?", 'iisssssssssi',
                             array($datos['proyecto'], $datos['centro'], $datos['tipo'], $datos['radicado'], $datos['fecha'], $datos['medio'], $datos['remitente'],
                                   $datos['asunto'], $datos['pendiente'], $datos['atencion'], $ahora, (int)$id)) ? (int)$id : 0;
        }
        $ok = HanaDB::q("INSERT INTO comunicacion (ID_PROYECTO, ID_CENTRO_OP, TIPO, RADICADO, FECHA_RECEPCION, MEDIO, REMITENTE, ASUNTO,
                                                   PENDIENTE_DE, FECHA_ATENCION, ESTADO, ID_COLABORADOR_REGISTRA, FEC_REGISTRO)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)", 'iissssssssis',
                        array($datos['proyecto'], $datos['centro'], $datos['tipo'], $datos['radicado'], $datos['fecha'], $datos['medio'], $datos['remitente'],
                              $datos['asunto'], $datos['pendiente'], $datos['atencion'], (int)$idColaborador, $ahora));
        return $ok ? HanaDB::id() : 0;
    }

    //-----------------------------------------------------------------------
    // La conversación del oficio: respuestas y cuándo se resolvió o reabrió.
    // Vive en historial (MODULO = 'COMUNICACION'); TIPO es RESPUESTA,
    // RESUELTA o REABIERTA
    //-----------------------------------------------------------------------
    public function hilo($id)
    {
        $filas = HanaDB::q("SELECT h.ID_HISTORIAL, h.TIPO, h.TEXTO, h.FECHA, col.NOM_COLABORADOR AS QUIEN, h.ID_COLABORADOR
                          FROM historial h LEFT JOIN colaboradores col ON col.ID_COLABORADOR = h.ID_COLABORADOR
                         WHERE h.MODULO = 'COMUNICACION' AND h.ID_REGISTRO = ?
                         ORDER BY h.FECHA, h.ID_HISTORIAL", 'i', array((int)$id));
        return $filas ? $filas : array();
    }

    //Agrega una entrada a la conversación de una comunicación (respuesta, nota, etc.)
    public function agregarHilo($id, $idColaborador, $tipo, $texto, $ahora)
    {
        return HanaDB::q("INSERT INTO historial (MODULO, ID_REGISTRO, TIPO, TEXTO, ID_COLABORADOR, FECHA)
                          VALUES ('COMUNICACION', ?, ?, ?, NULLIF(?, 0), ?)",
                         'issis', array((int)$id, $tipo, $texto, (int)$idColaborador, $ahora));
    }

    //Resuelta: queda atendida hoy y ya no está pendiente de nadie.
    //La nota de cierre NO se guarda aquí: va a la conversación (agregarHilo con TIPO RESUELTA), una sola vez
    public function resolver($id, $nota, $hoy, $ahora)
    {
        return HanaDB::q("UPDATE comunicacion SET FECHA_ATENCION = ?, PENDIENTE_DE = NULL, FEC_MODIFICACION = ? WHERE ID_COMUNICACION = ?",
                         'ssi', array($hoy, $ahora, (int)$id));
    }

    //Quita la fecha de atención para que la comunicación vuelva a quedar abierta
    public function reabrir($id, $ahora)
    {
        return HanaDB::q("UPDATE comunicacion SET FECHA_ATENCION = NULL, FEC_MODIFICACION = ? WHERE ID_COMUNICACION = ?", 'si', array($ahora, (int)$id));
    }

    //Anula una comunicación dejando el motivo
    public function anular($id, $motivo)
    {
        return HanaDB::q("UPDATE comunicacion SET ESTADO = 0, MOTIVO_ANULACION = ? WHERE ID_COMUNICACION = ? AND ESTADO = 1",
                         'si', array($motivo, (int)$id));
    }
}

//Las listas que el administrador edita en Parámetros del sistema → Listas (las de fábrica están en HanaConfig::LISTAS)
Vacante::$ESTADOS_GH     = HanaConfig::opciones('LISTA_ESTADOS_GH');
Comunicacion::$MEDIOS    = HanaConfig::opciones('LISTA_MEDIOS');
Comunicacion::$PENDIENTE = HanaConfig::opciones('LISTA_PENDIENTE');
