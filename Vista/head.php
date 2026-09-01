<?php
$modulosAcceso=explode(",",$_SESSION['Modulos']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <!-- Meta, title, CSS, favicons, etc. -->
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="../public/img/consicon.ico" type="image/ico">
<title>Gestion Novedades</title>

  <!-- Bootstrap -->
  <link href="../vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link href="../vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
  <!-- NProgress -->
  <link href="../vendors/nprogress/nprogress.css" rel="stylesheet">
  <!-- iCheck -->
  <link href="../vendors/iCheck/skins/flat/green.css" rel="stylesheet">
  <!-- bootstrap-wysiwyg -->
  <link href="../vendors/google-code-prettify/bin/prettify.min.css" rel="stylesheet">
  <!-- Select2 -->
  <link href="../vendors/select2/dist/css/select2.min.css" rel="stylesheet">
  <!-- Switchery -->
  <link href="../vendors/switchery/dist/switchery.min.css" rel="stylesheet">
  <!-- starrr -->
  <link href="../vendors/starrr/dist/starrr.css" rel="stylesheet">
  <!-- bootstrap-daterangepicker -->
  <link href="../vendors/bootstrap-daterangepicker/daterangepicker.css" rel="stylesheet">

  <!-- Custom Theme Style -->
  <link href="../build/css/custom.min.css" rel="stylesheet">

  <!--Alertify Style -->
  <link href="../vendors/alertify/alertify.bootstrap.css" rel="stylesheet">
  <link href="../vendors/alertify/alertify.core.css" rel="stylesheet">
  <link href="../vendors/alertify/alertify.default.css" rel="stylesheet">
  <!-- DATATABLES >
  <link rel="stylesheet" type="text/css" href="../js/datatables/jquery.dataTables.min.css">
    <link href="../js/datatables/buttons.dataTables.min.css" rel="stylesheet"/>
    <link href="../js/datatables/responsive.dataTables.min.css" rel="stylesheet"/-->

</head>
 
<body class="nav-md">
  <div class="container body">
    <div class="main_container">
      <div class="col-md-3 left_col">
        <div class="left_col scroll-view">
          <br>

         
<!-- Logo -->
 <div class="profile clearfix">
            <div class="profile_pic">
              <img src="../public/img/Logo1.png" class="img-rounded" alt="Cinque Terre"style="width: 270%;">
            </div>
          </div>
      
          
          <div id="sidebar-menu" class="main_menu_side hidden-print main_menu">

<div class="menu_section">

  <br>

  <ul class="nav side-menu">

  <?php if(in_array("1M",$modulosAcceso) or in_array("2M",$modulosAcceso) or in_array("3M",$modulosAcceso) or in_array("4M",$modulosAcceso) or in_array("5M",$modulosAcceso) or in_array("6M",$modulosAcceso)){ ?>
    <li><a><i class="fa fa-cog "></i>Configuración<span class="fa fa-chevron-down"></span></a>
      <ul class="nav child_menu">
          
        <?php if(in_array("3M",$modulosAcceso)){ ?><li><a href="ColaboradoresVista.php">Colaboradores</a></li> <?php } ?>
        <?php if(in_array("4M",$modulosAcceso)){ ?><li><a href="cargosVista.php">Cargos</a></li> <?php } ?>
        <?php if(in_array("6M",$modulosAcceso)){ ?><li><a href="proyectosVista.php">Proyectos</a></li> <?php } ?> 
        <?php if(in_array("5M",$modulosAcceso)){ ?><li><a href="centroOperativoVista.php">Centros de operación</a></li> <?php } ?>
        <?php if(in_array("2M",$modulosAcceso)){ ?><li><a href="RolesVista.php">Roles usuario</a></li> <?php } ?> 
        <?php if(in_array("1M",$modulosAcceso)){ ?><li><a href="UsuariosVista.php">Usuario</a></li> <?php } ?>  
      </ul>
    </li><?php } ?>
      
    <?php if(in_array("7M",$modulosAcceso) or in_array("8M",$modulosAcceso) or in_array("9M",$modulosAcceso)){ ?>  
    <li><a><i class="fa fa-cog"></i>Parametros Novedades<span class="fa fa-chevron-down"></span></a>
      <ul class="nav child_menu">
        <?php if(in_array("7M",$modulosAcceso)){ ?><li><a href="TitulosNovedadesVista.php">Titulos novedades</a></li><?php } ?> 
        <?php if(in_array("8M",$modulosAcceso)){ ?><li><a href="EstadosVista.php">Estados Relevancia</a></li><?php } ?>   
        <?php if(in_array("9M",$modulosAcceso)){ ?><li><a href="ObservadorVista.php">Observadores</a></li><?php } ?>  
      </ul>
    </li><?php } ?>
      
    <?php if(in_array("10M",$modulosAcceso)){ ?><li><a href="novedadesVista.php"><i class="fa fa-comment "></i>Novedades</a> </li><?php } ?> 
    
      
    <?php if(in_array("11M",$modulosAcceso) or in_array("12M",$modulosAcceso)){ ?>  
    <li><a><i class="fa fa-edit"></i>Listas de chequeo<span class="fa fa-chevron-down"></span></a>
      <ul class="nav child_menu">
        <?php if(in_array("12M",$modulosAcceso)){ ?><li><a href="GruposListasVista.php">Grupos Listas</a></li><?php } ?> 
        <?php if(in_array("11M",$modulosAcceso)){ ?><li><a href="ListasVista.php">Listas</a></li><?php } ?>    
      </ul>
    </li><?php } ?> 
      
    <?php if(in_array("13M",$modulosAcceso)){ ?><li><a href="reporteVista.php"><i class="fa fa-bar-chart"></i>Dashboard</a> </li><?php } ?>   


    <!--<li><a href="javascript:void(0)"><i class="fa fa-laptop"></i> Graficas <span
          class="label label-success pull-right">Muy pronto...</span></a>
    </li>-->

  </ul>

</div>

<!--<div class="menu_section">

  <h3>Configuración</h3>

  <ul class="nav side-menu">


    <li><a><i class="fa fa-windows"></i> Menú 1 <span class="fa fa-chevron-down"></span></a>
      <ul class="nav child_menu">
        <li><a href="#">Item 1</a></li>
        <li><a href="#">Item 2</a></li>
      </ul>
    </li>

  </ul>

</div>-->

</div>
</div>
      </div>


      <!-- top navigation -->
      <div class="top_nav">
        <div class="nav_menu">
          <nav>
            <div class="nav toggle">
              <a id="menu_toggle"><i class="fa fa-bars"></i></a>
            </div>

            <ul class="nav navbar-nav navbar-right">

              <li class="">
                <a href="javascript:;" class="user-profile dropdown-toggle" data-toggle="dropdown"
                  aria-expanded="false">
                  <?php echo isset($_SESSION['Nombre']) ? $_SESSION['Nombre'] : ""; ?>
                  <span class=" fa fa-angle-down"></span>
                </a>
                <ul class="dropdown-menu dropdown-usermenu pull-right">
                  <li><a href="UsuariosVista.php?op=canvu">Cambiar Clave</a></li>
                  <li><a href="../Control/UsuariosControl.php?op=salir"><i class="fa fa-sign-out pull-right"></i> Cerrar Sesión </a></li>
                </ul>
              </li>
            </ul>
          </nav>
        </div>
      </div>
      <!-- /top navigation -->