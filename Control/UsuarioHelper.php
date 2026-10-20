<?php
/*
  HANA - Datos para entrar: la clave temporal y el correo que se los lleva a la persona
  ---------------------------------------------------------------------------
  Lo usan las dos pantallas de personas:
    - Colaboradores (Control/ColaboradoresControl.php): al registrar a alguien se le crea
      el usuario y le llega este correo;
    - Usuarios (Control/UsuariosControl.php): al ponerle usuario a quien no tenía, o al
      darle una contraseña temporal nueva.
  Quien necesita los PHPMailer y CorreoConfig.php los carga antes de usar estas funciones.

  También dice qué roles «administran» (las casillas 1M, 2M, 3M y 24M): esos roles no se
  pueden dar desde Colaboradores, y a las personas que los tienen solo las edita o
  desactiva desde allí quien tiene 24M.
*/
require_once __DIR__ . '/../Modelo/HanaDB.php';
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

//¿El rol que tiene esa persona administra?
function usuPersonaAdministra($idPersona)
{
    $fila = HanaDB::fila("SELECT r.PERMISOS FROM colaboradores c JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = c.ID_ROL_USUARIO_SISTEMA
                           WHERE c.ID_COLABORADOR = ?", 'i', array((int)$idPersona));
    return $fila ? usuRolAdministra($fila['PERMISOS']) : false;
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
    return hanaEnviar($mail, 'USUARIOS'); //con plan B y registro (ver CorreoConfig.php)
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
