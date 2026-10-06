<?php
/*
  HANA — Archivos adjuntos (comunicaciones y oficios, listas de chequeo)
  ---------------------------------------------------------------------------
  Cómo funciona:
    - El archivo se guarda en archivos/adjuntos/AAAA/MM/ con un nombre al azar
      (nadie puede adivinar la ruta de otro). Esa carpeta tiene un .htaccess
      que no deja abrirla por la dirección: los archivos solo salen por aquí,
      después de revisar quién los pide.
    - En la tabla `adjunto` queda el nombre original, quién lo subió y cuándo.
    - Borrar deja el registro con ESTADO 0 (se puede recuperar si hace falta).

  Quién puede qué:
    COMUNICACION  subir, cambiar la descripción y borrar: el coordinador del
                  proyecto. Ver: además 20M, 21M y 22M.
    LISTA         subir, cambiar y borrar: solo quien llenó esa lista,
                  y solo el día de la lista (hoy o un día habilitado). Ver: además 12M,
                  20M, 21M y quien ve ese peaje en el tablero (19M).
  Y el administrador puede apagar los adjuntos de cada módulo en Parámetros.

  Operaciones: listar, subir, describir, borrar, ver (entrega el archivo).
*/
session_start();
require_once __DIR__ . "/../Modelo/HanaConfig.php";
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";
date_default_timezone_set('America/Bogota');

//La carpeta donde viven los archivos (fuera del alcance del navegador)
define('ADJ_CARPETA', __DIR__ . '/../archivos/adjuntos/');

//Tipos de archivo permitidos: extensión => tipo que se le dice al navegador
$ADJ_TIPOS = array(
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'csv' => 'text/csv', 'txt' => 'text/plain', 'msg' => 'application/vnd.ms-outlook', 'eml' => 'message/rfc822'
);

function adjError($codigo, $mensaje)
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { adjError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
$idUsuario = (int)$_SESSION['IdUsuarios'];
$idColaborador = (int)$_SESSION['Idcolaborador'];

//---------------------------------------------------------------------------
// ¿Qué puede hacer esta persona con los archivos de este registro?
// Devuelve array('ver' => bool, 'editar' => bool) o null si el registro no existe
//---------------------------------------------------------------------------
function adjAcceso($modulo, $idRegistro, $idUsuario, $idColaborador)
{
    $coordina = array();
    foreach (HanaDB::proyectosCoordinados($idColaborador) as $p) { $coordina[(int)$p['ID_PROYECTO']] = true; }
    $verTodos = hanaTienePermiso('20M') || hanaTienePermiso('21M') || hanaTienePermiso('22M');

    if ($modulo === 'COMUNICACION') {
        //Oficios como las RQ: los ve y les adjunta quien tenga el proyecto asignado (lo coordina,
        //es jefe de uno de sus peajes o tiene un peaje asignado en Usuarios); ADMIN TEC y 32M, todos
        $r = HanaDB::fila("SELECT ID_PROYECTO FROM comunicacion WHERE ID_COMUNICACION = ? AND ESTADO = 1", 'i', array((int)$idRegistro));
        if (!$r) { return null; }
        $p = (int)$r['ID_PROYECTO'];
        $asignado = isset($coordina[$p]) || hanaTienePermiso('24M') || hanaTienePermiso('32M')
            || HanaDB::fila("SELECT 1 AS ok FROM centros_operacion c
                              WHERE c.ID_PROYECTO_CENTRO_OP = ? AND c.Estado = '1'
                                AND (c.ID_COLABORADOR_JEFE = ? OR c.ID_CENTRO_OP IN (SELECT ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP FROM asoc_usuarios_sistemas_x_cop
                                                                                    WHERE ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = ?)) LIMIT 1",
                            'iii', array($p, (int)$idColaborador, (int)$idUsuario));
        $editar = HanaConfig::si('ADJUNTOS_COMUNICACIONES') && $asignado;
        return array('ver' => $asignado || $verTodos, 'editar' => $editar);
    }

    if ($modulo === 'LISTA') {
        $r = HanaDB::fila("SELECT l.ID_CENTRO_OP_LISTA_CHEQUEO AS CENTRO, l.FEC_REGISTRO_LISTA_CHEQUEO AS FECHA, c.ID_PROYECTO_CENTRO_OP AS PROYECTO,
                                  l.ID_COLABORADOR_LISTA_CHEQUEO AS DUENO
                             FROM lista_chequeo l INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = l.ID_CENTRO_OP_LISTA_CHEQUEO
                            WHERE l.ID_LISTA_CHEQUEO = ?", 'i', array((int)$idRegistro));
        if (!$r) { return null; }
        //Quién diligencia listas en ese centro: el coordinador del proyecto, o (si el
        //administrador lo permitió) quien tenga 11M y el centro asignado
        $asignado = (bool)HanaDB::fila("SELECT 1 AS ok FROM asoc_usuarios_sistemas_x_cop WHERE ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = ?
                                         AND ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP = ?", 'ii', array($idUsuario, (int)$r['CENTRO']));
        $diligencia = isset($coordina[(int)$r['PROYECTO']])
                   || (!HanaConfig::si('LISTAS_SOLO_COORDINADOR') && hanaTienePermiso('11M') && $asignado);
        //Los archivos de una lista los cambia solo quien la llenó, y solo si la lista es de hoy o de un día habilitado
        $editar = HanaConfig::si('ADJUNTOS_LISTAS') && $diligencia && (int)$r['DUENO'] === (int)$idColaborador && HanaFechas::enVentana($r['FECHA']);
        $ver = $diligencia || $asignado || $verTodos || hanaTienePermiso('12M');
        //El tablero (19M): el jefe mayor ve todo; el coordinador su proyecto; el jefe su peaje
        if (!$ver && hanaTienePermiso('19M')) {
            $ver = (bool)HanaDB::fila("SELECT 1 AS ok FROM centros_operacion WHERE ID_CENTRO_OP = ? AND ID_COLABORADOR_JEFE = ?", 'ii', array((int)$r['CENTRO'], $idColaborador));
        }
        return array('ver' => $ver, 'editar' => $editar);
    }
    return null;
}

function adjCargar($modulo, $id, $idUsuario, $idColaborador, $paraEditar)
{
    $m = in_array($modulo, array('COMUNICACION', 'LISTA'), true) ? $modulo : '';
    if ($m === '') { adjError(400, 'Módulo no válido.'); }
    $a = adjAcceso($m, $id, $idUsuario, $idColaborador);
    if (!$a || !$a['ver']) { adjError(404, 'No se encontró el registro, o no tienes acceso a él.'); }
    if ($paraEditar && !$a['editar']) { adjError(403, 'No puedes cambiar los archivos de este registro (o los adjuntos están desactivados, o la lista ya no es de hoy ni de un día habilitado).'); }
    return $a;
}

//Un adjunto por su id, con el acceso de su registro
function adjPorId($id, $idUsuario, $idColaborador, $paraEditar)
{
    $x = HanaDB::fila("SELECT * FROM adjunto WHERE ID_ADJUNTO = ? AND ESTADO = 1", 'i', array((int)$id));
    if (!$x) { adjError(404, 'No se encontró el archivo.'); }
    adjCargar($x['MODULO'], $x['ID_REGISTRO'], $idUsuario, $idColaborador, $paraEditar);
    return $x;
}

$op = isset($_GET['op']) ? $_GET['op'] : '';
if ($op !== 'ver') { header('Content-Type: application/json; charset=utf-8'); }

switch ($op) {

    case 'listar':
        $modulo = isset($_GET['modulo']) ? $_GET['modulo'] : '';
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $a = adjCargar($modulo, $id, $idUsuario, $idColaborador, false);
        $f = HanaDB::q("SELECT a.ID_ADJUNTO, a.NOMBRE, a.DESCRIPCION, a.TIPO, a.TAMANO, a.FEC_REGISTRO, c.NOM_COLABORADOR AS QUIEN
                          FROM adjunto a LEFT JOIN colaboradores c ON c.ID_COLABORADOR = a.ID_COLABORADOR
                         WHERE a.MODULO = ? AND a.ID_REGISTRO = ? AND a.ESTADO = 1 ORDER BY a.ID_ADJUNTO", 'si', array($modulo, $id));
        echo json_encode(array('archivos' => $f ? $f : array(), 'editable' => $a['editar'],
                               'maxMb' => HanaConfig::num('ADJUNTOS_MAX_MB', 10), 'tipos' => array_keys($ADJ_TIPOS)), JSON_UNESCAPED_UNICODE);
        break;

    //Sube uno o varios archivos (campo archivos[])
    case 'subir':
        $modulo = isset($_POST['modulo']) ? $_POST['modulo'] : '';
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        adjCargar($modulo, $id, $idUsuario, $idColaborador, true);
        if (empty($_FILES['archivos']) || !is_array($_FILES['archivos']['name'])) {
            adjError(400, 'No llegó ningún archivo. Si es muy grande, puede que el servidor lo haya rechazado antes (upload_max_filesize).');
        }
        $maxBytes = max(1, HanaConfig::num('ADJUNTOS_MAX_MB', 10)) * 1024 * 1024;
        $sub = date('Y/m');
        if (!is_dir(ADJ_CARPETA . $sub) && !@mkdir(ADJ_CARPETA . $sub, 0755, true)) {
            adjError(500, 'No se pudo crear la carpeta de archivos en el servidor. Revisa los permisos de archivos/adjuntos.');
        }
        $guardados = 0; $avisos = array();
        foreach ($_FILES['archivos']['name'] as $i => $nombre) {
            $err = $_FILES['archivos']['error'][$i];
            $nombre = basename((string)$nombre);
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $avisos[] = $nombre . ': es más grande de lo que permite el servidor.'; continue; }
            if ($err !== UPLOAD_ERR_OK) { $avisos[] = $nombre . ': no se pudo subir (error ' . $err . ').'; continue; }
            $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            if (!isset($ADJ_TIPOS[$ext])) { $avisos[] = $nombre . ': ese tipo de archivo no se permite.'; continue; }
            $tam = (int)$_FILES['archivos']['size'][$i];
            if ($tam > $maxBytes) { $avisos[] = $nombre . ': pasa de ' . HanaConfig::num('ADJUNTOS_MAX_MB', 10) . ' MB.'; continue; }
            //Una imagen tiene que ser de verdad una imagen (no un programa con otra extensión)
            if (in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true) && @getimagesize($_FILES['archivos']['tmp_name'][$i]) === false) {
                $avisos[] = $nombre . ': no parece una imagen válida.'; continue;
            }
            $ruta = $sub . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['archivos']['tmp_name'][$i], ADJ_CARPETA . $ruta)) { $avisos[] = $nombre . ': no se pudo guardar en el servidor.'; continue; }
            HanaDB::q("INSERT INTO adjunto (MODULO, ID_REGISTRO, NOMBRE, ARCHIVO, TIPO, TAMANO, ID_COLABORADOR, FEC_REGISTRO, ESTADO)
                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 1)", 'sisssii',
                      array($modulo, $id, function_exists('mb_substr') ? mb_substr($nombre, 0, 200) : substr($nombre, 0, 200), $ruta, $ADJ_TIPOS[$ext], $tam, $idColaborador));
            $guardados++;
        }
        if (!$guardados) { adjError(400, $avisos ? implode(' ', $avisos) : 'No se guardó ningún archivo.'); }
        echo json_encode(array('ok' => true, 'mensaje' => $guardados === 1 ? 'Archivo guardado.' : $guardados . ' archivos guardados.', 'avisos' => $avisos), JSON_UNESCAPED_UNICODE);
        break;

    //Cambiar la descripción de un archivo
    case 'describir':
        $x = adjPorId(isset($_POST['id']) ? (int)$_POST['id'] : 0, $idUsuario, $idColaborador, true);
        $d = HanaVal::texto(isset($_POST['descripcion']) ? $_POST['descripcion'] : '', 200);
        HanaDB::q("UPDATE adjunto SET DESCRIPCION = ?, FEC_MODIFICACION = NOW() WHERE ID_ADJUNTO = ?", 'si', array($d, (int)$x['ID_ADJUNTO']));
        echo json_encode(array('ok' => true, 'mensaje' => 'Descripción guardada.'), JSON_UNESCAPED_UNICODE);
        break;

    case 'borrar':
        $x = adjPorId(isset($_POST['id']) ? (int)$_POST['id'] : 0, $idUsuario, $idColaborador, true);
        HanaDB::q("UPDATE adjunto SET ESTADO = 0, FEC_MODIFICACION = NOW() WHERE ID_ADJUNTO = ?", 'i', array((int)$x['ID_ADJUNTO']));
        echo json_encode(array('ok' => true, 'mensaje' => 'Archivo quitado.'), JSON_UNESCAPED_UNICODE);
        break;

    //Entrega el archivo: en el navegador si es PDF o imagen; si no, se descarga
    case 'ver':
        $x = adjPorId(isset($_GET['id']) ? (int)$_GET['id'] : 0, $idUsuario, $idColaborador, false);
        $ruta = realpath(ADJ_CARPETA . $x['ARCHIVO']);
        if (!$ruta || strpos($ruta, realpath(ADJ_CARPETA)) !== 0 || !is_file($ruta)) { adjError(404, 'El archivo ya no está en el servidor.'); }
        $enLinea = !isset($_GET['descargar']) && (strpos($x['TIPO'], 'image/') === 0 || $x['TIPO'] === 'application/pdf');
        $nombre = str_replace(array('"', "\r", "\n"), '', $x['NOMBRE']);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: ' . $x['TIPO']);
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . $nombre . '"; filename*=UTF-8\'\'' . rawurlencode($x['NOMBRE']));
        readfile($ruta);
        break;

    default:
        adjError(400, 'Operación no reconocida.');
}
