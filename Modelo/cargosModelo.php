<?php
/*
  HANA - Cargos (modelo)
  ---------------------------------------------------------------------------
  Consultas de la tabla cargos_colaboradores.
  Lo usa Control/cargosControl.php.
*/
//Incluímos inicialmente la conexión a la base de datos
require "../Conexion/ConexionDB.php";
Class Cargos
	{
		//Implementamos el super constructor 
		public function __construct()
		{
		}
		//Implementamos un método para insertar registros
		public function insertar($NombreCargos){
			$sql="INSERT INTO `cargos_colaboradores`(`NOM_CARGO_COLABORADORES`,`ESTADO`)VALUES ('$NombreCargos','1')";
			return ejecutarConsulta($sql);
		}
		//Implementamos un método para editar registros
		public function editar($IdCargos, $NombreCargos)
		{
			$sql="UPDATE cargos_colaboradores SET NOM_CARGO_COLABORADORES='$NombreCargos', ESTADO='1' WHERE ID_CARGO_COLABORADORES='$IdCargos'";
			return ejecutarConsulta($sql);
		}
		//Implementar un método para listar los registros
		public function listar()
		{
			$sql="SELECT cargos_colaboradores.* FROM cargos_colaboradores WHERE NOM_CARGO_COLABORADORES != 'Desarrollador'";
			return ejecutarConsulta($sql);
		}
		//Implementar un método para mostrar los registros
		public function mostrar($IdCargos)
		{
			$sql="SELECT cargos_colaboradores.* FROM cargos_colaboradores WHERE ID_CARGO_COLABORADORES='$IdCargos'";
			return ejecutarConsultaSimpleFila($sql);
		}
		//Implementamos un método para editar registros
		public function desactivar($IdCargos)
		{
			$sql="UPDATE cargos_colaboradores SET ESTADO = '0' WHERE ID_CARGO_COLABORADORES='$IdCargos'";
			return ejecutarConsulta($sql);
		}
		//Implementar un método para listar los registros
		public function select($search_term)
		{
			
			if ($search_term === 0) {
				$sql="SELECT * FROM cargos_colaboradores WHERE NOM_CARGO_COLABORADORES != 'Desarrollador' AND ESTADO = 1";
				return ejecutarConsulta($sql);
		} else {
				$sql = "SELECT * FROM cargos_colaboradores WHERE NOM_CARGO_COLABORADORES LIKE '%".$search_term."%' AND NOM_CARGO_COLABORADORES != 'Desarrollador' AND Estado= '1' ORDER BY NOM_CARGO_COLABORADORES ASC";
				return ejecutarConsulta($sql);
		}
		}
	}
?>