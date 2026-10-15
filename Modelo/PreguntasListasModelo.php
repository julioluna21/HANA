<?php
/*
  HANA - Preguntas de las listas de chequeo (modelo)
  ---------------------------------------------------------------------------
  Consultas de la tabla detalle_grupo_lista_chequeo: el texto, el tipo de respuesta,
  el orden y si la pregunta genera novedad. Lo usa Control/PreguntasListasControl.php.
*/
require "../Conexion/ConexionDB.php";
class preguntas{
     //Implementamos el super constructor 
     public function __construct()
     {
     }
     //Una pregunta genera novedad cuando tiene título de novedad (TITULO_NOVEDAD apunta a
     //titulo_novedades_hallazgos). Si no genera, el título queda NULL
     private function tituloSql($genera, $titulo)
     {
        $t = intval($titulo);
        return ((string)$genera === '1' && $t > 0) ? (string)$t : 'NULL';
     }
     //Las columnas de la pregunta más GENERA_NOVEDAD (1/0), que se deduce del título
     const SQL_CAMPOS = "detalle_grupo_lista_chequeo.*, (detalle_grupo_lista_chequeo.TITULO_NOVEDAD IS NOT NULL) AS GENERA_NOVEDAD";
     //Implementamos un método para insertar registros
     public function insertar($nombreLista,$preguntas,$tiposRespuesta,$genera,$titulo,$orden)
     {
             $sql1 = "INSERT INTO detalle_grupo_lista_chequeo(ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO,PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO,TIPO_RESPUESTA,TITULO_NOVEDAD,ESTADO,ORDEN)
                         VALUES ('$nombreLista','$preguntas','$tiposRespuesta'," . $this->tituloSql($genera, $titulo) . ",'1','$orden')";
             return ejecutarConsulta($sql1); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
     }
     //ORDEN de una pregunta puntual
     public function ordenDe($idPregunta){
        $idPregunta = intval($idPregunta); //solo números en la consulta
        $sql = "SELECT ORDEN FROM detalle_grupo_lista_chequeo WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$idPregunta";
        return ejecutarConsultaSimpleFila($sql);
     }
     //ORDEN maximo dentro de un grupo (para insertar al final)
     public function ordenMax($idGrupo){
        $sql = "SELECT COALESCE(MAX(ORDEN),0) AS m FROM detalle_grupo_lista_chequeo WHERE ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO='$idGrupo'";
        return ejecutarConsultaSimpleFila($sql);
     }
     //Corre el ORDEN +1 desde una posicion (para insertar en medio)
     public function correrOrden($idGrupo,$desde){
        $sql = "UPDATE detalle_grupo_lista_chequeo SET ORDEN=ORDEN+1 WHERE ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO='$idGrupo' AND ORDEN >= $desde";
        return ejecutarConsulta($sql);
     }
     //Fija el ORDEN exacto de una pregunta (reordenamiento por arrastre)
     public function setOrden($id,$orden){
        $id = intval($id); $orden = intval($orden); //solo números en la consulta
        $sql = "UPDATE detalle_grupo_lista_chequeo SET ORDEN=$orden WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
        return ejecutarConsulta($sql);
     }
     //Implementamos un método para editar registros
     public function editar($id, $preguntas,$tiposRespuesta,$genera,$titulo)
     {
             $id = intval($id); //solo números en la consulta
             $sql = "UPDATE detalle_grupo_lista_chequeo SET PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO='$preguntas',TIPO_RESPUESTA='$tiposRespuesta', ESTADO='1', TITULO_NOVEDAD=" . $this->tituloSql($genera, $titulo) . " where 
                 ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Implementar un método para mostrar los datos de un registro a modificar
     public function mostrar($id)
     {
             $sql = "SELECT " . self::SQL_CAMPOS . " FROM detalle_grupo_lista_chequeo WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO='$id'";
             return ejecutarConsultaSimpleFila($sql);
     }
     //Implementar un método para listar los registros
     public function listar($idGrupoPreguntas)
     {
             $idGrupoPreguntas = intval($idGrupoPreguntas); //solo números en la consulta
             $sql = "SELECT " . self::SQL_CAMPOS . ", grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS grupo FROM `detalle_grupo_lista_chequeo` INNER JOIN grupo_lista_chequeo ON detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE `ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO`=$idGrupoPreguntas ORDER BY detalle_grupo_lista_chequeo.ORDEN";
             return ejecutarConsulta($sql);
     }
     //Implementamos un método para editar reasignar contraseña
     public function anular($id)
     {
             $id = intval($id); //solo números en la consulta
             $sql = "UPDATE detalle_grupo_lista_chequeo SET ESTADO=0 WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Preguntas activas para el buscador; si se escribió algo, filtra por el texto
     public function select($search_term)
     {
             if ($search_term !== 0) { $search_term = limpiarCadena((string)$search_term); } //el texto de búsqueda va escapado
             if ($search_term === 0) {
                     $sql = "SELECT " . self::SQL_CAMPOS . " FROM detalle_grupo_lista_chequeo WHERE ESTADO= '1' ORDER BY PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO ASC";
                     return ejecutarConsulta($sql);
             } else {
                     $sql = "SELECT " . self::SQL_CAMPOS . " FROM detalle_grupo_lista_chequeo WHERE PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO LIKE '%".$search_term."%' AND ESTADO= '1' ORDER BY PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO ASC";
                     return ejecutarConsulta($sql);
             }
             
     }
}
