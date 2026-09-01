<?php
session_start();
if(isset($_SESSION['IdUsuarios'])){
$modulosAcceso=explode(",",$_SESSION['Modulos']);    
if(in_array("4M",$modulosAcceso) or isset($_GET["op"])){    
    
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
            <h2> Cargos </h2>
            
            <div class="clearfix"></div>
          </div>
          <div class="x_content">
            <!-- Inicio Formulario -->
            <div class="panel-body" style="align-content: center;" id="formularioregistros">
            
           <form name="demo-form2" id="demo-form2" method="POST">
          <h2>Crear o editar un registro</h2>
                      <div class="row">
                              <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <label>Nombre del cargo:</label>
                                <input type="hidden" class="form-control" name="idCargo" id="idCargo">
                              <input type="text" class="form-control" name="nombre" id="nombre" required="" autofocus>
                              </div>
                          
                              <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                               <label>Permiso de asignar novedades:</label><br>
                                <select name="permisoNovedad" id="permisoNovedad" class="form-control"  required>
                                <option value="">Seleccione...</option>
                                <option value="1">Si</option>
                                <option value="0">No</option>       
                              </select> 
                            </div>
                          
                        </div> 
               
                                                   
                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" id="">
                              <SPAN title="Guardar Registro">
                                <button class="btn btn-primary" type="submit" id="btnGuardar"><i class="fa fa-save"></i> Guardar
                                </button>
                              </SPAN>       
<!--ejecuta cancelar formulario-->
                              <SPAN title="Cancelar Registro">
                                <button class="btn btn-primary" onclick="cancelarform()"  type="button"><i class="fa fa-arrow-circle-left"></i> Cancelar
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
                  <h2>Listado Formulario</h2>
                  <SPAN title="Agregar Registro" style="float:right">
              <!--span - abarcar. Es un contenedor en línea. Sirve para aplicar estilo al texto o agrupar elementos en línea.-->
              <button class="btn btn-success" id="btnagregar" onclick="mostrarform(true)">
                <!--Al hacer click, muestra el formulario-->
                <i class="fa fa-plus-square">
                  <!--Muestra el texto marcado con un estilo en cursiva o italica.-->
                </i> Nuevo Registro
              </button>
            </SPAN>
                    <div class="clearfix"></div>
                  </div>
                  <div class="x_content">
                      
                      <div class="panel-body table-responsive" >  

                    <table id="tbllistado" class="table table-striped table-bordered" style="width:100%; text-align: center">
                      <thead>
                  <th>ID</th>
                  <th>NOMBRE</th>
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
<script type="text/javascript" src="../Ajax/cargosAjax.js"></script>
<?php

}else{   
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