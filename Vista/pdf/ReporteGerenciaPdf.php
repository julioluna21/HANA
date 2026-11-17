<?php
/*
  HANA - Reporte a la Gerencia (el PDF): Informe de gestión de la Dirección Operativa P&B
  ---------------------------------------------------------------------------
  Pinta lo que arma Control/ReporteGerenciaPdfControl.php, con la estructura del
  informe que se envía a la Gerencia y el diseño de HANA (logo, franja de colores,
  recuadros suaves y la paleta vino, menta y ámbar):

    - la fecha, el título, el destinatario, «Cordial saludo:» y la presentación;
    - 1. Resultados de la operación: el cumplimiento de la promesa de valor, con
      una tarjeta (la cifra que dice el texto) y la gráfica por proyecto;
    - 2. Gestiones prioritarias y compromisos con clientes: «PROYECTO | Asunto»;
    - 3. Avances de gestión por proyecto: una franja por proyecto y sus apartados;
    - 4. Gestión transversal | Talento humano y SST;
    - 5. Compromisos y seguimiento: tabla de gestión y fecha objetivo;
    - «Atentamente,», el nombre y el cargo de quien firma.

  Los textos salen tal como los dejó la dirección. Los renglones que empiezan
  con «•» o «–» salen como viñetas; lo demás, en párrafos justificados.

  Todo va en flujo: si algo no cabe en la hoja, sigue en la siguiente. Ningún
  texto se recorta. Hoja carta vertical, medidas en puntos (612 × 792), con
  FPDF 1.9 (public/fpdf), igual que el informe mensual y los arqueos. FPDF
  escribe en Windows-1252: todo texto pasa por textoPdf() para que las tildes
  salgan bien.
*/
require_once __DIR__ . '/../../public/fpdf/fpdf.php';

class ReporteGerenciaPdf extends FPDF
{
    //Paleta de HANA
    const VINO  = array(110, 26, 30);
    const MENTA = array(26, 187, 156);
    const AMBAR = array(217, 156, 43);
    const NEGRO = array(26, 26, 26);
    const GRIS  = array(107, 112, 118);
    const BLANCO = array(255, 255, 255);
    const FONDO = array(251, 248, 246);
    const LINEA = array(236, 230, 225);
    const VINO_SUAVE  = array(245, 228, 229);
    const MENTA_SUAVE = array(228, 246, 242);
    const AMBAR_SUAVE = array(253, 243, 224);
    const GRIS_SUAVE  = array(242, 242, 243);

    const M = 40;          //margen izquierdo y derecho
    const ANCHO = 532;     //ancho útil (612 - 2 × 40)
    const INICIO = 86;     //donde empieza el contenido, debajo del encabezado
    const TOPE = 740;      //hasta donde llega el contenido (el pie empieza en 752)

    private $r;            //los datos del reporte
    private $quien = '';   //quién genera el PDF (la persona que lo descarga)
    private $rol = '';     //su rol
    private $cuando = '';  //cuándo se generó
    private $cy = 86;      //por dónde va el contenido en la hoja
    private $continua = '';//nombre del proyecto que sigue en la hoja nueva, si se parte

    public function __construct($reporte, $quien, $rol = '')
    {
        parent::__construct('P', 'pt', 'Letter');
        $this->r = $reporte;
        $this->quien = (string)$quien;
        $this->rol = (string)$rol;
        $this->cuando = date('d/m/Y H:i');
        $this->SetMargins(0, 0, 0);
        $this->SetAutoPageBreak(false);
        $this->AliasNbPages('{total}');
        $this->SetTitle('Informe de gestión - ' . (!empty($reporte['esRango']) ? $reporte['desde'] . ' a ' : '') . $reporte['fecha'], true);
        $this->SetAuthor('HANA - Grupo Regency', true);
    }

    //Nombre del archivo: Reporte_Gerencia_2026-10-29.pdf
    //Con rango de días: Reporte_Gerencia_2026-11-10_a_2026-11-14.pdf
    public static function nombreArchivo($fecha, $desde = null) { return 'Reporte_Gerencia_' . ($desde && $desde < $fecha ? $desde . '_a_' : '') . $fecha . '.pdf'; }

    //Las palabras que cambian si el reporte es de un día o de un rango de días
    private function periodo($deUnDia, $deRango) { return !empty($this->r['esRango']) ? $deRango : $deUnDia; }

    //=======================================================================
    // Utilidades de texto y de dibujo
    //=======================================================================
    private function textoPdf($texto)
    {
        $texto = html_entity_decode((string)$texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (function_exists('iconv')) {
            $convertido = @iconv('UTF-8', 'windows-1252//TRANSLIT', $texto);
            if ($convertido !== false) { return $convertido; }
        }
        if (function_exists('mb_convert_encoding')) { return mb_convert_encoding($texto, 'windows-1252', 'UTF-8'); }
        return $texto;
    }
    private function tinta($c)   { $this->SetTextColor($c[0], $c[1], $c[2]); }
    private function relleno($c) { $this->SetFillColor($c[0], $c[1], $c[2]); }
    private function trazo($c)   { $this->SetDrawColor($c[0], $c[1], $c[2]); }

    //Escribe un texto; $top es el borde superior de la letra. $alin: L, R (termina en $x) o C (centrado entre $x y $x2)
    private function escribir($x, $top, $texto, $tam, $estilo = '', $color = null, $alin = 'L', $x2 = 0)
    {
        $this->SetFont('Helvetica', $estilo, $tam);
        $this->tinta($color === null ? self::NEGRO : $color);
        $t = $this->textoPdf($texto);
        $ancho = $this->GetStringWidth($t);
        if ($alin === 'R')     { $x -= $ancho; }
        elseif ($alin === 'C') { $x += (($x2 - $x) - $ancho) / 2; }
        $this->Text($x, $top + 0.905 * $tam, $t);
    }

    //Escribe un renglón JUSTIFICADO: reparte entre las palabras el espacio que sobra, para que el
    //renglón llegue exacto al borde derecho ($x + $ancho), como un párrafo justificado de Word.
    //El último renglón de cada párrafo ($ultimo = true) va normal, alineado a la izquierda, porque
    //estirarlo dejaría huecos grandes. Si el renglón tiene muy pocas palabras y los huecos quedarían
    //exagerados (más de 4 veces un espacio normal), también se deja normal.
    private function escribirJustificado($x, $top, $renglon, $ancho, $tam, $estilo = '', $color = null, $ultimo = false)
    {
        $palabras = preg_split('/\s+/u', trim((string)$renglon), -1, PREG_SPLIT_NO_EMPTY);
        if ($ultimo || count($palabras) < 2) { $this->escribir($x, $top, $renglon, $tam, $estilo, $color); return; }
        $this->SetFont('Helvetica', $estilo, $tam);
        $this->tinta($color === null ? self::NEGRO : $color);
        $anchos = array();
        foreach ($palabras as $palabra) { $anchos[] = $this->GetStringWidth($this->textoPdf($palabra)); }
        $hueco = ($ancho - array_sum($anchos)) / (count($palabras) - 1);
        if ($hueco > 4 * $this->GetStringWidth(' ')) { $this->escribir($x, $top, $renglon, $tam, $estilo, $color); return; }
        $cx = $x;
        foreach ($palabras as $i => $palabra) {
            $this->Text($cx, $top + 0.905 * $tam, $this->textoPdf($palabra));
            $cx += $anchos[$i] + $hueco;
        }
    }

    private function anchoTexto($texto, $tam, $estilo = '')
    {
        $this->SetFont('Helvetica', $estilo, $tam);
        return $this->GetStringWidth($this->textoPdf($texto));
    }

    //Parte un texto en renglones que quepan en un ancho (una palabra muy larga se parte por letras)
    private function renglones($texto, $ancho, $tam, $estilo = '')
    {
        $this->SetFont('Helvetica', $estilo, $tam);
        $lista = array(); $linea = '';
        $ancho = max(10, $ancho);
        foreach (preg_split('/\s+/u', trim((string)$texto)) as $palabra) {
            if ($palabra === '') { continue; }
            $prueba = $linea === '' ? $palabra : "$linea $palabra";
            if ($this->GetStringWidth($this->textoPdf($prueba)) <= $ancho) { $linea = $prueba; continue; }
            if ($linea !== '') { $lista[] = $linea; $linea = ''; }
            while ($this->GetStringWidth($this->textoPdf($palabra)) > $ancho) {
                $letras = 1; $largo = mb_strlen($palabra, 'UTF-8');
                while ($letras < $largo && $this->GetStringWidth($this->textoPdf(mb_substr($palabra, 0, $letras + 1, 'UTF-8'))) <= $ancho) { $letras++; }
                $lista[] = mb_substr($palabra, 0, $letras, 'UTF-8');
                $palabra = mb_substr($palabra, $letras, null, 'UTF-8');
            }
            $linea = $palabra;
        }
        if ($linea !== '' || !count($lista)) { $lista[] = $linea; }
        return $lista;
    }

    //Rectángulo con esquinas redondeadas. $estilo: F (relleno), D (borde) o DF
    private function caja($x, $y, $ancho, $alto, $radio, $estilo = 'F')
    {
        $radio = min($radio, $alto / 2, $ancho / 2);
        $k = $this->k; $h = $this->h;
        $op = $estilo === 'F' ? 'f' : ($estilo === 'DF' ? 'B' : 'S');
        $arco = 4 / 3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x + $radio) * $k, ($h - $y) * $k));
        $xc = $x + $ancho - $radio; $yc = $y + $radio;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($h - $y) * $k));
        $this->curva($xc + $radio * $arco, $yc - $radio, $xc + $radio, $yc - $radio * $arco, $xc + $radio, $yc);
        $xc = $x + $ancho - $radio; $yc = $y + $alto - $radio;
        $this->_out(sprintf('%.2F %.2F l', ($x + $ancho) * $k, ($h - $yc) * $k));
        $this->curva($xc + $radio, $yc + $radio * $arco, $xc + $radio * $arco, $yc + $radio, $xc, $yc + $radio);
        $xc = $x + $radio; $yc = $y + $alto - $radio;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($h - ($y + $alto)) * $k));
        $this->curva($xc - $radio * $arco, $yc + $radio, $xc - $radio, $yc + $radio * $arco, $xc - $radio, $yc);
        $xc = $x + $radio; $yc = $y + $radio;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($h - $yc) * $k));
        $this->curva($xc - $radio, $yc - $radio * $arco, $xc - $radio * $arco, $yc - $radio, $xc, $yc - $radio);
        $this->_out($op);
    }
    private function curva($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $k = $this->k; $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x1 * $k, ($h - $y1) * $k, $x2 * $k, ($h - $y2) * $k, $x3 * $k, ($h - $y3) * $k));
    }
    //Círculo relleno (para los números de cada punto)
    private function circulo($x, $y, $radio, $color)
    {
        $this->relleno($color);
        $this->caja($x - $radio, $y - $radio, 2 * $radio, 2 * $radio, $radio);
    }

    //Un porcentaje para leer (null = sin dato: «—»)
    private static function pctTexto($valor)
    {
        if ($valor === null) { return '—'; }
        return rtrim(rtrim(number_format($valor, 1, ',', '.'), '0'), ',') . ' %';
    }
    private static function mayus($t) { return mb_strtoupper((string)$t, 'UTF-8'); }

    //Colores de cada indicador: bien (menta), regular (ámbar) o mal (vino). Sin dato, gris
    private static function colorPromesa($pct) { return $pct === null ? self::GRIS : ($pct >= 100 ? self::MENTA : ($pct >= 95 ? self::AMBAR : self::VINO)); }
    private static function suave($color)
    {
        if ($color === self::MENTA) { return self::MENTA_SUAVE; }
        if ($color === self::AMBAR) { return self::AMBAR_SUAVE; }
        if ($color === self::VINO)  { return self::VINO_SUAVE; }
        return self::GRIS_SUAVE;
    }

    //=======================================================================
    // Encabezado y pie de todas las hojas
    //=======================================================================
    public function Header()
    {
        $logo = __DIR__ . '/../../public/img/logo_pdf.jpg';
        $x = self::M;
        if (is_file($logo)) { $this->Image($logo, self::M, 22, 42 * (360 / 312), 42); $x = self::M + 60; }
        $this->escribir($x, 27, $this->periodo('INFORME DE GESTIÓN DIARIA', 'INFORME DE GESTIÓN'), 13.5, 'B', self::VINO);
        $this->escribir($x, 46, 'Dirección Operativa P&B  ·  ' . self::primeraMayus($this->r['fechaLarga']), 9.5, '', self::GRIS);
        $this->escribir(self::M + self::ANCHO, 28, 'Grupo Regency', 9.5, 'B', self::NEGRO, 'R');
        $this->escribir(self::M + self::ANCHO, 43, 'Dirección de Operaciones · Peajes', 7.5, '', self::GRIS, 'R');
        //Franja de colores debajo del encabezado: vino, ámbar y menta
        $this->relleno(self::VINO);  $this->Rect(self::M, 71, self::ANCHO * 0.70, 2.4, 'F');
        $this->relleno(self::AMBAR); $this->Rect(self::M + self::ANCHO * 0.70, 71, self::ANCHO * 0.15, 2.4, 'F');
        $this->relleno(self::MENTA); $this->Rect(self::M + self::ANCHO * 0.85, 71, self::ANCHO * 0.15, 2.4, 'F');
    }

    public function Footer()
    {
        $this->relleno(self::LINEA);
        $this->Rect(self::M, 752, self::ANCHO, 0.8, 'F');
        //Sin nombres: quién firma sale una sola vez, al final del informe
        $origen = $this->r['origen'] === 'guardado' && $this->r['guardado']
            ? 'Versión guardada el ' . date('d/m/Y H:i', strtotime($this->r['guardado']['fecha']))
            : ($this->r['origen'] === 'pantalla' ? 'Versión en edición (sin guardar)' : 'Propuesta del sistema (sin guardar)');
        $this->escribir(self::M, 758, 'Generado en HANA el ' . $this->cuando . '  ·  ' . $origen, 6.8, '', self::GRIS);
        $this->escribir(self::M + self::ANCHO, 758, 'Página ' . $this->PageNo() . ' de {total}', 6.8, '', self::GRIS, 'R');
    }

    private static function primeraMayus($t) { return mb_strtoupper(mb_substr($t, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($t, 1, null, 'UTF-8'); }

    //Si lo que sigue no cabe en lo que queda de la hoja, se abre otra
    private function espacio($alto)
    {
        if ($this->cy + $alto > self::TOPE && $this->cy > self::INICIO) {
            $this->AddPage(); $this->cy = self::INICIO;
            if ($this->continua !== '') { $this->escribir(self::M, $this->cy, $this->continua . ' (continuación)', 9, 'B', self::VINO); $this->cy += 16; }
        }
    }

    //Título de una parte del reporte, con una marquita de color
    //$siguiente: lo que debe caber debajo del título, para que no quede solo al final de una hoja
    private function titulo($texto, $nota = '', $siguiente = 60)
    {
        $lineasNota = $nota === '' ? array() : $this->renglones($nota, self::ANCHO - 10, 7.8);
        $this->espacio(26 + 10 * count($lineasNota) + $siguiente);
        $this->relleno(self::MENTA);
        $this->caja(self::M, $this->cy + 1, 4, 13, 2);
        $this->escribir(self::M + 11, $this->cy, $texto, 12, 'B', self::NEGRO);
        $this->cy += 18;
        foreach ($lineasNota as $renglon) { $this->escribir(self::M + 11, $this->cy, $renglon, 7.8, '', self::GRIS); $this->cy += 10; }
        $this->cy += 6;
    }

    //Tarjeta con una cifra grande y su detalle
    private function tarjeta($x, $y, $ancho, $alto, $t)
    {
        list($rotulo, $valor, $detalle, $color) = $t;
        $this->relleno(self::suave($color));
        $this->caja($x, $y, $ancho, $alto, 7);
        $this->relleno($color);
        $this->caja($x, $y, $ancho, 4, 2);
        $this->escribir($x + 12, $y + 13, self::mayus($rotulo), 6.6, 'B', self::GRIS);
        $tam = 22;
        while ($tam > 12 && $this->anchoTexto($valor, $tam, 'B') > $ancho - 24) { $tam--; }
        $this->escribir($x + 12, $y + 25, $valor, $tam, 'B', $color === self::AMBAR ? array(176, 120, 20) : ($color === self::GRIS ? self::GRIS : $color));
        foreach ($this->renglones($detalle, $ancho - 24, 6.9) as $i => $renglon) { $this->escribir($x + 12, $y + 53 + 8.6 * $i, $renglon, 6.9, '', self::NEGRO); }
    }

    //Barras horizontales de porcentaje por proyecto (0 a 100 %; si pasa de 100, la barra llega al tope)
    private function graficaPorcentaje($x, $y, $ancho, $titulo, $items, $colorDe, $altoCaja = null)
    {
        $this->relleno(self::FONDO);
        $this->caja($x, $y, $ancho, $altoCaja !== null ? $altoCaja : 34 + 17 * count($items) - 6, 6);
        $this->escribir($x + 10, $y + 9, $titulo, 8.2, 'B', self::NEGRO);
        //Si algún rótulo es largo («2 de 3 peajes»), la barra se acorta para que quepa
        $anchoRotulo = 56;
        foreach ($items as $item) { $anchoRotulo = max($anchoRotulo, $this->anchoTexto(isset($item[3]) ? $item[3] : self::pctTexto($item[1]), 7.4, 'B') + 10); }
        $anchoNombre = 86; $xBarra = $x + 10 + $anchoNombre; $anchoBarra = $ancho - $anchoNombre - 10 - $anchoRotulo;
        $yy = $y + 28;
        foreach ($items as $item) {
            list($nombre, $pct, $nota) = $item;
            $rotulo = isset($item[3]) ? $item[3] : self::pctTexto($pct);
            $nombreCorto = $nombre;
            while ($this->anchoTexto($nombreCorto, 7.2) > $anchoNombre - 6 && mb_strlen($nombreCorto, 'UTF-8') > 3) { $nombreCorto = mb_substr($nombreCorto, 0, -1, 'UTF-8'); }
            $this->escribir($x + 10, $yy + 1, $nombreCorto, 7.2, '', self::NEGRO);
            $this->relleno(self::BLANCO);
            $this->caja($xBarra, $yy, $anchoBarra, 10, 3);
            if ($pct !== null && $pct > 0) {
                $this->relleno(call_user_func(array(__CLASS__, $colorDe), $pct));
                $this->caja($xBarra, $yy, max(6, $anchoBarra * min(100, $pct) / 100), 10, 3);
            }
            $this->escribir($xBarra + $anchoBarra + 5, $yy + 1, $rotulo, 7.4, 'B', $pct === null ? self::GRIS : self::NEGRO);
            if ($nota !== '') { $this->escribir($x + $ancho - 8, $yy + 1.5, $nota, 6.2, '', self::GRIS, 'R'); }
            $yy += 17;
        }
    }

    //=======================================================================
    // Armado: la carta, las cinco secciones y la firma
    //=======================================================================
    public function armar()
    {
        $this->AddPage();
        $this->cy = self::INICIO;
        $c = $this->r['contenido'];
        $this->carta($c['encabezado']);
        $n = 0;
        $this->resultados(++$n, $c['resultados']);
        $this->apartados(++$n, 'prioritarias', $c['prioritarias'], $this->periodo('Sin gestiones prioritarias ni compromisos con clientes registrados en la fecha.', 'Sin gestiones prioritarias ni compromisos con clientes registrados en el periodo.'));
        $this->porProyecto(++$n, $c['proyectos']);
        $this->apartados(++$n, 'transversal', $c['transversal'], $this->periodo('Sin asuntos de talento humano y SST registrados en la fecha.', 'Sin asuntos de talento humano y SST registrados en el periodo.'));
        $this->compromisos(++$n, $c['compromisos']);
        $this->cierre($c['encabezado']);
    }

    //El nombre de un proyecto por su ID (los de la persona, o los que traiga lo guardado)
    private function nombreProyecto($id)
    {
        return isset($this->r['nombres'][(int)$id]) ? $this->r['nombres'][(int)$id] : '';
    }

    //Título de una sección: «1. RESULTADOS DE LA OPERACIÓN»
    private function seccion($numero, $clave, $siguiente = 70)
    {
        $this->cy += 4;
        $this->titulo($numero . '. ' . $this->r['secciones'][$clave], '', $siguiente);
    }

    //Un mensaje cuando una sección no tiene nada
    private function vacio($texto)
    {
        $this->espacio(20);
        $this->escribir(self::M + 11, $this->cy, $texto, 8.6, 'I', self::GRIS);
        $this->cy += 20;
    }

    //-----------------------------------------------------------------------
    // Encabezado de la carta: fecha, título, destinatario, saludo y presentación
    //-----------------------------------------------------------------------
    private function carta($e)
    {
        $this->escribir(self::M, $this->cy, $this->r['fechaInforme'], 10, 'B', self::NEGRO);
        $this->cy += 18;
        foreach ($this->renglones($this->r['titulo'], self::ANCHO, 11.5, 'B') as $renglon) {
            $this->escribir(self::M, $this->cy, $renglon, 11.5, 'B', self::VINO);
            $this->cy += 14;
        }
        $this->cy += 10;
        if ($e['trato'] !== '') { $this->escribir(self::M, $this->cy, $e['trato'], 9.4, '', self::NEGRO); $this->cy += 12; }
        if ($e['destinatario'] !== '') { $this->escribir(self::M, $this->cy, $e['destinatario'], 9.8, 'B', self::NEGRO); $this->cy += 12.5; }
        if ($e['cargo'] !== '') { $this->escribir(self::M, $this->cy, $e['cargo'], 9.2, '', self::GRIS); $this->cy += 12; }
        $this->cy += 12;
        $this->escribir(self::M, $this->cy, 'Cordial saludo:', 9.4, '', self::NEGRO);
        $this->cy += 16;
        //La presentación va en el recuadro suave con la raya vino, como en el diseño de HANA
        if ($e['presentacion'] !== '') {
            $anchoTexto = self::ANCHO - 28;
            $lineas = $this->renglones($e['presentacion'], $anchoTexto, 9);
            $alto = 18 + 11.6 * count($lineas);
            $this->espacio($alto);
            $this->relleno(self::FONDO);
            $this->caja(self::M, $this->cy, self::ANCHO, $alto, 6);
            $this->relleno(self::VINO);
            $this->caja(self::M, $this->cy, 4, $alto, 2);
            foreach ($lineas as $i => $renglon) {
                $this->escribirJustificado(self::M + 16, $this->cy + 9 + 11.6 * $i, $renglon, $anchoTexto, 9, '', self::NEGRO, $i === count($lineas) - 1);
            }
            $this->cy += $alto + 8;
        }
    }

    //-----------------------------------------------------------------------
    // 1. Resultados de la operación: el texto, la cifra de la promesa y la gráfica por proyecto
    //-----------------------------------------------------------------------
    private function resultados($numero, $texto)
    {
        $this->seccion($numero, 'resultados', 110);
        $this->escribir(self::M + 11, $this->cy, $this->r['tituloPromesa'], 9.6, 'B', self::NEGRO);
        $this->cy += 15;
        $this->cuerpo($texto !== '' ? $texto : 'Sin información de la promesa de valor.', self::M + 11, self::ANCHO - 11, null);
        $this->cy += 4;

        //La cifra de la promesa (del texto) y la gráfica de cada proyecto (de la base), lado a lado
        $p = $this->r['promesa'];
        $proyectos = $this->r['promesaProyectos'];
        $hayGrafica = count(array_filter($proyectos, function ($x) { return $x[1] !== null; })) > 0;
        if ($p['pct'] === null && !$hayGrafica) { return; }
        $color = self::colorPromesa($p['pct']);
        $detalle = $p['peajes'] ? $p['cumplen'] . ' de ' . $p['peajes'] . ((int)$p['peajes'] === 1 ? ' peaje cumple' : ' peajes cumplen') . ' el indicador de horas en punto de cobro' : 'Cumplimiento reportado';
        $anchoT = 150;
        $altoT = 58 + 8.6 * count($this->renglones($detalle, $anchoT - 24, 6.9));
        $altoG = $hayGrafica && count($proyectos) > 1 ? 34 + 17 * count($proyectos) - 6 : 0;
        $alto = max($altoT, $altoG);
        $this->espacio($alto + 10);
        $y = $this->cy;
        $this->tarjeta(self::M, $y, $anchoT, $alto, array($this->periodo('Promesa de valor del día', 'Promesa de valor del periodo'), self::pctTexto($p['pct']), $detalle, $color));
        //Con un solo proyecto, la gráfica repetiría la tarjeta
        if ($altoG) { $this->graficaPorcentaje(self::M + $anchoT + 12, $y, self::ANCHO - $anchoT - 12, 'Cumplimiento por proyecto', $proyectos, 'colorPromesa', $alto); }
        $this->cy = $y + $alto + 8;
    }

    //-----------------------------------------------------------------------
    // 2 y 4. Apartados con título: «ICCU | Tarifarios y facturación» y su texto
    //-----------------------------------------------------------------------
    private function apartados($numero, $clave, $lista, $sinNada)
    {
        $this->seccion($numero, $clave);
        if (!count($lista)) { $this->vacio($sinNada); return; }
        foreach ($lista as $a) { $this->apartado($this->nombreProyecto($a['proyecto']), $a['titulo'], $a['texto']); }
    }

    //Un apartado: el título en negrilla (con el proyecto delante, en vino) y el texto debajo
    private function apartado($proyecto, $titulo, $texto)
    {
        $x = self::M + 11; $ancho = self::ANCHO - 11;
        //El título no queda solo al final de una hoja: con él tienen que caber unos renglones del texto
        $this->espacio(14 + 3 * 11.2);
        $tam = 9.4;
        if ($proyecto !== '') {
            $this->escribir($x, $this->cy, $proyecto, $tam, 'B', self::VINO);
            $xt = $x + $this->anchoTexto($proyecto, $tam, 'B');
            $this->escribir($xt, $this->cy, '  |  ', $tam, 'B', self::GRIS);
            $xt += $this->anchoTexto('  |  ', $tam, 'B');
            $renglonesTitulo = $this->renglones($titulo, $ancho - ($xt - $x), $tam, 'B');
            foreach ($renglonesTitulo as $i => $renglon) {
                $this->escribir($i === 0 ? $xt : $x, $this->cy, $renglon, $tam, 'B', self::NEGRO);
                $this->cy += 12;
            }
        } else {
            foreach ($this->renglones($titulo, $ancho, $tam, 'B') as $renglon) { $this->escribir($x, $this->cy, $renglon, $tam, 'B', self::NEGRO); $this->cy += 12; }
        }
        $this->cy += 2;
        if ($texto !== '') { $this->cuerpo($texto, $x, $ancho, self::LINEA); }
        $this->cy += 9;
    }

    //-----------------------------------------------------------------------
    // 3. Avances de gestión por proyecto: el nombre del proyecto en una franja y sus apartados
    //-----------------------------------------------------------------------
    private function porProyecto($numero, $proyectos)
    {
        $this->seccion($numero, 'proyectos');
        $hay = false;
        foreach ($proyectos as $id => $p) {
            if (!count($p['apartados'])) { continue; }
            $hay = true;
            $nombre = $this->nombreProyecto($id);
            $this->espacio(26 + 14 + 3 * 11.2);
            $this->relleno(self::VINO);
            $this->caja(self::M + 11, $this->cy, self::ANCHO - 11, 20, 4);
            $this->escribir(self::M + 21, $this->cy + 5.2, self::mayus($nombre), 9.6, 'B', self::BLANCO);
            $this->cy += 28;
            $this->continua = $nombre;
            foreach ($p['apartados'] as $a) { $this->apartado('', $a['titulo'], $a['texto']); }
            $this->continua = '';
            $this->cy += 2;
        }
        if (!$hay) { $this->vacio($this->periodo('Sin avances de gestión registrados en la fecha.', 'Sin avances de gestión registrados en el periodo.')); }
    }

    //-----------------------------------------------------------------------
    // 5. Compromisos y seguimiento: tabla de gestión y fecha objetivo
    //-----------------------------------------------------------------------
    private function compromisos($numero, $lista)
    {
        $this->seccion($numero, 'compromisos');
        if (!count($lista)) { $this->vacio('Sin compromisos registrados.'); return; }
        $x = self::M + 11; $ancho = self::ANCHO - 11;
        $anchoFecha = 140; $anchoGestion = $ancho - $anchoFecha;
        $tam = 8.6; $paso = 11;
        $cabecera = function () use ($x, $ancho, $anchoGestion) {
            $this->relleno(self::VINO);
            $this->caja($x, $this->cy, $ancho, 20, 4);
            $this->escribir($x + 10, $this->cy + 6, 'Gestión', 8.6, 'B', self::BLANCO);
            $this->escribir($x + $anchoGestion + 10, $this->cy + 6, 'Fecha objetivo', 8.6, 'B', self::BLANCO);
            $this->cy += 22;
        };
        $this->espacio(22 + 2 * 22);
        $cabecera();
        foreach ($lista as $i => $c) {
            $rg = $this->renglones($c['gestion'] !== '' ? $c['gestion'] : '—', $anchoGestion - 20, $tam);
            $rf = $this->renglones($c['fecha'] !== '' ? $c['fecha'] : '—', $anchoFecha - 20, $tam);
            $alto = 10 + $paso * max(count($rg), count($rf));
            if ($this->cy + $alto > self::TOPE) { $this->AddPage(); $this->cy = self::INICIO; $cabecera(); }
            if ($i % 2 === 0) { $this->relleno(self::FONDO); $this->Rect($x, $this->cy, $ancho, $alto, 'F'); }
            foreach ($rg as $k => $renglon) { $this->escribir($x + 10, $this->cy + 5 + $paso * $k, $renglon, $tam, '', self::NEGRO); }
            foreach ($rf as $k => $renglon) { $this->escribir($x + $anchoGestion + 10, $this->cy + 5 + $paso * $k, $renglon, $tam, '', self::NEGRO); }
            $this->cy += $alto;
            $this->relleno(self::LINEA); $this->Rect($x, $this->cy - 0.5, $ancho, 0.5, 'F');
        }
        $this->cy += 10;
    }

    //-----------------------------------------------------------------------
    // Cierre: «Atentamente,», el nombre y el cargo de quien firma
    //-----------------------------------------------------------------------
    private function cierre($e)
    {
        $this->espacio(70);
        $this->cy += 8;
        $this->escribir(self::M, $this->cy, 'Atentamente,', 9.4, '', self::NEGRO);
        $this->cy += 34;
        if ($e['firma'] !== '') { $this->escribir(self::M, $this->cy, $e['firma'], 9.8, 'B', self::NEGRO); $this->cy += 12.5; }
        if ($e['firmaCargo'] !== '') { $this->escribir(self::M, $this->cy, $e['firmaCargo'], 9.2, '', self::GRIS); $this->cy += 12; }
    }

    //-----------------------------------------------------------------------
    // El texto de un apartado, renglón por renglón: «•» viñeta, «–» viñeta de segundo nivel y lo
    // demás, párrafo justificado. $barra: color de la raya delgada de la izquierda (null = sin raya)
    //-----------------------------------------------------------------------
    private function cuerpo($texto, $x, $ancho, $barra)
    {
        $tam = 9; $alto = 11.6;
        $sangriaTexto = $barra !== null ? 10 : 0;
        $parrafos = preg_split('/\n/u', (string)$texto);
        foreach ($parrafos as $parrafo) {
            $parrafo = rtrim($parrafo);
            if (trim($parrafo) === '') { $this->cy += 4; continue; }
            if (preg_match('/^\s*[•\-\*]\s+(.*)$/u', $parrafo, $m) && !preg_match('/^\s{2,}/u', $parrafo)) { $nivel = 1; $contenido = $m[1]; }
            elseif (preg_match('/^\s*[–—]\s+(.*)$/u', $parrafo, $m) || preg_match('/^\s{2,}[•\-\*]\s+(.*)$/u', $parrafo, $m)) { $nivel = 2; $contenido = $m[1]; }
            else { $nivel = 0; $contenido = trim($parrafo); }
            $sangria = $sangriaTexto + ($nivel === 0 ? 0 : ($nivel === 1 ? 14 : 28));
            $renglones = $this->renglones($contenido, $ancho - $sangria - 6, $tam);
            foreach ($renglones as $k => $renglon) {
                if ($this->cy + $alto > self::TOPE) { $this->espacio($alto + 20); }
                $yy = $this->cy;
                if ($barra !== null) { $this->relleno($barra); $this->Rect($x, $yy - 1.5, 2, $alto + 0.5, 'F'); }
                if ($k === 0 && $nivel === 1) { $this->circulo($x + $sangria - 7, $yy + 5, 1.7, self::VINO); }
                if ($k === 0 && $nivel === 2) { $this->relleno(self::GRIS); $this->Rect($x + $sangria - 10, $yy + 4.6, 4, 0.9, 'F'); }
                $this->escribirJustificado($x + $sangria, $yy, $renglon, $ancho - $sangria - 6, $tam, '', $nivel === 2 ? array(60, 60, 64) : self::NEGRO, $k === count($renglones) - 1);
                $this->cy += $alto;
            }
        }
    }
}
