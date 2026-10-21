<?php
/*
  HANA - Tarea programada: recordatorio del día a cada coordinador
  ---------------------------------------------------------------------------
  Para cada coordinador con correo, revisa lo que le falta HOY (solo de los
  módulos encendidos en Parámetros del sistema): Hoy en qué estás, listas de
  chequeo, revisar el cronograma y el estado del vehículo. Si le falta algo,
  le escribe un correo corto con el botón para entrar. Si está al día, no.

  Cron, por ejemplo de lunes a sábado a las 10:00 a. m.:
      0 10 * * 1-6 /usr/bin/php /home/USUARIO/public_html/tareas/recordatorios.php

  Para ver a quién le llegaría hoy, sin enviar nada:
      php tareas/recordatorios.php --simular

  Si no se quieren recordatorios, se borra la línea de cron (ya no hay un
  parámetro en el sistema para apagarlos).
*/
require_once __DIR__ . '/comun.php';
require_once __DIR__ . '/../Modelo/HanaConfig.php';
require_once __DIR__ . '/../Modelo/EstadoDiaModelo.php';
require_once __DIR__ . '/../Modelo/OrganizacionModelo.php';

$simular = tareaSimular();
if (!$simular) { tareaRevisarCorreo(); }

$hoy = date('Y-m-d');
$estadoDia = new EstadoDia();
$entrar = hanaUrlSistema('ReporteDiarioVista.php'); //el botón del correo
$enviados = 0; $fallos = 0; $alDia = 0; $seEnviarian = 0;

tareaLinea('Recordatorios del ' . $hoy . ($simular ? ' (simulación: no se envía nada)' : ''));
foreach ((array)Organizacion::coordinadoresConCorreo() as $coordinador) {
    $estado = $estadoDia->resumen((int)$coordinador['ID_COLABORADOR'], $hoy);
    if (!$estado) { continue; }
    $falta = array();
    if (HanaConfig::modulo('HOY') && !$estado['hoy']['ok']) { $falta[] = 'Contar en <strong>Hoy en qué estás</strong> dónde vas a estar y qué vas a hacer.'; }
    if (HanaConfig::modulo('LISTAS') && !$estado['listas']['ok']) { $falta[] = 'Diligenciar las <strong>listas de chequeo</strong> de los peajes que visites.'; }
    if (HanaConfig::modulo('CRONOGRAMA') && !$estado['cronograma']['ok']) { $falta[] = 'Darle una mirada a tu <strong>cronograma</strong>.'; }
    if (HanaConfig::modulo('CRONOGRAMA') && $estado['cronograma']['vehiculos'] > 0 && $estado['cronograma']['vehiculosReg'] < $estado['cronograma']['vehiculos']) {
        $falta[] = 'Registrar el <strong>estado del vehículo</strong> de hoy.';
    }
    $nombre = html_entity_decode($coordinador['NOM_COLABORADOR'], ENT_QUOTES, 'UTF-8');
    $correo = trim((string)$coordinador['MAIL_COLABORADOR']);
    if (!count($falta)) { $alDia++; continue; }
    $queFalta = implode(' · ', array_map('strip_tags', $falta));
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) { tareaLinea('  ' . $nombre . ': sin correo válido en Colaboradores. Le falta: ' . $queFalta); continue; }
    if ($simular) { $seEnviarian++; tareaLinea('  ' . $nombre . ' <' . $correo . '>: se le enviaría. Le falta: ' . $queFalta); continue; }

    //El correo: corto, cordial y con el enlace para entrar
    $mail = new \PHPMailer\PHPMailer\PHPMailer();
    hanaConfigurarSmtp($mail);
    $mail->FromName = 'HANA - Reporte diario';
    $mail->addAddress($correo, hanaNombreBonito($coordinador['NOM_COLABORADOR']));
    $mail->Subject = 'Lo que te falta hoy en HANA';
    $mail->Body = hanaCorreoHtml($coordinador['NOM_COLABORADOR'], 'Lo que te falta hoy en HANA',
        '<p style="margin:0 0 8px;">Un recordatorio rápido. Para hoy te falta:</p><ul style="margin:0 0 10px;padding-left:20px;"><li style="margin-bottom:4px;">'
        . implode('</li><li style="margin-bottom:4px;">', $falta) . '</li></ul><p style="margin:0;color:#7A716E;font-size:13px;">Si ya lo hiciste, ignora este mensaje.</p>',
        array('texto' => 'Entrar a HANA', 'url' => $entrar));
    if (hanaEnviar($mail, 'RECORDATORIO')) {
        $enviados++;
        tareaLinea('  ' . $nombre . ' <' . $correo . '>: enviado.');
    } else {
        $fallos++;
        tareaLinea('  ' . $nombre . ' <' . $correo . '>: NO SALIÓ (' . trim(preg_replace('/\s+/', ' ', $mail->ErrorInfo)) . ').');
    }
}
if ($simular) { tareaLinea('Simulación: se le enviaría a ' . $seEnviarian . ' persona(s); ' . $alDia . ' al día. No se envió nada.'); exit(0); }
tareaLinea('Listo: ' . $enviados . ' enviado(s), ' . $fallos . ' con error, ' . $alDia . ' al día.');
exit($fallos ? 1 : 0);
