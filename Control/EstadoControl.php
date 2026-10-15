<?php
/*
  HANA - Estados de prioridad de las novedades (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/EstadoAjax.js y responde con lo que
  trae el modelo (Modelo/EstadosModelo.php).
  Cada petición llega con ?op=... y cae en uno de los "case" de abajo:
    guardar, mostrar, anular, activar, select y listar.
  Permiso: 8M. El desplegable (select) también lo usa Novedades.
*/
session_start();//inicia la session, permite guardar variables de sesion
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
hanaGuardia(array('8M'), array('select')); //Estados: 8M. El selector lo usan novedades
require_once "../Modelo/EstadosModelo.php";//Utilizará este archivo
$Estados=new Estados();//objeto del modelo: con él se hacen las consultas
//carga las variables con los valores recibidos y limpia los que no se usaran
$idestado=isset($_POST["idestado"])? limpiarCadena($_POST["idestado"]):"";
$nombreestado=isset($_POST["nombre"])? limpiarCadena($_POST["nombre"]):"";
//Días para atender una novedad de esta prioridad: un número entero sin signos ni
//decimales (se revisa antes de que llegue a la consulta)
$dias=isset($_POST["dias"])? trim((string)$_POST["dias"]):"";


//opciones
switch ($_GET["op"])
    {
            case 'guardar'://primer caso
                if (!preg_match('/^\d{1,3}$/', $dias) || (int)$dias > 365) {
                    echo "Error: los días deben ser un número entero entre 0 y 365";
                    break;
                }
                if (empty($idestado)) {
                  
                    $rspta=$Estados->insertar(strtoupper($nombreestado),$dias);
                    echo $rspta? "Registro guardado con éxito": "Error no se pudo realizar el registro"; 
                    
                }else{
                    
                    $rspta=$Estados->editar($idestado,strtoupper($nombreestado),$dias);
                            echo $rspta ? "Registro actualizado con éxito" : "No se pudo actualizar";
                }
                            
            break;
            //Trae un estado de prioridad para cargarlo en el formulario
            case 'mostrar':
       
                    $rspta=$Estados->mostrar($idestado);
                    //Codificar el resultado utilizando json
                    echo json_encode($rspta);
            break;
            //Desactiva un estado de prioridad
            case 'anular':
                       $rspta=$Estados->desactivar($idestado);
                        echo $rspta ? "Registro anulado con éxito" : "No se pudo anular el registro";         
                            
            break;
        
             //Vuelve a activar un estado de prioridad
             case 'activar':
                   
                        $rspta=$Estados->activar($idestado);
                        echo $rspta ? "Registro activado con éxito" : "No se pudo activar el registro";        
                            
            break;
        
        
            //Arma las opciones del desplegable de estados de prioridad
            case 'select':
            $rspta=$Estados->select();
            echo "<option value=''>Seleccione Estado de prioridad...</option>";
           while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
           {
                  echo "<option value='$reg->ID_ESTADOS_RELEVANCIA'>$reg->NOMBRE_ESTADOS_RELEVANCIA</option>";
            
           }
            break;
        
        
   
        
        
            case 'listar'://lo pide la tabla de la pantalla al cargar
        
        
                    $rspta=$Estados->listar();//trae los registros para la tabla
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