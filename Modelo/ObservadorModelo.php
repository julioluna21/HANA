<?php
/*
  HANA - Observadores (modelo, sin uso)
  ---------------------------------------------------------------------------
  Consultas de la tabla observador_novedades_hallazgos, que ya no existe en la
  base (la quitó el script 32: quien reporta la novedad es quien la vio).
  Ningún archivo lo carga; se conserva solo como referencia.
*/
require "../Conexion/ConexionDB.php";
class Observador
{
        //Constructor vacío: la clase no guarda datos propios
        public function __construct()
        {
        }
        //Crea un observador activo
        public function insertar($nombre)
        {
                $sql = "INSERT INTO observador_novedades_hallazgos(NOM_OBSERVADOR_NOVEDADES_HALLAZGOS,ESTADO)
                            VALUES ('$nombre','1')";
                return ejecutarConsulta($sql); //ejecutarConsulta está en Conexion/ConexionDB.php
        }
        //Cambia el nombre de un observador
        public function editar($idObservador,$nombre)
        {
                $sql = "UPDATE observador_novedades_hallazgos SET NOM_OBSERVADOR_NOVEDADES_HALLAZGOS='$nombre' where ID_OBSERVADOR_NOVEDADES_HALLAZGOS=$idObservador";
                return ejecutarConsulta($sql);
        }
        //Un observador, para cargarlo en el formulario
        public function mostrar($idObservador)
        {
                $sql = "SELECT * FROM observador_novedades_hallazgos WHERE ID_OBSERVADOR_NOVEDADES_HALLAZGOS='$idObservador'";
                return ejecutarConsultaSimpleFila($sql);
        }
        //Todos los observadores
        public function listar()
        {
                $sql = "SELECT * FROM observador_novedades_hallazgos";
                return ejecutarConsulta($sql);
        }
        //Los observadores activos (desplegable)
        public function select()
        {
                $sql = "SELECT * FROM observador_novedades_hallazgos WHERE ESTADO=1";
                return ejecutarConsulta($sql);
        }
        //Desactiva un observador (no se borra)
        public function desactivar($idObservador)
        {
                $sql = "UPDATE observador_novedades_hallazgos SET ESTADO=0 WHERE ID_OBSERVADOR_NOVEDADES_HALLAZGOS=$idObservador";
                return ejecutarConsulta($sql);
        }
        //Vuelve a activar un observador
        public function activar($idObservador)
        {
               $sql = "UPDATE observador_novedades_hallazgos SET ESTADO=1 WHERE ID_OBSERVADOR_NOVEDADES_HALLAZGOS=$idObservador";
                return ejecutarConsulta($sql);
        }
		
}