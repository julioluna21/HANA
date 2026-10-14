<?php
/*
  HANA - Usuarios y sesión (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/UsuariosAjax.js y Ajax/LoginAjax.js y responde con lo que
  trae el modelo (Modelo/UsuarioModelo.php).
  Cada petición llega con ?op=... y cae en uno de los "case" de abajo:
    guardar, cambiarclave, mostrar, anular, activar, los select, los de centros, verificar
    (iniciar sesión), recuperar (clave olvidada), salir y listar.
  Permiso: 1M para administrar usuarios. Entrar, recuperar la clave y salir no piden sesión;
  el resto pide al menos haber iniciado sesión (ver el bloque de permisos más abajo).
*/
session_start();//inicia la session, permite guardar variables de sesion
require_once "../Modelo/UsuarioModelo.php";//Utilizará este archivo
require '../public/PHPMailer-master/src/Exception.php';
                require '../public/PHPMailer-master/src/PHPMailer.php';
                require '../public/PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/CorreoConfig.php'; //cuenta de correo y plantilla de los correos
require_once __DIR__ . '/../Modelo/HanaDB.php';
                use PHPMailer\PHPMailer\PHPMailer;
                use PHPMailer\PHPMailer\Exception;

$Usuarios=new Usuarios();//objeto del modelo: con él se hacen las consultas
//carga las variables con los valores recibidos y limpia los que no se usaran
$idusuario=isset($_POST["idusuario"])? limpiarCadena($_POST["idusuario"]):"";
$nombreususrio=isset($_POST["nombre"])? limpiarCadena($_POST["nombre"]):"";
$centros=isset($_POST["permiso"])? $_POST["permiso"]:"";
$idcolaborador=isset($_POST["colaborador"])? limpiarCadena($_POST["colaborador"]):"";
$idrol=isset($_POST["rolColaborador"])? limpiarCadena($_POST["rolColaborador"]):"";
$clave=isset($_POST["clave"])? limpiarCadena($_POST["clave"]):"";

$mod="";

/*
  Quién puede usar cada operación:
    - Sin sesión: solo entrar, recuperar la contraseña y salir.
    - Administrar usuarios (crear, editar, ver, activar, anular): permiso 1M.
    - Lo demás (listas de colaboradores de otras pantallas, cambiar la clave
      propia): basta con haber iniciado sesión.
*/
$op        = isset($_GET["op"]) ? $_GET["op"] : "";
$opsLibres = array('verificar', 'recuperar', 'salir');
$opsAdmin  = array('guardar', 'mostrar', 'anular', 'activar', 'listar',
                   'centros', 'MostrarCentros', 'centroslista', 'centroslistatodos');
$haySesion = isset($_SESSION['IdUsuarios']);
$esAdmin   = $haySesion && in_array('1M', explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : ''));

if (!in_array($op, $opsLibres, true)) {
    if (!$haySesion) {
        http_response_code(401);
        echo "Tu sesión terminó. Vuelve a iniciar sesión.";
        exit();
    }
    if (in_array($op, $opsAdmin, true) && !$esAdmin) {
        http_response_code(403);
        echo "No permitido: tu rol no administra usuarios.";
        exit();
    }
}



//Envía un correo a una persona, en HTML y saludándola por su nombre
//(la plantilla y la cuenta están en CorreoConfig.php)
function correoenvio($correo,$mensaje,$asunto,$nombre='',$boton=null){
                         $mail = new PHPMailer();
                         hanaConfigurarSmtp($mail); //cuenta, servidor, UTF-8 y HTML
                         $mail->FromName = 'HANA - Grupo Regency';
                         $mail->addAddress($correo, hanaNombreBonito($nombre));
                         $mail->Subject  = $asunto;
                         $mail->Body     = hanaCorreoHtml($nombre, $asunto, $mensaje, $boton);
                         $mail->AltBody  = trim(strip_tags(str_replace(array('<br>', '</p>'), "\n", $mensaje))); //para lectores que no muestran HTML
                         return hanaEnviar($mail, 'USUARIOS'); //con plan B y registro (ver CorreoConfig.php)
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
                        //Los datos del usuario se muestran aquí, una sola vez, para que quien lo crea se los
                        //pase a la persona (no se envían por correo). El correo solo llega cuando la
                        //persona usa "Olvidé mi contraseña".
                        echo "Usuario registrado con éxito.\n\nUsuario: ".$nombreususrio."\nContraseña temporal: ".$contrasena
                            ."\n\nAnótala y pásasela a la persona: no se envía por correo y no se vuelve a mostrar."
                            ."\nSi se le olvida, puede pedir una nueva con «Olvidé mi contraseña».";
                        
                        
                        }else{
                        echo 'Error: no se pudo registrar el usuario';
                    }
                        
                    }else{
                        echo 'Error: ese nombre de usuario ya existe en el sistema';
                    }    
                        
                }else{
                    $rspta=$Usuarios->eliminarcentros($idusuario);
                    if($centros){
                        foreach($centros as $idcentro){
                        $rspta=$Usuarios->insertarcentros($idusuario,$idcentro);
                        }
                    } 
                    
                    $rspta=$Usuarios->editar($idusuario,$nombreususrio,$idcolaborador,$idrol);
                    echo $rspta ? "Registro actualizado con éxito" : "No se pudo actualizar";
                }
                            
            break;
        
            case 'cambiarclave':
                    //Cada quien cambia su propia contraseña. La de otro usuario, solo
                    //con el permiso 1M. El id que llega de la pantalla no se usa si no
                    //es administrador: se toma el de la sesión
                    $idCambiar = (int)$_SESSION['IdUsuarios'];
                    if ($esAdmin && $idusuario !== "") { $idCambiar = (int)$idusuario; }

                    if ($clave === "") {
                        echo "Error: escribe la nueva contraseña";
                        break;
                    }
                    $clavehash=hash("SHA256",$clave);
                    $rspta=$Usuarios->cambiraclave($idCambiar,$clavehash);
                    //Codificar el resultado utilizando json
                    echo $rspta? "La contraseña se cambió con éxito" : "Error: no se pudo actualizar la contraseña";
            break;
        
            //Trae un usuario para cargarlo en el formulario
            case 'mostrar':
       
                    $rspta=$Usuarios->mostrar($idusuario);
                    //Codificar el resultado utilizando json
                    echo json_encode($rspta);
            break;
        
            
        
            //Desactiva un usuario (ya no puede entrar)
            case 'anular':
                       $rspta=$Usuarios->desactivar($idusuario);
                        echo $rspta ? "Registro anulado con éxito" : "No se pudo anular el registro";         
                            
            break;
        
             //Vuelve a activar un usuario
             case 'activar':
                   
                        $rspta=$Usuarios->activar($idusuario);
                        echo $rspta ? "Registro activado con éxito" : "No se pudo activar el registro";        
                            
            break;
        
        
            //Arma las opciones del desplegable de colaboradores (para asociarlos a un usuario)
            case 'select':
            $rspta=$Usuarios->colaboradores();
            echo "<option value=''>Seleccione Colaborador...</option>";
           while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
           {
                  echo "<option value='$reg->ID_COLABORADOR'>$reg->NOM_COLABORADOR</option>";
            
           }
            break;
        
        
         //Arma las opciones del desplegable de centros operativos
         case 'select2':
       
                    $rspta=$Usuarios->mostrarcentros();
                    echo "<option value=''>Seleccione Centro operativo...</option>";
                   while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                   {
                        echo "<option value='$reg->ID_CENTRO_OP'>$reg->NOM_CENTRO_OP</option>";
            
                   }
        break;
		
		 //Opciones del filtro de centros: todos, los asignados a mí o un centro
		 case 'selectc':
       
                    $rspta=$Usuarios->mostrarcentros();
                    echo "<option value='todo'>Todos</option><option value='usuario'>Asignadas a mi usuario</option>";
                   while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                   {
                        echo "<option value='$reg->ID_CENTRO_OP'>$reg->NOM_CENTRO_OP</option>";
            
                   }
        break;
        
        //Ids de los centros asignados al usuario, separados por coma
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
        
        //Ids de todos los centros, separados por coma
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
   
         //Nombres de los centros del usuario, uno por renglón, para la tabla
         case 'MostrarCentros':
       
                    $rspta=$Usuarios->consultaCentros($idusuario);
                    $centrosl="";
                    while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                    {
                       $centrosl=$centrosl.$reg->NOM_CENTRO_OP."<br>";   
                     
                     }
                    echo $centrosl;
                    
        break;
   
        
        
       //Arma las casillas de centros agrupadas por proyecto para el formulario de usuario
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
        
        
        //Genera una clave temporal y la envía al correo del usuario que la olvidó
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
              $mensajec='<p style="margin:0 0 12px;">Pediste restablecer tu contraseña de <strong>HANA</strong>. Esta es la nueva:</p>'
                       .'<table cellpadding="0" cellspacing="0" style="background:#F6ECEC;border-radius:8px;margin:0 0 12px;"><tr><td style="padding:12px 16px;font-family:Consolas,monospace;font-size:18px;font-weight:bold;letter-spacing:1px;">'.$contrasena.'</td></tr></table>'
                       .'<p style="margin:0;">Cuando entres, cámbiala por una que recuerdes. Si no fuiste tú quien la pidió, avísale al administrador del sistema.</p>';
              $asunto="Tu nueva contraseña de HANA";
                        if(correoenvio($correo,$mensajec,$asunto,$nombre,array('texto'=>'Entrar a HANA','url'=>hanaUrlSistema('login.php')))){
                            echo json_encode(array('mensaje' =>"Se cambio tu contraseña exitosamente, las credenciales se enviaron a tu correo electrónico.")); 
                        
                        }else{
                            echo json_encode(array('mensaje' =>"No fue posible enviar el correo con la nueva contraseña.")); 
                            
                        }
         }else{
             echo json_encode(array('mensaje' =>"No se pudo recuperar la contraseña.")); 
               
         }    
        }else{
            echo json_encode(array('mensaje' =>'El usuario ingresado no existe en el sistema.')); 
            
        }
        
        
        break;
        
          //Cierra la sesión y vuelve al inicio de sesión
          case 'salir':
                                 
            session_unset();
//            //Destruìmos la sesión
            session_destroy();
//            //Redireccionamos al login
            header("Location: ../Vista/login.php");
             
            break;
        
        
            case 'listar'://lo pide la tabla de la pantalla al cargar
        
        
                    $rspta=$Usuarios->listar();//trae los registros para la tabla
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