<?php
//Reporte diario - Reporte general del día (con el tablero por proyectos)
//Lo que enviaron los coordinadores y jefes de peaje, organizado por módulo,
//con quién falta por reportar. Permiso 19M: cada quien ve lo de sus peajes asignados; el ADMIN TEC, todo.
//Arriba, el tablero por proyectos: solo el director de operaciones (43M) y el administrador (24M)
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    if (in_array("19M", $modulosAcceso)) {
        include('head.php');
        $verTablero = in_array("43M", $modulosAcceso) || in_array("24M", $modulosAcceso);
?>
<link href="../public/css/reporte.css?v=16" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">

      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-list-alt"></i> Reporte general del día</h2>
          <div class="pull-right rd-botones-titulo rd-no-imprimir">
            <?php if (in_array("37M", $modulosAcceso)) { //el informe de un mes, una quincena o las fechas que se elijan ?>
            <a class="btn btn-default" href="InformeMensualVista.php"><i class="fa fa-file-pdf-o"></i> Informe mensual</a>
            <?php } ?>
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

          <?php if ($verTablero) { ?>
          <!-- Tablero por proyectos (director de operaciones): un mosaico por proyecto; al tocar uno,
               sus peajes con su jefe, y abajo el reporte queda solo de ese proyecto -->
          <div id="cdTablero" class="cd-tablero rd-no-imprimir">
            <h4 class="rd-subtitulo" id="tbTitulo">Tablero por proyectos</h4>
            <?php if (pagPromesaEntrar()) { ?><p class="rd-ayuda"><a href="PromesaValorVista.php"><i class="fa fa-clock-o"></i> Ver la promesa de valor de cada peaje</a> (horas prometidas y ejecutadas)</p><?php } ?>
            <div id="nivelProyectos" class="tb-proyectos"></div>
            <div id="nivelCentros" style="display:none;">
              <div id="tbIrA" class="tb-ir"></div>
              <div id="tbCoordinador"></div>
              <h4 class="rd-subtitulo">Peajes, básculas y bases</h4>
              <div id="tbCentros" class="tb-centros"></div>
            </div>
            <h4 class="rd-subtitulo" style="margin-top:18px;">Reporte del día</h4>
          </div>
          <?php } ?>

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
            <li id="cdPestanaAus"><a href="#tabAus" data-toggle="tab">Ausentismo</a></li>
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
                <thead><tr><th>Persona</th><th>Estado</th><th>Dónde</th><th>Horario</th><th>Plan del día</th><th>Registró</th><th class="rd-no-imprimir"></th></tr></thead>
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
              <h4 class="cd-sub-imprimir">Arqueos</h4> <!-- al imprimir, el título va primero en su hoja -->
              <div id="cdArqueosQuien"></div>
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
              <h4 class="cd-sub-imprimir">Vacantes abiertas</h4> <!-- al imprimir, el título va primero en su hoja -->
              <div id="cdVacProy"></div>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Centro</th><th>Cargo</th><th>Desde</th><th>Acuerdo hasta</th><th class="rd-num-col">Días</th><th>Resultado</th><th>Compromiso GH</th></tr></thead>
                <tbody id="tbVac"></tbody></table></div>
            </div>
            <div class="tab-pane" id="tabCom">
              <h4 class="cd-sub-imprimir">Comunicaciones abiertas</h4> <!-- al imprimir, el título va primero en su hoja -->
              <div id="cdComProy"></div>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Tipo</th><th>Recepción</th><th>Remitente</th><th>Asunto</th><th>Registró</th><th class="rd-num-col">Días</th><th>Resultado</th><th>Pendiente de</th></tr></thead>
                <tbody id="tbCom"></tbody></table></div>
            </div>
            <!-- Ausentismo del día: cada ausencia con su motivo (quién cubrió a quién) -->
            <div class="tab-pane" id="tabAus">
              <h4 class="cd-sub-imprimir">Ausentismo</h4> <!-- al imprimir, el título va primero en su hoja -->
              <div id="cdAusProy"></div>
              <h4 class="rd-subtitulo" style="margin-top:4px;">Ausencias registradas el día</h4>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Peaje</th><th>Proyecto</th><th>Cargo</th><th>Motivo</th><th class="rd-num-col">Personas</th><th>Registró</th></tr></thead>
                <tbody id="tbAus"></tbody></table></div>
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
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=12"></script>
<?php if ($verTablero) { ?><script type="text/javascript" src="../Ajax/TableroAjax.js?v=15"></script><?php } ?>
<script type="text/javascript" src="../Ajax/ControlDiarioAjax.js?v=21"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
