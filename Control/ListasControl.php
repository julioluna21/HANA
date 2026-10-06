<?php
session_start();
require_once __DIR__ . '/Guardia.php'; //sesión y permisos (antes no se revisaban)
hanaGuardia(array('11M', '12M')); //Diligenciar listas: 11M; administrarlas: 12M
require_once "../Modelo/ListasModelo.php";
require_once __DIR__ . "/../Modelo/HanaDB.php";     //consultas preparadas
require_once __DIR__ . "/../Modelo/HanaFechas.php"; //solo hoy, o un día que el administrador habilitó
$Listas = new listas();
// Obtiene los datos del formulario de datos.
setlocale(LC_ALL,'es-Es');// Activa la localización con el sistema para mostrar en español
date_default_timezone_set("America/Lima");
$idLista = isset($_POST["idLista"]) ? limpiarCadena($_POST["idLista"]) : "";
$idRespuesta = isset($_POST["idRespuesta"]) ? limpiarCadena($_POST["idRespuesta"]) : "";
$idGrupo = isset($_POST["idGrupo"]) ? limpiarCadena($_POST["idGrupo"]) : "";
$fechaEncuesta = isset($_POST["fechaEncuesta"]) ? $_POST["fechaEncuesta"] : '';
//Quien diligencia sale SIEMPRE de la sesión. Antes venía de un campo oculto de
//la pantalla, que se puede cambiar: una lista podía quedar a nombre de otro
$nombreColaborador = isset($_SESSION['Idcolaborador']) ? $_SESSION['Idcolaborador'] : '';

$idCentro = isset($_POST["selectCentro"]) ? $_POST["selectCentro"] : '';

$huboFallo = false; //queda en true si alguna respuesta no se pudo guardar
$preguntas= array();
$titulos= array();
$descPregunta= array();
$fechaNovedad=date("Y-m-d H:i:s");
$fecha2=date("Y-m-j H:i:s");

switch ($_GET["op"]) {
  //Los días en que esta persona puede diligenciar una lista: hoy y los que le habilitó el administrador
  case "dias":
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('hoy' => HanaFechas::hoy(), 'habilitados' => HanaFechas::habilitados()));
    break;
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
      //Por defecto las listas las llena el coordinador del proyecto (Parámetros del
      //sistema: LISTAS_SOLO_COORDINADOR). Si se apaga, basta el permiso 11M del rol
      require_once __DIR__ . '/../Modelo/HanaConfig.php';
      if (HanaConfig::si('LISTAS_SOLO_COORDINADOR') && !HanaDB::esCoordinador((int)$_SESSION['Idcolaborador'])) {
        http_response_code(403);
        echo 'Las listas de chequeo las llena el coordinador del proyecto.';
        break;
      }
      
      if (empty($idLista)) {
      //La lista es de hoy, o de un día que el administrador le habilitó (lo elige la
      //pantalla); cualquier otra fecha se rechaza aquí, no solo en la pantalla
      if (HanaVal::fecha(substr((string)$fechaEncuesta, 0, 10)) === '' || !HanaFechas::enVentana($fechaEncuesta)) {
        http_response_code(400);
        echo 'La fecha de la lista no es válida: solo se diligencian listas de ' . HanaFechas::textoVentana() . '.';
        break;
      }
      //El peaje tiene que ser de los suyos (de un proyecto que coordina o asignado en Usuarios)
      if (!$Listas->centroAsignado($_SESSION['IdUsuarios'], intval($idCentro))) {
        http_response_code(400);
        echo 'Ese peaje no es de los proyectos que coordinas.';
        break;
      }
		  
		  $rspta = $Listas->preguntasnovedad($idGrupo);
		   while ($row = $rspta->fetch_object()) {
		   $preguntas[]=$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO;
		   $titulos[$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO]=$row->TITULO_NOVEDAD;	
		   $descPregunta[$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO]=$row->PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO;		   
		   }
		  
        //var_dump($idLista);
      $idPregunta = '';
      //print_r($idCentro.",".$fechaEncuesta.",".$nombreColaborador.",".$idGrupo);
      $tipos = array();
      $rsptaTipos = $Listas->mostrarPreguntas($idGrupo);
      if ($rsptaTipos) { while ($rowT = $rsptaTipos->fetch_object()) { $tipos[$rowT->ID_DETALLE_GRUPO_LISTA_CHEQUEO] = $rowT->TIPO_RESPUESTA; } }
      $fechaBase = date('Y-m-d');
      foreach ($_POST as $kf => $vf) { if (preg_match('/answer_(\d+)/', $kf, $mf) && isset($tipos[$mf[1]]) && $tipos[$mf[1]] === 'fecha' && $vf !== '') { $fechaBase = $vf; break; } }
      $rspta = $Listas->insertarLista($idCentro, $fechaEncuesta, $nombreColaborador, $idGrupo);
      //$rspta ? http_response_code(200) : http_response_code(400);
      //print_r($rspta);
      if ($rspta) {
       $idLista = $rspta; //muestra id de encabezado lista respuesta
        foreach ($_FILES as $fkey => $farchivo) {
          if (preg_match('/answer_(\d+)/', $fkey, $fmatches) && isset($farchivo['tmp_name']) && $farchivo['error'] === UPLOAD_ERR_OK) {
            $idPreguntaF = $fmatches[1];
            $mimeF = @mime_content_type($farchivo['tmp_name']);
            if ($mimeF && strpos($mimeF, 'image/') === 0) {
              $firmaB64 = 'data:' . $mimeF . ';base64,' . base64_encode(file_get_contents($farchivo['tmp_name']));
              $rsptaF = $Listas->insertarRespuesta($idLista, $idPreguntaF, $firmaB64, $nombreColaborador);
              if ($rsptaF) {
                $cantF = $Listas->getCantRespuestas($idLista);
                $setCantF = 1 + ($cantF ? $cantF['CANT_RESPUESTAS_LISTA'] : 0);
                $Listas->insertarCantRespuestas($idLista, $setCantF);
              }
            }
          }
        }
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
				
				$descripcion="Se registra novedad automatica por medio del diligenciamiento de la lista de chequeo, de la pregunta ".$descPregunta[$idPregunta]." respondiendo ".$respuestaNo; 
				
				$rspta=$Listas->insertarNovedad($fechaNovedad,$_SESSION['Idcolaborador'],$idCentro,$_SESSION['Idcolaborador'],$Listas->observadorAutomatico(),$titulos[$idPregunta],$descripcion,"Procedente",$Listas->prioridadAutomatica(),$date_past);
				
			}  
            if (isset($tipos[$idPregunta]) && $tipos[$idPregunta] === 'datetime' && $respuesta !== '') { $respuesta = date("Y-m-d h:i:s A", strtotime($fechaBase . ' ' . $respuesta)); }
            $rspta1 = $Listas->insertarRespuesta($idLista, $idPregunta,  $respuesta, $nombreColaborador);
            //$rspta1 ? http_response_code(200) : http_response_code(400);
            if ($rspta1) {             
              $rspta2 = $Listas->getCantRespuestas($idLista);
              $rspta2 ? $rspta2 : 0;
              $setCantRespuestas = 1 + $rspta2['CANT_RESPUESTAS_LISTA'];
              $rspta3 = $Listas->insertarCantRespuestas($idLista, $setCantRespuestas);
              if (!$rspta3) { $huboFallo = true; } //se anota y se decide al final
            } else {
              $huboFallo = true; //una respuesta que no se guardo hace fallar toda la lista
            }
          }
        }

        //Lista terminada: se avisa por correo a quien este configurado.
        //Va dentro de try/catch en su propio archivo: si el correo falla,
        //la lista igual queda guardada y el usuario no ve ningun error.
        require_once __DIR__ . "/EnvioNotificacionesControl.php";
        hanaNotificarInmediato($idLista);

        //Una sola respuesta al navegador, ya con todas las preguntas procesadas.
        //Antes se respondia dentro del ciclo y la ultima pregunta decidia el
        //resultado: si fallaba una del medio, la pantalla igual decia "guardado"
        if ($huboFallo) {
          http_response_code(400);
          echo "Algunas respuestas no se pudieron guardar. Revisa la lista antes de continuar.";
        } else {
          http_response_code(200);
          //El número de la lista nueva: la pantalla lo usa para subir enseguida los archivos elegidos
          echo json_encode(array('ok' => true, 'idLista' => (int)$idLista));
        }
      } else {
        //Ni siquiera se creo el encabezado de la lista
        http_response_code(400);
        echo "No se pudo crear la lista.";
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
      require_once __DIR__ . '/../Modelo/HanaConfig.php';
      if (HanaConfig::si('LISTAS_SOLO_COORDINADOR') && !HanaDB::esCoordinador((int)$_SESSION['Idcolaborador'])) {
        http_response_code(403);
        echo 'Las listas de chequeo las corrige el coordinador del proyecto.';
        break;
      }
      //Solo se corrigen listas de hoy, o de un día que el administrador habilitó.
      //Y solo las corrige quien las llenó (DUENO), nadie más
      $fechaDeLista = null;
      if ($idLista !== '') {
        $fechaDeLista = HanaDB::fila("SELECT FEC_REGISTRO_LISTA_CHEQUEO AS F, ID_COLABORADOR_LISTA_CHEQUEO AS DUENO FROM lista_chequeo WHERE ID_LISTA_CHEQUEO = ?", 'i', array((int)$idLista));
      } elseif ($idRespuesta !== '') {
        $fechaDeLista = HanaDB::fila("SELECT l.FEC_REGISTRO_LISTA_CHEQUEO AS F, l.ID_COLABORADOR_LISTA_CHEQUEO AS DUENO FROM detale_lista_chequeo d
                                        INNER JOIN lista_chequeo l ON l.ID_LISTA_CHEQUEO = d.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO
                                       WHERE d.ID_DETALE_LISTA_CHEQUEO = ?", 'i', array((int)$idRespuesta));
      }
      if ($fechaDeLista && (int)$fechaDeLista['DUENO'] !== (int)$_SESSION['Idcolaborador']) {
        http_response_code(403);
        echo 'Esta lista solo la puede corregir quien la llenó.';
        break;
      }
      if (!$fechaDeLista || !HanaFechas::enVentana($fechaDeLista['F'])) {
        http_response_code(400);
        echo 'Esta lista ya no se puede corregir: solo se modifican listas de ' . HanaFechas::textoVentana() . '.';
        break;
      }
      if (!empty($idRespuesta)) {
        $nombreColaborador = $_SESSION['Idcolaborador'];

        //Si al corregir la lista se cambió el centro operativo, se guarda PRIMERO.
        //Así, si alguna respuesta corregida genera una novedad, esa novedad queda
        //en el centro nuevo y no en el viejo
        $nuevoCentro = isset($_POST['idCentro']) ? intval($_POST['idCentro']) : 0;
        if ($nuevoCentro > 0 && $idLista !== '') {
          $actual = $Listas->getCentro2(intval($idLista));
          if ($actual && intval($actual['ID_CENTRO_OP_LISTA_CHEQUEO']) !== $nuevoCentro) {
            if (!$Listas->centroAsignado($_SESSION['IdUsuarios'], $nuevoCentro)) {
              http_response_code(400);
              echo 'Ese centro operativo no está asignado a tu usuario.';
              break;
            }
            if ($Listas->existeOtraLista(intval($idLista), $nuevoCentro)) {
              http_response_code(400);
              echo 'Ya existe una lista de este grupo para ese centro operativo en la misma fecha.';
              break;
            }
            $Listas->cambiarCentro(intval($idLista), $nuevoCentro);
          }
        }
		   
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
				
				$descripcion="Se registra novedad automatica por medio del diligenciamiento de la lista de chequeo, de la pregunta ".$descPregunta[$idPregunta]." respondiendo ".$respuestaNo; 
				
				$rspta=$Listas->insertarNovedad($fechaNovedad,$_SESSION['Idcolaborador'],$idcentroget,$_SESSION['Idcolaborador'],$Listas->observadorAutomatico(),$titulos[$idPregunta],$descripcion,"Procedente",$Listas->prioridadAutomatica(),$date_past);
				
			}
			
			
          //Al editar toda la lista de una vez, cada respuesta llega con su propio
          //identificador en resp_<idPregunta>. Si no viene, se usa el unico idRespuesta
          //que manda la ventana de "editar una sola respuesta"
          $idRespuestaActual = isset($_POST['resp_'.$idPregunta]) ? $_POST['resp_'.$idPregunta] : $idRespuesta;

          $rspta = $Listas->editarUnaRespuesta($respuesta, $nombreColaborador, $idRespuestaActual);
          if (!$rspta) { $huboFallo = true; } //se anota y se decide al final
        }
      }
      //Una sola respuesta al navegador, ya con todas las preguntas procesadas
      if ($huboFallo) {
        http_response_code(400);
        echo "Algunas respuestas no se pudieron guardar.";
      } else {
        http_response_code(200);
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
				
				$descripcion="Se registra novedad automatica por medio del diligenciamiento de la lista de chequeo, de la pregunta ".$descPregunta[$idPregunta]." respondiendo ".$respuestaNo; 
				
				$rspta=$Listas->insertarNovedad($fechaNovedad,$_SESSION['Idcolaborador'],$idcentroget,$_SESSION['Idcolaborador'],$Listas->observadorAutomatico(),$titulos[$idPregunta],$descripcion,"Procedente",$Listas->prioridadAutomatica(),$date_past);
				
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
          $idCentro = '';
          $respuestaMostrar = '';
          $idColaborador = '';
          $lista = '';
          $idRespuestaMostrar = '';
          foreach ($rspta2 as $respuestaItem) {
            if ($respuestaItem['ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO'] === $key['ID_DETALLE_GRUPO_LISTA_CHEQUEO']) {
            
                $fecha = $respuestaItem['FEC_REGISTRO_LISTA_CHEQUEO'];
                $centro = $respuestaItem['NOM_CENTRO_OP'];
                $idCentro = $respuestaItem['ID_CENTRO_OP_LISTA_CHEQUEO']; //para poder cambiarlo al editar
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
            'idCentro'=> $idCentro,
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
      //Solo los centros asignados al usuario de la sesion, no todos los del sistema
      $rspta = $Listas->selectCentroUsuario($search_term, $_SESSION['IdUsuarios']);
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
          '<SPAN title="Mostrar respuestas"><button class="btn btn-success" onclick="listarRespuestas(' . $row->ID_GRUPO_LISTA_CHEQUEO . ')"><i class="fa fa-eye"></i></button></SPAN>'
          
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
