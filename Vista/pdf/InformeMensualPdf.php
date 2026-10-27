<?php
/*
  HANA - Informe de gestión, mensual o por rango de fechas (el PDF)
  ---------------------------------------------------------------------------
  Pinta lo que arma Modelo/InformeMensualModelo.php, por temas. Cada tema
  empieza en una hoja nueva con una franja de título (número, nombre y de qué
  trata) y su nombre queda en el encabezado de todas sus hojas:
    1 Resumen general   2 Requisiciones y novedades   3 Arqueos
    4 Reporte diario de los coordinadores   5 Vacantes y oficios   6 Ausentismo
  Dentro de cada tema va primero el total por proyecto y después cada
  proyecto (una franja con su nombre) con sus peajes.
  Todo va en flujo: si algo no cabe en la hoja, sigue en la siguiente (las
  tablas repiten su encabezado). Ningún texto se recorta ni se monta sobre
  otro: si no cabe a lo ancho, pasa al renglón de abajo.

  Hoja carta vertical, medidas en puntos (612 × 792). Usa FPDF 1.9
  (public/fpdf), igual que los arqueos. FPDF escribe en Windows-1252: todo
  texto pasa por textoPdf() para que las tildes salgan bien.
  Los colores son los de la paleta de HANA: vino, menta, ámbar, negro y blanco.

  Notación de las medidas: x y y son posiciones en la hoja (x hacia la derecha,
  y hacia abajo); x1, x2, x3 y y1, y2, y3 son los puntos de una curva, y $this->cy
  es la altura por donde va el contenido de la hoja.
*/
require_once __DIR__ . '/../../public/fpdf/fpdf.php';

class InformeMensualPdf extends FPDF
{
    //Paleta de HANA (rojo, verde, azul)
    const VINO  = array(110, 26, 30);
    const MENTA = array(26, 187, 156);
    const AMBAR = array(217, 156, 43);
    const NEGRO = array(26, 26, 26);
    //Apoyos: texto secundario, fondos suaves y líneas (los mismos tonos de las pantallas)
    const GRIS  = array(107, 112, 118);
    const FONDO = array(251, 248, 246);
    const LINEA = array(236, 230, 225);
    const VINO_SUAVE  = array(245, 228, 229);
    const MENTA_SUAVE = array(228, 246, 242);
    const AMBAR_SUAVE = array(253, 243, 224);

    const M = 40;        //margen izquierdo y derecho
    const ANCHO = 532;   //ancho útil (612 - 2 × 40)

    private $datos;       //lo que devuelve InformeMensual::datos()
    private $quien = '';  //quién generó el informe
    private $cuando = ''; //fecha y hora en que se generó

    public function __construct($datos, $quien)
    {
        parent::__construct('P', 'pt', 'Letter');
        $this->datos = $datos;
        $this->quien = (string)$quien;
        $this->cuando = date('d/m/Y H:i');
        $this->SetMargins(0, 0, 0);
        $this->SetAutoPageBreak(false);  //cada hoja se arma a mano: nada se parte solo
        $this->AliasNbPages('{total}');  //para "Página 1 de 7"
        $this->SetTitle('Informe de gestión - ' . $datos['periodo']['nombre'], true);
        $this->SetAuthor('HANA - Grupo Regency', true);
    }

    //=======================================================================
    // Utilidades de texto y de dibujo
    //=======================================================================

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

    //Colores de texto, relleno y línea a partir de un arreglo [r, g, b]
    private function tinta($color)   { $this->SetTextColor($color[0], $color[1], $color[2]); }
    private function relleno($color) { $this->SetFillColor($color[0], $color[1], $color[2]); }
    private function trazo($color)   { $this->SetDrawColor($color[0], $color[1], $color[2]); }

    //Escribe un texto. $top es el borde superior de la letra.
    //$alin: L (desde $x), R (terminando en $x) o C (centrado entre $x y $x2)
    private function escribir($x, $top, $texto, $tam, $estilo = '', $color = null, $alin = 'L', $x2 = 0)
    {
        $this->SetFont('Helvetica', $estilo, $tam);
        $this->tinta($color === null ? self::NEGRO : $color);
        $textoConvertido = $this->textoPdf($texto);
        $anchoTexto = $this->GetStringWidth($textoConvertido);
        if ($alin === 'R')     { $x = $x - $anchoTexto; }
        elseif ($alin === 'C') { $x = $x + (($x2 - $x) - $anchoTexto) / 2; }
        $this->Text($x, $top + 0.905 * $tam, $textoConvertido); //FPDF pide la línea base de la letra
    }

    //Recorta un texto para que quepa en un ancho, con "..." al final
    private function cabe($texto, $ancho, $tam, $estilo = '')
    {
        $this->SetFont('Helvetica', $estilo, $tam);
        $texto = trim(preg_replace('/\s+/', ' ', (string)$texto));
        if ($this->GetStringWidth($this->textoPdf($texto)) <= $ancho) { return $texto; }
        while ($texto !== '' && $this->GetStringWidth($this->textoPdf($texto . '...')) > $ancho) {
            $texto = function_exists('mb_substr') ? mb_substr($texto, 0, -1, 'UTF-8') : substr($texto, 0, -1);
        }
        return rtrim($texto) . '...';
    }

    //Parte un texto en renglones que quepan en un ancho. Una palabra que sola no cabe
    //(un correo, un código largo) se parte por letras: nada se sale de su espacio
    private function renglones($texto, $ancho, $tam, $estilo = '')
    {
        $this->SetFont('Helvetica', $estilo, $tam);
        $listaRenglones = array(); $linea = '';
        $ancho = max(10, $ancho);
        foreach (preg_split('/\s+/', trim((string)$texto)) as $palabra) {
            if ($palabra === '') { continue; }
            $prueba = $linea === '' ? $palabra : "$linea $palabra";
            if ($this->GetStringWidth($this->textoPdf($prueba)) <= $ancho) { $linea = $prueba; continue; }
            if ($linea !== '') { $listaRenglones[] = $linea; $linea = ''; }
            while ($this->GetStringWidth($this->textoPdf($palabra)) > $ancho) {        //la palabra sola no cabe: se parte
                $letras = 1; $largo = function_exists('mb_strlen') ? mb_strlen($palabra, 'UTF-8') : strlen($palabra);
                while ($letras < $largo && $this->GetStringWidth($this->textoPdf(self::corta($palabra, 0, $letras + 1))) <= $ancho) { $letras++; }
                $listaRenglones[] = self::corta($palabra, 0, $letras);
                $palabra = self::corta($palabra, $letras, null);
            }
            $linea = $palabra;
        }
        if ($linea !== '' || !count($listaRenglones)) { $listaRenglones[] = $linea; }
        return $listaRenglones;
    }
    //Recorta un texto respetando las tildes (usa mb_substr si el servidor lo tiene)
    private static function corta($texto, $desde, $largo)
    {
        return function_exists('mb_substr') ? mb_substr($texto, $desde, $largo, 'UTF-8') : ($largo === null ? substr($texto, $desde) : substr($texto, $desde, $largo));
    }

    //Rectángulo con las esquinas redondeadas. $estilo: F (relleno), D (borde) o DF (los dos)
    private function caja($x, $y, $ancho, $alto, $radio, $estilo = 'F')
    {
        $escala = $this->k; $altoHoja = $this->h;
        $operador = $estilo === 'F' ? 'f' : ($estilo === 'DF' ? 'B' : 'S');
        $arco = 4 / 3 * (sqrt(2) - 1); //cuánto se "abomba" la curva para que parezca un cuarto de círculo
        $this->_out(sprintf('%.2F %.2F m', ($x + $radio) * $escala, ($altoHoja - $y) * $escala));
        $xEsquina = $x + $ancho - $radio; $yEsquina = $y + $radio;
        $this->_out(sprintf('%.2F %.2F l', $xEsquina * $escala, ($altoHoja - $y) * $escala));
        $this->curva($xEsquina + $radio * $arco, $yEsquina - $radio, $xEsquina + $radio, $yEsquina - $radio * $arco, $xEsquina + $radio, $yEsquina);
        $xEsquina = $x + $ancho - $radio; $yEsquina = $y + $alto - $radio;
        $this->_out(sprintf('%.2F %.2F l', ($x + $ancho) * $escala, ($altoHoja - $yEsquina) * $escala));
        $this->curva($xEsquina + $radio, $yEsquina + $radio * $arco, $xEsquina + $radio * $arco, $yEsquina + $radio, $xEsquina, $yEsquina + $radio);
        $xEsquina = $x + $radio; $yEsquina = $y + $alto - $radio;
        $this->_out(sprintf('%.2F %.2F l', $xEsquina * $escala, ($altoHoja - ($y + $alto)) * $escala));
        $this->curva($xEsquina - $radio * $arco, $yEsquina + $radio, $xEsquina - $radio, $yEsquina + $radio * $arco, $xEsquina - $radio, $yEsquina);
        $xEsquina = $x + $radio; $yEsquina = $y + $radio;
        $this->_out(sprintf('%.2F %.2F l', $x * $escala, ($altoHoja - $yEsquina) * $escala));
        $this->curva($xEsquina - $radio, $yEsquina - $radio * $arco, $xEsquina - $radio * $arco, $yEsquina - $radio, $xEsquina, $yEsquina - $radio);
        $this->_out($operador);
    }
    //Dibuja una curva (FPDF no trae una función para esto)
    private function curva($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $escala = $this->k; $altoHoja = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x1 * $escala, ($altoHoja - $y1) * $escala, $x2 * $escala, ($altoHoja - $y2) * $escala, $x3 * $escala, ($altoHoja - $y3) * $escala));
    }

    //1234567 -> "1.234.567"
    private static function num($valor) { return number_format((float)$valor, 0, ',', '.'); }
    //Pesos sin centavos: "$ 1.234.567"
    private static function pesos($valor) { return '$ ' . self::num(round((float)$valor)); }
    //"2026-10-06" -> "06/10/2026"
    private static function fecha($fecha)
    {
        $partes = explode('-', substr((string)$fecha, 0, 10));
        return count($partes) === 3 ? $partes[2] . '/' . $partes[1] . '/' . $partes[0] : (string)$fecha;
    }
    //"1 día" / "5 días"
    private static function dias($cantidad) { $cantidad = (int)$cantidad; return $cantidad . ($cantidad === 1 ? ' día' : ' días'); }
    //Singular o plural según la cantidad: plural(1, 'vacante abierta', 'vacantes abiertas')
    private static function plural($cantidad, $uno, $varios) { return self::num($cantidad) . ' ' . ((int)$cantidad === 1 ? $uno : $varios); }

    //El color de un porcentaje de cumplimiento: bien, regular o mal
    private static function semaforo($pct)
    {
        return $pct >= 90 ? self::MENTA : ($pct >= 60 ? self::AMBAR : self::VINO);
    }

    //=======================================================================
    // Encabezado y pie de todas las hojas (FPDF los llama solo en cada AddPage)
    //=======================================================================
    public function Header()
    {
        $periodo = $this->datos['periodo'];
        //Logo de Regency (copia en JPG: FPDF no lee PNG con transparencia). Si no está, se sigue sin él
        $logo = __DIR__ . '/../../public/img/logo_pdf.jpg';
        $x = self::M;
        if (is_file($logo)) { $this->Image($logo, self::M, 24, 40 * (360 / 312), 40); $x = self::M + 58; }
        //"INFORME MENSUAL DE GESTIÓN" si es un mes exacto; "INFORME DE GESTIÓN" para cualquier otro rango
        $this->escribir($x, 28, $periodo['titulo'], 13, 'B', self::VINO);
        //Qué periodo cubre. Si el nombre ya son las fechas, no se repiten
        $this->escribir($x, 46, $periodo['nombre'] . (strpos($periodo['nombre'], 'Del ') === 0 ? ($periodo['completo'] ? '' : '  ·  hasta hoy') : '  ·  ' . $periodo['detalle']), 9, '', self::GRIS);
        $this->escribir(self::M + self::ANCHO, 29, 'Grupo Regency', 9, 'B', self::NEGRO, 'R');
        //El tema de esta hoja: así se sabe de qué trata cada hoja aunque se imprima suelta
        if ($this->seccionActual !== '') {
            $this->escribir(self::M + self::ANCHO, 44, $this->numSeccion . ' de ' . self::SECCIONES . ' · ' . $this->seccionActual, 8, 'B', self::VINO, 'R');
        } else {
            $this->escribir(self::M + self::ANCHO, 43, 'Sistema HANA · Operación de peajes', 7.5, '', self::GRIS, 'R');
        }
        //Línea vino debajo del encabezado
        $this->relleno(self::VINO);
        $this->Rect(self::M, 72, self::ANCHO, 1.6, 'F');
    }

    //Pie de cada hoja: línea y número de página
    public function Footer()
    {
        $this->relleno(self::LINEA);
        $this->Rect(self::M, 752, self::ANCHO, 0.8, 'F');
        $this->escribir(self::M, 758, 'Generado en HANA el ' . $this->cuando . ($this->quien !== '' ? ' por ' . $this->cabe($this->quien, 260, 7) : '')
                 . '  ·  Datos hasta el ' . self::fecha($this->datos['periodo']['corte']), 7, '', self::GRIS);
        $this->escribir(self::M + self::ANCHO, 758, 'Página ' . $this->PageNo() . ' de {total}', 7, '', self::GRIS, 'R');
    }

    //=======================================================================
    // Piezas que se repiten: título de sección, tarjeta, tabla y barras.
    // Todo va "en flujo": $this->cy dice por dónde va la hoja y, antes de
    // pintar cada renglón, se revisa si cabe; si no, sigue en la hoja
    // siguiente (la tabla repite su encabezado). Ningún texto se recorta:
    // si no cabe a lo ancho, pasa al renglón de abajo.
    //=======================================================================
    const INICIO = 86;   //donde empieza el contenido debajo del encabezado
    const TOPE = 740;    //hasta donde puede llegar el contenido (el pie empieza en 752)

    private $cy = 86; //por dónde va el contenido en la hoja

    //Si lo que sigue no cabe en lo que queda de la hoja, se abre otra
    private function espacio($alto)
    {
        if ($this->cy + $alto > self::TOPE && $this->cy > self::INICIO) { $this->AddPage(); $this->cy = self::INICIO; }
    }

    //Título de una sección, con su explicación al lado (o debajo, si no cabe al lado).
    //$siguiente: lo mínimo que debe caber debajo del título, para que no quede solo al final de una hoja
    private function seccion($titulo, $nota = '', $siguiente = 60)
    {
        $this->SetFont('Helvetica', 'B', 11.5);
        $ancho = $this->GetStringWidth($this->textoPdf($titulo));
        $alLado = $nota === '' ? array() : $this->renglones($nota, self::ANCHO - $ancho - 20, 7.5);
        $debajo = count($alLado) > 1 ? $this->renglones($nota, self::ANCHO - 9, 7.5) : array();
        $alto = 20 + 9.5 * count($debajo);
        $this->espacio($alto + $siguiente);
        $y = $this->cy;
        $this->relleno(self::MENTA);
        $this->caja(self::M, $y + 1.5, 3.5, 11, 1.7); //marquita menta a la izquierda del título
        $this->escribir(self::M + 9, $y, $titulo, 11.5, 'B', self::NEGRO);
        if (count($alLado) === 1) { $this->escribir(self::M + 9 + $ancho + 8, $y + 3, $alLado[0], 7.5, '', self::GRIS); }
        foreach ($debajo as $i => $renglon) { $this->escribir(self::M + 9, $y + 16 + 9.5 * $i, $renglon, 7.5, '', self::GRIS); }
        $this->cy = $y + $alto;
    }

    //Cuánto mide una tarjeta para que quepa todo su detalle
    private function altoTarjeta($ancho, $detalle, $minimo)
    {
        $renglonesDetalle = $detalle === '' ? 0 : count($this->renglones($detalle, $ancho - 22, 6.8));
        return max($minimo, 46 + 8.4 * $renglonesDetalle + 8);
    }

    //Tarjeta con una cifra grande: rótulo arriba, número y el detalle completo
    private function tarjeta($x, $y, $ancho, $alto, $rotulo, $valor, $detalle, $color)
    {
        $this->relleno(self::FONDO);
        $this->caja($x, $y, $ancho, $alto, 6);
        $this->relleno($color);
        $this->caja($x + 6, $y + 9, 3, $alto - 18, 1.5);                      //franja de color a la izquierda
        $renglonesRotulo = $this->renglones(self::mayus($rotulo), $ancho - 22, 6.5, 'B');
        $this->escribir($x + 15, $y + 8, $renglonesRotulo[0], 6.5, 'B', self::GRIS);
        $tam = $alto >= 60 ? 21 : 16;
        $this->SetFont('Helvetica', 'B', $tam);
        while ($tam > 9 && $this->GetStringWidth($this->textoPdf($valor)) > $ancho - 22) { $tam--; $this->SetFont('Helvetica', 'B', $tam); } //si la cifra es larga, se achica
        $this->escribir($x + 15, $y + 19, $valor, $tam, 'B', self::NEGRO);
        if ($detalle !== '') {
            $lineas = $this->renglones($detalle, $ancho - 22, 6.8);
            $top = $y + $alto - 9 - 8.4 * count($lineas);                         //el detalle va pegado abajo
            foreach ($lineas as $i => $renglon) { $this->escribir($x + 15, $top + $i * 8.4, $renglon, 6.8, '', self::GRIS); }
        }
    }

    //Una fila de tarjetas del mismo alto: cada una es array(rótulo, valor, detalle, color)
    private function filaTarjetas($tarjetas, $porFila, $minimo, $sep = 9)
    {
        $anchoTarjeta = (self::ANCHO - ($porFila - 1) * $sep) / $porFila;
        foreach (array_chunk($tarjetas, $porFila) as $fila) {
            $altoFila = $minimo;
            foreach ($fila as $tarjeta) { $altoFila = max($altoFila, $this->altoTarjeta($anchoTarjeta, $tarjeta[2], $minimo)); }
            $this->espacio($altoFila);
            foreach ($fila as $i => $tarjeta) { $this->tarjeta(self::M + $i * ($anchoTarjeta + $sep), $this->cy, $anchoTarjeta, $altoFila, $tarjeta[0], $tarjeta[1], $tarjeta[2], $tarjeta[3]); }
            $this->cy += $altoFila + $sep;
        }
    }

    //Mayúsculas con tildes, sin depender de mbstring
    private static function mayus($texto)
    {
        if (function_exists('mb_strtoupper')) { return mb_strtoupper((string)$texto, 'UTF-8'); }
        return strtr(strtoupper((string)$texto), array('á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ñ' => 'Ñ', 'ü' => 'Ü'));
    }

    //Tabla en flujo.
    //  $columnas: cada columna es array(título, ancho, alineación L/R/C)
    //  $filas: cada celda es un texto, o array('texto' => ..., 'color' => array(r, g, b), 'negrita' => true)
    //  $total: una fila más, en negrita, con fondo suave (o null)
    //Un texto largo ocupa los renglones que necesite (la fila crece); si la fila no cabe en la
    //hoja, pasa entera a la siguiente y allá se repite el encabezado
    private function tabla($columnas, $filas, $total = null, $altoFila = 15)
    {
        $x = self::M;
        $ancho = 0; foreach ($columnas as $columna) { $ancho += $columna[1]; }
        $altoRenglon = 8.8; //alto de cada renglón dentro de una celda
        //El encabezado: los títulos también pueden ir en dos renglones
        $titulos = array(); $renglonesCabecera = 1;
        foreach ($columnas as $columna) { $renglonesTexto = $this->renglones($columna[0], $columna[1] - 8, 6.6, 'B'); $titulos[] = $renglonesTexto; $renglonesCabecera = max($renglonesCabecera, count($renglonesTexto)); }
        $altoCab = max(17, 8 + 7.6 * $renglonesCabecera);
        $pintarCab = function () use ($x, $ancho, $columnas, $titulos, $altoCab) {
            $y = $this->cy;
            $this->relleno(self::VINO);
            $this->caja($x, $y, $ancho, $altoCab, 3);
            $xCelda = $x;
            foreach ($columnas as $j => $columna) {
                $top = $y + ($altoCab - 7.6 * count($titulos[$j])) / 2 + 0.6;
                foreach ($titulos[$j] as $i => $renglon) {
                    $yRenglon = $top + 7.6 * $i;
                    if ($columna[2] === 'L')     { $this->escribir($xCelda + 5, $yRenglon, $renglon, 6.6, 'B', array(255, 255, 255)); }
                    elseif ($columna[2] === 'R') { $this->escribir($xCelda + $columna[1] - 5, $yRenglon, $renglon, 6.6, 'B', array(255, 255, 255), 'R'); }
                    else                   { $this->escribir($xCelda, $yRenglon, $renglon, 6.6, 'B', array(255, 255, 255), 'C', $xCelda + $columna[1]); }
                }
                $xCelda += $columna[1];
            }
            $this->cy = $y + $altoCab;
        };
        if ($total !== null) { $filas[] = array('_total' => $total); } //la fila de totales se pinta como una más, al final
        //Se mide cada fila: sus renglones por celda y su alto
        $medidas = array();
        foreach ($filas as $fila) {
            $esTotal = isset($fila['_total']); $celdas = $esTotal ? $fila['_total'] : $fila;
            $partes = array(); $maxRenglones = 1;
            //Una celda sola en la fila (un aviso como "y 3 más") ocupa todo el ancho
            $unaSola = !$esTotal && count($celdas) === 1 && count($columnas) > 1;
            foreach ($columnas as $j => $columna) {
                $celda = isset($celdas[$j]) ? $celdas[$j] : '';
                $texto = is_array($celda) ? (string)$celda['texto'] : (string)$celda;
                $negrita = $esTotal || (is_array($celda) && !empty($celda['negrita']));
                $renglonesTexto = $texto === '' ? array('') : $this->renglones($texto, ($unaSola ? $ancho : $columna[1]) - 8, 7.4, $negrita ? 'B' : '');
                $partes[] = array($renglonesTexto, $negrita, is_array($celda) && isset($celda['color']) ? $celda['color'] : null, $texto);
                $maxRenglones = max($maxRenglones, count($renglonesTexto));
            }
            $medidas[] = array($esTotal, $partes, max($altoFila, $maxRenglones * $altoRenglon + 6), $unaSola);
        }
        //El encabezado no se deja solo al final de una hoja: va con al menos la primera fila
        $this->espacio($altoCab + (count($medidas) ? $medidas[0][2] : 0));
        $pintarCab();
        foreach ($medidas as $i => $medida) {
            list($esTotal, $partes, $altoMedido, $unaSola) = $medida;
            if ($this->cy + $altoMedido > self::TOPE) {
                $this->AddPage(); $this->cy = self::INICIO;
                //Si la tabla es de un proyecto, la hoja nueva dice de cuál (la franja quedó en la hoja anterior)
                if ($this->continua !== '') { $this->escribir(self::M, $this->cy, $this->continua . ' (continúa)', 9, 'B', self::VINO); $this->cy += 15; }
                $pintarCab();
            }
            $y = $this->cy;
            if ($esTotal)         { $this->relleno(self::VINO_SUAVE); $this->Rect($x, $y, $ancho, $altoMedido, 'F'); }
            elseif ($i % 2 === 1) { $this->relleno(self::FONDO);      $this->Rect($x, $y, $ancho, $altoMedido, 'F'); } //una fila sí y otra no
            $xCelda = $x;
            foreach ($columnas as $j => $columna) {
                list($lineas, $negrita, $color, $texto) = $partes[$j];
                //Un cero no dice nada: se deja en gris claro para que resalte lo que sí tiene valor
                if ($color === null) { $color = ($texto === '0' || $texto === '-') && !$esTotal ? array(190, 186, 182) : self::NEGRO; }
                $top = $y + ($altoMedido - count($lineas) * $altoRenglon) / 2 + 0.6;
                foreach ($lineas as $numRenglon => $renglon) {
                    $yRenglon = $top + $numRenglon * $altoRenglon;
                    if ($columna[2] === 'L' || $unaSola) { $this->escribir($xCelda + 5, $yRenglon, $renglon, 7.4, $negrita ? 'B' : '', $color); }
                    elseif ($columna[2] === 'R')         { $this->escribir($xCelda + $columna[1] - 5, $yRenglon, $renglon, 7.4, $negrita ? 'B' : '', $color, 'R'); }
                    else                           { $this->escribir($xCelda, $yRenglon, $renglon, 7.4, $negrita ? 'B' : '', $color, 'C', $xCelda + $columna[1]); }
                }
                if ($unaSola) { break; }
                $xCelda += $columna[1];
            }
            $this->cy = $y + $altoMedido;
            $this->relleno(self::LINEA); $this->Rect($x, $this->cy - 0.5, $ancho, 0.5, 'F'); //rayita entre filas
        }
    }

    //Barras horizontales: una por cada "nombre => valor", la más larga arriba.
    //Un nombre largo pasa al renglón de abajo. Con $medir = true solo dice cuánto mide
    //$comoFrase: true pasa "LICENCIA REMUNERADA" a "Licencia remunerada"; false deja el nombre como está
    private function barras($x, $y, $ancho, $items, $color, $anchoNombre = 120, $comoFrase = true, $medir = false, $topeFijo = null)
    {
        arsort($items);
        $tope = $topeFijo !== null ? $topeFijo : (count($items) ? max(1, max($items)) : 1);
        $anchoBarra = $ancho - $anchoNombre - 34;           //lo que queda para la barra, dejando campo al número
        $alto = 0;
        foreach ($items as $nombre => $valor) {
            $lineas = $this->renglones($comoFrase ? self::frase($nombre) : $nombre, $anchoNombre - 6, 7.4);
            $altoFila = max(15, 8.6 * count($lineas) + 6.4);
            if (!$medir) {
                foreach ($lineas as $i => $renglon) { $this->escribir($x, $y + 1.5 + 8.6 * $i, $renglon, 7.4, '', self::NEGRO); }
                $this->relleno(self::FONDO);
                $this->caja($x + $anchoNombre, $y, $anchoBarra, 10, 3);                   //el riel de la barra
                if ($valor > 0) {                                                       //en cero no se pinta barra, solo el riel
                    $this->relleno($color);
                    $this->caja($x + $anchoNombre, $y, max(6, $anchoBarra * $valor / $tope), 10, 3);
                }
                $this->escribir($x + $anchoNombre + $anchoBarra + 5, $y + 1.3, self::num($valor), 7.6, 'B', self::NEGRO);
            }
            $y += $altoFila; $alto += $altoFila;
        }
        return $medir ? $alto : $y;
    }

    //Dos bloques de barras lado a lado (con su subtítulo). Si juntos no caben en lo que queda
    //de la hoja, va uno debajo del otro, a todo el ancho
    private function dosBarras($titulo1, $items1, $color1, $anchoNombre1, $comoFrase1, $titulo2, $items2, $color2, $anchoNombre2, $comoFrase2)
    {
        $mitad = (self::ANCHO - 20) / 2;
        $altoMayor = max($this->barras(0, 0, $mitad, $items1, $color1, $anchoNombre1, $comoFrase1, true), $this->barras(0, 0, $mitad, $items2, $color2, $anchoNombre2, $comoFrase2, true));
        if (14 + $altoMayor <= self::TOPE - self::INICIO) {           //cabe en una hoja: lado a lado
            $this->espacio(14 + $altoMayor);
            $this->escribir(self::M, $this->cy, $titulo1, 8, 'B', self::GRIS);
            $this->escribir(self::M + $mitad + 20, $this->cy, $titulo2, 8, 'B', self::GRIS);
            $finIzquierda = $this->barras(self::M, $this->cy + 14, $mitad, $items1, $color1, $anchoNombre1, $comoFrase1);
            $finDerecha = $this->barras(self::M + $mitad + 20, $this->cy + 14, $mitad, $items2, $color2, $anchoNombre2, $comoFrase2);
            $this->cy = max($finIzquierda, $finDerecha);
            return;
        }
        foreach (array(array($titulo1, $items1, $color1, $anchoNombre1, $comoFrase1), array($titulo2, $items2, $color2, $anchoNombre2, $comoFrase2)) as $bloque) {
            $this->espacio(14 + 15);
            $this->escribir(self::M, $this->cy, $bloque[0], 8, 'B', self::GRIS);
            $this->cy += 14;
            arsort($bloque[1]);
            foreach ($bloque[1] as $nombre => $valor) {                    //una barra a la vez, revisando que quepa
                $alto = $this->barras(0, 0, self::ANCHO, array($nombre => $valor), $bloque[2], $bloque[3] + 60, $bloque[4], true);
                $this->espacio($alto);
                $this->barras(self::M, $this->cy, self::ANCHO, array($nombre => $valor), $bloque[2], $bloque[3] + 60, $bloque[4], false, max(1, max($bloque[1])));
                $this->cy += $alto;
            }
            $this->cy += 8;
        }
    }

    //"LICENCIA NO REMUNERADA" -> "Licencia no remunerada" (los catálogos se guardan en mayúsculas)
    private static function frase($texto)
    {
        $texto = (string)$texto;
        if (function_exists('mb_strtolower')) {
            $texto = mb_strtolower($texto, 'UTF-8');
            return mb_strtoupper(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($texto, 1, null, 'UTF-8');
        }
        return ucfirst(strtolower($texto));
    }

    //Aviso suave cuando una sección no tiene datos
    private function vacio($texto, $x = null, $ancho = null, $y = null)
    {
        $x = $x === null ? self::M : $x; $ancho = $ancho === null ? self::ANCHO : $ancho;
        $lineas = $this->renglones($texto, $ancho - 16, 7.6, 'I');
        $alto = 14 + 9 * count($lineas);
        if ($y === null) { $this->espacio($alto); $y = $this->cy; }
        $this->relleno(self::FONDO);
        $this->caja($x, $y, $ancho, $alto, 5);
        foreach ($lineas as $i => $renglon) { $this->escribir($x, $y + 7 + 9 * $i, $renglon, 7.6, 'I', self::GRIS, 'C', $x + $ancho); }
        return $y + $alto + 6;
    }

    //=======================================================================
    // Arma el informe por temas: cada tema empieza en una hoja nueva con su
    // título grande, y su nombre queda en el encabezado de todas sus hojas.
    // Dentro de cada tema va primero el total por proyecto y después el
    // detalle de cada proyecto, peaje por peaje.
    //=======================================================================
    private $seccionActual = ''; //el tema de la hoja, para el encabezado
    private $continua = '';      //el proyecto del que va la tabla, por si sigue en la hoja siguiente
    private $numSeccion = 0;
    const SECCIONES = 6;

    //Arma el informe en orden: resumen, requisiciones, arqueos, reporte diario, vacantes y oficios, y ausentismo
    public function armar()
    {
        $datos = $this->datos;
        $this->resumen($datos);
        if ($datos['periodo']['dias'] === 0) { return; } //el periodo no ha empezado: solo el resumen con el aviso
        $this->requisiciones($datos);
        $this->arqueos($datos);
        $this->reporteDiario($datos);
        $this->vacantesOficios($datos);
        //El ausentismo se retiró del sistema: el informe ya no lleva esa sección.
    }

    //Empieza un tema: hoja nueva y una franja con el número, el título y de qué trata
    private function nuevaSeccion($titulo, $descripcion)
    {
        $this->numSeccion++;
        $this->seccionActual = $titulo;
        $this->continua = '';
        $this->AddPage(); $this->cy = self::INICIO;
        $renglonesDescripcion = $this->renglones($descripcion, self::ANCHO - 84, 8.5);
        $alto = max(58, 38 + 10.5 * count($renglonesDescripcion));
        $y = $this->cy;
        $this->relleno(self::VINO);
        $this->caja(self::M, $y, self::ANCHO, $alto, 8);
        //El número del tema en un círculo blanco
        $this->relleno(array(255, 255, 255));
        $this->caja(self::M + 14, $y + ($alto - 34) / 2, 34, 34, 17);
        $this->escribir(self::M + 14, $y + ($alto - 34) / 2 + 8, (string)$this->numSeccion, 15, 'B', self::VINO, 'C', self::M + 48);
        $this->escribir(self::M + 62, $y + 11, $titulo, 17, 'B', array(255, 255, 255));
        foreach ($renglonesDescripcion as $i => $renglon) { $this->escribir(self::M + 62, $y + 33 + 10.5 * $i, $renglon, 8.5, '', array(240, 222, 223)); }
        $this->cy = $y + $alto + 16;
    }

    //Franja con el nombre de un proyecto dentro de un tema. $siguiente: lo que debe caber debajo
    private function bandaProyecto($proyecto, $extra = '', $siguiente = 60)
    {
        $nombre = $this->renglones($proyecto['NOM_PROYECTO'], 250, 10.5, 'B');
        $renglonesDerecha = $this->renglones('Coordinador: ' . ($proyecto['COORDINADOR'] ? $proyecto['COORDINADOR'] : 'sin asignar') . ($extra !== '' ? '  ·  ' . $extra : ''), self::ANCHO - 290, 7.5);
        $alto = max(24, 12 + max(12 * count($nombre), 9.5 * count($renglonesDerecha)));
        $this->espacio($alto + $siguiente);
        $y = $this->cy;
        $this->relleno(self::VINO_SUAVE);
        $this->caja(self::M, $y, self::ANCHO, $alto, 5);
        $this->relleno(self::VINO);
        $this->caja(self::M, $y, 4, $alto, 2);
        foreach ($nombre as $i => $renglon) { $this->escribir(self::M + 12, $y + 6 + 12 * $i, $renglon, 10.5, 'B', self::VINO); }
        foreach ($renglonesDerecha as $i => $renglon) { $this->escribir(self::M + self::ANCHO - 10, $y + 7.5 + 9.5 * $i, $renglon, 7.5, '', self::GRIS, 'R'); }
        $this->cy = $y + $alto + 8;
        $this->continua = $proyecto['NOM_PROYECTO'];
    }

    //Nombre de un peaje o báscula como sale en las tablas
    private static function nomCentro($centro) { return ($centro['tipo'] === 'BASCULA' ? 'Báscula ' : '') . $centro['nombre']; }

    //Las columnas de una tabla por peaje: el nombre se lleva lo que sobre del ancho
    private static function anchoCompleto($columnas)
    {
        $columnas[0][1] += self::ANCHO - array_sum(array_column($columnas, 1));
        return $columnas;
    }

    //Nota en cursiva al final de un tema
    private function nota($texto)
    {
        $renglonesNota = $this->renglones($texto, self::ANCHO, 7, 'I');
        $this->espacio(9 * count($renglonesNota));
        foreach ($renglonesNota as $renglon) { $this->escribir(self::M, $this->cy, $renglon, 7, 'I', self::GRIS); $this->cy += 9; }
    }

    //-----------------------------------------------------------------------
    // 1. Resumen general: las cifras del periodo, lo más relevante y el comparativo
    //-----------------------------------------------------------------------
    private function resumen($datos)
    {
        $totales = $datos['total']; $periodo = $datos['periodo'];
        $proyectos = $datos['proyectos']; $numProyectos = count($proyectos);
        $this->nuevaSeccion('Resumen general', self::plural($numProyectos, 'proyecto', 'proyectos') . ' · ' . self::plural($datos['centros'], 'peaje o báscula', 'peajes y básculas')
                            . ' · ' . self::dias($periodo['dias']) . ' del periodo. Las cifras de todo el informe en una sola mirada; el detalle de cada tema va en las hojas siguientes.');
        if ($periodo['dias'] === 0) { $this->vacio('Ese periodo todavía no empieza: no hay nada que informar.'); return; }
        $cumple = $this->cumplimientoPromedio($datos);
        $this->filaTarjetas(array(
            array('Requisiciones', self::num($totales['rq']), self::plural($totales['rqAprob'], 'aprobada', 'aprobadas') . ' · ' . self::plural($totales['rqRech'], 'rechazada', 'rechazadas') . ' · ' . self::num($totales['rqPend']) . ' por aprobar', self::VINO),
            array('Novedades', self::num($totales['nov']), self::plural($totales['novCerr'], 'atendida', 'atendidas') . ' · ' . self::plural($totales['novAb'], 'abierta', 'abiertas'), self::VINO),
            array('Listas de chequeo', self::num($totales['listas']), 'diligenciadas en el periodo', self::MENTA),
            array('Arqueos', self::num($totales['arq']), $totales['arqDif'] > 0 ? self::num($totales['arqDif']) . ' con diferencia' : ($totales['arq'] > 0 ? 'todos cuadraron' : 'sin arqueos en el periodo'), self::MENTA),
            array('Vacantes abiertas', self::num($totales['vacAb']), self::plural($totales['vacNuevas'], 'nueva', 'nuevas') . ' · ' . self::plural($totales['vacCub'], 'cubierta', 'cubiertas') . ' en el periodo', self::AMBAR),
            array('Oficios recibidos', self::num($totales['ofRec']), self::plural($totales['ofRes'], 'atendido', 'atendidos') . ' · ' . self::plural($totales['ofPend'], 'pendiente', 'pendientes') . ' hoy', self::AMBAR),
            array('Reporte diario', $cumple . ' %', 'días con "Hoy en qué estás", promedio de los coordinadores', self::semaforo($cumple)),
        ), 4, 70);
        $this->cy += 8;

        //Lo más relevante: frases armadas con las cifras (cada una en los renglones que necesite)
        $frases = array(); $alto = 12;
        foreach ($this->relevante($datos) as $fraseRelevante) { $renglonesFrase = $this->renglones($fraseRelevante[1], self::ANCHO - 34, 8); $frases[] = array($fraseRelevante[0], $renglonesFrase); $alto += 11 * count($renglonesFrase) + 2; }
        $this->seccion('Lo más relevante', '', min($alto, 200));
        $this->espacio($alto);
        $this->relleno(self::FONDO);
        $this->caja(self::M, $this->cy - 2, self::ANCHO, $alto, 6);
        $y = $this->cy + 5;
        foreach ($frases as $frase) {
            $this->relleno($frase[0]);
            $this->caja(self::M + 11, $y + 2.2, 4.5, 4.5, 2.2);              //puntico del color del tema
            foreach ($frase[1] as $i => $renglon) { $this->escribir(self::M + 22, $y + 11 * $i, $renglon, 8, '', self::NEGRO); }
            $y += 11 * count($frase[1]) + 2;
        }
        $this->cy += $alto + 12;

        //Comparativo por proyecto: una mirada rápida a todo
        $this->seccion('Comparativo por proyecto', 'Lo registrado en el periodo. Vacantes y oficios: los que siguen abiertos hoy.');
        $columnas = array(array('Proyecto', 112, 'L'), array('RQ', 36, 'R'), array('Por aprobar', 46, 'R'), array('Novedades', 44, 'R'), array('Listas', 34, 'R'),
                      array('Arqueos', 38, 'R'), array('Con dif.', 36, 'R'), array('Vacantes', 40, 'R'), array('Oficios pend.', 50, 'R'),
                      array('Reporte', 52, 'R'));
        $filas = array();
        foreach ($proyectos as $proyecto) {
            $kpi = $proyecto['kpi']; $cumpleProyecto = $proyecto['reporte']['cumple'];
            $filas[] = array($proyecto['NOM_PROYECTO'], self::num($kpi['rq']), $this->aviso($kpi['rqPend'], self::AMBAR),
                             self::num($kpi['nov']), self::num($kpi['listas']), self::num($kpi['arq']), $this->aviso($kpi['arqDif'], self::VINO),
                             self::num($kpi['vacAb']), self::num($kpi['ofPend']),
                             array('texto' => $cumpleProyecto . ' %', 'color' => self::semaforo($cumpleProyecto), 'negrita' => true));
        }
        $total = $numProyectos > 1 ? array('Total', self::num($totales['rq']), self::num($totales['rqPend']), self::num($totales['nov']), self::num($totales['listas']), self::num($totales['arq']),
                                 self::num($totales['arqDif']), self::num($totales['vacAb']), self::num($totales['ofPend']), $cumple . ' %') : null;
        $this->tabla($columnas, $filas, $total, 16);
    }

    //-----------------------------------------------------------------------
    // 2. Requisiciones y novedades
    //-----------------------------------------------------------------------
    private function requisiciones($datos)
    {
        $totales = $datos['total']; $proyectos = $datos['proyectos'];
        $this->nuevaSeccion('Requisiciones y novedades', 'Las RQ pedidas y las novedades registradas en el periodo, según cómo van hoy. Primero el total por proyecto; después cada proyecto, peaje por peaje, con las RQ que siguen por aprobar.');
        $columnas = array(array('Proyecto', 116, 'L'), array('RQ pedidas', 52, 'R'), array('Urgentes', 46, 'R'), array('Aprobadas', 50, 'R'), array('Rechazadas', 54, 'R'),
                      array('Por aprobar', 54, 'R'), array('Novedades', 54, 'R'), array('Atendidas', 52, 'R'), array('Abiertas', 54, 'R'));
        $fila = function ($nombre, $kpi) {
            return array($nombre, self::num($kpi['rq']), self::num($kpi['rqUrg']), self::num($kpi['rqAprob']), self::num($kpi['rqRech']),
                         $this->aviso($kpi['rqPend'], self::AMBAR), self::num($kpi['nov']), self::num($kpi['novCerr']), $this->aviso($kpi['novAb'], self::AMBAR));
        };
        $filas = array();
        foreach ($proyectos as $proyecto) { $filas[] = $fila($proyecto['NOM_PROYECTO'], $proyecto['kpi']); }
        $this->tabla($columnas, $filas, count($proyectos) > 1 ? array_map(function ($celda) { return is_array($celda) ? $celda['texto'] : $celda; }, $fila('Total', $totales)) : null, 16);
        $this->cy += 16;

        $columnas[0][0] = 'Peaje o báscula';
        foreach ($proyectos as $proyecto) {
            $this->bandaProyecto($proyecto, self::plural($proyecto['kpi']['rq'], 'RQ', 'RQ') . ' · ' . self::plural($proyecto['kpi']['nov'], 'novedad', 'novedades'));
            $filas = array();
            foreach ($proyecto['centros'] as $centro) { $filas[] = $fila(self::nomCentro($centro), isset($centro['kpi']) ? $centro['kpi'] : $this->datos['ceros']); }
            if (!count($filas)) { $this->cy = $this->vacio('Este proyecto no tiene peajes activos.') + 6; continue; }
            $this->tabla(self::anchoCompleto($columnas), $filas, null, 14.5);
            $this->cy += 8;
            $pendientes = $proyecto['pendientes'];
            if (count($pendientes['rq'])) {
                $this->espacio(14 + 32);
                $this->subtitulo(self::M, $this->cy, 'RQ que siguen por aprobar');
                $this->cy += 18;
                $filas = array();
                foreach ($pendientes['rq'] as $rqPendiente) {
                    $vencida = (int)$rqPendiente['DIAS'] > $pendientes['plazoRq'] || $rqPendiente['TIPO_RQ'] === 'U';
                    $filas[] = array(($rqPendiente['TIPO_RQ'] === 'U' ? 'RQ U ' : 'RQ ') . $rqPendiente['NUMERO_RQ'], $rqPendiente['CENTRO'],
                                     array('texto' => self::dias($rqPendiente['DIAS']), 'color' => $vencida ? self::VINO : self::GRIS, 'negrita' => $vencida));
                }
                $this->tabla(array(array('RQ', 120, 'L'), array('Peaje o báscula', 342, 'L'), array('Lleva', 70, 'R')), $filas, null, 14);
                $this->cy += 8;
            }
            $this->cy += 8;
        }
        $this->nota('Plazo para aprobar una RQ: ' . self::dias($datos['plazoRq']) . '. Las urgentes y las que pasaron el plazo salen en vino. Se cambia en Configuración, Parámetros del sistema.');
    }

    //-----------------------------------------------------------------------
    // 3. Arqueos
    //-----------------------------------------------------------------------
    private function arqueos($datos)
    {
        $totales = $datos['total']; $proyectos = $datos['proyectos'];
        $this->nuevaSeccion('Arqueos', 'Los arqueos del periodo (caja menor, recambio y casetas) y los que no cuadraron con el fondo autorizado. Primero el total por proyecto; después cada proyecto, peaje por peaje.');
        $columnas = array(array('Proyecto', 172, 'L'), array('Arqueos', 70, 'R'), array('Con diferencia', 80, 'R'), array('Faltantes', 105, 'R'), array('Sobrantes', 105, 'R'));
        $fila = function ($nombre, $kpi) {
            return array($nombre, self::num($kpi['arq']), $this->aviso($kpi['arqDif'], self::VINO),
                         $kpi['arqFalt'] > 0 ? array('texto' => self::pesos($kpi['arqFalt']), 'color' => self::VINO, 'negrita' => true) : '-',
                         $kpi['arqSobr'] > 0 ? array('texto' => self::pesos($kpi['arqSobr']), 'color' => self::AMBAR, 'negrita' => true) : '-');
        };
        $filas = array();
        foreach ($proyectos as $proyecto) { $filas[] = $fila($proyecto['NOM_PROYECTO'], $proyecto['kpi']); }
        $this->tabla($columnas, $filas, count($proyectos) > 1 ? array_map(function ($celda) { return is_array($celda) ? $celda['texto'] : $celda; }, $fila('Total', $totales)) : null, 16);
        $this->cy += 16;

        $columnas[0][0] = 'Peaje o báscula';
        $tipos = array('CAJA_MENOR' => 'Caja menor', 'RECAMBIO' => 'Recambio', 'CASETA' => 'Caseta');
        foreach ($proyectos as $proyecto) {
            $kpi = $proyecto['kpi'];
            $this->bandaProyecto($proyecto, self::plural($kpi['arq'], 'arqueo', 'arqueos') . ($kpi['arqDif'] > 0 ? ', ' . self::num($kpi['arqDif']) . ' con diferencia' : ''));
            $filas = array();
            foreach ($proyecto['centros'] as $centro) { $filas[] = $fila(self::nomCentro($centro), isset($centro['kpi']) ? $centro['kpi'] : $this->datos['ceros']); }
            if (!count($filas)) { $this->cy = $this->vacio('Este proyecto no tiene peajes activos.') + 6; continue; }
            $this->tabla($columnas, $filas, null, 14.5);
            $this->cy += 8;
            //Los que no cuadraron: todos, los de mayor diferencia primero
            $this->espacio(14 + 32);
            $this->subtitulo(self::M, $this->cy, 'Arqueos que no cuadraron');
            $this->cy += 18;
            if (!count($proyecto['arqueosDif'])) {
                $this->cy = $this->vacio($kpi['arq'] > 0 ? 'Todos los arqueos del periodo cuadraron.' : 'No se registraron arqueos en el periodo.') + 10;
                continue;
            }
            $filas = array();
            foreach ($proyecto['arqueosDif'] as $arqueo) {
                $falta = (float)$arqueo['DIFERENCIA'] < 0;
                $filas[] = array(self::fecha($arqueo['FECHA']), $arqueo['CENTRO'],
                                 self::tipoArqueo($arqueo, $tipos),
                                 (string)$arqueo['RESPONSABLE'],
                                 array('texto' => ($falta ? 'Faltante ' : 'Sobrante ') . self::pesos(abs((float)$arqueo['DIFERENCIA'])), 'color' => $falta ? self::VINO : self::AMBAR, 'negrita' => true));
            }
            $this->tabla(array(array('Fecha', 62, 'L'), array('Peaje', 120, 'L'), array('Tipo', 96, 'L'), array('Responsable', 150, 'L'), array('Diferencia', 104, 'R')), $filas, null, 14);
            $this->cy += 16;
        }
    }

    //-----------------------------------------------------------------------
    // 4. Reporte diario de los coordinadores
    //-----------------------------------------------------------------------
    private function reporteDiario($datos)
    {
        $periodo = $datos['periodo']; $proyectos = $datos['proyectos'];
        $this->nuevaSeccion('Reporte diario de los coordinadores', 'Hoy en qué estás, visitas del cronograma, listas de chequeo y vehículo, de los ' . self::dias($periodo['dias'])
                            . ' del periodo. Primero la comparación entre coordinadores; después el detalle de cada uno.');
        $columnas = array(array('Proyecto', 92, 'L'), array('Coordinador', 128, 'L'), array('Hoy en qué estás', 70, 'R'), array('Cumple', 44, 'R'),
                      array('Visitas hechas', 62, 'R'), array('Listas', 38, 'R'), array('Arqueos', 42, 'R'), array('Sin vehículo', 56, 'R'));
        $filas = array();
        foreach ($proyectos as $proyecto) {
            $reporte = $proyecto['reporte'];
            $filas[] = array($proyecto['NOM_PROYECTO'], $proyecto['COORDINADOR'] ? $proyecto['COORDINADOR'] : array('texto' => 'Sin asignar', 'color' => self::VINO),
                             $reporte['hoyDias'] . ' de ' . $reporte['dias'], array('texto' => $reporte['cumple'] . ' %', 'color' => self::semaforo($reporte['cumple']), 'negrita' => true),
                             $reporte['visProg'] > 0 ? $reporte['visReal'] . ' de ' . $reporte['visProg'] : '-',
                             self::num($proyecto['kpi']['listas']), self::num($proyecto['kpi']['arq']),
                             $reporte['vehNo'] > 0 ? array('texto' => self::dias($reporte['vehNo']), 'color' => self::AMBAR, 'negrita' => true) : '0');
        }
        $this->tabla($columnas, $filas, null, 16);
        $this->cy += 16;

        foreach ($proyectos as $proyecto) {
            $kpi = $proyecto['kpi']; $reporte = $proyecto['reporte'];
            $this->bandaProyecto($proyecto, $reporte['cumple'] . ' % de cumplimiento', 125); //el bloque del coordinador va entero en una hoja
            $filas = array(
                array('Hoy en qué estás', array('texto' => $reporte['hoyDias'] . ' de ' . self::dias($reporte['dias']) . ' (' . $reporte['cumple'] . ' %)', 'color' => self::semaforo($reporte['cumple']), 'negrita' => true)),
                array('Visitas del cronograma', $reporte['visProg'] > 0 ? $reporte['visReal'] . ' hechas de ' . $reporte['visProg'] . ' planeadas' : 'Sin visitas planeadas'),
                array('Listas de chequeo', self::plural($kpi['listas'], 'diligenciada', 'diligenciadas')),
                array('Arqueos', self::plural($kpi['arq'], 'arqueo', 'arqueos') . ($kpi['arqDif'] > 0 ? ', ' . self::num($kpi['arqDif']) . ' con diferencia' : '')),
                array('Vehículo', ($reporte['vehOk'] + $reporte['vehNo']) > 0 ? array('texto' => self::dias($reporte['vehOk']) . ' operativo, ' . self::dias($reporte['vehNo']) . ' sin operar', 'color' => $reporte['vehNo'] > 0 ? self::AMBAR : self::NEGRO, 'negrita' => $reporte['vehNo'] > 0) : 'Sin reporte del vehículo'),
                array('Días sin "Hoy en qué estás"', count($reporte['sinHoy']) ? array('texto' => implode(', ', $reporte['sinHoy']), 'color' => self::VINO) : 'Ninguno: registró todos los días'),
            );
            $this->tabla(array(array('Qué', 170, 'L'), array('Resultado', 362, 'L')), $filas, null, 14);
            $this->cy += 16;
        }
    }

    //-----------------------------------------------------------------------
    // 5. Vacantes y oficios
    //-----------------------------------------------------------------------
    private function vacantesOficios($datos)
    {
        $totales = $datos['total']; $proyectos = $datos['proyectos'];
        $this->nuevaSeccion('Vacantes y oficios', 'Las vacantes y las comunicaciones u oficios del periodo, y lo que sigue abierto hoy (de este periodo o de antes), lo más antiguo primero.');
        $columnas = array(array('Proyecto', 92, 'L'), array('Vacantes nuevas', 54, 'R'), array('Cubiertas', 48, 'R'), array('Abiertas hoy', 52, 'R'), array('Fuera del acuerdo', 62, 'R'),
                      array('Oficios recibidos', 58, 'R'), array('Atendidos', 52, 'R'), array('Sin atender', 52, 'R'), array('Fuera de plazo', 62, 'R'));
        $fila = function ($nombre, $kpi) {
            return array($nombre, self::num($kpi['vacNuevas']), self::num($kpi['vacCub']), self::num($kpi['vacAb']), $this->aviso($kpi['vacFuera'], self::VINO),
                         self::num($kpi['ofRec']), self::num($kpi['ofRes']), self::num($kpi['ofPend']), $this->aviso($kpi['ofVenc'], self::VINO));
        };
        $filas = array();
        foreach ($proyectos as $proyecto) { $filas[] = $fila($proyecto['NOM_PROYECTO'], $proyecto['kpi']); }
        $this->tabla($columnas, $filas, count($proyectos) > 1 ? array_map(function ($celda) { return is_array($celda) ? $celda['texto'] : $celda; }, $fila('Total', $totales)) : null, 16);
        $this->cy += 16;

        foreach ($proyectos as $proyecto) {
            $pendientes = $proyecto['pendientes'];
            $this->bandaProyecto($proyecto, self::plural(count($pendientes['vacantes']), 'vacante abierta', 'vacantes abiertas') . ' · ' . self::plural(count($pendientes['oficios']), 'oficio sin atender', 'oficios sin atender'));
            $filas = array();
            foreach ($pendientes['vacantes'] as $vacante) {
                $fuera = (int)$vacante['FUERA'] === 1;
                $filas[] = array('Vacante abierta', self::frase($vacante['CARGO']) . ' · ' . $vacante['CENTRO'], array('texto' => self::dias($vacante['DIAS']), 'color' => $fuera ? self::VINO : self::GRIS, 'negrita' => $fuera));
            }
            foreach ($pendientes['oficios'] as $oficio) {
                $tarde = (int)$oficio['DIAS'] > $pendientes['plazoOficio'];
                $filas[] = array('Oficio sin atender', ($oficio['RADICADO'] ? $oficio['RADICADO'] . ' · ' : '') . $oficio['ASUNTO'], array('texto' => self::dias($oficio['DIAS']), 'color' => $tarde ? self::VINO : self::GRIS, 'negrita' => $tarde));
            }
            if (!count($filas)) { $this->cy = $this->vacio('No hay vacantes abiertas ni oficios sin atender.') + 12; continue; }
            $this->tabla(array(array('Qué', 96, 'L'), array('Detalle', 366, 'L'), array('Lleva', 70, 'R')), $filas, null, 14);
            $this->cy += 16;
        }
        $this->nota('Plazo para atender un oficio: ' . self::dias($datos['plazoOficio']) . '. Las vacantes se miden contra su acuerdo de servicio. En vino, lo que ya pasó el plazo. Se cambian en Configuración, Parámetros del sistema.');
    }

    //-----------------------------------------------------------------------
    // 6. Ausentismo
    //-----------------------------------------------------------------------
    private function ausentismo($datos)
    {
        //El ausentismo (matriz de cubrimiento) se retiró del sistema. El método se conserva
        //inerte para no romper llamadas antiguas; ya no se invoca desde armar().
    }

    //Una cifra que pide atención: si es mayor que cero sale en negrita y con color; si no, un cero normal
    private function aviso($cantidad, $color)
    {
        return $cantidad > 0 ? array('texto' => self::num($cantidad), 'color' => $color, 'negrita' => true) : '0';
    }

    //El promedio del cumplimiento de "Hoy en qué estás" de los coordinadores
    private function cumplimientoPromedio($datos)
    {
        $numProyectos = count($datos['proyectos']);
        if (!$numProyectos) { return 0; }
        $suma = 0; foreach ($datos['proyectos'] as $proyecto) { $suma += $proyecto['reporte']['cumple']; }
        return (int)round($suma / $numProyectos);
    }

    //Las frases de "Lo más relevante": array(color, texto)
    private function relevante($datos)
    {
        $totales = $datos['total']; $frases = array();
        //Requisiciones
        if ($totales['rq'] > 0) {
            $frases[] = array(self::VINO, ($totales['rq'] === 1 ? 'Se pidió ' : 'Se pidieron ') . self::plural($totales['rq'], 'requisición', 'requisiciones')
                         . ($totales['rqUrg'] > 0 ? ' (' . self::plural($totales['rqUrg'], 'urgente', 'urgentes') . ')' : '')
                         . ': ' . self::plural($totales['rqAprob'], 'aprobada', 'aprobadas') . ', ' . self::plural($totales['rqRech'], 'rechazada', 'rechazadas') . ' y ' . self::num($totales['rqPend']) . ' por aprobar.');
        } else { $frases[] = array(self::VINO, 'No se pidieron requisiciones en el periodo.'); }
        //Arqueos
        if ($totales['arq'] > 0) {
            $frases[] = array(self::MENTA, $totales['arqDif'] > 0
                ? 'De ' . self::plural($totales['arq'], 'arqueo', 'arqueos') . ', ' . ($totales['arqDif'] === 1 ? '1 no cuadró' : self::num($totales['arqDif']) . ' no cuadraron')
                  . ': faltantes por ' . self::pesos($totales['arqFalt']) . ' y sobrantes por ' . self::pesos($totales['arqSobr']) . '.'
                : ($totales['arq'] === 1 ? 'Se hizo 1 arqueo y cuadró.' : 'Se hicieron ' . self::num($totales['arq']) . ' arqueos y todos cuadraron.'));
        } else { $frases[] = array(self::MENTA, 'No se registraron arqueos en el periodo.'); }
        //Vacantes
        $frases[] = array(self::AMBAR, $totales['vacAb'] > 0
            ? 'Hay ' . self::plural($totales['vacAb'], 'vacante abierta', 'vacantes abiertas') . '; ' . ($totales['vacFuera'] === 1 ? '1 ya pasó' : self::num($totales['vacFuera']) . ' ya pasaron') . ' la fecha del acuerdo de servicio.'
            : 'No hay vacantes abiertas.');
        //Oficios
        $frases[] = array(self::AMBAR, $totales['ofPend'] > 0
            ? 'Hay ' . self::plural($totales['ofPend'], 'oficio sin atender', 'oficios sin atender') . '; ' . ($totales['ofVenc'] === 1 ? '1 lleva' : self::num($totales['ofVenc']) . ' llevan') . ' más de ' . self::dias($datos['plazoOficio']) . '.'
            : 'Todos los oficios y comunicaciones están atendidos.');
        //El ausentismo se retiró: ya no hay frase de ausencias en el resumen.
        //Reporte diario: el coordinador que menos reportó
        if (count($datos['proyectos']) > 1) {
            $menor = null;
            foreach ($datos['proyectos'] as $proyecto) { if ($menor === null || $proyecto['reporte']['cumple'] < $menor['reporte']['cumple']) { $menor = $proyecto; } }
            $frases[] = array(self::semaforo($menor['reporte']['cumple']), 'Reporte diario: el cumplimiento más bajo de "Hoy en qué estás" fue el de ' . $menor['NOM_PROYECTO']
                         . ' (' . $menor['reporte']['cumple'] . ' %, ' . $menor['reporte']['hoyDias'] . ' de ' . self::dias($menor['reporte']['dias']) . ').');
        }
        return $frases;
    }

    //Subtítulo pequeño dentro de una hoja
    private function subtitulo($x, $y, $texto)
    {
        $this->escribir($x, $y, $texto, 9, 'B', self::NEGRO);
        $this->relleno(self::LINEA); $this->Rect($x, $y + 13, 60, 1, 'F');
    }

    //Nombre del archivo: "Informe de gestión HANA 2026-10.pdf" (un mes) o
    //"Informe de gestión HANA 2026-08-01 a 2026-10-06.pdf" (otro rango)
    public static function nombreArchivo($periodo)
    {
        if (!empty($periodo['mensual'])) { return 'Informe de gestion HANA ' . sprintf('%04d-%02d', $periodo['anio'], $periodo['mes']) . '.pdf'; }
        return 'Informe de gestion HANA ' . $periodo['desde'] . ' a ' . $periodo['corte'] . '.pdf';
    }

    //"Caseta 4" en vez de "Caseta Caseta 4" cuando el nombre de la caseta ya dice "caseta"
    private static function tipoArqueo($arqueo, $tipos)
    {
        $nombreTipo = isset($tipos[$arqueo['TIPO']]) ? $tipos[$arqueo['TIPO']] : $arqueo['TIPO'];
        if ($arqueo['TIPO'] !== 'CASETA' || !$arqueo['CASETA']) { return $nombreTipo; }
        return stripos(trim($arqueo['CASETA']), 'caseta') === 0 ? trim($arqueo['CASETA']) : $nombreTipo . ' ' . trim($arqueo['CASETA']);
    }
}
