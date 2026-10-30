<?php
/*
  HANA - Archivos adjuntos (tabla adjunto)
  ---------------------------------------------------------------------------
  Las consultas de los adjuntos. Quién puede ver o cambiar cada archivo lo
  decide el controlador (Control/AdjuntoControl.php).
  El archivo va dentro de la base, en ARCHIVO, como texto base64
  ("data:tipo;base64,..."). Por eso los listados no traen esa columna: solo se
  lee cuando alguien abre el archivo (contenido()).
*/
require_once __DIR__ . '/HanaDB.php';

class Adjunto
{
    //El proyecto de una comunicación activa (0 si no existe)
    public function proyectoDeComunicacion($idComunicacion)
    {
        $fila = HanaDB::fila("SELECT ID_PROYECTO FROM comunicacion WHERE ID_COMUNICACION = ? AND ESTADO = 1 AND TIPO <> 'TEMA_CLIENTE'", 'i', array((int)$idComunicacion));
        return $fila ? (int)$fila['ID_PROYECTO'] : 0;
    }

    //El peaje de una RQ (0 si no existe)
    public function centroDeRq($idRq)
    {
        $fila = HanaDB::fila("SELECT ID_CENTRO_OP FROM rq WHERE ID_RQ = ?", 'i', array((int)$idRq));
        return $fila ? (int)$fila['ID_CENTRO_OP'] : 0;
    }

    //Centro, fecha, proyecto y dueño de una lista de chequeo (o null)
    public function datosLista($idLista)
    {
        return HanaDB::fila("SELECT l.ID_CENTRO_OP AS CENTRO, l.FEC_REGISTRO AS FECHA, c.ID_PROYECTO AS PROYECTO,
                                    l.ID_COLABORADOR AS DUENO
                               FROM lista_chequeo l INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = l.ID_CENTRO_OP
                              WHERE l.ID_LISTA_CHEQUEO = ?", 'i', array((int)$idLista));
    }

    //Los datos de un adjunto activo, sin el archivo (o null)
    public function porId($id)
    {
        return HanaDB::fila("SELECT ID_ADJUNTO, MODULO, ID_REGISTRO, NOMBRE, DESCRIPCION, TIPO, TAMANO, ID_COLABORADOR, FEC_REGISTRO
                               FROM adjunto WHERE ID_ADJUNTO = ? AND ESTADO = 1", 'i', array((int)$id));
    }

    //El archivo de un adjunto: el texto base64 guardado
    public function contenido($id)
    {
        $fila = HanaDB::fila("SELECT ARCHIVO FROM adjunto WHERE ID_ADJUNTO = ? AND ESTADO = 1", 'i', array((int)$id));
        return $fila ? (string)$fila['ARCHIVO'] : '';
    }

    //Los adjuntos activos de un registro, con quién los subió
    public function listar($modulo, $idRegistro)
    {
        $filas = HanaDB::q("SELECT a.ID_ADJUNTO, a.NOMBRE, a.DESCRIPCION, a.TIPO, a.TAMANO, a.FEC_REGISTRO, c.NOM_COLABORADOR AS QUIEN
                          FROM adjunto a LEFT JOIN colaboradores c ON c.ID_COLABORADOR = a.ID_COLABORADOR
                         WHERE a.MODULO = ? AND a.ID_REGISTRO = ? AND a.ESTADO = 1 ORDER BY a.ID_ADJUNTO", 'si', array((string)$modulo, (int)$idRegistro));
        return $filas ? $filas : array();
    }

    //Guarda un archivo subido: $texto es el archivo completo en base64 ("data:tipo;base64,...")
    public function guardar($modulo, $idRegistro, $nombre, $texto, $tipo, $tamano, $idColaborador)
    {
        return HanaDB::q("INSERT INTO adjunto (MODULO, ID_REGISTRO, NOMBRE, ARCHIVO, TIPO, TAMANO, ID_COLABORADOR, FEC_REGISTRO, ESTADO)
                          VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 1)", 'sisssii',
                         array((string)$modulo, (int)$idRegistro, $nombre, $texto, $tipo, (int)$tamano, (int)$idColaborador));
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
