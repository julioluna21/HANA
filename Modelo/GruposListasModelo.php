<?php
/*
  HANA - Listas de chequeo (modelo)
  ---------------------------------------------------------------------------
  Consultas de la tabla grupo_lista_chequeo: cada tipo de lista (el "grupo" de preguntas).
  Lo usa Control/GruposListasControl.php.
*/
require "../Conexion/ConexionDB.php";
class grupos{
     //Constructor vacío: la clase no guarda datos propios
     public function __construct()
     {
     }
     //Crea un tipo de lista de chequeo
     public function insertar($nombreLista)
     {
             $sql = "INSERT INTO grupo_lista_chequeo(NOM_GRUPO_LISTA_CHEQUEO,ESTADO)
                         VALUES ('$nombreLista','1')";
             return ejecutarConsulta($sql); //ejecutarConsulta está en Conexion/ConexionDB.php
     }
     //Cambia el nombre de un tipo de lista
     public function editar($id, $nombreLista)
     {
             $sql = "UPDATE grupo_lista_chequeo SET NOM_GRUPO_LISTA_CHEQUEO='$nombreLista', ESTADO='1' where 
                 ID_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Un tipo de lista, para cargarlo en el formulario
     public function mostrar($id)
     {
             $sql = "SELECT * FROM grupo_lista_chequeo WHERE ID_GRUPO_LISTA_CHEQUEO='$id'";
             return ejecutarConsultaSimpleFila($sql);
     }
     //Todos los tipos de lista
     public function listar()
     {
             $sql = "SELECT * FROM grupo_lista_chequeo";
             return ejecutarConsulta($sql);
     }
     //Desactiva un tipo de lista (no se borra)
     public function anular($id)
     {
             $sql = "UPDATE grupo_lista_chequeo SET ESTADO=0 WHERE ID_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Listas activas para el buscador; si se escribió algo, filtra por el nombre
     public function select($busqueda)
     {
             if ($busqueda === 0) {
                     $sql = "SELECT * FROM grupo_lista_chequeo WHERE ESTADO= '1' ORDER BY NOM_GRUPO_LISTA_CHEQUEO ASC";
                     return ejecutarConsulta($sql);
             } else {
                     $sql = "SELECT * FROM grupo_lista_chequeo WHERE NOM_GRUPO_LISTA_CHEQUEO LIKE '%".$busqueda."%' AND ESTADO= '1' ORDER BY NOM_GRUPO_LISTA_CHEQUEO ASC";
                     return ejecutarConsulta($sql);
             }
             
     }
}
