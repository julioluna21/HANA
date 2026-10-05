<?php
//Reporte diario — Arqueos de caja menor y de recambio
//Reemplaza los formatos en PDF: calcula solo el total y la diferencia contra
//el fondo autorizado, y se puede imprimir para las firmas
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    //Si el administrador apagó este módulo (Parámetros del sistema), la pantalla no abre
    require_once __DIR__ . '/../Modelo/HanaConfig.php';
    if (!HanaConfig::modulo('ARQUEOS')) { echo "<script>window.location.replace('ReporteDiarioVista.php');</script>"; exit; }
    HanaDB::usarModulo('ARQUEOS');
    //Reporte del coordinador: lo llena quien coordina un proyecto (Configuración → Proyectos)
    require_once __DIR__ . '/../Modelo/HanaDB.php';
    $esCoordinador = isset($_SESSION['Idcolaborador']) && HanaDB::esCoordinador((int)$_SESSION['Idcolaborador']);

    //Hace arqueos quien diligencia el reporte (11M); configura fondos quien administra centros (5M)
    if ($esCoordinador || in_array("5M", $modulosAcceso) || in_array("36M", $modulosAcceso)) { //5M: campos y anular; 36M: autorizar fondos
        include('head.php');
?>
<link href="../public/css/reporte.css?v=13" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">

      <div class="rd-migas"><a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> Arqueos</div>
      <div id="alertaDia"></div>

      <!-- ====================== LISTADO ====================== -->
      <div class="x_panel" id="panelListaArq">
        <div class="x_title">
          <h2><i class="fa fa-money"></i> Arqueos</h2>
          <div class="pull-right rd-botones-titulo">
            <button type="button" class="btn btn-default" id="btnFondos" style="display:none;"><i class="fa fa-cog"></i> Fondos autorizados</button>
            <button type="button" class="btn btn-default" id="btnCampos" style="display:none;"><i class="fa fa-list-ul"></i> Campos de casetas</button>
            <button type="button" class="btn btn-success" id="btnNuevoArq" style="display:none;"><i class="fa fa-plus-square"></i> Nuevo arqueo</button>
          </div>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="rd-contadores">
            <div class="rd-contador"><span class="rd-num" id="cntArq">0</span><span class="rd-lbl">Arqueos del mes</span></div>
            <div class="rd-contador rd-cnt-ok"><span class="rd-num" id="cntCuadran">0</span><span class="rd-lbl">Cuadran</span></div>
            <div class="rd-contador rd-cnt-mal"><span class="rd-num" id="cntDif">0</span><span class="rd-lbl">Con diferencia</span></div>
          </div>

          <div class="row">
            <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="fCentroArq">Centro:</label>
              <select id="fCentroArq" class="form-control"><option value="0">Todos</option></select></div>
            <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="fTipoArq">Tipo:</label>
              <select id="fTipoArq" class="form-control"><option value="">Todos</option></select></div>
            <div class="form-group col-md-3 col-sm-6 col-xs-6"><label for="fMesArq">Mes:</label>
              <select id="fMesArq" class="form-control">
                <option value="1">Enero</option><option value="2">Febrero</option><option value="3">Marzo</option>
                <option value="4">Abril</option><option value="5">Mayo</option><option value="6">Junio</option>
                <option value="7">Julio</option><option value="8">Agosto</option><option value="9">Septiembre</option>
                <option value="10">Octubre</option><option value="11">Noviembre</option><option value="12">Diciembre</option>
              </select></div>
            <div class="form-group col-md-3 col-sm-6 col-xs-6"><label for="fAnioArq">Año:</label>
              <select id="fAnioArq" class="form-control"></select></div>
          </div>

          <div class="table-responsive">
            <table class="table table-bordered rd-tabla">
              <thead><tr><th>Fecha</th><th>Tipo</th><th>Centro</th><th>Responsable</th><th class="rd-num-col">Total</th><th class="rd-num-col">Fondo</th><th>Resultado</th><th></th></tr></thead>
              <tbody id="filasArq"><tr><td colspan="8" class="rd-vacio">Cargando...</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ====================== NUEVO ARQUEO ====================== -->
      <div class="x_panel" id="panelFormArq" style="display:none;">
        <div class="x_title"><h2><i class="fa fa-plus-square"></i> Nuevo arqueo</h2><div class="clearfix"></div></div>
        <div class="x_content">
          <form id="formArq" autocomplete="off">
            <h4 class="rd-subtitulo">Tipo de arqueo</h4>
            <div class="rd-opciones" id="arqTipos"></div>

            <div class="row" style="margin-top:12px;">
              <div class="form-group col-md-4 col-sm-6 col-xs-12">
                <label for="arqCentro">Centro: <span class="rd-req">*</span></label>
                <select id="arqCentro" name="centro" class="form-control"></select>
                <small class="rd-ayuda" id="arqFondoTxt"></small>
              </div>
              <div class="form-group col-md-3 col-sm-6 col-xs-12">
                <label for="arqFecha">Fecha: <span class="rd-req">*</span></label>
                <input type="date" id="arqFecha" name="fecha" class="form-control">
                <div class="rd-dia-rapido" id="arqRapido" role="group" aria-label="Día del arqueo">
                  <button type="button" class="btn btn-default btn-xs" data-dia="-1">Ayer</button>
                  <button type="button" class="btn btn-default btn-xs" data-dia="0">Hoy</button>
                  <button type="button" class="btn btn-default btn-xs" data-dia="1">Mañana</button>
                </div>
              </div>
              <div class="form-group col-md-2 col-sm-6 col-xs-6">
                <label for="arqHora">Hora: <span class="rd-req">*</span></label>
                <input type="time" id="arqHora" name="hora" class="form-control rd-con-ahora">
              </div>
              <div class="form-group col-md-3 col-sm-6 col-xs-6">
                <label for="arqHoraFin">Hora de terminación:</label>
                <input type="time" id="arqHoraFin" name="horaFin" class="form-control rd-con-ahora">
              </div>
            </div>
            <div class="row">
              <div class="form-group col-md-6 col-sm-6 col-xs-12">
                <label for="arqResponsable"><span id="arqResponsableTxt">Responsable del dinero</span>: <span class="rd-req">*</span></label>
                <input type="text" id="arqResponsable" name="responsable" class="form-control" maxlength="120" placeholder="Nombre completo">
              </div>
              <div class="form-group col-md-6 col-sm-6 col-xs-12">
                <label for="arqCargo">Cargo:</label>
                <input type="text" id="arqCargo" name="cargo" class="form-control" maxlength="80" placeholder="Ej: jefe de operación">
              </div>
            </div>

            <!-- Arqueo de casetas: la caseta y los campos configurados para el peaje -->
            <div id="arqBloqueCaseta" style="display:none;">
              <div class="row">
                <div class="form-group col-sm-4 col-xs-12">
                  <label for="arqCaseta">Caseta: <span class="rd-req">*</span></label>
                  <input type="text" id="arqCaseta" name="caseta" class="form-control" maxlength="30" placeholder="Ej: 3">
                </div>
              </div>
              <h4 class="rd-subtitulo">Recaudo</h4>
              <div id="arqCampos"></div>
            </div>

            <div id="arqBloqueEfectivo">
            <h4 class="rd-subtitulo">Efectivo contado</h4>
            <div id="arqEfectivo"></div>
            </div>

            <div id="arqBloqueDocs">
              <h4 class="rd-subtitulo">Facturas, reintegros, recibos y faltantes</h4>
              <div class="table-responsive">
                <table class="table rd-tabla-docs">
                  <thead><tr><th>Fecha</th><th>Concepto</th><th>Valor</th><th>Observación</th><th></th></tr></thead>
                  <tbody id="arqDocs"></tbody>
                </table>
              </div>
              <button type="button" class="btn btn-default btn-sm" id="btnAgregarDoc"><i class="fa fa-plus"></i> Agregar documento</button>
            </div>

            <!-- Totales: se calculan mientras se escribe -->
            <div class="rd-totales" id="arqTotales"></div>

            <h4 class="rd-subtitulo">Observación</h4>
            <textarea id="arqObs" name="observacion" class="form-control" rows="2" maxlength="2000"
                      placeholder="Obligatoria si el arqueo no cuadra con el fondo autorizado."></textarea>

            <div class="ln_solid"></div>
            <div class="rd-acciones rd-acciones-fijas">
              <button type="button" class="btn btn-default" id="btnCancelarArq">Cancelar</button>
              <button type="submit" class="btn btn-success" id="btnGuardarArq"><i class="fa fa-save"></i> Registrar arqueo</button>
            </div>
          </form>
        </div>
      </div>

      <!-- ============ CAMPOS DEL ARQUEO DE CASETAS (configurables) ============ -->
      <div class="x_panel" id="panelCampos" style="display:none;">
        <div class="x_title"><h2><i class="fa fa-list-ul"></i> Campos del arqueo de casetas</h2>
          <button type="button" class="btn btn-default pull-right" id="btnVolverCampos"><i class="fa fa-arrow-left"></i> Volver</button>
          <div class="clearfix"></div></div>
        <div class="x_content">
          <p class="rd-ayuda" style="margin-top:0;">Cada renglón del arqueo de casetas es un campo. Los de "todos los peajes" salen en todos; en un peaje
            puedes quitarlos o agregarle campos propios. Los arqueos ya registrados conservan sus renglones tal como estaban.</p>
          <div class="row">
            <div class="form-group col-md-5 col-sm-7 col-xs-12"><label for="cmpCentro">Ver los campos de:</label>
              <select id="cmpCentro" class="form-control"></select></div>
          </div>
          <div class="table-responsive">
            <table class="table table-bordered rd-tabla rd-tabla-compacta">
              <thead><tr><th style="width:70px;">Orden</th><th>Campo</th><th>Cómo se comporta</th><th>De dónde es</th><th>En este peaje</th><th></th></tr></thead>
              <tbody id="filasCampos"></tbody>
            </table>
          </div>
          <h4 class="rd-subtitulo">Agregar un campo <span id="cmpDonde" class="rd-ayuda" style="display:inline;"></span></h4>
          <div class="row" id="cmpNuevo">
            <div class="form-group col-md-5 col-sm-6 col-xs-12"><label for="cmpNombre">Nombre:</label>
              <input type="text" id="cmpNombre" class="form-control" maxlength="80" placeholder="Ej: TARJETAS O TAG"></div>
            <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="cmpRol">Cómo se comporta:</label>
              <select id="cmpRol" class="form-control"></select></div>
            <div class="form-group col-md-1 col-sm-3 col-xs-4"><label for="cmpOrden">Orden:</label>
              <input type="number" id="cmpOrden" class="form-control" value="10"></div>
            <div class="form-group col-md-2 col-sm-3 col-xs-8"><label>&nbsp;</label>
              <button type="button" class="btn btn-success btn-block" id="btnAgregarCampo"><i class="fa fa-plus"></i> Agregar</button></div>
          </div>
        </div>
      </div>

      <!-- ====================== FONDOS AUTORIZADOS ====================== -->
      <div class="x_panel" id="panelFondos" style="display:none;">
        <div class="x_title"><h2><i class="fa fa-cog"></i> Fondos autorizados</h2>
          <button type="button" class="btn btn-default pull-right" id="btnVolverFondos"><i class="fa fa-arrow-left"></i> Volver</button>
          <div class="clearfix"></div></div>
        <div class="x_content">
          <p class="rd-ayuda" style="margin-top:0;">El valor contra el que se compara cada arqueo. Déjalo vacío si el centro no maneja ese fondo.
            Si cambias un fondo, los arqueos ya registrados conservan el valor que tenían.</p>
          <div class="table-responsive">
            <table class="table table-bordered rd-tabla">
              <thead><tr><th>Proyecto</th><th>Centro</th><th>Caja menor</th><th>Recambio</th><th></th></tr></thead>
              <tbody id="filasFondos"></tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ====================== DETALLE ====================== -->
<div class="modal fade" id="modalArq" tabindex="-1" role="dialog" aria-labelledby="modalArqTitulo">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header rd-modal-cab">
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="modalArqTitulo">Arqueo</h4>
      </div>
      <div class="modal-body" id="modalArqCuerpo"></div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=4"></script>
<script type="text/javascript" src="../Ajax/ArqueoAjax.js?v=6"></script>
<?php
    } else {
        echo "<script>window.location.replace('InicioVista.php');</script>";
    }
} else {
    echo "<script>window.location.replace('login.php');</script>";
}
?>
