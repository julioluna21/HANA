<?php
//Consultas - Reporte a la Gerencia (Informe de gestión de la Dirección Operativa P&B)
//Con la estructura del informe: encabezado, 1. Resultados de la operación, 2. Gestiones prioritarias
//y compromisos con clientes, 3. Avances por proyecto, 4. Talento humano y SST, 5. Compromisos y firma.
//Es de un día o de un rango de días (desde - hasta, máximo 31). Lo propone el sistema con lo que
//registran los coordinadores; se corrige, se agrega o se quita a mano y se guarda por periodo
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
  .ger-dia .ger-al { color: #666; padding: 0 2px; }
  .ger-atajos { display: flex; gap: 4px; margin-top: 4px; }
  .ger-atajos .btn { padding: 1px 8px; font-size: 12px; }
  .ger-botones { display: flex; flex-wrap: wrap; gap: 6px; margin-left: auto; }
  .ger-botones .btn { margin: 0; }
  .ger-estado { margin: 12px 0 4px; padding: 8px 12px; border-radius: 4px; background: #f4f6f8; color: #444; }
  .ger-estado.ok { background: #e8f6ee; color: #1d6b3f; }
  /* Días guardados: se elige el mes y salen solo los días de ese mes (máximo 31), así la lista
     no crece sin fin con el paso de los meses */
  .ger-guardados { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; margin: 8px 0 0; font-size: 12px; color: #666; }
  .ger-guardados label { margin: 0; font-weight: normal; }
  .ger-guardados select { width: auto; height: 28px; padding: 2px 6px; font-size: 12px; }
  .ger-dias-mes { display: flex; flex-wrap: wrap; gap: 4px; }
  .ger-dias-mes a { display: inline-block; padding: 1px 8px; border: 1px solid #e6dcd8; border-radius: 10px; color: #1A1A1A; background: #fff; }
  .ger-dias-mes a:hover { border-color: #6E1A1E; text-decoration: none; }
  .ger-dias-mes a.actual { background: #6E1A1E; color: #fff; border-color: #6E1A1E; }
  .ger-dias-mes .vacio { color: #999; font-style: italic; }
  /* Cada sección del informe, en un recuadro con su número */
  .ger-seccion { border: 1px solid #e3e6ea; border-radius: 6px; padding: 12px 14px 8px; margin-top: 16px; background: #fff; }
  .ger-seccion .ger-sec-cab h3 { font-size: 15px; margin: 0 0 4px; color: #6E1A1E; font-weight: 700; letter-spacing: .2px; }
  .ger-seccion .rd-ayuda { margin: 0 0 8px; }
  .ger-sec-cab { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; flex-wrap: wrap; }
  .ger-subtitulo { font-weight: 600; margin: 4px 0 6px; color: #1A1A1A; }
  .ger-traer { font-size: 12px; white-space: nowrap; cursor: pointer; }
  .ger-seccion textarea { resize: vertical; min-height: 54px; line-height: 1.45; }
  /* Encabezado: destinatario, presentación y firma */
  .ger-encabezado .row > div { margin-bottom: 8px; }
  .ger-encabezado label { margin-bottom: 2px; font-weight: 600; font-size: 12px; }
  .ger-fecha-informe { font-weight: 700; color: #1A1A1A; }
  .ger-titulo-informe { font-weight: 700; color: #6E1A1E; margin-bottom: 8px; }
  /* Un apartado: proyecto, título, texto y botones para moverlo o quitarlo */
  .ger-apartado { border-left: 3px solid #e6dcd8; padding: 6px 0 6px 10px; margin-bottom: 10px; }
  .ger-apartado-cab { display: flex; gap: 6px; align-items: center; margin-bottom: 4px; }
  .ger-apartado-cab select { width: auto; max-width: 170px; }
  .ger-apartado-cab input { flex: 1; font-weight: 600; }
  .ger-mover .btn { padding: 4px 7px; }
  .ger-proyecto { border: 1px solid #efe7e4; border-radius: 6px; padding: 8px 10px 4px; margin: 8px 0 10px; background: #fdfbfa; }
  .ger-proyecto h4 { font-size: 13px; margin: 0 0 8px; padding: 4px 10px; border-radius: 4px; background: #6E1A1E; color: #fff; font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
  .ger-proyecto h4 .ger-traer { color: #f3dede; }
  .ger-vacio { color: #888; font-style: italic; margin: 2px 0 8px; }
  .ger-agregar { margin: 2px 0 6px; }
  .ger-compromisos td { vertical-align: middle !important; }
  .ger-compromisos td:last-child { width: 1%; white-space: nowrap; }
  .ger-compromisos .ger-fecha-col { width: 200px; }
  #gerImpresion { display: none; }
  @media print {
    #gerImpresion { display: block; font-size: 12px; color: #000; }
    #gerImpresion h1 { font-size: 18px; margin: 0 0 4px; }
    #gerImpresion .sub { color: #555; margin-bottom: 12px; }
    #gerImpresion h2 { font-weight: bold; font-size: 15px; margin: 14px 0 6px; border-bottom: 1px solid #999; padding-bottom: 2px; break-after: avoid; page-break-after: avoid; }
    #gerImpresion h4 { font-weight: bold; font-size: 13px; margin: 8px 0 2px; break-after: avoid; page-break-after: avoid; }
    #gerImpresion p { margin: 0 0 4px; white-space: pre-wrap; }
    #gerImpresion table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    #gerImpresion th, #gerImpresion td { border: 1px solid #999; padding: 3px 6px; text-align: left; }
  }
</style>

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="x_panel rd-no-imprimir">
        <div class="x_title">
          <h2><i class="fa fa-briefcase"></i> Reporte a la Gerencia</h2>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="ger-barra">
            <!-- Periodo del reporte: un solo día (desde = hasta) o un rango de días, de hasta 31 -->
            <div class="form-group"><label for="gDesde">Periodo del reporte:</label>
              <div class="ger-dia">
                <button type="button" class="btn btn-default" id="btnAntes" title="Correr el periodo un día atrás"><i class="fa fa-chevron-left"></i></button>
                <input type="date" id="gDesde" class="form-control" title="Primer día">
                <span class="ger-al">al</span>
                <input type="date" id="gFecha" class="form-control" title="Último día">
                <button type="button" class="btn btn-default" id="btnDespues" title="Correr el periodo un día adelante"><i class="fa fa-chevron-right"></i></button>
              </div>
              <div class="ger-atajos">
                <button type="button" class="btn btn-default" data-ger-periodo="dia" title="Solo el último día">Un día</button>
                <button type="button" class="btn btn-default" data-ger-periodo="semana" title="Del lunes al último día">Semana</button>
                <button type="button" class="btn btn-default" data-ger-periodo="mes" title="Del 1 del mes al último día">Mes</button>
              </div>
            </div>
            <div class="ger-botones">
              <button type="button" class="btn btn-default" id="btnTraerTodo" title="Vuelve a llenar todas las secciones con lo que hay en el sistema"><i class="fa fa-refresh"></i> Traer todo otra vez</button>
              <button type="button" class="btn btn-default" id="btnCopiar" title="Copia el reporte como texto, para pegarlo en un correo o en WhatsApp"><i class="fa fa-clipboard"></i> Copiar texto</button>
              <button type="button" class="btn btn-primary" id="btnPdf" title="El informe en PDF, listo para enviar a la Gerencia. La cifra de la promesa sale de lo que dice el texto de los resultados."><i class="fa fa-file-pdf-o"></i> PDF para la Gerencia</button>
              <button type="button" class="btn btn-success" id="btnGuardar"><i class="fa fa-save"></i> Guardar</button>
            </div>
          </div>
          <div class="ger-estado" id="gEstado">Cargando...</div>
          <div class="ger-guardados" id="gGuardados">
            <label for="gMes">Reportes guardados en</label>
            <select id="gMes" class="form-control"></select>
            <span class="ger-dias-mes" id="gDiasMes"></span>
          </div>
          <p class="rd-ayuda" style="margin-top:8px;">Cada sección se llena sola con lo que registran los coordinadores (promesa de valor, temas con el cliente y con Regency, oficios, novedades, vacantes y cronograma). Corrige, agrega o quita lo que haga falta y guarda: queda guardado para ese día o ese periodo. «Traer del sistema» vuelve a llenar solo esa sección.</p>
          <div id="gInforme"></div>
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
<script type="text/javascript" src="../Ajax/ReporteGerenciaAjax.js?v=7"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
