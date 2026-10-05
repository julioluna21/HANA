<?php
//Pantalla de administracion de notificaciones automaticas por correo
//Permite decidir a quien y cada cuanto se envian los reportes de cada lista de chequeo
session_start();

if (isset($_SESSION['IdUsuarios'])) {
$modulosAcceso = explode(",", $_SESSION['Modulos']);

//Se usa el mismo permiso de Grupos de listas (12M): quien administra las
//listas de chequeo es quien configura sus notificaciones
if (in_array("12M", $modulosAcceso)) {

include('head.php');
?>
<!-- Contenido -->
<div class="right_col" role="main">
  <div class="">
    <div class="clearfix"></div>

    <div class="row">
      <div class="col-md-12 col-xs-12">

        <!-- Formulario de configuracion -->
        <div class="x_panel">
          <div class="x_title">
            <h2><i class="fa fa-envelope-o"></i> Notificaciones automáticas</h2>
            <ul class="nav navbar-right panel_toolbox">
              <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
            </ul>
            <div class="clearfix"></div>
          </div>

          <div class="x_content">
            <br />
            <form class="form-horizontal form-label-left" id="frmNotificaciones" onsubmit="return guardaryeditar(event);">

              <!-- Campo oculto: vacio = alta, con valor = edicion -->
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
                    <option value="inmediato">Al enviar — cuando alguien diligencia la lista, se envia un correo notificando</option>
                    <option value="diario">Resumen diario — todo lo del día anterior</option>
                    <option value="semanal">Resumen semanal — todo lo de los últimos 7 días</option>
                  </select>
                  <!-- Aclaracion que cambia segun la opcion elegida -->
                  <span class="help-block" id="ayudaFrecuencia"></span>
                </div>
              </div>

              <div class="form-group">
                <label class="control-label col-md-3 col-sm-3 col-xs-12">Destinatarios <span class="required">*</span></label>
                <div class="col-md-6 col-sm-6 col-xs-12">
                  <!-- Campo de texto normal: siempre se puede escribir, no depende de librerias -->
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
                  <!-- Manda un correo de prueba antes de dejarlo automatico -->
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
            <h2><i class="fa fa-list"></i> Notificaciones configuradas</h2>
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

            <!-- Instrucciones para dejar andando los resumenes -->
            <div class="alert alert-warning" style="margin-top:18px;">
              <i class="fa fa-info-circle"></i>
              <strong>Importante:</strong> los envíos <em>"Al enviar"</em> funcionan solos.
              Los resúmenes <em>diario</em> y <em>semanal</em> necesitan una tarea programada
              en el servidor que llame a estas direcciones:
              <br><code>Control/NotificacionesControl.php?op=resumenDiario&amp;token=TU_TOKEN</code>
              <br><code>Control/NotificacionesControl.php?op=resumenSemanal&amp;token=TU_TOKEN</code>
              <br><small>Reemplaza <code>TU_TOKEN</code> por el valor que est&aacute; en
              <code>Control/NotificacionesControl.php</code>. El token no se muestra aqu&iacute;
              a prop&oacute;sito: es lo &uacute;nico que impide que cualquiera dispare
              env&iacute;os masivos desde el navegador.</small>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<?php
include('footer.php');
?>
<script type="text/javascript" src="../Ajax/NotificacionesAjax.js?v=2"></script>
<?php
} else {
  //Con sesion pero sin permiso: se devuelve a la pantalla anterior
  echo "<script> window.history.go(-1) </script>";
}
} else {
  //Sin sesion: al login
  echo "<script> window.location.replace('login.php'); </script>";
}
?>