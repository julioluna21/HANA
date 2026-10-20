<?php
/*
  HANA - Roles (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/RolesAjax.js y responde con lo que
  trae el modelo (Modelo/RolesModelo.php).
  Cada petición llega con ?op=... y cae en uno de los "case" de abajo:
    guardar, mostrar, anular, activar, select y listar.
  Un rol es la lista de permisos (1M, 2M, ...) que se guarda separada por comas.
  Permiso: 2M. El desplegable (select) también lo usa Usuarios.

  Reglas que revisa el servidor:
    - El nombre es obligatorio, no se repite y se guarda en mayúsculas (con tildes).
    - Quien edita SU PROPIO rol no puede quitarse la casilla 2M.
    - Un rol que tienen personas activas no se puede desactivar: primero hay que
      cambiarles el rol en Usuarios. (Además, al entrar se pide un rol activo.)
*/
session_start();
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
hanaGuardia(array('2M'), array('select')); //Roles: 2M. El selector lo usa Usuarios
require_once "../Modelo/RolesModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //cómo se guardan los nombres
$modeloRoles = new Roles(); //objeto del modelo: con él se hacen las consultas

//-----------------------------------------------------------------------------
// Lo que llega del formulario
//-----------------------------------------------------------------------------
$idRol = isset($_POST["idrol"]) ? intval($_POST["idrol"]) : 0;
//El nombre, en mayúsculas y en la forma en que se guardan los nombres de catálogo
$nombreRol = isset($_POST["nombre"]) && is_string($_POST["nombre"]) ? hanaTextoGuardado(hanaMayusculas($_POST["nombre"])) : '';
//"Puede cerrar novedades": 1 si viene marcado, 0 si no
$puedeCerrarNovedades = (isset($_POST["auditoria"]) && $_POST["auditoria"] !== '' && $_POST["auditoria"] !== '0') ? 1 : 0;
//Las casillas marcadas: solo códigos válidos (número + M)
$casillas = (isset($_POST["permiso"]) && is_array($_POST["permiso"])) ? $_POST["permiso"] : array();
$casillas = array_values(array_filter($casillas, function ($codigo) { return is_string($codigo) && preg_match('/^\d{1,3}M$/', $codigo); }));

//Quien edita SU PROPIO rol no puede quitarse "Permisos de usuario" (2M): se quedaría
//sin poder volver a esta pantalla para arreglarlo. Se le deja puesta y se le avisa
$avisoPropio = '';
if ($idRol && isset($_SESSION['IdUsuarios'])) {
    require_once __DIR__ . '/../Modelo/AccesoModelo.php';
    $miRol = Acceso::rolDeUsuario((int)$_SESSION['Idcolaborador']);
    if ($miRol && $miRol === $idRol && !in_array('2M', $casillas, true)) {
        $casillas[] = '2M';
        $avisoPropio = "\n\nSe conservó la casilla «Permisos de usuario» (2M): es tu propio rol y sin ella no podrías volver a esta pantalla.";
    }
}
$permisos = implode(',', $casillas); //como se guarda: "1M,2M,15M"

//-----------------------------------------------------------------------------
// Las opciones
//-----------------------------------------------------------------------------
switch (isset($_GET["op"]) ? $_GET["op"] : '')
{
    //Crea o edita un rol
    case 'guardar':
        $problema = hanaProblemaNombre($nombreRol, 50, 'del rol');
        if ($problema !== '') { echo 'Error: ' . $problema; break; }
        if ($modeloRoles->nombreExiste($nombreRol, $idRol)) { echo 'Error: ya hay un rol llamado «' . hanaTextoVisible($nombreRol) . '». Usa otro nombre o edita ese rol.'; break; }
        if (!$idRol) {
            echo $modeloRoles->insertar($nombreRol, $permisos, $puedeCerrarNovedades) ? "Registro guardado con éxito" : "Error no se pudo realizar el registro";
        } else {
            echo $modeloRoles->editar($idRol, $nombreRol, $permisos, $puedeCerrarNovedades) ? "Registro actualizado con éxito" . $avisoPropio : "No se pudo actualizar";
        }
    break;

    //Trae un rol para cargarlo en el formulario. El nombre va como se escribió, para
    //que al guardar de nuevo no se convierta dos veces
    case 'mostrar':
        echo hanaJsonTexto($modeloRoles->mostrar($idRol));
    break;

    //Desactiva un rol, si nadie activo lo tiene
    case 'anular':
        $conElRol = $modeloRoles->personasActivas($idRol);
        if ($conElRol > 0) {
            echo 'Error: no se puede desactivar este rol porque lo ' . ($conElRol === 1 ? 'tiene 1 persona activa' : 'tienen ' . $conElRol . ' personas activas')
               . '. Primero cámbiales el rol en Configuración → Usuarios.';
            break;
        }
        echo $modeloRoles->desactivar($idRol) ? "Registro anulado con éxito" : "No se pudo anular el registro";
    break;

    //Vuelve a activar un rol
    case 'activar':
        echo $modeloRoles->activar($idRol) ? "Registro activado con éxito" : "No se pudo activar el registro";
    break;

    //Las opciones del desplegable de roles (pantalla Usuarios)
    case 'select':
        $filas = $modeloRoles->select();
        echo "<option value=''>Seleccione Rol...</option>";
        while ($rol = $filas->fetch_object()) {
            echo "<option value='$rol->ID_ROL_USUARIO_SISTEMA'>$rol->NOM_ROL_USUARIO_SISTEMA</option>";
        }
    break;

    //La tabla de la pantalla
    case 'listar':
        $filas = $modeloRoles->listar();
        $filasTabla = array();
        while ($rol = $filas->fetch_object()) {
            $idFila = (int)$rol->ID_ROL_USUARIO_SISTEMA;
            $activo = (int)$rol->ESTADO !== 0;
            $botonEditar = '<SPAN title="Editar"><button class="btn btn-success" onclick="mostrar(' . $idFila . ')"><i class="fa fa-eye" style="color:white;"></i></button></SPAN> ';
            $botonEstado = $activo
                ? '<SPAN title="anular"><button type="button" class="btn btn-success" onclick="anular(' . $idFila . ')"><i class="fa fa-trash" style="color:white;"></i></button></SPAN>'
                : '<SPAN title="Activar"><button type="button" class="btn btn-success" onclick="activar(' . $idFila . ')"><i class="fa fa-check" style="color:white;"></i></button></SPAN>';
            $filasTabla[] = array(
                "0" => $rol->NOM_ROL_USUARIO_SISTEMA, //ya viene guardado listo para HTML
                "1" => $activo ? "Activo" : "Inactivo",
                "2" => $botonEditar . $botonEstado,
            );
        }
        echo json_encode(array(
            "sEcho" => 1,                          //datos para DataTables
            "iTotalRecords" => count($filasTabla),
            "iTotalDisplayRecords" => count($filasTabla),
            "aaData" => $filasTabla));
    break;
}
