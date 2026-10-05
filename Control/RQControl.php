<?php
//Controlador del módulo de Requisiciones (RQ)
//Fase 1 (24 de septiembre): dos tipos (normal y urgente), flujo de un solo paso
//(Solicitada -> Aprobada o Rechazada), permiso 18M para aprobar, filtro por
//periodo y sin novedades: las RQ ya no generan novedades
session_start();
require_once __DIR__ . "/../Modelo/RQModelo.php";
require_once __DIR__ . "/AccesoHelper.php"; //quién puede ver qué: la misma regla de novedades y campana

header('Content-Type: application/json; charset=utf-8');

//---------------------------------------------------------------------------
// Sin sesión, o sin ningún permiso de RQ, no se atiende nada.
// Entran: pedir RQ (15M), ver todas (17M) o aprobar (18M). Crear una nueva exige 15M
//---------------------------------------------------------------------------
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) {
    http_response_code(401);
    echo json_encode(array('error' => 'Tu sesión terminó. Vuelve a iniciar sesión.'));
    exit;
}
$modulos = explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : '');
if (!array_intersect(array('15M', '17M', '18M'), $modulos)) {
    http_response_code(403);
    echo json_encode(array('error' => 'No tienes permiso para el módulo de requisiciones.'));
    exit;
}
$puedePedir = in_array('15M', $modulos, true);

$idUsuario     = (int)$_SESSION['IdUsuarios'];
$idColaborador = (int)$_SESSION['Idcolaborador'];
$puedeAprobar  = hanaTienePermiso(PERMISO_APROBAR_RQ);
$Rq            = new Rq();

//Carpeta de los archivos de las RQ, dentro de public/
define('CARPETA_RQ', __DIR__ . '/../public/rq/');
define('RUTA_WEB_RQ', '../public/rq/');
define('MAX_ARCHIVOS', 10);
define('MAX_BYTES', 8 * 1024 * 1024); //8 MB por archivo

//---------------------------------------------------------------------------
// Utilidades
//---------------------------------------------------------------------------

//Responde con un error y termina
function hanaError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//Convierte un resultado en arreglo. limpiarCadena() guarda el texto codificado
//para HTML (&quot; en vez de "): aquí se decodifica, y la pantalla lo vuelve a
//escapar al mostrarlo. Así se ve bien y sigue siendo seguro
function hanaFilas($rspta)
{
    $filas = array();
    if ($rspta) {
        while ($f = $rspta->fetch_assoc()) {
            foreach ($f as $k => $v) {
                if (is_string($v)) { $f[$k] = html_entity_decode($v, ENT_QUOTES, 'UTF-8'); }
            }
            $filas[] = $f;
        }
    }
    return $filas;
}
function hanaFila($fila)
{
    if (!$fila) { return null; }
    foreach ($fila as $k => $v) {
        if (is_string($v)) { $fila[$k] = html_entity_decode($v, ENT_QUOTES, 'UTF-8'); }
    }
    return $fila;
}

//Solo se aceptan dos tipos; cualquier otra cosa es normal
function hanaTipoRQ($valor)
{
    return ($valor === 'U') ? 'U' : 'N';
}

//"RQ-12" o "RQ U-12", como se muestra en pantalla
function hanaEtiquetaRQ($tipo, $numero)
{
    return ($tipo === 'U' ? 'RQ U-' : 'RQ-') . $numero;
}

//Qué puede hacer este usuario con una RQ concreta
//  Solo quien subió la RQ la modifica (decisión de Jaime). Quien aprueba (18M)
//  la aprueba o la rechaza, pero no cambia su contenido ni la anula.
//  Anular:   mientras esté en "Solicitada", solo quien la pidió
//  Editar:   en "Solicitada" o "Rechazada", solo quien la pidió
//            (si estaba rechazada, al corregirla vuelve a "Solicitada")
function hanaAccionesRQ($cab, $idColaborador, $puedeAprobar)
{
    $activa     = (int)$cab['ESTADO'] === 1;
    $estado     = (int)$cab['ID_RQ_ESTADO'];
    $esQuienPide = (int)$cab['ID_COLABORADOR_SOLICITA'] === (int)$idColaborador;
    return array(
        'anular'    => $activa && $estado === Rq::SOLICITADA && $esQuienPide,
        'editar'    => $activa && $esQuienPide && ($estado === Rq::SOLICITADA || $estado === Rq::RECHAZADA),
        'retrocede' => $activa && $estado === Rq::RECHAZADA
    );
}

//Revisa que un archivo subido sea de verdad una imagen o un PDF.
//No se confía en la extensión ni en lo que diga el navegador: se mira el
//contenido. Devuelve la extensión a usar, o '' si no se acepta
function hanaTipoArchivo($ruta)
{
    $permitidos = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
                        'image/gif' => 'gif', 'application/pdf' => 'pdf');
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $ruta);
        finfo_close($fi);
        return isset($permitidos[$mime]) ? $permitidos[$mime] : '';
    }
    //Si el servidor no tiene la extensión fileinfo, se revisa a mano
    $info = @getimagesize($ruta);
    if ($info && isset($permitidos[$info['mime']])) { return $permitidos[$info['mime']]; }
    $cabecera = file_get_contents($ruta, false, null, 0, 5);
    return ($cabecera === '%PDF-') ? 'pdf' : '';
}

//Deja la carpeta lista, con la protección que impide ejecutar código ahí
function hanaPrepararCarpeta()
{
    if (!is_dir(CARPETA_RQ) && !@mkdir(CARPETA_RQ, 0755, true)) { return false; }
    $ht = CARPETA_RQ . '.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht,
            "# Carpeta de archivos de las RQ: aquí solo hay imágenes y PDF.\n" .
            "# Nada se ejecuta, aunque alguien lograra subir un archivo de código.\n" .
            "php_flag engine off\n" .
            "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps\n" .
            "<FilesMatch \"\\.(php|phtml|phps|pl|py|cgi|sh)$\">\n" .
            "  Require all denied\n" .
            "</FilesMatch>\n" .
            "Options -Indexes\n");
    }
    return is_writable(CARPETA_RQ);
}

//---------------------------------------------------------------------------
// Operaciones
//---------------------------------------------------------------------------
switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //Los peajes del usuario, para el formulario y los filtros
    case 'centros':
        echo json_encode(hanaFilas($Rq->centrosUsuario($idUsuario)), JSON_UNESCAPED_UNICODE);
        break;

    //Lo que la pantalla necesita saber al abrir: si ve todas, si aprueba y
    //qué años tienen RQ (para el filtro de periodo)
    case 'config':
        $anios = array();
        foreach (hanaFilas($Rq->anios()) as $f) { if ($f['anio']) { $anios[] = (int)$f['anio']; } }
        if (!in_array((int)date('Y'), $anios)) { array_unshift($anios, (int)date('Y')); }
        echo json_encode(array(
            'verTodas'     => hanaTienePermiso(PERMISO_VER_TODAS_RQ) || $puedeAprobar,
            'puedeAprobar' => $puedeAprobar,
            'anios'        => $anios
        ), JSON_UNESCAPED_UNICODE);
        break;

    case 'estados':
        echo json_encode(hanaFilas($Rq->estados()), JSON_UNESCAPED_UNICODE);
        break;

    //El número que le tocaría a la próxima RQ de un peaje
    case 'siguienteNumero':
        $idProyecto = $Rq->proyectoDeCentro($idUsuario, isset($_GET['centro']) ? $_GET['centro'] : 0);
        if (!$idProyecto) { hanaError(400, 'Ese peaje no está asignado a tu usuario.'); }
        echo json_encode(array('numero' => $Rq->siguienteNumero($idProyecto)));
        break;

    //El listado, filtrado por periodo (año y mes)
    case 'listar':
        echo json_encode(array(
            //La vista la elige la pantalla, pero la regla la aplica el servidor
            'filas' => hanaFilas($Rq->listar(hanaCondRQ(isset($_GET['vista']) ? $_GET['vista'] : 'mias'),
                isset($_GET['proyecto']) ? $_GET['proyecto'] : 0,
                isset($_GET['centro'])   ? $_GET['centro']   : 0,
                isset($_GET['anio'])     ? $_GET['anio']     : 0,
                isset($_GET['mes'])      ? $_GET['mes']      : 0))
        ), JSON_UNESCAPED_UNICODE);
        break;

    //Una RQ completa: cabecera, ítems, historial, archivos y a qué estados puede pasar
    case 'mostrar':
        $idRq = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $cab = hanaFila($Rq->mostrar(hanaCondRQ('todas'), $idRq));
        if (!$cab) { hanaError(404, 'No se encontró la RQ, o no pertenece a tus peajes.'); }

        $archivos = hanaFilas($Rq->soportes($idRq));
        foreach ($archivos as $i => $a) { $archivos[$i]['URL'] = RUTA_WEB_RQ . $a['RUTA']; }

        $acc = hanaAccionesRQ($cab, $idColaborador, $puedeAprobar);
        echo json_encode(array(
            'rq'          => $cab,
            'items'       => hanaFilas($Rq->detalle($idRq)),
            'historial'   => hanaFilas($Rq->seguimiento($idRq)),
            'archivos'    => $archivos,
            //Aprobar o rechazar: solo con el permiso 18M y mientras esté en "Solicitada"
            'siguientes'  => ((int)$cab['ESTADO'] === 1) ? $Rq->estadosPermitidos($cab['ID_RQ_ESTADO'], $puedeAprobar) : array(),
            'puedeEditar' => $acc['editar'],
            'puedeAnular' => $acc['anular'],
            'retrocede'   => $acc['retrocede']
        ), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Crear una RQ
    //-----------------------------------------------------------------------
    case 'guardar':
        if (!$puedePedir) { hanaError(403, 'Pedir RQ necesita la casilla 15M (Requisiciones) en tu rol.'); }
        $idCentro = isset($_POST['centro']) ? intval($_POST['centro']) : 0;
        $idProyecto = $Rq->proyectoDeCentro($idUsuario, $idCentro);
        if (!$idProyecto) { hanaError(400, 'Elige un peaje de los que tienes asignados.'); }

        //Fecha: la que se escriba, si es válida y no es futura; si no, hoy
        $fecha = isset($_POST['fecha']) ? trim($_POST['fecha']) : '';
        $partes = explode('-', $fecha);
        if (count($partes) !== 3 || !checkdate((int)$partes[1], (int)$partes[2], (int)$partes[0])) {
            $fecha = date('Y-m-d');
        } elseif ($fecha > date('Y-m-d')) {
            hanaError(400, 'La fecha de la RQ no puede ser posterior a hoy.');
        }

        //Número: si se escribe, se respeta (sirve para pasar las RQ del Excel con
        //su número original); si no, se toma el siguiente del proyecto
        $numero = isset($_POST['numero']) ? trim($_POST['numero']) : '';
        if ($numero === '') {
            $numero = $Rq->siguienteNumero($idProyecto);
        } else {
            //Letras, números y guion (por ejemplo 230, 230A o ICCU-15), hasta 20 caracteres
            $numero = strtoupper($numero);
            if (!preg_match('/^[A-Z0-9][A-Z0-9-]{0,19}$/', $numero)) { hanaError(400, 'El número de RQ solo puede tener letras, números y guion (máximo 20), por ejemplo 230A.'); }
            if ($Rq->existeNumero($idProyecto, $numero)) {
                hanaError(400, "Ya existe la RQ-$numero en este proyecto. Deja el número vacío para que se asigne solo.");
            }
        }

        //Los ítems llegan como arreglos paralelos: descripcion[], justificacion[]...
        $desc  = isset($_POST['descripcion'])   ? (array)$_POST['descripcion']   : array();
        $just  = isset($_POST['justificacion']) ? (array)$_POST['justificacion'] : array();
        $cant  = isset($_POST['cantidad'])      ? (array)$_POST['cantidad']      : array();
        $unid  = isset($_POST['unidad'])        ? (array)$_POST['unidad']        : array();
        $sop   = isset($_POST['soporte'])       ? (array)$_POST['soporte']       : array();

        $items = array();
        foreach ($desc as $i => $d) {
            if (trim($d) === '') { continue; } //fila vacía: se ignora
            if ((function_exists('mb_strlen') ? mb_strlen(trim($d), 'UTF-8') : strlen(trim($d))) > 255) { hanaError(400, 'La descripción del ítem ' . ($i + 1) . ' es muy larga (máximo 255 caracteres).'); }
            $items[] = array('d' => $d,
                             'j' => isset($just[$i]) ? $just[$i] : '',
                             'c' => isset($cant[$i]) ? $cant[$i] : 1,
                             'u' => isset($unid[$i]) ? $unid[$i] : 'Unidad',
                             's' => isset($sop[$i])  ? $sop[$i]  : '');
        }
        if (count($items) === 0) { hanaError(400, 'Agrega al menos un ítem con su descripción.'); }

        //Normal o urgente. Ya no se elige rol a notificar: la RQ le llega a
        //quien tenga el permiso de aprobar
        $tipo        = hanaTipoRQ(isset($_POST['tipo']) ? $_POST['tipo'] : 'N');
        $observacion = isset($_POST['sst']) ? $_POST['sst'] : '';

        //--- Archivos: se validan TODOS antes de guardar nada ---
        $aSubir = array();
        if (!empty($_FILES['archivos']) && is_array($_FILES['archivos']['name'])) {
            $n = count($_FILES['archivos']['name']);
            if ($n > MAX_ARCHIVOS) { hanaError(400, 'Puedes adjuntar máximo ' . MAX_ARCHIVOS . ' archivos.'); }
            for ($i = 0; $i < $n; $i++) {
                if ($_FILES['archivos']['error'][$i] === UPLOAD_ERR_NO_FILE) { continue; }
                $nom = $_FILES['archivos']['name'][$i];
                if ($_FILES['archivos']['error'][$i] !== UPLOAD_ERR_OK) { hanaError(400, "No se pudo recibir \"$nom\". Intenta de nuevo."); }
                if ($_FILES['archivos']['size'][$i] > MAX_BYTES) { hanaError(400, "\"$nom\" pesa más de 8 MB."); }
                $ext = hanaTipoArchivo($_FILES['archivos']['tmp_name'][$i]);
                if ($ext === '') { hanaError(400, "\"$nom\" no es una imagen ni un PDF."); }
                $aSubir[] = array('tmp' => $_FILES['archivos']['tmp_name'][$i], 'nombre' => $nom, 'ext' => $ext);
            }
        }
        if (count($aSubir) > 0 && !hanaPrepararCarpeta()) {
            hanaError(500, 'El servidor no permite guardar archivos en public/rq/. Revisa los permisos de esa carpeta.');
        }

        //--- Todo o nada: si algo falla, no queda una RQ a medias ---
        $movidos = array();
        $Rq->iniciar();
        $idRq = $Rq->insertar($numero, $idProyecto, $idCentro, $fecha, $idColaborador, $observacion, $tipo);
        $ok = $idRq > 0;

        foreach ($items as $it) {
            if (!$ok) { break; }
            $ok = $Rq->insertarDetalle($idRq, $it['d'], $it['j'], $it['c'], $it['u'], $it['s']) > 0;
        }
        if ($ok) { $ok = $Rq->insertarSeguimiento($idRq, null, 1, $idColaborador, 'RQ registrada') > 0; }

        foreach ($aSubir as $a) {
            if (!$ok) { break; }
            //Nombre al azar: el original no se usa en el disco, así no se puede adivinar
            $destino = 'rq' . $idRq . '_' . bin2hex(random_bytes(10)) . '.' . $a['ext'];
            if (move_uploaded_file($a['tmp'], CARPETA_RQ . $destino)) {
                $movidos[] = CARPETA_RQ . $destino;
                $tipo = ($a['ext'] === 'pdf') ? 'soporte' : 'evidencia';
                $ok = $Rq->insertarSoporte($idRq, $destino, $a['nombre'], $tipo) > 0;
            } else {
                $ok = false;
            }
        }

        if (!$ok) {
            $Rq->deshacer();
            foreach ($movidos as $m) { @unlink($m); } //no quedan archivos huérfanos
            hanaError(500, 'No se pudo guardar la RQ. No quedó nada registrado; intenta de nuevo.');
        }
        $Rq->confirmar();

        $mensaje = hanaEtiquetaRQ($tipo, $numero) . ' registrada con éxito. Quedó pendiente de aprobación.';
        echo json_encode(array('ok' => true, 'id' => $idRq, 'numero' => $numero, 'mensaje' => $mensaje),
                         JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Cambiar de estado
    //-----------------------------------------------------------------------
    case 'cambiarEstado':
        //Se revisa en el servidor: no basta con ocultar el botón, porque la
        //petición se puede enviar a mano
        if (!$puedeAprobar) { hanaError(403, 'No permitido: tu rol no aprueba RQ.'); }
        $idRq  = isset($_POST['id'])     ? intval($_POST['id'])     : 0;
        $nuevo = isset($_POST['estado']) ? intval($_POST['estado']) : 0;
        $obs   = isset($_POST['observacion']) ? trim($_POST['observacion']) : '';

        $cab = $Rq->mostrar(hanaCondRQ('todas'), $idRq);
        if (!$cab) { hanaError(404, 'No se encontró la RQ, o no pertenece a tus peajes.'); }
        if ((int)$cab['ESTADO'] !== 1) { hanaError(400, 'Esta RQ está anulada.'); }

        //Solo se acepta un estado de los permitidos: nadie puede saltarse pasos
        $valido = false;
        foreach ($Rq->estadosPermitidos($cab['ID_RQ_ESTADO'], $puedeAprobar) as $p) {
            if ((int)$p['ID_RQ_ESTADO'] === $nuevo) { $valido = true; break; }
        }
        if (!$valido) { hanaError(400, 'Esta RQ ya no está pendiente de aprobación.'); }
        if ($nuevo === Rq::RECHAZADA && $obs === '') { hanaError(400, 'Para rechazar una RQ hay que explicar el motivo.'); }

        $Rq->iniciar();
        $ok = $Rq->cambiarEstado($idRq, $nuevo)
           && $Rq->insertarSeguimiento($idRq, $cab['ID_RQ_ESTADO'], $nuevo, $idColaborador, $obs) > 0;
        if (!$ok) { $Rq->deshacer(); hanaError(500, 'No se pudo cambiar el estado. Intenta de nuevo.'); }
        $Rq->confirmar();
        echo json_encode(array('ok' => true, 'mensaje' => ($nuevo === Rq::APROBADA ? 'RQ aprobada.' : 'RQ rechazada.')),
                         JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Editar
    //   - En "Solicitada": la corrige quien la pidió o quien aprueba.
    //   - En "Rechazada": quien la pidió la corrige y vuelve a "Solicitada",
    //     para que se apruebe de nuevo.
    //   - Aprobada o anulada: ya no se edita.
    //-----------------------------------------------------------------------
    case 'editar':
        $idRq   = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $motivo = isset($_POST['motivo']) ? trim($_POST['motivo']) : '';

        $cab = $Rq->mostrar(hanaCondRQ('todas'), $idRq);
        if (!$cab) { hanaError(404, 'No se encontró la RQ, o no tienes permiso para verla.'); }
        $acc = hanaAccionesRQ($cab, $idColaborador, $puedeAprobar);
        if (!$acc['editar']) { hanaError(403, 'Esta RQ solo la puede modificar quien la subió, mientras esté pendiente o rechazada.'); }

        $retrocede = $acc['retrocede'];
        if ($retrocede && $motivo === '') {
            hanaError(400, 'Para enviar de nuevo la RQ hay que explicar qué se corrigió.');
        }

        $fecha = isset($_POST['fecha']) ? trim($_POST['fecha']) : '';
        $p = explode('-', $fecha);
        if (count($p) !== 3 || !checkdate((int)$p[1], (int)$p[2], (int)$p[0])) { hanaError(400, 'La fecha no es válida.'); }
        if ($fecha > date('Y-m-d')) { hanaError(400, 'La fecha de la RQ no puede ser posterior a hoy.'); }

        $desc = isset($_POST['descripcion'])   ? (array)$_POST['descripcion']   : array();
        $just = isset($_POST['justificacion']) ? (array)$_POST['justificacion'] : array();
        $cant = isset($_POST['cantidad'])      ? (array)$_POST['cantidad']      : array();
        $unid = isset($_POST['unidad'])        ? (array)$_POST['unidad']        : array();
        $sop  = isset($_POST['soporte'])       ? (array)$_POST['soporte']       : array();
        $items = array();
        foreach ($desc as $i => $d) {
            if (trim($d) === '') { continue; }
            $items[] = array('d' => $d, 'j' => isset($just[$i]) ? $just[$i] : '', 'c' => isset($cant[$i]) ? $cant[$i] : 1,
                             'u' => isset($unid[$i]) ? $unid[$i] : 'Unidad', 's' => isset($sop[$i]) ? $sop[$i] : '');
        }
        if (count($items) === 0) { hanaError(400, 'La RQ debe tener al menos un ítem con su descripción.'); }

        $tipo        = hanaTipoRQ(isset($_POST['tipo']) ? $_POST['tipo'] : $cab['TIPO_RQ']);
        $observacion = isset($_POST['sst']) ? $_POST['sst'] : '';

        //Todo o nada
        $Rq->iniciar();
        $ok = $Rq->actualizarCabecera($idRq, $fecha, $observacion, $tipo) && $Rq->borrarDetalle($idRq);
        foreach ($items as $it) {
            if (!$ok) { break; }
            $ok = $Rq->insertarDetalle($idRq, $it['d'], $it['j'], $it['c'], $it['u'], $it['s']) > 0;
        }
        if ($ok && $retrocede) {
            $ok = $Rq->reiniciarFlujo($idRq)
               && $Rq->insertarSeguimiento($idRq, $cab['ID_RQ_ESTADO'], 1, $idColaborador,
                                           'Corregida y enviada de nuevo: ' . $motivo) > 0
               && $Rq->marcarNoLeida($idRq);
        } elseif ($ok) {
            $ok = $Rq->insertarSeguimiento($idRq, null, 1, $idColaborador,
                                           'RQ corregida' . ($motivo !== '' ? ': ' . $motivo : '')) > 0;
        }
        if (!$ok) { $Rq->deshacer(); hanaError(500, 'No se pudo guardar la corrección. No cambió nada; intenta de nuevo.'); }
        $Rq->confirmar();

        echo json_encode(array('ok' => true, 'mensaje' => ($retrocede
            ? 'La RQ volvió a "Solicitada" con los cambios y quedó pendiente de aprobación otra vez.'
            : 'RQ corregida con éxito.')), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Anular (no se borra: queda en el historial)
    //-----------------------------------------------------------------------
    case 'anular':
        $idRq = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $obs  = isset($_POST['observacion']) ? trim($_POST['observacion']) : '';
        if ($obs === '') { hanaError(400, 'Para anular una RQ hay que explicar el motivo.'); }

        $cab = $Rq->mostrar(hanaCondRQ('todas'), $idRq);
        if (!$cab) { hanaError(404, 'No se encontró la RQ, o no pertenece a tus peajes.'); }
        if ((int)$cab['ESTADO'] !== 1) { hanaError(400, 'Esta RQ ya estaba anulada.'); }
        $acc = hanaAccionesRQ($cab, $idColaborador, $puedeAprobar);
        if (!$acc['anular']) {
            hanaError(403, 'Solo se anula una RQ pendiente, y solo quien la subió.');
        }

        $Rq->iniciar();
        $ok = $Rq->anular($idRq)
           && $Rq->insertarSeguimiento($idRq, $cab['ID_RQ_ESTADO'], 8, $idColaborador, $obs) > 0;
        if (!$ok) { $Rq->deshacer(); hanaError(500, 'No se pudo anular la RQ. Intenta de nuevo.'); }
        $Rq->confirmar();
        echo json_encode(array('ok' => true, 'mensaje' => 'RQ anulada. Queda en el historial.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        hanaError(400, 'Operación no reconocida.');
}
