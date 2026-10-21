<?php
/*
  HANA - Roles (modelo)
  ---------------------------------------------------------------------------
  Consultas de la tabla rol_usuarios_sistemas: cada rol con sus permisos
  (casillas 1M, 2M, ...) separados por coma y si puede cerrar novedades.
  Lo usa Control/RolesControl.php.
  El nombre se guarda como lo dejan los módulos de siempre (ver Modelo/HanaTexto.php).
*/
require "../Conexion/ConexionDB.php";
require_once __DIR__ . '/HanaDB.php';

class Roles
{
        //Constructor vacío: la clase no guarda datos propios
        public function __construct()
        {
        }

        //Crea un rol activo. $permisos: las casillas separadas por coma
        public function insertar($nombre, $permisos, $puedeCerrarNovedades)
        {
                return (bool)HanaDB::q("INSERT INTO rol_usuarios_sistemas (NOM_ROL_USUARIO_SISTEMA, PERMISOS, PUEDE_CERRAR_NOVEDADES, ESTADO) VALUES (?, ?, ?, 1)",
                                       'ssi', array((string)$nombre, (string)$permisos, (int)$puedeCerrarNovedades));
        }

        //Cambia el nombre, las casillas y el permiso de cerrar novedades
        public function editar($idRol, $nombre, $permisos, $puedeCerrarNovedades)
        {
                return (bool)HanaDB::q("UPDATE rol_usuarios_sistemas SET NOM_ROL_USUARIO_SISTEMA = ?, PERMISOS = ?, PUEDE_CERRAR_NOVEDADES = ? WHERE ID_ROL_USUARIO_SISTEMA = ?",
                                       'ssii', array((string)$nombre, (string)$permisos, (int)$puedeCerrarNovedades, (int)$idRol));
        }

        //Un rol, para cargarlo en el formulario
        public function mostrar($idRol)
        {
                return HanaDB::fila("SELECT * FROM rol_usuarios_sistemas WHERE ID_ROL_USUARIO_SISTEMA = ?", 'i', array((int)$idRol));
        }

        //¿Otro rol ya tiene ese nombre? (sin importar mayúsculas)
        public function nombreExiste($nombre, $excepto = 0)
        {
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM rol_usuarios_sistemas WHERE NOM_ROL_USUARIO_SISTEMA = ? AND ID_ROL_USUARIO_SISTEMA <> ?",
                                          'si', array((string)$nombre, (int)$excepto));
        }

        //Cuántas personas activas tienen un usuario activo con el rol (un rol con personas no se desactiva)
        public function personasActivas($idRol)
        {
                $fila = HanaDB::fila("SELECT COUNT(*) AS n FROM usuarios_sistema u
                                        INNER JOIN colaboradores c ON c.ID_COLABORADOR = u.ID_COLABORADOR
                                       WHERE u.ID_ROL_USUARIO_SISTEMA = ? AND u.ESTADO = 1 AND c.ESTADO = 1", 'i', array((int)$idRol));
                return $fila ? (int)$fila['n'] : 0;
        }

        //Todos los roles (tabla de la pantalla)
        public function listar()
        {
                return ejecutarConsulta("SELECT * FROM rol_usuarios_sistemas ORDER BY ESTADO DESC, NOM_ROL_USUARIO_SISTEMA");
        }

        //Los roles activos (desplegable de Usuarios)
        public function select()
        {
                return ejecutarConsulta("SELECT * FROM rol_usuarios_sistemas WHERE ESTADO = 1 ORDER BY NOM_ROL_USUARIO_SISTEMA");
        }

        //Desactiva un rol (no se borra)
        public function desactivar($idRol)
        {
                return (bool)HanaDB::q("UPDATE rol_usuarios_sistemas SET ESTADO = 0 WHERE ID_ROL_USUARIO_SISTEMA = ?", 'i', array((int)$idRol));
        }

        //Vuelve a activar un rol
        public function activar($idRol)
        {
                return (bool)HanaDB::q("UPDATE rol_usuarios_sistemas SET ESTADO = 1 WHERE ID_ROL_USUARIO_SISTEMA = ?", 'i', array((int)$idRol));
        }
}
