<?php
//Configuración — Parámetros del sistema
//Las decisiones del negocio que se cambian sin tocar el código. Permiso 24M
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    if (in_array("24M", $modulosAcceso)) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=15" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-sliders"></i> Parámetros del sistema</h2>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <p class="rd-intro">Las decisiones del negocio se cambian aquí, sin tocar el código. Quién puede hacer qué se define en
            <a href="RolesVista.php">Roles</a> (por ejemplo: quién aprueba RQ, quién consulta todos los proyectos).</p>

          <ul class="nav nav-tabs cd-pestanas" role="tablist">
            <li class="active"><a href="#tabPar" data-toggle="tab">Parámetros</a></li>
            <li><a href="#tabDias" data-toggle="tab" id="tabDiasLink">Días habilitados</a></li>
            <li><a href="#tabCargos" data-toggle="tab">Ausentismo: cargos</a></li>
            <li><a href="#tabNovedades" data-toggle="tab">Ausentismo: novedades</a></li>
            <li><a href="#tabCorreo" data-toggle="tab" id="tabCorreoLink">Correo</a></li>
          </ul>
          <div class="tab-content cd-contenido">
            <div class="tab-pane active" id="tabPar">
              <form id="formPar"><div id="parGrupos"><p class="rd-vacio">Cargando...</p></div>
                <div class="rd-acciones rd-acciones-fijas"><button type="submit" class="btn btn-success" id="btnGuardarPar"><i class="fa fa-save"></i> Guardar cambios</button></div>
              </form>
            </div>
            <!-- Días habilitados: el reporte diario solo se llena hoy; aquí se abre otro día a una persona -->
            <div class="tab-pane" id="tabDias">
              <p style="margin-top:0;">El reporte diario (Hoy en qué estás, listas de chequeo, arqueos, cronograma y vehículo) solo se llena <strong>el día de hoy</strong>.
                Si alguien necesita registrar o corregir otro día, habilítaselo aquí. Cuando termine, deshabilítalo: ese día vuelve a quedar solo de consulta.</p>
              <form id="formDia" autocomplete="off">
                <div class="row">
                  <div class="form-group col-md-4 col-sm-6 col-xs-12">
                    <label for="diaPersona">Persona: <span class="rd-req">*</span></label>
                    <select id="diaPersona" name="persona" class="form-control"></select>
                  </div>
                  <div class="form-group col-md-2 col-sm-6 col-xs-12">
                    <label for="diaFecha">Día: <span class="rd-req">*</span></label>
                    <input type="date" id="diaFecha" name="fecha" class="form-control">
                  </div>
                  <div class="form-group col-md-4 col-sm-8 col-xs-12">
                    <label for="diaMotivo">Motivo:</label>
                    <input type="text" id="diaMotivo" name="motivo" class="form-control" maxlength="200" placeholder="Ej: no tuvo señal en el peaje">
                  </div>
                  <div class="form-group col-md-2 col-sm-4 col-xs-12">
                    <label class="hidden-xs">&nbsp;</label>
                    <button type="submit" class="btn btn-success btn-block" id="btnHabilitarDia"><i class="fa fa-unlock"></i> Habilitar</button>
                  </div>
                </div>
              </form>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Persona</th><th>Día</th><th>Motivo</th><th>Lo habilitó</th><th>Estado</th><th></th></tr></thead>
                <tbody id="tbDias"><tr><td colspan="6" class="rd-vacio">Cargando...</td></tr></tbody></table></div>
            </div>
            <div class="tab-pane" id="tabCargos">
              <p class="rd-ayuda" style="margin-top:0;">Los bloques de cada hoja del reporte de ausentismo. "Aplica" dice si el cargo sale en peajes, en básculas o en ambos.
                Un cargo desactivado ya no sale para registrar, pero lo registrado se conserva.</p>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Orden</th><th>Cargo</th><th>Aplica en</th><th>Activo</th><th></th></tr></thead>
                <tbody id="tbCargos"></tbody></table></div>
              <button type="button" class="btn btn-success btn-sm" data-nuevo="cargo"><i class="fa fa-plus"></i> Nuevo cargo</button>
            </div>
            <div class="tab-pane" id="tabNovedades">
              <p class="rd-ayuda" style="margin-top:0;">Las filas de cada bloque. Las que "no son ausencia" (como cubre recolector) no suman en las ausencias diarias.
                Si una novedad es solo de un cargo, sale únicamente en ese bloque.</p>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Orden</th><th>Novedad</th><th>¿Es ausencia?</th><th>Solo en el cargo</th><th>Activa</th><th></th></tr></thead>
                <tbody id="tbNovedades"></tbody></table></div>
              <button type="button" class="btn btn-success btn-sm" data-nuevo="novedad"><i class="fa fa-plus"></i> Nueva novedad</button>
            </div>
            <div class="tab-pane" id="tabCorreo">
              <p style="margin-top:0;">Si los correos no están llegando, envía una prueba a tu propio correo. Aquí verás paso a paso qué respondió el servidor,
                y con eso sabemos si es la contraseña, el puerto, el certificado o un bloqueo del hosting.</p>
              <div class="par-prueba">
                <input type="email" id="correoPrueba" class="form-control" placeholder="tu.correo@regency.com.co" aria-label="Correo para la prueba">
                <button type="button" class="btn btn-success" id="btnProbarCorreo"><i class="fa fa-paper-plane"></i> Enviar prueba</button>
              </div>
              <div id="correoResultado"></div>
              <h4 class="rd-subtitulo">Recordatorios automáticos</h4>
              <p>Si enciendes <em>"Recordar por correo a cada coordinador lo que le falta del día"</em> (arriba, en Parámetros), crea en cPanel una
                tarea programada (Tareas Cron) a la hora que quieran, por ejemplo todos los días a las 10:00, con esta línea
                (cambia LA_CLAVE por el valor de HANA_TOKEN_TAREAS de Global.php):</p>
              <pre class="par-cron">curl -s "https://<?php echo htmlspecialchars($_SERVER['HTTP_HOST'], ENT_QUOTES, 'UTF-8'); ?><?php echo htmlspecialchars(rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\'), ENT_QUOTES, 'UTF-8'); ?>/Control/TareasControl.php?op=recordatorios&amp;token=LA_CLAVE" &gt; /dev/null</pre>
              <button type="button" class="btn btn-default" id="btnVistaRecordatorios"><i class="fa fa-eye"></i> Ver a quién le llegaría hoy</button>
              <div id="recordatoriosResultado" style="margin-top:10px;"></div>
              <h4 class="rd-subtitulo">Últimos envíos</h4>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Cuándo</th><th>Módulo</th><th>Para</th><th>Asunto</th><th>Método</th><th>Resultado</th></tr></thead>
                <tbody id="tbCorreoLog"><tr><td colspan="6" class="rd-vacio">Cargando...</td></tr></tbody></table></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=6"></script>
<script type="text/javascript" src="../Ajax/ParametrosAjax.js?v=4"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
