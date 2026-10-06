<?php
/*
  HANA — Informe de gestión, mensual o por rango de fechas (el PDF)
  ---------------------------------------------------------------------------
  Pinta lo que arma Modelo/InformeMensualModelo.php:
    Primero La parte general (dos hojas con cinco proyectos): las cifras del
            mes, lo más relevante, el comparativo por proyecto, el
            ausentismo, requisiciones y novedades, arqueos, vacantes y
            oficios, el reporte diario de los coordinadores y lo pendiente.
    Luego   Una hoja por proyecto, con sus peajes.
  La idea es que no pase de 10 hojas: con más de 8 proyectos, cada hoja
  lleva dos proyectos en versión corta.

  Hoja carta vertical, medidas en puntos (612 × 792). Usa FPDF 1.9
  (public/fpdf), igual que los arqueos. FPDF escribe en Windows-1252: todo
  texto pasa por txt() para que las tildes salgan bien.
  Los colores son los de la paleta de HANA: vino, menta, ámbar, negro y blanco.
*/
require_once __DIR__ . '/../public/fpdf/fpdf.php';
require_once __DIR__ . '/InformeMensualModelo.php';

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
    const MAX_HOJAS_PROYECTO = 8; //con más proyectos que esto, van dos por hoja

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

    //Parte un texto en renglones que quepan en un ancho
    private function renglones($texto, $ancho, $tam, $estilo = '')
    {
        $this->SetFont('Helvetica', $estilo, $tam);
        $res = array(); $linea = '';
        foreach (preg_split('/\s+/', trim((string)$texto)) as $palabra) {
            $prueba = $linea === '' ? $palabra : "$linea $palabra";
            if ($this->GetStringWidth($this->txt($prueba)) <= $ancho) { $linea = $prueba; continue; }
            if ($linea !== '') { $res[] = $linea; }
            $linea = $palabra;
        }
        if ($linea !== '') { $res[] = $linea; }
        return $res;
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
        $logo = __DIR__ . '/../public/img/logo_pdf.jpg';
        $x = self::M;
        if (is_file($logo)) { $this->Image($logo, self::M, 24, 40 * (360 / 312), 40); $x = self::M + 58; }
        //"INFORME MENSUAL DE GESTIÓN" si es un mes exacto; "INFORME DE GESTIÓN" para cualquier otro rango
        $this->t($x, 28, $per['titulo'], 13, 'B', self::VINO);
        //Qué periodo cubre. Si el nombre ya son las fechas, no se repiten
        $this->t($x, 46, $per['nombre'] . (strpos($per['nombre'], 'Del ') === 0 ? ($per['completo'] ? '' : '  ·  hasta hoy') : '  ·  ' . $per['detalle']), 9, '', self::GRIS);
        $this->t(self::M + self::ANCHO, 29, 'Grupo Regency', 9, 'B', self::NEGRO, 'R');
        $this->t(self::M + self::ANCHO, 43, 'Sistema HANA · Operación de peajes', 7.5, '', self::GRIS, 'R');
        //Línea vino debajo del encabezado
        $this->relleno(self::VINO);
        $this->Rect(self::M, 72, self::ANCHO, 1.6, 'F');
    }

    public function Footer()
    {
        $this->relleno(self::LINEA);
        $this->Rect(self::M, 752, self::ANCHO, 0.8, 'F');
        $this->t(self::M, 758, 'Generado en HANA el ' . $this->cuando . ($this->quien !== '' ? ' por ' . $this->cabe($this->quien, 180, 7) : '')
                 . '  ·  Datos hasta el ' . self::fecha($this->datos['periodo']['corte']), 7, '', self::GRIS);
        $this->t(self::M + self::ANCHO, 758, 'Página ' . $this->PageNo() . ' de {total}', 7, '', self::GRIS, 'R');
    }

    //=======================================================================
    // Piezas que se repiten: título de sección, tarjeta, tabla y barras
    //=======================================================================

    //Título de una sección, con su explicación al lado. Devuelve dónde sigue el contenido
    private function seccion($y, $titulo, $nota = '')
    {
        $this->relleno(self::MENTA);
        $this->caja(self::M, $y + 1.5, 3.5, 11, 1.7); //marquita menta a la izquierda del título
        $this->t(self::M + 9, $y, $titulo, 11.5, 'B', self::NEGRO);
        if ($nota !== '') {
            $this->SetFont('Helvetica', 'B', 11.5);
            $ancho = $this->GetStringWidth($this->txt($titulo));
            $this->t(self::M + 9 + $ancho + 8, $y + 3, $this->cabe($nota, self::ANCHO - $ancho - 20, 7.5), 7.5, '', self::GRIS);
        }
        return $y + 20;
    }

    //Tarjeta con una cifra grande: rótulo arriba, número y una línea de detalle
    private function tarjeta($x, $y, $w, $h, $rotulo, $valor, $detalle, $color)
    {
        $this->relleno(self::FONDO);
        $this->caja($x, $y, $w, $h, 6);
        $this->relleno($color);
        $this->caja($x + 6, $y + 9, 3, $h - 18, 1.5);                      //franja de color a la izquierda
        $this->t($x + 15, $y + 8, self::mayus($rotulo), 6.5, 'B', self::GRIS);
        $tam = $h >= 60 ? 21 : 16;                                         //las tarjetas bajitas llevan la cifra más pequeña
        $this->t($x + 15, $y + 19, $this->cabe($valor, $w - 22, $tam, 'B'), $tam, 'B', self::NEGRO);
        if ($detalle !== '') {
            $lineas = $this->renglones($detalle, $w - 22, 6.8);
            $top = $y + $h - 9 - 8.4 * min(2, count($lineas));               //el detalle va pegado abajo, en uno o dos renglones
            foreach (array_slice($lineas, 0, 2) as $i => $l) { $this->t($x + 15, $top + $i * 8.4, $l, 6.8, '', self::GRIS); }
        }
    }

    //Mayúsculas con tildes, sin depender de mbstring
    private static function mayus($t)
    {
        if (function_exists('mb_strtoupper')) { return mb_strtoupper((string)$t, 'UTF-8'); }
        return strtr(strtoupper((string)$t), array('á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ñ' => 'Ñ', 'ü' => 'Ü'));
    }

    //Tabla sencilla.
    //  $cols:  cada columna es array(título, ancho, alineación L/R/C)
    //  $filas: cada celda es un texto, o array('t' => texto, 'c' => color, 'b' => true para negrita)
    //  $total: una fila más, en negrita, con fondo suave (o null)
    //Devuelve dónde termina
    private function tabla($x, $y, $cols, $filas, $total = null, $altoFila = 15)
    {
        $ancho = 0; foreach ($cols as $c) { $ancho += $c[1]; }
        //Encabezado: vino con letra blanca
        $this->relleno(self::VINO);
        $this->caja($x, $y, $ancho, 17, 3);
        $cx = $x;
        foreach ($cols as $c) {
            $tit = $this->cabe($c[0], $c[1] - 6, 6.6, 'B');
            if ($c[2] === 'L')     { $this->t($cx + 5, $y + 5.4, $tit, 6.6, 'B', array(255, 255, 255)); }
            elseif ($c[2] === 'R') { $this->t($cx + $c[1] - 5, $y + 5.4, $tit, 6.6, 'B', array(255, 255, 255), 'R'); }
            else                   { $this->t($cx, $y + 5.4, $tit, 6.6, 'B', array(255, 255, 255), 'C', $cx + $c[1]); }
            $cx += $c[1];
        }
        $y += 17;
        if ($total !== null) { $filas[] = array('_total' => $total); } //la fila de totales se pinta como una más, al final
        foreach ($filas as $i => $fila) {
            $esTotal = isset($fila['_total']);
            if ($esTotal) { $fila = $fila['_total']; }
            if ($esTotal)         { $this->relleno(self::VINO_SUAVE); $this->Rect($x, $y, $ancho, $altoFila, 'F'); }
            elseif ($i % 2 === 1) { $this->relleno(self::FONDO);      $this->Rect($x, $y, $ancho, $altoFila, 'F'); } //una fila sí y otra no
            $cx = $x;
            foreach ($cols as $j => $c) {
                $celda = isset($fila[$j]) ? $fila[$j] : '';
                $texto = is_array($celda) ? (string)$celda['t'] : (string)$celda;
                $negrita = $esTotal || (is_array($celda) && !empty($celda['b']));
                //Un cero no dice nada: se deja en gris claro para que resalte lo que sí tiene valor
                $color = is_array($celda) && isset($celda['c']) ? $celda['c'] : (($texto === '0' || $texto === '-') && !$esTotal ? array(190, 186, 182) : self::NEGRO);
                $texto = $this->cabe($texto, $c[1] - 8, 7.4, $negrita ? 'B' : '');
                $top = $y + ($altoFila - 7.4) / 2 - 0.3;
                if ($c[2] === 'L')     { $this->t($cx + 5, $top, $texto, 7.4, $negrita ? 'B' : '', $color); }
                elseif ($c[2] === 'R') { $this->t($cx + $c[1] - 5, $top, $texto, 7.4, $negrita ? 'B' : '', $color, 'R'); }
                else                   { $this->t($cx, $top, $texto, 7.4, $negrita ? 'B' : '', $color, 'C', $cx + $c[1]); }
                $cx += $c[1];
            }
            $y += $altoFila;
            $this->relleno(self::LINEA); $this->Rect($x, $y - 0.5, $ancho, 0.5, 'F'); //rayita entre filas
        }
        return $y;
    }

    //Barras horizontales: una por cada "nombre => valor". Devuelve dónde termina
    //$comoFrase: true pasa "LICENCIA REMUNERADA" a "Licencia remunerada"; false deja el nombre como está
    private function barras($x, $y, $w, $items, $color, $anchoNombre = 120, $maximo = 8, $comoFrase = true)
    {
        arsort($items);                                 //la más larga arriba
        $items = array_slice($items, 0, $maximo, true);
        $tope = count($items) ? max(1, max($items)) : 1;
        $anchoBarra = $w - $anchoNombre - 34;           //lo que queda para la barra, dejando campo al número
        foreach ($items as $nombre => $valor) {
            $this->t($x, $y + 1.5, $this->cabe($comoFrase ? self::frase($nombre) : $nombre, $anchoNombre - 6, 7.4), 7.4, '', self::NEGRO);
            $this->relleno(self::FONDO);
            $this->caja($x + $anchoNombre, $y, $anchoBarra, 10, 3);                       //el riel de la barra
            if ($valor > 0) {                                                           //en cero no se pinta barra, solo el riel
                $this->relleno($color);
                $this->caja($x + $anchoNombre, $y, max(6, $anchoBarra * $valor / $tope), 10, 3);
            }
            $this->t($x + $anchoNombre + $anchoBarra + 5, $y + 1.3, self::num($valor), 7.6, 'B', self::NEGRO);
            $y += 15;
        }
        return $y;
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
    private function vacio($x, $y, $w, $texto)
    {
        $this->relleno(self::FONDO);
        $this->caja($x, $y, $w, 24, 5);
        $this->t($x, $y + 8, $texto, 7.6, 'I', self::GRIS, 'C', $x + $w);
        return $y + 30;
    }

    //=======================================================================
    // Arma el informe completo
    //=======================================================================
    public function armar()
    {
        $d = $this->datos;
        $this->general($d); //las primeras hojas: lo de todos los proyectos
        $proyectos = $d['proyectos'];
        if (count($proyectos) <= self::MAX_HOJAS_PROYECTO) {
            foreach ($proyectos as $p) { $this->AddPage(); $this->proyecto($p, 86, false); } //una hoja por proyecto
        } else {
            //Muchos proyectos: dos por hoja, en versión corta, para no pasar de 10 hojas
            foreach (array_chunk($proyectos, 2) as $par) {
                $this->AddPage();
                $this->proyecto($par[0], 86, true);
                if (isset($par[1])) { $this->proyecto($par[1], 420, true); }
            }
        }
    }

    //-----------------------------------------------------------------------
    // La parte general (las primeras hojas): va bloque por bloque, de arriba
    // hacia abajo. Antes de cada bloque se revisa si cabe; si no, pasa a la
    // hoja siguiente. Así no importa cuántos proyectos haya
    //-----------------------------------------------------------------------
    private $cy = 86; //por dónde va el contenido en la hoja

    //Si lo que sigue no cabe en lo que queda de la hoja, se abre otra
    private function espacio($alto)
    {
        if ($this->cy + $alto > 742) { $this->AddPage(); $this->cy = 86; }
    }

    private function general($d)
    {
        $this->AddPage(); $this->cy = 86;
        $t = $d['total']; $per = $d['periodo'];
        $proyectos = $d['proyectos']; $nP = count($proyectos);
        $conTotal = $nP > 1;                      //con un solo proyecto la fila de totales sobra
        $altoTabla = 20 + 17 + 16 * ($nP + ($conTotal ? 1 : 0)) + 14; //lo que ocupa una tabla por proyecto, con su título

        //---- Resumen general: ocho tarjetas, cuatro por fila
        $this->cy = $this->seccion($this->cy, 'Resumen general', self::plural($nP, 'proyecto', 'proyectos') . ' · ' . self::plural($d['centros'], 'peaje o báscula', 'peajes y básculas')
                                  . ' · ' . self::dias($per['dias']) . ' del periodo');
        if ($per['dias'] === 0) { $this->vacio(self::M, $this->cy, self::ANCHO, 'Ese periodo todavía no empieza: no hay nada que informar.'); return; }
        $w = (self::ANCHO - 3 * 9) / 4; $h = 70;
        $cumple = $this->cumplimientoPromedio($d);
        $tarjetas = array(
            array('Requisiciones', self::num($t['rq']), self::plural($t['rqAprob'], 'aprobada', 'aprobadas') . ' · ' . self::plural($t['rqRech'], 'rechazada', 'rechazadas') . ' · ' . self::num($t['rqPend']) . ' por aprobar', self::VINO),
            array('Novedades', self::num($t['nov']), self::plural($t['novCerr'], 'atendida', 'atendidas') . ' · ' . self::plural($t['novAb'], 'abierta', 'abiertas'), self::VINO),
            array('Listas de chequeo', self::num($t['listas']), 'diligenciadas en el periodo', self::MENTA),
            array('Arqueos', self::num($t['arq']), $t['arqDif'] > 0 ? self::num($t['arqDif']) . ' con diferencia' : ($t['arq'] > 0 ? 'todos cuadraron' : 'sin arqueos en el periodo'), self::MENTA),
            array('Vacantes abiertas', self::num($t['vacAb']), self::plural($t['vacNuevas'], 'nueva', 'nuevas') . ' · ' . self::plural($t['vacCub'], 'cubierta', 'cubiertas') . ' en el periodo', self::AMBAR),
            array('Oficios recibidos', self::num($t['ofRec']), self::plural($t['ofRes'], 'atendido', 'atendidos') . ' · ' . self::plural($t['ofPend'], 'pendiente', 'pendientes') . ' hoy', self::AMBAR),
            array('Ausencias', self::num($t['aus']), 'personas por día, en todos los peajes', self::VINO),
            array('Reporte diario', $cumple . ' %', 'días con "Hoy en qué estás", promedio de los coordinadores', self::semaforo($cumple)),
        );
        foreach ($tarjetas as $i => $c) {
            $this->tarjeta(self::M + ($i % 4) * ($w + 9), $this->cy + intdiv($i, 4) * ($h + 9), $w, $h, $c[0], $c[1], $c[2], $c[3]);
        }
        $this->cy += 2 * ($h + 9) + 8;

        //---- Lo más relevante: frases cortas armadas con las cifras
        $frases = $this->relevante($d);
        $alto = 12 + 13 * count($frases);
        $this->espacio(20 + $alto + 12);
        $this->cy = $this->seccion($this->cy, 'Lo más relevante');
        $this->relleno(self::FONDO);
        $this->caja(self::M, $this->cy - 2, self::ANCHO, $alto, 6);
        foreach ($frases as $i => $f) {
            $this->relleno($f[0]);
            $this->caja(self::M + 11, $this->cy + 7.2 + $i * 13, 4.5, 4.5, 2.2);              //puntico del color del tema
            $this->t(self::M + 22, $this->cy + 5 + $i * 13, $this->cabe($f[1], self::ANCHO - 34, 8), 8, '', self::NEGRO);
        }
        $this->cy += $alto + 12;

        //---- Comparativo por proyecto: una mirada rápida a todo
        $this->espacio($altoTabla);
        $this->cy = $this->seccion($this->cy, 'Comparativo por proyecto', 'Lo registrado en el periodo. Vacantes y oficios: los que siguen abiertos hoy.');
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
        $total = $conTotal ? array('Total', self::num($t['rq']), self::num($t['rqPend']), self::num($t['nov']), self::num($t['listas']), self::num($t['arq']),
                                   self::num($t['arqDif']), self::num($t['vacAb']), self::num($t['ofPend']), self::num($t['aus']), $cumple . ' %') : null;
        $this->cy = $this->tabla(self::M, $this->cy, $cols, $filas, $total, 16) + 14;

        //---- Ausentismo: por motivo y por proyecto
        $porProyecto = array();
        foreach ($proyectos as $p) { $porProyecto[$p['NOM_PROYECTO']] = $p['kpi']['aus']; }
        $barras = $t['aus'] > 0 ? min(8, max(count($d['ausMotivos']), $nP)) : 0;
        $this->espacio($t['aus'] > 0 ? 20 + 14 + 15 * $barras + 10 : 20 + 34);
        $this->cy = $this->seccion($this->cy, 'Ausentismo', 'Personas ausentes por día. No cuenta lo que no es ausencia (como cubre recolector).');
        if ($t['aus'] > 0) {
            $mitad = (self::ANCHO - 20) / 2;
            $this->t(self::M, $this->cy, 'Por motivo', 8, 'B', self::GRIS);
            $this->t(self::M + $mitad + 20, $this->cy, 'Por proyecto', 8, 'B', self::GRIS);
            $y1 = $this->barras(self::M, $this->cy + 14, $mitad, $d['ausMotivos'], self::VINO, 118, 8, true);
            $y2 = $this->barras(self::M + $mitad + 20, $this->cy + 14, $mitad, $porProyecto, self::AMBAR, 92, 8, false);
            $this->cy = max($y1, $y2) + 10;
        } else {
            $this->cy = $this->vacio(self::M, $this->cy, self::ANCHO, 'No hay ausencias registradas en el periodo.') + 4;
        }

        //---- Requisiciones y novedades del mes, proyecto por proyecto
        $this->espacio($altoTabla);
        $this->cy = $this->seccion($this->cy, 'Requisiciones y novedades', 'Las pedidas y las registradas en el periodo, según cómo van hoy.');
        $cols = array(array('Proyecto', 116, 'L'), array('RQ pedidas', 52, 'R'), array('Urgentes', 46, 'R'), array('Aprobadas', 50, 'R'), array('Rechazadas', 54, 'R'),
                      array('Por aprobar', 54, 'R'), array('Novedades', 54, 'R'), array('Atendidas', 52, 'R'), array('Abiertas', 54, 'R'));
        $filas = array();
        foreach ($proyectos as $p) {
            $k = $p['kpi'];
            $filas[] = array($p['NOM_PROYECTO'], self::num($k['rq']), self::num($k['rqUrg']), self::num($k['rqAprob']), self::num($k['rqRech']),
                             $this->aviso($k['rqPend'], self::AMBAR), self::num($k['nov']), self::num($k['novCerr']), $this->aviso($k['novAb'], self::AMBAR));
        }
        $total = $conTotal ? array('Total', self::num($t['rq']), self::num($t['rqUrg']), self::num($t['rqAprob']), self::num($t['rqRech']),
                                   self::num($t['rqPend']), self::num($t['nov']), self::num($t['novCerr']), self::num($t['novAb'])) : null;
        $this->cy = $this->tabla(self::M, $this->cy, $cols, $filas, $total, 16) + 14;

        //---- Arqueos, vacantes y oficios del mes
        $this->espacio($altoTabla);
        $this->cy = $this->seccion($this->cy, 'Arqueos, vacantes y oficios', 'Lo que se movió en el periodo.');
        $cols = array(array('Proyecto', 104, 'L'), array('Arqueos', 44, 'R'), array('Con dif.', 44, 'R'), array('Faltantes', 68, 'R'), array('Sobrantes', 68, 'R'),
                      array('Vac. nuevas', 52, 'R'), array('Cubiertas', 46, 'R'), array('Oficios', 46, 'R'), array('Atendidos', 60, 'R'));
        $filas = array();
        foreach ($proyectos as $p) {
            $k = $p['kpi'];
            $filas[] = array($p['NOM_PROYECTO'], self::num($k['arq']), $this->aviso($k['arqDif'], self::VINO),
                             $k['arqFalt'] > 0 ? self::pesos($k['arqFalt']) : '-', $k['arqSobr'] > 0 ? self::pesos($k['arqSobr']) : '-',
                             self::num($k['vacNuevas']), self::num($k['vacCub']), self::num($k['ofRec']), self::num($k['ofRes']));
        }
        $total = $conTotal ? array('Total', self::num($t['arq']), self::num($t['arqDif']), $t['arqFalt'] > 0 ? self::pesos($t['arqFalt']) : '-',
                                   $t['arqSobr'] > 0 ? self::pesos($t['arqSobr']) : '-', self::num($t['vacNuevas']), self::num($t['vacCub']),
                                   self::num($t['ofRec']), self::num($t['ofRes'])) : null;
        $this->cy = $this->tabla(self::M, $this->cy, $cols, $filas, $total, 16) + 14;

        //---- El reporte diario de cada coordinador
        $this->espacio($altoTabla - ($conTotal ? 16 : 0));
        $this->cy = $this->seccion($this->cy, 'Reporte diario de los coordinadores', 'Hoy en qué estás: días registrados de los ' . self::dias($per['dias']) . ' del periodo.');
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
        $this->cy = $this->tabla(self::M, $this->cy, $cols, $filas, null, 16) + 14;

        //---- Lo que sigue pendiente hoy, por proyecto
        $this->espacio($altoTabla + 14);
        $this->cy = $this->seccion($this->cy, 'Pendientes por atender', 'Lo que sigue abierto hoy, de este mes o de antes.');
        $cols = array(array('Proyecto', 96, 'L'), array('RQ por aprobar', 66, 'R'), array('Con plazo vencido', 76, 'R'), array('Vacantes abiertas', 76, 'R'),
                      array('Fuera del acuerdo', 78, 'R'), array('Oficios sin atender', 78, 'R'), array('Fuera de plazo', 62, 'R'));
        $filas = array(); $suma = array(0, 0, 0, 0, 0, 0);
        foreach ($proyectos as $p) {
            $pe = $p['pendientes']; $k = $p['kpi'];
            $rqVenc = 0; foreach ($pe['rq'] as $x) { if ((int)$x['DIAS'] > $pe['plazoRq']) { $rqVenc++; } } //las que ya pasaron el plazo de aprobación
            $v = array(count($pe['rq']), $rqVenc, $k['vacAb'], $k['vacFuera'], $k['ofPend'], $k['ofVenc']);
            foreach ($v as $i => $n) { $suma[$i] += $n; }
            $filas[] = array($p['NOM_PROYECTO'], self::num($v[0]), $this->aviso($v[1], self::VINO), self::num($v[2]), $this->aviso($v[3], self::VINO),
                             self::num($v[4]), $this->aviso($v[5], self::VINO));
        }
        $total = $conTotal ? array_merge(array('Total'), array_map(array('InformeMensualPdf', 'numPub'), $suma)) : null;
        $this->cy = $this->tabla(self::M, $this->cy, $cols, $filas, $total, 16) + 10;
        //Los plazos con los que se midió, para que el informe se explique solo
        $this->t(self::M, $this->cy, 'Plazos usados: aprobar una RQ, ' . self::dias($d['plazoRq']) . '; atender un oficio, ' . self::dias($d['plazoOficio'])
                 . '. Se cambian en Configuración, Parámetros del sistema.', 7, 'I', self::GRIS);
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

    //-----------------------------------------------------------------------
    // La hoja de un proyecto. $corto = versión de media hoja (cuando hay muchos proyectos)
    //-----------------------------------------------------------------------
    private function proyecto($p, $y, $corto)
    {
        $k = $p['kpi']; $r = $p['reporte'];
        $peajes = 0; $basculas = 0;
        foreach ($p['centros'] as $c) { if ($c['tipo'] === 'BASCULA') { $basculas++; } else { $peajes++; } }

        //Franja con el nombre del proyecto y quién lo coordina
        $this->relleno(self::VINO);
        $this->caja(self::M, $y, self::ANCHO, 36, 7);
        $this->t(self::M + 14, $y + 8, $this->cabe($p['NOM_PROYECTO'], 300, 15, 'B'), 15, 'B', array(255, 255, 255));
        $this->t(self::M + self::ANCHO - 14, $y + 8, 'Coordinador: ' . $this->cabe($p['COORDINADOR'] ? $p['COORDINADOR'] : 'sin asignar', 170, 8.5, 'B'), 8.5, 'B', array(255, 255, 255), 'R');
        $this->t(self::M + self::ANCHO - 14, $y + 21, self::plural($peajes, 'peaje', 'peajes') . ($basculas ? ' · ' . self::plural($basculas, 'báscula', 'básculas') : ''), 7.5, '', array(240, 222, 223), 'R');
        $y += 46;

        //Seis tarjetas con las cifras del proyecto
        $w = (self::ANCHO - 5 * 8) / 6; $h = 54;
        $tarjetas = array(
            array('Requisiciones', self::num($k['rq']), self::num($k['rqPend']) . ' por aprobar', self::VINO),
            array('Novedades', self::num($k['nov']), self::num($k['novAb']) . ' abiertas', self::VINO),
            array('Listas', self::num($k['listas']), 'diligenciadas', self::MENTA),
            array('Arqueos', self::num($k['arq']), $k['arqDif'] > 0 ? self::num($k['arqDif']) . ' con diferencia' : 'todos cuadraron', self::MENTA),
            array('Vacantes', self::num($k['vacAb']), 'abiertas hoy', self::AMBAR),
            array('Ausencias', self::num($k['aus']), 'personas por día', self::VINO),
        );
        foreach ($tarjetas as $i => $c) { $this->tarjeta(self::M + $i * ($w + 8), $y, $w, $h, $c[0], $c[1], $c[2], $c[3]); }
        $y += $h + 14;

        //Peaje por peaje
        $y = $this->seccion($y, 'Por peaje');
        $cols = array(array('Peaje o báscula', 118, 'L'), array('RQ', 34, 'R'), array('Por aprobar', 46, 'R'), array('Novedades', 44, 'R'), array('Listas', 34, 'R'),
                      array('Arqueos', 40, 'R'), array('Con dif.', 38, 'R'), array('Faltantes', 62, 'R'), array('Vacantes', 42, 'R'), array('Ausencias', 44, 'R'));
        $cols[0][1] += self::ANCHO - array_sum(array_column($cols, 1)); //lo que sobre de ancho se lo lleva el nombre
        $filas = array();
        $maxFilas = $corto ? 7 : 16;                                    //lo que cabe sin pasarse de la hoja
        $n = 0;
        foreach ($p['centros'] as $c) {
            if (++$n > $maxFilas) { break; }
            $x = isset($c['kpi']) ? $c['kpi'] : InformeMensual::ceros();
            $filas[] = array(($c['tipo'] === 'BASCULA' ? 'Báscula ' : '') . $c['nombre'], self::num($x['rq']),
                             $x['rqPend'] > 0 ? array('t' => self::num($x['rqPend']), 'c' => self::AMBAR, 'b' => true) : '0',
                             self::num($x['nov']), self::num($x['listas']), self::num($x['arq']),
                             $x['arqDif'] > 0 ? array('t' => self::num($x['arqDif']), 'c' => self::VINO, 'b' => true) : '0',
                             $x['arqFalt'] > 0 ? self::pesos($x['arqFalt']) : '-', self::num($x['vacAb']), self::num($x['aus']));
        }
        if (count($p['centros']) > $maxFilas) { $filas[] = array(array('t' => '... y ' . (count($p['centros']) - $maxFilas) . ' más (van sumados en el total)', 'c' => self::GRIS)); }
        if (!count($filas)) { $y = $this->vacio(self::M, $y, self::ANCHO, 'Este proyecto no tiene peajes activos.'); }
        else {
            $total = count($p['centros']) > 1 ? array('Total del proyecto', self::num($k['rq']), self::num($k['rqPend']), self::num($k['nov']), self::num($k['listas']), self::num($k['arq']),
                                                      self::num($k['arqDif']), $k['arqFalt'] > 0 ? self::pesos($k['arqFalt']) : '-', self::num($k['vacAb']), self::num($k['aus'])) : null;
            $y = $this->tabla(self::M, $y, $cols, $filas, $total, 14.5) + 14;
        }
        if ($corto) { return; } //la versión corta llega hasta aquí

        //Dos columnas: ausentismo por motivo y el reporte diario del coordinador
        $mitad = (self::ANCHO - 22) / 2; $x2 = self::M + $mitad + 22; $yCol = $y;
        $this->subtitulo(self::M, $y, 'Ausentismo por motivo');
        $y1 = count($p['ausMotivos']) ? $this->barras(self::M, $y + 18, $mitad, $p['ausMotivos'], self::VINO, 112, 7)
                                      : $this->vacio(self::M, $y + 18, $mitad, 'Sin ausencias en el periodo.');
        $this->subtitulo($x2, $yCol, 'Reporte diario del coordinador');
        $datos = array(
            array('Hoy en qué estás', $r['hoyDias'] . ' de ' . self::dias($r['dias']) . ' (' . $r['cumple'] . ' %)', self::semaforo($r['cumple'])),
            array('Visitas del cronograma', $r['visProg'] > 0 ? $r['visReal'] . ' hechas de ' . $r['visProg'] . ' planeadas' : 'Sin visitas planeadas', null),
            array('Listas de chequeo', self::plural($k['listas'], 'diligenciada', 'diligenciadas'), null),
            array('Arqueos', self::plural($k['arq'], 'arqueo', 'arqueos') . ($k['arqDif'] > 0 ? ', ' . self::num($k['arqDif']) . ' con diferencia' : ''), null),
            array('Vehículo', ($r['vehOk'] + $r['vehNo']) > 0 ? self::dias($r['vehOk']) . ' operativo, ' . self::dias($r['vehNo']) . ' sin operar' : 'Sin reporte del vehículo', $r['vehNo'] > 0 ? self::AMBAR : null),
            array('Oficios', self::num($k['ofRec']) . ' recibidos, ' . self::num($k['ofRes']) . ' atendidos', null),
        );
        $y2 = $yCol + 18;
        foreach ($datos as $i => $f) {
            if ($i % 2 === 0) { $this->relleno(self::FONDO); $this->caja($x2, $y2 - 2, $mitad, 15, 3); }
            $this->t($x2 + 7, $y2 + 1.6, $f[0], 7.4, '', self::GRIS);
            $this->t($x2 + $mitad - 7, $y2 + 1.6, $this->cabe($f[1], $mitad - 110, 7.6, 'B'), 7.6, 'B', $f[2] === null ? self::NEGRO : $f[2], 'R');
            $y2 += 15;
        }
        //Debajo: qué días del periodo se quedaron sin "Hoy en qué estás"
        $sin = $r['sinHoy'];
        $texto = count($sin) ? 'Días sin "Hoy en qué estás": ' . implode(', ', $sin) . '.' : 'Registró "Hoy en qué estás" todos los días del periodo.';
        foreach (array_slice($this->renglones($texto, $mitad - 14, 7), 0, 2) as $l) { $this->t($x2 + 7, $y2 + 3, $l, 7, 'I', count($sin) ? self::VINO : self::GRIS); $y2 += 9.5; }
        $y = max($y1, $y2) + 14;

        //Lo que sigue pendiente hoy en el proyecto: tres columnas
        $y = $this->seccion($y, 'Pendientes por atender', 'Lo que sigue abierto hoy. Lo más antiguo primero.');
        $pe = $p['pendientes'];
        $col = (self::ANCHO - 2 * 12) / 3;
        //Renglones de cada lista: los que hagan falta (entre 2 y 6), sin pasarse de lo que queda de hoja
        $lineas = max(2, min(6, max(count($pe['rq']), count($pe['vacantes']), count($pe['oficios']))));
        $lineas = max(1, min($lineas, (int)floor((742 - $y - 22) / 11)));
        $rq = array(); foreach ($pe['rq'] as $x) {
            $rq[] = array(($x['TIPO_RQ'] === 'U' ? 'RQ U ' : 'RQ ') . $x['NUMERO_RQ'] . ' · ' . $x['CENTRO'], self::dias($x['DIAS']), (int)$x['DIAS'] > $pe['plazoRq'] || $x['TIPO_RQ'] === 'U');
        }
        $vac = array(); foreach ($pe['vacantes'] as $x) { $vac[] = array(self::frase($x['CARGO']) . ' · ' . $x['CENTRO'], self::dias($x['DIAS']), (int)$x['FUERA'] === 1); }
        $ofi = array(); foreach ($pe['oficios'] as $x) { $ofi[] = array(($x['RADICADO'] ? $x['RADICADO'] . ' · ' : '') . $x['ASUNTO'], self::dias($x['DIAS']), (int)$x['DIAS'] > $pe['plazoOficio']); }
        $this->listaPendientes(self::M, $y, $col, 'RQ por aprobar', $rq, $lineas, 'Sin RQ por aprobar.');
        $this->listaPendientes(self::M + $col + 12, $y, $col, 'Vacantes abiertas', $vac, $lineas, 'Sin vacantes abiertas.');
        $this->listaPendientes(self::M + 2 * ($col + 12), $y, $col, 'Oficios sin atender', $ofi, $lineas, 'Sin oficios pendientes.');
        $y += 22 + 11 * $lineas + 16;

        //Los arqueos que no cuadraron, si queda espacio en la hoja (mínimo para el título y dos filas)
        $caben = (int)floor((742 - $y - 20 - 17) / 14);
        if ($caben < 2) { return; }
        $y = $this->seccion($y, 'Arqueos que no cuadraron', 'Los de mayor diferencia primero.');
        $dif = $p['arqueosDif'];
        if (!count($dif)) {
            $this->vacio(self::M, $y, self::ANCHO, $k['arq'] > 0 ? 'Todos los arqueos del periodo cuadraron.' : 'No se registraron arqueos en el periodo.');
            return;
        }
        $tipos = array('CAJA_MENOR' => 'Caja menor', 'RECAMBIO' => 'Recambio', 'CASETA' => 'Caseta');
        $cols = array(array('Fecha', 62, 'L'), array('Peaje', 120, 'L'), array('Tipo', 96, 'L'), array('Responsable', 150, 'L'), array('Diferencia', 104, 'R'));
        $ver = count($dif) > $caben ? $caben - 1 : $caben; //si no caben todos, la última fila dice cuántos faltan
        $filas = array();
        foreach (array_slice($dif, 0, $ver) as $a) {
            $falta = (float)$a['DIFERENCIA'] < 0;
            $filas[] = array(self::fecha($a['FECHA']), $a['CENTRO'],
                             (isset($tipos[$a['TIPO']]) ? $tipos[$a['TIPO']] : $a['TIPO']) . ($a['TIPO'] === 'CASETA' && $a['CASETA'] ? ' ' . $a['CASETA'] : ''),
                             (string)$a['RESPONSABLE'],
                             array('t' => ($falta ? 'Faltante ' : 'Sobrante ') . self::pesos(abs((float)$a['DIFERENCIA'])), 'c' => $falta ? self::VINO : self::AMBAR, 'b' => true));
        }
        if (count($dif) > $ver) { $filas[] = array(array('t' => '... y ' . (count($dif) - $ver) . ' más', 'c' => self::GRIS)); }
        $this->tabla(self::M, $y, $cols, $filas, null, 14);
    }

    //Subtítulo pequeño dentro de una hoja
    private function subtitulo($x, $y, $texto)
    {
        $this->t($x, $y, $texto, 9, 'B', self::NEGRO);
        $this->relleno(self::LINEA); $this->Rect($x, $y + 13, 60, 1, 'F');
    }

    //Una lista corta de pendientes: cada renglón es array(texto, cuánto lleva, ¿está vencido?)
    private function listaPendientes($x, $y, $w, $titulo, $items, $maximo, $siVacio)
    {
        $this->relleno(self::FONDO);
        $this->caja($x, $y, $w, 22 + 11 * $maximo, 6);
        $this->t($x + 9, $y + 7, $titulo . (count($items) ? ' (' . count($items) . ')' : ''), 7.6, 'B', self::NEGRO);
        $yy = $y + 21;
        if (!count($items)) { $this->t($x + 9, $yy, $siVacio, 7.2, 'I', self::GRIS); return; }
        $ver = count($items) > $maximo ? $maximo - 1 : $maximo; //si no caben todos, el último renglón dice cuántos faltan
        foreach (array_slice($items, 0, $ver) as $it) {
            $this->t($x + 9, $yy, $this->cabe($it[0], $w - 62, 7), 7, '', self::NEGRO);
            $this->t($x + $w - 9, $yy, $it[1], 7, $it[2] ? 'B' : '', $it[2] ? self::VINO : self::GRIS, 'R'); //lo vencido, en vino
            $yy += 11;
        }
        if (count($items) > $maximo) { $this->t($x + 9, $yy, '... y ' . (count($items) - $ver) . ' más', 7, 'I', self::GRIS); }
    }

    //Nombre del archivo: "Informe de gestion HANA 2026-10.pdf" (un mes) o
    //"Informe de gestion HANA 2026-08-01 a 2026-10-06.pdf" (otro rango)
    public static function nombreArchivo($periodo)
    {
        if (!empty($periodo['mensual'])) { return 'Informe de gestion HANA ' . sprintf('%04d-%02d', $periodo['anio'], $periodo['mes']) . '.pdf'; }
        return 'Informe de gestion HANA ' . $periodo['desde'] . ' a ' . $periodo['corte'] . '.pdf';
    }
}
