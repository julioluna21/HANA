<?php
//Reporte diario - Correos automáticos
//Arriba (solo el ADMIN TEC, 24M): los recordatorios, el resultado de la promesa de valor y su aviso
//en la campana, con su hora, sus días y a quién le llegan (Control/TareasControl.php).
//Abajo (12M): a quién y cada cuánto se envían los correos de cada lista de chequeo
session_start();

if (isset($_SESSION['IdUsuarios'])) {
$modulosAcceso = explode(",", $_SESSION['Modulos']);

//Dos casillas separadas en Permisos de usuario: los recordatorios y avisos automáticos (57M)
//y los correos de las listas de chequeo (44M). El ADMIN TEC (24M) ve las dos partes
$caTareas = in_array("57M", $modulosAcceso) || in_array("24M", $modulosAcceso);
$caListas = in_array("44M", $modulosAcceso) || in_array("24M", $modulosAcceso);
if ($caTareas || $caListas) {

include('head.php');
?>
<link href="../public/css/reporte.css?v=18" rel="stylesheet">
<!-- Contenido -->
<div class="right_col" role="main">
  <div class="">
    <div class="clearfix"></div>

    <div class="row">
      <div class="col-md-12 col-xs-12">

<?php if ($caTareas) { ?>
        <!-- Recordatorios, resúmenes y avisos automáticos: hora, días y a quién le llegan (casilla 57M) -->
        <div class="x_panel">
          <div class="x_title">
            <h2><i class="fa fa-clock-o"></i> Recordatorios y avisos automáticos</h2>
            <div class="clearfix"></div>
          </div>
          <div class="x_content">
            <p class="rd-intro">Cada uno se hace solo, una vez al día, desde la hora que elijas y en los días marcados. «Ver a quién le llegaría» no envía nada: solo muestra la lista.</p>
            <div id="caAvisoCron"></div>
            <div id="caTareas"><p class="rd-vacio">Cargando...</p></div>
          </div>
        </div>
<?php } ?>
<?php if ($caListas) { ?>

        <!-- Formulario de configuración -->
        <div class="x_panel" id="listas">
          <div class="x_title">
            <h2><i class="fa fa-envelope-o"></i> Correos de las listas de chequeo</h2>
            <ul class="nav navbar-right panel_toolbox">
              <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
            </ul>
            <div class="clearfix"></div>
          </div>

          <div class="x_content">
            <br />
            <form class="form-horizontal form-label-left" id="frmNotificaciones" onsubmit="return guardaryeditar(event);">

              <!-- Campo oculto: vacío = alta, con valor = edición -->
              <input type="hidden" id="idNotificacion" name="idNotificacion">

              <div class="form-group">
                <label class="control-label col-md-3 col-sm-3 col-xs-12">Lista de chequeo <span class="required">*</span></label>
                <div class="col-md-6 col-sm-6 col-xs-12">
                  <!-- Se llena por AJAX con las listas de chequeo existentes -->
                  <select id="idGrupo" name="idGrupo" class="form-control" required></select>
                </div>
              </div>

              <div class="form-group">
                <label class="control-label col-md-3 col-sm-3 col-xs-12">¿Cuándo enviar? <span class="required">*</span></label>
                <div class="col-md-6 col-sm-6 col-xs-12">
                  <select id="frecuencia" name="frecuencia" class="form-control" required>
                    <option value="inmediato">Al enviar — apenas alguien diligencia la lista</option>
                    <option value="diario">Resumen diario — todo lo del día anterior</option>
                    <option value="semanal">Resumen semanal — todo lo de los últimos 7 días</option>
                  </select>
                  <!-- Aclaración que cambia según la opción elegida -->
                  <span class="help-block" id="ayudaFrecuencia"></span>
                </div>
              </div>

              <div class="form-group">
                <label class="control-label col-md-3 col-sm-3 col-xs-12">Destinatarios <span class="required">*</span></label>
                <div class="col-md-6 col-sm-6 col-xs-12">
                  <!-- Campo de texto normal: siempre se puede escribir, no depende de librerías -->
                  <input type="text" id="destinatarios" name="destinatarios" class="form-control"
                         placeholder="correo1@empresa.com, correo2@empresa.com">
                  <span class="help-block">
                    Escribe uno o varios correos separados por coma.
                  </span>

                  <!-- Atajo: agrega el correo de un colaborador ya registrado -->
                  <div style="margin-top:8px;">
                    <div class="input-group">
                      <select id="buscarColaborador" class="form-control">
                        <option value="">-- Agregar colaborador registrado --</option>
                      </select>
                      <span class="input-group-btn">
                        <button class="btn btn-default" type="button" onclick="agregarColaborador()">
                          <i class="fa fa-plus"></i> Agregar
                        </button>
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="form-group">
                <label class="control-label col-md-3 col-sm-3 col-xs-12">Asunto del correo</label>
                <div class="col-md-6 col-sm-6 col-xs-12">
                  <input type="text" id="asunto" name="asunto" class="form-control" maxlength="150"
                         placeholder="Opcional. Si lo dejas vacío se genera automáticamente.">
                </div>
              </div>

              <div class="ln_solid"></div>
              <div class="form-group">
                <div class="col-md-6 col-md-offset-3">
                  <button type="submit" class="btn btn-primary" id="btnGuardar"><i class="fa fa-save"></i> Guardar</button>
                  <!-- Manda un correo de prueba antes de dejarlo automático -->
                  <button type="button" class="btn btn-info" id="btnProbar" onclick="probarEnvio()"><i class="fa fa-paper-plane"></i> Enviar prueba</button>
                  <button type="button" class="btn btn-default" onclick="limpiar()">Limpiar</button>
                </div>
              </div>
            </form>
          </div>
        </div>

        <!-- Listado de notificaciones configuradas -->
        <div class="x_panel">
          <div class="x_title">
            <h2><i class="fa fa-list"></i> Correos de listas configurados</h2>
            <div class="clearfix"></div>
          </div>
          <div class="x_content">
            <table id="tblNotificaciones" class="table table-striped table-bordered" style="width:100%">
              <thead>
                <tr>
                  <th style="width:90px;">Acciones</th>
                  <th>Lista de chequeo</th>
                  <th style="width:140px;">Cuándo</th>
                  <th>Destinatarios</th>
                  <th style="width:150px;">Último envío</th>
                  <th style="width:90px;">Estado</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>

          </div>
        </div>
<?php } ?>
      </div>
    </div>
  </div>
</div>

<?php
include('footer.php');
?>
<?php if ($caListas) { ?><script type="text/javascript" src="../Ajax/NotificacionesAjax.js?v=4"></script><?php } ?>
<?php if ($caTareas) { ?><script type="text/javascript" src="../Ajax/ReporteComun.js?v=12"></script>
<script type="text/javascript" src="../Ajax/CorreosAutomaticosAjax.js?v=2"></script><?php } ?>
<?php
} else {
  //Con sesión pero sin permiso: va al inicio (volver atrás dejaba la página en blanco si se abrió desde un enlace)
  echo "<script> window.location.replace('InicioVista.php'); </script>";
}
} else {
  //Sin sesion: al login
  echo "<script> window.location.replace('login.php'); </script>";
}
?>