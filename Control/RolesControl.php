<?php
session_start();//inicia la session, permite guardar variables de sesion
require_once "../Modelo/RolesModelo.php";//Utilizará este archivo
$Roles=new Roles();//crea un nuevo articulo
//carga las variables con los valores recibidos y limpia los que no se usaran
$idroles=isset($_POST["idrol"])? limpiarCadena($_POST["idrol"]):"";
$nombrerol=isset($_POST["nombre"])? limpiarCadena($_POST["nombre"]):"";
$modulos=isset($_POST["permiso"])? $_POST["permiso"]:"";
$auditoria=isset($_POST["auditoria"])? $_POST["auditoria"]:"";

$mod="";
 if($modulos){
        foreach($modulos as $selected){
            if($mod==""){
             $mod=$selected;
            }else{
              $mod=$mod.",".$selected;  
            }
              
         } 
        }


//opciones
switch ($_GET["op"])
    {
            case 'guardar'://primer caso
                if (empty($idroles)) {
                  
                    $rspta=$Roles->insertar(strtoupper($nombrerol),$mod,$auditoria);
                    echo $rspta? "Registro Exisitoso": "Error no se pudo realizar el registro"; 
                    
                }else{
                    
                    $rspta=$Roles->editar($idroles,strtoupper($nombrerol),$mod,$auditoria);
                            echo $rspta ? "Registro actualizado" : "No se pudo actualizar";
                }
                            
            break;
            case 'mostrar':
       
                    $rspta=$Roles->mostrar($idroles);
                    //Codificar el resultado utilizando json
                    echo json_encode($rspta);
            break;
            case 'anular':
                       $rspta=$Roles->desactivar($idroles);
                        echo $rspta ? "anulado exitoso" : "No se pudo anular el registro";         
                            
            break;
        
             case 'activar':
                   
                        $rspta=$Roles->activar($idroles);
                        echo $rspta ? "activado exitoso" : "No se pudo activar el registro";        
                            
            break;
        
        
            case 'select':
            $rspta=$Roles->select();
            echo "<option value=''>Seleccione Rol...</option>";
           while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
           {
                  echo "<option value='$reg->ID_ROL_USUARIO_SISTEMA'>$reg->NOM_ROL_USUARIO_SISTEMA</option>";
            
           }
            break;
        
        
   
        
        
            case 'listar'://activado por el ajax en el scrip articulos
        
        
                    $rspta=$Roles->listar();//Carga la rspta con lista de articulos
                    //Vamos a declarar un array
                    $data= Array();
        
                    while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                    {
                        
                      $bot='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$reg->ID_ROL_USUARIO_SISTEMA.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN> <SPAN title="anular">
                    <button type="button" class="btn btn-success" onclick="anular('.$reg->ID_ROL_USUARIO_SISTEMA.')">
                    <i class="fa fa-trash" style="color:white;"></i></button></SPAN>';       
                
                        $estado="Activo";
                        if($reg->ESTADO==0){
                        $estado="Inactivo";
                        $bot='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$reg->ID_ROL_USUARIO_SISTEMA.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN> <SPAN title="Activar">
                     <button type="button" class="btn btn-success" onclick="activar('.$reg->ID_ROL_USUARIO_SISTEMA.')">
                     <i class="fa fa-check" style="color:white;"></i></button></SPAN>';
                            
                        }
                               
                        
                            $data[]=array(//arreglo con los datos de las columnas
                                    "0"=>$reg->NOM_ROL_USUARIO_SISTEMA,
                                    "1"=>$estado,
                                    "2"=>$bot,
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