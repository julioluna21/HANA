<?php
/*
  HANA - Novedades y hallazgos (controlador)
  ---------------------------------------------------------------------------
  Atiende las peticiones de Ajax/novedadnuevoAjax.js y responde con lo que
  trae el modelo (Modelo/NovedadModelo.php).
  Cada petición llega con ?op=... y cae en uno de los "case" de abajo:
    guardar, roles, mostrarnovedad, listar, foto y select.
  Permiso: 10M. Ver una novedad o su foto también se permite desde la campana de avisos,
  siempre que el rol de la persona pueda ver esa novedad (AccesoHelper.php).
*/
session_start();//inicia la session, permite guardar variables de sesion
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
hanaGuardia(array('10M'), array('select', 'roles', 'mostrarnovedad', 'foto')); //Novedades: 10M. Ver una novedad o su foto (desde la campana) solo pide sesión
require_once "../Modelo/NovedadModelo.php";//Utilizará este archivo
require_once "AccesoHelper.php"; //quién puede ver qué: la misma regla para novedades, RQ y campana
require_once __DIR__ . '/ArchivoHelper.php'; //las fotos se guardan en la base como texto base64
require '../public/PHPMailer-master/src/Exception.php';
                require '../public/PHPMailer-master/src/PHPMailer.php';
                require '../public/PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/CorreoConfig.php'; //cuenta de correo y plantilla de los correos
                use PHPMailer\PHPMailer\PHPMailer;
                use PHPMailer\PHPMailer\Exception;

$Novedades=new Novedades();//objeto del modelo: con él se hacen las consultas
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
$rolDestino=isset($_POST["rolDestino"])? intval($_POST["rolDestino"]):0; //rol al que se notifica
$fecha=date("Y-m-d_H:i:s");
$fecha2=date("Y-m-j H:i:s");

//Sin la casilla 42M (asignar novedades) la novedad queda asignada a quien la registra
if(!hanaTienePermiso(PERMISO_ASIGNAR_NOVEDAD)){
$idcolaborador=$_SESSION['Idcolaborador'];   
}

//Fotos de las novedades: se guardan dentro del registro, como texto base64
//("data:image/...;base64,..."), en NOM_FOTO_INI (la del reporte) y NOM_FOTO_FIN (la del cierre).
//La pantalla las pide con op=foto, que revisa si la persona puede ver la novedad
$NOV_TIPOS_FOTO = array(IMAGETYPE_GIF => array('gif', 'image/gif'), IMAGETYPE_JPEG => array('jpg', 'image/jpeg'),
                        IMAGETYPE_PNG => array('png', 'image/png'), IMAGETYPE_BMP => array('bmp', 'image/bmp'),
                        IMAGETYPE_WEBP => array('webp', 'image/webp'));

//La foto que llegó en $_FILES[$campo], como texto base64 (si pesa mucho, se achica antes).
//Devuelve '' si no llegó foto o no es una imagen válida (la novedad se guarda sin foto)
//y false si es demasiado pesada para guardarla en la base
function novGuardarFoto($campo)
{
    global $NOV_TIPOS_FOTO;
    if (!isset($_FILES[$campo]) || !$_FILES[$campo]['name'] || $_FILES[$campo]['error'] !== UPLOAD_ERR_OK) { return ''; }
    $info = @getimagesize($_FILES[$campo]['tmp_name']);
    if (!$info || !isset($NOV_TIPOS_FOTO[$info[2]])) { return ''; }
    $bytes = @file_get_contents($_FILES[$campo]['tmp_name']);
    if ($bytes === false) { return ''; }
    $a = hanaArchivoTexto($bytes, $NOV_TIPOS_FOTO[$info[2]][1]);
    return hanaArchivoCabe($a['texto']) ? $a['texto'] : false;
}

//Lo que va en el src de la imagen: la dirección que entrega la foto (no el texto de la foto,
//para que la respuesta no cargue con todo ese peso)
function novFotoSrc($valor, $idNovedad, $cual)
{
    return '../Control/NovedadesControl.php?op=foto&amp;id=' . intval($idNovedad) . '&amp;f=' . $cual;
}

//Envía un correo a una persona, en HTML y saludándola por su nombre
//(la plantilla y la cuenta están en CorreoConfig.php)
function correoenvio($correo,$mensaje,$asunto,$nombre='',$boton=null){
                         $mail = new PHPMailer();
                         hanaConfigurarSmtp($mail); //cuenta, servidor, UTF-8 y HTML
                         $mail->FromName = 'HANA - Gestión de novedades';
                         $mail->addAddress($correo, hanaNombreBonito($nombre));
                         $mail->Subject  = $asunto;
                         $mail->Body     = hanaCorreoHtml($nombre, $asunto, $mensaje, $boton);
                         $mail->AltBody  = trim(strip_tags(str_replace(array('<br>', '</p>', '</tr>'), "\n", $mensaje)));
                         return hanaEnviar($mail, 'NOVEDADES'); //con plan B y registro (ver CorreoConfig.php)
}

//Avisa a todas las personas de un rol: cada una recibe su propio correo, dirigido
//a ella y con su nombre.
//$destinatarios = array(array('correo' => ..., 'nombre' => ...), ...)
//Devuelve true si le llegó a todas (o si no había a quién avisar)
function correoenvioRol($destinatarios,$mensaje,$asunto,$boton=null){
                         $bien = true;
                         foreach ($destinatarios as $d) {
                             if (!correoenvio($d['correo'], $mensaje, $asunto, $d['nombre'], $boton)) { $bien = false; }
                         }
                         return $bien;
}

//opciones
switch ($_GET["op"])
    {
            case 'guardar'://primer caso
                if (empty($idnovedad)){

                    //El rol a notificar es obligatorio al crear una novedad
                    if (!hanaRolValido($rolDestino)) {
                        echo 'Error: elige el rol al que se le va a notificar la novedad.';
                        break;
                    }

                    $rspta=$Novedades->diaslimites($idestado);
                    $dias=$rspta['DIAS_ESTADOS_RELEVANCIAS'];
                    $date_now = date('Y-m-j H:i:s', strtotime($fecha2));   
                    $date_past = strtotime('+'.$dias.' days', strtotime($date_now));
                    $date_past = date ('Y-m-j H:i:s' ,$date_past);
                    $imagen = novGuardarFoto('miarchivo2');
                    if ($imagen === false) {
                        echo 'Error: ' . hanaArchivoMuyGrande('La foto');
                        break;
                    }
                    if($rspta=$Novedades->insertar($fecha,$_SESSION['Idcolaborador'],$idcentroOP,$idcolaborador,$idobservador,$idtitulo,$novedad,$imagen,$validez,$idestado,$date_past,$rolDestino)){
						
						//El aviso va a todas las personas del ROL elegido.
						//Además, la novedad aparece en la campana de cada persona de ese rol
						$idNueva = $rspta;
						$datos = $Novedades->correo($idNueva);
						//Las personas del rol, con su nombre, para escribirle a cada una
						$destinatarios = array();
						$lista = $Novedades->correosDeRol($rolDestino, $_SESSION['Idcolaborador']);
						while ($lista && ($c = $lista->fetch_assoc())) { $destinatarios[] = array('correo' => $c['MAIL_COLABORADOR'], 'nombre' => $c['NOM_COLABORADOR']); }

						//El correo: qué pasó, dónde, qué tan urgente y para cuándo
						$fila = function ($etq, $valor) { return '<tr><td style="padding:6px 12px 6px 0;color:#7A716E;white-space:nowrap;vertical-align:top;">'.$etq.'</td><td style="padding:6px 0;font-weight:bold;">'.$valor.'</td></tr>'; };
						$mensaje = '<p style="margin:0 0 12px;">Se registró una novedad para tu rol en <strong>HANA</strong>. Estos son los datos:</p>'
						         . '<table cellpadding="0" cellspacing="0" style="margin:0 0 14px;font-size:14px;">'
						         . ($datos ? $fila('Tipo', $datos['NOM_TITULO_NOVEDADES_HALLAZGOS']) . $fila('Centro', $datos['NOM_CENTRO_OP']) . $fila('Relevancia', $datos['NOMBRE_ESTADOS_RELEVANCIA']) : '')
						         . $fila('Atender antes de', date('d/m/Y', strtotime($date_past)))
						         . '</table>'
						         . '<div style="background:#FBF8F6;border-left:4px solid #6E1A1E;border-radius:6px;padding:12px 14px;margin:0 0 6px;">'.nl2br($novedad).'</div>';

						if (correoenvioRol($destinatarios, $mensaje, "Nueva novedad para tu rol", array('texto' => 'Ver la novedad', 'url' => hanaUrlSistema('novedadesVista.php')))) {
							echo "Novedad registrada con éxito";
						} else {
							echo "La novedad se registró, pero no fue posible enviar la notificación por correo electrónico";
						}

					}else{
						echo 'Error: no se pudo guardar la novedad';
					}
					
					  
                    
                }else{
                    //Responder también exige poder ver la novedad
                    if (!hanaPuedeVerNovedad($idnovedad)) {
                        echo 'Error: no tienes permiso sobre esta novedad.';
                        break;
                    }
                    
                    if($estadonovedad==3){
                        $imagen = novGuardarFoto('miarchivo');
                        if ($imagen === false) {
                            echo 'Error: ' . hanaArchivoMuyGrande('La foto del cierre');
                            break;
                        }
                        if ($imagen !== '') { $Novedades->imagencierre($idnovedad,$imagen); }
                        $rspta=$Novedades->editarfecha($idnovedad,$fecha);
                    }
                    
                    if($estadonovedad==""){
                    $estadonovedad=0;
                    }else{
                     $rspta=$Novedades->editarEstado($idnovedad,$estadonovedad);    
                    }
                    $rspta=$Novedades->insertarRespuesta($idnovedad,$_SESSION['Idcolaborador'],$fecha,$novedad,$estadonovedad);
                    echo $rspta ? "Respuesta guardada con éxito" : "No se pudo guardar la respuesta";
                }
                            
            break;
        
            //Los roles para el selector "Rol a notificar", y si puede ver todas
            case 'roles':
                    $roles = array();
                    $r = hanaRolesActivos();
                    while ($r && ($f = $r->fetch_assoc())) { $roles[] = array('id' => (int)$f['id'], 'nombre' => html_entity_decode($f['nombre'], ENT_QUOTES, 'UTF-8')); }
                    echo json_encode(array('roles' => $roles, 'verTodas' => hanaTienePermiso(PERMISO_VER_TODAS_NOVEDADES)), JSON_UNESCAPED_UNICODE);
                    break;

            //Trae una novedad con su detalle para abrirla
            case 'mostrarnovedad':

                    //Aunque alguien escriba el número de otra novedad a mano, el
                    //servidor solo la entrega si su rol puede verla
                    if (!hanaPuedeVerNovedad($idnovedad)) {
                        http_response_code(403);
                        echo json_encode(array('error' => 'No tienes permiso para ver esta novedad.'));
                        break;
                    }
                    $rspta=$Novedades->mostrar($idnovedad);
                    $arreglo= Array();
                    $arreglo['titulo']=$rspta['NOM_TITULO_NOVEDADES_HALLAZGOS'];
                    $arreglo['fechacreacion']=$rspta['FEC_CREACION_NOVEDADES_HALLAZGOS'];
                    $imagencierre = $rspta['NOM_FOTO_FIN_NOVEDADES_HALLAZGOS'] != '' ? novFotoSrc($rspta['NOM_FOTO_FIN_NOVEDADES_HALLAZGOS'], $idnovedad, 'fin') : '';
                    $fotoInicial = novFotoSrc($rspta['NOM_FOTO_INI_NOVEDADES_HALLAZGOS'], $idnovedad, 'ini');
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
                                <span class="badge badge-secondary">FOTO DE EVIDENCIA DE LA NOVEDAD</span>
                            </div>
                            <!--end of col-->
                        </div>
                        
                        
                        <div class="row justify-content-end ">
                             <div class="col-auto">
                                <div class="card bg-primary text-white">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <a><img src="'.$fotoInicial.'" class="img-rounded" alt="Foto de la novedad" style="width: 350px;"></a>   
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
                                <span class="badge badge-secondary">FOTO DE EVIDENCIA DE LA NOVEDAD</span>
                            </div>
                            <!--end of col-->
                        </div>
                        
                        
                        <div class="row justify-content-start">
                             <div class="col-auto">
                                <div class="card bg-secondary">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <a ><img src="'.$fotoInicial.'" class="img-rounded" alt="Foto de la novedad" style="width: 350px;"></a>   
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
                            //El número del estado se cambia por su nombre para mostrarlo en la tabla
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
                                <span class="badge badge-secondary">FOTO DE EVIDENCIA DEL CIERRE</span>
                            </div>
                            <!--end of col-->
                        </div>
                        
                        
                        <div class="row justify-content-end ">
                             <div class="col-auto">
                                <div class="card bg-primary text-white">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <a ><img src="'.$imagencierre.'" class="img-rounded" alt="Foto de la novedad" style="width: 350px;"></a>   
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
                                <span class="badge badge-secondary">FOTO DE EVIDENCIA DEL CIERRE</span>
                            </div>
                            <!--end of col-->
                        </div>
                        
                        <div class="row justify-content-start">
                             <div class="col-auto">
                                <div class="card bg-secondary">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <a><img src="'.$imagencierre.'" class="img-rounded" alt="Foto de la novedad" style="width: 350px;"></a>   
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
        
            case 'listar'://lo pide la tabla de la pantalla al cargar
        
        
                   
        
                    $rspta=$Novedades->centrosOP($_SESSION['IdUsuarios']);//trae los registros para la tabla
                     $centrosoperativos= Array();
                     while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                     {
                         $centrosoperativos[]=$reg->ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP;
                         
                     }
        
                    //Las condiciones se juntan en una lista y se unen con AND.
                    //La PRIMERA siempre es la regla de acceso: sin importar los demás
                    //filtros, nadie recibe novedades que su rol no puede ver.
                    //Los valores de la dirección se validan uno por uno antes de llegar a la consulta
                    $vista = hanaVista(isset($_GET['vista']) ? $_GET['vista'] : 'mias');
                    $filtros = array(
                        'desde' => isset($_GET['fechai']) ? $_GET['fechai'] : '',
                        'hasta' => isset($_GET['fechaf']) ? $_GET['fechaf'] : ''
                    );
                    $val = isset($_GET['validador']) ? $_GET['validador'] : 'todo';
                    if ($val === 'usuario') {
                        $filtros['asignadoA'] = intval($_SESSION['Idcolaborador']);
                    } elseif ($val !== 'todo' && ctype_digit((string)$val)) {
                        $filtros['proyecto'] = intval($val);
                    }
                    $filtros['estado'] = isset($_GET['validador2']) ? max(0, intval($_GET['validador2'])) : 0;

                    $rspta=$Novedades->listarFiltrado(hanaCondNovedades($vista), $filtros);
                     
                    
                   
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
                                     
                            //El número del estado se cambia por su nombre para mostrarlo en la tabla
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
		
            //Entrega la foto de una novedad (f=ini la del registro, f=fin la del cierre),
            //solo a quien puede ver esa novedad
            case 'foto':
                    $idFoto = isset($_GET['id']) ? intval($_GET['id']) : 0;
                    if (!hanaPuedeVerNovedad($idFoto)) { http_response_code(403); echo 'No tienes permiso para ver esta foto.'; break; }
                    $fotos = $Novedades->fotos($idFoto);
                    $cual = (isset($_GET['f']) && $_GET['f'] === 'fin') ? 'FIN' : 'INI';
                    $valor = $fotos ? (string)$fotos[$cual] : '';
                    $partes = hanaArchivoPartes($valor);
                    if (!$partes || strpos($partes[0], 'image/') !== 0) {
                        http_response_code(404); echo 'La foto ya no está en el servidor.'; break;
                    }
                    list($tipo, $bytes) = $partes;
                    hanaArchivoEntregar($bytes, $tipo, 'novedad-' . $idFoto . ($cual === 'FIN' ? '-cierre' : '') . '.' . (explode('/', $tipo)[1] === 'jpeg' ? 'jpg' : explode('/', $tipo)[1]), true);
            break;

		     //Opciones del filtro de proyectos (todas, las asignadas a mí o un proyecto)
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