<?php
session_start();//inicia la session, permite guardar variables de sesion
require_once "../Modelo/ProyectoModelo.php";//Utilizará este archivo
$Proyecto=new Proyecto();//crea un nuevo articulo
//carga las variables con los valores recibidos y limpia los que no se usaran
$idProyecto=isset($_POST["idProyecto"])? limpiarCadena($_POST["idProyecto"]):"";
$nombreproyecto=isset($_POST["nombre"])? limpiarCadena($_POST["nombre"]):"";
//opciones
switch ($_GET["op"])
    {
            case 'guardar'://primer caso
                try {
                        if (empty($idProyecto)) {
                          
                            $rspta=$Proyecto->insertar($nombreproyecto);
                            echo $rspta ? http_response_code(200): http_response_code(400); 
                            
                        }else{
                            
                            $rspta=$Proyecto->editar($idProyecto, $nombreproyecto);
                        echo $rspta ? http_response_code(200) : http_response_code(400);
                        }
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                            
            break;
            case 'mostrar':
                try {
                        $rspta=$Proyecto->mostrar($idProyecto);
                        $rspta ? http_response_code(200):http_response_code(400);
                        echo json_encode($rspta);
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
            break;
            case 'anular':
                       $rspta=$Proyecto->anular($idProyecto);
                        echo $rspta ? http_response_code(200):http_response_code(400);        
                            
            break;        
        
            case 'select':
                $search_term = isset($_GET['search'])? $_GET['search']:0;
                try {
                        $rspta=$Proyecto->select($search_term);
                       $usersData = array();  
                        if($rspta->num_rows > 0){  
                            while($row = $rspta->fetch_assoc()){  
                                $data['id'] = $row['ID_PROYECTO'];  
                                $data['text'] = $row['NOM_PROYECTO'];  
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
                        $rspta=$Proyecto->listar();//Carga la rspta con lista de articulos
                        //Vamos a declarar un array
                        $data= Array();
            
                        while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                        {
                            
                          $bot='<SPAN title="Editar">
                          <button class="btn btn-success" onclick="mostrar('.$reg->ID_PROYECTO.')">
                          <i class="fa fa-eye"></i></button></SPAN>
             <SPAN title="anular">
 <button type="button" class="btn btn-success" onclick="anular('.$reg->ID_PROYECTO.')">
 <i class="fa fa-trash-o"></i></button></SPAN>';       
                    
                            $estado="Activo";
                            if($reg->Estado==0){
                            $estado="Inactivo";
                           /* $bot='<SPAN title="Editar">
                                                <button class="btn btn-secondary" onclick="mostrar('.$reg->ID_PROYECTO.')">
                                                        <i class="fas fa-eye" style="color:white;"></i></button></SPAN> <SPAN title="Activar">
                         <button type="button" class="btn btn-primary" onclick="activar('.$reg->ID_PROYECTO.')">
                         <i class="fas fa-check" style="color:white;"></i></button></SPAN>';*/
                                
                            }
                                   
                            
                                $data[]=array(//arreglo con los datos de las columnas
                                        "0"=>$reg->ID_PROYECTO,
                                        "1"=>$reg->NOM_PROYECTO,
                                        "2"=>$estado,
                                        "3"=>$bot,
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
          
    }