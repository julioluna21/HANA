<?php
//Incluímos inicialmente la conexión a la base de datos
require "../Conexion/ConexionDB.php";
class colaborador
{
        //Implementamos el super constructor 
        public function __construct()
        {
        }
        //Implementamos un método para insertar registros
        public function insertar($cedula, $nombre, $correo, $idCargo)
        {
                $sql = "INSERT INTO colaboradores (`DOC_COLABORADO`, `NOM_COLABORADOR`, `MAIL_COLABORADOR`, `ID_CARGO_COLABORADORES_COLABORADORES`, `ESTADO`)
                            VALUES ('$cedula','$nombre','$correo','$idCargo',1)";
                return ejecutarConsulta($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Implementamos un método para editar registros
        public function editar($id, $cedula, $nombre, $correo, $idCargo)
        {
                $sql = "UPDATE colaboradores SET DOC_COLABORADO = '$cedula', NOM_COLABORADOR='$nombre', MAIL_COLABORADOR='$correo', ID_CARGO_COLABORADORES_COLABORADORES='$idCargo', ESTADO='1' where 
                    ID_COLABORADOR=$id";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para mostrar los datos de un registro a modificar
        public function mostrar($id)
        {
                $sql = "SELECT * FROM colaboradores WHERE ID_COLABORADOR='$id'";
                return ejecutarConsultaSimpleFila($sql);
        }
        //Implementar un método para listar los registros
        public function listar()
        {
                $sql = "SELECT colaboradores.*,cargos_colaboladores.NOM_CARGO_COLABORADORES AS cargo FROM colaboradores INNER JOIN cargos_colaboladores ON cargos_colaboladores.ID_CARGO_COLABORADORES=colaboradores.ID_CARGO_COLABORADORES_COLABORADORES";
                return ejecutarConsulta($sql);
        }
        public function anular($id)
        {
                $sql = "UPDATE colaboradores SET ESTADO=0 WHERE ID_COLABORADOR=$id";
                return ejecutarConsulta($sql);
        }
        public function select()
        {
                $sql = "SELECT * FROM colaboradores WHERE ESTADO=1";
                return ejecutarConsulta($sql);
        }
}
