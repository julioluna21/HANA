<?php
/*
  HANA - Reporte general del día (control del reporte diario)
  ---------------------------------------------------------------------------
  Todo lo que enviaron los coordinadores y jefes de peaje en un día,
  organizado por módulo. El alcance es el mismo del tablero:
    - con 20M (director de operaciones): todo
    - el coordinador: su proyecto (él y los jefes de sus centros)
    - el jefe de peaje: lo suyo
*/
require_once __DIR__ . "/TableroModelo.php"; //trae también los modelos de Hoy en qué estás y del cronograma
require_once __DIR__ . "/VacanteModelo.php";
require_once __DIR__ . "/ArqueoModelo.php";

class ControlDiario
{
    //Arma los signos de pregunta (?, ?, ?) para un IN con varios ids
    private function marcas($ids) { return implode(',', array_fill(0, count($ids), '?')); }

    //-----------------------------------------------------------------------
    // Quiénes deben llenar el reporte del coordinador (Hoy en qué estás,
    // arqueos, cronograma...): SOLO los coordinadores. Las RQ y las listas de
    // chequeo dependen de las casillas del rol y se controlan por centro.
    //   $puedeVer: función ($idPersona, $idProyecto) -> bool
    //   Devuelve [idColaborador => [NOMBRE, ROLES[], PROYECTOS[]]]
    //-----------------------------------------------------------------------
    public function personas($proyectos, $centros, $puedeVer)
    {
        $personas = array();
        $agregar = function ($id, $nombre, $rol, $proyecto) use (&$personas) {
            $id = (int)$id;
            if (!isset($personas[$id])) { $personas[$id] = array('ID' => $id, 'NOMBRE' => $nombre, 'ROLES' => array(), 'PROYECTOS' => array()); }
            if (!in_array($rol, $personas[$id]['ROLES'], true)) { $personas[$id]['ROLES'][] = $rol; }
            if (!in_array($proyecto, $personas[$id]['PROYECTOS'], true)) { $personas[$id]['PROYECTOS'][] = $proyecto; }
        };
        foreach ($proyectos as $datosProyecto) {
            if ($datosProyecto['ID_COLABORADOR_COORDINADOR'] && $puedeVer((int)$datosProyecto['ID_COLABORADOR_COORDINADOR'], $datosProyecto['ID_PROYECTO'])) {
                $agregar($datosProyecto['ID_COLABORADOR_COORDINADOR'], $datosProyecto['COORDINADOR'], 'Coordinador ' . $datosProyecto['NOM_PROYECTO'], $datosProyecto['NOM_PROYECTO']);
            }
        }
        uasort($personas, function ($personaA, $personaB) { return strcmp($personaA['NOMBRE'], $personaB['NOMBRE']); });
        return $personas;
    }

    //Hoy en qué estás de un grupo de personas en un día
    public function hoy($idsPersonas, $fecha)
    {
        if (!count($idsPersonas)) { return array(); }
        $marcas = $this->marcas($idsPersonas);
        $filas = HanaDB::q("SELECT h.ID_COLABORADOR, h.SITUACION, h.HORA_INGRESO, h.HORA_SALIDA, h.LUGAR_OTRO, h.OBSERVACION,
                               h.FEC_REGISTRO, h.FEC_MODIFICACION,
                               (SELECT GROUP_CONCAT(c.NOM_CENTRO_OP ORDER BY c.NOM_CENTRO_OP SEPARATOR ' - ')
                                  FROM reporte_hoy_centro hc INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = hc.ID_CENTRO_OP
                                 WHERE hc.ID_REPORTE_HOY = h.ID_REPORTE_HOY) AS LUGARES,
                               h.ACTIVIDAD
                          FROM reporte_hoy h
                         WHERE h.FECHA = ? AND h.ID_COLABORADOR IN ($marcas)",
                       's' . str_repeat('i', count($idsPersonas)), array_merge(array($fecha), array_map('intval', $idsPersonas)));
        $porPersona = array();
        foreach ((array)$filas as $fila) { $porPersona[(int)$fila['ID_COLABORADOR']] = $fila; }
        return $porPersona;
    }

    //Listas de chequeo diligenciadas ese día en los centros visibles. PREGUNTAS (activas del
    //grupo) y RESPUESTAS (guardadas en la lista) se cuentan al momento
    public function listas($idsCentros, $fecha)
    {
        if (!count($idsCentros)) { return array(); }
        $diaSiguiente = date('Y-m-d', strtotime("$fecha +1 day"));
        $marcas = $this->marcas($idsCentros);
        $filas = HanaDB::q("SELECT l.ID_LISTA_CHEQUEO, l.ID_COLABORADOR AS ID_PERSONA,
                               l.FEC_REGISTRO AS FECHA, g.NOM_GRUPO_LISTA_CHEQUEO AS LISTA,
                               (SELECT COUNT(*) FROM detalle_grupo_lista_chequeo q WHERE q.ID_GRUPO_LISTA_CHEQUEO = l.ID_GRUPO_LISTA_CHEQUEO AND q.ESTADO = 1) AS PREGUNTAS,
                               (SELECT COUNT(*) FROM detalle_lista_chequeo d WHERE d.ID_LISTA_CHEQUEO = l.ID_LISTA_CHEQUEO) AS RESPUESTAS,
                               c.NOM_CENTRO_OP, p.NOM_PROYECTO, col.NOM_COLABORADOR AS PERSONA
                          FROM lista_chequeo l
                          INNER JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = l.ID_GRUPO_LISTA_CHEQUEO
                          INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = l.ID_CENTRO_OP
                          INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                          LEFT JOIN colaboradores col ON col.ID_COLABORADOR = l.ID_COLABORADOR
                         WHERE l.FEC_REGISTRO >= ? AND l.FEC_REGISTRO < ?
                           AND l.ID_CENTRO_OP IN ($marcas)
                         ORDER BY l.FEC_REGISTRO, l.ID_LISTA_CHEQUEO",
                       'ss' . str_repeat('i', count($idsCentros)), array_merge(array($fecha, $diaSiguiente), array_map('intval', $idsCentros)));
        return $filas ? $filas : array();
    }

    //Una lista de chequeo con todas sus respuestas, pregunta por pregunta.
    //Incluye las preguntas que quedaron sin responder (listas incompletas).
    //Los textos se guardaron con limpiarCadena (codificados para HTML): se
    //decodifican aquí para que la pantalla los escape una sola vez
    public function listaRespuestas($idLista)
    {
        $lista = HanaDB::fila("SELECT l.ID_LISTA_CHEQUEO, l.ID_CENTRO_OP AS ID_CENTRO, l.FEC_REGISTRO AS FECHA,
                                  l.ID_GRUPO_LISTA_CHEQUEO AS ID_GRUPO,
                                  (SELECT COUNT(*) FROM detalle_lista_chequeo d WHERE d.ID_LISTA_CHEQUEO = l.ID_LISTA_CHEQUEO) AS RESPUESTAS,
                                  g.NOM_GRUPO_LISTA_CHEQUEO AS LISTA, c.NOM_CENTRO_OP, p.NOM_PROYECTO, col.NOM_COLABORADOR AS PERSONA
                             FROM lista_chequeo l
                             INNER JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = l.ID_GRUPO_LISTA_CHEQUEO
                             INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = l.ID_CENTRO_OP
                             INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                             LEFT JOIN colaboradores col ON col.ID_COLABORADOR = l.ID_COLABORADOR
                            WHERE l.ID_LISTA_CHEQUEO = ?", 'i', array((int)$idLista));
        if (!$lista) { return null; }
        $filas = HanaDB::q("SELECT q.PREGUNTA, q.TIPO_RESPUESTA AS TIPO,
                               d.RESPUESTA
                          FROM detalle_grupo_lista_chequeo q
                          LEFT JOIN detalle_lista_chequeo d
                                 ON d.ID_PREGUNTA = q.ID_DETALLE_GRUPO_LISTA_CHEQUEO
                                AND d.ID_LISTA_CHEQUEO = ?
                         WHERE q.ID_GRUPO_LISTA_CHEQUEO = ?
                           AND (q.ESTADO = 1 OR d.ID_DETALLE_LISTA_CHEQUEO IS NOT NULL)
                         ORDER BY q.ORDEN, q.ID_DETALLE_GRUPO_LISTA_CHEQUEO", 'ii', array((int)$idLista, (int)$lista['ID_GRUPO']));
        $decodificar = function ($texto) { return $texto === null ? null : html_entity_decode((string)$texto, ENT_QUOTES, 'UTF-8'); };
        $lista['PREGUNTAS'] = array();
        foreach ((array)$filas as $fila) {
            $lista['PREGUNTAS'][] = array('PREGUNTA' => $decodificar($fila['PREGUNTA']), 'TIPO' => $fila['TIPO'], 'RESPUESTA' => $decodificar($fila['RESPUESTA']));
        }
        $lista['LISTA'] = $decodificar($lista['LISTA']);
        return $lista;
    }

    //Arqueos del día en los centros visibles
    public function arqueos($idsCentros, $fecha)
    {
        if (!count($idsCentros)) { return array(); }
        $marcas = $this->marcas($idsCentros);
        $filas = HanaDB::q("SELECT a.ID_ARQUEO, a.TIPO, a.HORA, a.RESPONSABLE, " . Arqueo::sqlTotal() . " AS TOTAL_ARQUEO,
                               " . Arqueo::sqlFondo() . " AS FONDO_AUTORIZADO, " . Arqueo::sqlDiferencia() . " AS DIFERENCIA,
                               a.OBSERVACION, c.NOM_CENTRO_OP, p.NOM_PROYECTO, p.ID_PROYECTO, col.NOM_COLABORADOR AS ARQUEA,
                               a.ID_COLABORADOR_ARQUEA AS ID_PERSONA
                          FROM arqueo a
                          INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = a.ID_CENTRO_OP
                          INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                          LEFT JOIN colaboradores col ON col.ID_COLABORADOR = a.ID_COLABORADOR_ARQUEA
                         WHERE a.FECHA = ? AND a.ESTADO = 1 AND a.ID_CENTRO_OP IN ($marcas)
                         ORDER BY a.HORA, a.ID_ARQUEO",
                       's' . str_repeat('i', count($idsCentros)), array_merge(array($fecha), array_map('intval', $idsCentros)));
        return $filas ? $filas : array();
    }

    //Cronograma del día de cada persona, con su estado real
    public function cronograma($personas, $fecha, $hoy)
    {
        $modeloCronograma = new Cronograma();
        $diaSiguiente = date('Y-m-d', strtotime("$fecha +1 day"));
        $actividades = array();
        foreach ($personas as $id => $persona) {
            foreach ($modeloCronograma->items($id, $fecha, $diaSiguiente, $hoy) as $actividad) {
                $actividad['PERSONA'] = $persona['NOMBRE'];
                $actividades[] = $actividad;
            }
        }
        return $actividades;
    }

    //Vehículos de las personas visibles (o de los proyectos visibles) y su estado ese día
    public function vehiculos($idsPersonas, $idsProyectos, $fecha)
    {
        $cond = array(); $tipos = 's'; $params = array($fecha);
        if (count($idsPersonas))  { $cond[] = 'v.ID_COLABORADOR_RESPONSABLE IN (' . $this->marcas($idsPersonas) . ')'; $tipos .= str_repeat('i', count($idsPersonas)); $params = array_merge($params, array_map('intval', $idsPersonas)); }
        if (count($idsProyectos)) { $cond[] = 'v.ID_PROYECTO IN (' . $this->marcas($idsProyectos) . ')'; $tipos .= str_repeat('i', count($idsProyectos)); $params = array_merge($params, array_map('intval', $idsProyectos)); }
        if (!count($cond)) { return array(); }
        $filas = HanaDB::q("SELECT v.ID_VEHICULO, v.PLACA, v.DESCRIPCION, col.NOM_COLABORADOR AS RESPONSABLE,
                               d.ESTADO_VH, d.TRANSPORTE, d.OBSERVACION, d.FEC_REGISTRO
                          FROM vehiculo v
                          LEFT JOIN colaboradores col ON col.ID_COLABORADOR = v.ID_COLABORADOR_RESPONSABLE
                          LEFT JOIN vehiculo_dia d ON d.ID_VEHICULO = v.ID_VEHICULO AND d.FECHA = ?
                         WHERE v.ESTADO = 1 AND (" . implode(' OR ', $cond) . ")
                         ORDER BY v.PLACA", $tipos, $params);
        return $filas ? $filas : array();
    }

    //Ausencias registradas ese día, por proyecto: [idProyecto => personas ausentes]
    //(solo las novedades que cuentan como ausencia, como el total del reporte)
    public function ausentismoDia($idsCentros, $fecha)
    {
        if (!count($idsCentros)) { return array(); }
        try {
            $filas = HanaDB::q("SELECT c.ID_PROYECTO, SUM(r.CANTIDAD) AS AUSENTES
                              FROM aus_registro r
                              INNER JOIN aus_novedad n ON n.ID_NOVEDAD_AUS = r.ID_NOVEDAD_AUS AND n.CUENTA_AUSENCIA = 1
                              INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                             WHERE r.FECHA = ? AND r.ID_CENTRO_OP IN (" . $this->marcas($idsCentros) . ")
                             GROUP BY c.ID_PROYECTO", 's' . str_repeat('i', count($idsCentros)), array_merge(array($fecha), $idsCentros));
        } catch (\Throwable $error) { return array(); } //si el ausentismo no está instalado
        $porProyecto = array();
        foreach ((array)$filas as $fila) { $porProyecto[(int)$fila['ID_PROYECTO']] = (int)$fila['AUSENTES']; }
        return $porProyecto;
    }

    //Las ausencias de un día, una fila por centro, cargo y motivo, con quién la anotó.
    //CUENTA = 0 para los motivos marcados como "no es ausencia" en Parámetros
    public function ausenciasDetalle($idsCentros, $fecha)
    {
        if (!count($idsCentros)) { return array(); }
        try {
            $filas = HanaDB::q("SELECT r.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, c.ID_PROYECTO, p.NOM_PROYECTO,
                                   r.ID_CARGO_AUS, ca.NOMBRE AS CARGO, n.NOMBRE AS MOTIVO, n.CUENTA_AUSENCIA AS CUENTA, r.CANTIDAD,
                                   col.NOM_COLABORADOR AS QUIEN, r.FEC_MODIFICACION
                              FROM aus_registro r
                              INNER JOIN aus_novedad n ON n.ID_NOVEDAD_AUS = r.ID_NOVEDAD_AUS
                              INNER JOIN aus_cargo ca ON ca.ID_CARGO_AUS = r.ID_CARGO_AUS
                              INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                              INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                              LEFT JOIN colaboradores col ON col.ID_COLABORADOR = r.ID_COLABORADOR
                             WHERE r.FECHA = ? AND r.ID_CENTRO_OP IN (" . $this->marcas($idsCentros) . ")
                             ORDER BY p.NOM_PROYECTO, c.TIPO_CENTRO = 'BASCULA', c.NOM_CENTRO_OP, ca.ORDEN, n.ORDEN",
                           's' . str_repeat('i', count($idsCentros)), array_merge(array($fecha), $idsCentros));
        } catch (\Throwable $error) { return array(); } //si el ausentismo no está instalado
        return $filas ? $filas : array();
    }
}
