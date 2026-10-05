<?php
//Ausentismo (Fase 3) — el "Reporte nacional de ausentismo" dentro de HANA.
//Registra el coordinador de cada proyecto; consultan 20M, 21M y 22M
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    require_once __DIR__ . '/../Modelo/HanaConfig.php';
    if (!HanaConfig::modulo('AUSENTISMO')) { echo "<script>window.location.replace('InicioVista.php');</script>"; exit; }
    $esCoordinador = isset($_SESSION['Idcolaborador']) && HanaDB::esCoordinador((int)$_SESSION['Idcolaborador']);
    //El jefe de peaje registra el de su peaje (si el administrador lo permite en Parámetros)
    $esJefe = isset($_SESSION['Idcolaborador']) && HanaConfig::si('AUS_JEFES_EDITAN')
              && HanaDB::fila("SELECT 1 AS ok FROM centros_operacion WHERE Estado = '1' AND ID_COLABORADOR_JEFE = ? LIMIT 1", 'i', array((int)$_SESSION['Idcolaborador']));
    if ($esCoordinador || $esJefe || in_array("20M", $modulosAcceso) || in_array("21M", $modulosAcceso) || in_array("22M", $modulosAcceso) || in_array("25M", $modulosAcceso)) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=13" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-user-times"></i> Ausentismo</h2>
          <div class="pull-right rd-botones-titulo">
            <a class="btn btn-default" id="btnExcel" href="#"><i class="fa fa-file-excel-o"></i> Descargar Excel</a>
            <button type="button" class="btn btn-default" id="btnCerrar" style="display:none;"><i class="fa fa-lock"></i> Cerrar el mes</button>
            <button type="button" class="btn btn-default" id="btnReabrir" style="display:none;"><i class="fa fa-unlock"></i> Reabrir el mes</button>
          </div>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="row">
            <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="aProyecto">Proyecto:</label><select id="aProyecto" class="form-control"></select></div>
            <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="aCentro">Peaje o báscula:</label><select id="aCentro" class="form-control"></select></div>
            <div class="form-group col-md-3 col-sm-6 col-xs-6"><label for="aMes">Mes:</label><select id="aMes" class="form-control">
              <option value="1">Enero</option><option value="2">Febrero</option><option value="3">Marzo</option><option value="4">Abril</option>
              <option value="5">Mayo</option><option value="6">Junio</option><option value="7">Julio</option><option value="8">Agosto</option>
              <option value="9">Septiembre</option><option value="10">Octubre</option><option value="11">Noviembre</option><option value="12">Diciembre</option></select></div>
            <div class="form-group col-md-3 col-sm-6 col-xs-6"><label for="aAnio">Año:</label><select id="aAnio" class="form-control"></select></div>
          </div>
          <div class="crono-quien" id="aQuien"></div>
          <!-- Cada centro del proyecto: si ya tiene registrado el mes -->
          <div class="aus-centros" id="aCentros"></div>

          <ul class="nav nav-tabs cd-pestanas" role="tablist">
            <li class="active"><a href="#tabRegistrar" data-toggle="tab">Registrar por centro</a></li>
            <li><a href="#tabConsolidado" data-toggle="tab" id="tabConsLink">Consolidado del proyecto</a></li>
          </ul>
          <div class="tab-content cd-contenido">
            <div class="tab-pane active" id="tabRegistrar">
              <p class="rd-ayuda" style="margin-top:0;">En cada celda, cuántas personas del cargo tuvieron esa novedad ese día. Se escribe y se pasa de celda con las flechas del teclado.
                Las filas en cursiva (como <em>cubre recolector</em>) no son ausencia: no suman en las ausencias diarias.</p>
              <div id="ausNota"></div>
              <form id="ausForm" onsubmit="return false;"><div id="ausGrid"><p class="rd-vacio">Cargando...</p></div></form>
              <div class="rd-acciones rd-acciones-fijas" id="ausAcciones">
                <span class="rd-ayuda" id="ausCambios" style="margin:0 auto 0 0;"></span>
                <button type="button" class="btn btn-success" id="btnGuardarAus"><i class="fa fa-save"></i> Guardar</button>
              </div>
            </div>
            <div class="tab-pane" id="tabConsolidado"><div id="ausConsolidado"><p class="rd-vacio">Cargando...</p></div></div>
            <!-- Quién cambió qué y cuándo (lo necesita el auditor: varias personas pueden editar) -->
            <details class="aus-historial" id="ausHistorial">
              <summary><i class="fa fa-history"></i> Historial de cambios de este peaje en el mes</summary>
              <div id="ausHistorialCuerpo"><p class="rd-vacio">Cargando...</p></div>
            </details>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=4"></script>
<script type="text/javascript" src="../Ajax/AusentismoAjax.js?v=2"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
