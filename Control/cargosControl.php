<?php
session_start();
require_once __DIR__ . '/Guardia.php'; //sesión y permisos (antes no se revisaban)
hanaGuardia(array('4M'), array('select')); //Cargos: 4M. El selector lo usa Colaboradores
require_once "../Modelo/cargosModelo.php";//Utilizará este archivo
$cargos=new Cargos();//crea un nuevo articulo
//carga las variables con los valores recibidos y limpia los que no se usaran
$IdCargos=isset($_POST["idCargo"])? limpiarCadena($_POST["idCargo"]):"";
$NombreCargos=isset($_POST["nombre"])? limpiarCadena($_POST["nombre"]):"";
$PermisoNovedad=isset($_POST["permisoNovedad"])? limpiarCadena($_POST["permisoNovedad"]):"";
//opciones
switch ($_GET["op"])
	{
		case 'guardar'://primer caso
            try {
                    if (empty($IdCargos)) {
                        $rspta=$cargos->insertar($NombreCargos,$PermisoNovedad);
                            echo $rspta ? http_response_code(200) : http_response_code(400);
                    } else {
                        $rspta=$cargos->editar($IdCargos, $NombreCargos, $PermisoNovedad);
                            echo $rspta ? http_response_code(200) : http_response_code(400);
                    }
            } catch (\Throwable $th) {
                    http_response_code(404);
                    echo json_encode($th);
            }
		break;
	    case 'mostrar':
            try {
                $rspta=$cargos->mostrar($IdCargos);
                $rspta? http_response_code(200):http_response_code(400);
                echo json_encode($rspta);   
            } catch (\Throwable $th) {
                http_response_code(404);
                echo json_encode($th);
            }
	 	break;
		case "select":
            $search_term = isset($_GET['search'])? $_GET['search']:0;
            try {
                $rspta=$cargos->select($search_term);
                   $usersData = array();  
                    if($rspta->num_rows > 0){  
                        while($row = $rspta->fetch_assoc()){  
                            $data['id'] = $row['ID_CARGO_COLABORADORES'];  
                            $data['text'] = $row['NOM_CARGO_COLABORADORES'];  
                            array_push($usersData, $data);  
                        }  
                    }                      
                    // Return results as json encoded array  
                    echo json_encode($usersData); 
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
		break;
		case 'listar'://activado por el ajax en el scrip articulos
            try {
                $rspta=$cargos->listar();//Carga la rspta con lista de articulos
                //Vamos a declarar un array
                $data= Array();
                while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                {                    
                    $bot='<SPAN title="Editar">
                              <button class="btn btn-success" onclick="mostrar('.$reg->ID_CARGO_COLABORADORES.')">
                              <i class="fa fa-eye"></i></button></SPAN>
                 <SPAN title="anular">
     <button type="button" class="btn btn-success" onclick="anular('.$reg->ID_CARGO_COLABORADORES.')">
     <i class="fa fa-trash-o"></i></button></SPAN>';
                    //var_dump($reg->IdCargos);
                    $data[]=array(//arreglo con los datos de las columnas
                        "0"=>$reg->ID_CARGO_COLABORADORES,
                        "1"=>$reg->NOM_CARGO_COLABORADORES,
                        "2"=>($reg->ESTADO == 1)?"Activo":"Inactivo",
                        "3"=>$bot
                        );
                }
                $results = array(//variable con el resultado del arreglo
                    "sEcho"=>1, //Información para el datatables
                    "iTotalRecords"=>count($data), //enviamos el total registros al datatable
                    "iTotalDisplayRecords"=>count($data), //enviamos el total registros a visualizar
                    "aaData"=>$data);
                    echo json_encode($results);//muestra el resultado
                 } catch (\Throwable $th) {
                http_response_code(404);
                echo json_encode($th);
            }
		break;
		case 'anular':
            try {
                $rspta=$cargos->desactivar($IdCargos);
                $rspta? http_response_code(200):http_response_code(400);
                echo $rspta;
            } catch (\Throwable $th) {
                http_response_code(404);
                echo $th;
            }
		break;
	}
