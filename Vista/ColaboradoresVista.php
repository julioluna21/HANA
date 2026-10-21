<?php
/*
  HANA - Colaboradores
  ---------------------------------------------------------------------------
  Las personas que trabajan en los proyectos: documento, nombre, correo y los peajes que
  tiene asignados cada una.
  Al registrar a una persona, una ventana pregunta si se le crea el usuario para entrar a
  HANA: el nombre de usuario (por defecto, su documento) y un rol de los que no administran
  (por defecto, USUARIO BASE). La contraseña temporal le llega por correo.
  Su usuario, su rol y su contraseña se cambian en Usuarios (UsuariosVista.php).
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
                    <label>Usuario para entrar a HANA:</label>
                    <p class="form-control-static" id="usuarioActual">—</p>
                    <!-- El usuario, el rol y la contraseña se cambian en Usuarios -->
                    <p class="rd-ayuda" style="margin-top:0;" id="ayudaUsuario">Al registrarla se pregunta si se le crea el usuario. El usuario, el rol y la contraseña se cambian en Usuarios.</p>
                  </div>
                </div>
                <!-- Los peajes que ve la persona además de los que coordina o de los que es jefe -->
                <div class="row" id="centros"></div>

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
                        <th>Peajes</th>
                        <th style="min-width: 140px;">Usuario y rol</th>
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

<!-- Ventana para crearle el usuario a una persona: sale al registrarla, o con el botón de la tabla -->
<div class="modal fade" id="modalUsuario" tabindex="-1" role="dialog" aria-labelledby="modalUsuarioTitulo">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form id="formUsuario">
        <div class="modal-header rd-modal-cab">
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
          <h4 class="modal-title" id="modalUsuarioTitulo">¿Crear el usuario?</h4>
        </div>
        <div class="modal-body">
          <input type="hidden" name="idcolaborador" id="usuIdPersona">
          <p id="usuPregunta" style="margin-top:0;">¿Le creamos un usuario para entrar a HANA?</p>
          <div class="form-group">
            <label for="usuNombre">Nombre de usuario:</label>
            <input type="text" class="form-control" name="usuario" id="usuNombre" maxlength="50" autocomplete="off" required>
          </div>
          <div class="form-group">
            <label for="usuRol">Rol (lo que hace en HANA):</label>
            <select name="rol" id="usuRol" class="form-control"></select>
            <p class="rd-ayuda" style="margin-top:4px;">La contraseña temporal le llega por correo. Los roles que administran, como ADMIN TEC, se dan en Usuarios.</p>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal" id="btnUsuarioNo">No, por ahora no</button>
          <button type="submit" class="btn btn-primary" id="btnUsuarioSi"><i class="fa fa-user-plus"></i> Sí, crear el usuario</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php

include('footer.php');

?>
<script type="text/javascript" src="../Ajax/ColaboradoresAjax.js?v=3"></script>
<?php
} else {
  echo "<script>window.location.replace('InicioVista.php');</script>";
}
} else {
  echo "<script>window.location.replace('login.php');</script>";
}
?>
