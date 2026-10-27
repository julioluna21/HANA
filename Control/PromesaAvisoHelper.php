<?php
/*
  HANA - Aviso por correo cuando la promesa de valor queda por debajo
  ---------------------------------------------------------------------------
  Lo usa Control/PromesaValorControl.php al guardar. Si en lo que se acaba de guardar
  hay casillas que quedaron por debajo de la promesa (y antes no lo estaban), y en
  Reporte diario → Correos automáticos el aviso «Promesa de valor por debajo» está
  encendido con la opción de correo marcada, les llega un correo en ese momento a:
    - las personas y los roles elegidos en ese aviso que tengan ese peaje asignado;
    - el coordinador del proyecto, si está marcada esa opción.
  A quien guardó no le llega (ya lo sabe). El correo queda anotado en la bitácora.
  La campana no depende de esto: la arma Modelo/CampanaModelo.php con la misma configuración.
*/
require_once __DIR__ . '/../public/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../public/PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/CorreoHelper.php';
require_once __DIR__ . '/../Modelo/TareasModelo.php';
require_once __DIR__ . '/../Modelo/PromesaValorModelo.php';

//$centro: el peaje (ID_CENTRO_OP, NOM_CENTRO_OP, TIPO_CENTRO, ID_PROYECTO)
//$debajo: lista de casillas que quedaron por debajo: [idCargo, fecha, promesa, ejecutado, justificacion]
//Devuelve cuántas personas recibieron el correo (0 si no se envió)
function pvAvisarPorCorreo($centro, $debajo, $idQuienGuardo)
{
    if (!count($debajo)) { return 0; }
    $config = Tareas::config('PROMESA_AVISO');
    if (!$config['activo'] || !$config['correo']) { return 0; }

    //A quién: personas y roles elegidos que tienen ese peaje asignado (o el ADMIN TEC),
    //y el coordinador del proyecto si está marcado
    $personas = array();
    foreach (Tareas::destinatarios($config) as $idPersona => $persona) {
        $tieneElPeaje = HanaDB::fila("SELECT 1 AS ok FROM asoc_usuarios_sistemas_x_cop WHERE ID_COLABORADOR = ? AND ID_CENTRO_OP = ? LIMIT 1",
                                     'ii', array((int)$persona['ID_COLABORADOR'], (int)$centro['ID_CENTRO_OP']));
        $esAdmin = HanaDB::fila("SELECT 1 AS ok FROM usuarios_sistema u INNER JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA
                                  WHERE u.ID_COLABORADOR = ? AND CONCAT(',', r.PERMISOS, ',') LIKE '%,24M,%' LIMIT 1", 'i', array((int)$persona['ID_COLABORADOR']));
        if ($tieneElPeaje || $esAdmin) { $personas[$idPersona] = $persona; }
    }
    if ($config['coordinador']) {
        $coordinador = HanaDB::fila("SELECT c.ID_COLABORADOR, c.NOM_COLABORADOR, c.MAIL_COLABORADOR FROM proyectos p
                                      INNER JOIN colaboradores c ON c.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR AND c.ESTADO = 1
                                      WHERE p.ID_PROYECTO = ?", 'i', array((int)$centro['ID_PROYECTO']));
        if ($coordinador) { $personas[(int)$coordinador['ID_COLABORADOR']] = $coordinador; }
    }
    unset($personas[(int)$idQuienGuardo]);
    $correos = array();
    foreach ($personas as $persona) {
        $correo = trim((string)$persona['MAIL_COLABORADOR']);
        if (filter_var($correo, FILTER_VALIDATE_EMAIL)) { $correos[$correo] = html_entity_decode((string)$persona['NOM_COLABORADOR'], ENT_QUOTES, 'UTF-8'); }
    }
    if (!count($correos) || !hanaCorreoDatos()) { return 0; }

    //El cuerpo: una fila por casilla, con el día, el cargo, las horas y la justificación
    $nombresCargo = array();
    foreach (PromesaValor::cargos($centro['TIPO_CENTRO']) as $cargo) { $nombresCargo[(int)$cargo['ID_CARGO_AUS']] = $cargo['NOMBRE']; }
    $esc = function ($texto) { return htmlspecialchars(html_entity_decode((string)$texto, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'); };
    $horas = function ($valor) { return str_replace('.', ',', (string)(round((float)$valor, 2) + 0)); };
    $peaje = html_entity_decode((string)$centro['NOM_CENTRO_OP'], ENT_QUOTES, 'UTF-8');
    $filas = '';
    foreach ($debajo as $casilla) {
        $filas .= '<tr><td style="padding:5px 6px;border-top:1px solid #ECE6E1;">' . date('d/m/Y', strtotime($casilla[1])) . '</td>'
                . '<td style="padding:5px 6px;border-top:1px solid #ECE6E1;">' . $esc(isset($nombresCargo[(int)$casilla[0]]) ? $nombresCargo[(int)$casilla[0]] : '') . '</td>'
                . '<td style="padding:5px 6px;border-top:1px solid #ECE6E1;text-align:right;">' . $horas($casilla[2]) . '</td>'
                . '<td style="padding:5px 6px;border-top:1px solid #ECE6E1;text-align:right;color:#A61B1B;font-weight:bold;">' . $horas($casilla[3]) . '</td>'
                . '<td style="padding:5px 6px;border-top:1px solid #ECE6E1;' . ($casilla[4] ? '' : 'color:#C62828;') . '">' . ($casilla[4] ? $esc($casilla[4]) : 'Sin justificar') . '</td></tr>';
    }
    $html = '<p style="margin:0 0 12px;">En <strong>' . $esc($peaje) . '</strong> se acaba de registrar la promesa de valor por debajo:</p>'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">'
          . '<tr style="background:#FBF8F6;color:#7A716E;"><td style="padding:6px;">Día</td><td style="padding:6px;">Cargo</td>'
          . '<td style="padding:6px;text-align:right;">Promesa (h)</td><td style="padding:6px;text-align:right;">Ejecutado (h)</td><td style="padding:6px;">Justificación</td></tr>'
          . $filas . '</table>';
    $primera = $debajo[0][1];
    $url = hanaUrlSistema('PromesaValorVista.php') . '?centro=' . (int)$centro['ID_CENTRO_OP'] . '&anio=' . (int)substr($primera, 0, 4) . '&mes=' . (int)substr($primera, 5, 2);

    $mail = new \PHPMailer\PHPMailer\PHPMailer();
    hanaConfigurarSmtp($mail);
    $mail->FromName = 'HANA - Promesa de valor';
    foreach ($correos as $correo => $nombre) { $mail->addAddress($correo, $nombre); }
    $mail->Subject = 'Promesa de valor por debajo en ' . $peaje . ' (' . date('d/m', strtotime($primera)) . ')';
    $mail->Body = hanaCorreoHtml(count($correos) === 1 ? reset($correos) : '', 'Promesa de valor', $html, array('texto' => 'Ver la promesa de valor', 'url' => $url));
    return hanaEnviar($mail, 'PROMESA') ? count($correos) : 0;
}
