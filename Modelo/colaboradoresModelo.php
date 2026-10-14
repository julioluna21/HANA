<?php
/*
  HANA - Colaboradores (modelo)
  ---------------------------------------------------------------------------
  Consultas de la tabla colaboradores: las personas que trabajan en los proyectos.
  Lo usa Control/ColaboradoresControl.php.
*/
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
                //Se trae tambien el nombre del cargo: el select2 carga sus opciones
                //por AJAX, asi que al editar hay que reconstruir la opcion actual
                $sql = "SELECT colaboradores.*, cargos_colaboradores.NOM_CARGO_COLABORADORES
                        FROM colaboradores
                        LEFT JOIN cargos_colaboradores
                          ON cargos_colaboradores.ID_CARGO_COLABORADORES = colaboradores.ID_CARGO_COLABORADORES_COLABORADORES
                        WHERE colaboradores.ID_COLABORADOR='$id'";
                return ejecutarConsultaSimpleFila($sql);
        }
        //Implementar un método para listar los registros
        public function listar()
        {
                $sql = "SELECT colaboradores.*,cargos_colaboradores.NOM_CARGO_COLABORADORES AS cargo FROM colaboradores INNER JOIN cargos_colaboradores ON cargos_colaboradores.ID_CARGO_COLABORADORES=colaboradores.ID_CARGO_COLABORADORES_COLABORADORES";
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
                $sql = "SELECT * FROM colaboradores WHERE ESTADO=1";
                return ejecutarConsulta($sql);
        }

        //-----------------------------------------------------------------------
        // Usuario automático al registrar un colaborador (ver ColaboradoresControl)
        //-----------------------------------------------------------------------
        //El colaborador más reciente con ese documento (id, o 0)
        public function idPorDocumento($documento)
        {
                require_once __DIR__ . '/HanaDB.php';
                $f = HanaDB::fila("SELECT ID_COLABORADOR FROM colaboradores WHERE DOC_COLABORADO = ? ORDER BY ID_COLABORADOR DESC LIMIT 1", 's', array((string)$documento));
                return $f ? (int)$f['ID_COLABORADOR'] : 0;
        }

        //¿Ya existe un usuario con ese nombre?
        public function usuarioExiste($usuario)
        {
                require_once __DIR__ . '/HanaDB.php';
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM usuarios_sistema WHERE NOM_USUARIO_SISTEMA = ?", 's', array((string)$usuario));
        }

        //El rol más básico: USUARIO BASE si existe; si no, el activo con menos permisos que no administre usuarios
        public function rolBasico()
        {
                require_once __DIR__ . '/HanaDB.php';
                return HanaDB::fila("SELECT ID_ROL_USUARIO_SISTEMA AS ID, NOM_ROL_USUARIO_SISTEMA AS NOMBRE FROM rol_usuarios_sistemas
                                      WHERE ESTADO = 1 AND FIND_IN_SET('1M', MODULOS_ROL_USUARIOS_SISTEMAS) = 0
                                      ORDER BY NOM_ROL_USUARIO_SISTEMA = 'USUARIO BASE' DESC, LENGTH(MODULOS_ROL_USUARIOS_SISTEMAS) LIMIT 1");
        }

        //Crea el usuario (la clave llega ya cifrada)
        public function crearUsuario($usuario, $claveCifrada, $idColaborador, $idRol)
        {
                require_once __DIR__ . '/HanaDB.php';
                return HanaDB::q("INSERT INTO usuarios_sistema (NOM_USUARIO_SISTEMA, CLAVE_USUARIO_SISTEMA, ID_COLABORADOR_USUARIOS_SISTEMA, ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA, ESTADO)
                                  VALUES (?, ?, ?, ?, 1)", 'ssii', array((string)$usuario, (string)$claveCifrada, (int)$idColaborador, (int)$idRol));
        }
}
