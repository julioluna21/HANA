<?php
/*
  HANA - Preguntas de las listas de chequeo (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/GruposListasnAjax.js y Ajax/Editarpreguntasajax.js y responde con lo que
  trae el modelo (Modelo/PreguntasListasModelo.php).
  Cada petición llega con ?op=... y cae en uno de los "case" de abajo:
    guardar, mostrar, importar (desde Excel o CSV), mostrarPreguntas, anular, select,
    reordenar, posiciones, guardarLote y listar.
  Permiso: 12M para cambiarlas; con 11M solo se consultan.
*/
session_start();
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
hanaGuardia(array('12M'), array('select', 'mostrar', 'mostrarPreguntas', 'listar'), array(), array('11M', '12M')); //Cambiar preguntas: 12M; verlas: 11M o 12M
require_once "../Modelo/PreguntasListasModelo.php";
$modeloPreguntas = new preguntas();

//Deja un texto comparable: minúsculas, sin tildes y sin espacios de sobra.
//Se usa para reconocer el tipo de respuesta y el encabezado del CSV sin que
//importe si el usuario escribió "Sí/No", "SI/NO" o "si no"
function hanaNormalizaTexto($texto) {
    $normalizado = trim($texto);
    $normalizado = function_exists('mb_strtolower') ? mb_strtolower($normalizado, 'UTF-8') : strtolower($normalizado);
    $normalizado = strtr($normalizado, array('á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u'));
    return preg_replace('/\s+/', ' ', $normalizado);
}
//Datos que llegan de la pantalla (no todas las operaciones los usan).
//idGrupo es el tipo de lista al que pertenecen las preguntas
$idGrupo=isset($_POST['idGrupo'])? limpiarCadena($_POST['idGrupo']):'';
$idPregunta=isset($_POST['idPregunta'])? limpiarCadena($_POST['idPregunta']):'';
$pregunta = isset($_POST["pregunta"])? limpiarCadena($_POST['pregunta']):'';
$tiposRespuesta = isset($_POST["tipo-respuesta"])? limpiarCadena($_POST["tipo-respuesta"]):'';
$idGrupoPreguntas=isset($_POST['idGrupoPreguntas'])? limpiarCadena($_POST['idGrupoPreguntas']):'';
$posicion=isset($_POST['posicion'])? limpiarCadena($_POST['posicion']):'final';

switch ($_GET["op"]) {
  //Crea una pregunta (al final o debajo de la que se elija) o edita una que existe
  case 'guardar':
    if (empty($idPregunta)) {
        if ($posicion === '' || $posicion === 'final') {
          $ordenMayor = $modeloPreguntas->ordenMax($idGrupo);
          $orden = ($ordenMayor ? $ordenMayor['ORDEN_MAYOR'] : 0) + 1;
        } else {
          $filaRef = $modeloPreguntas->ordenDe($posicion);
          $orden = ($filaRef ? $filaRef['ORDEN'] : 0) + 1;
          $modeloPreguntas->correrOrden($idGrupo, $orden);
        }
        $idNueva = $modeloPreguntas->insertar($idGrupo, $pregunta, $tiposRespuesta, $orden);
        if ($idNueva == '') {
          //Si no se pudo guardar, se responde con error para que la pantalla no lo tome como éxito
          http_response_code(400);
          echo "No se pudo guardar la pregunta.";
        }
    } else {
      $resultado = $modeloPreguntas->editar($idPregunta, $pregunta, $tiposRespuesta);
      if (!$resultado) { http_response_code(400); echo "No se pudo actualizar la pregunta."; }
    }
    break;
  
  //Trae una pregunta para cargarla en el formulario
  case 'mostrar':
    try {
      $resultado=$modeloPreguntas->mostrar($idPregunta);
      $resultado ? http_response_code(200) : http_response_code(400);
      echo json_encode($resultado);
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }
    break;
    
  case 'importar':
    //Carga masiva de preguntas desde un archivo Excel (.xlsx) o CSV.
    //Regla principal: se revisa TODO el archivo primero y solo se guarda si no
    //hay ningún error. Así nunca queda una lista a medio cargar que después
    //haya que limpiar a mano.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

      if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo 'No se recibió ningún archivo. Selecciona el archivo e intenta de nuevo.';
        break;
      }
      if ($idGrupo == '') {
        http_response_code(400);
        echo 'No se supo a qué lista agregar las preguntas. Cierra la ventana y vuelve a abrirla.';
        break;
      }

      $nombreArchivo = $_FILES['file']['name'];
      $extension = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
      $filasCrudas = array(); //cada fila: array(pregunta, tipo)

      if ($extension === 'xlsx' || $extension === 'xlsm') {

        //--- Archivo de Excel ---
        require_once __DIR__ . '/LectorXlsx.php';
        //Se lee la hoja PREGUNTAS. Si el usuario borró la hoja de instrucciones
        //y quedó una sola, el lector toma la primera que encuentre
        $resultado = hanaLeerXlsx($_FILES['file']['tmp_name'], 'PREGUNTAS');
        if (isset($resultado['error'])) {
          http_response_code(400);
          echo $resultado['error'];
          break;
        }
        foreach ($resultado['filas'] as $fila) {
          $filasCrudas[] = array(
            isset($fila[0]) ? trim($fila[0]) : '',
            isset($fila[1]) ? trim($fila[1]) : ''
          );
        }

      } elseif ($extension === 'csv' || $extension === 'txt') {

        //--- Archivo CSV ---
        $contenido = file_get_contents($_FILES['file']['tmp_name']);
        if ($contenido === false || trim($contenido) === '') {
          http_response_code(400);
          echo 'El archivo está vacío.';
          break;
        }

        //Excel guarda los CSV con una marca invisible al inicio (BOM). Si no se
        //quita, la primera celda llega con caracteres raros adelante
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        //Si el archivo viene en la codificación de Windows se pasa a UTF-8:
        //de lo contrario las tildes y la ñ quedan dañadas en la base
        if (function_exists('mb_check_encoding') && !mb_check_encoding($contenido, 'UTF-8')) {
          $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $lineas = preg_split('/\r\n|\r|\n/', $contenido);

        //El separador puede ser punto y coma o coma, según como guarde cada Excel.
        //Se elige el que más aparezca en la primera línea con contenido
        $separador = ';';
        foreach ($lineas as $lineaArchivo) {
          if (trim($lineaArchivo) !== '') {
            $separador = substr_count($lineaArchivo, ';') >= substr_count($lineaArchivo, ',') ? ';' : ',';
            break;
          }
        }

        foreach ($lineas as $linea) {
          if (trim($linea) === '') { $filasCrudas[] = array('', ''); continue; }
          $celdas = str_getcsv($linea, $separador);
          $filasCrudas[] = array(
            isset($celdas[0]) ? trim($celdas[0]) : '',
            isset($celdas[1]) ? trim($celdas[1]) : ''
          );
        }

      } else {
        http_response_code(400);
        echo 'El archivo debe ser de Excel (.xlsx) o CSV. Descarga la plantilla y trabaja sobre ella.';
        break;
      }

      //--- De aquí en adelante da igual si vino de Excel o de CSV ---

      //Tipos de respuesta válidos. La clave es como se guarda en la base y
      //los valores son las formas en que el usuario puede haberlo escrito
      $tiposValidos = array(
        'texto'    => array('texto', 'text', 'abierta'),
        'si/no'    => array('si/no', 'sino', 'si-no', 'si no', 'booleano'),
        'lista'    => array('lista', 'seleccion', 'opciones'),
        'fecha'    => array('fecha', 'date'),
        'datetime' => array('datetime', 'fecha y hora', 'hora', 'time'),
        'firma'    => array('firma', 'signature')
      );

      //Preguntas que la lista YA tiene, para no importar duplicados
      //(subir el mismo archivo dos veces no repite nada)
      $yaExisten = array();
      $preguntasAntes = $modeloPreguntas->listar($idGrupo);
      if ($preguntasAntes) {
        while ($preguntaAntes = $preguntasAntes->fetch_object()) {
          $yaExisten[hanaNormalizaTexto($preguntaAntes->PREGUNTA)] = true;
        }
      }

      $preguntasOk = array(); //filas listas para guardar
      $errores     = array(); //problemas encontrados, con el número de fila
      $numFila     = 0;
      $vistas      = array(); //para detectar preguntas repetidas dentro del archivo

      foreach ($filasCrudas as $fila) {
        $numFila++;
        $pregunta  = $fila[0];
        $tipoBruto = $fila[1];

        if ($pregunta === '' && $tipoBruto === '') { continue; } //fila vacía: se ignora

        //La primera fila es el encabezado de la plantilla: se salta
        if ($numFila === 1 && hanaNormalizaTexto($pregunta) === 'pregunta') { continue; }

        if ($pregunta === '' || $tipoBruto === '') {
          $errores[] = "Fila $numFila: falta la pregunta o el tipo de respuesta.";
          continue;
        }
        //La columna de la base es varchar(200): más largo no cabe
        if (strlen($pregunta) > 200) {
          $errores[] = "Fila $numFila: la pregunta es muy larga (máximo 200 caracteres).";
          continue;
        }

        //Se busca el tipo entre las formas aceptadas
        $tipo     = '';
        $tipoNorm = hanaNormalizaTexto($tipoBruto);
        foreach ($tiposValidos as $clave => $formas) {
          if (in_array($tipoNorm, $formas)) { $tipo = $clave; break; }
        }
        if ($tipo === '') {
          $errores[] = "Fila $numFila: \"$tipoBruto\" no es un tipo válido. Usa texto, si/no, lista, fecha, datetime o firma.";
          continue;
        }

        $clavePregunta = hanaNormalizaTexto($pregunta);
        if (isset($yaExisten[$clavePregunta])) {
          $errores[] = "Fila $numFila: esta pregunta ya está en la lista.";
          continue;
        }
        if (isset($vistas[$clavePregunta])) {
          $errores[] = "Fila $numFila: la pregunta está repetida (ya venía en la fila " . $vistas[$clavePregunta] . ").";
          continue;
        }
        $vistas[$clavePregunta] = $numFila;

        $preguntasOk[] = array('pregunta' => $pregunta, 'tipo' => $tipo);
      }

      //Si hay algo mal NO se guarda nada: se devuelve la lista de problemas
      if (count($errores) > 0) {
        http_response_code(400);
        $muestra = array_slice($errores, 0, 10);
        $mensaje = "No se importó ninguna pregunta. Corrige lo siguiente y vuelve a subir el archivo:\n\n"
                 . implode("\n", $muestra);
        if (count($errores) > 10) {
          $mensaje .= "\n\n...y " . (count($errores) - 10) . " problema(s) más.";
        }
        echo $mensaje;
        break;
      }

      if (count($preguntasOk) === 0) {
        http_response_code(400);
        echo 'El archivo no tiene ninguna pregunta. Revisa que estés escribiendo en la hoja PREGUNTAS, debajo del encabezado.';
        break;
      }

      //Ya validado todo, se guarda. El orden continúa después de las preguntas que ya existan
      $ordenMayor = $modeloPreguntas->ordenMax($idGrupo);
      $orden   = $ordenMayor ? intval($ordenMayor['ORDEN_MAYOR']) : 0;

      $guardadas = 0;
      $fallaron  = 0;
      foreach ($preguntasOk as $preguntaNueva) {
        $orden++;
        //insertar() espera la lista, el texto, el tipo y el orden
        $idNueva = $modeloPreguntas->insertar($idGrupo, limpiarCadena($preguntaNueva['pregunta']), $preguntaNueva['tipo'], $orden);
        if ($idNueva != '') { $guardadas++; } else { $fallaron++; }
      }

      if ($fallaron > 0) {
        http_response_code(400);
        echo "Se guardaron $guardadas pregunta(s), pero $fallaron no se pudieron guardar. Revisa la lista.";
      } else {
        echo "Se importaron $guardadas pregunta(s) correctamente.";
      }

    } else {
      http_response_code(400);
      echo 'Método no permitido para el envío del archivo.';
    }
    break;
  
    //Trae una pregunta como arreglo, para la pantalla de edición
    case 'mostrarPreguntas':
      try {
        $filasPregunta = [];
        $filas = $modeloPreguntas->mostrar($idPregunta);
        $filas ? http_response_code(200):http_response_code(400);
        foreach ($filas as $filaPregunta) {
            $filasPregunta[]=$filaPregunta;
        }
        echo json_encode($filasPregunta);
      } catch (\Throwable $error) {
        http_response_code(404);
        echo json_encode($error);
      }
      break;
  //Desactiva una pregunta: deja de salir al llenar la lista (las respuestas viejas se conservan)
  case 'anular':
    $anulada=$modeloPreguntas->anular($idPregunta);
    if ($anulada == '') {
      //Si la anulación falla, se responde con error para que la pantalla no lo tome como éxito
      http_response_code(400);
      echo "No se pudo anular la pregunta.";
    }
    break;
  //Opciones del buscador de preguntas (filtra por lo que se escriba)
  case 'select':
      $busqueda = isset($_GET['search'])?$_GET['search']:'0';
      try {
        $resultado=$modeloPreguntas->select($busqueda);
        $opciones = array();
        if ($resultado->num_rows >0) {
          while ($fila = $resultado->fetch_assoc()) {
            $opcion['id'] = $fila['ID_GRUPO_LISTA_CHEQUEO'];
            $opcion['pregunta'] = $fila['NOM_GRUPO_LISTA_CHEQUEO'];
            array_push($opciones, $opcion);
          }
        }
        echo json_encode($opciones);
      } catch (\Throwable $error) {
        http_response_code(404);
        echo json_encode($error);
      }
    break;

  //Guarda el nuevo orden de las preguntas después de arrastrarlas
  case 'reordenar':
    try {
      $ids = (isset($_POST['orden']) && is_array($_POST['orden'])) ? $_POST['orden'] : array();
      $numeroOrden = 1;
      foreach ($ids as $idPreguntaOrden) {
        $modeloPreguntas->setOrden((int)$idPreguntaOrden, $numeroOrden);
        $numeroOrden++;
      }
      echo http_response_code(200);
    } catch (\Throwable $error) {
      http_response_code(404); echo json_encode($error);
    }
    break;
  //Preguntas activas de una lista con su orden, para la edición masiva
  case 'posiciones':
    try {
      $resultado = $modeloPreguntas->listar($idGrupoPreguntas);
      $preguntasActivas = array();
      while ($fila = $resultado->fetch_object()) {
        if ($fila->ESTADO == 1) {
          //Se devuelve también el tipo: la pantalla de edición masiva
          //lo necesita para precargar cada fila
        $preguntasActivas[] = array(
            "id"      => $fila->ID_DETALLE_GRUPO_LISTA_CHEQUEO,
            "pregunta"=> $fila->PREGUNTA,
            "tipo"    => $fila->TIPO_RESPUESTA,
            "orden"   => $fila->ORDEN
          );
        }
      }
      echo json_encode($preguntasActivas);
    } catch (\Throwable $error) {
      http_response_code(404); echo json_encode($error);
    }
    break;
  //Usado por la pantalla "Editar todas las preguntas": guarda de un solo golpe
  //el nuevo orden y la edición (texto y tipo) de todas las preguntas
  case 'guardarLote':
    try {
      //Llega un JSON con las preguntas EN EL ORDEN FINAL que dejó el usuario
      $items = isset($_POST['items']) ? json_decode($_POST['items'], true) : array();
      if (!is_array($items)) { $items = array(); } //si el JSON viene mal, no se procesa nada
      $numeroOrden = 1; //posición: 1, 2, 3... según el orden de llegada
      foreach ($items as $item) {
        $idEditar = isset($item['id']) ? (int)$item['id'] : 0;
        if ($idEditar <= 0) { continue; }
        $textoPregunta   = limpiarCadena(isset($item['pregunta']) ? $item['pregunta'] : '');
        $tipo   = limpiarCadena(isset($item['tipo'])     ? $item['tipo']     : '');
        $modeloPreguntas->editar($idEditar, $textoPregunta, $tipo);
        $modeloPreguntas->setOrden($idEditar, $numeroOrden); //aquí queda fija la posición en la base
        $numeroOrden++;
      }
      echo http_response_code(200);
    } catch (\Throwable $error) {
      http_response_code(404); echo json_encode($error);
    }
    break;
  //Todas las preguntas de una lista para la tabla
  case 'listar':
    try {
      $resultado= $modeloPreguntas->listar($idGrupoPreguntas);
      $filasTabla = array();
        while ($fila = $resultado->fetch_object()) {
          $filasTabla[] = array(
            "0"=>$fila->ID_DETALLE_GRUPO_LISTA_CHEQUEO,
            "1"=>$fila->ORDEN,
            "2"=>$fila->ID_GRUPO_LISTA_CHEQUEO,
            "3"=>$fila->PREGUNTA,
            "4"=>$fila->TIPO_RESPUESTA,
            "5"=>$fila->ESTADO == 1 ? "Activo":"Inactivo",
            "6"=>'<SPAN title="Editar"><button class="btn btn-success" onclick="mostrarPregunta('.$fila->ID_DETALLE_GRUPO_LISTA_CHEQUEO.')"><i class="fa fa-eye"></i></button></SPAN>
            <SPAN title="anular"><button type="button" class="btn btn-success" onclick="anularPregunta('.$fila->ID_DETALLE_GRUPO_LISTA_CHEQUEO.','.$fila->ID_GRUPO_LISTA_CHEQUEO.')"><i class="fa fa-trash-o"></i></button></SPAN>'
          );
        }
        $respuestaTabla = array(
          "sEcho"=> 1,
          "iTotalRecords"=>count($filasTabla),
          "iTotalDisplayRecords"=>count($filasTabla),
          "aaData"=>$filasTabla);
          echo json_encode($respuestaTabla);
    } catch (\Throwable $error) {
      http_response_code(404);
      echo json_encode($error);
    }
    break;
}


?>