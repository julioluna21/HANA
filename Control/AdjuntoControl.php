<?php
/*
  HANA - Archivos adjuntos (comunicaciones y oficios, listas de chequeo, RQ)
  ---------------------------------------------------------------------------
  Cómo funciona:
    - El archivo se guarda dentro de la base, en adjunto.ARCHIVO, como texto
      base64 ("data:tipo;base64,..."), con su nombre original, quién lo subió
      y cuándo (ver Control/ArchivoHelper.php). Las fotos muy pesadas se achican.
    - Los archivos solo salen por aquí (op=ver), después de revisar quién los
      pide. También las fotos y soportes de las RQ, que se suben desde RQControl.php.
    - Borrar deja el registro con ESTADO 0 (se puede recuperar si hace falta).

  Quién puede qué:
    COMUNICACION  subir, cambiar la descripción y borrar: el coordinador del
                  proyecto. Ver: además 20M, 21M y 22M.
    LISTA         subir, cambiar y borrar: solo quien llenó esa lista,
                  y solo el día de la lista (hoy o un día habilitado). Ver: además 12M,
                  20M, 21M y quien ve ese peaje en el tablero (19M).
    RQ            solo ver: quien puede abrir esa RQ en Requisiciones, quien ve su
                  peaje en el reporte general (19M), 20M, 21M, 22M y el ADMIN TEC.
  Y el administrador puede apagar los adjuntos de cada módulo en Parámetros.

  Operaciones: listar, subir, describir, borrar, ver (entrega el archivo).
*/
session_start();
require_once __DIR__ . "/../Modelo/HanaConfig.php";
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/../Modelo/AdjuntoModelo.php";
require_once __DIR__ . "/../Modelo/RQModelo.php";
require_once __DIR__ . "/../Modelo/TableroModelo.php";
require_once __DIR__ . "/AccesoHelper.php";
require_once __DIR__ . "/ArchivoHelper.php";
date_default_timezone_set('America/Bogota');

//Tipos de archivo permitidos: extensión => tipo que se le dice al navegador
$ADJ_TIPOS = array(
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'csv' => 'text/csv', 'txt' => 'text/plain', 'msg' => 'application/vnd.ms-outlook', 'eml' => 'message/rfc822'
);

//Responde con un código de error y un mensaje en JSON, y corta la ejecución
function adjError($codigo, $mensaje)
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { adjError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
$idUsuario = (int)$_SESSION['Idcolaborador']; //el usuario es la persona (colaboradores)
$idColaborador = (int)$_SESSION['Idcolaborador'];

//---------------------------------------------------------------------------
// ¿Qué puede hacer esta persona con los archivos de este registro?
// Devuelve array('ver' => bool, 'editar' => bool) o null si el registro no existe
//---------------------------------------------------------------------------
function adjAcceso($modulo, $idRegistro, $idUsuario, $idColaborador)
{
    $coordina = array();
    foreach (hanaProyectosCoordinados($idColaborador) as $proyecto) { $coordina[(int)$proyecto['ID_PROYECTO']] = true; }
    $verTodos = hanaTienePermiso('20M') || hanaTienePermiso('21M') || hanaTienePermiso('22M');

    if ($modulo === 'COMUNICACION') {
        //Oficios como las RQ: los ve y les adjunta quien tenga el proyecto asignado (lo coordina,
        //es jefe de uno de sus peajes o tiene un peaje asignado en Usuarios); ADMIN TEC y 32M, todos
        $idProyecto = (new Adjunto())->proyectoDeComunicacion($idRegistro);
        if (!$idProyecto) { return null; }
        $asignado = isset($coordina[$idProyecto]) || hanaTienePermiso('24M') || hanaTienePermiso('32M')
            || Organizacion::tienePeajes($idColaborador, $idUsuario, $idProyecto);
        $editar = HanaConfig::si('ADJUNTOS_COMUNICACIONES') && $asignado;
        return array('ver' => $asignado || $verTodos, 'editar' => $editar);
    }

    if ($modulo === 'LISTA') {
        $lista = (new Adjunto())->datosLista($idRegistro);
        if (!$lista) { return null; }
        //Quién diligencia listas en ese centro: quien tenga 11M y el centro asignado (o el ADMIN TEC)
        $asignado = Organizacion::centroAsignado($idUsuario, (int)$lista['CENTRO']);
        $diligencia = hanaTienePermiso('11M') && ($asignado || isset($coordina[(int)$lista['PROYECTO']]) || hanaTienePermiso('24M'));
        //Los archivos de una lista los cambia quien la llenó (o el ADMIN TEC), y solo si la lista es de hoy o de un día habilitado
        $editar = HanaConfig::si('ADJUNTOS_LISTAS') && $diligencia && ((int)$lista['DUENO'] === (int)$idColaborador || hanaTienePermiso('24M'))
               && HanaFechas::enVentana($lista['FECHA'], $idColaborador);
        $ver = $diligencia || $asignado || $verTodos || hanaTienePermiso('12M');
        //El tablero (19M): el gerente de operaciones ve todo; el coordinador su proyecto; el jefe su peaje
        if (!$ver && hanaTienePermiso('19M')) {
            $ver = Organizacion::esJefeDeCentro((int)$lista['CENTRO'], $idColaborador);
        }
        return array('ver' => $ver, 'editar' => $editar);
    }

    if ($modulo === 'RQ') {
        //Las fotos y soportes de una RQ: los ve quien puede abrir esa RQ (la misma regla de Requisiciones)
        $misCasillas = explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : '');
        $conRq = (bool)array_intersect(array('15M', '17M', '18M', '41M'), $misCasillas);
        $ver = ($conRq && (new Rq())->mostrar(hanaCondRQ('todas'), $idRegistro)) || $verTodos || hanaTienePermiso('24M');
        //También quien ve el peaje de la RQ en el reporte general, donde salen las fotos de las RQ por aprobar
        if (!$ver && hanaTienePermiso('19M')) {
            $centro = (new Adjunto())->centroDeRq($idRegistro);
            $alcance = (new Tablero())->alcance($idColaborador, false);
            $ver = $centro > 0 && isset($alcance['centros'][$centro]);
        }
        return array('ver' => (bool)$ver, 'editar' => false);
    }
    return null;
}

//Revisa que el módulo sea válido y que la persona pueda ver (o editar) el registro antes de tocar sus adjuntos.
//Los de las RQ solo se abren desde aquí ($conRq); se suben con la RQ en RQControl.php
function adjCargar($modulo, $id, $idUsuario, $idColaborador, $paraEditar, $conRq = false)
{
    $validos = $conRq ? array('COMUNICACION', 'LISTA', 'RQ') : array('COMUNICACION', 'LISTA');
    $moduloValido = in_array($modulo, $validos, true) ? $modulo : '';
    if ($moduloValido === '') { adjError(400, 'Módulo no válido.'); }
    $acceso = adjAcceso($moduloValido, $id, $idUsuario, $idColaborador);
    if (!$acceso || !$acceso['ver']) { adjError(404, 'No se encontró el registro, o no tienes acceso a él.'); }
    if ($paraEditar && !$acceso['editar']) { adjError(403, 'No puedes cambiar los archivos de este registro (o los adjuntos están desactivados, o la lista ya no es de hoy ni de un día habilitado).'); }
    return $acceso;
}

//Un adjunto por su id, con el acceso de su registro
function adjPorId($id, $idUsuario, $idColaborador, $paraEditar, $conRq = false)
{
    $adjunto = (new Adjunto())->porId($id);
    if (!$adjunto) { adjError(404, 'No se encontró el archivo.'); }
    adjCargar($adjunto['MODULO'], $adjunto['ID_REGISTRO'], $idUsuario, $idColaborador, $paraEditar, $conRq);
    return $adjunto;
}

$op = isset($_GET['op']) ? $_GET['op'] : '';
if ($op !== 'ver') { header('Content-Type: application/json; charset=utf-8'); }

switch ($op) {

    //Lista los archivos adjuntos de una comunicación o de una lista de chequeo
    case 'listar':
        $modulo = isset($_GET['modulo']) ? $_GET['modulo'] : '';
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $acceso = adjCargar($modulo, $id, $idUsuario, $idColaborador, false);
        $archivos = (new Adjunto())->listar($modulo, $id);
        echo json_encode(array('archivos' => $archivos ? $archivos : array(), 'editable' => $acceso['editar'],
                               'maxMb' => HanaConfig::num('ADJUNTOS_MAX_MB', 10), 'tipos' => array_keys($ADJ_TIPOS)), JSON_UNESCAPED_UNICODE);
        break;

    //Sube uno o varios archivos (campo archivos[])
    case 'subir':
        $modulo = isset($_POST['modulo']) ? $_POST['modulo'] : '';
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        adjCargar($modulo, $id, $idUsuario, $idColaborador, true);
        if (empty($_FILES['archivos']) || !is_array($_FILES['archivos']['name'])) {
            adjError(400, 'No llegó ningún archivo. Si es muy grande, prueba con uno más liviano.');
        }
        $maxBytes = max(1, HanaConfig::num('ADJUNTOS_MAX_MB', 10)) * 1024 * 1024;
        $guardados = 0; $avisos = array();
        foreach ($_FILES['archivos']['name'] as $i => $nombre) {
            $err = $_FILES['archivos']['error'][$i];
            $nombre = basename((string)$nombre);
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $avisos[] = $nombre . ': es demasiado grande. Prueba con uno más liviano.'; continue; }
            if ($err !== UPLOAD_ERR_OK) { $avisos[] = $nombre . ': no se pudo subir (error ' . $err . ').'; continue; }
            $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            if (!isset($ADJ_TIPOS[$ext])) { $avisos[] = $nombre . ': ese tipo de archivo no se permite.'; continue; }
            $tam = (int)$_FILES['archivos']['size'][$i];
            if ($tam > $maxBytes) { $avisos[] = $nombre . ': pasa de ' . HanaConfig::num('ADJUNTOS_MAX_MB', 10) . ' MB.'; continue; }
            //Una imagen tiene que ser de verdad una imagen (no un programa con otra extensión)
            $esFoto = in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true);
            $info = $esFoto ? @getimagesize($_FILES['archivos']['tmp_name'][$i]) : null;
            if ($esFoto && $info === false) { $avisos[] = $nombre . ': no parece una imagen válida.'; continue; }
            $bytes = @file_get_contents($_FILES['archivos']['tmp_name'][$i]);
            if ($bytes === false) { $avisos[] = $nombre . ': no se pudo leer.'; continue; }
            //El archivo va dentro de la base como texto base64 (las fotos pesadas se achican antes)
            $archivo = hanaArchivoTexto($bytes, $esFoto ? $info['mime'] : $ADJ_TIPOS[$ext]);
            if (!hanaArchivoCabe($archivo['texto'])) { $avisos[] = hanaArchivoMuyGrande($nombre); continue; }
            if ($archivo['achicada']) { $nombre = hanaArchivoNombreJpg($nombre); }
            $nombre = function_exists('mb_substr') ? mb_substr($nombre, 0, 200) : substr($nombre, 0, 200);
            if (!(new Adjunto())->guardar($modulo, $id, $nombre, $archivo['texto'], $archivo['tipo'], $archivo['tamano'], $idColaborador)) {
                $avisos[] = $nombre . ': no se pudo guardar. Intenta de nuevo.'; continue;
            }
            $guardados++;
        }
        if (!$guardados) { adjError(400, $avisos ? implode(' ', $avisos) : 'No se guardó ningún archivo.'); }
        echo json_encode(array('ok' => true, 'mensaje' => $guardados === 1 ? 'Archivo guardado.' : $guardados . ' archivos guardados.', 'avisos' => $avisos), JSON_UNESCAPED_UNICODE);
        break;

    //Cambiar la descripción de un archivo
    case 'describir':
        $adjunto = adjPorId(isset($_POST['id']) ? (int)$_POST['id'] : 0, $idUsuario, $idColaborador, true);
        $descripcion = HanaVal::texto(isset($_POST['descripcion']) ? $_POST['descripcion'] : '', 200);
        (new Adjunto())->describir((int)$adjunto['ID_ADJUNTO'], $descripcion);
        echo json_encode(array('ok' => true, 'mensaje' => 'Descripción guardada.'), JSON_UNESCAPED_UNICODE);
        break;

    //Quita un adjunto (se revisa antes que la persona pueda editar el registro)
    case 'borrar':
        $adjunto = adjPorId(isset($_POST['id']) ? (int)$_POST['id'] : 0, $idUsuario, $idColaborador, true);
        (new Adjunto())->quitar((int)$adjunto['ID_ADJUNTO']);
        echo json_encode(array('ok' => true, 'mensaje' => 'Archivo quitado.'), JSON_UNESCAPED_UNICODE);
        break;

    //Entrega el archivo: en el navegador si es PDF o imagen; si no, se descarga.
    //Sirve también para las fotos y soportes de las RQ
    case 'ver':
        $adjunto = adjPorId(isset($_GET['id']) ? (int)$_GET['id'] : 0, $idUsuario, $idColaborador, false, true);
        $partes = hanaArchivoPartes((new Adjunto())->contenido((int)$adjunto['ID_ADJUNTO']));
        if (!$partes) { adjError(404, 'El archivo ya no está disponible.'); }
        list($tipo, $bytes) = $partes;
        $enLinea = !isset($_GET['descargar']) && (strpos($tipo, 'image/') === 0 || $tipo === 'application/pdf');
        hanaArchivoEntregar($bytes, $tipo, $adjunto['NOMBRE'], $enLinea);
        break;

    default:
        adjError(400, 'Operación no reconocida.');
}
