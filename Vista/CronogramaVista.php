<?php
//Reporte diario — Cronograma de visitas y vehículo
//Un calendario del mes: lo planeado para cada día y el estado del vehículo.
//Reemplaza la hoja "Cronograma visitas y vehículo" del Excel
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    //Si el administrador apagó este módulo (Parámetros del sistema), la pantalla no abre
    require_once __DIR__ . '/../Modelo/HanaConfig.php';
    if (!HanaConfig::modulo('CRONOGRAMA')) { echo "<script>window.location.replace('ReporteDiarioVista.php');</script>"; exit; }
    HanaDB::usarModulo('CRONOGRAMA');
    //Reporte del coordinador: lo llena quien coordina un proyecto (Configuración → Proyectos)
    require_once __DIR__ . '/../Modelo/HanaDB.php';
    $esCoordinador = isset($_SESSION['Idcolaborador']) && HanaDB::esCoordinador((int)$_SESSION['Idcolaborador']);

    //Lo llena el coordinador; 5M: catálogo de vehículos; 20M y 21M: consultan todos los proyectos
    if ($esCoordinador || in_array("5M", $modulosAcceso) || in_array("20M", $modulosAcceso) || in_array("21M", $modulosAcceso) || in_array("22M", $modulosAcceso)) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=13" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">

      <div class="rd-migas"><a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> Cronograma y vehículo</div>
      <div id="alertaDia"></div>

      <div class="x_panel" id="panelCalendario">
        <div class="x_title">
          <h2><i class="fa fa-calendar"></i> Cronograma y vehículo</h2>
          <div class="pull-right rd-botones-titulo">
            <button type="button" class="btn btn-default" id="btnVehiculos" style="display:none;"><i class="fa fa-car"></i> Vehículos</button>
          </div>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="row">
            <!-- Por proyecto: arranca en el del coordinador y se puede cambiar para consultar otro -->
            <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="cProyecto">Proyecto:</label>
              <select id="cProyecto" class="form-control"></select></div>
            <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="cCentro">Peaje o báscula:</label>
              <select id="cCentro" class="form-control"><option value="0">Todos</option></select></div>
            <div class="form-group col-md-2 col-sm-4 col-xs-6"><label for="cMes">Mes:</label>
              <select id="cMes" class="form-control">
                <option value="1">Enero</option><option value="2">Febrero</option><option value="3">Marzo</option>
                <option value="4">Abril</option><option value="5">Mayo</option><option value="6">Junio</option>
                <option value="7">Julio</option><option value="8">Agosto</option><option value="9">Septiembre</option>
                <option value="10">Octubre</option><option value="11">Noviembre</option><option value="12">Diciembre</option>
              </select></div>
            <div class="form-group col-md-2 col-sm-4 col-xs-6"><label for="cAnio">Año:</label>
              <select id="cAnio" class="form-control"></select></div>
            <div class="form-group col-md-2 col-sm-4 col-xs-12" id="cBloqueVh"><label for="cVehiculo">Vehículo:</label>
              <select id="cVehiculo" class="form-control"></select></div>
          </div>
          <!-- Quién es el coordinador del proyecto y, si es de otro, que es solo consulta -->
          <div class="crono-quien" id="cQuien"></div>
          <!-- Con un peaje elegido: cuántas veces se visitó y cuándo -->
          <div id="cResumenCentro"></div>

          <!-- Convenciones -->
          <div class="crono-leyenda">
            <span class="crono-item crono-PROGRAMADA">Programada</span>
            <span class="crono-item crono-REALIZADA">Realizada</span>
            <span class="crono-item crono-NO_REALIZADA">No realizada</span>
            <span class="crono-item crono-CANCELADA">Cancelada</span>
            <span class="crono-vh crono-vh-OPERATIVO">Vehículo operativo</span>
            <span class="crono-vh crono-vh-INOPERATIVO">Inoperativo</span>
            <span class="crono-vh crono-vh-FALLA_FLOTA">Falla reportada</span>
          </div>
          <p class="rd-ayuda">Toca un día para planear, marcar lo que hiciste o registrar el estado del vehículo.
            Las visitas a un peaje quedan realizadas solas si ese día lo marcaste en "Hoy en qué estás".</p>

          <!-- Dos formas de ver el mes: calendario, o lista con toda la información de cada día -->
          <div class="crono-vistas">
            <div class="btn-group" role="group" aria-label="Forma de ver el mes">
              <button type="button" class="btn btn-default" data-vista="calendario"><i class="fa fa-calendar"></i> Calendario</button>
              <button type="button" class="btn btn-default" data-vista="lista"><i class="fa fa-list"></i> Lista</button>
            </div>
            <label class="crono-desde-hoy" id="cDesdeHoyGrupo"><input type="checkbox" id="cDesdeHoy" checked> Próximos 15 días (sin marcar: el mes elegido)</label>
          </div>

          <div class="crono-calendario" id="calendario"></div>
          <div class="crono-lista" id="listaCrono" style="display:none;"></div>
        </div>
      </div>

      <!-- Catálogo de vehículos (5M) -->
      <div class="x_panel" id="panelVehiculos" style="display:none;">
        <div class="x_title"><h2><i class="fa fa-car"></i> Vehículos</h2>
          <button type="button" class="btn btn-default pull-right" id="btnVolverVh"><i class="fa fa-arrow-left"></i> Volver</button>
          <div class="clearfix"></div></div>
        <div class="x_content">
          <form id="formVh" class="row" autocomplete="off">
            <input type="hidden" name="id" id="vhId">
            <div class="form-group col-md-2 col-sm-4 col-xs-6"><label for="vhPlaca">Placa: <span class="rd-req">*</span></label>
              <input type="text" id="vhPlaca" name="placa" class="form-control" maxlength="10" placeholder="BOB120"></div>
            <div class="form-group col-md-3 col-sm-8 col-xs-6"><label for="vhDesc">Descripción:</label>
              <input type="text" id="vhDesc" name="descripcion" class="form-control" maxlength="80" placeholder="Ej: camioneta"></div>
            <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="vhResp">Responsable:</label>
              <select id="vhResp" name="responsable" class="form-control"><option value="">Sin asignar</option></select></div>
            <div class="form-group col-md-2 col-sm-3 col-xs-6"><label for="vhEstado">Estado:</label>
              <select id="vhEstado" name="estado" class="form-control"><option value="1">Activo</option><option value="0">Inactivo</option></select></div>
            <div class="form-group col-md-2 col-sm-3 col-xs-6"><label>&nbsp;</label>
              <button type="submit" class="btn btn-success btn-block" id="btnGuardarVh"><i class="fa fa-save"></i> Guardar</button></div>
          </form>
          <div class="table-responsive">
            <table class="table table-bordered rd-tabla">
              <thead><tr><th>Placa</th><th>Descripción</th><th>Responsable</th><th>Estado</th><th></th></tr></thead>
              <tbody id="filasVh"></tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ====================== UN DÍA ====================== -->
<div class="modal fade" id="modalDia" tabindex="-1" role="dialog" aria-labelledby="modalDiaTitulo">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header rd-modal-cab">
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="modalDiaTitulo">Día</h4>
      </div>
      <div class="modal-body" id="modalDiaCuerpo"></div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=4"></script>
<script type="text/javascript" src="../Ajax/CronogramaAjax.js?v=8"></script>
<?php
    } else {
        echo "<script>window.location.replace('InicioVista.php');</script>";
    }
} else {
    echo "<script>window.location.replace('login.php');</script>";
}
?>
