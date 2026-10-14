<?php
/*
  HANA - Observadores de las novedades (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/ObservadorAjax.js y responde con lo que
  trae el modelo (Modelo/ObservadorModelo.php).
  Cada petición llega con ?op=... y cae en uno de los "case" de abajo:
    guardar, mostrar, anular, activar, select y listar.
  Permiso: 9M. El desplegable (select) también lo usa Novedades.
*/
session_start();//inicia la session, permite guardar variables de sesion
require_once __DIR__ . '/Guardia.php'; //sesión y permisos (antes no se revisaban)
hanaGuardia(array('9M'), array('select')); //Observador: 9M. El selector lo usan novedades
require_once "../Modelo/ObservadorModelo.php";//Utilizará este archivo
$Observador=new Observador();//objeto del modelo: con él se hacen las consultas
//carga las variables con los valores recibidos y limpia los que no se usaran
$ideobservador=isset($_POST["idobservador"])? limpiarCadena($_POST["idobservador"]):"";
$nombreobservador=isset($_POST["nombre"])? limpiarCadena($_POST["nombre"]):"";



//opciones
switch ($_GET["op"])
    {
            case 'guardar'://primer caso
                if (empty($ideobservador)) {
                  
                    $rspta=$Observador->insertar(strtoupper($nombreobservador));
                    echo $rspta? "Registro guardado con éxito": "Error no se pudo realizar el registro"; 
                    
                }else{
                    
                    $rspta=$Observador->editar($ideobservador,strtoupper($nombreobservador));
                            echo $rspta ? "Registro actualizado con éxito" : "No se pudo actualizar";
                }
                            
            break;
            //Trae un observador para cargarlo en el formulario
            case 'mostrar':
       
                    $rspta=$Observador->mostrar($ideobservador);
                    //Codificar el resultado utilizando json
                    echo json_encode($rspta);
            break;
            //Desactiva un observador
            case 'anular':
                       $rspta=$Observador->desactivar($ideobservador);
                        echo $rspta ? "Registro anulado con éxito" : "No se pudo anular el registro";         
                            
            break;
        
             //Vuelve a activar un observador
             case 'activar':
                   
                        $rspta=$Observador->activar($ideobservador);
                        echo $rspta ? "Registro activado con éxito" : "No se pudo activar el registro";        
                            
            break;
        
        
            //Arma las opciones del desplegable de observadores
            case 'select':
            $rspta=$Observador->select();
            echo "<option value=''>Seleccione Observador...</option>";
           while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
           {
                  echo "<option value='$reg->ID_OBSERVADOR_NOVEDADES_HALLAZGOS'>$reg->NOM_OBSERVADOR_NOVEDADES_HALLAZGOS</option>";
            
           }
            break;
        
        
   
        
        
            case 'listar'://lo pide la tabla de la pantalla al cargar
        
        
                    $rspta=$Observador->listar();//trae los registros para la tabla
                    //Vamos a declarar un array
                    $data= Array();
        
                    while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                    {
                        
                      $bot='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$reg->ID_OBSERVADOR_NOVEDADES_HALLAZGOS.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN> <SPAN title="anular">
                    <button type="button" class="btn btn-success" onclick="anular('.$reg->ID_OBSERVADOR_NOVEDADES_HALLAZGOS.')">
                    <i class="fa fa-trash" style="color:white;"></i></button></SPAN>';       
                
                        $estado="Activo";
                        if($reg->ESTADO==0){
                        $estado="Inactivo";
                        $bot='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$reg->ID_OBSERVADOR_NOVEDADES_HALLAZGOS.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN> <SPAN title="Activar">
                     <button type="button" class="btn btn-success" onclick="activar('.$reg->ID_OBSERVADOR_NOVEDADES_HALLAZGOS.')">
                     <i class="fa fa-check" style="color:white;"></i></button></SPAN>';
                            
                        }
                               
                        
                            $data[]=array(//arreglo con los datos de las columnas
                                    "0"=>$reg->NOM_OBSERVADOR_NOVEDADES_HALLAZGOS,
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