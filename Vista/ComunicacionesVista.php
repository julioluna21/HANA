<?php
//Reporte diario — Comunicaciones y oficios
//La hoja "Comunicaciones y SOLIC" del Excel: lo que llega por atender y lo que
//hay que enviar, con su tiempo de respuesta
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    //Si el administrador apagó este módulo (Parámetros del sistema), la pantalla no abre
    require_once __DIR__ . '/../Modelo/HanaConfig.php';
    if (!HanaConfig::modulo('COMUNICACIONES')) { echo "<script>window.location.replace('ReporteDiarioVista.php');</script>"; exit; }
    HanaDB::usarModulo('COMUNICACIONES');
    //Reporte del coordinador: lo llena quien coordina un proyecto (Configuración → Proyectos)
    require_once __DIR__ . '/../Modelo/HanaDB.php';
    $esCoordinador = isset($_SESSION['Idcolaborador']) && HanaDB::esCoordinador((int)$_SESSION['Idcolaborador']);
    //Oficios como las RQ: entra cualquiera con un proyecto asignado (coordina, es jefe de un peaje o
    //tiene peajes asignados en Usuarios). El controlador revisa los proyectos uno por uno
    $tieneAsignados = isset($_SESSION['Idcolaborador'], $_SESSION['IdUsuarios']) && HanaDB::fila(
        "SELECT 1 AS ok FROM centros_operacion c WHERE c.Estado = '1' AND (c.ID_COLABORADOR_JEFE = ? OR c.ID_CENTRO_OP IN
           (SELECT ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP FROM asoc_usuarios_sistemas_x_cop WHERE ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = ?)) LIMIT 1",
        'ii', array((int)$_SESSION['Idcolaborador'], (int)$_SESSION['IdUsuarios']));
    if ($esCoordinador || $tieneAsignados) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=13" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="rd-migas"><a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> Comunicaciones y oficios</div>

      <div class="x_panel" id="panelLista">
        <div class="x_title">
          <h2><i class="fa fa-inbox"></i> Comunicaciones y oficios</h2>
          <div class="pull-right rd-botones-titulo"><button type="button" class="btn btn-success" id="btnNueva"><i class="fa fa-plus-square"></i> Nueva</button></div>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="rd-contadores rd-contadores-4">
            <div class="rd-contador rd-cnt-pend"><span class="rd-num" id="cAtender">0</span><span class="rd-lbl">Por atender</span></div>
            <div class="rd-contador rd-cnt-pend"><span class="rd-num" id="cEnviar">0</span><span class="rd-lbl">Por enviar</span></div>
            <div class="rd-contador rd-cnt-mal"><span class="rd-num" id="cVencidas">0</span><span class="rd-lbl">Abiertas fuera de tiempo</span></div>
            <div class="rd-contador rd-cnt-ok"><span class="rd-num" id="cCumple">0</span><span class="rd-lbl">Atendidas a tiempo</span></div>
          </div>
          <div class="row">
            <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="fProyecto">Proyecto:</label>
              <select id="fProyecto" class="form-control"><option value="0">Todos</option></select></div>
            <div class="form-group col-md-4 col-sm-3 col-xs-6"><label for="fMes">Ver:</label>
              <select id="fMes" class="form-control">
                <option value="0">Todas las abiertas</option>
                <option value="1">Recibidas en enero</option><option value="2">Recibidas en febrero</option><option value="3">Recibidas en marzo</option>
                <option value="4">Recibidas en abril</option><option value="5">Recibidas en mayo</option><option value="6">Recibidas en junio</option>
                <option value="7">Recibidas en julio</option><option value="8">Recibidas en agosto</option><option value="9">Recibidas en septiembre</option>
                <option value="10">Recibidas en octubre</option><option value="11">Recibidas en noviembre</option><option value="12">Recibidas en diciembre</option>
              </select></div>
            <div class="form-group col-md-4 col-sm-3 col-xs-6"><label for="fAnio">Año:</label>
              <select id="fAnio" class="form-control"></select></div>
          </div>
          <p class="rd-ayuda" id="ayudaPlazo"></p>
          <div class="table-responsive">
            <table class="table table-bordered rd-tabla rd-tabla-compacta">
              <thead><tr><th>Tipo</th><th>ID / radicado</th><th>Recepción</th><th>Medio</th><th>Remitente / área</th><th>Asunto / solicitud</th>
                <th>Responsable</th><th>Atención</th><th class="rd-num-col">Días</th><th>Resultado</th><th>Estado</th><th></th></tr></thead>
              <tbody id="filas"><tr><td colspan="12" class="rd-vacio">Cargando...</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="x_panel" id="panelForm" style="display:none;">
        <div class="x_title"><h2 id="tituloForm"><i class="fa fa-inbox"></i> Nueva comunicación</h2><div class="clearfix"></div></div>
        <div class="x_content">
          <form id="formCom" autocomplete="off">
            <input type="hidden" name="id" id="mId">
            <div class="rd-opciones" id="mTipos"></div>
            <div class="row" style="margin-top:12px;">
              <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="mProyecto">Proyecto: <span class="rd-req">*</span></label>
                <select id="mProyecto" name="proyecto" class="form-control"></select></div>
              <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="mCentro">Centro:</label>
                <select id="mCentro" name="centro" class="form-control"></select></div>
              <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="mRadicado">ID o radicado:</label>
                <input type="text" id="mRadicado" name="radicado" class="form-control" maxlength="40"></div>
            </div>
            <div class="row">
              <div class="form-group col-md-3 col-sm-6 col-xs-6"><label for="mFecha">Fecha de recepción: <span class="rd-req">*</span></label>
                <input type="date" id="mFecha" name="fecha" class="form-control"></div>
              <div class="form-group col-md-3 col-sm-6 col-xs-6"><label for="mMedio">Medio: <span class="rd-req">*</span></label>
                <select id="mMedio" name="medio" class="form-control"></select></div>
              <div class="form-group col-md-6 col-sm-12 col-xs-12"><label for="mRemitente">Remitente o área: <span class="rd-req">*</span></label>
                <input type="text" id="mRemitente" name="remitente" class="form-control" maxlength="120" placeholder="Ej: Operación, Interventoría"></div>
            </div>
            <div class="form-group"><label for="mAsunto">Asunto o solicitud: <span class="rd-req">*</span></label>
              <input type="text" id="mAsunto" name="asunto" class="form-control" maxlength="300" placeholder="Ej: Oficio dobles cobros"></div>
            <div class="row">
              <div class="form-group col-md-6 col-sm-6 col-xs-12"><label for="mResponsable">Responsable: <span class="rd-req">*</span></label>
                <input type="text" id="mResponsable" name="responsable" class="form-control" maxlength="120"></div>
              <div class="form-group col-md-6 col-sm-6 col-xs-12" id="grupoPendiente"><label for="mPendiente">Pendiente de:</label>
                <select id="mPendiente" name="pendiente" class="form-control"></select></div>
            </div>
            <div id="grupoAtencion">
            <h4 class="rd-subtitulo">Atención</h4>
            <div class="row">
              <div class="form-group col-md-3 col-sm-4 col-xs-12"><label for="mAtencion">Fecha de atención:</label>
                <input type="date" id="mAtencion" name="atencion" class="form-control">
                <small class="rd-ayuda">Mientras esté vacía, la comunicación sigue abierta.</small></div>
              <div class="form-group col-md-9 col-sm-8 col-xs-12"><label for="mRespuesta">Respuesta o gestión:</label>
                <textarea id="mRespuesta" name="respuesta" class="form-control" rows="2" maxlength="2000"></textarea></div>
            </div>
            </div>
            <!-- Archivos: el oficio escaneado, la respuesta, soportes -->
            <div id="mAdjuntos"></div>
            <!-- Conversación: todos los del proyecto responden; el coordinador o el admin la resuelven -->
            <div id="mHiloCaja" class="com-hilo-caja" style="display:none;">
              <h4 class="rd-subtitulo"><i class="fa fa-comments-o"></i> Conversación <span id="mEstado"></span></h4>
              <div id="mHilo" class="com-hilo"></div>
              <textarea id="mNuevaResp" class="form-control" rows="2" maxlength="2000" placeholder="Escribe una respuesta o la gestión que hiciste..."></textarea>
              <div class="com-hilo-botones">
                <button type="button" class="btn btn-default" id="btnResponder"><i class="fa fa-reply"></i> Responder</button>
                <button type="button" class="btn btn-success" id="btnResolver" style="display:none;"><i class="fa fa-check"></i> Marcar como resuelta</button>
                <button type="button" class="btn btn-default" id="btnReabrir" style="display:none;"><i class="fa fa-undo"></i> Reabrir</button>
              </div>
            </div>
            <div class="ln_solid"></div>
            <div class="rd-acciones">
              <input type="text" id="mMotivo" class="form-control rd-motivo" maxlength="300" placeholder="Motivo para anular" style="display:none;">
              <button type="button" class="btn btn-default" id="btnAnular" style="display:none;"><i class="fa fa-ban"></i> Anular</button>
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
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=4"></script>
<script type="text/javascript" src="../Ajax/ComunicacionAjax.js?v=4"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
