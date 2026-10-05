<?php
//Motor de envio de notificaciones por correo de las listas de chequeo
//Lo usan: ListasControl.php (envio inmediato) y las tareas programadas (resumenes)

//__DIR__ ancla las rutas a este archivo, sin importar desde donde se incluya
require_once __DIR__ . "/../Modelo/NotificacionesModelo.php";

require_once __DIR__ . '/../public/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/CorreoConfig.php'; //cuenta de correo y plantilla
require_once __DIR__ . '/../Modelo/HanaDB.php'; //para buscar el nombre de cada destinatario

use PHPMailer\PHPMailer\PHPMailer;

//Paleta del sistema, para que el correo se vea igual que la aplicacion
define('HANA_AZUL',  '#2A3F54'); //el mismo color del menu lateral
define('HANA_CELESTE','#5395a8');

//Guarda el motivo del ultimo fallo de envio, para poder mostrarlo al diagnosticar
$GLOBALS['hanaUltimoError'] = '';

//Envia un correo HTML. Reusa la misma cuenta SMTP que ya usa el sistema
function hanaEnviarCorreo($destinatarios, $asunto, $cuerpoHtml)
{
    $mail = new PHPMailer();
    $mail->CharSet  = "UTF-8"; //para que las tildes y la ñ no se dañen

    //Modo diagnostico: guarda toda la conversacion con el servidor de correo
    //para poder ver en que paso exacto falla el envio
    if (!empty($GLOBALS['hanaDebug'])) {
        $mail->SMTPDebug   = 2;
        $mail->Debugoutput = function ($str, $nivel) {
            $GLOBALS['hanaDebugTexto'] .= $str . "\n";
        };
    }
    $mail->Mailer   = "smtp";
    $mail->IsSMTP();
    $mail->SMTPAuth = true;
    //Servidor, cuenta y remitente: salen de Conexion/Global.php (ver CorreoConfig.php)
    if (!hanaConfigurarSmtp($mail)) {
        $GLOBALS['hanaUltimoError'] = "Faltan los datos del correo en Conexion/Global.php";
        return false;
    }
    $mail->FromName = 'HANA - Listas de Chequeo';

    //Los destinatarios llegan separados por coma. Cada uno recibe su propio correo,
    //dirigido a él y saludándolo por su nombre (si está en Colaboradores)
    $validos = array();
    foreach (explode(",", $destinatarios) as $correo) {
        $correo = trim($correo);
        //Se valida cada correo: uno malo no debe tumbar los demás
        if ($correo !== "" && filter_var($correo, FILTER_VALIDATE_EMAIL)) { $validos[] = $correo; }
    }
    if (!count($validos)) {
        $GLOBALS['hanaUltimoError'] = "Ningun destinatario valido en: ".$destinatarios;
        return false; //sin destinatarios validos no se envia
    }

    $mail->IsHTML(true);
    $mail->Subject = $asunto;
    $bien = true;
    foreach ($validos as $correo) {
        $col = HanaDB::fila("SELECT NOM_COLABORADOR FROM colaboradores WHERE MAIL_COLABORADOR = ? LIMIT 1", 's', array($correo));
        $nombre = $col ? hanaNombreBonito($col['NOM_COLABORADOR']) : '';
        $mail->clearAddresses();
        $mail->addAddress($correo, $nombre);
        //El saludo va al comienzo del cuerpo de la plantilla
        $saludo = '<p style="margin:0 0 14px;font-size:16px;font-weight:bold;">' . ($nombre !== '' ? 'Hola, ' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . ':' : 'Hola:') . '</p>';
        $marca = '<tr><td style="padding:24px 28px; color:#333333; font-size:14px;">';
        $mail->Body = strpos($cuerpoHtml, $marca) !== false ? preg_replace('/' . preg_quote($marca, '/') . '/', $marca . $saludo, $cuerpoHtml, 1) : $saludo . $cuerpoHtml;
        //Si el envio falla, se guarda el motivo que reporta PHPMailer
        if (!hanaEnviar($mail, 'LISTAS')) { //con plan B y registro (ver CorreoConfig.php)
            $GLOBALS['hanaUltimoError'] = $mail->ErrorInfo;
            $bien = false;
        }
    }
    return $bien;
}

//Arma la estructura exterior del correo (cabecera azul, cuerpo y pie)
//Se usa HTML con tablas y estilos en linea porque los gestores de correo
//(Outlook sobre todo) ignoran las hojas de estilo y el CSS moderno
function hanaPlantillaCorreo($titulo, $subtitulo, $contenido)
{
    $anio = date("Y");
    return '
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7; padding:20px 0; font-family:Arial, Helvetica, sans-serif;">
  <tr><td align="center">
    <table width="680" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 6px rgba(0,0,0,.1);">

      <!-- Cabecera con el color del sistema -->
      <tr>
        <td style="background:'.HANA_AZUL.'; padding:22px 28px; color:#ffffff;">
          <div style="font-size:22px; font-weight:bold;">'.$titulo.'</div>
          <div style="font-size:13px; opacity:.85; margin-top:4px;">'.$subtitulo.'</div>
        </td>
      </tr>

      <!-- Cuerpo -->
      <tr><td style="padding:24px 28px; color:#333333; font-size:14px;">'.$contenido.'</td></tr>

      <!-- Pie -->
      <tr>
        <td style="background:#f0f0f0; padding:14px 28px; color:#888888; font-size:11px; text-align:center;">
          Correo automatico generado por HANA - Sistema de Gestion de Novedades y Listas de Chequeo.<br>
          Por favor no responda a este mensaje. &copy; '.$anio.' Grupo Regency.
        </td>
      </tr>

    </table>
  </td></tr>
</table>';
}

//Convierte la respuesta a algo legible dentro del correo
function hanaFormatoRespuesta($respuesta, $tipo)
{
    //Las firmas se guardan como imagen en base64: se muestra la imagen
    if ($tipo === 'firma' && strpos($respuesta, 'data:image') === 0) {
        return '<img src="'.$respuesta.'" alt="Firma" style="max-width:180px; max-height:90px; border:1px solid #ddd;">';
    }
    if (trim($respuesta) === '') { return '<span style="color:#bbb;">(sin respuesta)</span>'; }

    //Se resaltan las respuestas Si/No para que salten a la vista
    $r = strtolower(trim($respuesta));
    if ($r === 'si' || $r === 'sí') { return '<span style="color:#3c8f3c; font-weight:bold;">'.htmlspecialchars($respuesta).'</span>'; }
    if ($r === 'no')                { return '<span style="color:#d9534f; font-weight:bold;">'.htmlspecialchars($respuesta).'</span>'; }

    return nl2br(htmlspecialchars($respuesta));
}

//Arma la tabla de preguntas y respuestas de UNA lista diligenciada
function hanaBloqueRespuestas($idLista, $Notif)
{
    $filas = '';
    $rs = $Notif->respuestasDeLista($idLista);
    $i = 1;
    while ($row = $rs->fetch_object()) {
        //Se alternan los fondos de fila para que sea mas facil de leer
        $fondo = ($i % 2 == 0) ? '#fafafa' : '#ffffff';
        $filas .= '
        <tr style="background:'.$fondo.';">
          <td style="padding:8px 10px; border-bottom:1px solid #eee; color:#999; width:34px;">'.$i.'</td>
          <td style="padding:8px 10px; border-bottom:1px solid #eee;">'.htmlspecialchars($row->PREGUNTA).'</td>
          <td style="padding:8px 10px; border-bottom:1px solid #eee;">'.hanaFormatoRespuesta($row->RESPUESTA, $row->TIPO_RESPUESTA).'</td>
        </tr>';
        $i++;
    }
    if ($filas === '') { return '<p style="color:#999;">Esta lista no tiene respuestas registradas.</p>'; }

    return '
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:13px; margin-top:10px;">
      <tr style="background:'.HANA_CELESTE.'; color:#ffffff;">
        <th align="left" style="padding:9px 10px; width:34px;">#</th>
        <th align="left" style="padding:9px 10px;">Pregunta</th>
        <th align="left" style="padding:9px 10px; width:33%;">Respuesta</th>
      </tr>'.$filas.'
    </table>';
}

//Recuadro con los datos de quien diligencio la lista
function hanaFichaCabecera($nombre, $centro, $fecha)
{
    return '
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f7f9fa; border-left:4px solid '.HANA_CELESTE.'; margin-bottom:6px;">
      <tr>
        <td style="padding:12px 14px; font-size:13px;">
          <strong>Diligenciado por:</strong> '.htmlspecialchars($nombre).'<br>
          <strong>Centro de operación:</strong> '.htmlspecialchars($centro).'<br>
          <strong>Fecha de registro:</strong> '.htmlspecialchars($fecha).'
        </td>
      </tr>
    </table>';
}

// ---------------------------------------------------------------------------
// ENVIO INMEDIATO: se dispara apenas un usuario guarda una lista de chequeo
// ---------------------------------------------------------------------------
function hanaNotificarInmediato($idLista)
{
    //Se envuelve todo en try/catch: si el correo falla, el guardado NO debe romperse
    try {
        $Notif = new Notificaciones();

        $cab = $Notif->cabeceraLista($idLista);
        if (!$cab) { return false; } //la lista no existe
        //La consulta devuelve un arreglo; se pasa a objeto para leer $cab->CAMPO.
        //(Antes se leía como objeto y el aviso «al guardar» nunca salía, sin mostrar error)
        $cab = (object)$cab;

        //Se buscan las configuraciones inmediatas de esa lista de chequeo
        $configs = $Notif->activasPorGrupo($cab->ID_GRUPO_LISTA_CHEQUEO, 'inmediato');
        if (!$configs || $configs->num_rows == 0) { return false; } //nadie quiere ser notificado

        //El cuerpo se arma una sola vez y se reusa para cada destinatario
        $contenido = '<p>Se registró un nuevo diligenciamiento de la lista
                      <strong>'.htmlspecialchars($cab->NOM_GRUPO_LISTA_CHEQUEO).'</strong>.</p>'
                   . hanaFichaCabecera($cab->NOM_COLABORADOR, $cab->NOM_CENTRO_OP, $cab->FEC_REGISTRO_LISTA_CHEQUEO)
                   . hanaBloqueRespuestas($idLista, $Notif);

        $html = hanaPlantillaCorreo(
            'Lista de chequeo diligenciada',
            $cab->NOM_GRUPO_LISTA_CHEQUEO,
            $contenido
        );

        while ($cfg = $configs->fetch_object()) {
            $asunto = ($cfg->ASUNTO != '')
                    ? $cfg->ASUNTO
                    : 'HANA - '.$cab->NOM_GRUPO_LISTA_CHEQUEO.' diligenciada por '.$cab->NOM_COLABORADOR;
            hanaEnviarCorreo($cfg->DESTINATARIOS, $asunto, $html);
        }
        return true;

    } catch (\Throwable $th) {
        return false; //nunca se propaga el error hacia el guardado de la lista
    }
}

// ---------------------------------------------------------------------------
// RESUMEN DIARIO / SEMANAL: agrupa todas las listas del periodo por persona
// ---------------------------------------------------------------------------
function hanaEnviarResumen($frecuencia)
{
    $Notif = new Notificaciones();

    //Rango de fechas segun la frecuencia pedida
    if ($frecuencia === 'diario') {
        $desde = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $hasta = date('Y-m-d 23:59:59', strtotime('-1 day'));
        $etiqueta = 'Resumen diario - '.date('d/m/Y', strtotime('-1 day'));
    } else {
        $desde = date('Y-m-d 00:00:00', strtotime('-7 day'));
        $hasta = date('Y-m-d 23:59:59');
        $etiqueta = 'Resumen semanal - '.date('d/m/Y', strtotime('-7 day')).' al '.date('d/m/Y');
    }

    $configs = $Notif->activasPorFrecuencia($frecuencia);
    if (!$configs) { return 0; }

    $enviados = 0;
    while ($cfg = $configs->fetch_object()) {

        $listas = $Notif->listasEnRango($cfg->ID_GRUPO_LISTA_CHEQUEO, $desde, $hasta);

        //Si en el periodo nadie diligencio nada, no se manda correo vacio
        if (!$listas || $listas->num_rows == 0) { continue; }

        $total = $listas->num_rows;
        $personas = array(); //para contar cuantas personas distintas participaron
        $bloques = '';

        while ($l = $listas->fetch_object()) {
            $personas[$l->NOM_COLABORADOR] = true;

            //Un bloque por cada diligenciamiento: quien lo hizo y sus respuestas
            $bloques .= '
            <div style="margin-bottom:26px; padding-bottom:6px; border-bottom:2px solid #eef1f3;">'
                . hanaFichaCabecera($l->NOM_COLABORADOR, $l->NOM_CENTRO_OP, $l->FEC_REGISTRO_LISTA_CHEQUEO)
                . hanaBloqueRespuestas($l->ID_LISTA_CHEQUEO, $Notif)
                . '</div>';
        }

        //Encabezado con las cifras del periodo
        $resumen = '
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:18px;">
          <tr>
            <td style="background:'.HANA_CELESTE.'; color:#fff; padding:14px; text-align:center; border-radius:6px;">
              <span style="font-size:26px; font-weight:bold;">'.$total.'</span>
              <span style="font-size:13px;"> diligenciamiento(s)</span>
              <span style="opacity:.5; margin:0 10px;">|</span>
              <span style="font-size:26px; font-weight:bold;">'.count($personas).'</span>
              <span style="font-size:13px;"> persona(s)</span>
            </td>
          </tr>
        </table>';

        $html = hanaPlantillaCorreo(
            $cfg->NOM_GRUPO_LISTA_CHEQUEO,
            $etiqueta,
            $resumen.$bloques
        );

        $asunto = ($cfg->ASUNTO != '')
                ? $cfg->ASUNTO
                : 'HANA - '.$etiqueta.' - '.$cfg->NOM_GRUPO_LISTA_CHEQUEO;

        if (hanaEnviarCorreo($cfg->DESTINATARIOS, $asunto, $html)) {
            $Notif->marcarEnvio($cfg->ID_NOTIFICACION); //queda la constancia
            $enviados++;
        }
    }
    return $enviados;
}
?>