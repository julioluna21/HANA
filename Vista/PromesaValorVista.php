<?php
//Promesa de valor (sección propia; el ausentismo se retiró del sistema)
//La hoja «(PROMESA DE VALOR)» del Excel, una por peaje: por cada cargo y cada
//día del mes, las horas prometidas y las ejecutadas en la misma matriz, la
//diferencia en colores y la justificación cuando no se cumple
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    require_once __DIR__ . '/../Control/PaginaControl.php';
    if (!pagModuloActivo('PROMESA')) { echo "<script>window.location.replace('InicioVista.php');</script>"; exit; }
    //El coordinador del proyecto la llena; todos los roles la consultan, con los comentarios de quien la llena
    if (pagPromesaEntrar()) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=18" rel="stylesheet">
<link href="../public/css/promesa.css?v=5" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <?php //Ausentismo y Promesa de valor, las dos partes de la sección (si solo entra a la promesa, la miga de pan)
        $secciones = pagSeccionesAusentismo('PROMESA');
        echo $secciones !== '' ? $secciones : '<div class="rd-migas"><i class="fa fa-clock-o"></i> Promesa de valor</div>'; ?>

      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-clock-o"></i> Promesa de valor</h2>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <p class="rd-intro">Por cada cargo del peaje, las <strong>horas</strong> prometidas y las ejecutadas de cada día.
            La diferencia es promesa menos ejecutado, como en el Excel. Si no se cumple, escribe en la fila de justificación por qué.</p>

          <div class="row">
            <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="pvProyecto">Proyecto:</label><select id="pvProyecto" class="form-control"></select></div>
            <div class="form-group col-md-2 col-sm-3 col-xs-6"><label for="pvMes">Mes:</label>
              <select id="pvMes" class="form-control">
                <option value="1">Enero</option><option value="2">Febrero</option><option value="3">Marzo</option><option value="4">Abril</option>
                <option value="5">Mayo</option><option value="6">Junio</option><option value="7">Julio</option><option value="8">Agosto</option>
                <option value="9">Septiembre</option><option value="10">Octubre</option><option value="11">Noviembre</option><option value="12">Diciembre</option>
              </select></div>
            <div class="form-group col-md-2 col-sm-3 col-xs-6"><label for="pvAnio">Año:</label><select id="pvAnio" class="form-control"></select></div>
          </div>

          <!-- Un botón por peaje del proyecto, con cómo va el mes -->
          <div class="pv-centros" id="pvCentros"></div>
          <div class="pv-quien" id="pvQuien"></div>

          <div class="pv-leyenda">
            <span><i class="pv-muestra pv-cumple"></i> Cumple</span>
            <span><i class="pv-muestra pv-debajo"></i> Por debajo</span>
            <span><i class="pv-muestra pv-encima"></i> Por encima</span>
            <span><i class="pv-muestra pv-sinjust"></i> Falta justificar</span>
          </div>

          <!-- Registro rápido, como «Registrar una ausencia» en Ausentismo: un día o una parte del mes
               de un cargo, sin buscar las casillas. Se anota en la misma matriz. Solo para quien la llena -->
          <div class="aus-rapido" id="pvRapido" style="display:none;">
            <h4 class="rd-subtitulo" style="margin-top:0;">Registrar un día o una parte del mes</h4>
            <p class="rd-ayuda" style="margin-top:0;">Elige el día y, si es una parte del mes (por ejemplo del 1 al 15), también el último día: se anota en cada día del rango.
              Escribe solo lo que quieras poner: lo que dejes vacío no cambia. El ejecutado se registra hasta hoy.</p>
            <form id="formPvRapido" autocomplete="off">
              <div class="row">
                <div class="form-group col-md-2 col-sm-4 col-xs-6"><label for="rpDesde">Día: <span class="rd-req">*</span></label><input type="date" id="rpDesde" class="form-control"></div>
                <div class="form-group col-md-2 col-sm-4 col-xs-6"><label for="rpHasta">Hasta (opcional):</label><input type="date" id="rpHasta" class="form-control" title="El último día. Vacío = solo un día"></div>
                <div class="form-group col-md-2 col-sm-4 col-xs-12"><label for="rpCargo">Cargo: <span class="rd-req">*</span></label><select id="rpCargo" class="form-control"></select></div>
                <div class="form-group col-md-1 col-sm-3 col-xs-6"><label for="rpPromesa">Promesa:</label><input type="text" inputmode="decimal" id="rpPromesa" class="form-control" maxlength="7" placeholder="horas"></div>
                <div class="form-group col-md-1 col-sm-3 col-xs-6"><label for="rpEjecutado">Ejecutado:</label><input type="text" inputmode="decimal" id="rpEjecutado" class="form-control" maxlength="7" placeholder="horas"></div>
                <div class="form-group col-md-2 col-sm-6 col-xs-12"><label for="rpJust">Justificación:</label><input type="text" id="rpJust" class="form-control" maxlength="300" placeholder="Por qué no se cumplió"></div>
                <div class="form-group col-md-2 col-sm-12 col-xs-12"><label class="hidden-xs hidden-sm">&nbsp;</label>
                  <button type="submit" class="btn btn-success btn-block" id="rpRegistrar"><i class="fa fa-plus"></i> Registrar</button></div>
              </div>
            </form>
          </div>

          <div class="pv-acciones" id="pvAcciones" style="display:none;">
            <button type="button" class="btn btn-default" id="pvCopiar" title="Repite la promesa de los días 1 a 7 en el resto del mes, día por día de la semana">
              <i class="fa fa-files-o"></i> Copiar la semana 1 al resto del mes</button>
            <button type="button" class="btn btn-success" id="pvGuardar" disabled><i class="fa fa-save"></i> Guardar</button>
            <span class="pv-pendientes" id="pvPendientes"></span>
          </div>

          <form id="formPromesa" onsubmit="return false;">
            <div id="pvMatriz"><p class="rd-vacio">Cargando…</p></div>
          </form>
          <!-- Para todos los roles: los comentarios del mes y por qué no se cumplió (los pinta PromesaValorAjax.js) -->
          <div id="pvComentarios"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=12"></script>
<script type="text/javascript" src="../Ajax/PromesaValorAjax.js?v=14"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
