<?php
//Incluímos inicialmente la conexión a la base de datos
require "../Conexion/ConexionDB.php";
class Proyecto
{
        //Implementamos el super constructor 
        public function __construct()
        {
        }
        //Implementamos un método para insertar registros
        //$coordinador: id del colaborador que coordina el proyecto, o 0 si no tiene (Fase 2)
        public function insertar($nombreproyecto, $coordinador = 0)
        {
                $coord = intval($coordinador) > 0 ? intval($coordinador) : 'NULL';
                $sql = "INSERT INTO proyectos(NOM_PROYECTO,Estado,ID_COLABORADOR_COORDINADOR)
                            VALUES ('$nombreproyecto','1',$coord)";
                return ejecutarConsulta($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Implementamos un método para editar registros
        public function editar($id, $nombreproyecto, $coordinador = 0)
        {
                $coord = intval($coordinador) > 0 ? intval($coordinador) : 'NULL';
                $sql = "UPDATE proyectos SET NOM_PROYECTO='$nombreproyecto', Estado='1',
                               ID_COLABORADOR_COORDINADOR=$coord
                         WHERE ID_PROYECTO=" . intval($id);
                return ejecutarConsulta($sql);
        }
        //Implementar un método para mostrar los datos de un registro a modificar
        public function mostrar($id)
        {
                $sql = "SELECT * FROM proyectos WHERE ID_PROYECTO=" . intval($id);
                return ejecutarConsultaSimpleFila($sql);
        }
        //Implementar un método para listar los registros
        public function listar()
        {
                //Con el nombre del coordinador, para mostrarlo en el listado
                $sql = "SELECT p.*, c.NOM_COLABORADOR AS COORDINADOR
                          FROM proyectos p
                          LEFT JOIN colaboradores c ON c.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR";
                return ejecutarConsulta($sql);
        }
        //Implementamos un método para editar reasignar contraseña
        public function anular($id)
        {
                $sql = "UPDATE proyectos SET Estado=0 WHERE ID_PROYECTO=$id";
                return ejecutarConsulta($sql);
        }
        //Colaboradores activos, para elegir coordinador (y jefe de centro)
        public function colaboradores()
        {
                $sql = "SELECT c.ID_COLABORADOR AS id, c.NOM_COLABORADOR AS nombre, g.NOM_CARGO_COLABORADORES AS cargo
                          FROM colaboradores c
                          LEFT JOIN cargos_colaboladores g ON g.ID_CARGO_COLABORADORES = c.ID_CARGO_COLABORADORES_COLABORADORES
                         WHERE c.ESTADO = 1
                         ORDER BY c.NOM_COLABORADOR";
                return ejecutarConsulta($sql);
        }

        //¿Existe ese colaborador y está activo? Evita guardar un id inventado
        public function colaboradorValido($id)
        {
                $id = intval($id);
                if ($id <= 0) { return false; }
                $f = ejecutarConsultaSimpleFila("SELECT 1 AS ok FROM colaboradores WHERE ID_COLABORADOR = $id AND ESTADO = 1");
                return (bool)$f;
        }

        public function select($search_term)
        {
                if ($search_term === 0) {
                        $sql = "SELECT * FROM proyectos WHERE Estado= '1' ORDER BY NOM_PROYECTO ASC";
                        return ejecutarConsulta($sql);
                } else {
                        $sql = "SELECT * FROM proyectos WHERE NOM_PROYECTO LIKE '%".$search_term."%' AND Estado= '1' ORDER BY NOM_PROYECTO ASC";
                        return ejecutarConsulta($sql);
                }
                
        }
}
