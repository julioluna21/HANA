<?php
session_start();
if(isset($_SESSION['IdUsuarios'])){
$modulosAcceso=explode(",",$_SESSION['Modulos']);    
//Sin el permiso 1M solo se entra a cambiar la clave propia (op=canvu)
$modoClave = (isset($_GET["op"]) && $_GET["op"] === "canvu");
if(in_array("1M",$modulosAcceso) or $modoClave){    
    
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
            <h2>Crear usuarios<small>Formulario</small></h2>
            <?php //Antes se imprimía lo que llegara en la dirección: permitía inyectar código en la página ?>
            <input type="hidden" name="cambioclave" id="cambioclave" value="<?php echo $modoClave ? 'canvu' : ''; ?>">
            <div class="clearfix"></div>
          </div>
          <div class="x_content">

            <!-- Inicio Formulario -->
            <div class="panel-body" style="align-content: center;" id="formularioregistros">

           <form name="demo-form2" id="demo-form2" method="POST">
                      <div class="row">
                           <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                               <label>Colaborador:</label><br>
                                <select name="colaborador" id="colaborador" class="form-control"  required>     
                              </select> 
                            </div>
                          
                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <input type="hidden" name="idusuario" id="idusuario">  
                                <label>Nombre de usuario:</label>
                                <input type="text" class="form-control" name="nombre" id="nombre" required="" autofocus>
                              </div>
                          
                          
                        </div> 
                
                        
               
                         <div class="row">
                             
                             <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" >
                               <label>Rol del usuario:</label><br>
                                <select name="rolColaborador" id="rolColaborador" class="form-control"  required>     
                              </select> 
                            </div>
                            
                                   
                        </div> 
               
                        <div class="row" id="centros">
                
                                   
                        </div> 
                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" id="">
                              <SPAN title="Guardar">
                                <button class="btn btn-primary" type="submit" id="btnGuardar"><i class="fa fa-save"></i> Guardar
                                </button>
                              </SPAN>       
<!--ejecuta cancelar formulario-->
                             <SPAN title="Cancelar">
                                <button class="btn btn-primary" onclick="mostrarformcalve(true)"  type="button" id="btnclave"><i class="fa fa-key "></i> Cambiar clave
                                </button>
                              </SPAN>
                                
                              <SPAN title="Cancelar">
                                <button class="btn btn-primary" onclick="cancelarform()"  type="button"><i class="fa fa-arrow-circle-left"></i> Cancelar
                                </button>
                              </SPAN>
                            </div>
                        
                          </form>

                
             </div>
              
            <div class="panel-body" style="align-content: center;" id="formclave">

           <form name="formularioclave" id="formularioclave" method="POST">
                      <div class="row">
                           <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <input type="hidden" name="idusuario" id="idusuario2" value="<?php echo $modoClave ? (int)$_SESSION['IdUsuarios'] : ''; ?>">  
                               <label>Contraseña:</label><br>
                               <input type="password" class="form-control" name="clave" id="clave" required="" autofocus>
                            </div>
                          
                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <label>Confirma contraseña:</label>
                                <input type="password" class="form-control" name="clave2" id="clave2" required="" autofocus>
                              </div>
                          
                          
                        </div> 
                
                        
               
    
                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" id="">
                              <SPAN title="Guardar">
                                <button class="btn btn-primary" type="submit" ><i class="fa fa-save"></i> Guardar
                                </button>
                              </SPAN>       
<!--ejecuta cancelar formulario-->
                              <SPAN title="Cancelar">
                                <button class="btn btn-primary" onclick="<?php echo isset($_GET["op"])? "window.history.go(-1)": "mostrarformcalve(false)"; ?>"  type="button"><i class="fa fa-arrow-circle-left"></i> Cancelar
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
                  <h2>Listado de usuarios</h2>
                  <SPAN title="Agregar usuario" style="float:right">
              <!--span - abarcar. Es un contenedor en línea. Sirve para aplicar estilo al texto o agrupar elementos en línea.-->
              <button class="btn btn-success" id="btnagregar" onclick="mostrarform(true)">
                <!--Al hacer click, muestra el formulario-->
                <i class="fa fa-plus-square">
                  <!--Muestra el texto marcado con un estilo en cursiva o italica.-->
                </i> Nuevo usuario
              </button>
            </SPAN>
                    <div class="clearfix"></div>
                  </div>
                  <div class="x_content">
                      
                      <div class="panel-body table-responsive" >  

                    <table id="tbllistado" class="table table-striped table-bordered" style="width:100%; text-align: center">
                    <thead>
                  <th style="min-width: 100px;">Documento</th>
                  <th style="min-width: 300px;">Colaborador</th>
                  <th style="min-width: 200px;">Usuario</th>
                  <th style="min-width: 200px;">Rol</th> 
                  <th>Centros de operación</th>        
                  <th>Estado</th>
                  <th style="min-width: 150px;">Opción</th>
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
<script type="text/javascript" src="../Ajax/UsuariosAjax.js"></script>
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