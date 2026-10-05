<?php
//Controlador de la pantalla de notificaciones automaticas por correo
session_start();
require_once __DIR__ . '/Guardia.php'; //sesión y permisos (antes no se revisaban)
hanaGuardia(array('12M'), array(), array('resumenDiario', 'resumenSemanal')); //Correos de listas: 12M. Los resúmenes programados usan su token
require_once "../Modelo/NotificacionesModelo.php";
$Notif = new Notificaciones();

//Datos que llegan del formulario
$idNotificacion = isset($_POST['idNotificacion']) ? limpiarCadena($_POST['idNotificacion']) : '';
$idGrupo        = isset($_POST['idGrupo'])        ? limpiarCadena($_POST['idGrupo'])        : '';
$destinatarios  = isset($_POST['destinatarios'])  ? limpiarCadena($_POST['destinatarios'])  : '';
$frecuencia     = isset($_POST['frecuencia'])     ? limpiarCadena($_POST['frecuencia'])     : '';
$asunto         = isset($_POST['asunto'])         ? limpiarCadena($_POST['asunto'])         : '';

switch ($_GET["op"]) {

  //Crea o actualiza una configuracion de notificacion
  case 'guardar':
    try {
      //Se valida que los correos escritos tengan forma de correo
      $limpios = array();
      foreach (explode(",", $destinatarios) as $correo) {
          $correo = trim($correo);
          if ($correo === '') { continue; }
          if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
              //Se corta aqui y se avisa cual es el correo malo
              http_response_code(400);
              echo "Correo inválido: ".$correo;
              exit();
          }
          $limpios[] = $correo;
      }
      if (count($limpios) == 0) { http_response_code(400); echo "Debes indicar al menos un destinatario."; exit(); }

      $destinatarios = implode(",", $limpios); //se guardan normalizados

      //Sin id es alta, con id es edicion
      if (empty($idNotificacion)) {
          $rspta = $Notif->insertar($idGrupo, $destinatarios, $frecuencia, $asunto);
      } else {
          $rspta = $Notif->editar($idNotificacion, $idGrupo, $destinatarios, $frecuencia, $asunto);
      }
      echo $rspta ? http_response_code(200) : http_response_code(400);
    } catch (\Throwable $th) {
      http_response_code(404); echo json_encode($th);
    }
    break;

  //Trae una configuracion para cargarla en el formulario
  case 'mostrar':
    $rspta = $Notif->mostrar($idNotificacion);
    echo json_encode($rspta);
    break;

  //Desactiva una notificacion
  case 'anular':
    $rspta = $Notif->anular($idNotificacion);
    echo $rspta ? http_response_code(200) : http_response_code(400);
    break;

  //Vuelve a activarla
  case 'activar':
    $rspta = $Notif->activar($idNotificacion);
    echo $rspta ? http_response_code(200) : http_response_code(400);
    break;

  //Listado para la tabla de la pantalla
  case 'listar':
    $rspta = $Notif->listar();
    $data = array();
    while ($row = $rspta->fetch_object()) {

      //Se traduce la frecuencia a algo entendible, con color
      if ($row->FRECUENCIA == 'inmediato') {
          $frec = '<span class="label label-success">Al enviar</span>';
      } elseif ($row->FRECUENCIA == 'diario') {
          $frec = '<span class="label label-primary">Resumen diario</span>';
      } else {
          $frec = '<span class="label label-info">Resumen semanal</span>';
      }

      //Botones segun si esta activa o anulada
      if ($row->ESTADO) {
          $acciones = '<button class="btn btn-warning btn-xs" onclick="mostrar('.$row->ID_NOTIFICACION.')"><i class="fa fa-pencil"></i></button>'
                    . ' <button class="btn btn-danger btn-xs" onclick="anular('.$row->ID_NOTIFICACION.')"><i class="fa fa-trash"></i></button>';
          $estado = '<span class="label label-success">Activa</span>';
      } else {
          $acciones = '<button class="btn btn-primary btn-xs" onclick="activar('.$row->ID_NOTIFICACION.')"><i class="fa fa-check"></i></button>';
          $estado = '<span class="label label-default">Anulada</span>';
      }

      $data[] = array(
        "0" => $acciones,
        "1" => $row->NOM_GRUPO_LISTA_CHEQUEO,
        "2" => $frec,
        "3" => str_replace(",", "<br>", $row->DESTINATARIOS), //un correo por linea
        "4" => $row->ULTIMO_ENVIO ? $row->ULTIMO_ENVIO : '<span style="color:#bbb;">—</span>',
        "5" => $estado
      );
    }
    echo json_encode(array("sEcho"=>1, "iTotalRecords"=>count($data), "iTotalDisplayRecords"=>count($data), "aaData"=>$data));
    break;

  //Buscador de colaboradores registrados, para no escribir el correo a mano
  case 'selectColaborador':
    $busqueda = isset($_GET['q']) ? limpiarCadena($_GET['q']) : '';
    $rspta = $Notif->selectColaboradores($busqueda);
    $data = array();
    while ($row = $rspta->fetch_object()) {
      $data[] = array(
        "id"   => $row->MAIL_COLABORADOR, //el valor util es el correo
        "text" => $row->NOM_COLABORADOR." (".$row->MAIL_COLABORADOR.")"
      );
    }
    echo json_encode($data);
    break;

  //Envia una prueba a los destinatarios, para verificar antes de dejarlo automatico
  case 'probar':
    //Se enciende el diagnostico para devolver el detalle si algo falla
    $GLOBALS['hanaDebug'] = true;
    $GLOBALS['hanaDebugTexto'] = '';

    require_once __DIR__ . "/EnvioNotificacionesControl.php";
    $html = hanaPlantillaCorreo(
        'Correo de prueba',
        'Configuración de notificaciones HANA',
        '<p>Este es un correo de prueba enviado desde HANA.</p>
         <p>Si lo estás recibiendo, la configuración de notificaciones
         quedó funcionando correctamente para esta dirección.</p>'
    );
    if (hanaEnviarCorreo($destinatarios, 'HANA - Correo de prueba', $html)) {
        http_response_code(200);
        echo "Enviado";
    } else {
        //Se devuelve el motivo exacto para poder diagnosticar desde la pantalla
        http_response_code(400);
        echo "No se pudo enviar.\n\nMotivo: ".$GLOBALS['hanaUltimoError']
             ."\n\n--- Detalle de la conexion ---\n".$GLOBALS['hanaDebugTexto'];
    }
    break;

  /* Disparadores de los resumenes. Se llaman desde una tarea programada:
     .../Control/NotificacionesControl.php?op=resumenDiario&token=EL_TOKEN
     .../Control/NotificacionesControl.php?op=resumenSemanal&token=EL_TOKEN
     El token evita que cualquiera dispare envios masivos entrando por el navegador.
     Ya no va escrito aquí: se define en Conexion/Global.php (HANA_TOKEN_TAREAS).
     Si no está definido, los resúmenes no se envían. */
  case 'resumenDiario':
  case 'resumenSemanal':
    require_once __DIR__ . '/../Conexion/Global.php';
    $token = isset($_GET['token']) ? (string)$_GET['token'] : '';
    if (!defined('HANA_TOKEN_TAREAS') || strlen(HANA_TOKEN_TAREAS) < 16) {
        error_log('HANA: falta HANA_TOKEN_TAREAS (mínimo 16 caracteres) en Conexion/Global.php');
        http_response_code(403); echo "Las tareas programadas no están configuradas"; exit();
    }
    //hash_equals compara sin revelar por el tiempo de respuesta cuántos caracteres acertó
    if (!hash_equals(HANA_TOKEN_TAREAS, $token)) { http_response_code(403); echo "Token inválido"; exit(); }

    require_once __DIR__ . "/EnvioNotificacionesControl.php";
    $frec = ($_GET["op"] == 'resumenDiario') ? 'diario' : 'semanal';
    $n = hanaEnviarResumen($frec);
    echo "Resumen $frec: $n correo(s) enviado(s).";
    break;
}
?>