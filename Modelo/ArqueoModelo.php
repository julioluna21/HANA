<?php
/*
  HANA - Arqueos (Reporte diario)
  ---------------------------------------------------------------------------
  Caja menor, recambio y casetas, con la misma forma de los formatos en PDF.
  Se guarda lo que se contó y nada que se pueda sacar de ahí:
    - arqueo.BILLETES_MONEDAS: los billetes y monedas de la caja menor (y de los recambios viejos,
      que los guardaban juntos; hoy el recambio los guarda por separado, como líneas);
    - arqueo_linea: cada documento, cada concepto del recambio y cada campo de la caseta;
    - arqueo.FONDO_AUTORIZADO: el fondo de ese día (caja menor y recambio). En casetas
      va vacío, porque el valor esperado es una de sus líneas (ROL ESPERADO).
  El total, el fondo de las casetas y la diferencia se calculan en la consulta
  (sqlTotal, sqlFondo y sqlDiferencia), y los subtotales de efectivo y de
  documentos del formato se calculan al leer el arqueo (mostrar()).
*/
require_once __DIR__ . "/HanaDB.php";

class Arqueo
{
    //Tipos de arqueo (lo que se guarda => cómo se muestra)
    public static $TIPOS = array('CAJA_MENOR' => 'Caja menor', 'RECAMBIO' => 'Recambio', 'CASETA' => 'Casetas');

    //Arqueo de casetas: cómo cuenta cada campo configurable (columna ROL de arqueo_concepto)
    public static $ROLES = array('ESPERADO' => 'Valor esperado (recaudo de la caseta)', 'SUMA' => 'Suma al total recaudo',
                                 'RESTA' => 'Resta del total recaudo', 'INFO' => 'Informativo (no entra en la cuenta)');

    //Qué se cuenta en cada tipo, en el orden de los formatos en papel. Efectivo: un campo por concepto.
    //Documentos (solo caja menor): tantas líneas como haya, con fecha.
    //Cada concepto que se guarda como línea existe en arqueo_concepto con su CODIGO (el de estas llaves):
    //la línea apunta a él con ID_CONCEPTO. El efectivo (billetes y monedas juntos, llave EFECTIVO) NO se
    //guarda como línea: queda en arqueo.BILLETES_MONEDAS (ver mostrar())
    public static $EFECTIVO = array(
        'CAJA_MENOR' => array('EFECTIVO' => 'Efectivo (billetes y monedas)'),
        //En el recambio los billetes y las monedas van por separado, cada uno como línea.
        //Los recambios registrados antes los tienen juntos en arqueo.BILLETES_MONEDAS (ver conEfectivo())
        'RECAMBIO'   => array('BASES_CASETAS'    => 'Dinero para bases en casetas',
                              'BASE_SUPERVISORA' => 'Dinero base supervisora',
                              'BILLETES'         => 'Dinero en efectivo: billetes',
                              'MONEDAS'          => 'Dinero en efectivo: monedas',
                              'CAJA_FUERTE'      => 'Dinero custodiado en caja fuerte')
    );
    public static $DOCUMENTOS = array('FACTURA' => 'Factura', 'REINTEGRO' => 'Reintegro de fondo',
                                      'RECIBO_CAJA' => 'Recibo de caja', 'FALTANTE' => 'Faltantes');
    //Los conceptos de documentos que se pueden usar en un arqueo nuevo. Un concepto apagado en
    //arqueo_concepto (ESTADO = 0, como el reintegro de fondo) ya no se ofrece,
    //pero los arqueos viejos que lo tienen lo siguen mostrando con su nombre
    public static $DOCUMENTOS_ACTIVOS = array('FACTURA' => 'Factura', 'RECIBO_CAJA' => 'Recibo de caja', 'FALTANTE' => 'Faltantes');

    //Los nombres de los conceptos de caja menor y recambio salen de arqueo_concepto (el administrador
    //los cambia en Parámetros del sistema → Listas). Los de arriba son los de fábrica, por si la base no
    //responde. El formato en PDF conserva los títulos impresos del formato en papel
    public static function nombresDeLaBase()
    {
        $filas = HanaDB::q("SELECT TIPO_ARQUEO, CODIGO, NOMBRE, ESTADO FROM arqueo_concepto WHERE CODIGO IS NOT NULL AND TIPO_ARQUEO IN ('CAJA_MENOR', 'RECAMBIO') ORDER BY ORDEN, ID_CONCEPTO");
        if (is_array($filas) && count($filas)) { self::$DOCUMENTOS_ACTIVOS = array(); }
        foreach (is_array($filas) ? $filas : array() as $fila) {
            if ($fila['TIPO_ARQUEO'] === 'CAJA_MENOR' && isset(self::$DOCUMENTOS[$fila['CODIGO']])) {
                self::$DOCUMENTOS[$fila['CODIGO']] = $fila['NOMBRE'];
                if ((int)$fila['ESTADO'] === 1) { self::$DOCUMENTOS_ACTIVOS[$fila['CODIGO']] = $fila['NOMBRE']; }
            }
            if ($fila['TIPO_ARQUEO'] === 'RECAMBIO' && isset(self::$EFECTIVO['RECAMBIO'][$fila['CODIGO']])) { self::$EFECTIVO['RECAMBIO'][$fila['CODIGO']] = $fila['NOMBRE']; }
        }
    }

    //Total del arqueo en SQL = billetes y monedas + las líneas que suman - las que restan.
    //Sirve para los tres tipos: los documentos y los conceptos del recambio suman; en casetas
    //cada campo dice si suma, resta o es informativo. $alias es el alias de la tabla arqueo
    public static function sqlTotal($alias = 'a')
    {
        return "(IFNULL($alias.BILLETES_MONEDAS, 0) + IFNULL((SELECT SUM(CASE lt.ROL WHEN 'SUMA' THEN lt.VALOR WHEN 'RESTA' THEN -lt.VALOR ELSE 0 END)"
             . " FROM arqueo_linea lt WHERE lt.ID_ARQUEO = $alias.ID_ARQUEO), 0))";
    }

    //Contra qué se compara el arqueo: el fondo autorizado (caja menor y recambio) o, en casetas,
    //el valor esperado, que es la línea con ROL ESPERADO
    public static function sqlFondo($alias = 'a')
    {
        return "(CASE WHEN $alias.TIPO = 'CASETA' THEN IFNULL((SELECT SUM(le.VALOR) FROM arqueo_linea le"
             . " WHERE le.ID_ARQUEO = $alias.ID_ARQUEO AND le.ROL = 'ESPERADO'), 0) ELSE $alias.FONDO_AUTORIZADO END)";
    }

    //La diferencia del arqueo en SQL: total menos fondo (negativa: faltante; positiva: sobrante)
    public static function sqlDiferencia($alias = 'a')
    {
        return "(" . self::sqlTotal($alias) . " - " . self::sqlFondo($alias) . ")";
    }

    //-----------------------------------------------------------------------
    // Fondos autorizados
    //-----------------------------------------------------------------------

    //Los fondos de una lista de centros: [idCentro][tipo] = valor
    public function fondos($idsCentros)
    {
        $fondos = array();
        if (!count($idsCentros)) { return $fondos; }
        $marcas = implode(',', array_fill(0, count($idsCentros), '?'));
        $filas = HanaDB::q("SELECT ID_CENTRO_OP, TIPO, VALOR_AUTORIZADO FROM arqueo_fondo WHERE ID_CENTRO_OP IN ($marcas)",
                       str_repeat('i', count($idsCentros)), array_map('intval', $idsCentros));
        foreach ((array)$filas as $fila) { $fondos[(int)$fila['ID_CENTRO_OP']][$fila['TIPO']] = (float)$fila['VALOR_AUTORIZADO']; }
        return $fondos;
    }

    //El fondo autorizado de un peaje para un tipo de arqueo (caja menor o recambio)
    public function fondo($idCentro, $tipo)
    {
        $fila = HanaDB::fila("SELECT VALOR_AUTORIZADO FROM arqueo_fondo WHERE ID_CENTRO_OP = ? AND TIPO = ?",
                          'is', array((int)$idCentro, $tipo));
        return $fila ? (float)$fila['VALOR_AUTORIZADO'] : null;
    }

    //Todos los centros activos con sus fondos, para la pantalla de configuración
    public function centrosConFondos()
    {
        $filas = HanaDB::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.NOM_PROYECTO,
                               MAX(CASE WHEN f.TIPO = 'CAJA_MENOR' THEN f.VALOR_AUTORIZADO END) AS CAJA_MENOR,
                               MAX(CASE WHEN f.TIPO = 'RECAMBIO'   THEN f.VALOR_AUTORIZADO END) AS RECAMBIO
                          FROM centros_operacion c
                          INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                          LEFT JOIN arqueo_fondo f ON f.ID_CENTRO_OP = c.ID_CENTRO_OP
                         WHERE c.ESTADO = '1'
                         GROUP BY c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.NOM_PROYECTO
                         ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP");
        return $filas ? $filas : array();
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
        $sql = "SELECT a.ID_ARQUEO, a.TIPO, a.CASETA, a.FECHA, a.HORA, a.RESPONSABLE, " . self::sqlFondo() . " AS FONDO_AUTORIZADO,
                       " . self::sqlTotal() . " AS TOTAL_ARQUEO, " . self::sqlDiferencia() . " AS DIFERENCIA,
                       a.ESTADO, a.ID_COLABORADOR_ARQUEA, c.NOM_CENTRO_OP, p.NOM_PROYECTO,
                       col.NOM_COLABORADOR AS ARQUEA
                  FROM arqueo a
                  INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = a.ID_CENTRO_OP
                  INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                  LEFT JOIN colaboradores col ON col.ID_COLABORADOR = a.ID_COLABORADOR_ARQUEA
                 WHERE a.ID_CENTRO_OP IN ($marcas) AND a.FECHA >= ? AND a.FECHA < ?";
        $tipos = str_repeat('i', count($idsCentros)) . 'ss';
        $params = array_merge(array_map('intval', $idsCentros), array($desde, $hasta));
        if ((int)$idCentro > 0) { $sql .= " AND a.ID_CENTRO_OP = ?"; $tipos .= 'i'; $params[] = (int)$idCentro; }
        if (isset(self::$TIPOS[$tipo])) { $sql .= " AND a.TIPO = ?"; $tipos .= 's'; $params[] = $tipo; }
        $sql .= " ORDER BY a.FECHA DESC, a.HORA DESC, a.ID_ARQUEO DESC";
        $filas = HanaDB::q($sql, $tipos, $params);
        return $filas ? $filas : array();
    }

    //Un arqueo completo, con sus líneas
    public function mostrar($idArqueo)
    {
        $arqueo = HanaDB::fila("SELECT a.*, " . self::sqlFondo() . " AS FONDO_CALCULADO, " . self::sqlTotal() . " AS TOTAL_ARQUEO, " . self::sqlDiferencia() . " AS DIFERENCIA,
                                  c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.NOM_PROYECTO, col.NOM_COLABORADOR AS ARQUEA
                             FROM arqueo a
                             INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = a.ID_CENTRO_OP
                             INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                             LEFT JOIN colaboradores col ON col.ID_COLABORADOR = a.ID_COLABORADOR_ARQUEA
                            WHERE a.ID_ARQUEO = ?", 'i', array((int)$idArqueo));
        if (!$arqueo) { return null; }
        //Cada línea con su concepto: CODIGO dice cuál es en caja menor y recambio (vacío en casetas);
        //NOMBRE y ROL son los que tenía el concepto el día del arqueo
        $arqueo['LINEAS'] = HanaDB::q("SELECT l.ID_CONCEPTO, c.CODIGO, l.NOMBRE, l.ROL, l.FECHA, l.VALOR, l.OBSERVACION
                                    FROM arqueo_linea l
                                    INNER JOIN arqueo_concepto c ON c.ID_CONCEPTO = l.ID_CONCEPTO
                                   WHERE l.ID_ARQUEO = ? ORDER BY l.ID_ARQUEO_LINEA",
                                  'i', array((int)$idArqueo));
        if (!$arqueo['LINEAS']) { $arqueo['LINEAS'] = array(); }
        //En casetas el fondo es el valor esperado (una línea); en los demás, el guardado
        $arqueo['FONDO_AUTORIZADO'] = $arqueo['FONDO_CALCULADO'];
        unset($arqueo['FONDO_CALCULADO']);
        //Los subtotales del formato: efectivo (billetes + conceptos del recambio) y documentos
        $documentos = 0.0; $otrosEfectivo = 0.0;
        if (isset(self::$EFECTIVO[$arqueo['TIPO']])) {
            foreach ($arqueo['LINEAS'] as $linea) {
                if (isset(self::$DOCUMENTOS[$linea['CODIGO']])) { $documentos += (float)$linea['VALOR']; }
                else { $otrosEfectivo += (float)$linea['VALOR']; }
            }
        }
        //En casetas no hay billetes aparte: lo contado es el total recaudo de sus campos
        $arqueo['TOTAL_EFECTIVO'] = $arqueo['TIPO'] === 'CASETA' ? round((float)$arqueo['TOTAL_ARQUEO'], 2)
                                  : round((float)$arqueo['BILLETES_MONEDAS'] + $otrosEfectivo, 2);
        $arqueo['TOTAL_DOCUMENTOS'] = round($documentos, 2);
        $arqueo['LINEAS'] = self::conEfectivo($arqueo);
        return $arqueo;
    }

    //Las líneas del arqueo con el efectivo (billetes y monedas) en su lugar, en el orden del formato:
    //primero el efectivo y los conceptos del recambio, después los documentos de caja menor.
    //El efectivo (billetes y monedas) no es una línea: viene de arqueo.BILLETES_MONEDAS
    private static function conEfectivo($arqueo)
    {
        if (!isset(self::$EFECTIVO[$arqueo['TIPO']])) { return $arqueo['LINEAS']; } //casetas: sus campos van tal cual
        $porConcepto = array(); $documentos = array();
        foreach ($arqueo['LINEAS'] as $linea) {
            if (isset(self::$DOCUMENTOS[$linea['CODIGO']])) { $documentos[] = $linea; continue; }
            $porConcepto[$linea['CODIGO']] = $linea;
        }
        $lineas = array();
        foreach (self::$EFECTIVO[$arqueo['TIPO']] as $codigo => $nombre) {
            //Un recambio viejo: billetes y monedas juntos, en el lugar de los billetes
            if ($codigo === 'BILLETES' && (float)$arqueo['BILLETES_MONEDAS'] != 0 && !isset($porConcepto['BILLETES'])) {
                $lineas[] = array('ID_CONCEPTO' => null, 'CODIGO' => 'EFECTIVO', 'NOMBRE' => 'Dinero en efectivo (billetes y monedas)', 'ROL' => 'SUMA',
                                  'FECHA' => null, 'VALOR' => round((float)$arqueo['BILLETES_MONEDAS'], 2), 'OBSERVACION' => null);
                continue;
            }
            if ($codigo === 'EFECTIVO') {
                $lineas[] = array('ID_CONCEPTO' => null, 'CODIGO' => 'EFECTIVO', 'NOMBRE' => $nombre, 'ROL' => 'SUMA', 'FECHA' => null,
                                  'VALOR' => round((float)$arqueo['BILLETES_MONEDAS'], 2), 'OBSERVACION' => null);
            } elseif (isset($porConcepto[$codigo])) {
                $lineas[] = $porConcepto[$codigo];
            }
        }
        return array_merge($lineas, $documentos);
    }

    //Los conceptos fijos de un tipo de arqueo (caja menor o recambio), con su código como llave:
    //así cada línea que se guarda apunta a su concepto y lleva el nombre que tiene hoy
    public function conceptosFijos($tipo)
    {
        $conceptos = array();
        foreach ((array)HanaDB::q("SELECT ID_CONCEPTO, CODIGO, NOMBRE, ROL FROM arqueo_concepto
                                    WHERE TIPO_ARQUEO = ? AND CODIGO IS NOT NULL", 's', array($tipo)) as $concepto) {
            $conceptos[$concepto['CODIGO']] = $concepto;
        }
        return $conceptos;
    }

    //Guarda un arqueo nuevo con sus líneas (dentro de una transacción).
    //Las líneas quedan en el orden en que llegan: así se muestran después
    public function insertar($datos, $lineas)
    {
        $ok = HanaDB::q("INSERT INTO arqueo (TIPO, ID_CENTRO_OP, CASETA, FECHA, HORA, HORA_FIN, RESPONSABLE, CARGO_RESPONSABLE,
                                             ID_COLABORADOR_ARQUEA, FONDO_AUTORIZADO, BILLETES_MONEDAS,
                                             OBSERVACION, FEC_REGISTRO, ESTADO)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                        'sissssssiddss',
                        array($datos['tipo'], $datos['centro'], isset($datos['caseta']) ? $datos['caseta'] : null, $datos['fecha'], $datos['hora'], $datos['horaFin'], $datos['responsable'],
                              $datos['cargo'], $datos['colaborador'], $datos['fondo'], $datos['billetes'],
                              $datos['observacion'], $datos['ahora']));
        if (!$ok) { return 0; }
        $id = HanaDB::id();
        foreach ($lineas as $linea) {
            //Cada línea apunta a su concepto y guarda el nombre y el rol que tiene hoy
            if (!HanaDB::q("INSERT INTO arqueo_linea (ID_ARQUEO, ID_CONCEPTO, NOMBRE, ROL, FECHA, VALOR, OBSERVACION)
                            VALUES (?, ?, ?, ?, ?, ?, ?)",
                           'iisssds', array($id, (int)$linea['idConcepto'], $linea['nombre'], $linea['rol'], $linea['fecha'], $linea['valor'], $linea['obs']))) {
                return 0;
            }
        }
        return $id;
    }

    //-----------------------------------------------------------------------
    // Arqueo de casetas: los campos configurables
    //-----------------------------------------------------------------------

    //Los campos que salen en el arqueo de un peaje: los generales (ID_CENTRO_OP vacío,
    //salen en todos los peajes) más los propios del peaje, en su orden
    public function conceptosCaseta($idCentro)
    {
        $filas = HanaDB::q("SELECT c.ID_CONCEPTO, c.NOMBRE, c.ROL, c.ORDEN, c.ID_CENTRO_OP
                          FROM arqueo_concepto c
                         WHERE c.TIPO_ARQUEO = 'CASETA' AND c.ESTADO = 1 AND (c.ID_CENTRO_OP IS NULL OR c.ID_CENTRO_OP = ?)
                         ORDER BY c.ORDEN, c.ID_CONCEPTO", 'i', array((int)$idCentro));
        return $filas ? $filas : array();
    }

    //Para la pantalla de configuración: todos los campos generales y los propios del peaje, activos o no
    public function conceptosConfig($idCentro)
    {
        $filas = HanaDB::q("SELECT c.ID_CONCEPTO, c.NOMBRE, c.ROL, c.ORDEN, c.ESTADO, c.ID_CENTRO_OP
                          FROM arqueo_concepto c
                         WHERE c.TIPO_ARQUEO = 'CASETA' AND (c.ID_CENTRO_OP IS NULL OR c.ID_CENTRO_OP = ?)
                         ORDER BY c.ID_CENTRO_OP IS NOT NULL, c.ORDEN, c.ID_CONCEPTO", 'i', array((int)$idCentro));
        return $filas ? $filas : array();
    }

    //Crea o edita un campo del arqueo de casetas (general o de un peaje).
    //Los conceptos fijos de caja menor y recambio no se tocan desde aquí
    public function guardarConcepto($id, $idCentro, $nombre, $rol, $orden, $estado)
    {
        if ($id > 0) {
            return HanaDB::q("UPDATE arqueo_concepto SET NOMBRE = ?, ROL = ?, ORDEN = ?, ESTADO = ? WHERE ID_CONCEPTO = ? AND TIPO_ARQUEO = 'CASETA'",
                             'ssiii', array($nombre, $rol, (int)$orden, (int)$estado, (int)$id));
        }
        return HanaDB::q("INSERT INTO arqueo_concepto (TIPO_ARQUEO, ID_CENTRO_OP, NOMBRE, ROL, ORDEN, ESTADO) VALUES ('CASETA', ?, ?, ?, ?, ?)",
                         'issii', array($idCentro > 0 ? (int)$idCentro : null, $nombre, $rol, (int)$orden, (int)$estado));
    }

    //Anula un arqueo dejando el motivo (solo si estaba activo)
    public function anular($idArqueo, $motivo)
    {
        return HanaDB::q("UPDATE arqueo SET ESTADO = 0, MOTIVO_ANULACION = ? WHERE ID_ARQUEO = ? AND ESTADO = 1",
                         'si', array($motivo, (int)$idArqueo));
    }
}

Arqueo::nombresDeLaBase(); //los nombres que dejó el administrador
