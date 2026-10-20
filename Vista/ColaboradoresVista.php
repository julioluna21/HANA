<?php
/*
  HANA - Colaboradores
  ---------------------------------------------------------------------------
  Las personas que trabajan en los proyectos: documento, nombre y correo.
  Al registrar a una persona se le crea su usuario para entrar a HANA (su documento,
  con una contraseña temporal que le llega por correo) y queda con el rol que se elija
  entre los que no administran; por defecto, USUARIO BASE.
  Su rol, su usuario, sus peajes y su contraseña se cambian en Usuarios (UsuariosVista.php).
  Lo que hace cada botón está en Ajax/ColaboradoresAjax.js y los datos los entrega
  Control/ColaboradoresControl.php.
  Permiso: 3M (o 24M). Sin el permiso, vuelve al inicio.
*/
session_start();
if (isset($_SESSION['IdUsuarios'])) {
$modulosAcceso = explode(",", $_SESSION['Modulos']);
if (in_array("3M", $modulosAcceso) || in_array("24M", $modulosAcceso)) {

include('head.php');

?>

<!-- Contenido de la pantalla -->
<div class="right_col" role="main">
  <div class="">

    <div class="clearfix"></div>

    <div class="row">
      <div class="col-md-12 col-xs-12">

        <div class="x_panel">
          <div class="x_title">
            <h2>Colaboradores<small>Las personas que trabajan en los proyectos</small></h2>
            <div class="clearfix"></div>
          </div>
          <div class="x_content">

            <!-- Formulario de la persona -->
            <div class="panel-body" style="align-content: center;" id="formularioregistros">

              <form name="formColaborador" id="formColaborador" method="POST">
                <input type="hidden" name="idcolaborador" id="idcolaborador">
                <div class="row">
                  <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                    <label for="documento">Documento:</label>
                    <input type="text" class="form-control" name="documento" id="documento" inputmode="numeric" maxlength="20" required>
                  </div>
                  <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                    <label for="persona">Nombre completo:</label>
                    <input type="text" class="form-control" name="persona" id="persona" maxlength="100" required>
                  </div>
                </div>
                <div class="row">
                  <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                    <label for="correo">Correo:</label>
                    <!-- A este correo le llegan su usuario y su contraseña, los avisos y la recuperación de la clave -->
                    <input type="email" class="form-control" name="correo" id="correo" maxlength="60" required>
                  </div>
                  <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                    <label for="rol">Rol (lo que hace en HANA):</label>
                    <select name="rol" id="rol" class="form-control"></select>
                    <!-- Al registrar se elige; después el rol se cambia en Usuarios -->
                    <p class="rd-ayuda" style="margin-top:4px;" id="ayudaRol">Al registrarla se le crea su usuario (su documento) y le llega por correo con una contraseña temporal. Los roles que administran, como ADMIN TEC, se dan en Usuarios.</p>
                  </div>
                </div>

                <div class="form-group col-lg-12 col-md-12 col-sm-12 col-xs-12">
                  <SPAN title="Guardar">
                    <button class="btn btn-primary" type="submit" id="btnGuardar"><i class="fa fa-save"></i> Guardar</button>
                  </SPAN>
                  <SPAN title="Cancelar">
                    <button class="btn btn-primary" onclick="cancelarform()" type="button"><i class="fa fa-arrow-circle-left"></i> Cancelar</button>
                  </SPAN>
                </div>
              </form>

            </div>

            <!-- Tabla de personas -->
            <div id="listadoregistros">
              <div class="x_panel">
                <div class="x_title">
                  <h2>Personas registradas</h2>
                  <SPAN title="Registrar una persona" style="float:right">
                    <button class="btn btn-success" id="btnagregar" onclick="nuevo()">
                      <i class="fa fa-plus-square"></i> Nuevo colaborador
                    </button>
                  </SPAN>
                  <div class="clearfix"></div>
                </div>
                <div class="x_content">
                  <div class="panel-body table-responsive">
                    <table id="tbllistado" class="table table-striped table-bordered" style="width:100%; text-align: center">
                      <thead>
                        <th style="min-width: 100px;">Documento</th>
                        <th style="min-width: 220px;">Nombre</th>
                        <th style="min-width: 180px;">Correo</th>
                        <th style="min-width: 160px;">Rol</th>
                        <th style="min-width: 140px;">Usuario</th>
                        <th>Estado</th>
                        <th style="min-width: 110px;">Opción</th>
                      </thead>
                      <tbody>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- /page content -->

<?php

include('footer.php');

?>
<script type="text/javascript" src="../Ajax/ColaboradoresAjax.js?v=2"></script>
<?php
} else {
  echo "<script>window.location.replace('InicioVista.php');</script>";
}
} else {
  echo "<script>window.location.replace('login.php');</script>";
}
?>
