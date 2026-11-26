<?php
/*
  HANA - Reporte diario de la Dirección Operativa en Word (.docx)
  ---------------------------------------------------------------------------
  Arma el mismo informe del PDF (la carta, la promesa de valor, los avances
  por proyecto, las gestiones transversales, el estado de vacantes y la firma)
  como un documento de Word que se puede abrir y seguir editando: cada texto
  es un párrafo normal y cada tabla una tabla de Word. Recibe el mismo $reporte
  que arma Control/ReporteGerenciaPdfControl.php.

  La hoja membretada de Regency (public/img/membrete_regency.png) va en el
  encabezado como imagen detrás del texto, de borde a borde, en todas las
  hojas: el logo, el logo de fondo y los datos de la empresa quedan en su
  sitio aunque se agregue o se quite texto.

  Un .docx es un ZIP con archivos XML adentro. El ZIP se escribe aquí mismo
  (clase ZipSencillo, al final), así no depende de que el servidor tenga
  activada la extensión zip de PHP.

  Uso:
    $word = new ReporteGerenciaWord($reporte, $quien);
    $bytes = $word->armar();
*/
//Del PDF se usan el nombre del archivo y cómo se reconoce la etiqueta en negrilla de un renglón
require_once __DIR__ . '/../pdf/ReporteGerenciaPdf.php';

class ReporteGerenciaWord
{
    //Colores (los mismos del PDF), en hexadecimal
    const BORDO = '7C2822';
    const MENTA = '1AA082';
    const AMBAR = 'B07814';
    const NEGRO = '141414';
    const GRIS = '64666A';
    const FONDO = 'FAF7F6';
    const BARRA = 'E8DEDC';
    const AZUL_OSCURO = '17365D';
    const AZUL = '1F4E78';
    const AZUL_FILA = 'F3F6FA';
    const BORDE = 'BFBFBF';
    const VERDE_DIAS = 'E2F0D9';
    const NARANJA_DIAS = 'FCE4D6';
    const ROJO_DIAS = 'F4CCCC';

    //Ancho útil de la hoja carta con márgenes de una pulgada a los lados, en veinteavos de punto (twips)
    const ANCHO = 9360;

    private $r;
    private $quien;
    private $cuerpo = '';
    private $hayMembrete = false;

    public function __construct($reporte, $quien)
    {
        $this->r = $reporte;
        $this->quien = (string)$quien;
    }

    //REPORTE_DIARIO_20_DE_NOVIEMBRE_2026.docx (el mismo nombre del PDF)
    public static function nombreArchivo($fecha, $desde = null) { return ReporteGerenciaPdf::nombreBase($fecha, $desde) . '.docx'; }

    //Las palabras que cambian si el reporte es de un día o de un rango de días
    private function periodo($deUnDia, $deRango) { return !empty($this->r['esRango']) ? $deRango : $deUnDia; }

    //=======================================================================
    // El documento
    //=======================================================================
    public function armar()
    {
        $c = $this->r['contenido'];
        $e = $c['encabezado'];
        $n = 0;

        //La carta: título, corte, destinatario, saludo y presentación
        $this->parrafo($this->texto($this->r['titulo'], array('b' => true, 'sz' => 21)), array('despues' => 0));
        $this->parrafo($this->texto($this->r['subtitulo'], array('b' => true, 'sz' => 21)), array('despues' => 240));
        $this->parrafo($this->texto($this->r['fechaInforme'], array('b' => true)), array('despues' => 240));
        $destinatario = trim($e['trato'] . ' ' . $e['destinatario']);
        if ($destinatario !== '') { $this->parrafo($this->texto('Dirigido a: ', array('b' => true)) . $this->texto($destinatario), array('despues' => 0)); }
        if ($e['cargo'] !== '') { $this->parrafo($this->texto($e['cargo']), array('despues' => 0)); }
        $this->parrafo($this->texto('Cordial saludo:'), array('antes' => 240, 'despues' => 240));
        foreach (preg_split('/\n+/u', (string)$e['presentacion']) as $parrafo) {
            if (trim($parrafo) !== '') { $this->parrafo($this->texto(trim($parrafo)), array('justificado' => true, 'despues' => 120)); }
        }

        //1. Promesa de valor: el resumen en negrilla, la cifra con la gráfica y lo de cada peaje
        $this->seccion((++$n) . '. ' . $this->r['secciones']['resultados']);
        $this->parrafo($this->texto($this->r['tituloPromesa'], array('b' => true)), array('despues' => 60, 'conSiguiente' => true));
        $renglones = preg_split('/\n/u', trim((string)(isset($this->r['resultadosTexto']) ? $this->r['resultadosTexto'] : $c['resultados'])));
        $resumen = trim((string)array_shift($renglones));
        $this->parrafo($this->texto($resumen !== '' ? $resumen : 'Sin información de la promesa de valor.', array('b' => true)), array('justificado' => true, 'despues' => 60));
        $this->cifraPromesa();
        $this->bloque(implode("\n", $renglones), 0);

        //2. Avances y gestión por proyecto: las gestiones prioritarias y luego un proyecto por número
        $this->seccion((++$n) . '. ' . $this->r['secciones']['proyectos']);
        $hay = false;
        if (count($c['prioritarias'])) {
            $hay = true;
            $this->subtitulo($this->r['secciones']['prioritarias'] . ':');
            foreach ($c['prioritarias'] as $a) {
                $proyecto = $this->nombreProyecto($a['proyecto']);
                $this->vinetaApartado(($proyecto !== '' ? $proyecto . ' – ' : '') . $a['titulo'], $a['texto']);
            }
        }
        $k = 0;
        foreach ($c['proyectos'] as $id => $p) {
            if (!count($p['apartados'])) { continue; }
            $hay = true;
            $this->subtitulo($n . '.' . (++$k) . '. ' . mb_strtoupper($this->nombreProyecto($id), 'UTF-8'), 160);
            foreach ($p['apartados'] as $a) { $this->vinetaApartado($a['titulo'], $a['texto']); }
        }
        if (!$hay) { $this->vacio($this->periodo('Sin avances de gestión registrados en la fecha.', 'Sin avances de gestión registrados en el periodo.')); }

        //3. Gestiones transversales (si hay): cada una con su título en negrilla y su texto debajo
        if (count($c['transversal'])) {
            $this->seccion((++$n) . '. ' . $this->r['secciones']['transversal']);
            foreach ($c['transversal'] as $a) {
                $proyecto = $this->nombreProyecto($a['proyecto']);
                $titulo = trim($a['titulo'] . ($proyecto !== '' ? ' – ' . $proyecto : ''));
                if ($titulo !== '') { $this->subtitulo($titulo, 120); }
                $this->bloque($a['texto'], 0);
            }
        }

        //Avance de los planes de trabajo (si hay)
        if (!empty($c['planes'])) {
            $this->seccion((++$n) . '. ' . $this->r['secciones']['planes']);
            $filas = array();
            foreach ($c['planes'] as $p) {
                $diferencia = $p['programado'] !== null && $p['real'] !== null ? $p['real'] - $p['programado'] : null;
                $vencido = $p['fecha'] && $p['fecha'] < $this->r['fecha'] && ($p['real'] === null || $p['real'] < 100);
                $filas[] = array('celdas' => array(mb_strtoupper($p['proyecto'], 'UTF-8'), $p['entregable'], self::pct($p['programado']), self::pct($p['real']),
                                                   $diferencia === null ? '—' : ($diferencia > 0 ? '+' : '') . self::pct($diferencia),
                                                   $p['fecha'] ? date('d/m/Y', strtotime($p['fecha'])) . ($vencido ? ' (vencida)' : '') : ''),
                                 'fondos' => array(4 => $diferencia === null ? null : ($diferencia < 0 ? self::ROJO_DIAS : self::VERDE_DIAS)),
                                 'colores' => $vencido ? array(5 => self::BORDO) : array());
            }
            $this->tabla(array(array('PROYECTO', 1100, 'C'), array('ENTREGABLE', 3000, 'L'), array('PROGRAMADO', 1500, 'C'), array('REAL', 900, 'C'),
                               array('DIFERENCIA', 1320, 'C'), array('FECHA COMPROMETIDA', 1540, 'C')), $filas, 16);
        }

        //Compromisos y seguimiento (si hay): asunto, qué se hace, quién responde, para cuándo y en qué va
        if (count($c['compromisos'])) {
            $this->seccion((++$n) . '. ' . $this->r['secciones']['compromisos']);
            $filas = array();
            foreach ($c['compromisos'] as $compromiso) {
                list($estado, $clase) = ReporteGerencia::estadoCompromiso($compromiso, $this->r['fecha']);
                $proyecto = $this->nombreProyecto($compromiso['proyecto']);
                $fondo = $clase === 'vencido' ? self::ROJO_DIAS : ($clase === 'cumplido' ? self::VERDE_DIAS : ($clase === 'sinfecha' || $clase === 'hoy' ? self::NARANJA_DIAS : null));
                $filas[] = array('celdas' => array($proyecto !== '' ? mb_strtoupper($proyecto, 'UTF-8') : 'GENERAL',
                                                   $compromiso['gestion'] . (isset($compromiso['detalle']) && $compromiso['detalle'] !== '' ? "\n" . $compromiso['detalle'] : ''),
                                                   $compromiso['accion'] !== '' ? $compromiso['accion'] : 'Sin acción registrada',
                                                   $compromiso['responsable'] !== '' ? $compromiso['responsable'] : 'Sin responsable',
                                                   $compromiso['fecha'] ? date('d/m/Y', strtotime($compromiso['fecha'])) : '', $estado),
                                 'fondos' => array(5 => $fondo));
            }
            $this->tabla(array(array('PROYECTO', 1120, 'C'), array('ASUNTO', 2160, 'L'), array('QUÉ SE ESTÁ HACIENDO', 2680, 'L'), array('RESPONSABLE', 1300, 'L'),
                               array('FECHA', 1100, 'C'), array('ESTADO', 1000, 'C')), $filas, 15);
        }

        //Asuntos que requieren decisión de la Gerencia (si hay)
        if (!empty($c['escalamientos'])) {
            $this->seccion((++$n) . '. ' . $this->r['secciones']['escalamientos']);
            foreach ($c['escalamientos'] as $x) {
                $proyecto = $this->nombreProyecto($x['proyecto']);
                $this->subtitulo(($proyecto !== '' ? mb_strtoupper($proyecto, 'UTF-8') . ' – ' : '') . ($x['asunto'] !== '' ? $x['asunto'] : 'Asunto'), 120);
                foreach (array('impacto' => 'Impacto contractual:', 'gestiones' => 'Gestiones adelantadas:', 'decision' => 'Decisión requerida:') as $clave => $etiqueta) {
                    if (trim($x[$clave]) === '') { continue; }
                    $this->vineta($this->texto($etiqueta . ' ', array('b' => true)) . $this->texto(preg_replace('/\s*\n\s*/u', ' ', $x[$clave])), 360, '•');
                }
            }
        }

        //Estado de vacantes
        $this->vacantes(is_array($c['vacantes']) ? $c['vacantes'] : array(), isset($c['notaVacantes']) ? $c['notaVacantes'] : '');

        //Firma
        $this->parrafo($this->texto('Atentamente,'), array('antes' => 360, 'despues' => 720, 'conSiguiente' => true));
        if ($e['firma'] !== '') { $this->parrafo($this->texto($e['firma'], array('b' => true)), array('despues' => 0, 'conSiguiente' => true)); }
        if ($e['firmaCargo'] !== '') { $this->parrafo($this->texto($e['firmaCargo']), array('despues' => 0)); }

        return $this->empacar();
    }

    //=======================================================================
    // Partes del informe
    //=======================================================================
    private function nombreProyecto($id) { return isset($this->r['nombres'][(int)$id]) ? (string)$this->r['nombres'][(int)$id] : ''; }

    //«1. PROMESA DE VALOR»
    private function seccion($titulo)
    {
        $this->parrafo($this->texto($titulo, array('b' => true, 'sz' => 21)), array('antes' => 280, 'despues' => 80, 'conSiguiente' => true));
    }

    //Un subtítulo en negrilla («Gestiones prioritarias:», «2.1. ACCENORTE»)
    private function subtitulo($texto, $antes = 80)
    {
        $this->parrafo($this->texto($texto, array('b' => true)), array('antes' => $antes, 'despues' => 60, 'conSiguiente' => true));
    }

    private function vacio($texto)
    {
        $this->parrafo($this->texto($texto, array('i' => true, 'color' => self::GRIS)));
    }

    //«Etiqueta:» en negrilla y el resto normal (ReporteGerenciaPdf::etiqueta dice dónde se parte)
    private function conEtiqueta($renglon)
    {
        list($etiqueta, $resto) = ReporteGerenciaPdf::etiqueta(trim($renglon));
        return ($etiqueta !== '' ? $this->texto($etiqueta . ' ', array('b' => true)) : '') . $this->texto($resto);
    }

    //Un texto escrito por la dirección, renglón por renglón, como en el PDF: «•» viñeta, «–» viñeta de
    //segundo nivel, «Algo:» solo es un subtítulo y «Algo: el resto» lleva «Algo:» en negrilla.
    //$sangria: donde empieza el texto normal (twips)
    private function bloque($texto, $sangria)
    {
        foreach (preg_split('/\n/u', (string)$texto) as $renglon) {
            $renglon = rtrim($renglon);
            if (trim($renglon) === '') { continue; }
            if (preg_match('/^\s*[•\-\*]\s+(.*)$/u', $renglon, $m) && !preg_match('/^\s{2,}/u', $renglon)) {
                $this->vineta($this->conEtiqueta($m[1]), $sangria + 360, '•');
            } elseif (preg_match('/^\s*[–—]\s+(.*)$/u', $renglon, $m) || preg_match('/^\s{2,}[•\-\*]\s+(.*)$/u', $renglon, $m)) {
                $this->vineta($this->conEtiqueta($m[1]), $sangria + 720, '–');
            } elseif (preg_match('/^[^:]{1,90}:$/u', trim($renglon))) {
                $this->parrafo($this->texto(trim($renglon), array('b' => true)), array('sangria' => $sangria, 'antes' => 60, 'despues' => 40, 'conSiguiente' => true));
            } else {
                $this->parrafo($this->conEtiqueta($renglon), array('justificado' => true, 'despues' => 60, 'sangria' => $sangria));
            }
        }
    }

    //Un renglón con viñeta; $sangria: donde empieza el texto (la viñeta va un poco antes)
    private function vineta($runs, $sangria, $marca)
    {
        $this->parrafo($this->texto($marca . "\t", $marca === '–' ? array('color' => self::GRIS) : array()) . $runs,
                       array('justificado' => true, 'despues' => 60, 'sangria' => $sangria, 'colgante' => 220, 'tab' => $sangria));
    }

    //Un apartado como viñeta: «• Título: el primer renglón del texto» y, debajo, lo demás del texto
    private function vinetaApartado($titulo, $texto)
    {
        $renglones = preg_split('/\n/u', trim((string)$texto));
        $primero = isset($renglones[0]) ? trim($renglones[0]) : '';
        $titulo = trim((string)$titulo);
        $junto = $primero !== '' && !preg_match('/^[•\-\*–—]\s+/u', $primero);
        $etiqueta = $titulo === '' ? '' : (preg_match('/[:.?!]$/u', $titulo) ? $titulo : $titulo . ':');
        $this->vineta(($etiqueta !== '' ? $this->texto($etiqueta . ' ', array('b' => true)) : '') . ($junto ? $this->texto($primero) : ''), 360, '•');
        $resto = $junto ? array_slice($renglones, 1) : $renglones;
        if (count($resto)) {
            $resto = array_map(function ($r) { return preg_replace('/^\s*[•\-\*]\s+/u', '– ', $r); }, $resto);
            $this->bloque(implode("\n", $resto), 360);
        }
    }

    //La cifra de la promesa (del texto) y, al lado, el cumplimiento de cada proyecto en barras, como en el
    //PDF. Con un solo proyecto va solo la cifra (la gráfica la repetiría)
    private function cifraPromesa()
    {
        $p = $this->r['promesa'];
        $proyectos = isset($this->r['promesaProyectos']) ? $this->r['promesaProyectos'] : array();
        if ($p['pct'] === null && !count($proyectos)) { return; }
        $color = self::colorPromesa($p['pct']);
        $detalle = $p['peajes'] ? $p['cumplen'] . ' de ' . $p['peajes'] . ' ' . ReporteGerencia::centros($p['peajes']) . ((int)$p['peajes'] === 1 ? ' cumplió' : ' cumplieron') . ' el 100 % de las horas en punto de cobro'
                 : ($p['pct'] !== null ? 'Cumplimiento reportado' : 'Sin promesa de valor con lo ejecutado registrado');
        $tarjeta = $this->parrafo($this->texto('CUMPLIMIENTO REAL DE HORAS', array('b' => true, 'sz' => 14, 'color' => self::GRIS)),
                                  array('antes' => 100, 'despues' => 20), true) .
                   $this->parrafo($this->texto(self::pct($p['pct']), array('b' => true, 'sz' => 48, 'color' => $color)), array('despues' => 20), true) .
                   $this->parrafo($this->texto($detalle, array('sz' => 15)), array('despues' => 100), true);
        $conGrafica = count($proyectos) > 1;
        $anchoT = $conGrafica ? 2600 : self::ANCHO;
        $celda = function ($ancho, $contenido, $fondo, $arriba = null) {
            return '<w:tc><w:tcPr><w:tcW w:w="' . (int)$ancho . '" w:type="dxa"/>' .
                   ($arriba ? '<w:tcBorders><w:top w:val="single" w:sz="24" w:space="0" w:color="' . $arriba . '"/></w:tcBorders>' : '') .
                   ($fondo ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fondo . '"/>' : '') . '<w:vAlign w:val="top"/></w:tcPr>' . $contenido . '</w:tc>';
        };
        $grilla = '<w:gridCol w:w="' . $anchoT . '"/>' . ($conGrafica ? '<w:gridCol w:w="160"/><w:gridCol w:w="' . (self::ANCHO - $anchoT - 160) . '"/>' : '');
        $fila = $celda($anchoT, $tarjeta, self::suave($color), $color);
        if ($conGrafica) { $fila .= $celda(160, '<w:p/>', null) . $celda(self::ANCHO - $anchoT - 160, $this->graficaProyectos($proyectos, self::ANCHO - $anchoT - 160 - 220), self::FONDO); }
        $this->cuerpo .= '<w:tbl><w:tblPr><w:tblW w:w="' . self::ANCHO . '" w:type="dxa"/><w:tblInd w:w="0" w:type="dxa"/>' .
                         '<w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>' .
                         '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="110" w:type="dxa"/><w:right w:w="110" w:type="dxa"/></w:tblCellMar></w:tblPr>' .
                         '<w:tblGrid>' . $grilla . '</w:tblGrid><w:tr><w:trPr><w:cantSplit/></w:trPr>' . $fila . '</w:tr></w:tbl>';
        $this->parrafo('', array('despues' => 60));
    }

    //«Cumplimiento por proyecto»: cada proyecto con su barra y su rótulo («96,6 % · 1 de 3 centros…»).
    //$proyectos: [nombre, porcentaje o null, nota, rótulo]. La barra es una tablita de dos celdas: la
    //coloreada mide el porcentaje (hasta el 100 %) y la otra el resto
    private function graficaProyectos($proyectos, $ancho)
    {
        $anchoNombre = 1350; $anchoRotulo = 2600; $anchoBarra = max(700, $ancho - $anchoNombre - $anchoRotulo);
        $xml = $this->parrafo($this->texto('Cumplimiento por proyecto', array('b' => true, 'sz' => 16)), array('antes' => 100, 'despues' => 80), true);
        $xml .= '<w:tbl><w:tblPr><w:tblW w:w="' . ($anchoNombre + $anchoBarra + $anchoRotulo) . '" w:type="dxa"/><w:tblInd w:w="0" w:type="dxa"/>' .
                '<w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>' .
                '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="40" w:type="dxa"/><w:right w:w="40" w:type="dxa"/></w:tblCellMar></w:tblPr>' .
                '<w:tblGrid><w:gridCol w:w="' . $anchoNombre . '"/><w:gridCol w:w="' . $anchoBarra . '"/><w:gridCol w:w="' . $anchoRotulo . '"/></w:tblGrid>';
        foreach ($proyectos as $p) {
            $pct = $p[1];
            $color = self::colorPromesa($pct);
            $lleno = $pct === null ? 0 : (int)round(($anchoBarra - 80) * max(0, min(100, (float)$pct)) / 100);
            $vacio = ($anchoBarra - 80) - $lleno;
            $partes = ($lleno > 0 ? '<w:gridCol w:w="' . $lleno . '"/>' : '') . ($vacio > 0 ? '<w:gridCol w:w="' . $vacio . '"/>' : '');
            $celdaBarra = function ($w, $fondo) { return '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . $fondo . '"/></w:tcPr><w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="150" w:lineRule="exact"/></w:pPr></w:p></w:tc>'; };
            $barra = '<w:tbl><w:tblPr><w:tblW w:w="' . ($anchoBarra - 80) . '" w:type="dxa"/><w:tblInd w:w="0" w:type="dxa"/>' .
                     '<w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>' .
                     '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tblCellMar></w:tblPr>' .
                     '<w:tblGrid>' . $partes . '</w:tblGrid><w:tr><w:trPr><w:trHeight w:val="160" w:hRule="exact"/></w:trPr>' .
                     ($lleno > 0 ? $celdaBarra($lleno, $color) : '') . ($vacio > 0 ? $celdaBarra($vacio, self::BARRA) : '') . '</w:tr></w:tbl>' .
                     '<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="20" w:lineRule="exact"/></w:pPr></w:p>';
            $xml .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>' .
                    '<w:tc><w:tcPr><w:tcW w:w="' . $anchoNombre . '" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' .
                        $this->parrafo($this->texto($p[0], array('sz' => 15)), array('antes' => 50, 'despues' => 50), true) . '</w:tc>' .
                    '<w:tc><w:tcPr><w:tcW w:w="' . $anchoBarra . '" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' . $barra . '</w:tc>' .
                    '<w:tc><w:tcPr><w:tcW w:w="' . $anchoRotulo . '" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' .
                        $this->parrafo($this->texto($p[3], array('b' => true, 'sz' => 14, 'color' => $pct === null ? self::GRIS : self::NEGRO)), array('antes' => 50, 'despues' => 50, 'alin' => 'right'), true) . '</w:tc>' .
                    '</w:tr>';
        }
        return $xml . '</w:tbl>' . $this->parrafo('', array('despues' => 40), true);
    }

    //-----------------------------------------------------------------------
    // Estado de vacantes: el resumen por proyecto, el detalle por estación y la nota
    //-----------------------------------------------------------------------
    private function vacantes($lista, $nota)
    {
        $this->parrafo($this->texto($this->r['secciones']['vacantes'], array('sz' => 22)), array('antes' => 360, 'despues' => 120, 'alin' => 'center', 'conSiguiente' => true));
        $this->franja('VACANTES OPERATIVAS – PEAJES Y BÁSCULAS', 24);
        $this->parrafo($this->texto('Corte: ' . $this->r['corte'], array('i' => true, 'sz' => 17, 'color' => self::GRIS)), array('antes' => 40, 'despues' => 80, 'alin' => 'right', 'conSiguiente' => true));
        if (!count($lista)) {
            $this->vacio('No hay vacantes abiertas.');
        } else {
            //El resumen: cada proyecto con cuántas vacantes tiene y los días de la más antigua
            $porProyecto = array();
            foreach ($lista as $v) {
                $id = (int)$v['proyecto'];
                if (!isset($porProyecto[$id])) { $porProyecto[$id] = array('total' => 0, 'dias' => null); }
                $porProyecto[$id]['total'] += (int)$v['total'];
                $dias = ReporteGerencia::diasDesde($v['antigua'], $this->r['fecha']);
                if ($dias !== null && ($porProyecto[$id]['dias'] === null || $dias > $porProyecto[$id]['dias'])) { $porProyecto[$id]['dias'] = $dias; }
            }
            $filas = array(); $total = 0; $mayor = null;
            foreach ($porProyecto as $id => $p) {
                $nombre = $this->nombreProyecto($id);
                $filas[] = array('celdas' => array($nombre !== '' ? mb_strtoupper($nombre, 'UTF-8') : 'SIN PROYECTO', (string)$p['total'], $p['dias'] === null ? '—' : (string)$p['dias']));
                $total += $p['total'];
                if ($p['dias'] !== null && ($mayor === null || $p['dias'] > $mayor)) { $mayor = $p['dias']; }
            }
            $filas[] = array('tipo' => 'total', 'celdas' => array('TOTAL', (string)$total, $mayor === null ? '—' : (string)$mayor));
            $this->tabla(array(array('PROYECTO', 2200, 'C'), array('CANTIDAD DE VACANTES', 2100, 'C'), array('VACANTE MÁS ANTIGUA (DÍAS)', 2300, 'C')), $filas, 18, true);

            //El detalle: un renglón por estación
            $this->franja('DETALLE DE VACANTES POR PROYECTO Y ESTACIÓN', 21);
            $this->parrafo($this->texto('Información suministrada – corte al ' . $this->r['corte'], array('i' => true, 'sz' => 15, 'color' => self::GRIS)), array('antes' => 40, 'despues' => 80, 'alin' => 'center', 'conSiguiente' => true));
            $filas = array();
            foreach ($lista as $v) {
                $dias = ReporteGerencia::diasDesde($v['antigua'], $this->r['fecha']);
                $colorDias = $dias === null ? null : ($dias >= 60 ? self::ROJO_DIAS : ($dias >= 30 ? self::NARANJA_DIAS : self::VERDE_DIAS));
                $vencido = $v['compromiso'] && $v['compromiso'] < $this->r['fecha'];
                $filas[] = array('celdas' => array(mb_strtoupper($this->nombreProyecto($v['proyecto']), 'UTF-8'), mb_strtoupper($v['centro'], 'UTF-8'), (string)$v['total'],
                                                   $v['antigua'] ? date('d/m/Y', strtotime($v['antigua'])) : '', $dias === null ? '' : (string)$dias,
                                                   mb_strtoupper($v['cargo'], 'UTF-8'), $v['compromiso'] ? ReporteGerencia::diaMesTabla($v['compromiso']) . ($vencido ? ' (vencida)' : '') : ''),
                                 'fondos' => array(4 => $colorDias), 'colores' => $vencido ? array(6 => self::BORDO) : array());
            }
            $this->tabla(array(array('PROYECTO', 1240, 'C'), array('ESTACIÓN', 1360, 'C'), array('CANTIDAD DE VACANTES', 1000, 'C'), array('FECHA DE VACANTE MÁS ANTIGUA', 1300, 'C'),
                               array('ANTIGÜEDAD (DÍAS)', 1260, 'C'), array('CARGO DE LA VACANTE MÁS ANTIGUA', 1880, 'L'), array('COMPROMISO GH', 1320, 'C')), $filas, 15);
        }
        if (trim($nota) !== '') {
            $this->parrafo($this->texto('Nota: ', array('b' => true, 'sz' => 17, 'color' => self::AZUL)) . $this->texto(trim($nota), array('sz' => 17)), array('antes' => 60, 'despues' => 120));
        }
    }

    private static function colorPromesa($pct) { return $pct === null ? self::GRIS : ($pct >= 100 ? self::MENTA : ($pct >= 95 ? self::AMBAR : self::BORDO)); }
    private static function suave($color)
    {
        if ($color === self::MENTA) { return 'E4F4EE'; }
        if ($color === self::AMBAR) { return 'FDF3E0'; }
        if ($color === self::BORDO) { return 'F4E6E4'; }
        return 'F2F2F3';
    }

    //99.25 -> "99,3 %"
    private static function pct($valor)
    {
        if ($valor === null) { return '—'; }
        $redondo = round((float)$valor, 1);
        return ($redondo == floor($redondo) ? number_format($redondo, 0, ',', '.') : number_format($redondo, 1, ',', '.')) . ' %';
    }

    //=======================================================================
    // Piezas de Word (XML)
    //=======================================================================

    //Texto para el XML: sin caracteres que el XML no admite
    private static function xml($texto)
    {
        //Un texto pegado de otro programa puede no venir en UTF-8: se convierte para no perderlo
        $texto = (string)$texto;
        if (!mb_check_encoding($texto, 'UTF-8')) { $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252'); }
        $texto = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string)$texto);
        return htmlspecialchars((string)$texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    //Un pedazo de texto con su formato: b (negrilla), i (cursiva), sz (medios puntos), color
    private function texto($texto, $f = array())
    {
        $props = '';
        if (!empty($f['b'])) { $props .= '<w:b/>'; }
        if (!empty($f['i'])) { $props .= '<w:i/>'; }
        if (!empty($f['color'])) { $props .= '<w:color w:val="' . $f['color'] . '"/>'; }
        if (!empty($f['sz'])) { $props .= '<w:sz w:val="' . (int)$f['sz'] . '"/><w:szCs w:val="' . (int)$f['sz'] . '"/>'; }
        $salida = '';
        //El tabulador va como su propio elemento
        foreach (preg_split('/(\t)/u', (string)$texto, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $parte) {
            $salida .= '<w:r>' . ($props !== '' ? '<w:rPr>' . $props . '</w:rPr>' : '') .
                       ($parte === "\t" ? '<w:tab/>' : '<w:t xml:space="preserve">' . self::xml($parte) . '</w:t>') . '</w:r>';
        }
        return $salida;
    }

    //Un párrafo. Opciones: antes/despues (espacio en twips), justificado, sangria, colgante, tab,
    //fondo (color), borde [color, grosor] a la izquierda, caja (color del borde en los cuatro lados, que da
    //aire alrededor del texto sobre el fondo), conSiguiente (no separarlo del que sigue)
    private function parrafo($runs, $o = array(), $devolver = false)
    {
        $p = '<w:pPr>';
        if (!empty($o['conSiguiente'])) { $p .= '<w:keepNext/>'; }
        if (!empty($o['caja'])) {
            $lado = 'w:val="single" w:sz="4" w:space="3" w:color="' . $o['caja'] . '"';
            $p .= '<w:pBdr><w:top ' . $lado . '/><w:left ' . $lado . '/><w:bottom ' . $lado . '/><w:right ' . $lado . '/></w:pBdr>';
        } elseif (!empty($o['borde'])) {
            $p .= '<w:pBdr><w:left w:val="single" w:sz="' . (int)$o['borde'][1] . '" w:space="' . (isset($o['borde'][2]) ? (int)$o['borde'][2] : 8) . '" w:color="' . $o['borde'][0] . '"/></w:pBdr>';
        }
        if (!empty($o['fondo'])) { $p .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['fondo'] . '"/>'; }
        if (isset($o['tab'])) { $p .= '<w:tabs><w:tab w:val="left" w:pos="' . (int)$o['tab'] . '"/></w:tabs>'; }
        $p .= '<w:spacing w:before="' . (isset($o['antes']) ? (int)$o['antes'] : 0) . '" w:after="' . (isset($o['despues']) ? (int)$o['despues'] : 120) . '"/>';
        $sangria = isset($o['sangria']) ? (int)$o['sangria'] : (!empty($o['borde']) || !empty($o['fondo']) ? 160 : 0);
        if ($sangria || !empty($o['colgante'])) {
            $p .= '<w:ind w:left="' . $sangria . '"' . (!empty($o['colgante']) ? ' w:hanging="' . (int)$o['colgante'] . '"' : '') . (!empty($o['fondo']) ? ' w:right="160"' : '') . '/>';
        }
        if (!empty($o['justificado'])) { $p .= '<w:jc w:val="both"/>'; }
        elseif (!empty($o['alin'])) { $p .= '<w:jc w:val="' . $o['alin'] . '"/>'; }
        $p .= '</w:pPr>';
        $xml = '<w:p>' . $p . $runs . '</w:p>';
        if ($devolver) { return $xml; }
        $this->cuerpo .= $xml;
        return '';
    }

    //Una tabla con la cabecera azul y bordes grises, como las del reporte (la cabecera se repite en cada hoja).
    //$columnas: [[título, ancho, L/C/R], ...]; $filas: [['celdas' => [...], 'tipo' => 'fila' | 'total',
    //'fondos' => [i => color], 'colores' => [i => color]], ...]; $tam: letra en medios puntos; $centrada: la
    //tabla centrada en la hoja (si es más angosta)
    private function tabla($columnas, $filas, $tam = 18, $centrada = false)
    {
        $alin = array('L' => 'left', 'C' => 'center', 'R' => 'right');
        $celda = function ($texto, $ancho, $a, $fondo, $formato) use ($alin, $tam) {
            //Cada renglón del texto va en su propio párrafo dentro de la celda
            $parrafos = '';
            foreach (explode("\n", (string)$texto) as $k => $renglon) {
                $parrafos .= $this->parrafo($this->texto($renglon, $formato + array('sz' => $tam)), array('antes' => $k ? 0 : 30, 'despues' => 30, 'alin' => $alin[$a]), true);
            }
            return '<w:tc><w:tcPr><w:tcW w:w="' . (int)$ancho . '" w:type="dxa"/>' . ($fondo ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fondo . '"/>' : '') .
                   '<w:vAlign w:val="center"/></w:tcPr>' . $parrafos . '</w:tc>';
        };
        $ancho = array_sum(array_map(function ($c) { return (int)$c[1]; }, $columnas));
        $linea = 'w:val="single" w:sz="4" w:space="0" w:color="' . self::BORDE . '"';
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . $ancho . '" w:type="dxa"/>' . ($centrada ? '<w:jc w:val="center"/>' : '<w:tblInd w:w="0" w:type="dxa"/>') .
               '<w:tblBorders><w:top ' . $linea . '/><w:left ' . $linea . '/><w:bottom ' . $linea . '/><w:right ' . $linea . '/><w:insideH ' . $linea . '/><w:insideV ' . $linea . '/></w:tblBorders>' .
               '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="70" w:type="dxa"/><w:right w:w="70" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($columnas as $col) { $xml .= '<w:gridCol w:w="' . (int)$col[1] . '"/>'; }
        $xml .= '</w:tblGrid><w:tr><w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>';
        foreach ($columnas as $col) { $xml .= $celda($col[0], $col[1], 'C', self::AZUL, array('b' => true, 'color' => 'FFFFFF')); }
        $xml .= '</w:tr>';
        foreach ($filas as $n => $fila) {
            $tipo = isset($fila['tipo']) ? $fila['tipo'] : 'fila';
            $xml .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            foreach ($columnas as $i => $col) {
                $texto = isset($fila['celdas'][$i]) ? (string)$fila['celdas'][$i] : '';
                if ($texto === '' && $tipo === 'fila') { $texto = '—'; }
                $fondo = $tipo === 'total' ? self::AZUL_OSCURO : (!empty($fila['fondos'][$i]) ? $fila['fondos'][$i] : ($n % 2 === 1 ? self::AZUL_FILA : null));
                $formato = $tipo === 'total' ? array('b' => true, 'color' => 'FFFFFF') : array();
                if (isset($fila['colores'][$i])) { $formato['color'] = $fila['colores'][$i]; $formato['b'] = true; }
                $xml .= $celda($texto, $col[1], $col[2], $fondo, $formato);
            }
            $xml .= '</w:tr>';
        }
        $xml .= '</w:tbl>';
        $this->cuerpo .= $xml;
        //Un renglón pequeño después de la tabla, para que lo que sigue no quede pegado
        $this->parrafo('', array('despues' => 60));
    }

    //Una franja azul de lado a lado con el título de una tabla, en blanco y centrado
    private function franja($texto, $tam)
    {
        $this->cuerpo .= '<w:tbl><w:tblPr><w:tblW w:w="' . self::ANCHO . '" w:type="dxa"/><w:tblInd w:w="0" w:type="dxa"/>' .
                         '<w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>' .
                         '<w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid><w:gridCol w:w="' . self::ANCHO . '"/></w:tblGrid><w:tr><w:trPr><w:cantSplit/></w:trPr>' .
                         '<w:tc><w:tcPr><w:tcW w:w="' . self::ANCHO . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . self::AZUL_OSCURO . '"/><w:vAlign w:val="center"/></w:tcPr>' .
                         $this->parrafo($this->texto($texto, array('b' => true, 'sz' => $tam, 'color' => 'FFFFFF')), array('antes' => 70, 'despues' => 70, 'alin' => 'center', 'conSiguiente' => true), true) .
                         '</w:tc></w:tr></w:tbl>';
    }

    //=======================================================================
    // Los archivos del .docx
    //=======================================================================
    private function empacar()
    {
        $membrete = __DIR__ . '/../../public/img/membrete_regency.png';
        $this->hayMembrete = is_file($membrete);
        $W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

        $zip = new ZipSencillo();
        $zip->agregar('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Default Extension="png" ContentType="image/png"/>' .
            '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' .
            '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>' .
            '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>' .
            '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>' .
            '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>' .
            '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' .
            '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' .
            '</Types>');
        $zip->agregar('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' .
            '</Relationships>');
        $ahora = gmdate('Y-m-d\TH:i:s\Z');
        $zip->agregar('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
            '<dc:title>' . self::xml($this->r['titulo'] . ' – ' . $this->r['fechaInforme']) . '</dc:title><dc:creator>' . self::xml($this->quien !== '' ? $this->quien : 'HANA') . '</dc:creator>' .
            '<dcterms:created xsi:type="dcterms:W3CDTF">' . $ahora . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $ahora . '</dcterms:modified>' .
            '</cp:coreProperties>');
        $zip->agregar('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>HANA</Application></Properties>');
        $zip->agregar('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>' .
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>' .
            '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>' .
            '</Relationships>');
        if ($this->hayMembrete) {
            $zip->agregar('word/media/membrete.png', file_get_contents($membrete));
            $zip->agregar('word/_rels/header1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
                '<Relationship Id="rIdMembrete" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/membrete.png"/>' .
                '</Relationships>');
        }
        $zip->agregar('word/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<w:styles ' . $W . '><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:eastAsia="Arial" w:cs="Arial"/>' .
            '<w:color w:val="' . self::NEGRO . '"/><w:sz w:val="20"/><w:szCs w:val="20"/><w:lang w:val="es-CO"/></w:rPr></w:rPrDefault>' .
            '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="259" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>' .
            '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>' .
            '<w:style w:type="table" w:default="1" w:styleId="TablaNormal"><w:name w:val="Normal Table"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/>' .
            '<w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>' .
            '</w:styles>');
        $zip->agregar('word/settings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<w:settings ' . $W . '><w:defaultTabStop w:val="708"/><w:characterSpacingControl w:val="doNotCompress"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>');
        $zip->agregar('word/header1.xml', $this->encabezadoXml($W));
        $zip->agregar('word/footer1.xml', $this->pieXml($W));
        //Márgenes como los de la hoja membretada: arriba deja el logo y abajo los datos de Regency
        $zip->agregar('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<w:document ' . $W . '><w:body>' . $this->cuerpo .
            '<w:sectPr><w:headerReference w:type="default" r:id="rId3"/><w:footerReference w:type="default" r:id="rId4"/>' .
            '<w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="2300" w:right="1440" w:bottom="1980" w:left="1440" w:header="0" w:footer="1000" w:gutter="0"/></w:sectPr>' .
            '</w:body></w:document>');
        return $zip->contenido();
    }

    //El encabezado de cada hoja: la hoja membretada de borde a borde, detrás del texto
    private function encabezadoXml($W)
    {
        $imagen = '';
        if ($this->hayMembrete) {
            //Carta: 8,5 × 11 pulgadas; 1 pulgada = 914.400 EMU
            $ancho = 7772400; $alto = 10058400;
            $imagen = '<w:r><w:drawing><wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="0" behindDoc="1" locked="1" layoutInCell="1" allowOverlap="1">' .
                      '<wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionH>' .
                      '<wp:positionV relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionV>' .
                      '<wp:extent cx="' . $ancho . '" cy="' . $alto . '"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/>' .
                      '<wp:docPr id="1" name="Hoja membretada Regency"/><wp:cNvGraphicFramePr/>' .
                      '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">' .
                      '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="0" name="membrete.png"/><pic:cNvPicPr/></pic:nvPicPr>' .
                      '<pic:blipFill><a:blip r:embed="rIdMembrete"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>' .
                      '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $ancho . '" cy="' . $alto . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>' .
                      '</a:graphicData></a:graphic></wp:anchor></w:drawing></w:r>';
        } else {
            //Sin la imagen: el nombre de la empresa arriba a la derecha
            $imagen = '<w:r><w:rPr><w:b/><w:color w:val="' . self::BORDO . '"/><w:sz w:val="32"/></w:rPr><w:t>REGENCY</w:t></w:r>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
               '<w:hdr ' . $W . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">' .
               '<w:p><w:pPr><w:spacing w:before="' . ($this->hayMembrete ? 0 : 500) . '" w:after="0"/><w:jc w:val="right"/></w:pPr>' . $imagen . '</w:p></w:hdr>';
    }

    //El pie de cada hoja: el número de la página, a la derecha (los datos de Regency ya están en la hoja)
    private function pieXml($W)
    {
        $gris = '<w:rPr><w:color w:val="' . self::GRIS . '"/><w:sz w:val="14"/><w:szCs w:val="14"/></w:rPr>';
        $campo = function ($instruccion) use ($gris) {
            return '<w:r>' . $gris . '<w:fldChar w:fldCharType="begin"/></w:r><w:r>' . $gris . '<w:instrText xml:space="preserve"> ' . $instruccion . ' </w:instrText></w:r>' .
                   '<w:r>' . $gris . '<w:fldChar w:fldCharType="separate"/></w:r><w:r>' . $gris . '<w:t>1</w:t></w:r><w:r>' . $gris . '<w:fldChar w:fldCharType="end"/></w:r>';
        };
        $sinMembrete = $this->hayMembrete ? '' :
            '<w:p><w:pPr><w:spacing w:after="0"/><w:jc w:val="center"/></w:pPr>' . $this->texto('CRA 100 No. 5-169 OASIS DE UNICENTRO OF. 306 BC · (+57 602) 333-2681 - (+57) 317 635 2676 · WWW.REGENCY.COM.CO · Cali - Colombia', array('sz' => 14, 'b' => true)) . '</w:p>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . $W . '>' . $sinMembrete .
               '<w:p><w:pPr><w:spacing w:after="0"/><w:ind w:right="-560"/><w:jc w:val="right"/></w:pPr>' .
               $this->texto('Página ', array('sz' => 14, 'color' => self::GRIS)) . $campo('PAGE') .
               $this->texto(' de ', array('sz' => 14, 'color' => self::GRIS)) . $campo('NUMPAGES') . '</w:p></w:ftr>';
    }
}

//===========================================================================
// Un ZIP sencillo en memoria: lo justo para armar el .docx sin la extensión zip de PHP.
// Comprime con deflate si PHP lo tiene (zlib); si no, guarda los archivos tal cual
//===========================================================================
class ZipSencillo
{
    private $datos = '';
    private $directorio = '';
    private $cuantos = 0;

    public function agregar($nombre, $contenido)
    {
        $contenido = (string)$contenido;
        $crc = crc32($contenido);
        $tamano = strlen($contenido);
        $metodo = 0; $guardado = $contenido;
        if (function_exists('gzdeflate')) {
            $comprimido = gzdeflate($contenido, 6);
            if ($comprimido !== false && strlen($comprimido) < $tamano) { $metodo = 8; $guardado = $comprimido; }
        }
        //Fecha y hora fijas en formato DOS (1 de enero de 2026, 00:00)
        $hora = 0; $fecha = ((2026 - 1980) << 9) | (1 << 5) | 1;
        $posicion = strlen($this->datos);
        $comun = pack('vvvvvVVVvv', 20, 0x0800, $metodo, $hora, $fecha, $crc, strlen($guardado), $tamano, strlen($nombre), 0);
        $this->datos .= "PK\x03\x04" . $comun . $nombre . $guardado;
        $this->directorio .= "PK\x01\x02" . pack('v', 20) . $comun . pack('vvvVV', 0, 0, 0, 0, $posicion) . $nombre;
        $this->cuantos++;
    }

    public function contenido()
    {
        return $this->datos . $this->directorio .
               "PK\x05\x06" . pack('vvvvVVv', 0, 0, $this->cuantos, $this->cuantos, strlen($this->directorio), strlen($this->datos), 0);
    }
}
