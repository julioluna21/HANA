<?php
/*
  HANA - Cargos (modelo, sin uso)
  ---------------------------------------------------------------------------
  Consultas de la tabla cargos_colaboradores, que ya no existe en la base (la
  quitó el script 35: la función de cada persona es su rol). Ningún archivo lo
  carga; se conserva solo como referencia.
*/
require "../Conexion/ConexionDB.php";
Class Cargos
	{
		//Constructor vacío: la clase no guarda datos propios
		public function __construct()
		{
		}
		//Crea un cargo activo
		public function insertar($NombreCargos){
			$sql="INSERT INTO `cargos_colaboradores`(`NOM_CARGO_COLABORADORES`,`ESTADO`)VALUES ('$NombreCargos','1')";
			return ejecutarConsulta($sql);
		}
		//Cambia el nombre de un cargo
		public function editar($IdCargos, $NombreCargos)
		{
			$sql="UPDATE cargos_colaboradores SET NOM_CARGO_COLABORADORES='$NombreCargos', ESTADO='1' WHERE ID_CARGO_COLABORADORES='$IdCargos'";
			return ejecutarConsulta($sql);
		}
		//Todos los cargos
		public function listar()
		{
			$sql="SELECT cargos_colaboradores.* FROM cargos_colaboradores WHERE NOM_CARGO_COLABORADORES != 'Desarrollador'";
			return ejecutarConsulta($sql);
		}
		//Un cargo, para cargarlo en el formulario
		public function mostrar($IdCargos)
		{
			$sql="SELECT cargos_colaboradores.* FROM cargos_colaboradores WHERE ID_CARGO_COLABORADORES='$IdCargos'";
			return ejecutarConsultaSimpleFila($sql);
		}
		//Desactiva un cargo (no se borra)
		public function desactivar($IdCargos)
		{
			$sql="UPDATE cargos_colaboradores SET ESTADO = '0' WHERE ID_CARGO_COLABORADORES='$IdCargos'";
			return ejecutarConsulta($sql);
		}
		//Cargos activos para el buscador; si se escribió algo, filtra por el nombre
		public function select($busqueda)
		{
			
			if ($busqueda === 0) {
				$sql="SELECT * FROM cargos_colaboradores WHERE NOM_CARGO_COLABORADORES != 'Desarrollador' AND ESTADO = 1";
				return ejecutarConsulta($sql);
		} else {
				//El texto de búsqueda se escapa (comillas) y se neutralizan los comodines % y _ antes del LIKE
				$texto = addcslashes(mysqli_real_escape_string($GLOBALS['conexion'], (string)$busqueda), '%_');
				$sql = "SELECT * FROM cargos_colaboradores WHERE NOM_CARGO_COLABORADORES LIKE '%".$texto."%' AND NOM_CARGO_COLABORADORES != 'Desarrollador' AND Estado= '1' ORDER BY NOM_CARGO_COLABORADORES ASC";
				return ejecutarConsulta($sql);
		}
		}
	}
?>