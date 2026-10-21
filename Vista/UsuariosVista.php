<?php
/*
  HANA - Usuarios
  ---------------------------------------------------------------------------
  El usuario de cada persona para entrar a HANA (tabla usuarios_sistema): crearlo a quien
  no tiene, su nombre de usuario, su rol, la contraseña temporal (le llega por correo) y
  bloquear o devolver el acceso.
  A las personas se las registra en Colaboradores (ColaboradoresVista.php), y allí se
  cambian su documento, su nombre, su correo y sus peajes; aquí se ven, pero no se cambian.
  Lo que hace cada botón está en Ajax/UsuariosAjax.js y los datos los entrega
  Control/UsuariosControl.php.
  Permiso: 1M (o 24M). Con ?op=canvu cada persona cambia su propia clave (no pide permisos).
*/
session_start();
if(isset($_SESSION['IdUsuarios'])){
$modulosAcceso=explode(",",$_SESSION['Modulos']);
$modoClave = (isset($_GET["op"]) && $_GET["op"] === "canvu");
$puedeAcceso = in_array("1M",$modulosAcceso) || in_array("24M",$modulosAcceso);
if($puedeAcceso or $modoClave){

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
            <h2><?php echo $modoClave ? 'Cambiar mi contraseña' : 'Usuarios'; ?><small><?php echo $modoClave ? '' : 'El usuario de cada persona para entrar a HANA'; ?></small></h2>
            <?php //El modo de la pantalla no se copia tal cual de la dirección: así no se puede inyectar código ?>
            <input type="hidden" name="cambioclave" id="cambioclave" value="<?php echo $modoClave ? 'canvu' : ''; ?>">
            <div class="clearfix"></div>
          </div>
          <div class="x_content">

            <!-- Formulario del acceso de una persona -->
            <div class="panel-body" style="align-content: center;" id="formularioregistros">

           <form name="demo-form2" id="demo-form2" method="POST">
                      <input type="hidden" name="idusuario" id="idusuario">
                      <h4 style="margin-top:0;">La persona</h4>
                      <p class="rd-ayuda">Su documento, su nombre, su correo y sus peajes se cambian en Configuración → Colaboradores.</p>
                      <div class="row">
                           <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                               <label for="documento">Documento:</label>
                               <input type="text" class="form-control" name="documento" id="documento" readonly>
                            </div>
                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <label for="persona">Nombre completo:</label>
                                <input type="text" class="form-control" name="persona" id="persona" readonly>
                              </div>
                        </div>
                      <div class="row">
                           <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                               <label for="correo">Correo:</label>
                               <!-- A este correo le llegan la contraseña temporal, los avisos y la recuperación de la clave -->
                               <input type="email" class="form-control" name="correo" id="correo" readonly>
                            </div>
                             <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" >
                               <label for="rol">Rol (lo que hace en HANA):</label><br>
                                <select name="rol" id="rol" class="form-control" required>
                              </select>
                            </div>
                        </div>

                      <h4>Acceso a HANA</h4>
                      <div class="row">
                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <label for="usuario">Nombre de usuario:</label>
                                <input type="text" class="form-control" name="usuario" id="usuario" maxlength="50" autocomplete="off">
                                <p class="rd-ayuda" style="margin-top:4px;" id="ayudaUsuario">Al crearle el usuario, el sistema le da una contraseña temporal y le llega por correo (si el correo no sale, se muestra aquí una sola vez).</p>
                              </div>
                        </div>


                            <div class="form-group col-lg-12 col-md-12 col-sm-12 col-xs-12" id="">
                              <SPAN title="Guardar">
                                <button class="btn btn-primary" type="submit" id="btnGuardar"><i class="fa fa-save"></i> Guardar
                                </button>
                              </SPAN>
                             <SPAN title="Darle una contraseña temporal nueva">
                                <button class="btn btn-primary" onclick="claveTemporal()" type="button" id="btnclave"><i class="fa fa-key "></i> Nueva contraseña temporal
                                </button>
                              </SPAN>
                              <SPAN title="Cancelar">
                                <button class="btn btn-primary" onclick="cancelarform()"  type="button"><i class="fa fa-arrow-circle-left"></i> Cancelar
                                </button>
                              </SPAN>
                            </div>

                          </form>


             </div>

            <!-- Cambiar la clave propia (menú del usuario) -->
            <div class="panel-body" style="align-content: center;" id="formclave">

           <form name="formularioclave" id="formularioclave" method="POST">
                      <div class="row">
                           <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <input type="hidden" name="idusuario" id="idusuario2" value="<?php echo $modoClave ? (int)$_SESSION['Idcolaborador'] : ''; ?>">
                               <label>Contraseña:</label><br>
                               <input type="password" class="form-control" name="clave" id="clave" required="" autofocus autocomplete="new-password">
                               <span class="rd-ayuda">Mínimo 8 caracteres. No puede empezar ni terminar con espacios.</span>
                            </div>

                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <label>Confirma contraseña:</label>
                                <input type="password" class="form-control" name="clave2" id="clave2" required="" autocomplete="new-password">
                              </div>
                        </div>

                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" id="">
                              <SPAN title="Guardar">
                                <button class="btn btn-primary" type="submit" ><i class="fa fa-save"></i> Guardar
                                </button>
                              </SPAN>
                              <SPAN title="Cancelar">
                                <button class="btn btn-primary" onclick="window.history.go(-1)"  type="button"><i class="fa fa-arrow-circle-left"></i> Cancelar
                                </button>
                              </SPAN>
                            </div>

                          </form>


             </div>
            <!-- tabla con el listado de registros -->
            <div id="listadoregistros">
                <div class="x_panel">
                  <div class="x_title">
                  <h2>Personas registradas</h2>
                  <!-- Las personas nuevas se registran en Colaboradores, y allí se les crea el usuario -->
                    <div class="clearfix"></div>
                  </div>
                  <div class="x_content">

                      <div class="panel-body table-responsive" >

                    <table id="tbllistado" class="table table-striped table-bordered" style="width:100%; text-align: center">
                    <thead>
                  <th style="min-width: 100px;">Documento</th>
                  <th style="min-width: 220px;">Nombre</th>
                  <th style="min-width: 180px;">Correo</th>
                  <th style="min-width: 160px;">Rol</th>
                  <th style="min-width: 140px;">Usuario</th>
                  <th>Acceso</th>
                  <th style="min-width: 110px;">Opción</th>
                    </thead>
                      <tbody>

                      </tbody>
                    </table>
                          </div>

                  </div>
                </div>
              </div>

            <!-- end form for validations -->
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- /page content -->

<!-- footer content -->
<?php

include('footer.php');

?>
<script type="text/javascript" src="../Ajax/UsuariosAjax.js?v=6"></script>
<?php
}else{
  echo "<script>
  window.location.replace('InicioVista.php');
  </script>";

}
}else{
  echo "<script>
  <!--
  window.location.replace('login.php');
  //-->
  </script>";
}
?>
