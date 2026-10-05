<?php
//Reporte diario — Reporte general del día
//Lo que enviaron los coordinadores y jefes de peaje, organizado por módulo,
//con quién falta por reportar. Permiso 19M (el del tablero)
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    if (in_array("19M", $modulosAcceso)) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=13" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="rd-migas"><a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> Reporte general</div>

      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-list-alt"></i> Reporte general del día</h2>
          <div class="pull-right rd-botones-titulo rd-no-imprimir">
            <a class="btn btn-default" href="TableroVista.php"><i class="fa fa-th-large"></i> Tablero por proyectos</a>
            <button type="button" class="btn btn-default" onclick="window.print()"><i class="fa fa-print"></i> Imprimir</button>
          </div>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="cd-filtros rd-no-imprimir">
            <div class="hoy-dia">
              <button type="button" class="btn btn-default" id="btnDiaAnt" title="Día anterior"><i class="fa fa-chevron-left"></i></button>
              <input type="date" id="cdFecha" class="form-control" aria-label="Día">
              <button type="button" class="btn btn-default" id="btnDiaSig" title="Día siguiente"><i class="fa fa-chevron-right"></i></button>
              <button type="button" class="btn btn-link" id="btnHoy">Hoy</button>
            </div>
            <select id="cdProyecto" class="form-control" aria-label="Proyecto"><option value="0">Todos los proyectos</option></select>
          </div>
          <p class="cd-titulo-imprimir" id="cdTituloImprimir"></p>
          <p class="rd-ayuda" id="cdAlcance"></p>

          <!-- Resumen: una tarjeta por módulo; al tocarla se abre su pestaña -->
          <div class="cd-resumen" id="cdResumen"></div>

          <!-- Una pestaña por módulo -->
          <ul class="nav nav-tabs cd-pestanas rd-no-imprimir" role="tablist" id="cdPestanas">
            <li class="active"><a href="#tabHoy" data-toggle="tab">Hoy en qué estás</a></li>
            <li><a href="#tabListas" data-toggle="tab">Listas de chequeo</a></li>
            <li><a href="#tabArqueos" data-toggle="tab">Arqueos</a></li>
            <li><a href="#tabCrono" data-toggle="tab">Cronograma</a></li>
            <li><a href="#tabVh" data-toggle="tab">Vehículos</a></li>
            <li><a href="#tabVac" data-toggle="tab">Vacantes</a></li>
            <li><a href="#tabCom" data-toggle="tab">Comunicaciones</a></li>
            <li><a href="#tabRq" data-toggle="tab">RQ por aprobar</a></li>
          </ul>
          <div class="tab-content cd-contenido">
            <div class="tab-pane active" id="tabHoy">
              <h4 class="cd-sub-imprimir">Hoy en qué estás</h4>
              <div class="rd-opciones cd-filtro-hoy rd-no-imprimir" id="cdFiltroHoy">
                <label class="rd-opcion"><input type="radio" name="fHoy" value="todos" checked> <span>Todos</span></label>
                <label class="rd-opcion"><input type="radio" name="fHoy" value="faltan"> <span>Faltan por reportar</span></label>
                <label class="rd-opcion"><input type="radio" name="fHoy" value="reportaron"> <span>Ya reportaron</span></label>
              </div>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Persona</th><th>Estado</th><th>Dónde</th><th>Horario</th><th class="rd-num-col">Bitácora</th><th>Registró</th><th class="rd-no-imprimir"></th></tr></thead>
                <tbody id="tbHoy"></tbody></table></div>
            </div>
            <div class="tab-pane" id="tabListas">
              <h4 class="cd-sub-imprimir">Listas de chequeo</h4>
              <div id="cdCentrosListas"></div>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Hora</th><th>Lista</th><th>Centro</th><th>Proyecto</th><th>Diligenció</th><th>Respuestas</th><th class="rd-no-imprimir"></th></tr></thead>
                <tbody id="tbListas"></tbody></table></div>
            </div>
            <div class="tab-pane" id="tabArqueos">
              <div id="cdArqueosQuien"></div>
              <h4 class="cd-sub-imprimir">Arqueos</h4>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Hora</th><th>Tipo</th><th>Centro</th><th>Responsable</th><th>Arqueó</th><th class="rd-num-col">Total</th><th class="rd-num-col">Fondo</th><th>Resultado</th><th class="rd-no-imprimir"></th></tr></thead>
                <tbody id="tbArqueos"></tbody></table></div>
            </div>
            <div class="tab-pane" id="tabCrono">
              <h4 class="cd-sub-imprimir">Cronograma</h4>
              <div id="cdRevision"></div>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Persona</th><th>Actividad</th><th>Estado</th><th>Observación</th></tr></thead>
                <tbody id="tbCrono"></tbody></table></div>
            </div>
            <div class="tab-pane" id="tabVh">
              <h4 class="cd-sub-imprimir">Vehículos</h4>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Placa</th><th>Responsable</th><th>Estado del día</th><th>Observación</th></tr></thead>
                <tbody id="tbVh"></tbody></table></div>
            </div>
            <div class="tab-pane" id="tabVac">
              <div id="cdVacProy"></div>
              <h4 class="cd-sub-imprimir">Vacantes abiertas</h4>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Centro</th><th>Cargo</th><th>Desde</th><th>Acuerdo hasta</th><th class="rd-num-col">Días</th><th>Resultado</th><th>Compromiso GH</th></tr></thead>
                <tbody id="tbVac"></tbody></table></div>
            </div>
            <div class="tab-pane" id="tabCom">
              <div id="cdComProy"></div>
              <h4 class="cd-sub-imprimir">Comunicaciones abiertas</h4>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Tipo</th><th>Recepción</th><th>Remitente</th><th>Asunto</th><th>Responsable</th><th class="rd-num-col">Días</th><th>Resultado</th><th>Pendiente de</th></tr></thead>
                <tbody id="tbCom"></tbody></table></div>
            </div>
            <div class="tab-pane" id="tabRq">
              <div id="cdRqProy"></div>
              <h4 class="cd-sub-imprimir">RQ por aprobar</h4>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>RQ</th><th>Centro</th><th>Solicitó</th><th>Qué se pide</th><th class="rd-num-col">Días esperando</th><th class="rd-no-imprimir cd-col-decidir">Decidir</th></tr></thead>
                <tbody id="tbRq"></tbody></table></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- El reporte completo de una persona (el mismo del tablero) -->
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
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=4"></script>
<script type="text/javascript" src="../Ajax/ControlDiarioAjax.js?v=11"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
