<?php
require "../Conexion/ConexionDB.php";
class listas{
     //Implementamos el super constructor 
     public function __construct()
     {
     }
     public function editarUnaRespuesta($respuesta, $colaborador, $idRespuesta){
        $sql="UPDATE `detale_lista_chequeo` SET `RESPUTA_DETALLE_LISTA_CHEQUEO`='$respuesta',`ID_COLABORADOR_DETALE_LISTA_CHEQUEO`='$colaborador' WHERE `ID_DETALE_LISTA_CHEQUEO`='$idRespuesta'";/* AND detale_lista_chequeo.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO =$idLista"*/;
        return ejecutarConsulta($sql);
     }
     public function insertarUnaRespuesta($idLista, $idPregunta, $respuesta, $colaborador){
        $sql="INSERT INTO `detale_lista_chequeo`(`ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`, `ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`, `RESPUTA_DETALLE_LISTA_CHEQUEO`, `ID_COLABORADOR_DETALE_LISTA_CHEQUEO`) VALUES ('$idLista','$idPregunta','$respuesta','$colaborador')";
        return ejecutarConsulta($sql);
     }
     public function validaListaIniciada($idGrupo,$fechaEncuesta,$idCentro){
        $sql="SELECT lista_chequeo.ID_LISTA_CHEQUEO FROM lista_chequeo WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idGrupo' AND lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO LIKE '$fechaEncuesta%' AND lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO='$idCentro'";
        return ejecutarConsultaSimpleFila($sql);
        }
     //LISTA LAS LISTAS ACTIVAS
     public function listar()
     {
             $sql = "SELECT * FROM grupo_lista_chequeo WHERE ESTADO = 1";
             return ejecutarConsulta($sql);
     }
     public function validarRespuesta($fechaEncuesta, $idGrupoPreguntas, $nombreColaborador){
        $sql = "SELECT DISTINCT lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO FROM lista_chequeo INNER JOIN detale_lista_chequeo ON detale_lista_chequeo.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO = lista_chequeo.ID_LISTA_CHEQUEO JOIN detalle_grupo_lista_cheque ON detale_lista_chequeo.ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO=detalle_grupo_lista_cheque.ID_DETALLE_GRUPO_LISTA_CHEQUEO WHERE lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO LIKE '$fechaEncuesta%' AND lista_chequeo.ID_COLABORADOR_LISTA_CHEQUEO= '$nombreColaborador' AND detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO ='$idGrupoPreguntas'";
        return ejecutarConsulta($sql);
     }
     //insertar encabezado de lista ok
     public function insertarLista($selectCentro,$fechaEncuesta,$nombreColaborador,$idGrupo){
        $sql = "INSERT INTO `lista_chequeo`(`ID_CENTRO_OP_LISTA_CHEQUEO`, `FEC_REGISTRO_LISTA_CHEQUEO`, `ID_COLABORADOR_LISTA_CHEQUEO`, `ID_GRUPO_LISTA_CHEQUEO`, `CANT_RESPUESTAS_LISTA`) VALUES ('$selectCentro','$fechaEncuesta','$nombreColaborador','$idGrupo',0)";
        return ejecutarConsulta_retornarID($sql);
     }
     //Implementamos un método para editar registros
     public function editarLista($idLista,$selectCentro, $fechaEncuesta, $nombreColaborador)
     {
             $sql = "UPDATE `lista_chequeo` SET `ID_CENTRO_OP_LISTA_CHEQUEO`='$selectCentro',`FEC_REGISTRO_LISTA_CHEQUEO`='$fechaEncuesta',`ID_COLABORADOR_LISTA_CHEQUEO`='$nombreColaborador' WHERE `ID_LISTA_CHEQUEO`='$idLista',";
             return ejecutarConsulta($sql);
     }
    //valida centro operativo del usuario actual
     public function centrosOP($IDusaurio)
   {
           $sql = "SELECT asoc_usuarios_sistemas_x_cop.* FROM asoc_usuarios_sistemas_x_cop WHERE asoc_usuarios_sistemas_x_cop.ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP=$IDusaurio";
           return ejecutarConsulta($sql);
   }

     public function contarRespuestas($idGrupo, $idLista){//,$fechaEncuesta,$nombreColaborador
        /*$sql = "SELECT COUNT(detalle_grupo_lista_cheque.PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO) AS npreguntas FROM detalle_grupo_lista_cheque WHERE detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO = '$idLista';";//muestra 5 preguntas
        $sql1= "SELECT COUNT(detale_lista_chequeo.RESPUTA_DETALLE_LISTA_CHEQUEO ) AS nrespuestas FROM detale_lista_chequeo WHERE detale_lista_chequeo.RESPUTA_DETALLE_LISTA_CHEQUEO != '' AND detale_lista_chequeo.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO=(SELECT detale_lista_chequeo.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO FROM detale_lista_chequeo INNER JOIN detalle_grupo_lista_cheque ON detalle_grupo_lista_cheque.ID_DETALLE_GRUPO_LISTA_CHEQUEO=detale_lista_chequeo.ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO WHERE detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO = '$idLista' LIMIT 1);"; //respuestas guardadas != '' 2
        $sql = "SELECT (SELECT COUNT(detalle_grupo_lista_cheque.PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO) FROM detalle_grupo_lista_cheque WHERE detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO ='$idLista') AS npreguntas,COUNT(detale_lista_chequeo.RESPUTA_DETALLE_LISTA_CHEQUEO ) AS nrespuestas FROM detale_lista_chequeo WHERE detale_lista_chequeo.RESPUTA_DETALLE_LISTA_CHEQUEO != '' AND detale_lista_chequeo.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO=(SELECT detale_lista_chequeo.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO FROM detale_lista_chequeo INNER JOIN detalle_grupo_lista_cheque ON detalle_grupo_lista_cheque.ID_DETALLE_GRUPO_LISTA_CHEQUEO=detale_lista_chequeo.ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO WHERE detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO = '$idLista' LIMIT 1)";
        $sql = "SELECT grupo_lista_chequeo.CANT_PREGUNTAS_GRUPO, lista_chequeo.CANT_RESPUESTAS_LISTA FROM grupo_lista_chequeo INNER JOIN lista_chequeo ON lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idGrupo' AND lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO = '$idCentro' AND lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO LIKE '$fechaEncuesta%';";
        */
        $sql = "SELECT grupo_lista_chequeo.CANT_PREGUNTAS_GRUPO AS npreguntas, lista_chequeo.CANT_RESPUESTAS_LISTA AS nrespuestas FROM grupo_lista_chequeo INNER JOIN lista_chequeo ON lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idGrupo' AND lista_chequeo.ID_LISTA_CHEQUEO = $idLista";
        return ejecutarConsulta($sql);
     }
     //Implementamos un método para editar registros
     public function editarPreguntas($id, $preguntas,$tiposRespuesta)
     {
             $sql = "UPDATE detalle_grupo_lista_cheque SET PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO='$preguntas',TIPO_RESPUESTA='$tiposRespuesta', ESTADO='1' where 
                 ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     public function getCantRespuestas($idLista){
        $sql = "SELECT lista_chequeo.CANT_RESPUESTAS_LISTA FROM lista_chequeo WHERE lista_chequeo.ID_LISTA_CHEQUEO = '$idLista'";
        return ejecutarConsultaSimpleFila($sql);
     }
     public function insertarCantRespuestas($idLista, $setCantRespuestas){
        $sql = "UPDATE lista_chequeo SET lista_chequeo.CANT_RESPUESTAS_LISTA = '$setCantRespuestas' WHERE lista_chequeo.ID_LISTA_CHEQUEO='$idLista'";
        return ejecutarConsulta($sql);
     }
     //Implementamos un método para insertar ok
     public function insertarRespuesta($idLista, $idPregunta, $respuesta,$nombreColaborador)
     {
             /*$sql = "INSERT INTO `detale_lista_chequeo`(`ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`, `ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`, `RESPUTA_DETALLE_LISTA_CHEQUEO`,`ID_COLABORADOR_DETALE_LISTA_CHEQUEO`) VALUES ( '$idLista', '$idPregunta', '$respuesta','$nombreColaborador')";
             return ejecutarConsulta($sql); */
             $sql = "INSERT INTO `detale_lista_chequeo`(`ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`, `ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`, `RESPUTA_DETALLE_LISTA_CHEQUEO`,`ID_COLABORADOR_DETALE_LISTA_CHEQUEO`)";

             $values = array();
         
             // Verificar y agregar las variables con contenido
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
         
             // Comprobar si hay valores para insertar
             if (!empty($values)) {
                 $sql .= "  VALUES(" . implode(', ', $values) . ")";
                 return ejecutarConsulta($sql);
             } else {
                 // No hay valores para insertar
                 return false;
             }
             
     }
     public function editarRespuesta($idRespuesta, $idLista, $idPregunta, $respuesta){
        $sql = "UPDATE `detale_lista_chequeo` SET `ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`='$idLista',`ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO`='$idPregunta',`RESPUTA_DETALLE_LISTA_CHEQUEO`='$respuesta' WHERE `ID_DETALE_LISTA_CHEQUEO`='$idRespuesta'";
        return ejecutarConsulta($sql);
     }
     //Implementar un método para listar los registros
     public function listarPregunta($idGrupoPreguntas)
     {
             $sql = "SELECT detalle_grupo_lista_cheque.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM `detalle_grupo_lista_cheque` INNER JOIN grupo_lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = detalle_grupo_lista_cheque.ID_DETALLE_GRUPO_LISTA_CHEQUEO WHERE `ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO`=$idGrupoPreguntas";
             return ejecutarConsulta($sql);
     }
     //Implementar un método para listar los registros
     public function listarRespuestas($idLista)
     {
             $sql = "SELECT lista_chequeo.ID_LISTA_CHEQUEO, lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO, centros_operacion.ID_CENTRO_OP, centros_operacion.NOM_CENTRO_OP, colaboradores.NOM_COLABORADOR, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO FROM lista_chequeo INNER JOIN centros_operacion ON lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO=centros_operacion.ID_CENTRO_OP JOIN colaboradores ON lista_chequeo.ID_COLABORADOR_LISTA_CHEQUEO = colaboradores.ID_COLABORADOR JOIN grupo_lista_chequeo ON lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idLista'";
             return ejecutarConsulta($sql);
     }
     public function listarRespPorCentro($idGrupo,$centrosoperativos){
        //$cantCentros = count($centrosoperativos);
       //var_dump($cantCentros);
        $sql = "SELECT lista_chequeo.ID_LISTA_CHEQUEO, lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO, centros_operacion.ID_CENTRO_OP, centros_operacion.NOM_CENTRO_OP, colaboradores.NOM_COLABORADOR, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO FROM lista_chequeo INNER JOIN centros_operacion ON lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO=centros_operacion.ID_CENTRO_OP JOIN colaboradores ON lista_chequeo.ID_COLABORADOR_LISTA_CHEQUEO = colaboradores.ID_COLABORADOR JOIN grupo_lista_chequeo ON lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idGrupo' AND centros_operacion.ID_CENTRO_OP IN ('" . implode("','", $centrosoperativos) . "')";
        return ejecutarConsulta($sql);
     }
     //Implementar un método para mostrar los datos de un registro a modificar
     public function mostrar($id)
     {
             $sql = "SELECT * FROM grupo_lista_chequeo WHERE ID_GRUPO_LISTA_CHEQUEO='$id'";
             return ejecutarConsultaSimpleFila($sql);
     }
     //metodo ok
     public function mostrarPreguntas($idGrupo)

     {
        $sql = "SELECT detalle_grupo_lista_cheque.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM `detalle_grupo_lista_cheque` INNER JOIN grupo_lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO WHERE detalle_grupo_lista_cheque.ESTADO = 1 AND detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO='$idGrupo'";
             return ejecutarConsulta($sql);
     }
     //metodo ok
     public function mostrarPreguntasRespuestas($idLista)

     {
        $sql = "SELECT detalle_grupo_lista_cheque.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM `detalle_grupo_lista_cheque` INNER JOIN grupo_lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO JOIN lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE detalle_grupo_lista_cheque.ESTADO = 1 AND lista_chequeo.ID_LISTA_CHEQUEO ='$idLista'";
             return ejecutarConsulta($sql);
     }
     //metodo ok
     public function mostrarPreguntasIniciadas($idGrupo, $idCentro, $fechaEncuesta)
     {        
        $sql = "SELECT detalle_grupo_lista_cheque.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM detalle_grupo_lista_cheque INNER JOIN grupo_lista_chequeo ON detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO JOIN lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = '$idGrupo' AND lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO LIKE '$fechaEncuesta%' AND lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO='$idCentro'";
        return ejecutarConsulta($sql);
     }
     //metodo ok
     public function mostrarRespuestas($idLista)
     {
             $sql = "SELECT detale_lista_chequeo.*, lista_chequeo.*, centros_operacion.NOM_CENTRO_OP FROM detale_lista_chequeo INNER JOIN lista_chequeo ON detale_lista_chequeo.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO = lista_chequeo.ID_LISTA_CHEQUEO JOIN centros_operacion ON centros_operacion.ID_CENTRO_OP = lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO WHERE detale_lista_chequeo.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO ='$idLista'";
             return ejecutarConsulta($sql);
     }
     //Implementamos un método para editar reasignar contraseña
     public function anular($id)
     {
             $sql = "UPDATE detalle_grupo_lista_cheque SET ESTADO=0 WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     public function selectColaborador($search_term)
     {
             if ($search_term === 0) {
                     $sql = "SELECT * FROM colaboradores WHERE ESTADO= '1' ORDER BY NOM_COLABORADOR ASC";
             } else {
                     $sql = "SELECT * FROM colaboradores WHERE NOM_COLABORADOR LIKE '%".$search_term."%' AND ESTADO= '1' ORDER BY NOM_COLABORADOR ASC";
             }
             return ejecutarConsulta($sql);
             
     }
     public function selectCentro($search_term)
     {
             if ($search_term === 0) {
                     $sql = "SELECT * FROM centros_operacion WHERE Estado= '1' ORDER BY NOM_CENTRO_OP ASC";
             } else {
                     $sql = "SELECT * FROM centros_operacion WHERE NOM_CENTRO_OP LIKE '%".$search_term."%' OR ID_CENTRO_OP LIKE '%".$search_term."%' AND Estado= '1' ORDER BY NOM_CENTRO_OP ASC";
             }
             return ejecutarConsulta($sql);
             
     }
        public function selectCentroId($search_term)
        {
                $sql = "SELECT * FROM centros_operacion WHERE ID_CENTRO_OP = $search_term AND Estado= '1' ORDER BY NOM_CENTRO_OP ASC";
                return ejecutarConsulta($sql);
        }
	
	   
	
	    public function preguntasnovedad($idgrupo)
        {
                $sql = "SELECT detalle_grupo_lista_cheque.ID_DETALLE_GRUPO_LISTA_CHEQUEO,  detalle_grupo_lista_cheque.TITULO_NOVEDAD,detalle_grupo_lista_cheque.PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO FROM detalle_grupo_lista_cheque  WHERE detalle_grupo_lista_cheque.GENERA_NOVEDAD=1 and detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO=$idgrupo and detalle_grupo_lista_cheque.ESTADO=1";
                return ejecutarConsulta($sql);
        }
	
	     public function insertarNovedad($fechacreacion,$colaboraador,$centroopertivo,$asignado,$observador,$titulo,$descripcion,$validez,$relevancia,$fechalimite)
        {
                $sql = "INSERT INTO novedades_hallazgos(FEC_CREACION_NOVEDADES_HALLAZGOS,ID_COLABORADOR_NOVEDADES_HALLAZGOS,ID_CENTRO_OP_NOVEDADES_HALLAZGOS,ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS,ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS,ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS,DESCR_NOVEDADES_HALLAZGOS,VALIDEZ_NOVEDADES_HALLAZGOS,ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS,FECHA_LIMITE_NOVEDAD,ESTADO_NOVEDAD)
                            VALUES ('$fechacreacion','$colaboraador','$centroopertivo','$asignado','$observador','$titulo','$descripcion','$validez','$relevancia','$fechalimite',1)";
                return ejecutarConsulta_retornarID($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
	
	       public function diaslimites()
        {
                $sql = "SELECT estados_relevancia.DIAS_ESTADOS_RELEVANCIAS FROM estados_relevancia WHERE estados_relevancia.ID_ESTADOS_RELEVANCIA=2";
                return ejecutarConsultaSimpleFila($sql);
        }
	
	
	       public function getCentro($centro)
        {
                $sql = "SELECT centros_operacion.ID_CENTRO_OP,lista_chequeo.ID_GRUPO_LISTA_CHEQUEO from detale_lista_chequeo INNER JOIN lista_chequeo on lista_chequeo.ID_LISTA_CHEQUEO=detale_lista_chequeo.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO WHERE detale_lista_chequeo.ID_DETALE_LISTA_CHEQUEO=$centro";
                return ejecutarConsultaSimpleFila($sql);
        }
	
	       public function getCentro2($centro)
        {
                $sql = "SELECT lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO, lista_chequeo.ID_GRUPO_LISTA_CHEQUEO from lista_chequeo WHERE lista_chequeo.ID_LISTA_CHEQUEO=$centro";
                return ejecutarConsultaSimpleFila($sql);
        }
}
