<?php
/*
  HANA - Estados de prioridad (modelo, sin uso)
  ---------------------------------------------------------------------------
  Consultas de la tabla estados_relevancia (la prioridad de una novedad y los
  días para atenderla), que ya no existe en la base: la quitó el script 35. Las
  prioridades están en Modelo/NovedadPrioridad.php. Solo lo carga
  Control/EstadoControl.php, que tampoco se usa; se conserva como referencia.
*/
require "../Conexion/ConexionDB.php";
class Estados
{
        //Constructor vacío: la clase no guarda datos propios
        public function __construct()
        {
        }
        //Crea un estado de prioridad activo
        public function insertar($nombre,$dias)
        {
                $sql = "INSERT INTO estados_relevancia(NOMBRE_ESTADOS_RELEVANCIA,DIAS_PARA_ATENDER,ESTADO)
                            VALUES ('$nombre'," . intval($dias) . ",'1')";
                return ejecutarConsulta($sql); //ejecutarConsulta está en Conexion/ConexionDB.php
        }
        //Cambia el nombre y los días de un estado de prioridad
        public function editar($idEstado,$nombre,$dias)
        {
                $idEstado = intval($idEstado); //solo números en la consulta
                $sql = "UPDATE estados_relevancia SET NOMBRE_ESTADOS_RELEVANCIA='$nombre',DIAS_PARA_ATENDER=" . intval($dias) . " where ID_ESTADOS_RELEVANCIA=$idEstado";
                return ejecutarConsulta($sql);
        }
        //Un estado de prioridad, para cargarlo en el formulario
        public function mostrar($idEstado)
        {
                $sql = "SELECT * FROM estados_relevancia WHERE ID_ESTADOS_RELEVANCIA='$idEstado'";
                return ejecutarConsultaSimpleFila($sql);
        }
        //Todos los estados de prioridad
        public function listar()
        {
                $sql = "SELECT * FROM estados_relevancia";
                return ejecutarConsulta($sql);
        }
        //Los estados de prioridad activos (desplegable)
        public function select()
        {
                $sql = "SELECT * FROM estados_relevancia WHERE ESTADO=1";
                return ejecutarConsulta($sql);
        }
        //Desactiva un estado de prioridad (no se borra)
        public function desactivar($idEstado)
        {
                $idEstado = intval($idEstado); //solo números en la consulta
                $sql = "UPDATE estados_relevancia SET ESTADO=0 WHERE ID_ESTADOS_RELEVANCIA=$idEstado";
                return ejecutarConsulta($sql);
        }
        //Vuelve a activar un estado de prioridad
        public function activar($idEstado)
        {
               $idEstado = intval($idEstado); //solo números en la consulta
               $sql = "UPDATE estados_relevancia SET ESTADO=1 WHERE ID_ESTADOS_RELEVANCIA=$idEstado";
                return ejecutarConsulta($sql);
        }
		
}