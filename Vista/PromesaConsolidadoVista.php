<?php
/*
  Pantalla sin uso: el consolidado de promesa de valor se quitó del sistema. Si alguien entra por la
  dirección, va al inicio. El código de abajo se conserva solo como referencia.
*/
header('Location: InicioVista.php');
exit;
?>
<?php
//Ausentismo - Consolidado de promesa de valor, ejecutado y ausentismo
//Lo que ve el gerente de operaciones: el mes de todos los proyectos (como la hoja MAIN del
//Excel consolidado), un proyecto con sus peajes y cargos, o un solo peaje con sus cargos.
//Todo el mes o un rango de días (desde, hasta).
//Permiso 40M; con 20M o 24M salen todos los proyectos, si no, los que coordina
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    require_once __DIR__ . '/../Control/PaginaControl.php'; //quién entra lo decide el controlador
    if (pagPromesaConsolidado()) {
        $hoyCons = pagHoy();                 //el día de hoy en hora de Colombia
        include('head.php');
?>
<link href="../public/css/reporte.css?v=17" rel="stylesheet">
<link href="../public/css/promesa.css?v=3" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">

      <!-- Migas: de dónde viene esta pantalla -->
      <div class="rd-migas"><a href="AusentismoVista.php"><i class="fa fa-user-times"></i> Ausentismo</a> <span>›</span> Consolidado de promesa de valor</div>

      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-bar-chart"></i> Promesa de valor, ejecutado y ausentismo</h2>
          <div class="pull-right rd-botones-titulo">
            <!-- El enlace del Excel lo arma PromesaConsolidadoAjax.js con lo que se está viendo: el mes, los días, el proyecto o el peaje y los cargos -->
            <a class="btn btn-default" id="btnExcelCons" href="#" title="Baja lo que estás viendo, con los mismos días y cargos"><i class="fa fa-file-excel-o"></i> Descargar Excel</a>
            <button type="button" class="btn btn-default" id="btnImprimirCons"><i class="fa fa-print"></i> Imprimir</button>
          </div>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <p class="rd-intro">Si se tuvo más o menos personal del prometido, y por qué. Diferencia = ejecutado − promesa de valor:
            en negativo faltó gente y en positivo hubo de más. El porqué se lee en las ausencias del mismo mes.</p>

          <!-- data-hoy le dice a PromesaConsolidadoAjax.js qué día es hoy -->
          <div class="pv-filtros" id="pvFiltros" data-hoy="<?php echo htmlspecialchars($hoyCons, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="row">
              <div class="form-group col-md-3 col-sm-4 col-xs-7"><label for="cMes">Mes:</label><select id="cMes" class="form-control">
                <option value="1">Enero</option><option value="2">Febrero</option><option value="3">Marzo</option><option value="4">Abril</option>
                <option value="5">Mayo</option><option value="6">Junio</option><option value="7">Julio</option><option value="8">Agosto</option>
                <option value="9">Septiembre</option><option value="10">Octubre</option><option value="11">Noviembre</option><option value="12">Diciembre</option></select></div>
              <div class="form-group col-md-2 col-sm-3 col-xs-5"><label for="cAnio">Año:</label><select id="cAnio" class="form-control"></select></div>
              <div class="form-group col-md-4 col-sm-5 col-xs-12"><label for="cProyecto">Qué ver:</label><select id="cProyecto" class="form-control"></select></div>
            </div>
            <!-- De qué día a qué día del mes se suma (sin escoger, todo el mes) -->
            <div class="row">
              <div class="form-group col-md-2 col-sm-3 col-xs-6"><label for="cDesde">Desde el día:</label><select id="cDesde" class="form-control"></select></div>
              <div class="form-group col-md-2 col-sm-3 col-xs-6"><label for="cHasta">Hasta el día:</label><select id="cHasta" class="form-control"></select></div>
              <div class="form-group col-md-8 col-sm-6 col-xs-12">
                <label class="hidden-xs">&nbsp;</label>
                <div class="aus-rangos" id="cRangos">
                  <button type="button" class="btn btn-default btn-sm" data-rango="mes">Todo el mes</button>
                  <button type="button" class="btn btn-default btn-sm" data-rango="q1">Días 1 a 14</button>
                  <button type="button" class="btn btn-default btn-sm" data-rango="q2">Del 15 al final</button>
                </div>
              </div>
            </div>
            <!-- Filtro por cargo: todas las cifras (y el Excel) se cuentan solo con los cargos elegidos -->
            <div class="pv-filtro-cargos" id="cFiltroCargos"></div>
          </div>

          <div id="consNota"></div>
          <!-- Las cifras grandes del mes -->
          <div class="pv-kpis" id="consKpis"></div>
          <!-- Las tablas: las pinta PromesaConsolidadoAjax.js según se vean todos los proyectos o uno solo -->
          <div id="consTablas"><p class="rd-vacio">Cargando...</p></div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=9"></script>
<script type="text/javascript" src="../Ajax/PromesaConsolidadoAjax.js?v=4"></script>
<?php
    } else {
        echo "<script>window.location.replace('InicioVista.php');</script>";
    }
} else {
    echo "<script>window.location.replace('login.php');</script>";
}
?>
