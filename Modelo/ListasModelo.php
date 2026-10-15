<?php
/*
  HANA - Listas de chequeo diligenciadas (modelo)
  ---------------------------------------------------------------------------
  Consultas de lista_chequeo (el encabezado: peaje, fecha y quién la llenó) y de
  detalle_lista_chequeo (una fila por respuesta). También crea las novedades que
  salen de las respuestas. Lo usa Control/ListasControl.php.
*/
require_once __DIR__ . "/HanaDB.php"; //para saber si es el ADMIN TEC (ve todos los peajes)
require_once __DIR__ . "/HanaConfig.php"; //prioridad y observador de las novedades automáticas
require "../Conexion/ConexionDB.php";
class listas{
     //Implementamos el super constructor 
     public function __construct()
     {
     }
     //Cambia una respuesta ya guardada y quién la corrigió
     public function editarUnaRespuesta($respuesta, $colaborador, $idRespuesta){
        $sql="UPDATE `detalle_lista_chequeo` SET `RESPUESTA_DETALLE_LISTA_CHEQUEO`='$respuesta',`ID_COLABORADOR_DETALLE_LISTA_CHEQUEO`='$colaborador' WHERE `ID_DETALLE_LISTA_CHEQUEO`='$idRespuesta'";/* AND detalle_lista_chequeo.ID_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO =$idLista"*/;
        return ejecutarConsulta($sql);
     }
     //Agrega la respuesta de una pregunta que había quedado sin responder
     public function insertarUnaRespuesta($idLista, $idPregunta, $respuesta, $colaborador){
        $sql="INSERT INTO `detalle_lista_chequeo`(`ID_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO`, `ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO`, `RESPUESTA_DETALLE_LISTA_CHEQUEO`, `ID_COLABORADOR_DETALLE_LISTA_CHEQUEO`) VALUES ('$idLista','$idPregunta','$respuesta','$colaborador')";
        return ejecutarConsulta($sql);
     }
     //¿Ya hay una lista de este tipo empezada para ese peaje en esa fecha?
     public function validaListaIniciada($idGrupo,$fechaEncuesta,$idCentro){
        $sql="SELECT lista_chequeo.ID_LISTA_CHEQUEO FROM lista_chequeo WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idGrupo' AND lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO LIKE '$fechaEncuesta%' AND lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO='$idCentro'";
        return ejecutarConsultaSimpleFila($sql);
        }
     //LISTA LAS LISTAS ACTIVAS, con cuántas preguntas activas tiene cada una
     public function listar()
     {
             $sql = "SELECT g.*, (SELECT COUNT(*) FROM detalle_grupo_lista_chequeo p
                                   WHERE p.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO = g.ID_GRUPO_LISTA_CHEQUEO AND p.ESTADO = 1) AS CANT_PREGUNTAS_GRUPO
                       FROM grupo_lista_chequeo g WHERE g.ESTADO = 1";
             return ejecutarConsulta($sql);
     }
     //Fechas en las que la persona ya respondió esta lista
     public function validarRespuesta($fechaEncuesta, $idGrupoPreguntas, $nombreColaborador){
        $sql = "SELECT DISTINCT lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO FROM lista_chequeo INNER JOIN detalle_lista_chequeo ON detalle_lista_chequeo.ID_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO = lista_chequeo.ID_LISTA_CHEQUEO JOIN detalle_grupo_lista_chequeo ON detalle_lista_chequeo.ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO=detalle_grupo_lista_chequeo.ID_DETALLE_GRUPO_LISTA_CHEQUEO WHERE lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO LIKE '$fechaEncuesta%' AND lista_chequeo.ID_COLABORADOR_LISTA_CHEQUEO= '$nombreColaborador' AND detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO ='$idGrupoPreguntas'";
        return ejecutarConsulta($sql);
     }
     //insertar encabezado de lista ok
     public function insertarLista($selectCentro,$fechaEncuesta,$nombreColaborador,$idGrupo){
        $sql = "INSERT INTO `lista_chequeo`(`ID_CENTRO_OP_LISTA_CHEQUEO`, `FEC_REGISTRO_LISTA_CHEQUEO`, `ID_COLABORADOR_LISTA_CHEQUEO`, `ID_GRUPO_LISTA_CHEQUEO`) VALUES ('$selectCentro','$fechaEncuesta','$nombreColaborador','$idGrupo')";
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

     //Las cuentas del avance de una lista, calculadas al momento: preguntas activas de su
     //grupo y respuestas guardadas. Se cuentan en la misma consulta, así nunca se descuadran
     const SQL_NPREGUNTAS = "(SELECT COUNT(*) FROM detalle_grupo_lista_chequeo p WHERE p.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO = lista_chequeo.ID_GRUPO_LISTA_CHEQUEO AND p.ESTADO = 1)";
     const SQL_NRESPUESTAS = "(SELECT COUNT(*) FROM detalle_lista_chequeo d WHERE d.ID_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO = lista_chequeo.ID_LISTA_CHEQUEO)";

     //Cuántas preguntas tiene la lista y cuántas se han respondido
     public function contarRespuestas($idGrupo, $idLista){
        $sql = "SELECT " . self::SQL_NPREGUNTAS . " AS npreguntas, " . self::SQL_NRESPUESTAS . " AS nrespuestas
                  FROM lista_chequeo
                 WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = " . intval($idGrupo) . " AND lista_chequeo.ID_LISTA_CHEQUEO = " . intval($idLista);
        return ejecutarConsulta($sql);
     }
     //Implementamos un método para editar registros
     public function editarPreguntas($id, $preguntas,$tiposRespuesta)
     {
             $id = intval($id); //solo números en la consulta
             $sql = "UPDATE detalle_grupo_lista_chequeo SET PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO='$preguntas',TIPO_RESPUESTA='$tiposRespuesta', ESTADO='1' where 
                 ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Implementamos un método para insertar ok
     public function insertarRespuesta($idLista, $idPregunta, $respuesta,$nombreColaborador)
     {
             /*$sql = "INSERT INTO `detalle_lista_chequeo`(`ID_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO`, `ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO`, `RESPUESTA_DETALLE_LISTA_CHEQUEO`,`ID_COLABORADOR_DETALLE_LISTA_CHEQUEO`) VALUES ( '$idLista', '$idPregunta', '$respuesta','$nombreColaborador')";
             return ejecutarConsulta($sql); */
             $sql = "INSERT INTO `detalle_lista_chequeo`(`ID_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO`, `ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO`, `RESPUESTA_DETALLE_LISTA_CHEQUEO`,`ID_COLABORADOR_DETALLE_LISTA_CHEQUEO`)";

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
     //Guarda los cambios de una respuesta
     public function editarRespuesta($idRespuesta, $idLista, $idPregunta, $respuesta){
        $sql = "UPDATE `detalle_lista_chequeo` SET `ID_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO`='$idLista',`ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO`='$idPregunta',`RESPUESTA_DETALLE_LISTA_CHEQUEO`='$respuesta' WHERE `ID_DETALLE_LISTA_CHEQUEO`='$idRespuesta'";
        return ejecutarConsulta($sql);
     }
     //Implementar un método para listar los registros
     public function listarPregunta($idGrupoPreguntas)
     {
             $sql = "SELECT detalle_grupo_lista_chequeo.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM `detalle_grupo_lista_chequeo` INNER JOIN grupo_lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = detalle_grupo_lista_chequeo.ID_DETALLE_GRUPO_LISTA_CHEQUEO WHERE `ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO`=$idGrupoPreguntas";
             return ejecutarConsulta($sql);
     }
     //Implementar un método para listar los registros
     public function listarRespuestas($idLista)
     {
             //Trae de una vez las cuentas del avance (una sola consulta para todas las listas)
             $sql = "SELECT lista_chequeo.ID_LISTA_CHEQUEO, lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO, centros_operacion.ID_CENTRO_OP, centros_operacion.NOM_CENTRO_OP, colaboradores.NOM_COLABORADOR, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO, " . self::SQL_NPREGUNTAS . " AS npreguntas, " . self::SQL_NRESPUESTAS . " AS nrespuestas FROM lista_chequeo INNER JOIN centros_operacion ON lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO=centros_operacion.ID_CENTRO_OP JOIN colaboradores ON lista_chequeo.ID_COLABORADOR_LISTA_CHEQUEO = colaboradores.ID_COLABORADOR JOIN grupo_lista_chequeo ON lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idLista'";
             return ejecutarConsulta($sql);
     }
     //Listas diligenciadas de un tipo en los centros indicados, con su avance
     public function listarRespPorCentro($idGrupo,$centrosoperativos){
        //$cantCentros = count($centrosoperativos);
       //var_dump($cantCentros);
        $sql = "SELECT lista_chequeo.ID_LISTA_CHEQUEO, lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO, centros_operacion.ID_CENTRO_OP, centros_operacion.NOM_CENTRO_OP, colaboradores.NOM_COLABORADOR, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO, " . self::SQL_NPREGUNTAS . " AS npreguntas, " . self::SQL_NRESPUESTAS . " AS nrespuestas FROM lista_chequeo INNER JOIN centros_operacion ON lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO=centros_operacion.ID_CENTRO_OP JOIN colaboradores ON lista_chequeo.ID_COLABORADOR_LISTA_CHEQUEO = colaboradores.ID_COLABORADOR JOIN grupo_lista_chequeo ON lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idGrupo' AND centros_operacion.ID_CENTRO_OP IN ('" . implode("','", $centrosoperativos) . "')";
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
        $sql = "SELECT detalle_grupo_lista_chequeo.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM `detalle_grupo_lista_chequeo` INNER JOIN grupo_lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO WHERE detalle_grupo_lista_chequeo.ESTADO = 1 AND detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO='$idGrupo' ORDER BY detalle_grupo_lista_chequeo.ORDEN";
             return ejecutarConsulta($sql);
     }
     //metodo ok
     public function mostrarPreguntasRespuestas($idLista)

     {
        $sql = "SELECT detalle_grupo_lista_chequeo.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM `detalle_grupo_lista_chequeo` INNER JOIN grupo_lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO JOIN lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE detalle_grupo_lista_chequeo.ESTADO = 1 AND lista_chequeo.ID_LISTA_CHEQUEO ='$idLista'";
             return ejecutarConsulta($sql);
     }
     //metodo ok
     public function mostrarPreguntasIniciadas($idGrupo, $idCentro, $fechaEncuesta)
     {        
        $sql = "SELECT detalle_grupo_lista_chequeo.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM detalle_grupo_lista_chequeo INNER JOIN grupo_lista_chequeo ON detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO JOIN lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = '$idGrupo' AND lista_chequeo.FEC_REGISTRO_LISTA_CHEQUEO LIKE '$fechaEncuesta%' AND lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO='$idCentro'";
        return ejecutarConsulta($sql);
     }
     //metodo ok
     public function mostrarRespuestas($idLista)
     {
             $sql = "SELECT detalle_lista_chequeo.*, lista_chequeo.*, centros_operacion.NOM_CENTRO_OP FROM detalle_lista_chequeo INNER JOIN lista_chequeo ON detalle_lista_chequeo.ID_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO = lista_chequeo.ID_LISTA_CHEQUEO JOIN centros_operacion ON centros_operacion.ID_CENTRO_OP = lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO WHERE detalle_lista_chequeo.ID_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO ='$idLista'";
             return ejecutarConsulta($sql);
     }
     //Implementamos un método para editar reasignar contraseña
     public function anular($id)
     {
             $id = intval($id); //solo números en la consulta
             $sql = "UPDATE detalle_grupo_lista_chequeo SET ESTADO=0 WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Colaboradores activos para el buscador
     public function selectColaborador($search_term)
     {
             if ($search_term === 0) {
                     $sql = "SELECT * FROM colaboradores WHERE ESTADO= '1' ORDER BY NOM_COLABORADOR ASC";
             } else {
                     $sql = "SELECT * FROM colaboradores WHERE NOM_COLABORADOR LIKE '%".$search_term."%' AND ESTADO= '1' ORDER BY NOM_COLABORADOR ASC";
             }
             return ejecutarConsulta($sql);
             
     }
     //Centros activos para el buscador
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
     //Así nadie diligencia una lista en un centro que no le corresponde (y que después
     //no vería en "Mostrar respuestas", que también filtra por los centros asignados)
     public function selectCentroUsuario($search_term, $idUsuario, $todos = false) //$todos: el ADMIN TEC ve todos los centros
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
                          OR " . ($todos ? "1 = 1" : "1 = 0") . ")
                       AND centros_operacion.Estado = '1'
                       $filtro
                     ORDER BY centros_operacion.NOM_CENTRO_OP ASC";
             return ejecutarConsulta($sql);
     }

        //Un centro activo por su id
        public function selectCentroId($search_term)
        {
                $sql = "SELECT * FROM centros_operacion WHERE ID_CENTRO_OP = $search_term AND Estado= '1' ORDER BY NOM_CENTRO_OP ASC";
                return ejecutarConsulta($sql);
        }
	
	   
	
	    //Preguntas de la lista que tienen título de novedad (las que pueden generar una novedad)
	    public function preguntasnovedad($idgrupo)
        {
                $idgrupo = intval($idgrupo); //solo números en la consulta
                $sql = "SELECT detalle_grupo_lista_chequeo.ID_DETALLE_GRUPO_LISTA_CHEQUEO,  detalle_grupo_lista_chequeo.TITULO_NOVEDAD,detalle_grupo_lista_chequeo.PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO FROM detalle_grupo_lista_chequeo  WHERE detalle_grupo_lista_chequeo.TITULO_NOVEDAD IS NOT NULL and detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO=$idgrupo and detalle_grupo_lista_chequeo.ESTADO=1";
                return ejecutarConsulta($sql);
        }
	
	     //Crea la novedad que sale de una respuesta de la lista de chequeo
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

        //El observador que se pone a las novedades automáticas (se toma de Parámetros)
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
	
	
	       //Centro y tipo de lista de una respuesta
	       public function getCentro($centro)
        {
                $centro = intval($centro); //solo números en la consulta
                $sql = "SELECT centros_operacion.ID_CENTRO_OP,lista_chequeo.ID_GRUPO_LISTA_CHEQUEO from detalle_lista_chequeo INNER JOIN lista_chequeo on lista_chequeo.ID_LISTA_CHEQUEO=detalle_lista_chequeo.ID_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO WHERE detalle_lista_chequeo.ID_DETALLE_LISTA_CHEQUEO=$centro";
                return ejecutarConsultaSimpleFila($sql);
        }
	
        //¿Este centro está asignado a este usuario? Así nadie mueve una lista a
        //un peaje ajeno modificando el formulario
        public function centroAsignado($idUsuario, $idCentro, $todos = false)
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
                          OR " . ($todos ? "1 = 1" : "1 = 0") . ") LIMIT 1";
                return (bool)ejecutarConsultaSimpleFila($sql);
        }

        //La fecha de una lista y quién la llenó (por el id de la lista o de una de sus respuestas).
        //Devuelve array('F' => fecha, 'DUENO' => id del colaborador) o null
        public function fechaYDueno($idLista, $idRespuesta)
        {
                require_once __DIR__ . '/HanaDB.php';
                if ((int)$idLista > 0) {
                        return HanaDB::fila("SELECT FEC_REGISTRO_LISTA_CHEQUEO AS F, ID_COLABORADOR_LISTA_CHEQUEO AS DUENO FROM lista_chequeo WHERE ID_LISTA_CHEQUEO = ?", 'i', array((int)$idLista));
                }
                return HanaDB::fila("SELECT l.FEC_REGISTRO_LISTA_CHEQUEO AS F, l.ID_COLABORADOR_LISTA_CHEQUEO AS DUENO FROM detalle_lista_chequeo d
                                       INNER JOIN lista_chequeo l ON l.ID_LISTA_CHEQUEO = d.ID_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO
                                      WHERE d.ID_DETALLE_LISTA_CHEQUEO = ?", 'i', array((int)$idRespuesta));
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

	       //Centro y tipo de lista de una lista diligenciada
	       public function getCentro2($centro)
        {
                $centro = intval($centro); //solo números en la consulta
                $sql = "SELECT lista_chequeo.ID_CENTRO_OP_LISTA_CHEQUEO, lista_chequeo.ID_GRUPO_LISTA_CHEQUEO from lista_chequeo WHERE lista_chequeo.ID_LISTA_CHEQUEO=$centro";
                return ejecutarConsultaSimpleFila($sql);
        }
}