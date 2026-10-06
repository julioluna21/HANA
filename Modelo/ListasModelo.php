<?php
require_once __DIR__ . "/HanaDB.php"; //para saber si es el ADMIN TEC (ve todos los peajes)
require_once __DIR__ . "/HanaConfig.php"; //prioridad y observador de las novedades automáticas
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
        $sql = "SELECT detalle_grupo_lista_cheque.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM `detalle_grupo_lista_cheque` INNER JOIN grupo_lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO WHERE detalle_grupo_lista_cheque.ESTADO = 1 AND detalle_grupo_lista_cheque.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO='$idGrupo' ORDER BY detalle_grupo_lista_cheque.ORDEN";
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
     //Trae SOLO los centros de operacion asignados al usuario que tiene la sesion abierta.
     //Antes se ofrecian todos los centros activos: alguien podia diligenciar una lista en un
     //centro que no le corresponde y despues no la veia en "Mostrar respuestas", porque ese
     //listado si filtra por los centros asignados
     public function selectCentroUsuario($search_term, $idUsuario)
     {
             $idUsuario = intval($idUsuario); //solo numero, nunca texto del usuario
             $filtro = "";
             if ($search_term !== 0 && $search_term !== '0' && $search_term !== '') {
                     //Los parentesis son necesarios: sin ellos el OR se mezcla con el AND
                     //del estado y aparecen centros inactivos al buscar por nombre
                     $filtro = " AND (centros_operacion.NOM_CENTRO_OP LIKE '%".$search_term."%'
                                   OR centros_operacion.ID_CENTRO_OP LIKE '%".$search_term."%') ";
             }
             //Los centros asignados al usuario y los de los proyectos que coordina
             //(las listas de chequeo las llena el coordinador)
             $sql = "SELECT centros_operacion.ID_CENTRO_OP, centros_operacion.NOM_CENTRO_OP
                     FROM centros_operacion
                     WHERE (centros_operacion.ID_CENTRO_OP IN (SELECT ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP FROM asoc_usuarios_sistemas_x_cop
                                                     WHERE ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = $idUsuario)
                          OR centros_operacion.ID_PROYECTO_CENTRO_OP IN (SELECT p.ID_PROYECTO FROM proyectos p
                                 INNER JOIN usuarios_sistema u ON u.ID_COLABORADOR_USUARIOS_SISTEMA = p.ID_COLABORADOR_COORDINADOR
                                 WHERE u.ID_USUARIO_SISTEMA = $idUsuario AND p.Estado = '1')
                          OR " . (HanaDB::adminVeTodo(isset($_SESSION['Idcolaborador']) ? (int)$_SESSION['Idcolaborador'] : 0) ? "1 = 1" : "1 = 0") . ")
                       AND centros_operacion.Estado = '1'
                       $filtro
                     ORDER BY centros_operacion.NOM_CENTRO_OP ASC";
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
	
        //Las novedades que abre una lista de chequeo salen con la prioridad y el
        //observador de Parámetros del sistema (NOV_AUTO_PRIORIDAD y NOV_AUTO_OBSERVADOR).
        //Si el número configurado no existe en su catálogo, se usa el 2
        public function prioridadAutomatica()
        {
                $id = HanaConfig::num('NOV_AUTO_PRIORIDAD', 2);
                $f = ejecutarConsultaSimpleFila("SELECT 1 AS ok FROM estados_relevancia WHERE ID_ESTADOS_RELEVANCIA = $id");
                return $f ? $id : 2;
        }

        public function observadorAutomatico()
        {
                $id = HanaConfig::num('NOV_AUTO_OBSERVADOR', 2);
                $f = ejecutarConsultaSimpleFila("SELECT 1 AS ok FROM observador_novedades_hallazgos WHERE ID_OBSERVADOR_NOVEDADES_HALLAZGOS = $id");
                return $f ? $id : 2;
        }

        //Días para cerrar una novedad automática: los de su prioridad
	       public function diaslimites()
        {
                $sql = "SELECT estados_relevancia.DIAS_ESTADOS_RELEVANCIAS FROM estados_relevancia WHERE estados_relevancia.ID_ESTADOS_RELEVANCIA=" . $this->prioridadAutomatica();
                return ejecutarConsultaSimpleFila($sql);
        }
	
	
	       public function getCentro($centro)
        {
                $sql = "SELECT centros_operacion.ID_CENTRO_OP,lista_chequeo.ID_GRUPO_LISTA_CHEQUEO from detale_lista_chequeo INNER JOIN lista_chequeo on lista_chequeo.ID_LISTA_CHEQUEO=detale_lista_chequeo.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO WHERE detale_lista_chequeo.ID_DETALE_LISTA_CHEQUEO=$centro";
                return ejecutarConsultaSimpleFila($sql);
        }
	
        //¿Este centro está asignado a este usuario? Así nadie mueve una lista a
        //un peaje ajeno modificando el formulario
        public function centroAsignado($idUsuario, $idCentro)
        {
                $idUsuario = intval($idUsuario);
                $idCentro  = intval($idCentro);
                //Asignado en Usuarios, o de un proyecto que coordina
                $sql = "SELECT 1 AS ok FROM centros_operacion
                         WHERE centros_operacion.ID_CENTRO_OP = $idCentro
                           AND (centros_operacion.ID_CENTRO_OP IN (SELECT ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP FROM asoc_usuarios_sistemas_x_cop
                                                     WHERE ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = $idUsuario)
                          OR centros_operacion.ID_PROYECTO_CENTRO_OP IN (SELECT p.ID_PROYECTO FROM proyectos p
                                 INNER JOIN usuarios_sistema u ON u.ID_COLABORADOR_USUARIOS_SISTEMA = p.ID_COLABORADOR_COORDINADOR
                                 WHERE u.ID_USUARIO_SISTEMA = $idUsuario AND p.Estado = '1')
                          OR " . (HanaDB::adminVeTodo(isset($_SESSION['Idcolaborador']) ? (int)$_SESSION['Idcolaborador'] : 0) ? "1 = 1" : "1 = 0") . ") LIMIT 1";
                return (bool)ejecutarConsultaSimpleFila($sql);
        }

        //¿Hay OTRA lista del mismo grupo, en ese centro, el mismo día que esta?
        //Es la misma regla de siempre (una lista por grupo, centro y día), pero
        //sin contar la lista que se está corrigiendo
        public function existeOtraLista($idLista, $idCentro)
        {
                $idLista  = intval($idLista);
                $idCentro = intval($idCentro);
                $sql = "SELECT 1 AS ok FROM lista_chequeo otra
                         INNER JOIN lista_chequeo esta ON esta.ID_LISTA_CHEQUEO = $idLista
                         WHERE otra.ID_LISTA_CHEQUEO <> $idLista
                           AND otra.ID_GRUPO_LISTA_CHEQUEO = esta.ID_GRUPO_LISTA_CHEQUEO
                           AND otra.ID_CENTRO_OP_LISTA_CHEQUEO = $idCentro
                           AND DATE(otra.FEC_REGISTRO_LISTA_CHEQUEO) = DATE(esta.FEC_REGISTRO_LISTA_CHEQUEO)
                         LIMIT 1";
                return (bool)ejecutarConsultaSimpleFila($sql);
        }

        //Cambia el centro operativo de una lista ya diligenciada
        public function cambiarCentro($idLista, $idCentro)
        {
                $sql = "UPDATE lista_chequeo SET ID_CENTRO_OP_LISTA_CHEQUEO = " . intval($idCentro) . "
                         WHERE ID_LISTA_CHEQUEO = " . intval($idLista);
                return ejecutarConsulta($sql);
        }

	       public function getCentro2($centro)
        {
                $sql = "SELECT lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO, lista_chequeo.ID_GRUPO_LISTA_CHEQUEO from lista_chequeo WHERE lista_chequeo.ID_LISTA_CHEQUEO=$centro";
                return ejecutarConsultaSimpleFila($sql);
        }
}