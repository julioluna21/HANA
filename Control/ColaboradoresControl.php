<?php
/*
  HANA - Colaboradores (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/ColaboradoresAjax.js (pantalla Vista/ColaboradoresVista.php):
  las personas que trabajan en los proyectos, con su documento, nombre y correo.
  La persona y su acceso viven en la misma fila (tabla colaboradores), pero se manejan
  en dos pantallas:
    - Colaboradores (esta): registrar a la persona y cambiar sus datos. Permiso 3M.
    - Usuarios (Control/UsuariosControl.php): su rol, usuario, peajes y contraseña. Permiso 1M.
  Con 24M se entra a las dos.

  Al registrar a una persona:
    - queda con el rol que se elija entre los que no administran (sin 1M, 2M, 3M ni 24M);
      por defecto, USUARIO BASE. Los demás roles, como ADMIN TEC, se dan en Usuarios;
    - si el parámetro «Poner el usuario automáticamente» está prendido, se le crea el
      usuario (su número de documento) con una contraseña temporal, y le llegan por correo.
      Si el correo no sale, se muestran en pantalla una sola vez.
  A quien tiene un rol que administra solo lo edita o lo desactiva desde aquí quien tiene
  24M: así nadie con 3M puede cambiarle el correo a un administrador y pedir su clave.
  Las personas no se borran: se desactivan (ya no entran y dejan de salir en las listas).

  Cada petición llega con ?op=...:
    listar, mostrar, guardar, anular, activar, roles (los que se pueden dar al registrar)
    y select (desplegable de colaboradores activos; basta con haber iniciado sesión).
*/
session_start();
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
hanaGuardia(array('3M', '24M'), array('select')); //Colaboradores: 3M (o 24M). El desplegable solo pide sesión
require_once __DIR__ . '/../Modelo/UsuarioModelo.php';
require_once __DIR__ . '/../Modelo/HanaConfig.php';
require_once __DIR__ . '/../Modelo/HanaClave.php';                //cifrado de las claves
require_once __DIR__ . '/../Modelo/HanaTexto.php';                //cómo se muestran los textos guardados
require '../public/PHPMailer-master/src/Exception.php';
require '../public/PHPMailer-master/src/PHPMailer.php';
require '../public/PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/CorreoConfig.php';                       //cuenta de correo y plantilla de los correos
require_once __DIR__ . '/UsuarioHelper.php';                      //clave temporal, correo con los datos y roles que administran

$modeloUsuarios = new Usuarios(); //objeto del modelo: con él se hacen las consultas

//Lo que llega del formulario. Los textos se guardan sin espacios a los lados ni etiquetas
$texto = function ($campo) { return (isset($_POST[$campo]) && is_string($_POST[$campo])) ? trim(strip_tags($_POST[$campo])) : ''; };
$idPersona     = isset($_POST['idcolaborador']) ? intval($_POST['idcolaborador']) : 0; //vacío = persona nueva
$documento     = $texto('documento');
$nombrePersona = $texto('persona');
$correo        = $texto('correo');
$idRol         = isset($_POST['rol']) ? intval($_POST['rol']) : 0;

$misCasillas = explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : '');
$esTodo = in_array('24M', $misCasillas, true); //con 24M también se edita a quien administra

//¿Quien entra puede editar o desactivar a esa persona? Devuelve el motivo si no, o ''
function colProblemaConPersona($idPersona, $esTodo)
{
    if (!$esTodo && usuPersonaAdministra($idPersona)) {
        return 'esta persona tiene un rol que administra el sistema: solo la cambia quien tiene la casilla 24M.';
    }
    return '';
}

switch (isset($_GET['op']) ? $_GET['op'] : '')
{
    //La tabla de la pantalla: datos de la persona, y su rol y usuario solo para ver
    case 'listar':
        //Los nombres pueden venir guardados con códigos (&amp;) por los módulos viejos:
        //se convierten de vuelta y se escapan una sola vez
        $escapar = function ($valor) { return htmlspecialchars(html_entity_decode((string)$valor, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'); };
        $filas = $modeloUsuarios->listar();
        $filasTabla = array();
        while ($fila = $filas->fetch_object()) {
            $id = (int)$fila->ID_COLABORADOR;
            $activo = (int)$fila->ESTADO === 1;
            $botones = '<SPAN title="Editar"><button class="btn btn-success" onclick="mostrar(' . $id . ')"><i class="fa fa-pencil" style="color:white;"></i></button></SPAN> '
                     . ($activo
                        ? '<SPAN title="Desactivar"><button type="button" class="btn btn-success" onclick="anular(' . $id . ')"><i class="fa fa-trash" style="color:white;"></i></button></SPAN>'
                        : '<SPAN title="Activar"><button type="button" class="btn btn-success" onclick="activar(' . $id . ')"><i class="fa fa-check" style="color:white;"></i></button></SPAN>');
            $filasTabla[] = array(
                "0" => $escapar($fila->DOC_COLABORADOR),
                "1" => $escapar($fila->NOM_COLABORADOR),
                "2" => $escapar($fila->MAIL_COLABORADOR),
                "3" => $escapar($fila->NOM_ROL_USUARIO_SISTEMA),
                "4" => $fila->USUARIO !== null && $fila->USUARIO !== '' ? $escapar($fila->USUARIO) : '<span class="rd-ayuda">Sin usuario</span>',
                "5" => $activo ? 'Activo' : 'Inactivo',
                "6" => $botones,
            );
        }
        echo json_encode(array("sEcho" => 1, "iTotalRecords" => count($filasTabla),
                               "iTotalDisplayRecords" => count($filasTabla), "aaData" => $filasTabla));
    break;

    //Trae a una persona para cargarla en el formulario (sin la clave)
    case 'mostrar':
        $persona = $modeloUsuarios->mostrar($idPersona);
        if ($persona) { $persona['EDITABLE'] = colProblemaConPersona($idPersona, $esTodo) === ''; }
        echo hanaJsonTexto($persona);
    break;

    //Los roles que se pueden dar al registrar a una persona, como opciones del desplegable.
    //El primero que sale elegido es el rol básico (USUARIO BASE)
    case 'roles':
        $rolBasico = $modeloUsuarios->rolBasico();
        foreach (usuRolesParaRegistrar() as $rol) {
            $id = (int)$rol['ID_ROL_USUARIO_SISTEMA'];
            echo '<option value="' . $id . '"' . ($id === $rolBasico ? ' selected' : '') . '>'
               . htmlspecialchars(html_entity_decode($rol['NOM_ROL_USUARIO_SISTEMA'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8') . '</option>';
        }
    break;

    //Registra a una persona nueva (con su usuario) o cambia los datos de una que ya está
    case 'guardar':
        $actual = $idPersona ? $modeloUsuarios->mostrar($idPersona) : null; //null = persona nueva
        if ($idPersona && !$actual) { echo 'Error: esa persona ya no existe. Actualiza la página.'; break; }
        if ($actual) {
            $problema = colProblemaConPersona($idPersona, $esTodo);
            if ($problema !== '') { echo 'Error: ' . $problema; break; }
        }

        //Los datos: nombre, correo y documento (solo números). Un documento viejo con letras
        //se deja como está mientras no se cambie
        if ($nombrePersona === '' || mb_strlen($nombrePersona) > 100) { echo 'Error: escribe el nombre de la persona (máximo 100 letras).'; break; }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 60) { echo 'Error: escribe un correo válido.'; break; }
        $docCambia = !$actual || $documento !== (string)$actual['DOC_COLABORADOR'];
        if ($docCambia && !preg_match('/^\d{5,15}$/', $documento)) { echo 'Error: el documento solo lleva números (entre 5 y 15).'; break; }
        if ($docCambia && $modeloUsuarios->documentoExiste($documento, $idPersona)) { echo 'Error: ya hay otra persona con ese documento.'; break; }

        //Persona que ya estaba: solo cambian sus datos. El rol, el usuario y la clave se cambian en Usuarios
        if ($actual) {
            echo $modeloUsuarios->editarDatos($idPersona, $documento, $nombrePersona, $correo)
                ? 'Datos actualizados con éxito.' : 'Error: no se pudieron guardar los datos.';
            break;
        }

        //Persona nueva: su rol, entre los que no administran (por defecto, el básico)
        if (!$idRol) { $idRol = $modeloUsuarios->rolBasico(); }
        if (!usuRolSePuedeRegistrar($idRol)) {
            echo 'Error: ese rol no se puede dar al registrar a una persona. Elige uno de la lista; los roles que administran (como ADMIN TEC) se dan en Usuarios.';
            break;
        }
        $idPersona = $modeloUsuarios->crearPersona($documento, $nombrePersona, $correo, $idRol);
        if (!$idPersona) { echo 'Error: no se pudo registrar a la persona.'; break; }
        $mensaje = 'Persona registrada con éxito.';

        //Su usuario: el número de documento, con una contraseña temporal que le llega por correo
        if (!HanaConfig::si('USUARIO_AUTOMATICO')) {
            $mensaje .= "\n\nQuedó sin usuario (el parámetro «Poner el usuario automáticamente» está apagado). Se le pone en Usuarios.";
        } elseif ($modeloUsuarios->usuarioExiste($documento)) {
            $mensaje .= "\n\nYa existía un usuario «" . $documento . "», así que quedó sin usuario. Se le pone uno en Usuarios.";
        } else {
            $temporal = usuClaveTemporal();
            $modeloUsuarios->ponerUsuario($idPersona, $documento, HanaClave::cifrar($temporal));
            $enviado = usuEnviarDatosEntrada($correo, $nombrePersona, $documento, $temporal);
            $mensaje .= usuAvisoDatosEntrada($enviado, $correo, $documento, $temporal);
        }
        echo $mensaje;
    break;

    //Desactiva a la persona: no entra y deja de salir en las listas (no se borra)
    case 'anular':
        if ($idPersona === (int)$_SESSION['Idcolaborador']) { echo 'Error: no puedes desactivarte a ti mismo.'; break; }
        $problema = colProblemaConPersona($idPersona, $esTodo);
        if ($problema !== '') { echo 'Error: ' . $problema; break; }
        echo $modeloUsuarios->desactivar($idPersona) ? 'Persona desactivada con éxito.' : 'No se pudo desactivar a la persona.';
    break;

    //Vuelve a activar a la persona
    case 'activar':
        $problema = colProblemaConPersona($idPersona, $esTodo);
        if ($problema !== '') { echo 'Error: ' . $problema; break; }
        echo $modeloUsuarios->activar($idPersona) ? 'Persona activada con éxito.' : 'No se pudo activar a la persona.';
    break;

    //Desplegable de colaboradores activos (lo pueden usar otras pantallas)
    case 'select':
        $filas = $modeloUsuarios->colaboradoresActivos();
        echo "<option value=''>Seleccione ...</option>";
        while ($persona = $filas->fetch_object()) {
            echo "<option value='" . (int)$persona->ID_COLABORADOR . "'>" . $persona->NOM_COLABORADOR . "</option>";
        }
    break;

    default:
        http_response_code(400);
        echo 'Operación no reconocida.';
}
