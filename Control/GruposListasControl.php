<?php
/*
  HANA - Listas de chequeo (los tipos de lista) (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/GruposListasnAjax.js y Ajax/Editarpreguntasajax.js y responde con lo que
  trae el modelo (Modelo/GruposListasModelo.php).
  Cada petición llega con ?op=... y cae en uno de los "case" de abajo:
    guardar, mostrar, anular, select y listar.
  Casilla 12M para cambiarlas; con llenar (11M), ver (21M) o correos (44M) solo se consultan.
*/
session_start();
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
hanaGuardia(array('12M', '24M'), array('select', 'mostrar', 'listar'), array(), array('11M', '12M', '21M', '44M', '24M')); //Cambiar listas: 12M; verlas: llenar, ver, editar o correos de listas
require_once "../Modelo/GruposListasModelo.php";
require_once "../Modelo/PreguntasListasModelo.php";
$Grupos = new grupos;
//Lo que llega del formulario
$idLista=isset($_POST['idLista'])? limpiarCadena($_POST['idLista']):'';
$nombreLista = isset($_POST["nombre"])? limpiarCadena($_POST['nombre']):'';

switch (isset($_GET["op"]) ? $_GET["op"] : '') {
  //Crea una lista de chequeo nueva o le cambia el nombre a una existente
  case 'guardar':
    try {
      if (empty($idLista)) {
        $resultado=$Grupos->insertar($nombreLista);
            echo $resultado? http_response_code(200) : http_response_code(400);
       } else {  
        $resultado=$Grupos->editar($idLista, $nombreLista);
        $resultado? http_response_code(200) : http_response_code(400);
       }
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }  
    break;
  
  //Trae una lista de chequeo para cargarla en el formulario
  case 'mostrar':
    try {
      $resultado=$Grupos->mostrar($idLista);
      $resultado ? http_response_code(200) : http_response_code(400);
      echo json_encode($resultado);
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }
    break;

  //Desactiva un tipo de lista (no se borra)
  case 'anular':
    $resultado=$Grupos->anular($idLista);
    $resultado? http_response_code(200):http_response_code(400);
    break;
  //Opciones del buscador de listas de chequeo (filtra por lo que se escriba)
  case 'select':
      $busqueda = isset($_GET['search'])?$_GET['search']:'0';
      try {
        $resultado=$Grupos->select($busqueda);
        $filasRespuesta = array();
        if ($resultado->num_rows >0) {
          while ($fila = $resultado->fetch_assoc()) {
            $opcion['id'] = $fila['ID_GRUPO_LISTA_CHEQUEO'];
            $opcion['nombre'] = $fila['NOM_GRUPO_LISTA_CHEQUEO'];
            array_push($filasRespuesta, $opcion);
          }
        }
        echo json_encode($filasRespuesta);
      } catch (\Throwable $error) {
        http_response_code(404);
        echo json_encode($error);
      }
    break;
  //Todos los tipos de lista, para la tabla de la pantalla
  case 'listar':
    try {
      $resultado= $Grupos->listar();
      $filasRespuesta = array();
        while ($fila = $resultado->fetch_object()) {
          $filasRespuesta[] = array(
            "0"=>$fila->ID_GRUPO_LISTA_CHEQUEO,
            "1"=>$fila->NOM_GRUPO_LISTA_CHEQUEO,
            "2"=>$fila->ESTADO == 1?"Activo":"Inactivo",
            "3"=>'<SPAN title="Editar"><button class="btn btn-success" onclick="mostrar('.$fila->ID_GRUPO_LISTA_CHEQUEO.')"><i class="fa fa-eye"></i></button></SPAN>
            <SPAN title="anular"><button type="button" class="btn btn-success" onclick="anular('.$fila->ID_GRUPO_LISTA_CHEQUEO.')"><i class="fa fa-trash-o"></i></button></SPAN>
            <SPAN title="Agregar preguntas"><button type="button" id="addPreguntas" class="btn btn-primary" data-toggle="modal" data-target="#modal-preguntas" data-idGrupo="'.$fila->ID_GRUPO_LISTA_CHEQUEO.'">
                Agregar preguntas
            </button></SPAN> <SPAN title="Agregar desde Excel"><button type="button" id="addExcel" class="btn btn-primary" data-toggle="modal" data-target="#modal-excel" data-idGrupo="'.$fila->ID_GRUPO_LISTA_CHEQUEO.'">
            Agregar desde Excel
        </button></SPAN>'
          );
        }
        $respuesta = array(
          "sEcho"=> 1,
          "iTotalRecords"=>count($filasRespuesta),
          "iTotalDisplayRecords"=>count($filasRespuesta),
          "aaData"=>$filasRespuesta);
          echo json_encode($respuesta);
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }
    break;
}


?>