<?php
//Incluímos inicialmente la conexión a la base de datos
require "../Conexion/ConexionDB.php";
class Preguntas
{
        //Implementamos el super constructor 
        public function __construct()
        {
        }
        //Implementamos un método para insertar registros
        public function insertar($pregunta, $tipoRespuesta,$grupo)
        {
                $sql = "INSERT INTO preguntas_lista(PREGUNTA_LISTA_CHEQUEO,TIPO_RESPUESTA_PREGUNTA,ID_PREGUNTAS_LISTA_CHEQUEO_GRUPO_LISTA_CHEQUEO,Estado)
                            VALUES ('$pregunta','$tipoRespuesta','$grupo','1')";
                return ejecutarConsulta($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Implementamos un método para editar registros
        public function editar($id, $pregunta,$tipoRespuesta)
        {
                $sql = "UPDATE preguntas_lista SET PREGUNTA_LISTA_CHEQUEO='$pregunta',TIPO_RESPUESTA_PREGUNTA='$tipoRespuesta', Estado='1' where 
                    ID_PREGUNTAS_LISTA_CHEQUEO=$id";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para mostrar los datos de un registro a modificar
        public function mostrar($id)
        {
                $sql = "SELECT * FROM preguntas_lista WHERE ID_PREGUNTAS_LISTA_CHEQUEO='$id'";
                return ejecutarConsultaSimpleFila($sql);
        }
        //Implementar un método para listar los registros
        public function listar()
        {
                $sql = "SELECT * FROM preguntas_lista";
                return ejecutarConsulta($sql);
        }
        //Implementamos un método para editar reasignar contraseña
        public function anular($id)
        {
                $sql = "UPDATE preguntas_lista SET Estado=0 WHERE ID_PREGUNTAS_LISTA_CHEQUEO=$id";
                return ejecutarConsulta($sql);
        }
        public function select($search_term)
        {
                if ($search_term === 0) {
                        $sql = "SELECT * FROM preguntas_lista WHERE Estado= '1' ORDER BY PREGUNTA_LISTA_CHEQUEO ASC";
                        return ejecutarConsulta($sql);
                } else {
                        $sql = "SELECT * FROM preguntas_lista WHERE PREGUNTA_LISTA_CHEQUEO LIKE '%".$search_term."%' AND Estado= '1' ORDER BY PREGUNTA_LISTA_CHEQUEO ASC";
                        return ejecutarConsulta($sql);
                }
                
        }
}
