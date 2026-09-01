<?php
session_start();
if(isset($_SESSION['IdUsuarios'])){
$modulosAcceso=explode(",",$_SESSION['Modulos']);    
if(in_array("2M",$modulosAcceso)){    
    
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
            <h2>Crear rol de usuarios<small>Formulario</small></h2>
            
            <div class="clearfix"></div>
          </div>
          <div class="x_content">

            <!-- Inicio Formulario -->
            <div class="panel-body" style="align-content: center;" id="formularioregistros">

           <form name="demo-form2" id="demo-form2" method="POST">
                      <div class="row">
                              <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <input type="hidden" name="idrol" id="idrol">  
                                <label>Nombre Rol:</label>
                                <input type="text" class="form-control" name="nombre" id="nombre" required="" autofocus>
                              </div>
                          
                           <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                               <label>Permisos de auditoría:</label><br>
                                <select name="auditoria" id="auditoria" class="form-control"  required>
                                <option value="">Seleccione...</option>
                                <option value="1">Si</option>
                                <option value="0">No</option>       
                              </select> 
                            </div>
                          
                        </div> 
               
                         <div class="row">
                              <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <label>Modulos acceso:</label><br><br>
                               
                               <SPAN title="Usuarios" >
                               <input type="checkbox" class="" name="permiso[]" id="r1" value="1M"  /></SPAN>  <label >Usuarios</label><br>
                               <SPAN title="Roles" >
                               <input type="checkbox" class="" name="permiso[]" id="r2" value="2M"  /></SPAN>  <label >Roles</label><br> 
                                <SPAN title="Colaboradores" >
                               <input type="checkbox" class="" name="permiso[]" id="r3" value="3M"  /></SPAN>  <label >Colaboradores</label><br> 
                                  <SPAN title="Cargos" >
                               <input type="checkbox" class="" name="permiso[]" id="r4" value="4M"  /></SPAN>  <label >Cargos</label><br> 
                                  <SPAN title="Centro de operación" >
                               <input type="checkbox" class="" name="permiso[]" id="r5" value="5M"  /></SPAN>  <label >Centro de operación</label><br> 
                                  <SPAN title="Proyectos" >
                               <input type="checkbox" class="" name="permiso[]" id="r6" value="6M"  /></SPAN>  <label >Proyectos</label><br> 
                                <SPAN title="Titulo novedades" >
                               <input type="checkbox" class="" name="permiso[]" id="r7" value="7M"  /></SPAN>  <label >Titulo novedades:</label><br>
                               <SPAN title="Estados relevancía" >
                               <input type="checkbox" class="" name="permiso[]" id="r8" value="8M"  /></SPAN>  <label>Estados relevancía:</label><br>   
                               
                              </div>
                             <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                            
                            <br><br>   
                               <SPAN title="Observadores" >
                               <input type="checkbox" class="" name="permiso[]" id="r9" value="9M"  /></SPAN>  <label >Observadores Novedades</label><br>
                               <SPAN title="Novedades" >
                               <input type="checkbox" class="" name="permiso[]" id="r10" value="10M"  /></SPAN>  <label >Novedades</label><br> 
                                <SPAN title="lista chequeo" >
                               <input type="checkbox" class="" name="permiso[]" id="r11" value="11M"  /></SPAN>  <label >Lista de chequeo</label><br> 
                                  <SPAN title="Auditoria" >
                               <input type="checkbox" class="" name="permiso[]" id="r12" value="12M"  /></SPAN>  <label >Grupos listas</label><br> 
                                <SPAN title="usuarios" >
                               <input type="checkbox" class="" name="permiso[]" id="r13" value="13M"  /></SPAN>  <label >Dashboard</label><br> 
                                  <!--<SPAN title="usuarios" >
                               <input type="checkbox" class="" name="permiso[]" id="r14" value="14M"  /></SPAN>  <label >Usuarios:</label><br>--> 
                               
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
                  <h2>Listado de roles</h2>
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
                  <th>Nombre Rol</th>
                  <th>Estado</th>
                  <th>Opción</th>
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
<script type="text/javascript" src="../Ajax/RolesAjax.js"></script>
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