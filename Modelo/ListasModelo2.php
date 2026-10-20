<?php
/*
  HANA - Listas de chequeo diligenciadas (versión del sistema original, sin uso)
  ---------------------------------------------------------------------------
  Trabaja con los nombres de tabla y columna de la base original
  (detale_lista_chequeo, detalle_grupo_lista_cheque...), que ya no existen. La
  versión en uso es Modelo/ListasModelo.php. Ningún archivo lo carga; se
  conserva solo como referencia.
*/
require "../Conexion/ConexionDB.php";
class listas{
     //Constructor vacío: la clase no guarda datos propios
     public function __construct()
     {
     }
     //Cambia una respuesta y quién la corrigió
     public function editarUnaRespuesta($respuesta, $colaborador, $idRespuesta){
        $sql="UPDATE `detale_lista_chequeo` SET `RESPUTA_DETALLE_LISTA_CHEQUEO`='$respuesta',`ID_COLABORADOR_DETALE_LISTA_CHEQUEO`='$colaborador' WHERE `ID_DETALE_LISTA_CHEQUEO`='$idRespuesta'";
        return ejecutarConsulta($sql);
     }
     //Agrega una respuesta a una lista
     public function insertarUnaRespuesta($idLista, $idPregunta, $respuesta, $colaborador){
        $sql="INSERT INTO `detale_lista_chequeo`(`ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`, `ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`, `RESPUTA_DETALLE_LISTA_CHEQUEO`, `ID_COLABORADOR_DETALE_LISTA_CHEQUEO`) VALUES ('$idLista','$idPregunta','$respuesta','$colaborador')";
        return ejecutarConsulta($sql);
     }
     //¿Ya hay una lista de este tipo empezada para ese peaje en esa fecha?
     public function validaListaIniciada($idGrupo,$fechaEncuesta,$idCentro){
        $sql="SELECT lista_chequeo.ID_LISTA_CHEQUEO FROM lista_chequeo WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idGrupo' AND lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO LIKE '$fechaEncuesta%' AND lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO='$idCentro'";
        return ejecutarConsultaSimpleFila($sql);
        }
     //Los tipos de lista activos
     public function listar()
     {
             $sql = "SELECT * FROM grupo_lista_chequeo WHERE ESTADO = 1";
             return ejecutarConsulta($sql);
     }
     //Fechas en que la persona ya respondió ese tipo de lista
     public function validarRespuesta($fechaEncuesta, $idGrupoPreguntas, $nombreColaborador){
        $sql = "SELECT DISTINCT lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO FROM lista_chequeo INNER JOIN detale_lista_chequeo ON detale_lista_chequeo.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO = lista_chequeo.ID_LISTA_CHEQUEO JOIN detalle_grupo_lista_cheque ON detale_lista_chequeo.ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO=detalle_grupo_lista_cheque.ID_DETALLE_GRUPO_LISTA_CHEQUEO WHERE lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO LIKE '$fechaEncuesta%' AND lista_chequeo.ID_COLABORADOR_LISTA_CHEQUEO= '$nombreColaborador' AND detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO ='$idGrupoPreguntas'";
        return ejecutarConsulta($sql);
     }
     //Crea el encabezado de una lista y devuelve su número
     public function insertarLista($selectCentro,$fechaEncuesta,$nombreColaborador,$idGrupo){
        $sql = "INSERT INTO `lista_chequeo`(`ID_CENTRO_OP_LISTA_CHEQUEO`, `FEC_REGISTRO_LISTA_CHEQUEO`, `ID_COLABORADOR_LISTA_CHEQUEO`, `ID_GRUPO_LISTA_CHEQUEO`, `CANT_RESPUESTAS_LISTA`) VALUES ('$selectCentro','$fechaEncuesta','$nombreColaborador','$idGrupo',0)";
        return ejecutarConsulta_retornarID($sql);
     }
     //Cambia el peaje, la fecha y la persona de una lista
     public function editarLista($idLista,$selectCentro, $fechaEncuesta, $nombreColaborador)
     {
             $sql = "UPDATE `lista_chequeo` SET `ID_CENTRO_OP_LISTA_CHEQUEO`='$selectCentro',`FEC_REGISTRO_LISTA_CHEQUEO`='$fechaEncuesta',`ID_COLABORADOR_LISTA_CHEQUEO`='$nombreColaborador' WHERE `ID_LISTA_CHEQUEO`='$idLista',";
             return ejecutarConsulta($sql);
     }
    //Los peajes asignados a un usuario
     public function centrosOP($idUsuario)
   {
           $sql = "SELECT asoc_usuarios_sistemas_x_cop.* FROM asoc_usuarios_sistemas_x_cop WHERE asoc_usuarios_sistemas_x_cop.ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP=$idUsuario";
           return ejecutarConsulta($sql);
   }

     //Cuántas preguntas tiene la lista y cuántas se han respondido
     public function contarRespuestas($idGrupo, $idLista){
        $sql = "SELECT grupo_lista_chequeo.CANT_PREGUNTAS_GRUPO AS npreguntas, lista_chequeo.CANT_RESPUESTAS_LISTA AS nrespuestas FROM grupo_lista_chequeo INNER JOIN lista_chequeo ON lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idGrupo' AND lista_chequeo.ID_LISTA_CHEQUEO = $idLista";
        return ejecutarConsulta($sql);
     }
     //Cambia el texto y el tipo de respuesta de una pregunta
     public function editarPreguntas($id, $preguntas,$tiposRespuesta)
     {
             $sql = "UPDATE detalle_grupo_lista_cheque SET PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO='$preguntas',TIPO_RESPUESTA='$tiposRespuesta', ESTADO='1' where 
                 ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Cuántas respuestas tiene una lista
     public function getCantRespuestas($idLista){
        $sql = "SELECT lista_chequeo.CANT_RESPUESTAS_LISTA FROM lista_chequeo WHERE lista_chequeo.ID_LISTA_CHEQUEO = '$idLista'";
        return ejecutarConsultaSimpleFila($sql);
     }
     //Guarda cuántas respuestas tiene una lista
     public function insertarCantRespuestas($idLista, $setCantRespuestas){
        $sql = "UPDATE lista_chequeo SET lista_chequeo.CANT_RESPUESTAS_LISTA = '$setCantRespuestas' WHERE lista_chequeo.ID_LISTA_CHEQUEO='$idLista'";
        return ejecutarConsulta($sql);
     }
     //Guarda una respuesta; solo incluye los datos que traen algo
     public function insertarRespuesta($idLista, $idPregunta, $respuesta,$nombreColaborador)
     {
             $sql = "INSERT INTO `detale_lista_chequeo`(`ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`, `ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`, `RESPUTA_DETALLE_LISTA_CHEQUEO`,`ID_COLABORADOR_DETALE_LISTA_CHEQUEO`)";

             $values = array();
         
             //Solo los datos que traen algo
             if (!empty($idLista)) {
                 $values[] = "'$idLista'";
             }
             if (!empty($idPregunta)) {
                 $values[] = "'$idPregunta'";
             }
             if (!empty($respuesta)) {
                 $values[] = "'$respuesta'";
             }
             if (!empty($nombreColaborador)) {
                 $values[] = "'$nombreColaborador'";
             }
         
             //Si hay datos, se guarda la respuesta
             if (!empty($values)) {
                 $sql .= "  VALUES(" . implode(', ', $values) . ")";
                 return ejecutarConsulta($sql);
             } else {
                 //No hay nada que guardar
                 return false;
             }
             
     }
     //Cambia una respuesta completa
     public function editarRespuesta($idRespuesta, $idLista, $idPregunta, $respuesta){
        $sql = "UPDATE `detale_lista_chequeo` SET `ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`='$idLista',`ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`='$idPregunta',`RESPUTA_DETALLE_LISTA_CHEQUEO`='$respuesta' WHERE `ID_DETALE_LISTA_CHEQUEO`='$idRespuesta'";
        return ejecutarConsulta($sql);
     }
     //Las preguntas de un tipo de lista
     public function listarPregunta($idGrupoPreguntas)
     {
             $sql = "SELECT detalle_grupo_lista_cheque.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM `detalle_grupo_lista_cheque` INNER JOIN grupo_lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = detalle_grupo_lista_cheque.ID_DETALLE_GRUPO_LISTA_CHEQUEO WHERE `ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO`=$idGrupoPreguntas";
             return ejecutarConsulta($sql);
     }
     //Las listas diligenciadas de un tipo de lista
     public function listarRespuestas($idLista)
     {
             $sql = "SELECT lista_chequeo.ID_LISTA_CHEQUEO, lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO, centros_operacion.ID_CENTRO_OP, centros_operacion.NOM_CENTRO_OP, colaboradores.NOM_COLABORADOR, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO FROM lista_chequeo INNER JOIN centros_operacion ON lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO=centros_operacion.ID_CENTRO_OP JOIN colaboradores ON lista_chequeo.ID_COLABORADOR_LISTA_CHEQUEO = colaboradores.ID_COLABORADOR JOIN grupo_lista_chequeo ON lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idLista'";
             return ejecutarConsulta($sql);
     }
     //Las listas diligenciadas de un tipo de lista en ciertos peajes
     public function listarRespPorCentro($idGrupo,$centrosoperativos){
        $sql = "SELECT lista_chequeo.ID_LISTA_CHEQUEO, lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO, centros_operacion.ID_CENTRO_OP, centros_operacion.NOM_CENTRO_OP, colaboradores.NOM_COLABORADOR, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO FROM lista_chequeo INNER JOIN centros_operacion ON lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO=centros_operacion.ID_CENTRO_OP JOIN colaboradores ON lista_chequeo.ID_COLABORADOR_LISTA_CHEQUEO = colaboradores.ID_COLABORADOR JOIN grupo_lista_chequeo ON lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idGrupo' AND centros_operacion.ID_CENTRO_OP IN ('" . implode("','", $centrosoperativos) . "')";
        return ejecutarConsulta($sql);
     }
     //Un tipo de lista
     public function mostrar($id)
     {
             $sql = "SELECT * FROM grupo_lista_chequeo WHERE ID_GRUPO_LISTA_CHEQUEO='$id'";
             return ejecutarConsultaSimpleFila($sql);
     }
     //Las preguntas activas de un tipo de lista
     public function mostrarPreguntas($idGrupo)

     {
        $sql = "SELECT detalle_grupo_lista_cheque.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM `detalle_grupo_lista_cheque` INNER JOIN grupo_lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO WHERE detalle_grupo_lista_cheque.ESTADO = 1 AND detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO='$idGrupo'";
             return ejecutarConsulta($sql);
     }
     //Las preguntas de la lista diligenciada
     public function mostrarPreguntasRespuestas($idLista)

     {
        $sql = "SELECT detalle_grupo_lista_cheque.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM `detalle_grupo_lista_cheque` INNER JOIN grupo_lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO JOIN lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE detalle_grupo_lista_cheque.ESTADO = 1 AND lista_chequeo.ID_LISTA_CHEQUEO ='$idLista'";
             return ejecutarConsulta($sql);
     }
     //Las preguntas de una lista ya empezada en un peaje y una fecha
     public function mostrarPreguntasIniciadas($idGrupo, $idCentro, $fechaEncuesta)
     {        
        $sql = "SELECT detalle_grupo_lista_cheque.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM detalle_grupo_lista_cheque INNER JOIN grupo_lista_chequeo ON detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO JOIN lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = '$idGrupo' AND lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO LIKE '$fechaEncuesta%' AND lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO='$idCentro'";
        return ejecutarConsulta($sql);
     }
     //Las respuestas de una lista diligenciada
     public function mostrarRespuestas($idLista)
     {
             $sql = "SELECT detale_lista_chequeo.*, lista_chequeo.*, centros_operacion.NOM_CENTRO_OP FROM detale_lista_chequeo INNER JOIN lista_chequeo ON detale_lista_chequeo.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO = lista_chequeo.ID_LISTA_CHEQUEO JOIN centros_operacion ON centros_operacion.ID_CENTRO_OP = lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO WHERE detale_lista_chequeo.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO ='$idLista'";
             return ejecutarConsulta($sql);
     }
     //Desactiva una pregunta (no se borra)
     public function anular($id)
     {
             $sql = "UPDATE detalle_grupo_lista_cheque SET ESTADO=0 WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Colaboradores activos para el buscador
     public function selectColaborador($busqueda)
     {
             if ($busqueda === 0) {
                     $sql = "SELECT * FROM colaboradores WHERE ESTADO= '1' ORDER BY NOM_COLABORADOR ASC";
             } else {
                     $sql = "SELECT * FROM colaboradores WHERE NOM_COLABORADOR LIKE '%".$busqueda."%' AND ESTADO= '1' ORDER BY NOM_COLABORADOR ASC";
             }
             return ejecutarConsulta($sql);
             
     }
     //Centros activos para el buscador
     public function selectCentro($busqueda)
     {
             if ($busqueda === 0) {
                     $sql = "SELECT * FROM centros_operacion WHERE Estado= '1' ORDER BY NOM_CENTRO_OP ASC";
             } else {
                     $sql = "SELECT * FROM centros_operacion WHERE NOM_CENTRO_OP LIKE '%".$busqueda."%' OR ID_CENTRO_OP LIKE '%".$busqueda."%' AND Estado= '1' ORDER BY NOM_CENTRO_OP ASC";
             }
             return ejecutarConsulta($sql);
             
     }
        //Un centro activo por su número
        public function selectCentroId($busqueda)
        {
                $sql = "SELECT * FROM centros_operacion WHERE ID_CENTRO_OP = $busqueda AND Estado= '1' ORDER BY NOM_CENTRO_OP ASC";
                return ejecutarConsulta($sql);
        }
}
