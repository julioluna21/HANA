<?php
//Configuración - Parámetros del sistema
//Las decisiones del negocio que se cambian sin tocar el código: parámetros, listas de opciones,
//catálogos de ausentismo y días habilitados. Permiso 24M
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    if (in_array("24M", $modulosAcceso)) {
        include('head.php');
?>
<link href="../public/css/reporte.css?v=16" rel="stylesheet">

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
            <a href="RolesVista.php">Permisos de usuario</a> (por ejemplo: quién aprueba RQ, quién consulta todos los proyectos).</p>

          <ul class="nav nav-tabs cd-pestanas" role="tablist">
            <li class="active"><a href="#tabPar" data-toggle="tab">Parámetros</a></li>
            <li><a href="#tabDias" data-toggle="tab" id="tabDiasLink">Días habilitados</a></li>
            <li><a href="#tabCargos" data-toggle="tab">Cargos (promesa de valor)</a></li>
            <li><a href="#tabListas" data-toggle="tab" id="tabListasLink">Listas</a></li>
            <li><a href="#tabCatalogos" data-toggle="tab">Dónde se edita cada cosa</a></li>
          </ul>
          <div class="tab-content cd-contenido">
            <div class="tab-pane active" id="tabPar">
              <div id="parCorreo"></div>
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
              <p class="rd-ayuda" style="margin-top:0;">Los cargos que usa la promesa de valor (cada fila de la matriz por peaje). "Aplica" dice si el cargo sale en peajes, en básculas o en ambos.
                Un cargo desactivado ya no sale para registrar, pero lo registrado se conserva.</p>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Orden</th><th>Cargo</th><th>Aplica en</th><th>Activo</th><th></th></tr></thead>
                <tbody id="tbCargos"></tbody></table></div>
              <button type="button" class="btn btn-success btn-sm" data-nuevo="cargo"><i class="fa fa-plus"></i> Nuevo cargo</button>
            </div>
            <!-- La pestaña «Ausentismo: novedades» se retiró junto con el ausentismo. La promesa de valor no usa motivos. -->
            <!-- Listas: las opciones de los desplegables (medios de los oficios, transportes, tipos del cronograma...) -->
            <div class="tab-pane" id="tabListas">
              <p class="rd-ayuda" style="margin-top:0;">Las opciones de los desplegables del reporte diario. Cambiarle el nombre a una opción cambia cómo se ve
                en todos los registros, los nuevos y los que ya estaban. Una opción que ya se usó no se puede quitar: se le cambia el nombre.
                Cada lista se guarda con su propio botón.</p>
              <div id="parListas"><p class="rd-vacio">Cargando...</p></div>
            </div>
            <!-- Dónde se edita cada cosa: lo que se configura en otras pantallas -->
            <div class="tab-pane" id="tabCatalogos">
              <p class="rd-ayuda" style="margin-top:0;">Todo lo que el sistema guarda como configuración, y dónde se cambia.</p>
              <div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta">
                <thead><tr><th>Qué</th><th>Dónde se edita</th></tr></thead>
                <tbody>
                  <tr><td>Módulos encendidos, plazos, reglas, adjuntos, contraseñas e ingreso</td><td>Aquí, pestaña <strong>Parámetros</strong></td></tr>
                  <tr><td>Medios de los oficios, «Pendiente de», transportes, tipos del cronograma, estados de Gestión Humana, nombres de los conceptos de caja menor y recambio</td><td>Aquí, pestaña <strong>Listas</strong></td></tr>
                  <tr><td>Cargos de la promesa de valor</td><td>Aquí, pestaña <strong>Cargos (promesa de valor)</strong></td></tr>
                  <tr><td>Días que se le abren a una persona para registrar fuera de fecha</td><td>Aquí, pestaña <strong>Días habilitados</strong></td></tr>
                  <tr><td>Proyectos y su coordinador</td><td><a href="proyectosVista.php">Configuración → Proyectos</a></td></tr>
                  <tr><td>Peajes, básculas, bases y oficinas, con su jefe</td><td><a href="centroOperativoVista.php">Configuración → Centros de operación</a></td></tr>
                  <tr><td>Roles y sus permisos</td><td><a href="RolesVista.php">Configuración → Permisos de usuario</a></td></tr>
                  <tr><td>Personas, sus peajes y su usuario</td><td><a href="ColaboradoresVista.php">Colaboradores</a> y <a href="UsuariosVista.php">Usuarios</a></td></tr>
                  <tr><td>Títulos de las novedades</td><td><a href="TitulosNovedadesVista.php">Configuración → Títulos de novedades</a></td></tr>
                  <tr><td>Listas de chequeo y sus preguntas</td><td><a href="GruposListasVista.php">Reporte diario → Editar listas</a></td></tr>
                  <tr><td>Recordatorios por correo, resultado de la promesa de valor, aviso de promesa por debajo: hora, días y a quién le llegan</td><td><a href="NotificacionesVista.php">Reporte diario → Correos automáticos</a></td></tr>
                  <tr><td>A quién le llegan los correos de cada lista de chequeo</td><td><a href="NotificacionesVista.php#listas">Reporte diario → Correos automáticos</a></td></tr>
                  <tr><td>Campos del arqueo de casetas (por peaje o para todos)</td><td><a href="ArqueosVista.php#campos">Arqueos → Campos de casetas</a></td></tr>
                  <tr><td>Fondos autorizados de caja menor y recambio</td><td><a href="ArqueosVista.php#fondos">Arqueos → Fondos autorizados</a></td></tr>
                  <tr><td>Vehículos y su responsable</td><td><a href="CronogramaVista.php#vehiculos">Cronograma y vehículo → Vehículos</a></td></tr>
                </tbody></table></div>
              <p class="rd-ayuda">No se editan porque el sistema depende de ellos en sus reglas: los estados de las RQ, de las novedades, de las vacantes y de las comunicaciones,
                las prioridades (sus días sí se cambian en Parámetros), los tipos de arqueo, los tipos de centro, las situaciones de Hoy en qué estás y el estado del vehículo.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/ReporteComun.js?v=12"></script>
<script type="text/javascript" src="../Ajax/ParametrosAjax.js?v=6"></script>
<script type="text/javascript" src="../Ajax/ParametrosListasAjax.js?v=1"></script>
<?php
    } else { echo "<script>window.location.replace('InicioVista.php');</script>"; }
} else { echo "<script>window.location.replace('login.php');</script>"; }
?>
