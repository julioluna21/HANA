<?php
/*
  HANA - Preguntas de las listas de chequeo (modelo)
  ---------------------------------------------------------------------------
  Consultas de la tabla detalle_grupo_lista_chequeo: el texto, el tipo de respuesta
  y el orden. Lo usa Control/PreguntasListasControl.php.
*/
require "../Conexion/ConexionDB.php";
class preguntas{
     //Constructor vacío: la clase no guarda datos propios
     public function __construct()
     {
     }
     //Las columnas de la pregunta
     const SQL_CAMPOS = "detalle_grupo_lista_chequeo.*";
     //Agrega una pregunta a un tipo de lista, con su tipo de respuesta y su orden. Devuelve su número
     public function insertar($idGrupo,$pregunta,$tipoRespuesta,$orden)
     {
             $sql = "INSERT INTO detalle_grupo_lista_chequeo(ID_GRUPO_LISTA_CHEQUEO,PREGUNTA,TIPO_RESPUESTA,ESTADO,ORDEN)
                         VALUES ('$idGrupo','$pregunta','$tipoRespuesta','1','$orden')";
             return ejecutarConsulta($sql); //ejecutarConsulta está en Conexion/ConexionDB.php
     }
     //ORDEN de una pregunta puntual
     public function ordenDe($idPregunta){
        $idPregunta = intval($idPregunta); //solo números en la consulta
        $sql = "SELECT ORDEN FROM detalle_grupo_lista_chequeo WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$idPregunta";
        return ejecutarConsultaSimpleFila($sql);
     }
     //ORDEN máximo dentro de un tipo de lista (para agregar al final)
     public function ordenMax($idGrupo){
        $sql = "SELECT COALESCE(MAX(ORDEN),0) AS ORDEN_MAYOR FROM detalle_grupo_lista_chequeo WHERE ID_GRUPO_LISTA_CHEQUEO='$idGrupo'";
        return ejecutarConsultaSimpleFila($sql);
     }
     //Corre el ORDEN +1 desde una posición (para agregar una pregunta en medio)
     public function correrOrden($idGrupo,$desde){
        $sql = "UPDATE detalle_grupo_lista_chequeo SET ORDEN=ORDEN+1 WHERE ID_GRUPO_LISTA_CHEQUEO='$idGrupo' AND ORDEN >= $desde";
        return ejecutarConsulta($sql);
     }
     //Fija el ORDEN exacto de una pregunta (reordenamiento por arrastre)
     public function setOrden($id,$orden){
        $id = intval($id); $orden = intval($orden); //solo números en la consulta
        $sql = "UPDATE detalle_grupo_lista_chequeo SET ORDEN=$orden WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
        return ejecutarConsulta($sql);
     }
     //Cambia el texto y el tipo de respuesta de una pregunta
     public function editar($id, $pregunta,$tipoRespuesta)
     {
             $id = intval($id); //solo números en la consulta
             $sql = "UPDATE detalle_grupo_lista_chequeo SET PREGUNTA='$pregunta',TIPO_RESPUESTA='$tipoRespuesta', ESTADO='1' where 
                 ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Una pregunta, para cargarla en el formulario
     public function mostrar($id)
     {
             $sql = "SELECT " . self::SQL_CAMPOS . " FROM detalle_grupo_lista_chequeo WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO='$id'";
             return ejecutarConsultaSimpleFila($sql);
     }
     //Las preguntas de un tipo de lista, en su orden
     public function listar($idGrupoPreguntas)
     {
             $idGrupoPreguntas = intval($idGrupoPreguntas); //solo números en la consulta
             $sql = "SELECT " . self::SQL_CAMPOS . ", grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS grupo FROM `detalle_grupo_lista_chequeo` INNER JOIN grupo_lista_chequeo ON detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=$idGrupoPreguntas ORDER BY detalle_grupo_lista_chequeo.ORDEN";
             return ejecutarConsulta($sql);
     }
     //Desactiva una pregunta (no se borra: las listas viejas la siguen mostrando)
     public function anular($id)
     {
             $id = intval($id); //solo números en la consulta
             $sql = "UPDATE detalle_grupo_lista_chequeo SET ESTADO=0 WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Preguntas activas para el buscador; si se escribió algo, filtra por el texto
     public function select($busqueda)
     {
             if ($busqueda !== 0) { $busqueda = limpiarCadena((string)$busqueda); } //el texto de búsqueda va escapado
             if ($busqueda === 0) {
                     $sql = "SELECT " . self::SQL_CAMPOS . " FROM detalle_grupo_lista_chequeo WHERE ESTADO= '1' ORDER BY PREGUNTA ASC";
                     return ejecutarConsulta($sql);
             } else {
                     $sql = "SELECT " . self::SQL_CAMPOS . " FROM detalle_grupo_lista_chequeo WHERE PREGUNTA LIKE '%".$busqueda."%' AND ESTADO= '1' ORDER BY PREGUNTA ASC";
                     return ejecutarConsulta($sql);
             }
             
     }
}
