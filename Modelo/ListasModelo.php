<?php
/*
  HANA - Listas de chequeo diligenciadas (modelo)
  ---------------------------------------------------------------------------
  Consultas de lista_chequeo (el encabezado: peaje, fecha y quién la llenó) y de
  detalle_lista_chequeo (una fila por respuesta). Lo usa Control/ListasControl.php.
*/
require_once __DIR__ . "/HanaDB.php"; //consultas preparadas
require "../Conexion/ConexionDB.php";
class listas{
     //Constructor vacío: la clase no guarda datos propios
     public function __construct()
     {
     }
     //Cambia una respuesta ya guardada y quién la corrigió
     public function editarUnaRespuesta($respuesta, $colaborador, $idRespuesta){
        $sql="UPDATE `detalle_lista_chequeo` SET `RESPUESTA`='$respuesta',`ID_COLABORADOR`='$colaborador' WHERE `ID_DETALLE_LISTA_CHEQUEO`='$idRespuesta'";
        return ejecutarConsulta($sql);
     }
     //Agrega la respuesta de una pregunta que había quedado sin responder
     public function insertarUnaRespuesta($idLista, $idPregunta, $respuesta, $colaborador){
        $sql="INSERT INTO `detalle_lista_chequeo`(`ID_LISTA_CHEQUEO`, `ID_PREGUNTA`, `RESPUESTA`, `ID_COLABORADOR`) VALUES ('$idLista','$idPregunta','$respuesta','$colaborador')";
        return ejecutarConsulta($sql);
     }
     //¿Ya hay una lista de este tipo empezada para ese peaje en esa fecha?
     public function validaListaIniciada($idGrupo,$fechaEncuesta,$idCentro){
        $sql="SELECT lista_chequeo.ID_LISTA_CHEQUEO FROM lista_chequeo WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idGrupo' AND lista_chequeo.FEC_REGISTRO LIKE '$fechaEncuesta%' AND lista_chequeo.ID_CENTRO_OP='$idCentro'";
        return ejecutarConsultaSimpleFila($sql);
        }
     //Los tipos de lista activos, con cuántas preguntas activas tiene cada uno
     public function listar()
     {
             $sql = "SELECT g.*, (SELECT COUNT(*) FROM detalle_grupo_lista_chequeo p
                                   WHERE p.ID_GRUPO_LISTA_CHEQUEO = g.ID_GRUPO_LISTA_CHEQUEO AND p.ESTADO = 1) AS CANT_PREGUNTAS_GRUPO
                       FROM grupo_lista_chequeo g WHERE g.ESTADO = 1";
             return ejecutarConsulta($sql);
     }
     //Fechas en las que la persona ya respondió esta lista
     public function validarRespuesta($fechaEncuesta, $idGrupoPreguntas, $idColaborador){
        $sql = "SELECT DISTINCT lista_chequeo.FEC_REGISTRO FROM lista_chequeo INNER JOIN detalle_lista_chequeo ON detalle_lista_chequeo.ID_LISTA_CHEQUEO = lista_chequeo.ID_LISTA_CHEQUEO JOIN detalle_grupo_lista_chequeo ON detalle_lista_chequeo.ID_PREGUNTA=detalle_grupo_lista_chequeo.ID_DETALLE_GRUPO_LISTA_CHEQUEO WHERE lista_chequeo.FEC_REGISTRO LIKE '$fechaEncuesta%' AND lista_chequeo.ID_COLABORADOR= '$idColaborador' AND detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO ='$idGrupoPreguntas'";
        return ejecutarConsulta($sql);
     }
     //Crea el encabezado de una lista (peaje, fecha, quién y tipo) y devuelve su número
     public function insertarLista($selectCentro,$fechaEncuesta,$idColaborador,$idGrupo){
        $sql = "INSERT INTO `lista_chequeo`(`ID_CENTRO_OP`, `FEC_REGISTRO`, `ID_COLABORADOR`, `ID_GRUPO_LISTA_CHEQUEO`) VALUES ('$selectCentro','$fechaEncuesta','$idColaborador','$idGrupo')";
        return ejecutarConsulta_retornarID($sql);
     }
     //Cambia el centro, la fecha o quién diligenció una lista ya guardada (sin uso: el
     //controlador cambia solo el centro, con cambiarCentro)
     public function editarLista($idLista,$selectCentro, $fechaEncuesta, $idColaborador)
     {
             $sql = "UPDATE `lista_chequeo` SET `ID_CENTRO_OP`='$selectCentro',`FEC_REGISTRO`='$fechaEncuesta',`ID_COLABORADOR`='$idColaborador' WHERE `ID_LISTA_CHEQUEO`='$idLista'";
             return ejecutarConsulta($sql);
     }
     //Los peajes asignados a una persona en Colaboradores
     public function centrosAsignados($idPersona)
     {
             $sql = "SELECT asoc_usuarios_sistemas_x_cop.* FROM asoc_usuarios_sistemas_x_cop WHERE asoc_usuarios_sistemas_x_cop.ID_COLABORADOR=" . intval($idPersona);
             return ejecutarConsulta($sql);
     }

     //Las cuentas del avance de una lista, calculadas al momento: preguntas activas de su
     //grupo y respuestas guardadas. Se cuentan en la misma consulta, así nunca se descuadran
     const SQL_NPREGUNTAS = "(SELECT COUNT(*) FROM detalle_grupo_lista_chequeo p WHERE p.ID_GRUPO_LISTA_CHEQUEO = lista_chequeo.ID_GRUPO_LISTA_CHEQUEO AND p.ESTADO = 1)";
     const SQL_NRESPUESTAS = "(SELECT COUNT(*) FROM detalle_lista_chequeo d WHERE d.ID_LISTA_CHEQUEO = lista_chequeo.ID_LISTA_CHEQUEO)";

     //Cuántas preguntas tiene la lista y cuántas se han respondido
     public function contarRespuestas($idGrupo, $idLista){
        $sql = "SELECT " . self::SQL_NPREGUNTAS . " AS npreguntas, " . self::SQL_NRESPUESTAS . " AS nrespuestas
                  FROM lista_chequeo
                 WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = " . intval($idGrupo) . " AND lista_chequeo.ID_LISTA_CHEQUEO = " . intval($idLista);
        return ejecutarConsulta($sql);
     }
     //Cambia el texto y el tipo de respuesta de una pregunta (sin uso: las preguntas se
     //editan en Modelo/PreguntasListasModelo.php)
     public function editarPreguntas($id, $preguntas,$tiposRespuesta)
     {
             $id = intval($id); //solo números en la consulta
             $sql = "UPDATE detalle_grupo_lista_chequeo SET PREGUNTA='$preguntas',TIPO_RESPUESTA='$tiposRespuesta', ESTADO='1' where 
                 ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Guarda la respuesta de una pregunta en una lista diligenciada
     public function insertarRespuesta($idLista, $idPregunta, $respuesta,$idColaborador)
     {
             $sql = "INSERT INTO `detalle_lista_chequeo`(`ID_LISTA_CHEQUEO`, `ID_PREGUNTA`, `RESPUESTA`,`ID_COLABORADOR`)";

             $valores = array();

             //Solo entran los datos que traen algo
             if (!empty($idLista)) {
                 $valores[] = "'$idLista'";
             }
             if (!empty($idPregunta)) {
                 $valores[] = "'$idPregunta'";
             }
             if (!empty($respuesta)) {
                 $valores[] = "'$respuesta'";
             }
             if (!empty($idColaborador)) {
                 $valores[] = "'$idColaborador'";
             }
         
             //Si hay datos, se guarda la respuesta
             if (!empty($valores)) {
                 $sql .= "  VALUES(" . implode(', ', $valores) . ")";
                 return ejecutarConsulta($sql);
             } else {
                 //No hay nada que guardar
                 return false;
             }
             
     }
     //Guarda los cambios de una respuesta (sin uso: el controlador usa editarUnaRespuesta)
     public function editarRespuesta($idRespuesta, $idLista, $idPregunta, $respuesta){
        $sql = "UPDATE `detalle_lista_chequeo` SET `ID_LISTA_CHEQUEO`='$idLista',`ID_PREGUNTA`='$idPregunta',`RESPUESTA`='$respuesta' WHERE `ID_DETALLE_LISTA_CHEQUEO`='$idRespuesta'";
        return ejecutarConsulta($sql);
     }
     //Las preguntas de un tipo de lista (sin uso: la pantalla usa mostrarPreguntas)
     public function listarPregunta($idGrupoPreguntas)
     {
             $sql = "SELECT detalle_grupo_lista_chequeo.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM `detalle_grupo_lista_chequeo` INNER JOIN grupo_lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = detalle_grupo_lista_chequeo.ID_DETALLE_GRUPO_LISTA_CHEQUEO WHERE `ID_GRUPO_LISTA_CHEQUEO`=$idGrupoPreguntas";
             return ejecutarConsulta($sql);
     }
     //Las listas diligenciadas de un tipo de lista, con su avance
     //(las cuentas del avance vienen en la misma consulta para todas las listas)
     public function listarRespuestas($idGrupo)
     {
             $sql = "SELECT lista_chequeo.ID_LISTA_CHEQUEO, lista_chequeo.FEC_REGISTRO, centros_operacion.ID_CENTRO_OP, centros_operacion.NOM_CENTRO_OP, colaboradores.NOM_COLABORADOR, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO, " . self::SQL_NPREGUNTAS . " AS npreguntas, " . self::SQL_NRESPUESTAS . " AS nrespuestas FROM lista_chequeo INNER JOIN centros_operacion ON lista_chequeo.ID_CENTRO_OP=centros_operacion.ID_CENTRO_OP JOIN colaboradores ON lista_chequeo.ID_COLABORADOR = colaboradores.ID_COLABORADOR JOIN grupo_lista_chequeo ON lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idGrupo'";
             return ejecutarConsulta($sql);
     }
     //Listas diligenciadas de un tipo en los centros indicados, con su avance
     public function listarRespPorCentro($idGrupo,$centrosoperativos){
        $sql = "SELECT lista_chequeo.ID_LISTA_CHEQUEO, lista_chequeo.FEC_REGISTRO, centros_operacion.ID_CENTRO_OP, centros_operacion.NOM_CENTRO_OP, colaboradores.NOM_COLABORADOR, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO, " . self::SQL_NPREGUNTAS . " AS npreguntas, " . self::SQL_NRESPUESTAS . " AS nrespuestas FROM lista_chequeo INNER JOIN centros_operacion ON lista_chequeo.ID_CENTRO_OP=centros_operacion.ID_CENTRO_OP JOIN colaboradores ON lista_chequeo.ID_COLABORADOR = colaboradores.ID_COLABORADOR JOIN grupo_lista_chequeo ON lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idGrupo' AND centros_operacion.ID_CENTRO_OP IN ('" . implode("','", $centrosoperativos) . "')";
        return ejecutarConsulta($sql);
     }
     //Un tipo de lista (nombre y estado)
     public function mostrar($id)
     {
             $sql = "SELECT * FROM grupo_lista_chequeo WHERE ID_GRUPO_LISTA_CHEQUEO='$id'";
             return ejecutarConsultaSimpleFila($sql);
     }
     //Las preguntas activas de un tipo de lista, en su orden
     public function mostrarPreguntas($idGrupo)
     {
        $sql = "SELECT detalle_grupo_lista_chequeo.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM `detalle_grupo_lista_chequeo` INNER JOIN grupo_lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE detalle_grupo_lista_chequeo.ESTADO = 1 AND detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO='$idGrupo' ORDER BY detalle_grupo_lista_chequeo.ORDEN";
             return ejecutarConsulta($sql);
     }
     //Las preguntas activas del tipo de lista de una lista diligenciada
     public function mostrarPreguntasRespuestas($idLista)
     {
        $sql = "SELECT detalle_grupo_lista_chequeo.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM `detalle_grupo_lista_chequeo` INNER JOIN grupo_lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO JOIN lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE detalle_grupo_lista_chequeo.ESTADO = 1 AND lista_chequeo.ID_LISTA_CHEQUEO ='$idLista'";
             return ejecutarConsulta($sql);
     }
     //Las preguntas de una lista ya empezada en un peaje y una fecha
     public function mostrarPreguntasIniciadas($idGrupo, $idCentro, $fechaEncuesta)
     {        
        $sql = "SELECT detalle_grupo_lista_chequeo.*, grupo_lista_chequeo.NOM_GRUPO_LISTA_CHEQUEO AS lista FROM detalle_grupo_lista_chequeo INNER JOIN grupo_lista_chequeo ON detalle_grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO JOIN lista_chequeo ON grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO=lista_chequeo.ID_GRUPO_LISTA_CHEQUEO WHERE grupo_lista_chequeo.ID_GRUPO_LISTA_CHEQUEO = '$idGrupo' AND lista_chequeo.FEC_REGISTRO LIKE '$fechaEncuesta%' AND lista_chequeo.ID_CENTRO_OP='$idCentro'";
        return ejecutarConsulta($sql);
     }
     //Las respuestas de una lista con su peaje y su fecha. ID_COLABORADOR es quien respondió cada pregunta
     public function mostrarRespuestas($idLista)
     {
             $sql = "SELECT detalle_lista_chequeo.*, lista_chequeo.ID_CENTRO_OP, lista_chequeo.FEC_REGISTRO, lista_chequeo.ID_GRUPO_LISTA_CHEQUEO, centros_operacion.NOM_CENTRO_OP FROM detalle_lista_chequeo INNER JOIN lista_chequeo ON detalle_lista_chequeo.ID_LISTA_CHEQUEO = lista_chequeo.ID_LISTA_CHEQUEO JOIN centros_operacion ON centros_operacion.ID_CENTRO_OP = lista_chequeo.ID_CENTRO_OP WHERE detalle_lista_chequeo.ID_LISTA_CHEQUEO ='$idLista'";
             return ejecutarConsulta($sql);
     }
     //Desactiva una PREGUNTA de las plantillas (detalle_grupo_lista_chequeo). Las listas
     //diligenciadas no tienen estado, así que no se anulan: ListasControl ya no la llama
     public function anular($id)
     {
             $id = intval($id); //solo números en la consulta
             $sql = "UPDATE detalle_grupo_lista_chequeo SET ESTADO=0 WHERE ID_DETALLE_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Colaboradores activos para el buscador
     public function selectColaborador($busqueda)
     {
             if ($busqueda === 0) {
                     $sql = "SELECT * FROM colaboradores WHERE ESTADO= '1' ORDER BY NOM_COLABORADOR ASC";
             } else {
                     //El texto de búsqueda se escapa (comillas) y se neutralizan los comodines % y _ antes del LIKE
                     $texto = addcslashes(mysqli_real_escape_string($GLOBALS['conexion'], (string)$busqueda), '%_');
                     $sql = "SELECT * FROM colaboradores WHERE NOM_COLABORADOR LIKE '%".$texto."%' AND ESTADO= '1' ORDER BY NOM_COLABORADOR ASC";
             }
             return ejecutarConsulta($sql);
             
     }
     //Centros activos para el buscador (sin uso: la pantalla usa selectCentroUsuario)
     public function selectCentro($busqueda)
     {
             if ($busqueda === 0) {
                     $sql = "SELECT * FROM centros_operacion WHERE ESTADO= '1' ORDER BY NOM_CENTRO_OP ASC";
             } else {
                     //El texto de búsqueda se escapa (comillas) y se neutralizan los comodines % y _ antes del LIKE
                     $texto = addcslashes(mysqli_real_escape_string($GLOBALS['conexion'], (string)$busqueda), '%_');
                     $sql = "SELECT * FROM centros_operacion WHERE (NOM_CENTRO_OP LIKE '%".$texto."%' OR ID_CENTRO_OP LIKE '%".$texto."%') AND ESTADO= '1' ORDER BY NOM_CENTRO_OP ASC";
             }
             return ejecutarConsulta($sql);
             
     }
     //Trae SOLO los centros de operación asignados al usuario que tiene la sesión abierta.
     //Así nadie diligencia una lista en un centro que no le corresponde (y que después
     //no vería en "Mostrar respuestas", que también filtra por los centros asignados)
     public function selectCentroUsuario($busqueda, $idUsuario, $todos = false) //$todos: el ADMIN TEC ve todos los centros
     {
             $idUsuario = intval($idUsuario); //solo número, nunca texto del usuario
             $filtro = "";
             if ($busqueda !== 0 && $busqueda !== '0' && $busqueda !== '') {
                     //Los paréntesis son necesarios: sin ellos el OR se mezcla con el AND
                     //del estado y aparecen centros inactivos al buscar por nombre
                     $texto = addcslashes(mysqli_real_escape_string($GLOBALS['conexion'], (string)$busqueda), '%_');
                     $filtro = " AND (centros_operacion.NOM_CENTRO_OP LIKE '%".$texto."%'
                                   OR centros_operacion.ID_CENTRO_OP LIKE '%".$texto."%') ";
             }
             //Los peajes asignados a la persona en Colaboradores (el ADMIN TEC, todos)
             $sql = "SELECT centros_operacion.ID_CENTRO_OP, centros_operacion.NOM_CENTRO_OP
                     FROM centros_operacion
                     WHERE (centros_operacion.ID_CENTRO_OP IN (SELECT ID_CENTRO_OP FROM asoc_usuarios_sistemas_x_cop
                                                     WHERE ID_COLABORADOR = $idUsuario)
                          OR " . ($todos ? "1 = 1" : "1 = 0") . ")
                       AND centros_operacion.ESTADO = '1'
                       $filtro
                     ORDER BY centros_operacion.NOM_CENTRO_OP ASC";
             return ejecutarConsulta($sql);
     }

        //Un centro activo por su id (sin uso: ningún controlador lo llama)
        public function selectCentroId($idCentro)
        {
                $sql = "SELECT * FROM centros_operacion WHERE ID_CENTRO_OP = $idCentro AND ESTADO= '1' ORDER BY NOM_CENTRO_OP ASC";
                return ejecutarConsulta($sql);
        }

        //Centro y tipo de lista de una respuesta (sin uso: el controlador usa getCentro2)
        public function getCentro($idRespuesta)
        {
                $idRespuesta = intval($idRespuesta); //solo números en la consulta
                $sql = "SELECT centros_operacion.ID_CENTRO_OP,lista_chequeo.ID_GRUPO_LISTA_CHEQUEO from detalle_lista_chequeo INNER JOIN lista_chequeo on lista_chequeo.ID_LISTA_CHEQUEO=detalle_lista_chequeo.ID_LISTA_CHEQUEO INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=lista_chequeo.ID_CENTRO_OP WHERE detalle_lista_chequeo.ID_DETALLE_LISTA_CHEQUEO=$idRespuesta";
                return ejecutarConsultaSimpleFila($sql);
        }
	
        //¿Este centro está asignado a este usuario? Así nadie mueve una lista a
        //un peaje ajeno modificando el formulario
        public function centroAsignado($idUsuario, $idCentro, $todos = false)
        {
                $idUsuario = intval($idUsuario);
                $idCentro  = intval($idCentro);
                //Asignado a la persona en Colaboradores (el ADMIN TEC, todos)
                $sql = "SELECT 1 AS ok FROM centros_operacion
                         WHERE centros_operacion.ID_CENTRO_OP = $idCentro
                           AND (centros_operacion.ID_CENTRO_OP IN (SELECT ID_CENTRO_OP FROM asoc_usuarios_sistemas_x_cop
                                                     WHERE ID_COLABORADOR = $idUsuario)
                          OR " . ($todos ? "1 = 1" : "1 = 0") . ") LIMIT 1";
                return (bool)ejecutarConsultaSimpleFila($sql);
        }

        //La fecha de una lista y quién la llenó (por el id de la lista o de una de sus respuestas).
        //Devuelve array('FECHA' => fecha, 'DUENO' => id del colaborador) o null
        public function fechaYDueno($idLista, $idRespuesta)
        {
                require_once __DIR__ . '/HanaDB.php';
                if ((int)$idLista > 0) {
                        return HanaDB::fila("SELECT FEC_REGISTRO AS FECHA, ID_COLABORADOR AS DUENO FROM lista_chequeo WHERE ID_LISTA_CHEQUEO = ?", 'i', array((int)$idLista));
                }
                return HanaDB::fila("SELECT l.FEC_REGISTRO AS FECHA, l.ID_COLABORADOR AS DUENO FROM detalle_lista_chequeo d
                                       INNER JOIN lista_chequeo l ON l.ID_LISTA_CHEQUEO = d.ID_LISTA_CHEQUEO
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
                           AND otra.ID_CENTRO_OP = $idCentro
                           AND DATE(otra.FEC_REGISTRO) = DATE(esta.FEC_REGISTRO)
                         LIMIT 1";
                return (bool)ejecutarConsultaSimpleFila($sql);
        }

        //Cambia el centro operativo de una lista ya diligenciada
        public function cambiarCentro($idLista, $idCentro)
        {
                $sql = "UPDATE lista_chequeo SET ID_CENTRO_OP = " . intval($idCentro) . "
                         WHERE ID_LISTA_CHEQUEO = " . intval($idLista);
                return ejecutarConsulta($sql);
        }

        //Centro y tipo de lista de una lista diligenciada
        public function getCentro2($idLista)
        {
                $idLista = intval($idLista); //solo números en la consulta
                $sql = "SELECT lista_chequeo.ID_CENTRO_OP, lista_chequeo.ID_GRUPO_LISTA_CHEQUEO from lista_chequeo WHERE lista_chequeo.ID_LISTA_CHEQUEO=$idLista";
                return ejecutarConsultaSimpleFila($sql);
        }
}