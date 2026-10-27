<?php
/*
  Pantalla retirada: el ausentismo (matriz de cubrimiento: quién cubrió a quién, los motivos
  CUBRIENDO A..., las inducciones y los días) se quitó del sistema. En esa sección queda solo
  la promesa de valor (Vista/PromesaValorVista.php). Si alguien entra por la dirección, va al inicio.
  El código de abajo se conserva solo como referencia.
*/
header('Location: InicioVista.php');
exit;
?>
<?php
//Ausentismo - el "Reporte nacional de ausentismo" dentro de HANA.
//Las ausencias con su motivo están en la marcación de turnos: aquí se anota cómo se cubrió
//cada falta (NO SE CUBRIÓ, CUBRIENDO A... o un apoyo a otro peaje o proyecto).
//Registra quien tiene 52M y consulta quien tiene 53M, siempre en sus peajes asignados.
//El consolidado se ve de todo el mes o de un rango de días; del proyecto, de un solo peaje o de todos sus proyectos juntos
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    require_once __DIR__ . '/../Control/PaginaControl.php';
    if (!pagModuloActivo('AUSENTISMO')) { echo "<script>window.location.replace('InicioVista.php');</script>"; exit; }
    //Lo registra, lo ve, cierra o corrige quien tenga esas casillas, en sus peajes asignados
    $esCoordinador = pagPuede('AUSENTISMO', 'llenar') && pagTieneCentros();
    $esJefe = false;
    if (pagAusentismoEntrar()) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=18" rel="stylesheet">
<style media="print">@page{ size: landscape; }</style> <!-- la matriz del mes (31 días) cabe mejor a lo ancho -->

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <?php echo pagSeccionesAusentismo('AUSENTISMO'); //Ausentismo y Promesa de valor, las dos partes de la sección ?>
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
            <li class="active"><a href="#tabRapido" data-toggle="tab">Registrar una ausencia</a></li>
            <li><a href="#tabRegistrar" data-toggle="tab">Matriz del mes</a></li>
            <li><a href="#tabConsolidado" data-toggle="tab" id="tabConsLink">Consolidado del proyecto</a></li>
          </ul>
          <div class="tab-content cd-contenido">
            <!-- Registro rápido: una ausencia a la vez. Escribe en las mismas celdas de la matriz del mes -->
            <div class="tab-pane active" id="tabRapido">
              <p class="rd-ayuda" style="margin-top:0;">Aquí se anota <strong>cómo se cubrió cada falta</strong> del peaje; la ausencia con su motivo ya está en la marcación de turnos.
                Elige el día, el cargo del puesto que quedó sin cubrir, qué pasó y cuántas personas: <em>NO SE CUBRIÓ</em> (faltó la persona y nadie ocupó su puesto),
                <em>CUBRIENDO A…</em> (la persona dejó su cargo para cubrir otro, que quedó cubierto) o un <em>APOYO</em> a otro peaje o proyecto.
                Queda anotado en la matriz del mes, así que el consolidado y el Excel se actualizan solos. Si pasó varios días seguidos,
                escribe también el último día: se anota en cada día del rango. <span id="ausFuturoAyuda"></span></p>
              <div id="ausNotaRapido"></div>
              <form id="ausRapido" class="aus-rapido" autocomplete="off">
                <div class="row">
                  <div class="form-group col-md-2 col-sm-3 col-xs-6">
                    <label for="rFecha">Día: <span class="rd-req">*</span></label>
                    <input type="date" id="rFecha" name="fecha" class="form-control">
                  </div>
                  <div class="form-group col-md-2 col-sm-3 col-xs-6">
                    <label for="rHasta">Hasta (opcional):</label>
                    <input type="date" id="rHasta" name="hasta" class="form-control" title="El último día. Vacío = solo un día">
                  </div>
                  <div class="form-group col-md-2 col-sm-6 col-xs-12">
                    <label for="rCargo">Cargo: <span class="rd-req">*</span></label>
                    <select id="rCargo" name="cargo" class="form-control"></select>
                  </div>
                  <div class="form-group col-md-2 col-sm-6 col-xs-12">
                    <label for="rNovedad">¿Qué pasó?: <span class="rd-req">*</span></label>
                    <select id="rNovedad" name="novedad" class="form-control"></select>
                  </div>
                  <div class="form-group col-md-2 col-sm-6 col-xs-6">
                    <label for="rCantidad">Personas: <span class="rd-req">*</span></label>
                    <input type="number" id="rCantidad" name="cantidad" class="form-control" min="1" max="999" value="1">
                  </div>
                  <div class="form-group col-md-2 col-sm-6 col-xs-6">
                    <label class="hidden-xs">&nbsp;</label>
                    <button type="submit" class="btn btn-success btn-block" id="btnRegistrarAus"><i class="fa fa-plus"></i> Registrar</button>
                  </div>
                </div>
              </form>
              <h4 class="rd-subtitulo">Ausencias registradas en el mes <span id="ausTotalMes"></span></h4>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Día</th><th>Cargo</th><th>Qué pasó</th><th class="rd-num-col">Personas</th><th></th></tr></thead>
                <tbody id="ausLista"><tr><td colspan="5" class="rd-vacio">Cargando...</td></tr></tbody></table></div>
            </div>
            <div class="tab-pane" id="tabRegistrar">
              <p class="rd-ayuda" style="margin-top:0;">En cada celda, cuántos puestos del cargo quedaron sin cubrir ese día y por qué. Se escribe y se pasa de celda con las flechas del teclado.
                <em>NO SE CUBRIÓ</em>: faltó la persona y nadie ocupó su puesto. <em>CUBRIENDO A…</em>: la persona de este cargo dejó su puesto para cubrir el otro, que quedó cubierto.
                <em>APOYO…</em>: salió a apoyar otro peaje o proyecto. Los motivos anteriores (incapacidad, licencias…) solo salen en los meses que los tienen.</p>
              <div id="ausNota"></div>
              <form id="ausForm" onsubmit="return false;"><div id="ausGrid"><p class="rd-vacio">Cargando...</p></div></form>
              <div class="rd-acciones rd-acciones-fijas" id="ausAcciones">
                <span class="rd-ayuda" id="ausCambios" style="margin:0 auto 0 0;"></span>
                <button type="button" class="btn btn-success" id="btnGuardarAus"><i class="fa fa-save"></i> Guardar</button>
              </div>
            </div>
            <div class="tab-pane" id="tabConsolidado">
              <!-- Qué se suma: el proyecto elegido, solo el peaje elegido o todos los proyectos, y de qué día a qué día del mes -->
              <div class="aus-rapido aus-filtro-cons">
                <div class="row">
                  <div class="form-group col-md-3 col-sm-12 col-xs-12" id="consAlcanceGrupo">
                    <label for="consAlcance">Ver:</label>
                    <!-- "Todos los proyectos" lo quita AusentismoAjax.js a quien no los consulta -->
                    <select id="consAlcance" class="form-control"><option value="proyecto">El proyecto elegido</option><option value="centro">Solo el peaje elegido</option><option value="todos">Todos los proyectos</option></select>
                  </div>
                  <div class="form-group col-md-2 col-sm-3 col-xs-6">
                    <label for="consDesde">Desde el día:</label>
                    <select id="consDesde" class="form-control"></select>
                  </div>
                  <div class="form-group col-md-2 col-sm-3 col-xs-6">
                    <label for="consHasta">Hasta el día:</label>
                    <select id="consHasta" class="form-control"></select>
                  </div>
                  <div class="form-group col-md-5 col-sm-6 col-xs-12">
                    <label class="hidden-xs">&nbsp;</label>
                    <div class="aus-rangos">
                      <button type="button" class="btn btn-default btn-sm" data-rango="mes">Todo el mes</button>
                      <button type="button" class="btn btn-default btn-sm" data-rango="q1">Días 1 a 14</button>
                      <button type="button" class="btn btn-default btn-sm" data-rango="q2">Del 15 al final</button>
                      <a class="btn btn-default btn-sm" id="btnExcelCons" href="#"><i class="fa fa-file-excel-o"></i> Excel de lo que ves</a>
                    </div>
                  </div>
                </div>
              </div>
              <div id="ausConsolidado"><p class="rd-vacio">Cargando...</p></div>
            </div>
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
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=12"></script>
<script type="text/javascript" src="../Ajax/AusentismoAjax.js?v=9"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
