<?php
//Incluímos inicialmente la conexión a la base de datos
require "../Conexion/ConexionDB.php";
class Centro
{
        //Implementamos el super constructor 
        public function __construct()
        {
        }
        //Implementamos un método para insertar registros
        //Tipos de centro (Fase 2). El tipo y el jefe los usa el reporte diario
        public static $TIPOS = array('PEAJE' => 'Peaje', 'BASCULA' => 'Báscula', 'BASE' => 'Base', 'OFICINA' => 'Oficina');

        public function insertar($nombrecentro, $proyecto, $tipo = 'PEAJE', $jefe = 0)
        {
                $tipo = isset(self::$TIPOS[$tipo]) ? $tipo : 'PEAJE';
                $jefe = intval($jefe) > 0 ? intval($jefe) : 'NULL';
                $proyecto = intval($proyecto);
                $sql = "INSERT INTO `centros_operacion`(`NOM_CENTRO_OP`, `ID_PROYECTO_CENTRO_OP`, `Estado`, `TIPO_CENTRO`, `ID_COLABORADOR_JEFE`)
                            VALUES ('$nombrecentro',$proyecto,'1','$tipo',$jefe)";
                return ejecutarConsulta($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Implementamos un método para editar registros
        public function editar($id, $nombrecentro, $proyecto, $tipo = 'PEAJE', $jefe = 0)
        {
                $tipo = isset(self::$TIPOS[$tipo]) ? $tipo : 'PEAJE';
                $jefe = intval($jefe) > 0 ? intval($jefe) : 'NULL';
                $sql = "UPDATE centros_operacion SET NOM_CENTRO_OP='$nombrecentro', ID_PROYECTO_CENTRO_OP=" . intval($proyecto) . ",
                               Estado='1', TIPO_CENTRO='$tipo', ID_COLABORADOR_JEFE=$jefe
                         WHERE ID_CENTRO_OP=" . intval($id);
                return ejecutarConsulta($sql);
        }
        //Implementar un método para mostrar los datos de un registro a modificar
        public function mostrar($id)
        {
                $sql = "SELECT centros_operacion.*, proyectos.ID_PROYECTO, proyectos.NOM_PROYECTO FROM centros_operacion INNER JOIN proyectos ON centros_operacion.ID_PROYECTO_CENTRO_OP = proyectos.ID_PROYECTO WHERE ID_CENTRO_OP=" . intval($id);
                return ejecutarConsultaSimpleFila($sql);
        }
        //Implementar un método para listar los registros
        public function listar()
        {
                $sql = "SELECT centros_operacion.*, proyectos.NOM_PROYECTO, j.NOM_COLABORADOR AS JEFE
                          FROM centros_operacion
                          INNER JOIN proyectos ON centros_operacion.ID_PROYECTO_CENTRO_OP=proyectos.ID_PROYECTO
                          LEFT JOIN colaboradores j ON j.ID_COLABORADOR = centros_operacion.ID_COLABORADOR_JEFE";
                return ejecutarConsulta($sql);
        }
        //Implementamos un método para editar reasignar contraseña
        public function anular($id)
        {
                $sql = "UPDATE centros_operacion SET Estado=0 WHERE ID_CENTRO_OP=$id";
                return ejecutarConsulta($sql);
        }
        public function select()
        {
                $sql = "SELECT * FROM centros_operacion WHERE Estado=1";
                return ejecutarConsulta($sql);
        }
}
