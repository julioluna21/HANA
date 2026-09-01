<?php
//Incluímos inicialmente la conexión a la base de datos
require "../Conexion/ConexionDB.php";
Class Cargos
	{
		//Implementamos el super constructor 
		public function __construct()
		{
		}
		//Implementamos un método para insertar registros
		public function insertar($NombreCargos,$PermisoNovedad){
			$sql="INSERT INTO `cargos_colaboladores`(`NOM_CARGO_COLABORADORES`,PERMISO_ASIGNAR_NOVEDAD,`ESTADO`)VALUES ('$NombreCargos',$PermisoNovedad,'1')";
			return ejecutarConsulta($sql);
		}
		//Implementamos un método para editar registros
		public function editar($IdCargos, $NombreCargos,$PermisoNovedad)
		{
			$sql="UPDATE cargos_colaboladores SET NOM_CARGO_COLABORADORES='$NombreCargos',PERMISO_ASIGNAR_NOVEDAD='$PermisoNovedad', ESTADO='1' WHERE ID_CARGO_COLABORADORES='$IdCargos'";
			return ejecutarConsulta($sql);
		}
		//Implementar un método para listar los registros
		public function listar()
		{
			$sql="SELECT cargos_colaboladores.* FROM cargos_colaboladores WHERE NOM_CARGO_COLABORADORES != 'Desarrollador'";
			return ejecutarConsulta($sql);
		}
		//Implementar un método para mostrar los registros
		public function mostrar($IdCargos)
		{
			$sql="SELECT cargos_colaboladores.* FROM cargos_colaboladores WHERE ID_CARGO_COLABORADORES='$IdCargos'";
			return ejecutarConsultaSimpleFila($sql);
		}
		//Implementamos un método para editar registros
		public function desactivar($IdCargos)
		{
			$sql="UPDATE cargos_colaboladores SET ESTADO = '0' WHERE ID_CARGO_COLABORADORES='$IdCargos'";
			return ejecutarConsulta($sql);
		}
		//Implementar un método para listar los registros
		public function select($search_term)
		{
			
			if ($search_term === 0) {
				$sql="SELECT * FROM cargos_colaboladores WHERE NOM_CARGO_COLABORADORES != 'Desarrollador' AND ESTADO = 1";
				return ejecutarConsulta($sql);
		} else {
				$sql = "SELECT * FROM cargos_colaboladores WHERE NOM_CARGO_COLABORADORES LIKE '%".$search_term."%' AND NOM_CARGO_COLABORADORES != 'Desarrollador' AND Estado= '1' ORDER BY NOM_CARGO_COLABORADORES ASC";
				return ejecutarConsulta($sql);
		}
		}
	}
?>