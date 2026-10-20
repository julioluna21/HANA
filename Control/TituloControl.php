<?php
/*
  HANA - Títulos de las novedades (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/TituloAjax.js y responde con lo que
  trae el modelo (Modelo/TituloModelo.php).
  Cada petición llega con ?op=... y cae en uno de los "case" de abajo:
    guardar, mostrar, anular, activar, select y listar.
  Permiso: 7M. El desplegable (select) también lo usan Novedades y Listas.

  Reglas que revisa el servidor al guardar: el nombre es obligatorio, no se repite y
  se guarda en mayúsculas respetando tildes y eñes («Daño en vía» → «DAÑO EN VÍA»).
*/
session_start();
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
hanaGuardia(array('7M'), array('select')); //Títulos: 7M. El selector lo usan las novedades
require_once "../Modelo/TituloModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //cómo se guardan y se muestran los nombres
$Titulo = new Titulo(); //objeto del modelo: con él se hacen las consultas

//Lo que llega del formulario
$idTitulo = isset($_POST["idtitulo"]) ? intval($_POST["idtitulo"]) : 0;
//El nombre, en mayúsculas y en la forma en que se guardan los nombres de catálogo
$nombre = isset($_POST["nombre"]) && is_string($_POST["nombre"]) ? hanaTextoGuardado(hanaMayusculas($_POST["nombre"])) : '';

switch (isset($_GET["op"]) ? $_GET["op"] : '')
{
    //Crea o edita un título
    case 'guardar':
        $problema = hanaProblemaNombre($nombre, 200, 'del título');
        if ($problema !== '') { echo 'Error: ' . $problema; break; }
        if ($Titulo->nombreExiste($nombre, $idTitulo)) { echo 'Error: ya hay un título llamado «' . hanaTextoVisible($nombre) . '». Usa otro nombre o edita ese título.'; break; }
        if (!$idTitulo) {
            echo $Titulo->insertar($nombre) ? "Registro guardado con éxito" : "Error no se pudo realizar el registro";
        } else {
            echo $Titulo->editar($idTitulo, $nombre) ? "Registro actualizado con éxito" : "No se pudo actualizar";
        }
    break;

    //Trae un título para cargarlo en el formulario. El nombre va como se escribió, para
    //que al guardar de nuevo no se convierta dos veces
    case 'mostrar':
        echo hanaJsonTexto($Titulo->mostrar($idTitulo));
    break;

    //Desactiva un título
    case 'anular':
        echo $Titulo->desactivar($idTitulo) ? "Registro anulado con éxito" : "No se pudo anular el registro";
    break;

    //Vuelve a activar un título
    case 'activar':
        echo $Titulo->activar($idTitulo) ? "Registro activado con éxito" : "No se pudo activar el registro";
    break;

    //Las opciones del desplegable de títulos (Novedades y Listas)
    case 'select':
        $filas = $Titulo->select();
        echo "<option value=''>Seleccione Titulo...</option>";
        while ($registro = $filas->fetch_object()) {
            echo "<option value='$registro->ID_TITULO_NOVEDADES_HALLAZGOS'>$registro->NOM_TITULO_NOVEDADES_HALLAZGOS</option>"; //ya viene guardado listo para HTML
        }
    break;

    //La tabla de la pantalla
    case 'listar':
        $filas = $Titulo->listar();
        $filasTabla = array();
        while ($registro = $filas->fetch_object()) {
            $id = (int)$registro->ID_TITULO_NOVEDADES_HALLAZGOS;
            $activo = (int)$registro->ESTADO !== 0;
            $botonEditar = '<SPAN title="Editar"><button class="btn btn-success" onclick="mostrar(' . $id . ')"><i class="fa fa-eye" style="color:white;"></i></button></SPAN> ';
            $botonEstado = $activo
                ? '<SPAN title="anular"><button type="button" class="btn btn-success" onclick="anular(' . $id . ')"><i class="fa fa-trash" style="color:white;"></i></button></SPAN>'
                : '<SPAN title="Activar"><button type="button" class="btn btn-success" onclick="activar(' . $id . ')"><i class="fa fa-check" style="color:white;"></i></button></SPAN>';
            $filasTabla[] = array(
                "0" => $registro->NOM_TITULO_NOVEDADES_HALLAZGOS, //ya viene guardado listo para HTML
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
