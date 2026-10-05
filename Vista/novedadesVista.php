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
                               <label>Título de la novedad:</label>
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
                                <option value="">Selecciona la validez</option>      
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

                        <!-- A quién se le avisa: a todas las personas de este rol, no a una
                             persona suelta ni a todo el mundo -->
                        <div class="row">
                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <label for="rolDestino">Rol a notificar: <span style="color:#6E1A1E;">*</span></label>
                                <select name="rolDestino" id="rolDestino" class="form-control" required>
                                    <option value="">Cargando roles...</option>
                                </select>
                                <small style="color:#6B7076;">Le llega a todas las personas que tengan este rol, en la campana y por correo.</small>
                            </div>
                        </div>
               
                         <div class="row">
                             
                          <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                               <label>Descripción novedad:</label>
                              <textarea class="form-control" name="novedad" id="novedad" required></textarea>
                                 
                            </div>
                             
                             <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                             <!-- La foto es obligatoria, pero antes su campo estaba escondido
                                  por CSS y solo se veia un iconito: si faltaba, parecia que el
                                  boton Guardar no hacia nada. Ahora se ve que es obligatoria,
                                  se puede tomar con la camara y queda una vista previa -->
                             <label for="miarchivo2">Foto de evidencia: <span style="color:#6E1A1E;">*</span></label>
                             <SPAN class="miarchivo2">
                                 <!-- accept: solo imagenes. El atributo capture NO se pone aqui:
                                      si estuviera, el celular abriria siempre la camara y no
                                      dejaria elegir una foto ya tomada. Lo pone y lo quita el
                                      JavaScript segun el boton que se oprima -->
                                 <input type="file" name="miarchivo2" id="miarchivo2" accept="image/*" required>
                             </SPAN>
                             <div class="hana-foto">
                                 <!-- Dos caminos: tomar la foto en el momento, o buscar una que
                                      ya este en el telefono o en el computador -->
                                 <button type="button" class="hana-foto-boton" id="imagenbt" data-campo="#miarchivo2" data-modo="camara">
                                     <i class="fa fa-camera" aria-hidden="true"></i> Tomar foto
                                 </button>
                                 <button type="button" class="hana-foto-boton hana-foto-boton-alt" data-campo="#miarchivo2" data-modo="archivo">
                                     <i class="fa fa-folder-open-o" aria-hidden="true"></i> Elegir de mis archivos
                                 </button>
                                 <div class="hana-foto-previa" id="fotoPrevia" style="display:none;">
                                     <img id="fotoPreviaImg" alt="Vista previa de la foto">
                                     <div class="hana-foto-datos"><span id="fotoNombre"></span><button type="button" class="hana-foto-quitar" id="fotoQuitar">Quitar</button></div>
                                 </div>
                             </div>
                            </div>      
                           
                             
                          
                        </div> 
               
               
                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" id="">
                              <SPAN title="Guardar">
                                <button class="btn btn-primary" type="submit" id="btnGuardar"><i id="btnguard" class="fa fa-save"></i> Guardar
                                </button>
                              </SPAN>       
<!--ejecuta cancelar formulario-->
                              <SPAN title="Cancelar">
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
                  <SPAN title="Registrar novedad" style="float:right">
              <!--span - abarcar. Es un contenedor en línea. Sirve para aplicar estilo al texto o agrupar elementos en línea.-->
              <button class="btn btn-success" id="btnagregar" onclick="mostrarform(true)">
                <!--Al hacer click, muestra el formulario-->
                <i class="fa fa-plus-square">
                  <!--Muestra el texto marcado con un estilo en cursiva o italica.-->
                </i> Nueva novedad
              </button>
            </SPAN>
                    <div class="clearfix"></div>
                  </div>
                  <div class="x_content">

                      <!-- Un solo control para elegir qué se ve. Reemplaza al antiguo filtro
                           "Asignadas a mi usuario". El servidor decide qué entra en cada vista -->
                      <div class="hana-vista" id="vistaNovedades" role="group" aria-label="Qué novedades ver">
                          <button type="button" class="hana-vista-btn activo" data-vista="mias">
                              <i class="fa fa-user"></i> Mis registros
                          </button>
                          <button type="button" class="hana-vista-btn" data-vista="todas">
                              <i class="fa fa-users"></i> <span class="hana-vista-todas">Las de mi rol</span>
                          </button>
                      </div>

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

                    <table id="tbllistado" class="table table-striped table-bordered tabla-novedades" style="width:100%; text-align: center">
                    <thead>
                  <th>ID</th>          
                  <!-- Los anchos minimos se pasaron a la hoja de estilos (public/css/comun.css).
                       Escritos aqui sumaban unos 2.400px y la tabla no cabia en el celular:
                       ahora solo se aplican en pantallas grandes -->
                  <th>Título de la novedad</th>
                  <th>Fecha registro</th>
                  <th>Colaborador registro</th>
                  <th>Colaborador asignado</th>
                  <th>Proyecto</th>
                  <th>Centro operación</th>
                  <th>Prioridad</th>
                  <th>Fecha límite</th>
                  <th>Estado respuesta</th>
                  <th>Estado novedad</th>
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
          
          
        
         <div class="x_panel" id="novedadeschat">
          
          <div class="x_content">

            
              
             <link href="../public/css/chat.css" rel="stylesheet">
             <!-- chat.css se carga aqui, DESPUES de head.php, asi que sus colores y su
                  regla ".row{display:-webkit-box}" ganaban sobre los nuestros. Por eso
                  se vuelven a cargar nuestras hojas justo despues: asi mandan las de HANA -->
             <link href="../public/css/comun.css?v=1" rel="stylesheet">
             <link href="../public/css/paleta.css?v=1" rel="stylesheet">
             <!-- Estilos de la pantalla de novedades: foto con vista previa y estados visibles -->
             <link href="../public/css/novedades.css?v=1" rel="stylesheet">
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
                        <!-- Este bloque lo llena el sistema con la novedad real.
                             Antes traia una conversacion de ejemplo escrita a mano
                             ("BRYAN CAJIA OCAMPO 2024/01/12"), que se alcanzaba a ver
                             mientras cargaba y confundia -->
                    </div>

                    <div class="card-footer bg-secondary">
                        <form enctype="multipart/form-data" name="respuesta" id="respuesta" method="post">

                            <!-- El estado ya no se elige dentro de un menu escondido detras de un
                                 reloj de arena: ahora se ve cual esta seleccionado, y el boton de
                                 enviar quedo despues del texto, que es el orden natural -->
                            <!-- El asterisco solo para quien SI esta obligado a elegir estado:
                                 el servidor permite responder sin estado a quien tiene permiso
                                 de auditoria, que a veces solo deja un comentario -->
                            <label class="hana-resp-titulo">Estado de la respuesta:<?php if($_SESSION['Audititoria']!=1){ ?> <span style="color:#6E1A1E;">*</span><?php } ?></label>
                            <div class="hana-resp-estados">
                                <label class="hana-resp-estado hana-resp-proceso">
                                    <input type="radio" name="estadoNovedad" id="estadoProceso" value="2">
                                    <span><i class="fa fa-clock-o" aria-hidden="true"></i> En proceso</span>
                                </label>
                                <label class="hana-resp-estado hana-resp-final">
                                    <input type="radio" name="estadoNovedad" id="estadoFinalizada" value="3">
                                    <span><i class="fa fa-check" aria-hidden="true"></i> Finalizada</span>
                                </label>
<?php if($_SESSION['Audititoria']==1){ ?>
                                <label class="hana-resp-estado hana-resp-cerrada">
                                    <input type="radio" name="estadoNovedad" id="estadoCerrada" value="4">
                                    <span><i class="fa fa-lock" aria-hidden="true"></i> Cerrada</span>
                                </label>
<?php } ?>
                            </div>

                            <!-- Al marcar "Finalizada" aparece este campo: antes el sistema abria
                                 el selector de archivos sin decir para que -->
                            <div class="hana-resp-foto" id="respFoto" style="display:none;">
                                <label for="miarchivo">Foto de evidencia del cierre (opcional):</label>
                                <input type="file" name="miarchivo" id="miarchivo" accept="image/*">
                                <div class="hana-foto">
                                    <button type="button" class="hana-foto-boton" id="imagenbt2" data-campo="#miarchivo" data-modo="camara">
                                        <i class="fa fa-camera" aria-hidden="true"></i> Tomar foto
                                    </button>
                                    <button type="button" class="hana-foto-boton hana-foto-boton-alt" data-campo="#miarchivo" data-modo="archivo">
                                        <i class="fa fa-folder-open-o" aria-hidden="true"></i> Elegir de mis archivos
                                    </button>
                                </div>
                                <div class="hana-foto-previa" id="fotoPrevia2" style="display:none;">
                                    <img id="fotoPreviaImg2" alt="Vista previa de la foto de cierre">
                                    <div class="hana-foto-datos"><span id="fotoNombre2"></span><button type="button" class="hana-foto-quitar" id="fotoQuitar2">Quitar</button></div>
                                </div>
                            </div>

                            <textarea class="form-control hana-resp-texto" placeholder="Escribe tu respuesta a la novedad" name="novedad" id="nrespuesta" rows="3" required></textarea>

                            <input type="hidden" id="IDnovedad" name="IDnovedad">
<?php echo $_SESSION['Audititoria']==1
        ? '<input type="hidden" id="esdaoauditoria" name="esdaoauditoria" value="1">'
        : '<input type="hidden" id="esdaoauditoria" name="esdaoauditoria" value="0">'; ?>

                            <button class="btn btn-success hana-resp-enviar" type="submit" id="btnenvio">
                                <i class="fa fa-paper-plane"></i> Enviar respuesta
                            </button>
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