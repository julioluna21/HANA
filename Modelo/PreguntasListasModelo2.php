<?php
/*
  HANA - Preguntas de las listas de chequeo (versión del sistema original, sin uso)
  ---------------------------------------------------------------------------
  Trabaja con los nombres de tabla y columna de la base original
  (detalle_grupo_lista_cheque...), que ya no existen. La versión en uso es
  Modelo/PreguntasListasModelo.php. Ningún archivo lo carga; se conserva solo
  como referencia.
*/
require "../Conexion/ConexionDB.php";
class preguntas{
     //Constructor vacío: la clase no guarda datos propios
     public function __construct()
     {
     }
     //Guarda en la lista cuántas preguntas tiene
     public function insertarCantPreguntas($idLista,$numPreguntas){
        $sql = "UPDATE grupo_lista_chequeo SET CANT_PREGUNTAS_GRUPO='$numPreguntas' where ID_GRUPO_LISTA_CHEQUEO='$idLista'";
        return ejecutarConsulta($sql);
     }
     //Cuenta las preguntas activas de una lista
     public function consCantPreguntas($idLista){
        $sql="SELECT count(detalle_grupo_lista_cheque.ID_DETALLE_GRUPO_LISTA_CHEQUEO) AS cantPreguntas FROM detalle_grupo_lista_cheque WHERE detalle_grupo_lista_cheque.ESTADO = 1 AND detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO='$idLista'";
        return ejecutarConsulta($sql);
     }
     //Crea una pregunta en una lista
     public function insertar($nombreLista,$preguntas,$tiposRespuesta)
     {
             $sql = "INSERT INTO detalle_grupo_lista_cheque(ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO,PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO,TIPO_RESPUESTA,ESTADO)
                         VALUES ('$nombreLista','$preguntas','$tiposRespuesta','1')";
             return ejecutarConsulta($sql); //ejecutarConsulta está en Conexion/ConexionDB.php
     }
     //Cambia el texto y el tipo de respuesta de una pregunta
     public function editar($id, $preguntas,$tiposRespuesta)
     {
             $sql = "UPDATE detalle_grupo_lista_cheque SET PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO='$preguntas',TIPO_RESPUESTA='$tiposRespuesta', ESTADO='1' where 
                 ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Una pregunta, para cargarla en el formulario
     public function mostrar($id)
     {
             $sql = "SELECT * FROM detalle_grupo_lista_cheque WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO='$id'";
             return ejecutarConsultaSimpleFila($sql);
     }
     //Las preguntas de una lista
     public function listar($idGrupoPreguntas)
     {
             $sql = "SELECT detalle_grupo_lista_cheque.* ,grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS grupo FROM `detalle_grupo_lista_cheque` INNER JOIN grupo_lista_chequeo ON detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE `ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO`=$idGrupoPreguntas";
             return ejecutarConsulta($sql);
     }
     //Desactiva una pregunta (no se borra)
     public function anular($id)
     {
             $sql = "UPDATE detalle_grupo_lista_cheque SET ESTADO=0 WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Preguntas activas para el buscador; si se escribió algo, filtra por el texto
     public function select($busqueda)
     {
             if ($busqueda === 0) {
                     $sql = "SELECT * FROM detalle_grupo_lista_cheque WHERE ESTADO= '1' ORDER BY PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO ASC";
                     return ejecutarConsulta($sql);
             } else {
                     $sql = "SELECT * FROM detalle_grupo_lista_cheque WHERE PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO LIKE '%".$busqueda."%' AND ESTADO= '1' ORDER BY PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO ASC";
                     return ejecutarConsulta($sql);
             }
             
     }
}
