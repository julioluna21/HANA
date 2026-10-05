<?php
/*
  HANA — PDF del arqueo
  ---------------------------------------------------------------------------
  Genera el arqueo con la MISMA estructura de los formatos en PDF que se usan
  hoy ("Arqueo caja menor ..." y "Arqueo recambio ..."): mismas posiciones,
  tamaños de letra, líneas y grises. Las coordenadas se tomaron de esos PDF
  (hoja carta, en puntos, midiendo desde arriba).

  El recuadro del logo queda en blanco a propósito: el formato lo usan varias
  concesiones y cada una pondrá el suyo.

  Usa FPDF 1.9 (public/fpdf). Las copias viejas del proyecto (public/Gpdf,
  versión 1.81) no funcionan con PHP 8. FPDF escribe en
  Windows-1252: todo texto pasa por txt() para que las tildes salgan bien.
*/
require_once __DIR__ . '/../public/fpdf/fpdf.php';

class ArqueoPdf extends FPDF
{
    private $gris = 0;

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

    //Mayúsculas con tildes, sin depender de mbstring
    public static function mayus($t)
    {
        if (function_exists('mb_strtoupper')) { return mb_strtoupper((string)$t, 'UTF-8'); }
        return strtr(strtoupper((string)$t), array('á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ñ' => 'Ñ', 'ü' => 'Ü'));
    }

    //Escribe un texto. $top es el borde superior de la letra (como en el
    //original); FPDF necesita la línea base, que queda 0,905 × tamaño más abajo.
    //$alin: L (desde $x), R (terminando en $x) o C (centrado entre $x y $x2)
    private function t($x, $top, $texto, $tam, $negrita = false, $alin = 'L', $x2 = 0)
    {
        $this->SetFont('Helvetica', $negrita ? 'B' : '', $tam);
        $s = $this->txt($texto);
        $w = $this->GetStringWidth($s);
        if ($alin === 'R')     { $x = $x - $w; }
        elseif ($alin === 'C') { $x = $x + (($x2 - $x) - $w) / 2; }
        $this->Text($x, $top + 0.905 * $tam, $s);
    }

    //Recorta un texto para que quepa en un ancho, con "..." al final
    private function cabe($texto, $ancho, $tam, $negrita = false)
    {
        $this->SetFont('Helvetica', $negrita ? 'B' : '', $tam);
        $texto = (string)$texto;
        if ($this->GetStringWidth($this->txt($texto)) <= $ancho) { return $texto; }
        while ($texto !== '' && $this->GetStringWidth($this->txt($texto . '...')) > $ancho) {
            $texto = function_exists('mb_substr') ? mb_substr($texto, 0, -1, 'UTF-8') : substr($texto, 0, -1);
        }
        return $texto . '...';
    }

    //Parte un texto en renglones que quepan en un ancho (máximo $max renglones)
    private function renglones($texto, $ancho, $tam, $max)
    {
        $this->SetFont('Helvetica', '', $tam);
        $res = array();
        foreach (preg_split('/\r\n|\r|\n/', trim((string)$texto)) as $parrafo) {
            $linea = '';
            foreach (preg_split('/\s+/', $parrafo) as $palabra) {
                $prueba = $linea === '' ? $palabra : "$linea $palabra";
                if ($this->GetStringWidth($this->txt($prueba)) <= $ancho) { $linea = $prueba; continue; }
                if ($linea !== '') { $res[] = $linea; }
                $linea = $this->cabe($palabra, $ancho, $tam);
            }
            $res[] = $linea;
        }
        if (count($res) > $max) {
            $res = array_slice($res, 0, $max);
            $res[$max - 1] = $this->cabe($res[$max - 1] . ' ...', $ancho, $tam);
        }
        return $res;
    }

    private function h($x1, $x2, $y) { $this->Line($x1, $y, $x2, $y); }
    private function v($x, $y1, $y2) { $this->Line($x, $y1, $x, $y2); }
    private function relleno($x1, $y1, $x2, $y2, $r, $g, $b)
    {
        $this->SetFillColor($r, $g, $b);
        $this->Rect($x1, $y1, $x2 - $x1, $y2 - $y1, 'F');
    }

    //Formato contable del original: "320,000"; el cero se muestra como "-"
    private static function num($n)
    {
        $n = round((float)$n);
        if ($n == 0) { return '-'; }
        return ($n < 0 ? '-' : '') . number_format(abs($n), 0, '.', ',');
    }

    //"$" a la izquierda y el valor a la derecha, como en Excel
    private function pesos($xSigno, $xFin, $top, $valor, $tam, $negrita = false)
    {
        $this->t($xSigno, $top, '$', $tam, $negrita);
        $n = self::num($valor);
        $this->t($n === '-' ? $xFin - 8 : $xFin, $top, $n, $tam, $negrita, 'R');
    }

    public static function fechaLarga($f)
    {
        $dias = array('domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado');
        $meses = array('', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                       'septiembre', 'octubre', 'noviembre', 'diciembre');
        $ts = strtotime($f);
        return $dias[(int)date('w', $ts)] . ', ' . (int)date('j', $ts) . ' de ' . $meses[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
    }

    //Sello de anulado, en diagonal y en vino, para que no se confunda con uno vigente
    private function sello($motivo)
    {
        $this->SetTextColor(110, 26, 30);
        $this->t(93, 30, 'ANULADO: ' . $this->cabe($motivo, 380, 9, true), 9, true);
        $this->SetTextColor(0, 0, 0);
    }

    //-----------------------------------------------------------------------
    // ARQUEO A CAJA MENOR
    //-----------------------------------------------------------------------
    public function cajaMenor($a)
    {
        $this->AddPage();
        if ((int)$a['ESTADO'] !== 1) { $this->sello($a['MOTIVO_ANULACION']); }

        //Líneas del efectivo y de los documentos
        $ef = array('BILLETES' => 0, 'MONEDAS' => 0);
        $docs = array();
        $sub = array('FACTURA' => 0, 'REINTEGRO' => 0, 'RECIBO_CAJA' => 0, 'FALTANTE' => 0);
        foreach ($a['LINEAS'] as $l) {
            if ($l['CLASE'] === 'DOCUMENTO') { $docs[] = $l; $sub[$l['CONCEPTO']] += (float)$l['VALOR']; }
            else { $ef[$l['CONCEPTO']] = (float)$l['VALOR']; }
        }

        //--- Marco exterior y encabezado (el logo va en 93..221, en blanco) ---
        $this->h(79, 526, 54); $this->h(79, 526, 644); $this->v(78, 54, 645);
        $this->h(93, 526, 65); $this->h(93, 526, 102);
        $this->v(93, 65, 102); $this->v(221, 65, 102); $this->v(525, 65, 102);
        $this->logo(93, 221, 65, 102); //el logo de Regency, centrado en su recuadro
        $this->t(221, 78, 'ARQUEO A CAJA MENOR', 8.8, true, 'C', 525);

        //--- Cuerpo ---
        $this->h(93, 526, 108); $this->v(93, 108, 634); $this->v(525, 109, 634); $this->h(93, 526, 633);

        //Datos generales
        $this->relleno(363, 109, 525, 128, 231, 230, 230);
        $this->t(95, 120, 'ZONA', 7.3);
        $this->t(172, 119, $this->cabe('CONCESIÓN ' . self::mayus($a['NOM_PROYECTO']), 112, 7.3), 7.3, false, 'C', 287);
        $this->t(362, 119, 'ESTACION', 7.3, false, 'R');
        $this->t(363, 119, $this->cabe(self::mayus(($a['TIPO_CENTRO'] === 'BASCULA' ? 'BÁSCULA ' : ($a['TIPO_CENTRO'] === 'PEAJE' ? 'PEAJE ' : '')) . $a['NOM_CENTRO_OP']), 158, 7.3), 7.3, false, 'C', 526);
        $this->h(172, 287, 128); $this->h(363, 526, 128);

        $this->t(95, 135, 'FONDO ARQUEADO', 7.3);
        $this->t(172, 135, 'CAJA MENOR', 7.3, false, 'C', 287);
        $this->t(362, 135, 'FECHA DE ARQUEO', 7.3, false, 'R');
        $this->t(363, 135, self::fechaLarga($a['FECHA']), 7.3, false, 'C', 526);
        $this->h(172, 287, 144); $this->h(363, 526, 144);

        $this->t(95, 153, 'HORA DE ARQUEO', 7.3);
        $this->t(172, 152, substr($a['HORA'], 0, 5), 7.3, false, 'C', 238);
        $this->t(252, 152, 'RESPONSABLE DEL FONDO', 7.3);
        $this->t(363, 152, $this->cabe(self::mayus($a['RESPONSABLE']), 158, 7.3), 7.3, false, 'C', 526);
        $this->h(172, 238, 161); $this->h(363, 526, 161);

        //Efectivo (izquierda)
        $this->t(95, 169, 'BILLETES', 7.3);   $this->pesos(176, 283, 169, $ef['BILLETES'], 7.3);
        $this->t(95, 181, 'MONEDAS', 7.3);    $this->pesos(176, 283, 181, $ef['MONEDAS'], 7.3);
        $this->t(95, 190, 'SUBTOTAL', 7.3, true); $this->pesos(176, 283, 190, $a['TOTAL_EFECTIVO'], 7.3);
        $this->h(172, 287, 178); $this->h(172, 287, 190); $this->h(172, 287, 199);

        //Documentos y totales (derecha)
        $filas = array(
            array('FACTURAS', 168, 169, $sub['FACTURA'], false, 178),
            array('REINTEGRO DE FONDO', 179, 181, $sub['REINTEGRO'], false, 190),
            array('RECIBO DE CAJA', 190, 190, $sub['RECIBO_CAJA'], false, 199),
            array('FALTANTES REGISTRADOS', 200, 200, $sub['FALTANTE'], false, 209),
            array('SUBTOTAL', 210, 211, $a['TOTAL_DOCUMENTOS'], true, 220),
            array('TOTAL ARQUEO', 231, 232, $a['TOTAL_ARQUEO'], false, 241),
            array('FONDO AUTORIZADO', 242, 243, $a['FONDO_AUTORIZADO'], false, 252),
            array('DIFERENCIA', 252, 253, $a['DIFERENCIA'], false, 262)
        );
        foreach ($filas as $f) {
            $this->t(435, $f[1], $f[0], 7.3, $f[4], 'R');
            $this->pesos(440, 522, $f[2], $f[3], 7.3);
            $this->h(436, 526, $f[5]);
        }

        //--- Tabla de documentos ---
        $this->relleno(93, 273, 526, 305, 217, 217, 217);
        $this->h(93, 526, 273); $this->h(93, 525, 283); $this->h(222, 287, 294);
        $this->t(93, 274, 'DESCRIPCION DE FACTURAS, REINTEGROS, RECIBOS Y FALTANTES', 7.3, true, 'C', 525);
        $this->t(221, 285, 'CONCEPTO', 7.3, true, 'C', 287);
        $this->t(93, 290, 'FECHA', 7.3, true, 'C', 172);
        $this->t(172, 290, 'VALOR', 7.3, true, 'C', 221);
        $this->t(287, 290, 'OBSERVACIONES', 7.3, true, 'C', 525);
        $cols = array('FACTURA' => array(221, 238, 'F'), 'REINTEGRO' => array(238, 254, 'R'),
                      'RECIBO_CAJA' => array(254, 271, 'RC'), 'FALTANTE' => array(271, 287, 'FR'));
        foreach ($cols as $c) { $this->t($c[0], 295, $c[2], 7.3, true, 'C', $c[1]); }

        //15 renglones como el original. Si hay más documentos, los renglones
        //se hacen más bajos para que todo quepa en el mismo espacio
        $n = max(15, count($docs));
        $alto = (463 - 304) / $n;
        $tam = min(6.6, $alto * 0.62);
        $this->h(93, 525, 304);
        for ($i = 1; $i <= $n; $i++) { $this->h(93, 525, 304 + $alto * $i); }
        $this->v(172, 284, 463); $this->v(221, 284, 463); $this->v(286, 284, 463);
        foreach (array(238, 254, 271) as $x) { $this->v($x, 295, 463); }
        foreach ($docs as $i => $d) {
            $top = 304 + $alto * $i + ($alto - $tam * 1.1) / 2;
            if ($d['FECHA']) { $this->t(171, $top, date('d/m/Y', strtotime($d['FECHA'])), $tam, false, 'R'); }
            $this->t(220, $top, '$ ' . self::num($d['VALOR']), $tam, false, 'R');
            $c = $cols[$d['CONCEPTO']];
            $this->t($c[0], $top, 'X', $tam, false, 'C', $c[1]);
            $this->t(287, $top, $this->cabe($d['OBSERVACION'], 232, $tam), $tam, false, 'C', 525);
        }
        $this->h(93, 526, 463);
        $this->t(95, 464, '(F) Factura   (R) Reintegro de Fondo  (RC) Recibo de Caja  (FR) Faltantes Registrados.', 7.3);

        //--- Observación ---
        $this->t(95, 480, 'OBSERVACION :', 7.3, true);
        foreach ($this->renglones($a['OBSERVACION'], 426, 6.6, 3) as $i => $r) { $this->t(96, 491 + $i * 10.5, $r, 6.6); }
        $this->h(93, 525, 500); $this->h(93, 525, 510); $this->h(93, 525, 521);

        //--- Constancia y cierre ---
        $this->t(94, 527, 'SE DEJA EN CONSTANCIA QUE EL DINERO Y DOCUMENTOS UTILIZADOS EN EL ARQUEO, FUERON MANEJADOS POR LA PERSONA RESPONSABLE DE', 5.9);
        $this->t(94, 535, 'LOS MISMOS EN LA EDR Y TERMINADA LA DILIGENCIA FUERON CUSTODIADOS EN LA CAJA FUERTE ASIGNADA.', 5.9);
        $this->t(94, 545, 'SE DA POR TERMINADO A LAS', 5.9);
        if ($a['HORA_FIN']) { $this->t(223, 545, substr($a['HORA_FIN'], 0, 8), 5.9); }
        $this->h(222, 271, 553);
        $this->t(272, 545, 'DEL', 5.9);
        $this->t(288, 545, self::fechaLarga($a['FECHA']), 6.6);
        $this->h(287, 436, 553);

        //--- Firmas (en blanco, para firmar a mano) ---
        $this->h(93, 287, 612); $this->h(329, 526, 612);
        $this->t(93, 615, 'PERSONA RESPONSABLE DEL DINERO', 5.9, false, 'C', 287);
        $this->t(329, 615, 'PERSONA QUE EFECTUA EL ARQUEO', 5.9, false, 'C', 526);

        $this->pie($a, 655);
    }

    //-----------------------------------------------------------------------
    // ARQUEO FONDO DE RECAMBIO
    //-----------------------------------------------------------------------
    public function recambio($a)
    {
        $this->AddPage();
        if ((int)$a['ESTADO'] !== 1) { $this->sello($a['MOTIVO_ANULACION']); }

        $ef = array();
        foreach ($a['LINEAS'] as $l) { $ef[$l['CONCEPTO']] = $l; }

        //--- Marco exterior y encabezado (logo en 93..188, en blanco) ---
        $this->h(79, 531, 54); $this->h(79, 531, 516); $this->v(79, 54, 517); $this->v(530, 55, 517);
        $this->h(93, 515, 64); $this->h(93, 515, 99);
        $this->v(93, 64, 99); $this->v(188, 64, 99); $this->v(515, 64, 99);
        $this->logo(93, 188, 64, 99); //el logo de Regency, centrado en su recuadro
        $this->t(188, 77, 'ARQUEO FONDO DE RECAMBIO', 8.1, true, 'C', 515);

        //--- Cuerpo ---
        $this->h(93, 515, 107); $this->v(93, 107, 507); $this->v(515, 107, 507); $this->h(93, 515, 506);

        $centro = self::mayus(($a['TIPO_CENTRO'] === 'BASCULA' ? 'BÁSCULA ' : ($a['TIPO_CENTRO'] === 'PEAJE' ? 'PEAJE ' : '')) . $a['NOM_CENTRO_OP']);
        $datos = array(
            array(116, 'ZONA', $this->cabe('CONCESIÓN ' . self::mayus($a['NOM_PROYECTO']), 94, 7.5), 'LUGAR O EDR', $this->cabe($centro, 136, 7.5), 125),
            array(135, 'FECHA ARQUEO', date('d/m/Y', strtotime($a['FECHA'])), 'HORA ARQUEO', substr($a['HORA'], 0, 5), 144),
            array(155, 'RESPONSABLE', $this->cabe(self::mayus($a['RESPONSABLE']), 94, 7.5), 'CARGO', $this->cabe(self::mayus($a['CARGO_RESPONSABLE']), 136, 7.5), 164)
        );
        foreach ($datos as $d) {
            $this->t(93, $d[0], $d[1], 7.5, true, 'C', 188);
            $this->t(188, $d[0], $d[2], 7.5, false, 'C', 286);
            $this->t(286, $d[0], $d[3], 7.5, true, 'C', 375);
            $this->t(375, $d[0], $d[4], 7.5, false, 'C', 515);
            $this->h(188, 286, $d[5]); $this->h(375, 515, $d[5]);
        }

        //--- Tabla de conceptos ---
        $this->relleno(93, 174, 515, 185, 242, 242, 242);
        $this->t(93, 175, 'CONCEPTO', 8.1, true, 'C', 285);
        $this->t(285, 175, 'VALOR', 8.1, true, 'C', 375);
        $this->t(375, 175, 'OBSERVACIONES', 8.1, true, 'C', 515);
        foreach (array(174, 185, 201, 218, 235, 252, 269, 286, 293, 310, 327, 344) as $y) { $this->h(93, 515, $y); }
        $this->v(285, 174, 286); $this->v(375, 174, 286); $this->v(285, 293, 344); $this->v(375, 293, 344);

        $conceptos = array(
            array('BASES_CASETAS', 'DINERO PARA BASES EN CASETAS', 185),
            array('BASE_SUPERVISORA', 'DINERO BASE SUPERVISORA', 201),
            array('MONEDAS', 'DINERO EN MONEDAS', 218),
            array('BILLETES', 'DINERO EN BILLETES', 235),
            array('CAJA_FUERTE', 'DINERO CUSTODIADO EN CAJA FUERTE', 252)
        );
        foreach ($conceptos as $c) {
            $fila = $c[2];
            $this->t(95, $fila + 4, $c[1], 7.5);
            $l = isset($ef[$c[0]]) ? $ef[$c[0]] : null;
            //Como en el original: un concepto sin valor queda en blanco
            if ($l && (float)$l['VALOR'] != 0) { $this->pesos(289, 370, $fila + 1, $l['VALOR'], 7.5); }
            if ($l && $l['OBSERVACION']) { $this->t(378, $fila + 4, $this->cabe($l['OBSERVACION'], 134, 6.6), 6.6); }
        }
        $this->t(95, 273, 'TOTAL ARQUEO DEL FONDO', 7.5, true);
        $this->pesos(289, 370, 270, $a['TOTAL_ARQUEO'], 7.5);

        $this->t(95, 297, 'FONDO AUTORIZADO', 7.5);   $this->pesos(289, 370, 294, $a['FONDO_AUTORIZADO'], 7.5);
        $this->t(95, 314, 'MENOS TOTAL ARQUEO', 7.5); $this->pesos(289, 370, 311, $a['TOTAL_ARQUEO'], 7.5);
        $this->t(95, 331, 'DIFERENCIA', 7.5);         $this->pesos(289, 370, 328, $a['DIFERENCIA'], 7.5);

        //--- Observaciones ---
        $this->t(95, 346, 'OBSERVACIONES:', 6.8, true);
        foreach (array(364, 373, 383, 393, 403, 413) as $y) { $this->h(93, 515, $y); }
        foreach ($this->renglones($a['OBSERVACION'], 416, 6.6, 6) as $i => $r) { $this->t(96, 356 + $i * 9.8, $r, 6.6); }

        //--- Constancia y cierre ---
        $this->t(94, 422, 'SE DEJA EN CONSTANCIA QUE EL DINERO Y DOCUMENTOS UTILIZADOS EN EL ARQUEO, FUERON MANEJADOS POR LA PERSONA', 6.1);
        $this->t(94, 430, 'RESPONSABLE DE LOS MISMOS EN LA EDR Y TERMINADA LA DILIGENCIA FUERON CUSTODIADOS EN LA CAJA FUERTE ASIGNADA.', 6.1);
        $this->t(94, 439, 'DILIGENCIA TERMINADA DE CONFORMIDAD A LOS', 6.1);
        if ($a['HORA_FIN']) {
            $this->t(286, 439, date('d/m/Y', strtotime($a['FECHA'])) . ' ' . substr($a['HORA_FIN'], 0, 5), 6.1, false, 'C', 515);
        }
        $this->h(286, 515, 447);

        //--- Firmas (en blanco, para firmar a mano) ---
        $this->h(93, 286, 487); $this->h(375, 515, 487);
        $this->t(93, 488, 'RESPONSABLE DEL DINERO', 7.5, true, 'C', 286);
        $this->t(375, 488, 'QUIEN REALIZA EL ARQUEO', 7.5, true, 'C', 515);

        $this->pie($a, 527);
    }

    //Una línea pequeña debajo del formato: de dónde salió y quién lo registró
    //-----------------------------------------------------------------------
    // ARQUEO DE CASETA (con los campos configurados para el peaje)
    //   PROYECTO / FECHA / HORA            PEAJE / CASETA / RECOLECTORA
    //   RECAUDO CASETA $
    //   RETIROS, EFECTIVO... (lo que suma o resta)   TOTAL RECAUDO $
    //   NOVEDAD: FALTANTE o SOBRANTE
    //   BASE DE SENCILLO y demás campos informativos
    //-----------------------------------------------------------------------
    public function caseta($a)
    {
        $this->AddPage();
        if ((int)$a['ESTADO'] !== 1) { $this->sello($a['MOTIVO_ANULACION']); }

        //Los renglones, por comportamiento (el código guardado es "ROL-id")
        $grupos = array('ESPERADO' => array(), 'CUENTA' => array(), 'INFO' => array());
        foreach ($a['LINEAS'] as $l) {
            $rol = strtok((string)$l['CONCEPTO'], '-');
            $l['ROL'] = $rol;
            if ($rol === 'ESPERADO') { $grupos['ESPERADO'][] = $l; }
            elseif ($rol === 'INFO') { $grupos['INFO'][] = $l; }
            else { $grupos['CUENTA'][] = $l; }
        }

        //--- Marco y encabezado: el logo de Regency en el recuadro izquierdo ---
        $this->h(93, 515, 64); $this->h(93, 515, 99);
        $this->v(93, 64, 99); $this->v(188, 64, 99); $this->v(515, 64, 99);
        $logo = __DIR__ . '/../public/img/logo_pdf.jpg'; //copia en JPG del logo (FPDF no lee PNG con transparencia)
        if (is_file($logo)) { $this->Image($logo, 122.5, 66, 36); } //36 pt de ancho, alto proporcional, centrado en el recuadro 93..188
        $this->t(188, 77, 'ARQUEO DE CASETA', 8.1, true, 'C', 515);

        //--- Datos: dos columnas ---
        $centro = self::mayus(($a['TIPO_CENTRO'] === 'BASCULA' ? 'BÁSCULA ' : '') . $a['NOM_CENTRO_OP']);
        $izq = array(array('PROYECTO', $this->cabe(self::mayus($a['NOM_PROYECTO']), 120, 8)),
                     array('FECHA', date('d/m/Y', strtotime($a['FECHA']))),
                     array('HORA', substr($a['HORA'], 0, 5)));
        $der = array(array('PEAJE', $this->cabe($centro, 110, 8)),
                     array('CASETA', $this->cabe(self::mayus($a['CASETA']), 110, 8)),
                     array('RECOLECTORA', $this->cabe(self::mayus($a['RESPONSABLE']), 110, 8)));
        $top = 118;
        for ($i = 0; $i < 3; $i++) {
            $this->t(100, $top, $izq[$i][0], 8, true);
            $this->t(165, $top, $izq[$i][1], 8);
            $this->t(318, $top, $der[$i][0], 8, true);
            $this->t(398, $top, $der[$i][1], 8);
            $top += 15;
        }

        //--- Valores ---
        $top += 14;
        $fila = function ($texto, $valor, $negrita = false, $signoMenos = false) use (&$top) {
            $this->t(100, $top, $texto, 8.5, $negrita);
            $this->t(236, $top, ($signoMenos ? '-' : '') . '$', 8.5, $negrita);
            //Pesos colombianos: miles con punto (10.000.000), como en el formato
            $this->t(360, $top, (float)$valor == 0 ? '-' : number_format((float)$valor, 0, ',', '.'), 8.5, $negrita, 'R');
            $top += 15;
        };
        foreach ($grupos['ESPERADO'] as $l) { $fila(self::mayus($l['NOMBRE']), $l['VALOR'], true); }
        $top += 8;
        foreach ($grupos['CUENTA'] as $l) { $fila(self::mayus($l['NOMBRE']), $l['VALOR'], false, $l['ROL'] === 'RESTA'); }
        $this->h(250, 360, $top - 3);
        $fila('TOTAL RECAUDO', $a['TOTAL_ARQUEO'], true);

        //--- Novedad: faltante o sobrante ---
        $top += 8;
        $dif = (float)$a['DIFERENCIA'];
        $this->t(100, $top, 'NOVEDAD', 8.5, true);
        if ($dif == 0) { $this->t(360, $top, 'SIN NOVEDAD', 8.5, true, 'R'); }
        $top += 15;
        $fila('FALTANTE', $dif < 0 ? abs($dif) : 0, $dif < 0, $dif < 0);
        $fila('SOBRANTE', $dif > 0 ? $dif : 0, $dif > 0);

        //--- Informativos (base de sencillo...) ---
        if (count($grupos['INFO'])) {
            $top += 8;
            foreach ($grupos['INFO'] as $l) { $fila(self::mayus($l['NOMBRE']), $l['VALOR']); }
        }

        //--- Observación ---
        $top += 10;
        $this->t(100, $top, 'OBSERVACIÓN', 8, true);
        $top += 12;
        foreach ($this->renglones((string)$a['OBSERVACION'], 410, 7.5, 5) as $r) { $this->t(100, $top, $r, 7.5); $top += 11; }

        //--- Firma: solo quien realiza el arqueo ---
        $top = max($top + 40, 470);
        $this->h(214, 394, $top);
        $this->t(214, $top + 4, 'QUIEN REALIZA EL ARQUEO', 7.5, true, 'C', 394);
        $this->t(214, $top + 15, $this->cabe(self::mayus($a['ARQUEA']), 180, 7), 7, false, 'C', 394);

        //Marco del cuerpo
        $this->h(93, 515, 107); $this->v(93, 107, $top + 32); $this->v(515, 107, $top + 32); $this->h(93, 515, $top + 32);
        $this->pie($a, $top + 42);
    }

    //El logo de Regency centrado en el recuadro (x1..x2, y1..y2) del encabezado.
    //Es una copia en JPG (FPDF no lee PNG con transparencia); si no está, se deja en blanco
    private function logo($x1, $x2, $y1, $y2)
    {
        $f = __DIR__ . '/../public/img/logo_pdf.jpg';
        if (!is_file($f)) { return; }
        $alto = ($y2 - $y1) - 4;                 //2 pt de aire arriba y abajo
        $ancho = $alto * (360 / 312);            //proporción del logo
        $this->Image($f, $x1 + (($x2 - $x1) - $ancho) / 2, $y1 + 2, $ancho, $alto);
    }

    private function pie($a, $top)
    {
        $this->SetTextColor(120, 120, 120);
        $this->t(79, $top, 'Registrado en HANA · Arqueo #' . (int)$a['ID_ARQUEO'] . ' · ' . $a['ARQUEA'] . ' · ' . $a['FEC_REGISTRO'], 5.5);
        $this->SetTextColor(0, 0, 0);
    }

    //Nombre del archivo, como los que usan hoy: "Arqueo caja menor Trapiche 2026-09-16.pdf"
    public static function nombreArchivo($a)
    {
        $tipo = $a['TIPO'] === 'CAJA_MENOR' ? 'caja menor' : ($a['TIPO'] === 'CASETA' ? 'caseta ' . trim((string)$a['CASETA']) : 'recambio');
        $centro = preg_replace('/[^A-Za-z0-9 ]/', '', strtr((string)$a['NOM_CENTRO_OP'],
                  array('á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N')));
        $centro = ucwords(strtolower(trim($centro)));
        return "Arqueo $tipo $centro " . $a['FECHA'] . ((int)$a['ESTADO'] !== 1 ? ' ANULADO' : '') . '.pdf';
    }
}
