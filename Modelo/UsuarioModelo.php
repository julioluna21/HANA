<?php
/*
  HANA - Usuarios (modelo)
  ---------------------------------------------------------------------------
  La persona y su usuario van en tablas distintas (script 40):
    - colaboradores: la persona (documento, nombre, correo y si está activa). Se maneja
      en la pantalla Colaboradores, junto con sus peajes asignados (asoc_usuarios_sistemas_x_cop).
    - usuarios_sistema: su usuario para entrar a HANA (nombre de usuario, clave cifrada,
      rol y si puede entrar). Se maneja en la pantalla Usuarios. Una persona tiene un
      usuario o ninguno.
  Aquí están también la consulta para iniciar sesión y las de recuperar la clave.
  Lo usan Control/ColaboradoresControl.php y Control/UsuariosControl.php.
  El número que usan las pantallas y la sesión es el de la persona (ID_COLABORADOR):
  el usuario se busca por la persona.
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
        //Registra una persona (sin usuario). Devuelve su id, o 0 si no se pudo
        public function crearPersona($documento, $nombre, $correo)
        {
                $ok = HanaDB::q("INSERT INTO colaboradores (DOC_COLABORADOR, NOM_COLABORADOR, MAIL_COLABORADOR, ESTADO)
                                 VALUES (?, ?, ?, 1)", 'sss', array((string)$documento, (string)$nombre, (string)$correo));
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
        //-----------------------------------------------------------------------
        // El usuario de la persona (tabla usuarios_sistema)
        //-----------------------------------------------------------------------
        //Le crea el usuario a una persona que no tiene, con su clave (ya cifrada) y su rol
        public function crearUsuario($idPersona, $usuario, $claveCifrada, $idRol)
        {
                return (bool)HanaDB::q("INSERT INTO usuarios_sistema (ID_COLABORADOR, USUARIO, CLAVE, ID_ROL_USUARIO_SISTEMA, ESTADO, FEC_CREACION)
                                        VALUES (?, ?, ?, ?, 1, NOW())", 'issi', array((int)$idPersona, (string)$usuario, (string)$claveCifrada, (int)$idRol));
        }
        //¿La persona ya tiene usuario?
        public function tieneUsuario($idPersona)
        {
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM usuarios_sistema WHERE ID_COLABORADOR = ?", 'i', array((int)$idPersona));
        }
        //Cambia el rol del usuario de la persona
        public function ponerRol($idPersona, $idRol)
        {
                return (bool)HanaDB::q("UPDATE usuarios_sistema SET ID_ROL_USUARIO_SISTEMA = ?, FEC_MODIFICACION = NOW() WHERE ID_COLABORADOR = ?",
                                       'ii', array((int)$idRol, (int)$idPersona));
        }
        //Cambia el nombre de usuario de la persona
        public function ponerUsuario($idPersona, $usuario)
        {
                return (bool)HanaDB::q("UPDATE usuarios_sistema SET USUARIO = ?, FEC_MODIFICACION = NOW() WHERE ID_COLABORADOR = ?",
                                       'si', array((string)$usuario, (int)$idPersona));
        }
        //Bloquea (0) o vuelve a dejar entrar (1) al usuario de la persona. La persona sigue en las listas
        public function ponerAcceso($idPersona, $estado)
        {
                return (bool)HanaDB::q("UPDATE usuarios_sistema SET ESTADO = ?, FEC_MODIFICACION = NOW() WHERE ID_COLABORADOR = ?",
                                       'ii', array($estado ? 1 : 0, (int)$idPersona));
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
        //La persona para el formulario, con su usuario si lo tiene (sin la clave).
        //USUARIO, el rol y ACCESO vienen vacíos si no tiene usuario
        public function mostrar($idPersona)
        {
                return HanaDB::fila("SELECT c.ID_COLABORADOR, c.DOC_COLABORADOR, c.NOM_COLABORADOR, c.MAIL_COLABORADOR, c.ESTADO,
                                            u.USUARIO, u.ID_ROL_USUARIO_SISTEMA, r.NOM_ROL_USUARIO_SISTEMA, u.ESTADO AS ACCESO
                                       FROM colaboradores c
                                       LEFT JOIN usuarios_sistema u ON u.ID_COLABORADOR = c.ID_COLABORADOR
                                       LEFT JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA
                                      WHERE c.ID_COLABORADOR = ?", 'i', array((int)$idPersona));
        }
        //Todas las personas, con su usuario y su rol si los tienen, y cuántos peajes tiene asignados (sin la clave)
        public function listar()
        {
                $sql = "SELECT c.ID_COLABORADOR, c.DOC_COLABORADOR, c.NOM_COLABORADOR, c.MAIL_COLABORADOR, c.ESTADO,
                               u.USUARIO, u.ESTADO AS ACCESO, r.NOM_ROL_USUARIO_SISTEMA,
                               (SELECT COUNT(*) FROM asoc_usuarios_sistemas_x_cop a WHERE a.ID_COLABORADOR = c.ID_COLABORADOR) AS PEAJES
                          FROM colaboradores c
                          LEFT JOIN usuarios_sistema u ON u.ID_COLABORADOR = c.ID_COLABORADOR
                          LEFT JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA
                         ORDER BY c.ESTADO DESC, c.NOM_COLABORADOR";
                return ejecutarConsulta($sql);
        }
        //Desactiva a la persona: deja de salir en las listas y, si tiene usuario, ya no puede entrar
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
                $sql = "SELECT c.ID_COLABORADOR, c.NOM_COLABORADOR FROM colaboradores c
                         WHERE c.ESTADO = 1 AND NOT EXISTS (SELECT 1 FROM usuarios_sistema u WHERE u.ID_COLABORADOR = c.ID_COLABORADOR)
                         ORDER BY c.NOM_COLABORADOR";
                return ejecutarConsulta($sql);
        }

        //-----------------------------------------------------------------------
        // Usuario y clave
        //-----------------------------------------------------------------------
        //¿Otra persona ya tiene ese usuario?
        public function usuarioExiste($usuario, $excepto = 0)
        {
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM usuarios_sistema WHERE USUARIO = ? AND ID_COLABORADOR <> ?", 'si', array((string)$usuario, (int)$excepto));
        }
        //Guarda la clave nueva del usuario de una persona. Llega ya cifrada (HanaClave::cifrar)
        public function guardarClave($idPersona, $claveCifrada)
        {
                return (bool)HanaDB::q("UPDATE usuarios_sistema SET CLAVE = ?, FEC_MODIFICACION = NOW() WHERE ID_COLABORADOR = ?",
                                       'si', array((string)$claveCifrada, (int)$idPersona));
        }
        //Lo necesario para iniciar sesión con ese usuario: la clave guardada, si la persona,
        //su usuario y su rol están activos, y los permisos del rol. null si nadie tiene ese usuario.
        //El controlador revisa la clave y los estados (así puede explicar por qué no entra)
        public function datosIngreso($usuario)
        {
                return HanaDB::fila("SELECT c.ID_COLABORADOR, c.NOM_COLABORADOR, u.USUARIO, u.CLAVE,
                                            c.ESTADO AS PERSONA_ACTIVA, u.ESTADO AS USUARIO_ACTIVO,
                                            COALESCE(r.ESTADO, 0) AS ROL_ACTIVO,
                                            r.PERMISOS, r.PUEDE_CERRAR_NOVEDADES
                                       FROM usuarios_sistema u
                                       INNER JOIN colaboradores c ON c.ID_COLABORADOR = u.ID_COLABORADOR
                                       LEFT JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA
                                      WHERE u.USUARIO = ?", 's', array((string)$usuario));
        }
        //La persona activa con ese usuario (también activo), para mandarle el enlace de
        //"Olvidé mi contraseña": su correo, su nombre y la clave guardada (con ella se firma el enlace).
        //null si no hay
        public function datosRecuperacion($usuario)
        {
                return HanaDB::fila("SELECT c.ID_COLABORADOR, c.NOM_COLABORADOR, c.MAIL_COLABORADOR, u.CLAVE
                                       FROM usuarios_sistema u
                                       INNER JOIN colaboradores c ON c.ID_COLABORADOR = u.ID_COLABORADOR
                                      WHERE u.USUARIO = ? AND c.ESTADO = 1 AND u.ESTADO = 1 AND u.CLAVE <> ''",
                                    's', array((string)$usuario));
        }
        //La persona del enlace de recuperación (por su número), con su usuario y la clave guardada
        //para revisar la firma. null si no existe, está inactiva o no tiene usuario activo
        public function datosEnlace($idPersona)
        {
                return HanaDB::fila("SELECT c.ID_COLABORADOR, c.NOM_COLABORADOR, u.USUARIO, u.CLAVE
                                       FROM usuarios_sistema u
                                       INNER JOIN colaboradores c ON c.ID_COLABORADOR = u.ID_COLABORADOR
                                      WHERE u.ID_COLABORADOR = ? AND c.ESTADO = 1 AND u.ESTADO = 1",
                                    'i', array((int)$idPersona));
        }
}
