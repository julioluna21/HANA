<?php
/*
  HANA - Parámetros del sistema: catálogos de ausentismo y días habilitados
  ---------------------------------------------------------------------------
  Las consultas de Control/ParametrosControl.php (permiso 24M).
  Los parámetros en sí están en HanaConfig.php.
*/
require_once __DIR__ . '/HanaDB.php';

class Parametros
{
    //-----------------------------------------------------------------------
    // Catálogos de ausentismo (cargos y novedades)
    //-----------------------------------------------------------------------
    public function cargos()
    {
        $filas = HanaDB::q("SELECT * FROM aus_cargo ORDER BY ORDEN, NOMBRE");
        return $filas ? $filas : array();
    }

    //Novedades de ausentismo con el cargo al que pertenecen
    public function novedades()
    {
        $filas = HanaDB::q("SELECT n.*, c.NOMBRE AS CARGO FROM aus_novedad n LEFT JOIN aus_cargo c ON c.ID_CARGO_AUS = n.ID_CARGO_AUS ORDER BY n.ORDEN, n.NOMBRE");
        return $filas ? $filas : array();
    }

    //¿Ya hay otro cargo (o novedad) con ese nombre?
    public function nombreRepetido($esCargo, $nombre, $id)
    {
        $sql = $esCargo ? "SELECT ID_CARGO_AUS AS ID FROM aus_cargo WHERE NOMBRE = ? AND ID_CARGO_AUS <> ?"
                        : "SELECT ID_NOVEDAD_AUS AS ID FROM aus_novedad WHERE NOMBRE = ? AND ID_NOVEDAD_AUS <> ?";
        return (bool)HanaDB::fila($sql, 'si', array((string)$nombre, (int)$id));
    }

    //Crea ($id = 0) o actualiza un cargo
    public function guardarCargo($id, $nombre, $aplica, $orden, $estado)
    {
        return $id ? HanaDB::q("UPDATE aus_cargo SET NOMBRE = ?, APLICA = ?, ORDEN = ?, ESTADO = ? WHERE ID_CARGO_AUS = ?", 'ssiii', array($nombre, $aplica, $orden, $estado, $id))
                   : HanaDB::q("INSERT INTO aus_cargo (NOMBRE, APLICA, ORDEN, ESTADO) VALUES (?, ?, ?, ?)", 'ssii', array($nombre, $aplica, $orden, $estado));
    }

    //Crea ($id = 0) o actualiza una novedad
    public function guardarNovedad($id, $nombre, $cuenta, $cargo, $orden, $estado)
    {
        return $id ? HanaDB::q("UPDATE aus_novedad SET NOMBRE = ?, CUENTA_AUSENCIA = ?, ID_CARGO_AUS = ?, ORDEN = ?, ESTADO = ? WHERE ID_NOVEDAD_AUS = ?", 'siiiii', array($nombre, $cuenta, $cargo, $orden, $estado, $id))
                   : HanaDB::q("INSERT INTO aus_novedad (NOMBRE, CUENTA_AUSENCIA, ID_CARGO_AUS, ORDEN, ESTADO) VALUES (?, ?, ?, ?, ?)", 'siiii', array($nombre, $cuenta, $cargo, $orden, $estado));
    }

    //-----------------------------------------------------------------------
    // Días habilitados (tabla dia_habilitado)
    //-----------------------------------------------------------------------
    //Arriba los que siguen abiertos; debajo los ya cerrados. false si falta la tabla
    public function diasHabilitados()
    {
        return HanaDB::q("SELECT d.ID_DIA_HABILITADO, d.FECHA, d.MOTIVO, d.ESTADO, d.FEC_HABILITA, d.FEC_DESHABILITA,
                                 c.NOM_COLABORADOR AS PERSONA, h.NOM_COLABORADOR AS HABILITO, x.NOM_COLABORADOR AS DESHABILITO
                            FROM dia_habilitado d
                            INNER JOIN colaboradores c ON c.ID_COLABORADOR = d.ID_COLABORADOR
                            LEFT  JOIN colaboradores h ON h.ID_COLABORADOR = d.ID_COLABORADOR_HABILITA
                            LEFT  JOIN colaboradores x ON x.ID_COLABORADOR = d.ID_COLABORADOR_DESHABILITA
                           ORDER BY d.ESTADO DESC, d.FECHA DESC, d.ID_DIA_HABILITADO DESC
                           LIMIT 200");
    }

    //El estado (1 abierto, 0 cerrado) de ese día para esa persona, o null si nunca se abrió
    public function estadoDia($idColaborador, $fecha)
    {
        $fila = HanaDB::fila("SELECT ESTADO FROM dia_habilitado WHERE ID_COLABORADOR = ? AND FECHA = ?", 'is', array((int)$idColaborador, $fecha));
        return $fila ? (int)$fila['ESTADO'] : null;
    }

    //El estado de un día habilitado por su id, o null si no existe
    public function estadoDiaPorId($id)
    {
        $fila = HanaDB::fila("SELECT ESTADO FROM dia_habilitado WHERE ID_DIA_HABILITADO = ?", 'i', array((int)$id));
        return $fila ? (int)$fila['ESTADO'] : null;
    }

    //Abre un día. Si ya se le había abierto y cerrado, se vuelve a abrir la misma fila
    public function habilitarDia($idColaborador, $fecha, $motivo, $quien, $ahora)
    {
        return HanaDB::q("INSERT INTO dia_habilitado (ID_COLABORADOR, FECHA, MOTIVO, ESTADO, ID_COLABORADOR_HABILITA, FEC_HABILITA)
                          VALUES (?, ?, ?, 1, ?, ?)
                          ON DUPLICATE KEY UPDATE ESTADO = 1, MOTIVO = VALUES(MOTIVO),
                              ID_COLABORADOR_HABILITA = VALUES(ID_COLABORADOR_HABILITA), FEC_HABILITA = VALUES(FEC_HABILITA),
                              ID_COLABORADOR_DESHABILITA = NULL, FEC_DESHABILITA = NULL",
                         'issis', array((int)$idColaborador, $fecha, $motivo, (int)$quien, $ahora));
    }

    //Cierra un día. La fila se conserva con quién y cuándo lo cerró
    public function deshabilitarDia($id, $quien, $ahora)
    {
        return HanaDB::q("UPDATE dia_habilitado SET ESTADO = 0, ID_COLABORADOR_DESHABILITA = ?, FEC_DESHABILITA = ? WHERE ID_DIA_HABILITADO = ?",
                         'isi', array((int)$quien, $ahora, (int)$id));
    }

    //-----------------------------------------------------------------------
    // Listas de opciones (HanaConfig::LISTAS) y nombres de los conceptos de arqueo
    //-----------------------------------------------------------------------
    //Cuántos registros usan cada código de una lista: [CODIGO => n]. $columna = 'tabla.COLUMNA'
    //de HanaConfig::LISTAS (fija en el código, nunca viene de la pantalla)
    public function usosLista($columna)
    {
        list($tabla, $campo) = explode('.', $columna);
        if (!preg_match('/^[a-z_]+$/', $tabla) || !preg_match('/^[A-Z_]+$/', $campo)) { return array(); }
        $filas = HanaDB::q("SELECT `$campo` AS CODIGO, COUNT(*) AS N FROM `$tabla` WHERE `$campo` IS NOT NULL GROUP BY `$campo`");
        $usos = array();
        foreach (is_array($filas) ? $filas : array() as $fila) { $usos[(string)$fila['CODIGO']] = (int)$fila['N']; }
        return $usos;
    }

    //Los conceptos fijos del arqueo de caja menor y de recambio (los de casetas se configuran en Arqueos → Campos)
    public function conceptosArqueo()
    {
        $filas = HanaDB::q("SELECT ID_CONCEPTO, TIPO_ARQUEO, CODIGO, NOMBRE, ORDEN FROM arqueo_concepto
                             WHERE CODIGO IS NOT NULL AND TIPO_ARQUEO IN ('CAJA_MENOR', 'RECAMBIO')
                             ORDER BY TIPO_ARQUEO = 'RECAMBIO', ORDEN, ID_CONCEPTO");
        return is_array($filas) ? $filas : array();
    }

    //Cambia el nombre de un concepto fijo de caja menor o recambio
    public function nombrarConcepto($id, $nombre)
    {
        return HanaDB::q("UPDATE arqueo_concepto SET NOMBRE = ? WHERE ID_CONCEPTO = ? AND CODIGO IS NOT NULL AND TIPO_ARQUEO IN ('CAJA_MENOR', 'RECAMBIO')",
                         'si', array($nombre, (int)$id));
    }
}
