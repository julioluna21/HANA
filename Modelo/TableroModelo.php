<?php
/*
  HANA - Tablero del reporte diario
  ---------------------------------------------------------------------------
  Tres niveles: proyectos -> centros de cada proyecto -> el reporte de una
  persona en un día. Qué ve cada quien sale de la jerarquía:
    - Con el permiso 20M (gerente de operaciones): todos los proyectos.
    - El coordinador de un proyecto: ese proyecto completo.
    - El jefe de un centro: ese centro, dentro de su proyecto.
*/
require_once __DIR__ . "/HanaDB.php";
require_once __DIR__ . "/HoyModelo.php";
require_once __DIR__ . "/CronogramaModelo.php";
require_once __DIR__ . "/RQEstados.php";
require_once __DIR__ . "/ArqueoModelo.php";

class Tablero
{
    //-----------------------------------------------------------------------
    // Alcance: qué proyectos y centros puede ver esta persona
    //   devuelve ['proyectos' => [id => true], 'centros' => [id => true]]
    //-----------------------------------------------------------------------
    public function alcance($idColaborador, $verTodos)
    {
        $alcance = array('proyectos' => array(), 'centros' => array());
        $centros = HanaDB::q("SELECT c.ID_CENTRO_OP, c.ID_PROYECTO, c.ID_COLABORADOR_JEFE, p.ID_COLABORADOR_COORDINADOR
                             FROM centros_operacion c
                             INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                            WHERE c.ESTADO = '1' AND p.ESTADO = '1'");
        foreach ((array)$centros as $centro) {
            $loVe = $verTodos || (int)$centro['ID_COLABORADOR_COORDINADOR'] === (int)$idColaborador
                            || (int)$centro['ID_COLABORADOR_JEFE'] === (int)$idColaborador;
            if ($loVe) {
                $alcance['centros'][(int)$centro['ID_CENTRO_OP']] = true;
                $alcance['proyectos'][(int)$centro['ID_PROYECTO']] = true;
            }
        }
        //Un proyecto sin centros todavía, pero del que la persona es coordinadora
        $proyectos = $verTodos ? HanaDB::q("SELECT ID_PROYECTO FROM proyectos WHERE ESTADO = '1'")
                        : HanaDB::q("SELECT ID_PROYECTO FROM proyectos WHERE ESTADO = '1' AND ID_COLABORADOR_COORDINADOR = ?", 'i', array((int)$idColaborador));
        foreach ((array)$proyectos as $proyecto) { $alcance['proyectos'][(int)$proyecto['ID_PROYECTO']] = true; }
        return $alcance;
    }

    //Arma los signos de pregunta (?, ?, ?) para un IN con varios ids
    private function marcas($ids) { return implode(',', array_fill(0, count($ids), '?')); }

    //-----------------------------------------------------------------------
    // Los centros visibles con lo del día: el jefe, si reportó, arqueos,
    // listas, vacantes abiertas y RQ pendientes
    //-----------------------------------------------------------------------
    public function centros($idsCentros, $fecha)
    {
        if (!count($idsCentros)) { return array(); }
        $diaSiguiente = date('Y-m-d', strtotime("$fecha +1 day"));
        $marcas = $this->marcas($idsCentros);
        $sql = "SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, c.ID_PROYECTO,
                       c.ID_COLABORADOR_JEFE, j.NOM_COLABORADOR AS JEFE,
                       h.SITUACION, h.HORA_INGRESO, h.HORA_SALIDA,
                       (SELECT COUNT(*) FROM vacante v WHERE v.ID_CENTRO_OP = c.ID_CENTRO_OP AND v.ESTADO_VACANTE = 'ABIERTA') AS VACANTES,
                       (SELECT COUNT(*) FROM arqueo a WHERE a.ID_CENTRO_OP = c.ID_CENTRO_OP AND a.FECHA = ? AND a.ESTADO = 1) AS ARQUEOS,
                       (SELECT COUNT(*) FROM arqueo a WHERE a.ID_CENTRO_OP = c.ID_CENTRO_OP AND a.FECHA = ? AND a.ESTADO = 1 AND " . Arqueo::sqlDiferencia() . " <> 0) AS ARQUEOS_DIF,
                       (SELECT COUNT(*) FROM lista_chequeo l WHERE l.ID_CENTRO_OP = c.ID_CENTRO_OP
                                AND l.FEC_REGISTRO >= ? AND l.FEC_REGISTRO < ?) AS LISTAS,
                       (SELECT COUNT(*) FROM rq r WHERE r.ID_CENTRO_OP = c.ID_CENTRO_OP AND r.ID_RQ_ESTADO = " . RqEstado::SOLICITADA . ") AS RQ_PENDIENTES
                  FROM centros_operacion c
                  LEFT JOIN colaboradores j ON j.ID_COLABORADOR = c.ID_COLABORADOR_JEFE
                  LEFT JOIN reporte_hoy h ON h.ID_COLABORADOR = c.ID_COLABORADOR_JEFE AND h.FECHA = ?
                 WHERE c.ID_CENTRO_OP IN ($marcas)
                 ORDER BY c.NOM_CENTRO_OP";
        $filas = HanaDB::q($sql, 'sssss' . str_repeat('i', count($idsCentros)),
                       array_merge(array($fecha, $fecha, $fecha, $diaSiguiente, $fecha), array_map('intval', $idsCentros)));
        return $filas ? $filas : array();
    }

    //Los proyectos visibles, con su coordinador y si reportó ese día
    public function proyectos($idsProyectos, $fecha)
    {
        if (!count($idsProyectos)) { return array(); }
        $marcas = $this->marcas($idsProyectos);
        $diaSiguiente = date('Y-m-d', strtotime("$fecha +1 day"));
        $filas = HanaDB::q("SELECT p.ID_PROYECTO, p.NOM_PROYECTO, p.ID_COLABORADOR_COORDINADOR, co.NOM_COLABORADOR AS COORDINADOR,
                               h.SITUACION AS COORD_SITUACION, h.HORA_INGRESO AS COORD_INGRESO,
                               (SELECT COUNT(*) FROM lista_chequeo l WHERE l.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR
                                        AND l.FEC_REGISTRO >= ? AND l.FEC_REGISTRO < ?) AS COORD_LISTAS,
                               (SELECT COUNT(*) FROM bitacora_sistema b WHERE b.TIPO = 'REVISION' AND b.MODULO = 'CRONOGRAMA'
                                        AND b.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR
                                        AND b.FECHA >= ? AND b.FECHA < ?) AS COORD_REVISO,
                               (SELECT COUNT(*) FROM comunicacion m WHERE m.ID_PROYECTO = p.ID_PROYECTO AND m.ESTADO = 1
                                        AND m.FECHA_ATENCION IS NULL) AS COMUNICACIONES
                          FROM proyectos p
                          LEFT JOIN colaboradores co ON co.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR
                          LEFT JOIN reporte_hoy h ON h.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR AND h.FECHA = ?
                         WHERE p.ID_PROYECTO IN ($marcas)
                         ORDER BY p.NOM_PROYECTO",
                       'sssss' . str_repeat('i', count($idsProyectos)), array_merge(array($fecha, $diaSiguiente, $fecha, $diaSiguiente, $fecha), array_map('intval', $idsProyectos)));
        return $filas ? $filas : array();
    }

    //-----------------------------------------------------------------------
    // El reporte de una persona en un día
    //-----------------------------------------------------------------------
    public function persona($idColaborador, $fecha, $hoy)
    {
        $persona = HanaDB::fila("SELECT c.ID_COLABORADOR, c.NOM_COLABORADOR, r.NOM_ROL_USUARIO_SISTEMA AS ROL
                               FROM colaboradores c
                               LEFT JOIN usuarios_sistema u ON u.ID_COLABORADOR = c.ID_COLABORADOR
                               LEFT JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA
                              WHERE c.ID_COLABORADOR = ?", 'i', array((int)$idColaborador));
        if (!$persona) { return null; }
        $diaSiguiente = date('Y-m-d', strtotime("$fecha +1 day"));

        //Hoy en qué estás, con los nombres de los centros
        $modeloHoy = new Hoy();
        $hoyReg = $modeloHoy->obtener($idColaborador, $fecha);
        if ($hoyReg && count($hoyReg['CENTROS'])) {
            $marcas = $this->marcas($hoyReg['CENTROS']);
            $nombres = HanaDB::q("SELECT NOM_CENTRO_OP FROM centros_operacion WHERE ID_CENTRO_OP IN ($marcas) ORDER BY NOM_CENTRO_OP",
                           str_repeat('i', count($hoyReg['CENTROS'])), $hoyReg['CENTROS']);
            $hoyReg['LUGARES'] = array_map(function ($centro) { return $centro['NOM_CENTRO_OP']; }, $nombres ? $nombres : array());
        }

        $modeloCronograma = new Cronograma();
        $listas = HanaDB::q("SELECT l.ID_LISTA_CHEQUEO, l.FEC_REGISTRO AS FECHA, g.NOM_GRUPO_LISTA_CHEQUEO AS LISTA,
                                    c.NOM_CENTRO_OP, (SELECT COUNT(*) FROM detalle_lista_chequeo d WHERE d.ID_LISTA_CHEQUEO = l.ID_LISTA_CHEQUEO) AS RESPUESTAS
                               FROM lista_chequeo l
                               INNER JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = l.ID_GRUPO_LISTA_CHEQUEO
                               INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = l.ID_CENTRO_OP
                              WHERE l.ID_COLABORADOR = ?
                                AND l.FEC_REGISTRO >= ? AND l.FEC_REGISTRO < ?
                              ORDER BY l.FEC_REGISTRO", 'iss', array((int)$idColaborador, $fecha, $diaSiguiente));
        $arqueos = HanaDB::q("SELECT a.ID_ARQUEO, a.TIPO, a.HORA, " . Arqueo::sqlTotal() . " AS TOTAL_ARQUEO,
                                     " . Arqueo::sqlDiferencia() . " AS DIFERENCIA, a.ID_CENTRO_OP, c.NOM_CENTRO_OP
                                FROM arqueo a INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = a.ID_CENTRO_OP
                               WHERE a.ID_COLABORADOR_ARQUEA = ? AND a.FECHA = ? AND a.ESTADO = 1
                               ORDER BY a.HORA", 'is', array((int)$idColaborador, $fecha));
        //RQ que pidió esta persona: las que esperan aprobación y las de ese día
        $rqPedidas = HanaDB::q("SELECT r.ID_RQ, r.NUMERO_RQ, r.TIPO_RQ, r.FECHA_RQ,
                                " . RqEstado::sqlCampo('r.ID_RQ_ESTADO', 'NOM_RQ_ESTADO') . " AS NOM_RQ_ESTADO, c.NOM_CENTRO_OP,
                                (SELECT GROUP_CONCAT(d.DESCRIPCION SEPARATOR ', ') FROM rq_detalle d WHERE d.ID_RQ = r.ID_RQ) AS ITEMS
                           FROM rq r
                           INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                          WHERE r.ID_RQ_ESTADO <> " . RqEstado::ANULADA . " AND r.ID_COLABORADOR_SOLICITA = ?
                            AND (r.ID_RQ_ESTADO = " . RqEstado::SOLICITADA . " OR r.FECHA_RQ = ?)
                          ORDER BY r.FEC_CREACION DESC LIMIT 20", 'is', array((int)$idColaborador, $fecha));
        return array(
            'esCoordinador' => HanaDB::esCoordinador($idColaborador),
            'rq'         => $rqPedidas ? $rqPedidas : array(),
            'persona'    => $persona,
            'hoy'        => $hoyReg,
            'cronograma' => $modeloCronograma->items($idColaborador, $fecha, $diaSiguiente, $hoy),
            'listas'     => $listas ? $listas : array(),
            'arqueos'    => $arqueos ? $arqueos : array()
        );
    }
}
