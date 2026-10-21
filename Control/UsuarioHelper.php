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

//¿El nombre de usuario sirve? Devuelve el problema, o '' si sirve
function usuProblemaNombre($modeloUsuarios, $usuario, $idPersona)
{
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', (string)$usuario)) {
        return 'el nombre de usuario lleva solo letras sin tilde, números, punto, guion o guion bajo (de 3 a 50).';
    }
    if ($modeloUsuarios->usuarioExiste($usuario, $idPersona)) { return 'ese nombre de usuario ya lo tiene otra persona.'; }
    return '';
}

//Le crea el usuario a una persona que no tiene: el nombre de usuario, el rol y una contraseña
//temporal al azar, que le llega por correo (si el correo no sale, se muestra una sola vez).
//Quien llama ya revisó que el rol se pueda dar. Devuelve el mensaje para la pantalla
//(empieza con «Error:» si no se pudo)
function usuCrearUsuario($modeloUsuarios, $persona, $usuario, $idRol)
{
    $idPersona = (int)$persona['ID_COLABORADOR'];
    if ($modeloUsuarios->tieneUsuario($idPersona)) { return 'Error: esta persona ya tiene usuario.'; }
    $problema = usuProblemaNombre($modeloUsuarios, $usuario, $idPersona);
    if ($problema !== '') { return 'Error: ' . $problema; }
    $temporal = usuClaveTemporal();
    if (!$modeloUsuarios->crearUsuario($idPersona, $usuario, HanaClave::cifrar($temporal), $idRol)) {
        return 'Error: no se pudo crear el usuario.';
    }
    $enviado = usuEnviarDatosEntrada($persona['MAIL_COLABORADOR'], $persona['NOM_COLABORADOR'], $usuario, $temporal);
    return 'Usuario creado con éxito.' . usuAvisoDatosEntrada($enviado, $persona['MAIL_COLABORADOR'], $usuario, $temporal);
}

//Le manda a la persona su usuario y su contraseña temporal. Devuelve true si el correo salió
function usuEnviarDatosEntrada($correo, $nombre, $usuario, $clave)
{
    $mensaje = '<p style="margin:0 0 12px;">Ya tienes usuario en <strong>HANA</strong>, el sistema de la operación de los peajes. Estos son tus datos para entrar:</p>'
             . '<table cellpadding="0" cellspacing="0" style="margin:0 0 14px;font-size:14px;">'
             . '<tr><td style="padding:6px 12px 6px 0;color:#7A716E;">Usuario</td><td style="padding:6px 0;font-weight:bold;">' . htmlspecialchars($usuario, ENT_QUOTES, 'UTF-8') . '</td></tr>'
             . '<tr><td style="padding:6px 12px 6px 0;color:#7A716E;">Contraseña temporal</td><td style="padding:6px 0;font-weight:bold;font-family:monospace;">' . htmlspecialchars($clave, ENT_QUOTES, 'UTF-8') . '</td></tr>'
             . '</table>'
             . '<p style="margin:0 0 6px;">Al entrar, cámbiala en el menú de tu nombre → <strong>Cambiar clave</strong>. Si se te olvida, pide una nueva con «Olvidé mi contraseña».</p>';
    $asunto = 'Tu usuario de HANA';
    $mail = new PHPMailer();
    hanaConfigurarSmtp($mail); //cuenta, servidor, UTF-8 y HTML
    $mail->FromName = 'HANA - Grupo Regency';
    $mail->addAddress($correo, hanaNombreBonito($nombre));
    $mail->Subject = $asunto;
    $mail->Body    = hanaCorreoHtml($nombre, $asunto, $mensaje, array('texto' => 'Entrar a HANA', 'url' => hanaUrlSistema('login.php')));
    $mail->AltBody = "Ya tienes usuario en HANA.\nUsuario: " . $usuario . "\nContraseña temporal: " . $clave
                   . "\nAl entrar, cámbiala en el menú de tu nombre → Cambiar clave.";
    return hanaEnviar($mail, 'USUARIOS'); //con la cuenta de Global.php; queda en la bitácora (ver CorreoHelper.php)
}

//El aviso para quien registró o dio la clave: si el correo salió, solo se dice a dónde
//llegó; si no salió, se muestran los datos una sola vez para que se los pase a la persona
function usuAvisoDatosEntrada($enviado, $correo, $usuario, $clave)
{
    if ($enviado) {
        return "\n\nEl usuario «" . $usuario . "» y su contraseña temporal le llegaron por correo a " . $correo . '.';
    }
    return "\n\nNo se pudo enviar el correo a " . $correo . ". Estos son sus datos para entrar:"
         . "\nUsuario: " . $usuario . "\nContraseña temporal: " . $clave
         . "\n\nAnótalos y pásaselos a la persona: no se vuelven a mostrar."
         . "\nSi se le olvida, puede pedir una nueva con «Olvidé mi contraseña».";
}
