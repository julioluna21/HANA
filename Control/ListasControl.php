<?php
/*
  HANA - Listas de chequeo diligenciadas (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/ListasAjax.js y responde con lo que
  trae el modelo (Modelo/ListasModelo.php).
  Cada petición llega con ?op=... y cae en uno de los "case" de abajo:
    dias, validaListaIniciada, guardar, editarUnaRespuesta, validarRespuesta, mostrarRespuestas,
    mostrarPreguntas, mostrar, anular, los select, listar, contar y los listados de respuestas.
  Permiso: 11M para diligenciar y 12M para administrar.
  Cuando se guarda una respuesta negativa en una pregunta con título de novedad,
  aquí mismo se crea la novedad.
*/
session_start();
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
hanaGuardia(array('11M', '12M')); //Diligenciar listas: 11M; administrarlas: 12M
require_once "../Modelo/ListasModelo.php";
require_once __DIR__ . "/../Modelo/HanaDB.php";     //consultas preparadas
require_once __DIR__ . "/../Modelo/HanaFechas.php"; //solo hoy, o un día que el administrador habilitó
$Listas = new listas();
//El ADMIN TEC (24M) puede diligenciar listas en cualquier centro
function listasVeTodo() { return in_array('24M', explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : ''), true); }
// Obtiene los datos del formulario de datos.
setlocale(LC_ALL,'es-Es');// Activa la localización con el sistema para mostrar en español
date_default_timezone_set("America/Lima");
$idLista = isset($_POST["idLista"]) ? limpiarCadena($_POST["idLista"]) : "";
$idRespuesta = isset($_POST["idRespuesta"]) ? limpiarCadena($_POST["idRespuesta"]) : "";
$idGrupo = isset($_POST["idGrupo"]) ? limpiarCadena($_POST["idGrupo"]) : "";
$fechaEncuesta = isset($_POST["fechaEncuesta"]) ? $_POST["fechaEncuesta"] : '';
//Quien diligencia sale SIEMPRE de la sesión, nunca de un campo de la pantalla
//(que se puede cambiar): así una lista no puede quedar a nombre de otro
$nombreColaborador = isset($_SESSION['Idcolaborador']) ? $_SESSION['Idcolaborador'] : '';

$idCentro = isset($_POST["selectCentro"]) ? $_POST["selectCentro"] : '';

$huboFallo = false; //queda en true si alguna respuesta no se pudo guardar
$preguntas= array();
$titulos= array();
$descPregunta= array();
$fechaNovedad=date("Y-m-d H:i:s");
$fecha2=date("Y-m-j H:i:s");

//Avance de una lista en decenas (0, 10, ... 100): es el nombre del dibujo public/img/<avance>.svg.
//Un grupo sin preguntas activas da 0 y nunca pasa de 100
function hanaAvanceLista($preguntas, $respuestas)
{
  $preguntas = (int)$preguntas;
  if ($preguntas <= 0) { return 0; }
  return (int)min(100, floor(100 * ((int)$respuestas / $preguntas) / 10) * 10);
}

switch ($_GET["op"]) {
  //Los días en que esta persona puede diligenciar una lista: hoy y los que le habilitó el administrador
  case "dias":
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('hoy' => HanaFechas::hoy(), 'habilitados' => HanaFechas::habilitados((isset($_SESSION['Idcolaborador']) ? (int)$_SESSION['Idcolaborador'] : 0))));
    break;
  //¿Ya hay una lista de este tipo empezada para ese peaje en esa fecha?
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
      //Llena listas quien tenga la casilla 11M en sus permisos (12M solo las edita y las consulta)
      if (!in_array('11M', explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : ''), true)) {
        http_response_code(403);
        echo 'Llenar listas de chequeo necesita la casilla 11M en tus permisos de usuario.';
        break;
      }
      
      if (empty($idLista)) {
      //La lista es de hoy, o de un día que el administrador le habilitó (lo elige la
      //pantalla); cualquier otra fecha se rechaza aquí, no solo en la pantalla
      if (HanaVal::fecha(substr((string)$fechaEncuesta, 0, 10)) === '' || !HanaFechas::enVentana($fechaEncuesta, (isset($_SESSION['Idcolaborador']) ? (int)$_SESSION['Idcolaborador'] : 0))) {
        http_response_code(400);
        echo 'La fecha de la lista no es válida: solo se diligencian listas de ' . HanaFechas::textoVentana() . '.';
        break;
      }
      //El peaje tiene que ser de los suyos (de un proyecto que coordina o asignado en Usuarios)
      if (!$Listas->centroAsignado($_SESSION['IdUsuarios'], intval($idCentro), listasVeTodo())) {
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
              $Listas->insertarRespuesta($idLista, $idPreguntaF, $firmaB64, $nombreColaborador);
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
            if (!$rspta1) {
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
        //Si falla cualquiera de ellas, la pantalla recibe el error
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
    //Corrige una sola respuesta de una lista ya diligenciada
    case 'editarUnaRespuesta':
      if (!in_array('11M', explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : ''), true)) {
        http_response_code(403);
        echo 'Corregir listas de chequeo necesita la casilla 11M en tus permisos de usuario.';
        break;
      }
      //Solo se corrigen listas de hoy, o de un día que el administrador habilitó.
      //Las corrige quien las llenó (DUENO) o el ADMIN TEC
      $fechaDeLista = null;
      if ($idLista !== '') {
        $fechaDeLista = $Listas->fechaYDueno((int)$idLista, 0);
      } elseif ($idRespuesta !== '') {
        $fechaDeLista = $Listas->fechaYDueno(0, (int)$idRespuesta);
      }
      if ($fechaDeLista && (int)$fechaDeLista['DUENO'] !== (int)$_SESSION['Idcolaborador'] && !listasVeTodo()) {
        http_response_code(403);
        echo 'Esta lista solo la puede corregir quien la llenó (o el ADMIN TEC).';
        break;
      }
      if (!$fechaDeLista || !HanaFechas::enVentana($fechaDeLista['F'], (isset($_SESSION['Idcolaborador']) ? (int)$_SESSION['Idcolaborador'] : 0))) {
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
            if (!$Listas->centroAsignado($_SESSION['IdUsuarios'], $nuevoCentro, listasVeTodo())) {
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
            echo http_response_code(200);
          }
        }
      }
      //
      //echo $rspta ? http_response_code(200) : http_response_code(400);
      }
      
      
      break;
  //¿La persona ya respondió esta lista en esa fecha? Devuelve las fechas encontradas
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

  //Arma la lista con sus preguntas y lo que se respondió en cada una, para verla o corregirla
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
            if ($respuestaItem['ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALLE_LISTA_CHEQUEO'] === $key['ID_DETALLE_GRUPO_LISTA_CHEQUEO']) {
            
                $fecha = $respuestaItem['FEC_REGISTRO_LISTA_CHEQUEO'];
                $centro = $respuestaItem['NOM_CENTRO_OP'];
                $idCentro = $respuestaItem['ID_CENTRO_OP_LISTA_CHEQUEO']; //para poder cambiarlo al editar
                $respuestaMostrar = $respuestaItem['RESPUESTA_DETALLE_LISTA_CHEQUEO'];
                $idColaborador = $respuestaItem['ID_COLABORADOR_DETALLE_LISTA_CHEQUEO'];
                $lista = $respuestaItem['ID_LISTA_CHEQUEO'];
                $idRespuestaMostrar = $respuestaItem['ID_DETALLE_LISTA_CHEQUEO'];
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


  //Trae una lista diligenciada para cargarla en el formulario
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
  //Anula una lista diligenciada
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

  //Peajes que puede elegir la persona al diligenciar una lista
  case 'selectCentro':
    $search_term = isset($_GET['search']) ? $_GET['search'] : 0;
    try {
      //Solo los centros asignados al usuario de la sesion, no todos los del sistema
      $rspta = $Listas->selectCentroUsuario($search_term, $_SESSION['IdUsuarios'], listasVeTodo());
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
      //Avance de la lista: cuántas preguntas tiene y cuántas se han respondido
      $rspta= $Listas->contarRespuestas($idGrupo, $idLista);
      while ($row1 = mysqli_fetch_assoc($rspta)) {
        echo hanaAvanceLista($row1["npreguntas"], $row1["nrespuestas"]);
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
        //Las cuentas del avance ya vienen en la misma consulta
        $avance = hanaAvanceLista($row->npreguntas, $row->nrespuestas);
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
         
        
    case 'listarPorCentro'://lo pide la tabla de la pantalla al cargar  
                          
       $rspta=$Listas->centrosOP($_SESSION['IdUsuarios']);//trae los registros para la tabla
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
          $fechaEncuesta = substr($row->FEC_REGISTRO_LISTA_CHEQUEO, 0,10);
          //Las cuentas del avance ya vienen en la misma consulta
          $avance = hanaAvanceLista($row->npreguntas, $row->nrespuestas);
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
