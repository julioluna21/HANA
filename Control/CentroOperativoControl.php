<?php
session_start(); //inicia la session, permite guardar variables de sesion
require_once "../Modelo/CentroOperativoModelo.php"; //Utilizará este archivo
$Centro = new Centro(); //crea un nuevo articulo
//carga las variables con los valores recibidos y limpia los que no se usaran
$idcentro = isset($_POST["idcentro"]) ? limpiarCadena($_POST["idcentro"]) : "";
$nombrecentro = isset($_POST["nombre"]) ? limpiarCadena($_POST["nombre"]) : "";
$proyecto = isset($_POST["selectProyecto"]) ? limpiarCadena($_POST["selectProyecto"]) : "";
$tipo = isset($_POST["tipo"]) ? strtoupper(trim($_POST["tipo"])) : "PEAJE";
$jefe = isset($_POST["jefe"]) ? intval($_POST["jefe"]) : 0;

/*
  Quién puede usar cada operación (Fase 2).
    - Sin sesión, nada.
    - Crear, editar, anular, ver y listar centros: permiso 5M.
    - La lista de centros (select) la usan otras pantallas: basta con la sesión.
*/
$op = isset($_GET["op"]) ? $_GET["op"] : "";
if (!isset($_SESSION['IdUsuarios'])) { http_response_code(401); echo "Tu sesión terminó. Vuelve a iniciar sesión."; exit(); }
$modulosCentro = explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : '');
if (in_array($op, array('guardar', 'mostrar', 'anular', 'listar'), true) && !in_array('5M', $modulosCentro)) {
        http_response_code(403); echo "No permitido: tu rol no administra centros de operación."; exit();
}
if ($op === 'guardar' && $jefe > 0) {
        $existe = ejecutarConsultaSimpleFila("SELECT 1 AS ok FROM colaboradores WHERE ID_COLABORADOR = $jefe AND ESTADO = 1");
        if (!$existe) { http_response_code(400); echo "El jefe elegido no existe o está inactivo."; exit(); }
}

//opciones
switch ($op) {
        case 'guardar': //primer caso
                try {
                        //El proyecto es obligatorio en la base (int NOT NULL).
                        //Si llega vacio se avisa, en vez de fallar callado.
                        if (empty($proyecto)) {
                                http_response_code(400);
                                echo "Debes seleccionar un proyecto.";
                                exit();
                        }
                        if (empty($idcentro)) {
                                $rspta = $Centro->insertar($nombrecentro, $proyecto, $tipo, $jefe);
                                echo $rspta ? http_response_code(200) : http_response_code(400);
                        } else {
                                $rspta = $Centro->editar($idcentro, $nombrecentro, $proyecto, $tipo, $jefe);
                                echo $rspta ? http_response_code(200) : http_response_code(400);
                        }
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                break;
        case 'mostrar':
                try {
                        $rspta = $Centro->mostrar($idcentro);
                        $rspta ? http_response_code(200) : http_response_code(400);
                        echo json_encode($rspta);
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                break;
        case 'anular':
                try {
                        $rspta = $Centro->anular($idcentro);
                        echo $rspta ? http_response_code(200) : http_response_code(400);
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                break;
        case 'select':
                try {
                        $rspta = $Centro->select();
                        echo "<option value=''>Seleccione ...</option>";
                        while ($reg = $rspta->fetch_object()) //mientras exista objeto en la respuesta
                        {
                                echo "<option value='$reg->ID_CENTRO_OP'>$reg->NOM_CENTRO_OP</option>";
                        }
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                break;
        case 'listar': //activado por el ajax en el scrip articulos
                $rspta = $Centro->listar(); //Carga la rspta con lista de articulos
                //Vamos a declarar un array
                $data = array();
                while ($reg = $rspta->fetch_object()) //mientras exista objeto en la respuesta
                {
                        $bot = '<SPAN title="Editar">
                        <button class="btn btn-success" onclick="mostrar('. $reg->ID_CENTRO_OP .')">
                        <i class="fa fa-eye"></i></button></SPAN>
           <SPAN title="anular">
<button type="button" class="btn btn-success" onclick="anular('. $reg->ID_CENTRO_OP .')">
<i class="fa fa-trash-o"></i></button></SPAN>';
                        $estado = "Activo";
                        if ($reg->Estado == 0) {$estado = "Inactivo";}
                        /*if ($reg->ESTADO_CENTRO_OPERATIVO == 0) {
                                $estado = "Inactivo";
                                $bot = '<SPAN title="Editar"><button class="btn btn-secondary" onclick="mostrar(' . $reg->ID_CENTRO_OP . ')"> <i class="fas fa-eye" style="color:white;"></i>
                                        </button></SPAN> <SPAN title="Activar"><button type="button" class="btn btn-primary" onclick="activar(' . $reg->ID_CENTRO_OP . ')"><i class="fas fa-check" style="color:white;"></i></button></SPAN>';
                        }*/
                        $data[] = array( //arreglo con los datos de las columnas
                                "0" => $reg->ID_CENTRO_OP,
                                "1" => $reg->NOM_CENTRO_OP,
                                "2" => $reg->NOM_PROYECTO,
                                "3" => isset(Centro::$TIPOS[$reg->TIPO_CENTRO]) ? Centro::$TIPOS[$reg->TIPO_CENTRO] : $reg->TIPO_CENTRO,
                                "4" => $reg->JEFE ? $reg->JEFE : '<span style="color:#8A5B0B;">Sin asignar</span>',
                                "5" => $estado,
                                "6" => $bot
                        );
                }
                $results = array( //variable con el resultado del arreglo
                        "sEcho" => 1, //Información para el datatables
                        "iTotalRecords" => count($data), //enviamos el total registros al datatable
                        "iTotalDisplayRecords" => count($data), //enviamos el total registros a visualizar
                        "aaData" => $data
                );
                echo json_encode($results); //muestra el resultado
                break;
}