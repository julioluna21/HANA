<?php
/*
  HANA — Tareas programadas: recordatorios del día a los coordinadores
  ---------------------------------------------------------------------------
  Se llama desde cPanel (Tareas Cron), por ejemplo todos los días a las 10 a. m.:

    curl -s "https://hana.cloudregencyapps.com/Control/TareasControl.php?op=recordatorios&token=LA_CLAVE" > /dev/null

  LA_CLAVE es HANA_TOKEN_TAREAS de Conexion/Global.php (la misma de los
  resúmenes de listas).

  Para cada coordinador con correo, revisa lo que le falta hoy (solo de los
  módulos encendidos): Hoy en qué estás, listas de chequeo, revisar el
  cronograma y el estado del vehículo. Si le falta algo, le escribe un correo
  corto. Se envía solo si el parámetro RECORDATORIOS_CORREO está encendido.

  Con &simular=1 no envía nada: devuelve a quién le llegaría y qué (lo usa la
  vista previa de Parámetros → Correo, con sesión y permiso 24M).
*/
session_start();
require_once __DIR__ . '/../Conexion/Global.php';
require_once __DIR__ . '/../Modelo/HanaConfig.php';
require_once __DIR__ . '/../Modelo/EstadoDiaModelo.php';
require_once __DIR__ . '/CorreoConfig.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/SMTP.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

function tareaError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//Entra la tarea programada (con el token) o el administrador desde Parámetros (24M)
$simular = !empty($_GET['simular']);
$conSesion = isset($_SESSION['Modulos']) && in_array('24M', explode(',', $_SESSION['Modulos']));
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
$conToken = defined('HANA_TOKEN_TAREAS') && strlen(HANA_TOKEN_TAREAS) >= 16 && hash_equals(HANA_TOKEN_TAREAS, $token);
if (!$conToken && !($conSesion && $simular)) { tareaError(403, 'Acceso no permitido.'); }

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'recordatorios':
        if (!$simular && !HanaConfig::si('RECORDATORIOS_CORREO', false)) {
            echo json_encode(array('ok' => true, 'enviados' => 0, 'mensaje' => 'Los recordatorios están apagados en Parámetros.'), JSON_UNESCAPED_UNICODE);
            break;
        }
        $hoy = date('Y-m-d');
        $E = new EstadoDia();
        //La dirección para entrar al sistema (la misma de esta página, dos carpetas arriba)
        $base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost')
              . rtrim(dirname(dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/Control/x.php')), '/\\') . '/Vista/ReporteDiarioVista.php';

        $coords = HanaDB::q("SELECT DISTINCT c.ID_COLABORADOR, c.NOM_COLABORADOR, c.MAIL_COLABORADOR
                               FROM proyectos p INNER JOIN colaboradores c ON c.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR
                              WHERE p.Estado = '1'");
        $salida = array(); $enviados = 0;
        foreach ((array)$coords as $c) {
            $e = $E->resumen((int)$c['ID_COLABORADOR'], $hoy);
            if (!$e) { continue; }
            $falta = array();
            if (HanaConfig::modulo('HOY') && !$e['hoy']['ok']) { $falta[] = 'Contar en <strong>Hoy en qué estás</strong> dónde vas a estar y qué vas a hacer.'; }
            if (HanaConfig::modulo('LISTAS') && !$e['listas']['ok']) { $falta[] = 'Diligenciar las <strong>listas de chequeo</strong> de los peajes que visites.'; }
            if (HanaConfig::modulo('CRONOGRAMA') && !$e['cronograma']['ok']) { $falta[] = 'Darle una mirada a tu <strong>cronograma</strong>.'; }
            if (HanaConfig::modulo('CRONOGRAMA') && $e['cronograma']['vehiculos'] > 0 && $e['cronograma']['vehiculosReg'] < $e['cronograma']['vehiculos']) {
                $falta[] = 'Registrar el <strong>estado del vehículo</strong> de hoy.';
            }
            $nombre = trim(explode(' ', html_entity_decode($c['NOM_COLABORADOR'], ENT_QUOTES, 'UTF-8'))[0]);
            $correo = trim((string)$c['MAIL_COLABORADOR']);
            $fila = array('coordinador' => $c['NOM_COLABORADOR'], 'correo' => $correo, 'falta' => array_map('strip_tags', $falta), 'resultado' => '');
            if (!count($falta)) { $fila['resultado'] = 'Al día: no se le escribe'; $salida[] = $fila; continue; }
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) { $fila['resultado'] = 'Sin correo válido en Colaboradores'; $salida[] = $fila; continue; }
            if ($simular) { $fila['resultado'] = 'Se le enviaría'; $salida[] = $fila; continue; }

            //El correo: corto, cordial y con el enlace para entrar
            $mail = new \PHPMailer\PHPMailer\PHPMailer();
            $mail->CharSet = 'UTF-8';
            hanaConfigurarSmtp($mail);
            $mail->FromName = 'HANA - Reporte diario';
            $mail->addAddress($correo, hanaNombreBonito($c['NOM_COLABORADOR']));
            $mail->Subject = 'Lo que te falta hoy en HANA';
            //La misma plantilla de todos los correos, saludando a la persona por su nombre
            $mail->Body = hanaCorreoHtml($c['NOM_COLABORADOR'], 'Lo que te falta hoy en HANA',
                '<p style="margin:0 0 8px;">Un recordatorio rápido. Para hoy te falta:</p><ul style="margin:0 0 10px;padding-left:20px;"><li style="margin-bottom:4px;">'
                . implode('</li><li style="margin-bottom:4px;">', $falta) . '</li></ul><p style="margin:0;color:#7A716E;font-size:13px;">Si ya lo hiciste, ignora este mensaje.</p>',
                array('texto' => 'Entrar a HANA', 'url' => $base));
            $ok = hanaEnviar($mail, 'RECORDATORIO');
            $fila['resultado'] = $ok ? 'Enviado' : 'No se pudo enviar (ver el registro de correos)';
            if ($ok) { $enviados++; }
            $salida[] = $fila;
        }
        echo json_encode(array('ok' => true, 'simulado' => $simular, 'enviados' => $enviados, 'detalle' => $salida), JSON_UNESCAPED_UNICODE);
        break;

    default:
        tareaError(400, 'Operación no reconocida.');
}
