<?php
/*
  HANA — Arqueos (Reporte diario, Fase 2)
  Caja menor y recambio, con la misma forma de los formatos en PDF.
  El total y la diferencia los calcula el servidor a partir de las líneas.
*/
require_once __DIR__ . "/HanaDB.php";

class Arqueo
{
    public static $TIPOS = array('CAJA_MENOR' => 'Caja menor', 'RECAMBIO' => 'Recambio', 'CASETA' => 'Casetas');

    //Arqueo de casetas: cómo se comporta cada campo configurable (ver 04_ARQUEO_CASETAS.sql)
    public static $ROLES = array('ESPERADO' => 'Valor esperado (recaudo de la caseta)', 'SUMA' => 'Suma al total recaudo',
                                 'RESTA' => 'Resta del total recaudo', 'INFO' => 'Informativo (no entra en la cuenta)');

    //Qué se cuenta en cada tipo. Efectivo: una línea fija por concepto.
    //Documentos (solo caja menor): tantas líneas como haya, con fecha
    public static $EFECTIVO = array(
        'CAJA_MENOR' => array('BILLETES' => 'Billetes', 'MONEDAS' => 'Monedas'),
        'RECAMBIO'   => array('BASES_CASETAS'    => 'Dinero para bases en casetas',
                              'BASE_SUPERVISORA' => 'Dinero base supervisora',
                              'MONEDAS'          => 'Dinero en monedas',
                              'BILLETES'         => 'Dinero en billetes',
                              'CAJA_FUERTE'      => 'Dinero custodiado en caja fuerte')
    );
    public static $DOCUMENTOS = array('FACTURA' => 'Factura', 'REINTEGRO' => 'Reintegro de fondo',
                                      'RECIBO_CAJA' => 'Recibo de caja', 'FALTANTE' => 'Faltante registrado');

    //-----------------------------------------------------------------------
    // Fondos autorizados
    //-----------------------------------------------------------------------

    //Los fondos de una lista de centros: [idCentro][tipo] = valor
    public function fondos($idsCentros)
    {
        $res = array();
        if (!count($idsCentros)) { return $res; }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $f = HanaDB::q("SELECT ID_CENTRO_OP, TIPO, VALOR_AUTORIZADO FROM arqueo_fondo WHERE ID_CENTRO_OP IN ($marcas)",
                       str_repeat('i', count($idsCentros)), array_map('intval', $idsCentros));
        foreach ((array)$f as $x) { $res[(int)$x['ID_CENTRO_OP']][$x['TIPO']] = (float)$x['VALOR_AUTORIZADO']; }
        return $res;
    }

    public function fondo($idCentro, $tipo)
    {
        $f = HanaDB::fila("SELECT VALOR_AUTORIZADO FROM arqueo_fondo WHERE ID_CENTRO_OP = ? AND TIPO = ?",
                          'is', array((int)$idCentro, $tipo));
        return $f ? (float)$f['VALOR_AUTORIZADO'] : null;
    }

    //Todos los centros activos con sus fondos, para la pantalla de configuración
    public function centrosConFondos()
    {
        $f = HanaDB::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.NOM_PROYECTO,
                               MAX(CASE WHEN f.TIPO = 'CAJA_MENOR' THEN f.VALOR_AUTORIZADO END) AS CAJA_MENOR,
                               MAX(CASE WHEN f.TIPO = 'RECAMBIO'   THEN f.VALOR_AUTORIZADO END) AS RECAMBIO
                          FROM centros_operacion c
                          INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                          LEFT JOIN arqueo_fondo f ON f.ID_CENTRO_OP = c.ID_CENTRO_OP
                         WHERE c.Estado = '1'
                         GROUP BY c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.NOM_PROYECTO
                         ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP");
        return $f ? $f : array();
    }

    //Guarda o quita un fondo. $valor null = el centro no maneja ese fondo
    public function guardarFondo($idCentro, $tipo, $valor, $idColaborador, $ahora)
    {
        if ($valor === null) {
            return HanaDB::q("DELETE FROM arqueo_fondo WHERE ID_CENTRO_OP = ? AND TIPO = ?", 'is', array((int)$idCentro, $tipo));
        }
        return HanaDB::q("INSERT INTO arqueo_fondo (ID_CENTRO_OP, TIPO, VALOR_AUTORIZADO, ID_COLABORADOR_MODIFICA, FEC_MODIFICACION)
                          VALUES (?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE VALOR_AUTORIZADO = VALUES(VALOR_AUTORIZADO),
                                                  ID_COLABORADOR_MODIFICA = VALUES(ID_COLABORADOR_MODIFICA),
                                                  FEC_MODIFICACION = VALUES(FEC_MODIFICACION)",
                         'isdis', array((int)$idCentro, $tipo, $valor, (int)$idColaborador, $ahora));
    }

    //-----------------------------------------------------------------------
    // Arqueos
    //-----------------------------------------------------------------------

    //Los arqueos de los centros del usuario, en un rango de fechas
    public function listar($idsCentros, $desde, $hasta, $idCentro, $tipo)
    {
        if (!count($idsCentros)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $sql = "SELECT a.ID_ARQUEO, a.TIPO, a.CASETA, a.FECHA, a.HORA, a.RESPONSABLE, a.FONDO_AUTORIZADO, a.TOTAL_ARQUEO,
                       a.DIFERENCIA, a.ESTADO, a.ID_COLABORADOR_ARQUEA, c.NOM_CENTRO_OP, p.NOM_PROYECTO,
                       col.NOM_COLABORADOR AS ARQUEA
                  FROM arqueo a
                  INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = a.ID_CENTRO_OP
                  INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                  LEFT JOIN colaboradores col ON col.ID_COLABORADOR = a.ID_COLABORADOR_ARQUEA
                 WHERE a.ID_CENTRO_OP IN ($marcas) AND a.FECHA >= ? AND a.FECHA < ?";
        $tipos = str_repeat('i', count($idsCentros)) . 'ss';
        $params = array_merge(array_map('intval', $idsCentros), array($desde, $hasta));
        if ((int)$idCentro > 0) { $sql .= " AND a.ID_CENTRO_OP = ?"; $tipos .= 'i'; $params[] = (int)$idCentro; }
        if (isset(self::$TIPOS[$tipo])) { $sql .= " AND a.TIPO = ?"; $tipos .= 's'; $params[] = $tipo; }
        $sql .= " ORDER BY a.FECHA DESC, a.HORA DESC, a.ID_ARQUEO DESC";
        $f = HanaDB::q($sql, $tipos, $params);
        return $f ? $f : array();
    }

    //Un arqueo completo, con sus líneas
    public function mostrar($idArqueo)
    {
        $a = HanaDB::fila("SELECT a.*, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.NOM_PROYECTO, col.NOM_COLABORADOR AS ARQUEA
                             FROM arqueo a
                             INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = a.ID_CENTRO_OP
                             INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                             LEFT JOIN colaboradores col ON col.ID_COLABORADOR = a.ID_COLABORADOR_ARQUEA
                            WHERE a.ID_ARQUEO = ?", 'i', array((int)$idArqueo));
        if (!$a) { return null; }
        $a['LINEAS'] = HanaDB::q("SELECT CLASE, CONCEPTO, NOMBRE, FECHA, VALOR, OBSERVACION FROM arqueo_linea
                                   WHERE ID_ARQUEO = ? ORDER BY CLASE DESC, ORDEN, ID_ARQUEO_LINEA",
                                  'i', array((int)$idArqueo));
        if (!$a['LINEAS']) { $a['LINEAS'] = array(); }
        return $a;
    }

    //Guarda un arqueo nuevo con sus líneas (dentro de una transacción)
    public function insertar($a, $lineas)
    {
        $ok = HanaDB::q("INSERT INTO arqueo (TIPO, ID_CENTRO_OP, CASETA, FECHA, HORA, HORA_FIN, RESPONSABLE, CARGO_RESPONSABLE,
                                             ID_COLABORADOR_ARQUEA, FONDO_AUTORIZADO, TOTAL_EFECTIVO, TOTAL_DOCUMENTOS,
                                             TOTAL_ARQUEO, DIFERENCIA, OBSERVACION, FEC_REGISTRO, ESTADO)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                        'sissssssidddddss',
                        array($a['tipo'], $a['centro'], isset($a['caseta']) ? $a['caseta'] : null, $a['fecha'], $a['hora'], $a['horaFin'], $a['responsable'],
                              $a['cargo'], $a['colaborador'], $a['fondo'], $a['efectivo'], $a['documentos'],
                              $a['total'], $a['diferencia'], $a['observacion'], $a['ahora']));
        if (!$ok) { return 0; }
        $id = HanaDB::id();
        $orden = 0;
        foreach ($lineas as $l) {
            $orden++;
            if (!HanaDB::q("INSERT INTO arqueo_linea (ID_ARQUEO, CLASE, CONCEPTO, NOMBRE, FECHA, VALOR, OBSERVACION, ORDEN)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                           'issssdsi', array($id, $l['clase'], $l['concepto'], isset($l['nombre']) ? $l['nombre'] : null, $l['fecha'], $l['valor'], $l['obs'], $orden))) {
                return 0;
            }
        }
        return $id;
    }

    //-----------------------------------------------------------------------
    // Arqueo de casetas: los campos configurables
    //-----------------------------------------------------------------------

    //Los campos que salen en el arqueo de un peaje: los generales (menos los
    //que se ocultaron en ese peaje) más los propios del peaje, en su orden
    public function conceptosCaseta($idCentro)
    {
        $f = HanaDB::q("SELECT c.ID_CONCEPTO, c.NOMBRE, c.ROL, c.ORDEN, c.ID_CENTRO_OP
                          FROM arqueo_concepto c
                         WHERE c.ESTADO = 1
                           AND (c.ID_CENTRO_OP = ? OR (c.ID_CENTRO_OP IS NULL AND NOT EXISTS (
                                SELECT 1 FROM arqueo_concepto_oculto o WHERE o.ID_CONCEPTO = c.ID_CONCEPTO AND o.ID_CENTRO_OP = ?)))
                         ORDER BY c.ORDEN, c.ID_CONCEPTO", 'ii', array((int)$idCentro, (int)$idCentro));
        return $f ? $f : array();
    }

    //Para la pantalla de configuración: todos los campos generales (con si están
    //ocultos en el peaje elegido) y los propios del peaje, activos o no
    public function conceptosConfig($idCentro)
    {
        $f = HanaDB::q("SELECT c.ID_CONCEPTO, c.NOMBRE, c.ROL, c.ORDEN, c.ESTADO, c.ID_CENTRO_OP,
                               (SELECT COUNT(*) FROM arqueo_concepto_oculto o WHERE o.ID_CONCEPTO = c.ID_CONCEPTO AND o.ID_CENTRO_OP = ?) AS OCULTO
                          FROM arqueo_concepto c
                         WHERE c.ID_CENTRO_OP IS NULL OR c.ID_CENTRO_OP = ?
                         ORDER BY c.ID_CENTRO_OP IS NOT NULL, c.ORDEN, c.ID_CONCEPTO", 'ii', array((int)$idCentro, (int)$idCentro));
        return $f ? $f : array();
    }

    public function guardarConcepto($id, $idCentro, $nombre, $rol, $orden, $estado)
    {
        if ($id > 0) {
            return HanaDB::q("UPDATE arqueo_concepto SET NOMBRE = ?, ROL = ?, ORDEN = ?, ESTADO = ? WHERE ID_CONCEPTO = ?",
                             'ssiii', array($nombre, $rol, (int)$orden, (int)$estado, (int)$id));
        }
        return HanaDB::q("INSERT INTO arqueo_concepto (ID_CENTRO_OP, NOMBRE, ROL, ORDEN, ESTADO) VALUES (?, ?, ?, ?, ?)",
                         'issii', array($idCentro > 0 ? (int)$idCentro : null, $nombre, $rol, (int)$orden, (int)$estado));
    }

    //Quitar (o volver a poner) en un peaje un campo que es de todos
    public function ocultarConcepto($idConcepto, $idCentro, $ocultar)
    {
        if ($ocultar) {
            return HanaDB::q("INSERT IGNORE INTO arqueo_concepto_oculto (ID_CONCEPTO, ID_CENTRO_OP) VALUES (?, ?)", 'ii', array((int)$idConcepto, (int)$idCentro));
        }
        return HanaDB::q("DELETE FROM arqueo_concepto_oculto WHERE ID_CONCEPTO = ? AND ID_CENTRO_OP = ?", 'ii', array((int)$idConcepto, (int)$idCentro));
    }

    public function anular($idArqueo, $motivo)
    {
        return HanaDB::q("UPDATE arqueo SET ESTADO = 0, MOTIVO_ANULACION = ? WHERE ID_ARQUEO = ? AND ESTADO = 1",
                         'si', array($motivo, (int)$idArqueo));
    }
}
