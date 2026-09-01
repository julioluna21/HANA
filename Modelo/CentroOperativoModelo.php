<?php
//Incluímos inicialmente la conexión a la base de datos
require "../Conexion/ConexionDB.php";
class Centro
{
        //Implementamos el super constructor 
        public function __construct()
        {
        }
        //Implementamos un método para insertar registros
        public function insertar($nombrecentro, $proyecto)
        {
                $sql = "INSERT INTO `centros_operacion`(`NOM_CENTRO_OP`, `ID_PROYECTO_CENTRO_OP`, `Estado`)
                            VALUES ('$nombrecentro','$proyecto','1')";
                return ejecutarConsulta($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Implementamos un método para editar registros
        public function editar($id, $nombrecentro, $proyecto)
        {
                $sql = "UPDATE centros_operacion SET NOM_CENTRO_OP='$nombrecentro', ID_PROYECTO_CENTRO_OP=$proyecto, Estado='1' where ID_CENTRO_OP=$id";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para mostrar los datos de un registro a modificar
        public function mostrar($id)
        {
                $sql = "SELECT centros_operacion.*, proyectos.ID_PROYECTO, proyectos.NOM_PROYECTO FROM centros_operacion INNER JOIN proyectos ON centros_operacion.ID_PROYECTO_CENTRO_OP = proyectos.ID_PROYECTO WHERE ID_CENTRO_OP='$id'";
                return ejecutarConsultaSimpleFila($sql);
        }
        //Implementar un método para listar los registros
        public function listar()
        {
                $sql = "SELECT centros_operacion.*,proyectos.NOM_PROYECTO FROM centros_operacion INNER JOIN proyectos ON centros_operacion.ID_PROYECTO_CENTRO_OP=proyectos.ID_PROYECTO";
                return ejecutarConsulta($sql);
        }
        //Implementamos un método para editar reasignar contraseña
        public function anular($id)
        {
                $sql = "UPDATE centros_operacion SET Estado=0 WHERE ID_CENTRO_OP=$id";
                return ejecutarConsulta($sql);
        }
        public function select()
        {
                $sql = "SELECT * FROM centros_operacion WHERE Estado=1";
                return ejecutarConsulta($sql);
        }
}
