<?php
/*
  HANA - Archivos adjuntos (tabla adjunto)
  ---------------------------------------------------------------------------
  Las consultas de los adjuntos. Quién puede ver o cambiar cada archivo lo
  decide el controlador (Control/AdjuntoControl.php).
*/
require_once __DIR__ . '/HanaDB.php';

class Adjunto
{
    //El proyecto de una comunicación activa (0 si no existe)
    public function proyectoDeComunicacion($idComunicacion)
    {
        $r = HanaDB::fila("SELECT ID_PROYECTO FROM comunicacion WHERE ID_COMUNICACION = ? AND ESTADO = 1", 'i', array((int)$idComunicacion));
        return $r ? (int)$r['ID_PROYECTO'] : 0;
    }

    //Centro, fecha, proyecto y dueño de una lista de chequeo (o null)
    public function datosLista($idLista)
    {
        return HanaDB::fila("SELECT l.ID_CENTRO_OP_LISTA_CHEQUEO AS CENTRO, l.FEC_REGISTRO_LISTA_CHEQUEO AS FECHA, c.ID_PROYECTO_CENTRO_OP AS PROYECTO,
                                    l.ID_COLABORADOR_LISTA_CHEQUEO AS DUENO
                               FROM lista_chequeo l INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = l.ID_CENTRO_OP_LISTA_CHEQUEO
                              WHERE l.ID_LISTA_CHEQUEO = ?", 'i', array((int)$idLista));
    }

    //Un adjunto activo (o null)
    public function porId($id)
    {
        return HanaDB::fila("SELECT * FROM adjunto WHERE ID_ADJUNTO = ? AND ESTADO = 1", 'i', array((int)$id));
    }

    //Los adjuntos activos de un registro, con quién los subió
    public function listar($modulo, $idRegistro)
    {
        $f = HanaDB::q("SELECT a.ID_ADJUNTO, a.NOMBRE, a.DESCRIPCION, a.TIPO, a.TAMANO, a.FEC_REGISTRO, c.NOM_COLABORADOR AS QUIEN
                          FROM adjunto a LEFT JOIN colaboradores c ON c.ID_COLABORADOR = a.ID_COLABORADOR
                         WHERE a.MODULO = ? AND a.ID_REGISTRO = ? AND a.ESTADO = 1 ORDER BY a.ID_ADJUNTO", 'si', array((string)$modulo, (int)$idRegistro));
        return $f ? $f : array();
    }

    //Registra un archivo subido (el archivo ya quedó guardado en la carpeta del servidor)
    public function guardar($modulo, $idRegistro, $nombre, $ruta, $tipo, $tamano, $idColaborador)
    {
        return HanaDB::q("INSERT INTO adjunto (MODULO, ID_REGISTRO, NOMBRE, ARCHIVO, TIPO, TAMANO, ID_COLABORADOR, FEC_REGISTRO, ESTADO)
                          VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 1)", 'sisssii',
                         array((string)$modulo, (int)$idRegistro, $nombre, $ruta, $tipo, (int)$tamano, (int)$idColaborador));
    }

    //Cambia la descripción de un adjunto
    public function describir($id, $descripcion)
    {
        return HanaDB::q("UPDATE adjunto SET DESCRIPCION = ?, FEC_MODIFICACION = NOW() WHERE ID_ADJUNTO = ?", 'si', array($descripcion, (int)$id));
    }

    //Se deja con ESTADO 0 (se puede recuperar si hace falta)
    public function quitar($id)
    {
        return HanaDB::q("UPDATE adjunto SET ESTADO = 0, FEC_MODIFICACION = NOW() WHERE ID_ADJUNTO = ?", 'i', array((int)$id));
    }
}
