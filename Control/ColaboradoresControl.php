<?php
session_start(); //inicia la session, permite guardar variables de sesion
require_once "../Modelo/colaboradoresModelo.php"; //Utilizará este archivo
$colaborador = new colaborador(); //crea un nuevo articulo
//carga las variables con los valores recibidos y limpia los que no se usaran
$idColaborador = isset($_POST["idColaborador"]) ? limpiarCadena($_POST["idColaborador"]) : "";
$documento = isset($_POST["documento"]) ? limpiarCadena(($_POST["documento"])) : "";
$nombre = isset($_POST["nombre"]) ? limpiarCadena($_POST["nombre"]) : "";
$email = isset($_POST["email"]) ? limpiarCadena($_POST["email"]):"" ;
$cargo = isset($_POST["selectCargo"]) ? limpiarCadena($_POST["selectCargo"]) : "";
//opciones
switch ($_GET["op"]) {
        case 'guardar': //primer caso
                try {
                        if (empty($idColaborador)) {
                                $rspta = $colaborador->insertar($documento, $nombre, $email, $cargo);
                                echo $rspta ? http_response_code(200) : http_response_code(400);
                        } else {
                                $rspta = $colaborador->editar($idColaborador, $documento, $nombre, $email, $cargo);
                                echo $rspta ? http_response_code(200) : http_response_code(400);
                        }
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                break;
        case 'mostrar':
                try {
                        $rspta = $colaborador->mostrar($idColaborador);
                        $rspta ? http_response_code(200) : http_response_code(400);
                        echo json_encode($rspta);
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                break;
        case 'anular':
                try {
                        $rspta = $colaborador->anular($idColaborador);
                        echo $rspta ? http_response_code(200) : http_response_code(400);
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                break;
        case 'select':
                try {
                        $rspta = $colaborador->select();
                        echo "<option value=''>Seleccione ...</option>";
                        while ($reg = $rspta->fetch_object()) //mientras exista objeto en la respuesta
                        {
                                echo "<option value='$reg->ID_COLABORADOR'>$reg->NOM_COLABORADOR</option>";
                        }
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                break;
        case 'listar': //activado por el ajax en el scrip articulos
                $rspta = $colaborador->listar(); //Carga la rspta con lista de articulos
                //Vamos a declarar un array
                $data = array();
                while ($reg = $rspta->fetch_object()) //mientras exista objeto en la respuesta
                {
                        
                        $data[] = array( //arreglo con los datos de las columnas
                                "0" => $reg->ID_COLABORADOR,
                                "1" => $reg->DOC_COLABORADO,
                                "2" => $reg->NOM_COLABORADOR,
                                "3" => $reg->MAIL_COLABORADOR,
                                "4" => $reg->cargo,
                                "5" => ($reg->ESTADO == 1)?'Activo':'Inactivo',
                                "6" => '<SPAN title="Editar">
                                <button class="btn btn-success" onclick="mostrar('. $reg->ID_COLABORADOR .')">
                                <i class="fa fa-eye"></i></button></SPAN>
                   <SPAN title="anular">
        <button type="button" class="btn btn-success" onclick="anular('. $reg->ID_COLABORADOR .')">
        <i class="fa fa-trash-o"></i></button></SPAN>'
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
