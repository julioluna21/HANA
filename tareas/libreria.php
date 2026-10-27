<?php
/*
  HANA - Lo que hacen las tareas automáticas (correos de recordatorio y resúmenes)
  ---------------------------------------------------------------------------
  Cada tarea es una función que devuelve cuántos correos salieron, cuántos fallaron
  y las líneas de lo que hizo. La usan:
    - tareas/programador.php, que cron llama cada hora y corre las que tocan
      (según la hora, los días y si están encendidas: Modelo/TareasModelo.php);
    - los archivos de cada tarea (recordatorio_manana.php...), para correrla a mano;
    - la pantalla Reporte diario → Correos automáticos (Control/TareasControl.php),
      con «Ver a quién le llegaría» y «Enviar ahora».
  Con $simular no se envía nada: solo se dice a quién le llegaría y qué.
  No se abre desde el navegador: lo incluyen los archivos de arriba.
*/
date_default_timezone_set('America/Bogota'); //"hoy" y "ayer" son los de Colombia

//Cómo se nombra a quien recibe un correo en lo que va diciendo la tarea. En la prueba de
//correos (tareas/probar_correos.php) todo va solo al buzón de prueba: entonces se dice
//para quién era, sin su correo, y a dónde llegó de verdad
function tareaQuien($nombre, $correo)
{
    return defined('HANA_CORREO_SOLO_A') ? $nombre : $nombre . ' <' . $correo . '>';
}
function tareaASoloA()
{
    return defined('HANA_CORREO_SOLO_A') ? ' a ' . HANA_CORREO_SOLO_A : '';
}
function tareaListaDestinos($correos, $nombres = array())
{
    if (!defined('HANA_CORREO_SOLO_A')) { return implode(', ', $correos); }
    $nombres = array_values(array_unique(array_filter($nombres)));
    return HANA_CORREO_SOLO_A . (count($nombres) ? ' (era para: ' . implode(', ', $nombres) . ')' : '');
}

require_once __DIR__ . '/../Conexion/Global.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/../Control/CorreoHelper.php';
require_once __DIR__ . '/../Modelo/HanaConfig.php';
require_once __DIR__ . '/../Modelo/TareasModelo.php';
require_once __DIR__ . '/../Modelo/EstadoDiaModelo.php';
require_once __DIR__ . '/../Modelo/OrganizacionModelo.php';
require_once __DIR__ . '/../Modelo/AccesoModelo.php';
require_once __DIR__ . '/../Modelo/PromesaValorModelo.php';

//Lo que va diciendo la tarea: se guarda para la pantalla y, por línea de comandos, se escribe
$GLOBALS['tareaSalida'] = array();
function tareaLinea($texto)
{
    $GLOBALS['tareaSalida'][] = $texto;
    if (PHP_SAPI === 'cli') { echo '[' . date('Y-m-d H:i:s') . '] ' . $texto . PHP_EOL; }
}

//¿La cuenta de correo está escrita en Global.php? Si no, la tarea lo dice y no envía nada
function tareaHayCorreo()
{
    if (hanaCorreoDatos()) { return true; }
    tareaLinea('No se envía nada: falta la cuenta de correo en Conexion/Global.php (MAIL_HOST, MAIL_USER y MAIL_PASSWORD).');
    return false;
}

//"3 abiertas, 1 fuera de tiempo"
function tareaCuantas($abiertas, $vencidas, $singular, $plural, $textoVencidas)
{
    $texto = $abiertas . ' ' . ($abiertas === 1 ? $singular : $plural);
    return $vencidas > 0 ? $texto . ', ' . $vencidas . ' ' . $textoVencidas : $texto;
}

//La nota que el administrador agregó al correo, lista para HTML
function tareaNota($config)
{
    $nota = trim(isset($config['nota']) ? (string)$config['nota'] : '');
    return $nota === '' ? '' : '<p style="margin:12px 0 0;padding:10px 12px;background:#FBF8F6;border-radius:8px;">' . nl2br(htmlspecialchars($nota, ENT_QUOTES, 'UTF-8')) . '</p>';
}

//Corre una tarea y deja constancia en la bitácora (si envió de verdad).
//Devuelve array('enviados', 'fallos', 'lineas')
function tareaEjecutar($clave, $simular, $quien)
{
    $GLOBALS['tareaSalida'] = array();
    $config = Tareas::config($clave);
    if (!$config) { tareaLinea('Esa tarea no existe.'); return array('enviados' => 0, 'fallos' => 0, 'lineas' => $GLOBALS['tareaSalida']); }
    $nombre = Tareas::DEFINICIONES[$clave]['nombre'];
    tareaLinea($nombre . ' · ' . date('Y-m-d H:i') . ($simular ? ' (simulación: no se envía nada)' : ''));
    if (!$config['activo']) { tareaLinea('Está apagada en Correos automáticos.' . ($simular ? ' Así le llegaría si estuviera encendida:' : ' No se envía nada.')); }
    $resultado = array('enviados' => 0, 'fallos' => 0);
    if ($config['activo'] || $simular) {
        switch ($clave) {
            case 'RECORDATORIO_MANANA':
            case 'RECORDATORIO_TARDE':    $resultado = tareaRecordatorio($clave, $config, $simular); break;
            case 'PROMESA_RESUMEN':       $resultado = tareaPromesaResumen($config, $simular, date('Y-m-d', strtotime('-1 day'))); break;
            case 'RESUMEN_LISTAS_DIARIO': $resultado = tareaResumenListas('diario', $simular); break;
            case 'RESUMEN_LISTAS_SEMANAL': $resultado = tareaResumenListas('semanal', $simular); break;
            default: tareaLinea('Esta tarea no envía correos: se ve en la campana desde su hora.');
        }
    }
    if (!$simular && $config['activo']) {
        Tareas::anotar($clave, $quien, $resultado['enviados'], $resultado['fallos'],
                       $resultado['enviados'] . ' enviado(s)' . ($resultado['fallos'] ? ', ' . $resultado['fallos'] . ' con error' : ''), date('Y-m-d H:i:s'));
    }
    return array('enviados' => $resultado['enviados'], 'fallos' => $resultado['fallos'], 'lineas' => $GLOBALS['tareaSalida']);
}

//---------------------------------------------------------------------------
// Recordatorios de la mañana y de la tarde: a cada persona, lo que le falta
//---------------------------------------------------------------------------
function tareaRecordatorio($clave, $config, $simular)
{
    $hoy = date('Y-m-d');
    $ayer = date('Y-m-d', strtotime('-1 day'));
    $items = array_flip($config['items']);
    $estadoDia = new EstadoDia();
    $diasRespuesta = HanaConfig::num('COMUNICACION_DIAS_RESPUESTA', 2);
    $personas = Tareas::personasDeRoles(Tareas::rolesDe($config));
    $enviados = 0; $fallos = 0; $alDia = 0;
    if (!count($personas)) { tareaLinea('No hay personas activas en los roles elegidos.'); return array('enviados' => 0, 'fallos' => 0); }
    if (!$simular && !tareaHayCorreo()) { return array('enviados' => 0, 'fallos' => count($personas)); }

    foreach ($personas as $persona) {
        $id = (int)$persona['ID_COLABORADOR'];
        $estado = $estadoDia->resumen($id, $hoy);
        $falta = array();
        //A cada quien solo se le recuerda lo que su rol llena (Permisos de usuario) y con peajes asignados
        $rol = Acceso::rolYPermisos($id);
        $casillas = $rol ? explode(',', (string)$rol['modulos']) : array();
        $llena = function ($codigos) use ($casillas) { return in_array('24M', $casillas, true) || count(array_intersect((array)$codigos, $casillas)) > 0; };
        if (!count(HanaDB::centrosUsuario($id)) && !in_array('24M', $casillas, true)) { $estado = null; }
        if ($estado) {
            if (isset($items['HOY']) && HanaConfig::modulo('HOY') && $llena('28M') && !$estado['hoy']['ok']) {
                $falta[] = 'Contar en <strong>Hoy en qué estás</strong> dónde vas a estar y qué vas a hacer.';
            }
            if (isset($items['CRONOGRAMA']) && HanaConfig::modulo('CRONOGRAMA') && $llena('30M')) {
                if ($estado['cronograma']['vehiculos'] > 0 && $estado['cronograma']['vehiculosReg'] < $estado['cronograma']['vehiculos']) {
                    $falta[] = 'Registrar en el <strong>cronograma</strong> el estado del vehículo de hoy.';
                } elseif (!$estado['cronograma']['ok']) {
                    $falta[] = 'Revisar tu <strong>cronograma</strong> de visitas.';
                }
            }
            if (isset($items['VACANTES']) || isset($items['COMUNICACIONES'])) {
                $abiertas = $estadoDia->abiertasCoordinador($id, $hoy, $diasRespuesta);
                if ($abiertas && isset($items['VACANTES']) && HanaConfig::modulo('VACANTES') && $llena('31M') && $abiertas['vacantes']['abiertas'] > 0 && !$abiertas['vacantes']['atendidas']) {
                    $falta[] = 'Actualizar las <strong>vacantes</strong> de tus peajes (' . tareaCuantas($abiertas['vacantes']['abiertas'], $abiertas['vacantes']['vencidas'], 'abierta', 'abiertas', 'fuera del acuerdo') . ').';
                }
                if ($abiertas && isset($items['COMUNICACIONES']) && HanaConfig::modulo('COMUNICACIONES') && $llena(array('32M', '51M')) && $abiertas['comunicaciones']['abiertas'] > 0 && !$abiertas['comunicaciones']['atendidas']) {
                    $falta[] = 'Darles seguimiento a las <strong>comunicaciones</strong> sin atender (' . tareaCuantas($abiertas['comunicaciones']['abiertas'], $abiertas['comunicaciones']['vencidas'], 'abierta', 'abiertas', 'fuera de tiempo') . ').';
                }
            }
            if (isset($items['PROMESA']) && HanaConfig::modulo('PROMESA') && $llena('54M')) {
                $sinJustificar = array();
                foreach (PromesaValor::debajoDeCoordinador($id, $ayer) as $fila) {
                    if (trim((string)$fila['JUSTIFICACION']) === '') { $sinJustificar[$fila['NOM_CENTRO_OP']] = true; }
                }
                if (count($sinJustificar)) {
                    $falta[] = 'Justificar por qué ayer la <strong>promesa de valor</strong> quedó por debajo en ' . htmlspecialchars(implode(', ', array_keys($sinJustificar)), ENT_QUOTES, 'UTF-8') . '.';
                }
            }
            if (isset($items['LISTAS']) && HanaConfig::modulo('LISTAS') && $llena('11M') && !$estado['listas']['ok']) {
                $falta[] = 'Diligenciar las <strong>listas de chequeo</strong> de los peajes que visitaste.';
            }
            if (isset($items['ARQUEOS']) && HanaConfig::modulo('ARQUEOS') && $llena('29M') && !$estado['arqueos']['ok']) {
                $falta[] = 'Registrar los <strong>arqueos</strong> que hiciste hoy.';
            }
        }
        $nombre = html_entity_decode($persona['NOM_COLABORADOR'], ENT_QUOTES, 'UTF-8');
        $correo = trim((string)$persona['MAIL_COLABORADOR']);
        if (!count($falta)) { $alDia++; continue; }
        $resumen = html_entity_decode(implode(' · ', array_map('strip_tags', $falta)), ENT_QUOTES, 'UTF-8');
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) { tareaLinea($nombre . ': sin correo válido en Colaboradores. Le falta: ' . $resumen); continue; }
        if ($simular) { $enviados++; tareaLinea(tareaQuien($nombre, $correo) . ': le llegaría' . tareaASoloA() . '. Le falta: ' . $resumen); continue; }

        $asunto = $clave === 'RECORDATORIO_MANANA' ? 'Lo que te falta hoy en HANA' : 'Antes de cerrar el día en HANA';
        $mail = new \PHPMailer\PHPMailer\PHPMailer();
        hanaConfigurarSmtp($mail);
        $mail->FromName = 'HANA - Reporte diario';
        $mail->addAddress($correo, hanaNombreBonito($persona['NOM_COLABORADOR']));
        $mail->Subject = $asunto;
        $mail->Body = hanaCorreoHtml($persona['NOM_COLABORADOR'], $asunto,
            '<p style="margin:0 0 8px;">Un recordatorio rápido. Para hoy te falta:</p><ul style="margin:0 0 10px;padding-left:20px;"><li style="margin-bottom:4px;">'
            . implode('</li><li style="margin-bottom:4px;">', $falta) . '</li></ul><p style="margin:0;color:#7A716E;font-size:13px;">Si ya lo hiciste, ignora este mensaje.</p>'
            . tareaNota($config),
            array('texto' => 'Entrar a HANA', 'url' => hanaUrlSistema('ReporteDiarioVista.php')));
        $mail->AltBody = html_entity_decode(strip_tags(str_replace('</li>', "\n", $mail->Body)), ENT_QUOTES, 'UTF-8');
        if (hanaEnviar($mail, 'RECORDATORIO')) { $enviados++; tareaLinea(tareaQuien($nombre, $correo) . ': enviado' . tareaASoloA() . '.'); }
        else { $fallos++; tareaLinea(tareaQuien($nombre, $correo) . ': NO SALIÓ (' . trim(preg_replace('/\s+/', ' ', $mail->ErrorInfo)) . ').'); }
    }
    tareaLinea(($simular ? 'Le llegaría a ' . $enviados : 'Listo: ' . $enviados . ' enviado(s), ' . $fallos . ' con error') . ', ' . $alDia . ' al día.');
    return array('enviados' => $enviados, 'fallos' => $fallos);
}

//---------------------------------------------------------------------------
// Resultado de la promesa de valor de un día (normalmente, ayer)
//---------------------------------------------------------------------------
function tareaPromesaResumen($config, $simular, $fecha)
{
    if (!HanaConfig::modulo('PROMESA')) { tareaLinea('El módulo de promesa de valor está apagado en Parámetros del sistema. No se envía nada.'); return array('enviados' => 0, 'fallos' => 0); }

    //A quién, agrupado por lo que puede ver: cada persona, los peajes que tiene asignados (con 24M, todos).
    //Los correos escritos a mano en la configuración reciben todos los peajes
    $grupos = array(); //llave del alcance => array('centros' => null|[ids], 'destinos' => [], 'nombres' => [], 'porCorreo' => [])
    $agregar = function ($llave, $centros, $correo, $nombre) use (&$grupos) {
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) { return; }
        if (!isset($grupos[$llave])) { $grupos[$llave] = array('centros' => $centros, 'destinos' => array(), 'nombres' => array(), 'porCorreo' => array()); }
        $grupos[$llave]['destinos'][$correo] = $correo;
        if ($nombre !== '') { $grupos[$llave]['nombres'][] = $nombre; $grupos[$llave]['porCorreo'][$correo][] = $nombre; }
    };
    foreach (array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)$config['correos'])))) as $correo) { $agregar('todos', null, $correo, ''); }
    foreach (Tareas::destinatarios($config) as $persona) { //roles y personas elegidas
        $permisos = Acceso::rolYPermisos((int)$persona['ID_COLABORADOR']);
        $nombre = html_entity_decode((string)$persona['NOM_COLABORADOR'], ENT_QUOTES, 'UTF-8');
        if ($permisos && in_array('24M', explode(',', (string)$permisos['modulos']), true)) { $agregar('todos', null, trim((string)$persona['MAIL_COLABORADOR']), $nombre); continue; }
        $ids = array_map('intval', array_column(HanaDB::centrosUsuario((int)$persona['ID_COLABORADOR']), 'ID_CENTRO_OP'));
        sort($ids);
        if (!count($ids)) { tareaLinea($nombre . ': no tiene peajes asignados, no se le envía.'); continue; }
        $agregar(implode(',', $ids), $ids, trim((string)$persona['MAIL_COLABORADOR']), $nombre);
    }
    if (!count($grupos)) { tareaLinea('No hay a quién enviarlo: elige personas o roles, o escribe un correo, en Correos automáticos.'); return array('enviados' => 0, 'fallos' => 0); }
    if (!$simular && !tareaHayCorreo()) { return array('enviados' => 0, 'fallos' => 1); }

    $esc = function ($texto) { return htmlspecialchars(html_entity_decode((string)$texto, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'); };
    $horas = function ($valor) { return $valor === null ? '—' : str_replace('.', ',', (string)(round((float)$valor, 2) + 0)); };
    $COLOR = array('CUMPLE' => array('#D7F2E9', '#0F6B57', 'Cumple'), 'DEBAJO' => array('#F9D6D6', '#A61B1B', 'Por debajo'),
                   'ENCIMA' => array('#FFE2BF', '#A55400', 'Por encima'), 'SIN_REGISTRO' => array('#F1F2F4', '#6B7076', 'Sin ejecutado'),
                   'SIN_PROMESA' => array('#F1F2F4', '#6B7076', 'Sin promesa'));

    //Las casillas del día, agrupadas por proyecto y peaje (todos los peajes; cada correo toma los suyos)
    $porCentroTodos = array();
    foreach (PromesaValor::dia($fecha) as $fila) {
        $fila['ESTADO'] = PromesaValor::estado($fila['PROMESA_HORAS'], $fila['EJECUTADO_HORAS']);
        $id = (int)$fila['ID_CENTRO_OP'];
        if (!isset($porCentroTodos[$id])) { $porCentroTodos[$id] = array('proyecto' => $fila['NOM_PROYECTO'], 'centro' => $fila['NOM_CENTRO_OP'], 'filas' => array(), 'estado' => 'CUMPLE'); }
        $porCentroTodos[$id]['filas'][] = $fila;
    }
    //El estado del peaje: por debajo si algún cargo quedó por debajo; si no, por encima; si falta ejecutado, sin ejecutado
    foreach ($porCentroTodos as $id => $centro) {
        $estados = array_column($centro['filas'], 'ESTADO');
        $porCentroTodos[$id]['estado'] = in_array('DEBAJO', $estados, true) ? 'DEBAJO' : (in_array('SIN_REGISTRO', $estados, true) ? 'SIN_REGISTRO'
                                       : (in_array('ENCIMA', $estados, true) ? 'ENCIMA' : 'CUMPLE'));
    }
    $proyectosActivos = array_map('intval', (array)Organizacion::idsProyectosActivos());
    $centrosActivos = array_filter((array)Organizacion::centrosActivos(), function ($centro) use ($proyectosActivos) { return in_array((int)$centro['ID_PROYECTO'], $proyectosActivos, true); });

    //Lo que sale en la pantalla de la tarea: todos los peajes
    foreach ($porCentroTodos as $centro) {
        $detalle = array();
        foreach ($centro['filas'] as $fila) {
            if ($fila['ESTADO'] === 'CUMPLE') { continue; }
            $detalle[] = html_entity_decode($fila['CARGO'], ENT_QUOTES, 'UTF-8') . ' ' . $horas($fila['PROMESA_HORAS']) . '/' . $horas($fila['EJECUTADO_HORAS']) . ' h'
                       . ($fila['JUSTIFICACION'] ? ' («' . $fila['JUSTIFICACION'] . '»)' : ($fila['ESTADO'] === 'DEBAJO' || $fila['ESTADO'] === 'ENCIMA' ? ' (sin justificar)' : ''));
        }
        tareaLinea(html_entity_decode($centro['proyecto'] . ' · ' . $centro['centro'], ENT_QUOTES, 'UTF-8') . ': ' . $COLOR[$centro['estado']][2] . (count($detalle) ? ' — ' . implode('; ', $detalle) : ''));
    }
    $sinTodos = array();
    foreach ($centrosActivos as $centro) { if (!isset($porCentroTodos[(int)$centro['ID_CENTRO_OP']])) { $sinTodos[] = $centro['NOM_CENTRO_OP'] . ' (' . $centro['NOM_PROYECTO'] . ')'; } }
    if (count($sinTodos)) { tareaLinea('Sin promesa ni ejecutado: ' . html_entity_decode(implode(', ', $sinTodos), ENT_QUOTES, 'UTF-8')); }

    $dias = array('', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo');
    $meses = array('', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');
    $t = strtotime($fecha);
    $fechaBonita = $dias[(int)date('N', $t)] . ' ' . (int)date('j', $t) . ' de ' . $meses[(int)date('n', $t)];
    $etiqueta = function ($estado) use ($COLOR) {
        return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:' . $COLOR[$estado][0] . ';color:' . $COLOR[$estado][1] . ';font-size:12px;font-weight:bold;">' . $COLOR[$estado][2] . '</span>';
    };

    $enviados = 0; $fallos = 0;
    foreach ($grupos as $grupo) {
        //Solo los peajes de este grupo (null = todos)
        $permitidos = $grupo['centros'] === null ? null : array_flip($grupo['centros']);
        $porCentro = array(); $conteo = array('CUMPLE' => 0, 'DEBAJO' => 0, 'ENCIMA' => 0, 'SIN_REGISTRO' => 0);
        foreach ($porCentroTodos as $id => $centro) { if ($permitidos === null || isset($permitidos[$id])) { $porCentro[$id] = $centro; $conteo[$centro['estado']]++; } }
        $sinPromesa = array();
        foreach ($centrosActivos as $centro) {
            $id = (int)$centro['ID_CENTRO_OP'];
            if (($permitidos === null || isset($permitidos[$id])) && !isset($porCentroTodos[$id])) { $sinPromesa[] = $centro['NOM_CENTRO_OP'] . ' (' . $centro['NOM_PROYECTO'] . ')'; }
        }

        $html = '<p style="margin:0 0 12px;">Así quedó la promesa de valor del <strong>' . $esc($fechaBonita) . '</strong>' . ($permitidos === null ? '' : ' en tus peajes') . ':</p>';
        $html .= '<p style="margin:0 0 16px;">' . $etiqueta('CUMPLE') . ' ' . $conteo['CUMPLE'] . ' &nbsp; ' . $etiqueta('DEBAJO') . ' ' . $conteo['DEBAJO']
               . ' &nbsp; ' . $etiqueta('ENCIMA') . ' ' . $conteo['ENCIMA'] . ($conteo['SIN_REGISTRO'] ? ' &nbsp; ' . $etiqueta('SIN_REGISTRO') . ' ' . $conteo['SIN_REGISTRO'] : '') . '</p>';
        if (!count($porCentro)) { $html .= '<p style="margin:0 0 12px;">Ningún peaje tiene promesa ni ejecutado para ese día.</p>'; }
        $proyectoActual = null;
        foreach ($porCentro as $centro) {
            if ($centro['proyecto'] !== $proyectoActual) {
                if ($proyectoActual !== null) { $html .= '</table>'; }
                $proyectoActual = $centro['proyecto'];
                $html .= '<p style="margin:18px 0 6px;font-weight:bold;color:#6E1A1E;">' . $esc($proyectoActual) . '</p>'
                       . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">'
                       . '<tr style="background:#FBF8F6;color:#7A716E;"><td style="padding:6px;">Cargo</td><td style="padding:6px;text-align:right;">Promesa</td>'
                       . '<td style="padding:6px;text-align:right;">Ejecutado</td><td style="padding:6px;text-align:right;">Dif.</td><td style="padding:6px;">Justificación</td></tr>';
            }
            $html .= '<tr><td colspan="5" style="padding:8px 6px 4px;border-top:1px solid #ECE6E1;"><strong>' . $esc($centro['centro']) . '</strong> ' . $etiqueta($centro['estado']) . '</td></tr>';
            foreach ($centro['filas'] as $fila) {
                $c = $COLOR[$fila['ESTADO']];
                $dif = ($fila['PROMESA_HORAS'] !== null && $fila['EJECUTADO_HORAS'] !== null) ? $horas((float)$fila['PROMESA_HORAS'] - (float)$fila['EJECUTADO_HORAS']) : '—';
                $falta = ($fila['ESTADO'] === 'DEBAJO' || $fila['ESTADO'] === 'ENCIMA') && !$fila['JUSTIFICACION'];
                $html .= '<tr><td style="padding:4px 6px;">' . $esc($fila['CARGO']) . '</td>'
                       . '<td style="padding:4px 6px;text-align:right;">' . $horas($fila['PROMESA_HORAS']) . '</td>'
                       . '<td style="padding:4px 6px;text-align:right;">' . $horas($fila['EJECUTADO_HORAS']) . '</td>'
                       . '<td style="padding:4px 6px;text-align:right;background:' . $c[0] . ';color:' . $c[1] . ';font-weight:bold;">' . $dif . '</td>'
                       . '<td style="padding:4px 6px;' . ($falta ? 'color:#C62828;' : 'color:#4A4F55;') . '">' . ($fila['JUSTIFICACION'] ? $esc($fila['JUSTIFICACION']) : ($falta ? 'Sin justificar' : '')) . '</td></tr>';
            }
        }
        if ($proyectoActual !== null) { $html .= '</table>'; }
        if (count($sinPromesa)) { $html .= '<p style="margin:16px 0 0;font-size:13px;color:#7A716E;">Sin promesa ni ejecutado ese día: ' . $esc(implode(', ', $sinPromesa)) . '.</p>'; }
        $html .= '<p style="margin:14px 0 0;font-size:13px;color:#7A716E;">Las horas son promesa − ejecutado: positivo = faltaron horas; negativo = sobraron.</p>' . tareaNota($config);

        $destinos = array_values($grupo['destinos']);
        $asunto = 'Promesa de valor del ' . $fechaBonita . ': ' . $conteo['CUMPLE'] . ' cumplen, ' . $conteo['DEBAJO'] . ' por debajo, ' . $conteo['ENCIMA'] . ' por encima';
        if ($simular) { tareaLinea('El correo «' . $asunto . '» le llegaría a ' . tareaListaDestinos($destinos, $grupo['nombres']) . '.'); $enviados++; continue; }
        $mail = new \PHPMailer\PHPMailer\PHPMailer();
        hanaConfigurarSmtp($mail);
        $mail->FromName = 'HANA - Promesa de valor';
        foreach ($destinos as $correo) {
            $mail->addAddress($correo, isset($grupo['porCorreo'][$correo]) ? implode(', ', array_unique($grupo['porCorreo'][$correo])) : Organizacion::nombrePorCorreo($correo));
        }
        $mail->Subject = $asunto;
        $mail->Body = hanaCorreoHtml(count($destinos) === 1 ? Organizacion::nombrePorCorreo($destinos[0]) : '', 'Promesa de valor', $html,
                                     array('texto' => 'Ver la promesa de valor', 'url' => hanaUrlSistema('PromesaValorVista.php')));
        $mail->AltBody = html_entity_decode(strip_tags(str_replace(array('</tr>', '</p>'), "\n", $html)), ENT_QUOTES, 'UTF-8');
        if (hanaEnviar($mail, 'PROMESA')) { tareaLinea('Enviado a ' . tareaListaDestinos($destinos, $grupo['nombres']) . '.'); $enviados++; }
        else { tareaLinea('NO SALIÓ (' . trim(preg_replace('/\s+/', ' ', $mail->ErrorInfo)) . ').'); $fallos++; }
    }
    return array('enviados' => $enviados, 'fallos' => $fallos);
}

//---------------------------------------------------------------------------
// Resúmenes de las listas de chequeo (a quién le llega se elige en cada aviso)
//---------------------------------------------------------------------------
function tareaResumenListas($frecuencia, $simular)
{
    require_once __DIR__ . '/../Control/EnvioNotificacionesControl.php';
    if (!$simular && !tareaHayCorreo()) { return array('enviados' => 0, 'fallos' => 1); }
    $GLOBALS['hanaResumenFallidos'] = array(); $GLOBALS['hanaResumenSimulado'] = array();
    $enviados = hanaEnviarResumen($frecuencia, $simular);
    foreach ($GLOBALS['hanaResumenSimulado'] as $linea) { tareaLinea('Le llegaría: ' . $linea); }
    foreach ($GLOBALS['hanaResumenFallidos'] as $fallido) { tareaLinea('NO SALIÓ: ' . $fallido); }
    if (!$enviados && !count($GLOBALS['hanaResumenFallidos'])) { tareaLinea('No hay listas en el periodo para ningún aviso de esta frecuencia: no se envía nada.'); }
    elseif (!$simular) { tareaLinea('Listo: ' . $enviados . ' correo(s) enviado(s).'); }
    return array('enviados' => $enviados, 'fallos' => count($GLOBALS['hanaResumenFallidos']));
}
