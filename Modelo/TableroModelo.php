<?php
/*
  HANA — Tablero del reporte diario (Fase 2)
  ---------------------------------------------------------------------------
  Tres niveles: proyectos -> centros de cada proyecto -> el reporte de una
  persona en un día. Qué ve cada quien sale de la jerarquía:
    - Con el permiso 20M (jefe mayor): todos los proyectos.
    - El coordinador de un proyecto: ese proyecto completo.
    - El jefe de un centro: ese centro, dentro de su proyecto.
*/
require_once __DIR__ . "/HanaDB.php";
require_once __DIR__ . "/HoyModelo.php";
require_once __DIR__ . "/CronogramaModelo.php";

class Tablero
{
    //-----------------------------------------------------------------------
    // Alcance: qué proyectos y centros puede ver esta persona
    //   devuelve ['proyectos' => [id => true], 'centros' => [id => true]]
    //-----------------------------------------------------------------------
    public function alcance($idColaborador, $verTodos)
    {
        $a = array('proyectos' => array(), 'centros' => array());
        $cent = HanaDB::q("SELECT c.ID_CENTRO_OP, c.ID_PROYECTO_CENTRO_OP, c.ID_COLABORADOR_JEFE, p.ID_COLABORADOR_COORDINADOR
                             FROM centros_operacion c
                             INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                            WHERE c.Estado = '1' AND p.Estado = '1'");
        foreach ((array)$cent as $c) {
            $ve = $verTodos || (int)$c['ID_COLABORADOR_COORDINADOR'] === (int)$idColaborador
                            || (int)$c['ID_COLABORADOR_JEFE'] === (int)$idColaborador;
            if ($ve) {
                $a['centros'][(int)$c['ID_CENTRO_OP']] = true;
                $a['proyectos'][(int)$c['ID_PROYECTO_CENTRO_OP']] = true;
            }
        }
        //Un proyecto sin centros todavía, pero del que la persona es coordinadora
        $pr = $verTodos ? HanaDB::q("SELECT ID_PROYECTO FROM proyectos WHERE Estado = '1'")
                        : HanaDB::q("SELECT ID_PROYECTO FROM proyectos WHERE Estado = '1' AND ID_COLABORADOR_COORDINADOR = ?", 'i', array((int)$idColaborador));
        foreach ((array)$pr as $p) { $a['proyectos'][(int)$p['ID_PROYECTO']] = true; }
        return $a;
    }

    private function marcas($ids) { return implode(',', array_fill(0, count($ids), '?')); }

    //-----------------------------------------------------------------------
    // Los centros visibles con lo del día: el jefe, si reportó, arqueos,
    // listas, vacantes abiertas y RQ pendientes
    //-----------------------------------------------------------------------
    public function centros($idsCentros, $fecha)
    {
        if (!count($idsCentros)) { return array(); }
        $sig = date('Y-m-d', strtotime("$fecha +1 day"));
        $m = $this->marcas($idsCentros);
        $sql = "SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, c.ID_PROYECTO_CENTRO_OP AS ID_PROYECTO,
                       c.ID_COLABORADOR_JEFE, j.NOM_COLABORADOR AS JEFE,
                       h.SITUACION, h.HORA_INGRESO, h.HORA_SALIDA,
                       (SELECT COUNT(*) FROM vacante v WHERE v.ID_CENTRO_OP = c.ID_CENTRO_OP AND v.ESTADO_VACANTE = 'ABIERTA') AS VACANTES,
                       (SELECT COUNT(*) FROM arqueo a WHERE a.ID_CENTRO_OP = c.ID_CENTRO_OP AND a.FECHA = ? AND a.ESTADO = 1) AS ARQUEOS,
                       (SELECT COUNT(*) FROM arqueo a WHERE a.ID_CENTRO_OP = c.ID_CENTRO_OP AND a.FECHA = ? AND a.ESTADO = 1 AND a.DIFERENCIA <> 0) AS ARQUEOS_DIF,
                       (SELECT COUNT(*) FROM lista_chequeo l WHERE l.ID_CENTRO_OP_LISTA_CHEQUEO = c.ID_CENTRO_OP
                                AND l.FEC_REGISTRO_LISTA_CHEQUEO >= ? AND l.FEC_REGISTRO_LISTA_CHEQUEO < ?) AS LISTAS,
                       (SELECT COUNT(*) FROM rq r WHERE r.ID_CENTRO_OP = c.ID_CENTRO_OP AND r.ESTADO = 1 AND r.ID_RQ_ESTADO = 1) AS RQ_PENDIENTES
                  FROM centros_operacion c
                  LEFT JOIN colaboradores j ON j.ID_COLABORADOR = c.ID_COLABORADOR_JEFE
                  LEFT JOIN reporte_hoy h ON h.ID_COLABORADOR = c.ID_COLABORADOR_JEFE AND h.FECHA = ?
                 WHERE c.ID_CENTRO_OP IN ($m)
                 ORDER BY c.NOM_CENTRO_OP";
        $f = HanaDB::q($sql, 'sssss' . str_repeat('i', count($idsCentros)),
                       array_merge(array($fecha, $fecha, $fecha, $sig, $fecha), array_map('intval', $idsCentros)));
        return $f ? $f : array();
    }

    //Los proyectos visibles, con su coordinador y si reportó ese día
    public function proyectos($idsProyectos, $fecha)
    {
        if (!count($idsProyectos)) { return array(); }
        $m = $this->marcas($idsProyectos);
        $sig = date('Y-m-d', strtotime("$fecha +1 day"));
        $f = HanaDB::q("SELECT p.ID_PROYECTO, p.NOM_PROYECTO, p.ID_COLABORADOR_COORDINADOR, co.NOM_COLABORADOR AS COORDINADOR,
                               h.SITUACION AS COORD_SITUACION, h.HORA_INGRESO AS COORD_INGRESO,
                               (SELECT COUNT(*) FROM lista_chequeo l WHERE l.ID_COLABORADOR_LISTA_CHEQUEO = p.ID_COLABORADOR_COORDINADOR
                                        AND l.FEC_REGISTRO_LISTA_CHEQUEO >= ? AND l.FEC_REGISTRO_LISTA_CHEQUEO < ?) AS COORD_LISTAS,
                               (SELECT COUNT(*) FROM reporte_revision r WHERE r.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR
                                        AND r.MODULO = 'CRONOGRAMA' AND r.FECHA = ?) AS COORD_REVISO,
                               (SELECT COUNT(*) FROM comunicacion m WHERE m.ID_PROYECTO = p.ID_PROYECTO AND m.ESTADO = 1
                                        AND m.FECHA_ATENCION IS NULL) AS COMUNICACIONES
                          FROM proyectos p
                          LEFT JOIN colaboradores co ON co.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR
                          LEFT JOIN reporte_hoy h ON h.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR AND h.FECHA = ?
                         WHERE p.ID_PROYECTO IN ($m)
                         ORDER BY p.NOM_PROYECTO",
                       'ssss' . str_repeat('i', count($idsProyectos)), array_merge(array($fecha, $sig, $fecha, $fecha), array_map('intval', $idsProyectos)));
        return $f ? $f : array();
    }

    //-----------------------------------------------------------------------
    // El reporte de una persona en un día
    //-----------------------------------------------------------------------
    public function persona($idColaborador, $fecha, $hoy)
    {
        $col = HanaDB::fila("SELECT c.ID_COLABORADOR, c.NOM_COLABORADOR, g.NOM_CARGO_COLABORADORES AS CARGO
                               FROM colaboradores c
                               LEFT JOIN cargos_colaboladores g ON g.ID_CARGO_COLABORADORES = c.ID_CARGO_COLABORADORES_COLABORADORES
                              WHERE c.ID_COLABORADOR = ?", 'i', array((int)$idColaborador));
        if (!$col) { return null; }
        $sig = date('Y-m-d', strtotime("$fecha +1 day"));

        //Hoy en qué estás, con los nombres de los centros
        $H = new Hoy();
        $hoyReg = $H->obtener($idColaborador, $fecha);
        if ($hoyReg && count($hoyReg['CENTROS'])) {
            $m = $this->marcas($hoyReg['CENTROS']);
            $n = HanaDB::q("SELECT NOM_CENTRO_OP FROM centros_operacion WHERE ID_CENTRO_OP IN ($m) ORDER BY NOM_CENTRO_OP",
                           str_repeat('i', count($hoyReg['CENTROS'])), $hoyReg['CENTROS']);
            $hoyReg['LUGARES'] = array_map(function ($x) { return $x['NOM_CENTRO_OP']; }, $n ? $n : array());
        }

        $C = new Cronograma();
        $listas = HanaDB::q("SELECT l.ID_LISTA_CHEQUEO, l.FEC_REGISTRO_LISTA_CHEQUEO AS FECHA, g.NOM_GRUPO_LISTA_CHEQUEO AS LISTA,
                                    c.NOM_CENTRO_OP, l.CANT_RESPUESTAS_LISTA AS RESPUESTAS
                               FROM lista_chequeo l
                               INNER JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = l.ID_GRUPO_LISTA_CHEQUEO
                               INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = l.ID_CENTRO_OP_LISTA_CHEQUEO
                              WHERE l.ID_COLABORADOR_LISTA_CHEQUEO = ?
                                AND l.FEC_REGISTRO_LISTA_CHEQUEO >= ? AND l.FEC_REGISTRO_LISTA_CHEQUEO < ?
                              ORDER BY l.FEC_REGISTRO_LISTA_CHEQUEO", 'iss', array((int)$idColaborador, $fecha, $sig));
        $arqueos = HanaDB::q("SELECT a.ID_ARQUEO, a.TIPO, a.HORA, a.TOTAL_ARQUEO, a.DIFERENCIA, a.ID_CENTRO_OP, c.NOM_CENTRO_OP
                                FROM arqueo a INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = a.ID_CENTRO_OP
                               WHERE a.ID_COLABORADOR_ARQUEA = ? AND a.FECHA = ? AND a.ESTADO = 1
                               ORDER BY a.HORA", 'is', array((int)$idColaborador, $fecha));
        //RQ que pidió esta persona: las que esperan aprobación y las de ese día
        $rq = HanaDB::q("SELECT r.ID_RQ, r.NUMERO_RQ, r.TIPO_RQ, r.FECHA_RQ, e.NOM_RQ_ESTADO, c.NOM_CENTRO_OP,
                                (SELECT GROUP_CONCAT(d.DESCRIPCION SEPARATOR ', ') FROM rq_detalle d WHERE d.ID_RQ = r.ID_RQ) AS ITEMS
                           FROM rq r
                           INNER JOIN rq_estado e ON e.ID_RQ_ESTADO = r.ID_RQ_ESTADO
                           INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                          WHERE r.ESTADO = 1 AND r.ID_COLABORADOR_SOLICITA = ?
                            AND (r.ID_RQ_ESTADO = 1 OR r.FECHA_RQ = ?)
                          ORDER BY r.FEC_CREACION DESC LIMIT 20", 'is', array((int)$idColaborador, $fecha));
        return array(
            'esCoordinador' => HanaDB::esCoordinador($idColaborador),
            'rq'         => $rq ? $rq : array(),
            'persona'    => $col,
            'hoy'        => $hoyReg,
            'cronograma' => $C->items($idColaborador, $fecha, $sig, $hoy),
            'listas'     => $listas ? $listas : array(),
            'arqueos'    => $arqueos ? $arqueos : array()
        );
    }
}
