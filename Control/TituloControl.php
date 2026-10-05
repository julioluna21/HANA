<?php
session_start();//inicia la session, permite guardar variables de sesion
require_once __DIR__ . '/Guardia.php'; //sesión y permisos (antes no se revisaban)
hanaGuardia(array('7M'), array('select')); //Títulos: 7M. El selector lo usan novedades y listas
require_once "../Modelo/TituloModelo.php";//Utilizará este archivo
$Titulo=new Titulo();//crea un nuevo articulo
//carga las variables con los valores recibidos y limpia los que no se usaran
$idestitulo=isset($_POST["idtitulo"])? limpiarCadena($_POST["idtitulo"]):"";
$nombreestado=isset($_POST["nombre"])? limpiarCadena($_POST["nombre"]):"";



//opciones
switch ($_GET["op"])
    {
            case 'guardar'://primer caso
                if (empty($idestitulo)) {
                  
                    $rspta=$Titulo->insertar(strtoupper($nombreestado));
                    echo $rspta? "Registro guardado con éxito": "Error no se pudo realizar el registro"; 
                    
                }else{
                    
                    $rspta=$Titulo->editar($idestitulo,strtoupper($nombreestado));
                            echo $rspta ? "Registro actualizado con éxito" : "No se pudo actualizar";
                }
                            
            break;
            case 'mostrar':
       
                    $rspta=$Titulo->mostrar($idestitulo);
                    //Codificar el resultado utilizando json
                    echo json_encode($rspta);
            break;
            case 'anular':
                       $rspta=$Titulo->desactivar($idestitulo);
                        echo $rspta ? "Registro anulado con éxito" : "No se pudo anular el registro";         
                            
            break;
        
             case 'activar':
                   
                        $rspta=$Titulo->activar($idestitulo);
                        echo $rspta ? "Registro activado con éxito" : "No se pudo activar el registro";        
                            
            break;
        
        
            case 'select':
            $rspta=$Titulo->select();
            echo "<option value=''>Seleccione Titulo...</option>";
           while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
           {
                  echo "<option value='$reg->ID_TITULO_NOVEDADES_HALLAZGOS'>$reg->NOM_TITULO_NOVEDADES_HALLAZGOS</option>";
            
           }
            break;
        
        
   
        
        
            case 'listar'://activado por el ajax en el scrip articulos
        
        
                    $rspta=$Titulo->listar();//Carga la rspta con lista de articulos
                    //Vamos a declarar un array
                    $data= Array();
        
                    while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                    {
                        
                      $bot='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$reg->ID_TITULO_NOVEDADES_HALLAZGOS.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN> <SPAN title="anular">
                    <button type="button" class="btn btn-success" onclick="anular('.$reg->ID_TITULO_NOVEDADES_HALLAZGOS.')">
                    <i class="fa fa-trash" style="color:white;"></i></button></SPAN>';       
                
                        $estado="Activo";
                        if($reg->ESTADO==0){
                        $estado="Inactivo";
                        $bot='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$reg->ID_TITULO_NOVEDADES_HALLAZGOS.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN> <SPAN title="Activar">
                     <button type="button" class="btn btn-success" onclick="activar('.$reg->ID_TITULO_NOVEDADES_HALLAZGOS.')">
                     <i class="fa fa-check" style="color:white;"></i></button></SPAN>';
                            
                        }
                               
                        
                            $data[]=array(//arreglo con los datos de las columnas
                                    "0"=>$reg->NOM_TITULO_NOVEDADES_HALLAZGOS,
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