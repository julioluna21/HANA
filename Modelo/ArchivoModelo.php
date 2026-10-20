<?php
/*
  HANA - Archivos guardados en la base de datos (consulta del límite)
  ---------------------------------------------------------------------------
  Las fotos y documentos se guardan como texto base64 ("data:tipo;base64,...")
  en adjunto.ARCHIVO (RQ, listas de chequeo y comunicaciones) y en las dos
  columnas de foto de novedades_hallazgos.

  Cada archivo viaja a la base en un solo envío, y MariaDB tiene un tamaño
  máximo por envío (max_allowed_packet). Aquí se consulta ese máximo para
  avisar a tiempo si un archivo no cabe.
  Lo usa: Control/ArchivoHelper.php.
*/
require_once __DIR__ . '/HanaDB.php';

class Archivo
{
    //El tamaño máximo que MariaDB acepta en un solo envío (max_allowed_packet), en bytes
    public static function maxPaquete()
    {
        static $max = null;
        if ($max === null) {
            $fila = HanaDB::fila("SELECT @@max_allowed_packet AS M");
            $max = $fila ? (int)$fila['M'] : 0;
        }
        return $max;
    }
}
