<?php
/*
  HANA - Crear una contraseña nueva (página del enlace de "Olvidé mi contraseña")
  ---------------------------------------------------------------------------
  A esta página se llega desde el enlace que llega al correo. El enlace trae:
    u  el número de la persona
    v  hasta cuándo sirve (hora Unix)
    f  la firma que prueba que el enlace lo armó HANA
  La página no decide nada: al abrir, Ajax/RestablecerAjax.js le pregunta a
  Control/UsuariosControl.php?op=revisarEnlace si el enlace sirve. Si sirve, muestra
  el formulario; si no (venció, ya se usó o está incompleto), lo explica y lleva al
  inicio de sesión. La clave se guarda con ?op=restablecer.
  No pide sesión.
*/
//Los tres datos del enlace, solo para devolverlos tal cual en el formulario
$enlaceU = isset($_GET['u']) ? (int)$_GET['u'] : 0;
$enlaceV = isset($_GET['v']) ? (int)$_GET['v'] : 0;
$enlaceF = isset($_GET['f']) ? preg_replace('/[^0-9a-f]/i', '', (string)$_GET['f']) : '';
?>
<!DOCTYPE html>
<html lang="es">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- El enlace lleva datos privados: que no quede en el historial de otros sitios -->
    <meta name="referrer" content="no-referrer">
    <link rel="shortcut icon" href="../public/img/consicon.ico">
    <title>HANA - Crear contraseña nueva</title>
    <link href="../vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="../build/css/custom.min.css" rel="stylesheet">
    <link href="../vendors/alertify/alertify.bootstrap.css" rel="stylesheet">
    <link href="../vendors/alertify/alertify.core.css" rel="stylesheet">
    <link href="../vendors/alertify/alertify.default.css" rel="stylesheet">
    <link href="../public/css/alertas.css?v=2" rel="stylesheet">
    <link href="../public/css/paleta.css?v=1" rel="stylesheet">
    <style>
      /* Los mismos colores del inicio de sesión (el tema pone fondo oscuro a .login) */
      body.login{ background-color:#FDFEFE !important; color:black; }
      .login_content img{ display:block; margin:0 auto; width:230px; height:200px; -webkit-user-drag:none; pointer-events:none; }
      .login_content p{ text-align:left; }
      .rs-ayuda{ font-size:12px; color:#666; text-align:left; margin:-12px 0 16px; }
      /* Las rayas del tema a los lados del título solo se ubican bien dentro de un formulario */
      .login_content h1:before, .login_content h1:after{ display:none; }
    </style>
  </head>
  <body class="login" style="background-color:#FDFEFE;">
    <div class="login_wrapper">
      <div class="animate form login_form">
        <section class="login_content">
          <img src="../public/img/REGENCYL.png" alt="">
          <h1 style="color:black;">Crear contraseña nueva</h1>

          <!-- Mientras se revisa el enlace, y después el saludo o por qué no sirve -->
          <p id="rsAviso">Revisando el enlace...</p>

          <!-- Se muestra solo si el enlace sirve -->
          <form id="frmRestablecer" style="display:none;" autocomplete="off">
            <input type="hidden" id="rsU" name="u" value="<?php echo $enlaceU; ?>">
            <input type="hidden" id="rsV" name="v" value="<?php echo $enlaceV; ?>">
            <input type="hidden" id="rsF" name="f" value="<?php echo htmlspecialchars($enlaceF, ENT_QUOTES, 'UTF-8'); ?>">
            <div>
              <input type="password" id="rsClave" name="clave" class="form-control" placeholder="Contraseña nueva" required autocomplete="new-password" />
            </div>
            <p class="rs-ayuda" id="rsRegla">Mínimo 8 caracteres.</p>
            <div>
              <input type="password" id="rsClave2" name="clave2" class="form-control" placeholder="Repite la contraseña nueva" required autocomplete="new-password" />
            </div>
            <div>
              <button class="btn btn-dark submit" type="submit" id="rsGuardar" style="float:none;">Guardar contraseña</button>
            </div>
          </form>

          <div class="clearfix"></div>
          <br />
          <p style="text-align:center;"><a href="login.php">Volver al inicio de sesión</a></p>
          <p style="text-align:center;">© <?php echo date("Y"); ?> Grupo Empresarial Regency</p>
        </section>
      </div>
    </div>
    <script src="../vendors/jquery/dist/jquery.min.js"></script>
    <!-- Alertas personalizadas: debe cargar antes de RestablecerAjax.js -->
    <script src="../Ajax/AlertasAjax.js?v=6"></script>
    <script src="../Ajax/RestablecerAjax.js?v=1"></script>
  </body>
</html>
