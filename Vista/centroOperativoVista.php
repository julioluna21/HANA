<?php
session_start();
if(isset($_SESSION['IdUsuarios'])){
$modulosAcceso=explode(",",$_SESSION['Modulos']);    
if(in_array("5M",$modulosAcceso) or isset($_GET["op"])){    
    
include('head.php');
?>


  <!-- Contenido aqui va todo el DIV del contenido.. -->
  <div class="right_col" role="main">
    <div class="">

      <div class="clearfix"></div>


      <div class="row">
        <div class="col-md-12 col-xs-12">


          <div class="x_panel">
            <div class="x_title">
              <h2> Centros de operación </h2>

              <div class="clearfix"></div>
            </div>
            <div class="x_content">
              <!-- Inicio Formulario -->
              <div class="panel-body" style="align-content: center;" id="formularioregistros">

                <form name="demo-form2" id="demo-form2" method="POST">
                  <h2>Crear o editar un registro</h2>
                  <div class="row">
                    <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                      <label for="nombre">Nombre del centro de operación:</label>
                      <input type="hidden" class="form-control" name="idcentro" id="idcentro">
                      <input type="text" class="form-control" name="nombre" id="nombre" required="" autofocus>
                    </div>
                      <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                        <label for="selectProyecto">Proyecto:</label>
                        <select class="form-control" style="width: 100%;" id="selectProyecto" name="selectProyecto">
                          <option value=''>Selecciona un proyecto...</option>
                        </select>
                      </div>
                  </div>
                  <!-- Fase 2: tipo de centro y su jefe. El jefe es la persona cuyo
                       reporte diario aparece en el tablero, dentro de su proyecto -->
                  <div class="row">
                      <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                        <label for="tipo">Tipo:</label>
                        <select class="form-control" id="tipo" name="tipo">
                          <option value="PEAJE">Peaje</option>
                          <option value="BASCULA">Báscula</option>
                          <option value="BASE">Base</option>
                          <option value="OFICINA">Oficina</option>
                        </select>
                      </div>
                      <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                        <label for="jefe">Jefe del centro:</label>
                        <select class="form-control" id="jefe" name="jefe">
                          <option value="">Sin asignar</option>
                        </select>
                      </div>
                  </div>


                  <div class=" orm-control form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" id="">
                    <SPAN title="Guardar">
                      <button class="btn btn-primary" type="submit" id="btnGuardar"><i class="fa fa-save"></i> Guardar
                      </button>
                    </SPAN>
                    <SPAN title="Cancelar">
                      <button class="btn btn-primary" onclick="cancelarform()" type="button"><i class="fa fa-arrow-circle-left"></i> Cancelar
                      </button>
                    </SPAN>
                  </div>

                </form>


              </div>
              <!-- Listado Registros-->

              <!-- centro listado de articulos-->
              <div id="listadoregistros">
                <div class="x_panel">
                  <div class="x_title">
                    <h2>Listado de centros de operación</h2>
                    <SPAN title="Agregar centro de operación" style="float:right">
                      <!--span - abarcar. Es un contenedor en línea. Sirve para aplicar estilo al texto o agrupar elementos en línea.-->
                      <button class="btn btn-success" id="btnagregar" onclick="mostrarform(true)">
                        <!--Al hacer click, muestra el formulario-->
                        <i class="fa fa-plus-square">
                          <!--Muestra el texto marcado con un estilo en cursiva o italica.-->
                        </i> Nuevo centro de operación
                      </button>
                    </SPAN>
                    <div class="clearfix"></div>
                  </div>
                  <div class="x_content">

                    <div class="panel-body table-responsive">

                      <table id="tbllistado" class="table table-striped table-bordered" style="width:100%; text-align: center" >
                        <thead>
                          <th>ID</th>
                          <th>NOMBRE</th>
                          <th>PROYECTO</th>
                          <th>TIPO</th>
                          <th>JEFE</th>
                          <th>ESTADO</th>
                          <th>OPCIÓN</th>
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
  <script type="text/javascript" src="../Ajax/CentroOperativoAjax.js"></script>
<?php

} else {
  echo "<script> 
  window.history.go(-1)
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