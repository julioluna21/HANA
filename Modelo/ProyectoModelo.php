<?php
//Incluímos inicialmente la conexión a la base de datos
require "../Conexion/ConexionDB.php";
class Proyecto
{
        //Implementamos el super constructor 
        public function __construct()
        {
        }
        //Implementamos un método para insertar registros
        public function insertar($nombreproyecto)
        {
                $sql = "INSERT INTO proyectos(NOM_PROYECTO,Estado)
                            VALUES ('$nombreproyecto','1')";
                return ejecutarConsulta($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Implementamos un método para editar registros
        public function editar($id, $nombreproyecto)
        {
                $sql = "UPDATE proyectos SET NOM_PROYECTO='$nombreproyecto', Estado='1' where 
                    ID_PROYECTO=$id";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para mostrar los datos de un registro a modificar
        public function mostrar($id)
        {
                $sql = "SELECT * FROM proyectos WHERE ID_PROYECTO='$id'";
                return ejecutarConsultaSimpleFila($sql);
        }
        //Implementar un método para listar los registros
        public function listar()
        {
                $sql = "SELECT * FROM proyectos";
                return ejecutarConsulta($sql);
        }
        //Implementamos un método para editar reasignar contraseña
        public function anular($id)
        {
                $sql = "UPDATE proyectos SET Estado=0 WHERE ID_PROYECTO=$id";
                return ejecutarConsulta($sql);
        }
        public function select($search_term)
        {
                if ($search_term === 0) {
                        $sql = "SELECT * FROM proyectos WHERE Estado= '1' ORDER BY NOM_PROYECTO ASC";
                        return ejecutarConsulta($sql);
                } else {
                        $sql = "SELECT * FROM proyectos WHERE NOM_PROYECTO LIKE '%".$search_term."%' AND Estado= '1' ORDER BY NOM_PROYECTO ASC";
                        return ejecutarConsulta($sql);
                }
                
        }
}
