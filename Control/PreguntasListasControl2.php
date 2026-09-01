<?php
session_start();
require_once "../Modelo/PreguntasListasModelo.php";
$Preguntas = new preguntas();
// Obtiene los datos del formulario de datos.
$idLista=isset($_POST['idGrupo'])? limpiarCadena($_POST['idGrupo']):'';
$idPregunta=isset($_POST['idPregunta'])? limpiarCadena($_POST['idPregunta']):'';
$pregunta = isset($_POST["pregunta"])? limpiarCadena($_POST['pregunta']):'';
$tiposRespuesta = isset($_POST["tipo-respuesta"])? limpiarCadena($_POST["tipo-respuesta"]):'';
$idGrupoPreguntas=isset($_POST['idGrupoPreguntas'])? limpiarCadena($_POST['idGrupoPreguntas']):'';

switch ($_GET["op"]) {
  case 'guardar':

    if (empty($idPregunta)) {
      $rspta = $Preguntas->consCantPreguntas($idLista);
      if ($rspta != '') {
        $rspt = $rspta->fetch_assoc();
        $numPreguntas = $rspt['cantPreguntas'] + 1;
        $rspta1 = $Preguntas->insertar($idLista, $pregunta, $tiposRespuesta);
        if ($rspta1 != '') {
          $rspta = $Preguntas->insertarCantPreguntas($idLista, $numPreguntas);
          echo $rspta ? http_response_code(200) : http_response_code(400);
        }
      }else{
        echo http_response_code(400);
      }
    } else {
      $rspta = $Preguntas->editar($idPregunta, $pregunta, $tiposRespuesta);
      echo $rspta ? http_response_code(200) : http_response_code(400);
    }


    try {
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
    break;
  
  case 'mostrar':
    try {
      $rspta=$Preguntas->mostrar($idPregunta);
      $rspta ? http_response_code(200) : http_response_code(400);
      echo json_encode($rspta);
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
    break;
    
  case 'importar':
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $csvMimes = array('text/x-comma-separated-values', 'text/comma-separated-values', 'application/octet-stream', 'application/vnd.ms-excel', 'application/x-csv', 'text/x-csv', 'text/csv', 'application/csv', 'application/excel', 'application/vnd.msexcel', 'text/plain');
      if (!empty($_FILES['file']['name']) && in_array($_FILES['file']['type'], $csvMimes)) {
        if (is_uploaded_file($_FILES['file']['tmp_name'])) {
          $csvFile = fopen($_FILES['file']['tmp_name'], 'r');
          fgetcsv($csvFile);    
          //questions list count  
          $rspta = $Preguntas->consCantPreguntas($idLista);
          if ($rspta != '') {
            $rspt = $rspta->fetch_assoc();
            $numPreguntas = $rspt['cantPreguntas'];//isset($rspt['cantPreguntas'])? $rspt['cantPreguntas']+ 1:0;
          
          }
          while (($line = fgetcsv($csvFile, 0, ';')) !== false) {
            $pregunta=$line[0];
            $tiposRespuesta=$line[1];
            $rspta1 = $Preguntas->insertar($idLista, $pregunta, $tiposRespuesta);
                      if ($rspta1 != '') {
                        $numPreguntas = $numPreguntas + 1;
                
                        $rspta = $Preguntas->insertarCantPreguntas($idLista, $numPreguntas);
                      }
          }
          echo 'Datos importados correctamente.';
        }else{
          echo 'Error al importar datos';
        }
      }else{
        echo 'No se ha proporcionado un archivo o formato no compatible.';
      }
  } else {
      echo 'Método no permitido par el envío del archivo.';
  }
  break;
  
    case 'mostrarPreguntas':
      try {
        $rsmostrar = [];
        $rspta1 = $Preguntas->mostrar($idPregunta);
        $rspta1 ? http_response_code(200):http_response_code(400);
        foreach ($rspta1 as $key) {
            $rsmostrar[]=$key;
        }
        echo json_encode($rsmostrar);
      } catch (\Throwable $th) {
        http_response_code(404);
        echo json_encode($th);
      }
      break;
  case 'anular':
    $rspta = $Preguntas->consCantPreguntas($idLista);     
       // echo "Anulando";
    if ($rspta) {
      $rspt = $rspta->fetch_assoc();
      $numPreguntas = $rspt['cantPreguntas'];
      $rspta1=$Preguntas->anular($idPregunta); 
      //var_dump($numPreguntas);
      if ($rspta1 != '') {
        $numPreguntas = $numPreguntas - 1;//isset($rspt['cantPreguntas'])? $rspt['cantPreguntas']+ 1:0;
        //var_dump($numPreguntas);
        $rspta = $Preguntas->insertarCantPreguntas($idLista, $numPreguntas);
        echo $rspta ? http_response_code(200) : http_response_code(400);
      }
    }
    break;
  case 'select':
      $search_term = isset($_GET['search'])?$_GET['search']:'0';
      try {
        $rspta=$Preguntas->select($search_term);
        $userData = array();
        if ($rspta->num_rows >0) {
          while ($row = $rspta->fetch_assoc()) {
            $data['id'] = $row['ID_GRUPO_LISTA_CHEQUEO'];
            $data['pregunta'] = $row['NOM_GRUPO_LISTA_CHEQUEO'];
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
      $rspta = $Preguntas->consCantPreguntas($idGrupoPreguntas);
      $rspt = $rspta->fetch_assoc();
      $numPreguntas = $rspt['cantPreguntas'];
      //print_r($numPreguntas);
      $rspta = $Preguntas->insertarCantPreguntas($idGrupoPreguntas, $numPreguntas);

      $rspta= $Preguntas->listar($idGrupoPreguntas);
      //print_r($rspta);
      $userData = array();
        while ($row = $rspta->fetch_object()) {
          $userData[] = array(
            "0"=>$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO,
            "1"=>$row->ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO,
            "2"=>$row->PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO,
            "3"=>$row->TIPO_RESPUESTA,
            "4"=>$row->ESTADO == 1 ? "Activo":"Inactivo",
            "5"=>'<SPAN title="Editar"><button class="btn btn-success" onclick="mostrarPregunta('.$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO.')"><i class="fa fa-eye"></i></button></SPAN>
            <SPAN title="anular"><button type="button" class="btn btn-success" onclick="anularPregunta('.$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO.','.$row->ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO.')"><i class="fa fa-trash-o"></i></button></SPAN>'
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