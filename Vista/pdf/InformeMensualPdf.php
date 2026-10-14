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
  texto pasa por txt() para que las tildes salgan bien.
  Los colores son los de la paleta de HANA: vino, menta, ámbar, negro y blanco.
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
    private function txt($t)
    {
        $t = (string)$t;
        if (function_exists('iconv')) {
            $r = @iconv('UTF-8', 'windows-1252//TRANSLIT', $t);
            if ($r !== false) { return $r; }
        }
        if (function_exists('mb_convert_encoding')) { return mb_convert_encoding($t, 'windows-1252', 'UTF-8'); }
        return $t;
    }

    //Colores de texto, relleno y línea a partir de un arreglo [r, g, b]
    private function tinta($c)   { $this->SetTextColor($c[0], $c[1], $c[2]); }
    private function relleno($c) { $this->SetFillColor($c[0], $c[1], $c[2]); }
    private function trazo($c)   { $this->SetDrawColor($c[0], $c[1], $c[2]); }

    //Escribe un texto. $top es el borde superior de la letra.
    //$alin: L (desde $x), R (terminando en $x) o C (centrado entre $x y $x2)
    private function t($x, $top, $texto, $tam, $estilo = '', $color = null, $alin = 'L', $x2 = 0)
    {
        $this->SetFont('Helvetica', $estilo, $tam);
        $this->tinta($color === null ? self::NEGRO : $color);
        $s = $this->txt($texto);
        $w = $this->GetStringWidth($s);
        if ($alin === 'R')     { $x = $x - $w; }
        elseif ($alin === 'C') { $x = $x + (($x2 - $x) - $w) / 2; }
        $this->Text($x, $top + 0.905 * $tam, $s); //FPDF pide la línea base de la letra
    }

    //Recorta un texto para que quepa en un ancho, con "..." al final
    private function cabe($texto, $ancho, $tam, $estilo = '')
    {
        $this->SetFont('Helvetica', $estilo, $tam);
        $texto = trim(preg_replace('/\s+/', ' ', (string)$texto));
        if ($this->GetStringWidth($this->txt($texto)) <= $ancho) { return $texto; }
        while ($texto !== '' && $this->GetStringWidth($this->txt($texto . '...')) > $ancho) {
            $texto = function_exists('mb_substr') ? mb_substr($texto, 0, -1, 'UTF-8') : substr($texto, 0, -1);
        }
        return rtrim($texto) . '...';
    }

    //Parte un texto en renglones que quepan en un ancho. Una palabra que sola no cabe
    //(un correo, un código largo) se parte por letras: nada se sale de su espacio
    private function renglones($texto, $ancho, $tam, $estilo = '')
    {
        $this->SetFont('Helvetica', $estilo, $tam);
        $res = array(); $linea = '';
        $ancho = max(10, $ancho);
        foreach (preg_split('/\s+/', trim((string)$texto)) as $palabra) {
            if ($palabra === '') { continue; }
            $prueba = $linea === '' ? $palabra : "$linea $palabra";
            if ($this->GetStringWidth($this->txt($prueba)) <= $ancho) { $linea = $prueba; continue; }
            if ($linea !== '') { $res[] = $linea; $linea = ''; }
            while ($this->GetStringWidth($this->txt($palabra)) > $ancho) {        //la palabra sola no cabe: se parte
                $n = 1; $largo = function_exists('mb_strlen') ? mb_strlen($palabra, 'UTF-8') : strlen($palabra);
                while ($n < $largo && $this->GetStringWidth($this->txt(self::corta($palabra, 0, $n + 1))) <= $ancho) { $n++; }
                $res[] = self::corta($palabra, 0, $n);
                $palabra = self::corta($palabra, $n, null);
            }
            $linea = $palabra;
        }
        if ($linea !== '' || !count($res)) { $res[] = $linea; }
        return $res;
    }
    //Recorta un texto respetando las tildes (usa mb_substr si el servidor lo tiene)
    private static function corta($t, $desde, $largo)
    {
        return function_exists('mb_substr') ? mb_substr($t, $desde, $largo, 'UTF-8') : ($largo === null ? substr($t, $desde) : substr($t, $desde, $largo));
    }

    //Rectángulo con las esquinas redondeadas. $estilo: F (relleno), D (borde) o DF (los dos)
    private function caja($x, $y, $w, $h, $r, $estilo = 'F')
    {
        $k = $this->k; $hp = $this->h;
        $op = $estilo === 'F' ? 'f' : ($estilo === 'DF' ? 'B' : 'S');
        $arco = 4 / 3 * (sqrt(2) - 1); //cuánto se "abomba" la curva para que parezca un cuarto de círculo
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->curva($xc + $r * $arco, $yc - $r, $xc + $r, $yc - $r * $arco, $xc + $r, $yc);
        $xc = $x + $w - $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->curva($xc + $r, $yc + $r * $arco, $xc + $r * $arco, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->curva($xc - $r * $arco, $yc + $r, $xc - $r, $yc + $r * $arco, $xc - $r, $yc);
        $xc = $x + $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->curva($xc - $r, $yc - $r * $arco, $xc - $r * $arco, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }
    //Dibuja una curva (FPDF no trae una función para esto)
    private function curva($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $k = $this->k; $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x1 * $k, ($h - $y1) * $k, $x2 * $k, ($h - $y2) * $k, $x3 * $k, ($h - $y3) * $k));
    }

    //1234567 -> "1.234.567"
    private static function num($n) { return number_format((float)$n, 0, ',', '.'); }
    //Pesos sin centavos: "$ 1.234.567"
    private static function pesos($n) { return '$ ' . self::num(round((float)$n)); }
    //"2026-10-06" -> "06/10/2026"
    private static function fecha($f)
    {
        $p = explode('-', substr((string)$f, 0, 10));
        return count($p) === 3 ? $p[2] . '/' . $p[1] . '/' . $p[0] : (string)$f;
    }
    //"1 día" / "5 días"
    private static function dias($n) { $n = (int)$n; return $n . ($n === 1 ? ' día' : ' días'); }
    //Singular o plural según la cantidad: plural(1, 'vacante abierta', 'vacantes abiertas')
    private static function plural($n, $uno, $varios) { return self::num($n) . ' ' . ((int)$n === 1 ? $uno : $varios); }

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
        $per = $this->datos['periodo'];
        //Logo de Regency (copia en JPG: FPDF no lee PNG con transparencia). Si no está, se sigue sin él
        $logo = __DIR__ . '/../../public/img/logo_pdf.jpg';
        $x = self::M;
        if (is_file($logo)) { $this->Image($logo, self::M, 24, 40 * (360 / 312), 40); $x = self::M + 58; }
        //"INFORME MENSUAL DE GESTIÓN" si es un mes exacto; "INFORME DE GESTIÓN" para cualquier otro rango
        $this->t($x, 28, $per['titulo'], 13, 'B', self::VINO);
        //Qué periodo cubre. Si el nombre ya son las fechas, no se repiten
        $this->t($x, 46, $per['nombre'] . (strpos($per['nombre'], 'Del ') === 0 ? ($per['completo'] ? '' : '  ·  hasta hoy') : '  ·  ' . $per['detalle']), 9, '', self::GRIS);
        $this->t(self::M + self::ANCHO, 29, 'Grupo Regency', 9, 'B', self::NEGRO, 'R');
        //El tema de esta hoja: así se sabe de qué trata cada hoja aunque se imprima suelta
        if ($this->seccionActual !== '') {
            $this->t(self::M + self::ANCHO, 44, $this->numSeccion . ' de ' . self::SECCIONES . ' · ' . $this->seccionActual, 8, 'B', self::VINO, 'R');
        } else {
            $this->t(self::M + self::ANCHO, 43, 'Sistema HANA · Operación de peajes', 7.5, '', self::GRIS, 'R');
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
        $this->t(self::M, 758, 'Generado en HANA el ' . $this->cuando . ($this->quien !== '' ? ' por ' . $this->cabe($this->quien, 260, 7) : '')
                 . '  ·  Datos hasta el ' . self::fecha($this->datos['periodo']['corte']), 7, '', self::GRIS);
        $this->t(self::M + self::ANCHO, 758, 'Página ' . $this->PageNo() . ' de {total}', 7, '', self::GRIS, 'R');
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
        $ancho = $this->GetStringWidth($this->txt($titulo));
        $alLado = $nota === '' ? array() : $this->renglones($nota, self::ANCHO - $ancho - 20, 7.5);
        $debajo = count($alLado) > 1 ? $this->renglones($nota, self::ANCHO - 9, 7.5) : array();
        $alto = 20 + 9.5 * count($debajo);
        $this->espacio($alto + $siguiente);
        $y = $this->cy;
        $this->relleno(self::MENTA);
        $this->caja(self::M, $y + 1.5, 3.5, 11, 1.7); //marquita menta a la izquierda del título
        $this->t(self::M + 9, $y, $titulo, 11.5, 'B', self::NEGRO);
        if (count($alLado) === 1) { $this->t(self::M + 9 + $ancho + 8, $y + 3, $alLado[0], 7.5, '', self::GRIS); }
        foreach ($debajo as $i => $l) { $this->t(self::M + 9, $y + 16 + 9.5 * $i, $l, 7.5, '', self::GRIS); }
        $this->cy = $y + $alto;
    }

    //Cuánto mide una tarjeta para que quepa todo su detalle
    private function altoTarjeta($w, $detalle, $minimo)
    {
        $n = $detalle === '' ? 0 : count($this->renglones($detalle, $w - 22, 6.8));
        return max($minimo, 46 + 8.4 * $n + 8);
    }

    //Tarjeta con una cifra grande: rótulo arriba, número y el detalle completo
    private function tarjeta($x, $y, $w, $h, $rotulo, $valor, $detalle, $color)
    {
        $this->relleno(self::FONDO);
        $this->caja($x, $y, $w, $h, 6);
        $this->relleno($color);
        $this->caja($x + 6, $y + 9, 3, $h - 18, 1.5);                      //franja de color a la izquierda
        $rot = $this->renglones(self::mayus($rotulo), $w - 22, 6.5, 'B');
        $this->t($x + 15, $y + 8, $rot[0], 6.5, 'B', self::GRIS);
        $tam = $h >= 60 ? 21 : 16;
        $this->SetFont('Helvetica', 'B', $tam);
        while ($tam > 9 && $this->GetStringWidth($this->txt($valor)) > $w - 22) { $tam--; $this->SetFont('Helvetica', 'B', $tam); } //si la cifra es larga, se achica
        $this->t($x + 15, $y + 19, $valor, $tam, 'B', self::NEGRO);
        if ($detalle !== '') {
            $lineas = $this->renglones($detalle, $w - 22, 6.8);
            $top = $y + $h - 9 - 8.4 * count($lineas);                         //el detalle va pegado abajo
            foreach ($lineas as $i => $l) { $this->t($x + 15, $top + $i * 8.4, $l, 6.8, '', self::GRIS); }
        }
    }

    //Una fila de tarjetas del mismo alto: cada una es array(rótulo, valor, detalle, color)
    private function filaTarjetas($tarjetas, $porFila, $minimo, $sep = 9)
    {
        $w = (self::ANCHO - ($porFila - 1) * $sep) / $porFila;
        foreach (array_chunk($tarjetas, $porFila) as $fila) {
            $h = $minimo;
            foreach ($fila as $c) { $h = max($h, $this->altoTarjeta($w, $c[2], $minimo)); }
            $this->espacio($h);
            foreach ($fila as $i => $c) { $this->tarjeta(self::M + $i * ($w + $sep), $this->cy, $w, $h, $c[0], $c[1], $c[2], $c[3]); }
            $this->cy += $h + $sep;
        }
    }

    //Mayúsculas con tildes, sin depender de mbstring
    private static function mayus($t)
    {
        if (function_exists('mb_strtoupper')) { return mb_strtoupper((string)$t, 'UTF-8'); }
        return strtr(strtoupper((string)$t), array('á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ñ' => 'Ñ', 'ü' => 'Ü'));
    }

    //Tabla en flujo.
    //  $cols:  cada columna es array(título, ancho, alineación L/R/C)
    //  $filas: cada celda es un texto, o array('t' => texto, 'c' => color, 'b' => true para negrita)
    //  $total: una fila más, en negrita, con fondo suave (o null)
    //Un texto largo ocupa los renglones que necesite (la fila crece); si la fila no cabe en la
    //hoja, pasa entera a la siguiente y allá se repite el encabezado
    private function tabla($cols, $filas, $total = null, $altoFila = 15)
    {
        $x = self::M;
        $ancho = 0; foreach ($cols as $c) { $ancho += $c[1]; }
        $lh = 8.8; //alto de cada renglón dentro de una celda
        //El encabezado: los títulos también pueden ir en dos renglones
        $titulos = array(); $nCab = 1;
        foreach ($cols as $c) { $r = $this->renglones($c[0], $c[1] - 8, 6.6, 'B'); $titulos[] = $r; $nCab = max($nCab, count($r)); }
        $altoCab = max(17, 8 + 7.6 * $nCab);
        $pintarCab = function () use ($x, $ancho, $cols, $titulos, $altoCab) {
            $y = $this->cy;
            $this->relleno(self::VINO);
            $this->caja($x, $y, $ancho, $altoCab, 3);
            $cx = $x;
            foreach ($cols as $j => $c) {
                $top = $y + ($altoCab - 7.6 * count($titulos[$j])) / 2 + 0.6;
                foreach ($titulos[$j] as $i => $l) {
                    $ty = $top + 7.6 * $i;
                    if ($c[2] === 'L')     { $this->t($cx + 5, $ty, $l, 6.6, 'B', array(255, 255, 255)); }
                    elseif ($c[2] === 'R') { $this->t($cx + $c[1] - 5, $ty, $l, 6.6, 'B', array(255, 255, 255), 'R'); }
                    else                   { $this->t($cx, $ty, $l, 6.6, 'B', array(255, 255, 255), 'C', $cx + $c[1]); }
                }
                $cx += $c[1];
            }
            $this->cy = $y + $altoCab;
        };
        if ($total !== null) { $filas[] = array('_total' => $total); } //la fila de totales se pinta como una más, al final
        //Se mide cada fila: sus renglones por celda y su alto
        $medidas = array();
        foreach ($filas as $fila) {
            $esTotal = isset($fila['_total']); $celdas = $esTotal ? $fila['_total'] : $fila;
            $partes = array(); $n = 1;
            //Una celda sola en la fila (un aviso como "y 3 más") ocupa todo el ancho
            $unaSola = !$esTotal && count($celdas) === 1 && count($cols) > 1;
            foreach ($cols as $j => $c) {
                $celda = isset($celdas[$j]) ? $celdas[$j] : '';
                $texto = is_array($celda) ? (string)$celda['t'] : (string)$celda;
                $negrita = $esTotal || (is_array($celda) && !empty($celda['b']));
                $r = $texto === '' ? array('') : $this->renglones($texto, ($unaSola ? $ancho : $c[1]) - 8, 7.4, $negrita ? 'B' : '');
                $partes[] = array($r, $negrita, is_array($celda) && isset($celda['c']) ? $celda['c'] : null, $texto);
                $n = max($n, count($r));
            }
            $medidas[] = array($esTotal, $partes, max($altoFila, $n * $lh + 6), $unaSola);
        }
        //El encabezado no se deja solo al final de una hoja: va con al menos la primera fila
        $this->espacio($altoCab + (count($medidas) ? $medidas[0][2] : 0));
        $pintarCab();
        foreach ($medidas as $i => $m) {
            list($esTotal, $partes, $h, $unaSola) = $m;
            if ($this->cy + $h > self::TOPE) {
                $this->AddPage(); $this->cy = self::INICIO;
                //Si la tabla es de un proyecto, la hoja nueva dice de cuál (la franja quedó en la hoja anterior)
                if ($this->continua !== '') { $this->t(self::M, $this->cy, $this->continua . ' (continúa)', 9, 'B', self::VINO); $this->cy += 15; }
                $pintarCab();
            }
            $y = $this->cy;
            if ($esTotal)         { $this->relleno(self::VINO_SUAVE); $this->Rect($x, $y, $ancho, $h, 'F'); }
            elseif ($i % 2 === 1) { $this->relleno(self::FONDO);      $this->Rect($x, $y, $ancho, $h, 'F'); } //una fila sí y otra no
            $cx = $x;
            foreach ($cols as $j => $c) {
                list($lineas, $negrita, $color, $texto) = $partes[$j];
                //Un cero no dice nada: se deja en gris claro para que resalte lo que sí tiene valor
                if ($color === null) { $color = ($texto === '0' || $texto === '-') && !$esTotal ? array(190, 186, 182) : self::NEGRO; }
                $top = $y + ($h - count($lineas) * $lh) / 2 + 0.6;
                foreach ($lineas as $k => $l) {
                    $ty = $top + $k * $lh;
                    if ($c[2] === 'L' || $unaSola) { $this->t($cx + 5, $ty, $l, 7.4, $negrita ? 'B' : '', $color); }
                    elseif ($c[2] === 'R')         { $this->t($cx + $c[1] - 5, $ty, $l, 7.4, $negrita ? 'B' : '', $color, 'R'); }
                    else                           { $this->t($cx, $ty, $l, 7.4, $negrita ? 'B' : '', $color, 'C', $cx + $c[1]); }
                }
                if ($unaSola) { break; }
                $cx += $c[1];
            }
            $this->cy = $y + $h;
            $this->relleno(self::LINEA); $this->Rect($x, $this->cy - 0.5, $ancho, 0.5, 'F'); //rayita entre filas
        }
    }

    //Barras horizontales: una por cada "nombre => valor", la más larga arriba.
    //Un nombre largo pasa al renglón de abajo. Con $medir = true solo dice cuánto mide
    //$comoFrase: true pasa "LICENCIA REMUNERADA" a "Licencia remunerada"; false deja el nombre como está
    private function barras($x, $y, $w, $items, $color, $anchoNombre = 120, $comoFrase = true, $medir = false, $topeFijo = null)
    {
        arsort($items);
        $tope = $topeFijo !== null ? $topeFijo : (count($items) ? max(1, max($items)) : 1);
        $anchoBarra = $w - $anchoNombre - 34;           //lo que queda para la barra, dejando campo al número
        $alto = 0;
        foreach ($items as $nombre => $valor) {
            $lineas = $this->renglones($comoFrase ? self::frase($nombre) : $nombre, $anchoNombre - 6, 7.4);
            $h = max(15, 8.6 * count($lineas) + 6.4);
            if (!$medir) {
                foreach ($lineas as $i => $l) { $this->t($x, $y + 1.5 + 8.6 * $i, $l, 7.4, '', self::NEGRO); }
                $this->relleno(self::FONDO);
                $this->caja($x + $anchoNombre, $y, $anchoBarra, 10, 3);                   //el riel de la barra
                if ($valor > 0) {                                                       //en cero no se pinta barra, solo el riel
                    $this->relleno($color);
                    $this->caja($x + $anchoNombre, $y, max(6, $anchoBarra * $valor / $tope), 10, 3);
                }
                $this->t($x + $anchoNombre + $anchoBarra + 5, $y + 1.3, self::num($valor), 7.6, 'B', self::NEGRO);
            }
            $y += $h; $alto += $h;
        }
        return $medir ? $alto : $y;
    }

    //Dos bloques de barras lado a lado (con su subtítulo). Si juntos no caben en lo que queda
    //de la hoja, va uno debajo del otro, a todo el ancho
    private function dosBarras($t1, $items1, $c1, $n1, $f1, $t2, $items2, $c2, $n2, $f2)
    {
        $mitad = (self::ANCHO - 20) / 2;
        $h = max($this->barras(0, 0, $mitad, $items1, $c1, $n1, $f1, true), $this->barras(0, 0, $mitad, $items2, $c2, $n2, $f2, true));
        if (14 + $h <= self::TOPE - self::INICIO) {           //cabe en una hoja: lado a lado
            $this->espacio(14 + $h);
            $this->t(self::M, $this->cy, $t1, 8, 'B', self::GRIS);
            $this->t(self::M + $mitad + 20, $this->cy, $t2, 8, 'B', self::GRIS);
            $y1 = $this->barras(self::M, $this->cy + 14, $mitad, $items1, $c1, $n1, $f1);
            $y2 = $this->barras(self::M + $mitad + 20, $this->cy + 14, $mitad, $items2, $c2, $n2, $f2);
            $this->cy = max($y1, $y2);
            return;
        }
        foreach (array(array($t1, $items1, $c1, $n1, $f1), array($t2, $items2, $c2, $n2, $f2)) as $b) {
            $this->espacio(14 + 15);
            $this->t(self::M, $this->cy, $b[0], 8, 'B', self::GRIS);
            $this->cy += 14;
            arsort($b[1]);
            foreach ($b[1] as $nombre => $valor) {                    //una barra a la vez, revisando que quepa
                $alto = $this->barras(0, 0, self::ANCHO, array($nombre => $valor), $b[2], $b[3] + 60, $b[4], true);
                $this->espacio($alto);
                $this->barras(self::M, $this->cy, self::ANCHO, array($nombre => $valor), $b[2], $b[3] + 60, $b[4], false, max(1, max($b[1])));
                $this->cy += $alto;
            }
            $this->cy += 8;
        }
    }

    //"LICENCIA NO REMUNERADA" -> "Licencia no remunerada" (los catálogos se guardan en mayúsculas)
    private static function frase($t)
    {
        $t = (string)$t;
        if (function_exists('mb_strtolower')) {
            $t = mb_strtolower($t, 'UTF-8');
            return mb_strtoupper(mb_substr($t, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($t, 1, null, 'UTF-8');
        }
        return ucfirst(strtolower($t));
    }

    //Aviso suave cuando una sección no tiene datos
    private function vacio($texto, $x = null, $w = null, $y = null)
    {
        $x = $x === null ? self::M : $x; $w = $w === null ? self::ANCHO : $w;
        $lineas = $this->renglones($texto, $w - 16, 7.6, 'I');
        $h = 14 + 9 * count($lineas);
        if ($y === null) { $this->espacio($h); $y = $this->cy; }
        $this->relleno(self::FONDO);
        $this->caja($x, $y, $w, $h, 5);
        foreach ($lineas as $i => $l) { $this->t($x, $y + 7 + 9 * $i, $l, 7.6, 'I', self::GRIS, 'C', $x + $w); }
        return $y + $h + 6;
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
        $d = $this->datos;
        $this->resumen($d);
        if ($d['periodo']['dias'] === 0) { return; } //el periodo no ha empezado: solo el resumen con el aviso
        $this->requisiciones($d);
        $this->arqueos($d);
        $this->reporteDiario($d);
        $this->vacantesOficios($d);
        $this->ausentismo($d);
    }

    //Empieza un tema: hoja nueva y una franja con el número, el título y de qué trata
    private function nuevaSeccion($titulo, $descripcion)
    {
        $this->numSeccion++;
        $this->seccionActual = $titulo;
        $this->continua = '';
        $this->AddPage(); $this->cy = self::INICIO;
        $desc = $this->renglones($descripcion, self::ANCHO - 84, 8.5);
        $alto = max(58, 38 + 10.5 * count($desc));
        $y = $this->cy;
        $this->relleno(self::VINO);
        $this->caja(self::M, $y, self::ANCHO, $alto, 8);
        //El número del tema en un círculo blanco
        $this->relleno(array(255, 255, 255));
        $this->caja(self::M + 14, $y + ($alto - 34) / 2, 34, 34, 17);
        $this->t(self::M + 14, $y + ($alto - 34) / 2 + 8, (string)$this->numSeccion, 15, 'B', self::VINO, 'C', self::M + 48);
        $this->t(self::M + 62, $y + 11, $titulo, 17, 'B', array(255, 255, 255));
        foreach ($desc as $i => $l) { $this->t(self::M + 62, $y + 33 + 10.5 * $i, $l, 8.5, '', array(240, 222, 223)); }
        $this->cy = $y + $alto + 16;
    }

    //Franja con el nombre de un proyecto dentro de un tema. $siguiente: lo que debe caber debajo
    private function bandaProyecto($p, $extra = '', $siguiente = 60)
    {
        $nombre = $this->renglones($p['NOM_PROYECTO'], 250, 10.5, 'B');
        $der = $this->renglones('Coordinador: ' . ($p['COORDINADOR'] ? $p['COORDINADOR'] : 'sin asignar') . ($extra !== '' ? '  ·  ' . $extra : ''), self::ANCHO - 290, 7.5);
        $alto = max(24, 12 + max(12 * count($nombre), 9.5 * count($der)));
        $this->espacio($alto + $siguiente);
        $y = $this->cy;
        $this->relleno(self::VINO_SUAVE);
        $this->caja(self::M, $y, self::ANCHO, $alto, 5);
        $this->relleno(self::VINO);
        $this->caja(self::M, $y, 4, $alto, 2);
        foreach ($nombre as $i => $l) { $this->t(self::M + 12, $y + 6 + 12 * $i, $l, 10.5, 'B', self::VINO); }
        foreach ($der as $i => $l) { $this->t(self::M + self::ANCHO - 10, $y + 7.5 + 9.5 * $i, $l, 7.5, '', self::GRIS, 'R'); }
        $this->cy = $y + $alto + 8;
        $this->continua = $p['NOM_PROYECTO'];
    }

    //Nombre de un peaje o báscula como sale en las tablas
    private static function nomCentro($c) { return ($c['tipo'] === 'BASCULA' ? 'Báscula ' : '') . $c['nombre']; }

    //Las columnas de una tabla por peaje: el nombre se lleva lo que sobre del ancho
    private static function anchoCompleto($cols)
    {
        $cols[0][1] += self::ANCHO - array_sum(array_column($cols, 1));
        return $cols;
    }

    //Nota en cursiva al final de un tema
    private function nota($texto)
    {
        $l = $this->renglones($texto, self::ANCHO, 7, 'I');
        $this->espacio(9 * count($l));
        foreach ($l as $x) { $this->t(self::M, $this->cy, $x, 7, 'I', self::GRIS); $this->cy += 9; }
    }

    //-----------------------------------------------------------------------
    // 1. Resumen general: las cifras del periodo, lo más relevante y el comparativo
    //-----------------------------------------------------------------------
    private function resumen($d)
    {
        $t = $d['total']; $per = $d['periodo'];
        $proyectos = $d['proyectos']; $nP = count($proyectos);
        $this->nuevaSeccion('Resumen general', self::plural($nP, 'proyecto', 'proyectos') . ' · ' . self::plural($d['centros'], 'peaje o báscula', 'peajes y básculas')
                            . ' · ' . self::dias($per['dias']) . ' del periodo. Las cifras de todo el informe en una sola mirada; el detalle de cada tema va en las hojas siguientes.');
        if ($per['dias'] === 0) { $this->vacio('Ese periodo todavía no empieza: no hay nada que informar.'); return; }
        $cumple = $this->cumplimientoPromedio($d);
        $this->filaTarjetas(array(
            array('Requisiciones', self::num($t['rq']), self::plural($t['rqAprob'], 'aprobada', 'aprobadas') . ' · ' . self::plural($t['rqRech'], 'rechazada', 'rechazadas') . ' · ' . self::num($t['rqPend']) . ' por aprobar', self::VINO),
            array('Novedades', self::num($t['nov']), self::plural($t['novCerr'], 'atendida', 'atendidas') . ' · ' . self::plural($t['novAb'], 'abierta', 'abiertas'), self::VINO),
            array('Listas de chequeo', self::num($t['listas']), 'diligenciadas en el periodo', self::MENTA),
            array('Arqueos', self::num($t['arq']), $t['arqDif'] > 0 ? self::num($t['arqDif']) . ' con diferencia' : ($t['arq'] > 0 ? 'todos cuadraron' : 'sin arqueos en el periodo'), self::MENTA),
            array('Vacantes abiertas', self::num($t['vacAb']), self::plural($t['vacNuevas'], 'nueva', 'nuevas') . ' · ' . self::plural($t['vacCub'], 'cubierta', 'cubiertas') . ' en el periodo', self::AMBAR),
            array('Oficios recibidos', self::num($t['ofRec']), self::plural($t['ofRes'], 'atendido', 'atendidos') . ' · ' . self::plural($t['ofPend'], 'pendiente', 'pendientes') . ' hoy', self::AMBAR),
            array('Ausencias', self::num($t['aus']), 'personas por día, en todos los peajes', self::VINO),
            array('Reporte diario', $cumple . ' %', 'días con "Hoy en qué estás", promedio de los coordinadores', self::semaforo($cumple)),
        ), 4, 70);
        $this->cy += 8;

        //Lo más relevante: frases armadas con las cifras (cada una en los renglones que necesite)
        $frases = array(); $alto = 12;
        foreach ($this->relevante($d) as $f) { $l = $this->renglones($f[1], self::ANCHO - 34, 8); $frases[] = array($f[0], $l); $alto += 11 * count($l) + 2; }
        $this->seccion('Lo más relevante', '', min($alto, 200));
        $this->espacio($alto);
        $this->relleno(self::FONDO);
        $this->caja(self::M, $this->cy - 2, self::ANCHO, $alto, 6);
        $y = $this->cy + 5;
        foreach ($frases as $f) {
            $this->relleno($f[0]);
            $this->caja(self::M + 11, $y + 2.2, 4.5, 4.5, 2.2);              //puntico del color del tema
            foreach ($f[1] as $i => $l) { $this->t(self::M + 22, $y + 11 * $i, $l, 8, '', self::NEGRO); }
            $y += 11 * count($f[1]) + 2;
        }
        $this->cy += $alto + 12;

        //Comparativo por proyecto: una mirada rápida a todo
        $this->seccion('Comparativo por proyecto', 'Lo registrado en el periodo. Vacantes y oficios: los que siguen abiertos hoy.');
        $cols = array(array('Proyecto', 112, 'L'), array('RQ', 36, 'R'), array('Por aprobar', 46, 'R'), array('Novedades', 44, 'R'), array('Listas', 34, 'R'),
                      array('Arqueos', 38, 'R'), array('Con dif.', 36, 'R'), array('Vacantes', 40, 'R'), array('Oficios pend.', 50, 'R'),
                      array('Ausencias', 44, 'R'), array('Reporte', 52, 'R'));
        $filas = array();
        foreach ($proyectos as $p) {
            $k = $p['kpi']; $c = $p['reporte']['cumple'];
            $filas[] = array($p['NOM_PROYECTO'], self::num($k['rq']), $this->aviso($k['rqPend'], self::AMBAR),
                             self::num($k['nov']), self::num($k['listas']), self::num($k['arq']), $this->aviso($k['arqDif'], self::VINO),
                             self::num($k['vacAb']), self::num($k['ofPend']), self::num($k['aus']),
                             array('t' => $c . ' %', 'c' => self::semaforo($c), 'b' => true));
        }
        $total = $nP > 1 ? array('Total', self::num($t['rq']), self::num($t['rqPend']), self::num($t['nov']), self::num($t['listas']), self::num($t['arq']),
                                 self::num($t['arqDif']), self::num($t['vacAb']), self::num($t['ofPend']), self::num($t['aus']), $cumple . ' %') : null;
        $this->tabla($cols, $filas, $total, 16);
    }

    //-----------------------------------------------------------------------
    // 2. Requisiciones y novedades
    //-----------------------------------------------------------------------
    private function requisiciones($d)
    {
        $t = $d['total']; $proyectos = $d['proyectos'];
        $this->nuevaSeccion('Requisiciones y novedades', 'Las RQ pedidas y las novedades registradas en el periodo, según cómo van hoy. Primero el total por proyecto; después cada proyecto, peaje por peaje, con las RQ que siguen por aprobar.');
        $cols = array(array('Proyecto', 116, 'L'), array('RQ pedidas', 52, 'R'), array('Urgentes', 46, 'R'), array('Aprobadas', 50, 'R'), array('Rechazadas', 54, 'R'),
                      array('Por aprobar', 54, 'R'), array('Novedades', 54, 'R'), array('Atendidas', 52, 'R'), array('Abiertas', 54, 'R'));
        $fila = function ($nombre, $k) {
            return array($nombre, self::num($k['rq']), self::num($k['rqUrg']), self::num($k['rqAprob']), self::num($k['rqRech']),
                         $this->aviso($k['rqPend'], self::AMBAR), self::num($k['nov']), self::num($k['novCerr']), $this->aviso($k['novAb'], self::AMBAR));
        };
        $filas = array();
        foreach ($proyectos as $p) { $filas[] = $fila($p['NOM_PROYECTO'], $p['kpi']); }
        $this->tabla($cols, $filas, count($proyectos) > 1 ? array_map(function ($c) { return is_array($c) ? $c['t'] : $c; }, $fila('Total', $t)) : null, 16);
        $this->cy += 16;

        $cols[0][0] = 'Peaje o báscula';
        foreach ($proyectos as $p) {
            $this->bandaProyecto($p, self::plural($p['kpi']['rq'], 'RQ', 'RQ') . ' · ' . self::plural($p['kpi']['nov'], 'novedad', 'novedades'));
            $filas = array();
            foreach ($p['centros'] as $c) { $filas[] = $fila(self::nomCentro($c), isset($c['kpi']) ? $c['kpi'] : $this->datos['ceros']); }
            if (!count($filas)) { $this->cy = $this->vacio('Este proyecto no tiene peajes activos.') + 6; continue; }
            $this->tabla(self::anchoCompleto($cols), $filas, null, 14.5);
            $this->cy += 8;
            $pe = $p['pendientes'];
            if (count($pe['rq'])) {
                $this->espacio(14 + 32);
                $this->subtitulo(self::M, $this->cy, 'RQ que siguen por aprobar');
                $this->cy += 18;
                $filas = array();
                foreach ($pe['rq'] as $x) {
                    $vencida = (int)$x['DIAS'] > $pe['plazoRq'] || $x['TIPO_RQ'] === 'U';
                    $filas[] = array(($x['TIPO_RQ'] === 'U' ? 'RQ U ' : 'RQ ') . $x['NUMERO_RQ'], $x['CENTRO'],
                                     array('t' => self::dias($x['DIAS']), 'c' => $vencida ? self::VINO : self::GRIS, 'b' => $vencida));
                }
                $this->tabla(array(array('RQ', 120, 'L'), array('Peaje o báscula', 342, 'L'), array('Lleva', 70, 'R')), $filas, null, 14);
                $this->cy += 8;
            }
            $this->cy += 8;
        }
        $this->nota('Plazo para aprobar una RQ: ' . self::dias($d['plazoRq']) . '. Las urgentes y las que pasaron el plazo salen en vino. Se cambia en Configuración, Parámetros del sistema.');
    }

    //-----------------------------------------------------------------------
    // 3. Arqueos
    //-----------------------------------------------------------------------
    private function arqueos($d)
    {
        $t = $d['total']; $proyectos = $d['proyectos'];
        $this->nuevaSeccion('Arqueos', 'Los arqueos del periodo (caja menor, recambio y casetas) y los que no cuadraron con el fondo autorizado. Primero el total por proyecto; después cada proyecto, peaje por peaje.');
        $cols = array(array('Proyecto', 172, 'L'), array('Arqueos', 70, 'R'), array('Con diferencia', 80, 'R'), array('Faltantes', 105, 'R'), array('Sobrantes', 105, 'R'));
        $fila = function ($nombre, $k) {
            return array($nombre, self::num($k['arq']), $this->aviso($k['arqDif'], self::VINO),
                         $k['arqFalt'] > 0 ? array('t' => self::pesos($k['arqFalt']), 'c' => self::VINO, 'b' => true) : '-',
                         $k['arqSobr'] > 0 ? array('t' => self::pesos($k['arqSobr']), 'c' => self::AMBAR, 'b' => true) : '-');
        };
        $filas = array();
        foreach ($proyectos as $p) { $filas[] = $fila($p['NOM_PROYECTO'], $p['kpi']); }
        $this->tabla($cols, $filas, count($proyectos) > 1 ? array_map(function ($c) { return is_array($c) ? $c['t'] : $c; }, $fila('Total', $t)) : null, 16);
        $this->cy += 16;

        $cols[0][0] = 'Peaje o báscula';
        $tipos = array('CAJA_MENOR' => 'Caja menor', 'RECAMBIO' => 'Recambio', 'CASETA' => 'Caseta');
        foreach ($proyectos as $p) {
            $k = $p['kpi'];
            $this->bandaProyecto($p, self::plural($k['arq'], 'arqueo', 'arqueos') . ($k['arqDif'] > 0 ? ', ' . self::num($k['arqDif']) . ' con diferencia' : ''));
            $filas = array();
            foreach ($p['centros'] as $c) { $filas[] = $fila(self::nomCentro($c), isset($c['kpi']) ? $c['kpi'] : $this->datos['ceros']); }
            if (!count($filas)) { $this->cy = $this->vacio('Este proyecto no tiene peajes activos.') + 6; continue; }
            $this->tabla($cols, $filas, null, 14.5);
            $this->cy += 8;
            //Los que no cuadraron: todos, los de mayor diferencia primero
            $this->espacio(14 + 32);
            $this->subtitulo(self::M, $this->cy, 'Arqueos que no cuadraron');
            $this->cy += 18;
            if (!count($p['arqueosDif'])) {
                $this->cy = $this->vacio($k['arq'] > 0 ? 'Todos los arqueos del periodo cuadraron.' : 'No se registraron arqueos en el periodo.') + 10;
                continue;
            }
            $filas = array();
            foreach ($p['arqueosDif'] as $a) {
                $falta = (float)$a['DIFERENCIA'] < 0;
                $filas[] = array(self::fecha($a['FECHA']), $a['CENTRO'],
                                 self::tipoArqueo($a, $tipos),
                                 (string)$a['RESPONSABLE'],
                                 array('t' => ($falta ? 'Faltante ' : 'Sobrante ') . self::pesos(abs((float)$a['DIFERENCIA'])), 'c' => $falta ? self::VINO : self::AMBAR, 'b' => true));
            }
            $this->tabla(array(array('Fecha', 62, 'L'), array('Peaje', 120, 'L'), array('Tipo', 96, 'L'), array('Responsable', 150, 'L'), array('Diferencia', 104, 'R')), $filas, null, 14);
            $this->cy += 16;
        }
    }

    //-----------------------------------------------------------------------
    // 4. Reporte diario de los coordinadores
    //-----------------------------------------------------------------------
    private function reporteDiario($d)
    {
        $per = $d['periodo']; $proyectos = $d['proyectos'];
        $this->nuevaSeccion('Reporte diario de los coordinadores', 'Hoy en qué estás, visitas del cronograma, listas de chequeo y vehículo, de los ' . self::dias($per['dias'])
                            . ' del periodo. Primero la comparación entre coordinadores; después el detalle de cada uno.');
        $cols = array(array('Proyecto', 92, 'L'), array('Coordinador', 128, 'L'), array('Hoy en qué estás', 70, 'R'), array('Cumple', 44, 'R'),
                      array('Visitas hechas', 62, 'R'), array('Listas', 38, 'R'), array('Arqueos', 42, 'R'), array('Sin vehículo', 56, 'R'));
        $filas = array();
        foreach ($proyectos as $p) {
            $r = $p['reporte'];
            $filas[] = array($p['NOM_PROYECTO'], $p['COORDINADOR'] ? $p['COORDINADOR'] : array('t' => 'Sin asignar', 'c' => self::VINO),
                             $r['hoyDias'] . ' de ' . $r['dias'], array('t' => $r['cumple'] . ' %', 'c' => self::semaforo($r['cumple']), 'b' => true),
                             $r['visProg'] > 0 ? $r['visReal'] . ' de ' . $r['visProg'] : '-',
                             self::num($p['kpi']['listas']), self::num($p['kpi']['arq']),
                             $r['vehNo'] > 0 ? array('t' => self::dias($r['vehNo']), 'c' => self::AMBAR, 'b' => true) : '0');
        }
        $this->tabla($cols, $filas, null, 16);
        $this->cy += 16;

        foreach ($proyectos as $p) {
            $k = $p['kpi']; $r = $p['reporte'];
            $this->bandaProyecto($p, $r['cumple'] . ' % de cumplimiento', 125); //el bloque del coordinador va entero en una hoja
            $filas = array(
                array('Hoy en qué estás', array('t' => $r['hoyDias'] . ' de ' . self::dias($r['dias']) . ' (' . $r['cumple'] . ' %)', 'c' => self::semaforo($r['cumple']), 'b' => true)),
                array('Visitas del cronograma', $r['visProg'] > 0 ? $r['visReal'] . ' hechas de ' . $r['visProg'] . ' planeadas' : 'Sin visitas planeadas'),
                array('Listas de chequeo', self::plural($k['listas'], 'diligenciada', 'diligenciadas')),
                array('Arqueos', self::plural($k['arq'], 'arqueo', 'arqueos') . ($k['arqDif'] > 0 ? ', ' . self::num($k['arqDif']) . ' con diferencia' : '')),
                array('Vehículo', ($r['vehOk'] + $r['vehNo']) > 0 ? array('t' => self::dias($r['vehOk']) . ' operativo, ' . self::dias($r['vehNo']) . ' sin operar', 'c' => $r['vehNo'] > 0 ? self::AMBAR : self::NEGRO, 'b' => $r['vehNo'] > 0) : 'Sin reporte del vehículo'),
                array('Días sin "Hoy en qué estás"', count($r['sinHoy']) ? array('t' => implode(', ', $r['sinHoy']), 'c' => self::VINO) : 'Ninguno: registró todos los días'),
            );
            $this->tabla(array(array('Qué', 170, 'L'), array('Resultado', 362, 'L')), $filas, null, 14);
            $this->cy += 16;
        }
    }

    //-----------------------------------------------------------------------
    // 5. Vacantes y oficios
    //-----------------------------------------------------------------------
    private function vacantesOficios($d)
    {
        $t = $d['total']; $proyectos = $d['proyectos'];
        $this->nuevaSeccion('Vacantes y oficios', 'Las vacantes y las comunicaciones u oficios del periodo, y lo que sigue abierto hoy (de este periodo o de antes), lo más antiguo primero.');
        $cols = array(array('Proyecto', 92, 'L'), array('Vacantes nuevas', 54, 'R'), array('Cubiertas', 48, 'R'), array('Abiertas hoy', 52, 'R'), array('Fuera del acuerdo', 62, 'R'),
                      array('Oficios recibidos', 58, 'R'), array('Atendidos', 52, 'R'), array('Sin atender', 52, 'R'), array('Fuera de plazo', 62, 'R'));
        $fila = function ($nombre, $k) {
            return array($nombre, self::num($k['vacNuevas']), self::num($k['vacCub']), self::num($k['vacAb']), $this->aviso($k['vacFuera'], self::VINO),
                         self::num($k['ofRec']), self::num($k['ofRes']), self::num($k['ofPend']), $this->aviso($k['ofVenc'], self::VINO));
        };
        $filas = array();
        foreach ($proyectos as $p) { $filas[] = $fila($p['NOM_PROYECTO'], $p['kpi']); }
        $this->tabla($cols, $filas, count($proyectos) > 1 ? array_map(function ($c) { return is_array($c) ? $c['t'] : $c; }, $fila('Total', $t)) : null, 16);
        $this->cy += 16;

        foreach ($proyectos as $p) {
            $pe = $p['pendientes'];
            $this->bandaProyecto($p, self::plural(count($pe['vacantes']), 'vacante abierta', 'vacantes abiertas') . ' · ' . self::plural(count($pe['oficios']), 'oficio sin atender', 'oficios sin atender'));
            $filas = array();
            foreach ($pe['vacantes'] as $x) {
                $fuera = (int)$x['FUERA'] === 1;
                $filas[] = array('Vacante abierta', self::frase($x['CARGO']) . ' · ' . $x['CENTRO'], array('t' => self::dias($x['DIAS']), 'c' => $fuera ? self::VINO : self::GRIS, 'b' => $fuera));
            }
            foreach ($pe['oficios'] as $x) {
                $tarde = (int)$x['DIAS'] > $pe['plazoOficio'];
                $filas[] = array('Oficio sin atender', ($x['RADICADO'] ? $x['RADICADO'] . ' · ' : '') . $x['ASUNTO'], array('t' => self::dias($x['DIAS']), 'c' => $tarde ? self::VINO : self::GRIS, 'b' => $tarde));
            }
            if (!count($filas)) { $this->cy = $this->vacio('No hay vacantes abiertas ni oficios sin atender.') + 12; continue; }
            $this->tabla(array(array('Qué', 96, 'L'), array('Detalle', 366, 'L'), array('Lleva', 70, 'R')), $filas, null, 14);
            $this->cy += 16;
        }
        $this->nota('Plazo para atender un oficio: ' . self::dias($d['plazoOficio']) . '. Las vacantes se miden contra su acuerdo de servicio. En vino, lo que ya pasó el plazo. Se cambian en Configuración, Parámetros del sistema.');
    }

    //-----------------------------------------------------------------------
    // 6. Ausentismo
    //-----------------------------------------------------------------------
    private function ausentismo($d)
    {
        $t = $d['total']; $proyectos = $d['proyectos'];
        $this->nuevaSeccion('Ausentismo', 'Personas ausentes por día, por motivo, proyecto y peaje. Incluye a quien dejó su cargo para cubrir otro (CUBRIENDO A…).');
        if ($t['aus'] <= 0) { $this->vacio('No hay ausencias registradas en el periodo.'); return; }
        $porProyecto = array();
        foreach ($proyectos as $p) { $porProyecto[$p['NOM_PROYECTO']] = $p['kpi']['aus']; }
        $this->dosBarras('Por motivo', $d['ausMotivos'], self::VINO, 118, true, 'Por proyecto', $porProyecto, self::AMBAR, 92, false);
        $this->cy += 18;

        foreach ($proyectos as $p) {
            $this->bandaProyecto($p, self::plural($p['kpi']['aus'], 'ausencia', 'ausencias'));
            if ($p['kpi']['aus'] <= 0) { $this->cy = $this->vacio('Sin ausencias en el periodo.') + 12; continue; }
            $porPeaje = array();
            foreach ($p['centros'] as $c) { $porPeaje[self::nomCentro($c)] = isset($c['kpi']) ? $c['kpi']['aus'] : 0; }
            $this->dosBarras('Por motivo', $p['ausMotivos'], self::VINO, 112, true, 'Por peaje', $porPeaje, self::AMBAR, 100, false);
            $this->cy += 16;
        }
    }

    //Una cifra que pide atención: si es mayor que cero sale en negrita y con color; si no, un cero normal
    private function aviso($n, $color)
    {
        return $n > 0 ? array('t' => self::num($n), 'c' => $color, 'b' => true) : '0';
    }

    //El promedio del cumplimiento de "Hoy en qué estás" de los coordinadores
    private function cumplimientoPromedio($d)
    {
        $n = count($d['proyectos']);
        if (!$n) { return 0; }
        $suma = 0; foreach ($d['proyectos'] as $p) { $suma += $p['reporte']['cumple']; }
        return (int)round($suma / $n);
    }

    //Las frases de "Lo más relevante": array(color, texto)
    private function relevante($d)
    {
        $t = $d['total']; $f = array();
        //Requisiciones
        if ($t['rq'] > 0) {
            $f[] = array(self::VINO, ($t['rq'] === 1 ? 'Se pidió ' : 'Se pidieron ') . self::plural($t['rq'], 'requisición', 'requisiciones')
                         . ($t['rqUrg'] > 0 ? ' (' . self::plural($t['rqUrg'], 'urgente', 'urgentes') . ')' : '')
                         . ': ' . self::plural($t['rqAprob'], 'aprobada', 'aprobadas') . ', ' . self::plural($t['rqRech'], 'rechazada', 'rechazadas') . ' y ' . self::num($t['rqPend']) . ' por aprobar.');
        } else { $f[] = array(self::VINO, 'No se pidieron requisiciones en el periodo.'); }
        //Arqueos
        if ($t['arq'] > 0) {
            $f[] = array(self::MENTA, $t['arqDif'] > 0
                ? 'De ' . self::plural($t['arq'], 'arqueo', 'arqueos') . ', ' . ($t['arqDif'] === 1 ? '1 no cuadró' : self::num($t['arqDif']) . ' no cuadraron')
                  . ': faltantes por ' . self::pesos($t['arqFalt']) . ' y sobrantes por ' . self::pesos($t['arqSobr']) . '.'
                : ($t['arq'] === 1 ? 'Se hizo 1 arqueo y cuadró.' : 'Se hicieron ' . self::num($t['arq']) . ' arqueos y todos cuadraron.'));
        } else { $f[] = array(self::MENTA, 'No se registraron arqueos en el periodo.'); }
        //Vacantes
        $f[] = array(self::AMBAR, $t['vacAb'] > 0
            ? 'Hay ' . self::plural($t['vacAb'], 'vacante abierta', 'vacantes abiertas') . '; ' . ($t['vacFuera'] === 1 ? '1 ya pasó' : self::num($t['vacFuera']) . ' ya pasaron') . ' la fecha del acuerdo de servicio.'
            : 'No hay vacantes abiertas.');
        //Oficios
        $f[] = array(self::AMBAR, $t['ofPend'] > 0
            ? 'Hay ' . self::plural($t['ofPend'], 'oficio sin atender', 'oficios sin atender') . '; ' . ($t['ofVenc'] === 1 ? '1 lleva' : self::num($t['ofVenc']) . ' llevan') . ' más de ' . self::dias($d['plazoOficio']) . '.'
            : 'Todos los oficios y comunicaciones están atendidos.');
        //Ausentismo: el proyecto con más
        if ($t['aus'] > 0) {
            $mayor = null;
            foreach ($d['proyectos'] as $p) { if ($mayor === null || $p['kpi']['aus'] > $mayor['kpi']['aus']) { $mayor = $p; } }
            $f[] = array(self::VINO, ($t['aus'] === 1 ? 'Se registró ' : 'Se registraron ') . self::plural($t['aus'], 'ausencia', 'ausencias') . ' (personas por día)'
                         . (count($d['proyectos']) > 1 ? '; el proyecto con más fue ' . $mayor['NOM_PROYECTO'] . ' (' . self::num($mayor['kpi']['aus']) . ').' : '.'));
        } else { $f[] = array(self::VINO, 'No se registraron ausencias en el periodo.'); }
        //Reporte diario: el coordinador que menos reportó
        if (count($d['proyectos']) > 1) {
            $menor = null;
            foreach ($d['proyectos'] as $p) { if ($menor === null || $p['reporte']['cumple'] < $menor['reporte']['cumple']) { $menor = $p; } }
            $f[] = array(self::semaforo($menor['reporte']['cumple']), 'Reporte diario: el cumplimiento más bajo de "Hoy en qué estás" fue el de ' . $menor['NOM_PROYECTO']
                         . ' (' . $menor['reporte']['cumple'] . ' %, ' . $menor['reporte']['hoyDias'] . ' de ' . self::dias($menor['reporte']['dias']) . ').');
        }
        return $f;
    }

    //num() para usar desde funciones anónimas y array_map
    public static function numPub($n) { return self::num($n); }

    //Subtítulo pequeño dentro de una hoja
    private function subtitulo($x, $y, $texto)
    {
        $this->t($x, $y, $texto, 9, 'B', self::NEGRO);
        $this->relleno(self::LINEA); $this->Rect($x, $y + 13, 60, 1, 'F');
    }

    //Nombre del archivo: "Informe de gestion HANA 2026-10.pdf" (un mes) o
    //"Informe de gestion HANA 2026-08-01 a 2026-10-06.pdf" (otro rango)
    public static function nombreArchivo($periodo)
    {
        if (!empty($periodo['mensual'])) { return 'Informe de gestion HANA ' . sprintf('%04d-%02d', $periodo['anio'], $periodo['mes']) . '.pdf'; }
        return 'Informe de gestion HANA ' . $periodo['desde'] . ' a ' . $periodo['corte'] . '.pdf';
    }

    //"Caseta 4" en vez de "Caseta Caseta 4" cuando el nombre de la caseta ya dice "caseta"
    private static function tipoArqueo($a, $tipos)
    {
        $t = isset($tipos[$a['TIPO']]) ? $tipos[$a['TIPO']] : $a['TIPO'];
        if ($a['TIPO'] !== 'CASETA' || !$a['CASETA']) { return $t; }
        return stripos(trim($a['CASETA']), 'caseta') === 0 ? trim($a['CASETA']) : $t . ' ' . trim($a['CASETA']);
    }
}
