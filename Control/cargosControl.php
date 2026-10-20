<?php
/*
  Archivo sin uso: la pantalla de Cargos se quitó (script 35; cada colaborador tiene
  su rol). Responde 410 ("Esta dirección ya no se usa.") y no ejecuta nada; el
  código de abajo se conserva solo como referencia.
*/
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('error' => 'Esta dirección ya no se usa.'), JSON_UNESCAPED_UNICODE);
exit;
?>
<?php
/*
  HANA - Cargos (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/cargosAjax.js y responde con lo que
  trae el modelo (Modelo/cargosModelo.php).
  Cada petición llega con ?op=... y cae en uno de los "case" de abajo:
    guardar, mostrar, select, listar y anular.
  Permiso: 4M. El desplegable (select) también lo usa Colaboradores.
*/
session_start();
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
hanaGuardia(array('4M'), array('select')); //Cargos: 4M. El selector lo usa Colaboradores
require_once "../Modelo/cargosModelo.php";//Utilizará este archivo
$cargos=new Cargos();//objeto del modelo: con él se hacen las consultas
//carga las variables con los valores recibidos y limpia los que no se usaran
$IdCargos=isset($_POST["idCargo"])? limpiarCadena($_POST["idCargo"]):"";
$NombreCargos=isset($_POST["nombre"])? limpiarCadena($_POST["nombre"]):"";
//opciones
switch ($_GET["op"])
	{
		case 'guardar'://primer caso
            try {
                    if (empty($IdCargos)) {
                        $rspta=$cargos->insertar($NombreCargos);
                            echo $rspta ? http_response_code(200) : http_response_code(400);
                    } else {
                        $rspta=$cargos->editar($IdCargos, $NombreCargos);
                            echo $rspta ? http_response_code(200) : http_response_code(400);
                    }
            } catch (\Throwable $th) {
                    http_response_code(404);
                    echo json_encode($th);
            }
		break;
	    //Trae un cargo para cargarlo en el formulario
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
		//Opciones del buscador de cargos
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
		case 'listar'://lo pide la tabla de la pantalla al cargar
            try {
                $rspta=$cargos->listar();//trae los registros para la tabla
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
		//Desactiva un cargo
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
