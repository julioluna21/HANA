<?php
require "../Conexion/ConexionDB.php";
class Titulo
{
        //Implementamos el super constructor 
        public function __construct()
        {
        }
        //Implementamos un método para insertar registros 	
        public function insertar($NOMBRE)
        {
                $sql = "INSERT INTO titulo_novedades_hallazgos(NOM_TITULO_NOVEDADES_HALLAZGOS,ESTADO)
                            VALUES ('$NOMBRE','1')";
                return ejecutarConsulta($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Implementamos un método para editar registros
        public function editar($IDETITULO,$NOMBRE)
        {
                $sql = "UPDATE titulo_novedades_hallazgos SET NOM_TITULO_NOVEDADES_HALLAZGOS='$NOMBRE' where ID_TITULO_NOVEDADES_HALLAZGOS=$IDETITULO";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para mostrar los datos de un registro a modificar
        public function mostrar($IDETITULO)
        {
                $sql = "SELECT * FROM titulo_novedades_hallazgos WHERE ID_TITULO_NOVEDADES_HALLAZGOS='$IDETITULO'";
                return ejecutarConsultaSimpleFila($sql);
        }
        //Implementar un método para listar los registros
        public function listar()
        {
                $sql = "SELECT * FROM titulo_novedades_hallazgos";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para listar los registros
        public function select()
        {
                $sql = "SELECT * FROM titulo_novedades_hallazgos WHERE ESTADO=1";
                return ejecutarConsulta($sql);
        }
        public function desactivar($IDETITULO)
        {
                $sql = "UPDATE titulo_novedades_hallazgos SET ESTADO=0 WHERE ID_TITULO_NOVEDADES_HALLAZGOS=$IDETITULO";
                return ejecutarConsulta($sql);
        }
        public function activar($IDETITULO)
        {
               $sql = "UPDATE titulo_novedades_hallazgos SET ESTADO=1 WHERE ID_TITULO_NOVEDADES_HALLAZGOS=$IDETITULO";
                return ejecutarConsulta($sql);
        }
		
}