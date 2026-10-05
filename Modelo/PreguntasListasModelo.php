<?php
require "../Conexion/ConexionDB.php";
class preguntas{
     //Implementamos el super constructor 
     public function __construct()
     {
     }
     public function insertarCantPreguntas($idLista,$numPreguntas){
        $sql = "UPDATE grupo_lista_chequeo SET CANT_PREGUNTAS_GRUPO='$numPreguntas' where ID_GRUPO_LISTA_CHEQUEO='$idLista'";
        return ejecutarConsulta($sql);
     }
     public function consCantPreguntas($idLista){
        $sql="SELECT count(detalle_grupo_lista_cheque.ID_DETALLE_GRUPO_LISTA_CHEQUEO) AS cantPreguntas FROM detalle_grupo_lista_cheque WHERE detalle_grupo_lista_cheque.ESTADO = 1 AND detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO='$idLista'";
        return ejecutarConsulta($sql);
     }
     //Implementamos un método para insertar registros
     public function insertar($nombreLista,$preguntas,$tiposRespuesta,$genera,$titulo,$orden)
     {
             $sql1 = "INSERT INTO detalle_grupo_lista_cheque(ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO,PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO,TIPO_RESPUESTA,GENERA_NOVEDAD,TITULO_NOVEDAD,ESTADO,ORDEN)
                         VALUES ('$nombreLista','$preguntas','$tiposRespuesta','$genera','$titulo','1','$orden')";
             return ejecutarConsulta($sql1); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
     }
     //ORDEN de una pregunta puntual
     public function ordenDe($idPregunta){
        $sql = "SELECT ORDEN FROM detalle_grupo_lista_cheque WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$idPregunta";
        return ejecutarConsultaSimpleFila($sql);
     }
     //ORDEN maximo dentro de un grupo (para insertar al final)
     public function ordenMax($idGrupo){
        $sql = "SELECT COALESCE(MAX(ORDEN),0) AS m FROM detalle_grupo_lista_cheque WHERE ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO='$idGrupo'";
        return ejecutarConsultaSimpleFila($sql);
     }
     //Corre el ORDEN +1 desde una posicion (para insertar en medio)
     public function correrOrden($idGrupo,$desde){
        $sql = "UPDATE detalle_grupo_lista_cheque SET ORDEN=ORDEN+1 WHERE ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO='$idGrupo' AND ORDEN >= $desde";
        return ejecutarConsulta($sql);
     }
     //Fija el ORDEN exacto de una pregunta (reordenamiento por arrastre)
     public function setOrden($id,$orden){
        $sql = "UPDATE detalle_grupo_lista_cheque SET ORDEN=$orden WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
        return ejecutarConsulta($sql);
     }
     //Implementamos un método para editar registros
     public function editar($id, $preguntas,$tiposRespuesta,$genera,$titulo)
     {
             $sql = "UPDATE detalle_grupo_lista_cheque SET PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO='$preguntas',TIPO_RESPUESTA='$tiposRespuesta', ESTADO='1',GENERA_NOVEDAD='$genera', TITULO_NOVEDAD='$titulo' where 
                 ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Implementar un método para mostrar los datos de un registro a modificar
     public function mostrar($id)
     {
             $sql = "SELECT * FROM detalle_grupo_lista_cheque WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO='$id'";
             return ejecutarConsultaSimpleFila($sql);
     }
     //Implementar un método para listar los registros
     public function listar($idGrupoPreguntas)
     {
             $sql = "SELECT detalle_grupo_lista_cheque.* ,grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS grupo FROM `detalle_grupo_lista_cheque` INNER JOIN grupo_lista_chequeo ON detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE `ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO`=$idGrupoPreguntas ORDER BY detalle_grupo_lista_cheque.ORDEN";
             return ejecutarConsulta($sql);
     }
     //Implementamos un método para editar reasignar contraseña
     public function anular($id)
     {
             $sql = "UPDATE detalle_grupo_lista_cheque SET ESTADO=0 WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     public function select($search_term)
     {
             if ($search_term === 0) {
                     $sql = "SELECT * FROM detalle_grupo_lista_cheque WHERE ESTADO= '1' ORDER BY PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO ASC";
                     return ejecutarConsulta($sql);
             } else {
                     $sql = "SELECT * FROM detalle_grupo_lista_cheque WHERE PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO LIKE '%".$search_term."%' AND ESTADO= '1' ORDER BY PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO ASC";
                     return ejecutarConsulta($sql);
             }
             
     }
}
