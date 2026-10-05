<?php
/*
  HANA — Lector de archivos Excel (.xlsx)

  Un .xlsx es en realidad un ZIP con archivos XML adentro. Aqui se abre ese ZIP y
  se leen las celdas directamente, sin instalar ninguna libreria: PHP ya trae todo
  lo necesario (ZipArchive y SimpleXML).

  Se usa en la carga masiva de preguntas. Si el servidor no tiene ZipArchive, la
  funcion avisa y el usuario puede subir el mismo contenido como CSV.

  hanaLeerXlsx($ruta, $nombreHoja)
     Devuelve un arreglo de filas, y cada fila es un arreglo de celdas en texto.
     $nombreHoja: la hoja preferida. Si no existe, se lee la primera.
*/

//Dice si el servidor puede leer archivos .xlsx
function hanaPuedeLeerXlsx()
{
    return class_exists('ZipArchive') && function_exists('simplexml_load_string');
}

//Pasa la referencia de una celda ("B7") al numero de columna (2)
function hanaColumnaDeCelda($ref)
{
    $letras = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $num = 0;
    for ($i = 0; $i < strlen($letras); $i++) {
        $num = $num * 26 + (ord($letras[$i]) - 64);
    }
    return $num > 0 ? $num : 1;
}

function hanaLeerXlsx($ruta, $nombreHoja = '')
{
    if (!hanaPuedeLeerXlsx()) {
        return array('error' => 'Este servidor no puede leer archivos de Excel. Guarda la hoja PREGUNTAS como CSV y sube ese archivo.');
    }

    $zip = new ZipArchive();
    if ($zip->open($ruta) !== true) {
        return array('error' => 'No se pudo abrir el archivo de Excel. Puede estar dañado.');
    }

    //--- Textos compartidos: Excel guarda aparte las cadenas que se repiten ---
    $textos = array();
    $xmlTextos = $zip->getFromName('xl/sharedStrings.xml');
    if ($xmlTextos !== false) {
        $sx = @simplexml_load_string($xmlTextos);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                //Una celda puede venir partida en varios trozos con distinto formato
                $texto = '';
                if (isset($si->t)) {
                    $texto = (string)$si->t;
                } else {
                    foreach ($si->r as $r) { $texto .= (string)$r->t; }
                }
                $textos[] = $texto;
            }
        }
    }

    //--- Que hojas tiene el libro y en que archivo esta cada una ---
    $relaciones = array();
    $xmlRels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($xmlRels !== false) {
        $sx = @simplexml_load_string($xmlRels);
        if ($sx !== false) {
            foreach ($sx->Relationship as $rel) {
                $relaciones[(string)$rel['Id']] = (string)$rel['Target'];
            }
        }
    }

    $hojas = array(); //nombre => archivo interno
    $xmlLibro = $zip->getFromName('xl/workbook.xml');
    if ($xmlLibro !== false) {
        $sx = @simplexml_load_string($xmlLibro);
        if ($sx !== false) {
            foreach ($sx->sheets->sheet as $hoja) {
                $id = '';
                foreach ($hoja->attributes('r', true) as $clave => $valor) {
                    if ($clave === 'id') { $id = (string)$valor; }
                }
                $destino = isset($relaciones[$id]) ? $relaciones[$id] : '';
                if ($destino === '') { continue; }
                //Las rutas vienen como "worksheets/sheet1.xml" o "/xl/worksheets/sheet1.xml"
                $destino = ltrim($destino, '/');
                if (strpos($destino, 'xl/') !== 0) { $destino = 'xl/' . $destino; }
                $hojas[(string)$hoja['name']] = $destino;
            }
        }
    }

    if (count($hojas) === 0) {
        $zip->close();
        return array('error' => 'El archivo de Excel no tiene ninguna hoja que se pueda leer.');
    }

    //--- Se elige la hoja: la que se pide, y si no existe, la primera ---
    $archivoHoja = '';
    if ($nombreHoja !== '') {
        foreach ($hojas as $nombre => $archivo) {
            if (strcasecmp(trim($nombre), trim($nombreHoja)) === 0) { $archivoHoja = $archivo; break; }
        }
    }
    if ($archivoHoja === '') {
        $primeros = array_values($hojas);
        $archivoHoja = $primeros[0];
    }

    //--- Las celdas de la hoja elegida ---
    $xmlHoja = $zip->getFromName($archivoHoja);
    $zip->close();
    if ($xmlHoja === false) {
        return array('error' => 'No se pudo leer la hoja del archivo de Excel.');
    }

    $sx = @simplexml_load_string($xmlHoja);
    if ($sx === false) {
        return array('error' => 'La hoja del archivo de Excel no se pudo interpretar.');
    }

    $filas = array();
    foreach ($sx->sheetData->row as $fila) {
        $celdas = array();
        foreach ($fila->c as $celda) {
            $tipo = isset($celda['t']) ? (string)$celda['t'] : '';
            $valor = '';

            if ($tipo === 's') {
                //Texto compartido: el valor es la posicion dentro de la lista
                $pos = (int)$celda->v;
                $valor = isset($textos[$pos]) ? $textos[$pos] : '';
            } elseif ($tipo === 'inlineStr') {
                $valor = isset($celda->is->t) ? (string)$celda->is->t : '';
            } elseif (isset($celda->v)) {
                $valor = (string)$celda->v;
            }

            //Se respeta la columna real: si la celda A esta vacia, Excel no la escribe
            $col = hanaColumnaDeCelda(isset($celda['r']) ? (string)$celda['r'] : '');
            $celdas[$col - 1] = trim($valor);
        }

        if (count($celdas) === 0) { $filas[] = array(); continue; }

        //Se completan los huecos para que las columnas queden alineadas
        $maxCol = max(array_keys($celdas));
        $completa = array();
        for ($i = 0; $i <= $maxCol; $i++) {
            $completa[] = isset($celdas[$i]) ? $celdas[$i] : '';
        }
        $filas[] = $completa;
    }

    return array('filas' => $filas, 'hojas' => array_keys($hojas));
}