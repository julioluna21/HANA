<?php
session_start();
require_once "../Modelo/ListasModelo.php";
$Listas = new listas();
// Obtiene los datos del formulario de datos.
setlocale(LC_ALL,'es-Es');// Activa la localización con el sistema para mostrar en español
date_default_timezone_set("America/Lima");
$idLista = isset($_POST["idLista"]) ? limpiarCadena($_POST["idLista"]) : "";
$idRespuesta = isset($_POST["idRespuesta"]) ? limpiarCadena($_POST["idRespuesta"]) : "";
$idGrupo = isset($_POST["idGrupo"]) ? limpiarCadena($_POST["idGrupo"]) : "";
$fechaEncuesta = isset($_POST["fechaEncuesta"]) ? $_POST["fechaEncuesta"] : '';
$nombreColaborador = isset($_POST["nombreColaborador"]) ? $_POST["nombreColaborador"] : '';

$idCentro = isset($_POST["selectCentro"]) ? $_POST["selectCentro"] : '';

$preguntas= array();
$titulos= array();
$descPregunta= array();
$fechaNovedad=date("Y-m-d_H:i:s");
$fecha2=date("Y-m-j H:i:s");

switch ($_GET["op"]) {
  case "validaListaIniciada":
    try {
      $rspta = $Listas->validaListaIniciada($idGrupo,$fechaEncuesta,$idCentro);
      //$rspta ? http_response_code(200) : http_response_code(400);
      echo json_encode($rspta);
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
    break;  
  case 'guardar':
    //try {
      
      if (empty($idLista)) {
		  
		  $rspta = $Listas->preguntasnovedad($idGrupo);
		   while ($row = $rspta->fetch_object()) {
		   $preguntas[]=$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO;
		   $titulos[$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO]=$row->TITULO_NOVEDAD;	
		   $descPregunta[$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO]=$row->PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO;		   
		   }
		  
        //var_dump($idLista);
      $idPregunta = '';
      //print_r($idCentro.",".$fechaEncuesta.",".$nombreColaborador.",".$idGrupo);
      $rspta = $Listas->insertarLista($idCentro, $fechaEncuesta, $nombreColaborador, $idGrupo);
      //$rspta ? http_response_code(200) : http_response_code(400);
      //print_r($rspta);
      if ($rspta) {
       $idLista = $rspta; //muestra id de encabezado lista respuesta
        foreach ($_POST as $key => $value) {
          if (preg_match('/answer_(\d+)/', $key, $matches)) {
            $idPregunta = $matches[1];
            $respuesta = $value;
            //print_r($idLista.",".$idPregunta.",".$respuesta.",".$nombreColaborador);
			  
			if(in_array($idPregunta,$preguntas) and ($respuesta=="2" or $respuesta=="Malo" or $respuesta=="Regular")){
				
			        $rspta=$Listas->diaslimites();
                    $dias=$rspta['DIAS_ESTADOS_RELEVANCIAS'];
                    $date_now = date('Y-m-j H:i:s', strtotime($fecha2));   
                    $date_past = strtotime('+'.$dias.' days', strtotime($date_now));
                    $date_past = date ('Y-m-j H:i:s' ,$date_past);
				
				
				$respuestaNo="";
				if($respuesta=="2"){
					$respuestaNo="NO";
				}else{
					$respuestaNo=$respuesta;
				}
				
				$descripcion="Se registra novedad automatica por medio del diligenciamiento de la lista de chequeo, de la pregunta ".$descPregunta[$idPregunta]." repondiendo ".$respuestaNo; 
				
				$rspta=$Listas->insertarNovedad($fechaNovedad,$_SESSION['Idcolaborador'],$idCentro,$_SESSION['Idcolaborador'],2,$titulos[$idPregunta],$descripcion,"Procedente",2,$date_past);
				
			}  
            $rspta1 = $Listas->insertarRespuesta($idLista, $idPregunta,  $respuesta, $nombreColaborador);
            //$rspta1 ? http_response_code(200) : http_response_code(400);
            if ($rspta1) {             
              $rspta2 = $Listas->getCantRespuestas($idLista);
              $rspta2 ? $rspta2 : 0;
              $setCantRespuestas = 1 + $rspta2['CANT_RESPUESTAS_LISTA'];
              $rspta3 = $Listas->insertarCantRespuestas($idLista, $setCantRespuestas);
              echo $rspta3 ? http_response_code(200) : http_response_code(400);
            }
          }
        }
      }
      }else{         
        
      //var_dump($idLista);
        $idPregunta = '';
        //print_r($idCentro.",".$fechaEncuesta.",".$nombreColaborador);
       /* $rspta = $Listas->editarLista($idLista,$idCentro, $fechaEncuesta, $nombreColaborador);
        echo $rspta ? http_response_code(200) : http_response_code(400);
        if ($rspta) {
          $idLista = $rspta; //muestra id de encabezado lista respuesta
          
          foreach ($_POST as $key => $value) {
            if (preg_match('/answer_(\d+)/', $key, $matches)) {
              $idPregunta = $matches[1];
              $respuesta = $value;
              $rspta1 = $Listas->editarRespuesta($idRespuesta, $idLista, $idPregunta,  $respuesta);
              echo $rspta1 ? http_response_code(200) : http_response_code(400);
            }
          }*/
        //}
      }
   /* } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }*/
    break;
    case 'editarUnaRespuesta':
      print_r($idRespuesta);
      if (!empty($idRespuesta)) {
        $nombreColaborador = $_SESSION['Idcolaborador'];
		   
		   $rspta = $Listas->getCentro($idRespuesta);
		   $idGrupoget=$rspta['ID_GRUPO_LISTA_CHEQUEO'];
		   $idcentroget=$rspta['ID_CENTRO_OP'];
		   $rspta = $Listas->preguntasnovedad($idGrupoget);
		   while ($row = $rspta->fetch_object()) {
		   $preguntas[]=$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO;
		   $titulos[$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO]=$row->TITULO_NOVEDAD;	
		   $descPregunta[$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO]=$row->PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO;		   
		   }  
		  
      foreach ($_POST as $key => $value) {
        if (preg_match('/answer_(\d+)/', $key, $matches)) {
          $idPregunta = $matches[1];
          $respuesta = $value;
		
			if(in_array($idPregunta,$preguntas) and ($respuesta=="2" or $respuesta=="Malo" or $respuesta=="Regular")){
				
			        $rspta=$Listas->diaslimites();
                    $dias=$rspta['DIAS_ESTADOS_RELEVANCIAS'];
                    $date_now = date('Y-m-j H:i:s', strtotime($fecha2));   
                    $date_past = strtotime('+'.$dias.' days', strtotime($date_now));
                    $date_past = date ('Y-m-j H:i:s' ,$date_past);
				
				
				$respuestaNo="";
				if($respuesta=="2"){
					$respuestaNo="NO";
				}else{
					$respuestaNo=$respuesta;
				}
				
				$descripcion="Se registra novedad automatica por medio del diligenciamiento de la lista de chequeo, de la pregunta ".$descPregunta[$idPregunta]." repondiendo ".$respuestaNo; 
				
				$rspta=$Listas->insertarNovedad($fechaNovedad,$_SESSION['Idcolaborador'],$idcentroget,$_SESSION['Idcolaborador'],2,$titulos[$idPregunta],$descripcion,"Procedente",2,$date_past);
				
			}
			
			
          //print_r($idRespuesta.",".$respuesta.",".$nombreColaborador.",".$key);
          $rspta = $Listas->editarUnaRespuesta($respuesta, $nombreColaborador, $idRespuesta);
          $rspta ? http_response_code(200) : http_response_code(400);
        }
      }
      //
      //echo $rspta ? http_response_code(200) : http_response_code(400);
      } else {
           $nombreColaborador = $_SESSION['Idcolaborador'];
		   $rspta = $Listas->getCentro2($idLista);
		   $idGrupoget=$rspta['ID_GRUPO_LISTA_CHEQUEO'];
		   $idcentroget=$rspta['ID_CENTRO_OP_LISTA_CHEQUEO'];
		   $rspta = $Listas->preguntasnovedad($idGrupoget);
		   while ($row = $rspta->fetch_object()) {
		   $preguntas[]=$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO;
		   $titulos[$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO]=$row->TITULO_NOVEDAD;	
		   $descPregunta[$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO]=$row->PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO;		   
		   }    
		  
      foreach ($_POST as $key => $value) {
        if (preg_match('/answer_(\d+)/', $key, $matches)) {
          $idPregunta = $matches[1];
          $respuesta = $value;
			
		
		  if(in_array($idPregunta,$preguntas) and ($respuesta=="2" or $respuesta=="Malo" or $respuesta=="Regular")){
				
			        $rspta=$Listas->diaslimites();
                    $dias=$rspta['DIAS_ESTADOS_RELEVANCIAS'];
                    $date_now = date('Y-m-j H:i:s', strtotime($fecha2));   
                    $date_past = strtotime('+'.$dias.' days', strtotime($date_now));
                    $date_past = date ('Y-m-j H:i:s' ,$date_past);
				
				
				$respuestaNo="";
				if($respuesta=="2"){
					$respuestaNo="NO";
				}else{
					$respuestaNo=$respuesta;
				}
				
				$descripcion="Se registra novedad automatica por medio del diligenciamiento de la lista de chequeo, de la pregunta ".$descPregunta[$idPregunta]." repondiendo ".$respuestaNo; 
				
				$rspta=$Listas->insertarNovedad($fechaNovedad,$_SESSION['Idcolaborador'],$idcentroget,$_SESSION['Idcolaborador'],2,$titulos[$idPregunta],$descripcion,"Procedente",2,$date_past);
				
			}	
			
          //print_r($idLista.", ".$idRespuesta.",".$respuesta.",".$nombreColaborador.",". $idPregunta);
				
          $rspta = $Listas->insertarUnaRespuesta($idLista, $idPregunta, $respuesta, $nombreColaborador);
          if ($rspta) {             
            $rspta1 = $Listas->getCantRespuestas($idLista);
            $rspta1 ? $rspta1 : 0;
            $setCantRespuestas = 1 + $rspta1['CANT_RESPUESTAS_LISTA'];
            $rspta2 = $Listas->insertarCantRespuestas($idLista, $setCantRespuestas);
            echo $rspta2 ? http_response_code(200) : http_response_code(400);
          }
        }
      }
      //
      //echo $rspta ? http_response_code(200) : http_response_code(400);
      }
      
      
      break;
  case 'validarRespuesta':
    try {
      $rsmostrar = [];
      $rspta = $Listas->validarRespuesta($fechaEncuesta, $idLista, $nombreColaborador);
      if ($rspta) {
        foreach ($rspta as $key) {
          $rsmostrar[] = $key;
        }
        echo json_encode($rsmostrar);
      }
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
    break;

  case 'mostrarRespuestas':
    try {
      $lista = '';
      $respuestaMostrar = '';
      $idColaborador = '';
      $idRespuestaMostrar = '';
      $fecha ='';
      $centro ='';
      $rsmostrar = [];
      $rspta = $Listas->mostrarPreguntasRespuestas($idLista);
      if ($rspta) {
        $rspta2 = $Listas->mostrarRespuestas($idLista);
        foreach ($rspta as $key) {
          $fecha = '';
          $centro = '';
          $respuestaMostrar = '';
          $idColaborador = '';
          $lista = '';
          $idRespuestaMostrar = '';
          foreach ($rspta2 as $respuestaItem) {
            if ($respuestaItem['ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO'] === $key['ID_DETALLE_GRUPO_LISTA_CHEQUEO']) {
            
                $fecha = $respuestaItem['FEC_REGISTRO_LISTA_CHEQUEO'];
                $centro = $respuestaItem['NOM_CENTRO_OP'];
                $respuestaMostrar = $respuestaItem['RESPUTA_DETALLE_LISTA_CHEQUEO'];
                $idColaborador = $respuestaItem['ID_COLABORADOR_DETALE_LISTA_CHEQUEO'];
                $lista = $respuestaItem['ID_LISTA_CHEQUEO'];
                $idRespuestaMostrar = $respuestaItem['ID_DETALE_LISTA_CHEQUEO'];
                break;
            }
          }
          // Agregar la pregunta con su respuesta al array $rsmostrar
          $rsmostrar[] = [
            'ID_GRUPO'=> $key['ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO'],
            'NOM_GRUPO'=> $key['lista'],
            'ID_LISTA'=> $lista,
            'FECHA'=> $fecha,
            'centro'=> $centro,
            'ID_PREGUNTA' => $key['ID_DETALLE_GRUPO_LISTA_CHEQUEO'],
            'PREGUNTA' => $key['PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO'],
            'TIPO_RESPUESTA'=> $key['TIPO_RESPUESTA'],
            'ID_RESPUESTA' => $idRespuestaMostrar,
            'RESPUESTA' => $respuestaMostrar,
            'ID_COLABORADOR' => $idColaborador
          ];
        }        http_response_code(200);
          echo json_encode($rsmostrar);
      } else {
        http_response_code(400);
    }
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
    break;
//mostrar preguntas ok
  case 'mostrarPreguntas':
    try {
      $rsmostrar = [];
      $rspta1 = $Listas->mostrarPreguntas($idGrupo);
      $rspta1 ? http_response_code(200) : http_response_code(400);
      if ($rspta1) {
        foreach ($rspta1 as $key) {
          $rsmostrar[] = $key;
        }
        echo json_encode($rsmostrar);
      }
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
    break;
    //mostrar preguntas ok
      case 'mostrarPreguntasIniciadas':
        try {
          $rsmostrar = [];
          $rspta1 = $Listas->mostrarPreguntasIniciadas($idGrupo, $idCentro, $fechaEncuesta);
          $rspta1 ? http_response_code(200) : http_response_code(400);
          if ($rspta1) {
            foreach ($rspta1 as $key) {
              $rsmostrar[] = $key;
            }
            echo json_encode($rsmostrar);
          }
        } catch (\Throwable $th) {
          http_response_code(404);
          echo json_encode($th);
        }
        break;


  case 'mostrar':
    try {
      $rspta = $Listas->mostrar($idLista);
      $rspta ? http_response_code(200) : http_response_code(400);
      echo json_encode($rspta);
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
    break;
  case 'anular':
    $rspta = $Listas->anular($idLista);
    $rspta ? http_response_code(200) : http_response_code(400);
    break;

  //select ok
  case 'selectColaborador':
    $search_term = isset($_GET['search']) ? $_GET['search'] : 0;
    try {
      $rspta = $Listas->selectColaborador($search_term);
      $userData = array();
      if ($rspta->num_rows > 0) {
        while ($row = $rspta->fetch_assoc()) {
          $data['id'] = $row['ID_COLABORADOR'];
          $data['text'] = $row['NOM_COLABORADOR'];
          array_push($userData, $data);
        }
      }
      echo json_encode($userData);
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
    break;

  case 'selectCentro':
    $search_term = isset($_GET['search']) ? $_GET['search'] : 0;
    try {
      $rspta = $Listas->selectCentro($search_term);
      $userData = array();
      if ($rspta->num_rows > 0) {
        while ($row = $rspta->fetch_assoc()) {
          $data['id'] = $row['ID_CENTRO_OP'];
          $data['text'] = $row['NOM_CENTRO_OP'];
          array_push($userData, $data);
        }
      }
      echo json_encode($userData);
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
    break;
    /*
      case 'selectCentroId':
          $search_term = isset($_GET['search'])?$_GET['search']:0;
          try {
            $rspta=$Listas->selectCentroId($search_term);
            $userData = array();
            if ($rspta->num_rows >0) {
              while ($row = $rspta->fetch_assoc()) {
                $data['id'] = $row['ID_CENTRO_OP'];
                $data['text'] = $row['NOM_CENTRO_OP'];
                array_push($userData, $data);
              }
            }
            echo json_encode($userData);
          } catch (\Throwable $th) {
            http_response_code(404);
            echo json_encode($th);
          }
        break;*/
    //listas grupos  a responder ok
  case 'listar':
    try {
      $rspta = $Listas->listar();
      $userData = array();
      while ($row = $rspta->fetch_object()) {
        //ARRAY PARA LISTAR
        $userData[] = array(
          "0" => $row->ID_GRUPO_LISTA_CHEQUEO,
          "1" => $row->NOM_GRUPO_LISTA_CHEQUEO,
          "2" => $row->CANT_PREGUNTAS_GRUPO,//ESTADO == 1 ? "Activo" : "Inactivo",
          "3" => '<SPAN title="Responder"><button class="btn btn-success" onclick="mostrarPreguntas(' . $row->ID_GRUPO_LISTA_CHEQUEO . ')"><i class="fa fa-pencil"></i></button></SPAN>'.
          '<SPAN title="Mostra Respuestas"><button class="btn btn-success" onclick="listarRespuestas(' . $row->ID_GRUPO_LISTA_CHEQUEO . ')"><i class="fa fa-eye"></i></button></SPAN>'
          
        );
      }
      $results = array(
        "sEcho" => 1,
        "iTotalRecords" => count($userData),
        "iTotalDisplayRecords" => count($userData),
        "aaData" => $userData
      );
      echo json_encode($results);
      
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
    break;
    //contar ok
    case "contar":
      try{
        $rspta=$Novedades->centrosOP($_SESSION['IdUsuarios']);//Carga la rspta con lista de articulos
                     $centrosoperativos= Array();
                     while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                     {
                         $centrosoperativos[]=$reg->ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP;
                         
                     }
      $rspta= $Listas->contarRespuestas($idGrupo, $idLista);
      while ($row1 = mysqli_fetch_assoc($rspta)) {
        $preguntas = $row1["npreguntas"];
        $respuestas = $row1["nrespuestas"];
        $avance = floor((100 * ($respuestas / $preguntas))/10)*10;
        echo $avance;
      }
      
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
      break;
    //listas listas respuestas ok
  case 'listarRespuestas':
    try {
      $avance=0;
      $rspta = $Listas->listarRespuestas($idGrupo);
      $userData = array();
      while ($row = $rspta->fetch_object()) {
        $idLista=$row->ID_LISTA_CHEQUEO;
        //TRAE # PREGUNTAS Y RESPUESTAS PARA %
        $rspta1 = $Listas->contarRespuestas($idGrupo, $idLista);
        while ($row1 = mysqli_fetch_assoc($rspta1)) {
          $preguntas = $row1["npreguntas"];
          $respuestas = $row1["nrespuestas"];
          $avance = floor(100 * ($respuestas / ($preguntas))/10)*10;
        }
        $rutaSVG = '../public/img/'.$avance.'.svg';
        $contenidoSVG = file_get_contents($rutaSVG);
        $userData[] = array(
          "0" => $row->ID_LISTA_CHEQUEO,
          "1" => $row->NOM_GRUPO_LISTA_CHEQUEO,
          "2" => $row->FEC_REGISTRO_LISTA_CHEQUEO,
          "3" => $row->NOM_CENTRO_OP,
          "4" => $row->NOM_COLABORADOR,
          "5"=>'<SPAN title="Editar"><button class="btn btn-success" onclick="mostrarRespuestas(' . $row->ID_LISTA_CHEQUEO . ')"><i class="fa fa-eye"></i></button></SPAN>',
          "6" => '<img src="data:image/svg+xml;base64,' . base64_encode($contenidoSVG) . '" alt="Imagen SVG">' //'<img class="imagen-avance" src="../public/img/'.$avance.'.svg" width="60" height="60">' 
        );
      }
      $results = array(
        "sEcho" => 1,
        "iTotalRecords" => count($userData),
        "iTotalDisplayRecords" => count($userData),
        "aaData" => $userData
      );
      echo json_encode($results);
    } catch (\Throwable $th) {
      http_response_code(404);
      echo json_encode($th);
    }
    break;
         
        
    case 'listarPorCentro'://activado por el ajax en el scrip articulos  
                          
       $rspta=$Listas->centrosOP($_SESSION['IdUsuarios']);//Carga la rspta con lista de articulos
       $centrosoperativos= Array();
       while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
       {
           $centrosoperativos[]=$reg->ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP;
           
       }
       
        $avance=0;
        $rspta = $Listas->listarRespPorCentro($idGrupo,$centrosoperativos);
        //var_dump($rspta);
        $userData = array();
        while ($row = $rspta->fetch_object()) {
          $idLista=$row->ID_LISTA_CHEQUEO;
          $fechaEncuesta = substr($row->FEC_REGISTRO_LISTA_CHEQUEO, 0,10);
          //TRAE # PREGUNTAS Y RESPUESTAS PARA %
          $rspta1 = $Listas->contarRespuestas($idGrupo, $idLista);
          while ($row1 = mysqli_fetch_assoc($rspta1)) {
            $preguntas = $row1["npreguntas"];
            $respuestas = $row1["nrespuestas"];
            $avance = floor(100 * ($respuestas / ($preguntas))/10)*10;
          }
          $rutaSVG = '../public/img/'.$avance.'.svg';
          $contenidoSVG = file_get_contents($rutaSVG);
          $userData[] = array(
            "0" => $row->ID_LISTA_CHEQUEO,
            "1" => $row->NOM_GRUPO_LISTA_CHEQUEO,
            "2" => $row->FEC_REGISTRO_LISTA_CHEQUEO,
            "3" => $row->NOM_CENTRO_OP,
            "4" => $row->NOM_COLABORADOR,
            "5"=>'<SPAN title="Editar"><button class="btn btn-success" onclick="mostrarRespuestas(' . $row->ID_LISTA_CHEQUEO .','. $fechaEncuesta.')"><i class="fa fa-eye"></i></button></SPAN>',
            "6" =>'<img src="data:image/svg+xml;base64,' . base64_encode($contenidoSVG) . '" alt="Imagen SVG"  width="60" height="60">' //'<img class="imagen-avance" src="../public/img/'.$avance.'.svg" width="60" height="60">' 
          );
        }
        $results = array(
          "sEcho" => 1,
          "iTotalRecords" => count($userData),
          "iTotalDisplayRecords" => count($userData),
          "aaData" => $userData
        );
        echo json_encode($results);
     
    break;
}
