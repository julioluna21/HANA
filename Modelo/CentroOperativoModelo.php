<?php
/*
  HANA - Centros operativos (modelo)
  ---------------------------------------------------------------------------
  Consultas de la tabla centros_operacion: peajes, básculas, bases y oficinas
  de cada proyecto, con su tipo y su jefe. Lo usa Control/CentroOperativoControl.php.
  El nombre se guarda como lo dejan los módulos de siempre, ya convertido para
  HTML (ver Modelo/HanaTexto.php).
*/
require "../Conexion/ConexionDB.php";
require_once __DIR__ . '/HanaDB.php';

class Centro
{
        //Tipos de centro (lo que se guarda => cómo se muestra). El tipo y el jefe los usa el reporte diario
        public static $TIPOS = array('PEAJE' => 'Peaje', 'BASCULA' => 'Báscula', 'BASE' => 'Base', 'OFICINA' => 'Oficina');

        //Constructor vacío: la clase no guarda datos propios
        public function __construct()
        {
        }

        //Crea un centro activo. $jefe: la persona a cargo, o 0 si no tiene
        public function insertar($nombre, $idProyecto, $tipo = 'PEAJE', $jefe = 0)
        {
                return (bool)HanaDB::q("INSERT INTO centros_operacion (NOM_CENTRO_OP, ID_PROYECTO, ESTADO, TIPO_CENTRO, ID_COLABORADOR_JEFE) VALUES (?, ?, '1', ?, ?)",
                                       'sisi', array((string)$nombre, (int)$idProyecto, isset(self::$TIPOS[$tipo]) ? $tipo : 'PEAJE', intval($jefe) > 0 ? intval($jefe) : null));
        }

        //Cambia nombre, proyecto, tipo y jefe (y lo deja activo)
        public function editar($idCentro, $nombre, $idProyecto, $tipo = 'PEAJE', $jefe = 0)
        {
                return (bool)HanaDB::q("UPDATE centros_operacion SET NOM_CENTRO_OP = ?, ID_PROYECTO = ?, ESTADO = '1', TIPO_CENTRO = ?, ID_COLABORADOR_JEFE = ? WHERE ID_CENTRO_OP = ?",
                                       'sisii', array((string)$nombre, (int)$idProyecto, isset(self::$TIPOS[$tipo]) ? $tipo : 'PEAJE', intval($jefe) > 0 ? intval($jefe) : null, (int)$idCentro));
        }

        //Un centro con su proyecto, para cargarlo en el formulario
        public function mostrar($idCentro)
        {
                return HanaDB::fila("SELECT centros_operacion.*, proyectos.ID_PROYECTO, proyectos.NOM_PROYECTO
                                       FROM centros_operacion
                                       INNER JOIN proyectos ON centros_operacion.ID_PROYECTO = proyectos.ID_PROYECTO
                                      WHERE ID_CENTRO_OP = ?", 'i', array((int)$idCentro));
        }

        //¿El proyecto existe y está activo?
        public function proyectoActivo($idProyecto)
        {
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM proyectos WHERE ID_PROYECTO = ? AND ESTADO = '1'", 'i', array((int)$idProyecto));
        }

        //El proyecto del centro activo que ya tiene ese nombre (sin importar mayúsculas ni
        //tildes), o null si ningún otro lo tiene
        public function proyectoConEseNombre($nombre, $excepto = 0)
        {
                $fila = HanaDB::fila("SELECT p.NOM_PROYECTO FROM centros_operacion c
                                     INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                                    WHERE c.NOM_CENTRO_OP = ? AND c.ESTADO = '1' AND c.ID_CENTRO_OP <> ?",
                                  'si', array((string)$nombre, (int)$excepto));
                return $fila ? $fila['NOM_PROYECTO'] : null;
        }

        //Todos los centros con su proyecto y su jefe (tabla de la pantalla)
        public function listar()
        {
                $sql = "SELECT centros_operacion.*, proyectos.NOM_PROYECTO, j.NOM_COLABORADOR AS JEFE
                          FROM centros_operacion
                          INNER JOIN proyectos ON centros_operacion.ID_PROYECTO=proyectos.ID_PROYECTO
                          LEFT JOIN colaboradores j ON j.ID_COLABORADOR = centros_operacion.ID_COLABORADOR_JEFE";
                return ejecutarConsulta($sql);
        }

        //Desactiva un centro (no se borra)
        public function anular($idCentro)
        {
                return (bool)HanaDB::q("UPDATE centros_operacion SET ESTADO = 0 WHERE ID_CENTRO_OP = ?", 'i', array((int)$idCentro));
        }

        //Centros activos, para los desplegables
        public function select()
        {
                return ejecutarConsulta("SELECT * FROM centros_operacion WHERE ESTADO=1");
        }
}
