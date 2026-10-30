<?php
/*
  HANA - Usuarios y sesión (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/UsuariosAjax.js, Ajax/LoginAjax.js y
  Ajax/RestablecerAjax.js, y responde con lo que trae el modelo
  (Modelo/UsuarioModelo.php).
  La persona y su usuario van en tablas distintas y se manejan en dos pantallas:
    - Colaboradores (Control/ColaboradoresControl.php): la persona (documento, nombre,
      correo) y sus peajes asignados. Al registrarla se pregunta si se le crea el usuario.
      Permiso 3M.
    - Usuarios (este): el usuario de cada persona (tabla usuarios_sistema): nombre de
      usuario, rol, contraseña y si puede entrar. Aquí también se le crea el usuario a
      quien no tiene. Permiso 1M.
  Aquí no se registra a nadie ni se cambian sus datos: eso es de Colaboradores.
  El número que llega de la pantalla y el de la sesión es el de la persona (ID_COLABORADOR).

  Cada petición llega con ?op=... y cae en uno de los "case" de abajo:
    Pantalla Usuarios   guardar, mostrar, anular y activar (bloquear o devolver el acceso),
                        listar y selectSinUsuario. guardar también asigna la contraseña
                        que escribió el administrador (le llega por correo).
                        claveTemporal (una al azar) queda por compatibilidad
    Otras pantallas     select, select2 y selectc (desplegables de colaboradores y centros)
    Mi contraseña       cambiarclave
    Sin sesión          verificar (iniciar sesión), recuperar (pedir el enlace de
                        "Olvidé mi contraseña"), revisarEnlace y restablecer (la página
                        del enlace) y salir

  Permisos:
    - 1M: el usuario de cada persona: crearlo, el rol (cualquiera, también los que
      administran), el nombre de usuario, la contraseña temporal y bloquear el acceso.
      24M (ADMIN TEC) también entra.
    - Cambiar la clave propia solo pide sesión.
  La contraseña temporal le llega a la persona por correo; si el correo no sale, se
  muestra en pantalla una sola vez (Control/UsuarioHelper.php).

  Las claves (reglas, cifrado y enlace de recuperación) están en Modelo/HanaClave.php.
  Cada intento de entrar queda en bitacora_sistema (TIPO 'INGRESO'); con
  HanaClave::intentosMaximos() claves malas seguidas hay que esperar
  HanaClave::minutosEspera() minutos.
*/
session_start();
require_once "../Modelo/UsuarioModelo.php";
require '../public/PHPMailer-master/src/Exception.php';
require '../public/PHPMailer-master/src/PHPMailer.php';
require '../public/PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/CorreoHelper.php';                //envío de los correos (la cuenta está en Global.php)
require_once __DIR__ . '/../Modelo/HanaDB.php';
require_once __DIR__ . '/../Modelo/HanaConfig.php';
require_once __DIR__ . '/../Modelo/AccesoModelo.php';      //para revisar que el rol exista
require_once __DIR__ . '/../Modelo/HanaClave.php';         //reglas y cifrado de las claves
require_once __DIR__ . '/../Modelo/BitacoraModelo.php';    //intentos de entrar
require_once __DIR__ . '/UsuarioHelper.php';                //clave temporal y el correo con los datos para entrar
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$modeloUsuarios = new Usuarios(); //objeto del modelo: con él se hacen las consultas

//-----------------------------------------------------------------------------
// Lo que llega del formulario
//-----------------------------------------------------------------------------
//Un texto tal como llegó ('' si no llegó o no es texto). Las claves se leen así:
//se cifran tal cual, sin quitarles nada
$valorPost = function ($campo) { return (isset($_POST[$campo]) && is_string($_POST[$campo])) ? $_POST[$campo] : ''; };
//Un texto para guardar: sin espacios a los lados ni etiquetas. Se guarda con
//consultas preparadas, así que no hace falta convertir nada más
$texto = function ($campo) use ($valorPost) { return trim(strip_tags($valorPost($campo))); };

$idPersona     = isset($_POST["idusuario"]) ? intval($_POST["idusuario"]) : 0; //la persona del formulario
$usuarioNuevo  = $texto('usuario');
$idRol         = isset($_POST["rol"]) ? intval($_POST["rol"]) : 0;
$ip            = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';

//-----------------------------------------------------------------------------
// Quién puede qué
//-----------------------------------------------------------------------------
$op          = isset($_GET["op"]) ? (string)$_GET["op"] : "";
$opsLibres   = array('verificar', 'recuperar', 'revisarEnlace', 'restablecer', 'salir'); //sin sesión
$opsPantalla = array('guardar', 'mostrar', 'anular', 'activar', 'listar', 'claveTemporal', 'selectSinUsuario'); //pantalla Usuarios
$haySesion   = isset($_SESSION['IdUsuarios']);
$misCasillas = explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : '');
$esTodo      = in_array('24M', $misCasillas, true);
$puedeAcceso = $haySesion && (in_array('1M', $misCasillas, true) || $esTodo); //usuario, rol y clave

if (!in_array($op, $opsLibres, true)) {
    if (!$haySesion) {
        http_response_code(401);
        echo "Tu sesión terminó. Vuelve a iniciar sesión.";
        exit();
    }
    if (in_array($op, $opsPantalla, true) && !$puedeAcceso) {
        http_response_code(403);
        echo "No permitido: tu rol no administra usuarios.";
        exit();
    }
}

//-----------------------------------------------------------------------------
// Ayudantes
//-----------------------------------------------------------------------------
//El asunto del correo de "Olvidé mi contraseña". También sirve para contar cuántos se
//mandaron en la última hora (Bitacora::correosRecientes)
const USU_ASUNTO_RECUPERAR = 'Crea tu contraseña nueva de HANA';

//Responde en JSON (lo usan el inicio de sesión y la página del enlace)
function usuJson($datos)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
}

//Envía un correo a una persona, en HTML y saludándola por su nombre
//(la plantilla está en CorreoHelper.php y la cuenta en Global.php)
function correoenvio($correo, $mensaje, $asunto, $nombre = '', $boton = null)
{
    $mail = new PHPMailer();
    hanaConfigurarSmtp($mail); //cuenta, servidor, UTF-8 y HTML
    $mail->FromName = 'HANA - Grupo Regency';
    $mail->addAddress($correo, hanaNombreBonito($nombre));
    $mail->Subject  = $asunto;
    $mail->Body     = hanaCorreoHtml($nombre, $asunto, $mensaje, $boton);
    $mail->AltBody  = trim(html_entity_decode(strip_tags(str_replace(array('<br>', '</p>'), "\n", $mensaje)), ENT_QUOTES, 'UTF-8')); //para lectores que no muestran HTML (los enlaces sin &amp;)
    return hanaEnviar($mail, 'USUARIOS'); //queda en la bitácora (ver CorreoHelper.php)
}

//La persona del enlace de "Olvidé mi contraseña", si el enlace es auténtico y no ha
//vencido; si no, null. El enlace trae u (la persona), v (hasta cuándo sirve) y f (la firma)
function usuPersonaDelEnlace($modeloUsuarios)
{
    $id    = isset($_POST['u']) ? intval($_POST['u']) : 0;
    $vence = isset($_POST['v']) ? intval($_POST['v']) : 0;
    $firma = (isset($_POST['f']) && is_string($_POST['f'])) ? $_POST['f'] : '';
    if ($id <= 0 || $firma === '') { return null; }
    $persona = $modeloUsuarios->datosEnlace($id);
    return ($persona && HanaClave::enlaceValido($id, $vence, $firma, $persona['CLAVE'])) ? $persona : null;
}
const USU_ENLACE_VENCIDO = 'Este enlace ya no sirve: venció (dura 60 minutos), ya se usó o está incompleto. Pide uno nuevo con «Olvidé mi contraseña».';

//-----------------------------------------------------------------------------
// Las opciones
//-----------------------------------------------------------------------------
switch ($op)
{
    //El usuario de una persona que ya está registrada: si no tiene, se le crea (con una
    //contraseña temporal que le llega por correo); si tiene, se le cambian el nombre de
    //usuario y el rol. A las personas se las registra en Colaboradores
    case 'guardar':
        $actual = $idPersona ? $modeloUsuarios->mostrar($idPersona) : null;
        if (!$actual) { echo 'Error: elige a la persona en la tabla. A las personas nuevas se las registra en Colaboradores.'; break; }

        //El rol: cualquiera que esté activo, también los que administran
        if (!Acceso::rolValido($idRol)) { echo 'Error: elige un rol activo.'; break; }
        if ($usuarioNuevo === '') { echo 'Error: escribe el nombre de usuario.'; break; }

        //La contraseña que escribió el administrador (opcional): se revisa antes de cambiar nada
        $claveEscrita = $valorPost('claveNueva');
        if ($claveEscrita !== '') {
            $problema = HanaClave::problema($claveEscrita);
            if ($problema !== '') { echo 'Error: ' . $problema; break; }
            if ($valorPost('claveNueva2') !== $claveEscrita) { echo 'Error: las dos contraseñas no coinciden. Vuelve a escribirlas.'; break; }
            if (!usuPuedeCambiarClaveDe($idPersona)) { echo 'Error: la contraseña de quien administra HANA solo la cambia quien tiene 24M.'; break; }
        }

        //No tiene usuario: se le crea (con la contraseña escrita, o una temporal al azar)
        if ((string)$actual['USUARIO'] === '') {
            if ((int)$actual['ESTADO'] !== 1) { echo 'Error: la persona está inactiva. Actívala en Colaboradores antes de crearle el usuario.'; break; }
            echo usuCrearUsuario($modeloUsuarios, $actual, $usuarioNuevo, $idRol, $claveEscrita);
            break;
        }

        //Ya tiene: el nombre de usuario (si cambió) y el rol
        if ($usuarioNuevo !== (string)$actual['USUARIO']) {
            $problema = usuProblemaNombre($modeloUsuarios, $usuarioNuevo, $idPersona);
            if ($problema !== '') { echo 'Error: ' . $problema; break; }
            $modeloUsuarios->ponerUsuario($idPersona, $usuarioNuevo);
        }
        $modeloUsuarios->ponerRol($idPersona, $idRol);
        if ($claveEscrita === '') { echo 'Usuario actualizado con éxito.'; break; }
        //Le asignó una contraseña nueva: se guarda y le llega por correo
        if (!$modeloUsuarios->guardarClave($idPersona, HanaClave::cifrar($claveEscrita))) { echo 'Error: se guardó el usuario, pero no se pudo cambiar la contraseña.'; break; }
        Bitacora::ingreso($usuarioNuevo, $ip, true, $idPersona, 'CLAVE NUEVA'); //si estaba esperando por claves malas, ya puede entrar
        $envio = usuEnviarDatosEntrada($actual['MAIL_COLABORADOR'], $actual['NOM_COLABORADOR'], $usuarioNuevo, $claveEscrita, true, false);
        echo 'Usuario y contraseña actualizados con éxito.' . usuAvisoDatosEntrada($envio, $actual['MAIL_COLABORADOR'], $usuarioNuevo, $claveEscrita);
    break;

    //Una contraseña temporal nueva para la persona. Le llega por correo
    //(si el correo no sale, se muestra en pantalla una sola vez)
    case 'claveTemporal':
        $persona = $modeloUsuarios->mostrar($idPersona);
        if (!$persona || (string)$persona['USUARIO'] === '') { echo 'Error: esa persona no tiene usuario. Créaselo primero.'; break; }
        if (!usuPuedeCambiarClaveDe($idPersona)) { echo 'Error: la contraseña de quien administra HANA solo la cambia quien tiene 24M.'; break; }
        $temporal = usuClaveTemporal();
        if (!$modeloUsuarios->guardarClave($idPersona, HanaClave::cifrar($temporal))) { echo 'Error: no se pudo cambiar la contraseña.'; break; }
        //Si estaba esperando por claves malas, con la clave nueva ya puede entrar
        Bitacora::ingreso($persona['USUARIO'], $ip, true, $idPersona, 'CLAVE NUEVA');
        $envio = usuEnviarDatosEntrada($persona['MAIL_COLABORADOR'], $persona['NOM_COLABORADOR'], $persona['USUARIO'], $temporal, true, true);
        echo 'La contraseña se cambió con éxito.' . usuAvisoDatosEntrada($envio, $persona['MAIL_COLABORADOR'], $persona['USUARIO'], $temporal);
    break;

    //Cada quien cambia su propia contraseña. La de otra persona, solo con el permiso 1M.
    //Si no lo tiene, el número que llega de la pantalla no se usa: se toma el de la sesión
    case 'cambiarclave':
        $idCambiar = (int)$_SESSION['Idcolaborador'];
        if ($puedeAcceso && $idPersona) { $idCambiar = $idPersona; }

        $claveNueva = $valorPost('clave');
        $problema = HanaClave::problema($claveNueva);
        if ($problema !== '') { echo 'Error: ' . $problema; break; }
        if (isset($_POST['clave2']) && $valorPost('clave2') !== $claveNueva) { echo 'Error: las dos contraseñas no coinciden. Vuelve a escribirlas.'; break; }
        $esPropia = $idCambiar === (int)$_SESSION['Idcolaborador'];
        if (!$esPropia && !usuPuedeCambiarClaveDe($idCambiar)) { echo 'Error: la contraseña de quien administra HANA solo la cambia quien tiene 24M.'; break; }
        if (!$modeloUsuarios->guardarClave($idCambiar, HanaClave::cifrar($claveNueva))) { echo "Error: no se pudo actualizar la contraseña"; break; }
        //El aviso al correo: a la persona le llega que su contraseña cambió
        //(si la cambió otra persona, con la contraseña nueva)
        $persona = $modeloUsuarios->mostrar($idCambiar);
        $envio = $esPropia ? usuAvisarCambioPropio($persona['MAIL_COLABORADOR'], $persona['NOM_COLABORADOR'], $persona['USUARIO'])
                           : usuEnviarDatosEntrada($persona['MAIL_COLABORADOR'], $persona['NOM_COLABORADOR'], $persona['USUARIO'], $claveNueva, true, false);
        echo "La contraseña se cambió con éxito." . ($envio['ok'] ? "\n\nEl aviso llegó al correo " . $persona['MAIL_COLABORADOR'] . "."
                                                               : "\n\nNo se pudo enviar el aviso al correo: " . $envio['motivo'] . ".");
    break;

    //Trae a una persona para cargarla en el formulario (sin la clave)
    case 'mostrar':
        echo json_encode($modeloUsuarios->mostrar($idPersona));
    break;

    //Bloquea el acceso: el usuario no puede entrar, pero la persona sigue activa en las listas.
    //A la persona se la desactiva en Colaboradores
    case 'anular':
        if ($idPersona === (int)$_SESSION['Idcolaborador']) { echo 'Error: no puedes bloquear tu propio usuario.'; break; }
        if (!$modeloUsuarios->tieneUsuario($idPersona)) { echo 'Error: esa persona no tiene usuario.'; break; }
        echo $modeloUsuarios->ponerAcceso($idPersona, false) ? 'Acceso bloqueado con éxito: ya no puede entrar a HANA.' : 'Error: no se pudo bloquear el acceso.';
    break;

    //Le devuelve el acceso al usuario
    case 'activar':
        if (!$modeloUsuarios->tieneUsuario($idPersona)) { echo 'Error: esa persona no tiene usuario.'; break; }
        echo $modeloUsuarios->ponerAcceso($idPersona, true) ? 'Acceso devuelto con éxito: ya puede entrar a HANA.' : 'Error: no se pudo devolver el acceso.';
    break;

    //Colaboradores activos que todavía no tienen usuario (formulario de Usuarios)
    case 'selectSinUsuario':
        $filas = $modeloUsuarios->colaboradoresSinUsuario();
        echo "<option value=''>Seleccione Colaborador...</option>";
        while ($opcion = $filas->fetch_object()) {
            echo "<option value='$opcion->ID_COLABORADOR'>$opcion->NOM_COLABORADOR</option>";
        }
    break;

    //Desplegable de colaboradores activos (lo usan otras pantallas)
    case 'select':
        $filas = $modeloUsuarios->colaboradoresActivos();
        echo "<option value=''>Seleccione Colaborador...</option>";
        while ($opcion = $filas->fetch_object()) {
            echo "<option value='$opcion->ID_COLABORADOR'>$opcion->NOM_COLABORADOR</option>";
        }
    break;

    //Desplegable de centros operativos activos
    case 'select2':
        $filas = $modeloUsuarios->centrosActivos();
        echo "<option value=''>Seleccione Centro operativo...</option>";
        while ($opcion = $filas->fetch_object()) {
            echo "<option value='$opcion->ID_CENTRO_OP'>$opcion->NOM_CENTRO_OP</option>";
        }
    break;

    //Filtro de centros: todos, los asignados a mí o un centro
    case 'selectc':
        $filas = $modeloUsuarios->centrosActivos();
        echo "<option value='todo'>Todos</option><option value='usuario'>Asignadas a mi usuario</option>";
        while ($opcion = $filas->fetch_object()) {
            echo "<option value='$opcion->ID_CENTRO_OP'>$opcion->NOM_CENTRO_OP</option>";
        }
    break;

    //-------------------------------------------------------------------------
    // Iniciar sesión
    //-------------------------------------------------------------------------
    //Responde {error: false} si entró, o {error: true, mensaje: '...'} con lo que debe saber la persona
    case 'verificar':
        $usuario      = trim($valorPost('LoginUsuarios'));
        $claveEscrita = $valorPost('ClaveUsuarios');
        if ($usuario === '' || $claveEscrita === '') { usuJson(array('error' => true, 'mensaje' => 'Escribe tu usuario y tu contraseña.')); break; }

        //1. El freno: demasiadas claves malas con este usuario, o desde este equipo
        if (Bitacora::fallosIngreso($usuario, HanaClave::minutosEspera()) >= HanaClave::intentosMaximos()
            || Bitacora::fallosIngresoIp($ip, HanaClave::minutosEspera()) >= HanaClave::INTENTOS_POR_IP) {
            usuJson(array('error' => true, 'mensaje' => 'Por seguridad, después de ' . HanaClave::intentosMaximos() . ' intentos con la contraseña equivocada hay que esperar '
                                                      . HanaClave::minutosEspera() . ' minutos. Si no la recuerdas, usa «Olvidé mi contraseña».'));
            break;
        }

        //2. La clave. Si el usuario no existe se revisa igual, contra una clave de relleno,
        //   para que la respuesta tarde lo mismo y no deje adivinar qué usuarios existen
        $persona   = $modeloUsuarios->datosIngreso($usuario);
        $guardada  = $persona ? (string)$persona['CLAVE'] : HanaClave::CLAVE_DE_RELLENO;
        $claveBien = HanaClave::coincide($claveEscrita, $guardada) && $persona;
        if (!$claveBien) {
            Bitacora::ingreso($usuario, $ip, false, $persona ? $persona['ID_COLABORADOR'] : null);
            $quedan  = HanaClave::intentosMaximos() - Bitacora::fallosIngreso($usuario, HanaClave::minutosEspera());
            $mensaje = 'Usuario o contraseña incorrectos. Verifica los datos e intenta de nuevo.';
            if ($quedan > 0 && $quedan <= 2) {
                $mensaje .= ' Te ' . ($quedan === 1 ? 'queda 1 intento' : 'quedan ' . $quedan . ' intentos') . ' antes de tener que esperar ' . HanaClave::minutosEspera() . ' minutos.';
            }
            usuJson(array('error' => true, 'mensaje' => $mensaje));
            break;
        }

        //3. La clave está bien: ¿la persona, su usuario y su rol siguen activos?
        if ((int)$persona['PERSONA_ACTIVA'] !== 1 || (int)$persona['USUARIO_ACTIVO'] !== 1) {
            Bitacora::ingreso($usuario, $ip, false, $persona['ID_COLABORADOR']);
            usuJson(array('error' => true, 'mensaje' => 'Tu usuario está desactivado. Si crees que es un error, habla con el administrador de HANA.'));
            break;
        }
        if ((int)$persona['ROL_ACTIVO'] !== 1) {
            Bitacora::ingreso($usuario, $ip, false, $persona['ID_COLABORADOR']);
            usuJson(array('error' => true, 'mensaje' => 'Tu rol de HANA está desactivado, así que por ahora no puedes entrar. Pídele al administrador que te asigne un rol activo.'));
            break;
        }

        //4. Entra. La sesión cambia de número al entrar, para que nadie pueda usar
        //   un número de sesión que conoció antes del ingreso
        session_regenerate_id(true);
        //Las pantallas trabajan con el número de la persona (ID_COLABORADOR); el usuario se busca por ella
        $_SESSION['IdUsuarios']    = $persona['ID_COLABORADOR'];
        $_SESSION['Idcolaborador'] = $persona['ID_COLABORADOR'];
        $_SESSION['Auditoria']     = $persona['PUEDE_CERRAR_NOVEDADES']; //puede cerrar novedades
        $_SESSION['Modulos']       = $persona['PERMISOS'];               //las casillas de su rol
        $_SESSION['Nombre']        = $persona['NOM_COLABORADOR'];
        //Si la clave estaba guardada en el formato de antes, se guarda en el nuevo
        if (HanaClave::hayQueRecifrar($guardada)) { $modeloUsuarios->guardarClave($persona['ID_COLABORADOR'], HanaClave::cifrar($claveEscrita)); }
        Bitacora::ingreso($usuario, $ip, true, $persona['ID_COLABORADOR']);
        usuJson(array('error' => false));
    break;

    //-------------------------------------------------------------------------
    // "Olvidé mi contraseña"
    //-------------------------------------------------------------------------
    //Manda al correo de la persona un enlace para crear una clave nueva. No cambia nada
    //todavía: la clave actual sigue sirviendo hasta que la persona use el enlace.
    //La respuesta es la misma exista o no el usuario, para no dejar adivinar quién existe
    case 'recuperar':
        $usuario = trim($valorPost('LoginUsuarios'));
        if ($usuario === '') { usuJson(array('mensaje' => 'Escribe tu usuario.')); break; }
        $respuesta = 'Si ese usuario existe y tiene un correo registrado, te enviamos un enlace para crear una contraseña nueva. '
                   . 'Revisa tu correo (también la carpeta de spam); el enlace sirve ' . HanaClave::minutosEnlace() . ' minutos. '
                   . 'Mientras tanto, tu contraseña actual sigue sirviendo. Si no te llega, pídele al administrador una contraseña nueva.';

        $persona = $modeloUsuarios->datosRecuperacion($usuario);
        if ($persona && filter_var($persona['MAIL_COLABORADOR'], FILTER_VALIDATE_EMAIL)
            && Bitacora::correosRecientes($persona['MAIL_COLABORADOR'], USU_ASUNTO_RECUPERAR, 60) < HanaClave::CORREOS_POR_HORA) {
            $id     = (int)$persona['ID_COLABORADOR'];
            $vence  = time() + HanaClave::minutosEnlace() * 60;
            $enlace = HanaClave::urlRestablecer($id, $vence, HanaClave::firmaEnlace($id, $vence, $persona['CLAVE']));
            $cuerpo = '<p style="margin:0 0 12px;">Pediste crear una contraseña nueva para <strong>HANA</strong>. Hazlo con el botón de abajo.</p>'
                    . '<p style="margin:0 0 12px;">El enlace sirve ' . HanaClave::minutosEnlace() . ' minutos y una sola vez. Si no fuiste tú, no hagas nada: tu contraseña actual sigue sirviendo.</p>'
                    . '<p style="margin:0;font-size:12px;color:#666;">Si el botón no abre, copia esta dirección en el navegador:<br>' . htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8') . '</p>';
            correoenvio($persona['MAIL_COLABORADOR'], $cuerpo, USU_ASUNTO_RECUPERAR, $persona['NOM_COLABORADOR'],
                        array('texto' => 'Crear contraseña nueva', 'url' => $enlace));
            //Si el correo no sale, no se cambia nada y queda anotado en la bitácora de correos
        }
        usuJson(array('mensaje' => $respuesta));
    break;

    //La página del enlace pregunta si el enlace sirve, antes de mostrar el formulario
    case 'revisarEnlace':
        $persona = usuPersonaDelEnlace($modeloUsuarios);
        if (!$persona) { usuJson(array('valido' => false, 'mensaje' => USU_ENLACE_VENCIDO)); break; }
        usuJson(array('valido' => true, 'nombre' => hanaNombreBonito($persona['NOM_COLABORADOR']), 'usuario' => $persona['USUARIO'],
                      'minimo' => HanaClave::largoMinimo()));
    break;

    //Guarda la clave nueva que la persona escribió en la página del enlace.
    //Al cambiar la clave guardada, el enlace deja de servir (se usa una sola vez)
    case 'restablecer':
        $persona = usuPersonaDelEnlace($modeloUsuarios);
        if (!$persona) { usuJson(array('ok' => false, 'mensaje' => USU_ENLACE_VENCIDO)); break; }
        $claveNueva = $valorPost('clave');
        $problema = HanaClave::problema($claveNueva);
        if ($problema !== '') { usuJson(array('ok' => false, 'mensaje' => $problema)); break; }
        if ($valorPost('clave2') !== $claveNueva) { usuJson(array('ok' => false, 'mensaje' => 'Las dos contraseñas no coinciden. Vuelve a escribirlas.')); break; }
        if (!$modeloUsuarios->guardarClave($persona['ID_COLABORADOR'], HanaClave::cifrar($claveNueva))) {
            usuJson(array('ok' => false, 'mensaje' => 'No se pudo guardar la contraseña. Intenta de nuevo en un momento.'));
            break;
        }
        //Si estaba esperando por claves malas, con la clave nueva ya puede entrar
        Bitacora::ingreso($persona['USUARIO'], $ip, true, $persona['ID_COLABORADOR'], 'CLAVE NUEVA');
        //Aviso al correo de que la contraseña cambió (sin la contraseña)
        $datos = $modeloUsuarios->mostrar($persona['ID_COLABORADOR']);
        if ($datos && filter_var($datos['MAIL_COLABORADOR'], FILTER_VALIDATE_EMAIL)) { usuAvisarCambioPropio($datos['MAIL_COLABORADOR'], $datos['NOM_COLABORADOR'], $datos['USUARIO']); }
        usuJson(array('ok' => true, 'mensaje' => 'Listo: tu contraseña quedó cambiada. Ya puedes entrar con ella.'));
    break;

    //Cierra la sesión y vuelve al inicio de sesión
    case 'salir':
        session_unset();
        session_destroy();
        header("Location: ../Vista/login.php");
    break;

    //La tabla de la pantalla Usuarios: todas las personas, con su usuario si lo tienen
    case 'listar':
        //Los nombres pueden venir guardados con códigos (&amp;) por los módulos viejos:
        //se convierten de vuelta y se escapan una sola vez
        $escapar = function ($valor) { return htmlspecialchars(html_entity_decode((string)$valor, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'); };
        $filas = $modeloUsuarios->listar();
        $filasTabla = array();
        while ($fila = $filas->fetch_object()) {
            $id = (int)$fila->ID_COLABORADOR;
            $tiene = $fila->USUARIO !== null && $fila->USUARIO !== '';
            $personaActiva = (int)$fila->ESTADO === 1;
            $puedeEntrar = $tiene && (int)$fila->ACCESO === 1;
            if (!$tiene) {
                $acceso = $personaActiva ? '<span class="rd-ayuda">Sin usuario</span>' : '<span class="rd-ayuda">Persona inactiva</span>';
                $botones = $personaActiva
                    ? '<SPAN title="Crear usuario"><button class="btn btn-success" onclick="crearUsuario(' . $id . ')"><i class="fa fa-user-plus" style="color:white;"></i></button></SPAN>'
                    : '';
            } else {
                $acceso = !$personaActiva ? 'Persona inactiva' : ($puedeEntrar ? 'Puede entrar' : 'Bloqueado');
                $botones = '<SPAN title="Editar"><button class="btn btn-success" onclick="mostrar(' . $id . ')"><i class="fa fa-pencil" style="color:white;"></i></button></SPAN> '
                         . ((int)$fila->ACCESO === 1
                            ? '<SPAN title="Bloquear el acceso"><button type="button" class="btn btn-success" onclick="anular(' . $id . ')"><i class="fa fa-lock" style="color:white;"></i></button></SPAN>'
                            : '<SPAN title="Devolver el acceso"><button type="button" class="btn btn-success" onclick="activar(' . $id . ')"><i class="fa fa-unlock" style="color:white;"></i></button></SPAN>');
            }
            $filasTabla[] = array(
                "0" => $escapar($fila->DOC_COLABORADOR),
                "1" => $escapar($fila->NOM_COLABORADOR),
                "2" => $escapar($fila->MAIL_COLABORADOR),
                "3" => $tiene ? $escapar($fila->NOM_ROL_USUARIO_SISTEMA) : '<span class="rd-ayuda">—</span>',
                "4" => $tiene ? $escapar($fila->USUARIO) : '<span class="rd-ayuda">Sin usuario</span>',
                "5" => $acceso,
                "6" => $botones,
            );
        }
        echo json_encode(array(
            "sEcho" => 1,
            "iTotalRecords" => count($filasTabla),
            "iTotalDisplayRecords" => count($filasTabla),
            "aaData" => $filasTabla));
    break;
}
