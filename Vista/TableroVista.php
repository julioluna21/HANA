<?php
//Reporte diario — Tablero por proyectos
//Para los jefes: un mosaico por proyecto; dentro, cada peaje con su jefe y lo
//que reportó ese día; y el reporte completo de cada persona
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    if (in_array("19M", $modulosAcceso)) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=15" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="rd-migas" id="migasTablero"><a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> Tablero</div>

      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-th-large"></i> <span id="tituloTablero">Tablero del reporte diario</span></h2>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="hoy-dia">
            <button type="button" class="btn btn-default" id="btnDiaAnt" title="Día anterior"><i class="fa fa-chevron-left"></i></button>
            <input type="date" id="tbFecha" class="form-control" aria-label="Día">
            <button type="button" class="btn btn-default" id="btnDiaSig" title="Día siguiente"><i class="fa fa-chevron-right"></i></button>
            <span class="rd-ayuda" id="tbAlcance" style="margin:0 0 0 6px;"></span>
          </div>

          <!-- Nivel 1: un mosaico por proyecto -->
          <div id="nivelProyectos" class="tb-proyectos"></div>

          <!-- Nivel 2: los centros de un proyecto -->
          <div id="nivelCentros" style="display:none;">
            <!-- Enlaces a los demás módulos (los arma TableroAjax.js con lo que la persona tiene en su menú) -->
            <div id="tbIrA" class="tb-ir rd-no-imprimir"></div>
            <div id="tbCoordinador"></div>
            <h4 class="rd-subtitulo">Peajes, básculas y bases</h4>
            <div id="tbCentros" class="tb-centros"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Nivel 3: el reporte de una persona -->
<div class="modal fade" id="modalPersona" tabindex="-1" role="dialog" aria-labelledby="modalPersonaTitulo">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header rd-modal-cab">
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="modalPersonaTitulo">Reporte</h4>
      </div>
      <div class="modal-body" id="modalPersonaCuerpo"></div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=6"></script>
<script type="text/javascript" src="../Ajax/TableroAjax.js?v=10"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
