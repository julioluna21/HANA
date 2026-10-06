<?php
//Reporte diario — Vacantes
//La hoja "Vacantes" del Excel: cargos por cubrir, su acuerdo de servicio y
//el compromiso de Gestión Humana
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    //Si el administrador apagó este módulo (Parámetros del sistema), la pantalla no abre
    require_once __DIR__ . '/../Modelo/HanaConfig.php';
    if (!HanaConfig::modulo('VACANTES')) { echo "<script>window.location.replace('ReporteDiarioVista.php');</script>"; exit; }
    HanaDB::usarModulo('VACANTES');
    //Reporte del coordinador: lo llena quien coordina un proyecto (Configuración → Proyectos)
    require_once __DIR__ . '/../Modelo/HanaDB.php';
    $esCoordinador = isset($_SESSION['Idcolaborador']) && HanaDB::esCoordinador((int)$_SESSION['Idcolaborador']);
    if ($esCoordinador) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=15" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="rd-migas"><a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> Vacantes</div>

      <div class="x_panel" id="panelLista">
        <div class="x_title">
          <h2><i class="fa fa-user-plus"></i> Vacantes</h2>
          <div class="pull-right rd-botones-titulo"><button type="button" class="btn btn-success" id="btnNueva"><i class="fa fa-plus-square"></i> Nueva vacante</button></div>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="rd-contadores rd-contadores-4">
            <div class="rd-contador rd-cnt-pend"><span class="rd-num" id="cAbiertas">0</span><span class="rd-lbl">Abiertas</span></div>
            <div class="rd-contador rd-cnt-mal"><span class="rd-num" id="cVencidas">0</span><span class="rd-lbl">Abiertas fuera del acuerdo</span></div>
            <div class="rd-contador rd-cnt-ok"><span class="rd-num" id="cCumple">0</span><span class="rd-lbl">Cubiertas a tiempo</span></div>
            <div class="rd-contador"><span class="rd-num" id="cNoCumple">0</span><span class="rd-lbl">Cubiertas tarde</span></div>
          </div>
          <div class="row">
            <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="fCentro">Centro:</label>
              <select id="fCentro" class="form-control"><option value="0">Todos</option></select></div>
            <div class="form-group col-md-4 col-sm-3 col-xs-6"><label for="fMes">Ver:</label>
              <select id="fMes" class="form-control">
                <option value="0">Todas las abiertas</option>
                <option value="1">Abiertas en enero</option><option value="2">Abiertas en febrero</option><option value="3">Abiertas en marzo</option>
                <option value="4">Abiertas en abril</option><option value="5">Abiertas en mayo</option><option value="6">Abiertas en junio</option>
                <option value="7">Abiertas en julio</option><option value="8">Abiertas en agosto</option><option value="9">Abiertas en septiembre</option>
                <option value="10">Abiertas en octubre</option><option value="11">Abiertas en noviembre</option><option value="12">Abiertas en diciembre</option>
              </select></div>
            <div class="form-group col-md-4 col-sm-3 col-xs-6"><label for="fAnio">Año:</label>
              <select id="fAnio" class="form-control"></select></div>
          </div>
          <p class="rd-ayuda" id="ayudaAns"></p>
          <div class="table-responsive">
            <table class="table table-bordered rd-tabla rd-tabla-compacta">
              <thead><tr><th>#RQ</th><th>Centro</th><th>Cargo</th><th>Fecha vacante</th><th>Acuerdo hasta</th><th>Cierre</th>
                <th class="rd-num-col">Días</th><th>Motivo</th><th>Estado</th><th>Resultado</th><th>Nombre</th><th>Compromiso GH</th><th></th></tr></thead>
              <tbody id="filas"><tr><td colspan="13" class="rd-vacio">Cargando...</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="x_panel" id="panelForm" style="display:none;">
        <div class="x_title"><h2 id="tituloForm"><i class="fa fa-user-plus"></i> Nueva vacante</h2><div class="clearfix"></div></div>
        <div class="x_content">
          <form id="formVac" autocomplete="off">
            <input type="hidden" name="id" id="vId">
            <div class="row">
              <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="vCentro">Centro: <span class="rd-req">*</span></label>
                <select id="vCentro" name="centro" class="form-control"></select></div>
              <div class="form-group col-md-2 col-sm-6 col-xs-6"><label for="vRq">#RQ de GH:</label>
                <input type="text" id="vRq" name="rq" class="form-control" maxlength="20" placeholder="Ej: 3607"></div>
              <div class="form-group col-md-6 col-sm-12 col-xs-12"><label for="vCargo">Cargo: <span class="rd-req">*</span></label>
                <input type="text" id="vCargo" name="cargo" class="form-control" maxlength="120" placeholder="Ej: Recolector planta"></div>
            </div>
            <div class="row">
              <div class="form-group col-md-3 col-sm-6 col-xs-6"><label for="vFecha">Fecha de la vacante: <span class="rd-req">*</span></label>
                <input type="date" id="vFecha" name="fecha" class="form-control"></div>
              <div class="form-group col-md-9 col-sm-6 col-xs-12"><label for="vMotivo">Motivo: <span class="rd-req">*</span></label>
                <input type="text" id="vMotivo" name="motivo" class="form-control" maxlength="150" list="listaMotivos" placeholder="Ej: Renuncia - mejor oferta laboral">
                <datalist id="listaMotivos"></datalist></div>
            </div>
            <h4 class="rd-subtitulo">Seguimiento</h4>
            <div class="row">
              <div class="form-group col-md-3 col-sm-4 col-xs-6"><label for="vGh">Estado en Gestión Humana:</label>
                <select id="vGh" name="gh" class="form-control"><option value="">Sin información</option></select></div>
              <div class="form-group col-md-3 col-sm-4 col-xs-6"><label for="vCompromiso">Fecha comprometida por GH:</label>
                <input type="date" id="vCompromiso" name="compromiso" class="form-control"></div>
              <div class="form-group col-md-6 col-sm-4 col-xs-12"><label for="vNombre">Nombre de quien la cubre:</label>
                <input type="text" id="vNombre" name="nombre" class="form-control" maxlength="120"></div>
            </div>
            <div class="row">
              <div class="form-group col-md-3 col-sm-4 col-xs-6"><label for="vEstado">Estado de la vacante:</label>
                <select id="vEstado" name="estado" class="form-control"></select></div>
              <div class="form-group col-md-3 col-sm-4 col-xs-6" id="grupoCierre"><label for="vCierre">Fecha de cierre: <span class="rd-req">*</span></label>
                <input type="date" id="vCierre" name="cierre" class="form-control"></div>
              <div class="form-group col-md-6 col-sm-4 col-xs-12"><label for="vObs">Observación:</label>
                <input type="text" id="vObs" name="obs" class="form-control" maxlength="300"></div>
            </div>
            <div class="ln_solid"></div>
            <div class="rd-acciones">
              <button type="button" class="btn btn-default" id="btnCancelar">Cancelar</button>
              <button type="submit" class="btn btn-success" id="btnGuardar"><i class="fa fa-save"></i> Guardar</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=6"></script>
<script type="text/javascript" src="../Ajax/VacanteAjax.js?v=3"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
