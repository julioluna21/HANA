<?php
/*
  HANA - Notificaciones de listas de chequeo por correo (controlador)
  ---------------------------------------------------------------------------
  Atiende la pantalla NotificacionesVista.php (Ajax/NotificacionesAjax.js):
  a quién se avisa cuando se llena un tipo de lista, y con qué frecuencia
  (al enviar, resumen diario o resumen semanal).
  Operaciones: guardar, mostrar, anular, activar, listar, selectColaborador y probar.
  resumenDiario y resumenSemanal las llama una tarea programada, con su token.
  Permiso: 12M.
*/
session_start();
require_once __DIR__ . '/Guardia.php'; //revisa la sesión y los permisos
hanaGuardia(array('12M'), array(), array('resumenDiario', 'resumenSemanal')); //Correos de listas: 12M. Los resúmenes programados usan su token
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
    //Se enciende el diagnóstico para devolver el detalle si algo falla
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
    if (!empty($idNotificacion)) { Bitacora::contexto((int)$idNotificacion); } //la prueba queda enlazada al aviso que se está editando
    $enviado = hanaEnviarCorreo($destinatarios, 'HANA - Correo de prueba', $html);
    Bitacora::contexto();
    if ($enviado) {
        http_response_code(200);
        echo "Enviado";
    } else {
        //Se devuelve el motivo exacto para poder diagnosticar desde la pantalla
        http_response_code(400);
        echo "No se pudo enviar.\n\nMotivo: ".$GLOBALS['hanaUltimoError']
             ."\n\n--- Detalle de la conexión ---\n".$GLOBALS['hanaDebugTexto'];
    }
    break;

  /* Disparadores de los resúmenes. Se llaman desde una tarea programada:
     .../Control/NotificacionesControl.php?op=resumenDiario&token=EL_TOKEN
     .../Control/NotificacionesControl.php?op=resumenSemanal&token=EL_TOKEN
     El token evita que cualquiera dispare envíos masivos entrando por el navegador.
     El token se define en Conexion/Global.php (HANA_TOKEN_TAREAS).
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
    $frecuenciaResumen = ($_GET["op"] == 'resumenDiario') ? 'diario' : 'semanal';
    $enviados = hanaEnviarResumen($frecuenciaResumen);
    echo "Resumen $frecuenciaResumen: $enviados correo(s) enviado(s).";
    break;
}
?>