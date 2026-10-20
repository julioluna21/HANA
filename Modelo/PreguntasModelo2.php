<?php
/*
  HANA - Preguntas de las listas (versión del sistema original, sin uso)
  ---------------------------------------------------------------------------
  Trabaja sobre la tabla preguntas_lista, que no existe en la base: las
  preguntas viven en detalle_grupo_lista_chequeo (Modelo/PreguntasListasModelo.php).
  Ningún archivo lo carga; se conserva solo como referencia.
*/
require "../Conexion/ConexionDB.php";
class Preguntas
{
        //Constructor vacío: la clase no guarda datos propios
        public function __construct()
        {
        }
        //Crea una pregunta
        public function insertar($pregunta, $tipoRespuesta,$grupo)
        {
                $sql = "INSERT INTO preguntas_lista(PREGUNTA_LISTA_CHEQUEO,TIPO_RESPUESTA_PREGUNTA,ID_PREGUNTAS_LISTA_CHEQUEO_GRUPO_LISTA_CHEQUEO,Estado)
                            VALUES ('$pregunta','$tipoRespuesta','$grupo','1')";
                return ejecutarConsulta($sql); //ejecutarConsulta está en Conexion/ConexionDB.php
        }
        //Cambia el texto y el tipo de respuesta de una pregunta
        public function editar($id, $pregunta,$tipoRespuesta)
        {
                $sql = "UPDATE preguntas_lista SET PREGUNTA_LISTA_CHEQUEO='$pregunta',TIPO_RESPUESTA_PREGUNTA='$tipoRespuesta', Estado='1' where 
                    ID_PREGUNTAS_LISTA_CHEQUEO=$id";
                return ejecutarConsulta($sql);
        }
        //Una pregunta, para cargarla en el formulario
        public function mostrar($id)
        {
                $sql = "SELECT * FROM preguntas_lista WHERE ID_PREGUNTAS_LISTA_CHEQUEO='$id'";
                return ejecutarConsultaSimpleFila($sql);
        }
        //Todas las preguntas
        public function listar()
        {
                $sql = "SELECT * FROM preguntas_lista";
                return ejecutarConsulta($sql);
        }
        //Desactiva una pregunta (no se borra)
        public function anular($id)
        {
                $sql = "UPDATE preguntas_lista SET Estado=0 WHERE ID_PREGUNTAS_LISTA_CHEQUEO=$id";
                return ejecutarConsulta($sql);
        }
        //Preguntas activas para el buscador; si se escribió algo, filtra por el texto
        public function select($busqueda)
        {
                if ($busqueda === 0) {
                        $sql = "SELECT * FROM preguntas_lista WHERE Estado= '1' ORDER BY PREGUNTA_LISTA_CHEQUEO ASC";
                        return ejecutarConsulta($sql);
                } else {
                        $sql = "SELECT * FROM preguntas_lista WHERE PREGUNTA_LISTA_CHEQUEO LIKE '%".$busqueda."%' AND Estado= '1' ORDER BY PREGUNTA_LISTA_CHEQUEO ASC";
                        return ejecutarConsulta($sql);
                }
                
        }
}
