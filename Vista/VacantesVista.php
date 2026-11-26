<?php
//Reporte diario - Vacantes
//La hoja "Vacantes" del Excel: cargos por cubrir, su acuerdo de servicio y
//el compromiso de Gestión Humana
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    //Si el administrador apagó este módulo (Parámetros del sistema), la pantalla no abre
    require_once __DIR__ . '/../Control/PaginaControl.php';
    if (!pagModuloActivo('VACANTES')) { echo "<script>window.location.replace('ReporteDiarioVista.php');</script>"; exit; }
    pagUsarModulo('VACANTES');
    //La llena o la ve quien tenga esas casillas, en sus peajes asignados
    $esCoordinador = pagPuede('VACANTES', 'llenar') && pagTieneCentros();
    if (pagEntra('VACANTES')) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=16" rel="stylesheet">
<style>
  /* Columna de Gestión Humana: el estado, la fecha comprometida y la última respuesta */
  .vac-gh{ min-width: 180px; max-width: 320px; }
  .vac-gh-resp{ color: #3c3c40; font-size: 12px; }
  .vac-respuestas{ margin-top: 8px; border-left: 3px solid #E6DCD8; padding-left: 10px; }
  .vac-resp-item{ margin-bottom: 8px; }
</style>

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="rd-migas"><a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> Vacantes</div>

      <div class="x_panel" id="panelLista">
        <div class="x_title">
          <h2><i class="fa fa-user-plus"></i> Vacantes</h2>
          <div class="pull-right rd-botones-titulo">
            <?php //Descargar en Excel: con la casilla 63M (o el administrador, 24M) ?>
            <?php if (pagPuede('EXCEL', 'descargar')) { ?><button type="button" class="btn btn-default" id="btnExcel" title="Descarga en Excel lo que se está viendo, con los mismos filtros"><i class="fa fa-file-excel-o"></i> Excel</button><?php } ?>
            <button type="button" class="btn btn-success rd-btn-flotante" id="btnNueva"><i class="fa fa-plus-square"></i> Nueva vacante</button></div>
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
            <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="fProyecto">Proyecto:</label>
              <select id="fProyecto" class="form-control"><option value="0">Todos</option></select></div>
            <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="fCentro">Centro:</label>
              <select id="fCentro" class="form-control"><option value="0">Todos</option></select></div>
            <div class="form-group col-md-3 col-sm-3 col-xs-6"><label for="fMes">Ver:</label>
              <select id="fMes" class="form-control">
                <option value="0">Todas las abiertas</option>
                <option value="1">Abiertas en enero</option><option value="2">Abiertas en febrero</option><option value="3">Abiertas en marzo</option>
                <option value="4">Abiertas en abril</option><option value="5">Abiertas en mayo</option><option value="6">Abiertas en junio</option>
                <option value="7">Abiertas en julio</option><option value="8">Abiertas en agosto</option><option value="9">Abiertas en septiembre</option>
                <option value="10">Abiertas en octubre</option><option value="11">Abiertas en noviembre</option><option value="12">Abiertas en diciembre</option>
              </select></div>
            <div class="form-group col-md-3 col-sm-3 col-xs-6"><label for="fAnio">Año:</label>
              <select id="fAnio" class="form-control"></select></div>
          </div>
          <p class="rd-ayuda" id="ayudaAns"></p>
          <div class="table-responsive">
            <table class="table table-bordered rd-tabla rd-tabla-compacta">
              <thead><tr><th>#RQ</th><th>Centro</th><th>Cargo</th><th>Fecha vacante</th><th>Acuerdo hasta</th><th title="El día en que la vacante se cubrió o se canceló">Cerrada el</th>
                <th class="rd-num-col">Días</th><th>Motivo</th><th title="Quien renunció, se trasladó o dejó el cargo">Quién lo deja</th><th>Estado</th><th>Resultado</th><th title="Mientras está abierta: quién la está cubriendo. Cubierta: quién quedó en el cargo">Quién la cubre</th><th title="Estado del proceso en Gestión Humana, su fecha comprometida (si es distinta del acuerdo) y lo último que respondió">Gestión Humana</th><th class="rd-col-acciones"></th></tr></thead>
              <tbody id="filas"><tr><td colspan="14" class="rd-vacio">Cargando...</td></tr></tbody>
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
                <input type="text" id="vCargo" name="cargo" class="form-control" maxlength="120" list="listaCargos" autocomplete="off" placeholder="Ej: Recolector planta">
                <datalist id="listaCargos"></datalist></div>
            </div>
            <div class="row">
              <div class="form-group col-md-3 col-sm-6 col-xs-6"><label for="vFecha">Fecha de la vacante: <span class="rd-req">*</span></label>
                <input type="date" id="vFecha" name="fecha" class="form-control"></div>
              <div class="form-group col-md-5 col-sm-6 col-xs-12"><label for="vMotivo">Motivo: <span class="rd-req">*</span></label>
                <input type="text" id="vMotivo" name="motivo" class="form-control" maxlength="150" list="listaMotivos" placeholder="Ej: Renuncia - mejor oferta laboral">
                <datalist id="listaMotivos"></datalist></div>
              <!-- Quién deja el puesto: se guarda en historial (VacanteModelo::guardarSale), no en la tabla vacante -->
              <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="vSale">Quién deja el puesto:</label>
                <input type="text" id="vSale" name="sale" class="form-control" maxlength="120" list="listaCubren" placeholder="Quien renunció, se trasladó o salió">
                <small class="rd-ayuda">Déjalo vacío si es un cargo nuevo.</small></div>
            </div>
            <h4 class="rd-subtitulo">Seguimiento</h4>
            <div class="row">
              <div class="form-group col-md-3 col-sm-4 col-xs-6"><label for="vGh">Estado en Gestión Humana:</label>
                <select id="vGh" name="gh" class="form-control"><option value="">Sin información</option></select></div>
              <div class="form-group col-md-3 col-sm-4 col-xs-6"><label for="vCompromiso">Fecha comprometida por GH:</label>
                <input type="date" id="vCompromiso" name="compromiso" class="form-control"><small class="rd-ayuda" id="ayudaCompromiso"></small>
                <small class="rd-ayuda" id="vCambiosCompromiso" style="display:none;"></small></div>
              <div class="form-group col-md-6 col-sm-4 col-xs-12"><label for="vNombre">Quién la cubre:</label>
                <input type="text" id="vNombre" name="nombre" class="form-control" maxlength="120" list="listaCubren" placeholder="Escribe o elige el nombre">
                <datalist id="listaCubren"></datalist>
                <small class="rd-ayuda" id="ayudaCubre">Mientras está abierta: quién la está cubriendo (encargo o apoyo), si hay alguien. Al marcarla como cubierta: quién quedó en el cargo.</small></div>
            </div>
            <!-- Solo aparece si se cambia la fecha comprometida de una vacante abierta -->
            <div class="form-group" id="grupoMotivoCompromiso" style="display:none;"><label for="vMotivoCompromiso">¿Por qué cambia la fecha comprometida? <span class="rd-req">*</span></label>
              <input type="text" id="vMotivoCompromiso" name="motivoCompromiso" class="form-control" maxlength="300" placeholder="Ej: el candidato no pasó el examen médico de ingreso"></div>
            <div class="row">
              <div class="form-group col-md-3 col-sm-4 col-xs-6"><label for="vEstado">Estado de la vacante:</label>
                <select id="vEstado" name="estado" class="form-control"></select></div>
              <div class="form-group col-md-3 col-sm-4 col-xs-6" id="grupoCierre"><label>Cerrada el:</label>
                <p class="form-control-static" id="vCierreTxt"></p><small class="rd-ayuda">Se pone sola al guardarla como cubierta o cancelada.</small></div>
              <div class="form-group col-md-6 col-sm-4 col-xs-12"><label for="vObs">Observación:</label>
                <input type="text" id="vObs" name="obs" class="form-control" maxlength="300"></div>
            </div>
            <div class="row">
              <div class="form-group col-xs-12"><label for="vRespuestaGh">Respuesta de Gestión Humana:</label>
                <textarea id="vRespuestaGh" name="respuestaGh" class="form-control" rows="2" maxlength="350"
                          placeholder="Qué dijo GH y en qué va el proceso. Ej: candidato en exámenes médicos, ingresa el 24 de noviembre"></textarea>
                <small class="rd-ayuda">Al cambiarla, la anterior queda en el historial con su fecha.
                  <a href="#" id="verRespuestasGh" style="display:none;">Ver respuestas anteriores</a></small>
                <div id="respuestasGh" class="vac-respuestas" style="display:none;"></div></div>
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
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=15"></script>
<script type="text/javascript" src="../Ajax/VacanteAjax.js?v=16"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
