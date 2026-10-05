<?php
//Reporte diario — "Hoy en qué estás"
//La bitácora de cada persona: situación del día, dónde estuvo, horario y qué
//hizo hora por hora. Reemplaza la hoja "Hoy en qué estás" del Excel
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    //Si el administrador apagó este módulo (Parámetros del sistema), la pantalla no abre
    require_once __DIR__ . '/../Modelo/HanaConfig.php';
    if (!HanaConfig::modulo('HOY')) { echo "<script>window.location.replace('ReporteDiarioVista.php');</script>"; exit; }
    HanaDB::usarModulo('HOY');
    //Reporte del coordinador: lo llena quien coordina un proyecto (Configuración → Proyectos)
    require_once __DIR__ . '/../Modelo/HanaDB.php';
    $esCoordinador = isset($_SESSION['Idcolaborador']) && HanaDB::esCoordinador((int)$_SESSION['Idcolaborador']);

    //Módulo 11M: diligenciar el reporte diario
    if ($esCoordinador) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=13" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">

      <!-- Migas: de dónde viene esta pantalla -->
      <div class="rd-migas"><a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> Hoy en qué estás</div>
      <div id="alertaDia"></div>

      <!-- ====================== EL DÍA ====================== -->
      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-map-marker"></i> Hoy en qué estás</h2>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">

          <!-- Qué día se está viendo -->
          <div class="hoy-dia">
            <button type="button" class="btn btn-default" id="btnDiaAnterior" title="Día anterior"><i class="fa fa-chevron-left"></i></button>
            <input type="date" id="hoyFecha" class="form-control" aria-label="Día">
            <button type="button" class="btn btn-default" id="btnDiaSiguiente" title="Día siguiente"><i class="fa fa-chevron-right"></i></button>
            <div class="rd-dia-rapido" id="hoyRapido" role="group" aria-label="Ir a un día" style="margin-top:0;">
              <button type="button" class="btn btn-default btn-sm" data-dia="-1">Ayer</button>
              <button type="button" class="btn btn-default btn-sm" data-dia="0">Hoy</button>
              <button type="button" class="btn btn-default btn-sm" data-dia="1">Mañana</button>
            </div>
            <span class="hoy-estado" id="hoyEstado"></span>
          </div>
          <p class="hoy-aviso-consulta" id="hoyConsulta" style="display:none;">
            <i class="fa fa-lock"></i> Este día ya no se puede modificar: se registran ayer, hoy y mañana.
          </p>

          <form id="formHoy" autocomplete="off">
            <h4 class="rd-subtitulo">Situación del día</h4>
            <div class="hoy-situaciones" id="hoySituaciones" role="radiogroup" aria-label="Situación del día"></div>

            <!-- Solo para un día laboral -->
            <div id="hoyLaboral">
              <div class="row">
                <div class="form-group col-md-3 col-sm-4 col-xs-6">
                  <label for="hoyIngreso">Hora de ingreso: <span class="rd-req">*</span></label>
                  <input type="time" id="hoyIngreso" name="ingreso" class="form-control rd-con-ahora">
                </div>
                <div class="form-group col-md-3 col-sm-4 col-xs-6">
                  <label for="hoySalida">Hora de salida:</label>
                  <input type="time" id="hoySalida" name="salida" class="form-control rd-con-ahora">
                  <small class="rd-ayuda">Déjala vacía si todavía estás en la jornada.</small>
                </div>
              </div>

              <h4 class="rd-subtitulo">Dónde estuviste</h4>
              <div id="hoyCentros" class="hoy-centros"></div>
              <div class="form-group" style="max-width:520px;">
                <label for="hoyLugarOtro">Otro lugar:</label>
                <input type="text" id="hoyLugarOtro" name="lugarOtro" class="form-control" maxlength="150"
                       placeholder="Ej: reunión con el cliente, capacitación, oficina regional">
              </div>

              <h4 class="rd-subtitulo">Qué hiciste, hora por hora</h4>
              <p class="rd-ayuda" style="margin-top:-4px;">Los bloques salen de tu hora de ingreso a tu hora de salida. Los que dejes vacíos no se guardan.</p>
              <div id="hoyHoras" class="hoy-horas"></div>
            </div>

            <h4 class="rd-subtitulo">Observación</h4>
            <div class="form-group">
              <textarea id="hoyObservacion" name="observacion" class="form-control" rows="2" maxlength="2000"
                        placeholder="Opcional. Algo que deba saber tu jefe sobre este día."></textarea>
            </div>

            <div class="ln_solid"></div>
            <div class="rd-acciones rd-acciones-fijas">
              <button type="submit" class="btn btn-success" id="btnGuardarHoy"><i class="fa fa-save"></i> Guardar el día</button>
            </div>
          </form>
        </div>
      </div>

      <!-- ====================== MI MES ====================== -->
      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-calendar"></i> Mi mes</h2>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="row">
            <div class="form-group col-md-3 col-sm-4 col-xs-6">
              <label for="mesMes">Mes:</label>
              <select id="mesMes" class="form-control">
                <option value="1">Enero</option><option value="2">Febrero</option><option value="3">Marzo</option>
                <option value="4">Abril</option><option value="5">Mayo</option><option value="6">Junio</option>
                <option value="7">Julio</option><option value="8">Agosto</option><option value="9">Septiembre</option>
                <option value="10">Octubre</option><option value="11">Noviembre</option><option value="12">Diciembre</option>
              </select>
            </div>
            <div class="form-group col-md-3 col-sm-4 col-xs-6">
              <label for="mesAnio">Año:</label>
              <select id="mesAnio" class="form-control"></select>
            </div>
            <div class="col-md-6 col-sm-4 col-xs-12">
              <div class="hoy-resumen-mes" id="mesResumen"></div>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-bordered hoy-tabla-mes">
              <thead><tr><th>Día</th><th>Situación</th><th>Dónde</th><th>Horario</th><th>Bitácora</th></tr></thead>
              <tbody id="mesFilas"><tr><td colspan="5" class="rd-vacio">Cargando...</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=4"></script>
<script type="text/javascript" src="../Ajax/HoyAjax.js?v=3"></script>
<?php
    } else {
        echo "<script>window.location.replace('InicioVista.php');</script>";
    }
} else {
    echo "<script>window.location.replace('login.php');</script>";
}
?>
