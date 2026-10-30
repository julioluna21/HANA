<?php
//Reporte diario - Temas con el cliente
//Los temas importantes que cada coordinador trata con el cliente: cuándo se
//abrió el tema, en qué va y cuándo se cerró
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    require_once __DIR__ . '/../Control/PaginaControl.php';
    //Si el administrador apagó este módulo (Parámetros del sistema), la pantalla no abre
    if (!pagModuloActivo('TEMAS')) { echo "<script>window.location.replace('ReporteDiarioVista.php');</script>"; exit; }
    pagUsarModulo('TEMAS');
    //Entra quien tenga la casilla de llenar o la de ver, con peajes asignados
    if (pagEntra('TEMAS')) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=16" rel="stylesheet">
<style>
  .tema-ultimo { color: #555; font-size: 12px; margin-top: 3px; }
  .tema-quieto { color: #b9770e; }
  .tema-cierre { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 8px; }
  .tema-cierre input[type="date"] { width: auto; }
</style>

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="rd-migas"><a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> Temas con el cliente</div>

      <div class="x_panel" id="panelLista">
        <div class="x_title">
          <h2><i class="fa fa-commenting-o"></i> Temas con el cliente</h2>
          <div class="pull-right rd-botones-titulo"><button type="button" class="btn btn-success rd-btn-flotante" id="btnNuevo"><i class="fa fa-plus-square"></i> Nuevo<span class="rd-solo-movil"> tema</span></button></div>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="rd-contadores">
            <div class="rd-contador rd-cnt-pend"><span class="rd-num" id="cAbiertos">0</span><span class="rd-lbl">Abiertos</span></div>
            <div class="rd-contador rd-cnt-mal"><span class="rd-num" id="cQuietos">0</span><span class="rd-lbl">Sin seguimiento hace más de 7 días</span></div>
            <div class="rd-contador rd-cnt-ok"><span class="rd-num" id="cCerrados">0</span><span class="rd-lbl">Cerrados</span></div>
          </div>
          <div class="row">
            <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="fProyecto">Proyecto:</label>
              <select id="fProyecto" class="form-control"><option value="0">Todos</option></select></div>
            <div class="form-group col-md-4 col-sm-3 col-xs-6"><label for="fMes">Ver:</label>
              <select id="fMes" class="form-control">
                <option value="0">Los abiertos</option>
                <option value="1">Los de enero</option><option value="2">Los de febrero</option><option value="3">Los de marzo</option>
                <option value="4">Los de abril</option><option value="5">Los de mayo</option><option value="6">Los de junio</option>
                <option value="7">Los de julio</option><option value="8">Los de agosto</option><option value="9">Los de septiembre</option>
                <option value="10">Los de octubre</option><option value="11">Los de noviembre</option><option value="12">Los de diciembre</option>
              </select></div>
            <div class="form-group col-md-4 col-sm-3 col-xs-6"><label for="fAnio">Año:</label>
              <select id="fAnio" class="form-control"></select></div>
          </div>
          <p class="rd-ayuda">«Los de un mes» son los que estuvieron abiertos en algún momento de ese mes: los que se abrieron, siguieron o se cerraron ese mes.</p>
          <div class="table-responsive">
            <table class="table table-bordered rd-tabla rd-tabla-compacta">
              <thead><tr><th>Proyecto</th><th>Tema</th><th>Con quién</th><th>De qué se trata</th><th>Apertura</th><th>En qué va</th>
                <th class="rd-num-col" title="Días que lleva abierto, o que duró si ya se cerró">Días</th><th>Estado</th><th></th></tr></thead>
              <tbody id="filas"><tr><td colspan="9" class="rd-vacio">Cargando...</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="x_panel" id="panelForm" style="display:none;">
        <div class="x_title"><h2 id="tituloForm"><i class="fa fa-commenting-o"></i> Nuevo tema</h2><div class="clearfix"></div></div>
        <div class="x_content">
          <form id="formTema" autocomplete="off">
            <input type="hidden" name="id" id="mId">
            <div class="row">
              <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="mProyecto">Proyecto: <span class="rd-req">*</span></label>
                <select id="mProyecto" name="proyecto" class="form-control"></select></div>
              <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="mCentro">Peaje:</label>
                <select id="mCentro" name="centro" class="form-control"></select></div>
              <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="mApertura">Fecha de apertura: <span class="rd-req">*</span></label>
                <input type="date" id="mApertura" name="apertura" class="form-control"></div>
            </div>
            <div class="form-group"><label for="mTema">Tema: <span class="rd-req">*</span></label>
              <input type="text" id="mTema" name="tema" class="form-control" maxlength="300" placeholder="Ej: Ajuste de horarios del personal de recaudo"></div>
            <div class="row">
              <div class="form-group col-md-6 col-sm-12 col-xs-12"><label for="mConQuien">Con quién se está tratando: <span class="rd-req">*</span></label>
                <input type="text" id="mConQuien" name="conQuien" class="form-control" maxlength="120" placeholder="Ej: Interventoría, gerente del proyecto"></div>
              <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="mClase">De qué se trata: <span class="rd-req">*</span></label>
                <select id="mClase" name="clase" class="form-control"></select></div>
              <div class="form-group col-md-3 col-sm-6 col-xs-12" id="grupoPendiente"><label for="mPendiente">Pendiente de:</label>
                <select id="mPendiente" name="pendiente" class="form-control"></select></div>
            </div>
            <div class="form-group" id="grupoInicio"><label for="mInicio">En qué va:</label>
              <textarea id="mInicio" name="inicio" class="form-control" rows="2" maxlength="2000" placeholder="Lo que se habló o se acordó hasta ahora (opcional)"></textarea></div>

            <!-- Seguimiento: cada vez que el tema avanza se escribe en qué va -->
            <div id="mSegCaja" class="com-hilo-caja" style="display:none;">
              <h4 class="rd-subtitulo"><i class="fa fa-comments-o"></i> Seguimiento <span id="mEstado"></span></h4>
              <div id="mSeg" class="com-hilo"></div>
              <div id="mSegEscribir">
                <textarea id="mNuevaNota" class="form-control" rows="2" maxlength="2000" placeholder="Escribe en qué va el tema..."></textarea>
                <div class="com-hilo-botones">
                  <button type="button" class="btn btn-default" id="btnAnotar"><i class="fa fa-pencil"></i> Guardar seguimiento</button>
                  <span class="tema-cierre" id="cajaCerrar">
                    <label for="mFechaCierre" style="margin:0;">Cerrado el</label>
                    <input type="date" id="mFechaCierre" class="form-control input-sm">
                    <button type="button" class="btn btn-success" id="btnCerrar"><i class="fa fa-check"></i> Cerrar el tema</button>
                  </span>
                  <button type="button" class="btn btn-default" id="btnReabrir" style="display:none;"><i class="fa fa-undo"></i> Reabrir</button>
                </div>
                <small class="rd-ayuda">Al cerrar, lo que esté escrito arriba queda como la nota de cierre.</small>
              </div>
            </div>
            <div class="ln_solid"></div>
            <div class="rd-acciones">
              <input type="text" id="mMotivo" class="form-control rd-motivo" maxlength="300" placeholder="Motivo para anular" style="display:none;">
              <button type="button" class="btn btn-default" id="btnAnular" style="display:none;"><i class="fa fa-ban"></i> Anular</button>
              <button type="button" class="btn btn-default" id="btnVolver">Volver</button>
              <button type="submit" class="btn btn-success" id="btnGuardar"><i class="fa fa-save"></i> Guardar</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=12"></script>
<script type="text/javascript" src="../Ajax/TemaClienteAjax.js?v=1"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
