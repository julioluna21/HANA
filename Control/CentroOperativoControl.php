<?php
/*
  HANA - Centros de operación (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/CentroOperativoAjax.js y responde con lo que trae
  el modelo (Modelo/CentroOperativoModelo.php). Cada petición llega con ?op=...:
    guardar, mostrar, anular y listar   la pantalla Centros de operación (permiso 5M)
    select                              desplegable de centros activos (otras pantallas;
                                        solo pide sesión)
  Un centro es un peaje, una báscula, una base o una oficina de un proyecto.

  Reglas que revisa el servidor al guardar:
    - El nombre es obligatorio y no se repite entre los centros activos.
      (un centro sin nombre dañaría el Excel del ausentismo de su proyecto).
    - El proyecto es obligatorio y tiene que estar activo.
    - El jefe, si se escoge, tiene que existir y estar activo.
  Si algo no se cumple responde 400 con el motivo, y la pantalla lo muestra.
*/
session_start();
require_once "../Modelo/CentroOperativoModelo.php";
require_once __DIR__ . "/../Modelo/OrganizacionModelo.php"; //¿el jefe elegido existe y está activo?
require_once __DIR__ . '/../Modelo/HanaTexto.php';          //cómo se guardan y se muestran los nombres
$Centro = new Centro(); //objeto del modelo: con él se hacen las consultas

//Lo que llega del formulario
$idCentro   = isset($_POST["idcentro"]) ? intval($_POST["idcentro"]) : 0;
$nombre     = isset($_POST["nombre"]) && is_string($_POST["nombre"]) ? hanaTextoGuardado($_POST["nombre"]) : '';
$idProyecto = isset($_POST["selectProyecto"]) ? intval($_POST["selectProyecto"]) : 0;
$tipo       = isset($_POST["tipo"]) && is_string($_POST["tipo"]) ? strtoupper(trim($_POST["tipo"])) : "PEAJE"; //PEAJE, BASCULA, BASE u OFICINA
$jefe       = isset($_POST["jefe"]) ? intval($_POST["jefe"]) : 0;

//Quién puede qué
$op = isset($_GET["op"]) ? $_GET["op"] : "";
if (!isset($_SESSION['IdUsuarios'])) { http_response_code(401); echo "Tu sesión terminó. Vuelve a iniciar sesión."; exit(); }
$misCasillas = explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : '');
if (in_array($op, array('guardar', 'mostrar', 'anular', 'listar'), true) && !in_array('5M', $misCasillas, true)) {
    http_response_code(403); echo "No permitido: tu rol no administra centros de operación."; exit();
}

//Corta con un 400 y el motivo (la pantalla lo muestra con hanaErrorAjax)
function centroError($mensaje)
{
    http_response_code(400);
    echo $mensaje;
    exit();
}

switch ($op)
{
    //Crea o edita un centro
    case 'guardar':
        $problema = hanaProblemaNombre($nombre, 50, 'del centro');
        if ($problema !== '') { centroError($problema); }
        if ($idProyecto <= 0) { centroError("Debes seleccionar un proyecto."); }
        if (!$Centro->proyectoActivo($idProyecto)) { centroError("El proyecto elegido no existe o está inactivo."); }
        if ($jefe > 0 && !Organizacion::colaboradorActivo($jefe)) { centroError("El jefe elegido no existe o está inactivo."); }
        //El nombre no se repite. Si se edita sin cambiarle el nombre no se revisa, por si ya
        //había dos iguales de antes (así se puede corregir uno de ellos)
        $actual = $idCentro ? $Centro->mostrar($idCentro) : null;
        $cambiaNombre = !$actual || (string)$actual['NOM_CENTRO_OP'] !== $nombre;
        $otroProyecto = $cambiaNombre ? $Centro->proyectoConEseNombre($nombre, $idCentro) : null;
        if ($otroProyecto !== null) {
            centroError('Ya hay un centro activo llamado «' . hanaTextoVisible($nombre) . '» (en ' . hanaTextoVisible($otroProyecto) . '). Usa otro nombre.');
        }
        $ok = $idCentro ? $Centro->editar($idCentro, $nombre, $idProyecto, $tipo, $jefe) : $Centro->insertar($nombre, $idProyecto, $tipo, $jefe);
        if (!$ok) { centroError('No se pudo guardar el centro.'); }
        echo 'Registro guardado con éxito';
    break;

    //Trae un centro para cargarlo en el formulario. Los nombres van como se escribieron,
    //para que al guardar de nuevo no se conviertan dos veces
    case 'mostrar':
        $fila = $Centro->mostrar($idCentro);
        if (!$fila) { http_response_code(400); }
        echo hanaJsonTexto($fila);
    break;

    //Desactiva un centro (no se borra)
    case 'anular':
        if (!$Centro->anular($idCentro)) { centroError('No se pudo anular el centro.'); }
        echo 'Registro anulado con éxito';
    break;

    //Las opciones del desplegable de centros activos
    case 'select':
        $filas = $Centro->select();
        echo "<option value=''>Seleccione ...</option>";
        while ($registro = $filas->fetch_object()) {
            echo "<option value='$registro->ID_CENTRO_OP'>$registro->NOM_CENTRO_OP</option>"; //ya viene guardado listo para HTML
        }
    break;

    //La tabla de la pantalla
    case 'listar':
        $filas = $Centro->listar();
        $filasTabla = array();
        while ($registro = $filas->fetch_object()) {
            $id = (int)$registro->ID_CENTRO_OP;
            $botones = '<SPAN title="Editar"><button class="btn btn-success" onclick="mostrar(' . $id . ')"><i class="fa fa-eye"></i></button></SPAN> '
                     . '<SPAN title="anular"><button type="button" class="btn btn-success" onclick="anular(' . $id . ')"><i class="fa fa-trash-o"></i></button></SPAN>';
            $filasTabla[] = array(
                "0" => $id,
                "1" => $registro->NOM_CENTRO_OP, //ya vienen guardados listos para HTML
                "2" => $registro->NOM_PROYECTO,
                "3" => isset(Centro::$TIPOS[$registro->TIPO_CENTRO]) ? Centro::$TIPOS[$registro->TIPO_CENTRO] : htmlspecialchars((string)$registro->TIPO_CENTRO, ENT_QUOTES, 'UTF-8'),
                "4" => $registro->JEFE ? htmlspecialchars(hanaTextoVisible($registro->JEFE), ENT_QUOTES, 'UTF-8') : '<span style="color:#8A5B0B;">Sin asignar</span>',
                "5" => (int)$registro->ESTADO === 0 ? "Inactivo" : "Activo",
                "6" => $botones,
            );
        }
        echo json_encode(array(
            "sEcho" => 1,                          //datos para DataTables
            "iTotalRecords" => count($filasTabla),
            "iTotalDisplayRecords" => count($filasTabla),
            "aaData" => $filasTabla));
    break;
}
