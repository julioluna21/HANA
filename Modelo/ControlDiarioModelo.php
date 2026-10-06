<?php
/*
  HANA — Reporte general del día (control del reporte diario)
  ---------------------------------------------------------------------------
  Todo lo que enviaron los coordinadores y jefes de peaje en un día,
  organizado por módulo. El alcance es el mismo del tablero:
    - con 20M (jefe mayor): todo
    - el coordinador: su proyecto (él y los jefes de sus centros)
    - el jefe de peaje: lo suyo
*/
require_once __DIR__ . "/TableroModelo.php";
require_once __DIR__ . "/VacanteModelo.php";
require_once __DIR__ . "/RQEstados.php";
require_once __DIR__ . "/ArqueoModelo.php";

class ControlDiario
{
    private $T;
    public function __construct() { $this->T = new Tablero(); }

    private function marcas($ids) { return implode(',', array_fill(0, count($ids), '?')); }

    //-----------------------------------------------------------------------
    // Quiénes deben llenar el reporte del coordinador (Hoy en qué estás,
    // arqueos, cronograma...): SOLO los coordinadores. Los jefes de peaje
    // hacen RQ y listas de chequeo; esas se controlan por centro.
    //   $puedeVer: función ($idPersona, $idProyecto) -> bool
    //   Devuelve [idColaborador => [NOMBRE, ROLES[], PROYECTOS[]]]
    //-----------------------------------------------------------------------
    public function personas($proyectos, $centros, $puedeVer)
    {
        $res = array();
        $agregar = function ($id, $nombre, $rol, $proyecto) use (&$res) {
            $id = (int)$id;
            if (!isset($res[$id])) { $res[$id] = array('ID' => $id, 'NOMBRE' => $nombre, 'ROLES' => array(), 'PROYECTOS' => array()); }
            if (!in_array($rol, $res[$id]['ROLES'], true)) { $res[$id]['ROLES'][] = $rol; }
            if (!in_array($proyecto, $res[$id]['PROYECTOS'], true)) { $res[$id]['PROYECTOS'][] = $proyecto; }
        };
        foreach ($proyectos as $p) {
            if ($p['ID_COLABORADOR_COORDINADOR'] && $puedeVer((int)$p['ID_COLABORADOR_COORDINADOR'], $p['ID_PROYECTO'])) {
                $agregar($p['ID_COLABORADOR_COORDINADOR'], $p['COORDINADOR'], 'Coordinador ' . $p['NOM_PROYECTO'], $p['NOM_PROYECTO']);
            }
        }
        uasort($res, function ($a, $b) { return strcmp($a['NOMBRE'], $b['NOMBRE']); });
        return $res;
    }

    //Hoy en qué estás de un grupo de personas en un día
    public function hoy($idsPersonas, $fecha)
    {
        if (!count($idsPersonas)) { return array(); }
        $m = $this->marcas($idsPersonas);
        $f = HanaDB::q("SELECT h.ID_COLABORADOR, h.SITUACION, h.HORA_INGRESO, h.HORA_SALIDA, h.LUGAR_OTRO, h.OBSERVACION,
                               h.FEC_REGISTRO, h.FEC_MODIFICACION,
                               (SELECT GROUP_CONCAT(c.NOM_CENTRO_OP ORDER BY c.NOM_CENTRO_OP SEPARATOR ' - ')
                                  FROM reporte_hoy_centro hc INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = hc.ID_CENTRO_OP
                                 WHERE hc.ID_REPORTE_HOY = h.ID_REPORTE_HOY) AS LUGARES,
                               h.ACTIVIDAD
                          FROM reporte_hoy h
                         WHERE h.FECHA = ? AND h.ID_COLABORADOR IN ($m)",
                       's' . str_repeat('i', count($idsPersonas)), array_merge(array($fecha), array_map('intval', $idsPersonas)));
        $res = array();
        foreach ((array)$f as $x) { $res[(int)$x['ID_COLABORADOR']] = $x; }
        return $res;
    }

    //Listas de chequeo diligenciadas ese día en los centros visibles
    public function listas($idsCentros, $fecha)
    {
        if (!count($idsCentros)) { return array(); }
        $sig = date('Y-m-d', strtotime("$fecha +1 day"));
        $m = $this->marcas($idsCentros);
        $f = HanaDB::q("SELECT l.ID_LISTA_CHEQUEO, l.ID_COLABORADOR_LISTA_CHEQUEO AS ID_PERSONA,
                               l.FEC_REGISTRO_LISTA_CHEQUEO AS FECHA, g.NOM_GRUPO_LISTA_CHEQUEO AS LISTA,
                               g.CANT_PREGUNTAS_GRUPO AS PREGUNTAS, l.CANT_RESPUESTAS_LISTA AS RESPUESTAS,
                               c.NOM_CENTRO_OP, p.NOM_PROYECTO, col.NOM_COLABORADOR AS PERSONA
                          FROM lista_chequeo l
                          INNER JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = l.ID_GRUPO_LISTA_CHEQUEO
                          INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = l.ID_CENTRO_OP_LISTA_CHEQUEO
                          INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                          LEFT JOIN colaboradores col ON col.ID_COLABORADOR = l.ID_COLABORADOR_LISTA_CHEQUEO
                         WHERE l.FEC_REGISTRO_LISTA_CHEQUEO >= ? AND l.FEC_REGISTRO_LISTA_CHEQUEO < ?
                           AND l.ID_CENTRO_OP_LISTA_CHEQUEO IN ($m)
                         ORDER BY l.FEC_REGISTRO_LISTA_CHEQUEO",
                       'ss' . str_repeat('i', count($idsCentros)), array_merge(array($fecha, $sig), array_map('intval', $idsCentros)));
        return $f ? $f : array();
    }

    //Una lista de chequeo con todas sus respuestas, pregunta por pregunta.
    //Incluye las preguntas que quedaron sin responder (listas incompletas).
    //Los textos se guardaron con limpiarCadena (codificados para HTML): se
    //decodifican aquí para que la pantalla los escape una sola vez
    public function listaRespuestas($idLista)
    {
        $l = HanaDB::fila("SELECT l.ID_LISTA_CHEQUEO, l.ID_CENTRO_OP_LISTA_CHEQUEO AS ID_CENTRO, l.FEC_REGISTRO_LISTA_CHEQUEO AS FECHA,
                                  l.ID_GRUPO_LISTA_CHEQUEO AS ID_GRUPO, l.CANT_RESPUESTAS_LISTA AS RESPUESTAS,
                                  g.NOM_GRUPO_LISTA_CHEQUEO AS LISTA, c.NOM_CENTRO_OP, p.NOM_PROYECTO, col.NOM_COLABORADOR AS PERSONA
                             FROM lista_chequeo l
                             INNER JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = l.ID_GRUPO_LISTA_CHEQUEO
                             INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = l.ID_CENTRO_OP_LISTA_CHEQUEO
                             INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                             LEFT JOIN colaboradores col ON col.ID_COLABORADOR = l.ID_COLABORADOR_LISTA_CHEQUEO
                            WHERE l.ID_LISTA_CHEQUEO = ?", 'i', array((int)$idLista));
        if (!$l) { return null; }
        $f = HanaDB::q("SELECT q.PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO AS PREGUNTA, q.TIPO_RESPUESTA AS TIPO,
                               d.RESPUTA_DETALLE_LISTA_CHEQUEO AS RESPUESTA
                          FROM detalle_grupo_lista_cheque q
                          LEFT JOIN detale_lista_chequeo d
                                 ON d.ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO = q.ID_DETALLE_GRUPO_LISTA_CHEQUEO
                                AND d.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO = ?
                         WHERE q.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO = ?
                           AND (q.ESTADO = 1 OR d.ID_DETALE_LISTA_CHEQUEO IS NOT NULL)
                         ORDER BY q.ORDEN, q.ID_DETALLE_GRUPO_LISTA_CHEQUEO", 'ii', array((int)$idLista, (int)$l['ID_GRUPO']));
        $dec = function ($t) { return $t === null ? null : html_entity_decode((string)$t, ENT_QUOTES, 'UTF-8'); };
        $l['PREGUNTAS'] = array();
        foreach ((array)$f as $x) {
            $l['PREGUNTAS'][] = array('PREGUNTA' => $dec($x['PREGUNTA']), 'TIPO' => $x['TIPO'], 'RESPUESTA' => $dec($x['RESPUESTA']));
        }
        $l['LISTA'] = $dec($l['LISTA']);
        return $l;
    }

    //Arqueos del día en los centros visibles
    public function arqueos($idsCentros, $fecha)
    {
        if (!count($idsCentros)) { return array(); }
        $m = $this->marcas($idsCentros);
        $f = HanaDB::q("SELECT a.ID_ARQUEO, a.TIPO, a.HORA, a.RESPONSABLE, " . Arqueo::sqlTotal() . " AS TOTAL_ARQUEO,
                               a.FONDO_AUTORIZADO, " . Arqueo::sqlDiferencia() . " AS DIFERENCIA,
                               a.OBSERVACION, c.NOM_CENTRO_OP, p.NOM_PROYECTO, p.ID_PROYECTO, col.NOM_COLABORADOR AS ARQUEA,
                               a.ID_COLABORADOR_ARQUEA AS ID_PERSONA
                          FROM arqueo a
                          INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = a.ID_CENTRO_OP
                          INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                          LEFT JOIN colaboradores col ON col.ID_COLABORADOR = a.ID_COLABORADOR_ARQUEA
                         WHERE a.FECHA = ? AND a.ESTADO = 1 AND a.ID_CENTRO_OP IN ($m)
                         ORDER BY a.HORA",
                       's' . str_repeat('i', count($idsCentros)), array_merge(array($fecha), array_map('intval', $idsCentros)));
        return $f ? $f : array();
    }

    //Cronograma del día de cada persona, con su estado real
    public function cronograma($personas, $fecha, $hoy)
    {
        $C = new Cronograma();
        $sig = date('Y-m-d', strtotime("$fecha +1 day"));
        $res = array();
        foreach ($personas as $id => $p) {
            foreach ($C->items($id, $fecha, $sig, $hoy) as $it) {
                $it['PERSONA'] = $p['NOMBRE'];
                $res[] = $it;
            }
        }
        return $res;
    }

    //Vehículos de las personas visibles (o de los proyectos visibles) y su estado ese día
    public function vehiculos($idsPersonas, $idsProyectos, $fecha)
    {
        $cond = array(); $tipos = 's'; $params = array($fecha);
        if (count($idsPersonas))  { $cond[] = 'v.ID_COLABORADOR_RESPONSABLE IN (' . $this->marcas($idsPersonas) . ')'; $tipos .= str_repeat('i', count($idsPersonas)); $params = array_merge($params, array_map('intval', $idsPersonas)); }
        if (count($idsProyectos)) { $cond[] = 'v.ID_PROYECTO IN (' . $this->marcas($idsProyectos) . ')'; $tipos .= str_repeat('i', count($idsProyectos)); $params = array_merge($params, array_map('intval', $idsProyectos)); }
        if (!count($cond)) { return array(); }
        $f = HanaDB::q("SELECT v.ID_VEHICULO, v.PLACA, v.DESCRIPCION, col.NOM_COLABORADOR AS RESPONSABLE,
                               d.ESTADO_VH, d.TRANSPORTE, d.OBSERVACION, d.FEC_REGISTRO
                          FROM vehiculo v
                          LEFT JOIN colaboradores col ON col.ID_COLABORADOR = v.ID_COLABORADOR_RESPONSABLE
                          LEFT JOIN vehiculo_dia d ON d.ID_VEHICULO = v.ID_VEHICULO AND d.FECHA = ?
                         WHERE v.ESTADO = 1 AND (" . implode(' OR ', $cond) . ")
                         ORDER BY v.PLACA", $tipos, $params);
        return $f ? $f : array();
    }

    //RQ que esperan aprobación en los centros visibles
    //Ausencias registradas ese día, por proyecto: [idProyecto => personas ausentes]
    //(solo las novedades que cuentan como ausencia, como el total del reporte)
    public function ausentismoDia($idsCentros, $fecha)
    {
        if (!count($idsCentros)) { return array(); }
        try {
            $f = HanaDB::q("SELECT c.ID_PROYECTO_CENTRO_OP AS P, SUM(r.CANTIDAD) AS T
                              FROM aus_registro r
                              INNER JOIN aus_novedad n ON n.ID_NOVEDAD_AUS = r.ID_NOVEDAD_AUS AND n.CUENTA_AUSENCIA = 1
                              INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                             WHERE r.FECHA = ? AND r.ID_CENTRO_OP IN (" . $this->marcas($idsCentros) . ")
                             GROUP BY c.ID_PROYECTO_CENTRO_OP", 's' . str_repeat('i', count($idsCentros)), array_merge(array($fecha), $idsCentros));
        } catch (\Throwable $e) { return array(); } //si el ausentismo no está instalado
        $res = array();
        foreach ((array)$f as $x) { $res[(int)$x['P']] = (int)$x['T']; }
        return $res;
    }

    public function rqPendientes($idsCentros)
    {
        if (!count($idsCentros)) { return array(); }
        $m = $this->marcas($idsCentros);
        $f = HanaDB::q("SELECT r.ID_RQ, r.NUMERO_RQ, r.TIPO_RQ, r.FECHA_RQ, c.NOM_CENTRO_OP, p.NOM_PROYECTO, p.ID_PROYECTO,
                               col.NOM_COLABORADOR AS SOLICITA, DATEDIFF(NOW(), r.FEC_ESTADO) AS DIAS,
                               (SELECT GROUP_CONCAT(d.DESCRIPCION SEPARATOR ', ') FROM rq_detalle d WHERE d.ID_RQ = r.ID_RQ) AS ITEMS,
                               -- Los archivos de la RQ (fotos y soportes), separados por |, para mostrarlos donde se aprueba
                               (SELECT GROUP_CONCAT(a.ARCHIVO ORDER BY a.ID_ADJUNTO SEPARATOR '|') FROM adjunto a
                                 WHERE a.MODULO = 'RQ' AND a.ESTADO = 1 AND a.ID_REGISTRO = r.ID_RQ) AS ARCHIVOS
                          FROM rq r
                          INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                          INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                          LEFT JOIN colaboradores col ON col.ID_COLABORADOR = r.ID_COLABORADOR_SOLICITA
                         WHERE r.ID_RQ_ESTADO = " . RqEstado::SOLICITADA . " AND r.ID_CENTRO_OP IN ($m)
                         ORDER BY (r.TIPO_RQ = 'U') DESC, r.FEC_ESTADO",
                       str_repeat('i', count($idsCentros)), array_map('intval', $idsCentros));
        return $f ? $f : array();
    }
}
