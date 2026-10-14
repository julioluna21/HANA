<?php
//Ausentismo - Promesa de valor (antes estaba en el reporte diario)
//Por cada peaje y cada cargo: cuántas personas se prometieron para el día
//(promesa de valor) y cuántas trabajaron (ejecutado). Reemplaza las hojas
//"(PROMESA DE VALOR)" del Excel del reporte diario
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    require_once __DIR__ . '/../Control/PaginaControl.php'; //quién entra lo decide el controlador
    //Si el administrador apagó el módulo (Parámetros del sistema), la pantalla no abre
    if (!pagModuloActivo('PROMESA')) { echo "<script>window.location.replace('InicioVista.php');</script>"; exit; }
    if (pagPromesaEntrar()) {                //coordinador, jefe de peaje (si se permite), casillas 38M, 39M o quien consulta
        $verConsolidado = pagPromesaConsolidado();
        include('head.php');
?>
<link href="../public/css/reporte.css?v=16" rel="stylesheet">
<link href="../public/css/promesa.css?v=3" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">

      <!-- Migas: de dónde viene esta pantalla -->
      <div class="rd-migas"><a href="AusentismoVista.php"><i class="fa fa-user-times"></i> Ausentismo</a> <span>›</span> Promesa de valor</div>

      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-users"></i> Promesa de valor</h2>
<?php   if ($verConsolidado) { ?>
          <div class="pull-right rd-botones-titulo">
            <a class="btn btn-default" href="PromesaConsolidadoVista.php"><i class="fa fa-bar-chart"></i> Ver el consolidado</a>
          </div>
<?php   } ?>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <p class="rd-intro">Por cada cargo del peaje: cuántas personas se prometieron para el día y cuántas trabajaron de verdad.
            La diferencia la saca el sistema, y el porqué sale de lo que ya está en Ausentismo.</p>

          <div class="row">
            <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="pvProyecto">Proyecto:</label><select id="pvProyecto" class="form-control"></select></div>
            <div class="form-group col-md-4 col-sm-6 col-xs-12"><label for="pvCentro">Peaje o báscula:</label><select id="pvCentro" class="form-control"></select></div>
          </div>
          <div class="crono-quien pv-quien" id="pvQuien"></div>
          <!-- Un botón por peaje del proyecto: verde si ya tiene el ejecutado del día que se está viendo -->
          <div class="aus-centros" id="pvCentros"></div>

          <!-- Filtro por cargo: deja ver solo algunos cargos (por ejemplo, solo los recolectores) en el día y en el mes -->
          <div class="pv-filtro-cargos" id="pvFiltroCargos" style="display:none;"></div>

          <ul class="nav nav-tabs cd-pestanas" role="tablist">
            <li class="active"><a href="#tabDia" data-toggle="tab">Registrar el día</a></li>
            <li><a href="#tabMes" data-toggle="tab" id="tabMesLink">Mes del peaje</a></li>
            <li><a href="#tabPromesa" data-toggle="tab" id="tabPromesaLink">Promesa del peaje</a></li>
          </ul>
          <div class="tab-content cd-contenido">

            <!-- ====================== EL DÍA ====================== -->
            <div class="tab-pane active" id="tabDia">
              <div class="pv-barra-dia">
                <!-- Los días que se pueden llenar: hoy y los que el administrador haya abierto. Los pinta PromesaValorAjax.js -->
                <div class="btn-group" id="pvDias" role="group" aria-label="Día que se registra"></div>
                <label class="pv-otro-dia" for="pvOtroDia">Consultar otro día:
                  <input type="date" id="pvOtroDia" class="form-control input-sm"></label>
              </div>
              <div id="pvNotaDia"></div>
              <form id="pvForm" autocomplete="off" onsubmit="return false;">
                <div class="table-responsive"><table class="table rd-tabla pv-dia">
                  <thead><tr><th>Cargo</th><th class="rd-num-col">Promesa de valor</th><th class="pv-col-eje">Ejecutado</th>
                    <th class="rd-num-col">Diferencia</th><th>Ausencias registradas ese día</th></tr></thead>
                  <tbody id="pvFilas"><tr><td colspan="5" class="rd-vacio">Cargando...</td></tr></tbody>
                  <tfoot id="pvTotales"></tfoot>
                </table></div>
                <div class="rd-acciones rd-acciones-fijas" id="pvAcciones" style="display:none;">
                  <span class="rd-ayuda pv-ayuda-llenar">«Llenar según el ausentismo» pone en cada cargo la promesa menos las ausencias ya registradas ese día. Revisa y corrige antes de guardar.</span>
                  <button type="button" class="btn btn-default" id="btnLlenar"><i class="fa fa-magic"></i> Llenar según el ausentismo</button>
                  <button type="button" class="btn btn-success" id="btnGuardarPv"><i class="fa fa-save"></i> Guardar</button>
                </div>
              </form>
              <p class="rd-ayuda pv-pie">Diferencia = ejecutado − promesa de valor. En negativo, faltó gente; en positivo, hubo más de lo prometido.
                <span id="pvIrAus"></span></p>
            </div>

            <!-- ====================== EL MES ====================== -->
            <div class="tab-pane" id="tabMes">
              <div class="row">
                <div class="form-group col-md-3 col-sm-4 col-xs-6"><label for="pvMes">Mes:</label><select id="pvMes" class="form-control">
                  <option value="1">Enero</option><option value="2">Febrero</option><option value="3">Marzo</option><option value="4">Abril</option>
                  <option value="5">Mayo</option><option value="6">Junio</option><option value="7">Julio</option><option value="8">Agosto</option>
                  <option value="9">Septiembre</option><option value="10">Octubre</option><option value="11">Noviembre</option><option value="12">Diciembre</option></select></div>
                <div class="form-group col-md-2 col-sm-3 col-xs-6"><label for="pvAnio">Año:</label><select id="pvAnio" class="form-control"></select></div>
              </div>
              <p class="rd-ayuda" style="margin-top:0;">Cada bloque es un cargo, como en la hoja del Excel. Los totales por semana cuentan solo los días que ya tienen ejecutado;
                la promesa de los días sin registrar se ve en gris.</p>
              <div id="pvMesGrid"><p class="rd-vacio">Cargando...</p></div>
            </div>

            <!-- ====================== LA PROMESA ====================== -->
            <div class="tab-pane" id="tabPromesa">
              <p class="rd-ayuda" style="margin-top:0;">La promesa se escribe una sola vez y vale todos los días hasta que se cambie. Si unos días de la semana
                son distintos (la contingencia de viernes a domingo y los festivos), se marcan abajo en «Días que se repiten». Y si son unas fechas
                puntuales (un puente, una temporada), se agregan como un rango.</p>
              <div id="pvNotaPromesa"></div>

              <!-- Solo lo ve quien puede definir la promesa de este peaje -->
              <div id="pvDefinir" style="display:none;">
                <form id="pvFormPromesa" class="aus-rapido" autocomplete="off" onsubmit="return false;">
                  <h4 class="rd-subtitulo" style="margin-top:0;">Personas por día en cada cargo</h4>
                  <div class="pv-cargos" id="pvCargosPromesa"></div>
                  <div class="row">
                    <div class="form-group col-md-3 col-sm-5 col-xs-12">
                      <label for="pvDesde">Rige desde: <span class="rd-req">*</span></label>
                      <input type="date" id="pvDesde" class="form-control">
                    </div>
                    <div class="form-group col-md-4 col-sm-7 col-xs-12">
                      <label class="hidden-xs">&nbsp;</label>
                      <button type="button" class="btn btn-success btn-block" id="btnGuardarPromesa"><i class="fa fa-save"></i> Guardar la promesa</button>
                    </div>
                  </div>
                  <span class="rd-ayuda">Solo se guardan los cargos que cambies. Un cargo en 0 deja de salir en el registro del día.</span>
                </form>

                <form id="pvFormDias" class="aus-rapido" autocomplete="off" onsubmit="return false;">
                  <h4 class="rd-subtitulo" style="margin-top:0;">Días que se repiten (contingencia)</h4>
                  <p class="rd-ayuda" style="margin-top:0;">Marca los días. Esos días, cada cargo tiene el número que escribas: es el total del día,
                    <strong>no se suma</strong> a la promesa normal. Deja en blanco los cargos que siguen igual.</p>
                  <div class="pv-semana" id="pvSemana" role="group" aria-label="Días en que aplica"></div>
                  <div class="pv-cargos" id="pvCargosDias"></div>
                  <div class="row">
                    <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="dsNombre">Nombre (opcional):</label>
                      <input type="text" id="dsNombre" class="form-control" maxlength="60" placeholder="Contingencia fin de semana"></div>
                    <div class="form-group col-md-2 col-sm-3 col-xs-6"><label for="dsDesde">Rige desde: <span class="rd-req">*</span></label><input type="date" id="dsDesde" class="form-control"></div>
                    <div class="form-group col-md-2 col-sm-3 col-xs-6"><label for="dsHasta">Hasta (opcional):</label><input type="date" id="dsHasta" class="form-control" title="Vacío = sigue rigiendo hasta que se borre"></div>
                    <div class="form-group col-md-3 col-sm-12 col-xs-12"><label class="hidden-xs hidden-sm">&nbsp;</label>
                      <button type="button" class="btn btn-success btn-block" id="btnGuardarDias"><i class="fa fa-repeat"></i> Guardar días que se repiten</button></div>
                  </div>
                  <span class="rd-ayuda">Si un día tiene varias reglas, manda la más puntual: primero un rango, después los días que se repiten y al final la promesa de siempre.</span>
                </form>

                <form id="pvFormRango" class="aus-rapido" autocomplete="off" onsubmit="return false;">
                  <h4 class="rd-subtitulo" style="margin-top:0;">Días distintos: un puente festivo o una temporada</h4>
                  <div class="row">
                    <div class="form-group col-md-3 col-sm-6 col-xs-12"><label for="rgCargo">Cargo: <span class="rd-req">*</span></label><select id="rgCargo" class="form-control"></select></div>
                    <div class="form-group col-md-2 col-sm-6 col-xs-6"><label for="rgDesde">Primer día: <span class="rd-req">*</span></label><input type="date" id="rgDesde" class="form-control"></div>
                    <div class="form-group col-md-2 col-sm-6 col-xs-6"><label for="rgHasta">Último día: <span class="rd-req">*</span></label><input type="date" id="rgHasta" class="form-control"></div>
                    <div class="form-group col-md-2 col-sm-3 col-xs-6"><label for="rgCantidad">Personas: <span class="rd-req">*</span></label><input type="number" id="rgCantidad" class="form-control" min="0" max="999"></div>
                    <div class="form-group col-md-3 col-sm-3 col-xs-6"><label class="hidden-xs">&nbsp;</label>
                      <button type="button" class="btn btn-default btn-block" id="btnAgregarRango"><i class="fa fa-plus"></i> Agregar rango</button></div>
                  </div>
                </form>
              </div>

              <h4 class="rd-subtitulo">Lo que está guardado</h4>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Cargo</th><th class="rd-num-col">Personas por día</th><th>Rige</th><th>Estado</th><th>Quién lo definió</th><th></th></tr></thead>
                <tbody id="pvReglas"><tr><td colspan="6" class="rd-vacio">Cargando...</td></tr></tbody></table></div>
            </div>

          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=9"></script>
<script type="text/javascript" src="../Ajax/PromesaValorAjax.js?v=2"></script>
<?php
    } else {
        echo "<script>window.location.replace('InicioVista.php');</script>";
    }
} else {
    echo "<script>window.location.replace('login.php');</script>";
}
?>
