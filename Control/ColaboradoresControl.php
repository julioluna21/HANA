<?php
/*
  HANA - Colaboradores (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/ColaboradoresAjax.js (pantalla Vista/ColaboradoresVista.php):
  las personas que trabajan en los proyectos, con su documento, nombre y correo, y los
  peajes que tiene asignados cada una.
  La persona y su usuario van en tablas distintas y se manejan en dos pantallas:
    - Colaboradores (esta): la persona (tabla colaboradores) y sus peajes asignados
      (asoc_usuarios_sistemas_x_cop). Permiso 3M.
    - Usuarios (Control/UsuariosControl.php): su usuario (tabla usuarios_sistema): nombre
      de usuario, rol, contraseña y si puede entrar. Permiso 1M.
  Con 24M se entra a las dos.

  Al registrar a una persona, la pantalla pregunta si se le crea el usuario. Si sí:
    - el usuario lo propone la pantalla (su número de documento) y se puede cambiar;
    - el rol se elige entre los que no administran (sin 1M, 2M, 3M ni 24M); por defecto,
      USUARIO BASE. Los demás roles, como ADMIN TEC, se dan en Usuarios;
    - nace con una contraseña temporal al azar que le llega por correo. Si el correo no
      sale, se muestra en pantalla una sola vez.
  Si no, la persona queda sin usuario y se le crea después (aquí con el botón de la tabla,
  o en Usuarios).
  A quien tiene un rol que administra solo lo edita o lo desactiva desde aquí quien tiene
  24M: así nadie con 3M puede cambiarle el correo a un administrador y pedir su clave.
  Las personas no se borran: se desactivan (dejan de salir en las listas y no entran).

  Cada petición llega con ?op=...:
    listar, mostrar, guardar (responde en JSON), anular, activar, crearUsuario, roles (los
    que se pueden dar aquí), los peajes (centros, centroslista, centroslistatodos y
    MostrarCentros) y select (desplegable de colaboradores activos; basta con haber
    iniciado sesión).
*/
session_start();
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
hanaGuardia(array('3M', '24M'), array('select')); //Colaboradores: 3M (o 24M). El desplegable solo pide sesión
require_once __DIR__ . '/../Modelo/UsuarioModelo.php';
require_once __DIR__ . '/../Modelo/HanaClave.php';                //cifrado de las claves
require_once __DIR__ . '/../Modelo/HanaTexto.php';                //cómo se muestran los textos guardados
require '../public/PHPMailer-master/src/Exception.php';
require '../public/PHPMailer-master/src/PHPMailer.php';
require '../public/PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/CorreoHelper.php';                       //envío de los correos (la cuenta está en Global.php)
require_once __DIR__ . '/UsuarioHelper.php';                      //crear el usuario, el correo con los datos y roles que administran

$modeloUsuarios = new Usuarios(); //objeto del modelo: con él se hacen las consultas

//Lo que llega del formulario. Los textos se guardan sin espacios a los lados ni etiquetas
$texto = function ($campo) { return (isset($_POST[$campo]) && is_string($_POST[$campo])) ? trim(strip_tags($_POST[$campo])) : ''; };
$idPersona     = isset($_POST['idcolaborador']) ? intval($_POST['idcolaborador']) : 0; //vacío = persona nueva
$documento     = $texto('documento');
$nombrePersona = $texto('persona');
$correo        = $texto('correo');
$idRol         = isset($_POST['rol']) ? intval($_POST['rol']) : 0;
$usuarioNuevo  = $texto('usuario');
$centros       = (isset($_POST['permiso']) && is_array($_POST['permiso'])) ? $_POST['permiso'] : array(); //peajes marcados

$misCasillas = explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : '');
$esTodo = in_array('24M', $misCasillas, true); //con 24M también se edita a quien administra

//Responde en JSON (el guardado: así la pantalla sabe a quién preguntarle por el usuario)
function colJson($datos)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
}

//Guarda los peajes asignados a la persona: se quitan los que tenía y se ponen los marcados
function colGuardarPeajes($modeloUsuarios, $idPersona, $centros)
{
    $modeloUsuarios->quitarCentros($idPersona);
    foreach ($centros as $idCentro) { if (intval($idCentro) > 0) { $modeloUsuarios->asignarCentro($idPersona, $idCentro); } }
}

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
    //La tabla de la pantalla: datos de la persona, sus peajes y su usuario (solo para ver)
    case 'listar':
        //Los nombres pueden venir guardados con códigos (&amp;) por los módulos viejos:
        //se convierten de vuelta y se escapan una sola vez
        $escapar = function ($valor) { return htmlspecialchars(html_entity_decode((string)$valor, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'); };
        $filas = $modeloUsuarios->listar();
        $filasTabla = array();
        while ($fila = $filas->fetch_object()) {
            $id = (int)$fila->ID_COLABORADOR;
            $activo = (int)$fila->ESTADO === 1;
            $tiene = $fila->USUARIO !== null && $fila->USUARIO !== '';
            $botones = '<SPAN title="Editar"><button class="btn btn-success" onclick="mostrar(' . $id . ')"><i class="fa fa-pencil" style="color:white;"></i></button></SPAN> '
                     . ($activo
                        ? '<SPAN title="Desactivar"><button type="button" class="btn btn-success" onclick="anular(' . $id . ')"><i class="fa fa-trash" style="color:white;"></i></button></SPAN>'
                        : '<SPAN title="Activar"><button type="button" class="btn btn-success" onclick="activar(' . $id . ')"><i class="fa fa-check" style="color:white;"></i></button></SPAN>')
                     . ($activo && !$tiene
                        ? ' <SPAN title="Crear su usuario"><button type="button" class="btn btn-success" onclick="preguntarUsuario(' . $id . ')"><i class="fa fa-user-plus" style="color:white;"></i></button></SPAN>'
                        : '');
            $filasTabla[] = array(
                "0" => $escapar($fila->DOC_COLABORADOR),
                "1" => $escapar($fila->NOM_COLABORADOR),
                "2" => $escapar($fila->MAIL_COLABORADOR),
                "3" => (int)$fila->PEAJES > 0
                       ? '<SPAN title="Peajes asignados"><button class="btn btn-success" onclick="centros(' . $id . ')"><i class="fa fa-industry" style="color:white;"></i> ' . (int)$fila->PEAJES . '</button></SPAN>'
                       : '<span class="rd-ayuda">Ninguno</span>',
                "4" => $tiene ? $escapar($fila->USUARIO) . '<br><small class="rd-ayuda">' . $escapar($fila->NOM_ROL_USUARIO_SISTEMA) . '</small>' : '<span class="rd-ayuda">Sin usuario</span>',
                "5" => $activo ? 'Activo' : 'Inactivo',
                "6" => $botones,
            );
        }
        echo json_encode(array("sEcho" => 1, "iTotalRecords" => count($filasTabla),
                               "iTotalDisplayRecords" => count($filasTabla), "aaData" => $filasTabla));
    break;

    //Trae a una persona para cargarla en el formulario (sin la clave), con sus peajes
    case 'mostrar':
        $persona = $modeloUsuarios->mostrar($idPersona);
        if ($persona) {
            $persona['EDITABLE'] = colProblemaConPersona($idPersona, $esTodo) === '';
            $persona['PEAJES'] = array();
            $filas = $modeloUsuarios->centrosAsignados($idPersona);
            while ($centro = $filas->fetch_object()) { $persona['PEAJES'][] = (int)$centro->ID_CENTRO_OP; }
        }
        echo hanaJsonTexto($persona);
    break;

    //Los roles que se pueden dar aquí al crearle el usuario a una persona, como opciones del desplegable.
    //El primero que sale elegido es el rol básico (USUARIO BASE)
    case 'roles':
        $rolBasico = $modeloUsuarios->rolBasico();
        foreach (usuRolesParaRegistrar() as $rol) {
            $id = (int)$rol['ID_ROL_USUARIO_SISTEMA'];
            echo '<option value="' . $id . '"' . ($id === $rolBasico ? ' selected' : '') . '>'
               . htmlspecialchars(html_entity_decode($rol['NOM_ROL_USUARIO_SISTEMA'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8') . '</option>';
        }
    break;

    //Registra a una persona nueva o cambia los datos de una que ya está, con sus peajes.
    //Responde en JSON: {error, mensaje} y, si la persona es nueva, {nuevo, id, documento, nombre}
    //para que la pantalla pregunte si se le crea el usuario
    case 'guardar':
        $actual = $idPersona ? $modeloUsuarios->mostrar($idPersona) : null; //null = persona nueva
        if ($idPersona && !$actual) { colJson(array('error' => true, 'mensaje' => 'Error: esa persona ya no existe. Actualiza la página.')); break; }
        if ($actual) {
            $problema = colProblemaConPersona($idPersona, $esTodo);
            if ($problema !== '') { colJson(array('error' => true, 'mensaje' => 'Error: ' . $problema)); break; }
        }

        //Los datos: nombre, correo y documento (solo números). Un documento viejo con letras
        //se deja como está mientras no se cambie
        $error = '';
        $docCambia = !$actual || $documento !== (string)$actual['DOC_COLABORADOR'];
        if ($nombrePersona === '' || mb_strlen($nombrePersona) > 100) { $error = 'Error: escribe el nombre de la persona (máximo 100 letras).'; }
        elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 60) { $error = 'Error: escribe un correo válido.'; }
        elseif ($docCambia && !preg_match('/^\d{5,15}$/', $documento)) { $error = 'Error: el documento solo lleva números (entre 5 y 15).'; }
        elseif ($docCambia && $modeloUsuarios->documentoExiste($documento, $idPersona)) { $error = 'Error: ya hay otra persona con ese documento.'; }
        if ($error !== '') { colJson(array('error' => true, 'mensaje' => $error)); break; }

        //Persona que ya estaba: sus datos y sus peajes. El usuario, el rol y la clave se cambian en Usuarios
        if ($actual) {
            if (!$modeloUsuarios->editarDatos($idPersona, $documento, $nombrePersona, $correo)) {
                colJson(array('error' => true, 'mensaje' => 'Error: no se pudieron guardar los datos.')); break;
            }
            colGuardarPeajes($modeloUsuarios, $idPersona, $centros);
            colJson(array('error' => false, 'nuevo' => false, 'mensaje' => 'Datos actualizados con éxito.'));
            break;
        }

        //Persona nueva (sin usuario: la pantalla pregunta después si se le crea)
        $idPersona = $modeloUsuarios->crearPersona($documento, $nombrePersona, $correo);
        if (!$idPersona) { colJson(array('error' => true, 'mensaje' => 'Error: no se pudo registrar a la persona.')); break; }
        colGuardarPeajes($modeloUsuarios, $idPersona, $centros);
        colJson(array('error' => false, 'nuevo' => true, 'id' => $idPersona, 'documento' => $documento,
                      'nombre' => $nombrePersona, 'mensaje' => 'Persona registrada con éxito.'));
    break;

    //Le crea el usuario a una persona activa que no tiene, con un rol que no administre
    //(los que administran, como ADMIN TEC, se dan en Usuarios)
    case 'crearUsuario':
        $persona = $idPersona ? $modeloUsuarios->mostrar($idPersona) : null;
        if (!$persona) { echo 'Error: esa persona ya no existe. Actualiza la página.'; break; }
        if ((int)$persona['ESTADO'] !== 1) { echo 'Error: la persona está inactiva. Actívala antes de crearle el usuario.'; break; }
        if (!usuRolSePuedeRegistrar($idRol)) {
            echo 'Error: ese rol no se puede dar aquí. Elige uno de la lista; los roles que administran (como ADMIN TEC) se dan en Usuarios.';
            break;
        }
        echo usuCrearUsuario($modeloUsuarios, $persona, $usuarioNuevo, $idRol);
    break;

    //Desactiva a la persona: deja de salir en las listas y, si tiene usuario, no entra (no se borra)
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

    //-------------------------------------------------------------------------
    // Peajes asignados a la persona
    //-------------------------------------------------------------------------
    //Números de los centros asignados a la persona, separados por coma (para marcar sus casillas)
    case 'centroslista':
        $filas = $modeloUsuarios->centrosAsignados($idPersona);
        $ids = array();
        while ($centro = $filas->fetch_object()) { $ids[] = (int)$centro->ID_CENTRO_OP; }
        echo implode(',', $ids);
    break;

    //Números de todos los centros activos, separados por coma ("Seleccionar todos")
    case 'centroslistatodos':
        $filas = $modeloUsuarios->centrosActivos();
        $ids = array();
        while ($centro = $filas->fetch_object()) { $ids[] = (int)$centro->ID_CENTRO_OP; }
        echo implode(',', $ids);
    break;

    //Nombres de los centros de la persona, uno por renglón (ventana "Peajes asignados")
    case 'MostrarCentros':
        $filas = $modeloUsuarios->nombresCentros($idPersona);
        $nombres = '';
        while ($centro = $filas->fetch_object()) { $nombres .= htmlspecialchars($centro->NOM_CENTRO_OP, ENT_QUOTES, 'UTF-8') . "<br>"; }
        echo $nombres;
    break;

    //Las casillas de los centros para el formulario, agrupadas por proyecto. Los proyectos
    //se reparten en dos columnas, uno en cada una por turnos, para que la lista no quede tan larga
    case 'centros':
        $filas = $modeloUsuarios->centrosActivos();
        $columnas = array(
            '<div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                <label>Peajes asignados:</label><br>
                <SPAN title="Seleccionar todos"><input type="checkbox" name="todosc" id="todosc" onclick="seleccionarTodos()" /></SPAN> <label for="todosc">Seleccionar todos</label><br>',
            '<div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                <br><br>',
        );
        $proyectoAnterior = null;
        $columna = 1; //en qué columna va el proyecto actual (0 o 1); el primero cae en la 0
        while ($centro = $filas->fetch_object()) {
            if ($centro->NOM_PROYECTO !== $proyectoAnterior) {
                $columna = 1 - $columna;
                $columnas[$columna] .= '<div style="background-color: #bcd4db; text-align: center"><label>' . htmlspecialchars($centro->NOM_PROYECTO, ENT_QUOTES, 'UTF-8') . '</label></div>';
                $proyectoAnterior = $centro->NOM_PROYECTO;
            }
            $nombre = htmlspecialchars($centro->NOM_CENTRO_OP, ENT_QUOTES, 'UTF-8');
            $columnas[$columna] .= '<SPAN title="' . $nombre . '"><input type="checkbox" name="permiso[]" id="r' . (int)$centro->ID_CENTRO_OP . '" value="' . (int)$centro->ID_CENTRO_OP . '" /></SPAN>'
                                 . ' <label for="r' . (int)$centro->ID_CENTRO_OP . '">' . $nombre . '</label><br>';
        }
        echo $columnas[0] . "</div>" . $columnas[1] . "</div>";
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
