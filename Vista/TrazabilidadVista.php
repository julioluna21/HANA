<?php
//Consultas - Trazabilidad
//Qué se registró, se cambió, se anuló o se envió en el sistema, quién lo hizo y cuándo,
//organizado y con filtros (módulo, tipo de cambio, proyecto, peaje, persona, fechas y texto).
//Casilla 62M (o el ADMIN TEC, 24M). Los datos los da Control/TrazabilidadControl.php
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    require_once __DIR__ . '/../Control/PaginaControl.php';
    if (pagPuede('TRAZABILIDAD', 'ver')) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=16" rel="stylesheet">
<style>
  .tz-filtros .form-group { margin-bottom: 10px; }
  .tz-accion { white-space: nowrap; }
  .tz-detalle { max-width: 520px; word-break: break-word; }
  .tz-fecha { white-space: nowrap; }
  .tz-paginas { display: flex; gap: 8px; align-items: center; justify-content: flex-end; margin-top: 8px; }
  .tz-sin-quien { color: #999; font-style: italic; }
</style>

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-history"></i> Trazabilidad</h2>
          <div class="pull-right rd-botones-titulo rd-no-imprimir">
            <button type="button" class="btn btn-default" id="tzCsv" disabled><i class="fa fa-file-excel-o"></i> Descargar (Excel)</button>
            <button type="button" class="btn btn-default" onclick="window.print()"><i class="fa fa-print"></i> Imprimir</button>
          </div>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <p class="rd-intro">Todo lo que se ha registrado, cambiado, anulado o enviado en el sistema: <strong>qué</strong> pasó, <strong>dónde</strong>,
            <strong>quién</strong> lo hizo y <strong>cuándo</strong>. Usa los filtros para quedarte solo con lo que buscas.
            Toca el encabezado de una columna para ordenar.</p>
          <form id="tzFiltros" class="tz-filtros rd-no-imprimir" autocomplete="off">
            <div class="row">
              <div class="form-group col-md-2 col-sm-3 col-xs-6"><label for="tzDesde">Desde:</label><input type="date" id="tzDesde" class="form-control"></div>
              <div class="form-group col-md-2 col-sm-3 col-xs-6"><label for="tzHasta">Hasta:</label><input type="date" id="tzHasta" class="form-control"></div>
              <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="tzModulo">Dónde (módulo):</label>
                <select id="tzModulo" class="form-control"><option value="">Todos los módulos</option></select></div>
              <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="tzAccion">Qué tipo de cambio:</label>
                <select id="tzAccion" class="form-control"><option value="">Todos</option></select></div>
            </div>
            <div class="row">
              <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="tzProyecto">Proyecto:</label>
                <select id="tzProyecto" class="form-control"><option value="0">Todos</option></select></div>
              <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="tzCentro">Peaje:</label>
                <select id="tzCentro" class="form-control"><option value="0">Todos</option></select></div>
              <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="tzQuien">Quién lo hizo:</label>
                <select id="tzQuien" class="form-control" style="width:100%;"><option value="0">Cualquier persona</option></select></div>
              <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="tzTexto">Buscar texto:</label>
                <input type="text" id="tzTexto" class="form-control" maxlength="80" placeholder="Ej: número de RQ, placa, asunto"></div>
            </div>
            <div class="rd-acciones" style="margin-top:0;">
              <button type="submit" class="btn btn-success" id="tzBuscar"><i class="fa fa-search"></i> Buscar</button>
              <button type="button" class="btn btn-link" id="tzLimpiar">Quitar filtros</button>
              <span class="rd-ayuda">Se buscan hasta tres meses a la vez.</span>
            </div>
          </form>
          <p class="cd-titulo-imprimir" id="tzTituloImprimir"></p>
          <div class="rd-contadores" id="tzResumen"></div>
          <div class="table-responsive">
            <table class="table table-bordered rd-tabla rd-tabla-compacta hana-ordenable" id="tzTabla">
              <thead><tr><th>Cuándo</th><th>Módulo</th><th>Qué pasó</th><th>Proyecto</th><th>Peaje</th><th>Quién</th><th>Detalle</th></tr></thead>
              <tbody id="tzFilas"><tr><td colspan="7" class="rd-vacio">Cargando...</td></tr></tbody>
            </table>
          </div>
          <div class="tz-paginas rd-no-imprimir" id="tzPaginas"></div>
          <p class="rd-ayuda">Donde dice «sin dato», la tabla guarda cuándo se hizo el último cambio pero no quién.
            Los cambios de los registros que no guardan historial salen como «último cambio».</p>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=15"></script>
<script type="text/javascript" src="../Ajax/TrazabilidadAjax.js?v=1"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
