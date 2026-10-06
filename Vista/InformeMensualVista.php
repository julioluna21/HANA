<?php
//Reporte diario — Informe mensual de gestión
//Se elige el periodo (un mes, varios meses, una quincena o las fechas que se
//quieran) y se genera un PDF con todo lo que pasó: primero el resumen general
//y luego una hoja por proyecto. Permiso 37M
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    if (in_array("37M", $modulosAcceso)) {
        require_once __DIR__ . '/../Modelo/InformeMensualModelo.php';
        //Los proyectos que van a salir: todos (20M o 24M) o solo los que coordina
        $todosInf = in_array('20M', $modulosAcceso) || in_array('24M', $modulosAcceso);
        $proyectosInf = (new InformeMensual())->proyectos(isset($_SESSION['Idcolaborador']) ? (int)$_SESSION['Idcolaborador'] : 0, $todosInf);
        $hoyInf = HanaFechas::hoy();
        include('head.php');
?>
<link href="../public/css/reporte.css?v=15" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">

      <!-- Migas: de dónde viene esta pantalla -->
      <div class="rd-migas"><a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> Informe mensual</div>

      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-file-pdf-o"></i> Informe mensual de gestión</h2>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <p class="rd-intro">Un PDF con todo lo que pasó en el periodo que elijas: primero el resumen general y después una hoja por proyecto.
            Puede ser un mes, varios meses, una quincena o las fechas que quieras. Si el periodo todavía no termina, el informe llega hasta hoy.</p>

<?php if (!count($proyectosInf)) { ?>
          <!-- Tiene la casilla, pero no hay proyectos que mostrarle -->
          <div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-info-circle"></i>
            No tienes proyectos para el informe: no coordinas ninguno y tu rol no tiene la casilla «Tablero: ver todos los proyectos» (20M).</div>
<?php } else { ?>
          <!-- data-hoy le dice a InformeMensualAjax.js qué día es hoy (hora de Colombia) -->
          <div class="inf-generar" id="infGenerar" data-hoy="<?php echo htmlspecialchars($hoyInf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="row">
              <div class="form-group col-md-4 col-sm-12 col-xs-12">
                <label for="iPeriodo">Periodo:</label>
                <!-- Cada opción solo llena las dos fechas; también se pueden escribir a mano -->
                <select id="iPeriodo" class="form-control">
                  <option value="mes">Este mes</option>
                  <option value="mesAnterior">El mes anterior</option>
                  <option value="quincena1">Primera quincena de este mes</option>
                  <option value="quincena2">Segunda quincena de este mes</option>
                  <option value="quincenaAnterior">La quincena anterior</option>
                  <option value="meses2">Los últimos 2 meses</option>
                  <option value="meses3">Los últimos 3 meses</option>
                  <option value="meses6">Los últimos 6 meses</option>
                  <option value="anio">Lo que va del año</option>
                  <option value="otro">Otras fechas (las eliges tú)</option>
                </select>
              </div>
              <div class="form-group col-md-2 col-sm-6 col-xs-6">
                <label for="iDesde">Desde:</label>
                <input type="date" id="iDesde" class="form-control">
              </div>
              <div class="form-group col-md-2 col-sm-6 col-xs-6">
                <label for="iHasta">Hasta:</label>
                <input type="date" id="iHasta" class="form-control">
              </div>
              <div class="form-group col-md-4 col-sm-12 col-xs-12">
                <label class="hidden-xs hidden-sm">&nbsp;</label>
                <div class="inf-botones">
                  <!-- Los dos enlaces los arma InformeMensualAjax.js con las fechas elegidas -->
                  <a class="btn btn-success" id="btnVerInforme" href="#" target="_blank" rel="noopener"><i class="fa fa-file-pdf-o"></i> Generar informe</a>
                  <a class="btn btn-default" id="btnBajarInforme" href="#"><i class="fa fa-download"></i> Descargar</a>
                </div>
              </div>
            </div>
            <p class="rd-ayuda" id="infNota"></p>
          </div>

          <h4 class="rd-subtitulo">Proyectos que salen en tu informe</h4>
          <div class="inf-proyectos">
<?php   foreach ($proyectosInf as $p) { ?>
            <span class="rd-tag rd-tag-ok"><i class="fa fa-road"></i> <?php echo htmlspecialchars($p['NOM_PROYECTO'], ENT_QUOTES, 'UTF-8'); ?></span>
<?php   } ?>
          </div>
          <p class="rd-ayuda"><?php echo $todosInf ? 'Ves todos los proyectos activos.' : 'Ves los proyectos que coordinas. Para ver todos hace falta la casilla «Tablero: ver todos los proyectos» (20M).'; ?></p>

          <h4 class="rd-subtitulo">Qué trae</h4>
          <div class="inf-contenido">
            <div><strong><i class="fa fa-globe"></i> Resumen general</strong>
              <span>Las cifras del periodo, lo más relevante, el comparativo por proyecto, el ausentismo, requisiciones y novedades,
                arqueos, vacantes y oficios, el reporte diario de los coordinadores y lo que sigue pendiente.</span></div>
            <div><strong><i class="fa fa-road"></i> Una hoja por proyecto</strong>
              <span>Sus cifras, el detalle peaje por peaje, el ausentismo por motivo, cómo le fue al coordinador con su reporte diario,
                lo pendiente por atender y los arqueos que no cuadraron.</span></div>
          </div>
<?php } ?>
        </div>
      </div>

    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=6"></script>
<script type="text/javascript" src="../Ajax/InformeMensualAjax.js?v=2"></script>
<?php
    } else {
        echo "<script>window.location.replace('InicioVista.php');</script>";
    }
} else {
    echo "<script>window.location.replace('login.php');</script>";
}
?>
