<?php
/*
  HANA - Crear el usuario de una persona: la clave temporal y el correo que se la lleva
  ---------------------------------------------------------------------------
  El usuario (tabla usuarios_sistema) va aparte de la persona (colaboradores). Lo usan
  las dos pantallas de personas:
    - Colaboradores (Control/ColaboradoresControl.php): al registrar a alguien se pregunta
      si se le crea el usuario, y allí mismo se le crea con un rol que no administre;
    - Usuarios (Control/UsuariosControl.php): crearle el usuario a quien no tiene, con
      cualquier rol, o darle una contraseña temporal nueva.
  Quien necesita los PHPMailer y CorreoHelper.php los carga antes de usar estas funciones.

  También dice qué roles «administran» (las casillas 1M, 2M, 3M y 24M): esos roles no se
  pueden dar desde Colaboradores, y a las personas que los tienen solo las edita o
  desactiva desde allí quien tiene 24M.
*/
require_once __DIR__ . '/../Modelo/HanaDB.php';
require_once __DIR__ . '/../Modelo/HanaClave.php';
use PHPMailer\PHPMailer\PHPMailer;

//Las casillas que dejan administrar a otras personas o a los roles
const USU_PERMISOS_ADMINISTRACION = array('1M', '2M', '3M', '24M');

//Una clave temporal al azar, sin letras que se confunden (l, i, o, 0, 1)
function usuClaveTemporal()
{
    $letras = 'abcdefghjkmnpqrstuvwxyz23456789'; $clave = '';
    for ($i = 0; $i < 10; $i++) { $clave .= $letras[random_int(0, strlen($letras) - 1)]; }
    return $clave;
}

//¿El rol (sus casillas, "1M,10M,...") tiene alguna casilla de administración?
function usuRolAdministra($permisos)
{
    return count(array_intersect(explode(',', (string)$permisos), USU_PERMISOS_ADMINISTRACION)) > 0;
}

//Los roles activos que se pueden dar al registrar a una persona: los que no administran
function usuRolesParaRegistrar()
{
    $filas = HanaDB::q("SELECT ID_ROL_USUARIO_SISTEMA, NOM_ROL_USUARIO_SISTEMA, PERMISOS FROM rol_usuarios_sistemas
                         WHERE ESTADO = 1 ORDER BY NOM_ROL_USUARIO_SISTEMA");
    return array_values(array_filter(is_array($filas) ? $filas : array(), function ($rol) { return !usuRolAdministra($rol['PERMISOS']); }));
}

//¿Ese rol está activo y se puede dar al registrar?
function usuRolSePuedeRegistrar($idRol)
{
    foreach (usuRolesParaRegistrar() as $rol) { if ((int)$rol['ID_ROL_USUARIO_SISTEMA'] === (int)$idRol) { return true; } }
    return false;
}

//¿El rol del usuario de esa persona administra? (sin usuario, no administra)
function usuPersonaAdministra($idPersona)
{
    $fila = HanaDB::fila("SELECT r.PERMISOS FROM usuarios_sistema u JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA
                           WHERE u.ID_COLABORADOR = ?", 'i', array((int)$idPersona));
    return $fila ? usuRolAdministra($fila['PERMISOS']) : false;
}

//¿La persona de la sesión puede cambiarle la contraseña a esa persona?
//A quien administra (1M, 2M, 3M o 24M) solo la cambia quien tiene 24M
function usuPuedeCambiarClaveDe($idPersona)
{
    $misCasillas = explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : '');
    return in_array('24M', $misCasillas, true) || !usuPersonaAdministra($idPersona);
}

//¿El nombre de usuario sirve? Devuelve el problema, o '' si sirve
function usuProblemaNombre($modeloUsuarios, $usuario, $idPersona)
{
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', (string)$usuario)) {
        return 'el nombre de usuario lleva solo letras sin tilde, números, punto, guion o guion bajo (de 3 a 50).';
    }
    if ($modeloUsuarios->usuarioExiste($usuario, $idPersona)) { return 'ese nombre de usuario ya lo tiene otra persona.'; }
    return '';
}

//Por qué no salió el último correo (para decirlo en pantalla)
function usuMotivoCorreo($mail)
{
    if (!hanaCorreoDatos()) { return 'la contraseña de la cuenta de correo no está escrita en Conexion/Global.php del servidor (MAIL_PASSWORD)'; }
    $error = trim(preg_replace('/\s+/', ' ', (string)$mail->ErrorInfo));
    return 'el servidor de correo respondió: ' . ($error !== '' ? $error : 'sin detalle');
}

//Le crea el usuario a una persona que no tiene: el nombre de usuario, el rol y la contraseña.
//La contraseña es la que escribió quien lo crea; si no escribió ninguna, una temporal al azar.
//Le llega por correo (si el correo no sale, se muestra una sola vez).
//Quien llama ya revisó que el rol se pueda dar y que la contraseña escrita cumpla las reglas.
//Devuelve el mensaje para la pantalla (empieza con «Error:» si no se pudo)
function usuCrearUsuario($modeloUsuarios, $persona, $usuario, $idRol, $claveEscrita = '')
{
    $idPersona = (int)$persona['ID_COLABORADOR'];
    if ($modeloUsuarios->tieneUsuario($idPersona)) { return 'Error: esta persona ya tiene usuario.'; }
    $problema = usuProblemaNombre($modeloUsuarios, $usuario, $idPersona);
    if ($problema !== '') { return 'Error: ' . $problema; }
    $temporal = $claveEscrita === '';
    $clave = $temporal ? usuClaveTemporal() : $claveEscrita;
    if (!$modeloUsuarios->crearUsuario($idPersona, $usuario, HanaClave::cifrar($clave), $idRol)) {
        return 'Error: no se pudo crear el usuario.';
    }
    $envio = usuEnviarDatosEntrada($persona['MAIL_COLABORADOR'], $persona['NOM_COLABORADOR'], $usuario, $clave, false, $temporal);
    return 'Usuario creado con éxito.' . usuAvisoDatosEntrada($envio, $persona['MAIL_COLABORADOR'], $usuario, $clave);
}

//Le manda a la persona su usuario y su contraseña.
//  $cambio   true = ya tenía usuario y se le asignó una contraseña nueva
//  $temporal true = la generó el sistema al azar
//Devuelve array('ok' => bool, 'motivo' => por qué no salió)
function usuEnviarDatosEntrada($correo, $nombre, $usuario, $clave, $cambio = false, $temporal = true)
{
    $etiqueta = $temporal ? 'Contraseña temporal' : 'Contraseña';
    $intro = $cambio ? 'Se te asignó una contraseña nueva para entrar a <strong>HANA</strong>. La anterior ya no sirve. Estos son tus datos:'
                     : 'Ya tienes usuario en <strong>HANA</strong>, el sistema de la operación de los peajes. Estos son tus datos para entrar:';
    $mensaje = '<p style="margin:0 0 12px;">' . $intro . '</p>'
             . '<table cellpadding="0" cellspacing="0" style="margin:0 0 14px;font-size:14px;">'
             . '<tr><td style="padding:6px 12px 6px 0;color:#7A716E;">Usuario</td><td style="padding:6px 0;font-weight:bold;">' . htmlspecialchars($usuario, ENT_QUOTES, 'UTF-8') . '</td></tr>'
             . '<tr><td style="padding:6px 12px 6px 0;color:#7A716E;">' . $etiqueta . '</td><td style="padding:6px 0;font-weight:bold;font-family:monospace;">' . htmlspecialchars($clave, ENT_QUOTES, 'UTF-8') . '</td></tr>'
             . '</table>'
             . '<p style="margin:0 0 6px;">Si quieres, cámbiala al entrar en el menú de tu nombre → <strong>Cambiar clave</strong>. Si se te olvida, pide una nueva con «Olvidé mi contraseña».</p>'
             . ($cambio ? '<p style="margin:8px 0 0;color:#7A716E;font-size:13px;">Si no pediste este cambio, avísale al administrador de HANA.</p>' : '');
    $asunto = $cambio ? 'Tu contraseña de HANA cambió' : 'Tu usuario de HANA';
    $mail = new PHPMailer();
    hanaConfigurarSmtp($mail); //cuenta, servidor, UTF-8 y HTML
    $mail->FromName = 'HANA - Grupo Regency';
    $mail->addAddress($correo, hanaNombreBonito($nombre));
    $mail->Subject = $asunto;
    $mail->Body    = hanaCorreoHtml($nombre, $asunto, $mensaje, array('texto' => 'Entrar a HANA', 'url' => hanaUrlSistema('login.php')));
    $mail->AltBody = ($cambio ? "Se te asignó una contraseña nueva para entrar a HANA." : "Ya tienes usuario en HANA.")
                   . "\nUsuario: " . $usuario . "\n" . $etiqueta . ": " . $clave
                   . "\nSi quieres, cámbiala al entrar en el menú de tu nombre → Cambiar clave.";
    $ok = hanaEnviar($mail, 'USUARIOS'); //con la cuenta de Global.php; queda en la bitácora (ver CorreoHelper.php)
    return array('ok' => (bool)$ok, 'motivo' => $ok ? '' : usuMotivoCorreo($mail));
}

//Aviso a una persona de que su contraseña cambió (cuando la cambia ella misma). No lleva la contraseña
function usuAvisarCambioPropio($correo, $nombre, $usuario)
{
    $mensaje = '<p style="margin:0 0 10px;">Tu contraseña de <strong>HANA</strong> (usuario <strong>' . htmlspecialchars($usuario, ENT_QUOTES, 'UTF-8') . '</strong>) se cambió el '
             . date('d/m/Y') . ' a las ' . date('g:i a') . '.</p>'
             . '<p style="margin:0;">Si fuiste tú, no tienes que hacer nada. Si no, pide una nueva con «Olvidé mi contraseña» y avísale al administrador.</p>';
    $asunto = 'Tu contraseña de HANA cambió';
    $mail = new PHPMailer();
    hanaConfigurarSmtp($mail);
    $mail->FromName = 'HANA - Grupo Regency';
    $mail->addAddress($correo, hanaNombreBonito($nombre));
    $mail->Subject = $asunto;
    $mail->Body    = hanaCorreoHtml($nombre, $asunto, $mensaje, array('texto' => 'Entrar a HANA', 'url' => hanaUrlSistema('login.php')));
    $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace('</p>', "\n", $mensaje)), ENT_QUOTES, 'UTF-8'));
    $ok = hanaEnviar($mail, 'USUARIOS');
    return array('ok' => (bool)$ok, 'motivo' => $ok ? '' : usuMotivoCorreo($mail));
}

//El aviso para quien registró o asignó la clave: si el correo salió, solo se dice a dónde
//llegó; si no salió, se dice por qué y se muestran los datos una sola vez para pasárselos a la persona
function usuAvisoDatosEntrada($envio, $correo, $usuario, $clave)
{
    if ($envio['ok']) {
        return "\n\nEl usuario «" . $usuario . "» y su contraseña le llegaron por correo a " . $correo . '.';
    }
    return "\n\nNo se pudo enviar el correo a " . $correo . ": " . $envio['motivo'] . '.'
         . "\nEstos son sus datos para entrar:"
         . "\nUsuario: " . $usuario . "\nContraseña: " . $clave
         . "\n\nAnótalos y pásaselos a la persona: no se vuelven a mostrar.";
}
