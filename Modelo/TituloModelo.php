<?php
/*
  HANA - Títulos de novedades (modelo)
  ---------------------------------------------------------------------------
  Consultas de la tabla titulo_novedades_hallazgos: los títulos que se escogen al
  reportar una novedad. Lo usa Control/TituloControl.php.
  El nombre se guarda como lo dejan los módulos de siempre, ya convertido para
  HTML (ver Modelo/HanaTexto.php).
*/
require "../Conexion/ConexionDB.php";
require_once __DIR__ . '/HanaDB.php';

class Titulo
{
        //Constructor vacío: la clase no guarda datos propios
        public function __construct()
        {
        }

        //Crea un título activo
        public function insertar($nombre)
        {
                return (bool)HanaDB::q("INSERT INTO titulo_novedades_hallazgos (NOM_TITULO_NOVEDADES_HALLAZGOS, ESTADO) VALUES (?, '1')", 's', array((string)$nombre));
        }

        //Cambia el nombre
        public function editar($idTitulo, $nombre)
        {
                return (bool)HanaDB::q("UPDATE titulo_novedades_hallazgos SET NOM_TITULO_NOVEDADES_HALLAZGOS = ? WHERE ID_TITULO_NOVEDADES_HALLAZGOS = ?",
                                       'si', array((string)$nombre, (int)$idTitulo));
        }

        //Un título, para cargarlo en el formulario
        public function mostrar($idTitulo)
        {
                return HanaDB::fila("SELECT * FROM titulo_novedades_hallazgos WHERE ID_TITULO_NOVEDADES_HALLAZGOS = ?", 'i', array((int)$idTitulo));
        }

        //¿Otro título ya tiene ese nombre? (sin importar mayúsculas ni tildes)
        public function nombreExiste($nombre, $excepto = 0)
        {
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM titulo_novedades_hallazgos WHERE NOM_TITULO_NOVEDADES_HALLAZGOS = ? AND ID_TITULO_NOVEDADES_HALLAZGOS <> ?",
                                          'si', array((string)$nombre, (int)$excepto));
        }

        //Todos los títulos (tabla de la pantalla)
        public function listar()
        {
                return ejecutarConsulta("SELECT * FROM titulo_novedades_hallazgos");
        }

        //Los títulos activos (desplegable de Novedades)
        public function select()
        {
                return ejecutarConsulta("SELECT * FROM titulo_novedades_hallazgos WHERE ESTADO=1");
        }

        //Desactiva un título (no se borra: las novedades viejas lo siguen mostrando)
        public function desactivar($idTitulo)
        {
                return (bool)HanaDB::q("UPDATE titulo_novedades_hallazgos SET ESTADO = 0 WHERE ID_TITULO_NOVEDADES_HALLAZGOS = ?", 'i', array((int)$idTitulo));
        }

        //Vuelve a activar un título
        public function activar($idTitulo)
        {
                return (bool)HanaDB::q("UPDATE titulo_novedades_hallazgos SET ESTADO = 1 WHERE ID_TITULO_NOVEDADES_HALLAZGOS = ?", 'i', array((int)$idTitulo));
        }
}
