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
                           <div class="col-xs-12">
                             <label>Qué puede hacer este rol:</label>
                             <p class="rd-ayuda" style="margin:2px 0 10px;">El coordinador de cada proyecto (asignado en Proyectos) ya usa las pantallas de "llenar" de su proyecto sin marcar nada.
                               Si marcas una casilla de "llenar", el rol la usa en todos los proyectos. El ADMIN TEC tiene todas.</p>
                             <div class="roles-grupos">
                               <fieldset class="roles-grupo"><legend>Configuración</legend>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r1" value="1M"> <span>Usuarios</span> <small>1M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r2" value="2M"> <span>Roles de usuario</span> <small>2M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r3" value="3M"> <span>Colaboradores</span> <small>3M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r4" value="4M"> <span>Cargos</span> <small>4M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r5" value="5M"> <span>Centros de operación (y fondos, vehículos y campos de casetas)</span> <small>5M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r6" value="6M"> <span>Proyectos</span> <small>6M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r24" value="24M"> <span>Parámetros del sistema</span> <small>24M</small></label>
                               </fieldset>
                               <fieldset class="roles-grupo"><legend>Novedades</legend>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r7" value="7M"> <span>Títulos de novedades</span> <small>7M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r8" value="8M"> <span>Estados de relevancia</span> <small>8M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r9" value="9M"> <span>Observadores</span> <small>9M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r10" value="10M"> <span>Novedades (registrar y responder)</span> <small>10M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r16" value="16M"> <span>Ver todas las novedades (no solo las propias)</span> <small>16M</small></label>
                               </fieldset>
                               <fieldset class="roles-grupo"><legend>Requisiciones (RQ)</legend>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r15" value="15M"> <span>Requisiciones (pedir RQ)</span> <small>15M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r18" value="18M"> <span>Aprobar o rechazar RQ</span> <small>18M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r17" value="17M"> <span>Ver todas las RQ (no solo las propias)</span> <small>17M</small></label>
                               </fieldset>
                               <fieldset class="roles-grupo"><legend>Reporte diario: llenar</legend>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r28" value="28M"> <span>Hoy en qué estás</span> <small>28M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r11" value="11M"> <span>Listas de chequeo (diligenciar)</span> <small>11M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r12" value="12M"> <span>Editar listas y correos de listas</span> <small>12M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r29" value="29M"> <span>Arqueos</span> <small>29M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r36" value="36M"> <span>Autorizar fondos de arqueos (el coordinador, solo en su proyecto)</span> <small>36M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r30" value="30M"> <span>Cronograma y vehículo (ver todos los proyectos)</span> <small>30M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r31" value="31M"> <span>Vacantes</span> <small>31M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r32" value="32M"> <span>Comunicaciones y oficios</span> <small>32M</small></label>
                               </fieldset>
                               <fieldset class="roles-grupo"><legend>Reporte diario: consultar</legend>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r19" value="19M"> <span>Reporte general y tablero</span> <small>19M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r20" value="20M"> <span>Tablero: ver todos los proyectos</span> <small>20M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r21" value="21M"> <span>Administración del reporte diario (lo que llenan todos)</span> <small>21M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r22" value="22M"> <span>Consultar todos los proyectos (cronograma, ausentismo, seguimientos)</span> <small>22M</small></label>
                               </fieldset>
                               <fieldset class="roles-grupo"><legend>Ausentismo</legend>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r25" value="25M"> <span>Registrar y corregir el ausentismo de todos los proyectos (aunque el mes esté cerrado)</span> <small>25M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r23" value="23M"> <span>Reabrir meses cerrados</span> <small>23M</small></label>
                               </fieldset>
                               <fieldset class="roles-grupo"><legend>Dashboard y Power BI</legend>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r13" value="13M"> <span>Dashboard (Power BI dentro de HANA)</span> <small>13M</small></label>
                                 <label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r27" value="27M"> <span>Leer datos desde Power BI (usuario de conexión)</span> <small>27M</small></label>
                               </fieldset>
                             </div>
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