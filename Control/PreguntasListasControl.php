<?php
session_start();
require_once __DIR__ . '/Guardia.php'; //sesión y permisos (antes no se revisaban)
hanaGuardia(array('12M'), array('select', 'mostrar', 'mostrarPreguntas', 'listar'), array(), array('11M', '12M')); //Cambiar preguntas: 12M; verlas: 11M o 12M
require_once "../Modelo/PreguntasListasModelo.php";
$Preguntas = new preguntas();

//Deja un texto comparable: minusculas, sin tildes y sin espacios de sobra.
//Se usa para reconocer el tipo de respuesta y el encabezado del CSV sin que
//importe si el usuario escribio "Sí/No", "SI/NO" o "si no"
function hanaNormalizaTexto($texto) {
    $t = trim($texto);
    $t = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
    $t = strtr($t, array('á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u'));
    return preg_replace('/\s+/', ' ', $t);
}
// Obtiene los datos del formulario de datos.
$idLista=isset($_POST['idGrupo'])? limpiarCadena($_POST['idGrupo']):'';
$idPregunta=isset($_POST['idPregunta'])? limpiarCadena($_POST['idPregunta']):'';
$pregunta = isset($_POST["pregunta"])? limpiarCadena($_POST['pregunta']):'';
$tiposRespuesta = isset($_POST["tipo-respuesta"])? limpiarCadena($_POST["tipo-respuesta"]):'';
$idGrupoPreguntas=isset($_POST['idGrupoPreguntas'])? limpiarCadena($_POST['idGrupoPreguntas']):'';
$generaNovedad=isset($_POST['genera-novedad'])? limpiarCadena($_POST['genera-novedad']):'';
$titulo=isset($_POST['Titulo-novedad'])? limpiarCadena($_POST['Titulo-novedad']):'';
$posicion=isset($_POST['posicion'])? limpiarCadena($_POST['posicion']):'final';

//GENERA_NOVEDAD es una columna entera. Los tipos que no generan novedad (texto,
//fecha, firma) la enviaban vacía, y MySQL en modo estricto rechaza '' en una
//columna entera: la pregunta no se guardaba. Vacío = 0 (no genera)
$generaNovedad = ($generaNovedad === '1' || $generaNovedad === 1) ? 1 : 0;

switch ($_GET["op"]) {
  case 'guardar':

    if (empty($idPregunta)) {
      $rspta = $Preguntas->consCantPreguntas($idLista);
      if ($rspta != '') {
        $rspt = $rspta->fetch_assoc();
        $numPreguntas = $rspt['cantPreguntas'] + 1;
        if ($posicion === '' || $posicion === 'final') {
          $filaMax = $Preguntas->ordenMax($idLista);
          $orden = ($filaMax ? $filaMax['m'] : 0) + 1;
        } else {
          $filaRef = $Preguntas->ordenDe($posicion);
          $orden = ($filaRef ? $filaRef['ORDEN'] : 0) + 1;
          $Preguntas->correrOrden($idLista, $orden);
        }
        $rspta1 = $Preguntas->insertar($idLista, $pregunta, $tiposRespuesta,$generaNovedad,$titulo,$orden);
        if ($rspta1 != '') {
          $rspta = $Preguntas->insertarCantPreguntas($idLista, $numPreguntas);
          if (!$rspta) { http_response_code(400); echo "No se pudo actualizar el conteo de preguntas."; }
        } else {
          //Antes, si el insert fallaba, no se respondia nada y la pantalla
          //lo tomaba como exito
          http_response_code(400);
          echo "No se pudo guardar la pregunta.";
        }
      }else{
        http_response_code(400);
        echo "No se pudo consultar la lista de preguntas.";
      }
    } else {
      $rspta = $Preguntas->editar($idPregunta, $pregunta, $tiposRespuesta,$generaNovedad,$titulo);
      if (!$rspta) { http_response_code(400); echo "No se pudo actualizar la pregunta."; }
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
    //Carga masiva de preguntas desde un archivo Excel (.xlsx) o CSV.
    //Regla principal: se revisa TODO el archivo primero y solo se guarda si no
    //hay ningun error. Asi nunca queda una lista a medio cargar que despues
    //haya que limpiar a mano.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

      if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo 'No se recibió ningún archivo. Selecciona el archivo e intenta de nuevo.';
        break;
      }
      if ($idLista == '') {
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

        //Si el archivo viene en la codificacion de Windows se pasa a UTF-8:
        //de lo contrario las tildes y la ñ quedan dañadas en la base
        if (function_exists('mb_check_encoding') && !mb_check_encoding($contenido, 'UTF-8')) {
          $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $lineas = preg_split('/\r\n|\r|\n/', $contenido);

        //El separador puede ser punto y coma o coma, segun como guarde cada Excel.
        //Se elige el que mas aparezca en la primera linea con contenido
        $separador = ';';
        foreach ($lineas as $l) {
          if (trim($l) !== '') {
            $separador = substr_count($l, ';') >= substr_count($l, ',') ? ';' : ',';
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

      //--- De aqui en adelante da igual si vino de Excel o de CSV ---

      //Tipos de respuesta validos. La clave es como se guarda en la base y
      //los valores son las formas en que el usuario puede haberlo escrito
      $tiposValidos = array(
        'texto'    => array('texto', 'text', 'abierta'),
        'si/no'    => array('si/no', 'sino', 'si-no', 'si no', 'booleano'),
        'lista'    => array('lista', 'seleccion', 'opciones'),
        'fecha'    => array('fecha', 'date'),
        'datetime' => array('datetime', 'fecha y hora', 'hora', 'time'),
        'firma'    => array('firma', 'signature')
      );

      //Preguntas que la lista YA tiene, para no importar duplicados.
      //Antes se podia subir el mismo archivo dos veces y quedaba todo repetido
      $yaExisten = array();
      $rsptaPrev = $Preguntas->listar($idLista);
      if ($rsptaPrev) {
        while ($regPrev = $rsptaPrev->fetch_object()) {
          $yaExisten[hanaNormalizaTexto($regPrev->PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO)] = true;
        }
      }

      $preguntasOk = array(); //filas listas para guardar
      $errores     = array(); //problemas encontrados, con el numero de fila
      $numFila     = 0;
      $vistas      = array(); //para detectar preguntas repetidas dentro del archivo

      foreach ($filasCrudas as $fila) {
        $numFila++;
        $pregunta  = $fila[0];
        $tipoBruto = $fila[1];

        if ($pregunta === '' && $tipoBruto === '') { continue; } //fila vacia: se ignora

        //La primera fila es el encabezado de la plantilla: se salta
        if ($numFila === 1 && hanaNormalizaTexto($pregunta) === 'pregunta') { continue; }

        if ($pregunta === '' || $tipoBruto === '') {
          $errores[] = "Fila $numFila: falta la pregunta o el tipo de respuesta.";
          continue;
        }
        //La columna de la base es varchar(200): mas largo no cabe
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

      //Ya validado todo, se guarda. El orden continua despues de las preguntas que ya existan
      $filaMax = $Preguntas->ordenMax($idLista);
      $orden   = $filaMax ? intval($filaMax['m']) : 0;

      $numPreguntas = 0;
      $rsptaCant = $Preguntas->consCantPreguntas($idLista);
      if ($rsptaCant) {
        $filaCant = $rsptaCant->fetch_assoc();
        $numPreguntas = intval($filaCant['cantPreguntas']);
      }

      $guardadas = 0;
      $fallaron  = 0;
      foreach ($preguntasOk as $p) {
        $orden++;
        //insertar() espera 6 datos. Antes se le enviaban 3 y la importacion
        //moria con un error fatal que no se veia en pantalla.
        //GENERA_NOVEDAD es una columna entera: enviarle '' hace fallar el INSERT
        //cuando MySQL esta en modo estricto
        $rspta1 = $Preguntas->insertar($idLista, limpiarCadena($p['pregunta']), $p['tipo'], 0, '', $orden);
        if ($rspta1 != '') { $guardadas++; } else { $fallaron++; }
      }

      $Preguntas->insertarCantPreguntas($idLista, $numPreguntas + $guardadas);

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
    if ($rspta) {
      $rspt = $rspta->fetch_assoc();
      $numPreguntas = $rspt['cantPreguntas'];
      $rspta1=$Preguntas->anular($idPregunta);
      if ($rspta1 != '') {
        $numPreguntas = $numPreguntas - 1;
        $rspta = $Preguntas->insertarCantPreguntas($idLista, $numPreguntas);
        if (!$rspta) { http_response_code(400); echo "No se pudo actualizar el conteo de preguntas."; }
      } else {
        //Antes, si la anulacion fallaba, no se respondia nada y la pantalla
        //lo tomaba como exito
        http_response_code(400);
        echo "No se pudo anular la pregunta.";
      }
    } else {
      http_response_code(400);
      echo "No se pudo consultar la lista de preguntas.";
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

  case 'reordenar':
    try {
      $ids = (isset($_POST['orden']) && is_array($_POST['orden'])) ? $_POST['orden'] : array();
      $pos = 1;
      foreach ($ids as $idp) {
        $Preguntas->setOrden((int)$idp, $pos);
        $pos++;
      }
      echo http_response_code(200);
    } catch (\Throwable $th) {
      http_response_code(404); echo json_encode($th);
    }
    break;
  case 'posiciones':
    try {
      $rspta = $Preguntas->listar($idGrupoPreguntas);
      $arr = array();
      while ($row = $rspta->fetch_object()) {
        if ($row->ESTADO == 1) {
          //Se devuelve tambien tipo/genera/titulo: la pantalla de edicion masiva
        //los necesita para precargar cada fila
        $arr[] = array(
            "id"      => $row->ID_DETALLE_GRUPO_LISTA_CHEQUEO,
            "pregunta"=> $row->PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO,
            "tipo"    => $row->TIPO_RESPUESTA,
            "genera"  => $row->GENERA_NOVEDAD,
            "titulo"  => $row->TITULO_NOVEDAD,
            "orden"   => $row->ORDEN
          );
        }
      }
      echo json_encode($arr);
    } catch (\Throwable $th) {
      http_response_code(404); echo json_encode($th);
    }
    break;
  //Usado por la pantalla "Editar todas las preguntas": guarda de un solo golpe
  //el nuevo orden y la edicion (texto y tipo) de todas las preguntas
  case 'guardarLote':
    try {
      //Llega un JSON con las preguntas EN EL ORDEN FINAL que dejo el usuario
      $items = isset($_POST['items']) ? json_decode($_POST['items'], true) : array();
      if (!is_array($items)) { $items = array(); } //si el JSON viene mal, no se procesa nada
      $pos = 1; //contador de posicion: 1,2,3... segun el orden de llegada
      foreach ($items as $it) {
        $id = isset($it['id']) ? (int)$it['id'] : 0;
        if ($id <= 0) { continue; }
        $preg   = limpiarCadena(isset($it['pregunta']) ? $it['pregunta'] : '');
        $tipo   = limpiarCadena(isset($it['tipo'])     ? $it['tipo']     : '');
        $genera = limpiarCadena(isset($it['genera'])   ? $it['genera']   : '');
        $titulo = limpiarCadena(isset($it['titulo'])   ? $it['titulo']   : '');
        //editar() sobrescribe tambien los campos de novedad, por eso se reenvian igual
        $Preguntas->editar($id, $preg, $tipo, $genera, $titulo);
        $Preguntas->setOrden($id, $pos); //aqui queda fija la posicion en la BD
        $pos++;
      }
      echo http_response_code(200);
    } catch (\Throwable $th) {
      http_response_code(404); echo json_encode($th);
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
            "1"=>$row->ORDEN,
            "2"=>$row->ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO,
            "3"=>$row->PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO,
            "4"=>$row->TIPO_RESPUESTA,
            "5"=>$row->ESTADO == 1 ? "Activo":"Inactivo",
            "6"=>'<SPAN title="Editar"><button class="btn btn-success" onclick="mostrarPregunta('.$row->ID_DETALLE_GRUPO_LISTA_CHEQUEO.')"><i class="fa fa-eye"></i></button></SPAN>
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