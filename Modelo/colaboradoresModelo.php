<?php
/*
  HANA - Colaboradores (modelo)
  ---------------------------------------------------------------------------
  Consultas de la tabla colaboradores: las personas que trabajan en los proyectos.
  Cada persona tiene su rol y, si entra a HANA, su usuario y su clave en la misma
  fila. La clave nunca se manda a la pantalla.
  Las pantallas usan Modelo/UsuarioModelo.php. Este archivo solo lo carga
  Control/ColaboradoresControl.php, que únicamente responde el desplegable de
  colaboradores activos (select); el resto se conserva como referencia.
*/
require "../Conexion/ConexionDB.php";
class colaborador
{
        //Constructor vacío: la clase no guarda datos propios
        public function __construct()
        {
        }
        //Registra una persona activa
        public function insertar($cedula, $nombre, $correo, $idRol)
        {
                $idRol = intval($idRol);
                $sql = "INSERT INTO colaboradores (`DOC_COLABORADOR`, `NOM_COLABORADOR`, `MAIL_COLABORADOR`, `ID_ROL_USUARIO_SISTEMA`, `ESTADO`)
                            VALUES ('$cedula','$nombre','$correo',$idRol,1)";
                return ejecutarConsulta($sql); //ejecutarConsulta está en Conexion/ConexionDB.php
        }
        //Cambia los datos de una persona (y la deja activa).
        //$idRol null: el rol no se toca (quien edita no administra usuarios)
        public function editar($id, $cedula, $nombre, $correo, $idRol)
        {
                $rol = ($idRol === null) ? "" : ", ID_ROL_USUARIO_SISTEMA=" . intval($idRol);
                $sql = "UPDATE colaboradores SET DOC_COLABORADOR = '$cedula', NOM_COLABORADOR='$nombre', MAIL_COLABORADOR='$correo'$rol, ESTADO='1' where 
                    ID_COLABORADOR=" . intval($id);
                return ejecutarConsulta($sql);
        }
        //Una persona, para cargarla en el formulario
        public function mostrar($id)
        {
                //Con el nombre del rol y sin la clave
                $sql = "SELECT c.ID_COLABORADOR, c.DOC_COLABORADOR, c.NOM_COLABORADOR, c.MAIL_COLABORADOR, c.USUARIO,
                               c.ID_ROL_USUARIO_SISTEMA, r.NOM_ROL_USUARIO_SISTEMA, c.ESTADO
                        FROM colaboradores c
                        LEFT JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = c.ID_ROL_USUARIO_SISTEMA
                        WHERE c.ID_COLABORADOR=" . intval($id);
                return ejecutarConsultaSimpleFila($sql);
        }
        //Todas las personas con el nombre de su rol
        public function listar()
        {
                $sql = "SELECT c.ID_COLABORADOR, c.DOC_COLABORADOR, c.NOM_COLABORADOR, c.MAIL_COLABORADOR, c.USUARIO, c.ESTADO,
                               r.NOM_ROL_USUARIO_SISTEMA AS rol
                          FROM colaboradores c
                          LEFT JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = c.ID_ROL_USUARIO_SISTEMA";
                return ejecutarConsulta($sql);
        }
        //Desactiva un colaborador (no se borra)
        public function anular($id)
        {
                $sql = "UPDATE colaboradores SET ESTADO=0 WHERE ID_COLABORADOR=$id";
                return ejecutarConsulta($sql);
        }
        //Colaboradores activos
        public function select()
        {
                $sql = "SELECT ID_COLABORADOR, NOM_COLABORADOR FROM colaboradores WHERE ESTADO=1";
                return ejecutarConsulta($sql);
        }

        //-----------------------------------------------------------------------
        // Usuario automático al registrar un colaborador
        //-----------------------------------------------------------------------
        //El colaborador más reciente con ese documento (id, o 0)
        public function idPorDocumento($documento)
        {
                require_once __DIR__ . '/HanaDB.php';
                $fila = HanaDB::fila("SELECT ID_COLABORADOR FROM colaboradores WHERE DOC_COLABORADOR = ? ORDER BY ID_COLABORADOR DESC LIMIT 1", 's', array((string)$documento));
                return $fila ? (int)$fila['ID_COLABORADOR'] : 0;
        }

        //¿Ya existe un usuario con ese nombre?
        public function usuarioExiste($usuario)
        {
                require_once __DIR__ . '/HanaDB.php';
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM colaboradores WHERE USUARIO = ?", 's', array((string)$usuario));
        }

        //El nombre del rol de un colaborador ('' si no tiene)
        public function nombreRol($idColaborador)
        {
                require_once __DIR__ . '/HanaDB.php';
                $fila = HanaDB::fila("SELECT r.NOM_ROL_USUARIO_SISTEMA AS NOMBRE FROM colaboradores c
                                     INNER JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = c.ID_ROL_USUARIO_SISTEMA
                                    WHERE c.ID_COLABORADOR = ?", 'i', array((int)$idColaborador));
                return $fila ? $fila['NOMBRE'] : '';
        }

        //El rol más básico: USUARIO BASE si existe; si no, el activo con menos permisos que no administre usuarios
        public function rolBasico()
        {
                require_once __DIR__ . '/HanaDB.php';
                return HanaDB::fila("SELECT ID_ROL_USUARIO_SISTEMA AS ID, NOM_ROL_USUARIO_SISTEMA AS NOMBRE FROM rol_usuarios_sistemas
                                      WHERE ESTADO = 1 AND FIND_IN_SET('1M', PERMISOS) = 0
                                      ORDER BY NOM_ROL_USUARIO_SISTEMA = 'USUARIO BASE' DESC, LENGTH(PERMISOS) LIMIT 1");
        }

        //Le pone usuario y clave (la clave llega ya cifrada) al colaborador, si todavía no tenía.
        //El rol es el que ya tiene el colaborador
        public function crearUsuario($usuario, $claveCifrada, $idColaborador)
        {
                require_once __DIR__ . '/HanaDB.php';
                return HanaDB::q("UPDATE colaboradores SET USUARIO = ?, CLAVE = ? WHERE ID_COLABORADOR = ? AND USUARIO IS NULL",
                                 'ssi', array((string)$usuario, (string)$claveCifrada, (int)$idColaborador));
        }
}
