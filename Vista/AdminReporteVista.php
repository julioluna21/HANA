<?php
/*
  Pantalla sin uso: la Administración del reporte diario se quitó porque repetía lo
  mismo que el Reporte general (ControlDiarioVista.php), que ya muestra todo lo que
  llenan coordinadores y jefes, por módulo y por proyecto. Si alguien entra por la
  dirección, va al Reporte general. El código de abajo se conserva solo como referencia.
*/
header('Location: ControlDiarioVista.php');
exit;
?>
<?php
//Administración del reporte diario - pantalla de entrada
//Para el administrador (permiso 21M): consultar todo lo que llenan los
//coordinadores. Desde aquí se llega a las cinco consultas y a las
//pantallas de control
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    if (in_array("21M", $modulosAcceso)) {
        //[archivo, icono, título, descripción, permiso extra (o '')]
        $mosaicos = array(
            array('AdminHoyVista.php', 'fa-map-marker', 'Hoy en qué estás', 'Lo que registró cada coordinador, día por día', ''),
            array('AdminArqueosVista.php', 'fa-money', 'Arqueos', 'Todos los arqueos, con su PDF', ''),
            array('AdminCronogramaVista.php', 'fa-calendar', 'Cronograma y vehículo', 'El de cada coordinador, con el estado del vehículo', ''),
            array('AdminVacantesVista.php', 'fa-user-plus', 'Vacantes', 'Las de todos los centros y su acuerdo de servicio', ''),
            array('AdminComunicacionesVista.php', 'fa-inbox', 'Comunicaciones y oficios', 'Las de todos los proyectos y su tiempo de respuesta', ''),
            array('ControlDiarioVista.php', 'fa-list-alt', 'Reporte general del día', 'El tablero por proyectos y quién reportó y quién falta, por módulo', '19M'),
            array('ListasVista.php', 'fa-check-square-o', 'Listas de chequeo', 'Las listas diligenciadas', '11M'),
            array('RQVista.php', 'fa-wrench', 'Requisiciones (RQ)', 'Todas las RQ', '15M'),
            array('ArqueosVista.php', 'fa-cog', 'Fondos autorizados', 'El fondo de cada centro para los arqueos', '5M'),
            array('CronogramaVista.php', 'fa-car', 'Vehículos', 'Placas y responsables', '5M'),
            array('AusentismoVista.php', 'fa-user-times', 'Ausentismo', 'El reporte nacional de ausentismo de cada proyecto', ''),
            array('ParametrosVista.php', 'fa-sliders', 'Parámetros del sistema', 'Módulos activos, plazos y reglas', '24M')
        );
        include('head.php');
?>
<link href="../public/css/reporte.css?v=16" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="rd-migas"><a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> Administración</div>
      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-shield"></i> Administración del reporte diario</h2>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <p class="rd-intro">Todo lo que llenan los coordinadores de todos los proyectos. Estas pantallas son de consulta:
            cada coordinador sigue llenando lo suyo en su propia pantalla.</p>
          <div class="rd-mosaicos">
<?php foreach ($mosaicos as $mosaico) {
          if ($mosaico[4] !== '' && !in_array($mosaico[4], $modulosAcceso)) { continue; } ?>
            <a href="<?php echo $mosaico[0]; ?>" class="rd-mosaico">
              <div class="rd-mosaico-icono"><i class="fa <?php echo $mosaico[1]; ?>"></i></div>
              <div class="rd-mosaico-titulo"><?php echo $mosaico[2]; ?></div>
              <div class="rd-mosaico-texto"><?php echo $mosaico[3]; ?></div>
            </a>
<?php } ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
