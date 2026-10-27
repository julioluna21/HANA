<?php
$modulosAcceso=explode(",",$_SESSION['Modulos']);
//El menú pregunta al controlador qué puede ver esta persona (la vista no consulta la base)
require_once __DIR__ . '/../Control/PaginaControl.php';
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <!-- Meta, title, CSS, favicons, etc. -->
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="../public/img/consicon.ico" type="image/ico">
<title>HANA - Gestión de novedades</title>

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

  <!-- Alertas personalizadas de HANA (reemplazan las ventanas del navegador y de bootbox) -->
  <link href="../public/css/alertas.css?v=5" rel="stylesheet">
  <!-- Estilos comunes: tablas en celular, indicador de carga y barra de progreso -->
  <link href="../public/css/comun.css?v=6" rel="stylesheet">
  <!-- Tablas adaptables al celular (la librería ya venia en vendors) -->
  <link href="../vendors/datatables.net-responsive-bs/css/responsive.bootstrap.min.css" rel="stylesheet">

  <!--Alertify Style -->
  <link href="../vendors/alertify/alertify.bootstrap.css" rel="stylesheet">
  <link href="../vendors/alertify/alertify.core.css" rel="stylesheet">
  <link href="../vendors/alertify/alertify.default.css" rel="stylesheet">

  <!-- Aspecto más cálido y humano (tipografía Nunito, esquinas suaves, fondos cálidos).
       Va de último para quedar encima de la plantilla. Quitar estas dos líneas deja el aspecto anterior -->
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link href="../public/css/humano.css?v=17" rel="stylesheet">

  <!-- Paleta de colores de HANA. Va de ULTIMO a proposito: así sus colores
       mandan sobre los de la plantilla sin tener que editar custom.min.css -->
  <link href="../public/css/paleta.css?v=1" rel="stylesheet">
  <!-- Campana de notificaciones -->
  <link href="../public/css/campana.css?v=3" rel="stylesheet">
  <!-- Al imprimir o guardar como PDF: nada se corta ni se monta (va de último para que mande) -->
  <link href="../public/css/impresion.css?v=5" rel="stylesheet">

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
              <img src="../public/img/Logo1.png" class="img-rounded" alt="Logo HANA" style="width: 270%;">
            </div>
          </div>
      
          
          <div id="sidebar-menu" class="main_menu_side hidden-print main_menu">

<div class="menu_section">

  <br>

  <ul class="nav side-menu">

    <!-- Inicio: siempre visible, no depende de permisos de modulo -->
    <li><a href="InicioVista.php"><i class="fa fa-home"></i>Inicio</a></li>
    <!-- Mis notificaciones: los círculos de colores los llena CampanaAjax.js -->
    <li class="hana-menu-notif"><a href="MisNotificacionesVista.php"><i class="fa fa-bell"></i>Mis notificaciones
        <span class="hana-puntos" id="puntosMenu"></span></a></li>

  <?php if(in_array("1M",$modulosAcceso) or in_array("2M",$modulosAcceso) or in_array("3M",$modulosAcceso) or in_array("5M",$modulosAcceso) or in_array("6M",$modulosAcceso) or in_array("7M",$modulosAcceso) or in_array("24M",$modulosAcceso)){ ?>
    <li><a><i class="fa fa-cog "></i>Configuración<span class="fa fa-chevron-down"></span></a>
      <ul class="nav child_menu">
          
        <?php if(in_array("6M",$modulosAcceso)){ ?><li><a href="proyectosVista.php">Proyectos</a></li> <?php } ?> 
        <?php if(in_array("5M",$modulosAcceso)){ ?><li><a href="centroOperativoVista.php">Centros de operación</a></li> <?php } ?>
        <?php if(in_array("2M",$modulosAcceso)){ ?><li><a href="RolesVista.php">Permisos de usuario</a></li> <?php } ?> 
        <?php if(in_array("24M",$modulosAcceso)){ ?><li><a href="ParametrosVista.php">Parámetros del sistema</a></li> <?php } ?>
        <?php //Colaboradores: registrar a las personas (3M). Usuarios: su acceso (1M). 24M ve las dos ?>
        <?php if(in_array("3M",$modulosAcceso) or in_array("24M",$modulosAcceso)){ ?><li><a href="ColaboradoresVista.php">Colaboradores</a></li> <?php } ?>
        <?php if(in_array("1M",$modulosAcceso) or in_array("24M",$modulosAcceso)){ ?><li><a href="UsuariosVista.php">Usuarios</a></li> <?php } ?>
        <?php if(in_array("7M",$modulosAcceso)){ ?><li><a href="TitulosNovedadesVista.php">Títulos de novedades</a></li><?php } ?>
      </ul>
    </li><?php } ?>
      
    <?php if(in_array("10M",$modulosAcceso)){ ?><li><a href="novedadesVista.php"><i class="fa fa-comment "></i>Novedades</a> </li><?php } ?> 

    <!-- Requisiciones: ver (15M), ver todas (17M), aprobar (18M) o pedir (41M) -->
    <?php if(in_array("15M",$modulosAcceso) or in_array("17M",$modulosAcceso) or in_array("18M",$modulosAcceso) or in_array("41M",$modulosAcceso)){ ?><li><a href="RQVista.php"><i class="fa fa-wrench"></i>Requisiciones (RQ)</a></li><?php } ?>
    
      
    <?php
      //Cada pantalla sale si el rol tiene alguna de sus casillas (Permisos de usuario), la persona
      //tiene peajes asignados (Colaboradores) y el módulo está encendido (Parámetros del sistema)
      require_once __DIR__ . '/../Control/PaginaControl.php';
      $menuModuloActivo = function ($modulo) { return pagModuloActivo($modulo); };
      $menuVe = function ($modulo, $activo = null) use ($menuModuloActivo) { return pagEntra($modulo) && $menuModuloActivo($activo === null ? $modulo : $activo); };
      $menuHoy = $menuVe('HOY'); $menuArq = $menuVe('ARQUEOS'); $menuCrono = $menuVe('CRONOGRAMA');
      $menuVac = $menuVe('VACANTES'); $menuOficios = $menuVe('COMUNICACIONES');
      $menuListas = (pagPuede('LISTAS', 'llenar') || pagPuede('LISTAS', 'ver')) && pagTieneCentros() && $menuModuloActivo('LISTAS');
      $menuEditarListas = pagPuede('LISTAS', 'editar');
      $menuCorreos = pagPuede('LISTAS', 'correos') || pagPuede('TAREAS', 'editar');
      $menuCoord = $menuHoy || $menuArq || $menuCrono || $menuVac || $menuOficios;
    ?>
    <?php if($menuCoord or $menuListas or $menuEditarListas or $menuCorreos){ ?>
    <!-- Reporte diario: las listas de chequeo y las demás secciones del día -->
    <li><a><i class="fa fa-calendar-check-o"></i>Reporte diario<span class="fa fa-chevron-down"></span></a>
      <ul class="nav child_menu">
        <li><a href="ReporteDiarioVista.php">Todo el reporte</a></li>
        <?php if($menuHoy){ ?><li><a href="HoyVista.php">Hoy en qué estás</a></li><?php } ?>
        <?php if($menuArq){ ?><li><a href="ArqueosVista.php">Arqueos</a></li><?php } ?>
        <?php if($menuCrono){ ?><li><a href="CronogramaVista.php">Cronograma y vehículo</a></li><?php } ?>
        <?php if($menuVac){ ?><li><a href="VacantesVista.php">Vacantes</a></li><?php } ?>
        <?php if($menuOficios){ ?><li><a href="ComunicacionesVista.php">Comunicaciones y oficios</a></li><?php } ?>
        <?php if($menuListas){ ?><li><a href="ListasVista.php">Listas de chequeo</a></li><?php } ?>
        <?php if($menuEditarListas){ ?><li><a href="GruposListasVista.php">Editar listas</a></li><?php } ?>
        <?php if($menuCorreos){ ?><li><a href="NotificacionesVista.php">Correos automáticos</a></li><?php } ?>
      </ul>
    </li><?php } ?>

    <?php if(in_array("19M",$modulosAcceso) or in_array("37M",$modulosAcceso)){ ?>
    <!-- Consultas: donde se revisa lo que se llena en el Reporte diario. Es la misma información,
         vista de otra manera: el coordinador la llena en Reporte diario y el director de operaciones
         la revisa aquí (Reporte general: por día, módulo y proyecto, con el tablero por proyectos;
         Informe mensual: un mes, una quincena o las fechas que se elijan) -->
    <li><a><i class="fa fa-line-chart"></i>Consultas<span class="fa fa-chevron-down"></span></a>
      <ul class="nav child_menu">
        <?php if(in_array("19M",$modulosAcceso)){ ?><li><a href="ControlDiarioVista.php">Reporte general</a></li><?php } ?>
        <?php if(in_array("37M",$modulosAcceso)){ ?><li><a href="InformeMensualVista.php">Informe mensual</a></li><?php } ?>
      </ul>
    </li><?php } ?>

    <?php //Promesa de valor (el ausentismo se retiró del sistema): con su casilla y peajes asignados
      $menuPromesa = pagPromesaEntrar(); //ya revisa que el módulo esté encendido
      if($menuPromesa){ ?>
    <li><a href="PromesaValorVista.php"><i class="fa fa-clock-o"></i> Promesa de valor</a></li>
    <?php } ?>


  </ul>

</div>

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
                  <li><a href="UsuariosVista.php?op=canvu">Cambiar clave</a></li>
                  <li><a href="../Control/UsuariosControl.php?op=salir"><i class="fa fa-sign-out pull-right"></i> Cerrar Sesión </a></li>
                </ul>
              </li>

              <!-- Campana de notificaciones: rojo alta, amarillo media, blanco baja -->
              <li class="dropdown hana-campana" id="hanaCampana">
                <a href="javascript:;" class="dropdown-toggle" data-toggle="dropdown"
                   aria-expanded="false" title="Notificaciones">
                  <i class="fa fa-bell"></i>
                  <span class="hana-puntos" id="puntosCampana"></span>
                </a>
                <ul class="dropdown-menu hana-campana-menu pull-right">
                  <li class="hana-campana-cab">
                    <span>Notificaciones</span>
                    <button type="button" id="campanaTodas" style="display:none;">Marcar todas como leídas</button>
                  </li>
                  <li>
                    <ul class="hana-campana-lista" id="campanaLista">
                      <li class="hana-campana-vacia"><i class="fa fa-spinner fa-spin"></i>Cargando...</li>
                    </ul>
                  </li>
                  <li><a class="hana-campana-pie" href="MisNotificacionesVista.php">Ver todas las notificaciones</a></li>
                </ul>
              </li>
            </ul>
          </nav>
        </div>
      </div>
      <!-- /top navigation -->