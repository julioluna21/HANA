<?php
/*
  HANA - Proyectos (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/ProyectoAjax.js y responde con lo que trae el
  modelo (Modelo/ProyectoModelo.php). Cada petición llega con ?op=...:
    guardar, mostrar, anular y listar   la pantalla Proyectos (permiso 6M)
    colaboradores                       personas activas para elegir coordinador
                                        (también la usan Centros y Cronograma)
    select                              el buscador de proyectos (Centros)
  Las dos últimas solo piden haber iniciado sesión.

  Reglas que revisa el servidor al guardar:
    - El nombre es obligatorio y no se repite entre los proyectos activos.
      (un proyecto sin nombre dañaría los Excel del ausentismo y de la promesa de valor).
    - El coordinador, si se escoge, tiene que existir y estar activo.
  Si algo no se cumple responde 400 con el motivo, y la pantalla lo muestra.
*/
session_start();
require_once "../Modelo/ProyectoModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //cómo se guardan y se muestran los nombres
$Proyecto = new Proyecto(); //objeto del modelo: con él se hacen las consultas

//Lo que llega del formulario
$idProyecto  = isset($_POST["idProyecto"]) ? intval($_POST["idProyecto"]) : 0;
$nombre      = isset($_POST["nombre"]) && is_string($_POST["nombre"]) ? hanaTextoGuardado($_POST["nombre"]) : '';
$coordinador = isset($_POST["coordinador"]) ? intval($_POST["coordinador"]) : 0;

//Quién puede qué
$op = isset($_GET["op"]) ? $_GET["op"] : "";
if (!isset($_SESSION['IdUsuarios'])) { http_response_code(401); echo "Tu sesión terminó. Vuelve a iniciar sesión."; exit(); }
$misCasillas = explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : '');
if (in_array($op, array('guardar', 'mostrar', 'anular', 'listar'), true) && !in_array('6M', $misCasillas, true)) {
    http_response_code(403); echo "No permitido: tu rol no administra proyectos."; exit();
}

//Corta con un 400 y el motivo (la pantalla lo muestra con hanaErrorAjax)
function proyError($mensaje)
{
    http_response_code(400);
    echo $mensaje;
    exit();
}

switch ($op)
{
    //Crea o edita un proyecto
    case 'guardar':
        $problema = hanaProblemaNombre($nombre, 50, 'del proyecto');
        if ($problema !== '') { proyError($problema); }
        if ($coordinador > 0 && !$Proyecto->colaboradorValido($coordinador)) { proyError("El coordinador elegido no existe o está inactivo."); }
        //El nombre no se repite. Si se edita sin cambiarle el nombre no se revisa, por si ya
        //había dos iguales de antes (así se puede corregir uno de ellos)
        $actual = $idProyecto ? $Proyecto->mostrar($idProyecto) : null;
        $cambiaNombre = !$actual || (string)$actual['NOM_PROYECTO'] !== $nombre;
        if ($cambiaNombre && $Proyecto->nombreExiste($nombre, $idProyecto)) {
            proyError('Ya hay un proyecto activo llamado «' . hanaTextoVisible($nombre) . '». Usa otro nombre.');
        }
        $ok = $idProyecto ? $Proyecto->editar($idProyecto, $nombre, $coordinador) : $Proyecto->insertar($nombre, $coordinador);
        if (!$ok) { proyError('No se pudo guardar el proyecto.'); }
        echo 'Registro guardado con éxito';
    break;

    //Trae un proyecto para cargarlo en el formulario. El nombre va como se escribió,
    //para que al guardar de nuevo no se convierta dos veces
    case 'mostrar':
        $fila = $Proyecto->mostrar($idProyecto);
        if (!$fila) { http_response_code(400); }
        echo hanaJsonTexto($fila);
    break;

    //Personas activas con su rol, para el selector de coordinador (y de jefe de centro)
    case 'colaboradores':
        $lista = array();
        $filas = $Proyecto->colaboradores();
        while ($filas && ($fila = $filas->fetch_assoc())) {
            $lista[] = array('id' => (int)$fila['id'], 'nombre' => $fila['nombre'], 'rol' => (string)$fila['rol']);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo hanaJsonTexto($lista); //la pantalla los pinta como texto
    break;

    //Desactiva un proyecto (no se borra)
    case 'anular':
        if (!$Proyecto->anular($idProyecto)) { proyError('No se pudo anular el proyecto.'); }
        echo 'Registro anulado con éxito';
    break;

    //Opciones del buscador de proyectos (select2 las pinta como texto)
    case 'select':
        $loQueSeEscribio = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
        $opciones = array();
        foreach ($Proyecto->buscar($loQueSeEscribio) as $proyecto) {
            $opciones[] = array('id' => $proyecto['ID_PROYECTO'], 'text' => $proyecto['NOM_PROYECTO']);
        }
        echo hanaJsonTexto($opciones);
    break;

    //La tabla de la pantalla
    case 'listar':
        $filas = $Proyecto->listar();
        $filasTabla = array();
        while ($registro = $filas->fetch_object()) {
            $id = (int)$registro->ID_PROYECTO;
            $botones = '<SPAN title="Editar"><button class="btn btn-success" onclick="mostrar(' . $id . ')"><i class="fa fa-eye"></i></button></SPAN> '
                     . '<SPAN title="anular"><button type="button" class="btn btn-success" onclick="anular(' . $id . ')"><i class="fa fa-trash-o"></i></button></SPAN>';
            $filasTabla[] = array(
                "0" => $id,
                "1" => $registro->NOM_PROYECTO, //ya viene guardado listo para HTML
                "2" => $registro->COORDINADOR ? htmlspecialchars(hanaTextoVisible($registro->COORDINADOR), ENT_QUOTES, 'UTF-8') : '<span style="color:#8A5B0B;">Sin asignar</span>',
                "3" => (int)$registro->ESTADO === 0 ? "Inactivo" : "Activo",
                "4" => $botones,
            );
        }
        echo json_encode(array(
            "sEcho" => 1,                          //datos para DataTables
            "iTotalRecords" => count($filasTabla),
            "iTotalDisplayRecords" => count($filasTabla),
            "aaData" => $filasTabla));
    break;
}
