<?php
$modulosAcceso=explode(",",$_SESSION['Modulos']);
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
  <link href="../public/css/alertas.css?v=2" rel="stylesheet">
  <!-- Estilos comunes: tablas en celular, indicador de carga y barra de progreso -->
  <link href="../public/css/comun.css?v=3" rel="stylesheet">
  <!-- Tablas adaptables al celular (la libreria ya venia en vendors) -->
  <link href="../vendors/datatables.net-responsive-bs/css/responsive.bootstrap.min.css" rel="stylesheet">

  <!--Alertify Style -->
  <link href="../vendors/alertify/alertify.bootstrap.css" rel="stylesheet">
  <link href="../vendors/alertify/alertify.core.css" rel="stylesheet">
  <link href="../vendors/alertify/alertify.default.css" rel="stylesheet">

  <!-- Aspecto más cálido y humano (tipografía Nunito, esquinas suaves, fondos cálidos).
       Va de último para quedar encima de la plantilla. Quitar estas dos líneas deja el aspecto anterior -->
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link href="../public/css/humano.css?v=16" rel="stylesheet">
  <!-- DATATABLES >
  <link rel="stylesheet" type="text/css" href="../js/datatables/jquery.dataTables.min.css">
    <link href="../js/datatables/buttons.dataTables.min.css" rel="stylesheet"/>
    <link href="../js/datatables/responsive.dataTables.min.css" rel="stylesheet"/-->

  <!-- Paleta de colores de HANA. Va de ULTIMO a proposito: asi sus colores
       mandan sobre los de la plantilla sin tener que editar custom.min.css -->
  <link href="../public/css/paleta.css?v=1" rel="stylesheet">
  <!-- Campana de notificaciones -->
  <link href="../public/css/campana.css?v=2" rel="stylesheet">

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
    <!-- Mis notificaciones: los circulos de colores los llena CampanaAjax.js -->
    <li class="hana-menu-notif"><a href="MisNotificacionesVista.php"><i class="fa fa-bell"></i>Mis notificaciones
        <span class="hana-puntos" id="puntosMenu"></span></a></li>

  <?php if(in_array("1M",$modulosAcceso) or in_array("2M",$modulosAcceso) or in_array("3M",$modulosAcceso) or in_array("4M",$modulosAcceso) or in_array("5M",$modulosAcceso) or in_array("6M",$modulosAcceso) or in_array("24M",$modulosAcceso)){ ?>
    <li><a><i class="fa fa-cog "></i>Configuración<span class="fa fa-chevron-down"></span></a>
      <ul class="nav child_menu">
          
        <?php if(in_array("3M",$modulosAcceso)){ ?><li><a href="ColaboradoresVista.php">Colaboradores</a></li> <?php } ?>
        <?php if(in_array("4M",$modulosAcceso)){ ?><li><a href="cargosVista.php">Cargos</a></li> <?php } ?>
        <?php if(in_array("6M",$modulosAcceso)){ ?><li><a href="proyectosVista.php">Proyectos</a></li> <?php } ?> 
        <?php if(in_array("5M",$modulosAcceso)){ ?><li><a href="centroOperativoVista.php">Centros de operación</a></li> <?php } ?>
        <?php if(in_array("2M",$modulosAcceso)){ ?><li><a href="RolesVista.php">Roles de usuario</a></li> <?php } ?> 
        <?php if(in_array("24M",$modulosAcceso)){ ?><li><a href="ParametrosVista.php">Parámetros del sistema</a></li> <?php } ?>
        <?php if(in_array("1M",$modulosAcceso)){ ?><li><a href="UsuariosVista.php">Usuarios</a></li> <?php } ?>  
      </ul>
    </li><?php } ?>
      
    <?php if(in_array("7M",$modulosAcceso) or in_array("8M",$modulosAcceso) or in_array("9M",$modulosAcceso)){ ?>  
    <li><a><i class="fa fa-cog"></i>Parámetros de novedades<span class="fa fa-chevron-down"></span></a>
      <ul class="nav child_menu">
        <?php if(in_array("7M",$modulosAcceso)){ ?><li><a href="TitulosNovedadesVista.php">Títulos de novedades</a></li><?php } ?> 
        <?php if(in_array("8M",$modulosAcceso)){ ?><li><a href="EstadosVista.php">Estados de relevancia</a></li><?php } ?>   
        <?php if(in_array("9M",$modulosAcceso)){ ?><li><a href="ObservadorVista.php">Observadores</a></li><?php } ?>  
      </ul>
    </li><?php } ?>
      
    <?php if(in_array("10M",$modulosAcceso)){ ?><li><a href="novedadesVista.php"><i class="fa fa-comment "></i>Novedades</a> </li><?php } ?> 

    <!-- Requisiciones: módulo 15M -->
    <?php if(in_array("15M",$modulosAcceso) or in_array("17M",$modulosAcceso) or in_array("18M",$modulosAcceso)){ ?><li><a href="RQVista.php"><i class="fa fa-wrench"></i>Requisiciones (RQ)</a></li><?php } ?>
    
      
    <?php
      //El reporte del coordinador (Hoy en qué estás, arqueos, cronograma, vacantes,
      //comunicaciones) solo aparece para quien coordina un proyecto. Los jefes de
      //peaje hacen RQ y listas de chequeo
      require_once __DIR__ . '/../Modelo/HanaDB.php';
      require_once __DIR__ . '/../Modelo/HanaConfig.php';
      $menuCoord = isset($_SESSION['Idcolaborador']) && HanaDB::esCoordinador((int)$_SESSION['Idcolaborador']);
      //Cada pantalla del coordinador sale si es coordinador, ADMIN TEC o tiene su casilla en Roles
      $mPuede = function ($m) { return HanaDB::puedeModulo($m); };
      $menuAlgunModulo = false;
      foreach (array_keys(HanaDB::$PERMISO_MODULO) as $mm) { if ($mPuede($mm)) { $menuAlgunModulo = true; break; } }
      //Oficios: también quien tenga peajes asignados (jefe de peaje o en Usuarios)
      $menuOficios = $mPuede('COMUNICACIONES') || (isset($_SESSION['Idcolaborador'], $_SESSION['IdUsuarios']) && HanaDB::fila("SELECT 1 AS ok FROM centros_operacion c WHERE c.Estado = '1' AND (c.ID_COLABORADOR_JEFE = ? OR c.ID_CENTRO_OP IN
           (SELECT ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP FROM asoc_usuarios_sistemas_x_cop WHERE ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = ?)) LIMIT 1",
        'ii', array((int)$_SESSION['Idcolaborador'], (int)$_SESSION['IdUsuarios'])));
      if ($menuOficios && HanaConfig::modulo('COMUNICACIONES')) { $menuAlgunModulo = true; }
      //Los módulos que el administrador apagó en Parámetros del sistema no salen
      $mOn = function ($m) { return HanaConfig::modulo($m); };
      //¿Quién llena las listas? Por defecto el coordinador; si se apaga, cualquiera con 11M
      $menuListas = (($menuCoord || !HanaConfig::si('LISTAS_SOLO_COORDINADOR')) and in_array("11M",$modulosAcceso)) or in_array("12M",$modulosAcceso) or in_array("21M",$modulosAcceso);
    ?>
    <?php if(in_array("11M",$modulosAcceso) or in_array("12M",$modulosAcceso) or in_array("5M",$modulosAcceso) or in_array("19M",$modulosAcceso) or in_array("21M",$modulosAcceso) or $menuCoord or $menuAlgunModulo){ ?>  
    <!-- Fase 2: "Listas de chequeo" pasa a llamarse Reporte diario y reúne sus secciones -->
    <li><a><i class="fa fa-calendar-check-o"></i>Reporte diario<span class="fa fa-chevron-down"></span></a>
      <ul class="nav child_menu">
        <li><a href="ReporteDiarioVista.php">Todo el reporte</a></li>
        <?php if(in_array("21M",$modulosAcceso)){ ?><li><a href="AdminReporteVista.php">Administración</a></li><?php } ?>
        <?php if($mPuede('HOY') and $mOn('HOY')){ ?><li><a href="HoyVista.php">Hoy en qué estás</a></li><?php } ?>
        <?php if(($mPuede('ARQUEOS') or in_array("5M",$modulosAcceso) or in_array("36M",$modulosAcceso)) and $mOn('ARQUEOS')){ ?><li><a href="ArqueosVista.php">Arqueos</a></li><?php } ?>
        <?php if(($mPuede('CRONOGRAMA') or in_array("5M",$modulosAcceso) or in_array("20M",$modulosAcceso) or in_array("21M",$modulosAcceso) or in_array("22M",$modulosAcceso)) and $mOn('CRONOGRAMA')){ ?><li><a href="CronogramaVista.php">Cronograma y vehículo</a></li><?php } ?>
        <?php if($mPuede('VACANTES') and $mOn('VACANTES')){ ?><li><a href="VacantesVista.php">Vacantes</a></li><?php } ?>
        <?php if($menuOficios and $mOn('COMUNICACIONES')){ ?><li><a href="ComunicacionesVista.php">Comunicaciones y oficios</a></li><?php } ?>
        <?php if(in_array("19M",$modulosAcceso)){ ?><li><a href="ControlDiarioVista.php">Reporte general</a></li><?php } ?>
        <?php if(in_array("19M",$modulosAcceso)){ ?><li><a href="TableroVista.php">Tablero por proyectos</a></li><?php } ?>
        <?php if($menuListas and $mOn('LISTAS')){ ?><li><a href="ListasVista.php">Listas de chequeo</a></li><?php } ?>    
        <?php if(in_array("12M",$modulosAcceso)){ ?><li><a href="GruposListasVista.php">Editar listas</a></li><?php } ?> 
        <?php if(in_array("12M",$modulosAcceso)){ ?><li><a href="NotificacionesVista.php">Correos de listas</a></li><?php } ?>
      </ul>
    </li><?php } ?> 
      
    <?php //Fase 3: ausentismo. Lo registran el coordinador, el jefe de su peaje y quien tenga 25M;
      //lo consultan 20M, 21M y 22M
      $menuJefe = isset($_SESSION['Idcolaborador']) && HanaConfig::si('AUS_JEFES_EDITAN')
                  && HanaDB::fila("SELECT 1 AS ok FROM centros_operacion WHERE Estado = '1' AND ID_COLABORADOR_JEFE = ? LIMIT 1", 'i', array((int)$_SESSION['Idcolaborador']));
      //Ausentismo y Dashboard van juntos: en uno se llena la data y el otro la muestra
      $menuAusReg = $mOn('AUSENTISMO') && ($menuCoord or $menuJefe or in_array("20M",$modulosAcceso) or in_array("21M",$modulosAcceso) or in_array("22M",$modulosAcceso) or in_array("25M",$modulosAcceso));
      $menuDash = in_array("13M",$modulosAcceso);
      if($menuAusReg or $menuDash){ ?>
    <li><a><i class="fa fa-user-times"></i> Ausentismo <span class="fa fa-chevron-down"></span></a>
      <ul class="nav child_menu">
        <?php if($menuAusReg){ ?><li><a href="AusentismoVista.php">Registrar ausentismo</a></li><?php } ?>
        <?php if($menuDash){ ?><li><a href="reporteVista.php">Dashboard</a></li><?php } ?>
      </ul>
    </li>
    <?php } ?>


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