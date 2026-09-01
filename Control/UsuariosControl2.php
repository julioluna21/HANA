<?php
session_start();//inicia la session, permite guardar variables de sesion
require_once "../Modelo/UsuarioModelo.php";//Utilizará este archivo
require '../public/PHPMailer-master/src/Exception.php';
                require '../public/PHPMailer-master/src/PHPMailer.php';
                require '../public/PHPMailer-master/src/SMTP.php';
                use PHPMailer\PHPMailer\PHPMailer;
                use PHPMailer\PHPMailer\Exception;

$Usuarios=new Usuarios();//crea un nuevo articulo
//carga las variables con los valores recibidos y limpia los que no se usaran
$idusuario=isset($_POST["idusuario"])? limpiarCadena($_POST["idusuario"]):"";
$nombreususrio=isset($_POST["nombre"])? limpiarCadena($_POST["nombre"]):"";
$centros=isset($_POST["permiso"])? $_POST["permiso"]:"";
$idcolaborador=isset($_POST["colaborador"])? limpiarCadena($_POST["colaborador"]):"";
$idrol=isset($_POST["rolColaborador"])? limpiarCadena($_POST["rolColaborador"]):"";
$clave=isset($_POST["clave"])? limpiarCadena($_POST["clave"]):"";

$mod="";



function correoenvio($correo,$mensaje,$asunto){
                         $mail = new PHPMailer();
                         $mail->PluginDir = "phpMailer/";
                         $mail->Mailer = "smtp";
                         $mail->IsSMTP();
                         $mail->SMTPAuth = true;
                         $mail->Host = "regencysa.net";
                         $mail->Port = 465;
                         $mail->Username = "no-reply@regencysa.net";
                         $mail->Password = "Pr0t1nc0315*";
                         $mail->SMTPSecure = "ssl";
                         $mail->From     = 'no-reply@regencysa.net';
                         $mail->FromName = utf8_decode('HANA LISTA DE CHEQUEO');
                         $mail->AddAddress($correo);
                         $mail->WordWrap = 200;
                         $mail->IsHTML(true);
                         $mail->Subject  =  utf8_decode($asunto);
                         $mail->Body     =  utf8_decode(' <img src="" style=" width: 45%;
        height: 35%; display: block;
              margin-left: auto;
             margin-right: auto;"><br><br>
      
      <div style=" width: 45%;
         display: block;
              margin-left: auto;
             margin-right: auto;">
       '.$mensaje.'<br><br>
       
      </div><br>');
                            if($mail->send()){
                             return true;   
                            }else{
                              return false;   
                            }  
}



//opciones
switch ($_GET["op"])
    {
            case 'guardar'://primer caso
                if (empty($idusuario)) {
                    $rspta=$Usuarios->Verficar($nombreususrio);
                    if($rspta->num_rows<=0){
                    $permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyz';
                    $contrasena=substr(str_shuffle($permitted_chars), 0, 10);    
                    $clavehash=hash("SHA256",$contrasena);
                    if( $rspta=$Usuarios->insertar($nombreususrio,$clavehash,$idcolaborador,$idrol)){
                        $id=$rspta;//id usuario registrado
                         if($centros){
                                foreach($centros as $idcentro){
                                $rspta=$Usuarios->insertarcentros($id,$idcentro);
                               }
                          } 
                        $rspta=$Usuarios->correo($idcolaborador);
                        $correo=$rspta['MAIL_COLABORADOR'];
                        $mensaje="Saludos, se informa que ha sido registrado el sistema de Gestión de novedades con las siguientes credenciales de acceso:<br> Usuario: $nombreususrio<br>Clave ingreso: $contrasena<br> se recomienda por seguridad cambiar la calve de ingreso al iniciar sesión por primera vez en el sistema.<br> link de ingreso: https://hana.cloudregencyapps.com/Vista/login.php <br><br> Este es un sistema automático porfavor no responder este mensaje.";
                        $asunto="REGISTRO DE USUARIO";
                    if(correoenvio($correo,$mensaje,$asunto)){
                        echo "Registro Exitoso"; 
                    }else{
                        echo "Error se registro el usuario, pero no fue posible enviar las credenciales por medio de correo electrónico";
                    }    
                        
                        
                        }else{
                        echo 'Error no se pudo registrar el usuario';
                    }
                        
                    }else{
                        echo 'Error no se pudo registrar el usuario ya existe en el sistema';
                    }    
                        
                }else{
                    $rspta=$Usuarios->eliminarcentros($idusuario);
                    if($centros){
                        foreach($centros as $idcentro){
                        $rspta=$Usuarios->insertarcentros($idusuario,$idcentro);
                        }
                    } 
                    
                    $rspta=$Usuarios->editar($idusuario,$nombreususrio,$idcolaborador,$idrol);
                    echo $rspta ? "Registro actualizado" : "No se pudo actualizar";
                }
                            
            break;
        
            case 'cambiarclave':
                    $clavehash=hash("SHA256",$clave);
                    $rspta=$Usuarios->cambiraclave($idusuario,$clavehash);
                    //Codificar el resultado utilizando json
                    echo $rspta? "Se cambio la clave exitosamente" : "Error no se pudo actulizar la clave";
            break;
        
            case 'mostrar':
       
                    $rspta=$Usuarios->mostrar($idusuario);
                    //Codificar el resultado utilizando json
                    echo json_encode($rspta);
            break;
        
            
        
            case 'anular':
                       $rspta=$Usuarios->desactivar($idusuario);
                        echo $rspta ? "anulado exitoso" : "No se pudo anular el registro";         
                            
            break;
        
             case 'activar':
                   
                        $rspta=$Usuarios->activar($idusuario);
                        echo $rspta ? "activado exitoso" : "No se pudo activar el registro";        
                            
            break;
        
        
            case 'select':
            $rspta=$Usuarios->colaboradores();
            echo "<option value=''>Seleccione Colaborador...</option>";
           while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
           {
                  echo "<option value='$reg->ID_COLABORADOR'>$reg->NOM_COLABORADOR</option>";
            
           }
            break;
        
        
         case 'select2':
       
                    $rspta=$Usuarios->mostrarcentros();
                    echo "<option value=''>Seleccione Centro operativo...</option>";
                   while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                   {
                        echo "<option value='$reg->ID_CENTRO_OP'>$reg->NOM_CENTRO_OP</option>";
            
                   }
        break;
        
        case 'centroslista':
       
                    $rspta=$Usuarios->detectarcentros($idusuario);
                    $lista="";
                    while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                     {
                    if($lista==""){
                        $lista=$reg->ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP;
                    }else{
                       $lista=$lista.",".$reg->ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP; 
                    }    
                     
                     }
                    echo $lista;
                    
        break;
        
        case 'centroslistatodos':
       
                    $rspta=$Usuarios->mostrarcentros();
                    $lista="";
                    while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                     {
                    if($lista==""){
                        $lista=$reg->ID_CENTRO_OP;
                    }else{
                       $lista=$lista.",".$reg->ID_CENTRO_OP; 
                    }    
                     
                     }
                    echo $lista;
                    
        break;
   
         case 'MostrarCentros':
       
                    $rspta=$Usuarios->consultaCentros($idusuario);
                    $centrosl="";
                    while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                    {
                       $centrosl=$centrosl.$reg->NOM_CENTRO_OP."<br>";   
                     
                     }
                    echo $centrosl;
                    
        break;
   
        
        
       case 'centros':
       
                     $rspta=$Usuarios->mostrarcentros();
                     $pro="";
                     $valid=0; 
                     $contenido1='<div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" >
                                <label>Centros de operación:</label><br>
                                <SPAN title="Seleccionar todos" >
                               <input type="checkbox" class="" name="todosc" id="todosc" onclick="selecionar()"  /></SPAN> <label >Seleccionar Todos</label><br>';
                     $contenido2='<div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" >
                                <br><br>';
                      while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                     {
                          
                     if($pro!=$reg->NOM_PROYECTO){
                     if($valid==0){
                      $valid=1;
                      $contenido1=$contenido1.'<div   style="background-color: #bcd4db; text-align: center"><label>'.$reg->NOM_PROYECTO.'</label></div>';      
                     }else{
                       $valid=0; 
                       $contenido2=$contenido2.'<div   style="background-color: #bcd4db; text-align: center"><label>'.$reg->NOM_PROYECTO.'</label></div>';        
                     }     
                     $pro=$reg->NOM_PROYECTO;         
                     }
                          
                      if($valid==0){
                               $contenido2=$contenido2.'<SPAN title="'.$reg->NOM_CENTRO_OP.'" >
                               <input type="checkbox" class="" name="permiso[]" id="r'.$reg->ID_CENTRO_OP.'" value="'.$reg->ID_CENTRO_OP.'"  /></SPAN> <label >'.$reg->NOM_CENTRO_OP.'</label><br>';
                      }else{
                          
                          $contenido1=$contenido1.'<SPAN title="'.$reg->NOM_CENTRO_OP.'" >
                               <input type="checkbox" class="" name="permiso[]" id="r'.$reg->ID_CENTRO_OP.'" value="'.$reg->ID_CENTRO_OP.'"  /></SPAN> <label >'.$reg->NOM_CENTRO_OP.'</label><br>';
                          
                      }     
                     
            
                     }
        
                     $contenido1=$contenido1."</div>";
                     $contenido2=$contenido2."</div>";
        
                     echo $contenido1.$contenido2;
                    
        break; 
        
         case 'verificar'://verifica el login
                $LoginUsuarios=isset($_POST["LoginUsuarios"])? limpiarCadena($_POST["LoginUsuarios"]):"";
                $ClaveUsuarios=isset($_POST["ClaveUsuarios"])? limpiarCadena($_POST["ClaveUsuarios"]):"";

                //Hash SHA256 en la contraseña
                    $clavehash=hash("SHA256",$ClaveUsuarios);

                    $rspta=$Usuarios->verificar($LoginUsuarios, $clavehash);//respuesta de la verificación
                    if ($rspta) {
                        $fetch=$rspta->fetch_object();//variable con el resultado
                        if (isset($fetch)){//si no es nulo
                         //Declaramos las variables de sesión
                                $_SESSION['IdUsuarios']=$fetch->ID_USUARIO_SISTEMA;
                                $_SESSION['Idcolaborador']=$fetch->ID_COLABORADOR;
                                $_SESSION['Audititoria']=$fetch->PERMISOS_CERRAR_NOVEDADES;
                                $_SESSION['NovedadesAS']=$fetch->PERMISO_ASIGNAR_NOVEDAD;
                                $_SESSION['Modulos']=$fetch->MODULOS_ROL_USUARIOS_SISTEMAS;
                                $_SESSION['Nombre']=$fetch->NOM_COLABORADOR;
                                echo json_encode(array('error' =>false));      
                               
                        }else{
                         echo json_encode(array('error' =>true));   
                        }
                    }else{
                        echo json_encode(array('error' =>true));
                    }

            break;
        
        
        case 'recuperar':
        $LoginUsuarios=isset($_POST["LoginUsuarios"])? limpiarCadena($_POST["LoginUsuarios"]):"";
        $permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyz';
        $contrasena=substr(str_shuffle($permitted_chars), 0, 10);
        if($rspta=$Usuarios->CorreoRecuperacion($LoginUsuarios)){
         $correo=$rspta['MAIL_COLABORADOR'];
         $id=$rspta['ID_USUARIO_SISTEMA'];
         $nombre=$rspta['NOM_COLABORADOR'];     
         $clavehash=hash("SHA256",$contrasena);
         $rspta=$Usuarios->cambiraclave($id,$clavehash);
         if($rspta){
              $mensajec="Saludos $nombre, su nueva contraseña para ingresar al sistema de Autgestíon novedades es la siguiente:<br><br>Contraseña:$contrasena<br><br> Cuando ingresé al sistema se recomienda cambiar esta contraseña por razones de seguridad.<br><br> Este es un sistema automático porfavor no responder este mensaje.";
              $asunto="RESTABLECER CLAVE";
                        if(correoenvio($correo,$mensajec,$asunto)){
                            echo json_encode(array('mensaje' =>"Se cambio tu contraseña exitosamente, las credenciales se enviaron a tu correo electrónico.")); 
                        
                        }else{
                            echo json_encode(array('mensaje' =>"No se envio el correo de notificación.")); 
                            
                        }
         }else{
             echo json_encode(array('mensaje' =>"No se pudo recuperar la contraseña.")); 
               
         }    
        }else{
            echo json_encode(array('mensaje' =>'El usurio ingresado no existe en el sistema.')); 
            
        }
        
        
        break;
        
          case 'salir':
                                 
            session_unset();
//            //Destruìmos la sesión
            session_destroy();
//            //Redireccionamos al login
            header("Location: ../Vista/login.php");
             
            break;
        
        
            case 'listar'://activado por el ajax en el scrip articulos
        
        
                    $rspta=$Usuarios->listar();//Carga la rspta con lista de articulos
                    //Vamos a declarar un array
                    $data= Array();
        
                    while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                    {
                        
                      $bot='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$reg->ID_USUARIO_SISTEMA.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN> <SPAN title="anular">
                    <button type="button" class="btn btn-success" onclick="anular('.$reg->ID_USUARIO_SISTEMA.')">
                    <i class="fa fa-trash" style="color:white;"></i></button></SPAN>';       
                
                        $estado="Activo";
                        if($reg->ESTADO==0){
                        $estado="Inactivo";
                        $bot='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$reg->ID_USUARIO_SISTEMA.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN> <SPAN title="Activar">
                     <button type="button" class="btn btn-success" onclick="activar('.$reg->ID_USUARIO_SISTEMA.')">
                     <i class="fa fa-check" style="color:white;"></i></button></SPAN>';
                            
                        }
                               
                        
                            $data[]=array(//arreglo con los datos de las columnas
                                    "0"=>$reg->DOC_COLABORADO,
                                    "1"=>$reg->NOM_COLABORADOR,
                                    "2"=>$reg->NOM_USUARIO_SISTEMA,
                                    "3"=>$reg->NOM_ROL_USUARIO_SISTEMA,
                                    "4"=>'<SPAN title="Centros operativos">
                                            <button class="btn btn-success" onclick="centros('.$reg->ID_USUARIO_SISTEMA.')">
                                                    <i class="fa fa-industry" style="color:white;"></i></button></SPAN>',
                                    "5"=>$estado,
                                    "6"=>$bot,
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