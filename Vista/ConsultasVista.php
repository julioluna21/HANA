<?php
//Consultas - pantalla principal
//Reúne en mosaicos lo que se revisa (lo que el coordinador llena en el Reporte diario, visto
//de otra manera): reporte general, informe mensual, reporte a la Gerencia y trazabilidad.
//Cada mosaico sale solo si el rol tiene su casilla (las mismas del menú lateral)
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $accesoConsultas = explode(",", $_SESSION['Modulos']);
    require_once __DIR__ . '/../Control/PaginaControl.php';

    /* Mosaicos: [¿lo ve?, archivo, icono, título, descripción] */
    $mosaicos = array(
        array(in_array('19M', $accesoConsultas, true), 'ControlDiarioVista.php', 'fa-list-alt', 'Reporte general', 'Lo que enviaron coordinadores y jefes, por día, módulo y proyecto'),
        array(in_array('37M', $accesoConsultas, true), 'InformeMensualVista.php', 'fa-file-pdf-o', 'Informe mensual', 'Un PDF de un mes, una quincena o las fechas que elijas'),
        array(pagPuede('GERENCIA', 'armar') && pagModuloActivo('GERENCIA'), 'ReporteGerenciaVista.php', 'fa-briefcase', 'Reporte a la Gerencia', 'El reporte por proyecto de un día o de un rango de días, para la Gerencia'),
        array(pagPuede('TRAZABILIDAD', 'ver'), 'TrazabilidadVista.php', 'fa-history', 'Trazabilidad', 'Qué se registró, cambió o anuló, dónde, quién y cuándo')
    );
    $visibles = array_filter($mosaicos, function ($mosaico) { return $mosaico[0]; });

    if (count($visibles)) {
        include('head.php');
        require_once __DIR__ . '/saludo.php'; //el saludo de arriba
?>
<link href="../public/css/reporte.css?v=16" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <?php hanaSaludo(array(
          'texto'  => 'Aquí se revisa lo que se registra en el sistema. Elige qué quieres consultar.',
          'icono'  => 'fa-line-chart'
      )); ?>
      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-line-chart"></i> Consultas</h2>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="rd-mosaicos">
<?php foreach ($visibles as $mosaico) { ?>
            <a href="<?php echo $mosaico[1]; ?>" class="rd-mosaico">
              <div class="rd-mosaico-icono"><i class="fa <?php echo $mosaico[2]; ?>"></i></div>
              <div class="rd-mosaico-titulo"><?php echo $mosaico[3]; ?></div>
              <div class="rd-mosaico-texto"><?php echo $mosaico[4]; ?></div>
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
    } else {
        echo "<script>window.location.replace('InicioVista.php');</script>";
    }
} else {
    echo "<script>window.location.replace('login.php');</script>";
}
?>
