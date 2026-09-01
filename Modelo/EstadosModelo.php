<?php
require "../Conexion/ConexionDB.php";
class Estados
{
        //Implementamos el super constructor 
        public function __construct()
        {
        }
        //Implementamos un método para insertar registros 	
        public function insertar($NOMBRE,$dias)
        {
                $sql = "INSERT INTO estados_relevancia(NOMBRE_ESTADOS_RELEVANCIA,DIAS_ESTADOS_RELEVANCIAS,ESTADO)
                            VALUES ('$NOMBRE','$dias','1')";
                return ejecutarConsulta($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Implementamos un método para editar registros
        public function editar($idestado,$NOMBRE,$dias)
        {
                $sql = "UPDATE estados_relevancia SET NOMBRE_ESTADOS_RELEVANCIA='$NOMBRE',DIAS_ESTADOS_RELEVANCIAS='$dias' where ID_ESTADOS_RELEVANCIA=$idestado";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para mostrar los datos de un registro a modificar
        public function mostrar($idestado)
        {
                $sql = "SELECT * FROM estados_relevancia WHERE ID_ESTADOS_RELEVANCIA='$idestado'";
                return ejecutarConsultaSimpleFila($sql);
        }
        //Implementar un método para listar los registros
        public function listar()
        {
                $sql = "SELECT * FROM estados_relevancia";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para listar los registros
        public function select()
        {
                $sql = "SELECT * FROM estados_relevancia WHERE ESTADO=1";
                return ejecutarConsulta($sql);
        }
        public function desactivar($IDrol)
        {
                $sql = "UPDATE estados_relevancia SET ESTADO=0 WHERE ID_ESTADOS_RELEVANCIA=$IDrol";
                return ejecutarConsulta($sql);
        }
        public function activar($IDrol)
        {
               $sql = "UPDATE estados_relevancia SET ESTADO=1 WHERE ID_ESTADOS_RELEVANCIA=$IDrol";
                return ejecutarConsulta($sql);
        }
		
}