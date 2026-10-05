<?php
session_start();
if (!isset($_SESSION["IdUsuarios"])){
?>
<!DOCTYPE html>
<html lang="es">
  <head>
     <!--<base href="localhost/libros">-->  
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

   
    <link rel="apple-touch-icon" href="../public/img/consicon.ico">
    <link rel="shortcut icon" href="../public/img/consicon.ico">
     <title>HANA - Gestión de novedades</title>  
    <!-- Bootstrap -->
    <link href="../vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../vendors/nprogress/nprogress.css" rel="stylesheet">
    <!-- Animate.css -->
    <link href="../vendors/animate.css/animate.min.css" rel="stylesheet">

    <!-- Custom Theme Style -->
    <link href="../build/css/custom.min.css" rel="stylesheet">
  <!--Alertify Style -->
  <link href="../vendors/alertify/alertify.bootstrap.css" rel="stylesheet">
  <link href="../vendors/alertify/alertify.core.css" rel="stylesheet">
  <link href="../vendors/alertify/alertify.default.css" rel="stylesheet">
  <!-- Alertas personalizadas de HANA (reemplazan el alert del navegador) -->
  <link href="../public/css/alertas.css?v=2" rel="stylesheet">
  <!-- Paleta de colores de HANA -->
  <link href="../public/css/paleta.css?v=1" rel="stylesheet">
    <!-- El logo no se arrastra ni se guarda con clic derecho, y los títulos no se
       seleccionan al tocarlos. Es solo visual -->
  <style>
    .login_content img{ -webkit-user-drag:none; user-drag:none; pointer-events:none; }
    .login_content h1, .login_content label, .login_content .btn{
      -webkit-user-select:none; -moz-user-select:none; -ms-user-select:none; user-select:none;
    }
  </style>
</head>
<style>

 body{background-color: white; color:black;}   
</style>
  <body class="login" style="background-color:#FDFEFE; ">
    <div>
      <a class="hiddenanchor" id="signup"></a>
      <a class="hiddenanchor" id="signin"></a>

      <div class="login_wrapper">
        <div class="animate form login_form" id="login">
            
          <section class="login_content">
               <img src="../public/img/REGENCYL.png" style="display: block;
              margin-left: auto;
             margin-right: auto;
             width: 230px;
    height: 200px;">
            <form id="frmAcceso">
               
              <h1 style="color:black;">Iniciar Sesión</h1>
              <div>
                <input type="text" id="LoginUsuarios" name="LoginUsuarios" autofocus="autofocus" class="form-control" placeholder="Usuario" required="" />
              </div>
              <div>
                <input type="password" id="ClaveUsuarios" name="ClaveUsuarios" class="form-control" placeholder="Contraseña" required="" />
              </div>
              <div>
                <button class="btn btn-dark submit" type="submit" >Iniciar Sesión</button>
                <a ></a>
              </div>

              <div class="clearfix"></div>

              <div class="clearfix"></div>
                <br />

                <div>
                  <p>© <?php echo date("Y"); ?> Grupo Empresarial Regency · <a onclick="recuperar(true)" style="cursor: pointer;">Olvidé mi contraseña</a></p>
                </div>
            
            </form>
          </section>
        </div>
          
          
          <div class="animate form login_form" id="recuperar">
            
          <section class="login_content">
              <img src="../public/img/REGENCYL.png" style="display: block;
              margin-left: auto;
             margin-right: auto;
             width: 230px;
    height: 200px;">
               
            <form id="frmrecuperar">
               
              <h1 style="color:black;">Recuperar contraseña</h1>
              <div>
                <input type="text" id="LoginUsuariosr" name="LoginUsuarios"  class="form-control" placeholder="Usuario" required="" />
              </div>
              <div>
                <button class="btn btn-dark submit" type="submit" id="btrecuperar" >Recuperar</button>
                <!-- type="button": sin esto, Volver enviaba el formulario y restablecia la clave -->
                <button class="btn btn-dark submit" type="button" onclick="recuperar(false);" >Volver</button>  
                <a ></a>
              </div>

              <div class="clearfix"></div>

              <div class="clearfix"></div>
                <br />

                <div>
                  <p>© <?php echo date("Y"); ?> Grupo Empresarial Regency</p>
                </div>
            
            </form>
          </section>
        </div>

        
      </div>
    </div>
  </body>
  <!-- jQuery -->
  <script src="../vendors/jquery/dist/jquery.min.js"></script>
  <!-- Alertas personalizadas: debe cargar antes de LoginAjax.js -->
  <script src="../Ajax/AlertasAjax.js?v=5"></script>
  <script type="text/javascript" src="../Ajax/LoginAjax.js?v=3"></script>
  <!-- Bootstrap -->
    
    
  <script src="../vendors/bootstrap/dist/js/bootstrap.min.js"></script>
    <!-- libreria alertas con estilo-->
     <!-- Bootbox -->
    <script src="../public/js/bootbox.min.js"></script>
</html>
<?php }else{
    echo "<script> 
  window.history.go(-1)
  </script>";
}?>