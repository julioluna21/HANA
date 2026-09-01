<?php
require "../Conexion/ConexionDB.php";
class Roles
{
        //Implementamos el super constructor 
        public function __construct()
        {
        }
        //Implementamos un método para insertar registros 	
        public function insertar($NOMBRE_rol,$modulos,$auditoria)
        {
                $sql = "INSERT INTO rol_usuarios_sistemas(NOM_ROL_USUARIO_SISTEMA,MODULOS_ROL_USUARIOS_SISTEMAS,PERMISOS_CERRAR_NOVEDADES,ESTADO)
                            VALUES ('$NOMBRE_rol','$modulos','$auditoria','1')";
                return ejecutarConsulta($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Implementamos un método para editar registros
        public function editar($idrol,$NOMBRE_rol,$modulos,$auditoria)
        {
                $sql = "UPDATE rol_usuarios_sistemas SET NOM_ROL_USUARIO_SISTEMA='$NOMBRE_rol',MODULOS_ROL_USUARIOS_SISTEMAS='$modulos', PERMISOS_CERRAR_NOVEDADES= '$auditoria' where ID_ROL_USUARIO_SISTEMA=$idrol";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para mostrar los datos de un registro a modificar
        public function mostrar($ID_PERFIL)
        {
                $sql = "SELECT * FROM rol_usuarios_sistemas WHERE ID_ROL_USUARIO_SISTEMA='$ID_PERFIL'";
                return ejecutarConsultaSimpleFila($sql);
        }
        //Implementar un método para listar los registros
        public function listar()
        {
                $sql = "SELECT * FROM rol_usuarios_sistemas";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para listar los registros
        public function select()
        {
                $sql = "SELECT * FROM rol_usuarios_sistemas WHERE ESTADO=1";
                return ejecutarConsulta($sql);
        }
        public function desactivar($IDrol)
        {
                $sql = "UPDATE rol_usuarios_sistemas SET ESTADO=0 WHERE ID_ROL_USUARIO_SISTEMA=$IDrol";
                return ejecutarConsulta($sql);
        }
        public function activar($IDrol)
        {
               $sql = "UPDATE rol_usuarios_sistemas SET ESTADO=1 WHERE ID_ROL_USUARIO_SISTEMA=$IDrol";
                return ejecutarConsulta($sql);
        }
		
}
