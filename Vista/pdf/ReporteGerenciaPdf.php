<?php
/*
  HANA - Reporte diario a la Gerencia (el PDF)
  ---------------------------------------------------------------------------
  Pinta lo que arma Control/ReporteGerenciaPdfControl.php para un día:

    Primera parte · Resumen del día
      - un párrafo de presentación;
      - cuatro tarjetas con las cifras de todos los proyectos: disponibilidad de
        flota, cumplimiento de la promesa de valor de la semana, oficios en
        gestión y temas con el cliente (el color dice si va bien, regular o mal);
      - tres gráficas por proyecto: flota disponible (%), promesa de valor (%) y
        oficios en gestión (dentro y fuera de plazo);
      - una tabla de resumen por proyecto.
    Segunda parte · Detalle por proyecto
      - una franja con el nombre del proyecto y sus cifras principales;
      - cada punto del reporte (los de Parámetros → Listas, en su orden) en un
        recuadro con su número, su título y el texto tal como lo dejó la
        dirección. Los renglones que empiezan con «•» o «–» salen como viñetas.

  Todo va en flujo: si algo no cabe en la hoja, sigue en la siguiente. Ningún
  texto se recorta. Hoja carta vertical, medidas en puntos (612 × 792), con
  FPDF 1.9 (public/fpdf), igual que el informe mensual y los arqueos. FPDF
  escribe en Windows-1252: todo texto pasa por textoPdf() para que las tildes
  salgan bien. Colores de la paleta de HANA: vino, menta y ámbar.
*/
require_once __DIR__ . '/../../public/fpdf/fpdf.php';

class ReporteGerenciaPdf extends FPDF
{
    //Paleta de HANA
    const VINO  = array(110, 26, 30);
    const MENTA = array(26, 187, 156);
    const AMBAR = array(217, 156, 43);
    const NEGRO = array(26, 26, 26);
    const AZUL  = array(42, 63, 84);
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
        $this->SetTitle('Reporte diario a la Gerencia - ' . $reporte['fecha'], true);
        $this->SetAuthor('HANA - Grupo Regency', true);
    }

    //Nombre del archivo: Reporte_Gerencia_2026-10-29.pdf
    public static function nombreArchivo($fecha) { return 'Reporte_Gerencia_' . $fecha . '.pdf'; }

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

    //Números y porcentajes para leer
    private static function num($v) { return number_format((float)$v, 0, ',', '.'); }
    private static function pct($parte, $total)
    {
        if ((float)$total <= 0) { return null; }
        return 100 * (float)$parte / (float)$total;
    }
    private static function pctTexto($valor)
    {
        if ($valor === null) { return '—'; }
        return rtrim(rtrim(number_format($valor, 1, ',', '.'), '0'), ',') . ' %';
    }
    private static function mayus($t) { return mb_strtoupper((string)$t, 'UTF-8'); }

    //Colores de cada indicador: bien (menta), regular (ámbar) o mal (vino). Sin dato, gris
    //Flota: todos operativos (verde), algunos (ámbar) o ninguno (vino). Recibe el porcentaje de operativos
    private static function colorFlota($pct)   { return $pct === null ? self::GRIS : ($pct >= 100 ? self::MENTA : ($pct > 0 ? self::AMBAR : self::VINO)); }
    private static function colorPromesa($pct) { return $pct === null ? self::GRIS : ($pct >= 100 ? self::MENTA : ($pct >= 95 ? self::AMBAR : self::VINO)); }
    private static function colorOficios($total, $fuera) { return $total == 0 ? self::MENTA : ($fuera == 0 ? self::MENTA : ($fuera / $total <= 0.3 ? self::AMBAR : self::VINO)); }
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
        $this->escribir($x, 27, 'REPORTE DIARIO A LA GERENCIA', 13.5, 'B', self::VINO);
        $this->escribir($x, 46, self::primeraMayus($this->r['fechaLarga']), 9.5, '', self::GRIS);
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
        $origen = $this->r['origen'] === 'guardado' && $this->r['guardado']
            ? 'Versión guardada por ' . $this->r['guardado']['quien'] . ' el ' . date('d/m/Y H:i', strtotime($this->r['guardado']['fecha']))
            : ($this->r['origen'] === 'pantalla' ? 'Versión en edición (sin guardar)' : 'Propuesta del sistema (sin guardar)');
        $this->escribir(self::M, 758, 'Generado en HANA el ' . $this->cuando . ($this->quien !== '' ? ' por ' . $this->quien . ($this->rol !== '' ? ' (' . $this->rol . ')' : '') : '') . '  ·  ' . $origen, 6.8, '', self::GRIS);
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
        $this->escribir(self::M + 11, $this->cy, $texto, 12, 'B', self::AZUL);
        $this->cy += 18;
        foreach ($lineasNota as $renglon) { $this->escribir(self::M + 11, $this->cy, $renglon, 7.8, '', self::GRIS); $this->cy += 10; }
        $this->cy += 6;
    }

    //=======================================================================
    // Totales de todos los proyectos
    //=======================================================================
    private function totales()
    {
        $t = array('vehiculos' => 0, 'operativos' => 0, 'fuera' => 0, 'sinRegistro' => 0, 'prometidas' => 0, 'ejecutadas' => 0,
                   'debajo' => 0, 'encima' => 0, 'sinJustificar' => 0, 'oficios' => 0, 'fueraPlazo' => 0, 'porEnviar' => 0,
                   'temas' => 0, 'temasHoy' => 0, 'novedades' => 0);
        foreach ($this->r['proyectos'] as $p) {
            $c = $p['cifras'];
            $t['vehiculos'] += $c['flota']['total']; $t['operativos'] += $c['flota']['operativos'];
            $t['fuera'] += $c['flota']['fuera']; $t['sinRegistro'] += $c['flota']['sinRegistro'];
            $t['prometidas'] += $c['promesa']['prometidas']; $t['ejecutadas'] += $c['promesa']['ejecutadas'];
            $t['debajo'] += $c['promesa']['diasDebajo']; $t['encima'] += $c['promesa']['diasEncima']; $t['sinJustificar'] += $c['promesa']['sinJustificar'];
            $t['oficios'] += $c['oficios']['total']; $t['fueraPlazo'] += $c['oficios']['fueraPlazo']; $t['porEnviar'] += $c['oficios']['porEnviar'];
            $t['temas'] += $c['temas']['abiertos']; $t['temasHoy'] += count($c['temas']['movimientos']);
            $t['novedades'] += count($c['novedades']);
        }
        return $t;
    }

    //=======================================================================
    // Armado
    //=======================================================================
    public function armar()
    {
        $this->AddPage();
        $this->cy = self::INICIO;
        $this->resumen();
        $this->AddPage(); $this->cy = self::INICIO;
        $this->titulo('Detalle por proyecto', 'Lo que reporta cada proyecto en los puntos del día, con la información registrada por los coordinadores y revisada por la Dirección de Operaciones.');
        $this->detalle();
    }

    //-----------------------------------------------------------------------
    // Primera parte: resumen del día
    //-----------------------------------------------------------------------
    private function resumen()
    {
        $t = $this->totales();
        $n = count($this->r['proyectos']);
        //Presentación
        $semana = $this->r['lunes'] === $this->r['fecha'] ? 'la semana que inicia en la fecha' : 'la semana en curso (desde el lunes)';
        $intro = 'Se presenta el estado de la operación con corte al ' . $this->r['fechaLarga'] . ', para ' . ($n === 1 ? 'el proyecto a cargo' : 'los ' . $n . ' proyectos a cargo') .
                 ' de la Dirección de Operaciones: disponibilidad de la flota, cumplimiento de la promesa de valor durante ' . $semana .
                 ', oficios y comunicaciones en gestión, y novedades relevantes de la jornada. Las cifras provienen de los registros de los coordinadores en HANA.';
        $lineas = $this->renglones($intro, self::ANCHO - 28, 8.6);
        $alto = 18 + 11.2 * count($lineas);
        $this->relleno(self::FONDO);
        $this->caja(self::M, $this->cy, self::ANCHO, $alto, 6);
        $this->relleno(self::VINO);
        $this->caja(self::M, $this->cy, 4, $alto, 2);
        foreach ($lineas as $i => $renglon) { $this->escribir(self::M + 16, $this->cy + 9 + 11.2 * $i, $renglon, 8.6, '', self::NEGRO); }
        $this->cy += $alto + 14;

        //Tarjetas con las cifras del día
        $pFlota = self::pct($t['operativos'], $t['vehiculos']);
        $pPromesa = self::pct($t['ejecutadas'], $t['prometidas']);
        $tarjetas = array(
            array('Disponibilidad de flota', $t['vehiculos'] ? self::num($t['operativos']) . ' de ' . self::num($t['vehiculos']) : '—',
                  $t['vehiculos'] ? 'vehículos operativos' . ($t['fuera'] ? ' · ' . $t['fuera'] . ($t['fuera'] === 1 ? ' inoperativo' : ' inoperativos') : ' · ninguno inoperativo') . ($t['sinRegistro'] ? ' · ' . $t['sinRegistro'] . ' sin reporte' : '') : 'Sin vehículos asignados',
                  $t['vehiculos'] ? self::colorFlota($pFlota) : self::GRIS),
            array('Promesa de valor (semana)', self::pctTexto($pPromesa),
                  $t['prometidas'] > 0 ? self::num($t['ejecutadas']) . ' de ' . self::num($t['prometidas']) . ' horas ejecutadas · ' . $t['debajo'] . ($t['debajo'] === 1 ? ' caso' : ' casos') . ' por debajo' : 'Sin promesa registrada',
                  self::colorPromesa($pPromesa)),
            array('Oficios en gestión', self::num($t['oficios']),
                  $t['oficios'] ? $t['fueraPlazo'] . ' fuera de plazo · ' . $t['porEnviar'] . ' por enviar' : 'Sin oficios pendientes',
                  self::colorOficios($t['oficios'], $t['fueraPlazo'])),
            array('Temas con el cliente', self::num($t['temas']),
                  ($t['temas'] === 1 ? 'abierto' : 'abiertos') . ' · ' . $t['temasHoy'] . ' con movimiento en la fecha' . ($this->r['conNovedades'] ? ' · ' . $t['novedades'] . ($t['novedades'] === 1 ? ' novedad' : ' novedades') . ' del día' : ''),
                  $t['temas'] ? self::AMBAR : self::MENTA),
        );
        $sep = 9; $anchoT = (self::ANCHO - 3 * $sep) / 4;
        $altoT = 0;
        foreach ($tarjetas as $tarjeta) { $altoT = max($altoT, 58 + 8.6 * count($this->renglones($tarjeta[2], $anchoT - 24, 6.9))); }
        $this->espacio($altoT);
        foreach ($tarjetas as $i => $tarjeta) { $this->tarjeta(self::M + $i * ($anchoT + $sep), $this->cy, $anchoT, $altoT, $tarjeta); }
        $this->cy += $altoT + 14;

        //Gráficas por proyecto
        $this->titulo('Indicadores por proyecto', 'Verde: cumple · Ámbar: requiere atención · Vino: crítico. La promesa de valor compara las horas ejecutadas con las prometidas en la semana.');
        $flota = array(); $promesa = array(); $oficios = array();
        foreach ($this->r['proyectos'] as $p) {
            $c = $p['cifras'];
            $flota[] = array($p['nombre'], $c['flota']);
            $promesa[] = array($p['nombre'], self::pct($c['promesa']['ejecutadas'], $c['promesa']['prometidas']), '');
            $oficios[] = array($p['nombre'], $c['oficios']['total'] - $c['oficios']['fueraPlazo'], $c['oficios']['fueraPlazo']);
        }
        $anchoG = (self::ANCHO - 14) / 2;
        $altoG = max(34 + 17 * count($promesa) - 6, $this->graficaFlota(self::M, 0, $anchoG, $flota, true));
        $this->espacio($altoG);
        $y = $this->cy;
        $this->graficaFlota(self::M, $y, $anchoG, $flota, false, $altoG);
        $this->graficaPorcentaje(self::M + $anchoG + 14, $y, $anchoG, 'Cumplimiento de la promesa de valor', $promesa, 'colorPromesa', $altoG);
        $this->cy = $y + $altoG + 12;
        $altoO = 46 + 17 * count($oficios);
        $this->espacio($altoO);
        $this->graficaOficios(self::M, $this->cy, self::ANCHO, $oficios);
        $this->cy += $altoO + 10;

        //Tabla de resumen
        $this->titulo('Resumen por proyecto', '', 24 + 20 * count($this->r['proyectos']));
        $this->tablaResumen();
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
        $this->escribir($x + 10, $y + 9, $titulo, 8.2, 'B', self::AZUL);
        $anchoNombre = 86; $xBarra = $x + 10 + $anchoNombre; $anchoBarra = $ancho - $anchoNombre - 66;
        $yy = $y + 28;
        foreach ($items as $item) {
            list($nombre, $pct, $nota) = $item;
            $nombreCorto = $nombre;
            while ($this->anchoTexto($nombreCorto, 7.2) > $anchoNombre - 6 && mb_strlen($nombreCorto, 'UTF-8') > 3) { $nombreCorto = mb_substr($nombreCorto, 0, -1, 'UTF-8'); }
            $this->escribir($x + 10, $yy + 1, $nombreCorto, 7.2, '', self::NEGRO);
            $this->relleno(self::BLANCO);
            $this->caja($xBarra, $yy, $anchoBarra, 10, 3);
            if ($pct !== null && $pct > 0) {
                $this->relleno(call_user_func(array(__CLASS__, $colorDe), $pct));
                $this->caja($xBarra, $yy, max(6, $anchoBarra * min(100, $pct) / 100), 10, 3);
            }
            $this->escribir($xBarra + $anchoBarra + 5, $yy + 1, self::pctTexto($pct), 7.4, 'B', $pct === null ? self::GRIS : self::NEGRO);
            if ($nota !== '') { $this->escribir($x + $ancho - 8, $yy + 1.5, $nota, 6.2, '', self::GRIS, 'R'); }
            $yy += 17;
        }
    }

    //Estado de la flota por proyecto: una pastilla por vehículo (verde operativo, vino inoperativo,
    //gris sin reporte) y a la derecha cuántos están operativos. Con $medir = true solo dice cuánto mide
    private function graficaFlota($x, $y, $ancho, $items, $medir = false, $altoCaja = null)
    {
        $anchoNombre = 78; $xPastillas = $x + 10 + $anchoNombre; $anchoPastillas = $ancho - $anchoNombre - 62;
        //Primero se reparten las pastillas en renglones para saber cuánto mide cada proyecto
        $filas = array(); $alto = 28;
        foreach ($items as $item) {
            list($nombre, $f) = $item;
            $renglonesP = array(array()); $usado = 0;
            foreach ($f['vehiculos'] as $v) {
                $w = $this->anchoTexto($v['placa'], 6.4, 'B') + 12;
                if ($usado + $w > $anchoPastillas && $usado > 0) { $renglonesP[] = array(); $usado = 0; }
                $renglonesP[count($renglonesP) - 1][] = array($v, $w); $usado += $w + 4;
            }
            $altoFila = max(17, 13 * count($renglonesP) + 4);
            $filas[] = array($nombre, $f, $renglonesP, $altoFila);
            $alto += $altoFila;
        }
        $alto += 16; //leyenda
        if ($medir) { return $alto; }
        $this->relleno(self::FONDO);
        $this->caja($x, $y, $ancho, $altoCaja !== null ? $altoCaja : $alto, 6);
        $this->escribir($x + 10, $y + 9, 'Estado de la flota', 8.2, 'B', self::AZUL);
        $yy = $y + 28;
        foreach ($filas as $fila) {
            list($nombre, $f, $renglonesP, $altoFila) = $fila;
            $nombreCorto = $nombre;
            while ($this->anchoTexto($nombreCorto, 7.2) > $anchoNombre - 6 && mb_strlen($nombreCorto, 'UTF-8') > 3) { $nombreCorto = mb_substr($nombreCorto, 0, -1, 'UTF-8'); }
            $this->escribir($x + 10, $yy + 1, $nombreCorto, 7.2, '', self::NEGRO);
            if (!$f['total']) { $this->escribir($xPastillas, $yy + 1.5, 'Sin vehículos asignados', 6.8, '', self::GRIS); }
            foreach ($renglonesP as $r => $pastillas) {
                $xp = $xPastillas;
                foreach ($pastillas as $pastilla) {
                    list($v, $w) = $pastilla;
                    $color = $v['estado'] === 'OPERATIVO' ? self::MENTA : ($v['estado'] === 'FUERA' ? self::VINO : self::GRIS);
                    $this->relleno($color);
                    $this->caja($xp, $yy + 13 * $r, $w, 10.5, 5);
                    $this->escribir($xp, $yy + 13 * $r + 2.2, $v['placa'], 6.4, 'B', self::BLANCO, 'C', $xp + $w);
                    $xp += $w + 4;
                }
            }
            if ($f['total']) {
                $this->escribir($x + $ancho - 10, $yy + 1, $f['operativos'] . ' de ' . $f['total'], 7.4, 'B', self::colorFlota(self::pct($f['operativos'], $f['total'])) === self::AMBAR ? array(176, 120, 20) : self::colorFlota(self::pct($f['operativos'], $f['total'])), 'R');
            }
            $yy += $altoFila;
        }
        //Leyenda
        $xl = $x + 10; $yl = $y + ($altoCaja !== null ? $altoCaja : $alto) - 16;
        foreach (array(array('Operativo', self::MENTA), array('Inoperativo', self::VINO), array('Sin reporte', self::GRIS)) as $l) {
            $this->relleno($l[1]); $this->caja($xl, $yl + 1, 7, 7, 2);
            $this->escribir($xl + 10, $yl + 0.8, $l[0], 6.6, '', self::GRIS);
            $xl += $this->anchoTexto($l[0], 6.6) + 22;
        }
        return $alto;
    }

    //Barras apiladas: oficios dentro del plazo (menta) y fuera del plazo (vino), por proyecto
    private function graficaOficios($x, $y, $ancho, $items)
    {
        $alto = 46 + 17 * count($items) - 6;
        $this->relleno(self::FONDO);
        $this->caja($x, $y, $ancho, $alto, 6);
        $this->escribir($x + 10, $y + 9, 'Oficios y comunicaciones en gestión', 8.2, 'B', self::AZUL);
        //Leyenda
        $xl = $x + $ancho - 10;
        $this->escribir($xl, $y + 10, 'Fuera de plazo (más de ' . $this->r['plazoOficios'] . ' días)', 6.8, '', self::GRIS, 'R');
        $xl -= $this->anchoTexto('Fuera de plazo (más de ' . $this->r['plazoOficios'] . ' días)', 6.8) + 12;
        $this->relleno(self::VINO); $this->caja($xl, $y + 10, 8, 8, 2);
        $xl -= 10;
        $this->escribir($xl, $y + 10, 'Dentro del plazo', 6.8, '', self::GRIS, 'R');
        $xl -= $this->anchoTexto('Dentro del plazo', 6.8) + 12;
        $this->relleno(self::MENTA); $this->caja($xl, $y + 10, 8, 8, 2);

        $tope = 1;
        foreach ($items as $item) { $tope = max($tope, $item[1] + $item[2]); }
        $anchoNombre = 96; $xBarra = $x + 10 + $anchoNombre; $anchoBarra = $ancho - $anchoNombre - 60;
        $yy = $y + 32;
        foreach ($items as $item) {
            list($nombre, $enPlazo, $fuera) = $item;
            $this->escribir($x + 10, $yy + 1, $nombre, 7.2, '', self::NEGRO);
            $this->relleno(self::BLANCO);
            $this->caja($xBarra, $yy, $anchoBarra, 10, 3);
            $w1 = $anchoBarra * $enPlazo / $tope; $w2 = $anchoBarra * $fuera / $tope;
            if ($enPlazo > 0) { $this->relleno(self::MENTA); $this->caja($xBarra, $yy, max(6, $w1), 10, 3); }
            if ($fuera > 0)   { $this->relleno(self::VINO);  $this->caja($xBarra + ($enPlazo > 0 ? max(6, $w1) : 0), $yy, max(6, $w2), 10, 3); }
            $total = $enPlazo + $fuera;
            $this->escribir($xBarra + $anchoBarra + 6, $yy + 1, $total ? $total . ($fuera ? '  (' . $fuera . ' fuera)' : '') : 'Ninguno', 7.2, $total ? 'B' : '', $total ? self::NEGRO : self::GRIS);
            $yy += 17;
        }
    }

    //Tabla de resumen por proyecto
    private function tablaResumen()
    {
        $columnas = array(array('Proyecto', 112, 'L'), array('Flota operativa', 70, 'C'), array('Promesa de valor', 70, 'C'),
                          array('Casos por debajo', 62, 'C'), array('Oficios en gestión', 70, 'C'), array('Fuera de plazo', 54, 'C'),
                          array('Temas abiertos', 47, 'C'), array('Novedades del día', 47, 'C'));
        $altoCab = 24;
        $this->espacio($altoCab + 20 * count($this->r['proyectos'])); //la tabla no se parte: va completa
        $x = self::M; $y = $this->cy;
        $this->relleno(self::VINO);
        $this->caja($x, $y, self::ANCHO, $altoCab, 4);
        foreach ($columnas as $col) {
            $lineas = $this->renglones($col[0], $col[1] - 6, 6.6, 'B');
            $top = $y + ($altoCab - 7.8 * count($lineas)) / 2;
            foreach ($lineas as $i => $l) {
                if ($col[2] === 'L') { $this->escribir($x + 6, $top + 7.8 * $i, $l, 6.6, 'B', self::BLANCO); }
                else { $this->escribir($x, $top + 7.8 * $i, $l, 6.6, 'B', self::BLANCO, 'C', $x + $col[1]); }
            }
            $x += $col[1];
        }
        $this->cy = $y + $altoCab;
        foreach ($this->r['proyectos'] as $i => $p) {
            $c = $p['cifras'];
            $pF = self::pct($c['flota']['operativos'], $c['flota']['total']);
            $pP = self::pct($c['promesa']['ejecutadas'], $c['promesa']['prometidas']);
            $celdas = array(
                array($p['nombre'], null, 'B'),
                array($c['flota']['total'] ? $c['flota']['operativos'] . ' de ' . $c['flota']['total'] : '—', $c['flota']['total'] ? self::colorFlota($pF) : null, 'B'),
                array(self::pctTexto($pP), $pP === null ? null : self::colorPromesa($pP), 'B'),
                array((string)$c['promesa']['diasDebajo'], $c['promesa']['diasDebajo'] ? self::VINO : null, ''),
                array((string)$c['oficios']['total'], null, ''),
                array((string)$c['oficios']['fueraPlazo'], $c['oficios']['fueraPlazo'] ? self::VINO : null, ''),
                array((string)$c['temas']['abiertos'], null, ''),
                array($this->r['conNovedades'] ? (string)count($c['novedades']) : '—', count($c['novedades']) ? self::AMBAR : null, ''),
            );
            $alto = 20;
            $this->espacio($alto);
            $y = $this->cy; $x = self::M;
            if ($i % 2 === 1) { $this->relleno(self::FONDO); $this->Rect($x, $y, self::ANCHO, $alto, 'F'); }
            foreach ($columnas as $j => $col) {
                list($texto, $color, $estilo) = $celdas[$j];
                if ($color !== null && $j > 0) {
                    //Una pastilla de color suave detrás de la cifra
                    $w = min($col[1] - 8, $this->anchoTexto($texto, 7.4, 'B') + 14);
                    $this->relleno(self::suave($color));
                    $this->caja($x + ($col[1] - $w) / 2, $y + 4, $w, 12, 6);
                }
                $tinta = $color === null ? (($texto === '0' || $texto === '—') ? array(175, 170, 166) : self::NEGRO) : ($color === self::AMBAR ? array(176, 120, 20) : $color);
                if ($col[2] === 'L') { $this->escribir($x + 6, $y + 6, $texto, 7.4, $estilo, $tinta); }
                else { $this->escribir($x, $y + 6, $texto, 7.4, $estilo === '' && $color !== null ? 'B' : $estilo, $tinta, 'C', $x + $col[1]); }
                $x += $col[1];
            }
            $this->cy = $y + $alto;
            $this->relleno(self::LINEA); $this->Rect(self::M, $this->cy - 0.5, self::ANCHO, 0.5, 'F');
        }
        $this->cy += 10;
    }

    //-----------------------------------------------------------------------
    // Segunda parte: detalle de cada proyecto
    //-----------------------------------------------------------------------
    private function detalle()
    {
        $primero = true;
        foreach ($this->r['proyectos'] as $p) {
            //Cada proyecto empieza en hoja nueva (el primero, en la hoja del título «Detalle por proyecto»)
            $this->continua = '';
            if ($primero) { $primero = false; } else { $this->AddPage(); $this->cy = self::INICIO; }
            $this->franjaProyecto($p);
            $this->continua = $p['nombre'];
            $numero = 0;
            foreach ($this->r['puntos'] as $codigo => $titulo) {
                $numero++;
                $this->punto($numero, $titulo, isset($p['textos'][$codigo]) ? $p['textos'][$codigo] : '', $this->colorPunto($codigo, $p['cifras']));
            }
            $this->continua = '';
        }
        //Cierre
        $this->espacio(40);
        $this->cy += 6;
        $quien = $this->r['origen'] === 'guardado' && $this->r['guardado'] ? $this->r['guardado']['quien'] : $this->quien;
        if ($quien !== '') {
            $this->escribir(self::M, $this->cy, 'Elaborado por: ' . $quien, 8.4, 'B', self::NEGRO);
            $this->escribir(self::M, $this->cy + 12, 'Dirección de Operaciones · Grupo Regency', 7.8, '', self::GRIS);
        }
    }

    //El color de cada punto según cómo van sus cifras
    private function colorPunto($codigo, $c)
    {
        if ($codigo === 'FLOTA')   { return $c['flota']['total'] ? self::colorFlota(self::pct($c['flota']['operativos'], $c['flota']['total'])) : self::GRIS; }
        if ($codigo === 'PROMESA') { return self::colorPromesa(self::pct($c['promesa']['ejecutadas'], $c['promesa']['prometidas'])); }
        if ($codigo === 'OFICIOS') { return self::colorOficios($c['oficios']['total'], $c['oficios']['fueraPlazo']); }
        if ($codigo === 'NOVEDADES') { return (count($c['novedades']) || count($c['temas']['movimientos'])) ? self::AMBAR : self::MENTA; }
        return self::AZUL;
    }

    //Franja con el nombre del proyecto y sus cifras principales
    private function franjaProyecto($p)
    {
        $c = $p['cifras'];
        $alto = 58;
        $this->relleno(self::VINO);
        $this->caja(self::M, $this->cy, self::ANCHO, $alto, 8);
        $this->escribir(self::M + 16, $this->cy + 10, 'PROYECTO', 6.8, 'B', array(235, 200, 202));
        $this->escribir(self::M + 16, $this->cy + 21, $p['nombre'], 17, 'B', self::BLANCO);
        //Pastillas a la derecha
        $pF = self::pct($c['flota']['operativos'], $c['flota']['total']);
        $pP = self::pct($c['promesa']['ejecutadas'], $c['promesa']['prometidas']);
        $chips = array(
            array('Flota', $c['flota']['total'] ? $c['flota']['operativos'] . ' de ' . $c['flota']['total'] : '—', $c['flota']['total'] ? self::colorFlota($pF) : self::GRIS),
            array('Promesa', self::pctTexto($pP), self::colorPromesa($pP)),
            array('Oficios', (string)$c['oficios']['total'], self::colorOficios($c['oficios']['total'], $c['oficios']['fueraPlazo'])),
            array('Temas', (string)$c['temas']['abiertos'], self::AMBAR),
        );
        $x = self::M + self::ANCHO - 12;
        foreach (array_reverse($chips) as $chip) {
            $w = max($this->anchoTexto($chip[1], 11, 'B'), $this->anchoTexto($chip[0], 6.4, 'B')) + 18;
            $x -= $w;
            $this->relleno(self::BLANCO);
            $this->caja($x, $this->cy + 11, $w, 36, 6);
            $this->relleno($chip[2]);
            $this->caja($x, $this->cy + 11, $w, 3.5, 1.5);
            $this->escribir($x, $this->cy + 18, self::mayus($chip[0]), 6.4, 'B', self::GRIS, 'C', $x + $w);
            $this->escribir($x, $this->cy + 28, $chip[1], 11, 'B', $chip[2] === self::AMBAR ? array(176, 120, 20) : $chip[2], 'C', $x + $w);
            $x -= 6;
        }
        $this->cy += $alto + 14;
    }

    //Un punto del reporte: número, título y su texto en un recuadro de color suave
    private function punto($numero, $titulo, $texto, $color)
    {
        $x = self::M; $ancho = self::ANCHO;
        $tam = 8.2; $alto = 11;
        //El texto, renglón por renglón: «•» viñeta, «–» viñeta de segundo nivel, lo demás párrafo
        $piezas = array();
        $parrafos = $texto === '' ? array('Sin información para este punto.') : preg_split('/\n/u', $texto);
        foreach ($parrafos as $parrafo) {
            $parrafo = rtrim($parrafo);
            if (trim($parrafo) === '') { $piezas[] = array('espacio', ''); continue; }
            if (preg_match('/^\s*[•\-\*]\s+(.*)$/u', $parrafo, $m) && !preg_match('/^\s{2,}/u', $parrafo)) { $nivel = 1; $contenido = $m[1]; }
            elseif (preg_match('/^\s*[–—]\s+(.*)$/u', $parrafo, $m) || preg_match('/^\s{2,}[•\-\*]\s+(.*)$/u', $parrafo, $m)) { $nivel = 2; $contenido = $m[1]; }
            else { $nivel = 0; $contenido = trim($parrafo); }
            $sangria = $nivel === 0 ? 0 : ($nivel === 1 ? 12 : 26);
            $negrita = $nivel === 0 && count($piezas) === 0 && $texto !== '' && count($parrafos) > 1; //el primer renglón suele ser el resumen del punto
            foreach ($this->renglones($contenido, $ancho - 40 - $sangria, $tam, $negrita ? 'B' : '') as $k => $renglon) {
                $piezas[] = array($k === 0 ? 'inicio' : 'sigue', $renglon, $nivel, $sangria, $negrita);
            }
        }
        //Un punto no se parte entre dos hojas: si no cabe en lo que queda, pasa completo a la siguiente.
        //Solo se parte si por sí solo es más largo que una hoja entera
        $altoPunto = 28 + 12;
        foreach ($piezas as $pieza) { $altoPunto += $pieza[0] === 'espacio' ? 4 : $alto; }
        if ($altoPunto <= self::TOPE - self::INICIO - 20) { $this->espacio($altoPunto); } else { $this->espacio(30 + 2 * $alto); }
        //Encabezado del punto
        $y = $this->cy;
        $this->relleno(self::suave($color));
        $this->caja($x, $y, $ancho, 24, 5);
        $this->circulo($x + 15, $y + 12, 8, $color);
        $this->escribir($x + 7, $y + 7.6, (string)$numero, 8.4, 'B', self::BLANCO, 'C', $x + 23);
        $this->escribir($x + 30, $y + 7.2, $titulo, 9.6, 'B', $color === self::AMBAR ? array(140, 95, 15) : ($color === self::MENTA ? array(15, 120, 100) : ($color === self::GRIS ? self::NEGRO : $color)));
        $this->cy = $y + 28;
        //Cuerpo
        foreach ($piezas as $pieza) {
            if ($pieza[0] === 'espacio') { $this->cy += 4; continue; }
            list($tipo, $renglon, $nivel, $sangria, $negrita) = $pieza;
            if ($this->cy + $alto > self::TOPE) { $this->espacio($alto + 10); }
            $yy = $this->cy;
            $this->relleno($color);
            $this->Rect($x + 4, $yy - 1, 2.2, $alto + 1, 'F'); //raya de color a la izquierda, a lo largo de todo el punto
            if ($tipo === 'inicio' && $nivel === 1) { $this->circulo($x + 20 + 3, $yy + 4.6, 1.8, $color); }
            if ($tipo === 'inicio' && $nivel === 2) { $this->relleno(self::GRIS); $this->Rect($x + 20 + 15, $yy + 4.2, 4, 0.9, 'F'); }
            $this->escribir($x + 20 + $sangria, $yy, $renglon, $tam, $negrita ? 'B' : '', $nivel === 2 ? array(60, 60, 64) : self::NEGRO);
            $this->cy += $alto;
        }
        $this->cy += 12;
    }
}
