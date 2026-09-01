<?php
session_start();
require_once "../Modelo/GruposListasModelo.php";
require_once "../Modelo/PreguntasListasModelo.php";
$Grupos = new grupos;
$Preguntas = new preguntas;
// Obtiene los datos del formulario de datos.
$idLista=isset($_POST['idLista'])? limpiarCadena($_POST['idLista']):'';
$nombreLista = isset($_POST["nombre"])? limpiarCadena($_POST['nombre']):'';
//$preguntasRespuestas = isset($_POST["preguntasRespuestas"])?$_POST["preguntasRespuestas"]:'';

switch ($_GET["op"]) {
  case 'guardar':
    try {
      if (empty($idLista)) {
        $rspta=$Grupos->insertar($nombreLista);
            echo $rspta? http_response_code(200) : http_response_code(400);
       } else {  
        $rspta=$Grupos->editar($idLista, $nombreLista);
        $rspta? http_response_code(200) : http_response_code(400);
       }
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }  
    break;
  
  case 'mostrar':
    try {
      $rspta=$Grupos->mostrar($idLista);
      $rspta ? http_response_code(200) : http_response_code(400);
      echo json_encode($rspta);
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
    break;
  
    /*case 'mostrarPreguntas':
      try {
        $rsmostrar = [];
        $rspta1 = $Preguntas->mostrar($idLista);
        $rspta1 ? http_response_code(200):http_response_code(400);
        foreach ($rspta1 as $key) {
            $rsmostrar[]=$key;
        }
        echo json_encode($rsmostrar);
      } catch (\Throwable $th) {
        http_response_code(404);
        echo json_encode($th);
      }
      break;*/
  case 'anular':
    $rspta=$Grupos->anular($idLista);
    $rspta? http_response_code(200):http_response_code(400);
    break;
  case 'select':
      $search_term = isset($_GET['search'])?$_GET['search']:'0';
      try {
        $rspta=$Grupos->select($search_term);
        $userData = array();
        if ($rspta->num_rows >0) {
          while ($row = $rspta->fetch_assoc()) {
            $data['id'] = $row['ID_GRUPO_LISTA_CHEQUEO'];
            $data['nombre'] = $row['NOM_GRUPO_LISTA_CHEQUEO'];
            array_push($userData, $data);
          }
        }
        echo json_encode($userData);
      } catch (\Throwable $th) {
        http_response_code(404);
        echo json_encode($th);
      }
    break;
  case 'listar':
    try {
      $rspta= $Grupos->listar();
      $userData = array();
        while ($row = $rspta->fetch_object()) {
          $userData[] = array(
            "0"=>$row->ID_GRUPO_LISTA_CHEQUEO,
            "1"=>$row->NOM_GRUPO_LISTA_CHEQUEO,
            "2"=>$row->ESTADO == 1?"Activo":"Inactivo",
            "3"=>'<SPAN title="Editar"><button class="btn btn-success" onclick="mostrar('.$row->ID_GRUPO_LISTA_CHEQUEO.')"><i class="fa fa-eye"></i></button></SPAN>
            <SPAN title="anular"><button type="button" class="btn btn-success" onclick="anular('.$row->ID_GRUPO_LISTA_CHEQUEO.')"><i class="fa fa-trash-o"></i></button></SPAN>
            <SPAN tittle="Agregar Preguntas"><button type="button" id="addPreguntas" class="btn btn-primary" data-toggle="modal" data-target="#modal-preguntas" data-idGrupo="'.$row->ID_GRUPO_LISTA_CHEQUEO.'">
                Agregar preguntas
            </button></SPAN> <SPAN tittle="Agregar desde Excel"><button type="button" id="addExcel" class="btn btn-primary" data-toggle="modal" data-target="#modal-excel" data-idGrupo="'.$row->ID_GRUPO_LISTA_CHEQUEO.'">
            Agregar desde Excel
        </button></SPAN>'
          );
        }
        $results = array(
          "sEcho"=> 1,
          "iTotalRecords"=>count($userData),
          "iTotalDisplayRecords"=>count($userData),
          "aaData"=>$userData);
          echo json_encode($results);
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
    break;
}


?>