<?php
/*
  HANA - Roles (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/RolesAjax.js y responde con lo que
  trae el modelo (Modelo/RolesModelo.php).
  Cada petición llega con ?op=... y cae en uno de los "case" de abajo:
    guardar, mostrar, anular, activar, select y listar.
  Un rol es la lista de permisos (1M, 2M, ...) que se guarda separada por comas.
  Permiso: 2M. El desplegable (select) también lo usa Usuarios.
*/
session_start();//inicia la session, permite guardar variables de sesion
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
hanaGuardia(array('2M'), array('select')); //Roles: 2M. El selector lo usa Usuarios
require_once "../Modelo/RolesModelo.php";//Utilizará este archivo
$Roles=new Roles();//objeto del modelo: con él se hacen las consultas
//carga las variables con los valores recibidos y limpia los que no se usaran
$idroles=isset($_POST["idrol"])? limpiarCadena($_POST["idrol"]):"";
$nombrerol=isset($_POST["nombre"])? limpiarCadena($_POST["nombre"]):"";
$modulos=isset($_POST["permiso"])? $_POST["permiso"]:"";
//"Permiso de cerrar novedades": 1 si viene marcado, 0 si no
$auditoria = (isset($_POST["auditoria"]) && $_POST["auditoria"] !== '' && $_POST["auditoria"] !== '0') ? 1 : 0;

//Solo códigos de permiso válidos (número + M)
$modulos = is_array($modulos) ? array_values(array_filter($modulos, function ($m) { return preg_match('/^\d{1,3}M$/', $m); })) : array();
//Protección: quien edita SU PROPIO rol no puede quitarse "Permisos de usuario" (2M),
//porque se quedaría sin poder volver a entrar a esta pantalla para arreglarlo
$avisoPropio = '';
if (!empty($idroles) && isset($_SESSION['IdUsuarios'])) {
    require_once __DIR__ . '/../Modelo/AccesoModelo.php';
    $mio = Acceso::rolDeUsuario((int)$_SESSION['IdUsuarios']);
    if ($mio && $mio === (int)$idroles && !in_array('2M', $modulos, true)) {
        $modulos[] = '2M';
        $avisoPropio = "\n\nSe conservó la casilla «Permisos de usuario» (2M): es tu propio rol y sin ella no podrías volver a esta pantalla.";
    }
}

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
                    echo $rspta? "Registro guardado con éxito": "Error no se pudo realizar el registro"; 
                    
                }else{
                    
                    $rspta=$Roles->editar($idroles,strtoupper($nombrerol),$mod,$auditoria);
                            echo $rspta ? "Registro actualizado con éxito" . $avisoPropio : "No se pudo actualizar";
                }
                            
            break;
            //Trae un rol para cargarlo en el formulario
            case 'mostrar':
       
                    $rspta=$Roles->mostrar($idroles);
                    //Codificar el resultado utilizando json
                    echo json_encode($rspta);
            break;
            //Desactiva un rol
            case 'anular':
                       $rspta=$Roles->desactivar($idroles);
                        echo $rspta ? "Registro anulado con éxito" : "No se pudo anular el registro";         
                            
            break;
        
             //Vuelve a activar un rol
             case 'activar':
                   
                        $rspta=$Roles->activar($idroles);
                        echo $rspta ? "Registro activado con éxito" : "No se pudo activar el registro";        
                            
            break;
        
        
            //Arma las opciones del desplegable de roles
            case 'select':
            $rspta=$Roles->select();
            echo "<option value=''>Seleccione Rol...</option>";
           while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
           {
                  echo "<option value='$reg->ID_ROL_USUARIO_SISTEMA'>$reg->NOM_ROL_USUARIO_SISTEMA</option>";
            
           }
            break;
        
        
   
        
        
            case 'listar'://lo pide la tabla de la pantalla al cargar
        
        
                    $rspta=$Roles->listar();//trae los registros para la tabla
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