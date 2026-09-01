<?php
require "../Conexion/ConexionDB.php";
class grupos{
     //Implementamos el super constructor 
     public function __construct()
     {
     }
     //Implementamos un método para insertar registros
     public function insertar($nombreLista)
     {
             $sql = "INSERT INTO grupo_lista_chequeo(NOM_GRUPO_LISTA_CHEQUEO,ESTADO)
                         VALUES ('$nombreLista','1')";
             return ejecutarConsulta($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
     }
     //Implementamos un método para editar registros
     public function editar($id, $nombreLista)
     {
             $sql = "UPDATE grupo_lista_chequeo SET NOM_GRUPO_LISTA_CHEQUEO='$nombreLista', ESTADO='1' where 
                 ID_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     //Implementar un método para mostrar los datos de un registro a modificar
     public function mostrar($id)
     {
             $sql = "SELECT * FROM grupo_lista_chequeo WHERE ID_GRUPO_LISTA_CHEQUEO='$id'";
             return ejecutarConsultaSimpleFila($sql);
     }
     //Implementar un método para listar los registros
     public function listar()
     {
             $sql = "SELECT * FROM grupo_lista_chequeo";
             return ejecutarConsulta($sql);
     }
     //Implementamos un método para editar reasignar contraseña
     public function anular($id)
     {
             $sql = "UPDATE grupo_lista_chequeo SET ESTADO=0 WHERE ID_GRUPO_LISTA_CHEQUEO=$id";
             return ejecutarConsulta($sql);
     }
     public function select($search_term)
     {
             if ($search_term === 0) {
                     $sql = "SELECT * FROM grupo_lista_chequeo WHERE ESTADO= '1' ORDER BY NOM_GRUPO_LISTA_CHEQUEO ASC";
                     return ejecutarConsulta($sql);
             } else {
                     $sql = "SELECT * FROM grupo_lista_chequeo WHERE NOM_GRUPO_LISTA_CHEQUEO LIKE '%".$search_term."%' AND ESTADO= '1' ORDER BY NOM_GRUPO_LISTA_CHEQUEO ASC";
                     return ejecutarConsulta($sql);
             }
             
     }
}
