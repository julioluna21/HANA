<?php
session_start();//inicia la session, permite guardar variables de sesion
require_once "../Modelo/NovedadModelo.php";//Utilizará este archivo
require '../public/PHPMailer-master/src/Exception.php';
                require '../public/PHPMailer-master/src/PHPMailer.php';
                require '../public/PHPMailer-master/src/SMTP.php';
                use PHPMailer\PHPMailer\PHPMailer;
                use PHPMailer\PHPMailer\Exception;

$Novedades=new Novedades();//crea un nuevo articulo
setlocale(LC_ALL,'es-Es');// Activa la localización con el sistema para mostrar en español
date_default_timezone_set("America/Lima");
//carga las variables con los valores recibidos y limpia los que no se usaran
$idnovedad=isset($_POST["IDnovedad"])? limpiarCadena($_POST["IDnovedad"]):"";
$idtitulo=isset($_POST["titulo"])? limpiarCadena($_POST["titulo"]):"";
$idcolaborador=isset($_POST["colaborador"])? limpiarCadena($_POST["colaborador"]):"";
$idestado=isset($_POST["prioridad"])? limpiarCadena($_POST["prioridad"]):"";
$idcentroOP=isset($_POST["centroop"])? limpiarCadena($_POST["centroop"]):"";
$idobservador=isset($_POST["observador"])? limpiarCadena($_POST["observador"]):"";
$validez=isset($_POST["validez"])? limpiarCadena($_POST["validez"]):"";    
$estadonovedad=isset($_POST["estadoNovedad"])? limpiarCadena($_POST["estadoNovedad"]):"";
$novedad=isset($_POST["novedad"])? limpiarCadena($_POST["novedad"]):"";
$fecha=date("Y-m-d_H:i:s");
$fecha2=date("Y-m-j H:i:s");

if($_SESSION['NovedadesAS']==0){
$idcolaborador=$_SESSION['Idcolaborador'];   
}

function esImagen($path)
    {
                $imageSizeArray = getimagesize($path);
                $imageTypeArray = $imageSizeArray[2];
                return (bool)(in_array($imageTypeArray , array(IMAGETYPE_GIF , IMAGETYPE_JPEG ,IMAGETYPE_PNG , IMAGETYPE_BMP)));
     }

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
                         $mail->FromName = utf8_decode('NOHA LISTA DE CHEQUEO');
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
                if (empty($idnovedad)){
                  
                    $rspta=$Novedades->diaslimites($idestado);
                    $dias=$rspta['DIAS_ESTADOS_RELEVANCIAS'];
                    $date_now = date('Y-m-j H:i:s', strtotime($fecha2));   
                    $date_past = strtotime('+'.$dias.' days', strtotime($date_now));
                    $date_past = date ('Y-m-j H:i:s' ,$date_past);
                    $imagen="";
                    
                    if(isset($_FILES["miarchivo2"]) and $_FILES["miarchivo2"]["name"]){
                        if(esImagen($_FILES['miarchivo2']['tmp_name'])){
                         $tipo = pathinfo($_FILES['miarchivo2']['tmp_name'], PATHINFO_EXTENSION); 
                           $imagen ='data:'.mime_content_type($_FILES['miarchivo2']['tmp_name']).';base64,'.base64_encode(file_get_contents($_FILES['miarchivo2']['tmp_name']));   
                        }
                        
                    }
                    if($rspta=$Novedades->insertar($fecha,$_SESSION['Idcolaborador'],$idcentroOP,$idcolaborador,$idobservador,$idtitulo,$novedad,$imagen,$validez,$idestado,$date_past)){
						
						if($idcolaborador!=$_SESSION['Idcolaborador']){
						$rspta=$Novedades->correo($rspta);
                        $correo=$rspta['MAIL_COLABORADOR'];
                        $mensaje="Saludos ".$rspta['NOM_COLABORADOR'].", se informa que fue asignado a una novedad en el sistema NOHA para el centro de operación ".$rspta['NOM_CENTRO_OP'].",  con el titulo ".$rspta['NOM_TITULO_NOVEDADES_HALLAZGOS']." y de prioridad ".$rspta['NOMBRE_ESTADOS_RELEVANCIA'].".<br><br> Este es un sistema automático porfavor no responder este mensaje.";
                        $asunto="ASIGNACIÓN DE NOVEDAD";
                    if(correoenvio($correo,$mensaje,$asunto)){
                        echo "Registro Exitoso"; 
                    }else{
                        echo "Error se registro la novedad, pero no fue posible enviar la notificación por medio de correo electrónico";
                    }  
							
						}else{
						echo "Registro Exitoso"; 	
						}	
						
					}else{
						echo 'Error no se guardo la novedad';
					}
					
					  
                    
                }else{
                    
                    if($estadonovedad==3){
                    $imagen="";
                    if(isset($_FILES["miarchivo"]) and $_FILES["miarchivo"]["name"]){
                        if (esImagen($_FILES['miarchivo']['tmp_name'])) {
                           $tipo = pathinfo($_FILES['miarchivo']['tmp_name'], PATHINFO_EXTENSION); 
                          $imagen ='data:'.mime_content_type($_FILES['miarchivo']['tmp_name']).';base64,'.base64_encode(file_get_contents($_FILES['miarchivo']['tmp_name']));   
                           $rspta=$Novedades->imagencierre($idnovedad,$imagen);     
                        }
                        
                    }
                        $rspta=$Novedades->editarfecha($idnovedad,$fecha);
                    }
                    
                    if($estadonovedad==""){
                    $estadonovedad=0;
                    }else{
                     $rspta=$Novedades->editarEstado($idnovedad,$estadonovedad);    
                    }
                    $rspta=$Novedades->insertarRespuesta($idnovedad,$_SESSION['Idcolaborador'],$fecha,$novedad,$estadonovedad);
                    echo $rspta ? "Respuesta exitosa" : "No se pudo guardar la respuesta";
                }
                            
            break;
        
            case 'mostrarnovedad':
       
                    $rspta=$Novedades->mostrar($idnovedad);
                    $arreglo= Array();
                    $arreglo['titulo']=$rspta['NOM_TITULO_NOVEDADES_HALLAZGOS'];
                    $arreglo['fechacreacion']=$rspta['FEC_CREACION_NOVEDADES_HALLAZGOS'];
                    $imagencierre=$rspta['NOM_FOTO_FIN_NOVEDADES_HALLAZGOS'];
                    $contenido="";
                
        
                    if($_SESSION['Idcolaborador']==$rspta['ID_COLABORADOR_NOVEDADES_HALLAZGOS']){
                        
                         $contenido=$contenido.'<div class="row justify-content-end ">
                            <div class="col-auto">
                                <div class="card bg-primary text-white">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <span style=" font-weight: bold;">Observador novedad:</span>'.$rspta['NOM_OBSERVADOR_NOVEDADES_HALLAZGOS'].'<br>
                                        <span style=" font-weight: bold;">Centro operativo:</span>'.$rspta['NOM_CENTRO_OP'].'<br>    
                                        <span style=" font-weight: bold;">Novedad asignada a:</span>'.$rspta['asignado'].'<br>  
                                        <span style=" font-weight: bold;">Validez novedad:</span>'.$rspta['VALIDEZ_NOVEDADES_HALLAZGOS'].'<br>  
                                        <span style=" font-weight: bold;">Prioridad:</span>'.$rspta['NOMBRE_ESTADOS_RELEVANCIA'].'<br>       
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">'.$rspta['NOM_COLABORADOR']." ".$rspta['FEC_CREACION_NOVEDADES_HALLAZGOS'].'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row justify-content-end ">
                             <div class="col-auto">
                                <div class="card bg-primary text-white">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <span style=" font-weight: bold;">Novedad:<br></span>'.nl2br($rspta['DESCR_NOVEDADES_HALLAZGOS']).'     
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">'.$rspta['NOM_COLABORADOR']." ".$rspta['FEC_CREACION_NOVEDADES_HALLAZGOS'].'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        ';
                        
                        if($rspta['NOM_FOTO_INI_NOVEDADES_HALLAZGOS']!=""){
                          $contenido=$contenido.'<div class="row mb-4">
                            <div class="col text-center">
                                <span class="badge badge-secondary">FOTO EVIDENCÍA NOVEDAD</span>
                            </div>
                            <!--end of col-->
                        </div>
                        
                        
                        <div class="row justify-content-end ">
                             <div class="col-auto">
                                <div class="card bg-primary text-white">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <a><img src="'.$rspta['NOM_FOTO_INI_NOVEDADES_HALLAZGOS'].'" class="img-rounded" alt="Cinque Terre"style="width: 350px;"></a>   
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">'.$rspta['NOM_COLABORADOR']." ".$rspta['FEC_CREACION_NOVEDADES_HALLAZGOS'].'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>';  
                        }
                        
                        
                        
                    }else{
                        $contenido=$contenido.'<div class="row justify-content-start">
                            <div class="col-auto">
                                <div class="card bg-secondary">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <span style=" font-weight: bold;">Observador novedad:</span>'.$rspta['NOM_OBSERVADOR_NOVEDADES_HALLAZGOS'].'<br>
                                        <span style=" font-weight: bold;">Centro operativo:</span>'.$rspta['NOM_CENTRO_OP'].'<br>    
                                        <span style=" font-weight: bold;">Novedad asignada a:</span>'.$rspta['asignado'].'<br>  
                                        <span style=" font-weight: bold;">Validez novedad:</span>'.$rspta['VALIDEZ_NOVEDADES_HALLAZGOS'].'<br>  
                                        <span style=" font-weight: bold;">Prioridad:</span>'.$rspta['NOMBRE_ESTADOS_RELEVANCIA'].'<br>       
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">'.$rspta['NOM_COLABORADOR']." ".$rspta['FEC_CREACION_NOVEDADES_HALLAZGOS'].'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row justify-content-start">
                             <div class="col-auto">
                                <div class="card bg-secondary">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <span style=" font-weight: bold;">Novedad:</span><br>'.nl2br($rspta['DESCR_NOVEDADES_HALLAZGOS']).'     
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">'.$rspta['NOM_COLABORADOR']." ".$rspta['FEC_CREACION_NOVEDADES_HALLAZGOS'].'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        ';
                        
                        if($rspta['NOM_FOTO_INI_NOVEDADES_HALLAZGOS']!=""){
                          $contenido=$contenido.'<div class="row mb-4">
                            <div class="col text-center">
                                <span class="badge badge-secondary">FOTO EVIDENCÍA NOVEDAD</span>
                            </div>
                            <!--end of col-->
                        </div>
                        
                        
                        <div class="row justify-content-start">
                             <div class="col-auto">
                                <div class="card bg-secondary">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <a ><img src="'.$rspta['NOM_FOTO_INI_NOVEDADES_HALLAZGOS'].'" class="img-rounded" alt="Cinque Terre"style="width: 350px;"></a>   
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">'.$rspta['NOM_COLABORADOR']." ".$rspta['FEC_CREACION_NOVEDADES_HALLAZGOS'].'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>';  
                        }
                        
                    }
        
        
                    $rspta=$Novedades->mostrarRespuesta($idnovedad);
                    $estadon="";
                    while ($reg=$rspta->fetch_object()){
                        $estadon="";
                        switch($reg->ESTADO_NOVEDAD)
                        {
                            case 0:
                                $estadon="Observador";
                                break;    
                            case 2:
                                $estadon="En proceso";
                                break;
                            case 3:
                                $estadon="Finalizada";
                                break;
                            case 4:
                                $estadon="Cerrada";
                                break;    
                        }
                         
                        
                        if($_SESSION['Idcolaborador']==$reg->ID_COLABORADOR_RESPUESTA){
                            
                            $contenido=$contenido.'<div class="row justify-content-end ">
                             <div class="col-auto">
                                <div class="card bg-primary text-white">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        '.nl2br($reg->DESCR_RESPUESTA_NOVEDAD_HALLAZGOS).'     
                                        </p><br>
                                        <div>
                                            <span style=" font-weight: bold;" class="opacity-60">ESTADO: '.$estadon.'</span><br> 
                                            <small class="opacity-60">'.$reg->NOM_COLABORADOR." ".$reg->FEC_RESPUESTA_NOVEDAD_HALLAZGOS.'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>';
                            
                        if($reg->ESTADO_NOVEDAD==3 and $imagencierre!=""){
                            
                            $contenido=$contenido.'<div class="row mb-4">
                            <div class="col text-center">
                                <span class="badge badge-secondary">FOTO EVIDENCIA CIERRE NOVEDAD</span>
                            </div>
                            <!--end of col-->
                        </div>
                        
                        
                        <div class="row justify-content-end ">
                             <div class="col-auto">
                                <div class="card bg-primary text-white">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <a ><img src="'.$imagencierre.'" class="img-rounded" alt="Cinque Terre"style="width: 350px;"></a>   
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">'.$reg->NOM_COLABORADOR." ".$reg->FEC_RESPUESTA_NOVEDAD_HALLAZGOS.'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>'; 
                            
                        }    
                            
                        }else{
                            
                            
                         $contenido=$contenido.'<div class="row justify-content-start">
                             <div class="col-auto">
                                <div class="card bg-secondary">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        '.nl2br($reg->DESCR_RESPUESTA_NOVEDAD_HALLAZGOS).'    
                                        </p><br>
                                        <div>
                                            <span style=" font-weight: bold;" class="opacity-60">ESTADO: '.$estadon.'</span><br>
                                            <small class="opacity-60">'.$reg->NOM_COLABORADOR." ".$reg->FEC_RESPUESTA_NOVEDAD_HALLAZGOS.'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>';    
                            
                        if($reg->ESTADO_NOVEDAD==3 and $imagencierre!=""){
                            
                            $contenido=$contenido.'<div class="row mb-4">
                            <div class="col text-center">
                                <span class="badge badge-secondary">FOTO EVIDENCÍA CIERRE NOVEDAD</span>
                            </div>
                            <!--end of col-->
                        </div>
                        
                        <div class="row justify-content-start">
                             <div class="col-auto">
                                <div class="card bg-secondary">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <a><img src="'.$imagencierre.'" class="img-rounded" alt="Cinque Terre"style="width: 350px;"></a>   
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">'.$reg->NOM_COLABORADOR." ".$reg->FEC_RESPUESTA_NOVEDAD_HALLAZGOS.'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>'; 
                            
                        } 
                            
                            
                            
                        }
                        
                    }
                   
                    $arreglo['contenido']=$contenido;
                    $arreglo['estado']=$estadon;
                    $arreglo['permiso']=$_SESSION['Audititoria'];
                    //Codificar el resultado utilizando json
                    echo json_encode($arreglo);
            break;        
        
            case 'listar'://activado por el ajax en el scrip articulos
        
        
                   
        
                    $rspta=$Novedades->centrosOP($_SESSION['IdUsuarios']);//Carga la rspta con lista de articulos
                     $centrosoperativos= Array();
                     while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                     {
                         $centrosoperativos[]=$reg->ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP;
                         
                     }
        
                    if($_GET['validador']=='todo' and $_GET['validador2']==0 and $_GET['fechai']=='' and $_GET['fechaf']==''){
                      
                         $rspta=$Novedades->listar();//Carga la rspta con lista de articulos 
                    }else{
						$condicional="WHERE ";
						
						if($_GET['fechai']!='' and $_GET['fechaf']!=''){
							$condicional=$condicional."(novedades_hallazgos.FEC_CREACION_NOVEDADES_HALLAZGOS BETWEEN '".$_GET['fechai']." 00:00:00'"."  and '".$_GET['fechaf']." 23:59:59')" ;
							
						}
						
						if($_GET['validador']!='todo' and $_GET['validador']!='usuario'){
							 if($condicional=="WHERE "){
							  $condicional=$condicional."proyectos.ID_PROYECTO=".$_GET['validador'];	 
							 }else{
								  $condicional=$condicional." and proyectos.ID_PROYECTO=".$_GET['validador'];	
							 }
						}else if($_GET['validador']=='usuario'){
							if($condicional=="WHERE "){
							  $condicional=$condicional." novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS=".$_SESSION['Idcolaborador'];	 
							 }else{
								  $condicional=$condicional." and novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS=".$_SESSION['Idcolaborador'];
							 }
						}
						
						if($_GET['validador2']!=0){
							  if($condicional=="WHERE "){
                                    $condicional=$condicional." novedades_hallazgos.ESTADO_NOVEDAD=".$_GET['validador2']; 
                                }else{
                                    $condicional=$condicional." AND novedades_hallazgos.ESTADO_NOVEDAD=".$_GET['validador2'];  
                                }
							
						}

                         $rspta=$Novedades->listarCondicional($condicional);//Carga la rspta con lista de articulos
                    }
                     
                    
                   
                    //Vamos a declarar un array
                    $data= Array();
        
                    while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                    {
                        
                        
                      $bot="";  
                     if(in_array($reg->ID_CENTRO_OP,$centrosoperativos)){
                         
                         
                        if($reg->ESTADO_NOVEDAD!=3 and $reg->ESTADO_NOVEDAD!=4){
                        $bot='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$reg->ID_NOVEDADES_HALLAZGOS.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN>';     
                            
                        }else if($reg->ESTADO_NOVEDAD==3 and $_SESSION['Audititoria']==1){
                        $bot='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$reg->ID_NOVEDADES_HALLAZGOS.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN>';     
                        }else if(($reg->ESTADO_NOVEDAD==3 and $_SESSION['Audititoria']==0) or $reg->ESTADO_NOVEDAD==4){
                            $bot='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="solover('.$reg->ID_NOVEDADES_HALLAZGOS.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN>'; 
                            
                        }
                  
                     }else{
                         
                        $bot='<SPAN title="Editar">
                        <button class="btn btn-success" onclick="solover('.$reg->ID_NOVEDADES_HALLAZGOS.')">
                        <i class="fa fa-eye" style="color:white;"></i></button></SPAN>';   
                         
                     }    
               
                        $estadon="";
                         switch($reg->ESTADO_NOVEDAD)
                        {
                                     
                            case 1:
                                $estadon="Asignada";
                                break;     
                            case 2:
                                $estadon="En proceso";
                                break;
                            case 3:
                                $estadon="Finalizada";
                                break;
                            case 4:
                                $estadon="Cerrada";
                                break;    
                        }
                        
                         $est="";
                         if($reg->FEC_CIERRE_NOVEDAD_HALLAZGOS!=null and $reg->ESTADO_NOVEDAD>=3){
                             
                        if($reg->FEC_CIERRE_NOVEDAD_HALLAZGOS>$reg->FECHA_LIMITE_NOVEDAD){
                         $est='<SPAN title="estado">                        
                                    <i class="fa fa-thumbs-down" style="color: red;
                         font-size: 25px; "></i></SPAN>';
                    }else{
                       $est='<SPAN title="estado">                        
                        <i class="fa fa-thumbs-up" style="color: green;
                         font-size: 25px; "></i></SPAN>'; 
                         }  
                             
                         }else{    
                             
                         if($fecha>$reg->FECHA_LIMITE_NOVEDAD){
                         $est='<SPAN title="estado">                        
                                    <i class="fa fa-thumbs-down" style="color: red;
                         font-size: 25px; "></i></SPAN>';
                        }else{
                        $est='<SPAN title="estado">                        
                        <i class="fa fa-thumbs-up" style="color: green;
                         font-size: 25px; "></i></SPAN>'; 
                         }
                            
                             
                         }
                        
                        
                            $data[]=array(//arreglo con los datos de las columnas
                                    "0"=>$reg->ID_NOVEDADES_HALLAZGOS,
                                    "1"=>'<SPAN title="'.$reg->DESCR_NOVEDADES_HALLAZGOS.'">'.$reg->NOM_TITULO_NOVEDADES_HALLAZGOS.'</SPAN>',
                                    "2"=>$reg->FEC_CREACION_NOVEDADES_HALLAZGOS,
                                    "3"=>$reg->NOM_COLABORADOR,
                                    "4"=>$reg->asignado,
                                    "5"=>$reg->NOM_PROYECTO,
                                    "6"=>$reg->NOM_CENTRO_OP,
                                    "7"=>$reg->NOMBRE_ESTADOS_RELEVANCIA,
                                    "8"=>$reg->FECHA_LIMITE_NOVEDAD,
                                    "9"=>$est,
                                    "10"=>$estadon,
                                    "11"=>$bot,
                            );
                    }
                 
                    $results = array(//variable con el resultado del arreglo
                            "sEcho"=>1, //Información para el datatables
                            "iTotalRecords"=>count($data), //enviamos el total registros al datatable
                            "iTotalDisplayRecords"=>count($data), //enviamos el total registros a visualizar
                            "aaData"=>$data);
                    echo json_encode($results);//muestra el resultado
            break;
		
		     case 'select':
                try {
                        $rspta = $Novedades->proyectos();
                        echo "<option value='todo'>Todos</option><option value='usuario'>Asignadas a mi usuario</option>";
                        while ($reg = $rspta->fetch_object()) //mientras exista objeto en la respuesta
                        {
                                echo "<option value='$reg->ID_PROYECTO'>$reg->NOM_PROYECTO</option>";
                        }
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                break;
          
    }