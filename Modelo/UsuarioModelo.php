<?php
/*
  HANA - Usuarios (modelo)
  ---------------------------------------------------------------------------
  Las personas y su acceso a HANA viven en la misma fila (tabla colaboradores,
  script 35): documento, nombre, correo, rol, USUARIO y CLAVE (cifrada). Se manejan
  en dos pantallas: Colaboradores (la persona y sus datos) y Usuarios (su acceso).
  También están los peajes asignados a cada persona (asoc_usuarios_sistemas_x_cop)
  y la consulta para iniciar sesión y recuperar la clave.
  Lo usan Control/ColaboradoresControl.php y Control/UsuariosControl.php.
  El número de usuario que usan la pantalla y la sesión es el ID_COLABORADOR.
  La clave nunca sale de aquí hacia la pantalla: solo el controlador la revisa
  (con Modelo/HanaClave.php) al entrar o al validar el enlace de recuperación.
*/
require "../Conexion/ConexionDB.php";
require_once __DIR__ . '/HanaDB.php';
class Usuarios
{
        public function __construct()
        {
        }

        //-----------------------------------------------------------------------
        // La persona
        //-----------------------------------------------------------------------
        //Registra una persona con su rol. Devuelve su id, o 0 si no se pudo
        public function crearPersona($documento, $nombre, $correo, $idRol)
        {
                $ok = HanaDB::q("INSERT INTO colaboradores (DOC_COLABORADOR, NOM_COLABORADOR, MAIL_COLABORADOR, ID_ROL_USUARIO_SISTEMA, ESTADO)
                                 VALUES (?, ?, ?, ?, 1)", 'sssi', array((string)$documento, (string)$nombre, (string)$correo, (int)$idRol));
                if (!$ok) { return 0; }
                $fila = HanaDB::fila("SELECT ID_COLABORADOR FROM colaboradores WHERE DOC_COLABORADOR = ?", 's', array((string)$documento));
                return $fila ? (int)$fila['ID_COLABORADOR'] : 0;
        }
        //Cambia documento, nombre y correo
        public function editarDatos($id, $documento, $nombre, $correo)
        {
                return (bool)HanaDB::q("UPDATE colaboradores SET DOC_COLABORADOR = ?, NOM_COLABORADOR = ?, MAIL_COLABORADOR = ? WHERE ID_COLABORADOR = ?",
                                       'sssi', array((string)$documento, (string)$nombre, (string)$correo, (int)$id));
        }
        //Cambia el rol
        public function ponerRol($id, $idRol)
        {
                return (bool)HanaDB::q("UPDATE colaboradores SET ID_ROL_USUARIO_SISTEMA = ? WHERE ID_COLABORADOR = ?", 'ii', array((int)$idRol, (int)$id));
        }
        //Cambia el nombre de usuario (si no tenía, también se le pone la clave temporal)
        public function ponerUsuario($id, $usuario, $claveCifrada = null)
        {
                if ($claveCifrada === null) {
                        return (bool)HanaDB::q("UPDATE colaboradores SET USUARIO = ? WHERE ID_COLABORADOR = ?", 'si', array((string)$usuario, (int)$id));
                }
                return (bool)HanaDB::q("UPDATE colaboradores SET USUARIO = ?, CLAVE = ? WHERE ID_COLABORADOR = ?", 'ssi', array((string)$usuario, (string)$claveCifrada, (int)$id));
        }
        //¿Otra persona ya tiene ese documento?
        public function documentoExiste($documento, $excepto = 0)
        {
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM colaboradores WHERE DOC_COLABORADOR = ? AND ID_COLABORADOR <> ?", 'si', array((string)$documento, (int)$excepto));
        }
        //El rol más básico: USUARIO BASE si existe; si no, el activo con menos casillas que no administre usuarios
        public function rolBasico()
        {
                $fila = HanaDB::fila("SELECT ID_ROL_USUARIO_SISTEMA AS ID FROM rol_usuarios_sistemas
                                    WHERE ESTADO = 1 AND FIND_IN_SET('1M', PERMISOS) = 0
                                    ORDER BY NOM_ROL_USUARIO_SISTEMA = 'USUARIO BASE' DESC, LENGTH(PERMISOS) LIMIT 1");
                return $fila ? (int)$fila['ID'] : 0;
        }
        //Los datos de la persona para el formulario (sin la clave)
        public function mostrar($idPersona)
        {
                return HanaDB::fila("SELECT c.ID_COLABORADOR, c.DOC_COLABORADOR, c.NOM_COLABORADOR, c.MAIL_COLABORADOR, c.USUARIO,
                                            c.ID_ROL_USUARIO_SISTEMA, r.NOM_ROL_USUARIO_SISTEMA, c.ESTADO
                                       FROM colaboradores c
                                       LEFT JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = c.ID_ROL_USUARIO_SISTEMA
                                      WHERE c.ID_COLABORADOR = ?", 'i', array((int)$idPersona));
        }
        //Todas las personas, con su rol, su usuario y cuántos peajes tiene asignados (sin la clave)
        public function listar()
        {
                $sql = "SELECT c.ID_COLABORADOR, c.DOC_COLABORADOR, c.NOM_COLABORADOR, c.MAIL_COLABORADOR, c.USUARIO, c.ESTADO,
                               r.NOM_ROL_USUARIO_SISTEMA,
                               (SELECT COUNT(*) FROM asoc_usuarios_sistemas_x_cop a WHERE a.ID_COLABORADOR = c.ID_COLABORADOR) AS PEAJES
                          FROM colaboradores c
                          LEFT JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = c.ID_ROL_USUARIO_SISTEMA
                         ORDER BY c.ESTADO DESC, c.NOM_COLABORADOR";
                return ejecutarConsulta($sql);
        }
        //Desactiva a la persona: ya no puede entrar y deja de salir en las listas
        public function desactivar($idPersona)
        {
                return (bool)HanaDB::q("UPDATE colaboradores SET ESTADO = 0 WHERE ID_COLABORADOR = ?", 'i', array((int)$idPersona));
        }
        //Vuelve a activar a la persona
        public function activar($idPersona)
        {
                return (bool)HanaDB::q("UPDATE colaboradores SET ESTADO = 1 WHERE ID_COLABORADOR = ?", 'i', array((int)$idPersona));
        }

        //-----------------------------------------------------------------------
        // Peajes asignados
        //-----------------------------------------------------------------------
        //Asigna un centro a una persona. La pareja persona-centro no se repite (IGNORE)
        public function asignarCentro($idPersona, $idCentro)
        {
                return (bool)HanaDB::q("INSERT IGNORE INTO asoc_usuarios_sistemas_x_cop (ID_COLABORADOR, ID_CENTRO_OP) VALUES (?, ?)",
                                       'ii', array((int)$idPersona, (int)$idCentro));
        }
        //Quita todos los centros de una persona (se usa antes de guardar los nuevos)
        public function quitarCentros($idPersona)
        {
                return (bool)HanaDB::q("DELETE FROM asoc_usuarios_sistemas_x_cop WHERE ID_COLABORADOR = ?", 'i', array((int)$idPersona));
        }
        //Centros asignados a una persona
        public function centrosAsignados($idPersona)
        {
                $sql = "SELECT * FROM asoc_usuarios_sistemas_x_cop WHERE ID_COLABORADOR=" . intval($idPersona);
                return ejecutarConsulta($sql);
        }
        //Todos los centros activos con el nombre de su proyecto, ordenados por proyecto
        public function centrosActivos()
        {
                $sql = "SELECT centros_operacion.*, proyectos.NOM_PROYECTO
                          FROM centros_operacion
                          INNER JOIN proyectos ON proyectos.ID_PROYECTO = centros_operacion.ID_PROYECTO
                         WHERE centros_operacion.ESTADO = 1
                         ORDER BY centros_operacion.ID_PROYECTO ASC";
                return ejecutarConsulta($sql);
        }
        //Nombres de los centros asignados a una persona
        public function nombresCentros($idPersona)
        {
                $sql = "SELECT centros_operacion.NOM_CENTRO_OP
                          FROM asoc_usuarios_sistemas_x_cop
                          INNER JOIN centros_operacion ON centros_operacion.ID_CENTRO_OP = asoc_usuarios_sistemas_x_cop.ID_CENTRO_OP
                         WHERE asoc_usuarios_sistemas_x_cop.ID_COLABORADOR=" . intval($idPersona) . "
                         ORDER BY centros_operacion.NOM_CENTRO_OP";
                return ejecutarConsulta($sql);
        }

        //-----------------------------------------------------------------------
        // Otras pantallas
        //-----------------------------------------------------------------------
        //Colaboradores activos (desplegables de otras pantallas)
        public function colaboradoresActivos()
        {
                $sql = "SELECT ID_COLABORADOR, NOM_COLABORADOR FROM colaboradores WHERE ESTADO=1";
                return ejecutarConsulta($sql);
        }
        //Colaboradores activos que todavía no tienen usuario
        public function colaboradoresSinUsuario()
        {
                $sql = "SELECT ID_COLABORADOR, NOM_COLABORADOR FROM colaboradores WHERE ESTADO=1 AND USUARIO IS NULL ORDER BY NOM_COLABORADOR";
                return ejecutarConsulta($sql);
        }

        //-----------------------------------------------------------------------
        // Usuario y clave
        //-----------------------------------------------------------------------
        //¿Otra persona ya tiene ese usuario?
        public function usuarioExiste($usuario, $excepto = 0)
        {
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM colaboradores WHERE USUARIO = ? AND ID_COLABORADOR <> ?", 'si', array((string)$usuario, (int)$excepto));
        }
        //Guarda la clave nueva de una persona. Llega ya cifrada (HanaClave::cifrar).
        //Solo a quien tiene usuario: sin usuario no hay con qué entrar
        public function guardarClave($idPersona, $claveCifrada)
        {
                return (bool)HanaDB::q("UPDATE colaboradores SET CLAVE = ? WHERE ID_COLABORADOR = ? AND USUARIO IS NOT NULL",
                                       'si', array((string)$claveCifrada, (int)$idPersona));
        }
        //Lo necesario para iniciar sesión con ese usuario: la clave guardada, si la persona
        //y su rol están activos, y los permisos del rol. null si nadie tiene ese usuario.
        //El controlador revisa la clave y los estados (así puede explicar por qué no entra)
        public function datosIngreso($usuario)
        {
                return HanaDB::fila("SELECT c.ID_COLABORADOR, c.NOM_COLABORADOR, c.USUARIO, c.CLAVE,
                                            c.ESTADO AS PERSONA_ACTIVA,
                                            COALESCE(r.ESTADO, 0) AS ROL_ACTIVO,
                                            r.PERMISOS, r.PUEDE_CERRAR_NOVEDADES
                                       FROM colaboradores c
                                       LEFT JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = c.ID_ROL_USUARIO_SISTEMA
                                      WHERE c.USUARIO = ?", 's', array((string)$usuario));
        }
        //La persona activa con ese usuario, para mandarle el enlace de "Olvidé mi contraseña":
        //su correo, su nombre y la clave guardada (con ella se firma el enlace). null si no hay
        public function datosRecuperacion($usuario)
        {
                return HanaDB::fila("SELECT ID_COLABORADOR, NOM_COLABORADOR, MAIL_COLABORADOR, CLAVE
                                       FROM colaboradores
                                      WHERE USUARIO = ? AND ESTADO = 1 AND CLAVE IS NOT NULL AND CLAVE <> ''",
                                    's', array((string)$usuario));
        }
        //La persona del enlace de recuperación (por su número), con su clave guardada para
        //revisar la firma. null si no existe, está inactiva o no tiene usuario
        public function datosEnlace($idPersona)
        {
                return HanaDB::fila("SELECT ID_COLABORADOR, NOM_COLABORADOR, USUARIO, CLAVE
                                       FROM colaboradores
                                      WHERE ID_COLABORADOR = ? AND ESTADO = 1 AND USUARIO IS NOT NULL AND USUARIO <> ''",
                                    'i', array((int)$idPersona));
        }
}
