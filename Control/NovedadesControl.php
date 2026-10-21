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
session_start(); //la sesión dice quién es la persona y qué permisos tiene
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
hanaGuardia(array('10M'), array('select', 'roles', 'mostrarnovedad', 'foto')); //Novedades: 10M. Ver una novedad o su foto (desde la campana) solo pide sesión
require_once "../Modelo/NovedadModelo.php"; //consultas de novedades
require_once "AccesoHelper.php"; //quién puede ver qué: la misma regla para novedades, RQ y campana
require_once __DIR__ . '/ArchivoHelper.php'; //las fotos se guardan en la base como texto base64
require '../public/PHPMailer-master/src/Exception.php';
                require '../public/PHPMailer-master/src/PHPMailer.php';
                require '../public/PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/CorreoHelper.php'; //envío y plantilla de los correos (la cuenta está en Conexion/Global.php)
                use PHPMailer\PHPMailer\PHPMailer;
                use PHPMailer\PHPMailer\Exception;

$modeloNovedades = new Novedades(); //objeto del modelo: con él se hacen las consultas
setlocale(LC_ALL, 'es-Es');      //fechas en español
date_default_timezone_set("America/Bogota");

//Lo que llega del formulario. Los textos se guardan como en los demás módulos de
//siempre (con limpiarCadena: ya convertidos para HTML), así que se pintan tal cual
$idNovedad     = isset($_POST["IDnovedad"]) ? intval($_POST["IDnovedad"]) : 0;     //vacío = novedad nueva; con número = respuesta
$idTitulo      = isset($_POST["titulo"]) ? intval($_POST["titulo"]) : 0;
$idAsignado = isset($_POST["colaborador"]) ? intval($_POST["colaborador"]) : 0; //a quién se asigna (con 42M)
$prioridad     = isset($_POST["prioridad"]) ? strtoupper(trim(limpiarCadena($_POST["prioridad"]))) : ""; //ALTO, MEDIO o BAJO
$idCentro    = isset($_POST["centroop"]) ? intval($_POST["centroop"]) : 0;
$validez       = isset($_POST["validez"]) ? limpiarCadena($_POST["validez"]) : "";
$estadoElegido = isset($_POST["estadoNovedad"]) ? trim((string)$_POST["estadoNovedad"]) : ""; //estado que deja la respuesta: '', 2, 3 o 4
//La descripción, o el texto de la respuesta. Los saltos de línea quedan como uno solo (\n),
//como los cuenta la pantalla
$novedad       = isset($_POST["novedad"]) && is_string($_POST["novedad"]) ? limpiarCadena(str_replace("\r\n", "\n", $_POST["novedad"])) : "";
$rolDestino    = isset($_POST["rolDestino"]) ? intval($_POST["rolDestino"]) : 0;     //rol al que se notifica
$fecha  = date("Y-m-d_H:i:s"); //ahora, como se guarda en las novedades
$fechaCalculo = date("Y-m-j H:i:s"); //ahora, para calcular la fecha límite

//Largo máximo de los textos (la descripción es varchar(600) en la base)
const NOV_LARGO_DESCRIPCION = 600;
const NOV_LARGO_RESPUESTA = 5000;
//Cuántos caracteres ocupa el texto ya guardado: < > " ' & ocupan más de uno (&amp;...)
function novLargoGuardado($textoGuardado)
{
    return mb_strlen(stripcslashes((string)$textoGuardado), 'UTF-8'); //limpiarCadena agrega barras para la consulta (\n, \'...) que no se guardan
}

//Sin la casilla 42M (asignar novedades) la novedad queda asignada a quien la registra
if (!hanaTienePermiso(PERMISO_ASIGNAR_NOVEDAD)) {
    $idAsignado = (int)$_SESSION['Idcolaborador'];
}

//Las reglas de una respuesta, las mismas que muestra la pantalla:
//  - una novedad Cerrada ya no recibe respuestas;
//  - una Finalizada solo la responde quien tiene permiso de auditoría;
//  - Cerrar (4) pide permiso de auditoría;
//  - responder sin cambiar el estado (un comentario) también pide auditoría:
//    los demás escogen En proceso (2) o Finalizada (3);
//  - el texto es obligatorio.
//Devuelve el mensaje para la persona, o '' si la respuesta se puede guardar
function novProblemaRespuesta($estadoActual, $estadoNuevo, $texto, $esAuditoria)
{
    if ($estadoActual === 0) { return 'esa novedad ya no existe. Actualiza la página.'; }
    if (!in_array((string)$estadoNuevo, array('', '2', '3', '4'), true)) { return 'ese estado no existe. Escoge En proceso, Finalizada o Cerrada.'; }
    if ($estadoActual === Novedades::CERRADA) { return 'esta novedad ya está cerrada: no recibe más respuestas.'; }
    if ($estadoActual === Novedades::FINALIZADA && !$esAuditoria) { return 'esta novedad ya está finalizada: solo quien tiene permiso de auditoría puede responderla o cerrarla.'; }
    if ((string)$estadoNuevo === '4' && !$esAuditoria) { return 'cerrar una novedad pide el permiso de auditoría.'; }
    if ((string)$estadoNuevo === '' && !$esAuditoria) { return 'escoge el estado de la respuesta: En proceso o Finalizada.'; }
    if (trim(html_entity_decode(stripcslashes($texto), ENT_QUOTES, 'UTF-8')) === '') { return 'escribe la respuesta.'; }
    if (novLargoGuardado($texto) > NOV_LARGO_RESPUESTA) { return 'la respuesta es muy larga: máximo ' . NOV_LARGO_RESPUESTA . ' caracteres.'; }
    return '';
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
    $foto = hanaArchivoTexto($bytes, $NOV_TIPOS_FOTO[$info[2]][1]);
    return hanaArchivoCabe($foto['texto']) ? $foto['texto'] : false;
}

//Lo que va en el src de la imagen: la dirección que entrega la foto (no el texto de la foto,
//para que la respuesta no cargue con todo ese peso)
function novFotoSrc($valor, $idNovedad, $cual)
{
    return '../Control/NovedadesControl.php?op=foto&amp;id=' . intval($idNovedad) . '&amp;f=' . $cual;
}

//Envía un correo a una persona, en HTML y saludándola por su nombre
//(la plantilla está en CorreoHelper.php; la cuenta, en Conexion/Global.php)
function correoenvio($correo,$mensaje,$asunto,$nombre='',$boton=null){
                         $mail = new PHPMailer();
                         hanaConfigurarSmtp($mail); //cuenta, servidor, UTF-8 y HTML
                         $mail->FromName = 'HANA - Gestión de novedades';
                         $mail->addAddress($correo, hanaNombreBonito($nombre));
                         $mail->Subject  = $asunto;
                         $mail->Body     = hanaCorreoHtml($nombre, $asunto, $mensaje, $boton);
                         $mail->AltBody  = trim(strip_tags(str_replace(array('<br>', '</p>', '</tr>'), "\n", $mensaje)));
                         return hanaEnviar($mail, 'NOVEDADES'); //queda anotado en la bitácora (ver CorreoHelper.php)
}

//Avisa a todas las personas de un rol: cada una recibe su propio correo, dirigido
//a ella y con su nombre.
//$destinatarios = array(array('correo' => ..., 'nombre' => ...), ...)
//Devuelve true si le llegó a todas (o si no había a quién avisar)
function correoenvioRol($destinatarios,$mensaje,$asunto,$boton=null){
                         $todosEnviados = true;
                         foreach ($destinatarios as $destinatario) {
                             if (!correoenvio($destinatario['correo'], $mensaje, $asunto, $destinatario['nombre'], $boton)) { $todosEnviados = false; }
                         }
                         return $todosEnviados;
}

//Operaciones
switch ($_GET["op"])
    {
            case 'guardar':
                if (!$idNovedad) {
                    //-----------------------------------------------------------
                    // Novedad nueva
                    //-----------------------------------------------------------
                    //La descripción: obligatoria y de máximo 600 caracteres
                    if (trim(html_entity_decode(stripcslashes($novedad), ENT_QUOTES, 'UTF-8')) === '') {
                        echo 'Error: escribe la descripción de la novedad.';
                        break;
                    }
                    if (novLargoGuardado($novedad) > NOV_LARGO_DESCRIPCION) {
                        echo 'Error: la descripción es muy larga: máximo ' . NOV_LARGO_DESCRIPCION . ' caracteres (los símbolos < > " \' & ocupan más de uno). Hazla más corta.';
                        break;
                    }
                    //Título, centro y persona asignada: que existan y estén activos
                    if (!$modeloNovedades->tituloActivo($idTitulo)) { echo 'Error: elige el título de la novedad.'; break; }
                    if (!$modeloNovedades->centroActivo($idCentro)) { echo 'Error: elige el centro de operación.'; break; }
                    if ($idAsignado <= 0) { echo 'Error: elige a quién se le asigna la novedad.'; break; }
                    if (!$modeloNovedades->colaboradorActivo($idAsignado)) { echo 'Error: la persona asignada no existe o está inactiva.'; break; }

                    //El rol a notificar es obligatorio al crear una novedad
                    if (!hanaRolValido($rolDestino)) {
                        echo 'Error: elige el rol al que se le va a notificar la novedad.';
                        break;
                    }

                    //La prioridad: ALTO, MEDIO o BAJO. Sus días están en Parámetros del sistema
                    if (!NovedadPrioridad::valida($prioridad)) {
                        echo 'Error: elige la prioridad de la novedad (ALTO, MEDIO o BAJO).';
                        break;
                    }
                    //La fecha límite: hoy más los días de su prioridad
                    $diasParaAtender = $modeloNovedades->diasParaAtender($prioridad);
                    $fechaLimite = date('Y-m-j H:i:s', strtotime('+' . $diasParaAtender . ' days', strtotime($fechaCalculo)));
                    $imagen = novGuardarFoto('miarchivo2');
                    if ($imagen === false) {
                        echo 'Error: ' . hanaArchivoMuyGrande('La foto');
                        break;
                    }
                    $idNueva = $modeloNovedades->insertar($fecha, $_SESSION['Idcolaborador'], $idCentro, $idAsignado, $idTitulo, $novedad, $imagen, $validez, $prioridad, $fechaLimite, $rolDestino);
                    if (!$idNueva) {
                        echo 'Error: no se pudo guardar la novedad';
                        break;
                    }

                    //El aviso va a todas las personas del ROL elegido.
                    //Además, la novedad aparece en la campana de cada persona de ese rol
                    $datos = $modeloNovedades->correo($idNueva);
                    //Las personas del rol, con su nombre, para escribirle a cada una
                    $destinatarios = array();
                    $lista = $modeloNovedades->correosDeRol($rolDestino, $_SESSION['Idcolaborador']);
                    while ($lista && ($personaDelRol = $lista->fetch_assoc())) { $destinatarios[] = array('correo' => $personaDelRol['MAIL_COLABORADOR'], 'nombre' => $personaDelRol['NOM_COLABORADOR']); }

                    //El correo: qué pasó, dónde, qué tan urgente y para cuándo
                    $filaCorreo = function ($etiqueta, $valor) { return '<tr><td style="padding:6px 12px 6px 0;color:#7A716E;white-space:nowrap;vertical-align:top;">'.$etiqueta.'</td><td style="padding:6px 0;font-weight:bold;">'.$valor.'</td></tr>'; };
                    $mensaje = '<p style="margin:0 0 12px;">Se registró una novedad para tu rol en <strong>HANA</strong>. Estos son los datos:</p>'
                             . '<table cellpadding="0" cellspacing="0" style="margin:0 0 14px;font-size:14px;">'
                             . ($datos ? $filaCorreo('Tipo', $datos['NOM_TITULO_NOVEDADES_HALLAZGOS']) . $filaCorreo('Centro', $datos['NOM_CENTRO_OP']) . $filaCorreo('Prioridad', $datos['PRIORIDAD']) : '')
                             . $filaCorreo('Atender antes de', date('d/m/Y', strtotime($fechaLimite)))
                             . '</table>'
                             . '<div style="background:#FBF8F6;border-left:4px solid #6E1A1E;border-radius:6px;padding:12px 14px;margin:0 0 6px;">'.nl2br(stripcslashes($novedad)).'</div>';

                    if (correoenvioRol($destinatarios, $mensaje, "Nueva novedad para tu rol", array('texto' => 'Ver la novedad', 'url' => hanaUrlSistema('novedadesVista.php')))) {
                        echo "Novedad registrada con éxito";
                    } else {
                        echo "La novedad se registró, pero no fue posible enviar la notificación por correo electrónico";
                    }

                } else {
                    //-----------------------------------------------------------
                    // Respuesta a una novedad
                    //-----------------------------------------------------------
                    //Responder también exige poder ver la novedad
                    if (!hanaPuedeVerNovedad($idNovedad)) {
                        echo 'Error: no tienes permiso sobre esta novedad.';
                        break;
                    }
                    $esAuditoria = isset($_SESSION['Auditoria']) && (int)$_SESSION['Auditoria'] === 1; //puede cerrar novedades
                    $problema = novProblemaRespuesta($modeloNovedades->estadoActual($idNovedad), $estadoElegido, $novedad, $esAuditoria);
                    if ($problema !== '') { echo 'Error: ' . $problema; break; }

                    //Finalizada: se guarda la fecha y, si la hay, la foto del cierre
                    if ($estadoElegido === '3') {
                        $imagen = novGuardarFoto('miarchivo');
                        if ($imagen === false) {
                            echo 'Error: ' . hanaArchivoMuyGrande('La foto del cierre');
                            break;
                        }
                        if ($imagen !== '') { $modeloNovedades->guardarFotoCierre($idNovedad, $imagen); }
                        $modeloNovedades->guardarFechaCierre($idNovedad, $fecha);
                    }
                    //Sin estado es un comentario: la novedad sigue como estaba
                    if ($estadoElegido !== '') { $modeloNovedades->editarEstado($idNovedad, (int)$estadoElegido); }
                    $guardada = $modeloNovedades->insertarRespuesta($idNovedad, $_SESSION['Idcolaborador'], $fecha, $novedad, (int)$estadoElegido);
                    echo $guardada ? "Respuesta guardada con éxito" : "No se pudo guardar la respuesta";
                }
            break;

            //Los roles para el selector "Rol a notificar", y si puede ver todas
            case 'roles':
                    $roles = array();
                    $rolesActivos = hanaRolesActivos();
                    while ($rolesActivos && ($rol = $rolesActivos->fetch_assoc())) { $roles[] = array('id' => (int)$rol['id'], 'nombre' => html_entity_decode($rol['nombre'], ENT_QUOTES, 'UTF-8')); }
                    echo json_encode(array('roles' => $roles, 'verTodas' => hanaTienePermiso(PERMISO_VER_TODAS_NOVEDADES)), JSON_UNESCAPED_UNICODE);
                    break;

            //Trae una novedad con su detalle para abrirla
            case 'mostrarnovedad':

                    //Aunque alguien escriba el número de otra novedad a mano, el
                    //servidor solo la entrega si su rol puede verla
                    if (!hanaPuedeVerNovedad($idNovedad)) {
                        http_response_code(403);
                        echo json_encode(array('error' => 'No tienes permiso para ver esta novedad.'));
                        break;
                    }
                    $datosNovedad=$modeloNovedades->mostrar($idNovedad);
                    $respuestaPantalla= Array();
                    $respuestaPantalla['titulo']=$datosNovedad['NOM_TITULO_NOVEDADES_HALLAZGOS'];
                    $respuestaPantalla['fechacreacion']=$datosNovedad['FEC_CREACION'];
                    $imagencierre = $datosNovedad['FOTO_CIERRE'] != '' ? novFotoSrc($datosNovedad['FOTO_CIERRE'], $idNovedad, 'fin') : '';
                    $fotoInicial = novFotoSrc($datosNovedad['FOTO_REPORTE'], $idNovedad, 'ini');
                    $contenido="";
                
        
                    if($_SESSION['Idcolaborador']==$datosNovedad['ID_COLABORADOR_REPORTA']){
                        
                         $contenido=$contenido.'<div class="row justify-content-end ">
                            <div class="col-auto">
                                <div class="card bg-primary text-white">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <span style=" font-weight: bold;">Reportó:</span>'.$datosNovedad['NOM_COLABORADOR'].'<br>
                                        <span style=" font-weight: bold;">Centro operativo:</span>'.$datosNovedad['NOM_CENTRO_OP'].'<br>    
                                        <span style=" font-weight: bold;">Novedad asignada a:</span>'.$datosNovedad['asignado'].'<br>  
                                        <span style=" font-weight: bold;">Validez novedad:</span>'.$datosNovedad['VALIDEZ'].'<br>  
                                        <span style=" font-weight: bold;">Prioridad:</span>'.$datosNovedad['PRIORIDAD'].'<br>       
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">'.$datosNovedad['NOM_COLABORADOR']." ".$datosNovedad['FEC_CREACION'].'</small>
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
                                        <span style=" font-weight: bold;">Novedad:<br></span>'.nl2br($datosNovedad['DESCRIPCION']).'     
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">'.$datosNovedad['NOM_COLABORADOR']." ".$datosNovedad['FEC_CREACION'].'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        ';
                        
                        if($datosNovedad['FOTO_REPORTE']!=""){
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
                                            <small class="opacity-60">'.$datosNovedad['NOM_COLABORADOR']." ".$datosNovedad['FEC_CREACION'].'</small>
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
                                        <span style=" font-weight: bold;">Reportó:</span>'.$datosNovedad['NOM_COLABORADOR'].'<br>
                                        <span style=" font-weight: bold;">Centro operativo:</span>'.$datosNovedad['NOM_CENTRO_OP'].'<br>    
                                        <span style=" font-weight: bold;">Novedad asignada a:</span>'.$datosNovedad['asignado'].'<br>  
                                        <span style=" font-weight: bold;">Validez novedad:</span>'.$datosNovedad['VALIDEZ'].'<br>  
                                        <span style=" font-weight: bold;">Prioridad:</span>'.$datosNovedad['PRIORIDAD'].'<br>       
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">'.$datosNovedad['NOM_COLABORADOR']." ".$datosNovedad['FEC_CREACION'].'</small>
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
                                        <span style=" font-weight: bold;">Novedad:</span><br>'.nl2br($datosNovedad['DESCRIPCION']).'     
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">'.$datosNovedad['NOM_COLABORADOR']." ".$datosNovedad['FEC_CREACION'].'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        ';
                        
                        if($datosNovedad['FOTO_REPORTE']!=""){
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
                                            <small class="opacity-60">'.$datosNovedad['NOM_COLABORADOR']." ".$datosNovedad['FEC_CREACION'].'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>';  
                        }
                        
                    }
        
        
                    //La conversación: cada respuesta con el estado en que dejó la novedad
                    $respuestas=$modeloNovedades->mostrarRespuesta($idNovedad);
                    $nombreEstado="";
                    while ($respuestaNovedad=$respuestas->fetch_object()){
                        $nombreEstado="";
                        switch($respuestaNovedad->ESTADO_NOVEDAD)
                        {
                            //El número del estado se cambia por su nombre para mostrarlo en la tabla
                            case 0:
                                $nombreEstado="Comentario"; //respuesta que no cambió el estado
                                break;    
                            case 2:
                                $nombreEstado="En proceso";
                                break;
                            case 3:
                                $nombreEstado="Finalizada";
                                break;
                            case 4:
                                $nombreEstado="Cerrada";
                                break;    
                        }
                         
                        
                        if($_SESSION['Idcolaborador']==$respuestaNovedad->ID_COLABORADOR_RESPUESTA){
                            
                            $contenido=$contenido.'<div class="row justify-content-end ">
                             <div class="col-auto">
                                <div class="card bg-primary text-white">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        '.nl2br($respuestaNovedad->DESCR_RESPUESTA_NOVEDAD_HALLAZGOS).'     
                                        </p><br>
                                        <div>
                                            <span style=" font-weight: bold;" class="opacity-60">ESTADO: '.$nombreEstado.'</span><br> 
                                            <small class="opacity-60">'.$respuestaNovedad->NOM_COLABORADOR." ".$respuestaNovedad->FEC_RESPUESTA_NOVEDAD_HALLAZGOS.'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>';
                            
                        if($respuestaNovedad->ESTADO_NOVEDAD==3 and $imagencierre!=""){
                            
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
                                            <small class="opacity-60">'.$respuestaNovedad->NOM_COLABORADOR." ".$respuestaNovedad->FEC_RESPUESTA_NOVEDAD_HALLAZGOS.'</small>
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
                                        '.nl2br($respuestaNovedad->DESCR_RESPUESTA_NOVEDAD_HALLAZGOS).'    
                                        </p><br>
                                        <div>
                                            <span style=" font-weight: bold;" class="opacity-60">ESTADO: '.$nombreEstado.'</span><br>
                                            <small class="opacity-60">'.$respuestaNovedad->NOM_COLABORADOR." ".$respuestaNovedad->FEC_RESPUESTA_NOVEDAD_HALLAZGOS.'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>';    
                            
                        if($respuestaNovedad->ESTADO_NOVEDAD==3 and $imagencierre!=""){
                            
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
                                            <small class="opacity-60">'.$respuestaNovedad->NOM_COLABORADOR." ".$respuestaNovedad->FEC_RESPUESTA_NOVEDAD_HALLAZGOS.'</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>'; 
                            
                        } 
                            
                            
                            
                        }
                        
                    }
                   
                    $respuestaPantalla['contenido']=$contenido;
                    $respuestaPantalla['estado']=$nombreEstado;
                    $respuestaPantalla['permiso']=$_SESSION['Auditoria'];
                    //La pantalla recibe el detalle armado, el estado y si la persona puede cerrar novedades
                    echo json_encode($respuestaPantalla);
            break;        
        
            //La tabla de novedades (la pide la pantalla al cargar y al cambiar los filtros)
            case 'listar':
        
        
                   
        
                    $misCentros=$modeloNovedades->centrosAsignados($_SESSION['Idcolaborador']); //los centros asignados a la persona
                     $centrosOperativos= Array();
                     while ($centroAsignado=$misCentros->fetch_object())
                     {
                         $centrosOperativos[]=$centroAsignado->ID_CENTRO_OP;
                         
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
                    $validador = isset($_GET['validador']) ? $_GET['validador'] : 'todo';
                    if ($validador === 'usuario') {
                        $filtros['asignadoA'] = intval($_SESSION['Idcolaborador']);
                    } elseif ($validador !== 'todo' && ctype_digit((string)$validador)) {
                        $filtros['proyecto'] = intval($validador);
                    }
                    $filtros['estado'] = isset($_GET['validador2']) ? max(0, intval($_GET['validador2'])) : 0;

                    $novedades=$modeloNovedades->listarFiltrado(hanaCondNovedades($vista), $filtros);
                     
                    
                   
                    //Una fila de la tabla por cada novedad
                    $filasTabla= Array();
        
                    while ($filaNovedad=$novedades->fetch_object())
                    {
                        
                        
                      //El botón del ojo: mostrar() abre la novedad para responderla y solover()
                      //solo para leerla. Se responde si el peaje es de los asignados a la persona
                      //y la novedad sigue abierta (una Finalizada solo la responde auditoría)
                      $botones="";  
                     if(in_array($filaNovedad->ID_CENTRO_OP,$centrosOperativos)){
                         
                         
                        if($filaNovedad->ESTADO_NOVEDAD!=3 and $filaNovedad->ESTADO_NOVEDAD!=4){
                        $botones='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$filaNovedad->ID_NOVEDADES_HALLAZGOS.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN>';     
                            
                        }else if($filaNovedad->ESTADO_NOVEDAD==3 and $_SESSION['Auditoria']==1){
                        $botones='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="mostrar('.$filaNovedad->ID_NOVEDADES_HALLAZGOS.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN>';     
                        }else if(($filaNovedad->ESTADO_NOVEDAD==3 and $_SESSION['Auditoria']==0) or $filaNovedad->ESTADO_NOVEDAD==4){
                            $botones='<SPAN title="Editar">
                                            <button class="btn btn-success" onclick="solover('.$filaNovedad->ID_NOVEDADES_HALLAZGOS.')">
                                                    <i class="fa fa-eye" style="color:white;"></i></button></SPAN>'; 
                            
                        }
                  
                     }else{
                         
                        $botones='<SPAN title="Editar">
                        <button class="btn btn-success" onclick="solover('.$filaNovedad->ID_NOVEDADES_HALLAZGOS.')">
                        <i class="fa fa-eye" style="color:white;"></i></button></SPAN>';   
                         
                     }    
               
                        $nombreEstado="";
                         switch($filaNovedad->ESTADO_NOVEDAD)
                        {
                                     
                            //El número del estado se cambia por su nombre para mostrarlo en la tabla
                            case 1:
                                $nombreEstado="Asignada";
                                break;     
                            case 2:
                                $nombreEstado="En proceso";
                                break;
                            case 3:
                                $nombreEstado="Finalizada";
                                break;
                            case 4:
                                $nombreEstado="Cerrada";
                                break;    
                        }
                        
                         //Mano arriba (verde) si se atendió o va a tiempo; abajo (roja) si pasó la fecha límite.
                         //Una novedad finalizada o cerrada se mide con su fecha de cierre; una abierta, con hoy
                         $indicadorPlazo="";
                         if($filaNovedad->FEC_CIERRE!=null and $filaNovedad->ESTADO_NOVEDAD>=3){
                             
                        if($filaNovedad->FEC_CIERRE>$filaNovedad->FECHA_LIMITE){
                         $indicadorPlazo='<SPAN title="estado">                        
                                    <i class="fa fa-thumbs-down" style="color: red;
                         font-size: 25px; "></i></SPAN>';
                    }else{
                       $indicadorPlazo='<SPAN title="estado">                        
                        <i class="fa fa-thumbs-up" style="color: green;
                         font-size: 25px; "></i></SPAN>'; 
                         }  
                             
                         }else{    
                             
                         if($fecha>$filaNovedad->FECHA_LIMITE){
                         $indicadorPlazo='<SPAN title="estado">                        
                                    <i class="fa fa-thumbs-down" style="color: red;
                         font-size: 25px; "></i></SPAN>';
                        }else{
                        $indicadorPlazo='<SPAN title="estado">                        
                        <i class="fa fa-thumbs-up" style="color: green;
                         font-size: 25px; "></i></SPAN>'; 
                         }
                            
                             
                         }
                        
                        
                            $filasTabla[]=array( //columnas de la tabla, en orden
                                    "0"=>$filaNovedad->ID_NOVEDADES_HALLAZGOS,
                                    "1"=>'<SPAN title="'.$filaNovedad->DESCRIPCION.'">'.$filaNovedad->NOM_TITULO_NOVEDADES_HALLAZGOS.'</SPAN>',
                                    "2"=>$filaNovedad->FEC_CREACION,
                                    "3"=>$filaNovedad->NOM_COLABORADOR,
                                    "4"=>$filaNovedad->asignado,
                                    "5"=>$filaNovedad->NOM_PROYECTO,
                                    "6"=>$filaNovedad->NOM_CENTRO_OP,
                                    "7"=>$filaNovedad->PRIORIDAD,
                                    "8"=>$filaNovedad->FECHA_LIMITE,
                                    "9"=>$indicadorPlazo,
                                    "10"=>$nombreEstado,
                                    "11"=>$botones,
                            );
                    }
                 
                    //El formato que espera DataTables
                    $respuestaTabla = array(
                            "sEcho"=>1,
                            "iTotalRecords"=>count($filasTabla),
                            "iTotalDisplayRecords"=>count($filasTabla),
                            "aaData"=>$filasTabla);
                    echo json_encode($respuestaTabla);
            break;
		
            //Entrega la foto de una novedad (f=ini la del registro, f=fin la del cierre),
            //solo a quien puede ver esa novedad
            case 'foto':
                    $idFoto = isset($_GET['id']) ? intval($_GET['id']) : 0;
                    if (!hanaPuedeVerNovedad($idFoto)) { http_response_code(403); echo 'No tienes permiso para ver esta foto.'; break; }
                    $fotos = $modeloNovedades->fotos($idFoto);
                    $cual = (isset($_GET['f']) && $_GET['f'] === 'fin') ? 'FIN' : 'INI';
                    $valor = $fotos ? (string)$fotos[$cual] : '';
                    $partes = hanaArchivoPartes($valor);
                    if (!$partes || strpos($partes[0], 'image/') !== 0) {
                        http_response_code(404); echo 'La foto ya no está disponible.'; break;
                    }
                    list($tipo, $bytes) = $partes;
                    hanaArchivoEntregar($bytes, $tipo, 'novedad-' . $idFoto . ($cual === 'FIN' ? '-cierre' : '') . '.' . (explode('/', $tipo)[1] === 'jpeg' ? 'jpg' : explode('/', $tipo)[1]), true);
            break;

		     //Opciones del filtro de proyectos (todas, las asignadas a mí o un proyecto)
		     case 'select':
                try {
                        $proyectos = $modeloNovedades->proyectos();
                        echo "<option value='todo'>Todos</option><option value='usuario'>Asignadas a mi usuario</option>";
                        while ($proyecto = $proyectos->fetch_object())
                        {
                                echo "<option value='$proyecto->ID_PROYECTO'>$proyecto->NOM_PROYECTO</option>";
                        }
                } catch (\Throwable $error) {
                        http_response_code(404);
                        echo json_encode($error);
                }
                break;
          
    }