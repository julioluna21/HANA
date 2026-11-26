<?php
/*
  HANA - Reporte diario de la Dirección Operativa (el PDF)
  ---------------------------------------------------------------------------
  Pinta lo que arma Control/ReporteGerenciaPdfControl.php sobre la hoja
  membretada de Regency (public/img/membrete_regency.png: el logo arriba a la
  derecha, el logo de fondo y los datos de la empresa abajo), con el orden y
  las palabras del reporte que se envía a la Gerencia:

    - «REPORTE DIARIO – DIRECCIÓN OPERATIVA», «Operación de Peajes y Básculas»,
      «Corte: …», «Dirigido a: …», «Cordial saludo:» y la presentación;
    - 1. Promesa de valor: el cumplimiento, la cifra general con la gráfica por
      proyecto y, debajo, cada peaje que no cumplió con lo de cada día;
    - 2. Avances y gestión por proyecto: las gestiones prioritarias y luego
      2.1, 2.2… un proyecto por número, cada apartado como viñeta;
    - 3. Gestiones transversales (si hay);
    - Avance de los planes de trabajo (si hay): programado, real y diferencia;
    - Compromisos y seguimiento (si hay): asunto, qué se hace, responsable, fecha y estado;
    - Asuntos que requieren decisión de la Gerencia (si hay);
    - Estado de vacantes: el resumen por proyecto, el detalle por estación y la nota;
    - «Atentamente,», el nombre y el cargo de quien firma.

  Los textos salen tal como los dejó la dirección. En cada renglón:
    «• …» o «- …»      viñeta; «– …» o una viñeta con sangría, viñeta de segundo nivel
    «Algo:» solo        subtítulo en negrilla («ANDES (ACCENORTE):»)
    «Algo: el resto»    «Algo:» en negrilla (si es corto: hasta seis palabras)

  Todo va en flujo: si algo no cabe en la hoja, sigue en la siguiente. Ningún
  texto se recorta. Hoja carta vertical, medidas en puntos (612 × 792), con
  FPDF 1.9 (public/fpdf). FPDF escribe en Windows-1252: todo texto pasa por
  textoPdf() para que las tildes salgan bien.
*/
require_once __DIR__ . '/../../public/fpdf/fpdf.php';

class ReporteGerenciaPdf extends FPDF
{
    //Colores de la hoja membretada y de las tablas del reporte
    const BORDO = array(124, 40, 34);
    const NEGRO = array(20, 20, 20);
    const GRIS  = array(100, 102, 106);
    const BLANCO = array(255, 255, 255);
    const MENTA = array(26, 160, 130);
    const AMBAR = array(217, 156, 43);
    const FONDO = array(250, 247, 246);
    const BARRA = array(232, 222, 220);
    const MENTA_SUAVE = array(228, 244, 238);
    const AMBAR_SUAVE = array(253, 243, 224);
    const BORDO_SUAVE = array(244, 230, 228);
    const GRIS_SUAVE  = array(242, 242, 243);
    const AZUL_OSCURO = array(23, 54, 93);     //las franjas de título de las tablas de vacantes
    const AZUL = array(31, 78, 120);           //la cabecera de las tablas
    const AZUL_FILA = array(243, 246, 250);    //una fila sí y otra no
    const BORDE = array(191, 191, 191);
    const VERDE_DIAS = array(226, 240, 217);   //vacante con menos de 30 días
    const NARANJA_DIAS = array(252, 228, 214); //de 30 a 59 días
    const ROJO_DIAS = array(244, 204, 204);    //60 días o más

    const M = 72;          //margen izquierdo y derecho (una pulgada)
    const ANCHO = 468;     //ancho útil (612 - 2 × 72)
    const INICIO = 118;    //donde empieza el contenido, debajo del logo
    const TOPE = 698;      //hasta donde llega el contenido (abajo van los datos de Regency)
    const TAM = 10;        //letra del texto
    const ALTO = 12.8;     //alto de cada renglón del texto

    private $r;            //los datos del reporte
    private $quien = '';   //quién genera el PDF (la persona que lo descarga)
    private $rol = '';     //su rol
    private $cy = 118;     //por dónde va el contenido en la hoja
    private $membrete;     //la imagen de la hoja membretada (null si no está)

    public function __construct($reporte, $quien, $rol = '')
    {
        parent::__construct('P', 'pt', 'Letter');
        $this->r = $reporte;
        $this->quien = (string)$quien;
        $this->rol = (string)$rol;
        $archivo = __DIR__ . '/../../public/img/membrete_regency.png';
        $this->membrete = is_file($archivo) ? $archivo : null;
        $this->SetMargins(0, 0, 0);
        $this->SetAutoPageBreak(false);
        $this->AliasNbPages('{total}');
        $this->SetTitle($reporte['titulo'] . ' - ' . $reporte['fechaInforme'], true);
        $this->SetAuthor('HANA - Grupo Regency', true);
    }

    //Nombre del archivo, como el del reporte en Word: REPORTE_DIARIO_20_DE_NOVIEMBRE_2026.pdf
    //Con rango de días: REPORTE_DE_GESTION_14_AL_20_DE_NOVIEMBRE_2026.pdf
    public static function nombreArchivo($fecha, $desde = null) { return self::nombreBase($fecha, $desde) . '.pdf'; }

    public static function nombreBase($fecha, $desde = null)
    {
        $esRango = $desde && $desde < $fecha;
        $periodo = preg_replace('/^(Corte|Periodo):\s*(del\s+)?/u', '', ReporteGerencia::fechaInforme($esRango ? $desde : null, $fecha));
        $nombre = ($esRango ? 'REPORTE DE GESTION ' : 'REPORTE DIARIO ') . preg_replace('/\s+de\s+(\d{4})$/u', ' $1', $periodo);
        $nombre = strtr(mb_strtoupper($nombre, 'UTF-8'), array('Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N'));
        return preg_replace('/[^A-Z0-9]+/', '_', $nombre);
    }

    //Las palabras que cambian si el reporte es de un día o de un rango de días
    private function periodo($deUnDia, $deRango) { return !empty($this->r['esRango']) ? $deRango : $deUnDia; }

    //=======================================================================
    // Utilidades de texto y de dibujo
    //=======================================================================
    private function textoPdf($texto)
    {
        //Un texto pegado de otro programa puede no venir en UTF-8, y puede traer tabulaciones, emojis o
        //comillas «inteligentes»: lo que la letra del PDF no tiene se cambia por lo más parecido o se quita
        $texto = (string)$texto;
        if (!mb_check_encoding($texto, 'UTF-8')) { $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252'); }
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace(array('/\t/u', '/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}\x{200B}-\x{200D}\x{FE0F}]/u', '/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]/u'), array(' ', '', ''), $texto);
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

    private function anchoTexto($texto, $tam, $estilo = '')
    {
        $this->SetFont('Helvetica', $estilo, $tam);
        return $this->GetStringWidth($this->textoPdf($texto));
    }

    //Parte un texto en renglones que quepan en un ancho (una palabra muy larga se parte por letras)
    private function renglones($texto, $ancho, $tam, $estilo = '')
    {
        $lista = array(); $linea = '';
        $ancho = max(10, $ancho);
        foreach (preg_split('/\s+/u', trim((string)$texto)) as $palabra) {
            if ($palabra === '') { continue; }
            $prueba = $linea === '' ? $palabra : "$linea $palabra";
            if ($this->anchoTexto($prueba, $tam, $estilo) <= $ancho) { $linea = $prueba; continue; }
            if ($linea !== '') { $lista[] = $linea; $linea = ''; }
            foreach ($this->partirPalabra($palabra, $ancho, $tam, $estilo) as $pedazo) { $lista[] = $pedazo; }
            $linea = array_pop($lista);
        }
        if ($linea !== '' || !count($lista)) { $lista[] = $linea; }
        return $lista;
    }

    //Una palabra más ancha que el renglón, en pedazos que quepan
    private function partirPalabra($palabra, $ancho, $tam, $estilo)
    {
        $pedazos = array();
        while ($this->anchoTexto($palabra, $tam, $estilo) > $ancho && mb_strlen($palabra, 'UTF-8') > 1) {
            $letras = 1; $largo = mb_strlen($palabra, 'UTF-8');
            while ($letras < $largo && $this->anchoTexto(mb_substr($palabra, 0, $letras + 1, 'UTF-8'), $tam, $estilo) <= $ancho) { $letras++; }
            $pedazos[] = mb_substr($palabra, 0, $letras, 'UTF-8');
            $palabra = mb_substr($palabra, $letras, null, 'UTF-8');
        }
        $pedazos[] = $palabra;
        return $pedazos;
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

    //Un porcentaje para leer (null = sin dato: «—»)
    private static function pctTexto($valor)
    {
        if ($valor === null) { return '—'; }
        return rtrim(rtrim(number_format($valor, 1, ',', '.'), '0'), ',') . ' %';
    }
    private static function mayus($t) { return mb_strtoupper((string)$t, 'UTF-8'); }

    //Colores de cada indicador: bien (verde), regular (ámbar) o mal (bordó). Sin dato, gris
    private static function colorPromesa($pct) { return $pct === null ? self::GRIS : ($pct >= 100 ? self::MENTA : ($pct >= 95 ? self::AMBAR : self::BORDO)); }
    private static function suave($color)
    {
        if ($color === self::MENTA) { return self::MENTA_SUAVE; }
        if ($color === self::AMBAR) { return self::AMBAR_SUAVE; }
        if ($color === self::BORDO) { return self::BORDO_SUAVE; }
        return self::GRIS_SUAVE;
    }

    //=======================================================================
    // La hoja membretada: la imagen de fondo en cada hoja y el número de la página
    //=======================================================================
    public function Header()
    {
        if ($this->membrete !== null) { $this->Image($this->membrete, 0, 0, 612, 792); return; }
        //Sin la imagen: el nombre de la empresa arriba y sus datos abajo
        $this->escribir(612 - self::M, 40, 'REGENCY', 16, 'B', self::BORDO, 'R');
        foreach (array('CRA 100 No. 5-169 OASIS DE UNICENTRO OF. 306 BC', '(+57 602) 333-2681 - (+57) 317 635 2676', 'WWW.REGENCY.COM.CO', 'Cali - Colombia') as $i => $renglon) {
            $this->escribir(0, 716 + 9 * $i, $renglon, 7, 'B', self::NEGRO, 'C', 612);
        }
        $this->relleno(self::BORDO);
        $this->Rect(0, 768, 612, 6, 'F');
    }

    public function Footer()
    {
        $this->escribir(612 - self::M + 30, 742, 'Página ' . $this->PageNo() . ' de {total}', 7, '', self::GRIS, 'R');
    }

    private static function primeraMayus($t) { return mb_strtoupper(mb_substr($t, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($t, 1, null, 'UTF-8'); }

    //Si lo que sigue no cabe en lo que queda de la hoja, se abre otra
    private function espacio($alto)
    {
        if ($this->cy + $alto > self::TOPE && $this->cy > self::INICIO) { $this->AddPage(); $this->cy = self::INICIO; }
    }

    //=======================================================================
    // Texto con partes en negrilla, justificado, con viñeta o sin ella
    //=======================================================================

    //Un párrafo: $partes = [[texto, estilo], ...]. $sangria: dónde empieza el texto; $vineta: '' (sin),
    //'•' o '–' (se dibuja antes del texto). El último renglón va alineado a la izquierda
    private function parrafo($partes, $sangria = 0, $vineta = '', $tam = self::TAM, $alto = self::ALTO, $justificado = true)
    {
        $x = self::M + $sangria; $ancho = self::ANCHO - $sangria;
        //Las palabras con su estilo, y cuánto miden
        $palabras = array();
        foreach ($partes as $parte) {
            foreach (preg_split('/\s+/u', trim((string)$parte[0]), -1, PREG_SPLIT_NO_EMPTY) as $palabra) {
                foreach ($this->partirPalabra($palabra, $ancho, $tam, $parte[1]) as $pedazo) {
                    $palabras[] = array($pedazo, $parte[1], $this->anchoTexto($pedazo, $tam, $parte[1]));
                }
            }
        }
        if (!count($palabras)) { return; }
        $espacio = $this->anchoTexto(' ', $tam);
        //Los renglones
        $lineas = array(); $linea = array(); $medida = 0;
        foreach ($palabras as $p) {
            $nueva = $medida + (count($linea) ? $espacio : 0) + $p[2];
            if (count($linea) && $nueva > $ancho) { $lineas[] = $linea; $linea = array(); $nueva = $p[2]; }
            $linea[] = $p; $medida = $nueva;
        }
        $lineas[] = $linea;
        foreach ($lineas as $i => $l) {
            $this->espacio($alto);
            if ($i === 0 && $vineta === '•') { $this->relleno(self::NEGRO); $this->caja($x - 9.5, $this->cy + 0.36 * $tam, 3.4, 3.4, 1.7); }
            if ($i === 0 && $vineta === '–') { $this->relleno(self::GRIS); $this->Rect($x - 10, $this->cy + 0.45 * $tam, 4.5, 0.9, 'F'); }
            $suma = array_sum(array_map(function ($p) { return $p[2]; }, $l));
            $hueco = $espacio;
            if ($justificado && $i < count($lineas) - 1 && count($l) > 1) {
                $estirado = ($ancho - $suma) / (count($l) - 1);
                if ($estirado <= 4 * $espacio) { $hueco = $estirado; }
            }
            $cx = $x;
            foreach ($l as $p) {
                $this->SetFont('Helvetica', $p[1], $tam);
                $this->tinta(self::NEGRO);
                $this->Text($cx, $this->cy + 0.905 * $tam, $this->textoPdf($p[0]));
                $cx += $p[2] + $hueco;
            }
            $this->cy += $alto;
        }
    }

    //Un renglón del texto escrito por la dirección, partido en [etiqueta, resto]: «Visita ANI: Programada…»
    //-> ['Visita ANI:', 'Programada…']. La etiqueta es corta (hasta seis palabras), empieza con letra y no
    //tiene punto; si no hay etiqueta, ['', renglón]
    public static function etiqueta($renglon)
    {
        if (preg_match('/^(\p{L}[^:.\n]{0,58}:)\s+(\S.*)$/u', $renglon, $m) && count(preg_split('/\s+/u', trim($m[1]))) <= 6) { return array($m[1], $m[2]); }
        return array('', $renglon);
    }

    //El texto de un apartado, renglón por renglón (ver arriba). $sangria: donde empieza el texto normal
    private function cuerpo($texto, $sangria = 0)
    {
        foreach (preg_split('/\n/u', (string)$texto) as $renglon) {
            $renglon = rtrim($renglon);
            if (trim($renglon) === '') { $this->cy += 3; continue; }
            if (preg_match('/^\s*[•\-\*]\s+(.*)$/u', $renglon, $m) && !preg_match('/^\s{2,}/u', $renglon)) {
                list($etiqueta, $resto) = self::etiqueta(trim($m[1]));
                $this->parrafo(array(array($etiqueta, 'B'), array($resto, '')), $sangria + 16, '•');
            } elseif (preg_match('/^\s*[–—]\s+(.*)$/u', $renglon, $m) || preg_match('/^\s{2,}[•\-\*]\s+(.*)$/u', $renglon, $m)) {
                list($etiqueta, $resto) = self::etiqueta(trim($m[1]));
                $this->parrafo(array(array($etiqueta, 'B'), array($resto, '')), $sangria + 32, '–');
            } elseif (preg_match('/^[^:]{1,90}:$/u', trim($renglon))) {
                $this->espacio(self::ALTO * 3);
                $this->parrafo(array(array(trim($renglon), 'B')), $sangria, '', self::TAM, self::ALTO, false);
            } else {
                list($etiqueta, $resto) = self::etiqueta(trim($renglon));
                $this->parrafo(array(array($etiqueta, 'B'), array($resto, '')), $sangria);
            }
            $this->cy += 2;
        }
    }

    //Un apartado como viñeta: «• Título: el primer renglón del texto» y, debajo, lo demás del texto
    private function vinetaApartado($titulo, $texto)
    {
        $renglones = preg_split('/\n/u', trim((string)$texto));
        $primero = isset($renglones[0]) ? trim($renglones[0]) : '';
        $titulo = trim((string)$titulo);
        //El primer renglón va junto al título si es un párrafo (no otra viñeta)
        $junto = $primero !== '' && !preg_match('/^[•\-\*–—]\s+/u', $primero);
        $etiqueta = $titulo === '' ? '' : (preg_match('/[:.?!]$/u', $titulo) ? $titulo : $titulo . ':');
        $this->espacio(self::ALTO * 2);
        $this->parrafo(array(array($etiqueta, 'B'), array($junto ? $primero : '', '')), 16, '•');
        $this->cy += 2;
        $resto = $junto ? array_slice($renglones, 1) : $renglones;
        if (count($resto)) {
            //Las viñetas de debajo van como de segundo nivel
            $resto = array_map(function ($r) { return preg_replace('/^\s*[•\-\*]\s+/u', '– ', $r); }, $resto);
            $this->cuerpo(implode("\n", $resto), 16);
        }
        $this->cy += 3;
    }

    //=======================================================================
    // Armado
    //=======================================================================
    public function armar()
    {
        $this->AddPage();
        $this->cy = self::INICIO;
        $c = $this->r['contenido'];
        $this->carta($c['encabezado']);
        $n = 0;
        $this->promesa(++$n, isset($this->r['resultadosTexto']) ? $this->r['resultadosTexto'] : $c['resultados']);
        $this->avances(++$n, $c['prioritarias'], $c['proyectos']);
        if (count($c['transversal'])) { $this->transversal(++$n, $c['transversal']); }
        if (!empty($c['planes'])) { $this->planes(++$n, $c['planes']); }
        if (count($c['compromisos'])) { $this->compromisos(++$n, $c['compromisos']); }
        if (!empty($c['escalamientos'])) { $this->escalamientos(++$n, $c['escalamientos']); }
        $this->vacantes(is_array($c['vacantes']) ? $c['vacantes'] : array(), isset($c['notaVacantes']) ? $c['notaVacantes'] : '');
        $this->cierre($c['encabezado']);
    }

    //El nombre de un proyecto por su ID (los de la persona, o los que traiga lo guardado)
    private function nombreProyecto($id)
    {
        return isset($this->r['nombres'][(int)$id]) ? $this->r['nombres'][(int)$id] : '';
    }

    //Título de una sección: «1. PROMESA DE VALOR». $siguiente: lo que debe caber debajo
    private function seccion($titulo, $siguiente = 60)
    {
        $this->cy += 8;
        $this->espacio(18 + $siguiente);
        $this->escribir(self::M, $this->cy, $titulo, 10.5, 'B', self::NEGRO);
        $this->cy += 15;
    }

    //Un subtítulo en negrilla («Cumplimiento – 19 de noviembre», «2.1. ACCENORTE»)
    private function subtitulo($texto, $siguiente = 40)
    {
        $this->espacio(14 + $siguiente);
        foreach ($this->renglones($texto, self::ANCHO, self::TAM, 'B') as $renglon) {
            $this->escribir(self::M, $this->cy, $renglon, self::TAM, 'B', self::NEGRO);
            $this->cy += self::ALTO;
        }
        $this->cy += 3;
    }

    //Un mensaje cuando una sección no tiene nada
    private function vacio($texto)
    {
        $this->espacio(18);
        $this->escribir(self::M, $this->cy, $texto, 9.4, 'I', self::GRIS);
        $this->cy += 18;
    }

    //-----------------------------------------------------------------------
    // La carta: título, corte, destinatario, saludo y presentación
    //-----------------------------------------------------------------------
    private function carta($e)
    {
        $this->escribir(self::M, $this->cy, $this->r['titulo'], 10.5, 'B', self::NEGRO);
        $this->cy += 13.5;
        $this->escribir(self::M, $this->cy, $this->r['subtitulo'], 10.5, 'B', self::NEGRO);
        $this->cy += 24;
        $this->escribir(self::M, $this->cy, $this->r['fechaInforme'], self::TAM, 'B', self::NEGRO);
        $this->cy += 24;
        $destinatario = trim($e['trato'] . ' ' . $e['destinatario']);
        if ($destinatario !== '') { $this->parrafo(array(array('Dirigido a:', 'B'), array($destinatario, '')), 0, '', self::TAM, self::ALTO, false); }
        if ($e['cargo'] !== '') { $this->parrafo(array(array($e['cargo'], '')), 0, '', self::TAM, self::ALTO, false); }
        $this->cy += 12;
        $this->escribir(self::M, $this->cy, 'Cordial saludo:', self::TAM, '', self::NEGRO);
        $this->cy += 24;
        if ($e['presentacion'] !== '') {
            foreach (preg_split('/\n+/u', $e['presentacion']) as $parrafo) { $this->parrafo(array(array($parrafo, '')), 0); $this->cy += 4; }
        }
    }

    //-----------------------------------------------------------------------
    // 1. Promesa de valor: el cumplimiento del día, la cifra con la gráfica por proyecto y lo de cada peaje
    //-----------------------------------------------------------------------
    private function promesa($numero, $texto)
    {
        $this->seccion($numero . '. ' . $this->r['secciones']['resultados'], 110);
        $this->subtitulo($this->r['tituloPromesa'], 30);
        $renglones = preg_split('/\n/u', trim((string)$texto));
        //El primer renglón es el resumen («16 de 20 centros de operación: cumplimiento del 98,3 %.»): va en
        //negrilla, y debajo la cifra y la gráfica; luego lo de cada peaje
        $resumen = trim((string)array_shift($renglones));
        $this->parrafo(array(array($resumen !== '' ? $resumen : 'Sin información de la promesa de valor.', 'B')), 0);
        $this->cy += 6;
        $this->grafica();
        $resto = trim(implode("\n", $renglones));
        if ($resto !== '') { $this->cuerpo($resto); }
    }

    //La cifra de la promesa (la que dice el texto) y, al lado, el cumplimiento de cada proyecto en barras
    private function grafica()
    {
        $p = $this->r['promesa'];
        $proyectos = $this->r['promesaProyectos'];
        if ($p['pct'] === null && !count($proyectos)) { return; }
        $color = self::colorPromesa($p['pct']);
        $detalle = $p['peajes'] ? $p['cumplen'] . ' de ' . $p['peajes'] . ' ' . ReporteGerencia::centros($p['peajes']) . ((int)$p['peajes'] === 1 ? ' cumplió' : ' cumplieron') . ' el 100 % de las horas en punto de cobro'
                 : ($p['pct'] !== null ? 'Cumplimiento reportado' : 'Sin promesa de valor con lo ejecutado registrado');
        $anchoT = 136;
        $altoT = 56 + 8.4 * count($this->renglones($detalle, $anchoT - 22, 6.8));
        //Con un solo proyecto, la gráfica repetiría la tarjeta
        $filas = count($proyectos) > 1 ? count($proyectos) : 0;
        $paso = $filas <= 8 ? 16 : 13;
        $alto = max($altoT, $filas ? 28 + $paso * $filas : 0);
        $this->espacio($alto + 10);
        $y = $this->cy;
        //La tarjeta
        $this->relleno(self::suave($color));
        $this->caja(self::M, $y, $anchoT, $alto, 6);
        $this->relleno($color);
        $this->caja(self::M, $y, $anchoT, 3.5, 1.7);
        $this->escribir(self::M + 11, $y + 12, self::mayus('Cumplimiento real de horas'), 6.4, 'B', self::GRIS);
        $valor = self::pctTexto($p['pct']);
        $tam = 22;
        while ($tam > 12 && $this->anchoTexto($valor, $tam, 'B') > $anchoT - 22) { $tam--; }
        $this->escribir(self::M + 11, $y + 24, $valor, $tam, 'B', $color === self::AMBAR ? array(176, 120, 20) : $color);
        foreach ($this->renglones($detalle, $anchoT - 22, 6.8) as $i => $renglon) { $this->escribir(self::M + 11, $y + 51 + 8.4 * $i, $renglon, 6.8, '', self::NEGRO); }
        if ($filas) { $this->barras(self::M + $anchoT + 10, $y, self::ANCHO - $anchoT - 10, $alto, $proyectos, $paso); }
        $this->cy = $y + $alto + 10;
    }

    //Las barras de cumplimiento por proyecto (0 a 100 %). $items: [nombre, porcentaje o null, nota, rótulo]
    private function barras($x, $y, $ancho, $alto, $items, $paso)
    {
        $this->relleno(self::FONDO);
        $this->caja($x, $y, $ancho, $alto, 6);
        $this->escribir($x + 10, $y + 9, 'Cumplimiento por proyecto', 8, 'B', self::NEGRO);
        $anchoRotulo = 50;
        foreach ($items as $item) { $anchoRotulo = max($anchoRotulo, $this->anchoTexto($item[3], 7, 'B') + 8); }
        $anchoNombre = 70;
        foreach ($items as $item) { $anchoNombre = max($anchoNombre, $this->anchoTexto($item[0], 7) + 8); }
        $anchoNombre = min($anchoNombre, round($ancho * 0.32));
        $xBarra = $x + 10 + $anchoNombre; $anchoBarra = max(30, $ancho - $anchoNombre - 14 - $anchoRotulo);
        $altoBarra = min(9, $paso - 4);
        $yy = $y + 26;
        foreach ($items as $item) {
            list($nombre, $pct) = $item;
            $nombreCorto = $nombre;
            while ($this->anchoTexto($nombreCorto, 7) > $anchoNombre - 5 && mb_strlen($nombreCorto, 'UTF-8') > 3) { $nombreCorto = mb_substr($nombreCorto, 0, -1, 'UTF-8'); }
            if ($nombreCorto !== $nombre) { $nombreCorto = rtrim(mb_substr($nombreCorto, 0, -1, 'UTF-8')) . '…'; }
            $yTexto = $yy + ($altoBarra - 7) / 2;
            $this->escribir($x + 10, $yTexto, $nombreCorto, 7, '', self::NEGRO);
            $this->relleno(self::BLANCO);
            $this->caja($xBarra, $yy, $anchoBarra, $altoBarra, 3);
            if ($pct !== null && $pct > 0) {
                $this->relleno(self::colorPromesa($pct));
                $this->caja($xBarra, $yy, max(6, $anchoBarra * min(100, $pct) / 100), $altoBarra, 3);
            }
            $this->escribir($xBarra + $anchoBarra + 5, $yTexto, $item[3], 7, 'B', $pct === null ? self::GRIS : self::NEGRO);
            $yy += $paso;
        }
    }

    //-----------------------------------------------------------------------
    // 2. Avances y gestión por proyecto: las gestiones prioritarias y luego un proyecto por número
    //-----------------------------------------------------------------------
    private function avances($numero, $prioritarias, $proyectos)
    {
        $this->seccion($numero . '. ' . $this->r['secciones']['proyectos']);
        $hay = false;
        if (count($prioritarias)) {
            $hay = true;
            $this->subtitulo($this->r['secciones']['prioritarias'] . ':');
            foreach ($prioritarias as $a) {
                $proyecto = $this->nombreProyecto($a['proyecto']);
                $this->vinetaApartado(($proyecto !== '' ? $proyecto . ' – ' : '') . $a['titulo'], $a['texto']);
            }
        }
        $k = 0;
        foreach ($proyectos as $id => $p) {
            if (!count($p['apartados'])) { continue; }
            $hay = true;
            $this->cy += 4;
            $this->subtitulo($numero . '.' . (++$k) . '. ' . self::mayus($this->nombreProyecto($id)));
            foreach ($p['apartados'] as $a) { $this->vinetaApartado($a['titulo'], $a['texto']); }
        }
        if (!$hay) { $this->vacio($this->periodo('Sin avances de gestión registrados en la fecha.', 'Sin avances de gestión registrados en el periodo.')); }
    }

    //-----------------------------------------------------------------------
    // 3. Gestiones transversales: cada una con su título en negrilla y su texto debajo
    //-----------------------------------------------------------------------
    private function transversal($numero, $lista)
    {
        $this->seccion($numero . '. ' . $this->r['secciones']['transversal']);
        foreach ($lista as $a) {
            $proyecto = $this->nombreProyecto($a['proyecto']);
            $titulo = trim($a['titulo'] . ($proyecto !== '' ? ' – ' . $proyecto : ''));
            if ($titulo !== '') { $this->subtitulo($titulo, 26); }
            if ($a['texto'] !== '') { $this->cuerpo($a['texto']); }
            $this->cy += 4;
        }
    }

    //-----------------------------------------------------------------------
    // Compromisos y seguimiento: tabla de gestión y fecha objetivo
    //-----------------------------------------------------------------------
    private function compromisos($numero, $lista)
    {
        $this->seccion($numero . '. ' . $this->r['secciones']['compromisos'], 50);
        $filas = array();
        foreach ($lista as $c) {
            list($estado, $clase) = ReporteGerencia::estadoCompromiso($c, $this->r['fecha']);
            $proyecto = $this->nombreProyecto($c['proyecto']);
            $asunto = $c['gestion'] . (isset($c['detalle']) && $c['detalle'] !== '' ? "\n" . $c['detalle'] : '');
            $fondo = $clase === 'vencido' ? self::ROJO_DIAS : ($clase === 'cumplido' ? self::VERDE_DIAS : ($clase === 'sinfecha' || $clase === 'hoy' ? self::NARANJA_DIAS : null));
            $filas[] = array('celdas' => array($proyecto !== '' ? self::mayus($proyecto) : 'GENERAL', $asunto, $c['accion'] !== '' ? $c['accion'] : 'Sin acción registrada',
                                               $c['responsable'] !== '' ? $c['responsable'] : 'Sin responsable', $c['fecha'] ? date('d/m/Y', strtotime($c['fecha'])) : '', $estado),
                             'fondos' => array(5 => $fondo));
        }
        $this->tabla(self::M, array(array('PROYECTO', 56, 'C'), array('ASUNTO', 108, 'L'), array('QUÉ SE ESTÁ HACIENDO', 134, 'L'), array('RESPONSABLE', 66, 'L'),
                                    array('FECHA', 54, 'C'), array('ESTADO', 50, 'C')), $filas, 7.4);
    }

    //-----------------------------------------------------------------------
    // Avance de los planes de trabajo: cada entregable con lo programado, lo real y la diferencia
    //-----------------------------------------------------------------------
    private function planes($numero, $lista)
    {
        $this->seccion($numero . '. ' . $this->r['secciones']['planes'], 50);
        $filas = array();
        foreach ($lista as $p) {
            $diferencia = $p['programado'] !== null && $p['real'] !== null ? $p['real'] - $p['programado'] : null;
            $vencido = $p['fecha'] && $p['fecha'] < $this->r['fecha'] && ($p['real'] === null || $p['real'] < 100);
            $filas[] = array('celdas' => array(self::mayus($p['proyecto']), $p['entregable'], self::pctTexto($p['programado']), self::pctTexto($p['real']),
                                               $diferencia === null ? '—' : ($diferencia > 0 ? '+' : '') . self::pctTexto($diferencia),
                                               $p['fecha'] ? date('d/m/Y', strtotime($p['fecha'])) . ($vencido ? ' (vencida)' : '') : ''),
                             'fondos' => array(4 => $diferencia === null ? null : ($diferencia < 0 ? self::ROJO_DIAS : self::VERDE_DIAS)),
                             'colores' => $vencido ? array(5 => self::BORDO) : array());
        }
        $this->tabla(self::M, array(array('PROYECTO', 62, 'C'), array('ENTREGABLE', 160, 'L'), array('PROGRAMADO', 66, 'C'), array('REAL', 46, 'C'),
                                    array('DIFERENCIA', 62, 'C'), array('FECHA COMPROMETIDA', 72, 'C')), $filas, 7.8);
    }

    //-----------------------------------------------------------------------
    // Asuntos que requieren decisión de la Gerencia: cada uno con su impacto, lo que se ha hecho y la decisión
    //-----------------------------------------------------------------------
    private function escalamientos($numero, $lista)
    {
        $this->seccion($numero . '. ' . $this->r['secciones']['escalamientos']);
        foreach ($lista as $x) {
            $proyecto = $this->nombreProyecto($x['proyecto']);
            $this->subtitulo(($proyecto !== '' ? self::mayus($proyecto) . ' – ' : '') . ($x['asunto'] !== '' ? $x['asunto'] : 'Asunto'), 50);
            foreach (array('impacto' => 'Impacto contractual:', 'gestiones' => 'Gestiones adelantadas:', 'decision' => 'Decisión requerida:') as $clave => $etiqueta) {
                if (trim($x[$clave]) === '') { continue; }
                $this->parrafo(array(array($etiqueta, 'B'), array(preg_replace('/\s*\n\s*/u', ' ', $x[$clave]), '')), 16, '•');
                $this->cy += 2;
            }
            $this->cy += 4;
        }
    }

    //-----------------------------------------------------------------------
    // Estado de vacantes: el resumen por proyecto, el detalle por estación y la nota
    //-----------------------------------------------------------------------
    private function vacantes($lista, $nota)
    {
        $this->cy += 14;
        //Si las tablas caben en una hoja entera pero no en lo que queda de esta, empiezan en la siguiente
        $proyectos = count(array_unique(array_map(function ($v) { return (int)$v['proyecto']; }, $lista)));
        $alto = 20 + 30 + 14 + (count($lista) ? 36 + 19 * ($proyectos + 1) + 8 + 30 + 12 + 34 + 19 * count($lista) + 8 : 18) + (trim($nota) !== '' ? 16 : 0);
        $this->espacio($alto <= self::TOPE - self::INICIO ? $alto : 150);
        $this->escribir(self::M, $this->cy, $this->r['secciones']['vacantes'], 11, '', self::NEGRO, 'C', self::M + self::ANCHO);
        $this->cy += 20;
        $this->franja('VACANTES OPERATIVAS – PEAJES Y BÁSCULAS', 12);
        $this->escribir(self::M + self::ANCHO, $this->cy, 'Corte: ' . $this->r['corte'], 8.4, 'I', self::GRIS, 'R');
        $this->cy += 14;
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
                $filas[] = array('celdas' => array($nombre !== '' ? self::mayus($nombre) : 'SIN PROYECTO', (string)$p['total'], $p['dias'] === null ? '—' : (string)$p['dias']));
                $total += $p['total'];
                if ($p['dias'] !== null && ($mayor === null || $p['dias'] > $mayor)) { $mayor = $p['dias']; }
            }
            $filas[] = array('tipo' => 'total', 'celdas' => array('TOTAL', (string)$total, $mayor === null ? '—' : (string)$mayor));
            $anchoResumen = 330;
            $this->tabla(self::M + (self::ANCHO - $anchoResumen) / 2, array(array('PROYECTO', 110, 'C'), array('CANTIDAD DE VACANTES', 105, 'C'), array('VACANTE MÁS ANTIGUA (DÍAS)', 115, 'C')), $filas, 8.4);

            //El detalle: un renglón por estación
            $this->espacio(80);
            $this->franja('DETALLE DE VACANTES POR PROYECTO Y ESTACIÓN', 10.5);
            $this->escribir(self::M, $this->cy, 'Información suministrada – corte al ' . $this->r['corte'], 7.4, 'I', self::GRIS, 'C', self::M + self::ANCHO);
            $this->cy += 12;
            $filas = array();
            foreach ($lista as $v) {
                $dias = ReporteGerencia::diasDesde($v['antigua'], $this->r['fecha']);
                $colorDias = $dias === null ? null : ($dias >= 60 ? self::ROJO_DIAS : ($dias >= 30 ? self::NARANJA_DIAS : self::VERDE_DIAS));
                $vencido = $v['compromiso'] && $v['compromiso'] < $this->r['fecha'];
                $filas[] = array('celdas' => array(self::mayus($this->nombreProyecto($v['proyecto'])), self::mayus($v['centro']), (string)$v['total'],
                                                   $v['antigua'] ? date('d/m/Y', strtotime($v['antigua'])) : '', $dias === null ? '' : (string)$dias,
                                                   self::mayus($v['cargo']), $v['compromiso'] ? ReporteGerencia::diaMesTabla($v['compromiso']) . ($vencido ? ' (vencida)' : '') : ''),
                                 'fondos' => array(4 => $colorDias), 'colores' => $vencido ? array(6 => self::BORDO) : array());
            }
            $this->tabla(self::M, array(array('PROYECTO', 64, 'C'), array('ESTACIÓN', 72, 'C'), array('CANTIDAD DE VACANTES', 52, 'C'), array('FECHA DE VACANTE MÁS ANTIGUA', 66, 'C'),
                                        array('ANTIGÜEDAD (DÍAS)', 56, 'C'), array('CARGO DE LA VACANTE MÁS ANTIGUA', 92, 'L'), array('COMPROMISO GH', 66, 'C')), $filas, 7.6);
        }
        if (trim($nota) !== '') {
            $this->espacio(self::ALTO * 2);
            $sangria = $this->anchoTexto('Nota:', 8.6, 'B') + 8;
            $this->escribir(self::M, $this->cy, 'Nota:', 8.6, 'B', self::AZUL);
            $this->parrafo(array(array($nota, '')), $sangria, '', 8.6, 11);
            $this->cy += 4;
        }
    }

    //Una franja azul con el título de una tabla, en blanco y centrado
    private function franja($texto, $tam)
    {
        $alto = $tam + 10;
        $this->espacio($alto + 60);
        $this->relleno(self::AZUL_OSCURO);
        $this->Rect(self::M, $this->cy, self::ANCHO, $alto, 'F');
        $this->escribir(self::M, $this->cy + 5, $texto, $tam, 'B', self::BLANCO, 'C', self::M + self::ANCHO);
        $this->cy += $alto + 4;
    }

    //-----------------------------------------------------------------------
    // Tabla con la cabecera azul y bordes grises, como las del reporte. $columnas: [[título, ancho, L/C/R], ...]
    // $filas: [['celdas' => [...], 'tipo' => 'fila' | 'total', 'fondos' => [i => color], 'colores' => [i => color]], ...]
    // Si no cabe en la hoja, sigue en la siguiente con la cabecera repetida
    //-----------------------------------------------------------------------
    private function tabla($x, $columnas, $filas, $tam = 8.2)
    {
        $ancho = array_sum(array_map(function ($c) { return $c[1]; }, $columnas));
        $paso = $tam + 2.2;
        $this->trazo(self::BORDE);
        $this->SetLineWidth(0.5);
        $cabecera = function () use ($x, $ancho, $columnas, $tam) {
            $partes = array(); $maximo = 1;
            foreach ($columnas as $i => $col) { $partes[$i] = $this->renglones($col[0], $col[1] - 8, $tam - 0.8, 'B'); $maximo = max($maximo, count($partes[$i])); }
            $alto = 8 + ($tam + 1.2) * $maximo;
            $this->relleno(self::AZUL);
            $this->Rect($x, $this->cy, $ancho, $alto, 'F');
            $cx = $x;
            foreach ($columnas as $i => $col) {
                $arriba = $this->cy + ($alto - ($tam + 1.2) * count($partes[$i])) / 2;
                foreach ($partes[$i] as $k => $renglon) { $this->celda($cx, $arriba + ($tam + 1.2) * $k, $col[1], $renglon, $tam - 0.8, 'B', self::BLANCO, 'C'); }
                $cx += $col[1];
            }
            $this->cy += $alto;
        };
        $this->espacio(40 + 2 * ($paso + 8));
        $cabecera();
        foreach ($filas as $n => $fila) {
            $tipo = isset($fila['tipo']) ? $fila['tipo'] : 'fila';
            $estilo = $tipo === 'total' ? 'B' : '';
            $partes = array(); $maximo = 1;
            foreach ($columnas as $i => $col) {
                $celda = isset($fila['celdas'][$i]) ? (string)$fila['celdas'][$i] : '';
                if ($celda === '' && $tipo === 'fila') { $celda = '—'; }
                $partes[$i] = array();
                foreach (explode("\n", $celda) as $parrafo) { $partes[$i] = array_merge($partes[$i], $this->renglones($parrafo, $col[1] - 8, $tam, $estilo)); }
                $maximo = max($maximo, count($partes[$i]));
            }
            $alto = 7 + $paso * $maximo;
            if ($this->cy + $alto > self::TOPE) { $this->AddPage(); $this->cy = self::INICIO; $cabecera(); }
            $cx = $x;
            foreach ($columnas as $i => $col) {
                $fondo = $tipo === 'total' ? self::AZUL_OSCURO : (isset($fila['fondos'][$i]) && $fila['fondos'][$i] ? $fila['fondos'][$i] : ($n % 2 === 1 ? self::AZUL_FILA : self::BLANCO));
                $this->relleno($fondo);
                $this->Rect($cx, $this->cy, $col[1], $alto, $tipo === 'total' ? 'F' : 'DF');
                $color = $tipo === 'total' ? self::BLANCO : (isset($fila['colores'][$i]) ? $fila['colores'][$i] : self::NEGRO);
                $arriba = $this->cy + ($alto - $paso * count($partes[$i])) / 2 + 1;
                foreach ($partes[$i] as $k => $renglon) { $this->celda($cx, $arriba + $paso * $k, $col[1], $renglon, $tam, isset($fila['colores'][$i]) ? 'B' : $estilo, $color, $col[2]); }
                $cx += $col[1];
            }
            $this->cy += $alto;
        }
        $this->cy += 8;
    }

    //Un texto dentro de una celda de $ancho, con 4 puntos de margen a cada lado
    private function celda($x, $top, $ancho, $texto, $tam, $estilo, $color, $alin)
    {
        if ($alin === 'R') { $this->escribir($x + $ancho - 4, $top, $texto, $tam, $estilo, $color, 'R'); }
        elseif ($alin === 'C') { $this->escribir($x, $top, $texto, $tam, $estilo, $color, 'C', $x + $ancho); }
        else { $this->escribir($x + 4, $top, $texto, $tam, $estilo, $color); }
    }

    //-----------------------------------------------------------------------
    // Cierre: «Atentamente,», el nombre y el cargo de quien firma
    //-----------------------------------------------------------------------
    private function cierre($e)
    {
        $this->cy += 14;
        $this->espacio(76);
        $this->escribir(self::M, $this->cy, 'Atentamente,', self::TAM, '', self::NEGRO);
        $this->cy += 44;
        if ($e['firma'] !== '') { $this->escribir(self::M, $this->cy, $e['firma'], self::TAM, 'B', self::NEGRO); $this->cy += self::ALTO; }
        if ($e['firmaCargo'] !== '') { $this->escribir(self::M, $this->cy, $e['firmaCargo'], self::TAM, '', self::NEGRO); $this->cy += self::ALTO; }
    }
}
