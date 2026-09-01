<?php
require "../Conexion/ConexionDB.php";
class Usuarios
{
        //Implementamos el super constructor 
        public function __construct()
        {
        }
        //Implementamos un método para insertar registros 	
        public function insertar($NOMBREUsuario,$clave,$idcolaborador,$idrol)
        {
                $sql = "INSERT INTO usuarios_sistema(NOM_USUARIO_SISTEMA,CLAVE_USUARIO_SISTEMA,ID_COLABORADOR_USUARIOS_SISTEMA,ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA,ESTADO)
                            VALUES ('$NOMBREUsuario','$clave','$idcolaborador','$idrol','1')";
                return ejecutarConsulta_retornarID($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Implementamos un método para editar registros
        public function editar($idusurio,$NOMBREUsuario,$idcolaborador,$idrol)
        {
                $sql = "UPDATE usuarios_sistema SET NOM_USUARIO_SISTEMA='$NOMBREUsuario',ID_COLABORADOR_USUARIOS_SISTEMA='$idcolaborador', ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA='$idrol' where ID_USUARIO_SISTEMA=$idusurio";
                return ejecutarConsulta($sql);
        }
    
        public function insertarcentros($idusuario,$idcentro)
        {
                $sql = "INSERT INTO asoc_usuarios_sistemas_x_cop(ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP ,ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP ,ESTADO)
                            VALUES ($idusuario,$idcentro,'1')";
                return ejecutarConsulta($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Implementar un método para mostrar los datos de un registro a modificar
        public function mostrar($ID_usuario)
        {
                $sql = "SELECT * FROM usuarios_sistema WHERE ID_USUARIO_SISTEMA='$ID_usuario'";
                return ejecutarConsultaSimpleFila($sql);
        }
    
        public function detectarcentros($ID_usuario)
        {
                $sql = "SELECT * FROM asoc_usuarios_sistemas_x_cop WHERE ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP='$ID_usuario'";
                return ejecutarConsulta($sql);
        }
    
         public function mostrarcentros()
        {
                $sql = "SELECT centros_operacion.*,proyectos.NOM_PROYECTO FROM centros_operacion INNER JOIN proyectos ON proyectos.ID_PROYECTO=centros_operacion.ID_PROYECTO_CENTRO_OP WHERE centros_operacion.Estado=1 ORDER BY centros_operacion.ID_PROYECTO_CENTRO_OP ASC";
                return ejecutarConsulta($sql);
        }
    
    
         public function consultaCentros($ID_usuario)
        {
                $sql = "SELECT centros_operacion.NOM_CENTRO_OP from asoc_usuarios_sistemas_x_cop INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=asoc_usuarios_sistemas_x_cop.ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP WHERE asoc_usuarios_sistemas_x_cop.ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP='$ID_usuario'";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para listar los registros
        public function listar()
        {
                $sql = "SELECT colaboradores.DOC_COLABORADO,colaboradores.NOM_COLABORADOR, rol_usuarios_sistemas.NOM_ROL_USUARIO_SISTEMA, usuarios_sistema.* FROM usuarios_sistema INNER JOIN colaboradores ON colaboradores.ID_COLABORADOR=usuarios_sistema.ID_COLABORADOR_USUARIOS_SISTEMA INNER join rol_usuarios_sistemas on rol_usuarios_sistemas.ID_ROL_USUARIO_SISTEMA=usuarios_sistema.ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA";
                return ejecutarConsulta($sql);
        }
        
    
        public function desactivar($IDUsuario)
        {
                $sql = "UPDATE usuarios_sistema SET ESTADO=0 WHERE ID_USUARIO_SISTEMA=$IDUsuario";
                return ejecutarConsulta($sql);
        }
        public function activar($IDUsuario)
        {
               $sql = "UPDATE usuarios_sistema SET ESTADO=1 WHERE ID_USUARIO_SISTEMA=$IDUsuario";
                return ejecutarConsulta($sql);
        }
    
        public function Verficar($usuario)
        {
                $sql = "SELECT * from  usuarios_sistema WHERE NOM_USUARIO_SISTEMA='$usuario'";
                return ejecutarConsulta($sql);
        }
        
    
        public function correo($idcolaborador)
        {
               $sql = "SELECT MAIL_COLABORADOR from  colaboradores WHERE ID_COLABORADOR=$idcolaborador";
                return ejecutarConsultaSimpleFila($sql);
        }
    
        public function eliminarcentros($idusuario)
        {
               $sql = "DELETE from asoc_usuarios_sistemas_x_cop WHERE asoc_usuarios_sistemas_x_cop.ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP=$idusuario";
                return ejecutarConsulta($sql);
        }
        
     
    
       public function cambiraclave($IDUsuario,$clave)
        {
               $sql = "UPDATE usuarios_sistema SET CLAVE_USUARIO_SISTEMA='$clave' WHERE ID_USUARIO_SISTEMA=$IDUsuario";
                return ejecutarConsulta($sql);
        }
    
          public function colaboradores()
        {
                $sql = "SELECT * FROM colaboradores WHERE ESTADO=1";
                return ejecutarConsulta($sql);
        }
        
    
         public function verificar($usuario,$calve)
        {
                $sql = "SELECT usuarios_sistema.ID_USUARIO_SISTEMA,colaboradores.ID_COLABORADOR,colaboradores.NOM_COLABORADOR, rol_usuarios_sistemas.MODULOS_ROL_USUARIOS_SISTEMAS,rol_usuarios_sistemas.PERMISOS_CERRAR_NOVEDADES,cargos_colaboladores.PERMISO_ASIGNAR_NOVEDAD FROM usuarios_sistema INNER JOIN rol_usuarios_sistemas on rol_usuarios_sistemas.ID_ROL_USUARIO_SISTEMA=usuarios_sistema.ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=usuarios_sistema.ID_COLABORADOR_USUARIOS_SISTEMA INNER JOIN cargos_colaboladores on cargos_colaboladores.ID_CARGO_COLABORADORES=colaboradores.ID_CARGO_COLABORADORES_COLABORADORES WHERE usuarios_sistema.NOM_USUARIO_SISTEMA='$usuario' AND usuarios_sistema.CLAVE_USUARIO_SISTEMA='$calve' AND usuarios_sistema.ESTADO=1";
                return ejecutarConsulta($sql);
        }
    
         public function CorreoRecuperacion($usuario)
        {
                $sql = "SELECT colaboradores.MAIL_COLABORADOR, usuarios_sistema.ID_USUARIO_SISTEMA, colaboradores.NOM_COLABORADOR FROM usuarios_sistema INNER JOIN colaboradores ON colaboradores.ID_COLABORADOR=usuarios_sistema.ID_COLABORADOR_USUARIOS_SISTEMA WHERE usuarios_sistema.NOM_USUARIO_SISTEMA='$usuario' AND usuarios_sistema.ESTADO=1";
                return ejecutarConsultaSimpleFila($sql);
        }
        
		
}