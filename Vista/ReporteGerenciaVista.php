<?php
//Consultas - Reporte diario a la Gerencia
//La dirección lo arma cada día, por proyecto, con lo que llenan los coordinadores:
//disponibilidad de flota, promesa de valor, oficios en gestión y novedades.
//Lo propone el sistema; se corrige a mano y se guarda por día
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    require_once __DIR__ . '/../Control/PaginaControl.php';
    if (!pagModuloActivo('GERENCIA')) { echo "<script>window.location.replace('InicioVista.php');</script>"; exit; }
    pagUsarModulo('GERENCIA');
    if (pagPuede('GERENCIA', 'armar') && pagTieneCentros()) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=16" rel="stylesheet">
<style>
  .ger-barra { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
  .ger-barra .form-group { margin-bottom: 0; }
  .ger-dia { display: flex; gap: 4px; align-items: center; }
  .ger-dia input[type="date"] { width: auto; min-width: 150px; }
  .ger-botones { display: flex; flex-wrap: wrap; gap: 6px; margin-left: auto; }
  .ger-botones .btn { margin: 0; }
  .ger-estado { margin: 12px 0 4px; padding: 8px 12px; border-radius: 4px; background: #f4f6f8; color: #444; }
  .ger-estado.ok { background: #e8f6ee; color: #1d6b3f; }
  .ger-guardados { margin: 6px 0 0; font-size: 12px; color: #666; }
  .ger-guardados a { display: inline-block; margin: 2px 4px 2px 0; padding: 1px 7px; border: 1px solid #cfd8dc; border-radius: 10px; color: #37474f; }
  .ger-guardados a.actual { background: #2A3F54; color: #fff; border-color: #2A3F54; }
  .ger-proyecto { border: 1px solid #e3e6ea; border-radius: 6px; padding: 12px 14px 4px; margin-top: 16px; background: #fff; }
  .ger-proyecto h3 { font-size: 17px; margin: 0 0 10px; color: #2A3F54; font-weight: 600; }
  .ger-punto { margin-bottom: 12px; }
  .ger-punto-cab { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
  .ger-punto-cab label { margin-bottom: 3px; }
  .ger-traer { font-size: 12px; white-space: nowrap; cursor: pointer; }
  .ger-punto textarea { resize: vertical; min-height: 54px; line-height: 1.45; }
  .ger-punto textarea.cambiado { border-left: 3px solid #f0ad4e; }
  #gerImpresion { display: none; }
  @media print {
    #gerImpresion { display: block; font-size: 12px; color: #000; }
    #gerImpresion h1 { font-size: 18px; margin: 0 0 4px; }
    #gerImpresion .sub { color: #555; margin-bottom: 12px; }
    #gerImpresion h2 { font-weight: bold; font-size: 15px; margin: 14px 0 6px; border-bottom: 1px solid #999; padding-bottom: 2px; break-after: avoid; page-break-after: avoid; }
    #gerImpresion h4 { font-weight: bold; font-size: 13px; margin: 8px 0 2px; break-after: avoid; page-break-after: avoid; }
    #gerImpresion p { margin: 0 0 4px; white-space: pre-wrap; }
  }
</style>

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="x_panel rd-no-imprimir">
        <div class="x_title">
          <h2><i class="fa fa-briefcase"></i> Reporte diario a la Gerencia</h2>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="ger-barra">
            <div class="form-group"><label for="gFecha">Día del reporte:</label>
              <div class="ger-dia">
                <button type="button" class="btn btn-default" id="btnAntes" title="Día anterior"><i class="fa fa-chevron-left"></i></button>
                <input type="date" id="gFecha" class="form-control">
                <button type="button" class="btn btn-default" id="btnDespues" title="Día siguiente"><i class="fa fa-chevron-right"></i></button>
              </div>
            </div>
            <div class="ger-botones">
              <button type="button" class="btn btn-default" id="btnTraerTodo" title="Vuelve a llenar todos los puntos con lo que hay hoy en el sistema"><i class="fa fa-refresh"></i> Traer todo otra vez</button>
              <button type="button" class="btn btn-default" id="btnCopiar" title="Copia el reporte como texto, para pegarlo en un correo o en WhatsApp"><i class="fa fa-clipboard"></i> Copiar texto</button>
              <button type="button" class="btn btn-primary" id="btnPdf" title="El reporte en PDF, con colores, porcentajes y gráficas, listo para enviar a la Gerencia"><i class="fa fa-file-pdf-o"></i> PDF para la Gerencia</button>
              <button type="button" class="btn btn-success" id="btnGuardar"><i class="fa fa-save"></i> Guardar</button>
            </div>
          </div>
          <div class="ger-estado" id="gEstado">Cargando...</div>
          <div class="ger-guardados" id="gGuardados"></div>
          <p class="rd-ayuda" style="margin-top:8px;">Los puntos se llenan solos con lo que registran los coordinadores (vehículos, promesa de valor, comunicaciones y temas con el cliente). Corrige lo que haga falta y guarda: queda guardado para ese día. «Traer del sistema» vuelve a llenar solo ese punto.</p>
          <div id="gProyectos"></div>
          <div class="ln_solid"></div>
          <div class="rd-acciones"><button type="button" class="btn btn-success" id="btnGuardar2"><i class="fa fa-save"></i> Guardar</button></div>
        </div>
      </div>
      <!-- Lo que sale al imprimir o guardar como PDF -->
      <div id="gerImpresion"></div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=12"></script>
<script type="text/javascript" src="../Ajax/ReporteGerenciaAjax.js?v=2"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
