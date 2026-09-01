<?php
require "../Conexion/ConexionDB.php";
class Observador
{
        //Implementamos el super constructor 
        public function __construct()
        {
        }
        //Implementamos un método para insertar registros 	
        public function insertar($NOMBRE)
        {
                $sql = "INSERT INTO observador_novedades_hallazgos(NOM_OBSERVADOR_NOVEDADES_HALLAZGOS,ESTADO)
                            VALUES ('$NOMBRE','1')";
                return ejecutarConsulta($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Implementamos un método para editar registros
        public function editar($IDOBSERVADOR,$NOMBRE)
        {
                $sql = "UPDATE observador_novedades_hallazgos SET NOM_OBSERVADOR_NOVEDADES_HALLAZGOS='$NOMBRE' where ID_OBSERVADOR_NOVEDADES_HALLAZGOS=$IDOBSERVADOR";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para mostrar los datos de un registro a modificar
        public function mostrar($IDOBSERVADOR)
        {
                $sql = "SELECT * FROM observador_novedades_hallazgos WHERE ID_OBSERVADOR_NOVEDADES_HALLAZGOS='$IDOBSERVADOR'";
                return ejecutarConsultaSimpleFila($sql);
        }
        //Implementar un método para listar los registros
        public function listar()
        {
                $sql = "SELECT * FROM observador_novedades_hallazgos";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para listar los registros
        public function select()
        {
                $sql = "SELECT * FROM observador_novedades_hallazgos WHERE ESTADO=1";
                return ejecutarConsulta($sql);
        }
        public function desactivar($IDOBSERVADOR)
        {
                $sql = "UPDATE observador_novedades_hallazgos SET ESTADO=0 WHERE ID_OBSERVADOR_NOVEDADES_HALLAZGOS=$IDOBSERVADOR";
                return ejecutarConsulta($sql);
        }
        public function activar($IDOBSERVADOR)
        {
               $sql = "UPDATE observador_novedades_hallazgos SET ESTADO=1 WHERE ID_OBSERVADOR_NOVEDADES_HALLAZGOS=$IDOBSERVADOR";
                return ejecutarConsulta($sql);
        }
		
}