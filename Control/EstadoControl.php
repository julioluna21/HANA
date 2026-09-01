<?php
session_start();//inicia la session, permite guardar variables de sesion
require_once "../Modelo/EstadosModelo.php";//Utilizará este archivo
$Estados=new Estados();//crea un nuevo articulo
//carga las variables con los valores recibidos y limpia los que no se usaran
$idestado=isset($_POST["idestado"])? limpiarCadena($_POST["idestado"]):"";
$nombreestado=isset($_POST["nombre"])? limpiarCadena($_POST["nombre"]):"";
$dias=isset($_POST["dias"])? $_POST["dias"]:"";


//opciones
switch ($_GET["op"])
    {
            case 'guardar'://primer caso
                if (empty($idestado)) {
                  
                    $rspta=$Estados->insertar(strtoupper($nombreestado),$dias);
                    echo $rspta? "Registro Exisitoso": "Error no se pudo realizar el registro"; 
                    
                }else{
                    
                    $rspta=$Estados->editar($idestado,strtoupper($nombreestado),$dias);
                            echo $rspta ? "Registro actualizado" : "No se pudo actualizar";
                }
                            
            break;
            case 'mostrar':
       
                    $rspta=$Estados->mostrar($idestado);
                    //Codificar el resultado utilizando json
                    echo json_encode($rspta);
            break;
            case 'anular':
                       $rspta=$Estados->desactivar($idestado);
                        echo $rspta ? "anulado exitoso" : "No se pudo anular el registro";         
                            
            break;
        
             case 'activar':
                   
                        $rspta=$Estados->activar($idestado);
                        echo $rspta ? "activado exitoso" : "No se pudo activar el registro";        
                            
            break;
        
        
            case 'select':
            $rspta=$Estados->select();
            echo "<option value=''>Seleccione Estado de prioridad...</option>";
           while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
           {
                  echo "<option value='$reg->ID_ESTADOS_RELEVANCIA'>$reg->NOMBRE_ESTADOS_RELEVANCIA</option>";
            
           }
            break;
        
        
   
        
        
            case 'listar'://activado por el ajax en el scrip articulos
        
        
                    $rspta=$Estados->listar();//Carga la rspta con lista de articulos
                    //Vamos a declarar un array
                    $data= Array();
        
                    while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                    {
                        
                      $bot='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$reg->ID_ESTADOS_RELEVANCIA.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN> <SPAN title="anular">
                    <button type="button" class="btn btn-success" onclick="anular('.$reg->ID_ESTADOS_RELEVANCIA.')">
                    <i class="fa fa-trash" style="color:white;"></i></button></SPAN>';       
                
                        $estado="Activo";
                        if($reg->ESTADO==0){
                        $estado="Inactivo";
                        $bot='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$reg->ID_ESTADOS_RELEVANCIA.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN> <SPAN title="Activar">
                     <button type="button" class="btn btn-success" onclick="activar('.$reg->ID_ESTADOS_RELEVANCIA.')">
                     <i class="fa fa-check" style="color:white;"></i></button></SPAN>';
                            
                        }
                               
                        
                            $data[]=array(//arreglo con los datos de las columnas
                                    "0"=>$reg->NOMBRE_ESTADOS_RELEVANCIA,
                                    "1"=>$reg->DIAS_ESTADOS_RELEVANCIAS." días",
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
            break;
          
    }