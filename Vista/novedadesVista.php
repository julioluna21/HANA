<?php
session_start();
if(isset($_SESSION['IdUsuarios'])){
$modulosAcceso=explode(",",$_SESSION['Modulos']);    
if(in_array("10M",$modulosAcceso)){    
    
include('head.php');    

?>

<style>
 .dataTables_filter {
     position: relative;
     
     
     
    }
 
    .dataTables_filter input {
        width: 200px;
        height: 32px;
        background: #fcfcfc;
        border: 1px solid #aaa;
        border-radius: 5px;
        box-shadow: 0 0 3px #ccc, 0 10px 15px #ebebeb inset;
        text-indent: 10px;
    }
 
    .dataTables_filter .fa-search {
        position: absolute;
        top: 10px;
        left: auto;
        right: 10px;
    }

</style>


<!-- Contenido aqui va todo el DIV del contenido.. -->
<div class="right_col" role="main">
  <div class="">

    <div class="clearfix"></div>


    <div class="row">
      <div class="col-md-12 col-xs-12">
          
    
           
          


        <div class="x_panel" id="tablasform">
          <div class="x_title">
            <h2>Novedades registradas<small>Registros</small></h2>
            
            <div class="clearfix"></div>
          </div>
          <div class="x_content">

            <!-- Inicio Formulario -->
            <div class="panel-body" style="align-content: center;" id="formularioregistros">

           <form name="demo-form2" id="demo-form2" enctype="multipart/form-data" method="POST">
                      <div class="row">
                             
                          
                          <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                               <label>Titulo Novedad:</label>
                                <select name="titulo" id="titulo" class="form-control"  required>     
                              </select> 
                            </div>
                          
                          <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                              
                              <?php if($_SESSION['NovedadesAS']==1){ ?>
                                <label>Asignación Novedad:</label>
                                <select name="colaborador" id="colaborador" class="form-control"  required>     
                              </select> <?php } ?>
                              </div>
                          
                        </div> 
               
               
                       <div class="row">
                              
                          
                          <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                               <label>Centro de operación:</label>
                                <select name="centroop" id="centroop" class="form-control"  required>     
                              </select> 
                            </div>
                           
                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <label>Observador hallazgo:</label>
                                <select name="observador" id="observador" class="form-control"  required>     
                              </select> 
                              </div>
                          
                        </div> 
               
               
                        <div class="row">
                             
                          
                          <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                               <label>Validez novedad:</label><br>
                                <select name="validez" id="validez" class="form-control"  required>
                                <option value="">Seleccione validez</option>      
                                <option value="Procedente">Procedente</option>  
                                <option value="Improcedente">Improcedente</option>      
                              </select> 
                            </div>
                            
                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <label>Prioridad:</label>
                                <select name="prioridad" id="prioridad" class="form-control"  required>     
                              </select> 
                              </div>
                          
                        </div> 
               
                         <div class="row">
                             
                          <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                               <label>Descripción novedad:</label>
                              <textarea class="form-control" name="novedad" id="novedad" required></textarea>
                                 
                            </div>
                             
                             <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                             <br><SPAN class="miarchivo2" >  
                                 
                                 <input type="file" class="btn btn-secondary" name="miarchivo2" id="miarchivo2" required>
                                 
                             </SPAN>
                             <label for="miarchivo2" 
                                    ><SPAN title="Cargar imagen" id="imagenbt"><i class="fa fa-picture-o" aria-hidden="true" style="font-size: 20px"></i></SPAN></label>                      
                                 
                            </div>      
                           
                             
                          
                        </div> 
               
               
                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" id="">
                              <SPAN title="Guardar Registro">
                                <button class="btn btn-primary" type="submit" id="btnGuardar"><i id="btnguard" class="fa fa-save"></i> Guardar
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
                  <h2>Listado de novedades</h2>
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
                      
                      <div class="row">
                             
                          <div class="form-group col-lg-3 col-md-3 col-sm-3 col-xs-12">
                               <label>Filtrar por proyecto:</label>
                               <select id="validador" class="form-control" >  
                                <option value="1" selected>Todos</option> 
                                <option value="2">Mis centros de operación</option>  
                                <option value="3">Asignadas a mi usuario</option>     
                              </select> 
                            </div>
                             
                             <div class="form-group col-lg-3 col-md-3 col-sm-3 col-xs-12">
                             <label>Filtrar por estado:</label>
                               <select id="validador2" class="form-control" >
                                <option value="0" selected>Todos</option>    
                                <option value="1">Asignadas</option>
                                <option value="2">En proceso</option>    
                                <option value="3">Finalizadas</option>
                                <option value="4">Cerradas</option>       
                              </select>                     
                                 
                            </div>   
						  
						  <div class="form-group col-lg-2 col-md-2 col-sm-2 col-xs-12">
                             <label>Fecha inicial:</label>
                             <input type="date" class="form-control" name="fechai" id="fechai" >                      
                                 
                            </div> 
						  <div class="form-group col-lg-2 col-md-2 col-sm-2 col-xs-12">
                             <label>Fecha final:</label>
                             <input type="date" class="form-control" name="fechaf" id="fechaf" >                      
                                 
                            </div> 
						  
                          
                           <div class="form-group col-lg-2 col-md-2 col-sm-2 col-xs-12">
                         <br>  <SPAN title="Buscar" >
              <button class="btn btn-success"  onclick="condiciones()" style=" margin-top: 5px; ">
          
                <i class="fa fa-search">
                 
                </i>
              </button>
                               </SPAN></div>
                           
                             
                          
                        </div> 
                      
                      <div class="panel-body table-responsive" >  

                    <table id="tbllistado" class="table table-striped table-bordered" style="width:100%; text-align: center">
                    <thead>
                  <th>ID</th>          
                  <th style="min-width: 350px;">Titulo novedad</th>    
                  <th style="min-width: 150px;">Fecha registro</th>            
                  <th style="min-width: 300px;">Colaborador registro</th>
                  <th style="min-width: 300px;">Colaborador asignado</th>
                  <th style="min-width: 200px;">Proyecto</th>        
                  <th style="min-width: 200px;">Centro operación</th>
                  <th style="min-width: 150px;">Prioridad</th>         
                  <th style="min-width: 150px;">Fecha limite</th>    
                  <th style="min-width: 150px;">Estado respuesta</th>        
                  <th style="min-width: 150px;">Estado novedad</th>                
                  <th style="min-width: 100px;">Opción</th>
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
          
          
        
         <div class="x_panel" id="novedadeschat">
          
          <div class="x_content">

            
              
             <link href="../public/css/chat.css" rel="stylesheet">
          <div class="col">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="media align-items-center">
                            <i class="fa fa-comments" aria-hidden="true"  style="font-size: 25px"> .</i> 
                            <div class="media-body" id="encabezado">
                                <h6 class="mb-0 d-block"> DAÑO DE VENTANA PEAJE
                                <span class="badge badge-indicator badge-success">. </span>
                                </h6>
                                <span class="text-muted text-small"> 20/09/2024 14:21:21 </span>
                            </div>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-success" type="button" onclick="mostrarcontenedor(true)">
                           <i class="fa fa-sign-out" aria-hidden="true"  style="font-size: 20px"> </i> 
                            </button>
                         
                        </div>
                    </div>
                    <!--end card header-->
                    <div class="card-body overflow-auto" id="contenido">
                        
                        <div class="row justify-content-start">
                            <div class="col-auto">
                                <div class="card bg-secondary">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <span style=" font-weight: bold;">Observador novedad:</span> Interventoria<br>
                                        <span style=" font-weight: bold;">Centro operativo:</span> Peaje niquia<br>    
                                        <span style=" font-weight: bold;">Novedad asignada a:</span> JULIO MARTIN LUNA GALVIS<br>  
                                        <span style=" font-weight: bold;">Validez novedad:</span> Procede<br>  
                                        <span style=" font-weight: bold;">Prioridad:</span> Alta<br>       
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">JULIO MARTIN LUNA 2024/010/09 10:20:23</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                         <div class="row justify-content-start">
                             <div class="col-auto">
                                <div class="card bg-secondary">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <span style=" font-weight: bold;">Novedad:</span> el vidrio del peaje niquia se encuentraa roto, la intervemtoria realiza laa novedad para drle seguimiento, el vidrio del peaje niquia se encuentraa roto, la intervemtoria realiza laa novedad para drle seguimiento,     
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">JULIO MARTIN LUNA 2024/01/09 10:20:23</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col text-center">
                                <span class="badge badge-secondary">FOTO EVIDENCIA NOVEDAD</span>
                            </div>
                            <!--end of col-->
                        </div>
                        
                        
                        <div class="row justify-content-start">
                             <div class="col-auto">
                                <div class="card bg-secondary">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        <a href="../public/img/Logo1.png"><img src="../public/img/construccion.png" class="img-rounded" alt="Cinque Terre"style="width: 350px;"></a>   
                                        </p><br>
                                        <div>
                                            <small class="opacity-60">JULIO MARTIN LUNA 2024/01/09 10:20:23</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row justify-content-end text-right">
                            <div class="col-auto">
                                <div class="card bg-primary text-white">
                                    <div class="card-body p-2">
                                        <p class="mb-0">
                                        Se monta requisicion para la compra del vidrio en sisesa a la espera de respuesta<br><br>
                                            
                                        </p>
                                        <div>
                                            <span style=" font-weight: bold;" class="opacity-60">Estado:En proceso</span><br>    
                                            <small class="opacity-60">BRYAN CAJIA OCAMPO 2024/01/12 10:20:23</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                      
                        
                    </div>
                    <div class="card-footer bg-secondary">
                        <form class="d-flex align-items-center" enctype="multipart/form-data" name="respuesta" id="respuesta" method="post">
                            <div class="input-group input-group-lg">
                                <div class="input-group-prepend">
                                    <button class="btn btn-secondary" type="submit" id="btnenvio">
                                    <i class="fa fa-paper-plane"></i>
                                    </button>
                                </div>
                                
                                <div class=" input-group-prepend dropdown">
                            <button class="btn btn-secondary dropdown-toggle dropdown-toggle-no-arrow"  data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" type="button">
                            <i class="fa fa-hourglass-start"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-right dropdown-menu-sm" aria-labelledby="Button" >
                                <a class="dropdown-item" onclick="novedades(2)">En proceso</a>
                                <label class="dropdown-item" for="miarchivo" onclick="novedades(3)" style="cursor: pointer;">Finalizada</label>
                                <?php if($_SESSION['Audititoria']==1){?>
                                <a class="dropdown-item" onclick="novedades(4)">Cerrada</a> 
                                <?php } 
                             echo $_SESSION['Audititoria']==1? '<input type="hidden" id="esdaoauditoria" name="esdaoauditoria" value="1">': '<input type="hidden" id="esdaoauditoria" name="esdaoauditoria" value="0">'; 
                                ?>
                                
                            </div>
                            </div>
                                <input type="hidden" id="IDnovedad" name="IDnovedad">
                                <input type="hidden" id="estadoNovedad" name="estadoNovedad">
                                <textarea  class="form-control" type="text" placeholder="Escribir respuesta a novedad" name="novedad" id="nrespuesta" required></textarea>
                                <input type="file" class="btn btn-secondary" name="miarchivo" id="miarchivo"  />
                            </div>    
                          
                        </form>
                    </div>
                </div>  
              
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
<script type="text/javascript" src="../Ajax/novedadnuevoAjax.js"></script>
<script>
    
    

 </script>
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