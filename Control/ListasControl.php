<?php
/*
  HANA - Listas de chequeo diligenciadas (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/ListasAjax.js y responde con lo que
  trae el modelo (Modelo/ListasModelo.php).
  Cada petición llega con ?op=... y cae en uno de los "case" de abajo:
    días, validaListaIniciada, guardar, editarUnaRespuesta, validarRespuesta, mostrarRespuestas,
    mostrarPreguntas, mostrar, los select, listar, contar y los listados de respuestas.
    ("anular" quedó bloqueado: ver más abajo.)
  Casillas (Permisos de usuario): 11M para diligenciar, 21M para ver las diligenciadas y 12M
  para editar las listas y sus preguntas. Siempre en los peajes asignados a la persona.
  Las listas no crean novedades: si algo sale mal en una lista, la novedad se
  registra aparte en Novedades.
*/
session_start();
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
//Diligenciar listas: 11M; administrarlas: 12M. Solo consultar: 11M, 12M o 21M
hanaGuardia(array('11M', '24M'),
            array('dias', 'validaListaIniciada', 'validarRespuesta', 'mostrarRespuestas', 'mostrarPreguntas',
                  'mostrarPreguntasIniciadas', 'mostrar', 'selectColaborador', 'selectCentro', 'listar',
                  'contar', 'listarRespuestas', 'listarPorCentro'),
            array(), array('11M', '12M', '21M', '24M'));
require_once __DIR__ . '/AccesoHelper.php'; //los peajes asignados de la persona
require_once "../Modelo/ListasModelo.php";
require_once __DIR__ . "/../Modelo/HanaDB.php";     //consultas preparadas
require_once __DIR__ . "/../Modelo/HanaFechas.php"; //solo hoy, o un día que el administrador habilitó
$modeloListas = new listas();
//El ADMIN TEC (24M) puede diligenciar listas en cualquier centro
function listasVeTodo() { return in_array('24M', explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : ''), true); }
//Los peajes cuyas listas puede ver la persona: los asignados (el ADMIN TEC, todos)
function listasMisCentros() { return array_map('intval', array_keys(hanaMisCentrosIds())); }
//¿Esa lista diligenciada es de uno de sus peajes? Si no, se corta
function listasRevisarLista($idLista)
{
  $fila = HanaDB::fila("SELECT ID_CENTRO_OP FROM lista_chequeo WHERE ID_LISTA_CHEQUEO = ?", 'i', array((int)$idLista));
  if (!$fila || !in_array((int)$fila['ID_CENTRO_OP'], listasMisCentros(), true)) {
    http_response_code(403);
    echo json_encode(array('error' => 'Esa lista no es de tus peajes.'));
    exit;
  }
}
//Fechas y nombres de meses en español, con la hora de Colombia
setlocale(LC_ALL,'es-Es');
date_default_timezone_set("America/Bogota");
//Datos que llegan de la pantalla (no todas las operaciones los usan)
$idLista = isset($_POST["idLista"]) ? limpiarCadena($_POST["idLista"]) : "";
$idRespuesta = isset($_POST["idRespuesta"]) ? limpiarCadena($_POST["idRespuesta"]) : "";
$idGrupo = isset($_POST["idGrupo"]) ? limpiarCadena($_POST["idGrupo"]) : "";
$fechaEncuesta = isset($_POST["fechaEncuesta"]) ? $_POST["fechaEncuesta"] : '';
//Quien diligencia sale SIEMPRE de la sesión, nunca de un campo de la pantalla
//(que se puede cambiar): así una lista no puede quedar a nombre de otro
$idQuienDiligencia = isset($_SESSION['Idcolaborador']) ? $_SESSION['Idcolaborador'] : '';

$idCentro = isset($_POST["selectCentro"]) ? $_POST["selectCentro"] : '';

$huboFallo = false; //queda en true si alguna respuesta no se pudo guardar

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
      $resultado = $modeloListas->validaListaIniciada($idGrupo,$fechaEncuesta,$idCentro);
      echo json_encode($resultado);
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
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
      //El peaje tiene que ser de los suyos (asignado en Colaboradores)
      if (!$modeloListas->centroAsignado($_SESSION['Idcolaborador'], intval($idCentro), listasVeTodo())) {
        http_response_code(400);
        echo 'Ese peaje no está entre tus peajes asignados.';
        break;
      }

      $idPregunta = '';
      $tipos = array();
      $preguntasDelGrupo = $modeloListas->mostrarPreguntas($idGrupo);
      if ($preguntasDelGrupo) { while ($pregunta = $preguntasDelGrupo->fetch_object()) { $tipos[$pregunta->ID_DETALLE_GRUPO_LISTA_CHEQUEO] = $pregunta->TIPO_RESPUESTA; } }
      $fechaBase = date('Y-m-d');
      foreach ($_POST as $campo => $valor) { if (preg_match('/answer_(\d+)/', $campo, $partes) && isset($tipos[$partes[1]]) && $tipos[$partes[1]] === 'fecha' && $valor !== '') { $fechaBase = $valor; break; } }
      $resultado = $modeloListas->insertarLista($idCentro, $fechaEncuesta, $idQuienDiligencia, $idGrupo);
      if ($resultado) {
       $idLista = $resultado; //número de la lista recién creada
        foreach ($_FILES as $campoArchivo => $archivoSubido) {
          if (preg_match('/answer_(\d+)/', $campoArchivo, $partesCampoArchivo) && isset($archivoSubido['tmp_name']) && $archivoSubido['error'] === UPLOAD_ERR_OK) {
            $idPreguntaArchivo = $partesCampoArchivo[1];
            $tipoArchivo = @mime_content_type($archivoSubido['tmp_name']);
            if ($tipoArchivo && strpos($tipoArchivo, 'image/') === 0) {
              $imagenBase64 = 'data:' . $tipoArchivo . ';base64,' . base64_encode(file_get_contents($archivoSubido['tmp_name']));
              $modeloListas->insertarRespuesta($idLista, $idPreguntaArchivo, $imagenBase64, $idQuienDiligencia);
            }
          }
        }
        foreach ($_POST as $nombreCampo => $valorEnviado) {
          if (preg_match('/answer_(\d+)/', $nombreCampo, $partesCampo)) {
            $idPregunta = $partesCampo[1];
            $respuesta = $valorEnviado;
			  
            if (isset($tipos[$idPregunta]) && $tipos[$idPregunta] === 'datetime' && $respuesta !== '') { $respuesta = date("Y-m-d h:i:s A", strtotime($fechaBase . ' ' . $respuesta)); }
            $respuestaGuardada = $modeloListas->insertarRespuesta($idLista, $idPregunta, $respuesta, $idQuienDiligencia);
            if (!$respuestaGuardada) {
              $huboFallo = true; //una respuesta que no se guardó hace fallar toda la lista
            }
          }
        }

        //Lista terminada: se avisa por correo a quien esté configurado.
        //Va dentro de try/catch en su propio archivo: si el correo falla,
        //la lista igual queda guardada y el usuario no ve ningún error.
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
        //Ni siquiera se creó el encabezado de la lista
        http_response_code(400);
        echo "No se pudo crear la lista.";
      }
      }
      //Con número de lista no se hace nada aquí: las correcciones van por editarUnaRespuesta
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
        $fechaDeLista = $modeloListas->fechaYDueno((int)$idLista, 0);
      } elseif ($idRespuesta !== '') {
        $fechaDeLista = $modeloListas->fechaYDueno(0, (int)$idRespuesta);
      }
      if ($fechaDeLista && (int)$fechaDeLista['DUENO'] !== (int)$_SESSION['Idcolaborador'] && !listasVeTodo()) {
        http_response_code(403);
        echo 'Esta lista solo la puede corregir quien la llenó (o el ADMIN TEC).';
        break;
      }
      if (!$fechaDeLista || !HanaFechas::enVentana($fechaDeLista['FECHA'], (isset($_SESSION['Idcolaborador']) ? (int)$_SESSION['Idcolaborador'] : 0))) {
        http_response_code(400);
        echo 'Esta lista ya no se puede corregir: solo se modifican listas de ' . HanaFechas::textoVentana() . '.';
        break;
      }
      if (!empty($idRespuesta)) {
        $idQuienDiligencia = $_SESSION['Idcolaborador'];

        //Si al corregir la lista se cambió el centro operativo, se guarda primero
        $nuevoCentro = isset($_POST['idCentro']) ? intval($_POST['idCentro']) : 0;
        if ($nuevoCentro > 0 && $idLista !== '') {
          $actual = $modeloListas->getCentro2(intval($idLista));
          if ($actual && intval($actual['ID_CENTRO_OP']) !== $nuevoCentro) {
            if (!$modeloListas->centroAsignado($_SESSION['Idcolaborador'], $nuevoCentro, listasVeTodo())) {
              http_response_code(400);
              echo 'Ese centro operativo no está asignado a tu usuario.';
              break;
            }
            if ($modeloListas->existeOtraLista(intval($idLista), $nuevoCentro)) {
              http_response_code(400);
              echo 'Ya existe una lista de este grupo para ese centro operativo en la misma fecha.';
              break;
            }
            $modeloListas->cambiarCentro(intval($idLista), $nuevoCentro);
          }
        }
		   
      foreach ($_POST as $nombreCampo => $valorEnviado) {
        if (preg_match('/answer_(\d+)/', $nombreCampo, $partesCampo)) {
          $idPregunta = $partesCampo[1];
          $respuesta = $valorEnviado;
		
			
			
          //Al editar toda la lista de una vez, cada respuesta llega con su propio
          //identificador en resp_<idPregunta>. Si no viene, se usa el único idRespuesta
          //que manda la ventana de "editar una sola respuesta"
          $idRespuestaActual = isset($_POST['resp_'.$idPregunta]) ? $_POST['resp_'.$idPregunta] : $idRespuesta;

          $resultado = $modeloListas->editarUnaRespuesta($respuesta, $idQuienDiligencia, $idRespuestaActual);
          if (!$resultado) { $huboFallo = true; } //se anota y se decide al final
        }
      }
      //Una sola respuesta al navegador, ya con todas las preguntas procesadas
      if ($huboFallo) {
        http_response_code(400);
        echo "Algunas respuestas no se pudieron guardar.";
      } else {
        http_response_code(200);
      }
      } else {
           $idQuienDiligencia = $_SESSION['Idcolaborador'];
      foreach ($_POST as $nombreCampo => $valorEnviado) {
        if (preg_match('/answer_(\d+)/', $nombreCampo, $partesCampo)) {
          $idPregunta = $partesCampo[1];
          $respuesta = $valorEnviado;
			
		
			
				
          $resultado = $modeloListas->insertarUnaRespuesta($idLista, $idPregunta, $respuesta, $idQuienDiligencia);
          if ($resultado) {
            echo http_response_code(200);
          }
        }
      }
      }
      
      
      break;
  //¿La persona ya respondió esta lista en esa fecha? Devuelve las fechas encontradas
  case 'validarRespuesta':
    try {
      $filas = [];
      $resultado = $modeloListas->validarRespuesta($fechaEncuesta, $idLista, $idQuienDiligencia);
      if ($resultado) {
        foreach ($resultado as $filaEncontrada) {
          $filas[] = $filaEncontrada;
        }
        echo json_encode($filas);
      }
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }
    break;

  //Arma la lista con sus preguntas y lo que se respondió en cada una, para verla o corregirla
  case 'mostrarRespuestas':
    listasRevisarLista($idLista);
    try {
      $lista = '';
      $respuestaMostrar = '';
      $idColaborador = '';
      $idRespuestaMostrar = '';
      $fecha ='';
      $centro ='';
      $filas = [];
      $resultado = $modeloListas->mostrarPreguntasRespuestas($idLista);
      if ($resultado) {
        $respuestasDeLaLista = $modeloListas->mostrarRespuestas($idLista);
        foreach ($resultado as $pregunta) {
          $fecha = '';
          $centro = '';
          $idCentro = '';
          $respuestaMostrar = '';
          $idColaborador = '';
          $lista = '';
          $idRespuestaMostrar = '';
          foreach ($respuestasDeLaLista as $respuestaItem) {
            if ($respuestaItem['ID_PREGUNTA'] === $pregunta['ID_DETALLE_GRUPO_LISTA_CHEQUEO']) {
            
                $fecha = $respuestaItem['FEC_REGISTRO'];
                $centro = $respuestaItem['NOM_CENTRO_OP'];
                $idCentro = $respuestaItem['ID_CENTRO_OP']; //para poder cambiarlo al editar
                $respuestaMostrar = $respuestaItem['RESPUESTA'];
                $idColaborador = $respuestaItem['ID_COLABORADOR'];
                $lista = $respuestaItem['ID_LISTA_CHEQUEO'];
                $idRespuestaMostrar = $respuestaItem['ID_DETALLE_LISTA_CHEQUEO'];
                break;
            }
          }
          //La pregunta con lo que se respondió (vacío si aún no tiene respuesta)
          $filas[] = [
            'ID_GRUPO'=> $pregunta['ID_GRUPO_LISTA_CHEQUEO'],
            'NOM_GRUPO'=> $pregunta['lista'],
            'ID_LISTA'=> $lista,
            'FECHA'=> $fecha,
            'centro'=> $centro,
            'idCentro'=> $idCentro,
            'ID_PREGUNTA' => $pregunta['ID_DETALLE_GRUPO_LISTA_CHEQUEO'],
            'PREGUNTA' => $pregunta['PREGUNTA'],
            'TIPO_RESPUESTA'=> $pregunta['TIPO_RESPUESTA'],
            'ID_RESPUESTA' => $idRespuestaMostrar,
            'RESPUESTA' => $respuestaMostrar,
            'ID_COLABORADOR' => $idColaborador
          ];
        }        http_response_code(200);
          echo json_encode($filas);
      } else {
        http_response_code(400);
    }
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }
    break;
  //Preguntas activas de un tipo de lista, para llenarla
  case 'mostrarPreguntas':
    try {
      $filas = [];
      $filasPreguntas = $modeloListas->mostrarPreguntas($idGrupo);
      $filasPreguntas ? http_response_code(200) : http_response_code(400);
      if ($filasPreguntas) {
        foreach ($filasPreguntas as $pregunta) {
          $filas[] = $pregunta;
        }
        echo json_encode($filas);
      }
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }
    break;
      //Preguntas de una lista ya empezada ese día en ese peaje, con lo que ya se respondió
      case 'mostrarPreguntasIniciadas':
        try {
          $filas = [];
          $filasPreguntas = $modeloListas->mostrarPreguntasIniciadas($idGrupo, $idCentro, $fechaEncuesta);
          $filasPreguntas ? http_response_code(200) : http_response_code(400);
          if ($filasPreguntas) {
            foreach ($filasPreguntas as $pregunta) {
              $filas[] = $pregunta;
            }
            echo json_encode($filas);
          }
        } catch (\Throwable $error) {
          http_response_code(404);
          echo json_encode($error);
        }
        break;


  //Trae una lista diligenciada para cargarla en el formulario
  case 'mostrar':
    try {
      $resultado = $modeloListas->mostrar($idLista);
      $resultado ? http_response_code(200) : http_response_code(400);
      echo json_encode($resultado);
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }
    break;
  //"anular" quedó bloqueado: ninguna pantalla lo usa, y el modelo desactivaba la
  //PREGUNTA con ese número (no la lista), así que dañaba otro tipo de lista.
  //Se conserva el código original como referencia, pero ya no se ejecuta
  case 'anular':
    http_response_code(410);
    echo 'Anular listas diligenciadas no está disponible.';
    break;
    /* Código original:
    $resultado = $modeloListas->anular($idLista);
    $resultado ? http_response_code(200) : http_response_code(400);
    */

  //Colaboradores activos para el buscador (opciones de select2)
  case 'selectColaborador':
    $busqueda = isset($_GET['search']) ? $_GET['search'] : 0;
    try {
      $resultado = $modeloListas->selectColaborador($busqueda);
      $opciones = array();
      if ($resultado->num_rows > 0) {
        while ($fila = $resultado->fetch_assoc()) {
          $opcion['id'] = $fila['ID_COLABORADOR'];
          $opcion['text'] = $fila['NOM_COLABORADOR'];
          array_push($opciones, $opcion);
        }
      }
      echo json_encode($opciones);
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }
    break;

  //Peajes que puede elegir la persona al diligenciar una lista
  case 'selectCentro':
    $busqueda = isset($_GET['search']) ? $_GET['search'] : 0;
    try {
      //Solo los centros asignados al usuario de la sesión, no todos los del sistema
      $resultado = $modeloListas->selectCentroUsuario($busqueda, $_SESSION['Idcolaborador'], listasVeTodo());
      $opciones = array();
      if ($resultado->num_rows > 0) {
        while ($fila = $resultado->fetch_assoc()) {
          $opcion['id'] = $fila['ID_CENTRO_OP'];
          $opcion['text'] = $fila['NOM_CENTRO_OP'];
          array_push($opciones, $opcion);
        }
      }
      echo json_encode($opciones);
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }
    break;
  //Tipos de lista que se pueden diligenciar, con sus botones (tabla de la pantalla)
  case 'listar':
    try {
      $resultado = $modeloListas->listar();
      //El botón de responder solo lo ve quien llena listas (11M). El director de operaciones y
      //quien las administra o consulta (12M, 21M) solo ven las respuestas
      $llenaListas = in_array('11M', explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : ''), true);
      $filasTabla = array();
      while ($fila = $resultado->fetch_object()) {
        $filasTabla[] = array(
          "0" => $fila->ID_GRUPO_LISTA_CHEQUEO,
          "1" => $fila->NOM_GRUPO_LISTA_CHEQUEO,
          "2" => $fila->CANT_PREGUNTAS_GRUPO,
          "3" => ($llenaListas ? '<SPAN title="Responder"><button class="btn btn-success" onclick="mostrarPreguntas(' . $fila->ID_GRUPO_LISTA_CHEQUEO . ')"><i class="fa fa-pencil"></i></button></SPAN>' : '').
          '<SPAN title="Mostrar respuestas"><button class="btn btn-success" onclick="listarRespuestas(' . $fila->ID_GRUPO_LISTA_CHEQUEO . ')"><i class="fa fa-eye"></i></button></SPAN>'
          
        );
      }
      $respuestaTabla = array(
        "sEcho" => 1,
        "iTotalRecords" => count($filasTabla),
        "iTotalDisplayRecords" => count($filasTabla),
        "aaData" => $filasTabla
      );
      echo json_encode($respuestaTabla);
      
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }
    break;
    //Avance de una lista (0, 10, ... 100)
    case "contar":
      try{
      //Avance de la lista: cuántas preguntas tiene y cuántas se han respondido
      $resultado= $modeloListas->contarRespuestas($idGrupo, $idLista);
      while ($conteo = mysqli_fetch_assoc($resultado)) {
        echo hanaAvanceLista($conteo["npreguntas"], $conteo["nrespuestas"]);
      }
      
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }
      break;
  //Listas diligenciadas de un tipo, con su avance en dibujo
  case 'listarRespuestas':
    try {
      $avance=0;
      $resultado = $modeloListas->listarRespPorCentro($idGrupo, listasMisCentros()); //solo las de sus peajes
      $filasTabla = array();
      while ($fila = $resultado->fetch_object()) {
        //Las cuentas del avance ya vienen en la misma consulta
        $avance = hanaAvanceLista($fila->npreguntas, $fila->nrespuestas);
        $rutaSVG = '../public/img/'.$avance.'.svg';
        $contenidoSVG = file_get_contents($rutaSVG);
        $filasTabla[] = array(
          "0" => $fila->ID_LISTA_CHEQUEO,
          "1" => $fila->NOM_GRUPO_LISTA_CHEQUEO,
          "2" => $fila->FEC_REGISTRO,
          "3" => $fila->NOM_CENTRO_OP,
          "4" => $fila->NOM_COLABORADOR,
          "5"=>'<SPAN title="Editar"><button class="btn btn-success" onclick="mostrarRespuestas(' . $fila->ID_LISTA_CHEQUEO . ')"><i class="fa fa-eye"></i></button></SPAN>',
          "6" => '<img src="data:image/svg+xml;base64,' . base64_encode($contenidoSVG) . '" alt="Imagen SVG">' //el dibujo va incrustado para que la tabla no pida un archivo por fila
        );
      }
      $respuestaTabla = array(
        "sEcho" => 1,
        "iTotalRecords" => count($filasTabla),
        "iTotalDisplayRecords" => count($filasTabla),
        "aaData" => $filasTabla
      );
      echo json_encode($respuestaTabla);
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }
    break;
         
        
    //Listas diligenciadas de un tipo, de los peajes asignados a la persona (el ADMIN TEC, todos)
    //Es la que pide la tabla de la pantalla al cargar
    case 'listarPorCentro':
       $centrosOperativos = listasMisCentros();
       
        $avance=0;
        $resultado = $modeloListas->listarRespPorCentro($idGrupo,$centrosOperativos);
        $filasTabla = array();
        while ($fila = $resultado->fetch_object()) {
          $fechaEncuesta = substr($fila->FEC_REGISTRO, 0,10);
          //Las cuentas del avance ya vienen en la misma consulta
          $avance = hanaAvanceLista($fila->npreguntas, $fila->nrespuestas);
          $rutaSVG = '../public/img/'.$avance.'.svg';
          $contenidoSVG = file_get_contents($rutaSVG);
          $filasTabla[] = array(
            "0" => $fila->ID_LISTA_CHEQUEO,
            "1" => $fila->NOM_GRUPO_LISTA_CHEQUEO,
            "2" => $fila->FEC_REGISTRO,
            "3" => $fila->NOM_CENTRO_OP,
            "4" => $fila->NOM_COLABORADOR,
            "5"=>'<SPAN title="Editar"><button class="btn btn-success" onclick="mostrarRespuestas(' . $fila->ID_LISTA_CHEQUEO .','. $fechaEncuesta.')"><i class="fa fa-eye"></i></button></SPAN>',
            "6" =>'<img src="data:image/svg+xml;base64,' . base64_encode($contenidoSVG) . '" alt="Imagen SVG"  width="60" height="60">' 
          );
        }
        $respuestaTabla = array(
          "sEcho" => 1,
          "iTotalRecords" => count($filasTabla),
          "iTotalDisplayRecords" => count($filasTabla),
          "aaData" => $filasTabla
        );
        echo json_encode($respuestaTabla);
     
    break;
}
