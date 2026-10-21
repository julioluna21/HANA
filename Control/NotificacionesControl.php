<?php
/*
  HANA - Notificaciones de listas de chequeo por correo (controlador)
  ---------------------------------------------------------------------------
  Atiende la pantalla NotificacionesVista.php (Ajax/NotificacionesAjax.js):
  a quién se avisa cuando se llena un tipo de lista, y con qué frecuencia
  (al enviar, resumen diario o resumen semanal).
  Operaciones: guardar, mostrar, anular, activar, listar, selectColaborador y probar.
  Los resúmenes diario y semanal los envía el servidor con cron
  (tareas/resumen_diario.php y tareas/resumen_semanal.php), no esta dirección.
  Permiso: 12M.
*/
session_start();
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
//Los resúmenes ya no se piden por aquí: son tareas del servidor (carpeta tareas/)
if (isset($_GET['op']) && in_array($_GET['op'], array('resumenDiario', 'resumenSemanal'), true)) {
    http_response_code(410);
    echo "Esta dirección ya no se usa.";
    exit();
}
hanaGuardia(array('12M')); //Correos de listas: 12M
require_once "../Modelo/NotificacionesModelo.php";
$modeloNotificaciones = new Notificaciones();

//Datos que llegan del formulario
$idNotificacion = isset($_POST['idNotificacion']) ? limpiarCadena($_POST['idNotificacion']) : '';
$idGrupo        = isset($_POST['idGrupo'])        ? limpiarCadena($_POST['idGrupo'])        : '';
$destinatarios  = isset($_POST['destinatarios'])  ? limpiarCadena($_POST['destinatarios'])  : '';
$frecuencia     = isset($_POST['frecuencia'])     ? limpiarCadena($_POST['frecuencia'])     : '';
$asunto         = isset($_POST['asunto'])         ? limpiarCadena($_POST['asunto'])         : '';

switch ($_GET["op"]) {

  //Crea o actualiza la configuración de una notificación
  case 'guardar':
    try {
      //Se valida que los correos escritos tengan forma de correo
      $correosValidos = array();
      foreach (explode(",", $destinatarios) as $correo) {
          $correo = trim($correo);
          if ($correo === '') { continue; }
          if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
              //Se corta aquí y se avisa cuál es el correo malo
              http_response_code(400);
              echo "Correo inválido: ".$correo;
              exit();
          }
          $correosValidos[] = $correo;
      }
      if (count($correosValidos) == 0) { http_response_code(400); echo "Debes indicar al menos un destinatario."; exit(); }

      $destinatarios = implode(",", $correosValidos); //se guardan normalizados

      //Sin número es una notificación nueva; con número, se edita la que existe
      if (empty($idNotificacion)) {
          $resultado = $modeloNotificaciones->insertar($idGrupo, $destinatarios, $frecuencia, $asunto);
      } else {
          $resultado = $modeloNotificaciones->editar($idNotificacion, $idGrupo, $destinatarios, $frecuencia, $asunto);
      }
      echo $resultado ? http_response_code(200) : http_response_code(400);
    } catch (\Throwable $error) {
      http_response_code(404); echo json_encode($error);
    }
    break;

  //Trae una configuración para cargarla en el formulario
  case 'mostrar':
    $resultado = $modeloNotificaciones->mostrar($idNotificacion);
    echo json_encode($resultado);
    break;

  //Desactiva una notificación
  case 'anular':
    $resultado = $modeloNotificaciones->anular($idNotificacion);
    echo $resultado ? http_response_code(200) : http_response_code(400);
    break;

  //Vuelve a activarla
  case 'activar':
    $resultado = $modeloNotificaciones->activar($idNotificacion);
    echo $resultado ? http_response_code(200) : http_response_code(400);
    break;

  //Listado para la tabla de la pantalla
  case 'listar':
    $resultado = $modeloNotificaciones->listar();
    $filasTabla = array();
    while ($fila = $resultado->fetch_object()) {

      //La frecuencia, como etiqueta de color
      if ($fila->FRECUENCIA == 'inmediato') {
          $etiquetaFrecuencia = '<span class="label label-success">Al enviar</span>';
      } elseif ($fila->FRECUENCIA == 'diario') {
          $etiquetaFrecuencia = '<span class="label label-primary">Resumen diario</span>';
      } else {
          $etiquetaFrecuencia = '<span class="label label-info">Resumen semanal</span>';
      }

      //Botones según si está activa o anulada
      if ($fila->ESTADO) {
          $acciones = '<button class="btn btn-warning btn-xs" onclick="mostrar('.$fila->ID_NOTIFICACION.')"><i class="fa fa-pencil"></i></button>'
                    . ' <button class="btn btn-danger btn-xs" onclick="anular('.$fila->ID_NOTIFICACION.')"><i class="fa fa-trash"></i></button>';
          $estado = '<span class="label label-success">Activa</span>';
      } else {
          $acciones = '<button class="btn btn-primary btn-xs" onclick="activar('.$fila->ID_NOTIFICACION.')"><i class="fa fa-check"></i></button>';
          $estado = '<span class="label label-default">Anulada</span>';
      }

      $filasTabla[] = array(
        "0" => $acciones,
        "1" => $fila->NOM_GRUPO_LISTA_CHEQUEO,
        "2" => $etiquetaFrecuencia,
        "3" => str_replace(",", "<br>", $fila->DESTINATARIOS), //un correo por línea
        "4" => $fila->ULTIMO_ENVIO ? $fila->ULTIMO_ENVIO : '<span style="color:#bbb;">—</span>',
        "5" => $estado
      );
    }
    echo json_encode(array("sEcho"=>1, "iTotalRecords"=>count($filasTabla), "iTotalDisplayRecords"=>count($filasTabla), "aaData"=>$filasTabla));
    break;

  //Buscador de colaboradores registrados, para no escribir el correo a mano
  case 'selectColaborador':
    $busqueda = isset($_GET['q']) ? limpiarCadena($_GET['q']) : '';
    $resultado = $modeloNotificaciones->selectColaboradores($busqueda);
    $opciones = array();
    while ($fila = $resultado->fetch_object()) {
      $opciones[] = array(
        "id"   => $fila->MAIL_COLABORADOR, //lo que se guarda es el correo
        "text" => $fila->NOM_COLABORADOR." (".$fila->MAIL_COLABORADOR.")"
      );
    }
    echo json_encode($opciones);
    break;

  //Envía una prueba a los destinatarios, para revisar antes de dejarla automática
  case 'probar':
    require_once __DIR__ . "/EnvioNotificacionesControl.php";
    $html = hanaPlantillaCorreo(
        'Correo de prueba',
        'Configuración de notificaciones HANA',
        '<p>Este es un correo de prueba enviado desde HANA.</p>
         <p>Si lo estás recibiendo, la configuración de notificaciones
         quedó funcionando correctamente para esta dirección.</p>'
    );
    if (!empty($idNotificacion)) { Bitacora::contexto((int)$idNotificacion); } //la prueba queda enlazada al aviso que se está editando
    $enviado = hanaEnviarCorreo($destinatarios, 'HANA - Correo de prueba', $html);
    Bitacora::contexto();
    if ($enviado) {
        http_response_code(200);
        echo "Enviado";
    } else {
        //A la persona solo se le dice qué puede revisar ella (los correos); lo técnico del envío
        //queda en bitacora_sistema y en el error_log para el administrador
        http_response_code(400);
        echo strpos($GLOBALS['hanaUltimoError'], 'Ningun destinatario') === 0
            ? "Revisa los destinatarios: ninguno es un correo válido."
            : "El correo no salió. Revisa que los destinatarios estén bien escritos; si lo están, avísale al administrador del sistema.";
    }
    break;

}
?>