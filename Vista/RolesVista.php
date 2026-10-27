<?php
/*
  HANA - Roles
  ---------------------------------------------------------------------------
  Pantalla para crear roles y marcar qué permisos tiene cada uno.
  La pantalla es solo el HTML: lo que hace cada botón está en Ajax/RolesAjax.js
  y los datos los entrega Control/RolesControl.php.
  Permiso: 2M (sin sesión va al inicio de sesión; sin el permiso, a la pantalla de inicio).
*/
session_start();
if(isset($_SESSION['IdUsuarios'])){
$modulosAcceso=explode(",",$_SESSION['Modulos']);    
if(in_array("2M",$modulosAcceso)){    
    
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
            <h2>Permisos de usuario<small>Crear o editar un rol</small></h2>
            
            <div class="clearfix"></div>
          </div>
          <div class="x_content">

            <!-- Inicio Formulario -->
            <div class="panel-body" style="align-content: center;" id="formularioregistros">

           <form name="demo-form2" id="demo-form2" method="POST">
                      <div class="row">
                              <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                <input type="hidden" name="idrol" id="idrol">  
                                <label>Nombre del rol:</label>
                                <input type="text" class="form-control" name="nombre" id="nombre" required="" autofocus>
                              </div>
                          
                           <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                               <label>Puede cerrar novedades (auditoría):</label><br>
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
                             <p class="rd-ayuda" style="margin:2px 0 10px;">Las casillas dicen <strong>qué</strong> puede hacer el rol. <strong>Dónde</strong> lo dicen los peajes asignados a cada persona en Configuración → Colaboradores: si tiene solo los de un proyecto, ve y llena solo ese proyecto; si tiene todos, ve todos.
                        Ser coordinador de un proyecto (Configuración → Proyectos) ya no da acceso por sí solo. Con 24M (ADMIN TEC) se hace todo, en todos los peajes.</p>
<?php
//Casillas por módulo: [código, texto]. Las mismas que usa Control/AccesoHelper.php (hanaCasillas)
$gruposRoles = array(
    'Configuración' => array(array('1M', 'Usuarios: el acceso de cada persona (usuario, rol y contraseña)'), array('2M', 'Permisos de usuario (esta pantalla)'),
        array('3M', 'Colaboradores: registrar personas, sus datos y sus peajes'), array('5M', 'Centros de operación'), array('6M', 'Proyectos'),
        array('24M', 'Parámetros del sistema y control total (todo, en todos los peajes)'), array('57M', 'Recordatorios y avisos automáticos por correo (configurarlos)')),
    'Novedades' => array(array('7M', 'Títulos de novedades'), array('10M', 'Registrar y responder novedades'),
        array('16M', 'Ver todas las novedades de sus peajes (no solo las propias)'), array('42M', 'Asignar una novedad a una persona')),
    'Requisiciones (RQ)' => array(array('15M', 'Entrar y ver las propias'), array('41M', 'Pedir RQ'), array('18M', 'Aprobar o rechazar RQ de sus peajes'),
        array('17M', 'Ver todas las RQ de sus peajes (no solo las propias)')),
    'Hoy en qué estás' => array(array('28M', 'Llenar: registrar su día'), array('33M', 'Ver: el día de las personas de sus peajes')),
    'Listas de chequeo' => array(array('11M', 'Llenar: diligenciar y corregir listas'), array('21M', 'Ver: revisar las listas diligenciadas'),
        array('12M', 'Editar: crear y cambiar las listas y sus preguntas'), array('44M', 'Correos de las listas (a quién le llegan)')),
    'Arqueos' => array(array('29M', 'Llenar: hacer arqueos'), array('45M', 'Ver: los arqueos de sus peajes'),
        array('36M', 'Autorizar fondos y configurar los campos de casetas'), array('46M', 'Anular arqueos que hicieron otros')),
    'Cronograma y vehículo' => array(array('30M', 'Llenar: el cronograma del proyecto y el estado del vehículo'), array('34M', 'Ver: el cronograma y el vehículo'),
        array('47M', 'Vehículos: agregar, cambiar y quitar los de sus proyectos (placas)')),
    'Vacantes' => array(array('31M', 'Llenar: registrar y actualizar vacantes'), array('48M', 'Ver: las vacantes de sus peajes'),
        array('49M', 'Cambiar la fecha comprometida por Gestión Humana (con Llenar)')),
    'Comunicaciones y oficios' => array(array('32M', 'Llenar: registrar y responder'), array('50M', 'Ver: las de sus proyectos'),
        array('51M', 'Resolver, reabrir y corregir o anular las de otros')),
    'Consultas' => array(array('19M', 'Reporte general (de sus peajes)'), array('43M', 'Tablero por proyectos, dentro del reporte general'),
        array('37M', 'Informe mensual de gestión (PDF)')),
    //El grupo «Ausentismo» (casillas 52M, 53M, 58M, 25M, 23M) se retiró junto con el módulo de ausentismo.
    //Esos códigos quedan reservados y no se reutilizan. También quedan reservados y sin pantalla: 40M (consolidado
    //de promesa, retirado), 4M, 8M, 9M, 13M y 27M (funciones retiradas: cargos, estados, observadores, Power BI).
    'Promesa de valor' => array(array('54M', 'Llenar: promesa, ejecutado y justificación'), array('55M', 'Ver: la promesa de sus peajes'),
        array('56M', 'Corregir días pasados (fuera del plazo de Parámetros)'))
);
?>
<div class="roles-grupos">
<?php foreach ($gruposRoles as $tituloGrupo => $casillasGrupo) { ?>
<fieldset class="roles-grupo"><legend><?php echo htmlspecialchars($tituloGrupo, ENT_QUOTES, 'UTF-8'); ?></legend>
<?php foreach ($casillasGrupo as $casillaRol) { $numeroCasilla = (int)$casillaRol[0]; ?>
<label class="roles-casilla"><input type="checkbox" name="permiso[]" id="r<?php echo $numeroCasilla; ?>" value="<?php echo $casillaRol[0]; ?>"> <span><?php echo htmlspecialchars($casillaRol[1], ENT_QUOTES, 'UTF-8'); ?></span> <small><?php echo $casillaRol[0]; ?></small></label>
<?php } ?>
</fieldset>
<?php } ?>
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

            <!-- tabla con el listado de registros -->
            <div id="listadoregistros">
                <div class="x_panel">
                  <div class="x_title">
                  <h2>Listado de roles</h2>
                  <SPAN title="Agregar Registro" style="float:right">
              <!--span - abarcar. Es un contenedor en línea. Sirve para aplicar estilo al texto o agrupar elementos en línea.-->
              <button class="btn btn-success" id="btnagregar" onclick="mostrarform(true)">
                <!--Al hacer click, muestra el formulario-->
                <i class="fa fa-plus-square">
                  <!--Muestra el texto marcado con un estilo en cursiva o itálica.-->
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
<script type="text/javascript" src="../Ajax/RolesAjax.js?v=2"></script>
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