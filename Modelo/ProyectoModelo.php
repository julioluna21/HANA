<?php
/*
  HANA - Proyectos (modelo)
  ---------------------------------------------------------------------------
  Consultas de la tabla proyectos (las concesiones) y su coordinador.
  Lo usa Control/ProyectoControl.php.
  El nombre se guarda como lo dejan los módulos de siempre, ya convertido para
  HTML (ver Modelo/HanaTexto.php): «Peajes & Vías» queda «Peajes &amp; Vías».
*/
require "../Conexion/ConexionDB.php";
require_once __DIR__ . '/HanaDB.php';
require_once __DIR__ . '/HanaTexto.php'; //cómo se guardan los nombres

class Proyecto
{
        //Constructor vacío: la clase no guarda datos propios
        public function __construct()
        {
        }

        //Crea un proyecto activo. $coordinador: la persona que lo coordina, o 0 si no tiene
        public function insertar($nombre, $coordinador = 0)
        {
                return (bool)HanaDB::q("INSERT INTO proyectos (NOM_PROYECTO, ESTADO, ID_COLABORADOR_COORDINADOR) VALUES (?, '1', ?)",
                                       'si', array((string)$nombre, intval($coordinador) > 0 ? intval($coordinador) : null));
        }

        //Cambia el nombre y el coordinador (y lo deja activo)
        public function editar($idProyecto, $nombre, $coordinador = 0)
        {
                return (bool)HanaDB::q("UPDATE proyectos SET NOM_PROYECTO = ?, ESTADO = '1', ID_COLABORADOR_COORDINADOR = ? WHERE ID_PROYECTO = ?",
                                       'sii', array((string)$nombre, intval($coordinador) > 0 ? intval($coordinador) : null, (int)$idProyecto));
        }

        //Un proyecto, para cargarlo en el formulario
        public function mostrar($idProyecto)
        {
                return HanaDB::fila("SELECT * FROM proyectos WHERE ID_PROYECTO = ?", 'i', array((int)$idProyecto));
        }

        //¿Otro proyecto activo ya tiene ese nombre? (sin importar mayúsculas ni tildes)
        public function nombreExiste($nombre, $excepto = 0)
        {
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM proyectos WHERE NOM_PROYECTO = ? AND ESTADO = '1' AND ID_PROYECTO <> ?",
                                          'si', array((string)$nombre, (int)$excepto));
        }

        //Todos los proyectos, con el nombre del coordinador (tabla de la pantalla)
        public function listar()
        {
                $sql = "SELECT p.*, c.NOM_COLABORADOR AS COORDINADOR
                          FROM proyectos p
                          LEFT JOIN colaboradores c ON c.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR";
                return ejecutarConsulta($sql);
        }

        //Desactiva un proyecto (no se borra)
        public function anular($idProyecto)
        {
                return (bool)HanaDB::q("UPDATE proyectos SET ESTADO = 0 WHERE ID_PROYECTO = ?", 'i', array((int)$idProyecto));
        }

        //Colaboradores activos con su rol, para elegir coordinador (y jefe de centro)
        public function colaboradores()
        {
                $sql = "SELECT c.ID_COLABORADOR AS id, c.NOM_COLABORADOR AS nombre, r.NOM_ROL_USUARIO_SISTEMA AS rol
                          FROM colaboradores c
                          LEFT JOIN usuarios_sistema u ON u.ID_COLABORADOR = c.ID_COLABORADOR
                          LEFT JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA
                         WHERE c.ESTADO = 1
                         ORDER BY c.NOM_COLABORADOR";
                return ejecutarConsulta($sql);
        }

        //¿Existe ese colaborador y está activo? Evita guardar un número inventado
        public function colaboradorValido($idColaborador)
        {
                if ((int)$idColaborador <= 0) { return false; }
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM colaboradores WHERE ID_COLABORADOR = ? AND ESTADO = 1", 'i', array((int)$idColaborador));
        }

        //Proyectos activos para el buscador; si se escribió algo, solo los que lo contienen
        public function buscar($loQueSeEscribio = '')
        {
                if ((string)$loQueSeEscribio === '') {
                        $filas = HanaDB::q("SELECT ID_PROYECTO, NOM_PROYECTO FROM proyectos WHERE ESTADO = '1' ORDER BY NOM_PROYECTO ASC");
                } else {
                        $filas = HanaDB::q("SELECT ID_PROYECTO, NOM_PROYECTO FROM proyectos WHERE ESTADO = '1' AND NOM_PROYECTO LIKE ? ORDER BY NOM_PROYECTO ASC",
                                       's', array('%' . hanaTextoGuardado($loQueSeEscribio) . '%'));
                }
                return $filas ? $filas : array();
        }
}
