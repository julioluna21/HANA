<?php
//Administración del reporte diario — Arqueos (consulta)
//Lo que llenan TODOS los coordinadores, para el administrador (permiso 21M).
//Es de solo lectura: cada coordinador sigue llenando lo suyo en su pantalla
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    if (in_array("21M", $modulosAcceso)) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=15" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="rd-migas"><a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span>
        <a href="AdminReporteVista.php">Administración</a> <span>›</span> Arqueos</div>
      <div id="admPagina" data-pagina="arqueos"></div>

      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-money"></i> Arqueos <small class="adm-sello">Administración · solo consulta</small></h2>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <p class="rd-ayuda" style="margin-top:0;">Todos los arqueos de todos los centros, con su PDF.</p>
          <div class="row">
            <div class="form-group col-md-2 col-sm-4 col-xs-6"><label for="aMes">Mes:</label><select id="aMes" class="form-control"><option value="1">Enero</option><option value="2">Febrero</option><option value="3">Marzo</option><option value="4">Abril</option><option value="5">Mayo</option><option value="6">Junio</option><option value="7">Julio</option><option value="8">Agosto</option><option value="9">Septiembre</option><option value="10">Octubre</option><option value="11">Noviembre</option><option value="12">Diciembre</option></select></div>
            <div class="form-group col-md-2 col-sm-4 col-xs-6"><label for="aAnio">Año:</label><select id="aAnio" class="form-control"></select></div><div class="form-group col-md-3 col-sm-4 col-xs-12"><label for="aProyecto">Proyecto:</label><select id="aProyecto" class="form-control"></select></div><div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="aCentro">Centro:</label><select id="aCentro" class="form-control"></select></div><div class="form-group col-md-2 col-sm-6 col-xs-12"><label for="aTipo">Tipo:</label><select id="aTipo" class="form-control"></select></div>
          </div>
          <div class="adm-resumen" id="admResumen"></div>
          <div class="table-responsive" id="admResultado"><table class="table table-bordered rd-tabla rd-tabla-compacta">
            <thead><tr><th>Fecha</th><th>Tipo</th><th>Centro</th><th>Proyecto</th><th>Responsable</th><th>Arqueó</th><th class="rd-num-col">Total</th><th class="rd-num-col">Fondo</th><th>Resultado</th><th></th></tr></thead><tbody id="admFilas"><tr><td colspan="10" class="rd-vacio">Cargando...</td></tr></tbody></table></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="admModal" tabindex="-1" role="dialog" aria-labelledby="admModalTitulo">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header rd-modal-cab">
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="admModalTitulo">Detalle</h4>
      </div>
      <div class="modal-body" id="admModalCuerpo"></div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=6"></script>
<script type="text/javascript" src="../Ajax/AdminReporteAjax.js?v=6"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
