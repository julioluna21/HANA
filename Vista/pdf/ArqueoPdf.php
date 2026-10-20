<?php
/*
  HANA - PDF del arqueo
  ---------------------------------------------------------------------------
  Genera el arqueo con la MISMA estructura de los formatos en PDF que se usan
  hoy ("Arqueo caja menor ..." y "Arqueo recambio ..."): mismas posiciones,
  tamaños de letra, líneas y grises. Las coordenadas se tomaron de esos PDF
  (hoja carta, en puntos, midiendo desde arriba).

  El recuadro del logo queda en blanco a propósito: el formato lo usan varias
  concesiones y cada una pondrá el suyo.

  Usa FPDF 1.9 (public/fpdf). La versión de public/Gpdf
  (1.81) no funciona con PHP 8. FPDF escribe en
  Windows-1252: todo texto pasa por textoPdf() para que las tildes salgan bien.

  Notación de las medidas: x y y son posiciones en la hoja (x hacia la derecha,
  y hacia abajo); x1/x2 y y1/y2 son los extremos de una línea o recuadro, y
  r, g, b son los tres componentes de un color (rojo, verde, azul, de 0 a 255).
*/
require_once __DIR__ . '/../../public/fpdf/fpdf.php';

class ArqueoPdf extends FPDF
{
    private $gris = 0;

    //Hoja carta en puntos, sin márgenes ni saltos automáticos: todo se ubica a mano
    public function __construct()
    {
        parent::__construct('P', 'pt', 'Letter');
        $this->SetMargins(0, 0, 0);
        $this->SetAutoPageBreak(false);
        $this->SetLineWidth(0.6);
        $this->SetDrawColor(0, 0, 0);
        $this->SetTextColor(0, 0, 0);
    }

    //-----------------------------------------------------------------------
    // Utilidades de dibujo
    //-----------------------------------------------------------------------

    //UTF-8 -> Windows-1252 (la codificación de las fuentes de FPDF)
    private function textoPdf($texto)
    {
        //Los nombres de los módulos de siempre llegan con códigos (&amp;, &quot;): en el
        //PDF van como se escribieron. Dos pasadas, por los que quedaron convertidos dos veces
        $texto = html_entity_decode(html_entity_decode((string)$texto, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (function_exists('iconv')) {
            $convertido = @iconv('UTF-8', 'windows-1252//TRANSLIT', $texto);
            if ($convertido !== false) { return $convertido; }
        }
        if (function_exists('mb_convert_encoding')) { return mb_convert_encoding($texto, 'windows-1252', 'UTF-8'); }
        return $texto;
    }

    //Mayúsculas con tildes, sin depender de mbstring
    public static function mayus($texto)
    {
        if (function_exists('mb_strtoupper')) { return mb_strtoupper((string)$texto, 'UTF-8'); }
        return strtr(strtoupper((string)$texto), array('á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ñ' => 'Ñ', 'ü' => 'Ü'));
    }

    //Escribe un texto. $top es el borde superior de la letra (como se midió en el
    //formato PDF); FPDF necesita la línea base, que queda 0,905 × tamaño más abajo.
    //$alin: L (desde $x), R (terminando en $x) o C (centrado entre $x y $x2)
    private function escribir($x, $top, $texto, $tam, $negrita = false, $alin = 'L', $x2 = 0)
    {
        $texto = (string)$texto;
        //Un dato que se marcó con cabe() y no cabe en su casilla: primero se achica la letra;
        //si ni así cabe, pasa a dos o más renglones dentro del mismo alto. Nunca se recorta
        if (isset($this->limites[$texto])) {
            $ancho = $this->limites[$texto];
            $this->SetFont('Helvetica', $negrita ? 'B' : '', $tam);
            if ($this->GetStringWidth($this->textoPdf($texto)) > $ancho) {
                $tamano = $tam;
                while ($tamano > $tam * 0.62) {
                    $tamano -= 0.2;
                    $this->SetFont('Helvetica', $negrita ? 'B' : '', $tamano);
                    if ($this->GetStringWidth($this->textoPdf($texto)) <= $ancho) { break; }
                }
                if ($this->GetStringWidth($this->textoPdf($texto)) <= $ancho) {
                    $this->escribirRenglon($x, $top + ($tam - $tamano) * 0.45, $texto, $tamano, $negrita, $alin, $x2);
                    return;
                }
                //Varios renglones: el bloque queda centrado sobre el renglón original
                $tamano = $tam * 0.8;
                while (true) {
                    $lineas = $this->partir($texto, $ancho, $tamano, $negrita);
                    $alto = count($lineas) * $tamano * 1.08;
                    if ($alto <= $tam * 2.3 || $tamano <= 3.6) { break; }
                    $tamano -= 0.2;
                }
                $y = $top + $tam / 2 - $alto / 2;
                foreach ($lineas as $renglon) { $this->escribirRenglon($x, $y, $renglon, $tamano, $negrita, $alin, $x2); $y += $tamano * 1.08; }
                return;
            }
        }
        $this->escribirRenglon($x, $top, $texto, $tam, $negrita, $alin, $x2);
    }

    //Escribe un texto en un solo renglón, sin revisar si cabe (escribir() es la que lo achica o lo parte)
    private function escribirRenglon($x, $top, $texto, $tam, $negrita = false, $alin = 'L', $x2 = 0)
    {
        $this->SetFont('Helvetica', $negrita ? 'B' : '', $tam);
        $textoConvertido = $this->textoPdf($texto);
        $anchoTexto = $this->GetStringWidth($textoConvertido);
        if ($alin === 'R')     { $x = $x - $anchoTexto; }
        elseif ($alin === 'C') { $x = $x + (($x2 - $x) - $anchoTexto) / 2; }
        $this->Text($x, $top + 0.905 * $tam, $textoConvertido);
    }

    //No se recorta ningún texto: solo se anota el ancho de su casilla, y al escribirlo
    //escribir() achica la letra o lo pasa a otro renglón
    private $limites = array();
    private function cabe($texto, $ancho, $tam, $negrita = false)
    {
        $texto = (string)$texto;
        $this->limites[$texto] = isset($this->limites[$texto]) ? min($this->limites[$texto], $ancho) : $ancho;
        return $texto;
    }

    //Parte un texto en los renglones que necesite (una palabra muy larga se parte por letras)
    private function partir($texto, $ancho, $tam, $negrita = false)
    {
        $this->SetFont('Helvetica', $negrita ? 'B' : '', $tam);
        $renglones = array();
        foreach (preg_split('/\r\n|\r|\n/', trim((string)$texto)) as $parrafo) {
            $linea = '';
            foreach (preg_split('/\s+/', $parrafo) as $palabra) {
                $prueba = $linea === '' ? $palabra : "$linea $palabra";
                if ($this->GetStringWidth($this->textoPdf($prueba)) <= $ancho) { $linea = $prueba; continue; }
                if ($linea !== '') { $renglones[] = $linea; }
                while ($palabra !== '' && $this->GetStringWidth($this->textoPdf($palabra)) > $ancho) {
                    $letras = 1; $largo = mb_strlen($palabra, 'UTF-8');
                    while ($letras < $largo && $this->GetStringWidth($this->textoPdf(mb_substr($palabra, 0, $letras + 1, 'UTF-8'))) <= $ancho) { $letras++; }
                    $renglones[] = mb_substr($palabra, 0, $letras, 'UTF-8');
                    $palabra = mb_substr($palabra, $letras, null, 'UTF-8');
                }
                $linea = $palabra;
            }
            $renglones[] = $linea;
        }
        return $renglones;
    }

    //Un párrafo (la observación) en su recuadro de $max renglones de $paso de alto.
    //Si el texto es largo, la letra se achica para que quepa completo en el mismo espacio
    //$rayas: las líneas del formato (como en el papel). Si la letra tuvo que achicarse, los renglones
    //ya no coinciden con ellas y quedarían tachando el texto: en ese caso no se pintan
    private function parrafo($x, $top, $texto, $ancho, $tam, $max, $paso, $rayas = array(), $x1 = 0, $x2 = 0)
    {
        $tamano = $tam;
        while (true) {
            $lineas = $this->partir($texto, $ancho, $tamano);
            $pasoReal = $paso * $tamano / $tam;
            if (count($lineas) * $pasoReal <= $max * $paso || $tamano <= 3.6) { break; }
            $tamano -= 0.2;
        }
        if ($tamano === $tam) { foreach ($rayas as $alturaRaya) { $this->rayaHorizontal($x1, $x2, $alturaRaya); } }
        foreach ($lineas as $i => $renglon) { $this->escribirRenglon($x, $top + $i * $pasoReal, $renglon, $tamano); }
        return $top + count($lineas) * $pasoReal;
    }

    //Ayudas para dibujar: línea horizontal, línea vertical y rectángulo relleno
    private function rayaHorizontal($x1, $x2, $y) { $this->Line($x1, $y, $x2, $y); }
    private function rayaVertical($x, $y1, $y2) { $this->Line($x, $y1, $x, $y2); }
    private function relleno($x1, $y1, $x2, $y2, $r, $g, $b)
    {
        $this->SetFillColor($r, $g, $b);
        $this->Rect($x1, $y1, $x2 - $x1, $y2 - $y1, 'F');
    }

    //Formato contable del formato en papel: "320,000"; el cero se muestra como "-"
    private static function num($valor)
    {
        $valor = round((float)$valor);
        if ($valor == 0) { return '-'; }
        return ($valor < 0 ? '-' : '') . number_format(abs($valor), 0, '.', ',');
    }

    //"$" a la izquierda y el valor a la derecha, como en Excel
    private function pesos($xSigno, $xFin, $top, $valor, $tam, $negrita = false)
    {
        $this->escribir($xSigno, $top, '$', $tam, $negrita);
        $textoValor = self::num($valor);
        $this->escribir($textoValor === '-' ? $xFin - 8 : $xFin, $top, $textoValor, $tam, $negrita, 'R');
    }

    //La fecha en palabras, por ejemplo "lunes, 5 de octubre de 2026"
    public static function fechaLarga($fecha)
    {
        $dias = array('domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado');
        $meses = array('', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                       'septiembre', 'octubre', 'noviembre', 'diciembre');
        $marcaTiempo = strtotime($fecha);
        return $dias[(int)date('w', $marcaTiempo)] . ', ' . (int)date('j', $marcaTiempo) . ' de ' . $meses[(int)date('n', $marcaTiempo)] . ' de ' . date('Y', $marcaTiempo);
    }

    //Sello de anulado, en diagonal y en vino, para que no se confunda con uno vigente
    private function sello($motivo)
    {
        $this->SetTextColor(110, 26, 30);
        $this->escribir(93, 30, 'ANULADO: ' . $this->cabe($motivo, 380, 9, true), 9, true);
        $this->SetTextColor(0, 0, 0);
    }

    //-----------------------------------------------------------------------
    // ARQUEO A CAJA MENOR
    //-----------------------------------------------------------------------
    public function cajaMenor($arqueo)
    {
        $this->AddPage();
        if ((int)$arqueo['ESTADO'] !== 1) { $this->sello($arqueo['MOTIVO_ANULACION']); }

        //Líneas del efectivo y de los documentos. El efectivo es un solo valor (billetes y monedas juntos)
        $efectivo = 0;
        $documentos = array();
        $subtotales = array('FACTURA' => 0, 'REINTEGRO' => 0, 'RECIBO_CAJA' => 0, 'FALTANTE' => 0);
        foreach ($arqueo['LINEAS'] as $linea) {
            if (isset($subtotales[$linea['CODIGO']])) { $documentos[] = $linea; $subtotales[$linea['CODIGO']] += (float)$linea['VALOR']; }
            else { $efectivo += (float)$linea['VALOR']; }
        }

        //--- Marco exterior y encabezado (el logo va en 93..221, en blanco) ---
        $this->rayaHorizontal(79, 526, 54); $this->rayaHorizontal(79, 526, 644); $this->rayaVertical(78, 54, 645);
        $this->rayaHorizontal(93, 526, 65); $this->rayaHorizontal(93, 526, 102);
        $this->rayaVertical(93, 65, 102); $this->rayaVertical(221, 65, 102); $this->rayaVertical(525, 65, 102);
        $this->logo(93, 221, 65, 102); //el logo de Regency, centrado en su recuadro
        $this->escribir(221, 78, 'ARQUEO A CAJA MENOR', 8.8, true, 'C', 525);

        //--- Cuerpo ---
        $this->rayaHorizontal(93, 526, 108); $this->rayaVertical(93, 108, 634); $this->rayaVertical(525, 109, 634); $this->rayaHorizontal(93, 526, 633);

        //Datos generales
        $this->relleno(363, 109, 525, 128, 231, 230, 230);
        $this->escribir(95, 120, 'ZONA', 7.3);
        $this->escribir(172, 119, $this->cabe('CONCESIÓN ' . self::mayus($arqueo['NOM_PROYECTO']), 112, 7.3), 7.3, false, 'C', 287);
        $this->escribir(362, 119, 'ESTACION', 7.3, false, 'R');
        $this->escribir(363, 119, $this->cabe(self::mayus(($arqueo['TIPO_CENTRO'] === 'BASCULA' ? 'BÁSCULA ' : ($arqueo['TIPO_CENTRO'] === 'PEAJE' ? 'PEAJE ' : '')) . $arqueo['NOM_CENTRO_OP']), 158, 7.3), 7.3, false, 'C', 526);
        $this->rayaHorizontal(172, 287, 128); $this->rayaHorizontal(363, 526, 128);

        $this->escribir(95, 135, 'FONDO ARQUEADO', 7.3);
        $this->escribir(172, 135, 'CAJA MENOR', 7.3, false, 'C', 287);
        $this->escribir(362, 135, 'FECHA DE ARQUEO', 7.3, false, 'R');
        $this->escribir(363, 135, self::fechaLarga($arqueo['FECHA']), 7.3, false, 'C', 526);
        $this->rayaHorizontal(172, 287, 144); $this->rayaHorizontal(363, 526, 144);

        $this->escribir(95, 153, 'HORA DE ARQUEO', 7.3);
        $this->escribir(172, 152, substr($arqueo['HORA'], 0, 5), 7.3, false, 'C', 238);
        $this->escribir(252, 152, 'RESPONSABLE DEL FONDO', 7.3);
        $this->escribir(363, 152, $this->cabe(self::mayus($arqueo['RESPONSABLE']), 158, 7.3), 7.3, false, 'C', 526);
        $this->rayaHorizontal(172, 238, 161); $this->rayaHorizontal(363, 526, 161);

        //Efectivo (izquierda): billetes y monedas en un solo valor
        $this->escribir(95, 169, 'EFECTIVO', 7.3);   $this->pesos(176, 283, 169, $efectivo, 7.3);
        $this->escribir(95, 178, '(billetes y monedas)', 6.2);
        $this->escribir(95, 190, 'SUBTOTAL', 7.3, true); $this->pesos(176, 283, 190, $arqueo['TOTAL_EFECTIVO'], 7.3);
        $this->rayaHorizontal(172, 287, 178); $this->rayaHorizontal(172, 287, 199);

        //Documentos y totales (derecha)
        $filas = array(
            array('FACTURAS', 168, 169, $subtotales['FACTURA'], false, 178),
            array('REINTEGRO DE FONDO', 179, 181, $subtotales['REINTEGRO'], false, 190),
            array('RECIBO DE CAJA', 190, 190, $subtotales['RECIBO_CAJA'], false, 199),
            array('FALTANTES REGISTRADOS', 200, 200, $subtotales['FALTANTE'], false, 209),
            array('SUBTOTAL', 210, 211, $arqueo['TOTAL_DOCUMENTOS'], true, 220),
            array('TOTAL ARQUEO', 231, 232, $arqueo['TOTAL_ARQUEO'], false, 241),
            array('FONDO AUTORIZADO', 242, 243, $arqueo['FONDO_AUTORIZADO'], false, 252),
            array('DIFERENCIA', 252, 253, $arqueo['DIFERENCIA'], false, 262)
        );
        foreach ($filas as $filaTotal) {
            $this->escribir(435, $filaTotal[1], $filaTotal[0], 7.3, $filaTotal[4], 'R');
            $this->pesos(440, 522, $filaTotal[2], $filaTotal[3], 7.3);
            $this->rayaHorizontal(436, 526, $filaTotal[5]);
        }

        //--- Tabla de documentos ---
        $this->relleno(93, 273, 526, 305, 217, 217, 217);
        $this->rayaHorizontal(93, 526, 273); $this->rayaHorizontal(93, 525, 283); $this->rayaHorizontal(222, 287, 294);
        $this->escribir(93, 274, 'DESCRIPCION DE FACTURAS, REINTEGROS, RECIBOS Y FALTANTES', 7.3, true, 'C', 525);
        $this->escribir(221, 285, 'CONCEPTO', 7.3, true, 'C', 287);
        $this->escribir(93, 290, 'FECHA', 7.3, true, 'C', 172);
        $this->escribir(172, 290, 'VALOR', 7.3, true, 'C', 221);
        $this->escribir(287, 290, 'OBSERVACIONES', 7.3, true, 'C', 525);
        $columnas = array('FACTURA' => array(221, 238, 'F'), 'REINTEGRO' => array(238, 254, 'R'),
                      'RECIBO_CAJA' => array(254, 271, 'RC'), 'FALTANTE' => array(271, 287, 'FR'));
        foreach ($columnas as $columna) { $this->escribir($columna[0], 295, $columna[2], 7.3, true, 'C', $columna[1]); }

        //15 renglones como el formato en papel. Si hay más documentos, los renglones
        //se hacen más bajos para que todo quepa en el mismo espacio
        $renglonesTabla = max(15, count($documentos));
        $alto = (463 - 304) / $renglonesTabla;
        $tam = min(6.6, $alto * 0.62);
        $this->rayaHorizontal(93, 525, 304);
        for ($i = 1; $i <= $renglonesTabla; $i++) { $this->rayaHorizontal(93, 525, 304 + $alto * $i); }
        $this->rayaVertical(172, 284, 463); $this->rayaVertical(221, 284, 463); $this->rayaVertical(286, 284, 463);
        foreach (array(238, 254, 271) as $x) { $this->rayaVertical($x, 295, 463); }
        foreach ($documentos as $i => $documento) {
            $top = 304 + $alto * $i + ($alto - $tam * 1.1) / 2;
            if ($documento['FECHA']) { $this->escribir(171, $top, date('d/m/Y', strtotime($documento['FECHA'])), $tam, false, 'R'); }
            $this->escribir(220, $top, '$ ' . self::num($documento['VALOR']), $tam, false, 'R');
            $columna = $columnas[$documento['CODIGO']];
            $this->escribir($columna[0], $top, 'X', $tam, false, 'C', $columna[1]);
            $this->escribir(287, $top, $this->cabe($documento['OBSERVACION'], 232, $tam), $tam, false, 'C', 525);
        }
        $this->rayaHorizontal(93, 526, 463);
        $this->escribir(95, 464, '(F) Factura   (R) Reintegro de Fondo  (RC) Recibo de Caja  (FR) Faltantes Registrados.', 7.3);

        //--- Observación ---
        $this->escribir(95, 480, 'OBSERVACION :', 7.3, true);
        $this->parrafo(96, 491, (string)$arqueo['OBSERVACION'], 426, 6.6, 3, 10.5, array(500, 510, 521), 93, 525);

        //--- Constancia y cierre ---
        $this->escribir(94, 527, 'SE DEJA EN CONSTANCIA QUE EL DINERO Y DOCUMENTOS UTILIZADOS EN EL ARQUEO, FUERON MANEJADOS POR LA PERSONA RESPONSABLE DE', 5.9);
        $this->escribir(94, 535, 'LOS MISMOS EN LA EDR Y TERMINADA LA DILIGENCIA FUERON CUSTODIADOS EN LA CAJA FUERTE ASIGNADA.', 5.9);
        $this->escribir(94, 545, 'SE DA POR TERMINADO A LAS', 5.9);
        if ($arqueo['HORA_FIN']) { $this->escribir(223, 545, substr($arqueo['HORA_FIN'], 0, 5), 5.9); } //HH:MM, como la hora del arqueo
        $this->rayaHorizontal(222, 271, 553);
        $this->escribir(272, 545, 'DEL', 5.9);
        $this->escribir(288, 545, self::fechaLarga($arqueo['FECHA']), 6.6);
        $this->rayaHorizontal(287, 436, 553);

        //--- Firmas (en blanco, para firmar a mano) ---
        $this->rayaHorizontal(93, 287, 612); $this->rayaHorizontal(329, 526, 612);
        $this->escribir(93, 615, 'PERSONA RESPONSABLE DEL DINERO', 5.9, false, 'C', 287);
        $this->escribir(329, 615, 'PERSONA QUE EFECTUA EL ARQUEO', 5.9, false, 'C', 526);

        $this->pie($arqueo, 655);
    }

    //-----------------------------------------------------------------------
    // ARQUEO FONDO DE RECAMBIO
    //-----------------------------------------------------------------------
    public function recambio($arqueo)
    {
        $this->AddPage();
        if ((int)$arqueo['ESTADO'] !== 1) { $this->sello($arqueo['MOTIVO_ANULACION']); }

        //Cada concepto del recambio por su código (el efectivo, billetes y monedas juntos, viene como EFECTIVO)
        $lineaPorCodigo = array();
        foreach ($arqueo['LINEAS'] as $linea) { $lineaPorCodigo[$linea['CODIGO']] = $linea; }

        //--- Marco exterior y encabezado (logo en 93..188, en blanco) ---
        $this->rayaHorizontal(79, 531, 54); $this->rayaHorizontal(79, 531, 516); $this->rayaVertical(79, 54, 517); $this->rayaVertical(530, 55, 517);
        $this->rayaHorizontal(93, 515, 64); $this->rayaHorizontal(93, 515, 99);
        $this->rayaVertical(93, 64, 99); $this->rayaVertical(188, 64, 99); $this->rayaVertical(515, 64, 99);
        $this->logo(93, 188, 64, 99); //el logo de Regency, centrado en su recuadro
        $this->escribir(188, 77, 'ARQUEO FONDO DE RECAMBIO', 8.1, true, 'C', 515);

        //--- Cuerpo ---
        $this->rayaHorizontal(93, 515, 107); $this->rayaVertical(93, 107, 507); $this->rayaVertical(515, 107, 507); $this->rayaHorizontal(93, 515, 506);

        $centro = self::mayus(($arqueo['TIPO_CENTRO'] === 'BASCULA' ? 'BÁSCULA ' : ($arqueo['TIPO_CENTRO'] === 'PEAJE' ? 'PEAJE ' : '')) . $arqueo['NOM_CENTRO_OP']);
        $datos = array(
            array(116, 'ZONA', $this->cabe('CONCESIÓN ' . self::mayus($arqueo['NOM_PROYECTO']), 94, 7.5), 'LUGAR O EDR', $this->cabe($centro, 136, 7.5), 125),
            array(135, 'FECHA ARQUEO', date('d/m/Y', strtotime($arqueo['FECHA'])), 'HORA ARQUEO', substr($arqueo['HORA'], 0, 5), 144),
            array(155, 'RESPONSABLE', $this->cabe(self::mayus($arqueo['RESPONSABLE']), 94, 7.5), 'CARGO', $this->cabe(self::mayus($arqueo['CARGO_RESPONSABLE']), 136, 7.5), 164)
        );
        foreach ($datos as $dato) {
            $this->escribir(93, $dato[0], $dato[1], 7.5, true, 'C', 188);
            $this->escribir(188, $dato[0], $dato[2], 7.5, false, 'C', 286);
            $this->escribir(286, $dato[0], $dato[3], 7.5, true, 'C', 375);
            $this->escribir(375, $dato[0], $dato[4], 7.5, false, 'C', 515);
            $this->rayaHorizontal(188, 286, $dato[5]); $this->rayaHorizontal(375, 515, $dato[5]);
        }

        //--- Tabla de conceptos ---
        $this->relleno(93, 174, 515, 185, 242, 242, 242);
        $this->escribir(93, 175, 'CONCEPTO', 8.1, true, 'C', 285);
        $this->escribir(285, 175, 'VALOR', 8.1, true, 'C', 375);
        $this->escribir(375, 175, 'OBSERVACIONES', 8.1, true, 'C', 515);
        foreach (array(174, 185, 201, 218, 235, 252, 269, 293, 310, 327, 344) as $alturaRaya) { $this->rayaHorizontal(93, 515, $alturaRaya); }
        $this->rayaVertical(285, 174, 269); $this->rayaVertical(375, 174, 269); $this->rayaVertical(285, 293, 344); $this->rayaVertical(375, 293, 344);

        //Cuatro conceptos: billetes y monedas van juntos en una sola fila
        $conceptos = array(
            array('BASES_CASETAS', 'DINERO PARA BASES EN CASETAS', 185),
            array('BASE_SUPERVISORA', 'DINERO BASE SUPERVISORA', 201),
            array('EFECTIVO', 'DINERO EN EFECTIVO (BILLETES Y MONEDAS)', 218),
            array('CAJA_FUERTE', 'DINERO CUSTODIADO EN CAJA FUERTE', 235)
        );
        foreach ($conceptos as $concepto) {
            $fila = $concepto[2];
            $this->escribir(95, $fila + 4, $concepto[1], 7.5);
            $linea = isset($lineaPorCodigo[$concepto[0]]) ? $lineaPorCodigo[$concepto[0]] : null;
            //Como en el formato en papel: un concepto sin valor queda en blanco
            if ($linea && (float)$linea['VALOR'] != 0) { $this->pesos(289, 370, $fila + 1, $linea['VALOR'], 7.5); }
            if ($linea && $linea['OBSERVACION']) { $this->escribir(378, $fila + 4, $this->cabe($linea['OBSERVACION'], 134, 6.6), 6.6); }
        }
        $this->escribir(95, 256, 'TOTAL ARQUEO DEL FONDO', 7.5, true);
        $this->pesos(289, 370, 253, $arqueo['TOTAL_ARQUEO'], 7.5);

        $this->escribir(95, 297, 'FONDO AUTORIZADO', 7.5);   $this->pesos(289, 370, 294, $arqueo['FONDO_AUTORIZADO'], 7.5);
        $this->escribir(95, 314, 'MENOS TOTAL ARQUEO', 7.5); $this->pesos(289, 370, 311, $arqueo['TOTAL_ARQUEO'], 7.5);
        $this->escribir(95, 331, 'DIFERENCIA', 7.5);         $this->pesos(289, 370, 328, $arqueo['DIFERENCIA'], 7.5);

        //--- Observaciones ---
        $this->escribir(95, 346, 'OBSERVACIONES:', 6.8, true);
        $this->parrafo(96, 356, (string)$arqueo['OBSERVACION'], 416, 6.6, 6, 9.8, array(364, 373, 383, 393, 403, 413), 93, 515);

        //--- Constancia y cierre ---
        $this->escribir(94, 422, 'SE DEJA EN CONSTANCIA QUE EL DINERO Y DOCUMENTOS UTILIZADOS EN EL ARQUEO, FUERON MANEJADOS POR LA PERSONA', 6.1);
        $this->escribir(94, 430, 'RESPONSABLE DE LOS MISMOS EN LA EDR Y TERMINADA LA DILIGENCIA FUERON CUSTODIADOS EN LA CAJA FUERTE ASIGNADA.', 6.1);
        $this->escribir(94, 439, 'DILIGENCIA TERMINADA DE CONFORMIDAD A LOS', 6.1);
        //La fecha va siempre; si no se registró la hora de terminación, queda el espacio para escribirla a mano
        $this->escribir(286, 439, date('d/m/Y', strtotime($arqueo['FECHA'])) . ($arqueo['HORA_FIN'] ? ' ' . substr($arqueo['HORA_FIN'], 0, 5) : '  a las ________'), 6.1, false, 'C', 515);
        $this->rayaHorizontal(286, 515, 447);

        //--- Firmas (en blanco, para firmar a mano) ---
        $this->rayaHorizontal(93, 286, 487); $this->rayaHorizontal(375, 515, 487);
        $this->escribir(93, 488, 'RESPONSABLE DEL DINERO', 7.5, true, 'C', 286);
        $this->escribir(375, 488, 'QUIEN REALIZA EL ARQUEO', 7.5, true, 'C', 515);

        $this->pie($arqueo, 527);
    }

    //-----------------------------------------------------------------------
    // ARQUEO DE CASETA (con los campos configurados para el peaje)
    //   PROYECTO / FECHA / HORA            PEAJE / CASETA / RECOLECTORA
    //   RECAUDO CASETA $
    //   RETIROS, EFECTIVO... (lo que suma o resta)   TOTAL RECAUDO $
    //   NOVEDAD: FALTANTE o SOBRANTE
    //   BASE DE SENCILLO y demás campos informativos
    //-----------------------------------------------------------------------
    public function caseta($arqueo)
    {
        $this->AddPage();
        if ((int)$arqueo['ESTADO'] !== 1) { $this->sello($arqueo['MOTIVO_ANULACION']); }

        //Los renglones, por comportamiento: ROL es el que tenía el campo el día del arqueo
        $grupos = array('ESPERADO' => array(), 'CUENTA' => array(), 'INFO' => array());
        foreach ($arqueo['LINEAS'] as $linea) {
            $rol = $linea['ROL'];
            if ($rol === 'ESPERADO') { $grupos['ESPERADO'][] = $linea; }
            elseif ($rol === 'INFO') { $grupos['INFO'][] = $linea; }
            else { $grupos['CUENTA'][] = $linea; }
        }

        //--- Marco y encabezado: el logo de Regency en el recuadro izquierdo ---
        $this->rayaHorizontal(93, 515, 64); $this->rayaHorizontal(93, 515, 99);
        $this->rayaVertical(93, 64, 99); $this->rayaVertical(188, 64, 99); $this->rayaVertical(515, 64, 99);
        $logo = __DIR__ . '/../../public/img/logo_pdf.jpg'; //copia en JPG del logo (FPDF no lee PNG con transparencia)
        if (is_file($logo)) { $this->Image($logo, 122.5, 66, 36); } //36 pt de ancho, alto proporcional, centrado en el recuadro 93..188
        $this->escribir(188, 77, 'ARQUEO DE CASETA', 8.1, true, 'C', 515);

        //--- Datos: dos columnas ---
        $centro = self::mayus(($arqueo['TIPO_CENTRO'] === 'BASCULA' ? 'BÁSCULA ' : '') . $arqueo['NOM_CENTRO_OP']);
        $datosIzquierda = array(array('PROYECTO', $this->cabe(self::mayus($arqueo['NOM_PROYECTO']), 120, 8)),
                     array('FECHA', date('d/m/Y', strtotime($arqueo['FECHA']))),
                     array('HORA', substr($arqueo['HORA'], 0, 5)));
        $datosDerecha = array(array('PEAJE', $this->cabe($centro, 110, 8)),
                     array('CASETA', $this->cabe(self::mayus($arqueo['CASETA']), 110, 8)),
                     array('RECOLECTORA', $this->cabe(self::mayus($arqueo['RESPONSABLE']), 110, 8)));
        $top = 118;
        for ($i = 0; $i < 3; $i++) {
            $this->escribir(100, $top, $datosIzquierda[$i][0], 8, true);
            $this->escribir(165, $top, $datosIzquierda[$i][1], 8);
            $this->escribir(318, $top, $datosDerecha[$i][0], 8, true);
            $this->escribir(398, $top, $datosDerecha[$i][1], 8);
            $top += 15;
        }

        //--- Valores ---
        $top += 14;
        $fila = function ($texto, $valor, $negrita = false, $signoMenos = false) use (&$top) {
            $this->escribir(100, $top, $texto, 8.5, $negrita);
            $this->escribir(236, $top, ($signoMenos ? '-' : '') . '$', 8.5, $negrita);
            //Pesos colombianos: miles con punto (10.000.000), como en el formato
            $this->escribir(360, $top, (float)$valor == 0 ? '-' : number_format((float)$valor, 0, ',', '.'), 8.5, $negrita, 'R');
            $top += 15;
        };
        foreach ($grupos['ESPERADO'] as $linea) { $fila(self::mayus($linea['NOMBRE']), $linea['VALOR'], true); }
        $top += 8;
        foreach ($grupos['CUENTA'] as $linea) { $fila(self::mayus($linea['NOMBRE']), $linea['VALOR'], false, $linea['ROL'] === 'RESTA'); }
        $this->rayaHorizontal(250, 360, $top - 3);
        $fila('TOTAL RECAUDO', $arqueo['TOTAL_ARQUEO'], true);

        //--- Novedad: faltante o sobrante ---
        $top += 8;
        $diferencia = (float)$arqueo['DIFERENCIA'];
        $this->escribir(100, $top, 'NOVEDAD', 8.5, true);
        if ($diferencia == 0) { $this->escribir(360, $top, 'SIN NOVEDAD', 8.5, true, 'R'); }
        $top += 15;
        $fila('FALTANTE', $diferencia < 0 ? abs($diferencia) : 0, $diferencia < 0, $diferencia < 0);
        $fila('SOBRANTE', $diferencia > 0 ? $diferencia : 0, $diferencia > 0);

        //--- Informativos (base de sencillo...) ---
        if (count($grupos['INFO'])) {
            $top += 8;
            foreach ($grupos['INFO'] as $linea) { $fila(self::mayus($linea['NOMBRE']), $linea['VALOR']); }
        }

        //--- Observación ---
        $top += 10;
        $this->escribir(100, $top, 'OBSERVACIÓN', 8, true);
        $top += 12;
        $top = $this->parrafo(100, $top, (string)$arqueo['OBSERVACION'], 410, 7.5, 5, 11);

        //--- Firma: solo quien realiza el arqueo ---
        $top = max($top + 40, 470);
        $this->rayaHorizontal(214, 394, $top);
        $this->escribir(214, $top + 4, 'QUIEN REALIZA EL ARQUEO', 7.5, true, 'C', 394);
        $this->escribir(214, $top + 15, $this->cabe(self::mayus($arqueo['ARQUEA']), 180, 7), 7, false, 'C', 394);

        //Marco del cuerpo
        $this->rayaHorizontal(93, 515, 107); $this->rayaVertical(93, 107, $top + 32); $this->rayaVertical(515, 107, $top + 32); $this->rayaHorizontal(93, 515, $top + 32);
        $this->pie($arqueo, $top + 42);
    }

    //El logo de Regency centrado en el recuadro (x1..x2, y1..y2) del encabezado.
    //Es una copia en JPG (FPDF no lee PNG con transparencia); si no está, se deja en blanco
    private function logo($x1, $x2, $y1, $y2)
    {
        $rutaLogo = __DIR__ . '/../../public/img/logo_pdf.jpg';
        if (!is_file($rutaLogo)) { return; }
        $alto = ($y2 - $y1) - 4;                 //2 pt de aire arriba y abajo
        $ancho = $alto * (360 / 312);            //proporción del logo
        $this->Image($rutaLogo, $x1 + (($x2 - $x1) - $ancho) / 2, $y1 + 2, $ancho, $alto);
    }

    //Pie del arqueo: número, quién lo hizo y cuándo se registró en HANA
    private function pie($arqueo, $top)
    {
        $this->SetTextColor(120, 120, 120);
        $this->escribir(79, $top, 'Registrado en HANA · Arqueo #' . (int)$arqueo['ID_ARQUEO'] . ' · ' . $arqueo['ARQUEA'] . ' · ' . $arqueo['FEC_REGISTRO'], 5.5);
        $this->SetTextColor(0, 0, 0);
    }

    //Nombre del archivo, como los que usan hoy: "Arqueo caja menor Trapiche 2026-09-16.pdf"
    public static function nombreArchivo($arqueo)
    {
        $tipo = $arqueo['TIPO'] === 'CAJA_MENOR' ? 'caja menor' : ($arqueo['TIPO'] === 'CASETA' ? 'caseta ' . trim((string)$arqueo['CASETA']) : 'recambio');
        $centro = preg_replace('/[^A-Za-z0-9 ]/', '', strtr((string)$arqueo['NOM_CENTRO_OP'],
                  array('á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N')));
        $centro = ucwords(strtolower(trim($centro)));
        return "Arqueo $tipo $centro " . $arqueo['FECHA'] . ((int)$arqueo['ESTADO'] !== 1 ? ' ANULADO' : '') . '.pdf';
    }
}
