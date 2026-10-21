<?php
/*
  HANA - Fotos y documentos guardados en la base de datos
  ---------------------------------------------------------------------------
  Todo archivo que sube la gente (fotos y soportes de las RQ, fotos de las
  novedades, adjuntos de listas de chequeo y de comunicaciones) se guarda
  dentro de la base como texto base64, en la forma "data:tipo;base64,...".
  Ningún archivo se escribe en carpetas del servidor.

  Lo que comparten los controladores:
    hanaArchivoTexto()     convierte el archivo en ese texto. Las fotos muy
                           pesadas se achican antes (lado mayor de 1800 px, en
                           JPEG) para que la base no crezca de más.
    hanaArchivoCabe()      revisa que el texto quepa en un envío a la base
                           (max_allowed_packet de MariaDB).
    hanaArchivoPartes()    saca el tipo y el contenido de ese texto.
    hanaArchivoEntregar()  devuelve el archivo al navegador con su tipo.

  Lo usan: AdjuntoControl.php, RQControl.php y NovedadesControl.php.
*/
require_once __DIR__ . '/../Modelo/ArchivoModelo.php';

//Fotos: desde este peso (o este tamaño) se achican, al lado mayor indicado y en JPEG
define('HANA_FOTO_PESO_MAX', 800 * 1024);
define('HANA_FOTO_LADO_MAX', 2200);
define('HANA_FOTO_LADO', 1800);
define('HANA_FOTO_CALIDAD', 82);

//El tipo real de un archivo, mirando su contenido (no la extensión). '' si no se reconoce
function hanaArchivoMime($bytes)
{
    if (function_exists('finfo_open')) {
        $inspector = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($inspector, $bytes);
        finfo_close($inspector);
        if ($mime) { return $mime; }
    }
    $info = @getimagesizefromstring($bytes);
    if ($info && !empty($info['mime'])) { return $info['mime']; }
    return (substr($bytes, 0, 5) === '%PDF-') ? 'application/pdf' : '';
}

//Gira la foto como la tomó el celular (la marca de orientación se pierde al volverla a guardar)
function hanaFotoDerecha($img, $bytes)
{
    if (!function_exists('exif_read_data')) { return $img; }
    $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
    $orientacion = ($exif && isset($exif['Orientation'])) ? (int)$exif['Orientation'] : 1;
    if ($orientacion === 3) { return imagerotate($img, 180, 0); }
    if ($orientacion === 6) { return imagerotate($img, -90, 0); }
    if ($orientacion === 8) { return imagerotate($img, 90, 0); }
    return $img;
}

//Achica una foto pesada. Devuelve array(bytes, tipo) o null si no hace falta o no se puede
function hanaFotoLiviana($bytes, $mime)
{
    if (!in_array($mime, array('image/jpeg', 'image/png', 'image/webp'), true) || !function_exists('imagecreatefromstring')) { return null; }
    //Sin EXIF no se sabe cómo estaba girada la foto: mejor no tocarla
    if ($mime === 'image/jpeg' && !function_exists('exif_read_data')) { return null; }
    $info = @getimagesizefromstring($bytes);
    if (!$info) { return null; }
    list($ancho, $alto) = $info;
    if (strlen($bytes) <= HANA_FOTO_PESO_MAX && max($ancho, $alto) <= HANA_FOTO_LADO_MAX) { return null; }
    $img = @imagecreatefromstring($bytes);
    if (!$img) { return null; }
    if ($mime === 'image/jpeg') { $img = hanaFotoDerecha($img, $bytes); $ancho = imagesx($img); $alto = imagesy($img); }
    $escala = min(1, HANA_FOTO_LADO / max($ancho, $alto));
    $anchoNuevo = max(1, (int)round($ancho * $escala)); $altoNuevo = max(1, (int)round($alto * $escala));
    $lienzo = imagecreatetruecolor($anchoNuevo, $altoNuevo);
    imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255)); //lo transparente queda blanco
    imagecopyresampled($lienzo, $img, 0, 0, 0, 0, $anchoNuevo, $altoNuevo, $ancho, $alto);
    ob_start();
    imagejpeg($lienzo, null, HANA_FOTO_CALIDAD);
    $nuevo = ob_get_clean();
    imagedestroy($img); imagedestroy($lienzo);
    return ($nuevo !== '' && strlen($nuevo) < strlen($bytes)) ? array($nuevo, 'image/jpeg') : null;
}

//Convierte un archivo en el texto que se guarda en la base.
//Devuelve array('texto' => 'data:...;base64,...', 'tipo' => tipo final, 'tamano' => bytes, 'achicada' => bool)
function hanaArchivoTexto($bytes, $mime)
{
    $achicada = false;
    $liviana = hanaFotoLiviana($bytes, $mime);
    if ($liviana) { list($bytes, $mime) = $liviana; $achicada = true; }
    return array('texto' => 'data:' . $mime . ';base64,' . base64_encode($bytes), 'tipo' => $mime,
                 'tamano' => strlen($bytes), 'achicada' => $achicada);
}

//¿El texto cabe en un solo envío a la base?
function hanaArchivoCabe($texto)
{
    $max = Archivo::maxPaquete();
    return $max <= 0 || strlen($texto) + 4096 <= $max;
}

//Mensaje para un archivo que no cabe: dice hasta cuántos MB se aceptan
function hanaArchivoMuyGrande($nombre)
{
    $megas = max(0.1, round(Archivo::maxPaquete() / 1.37 / 1048576, 1));
    //El límite real (max_allowed_packet de la base) queda en el error_log; a la persona solo se le dice el tamaño
    error_log('HANA - archivo «' . $nombre . '» no cabe: max_allowed_packet permite ~' . $megas . ' MB por archivo');
    return '«' . $nombre . '» es muy pesado (se aceptan hasta ' . $megas . ' MB por archivo). Súbelo más liviano.';
}

//Separa el texto guardado en tipo y contenido. null si no es un texto "data:...;base64,..."
function hanaArchivoPartes($valor)
{
    if (!is_string($valor) || !preg_match('#^data:([a-zA-Z0-9.+/-]+);base64,#', $valor, $partes)) { return null; }
    $bytes = base64_decode(substr($valor, strlen($partes[0])));
    return ($bytes === false) ? null : array($partes[1], $bytes);
}

//Entrega el archivo: en el navegador ($enLinea) o como descarga, con su nombre
function hanaArchivoEntregar($bytes, $mime, $nombre, $enLinea)
{
    $limpio = str_replace(array('"', "\r", "\n"), '', (string)$nombre);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . strlen($bytes));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . $limpio . '"; filename*=UTF-8\'\'' . rawurlencode((string)$nombre));
    echo $bytes;
}

//El nombre con la extensión que corresponde cuando una foto pasó a JPEG (foto.png -> foto.jpg)
function hanaArchivoNombreJpg($nombre)
{
    return preg_match('/\.jpe?g$/i', $nombre) ? $nombre : preg_replace('/(\.[A-Za-z0-9]{1,5})?$/', '.jpg', $nombre, 1);
}
