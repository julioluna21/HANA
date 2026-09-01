<?php
session_start();
if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    if (in_array("12M",$modulosAcceso) or isset($_GET["op"])) {
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
                                <h2>Listas de Chequeo</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <!-- Inicio Formulario -->
                                <div class="panel-body" style="align-content: center;" id="formularioregistros">
                                    <form name="demo-form2" id="demo-form2" method="POST">
                                        <h2>Crea o edita una lista de chequeo</h2>
                                        <div class="row">
                                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                                <label>Nombre de la lista:</label>
                                                <input type="hidden" class="form-control" name="idLista" id="idLista">
                                                <input type="text" class="form-control" name="nombre" id="nombre" required="" autofocus>
                                            </div>
                                        </div> 
                                           <!-- Lista de Preguntas y Respuestas -->
                                            <ul class="list-group" id="listaPreguntas"></ul>                                        
                                        <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" id="">
                                            <SPAN title="Guardar Registro">
                                                <button class="btn btn-primary" type="submit" id="btnGuardar"><i class="fa fa-save"></i> Guardar
                                                </button>
                                            </SPAN>
                                            <!--ejecuta cancelar formulario-->
                                            <SPAN title="Cancelar Registro">
                                                <button class="btn btn-primary" onclick="cancelarform()" type="button"><i class="fa fa-arrow-circle-left"></i> Cancelar
                                                </button>
                                            </SPAN>
                                        </div>
                                    </form>
                                    <div class="modal fade" id="modal-preguntas" tabindex="-1" role="dialog" aria-labelledby="modal-preguntas-label" aria-hidden="true">
                                                <div class="modal-dialog" role="document">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="modal-preguntas-label">Agregar preguntas</h5>
                                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                                <span aria-hidden="true">&times;</span>
                                                            </button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <form style="margin-left: 5%; width:90%" id="modal-form2" method="POST">
                                                                <div class="form-group">
                                                                    <label for="pregunta">Pregunta</label>
                                                                    <input type="hidden" class="form-control" id="idGrupo" />
                                                                    <input type="hidden" class="form-control" id="idPregunta" require />
                                                                    <input type="text" class="form-control" id="pregunta" require autofocus/>
                                                                </div>
                                                                <div class="form-group">
                                                                    <label for="tipo-respuesta">Tipo de respuesta</label>
                                                                    <select class="form-control" id="tipo-respuesta">
                                                                        <option value="">Seleccione una opción</option>
                                                                        <option value="texto">Texto</option>
                                                                        <option value="si/no">Sí/No</option>
                                                                        <option value="lista">Lista</option>
                                                                    </select>
                                                                </div>
																<div class="form-group" id="novedad">
                                                                    <label for="genera-novedad">La Preguna Genera Novedad?</label>
                                                                    <select class="form-control" id="genera-novedad">
                                                                        <option value="">Seleccione una opción</option>
                                                                        <option value="1">Si</option>
                                                                        <option value="0">No</option>
                                                                    </select>
                                                                </div>
																<div class="form-group" id="titulo">
                                                                    <label for="Titulo-novedad">Titulo Asociado</label>
                                                                    <select class="form-control" id="Titulo-novedad">
                                                                        
                                                                    </select>
                                                                </div>
                                                            </form>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Terminar</button>
                                                            <button type="button" class="btn btn-primary" id="btn-agregar-pregunta">Agregar pregunta</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <!--modal importa excel-->
                                            <div class="modal fade" id="modal-excel" tabindex="-1" role="dialog" aria-labelledby="modal-excel-label" aria-hidden="true">
                                                <div class="modal-dialog" role="document">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="modal-excel-label">Agregar Preguntas desde Excel</h5>
                                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                                <span aria-hidden="true">&times;</span>
                                                            </button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="row" style="    margin-left: 5%;">
                                                                <p>Por favor utilice el siguiente ejemplo para agregar preguntas a una lista de chequeo<button id="descargarCSV" >Descargar ejemplo</button></p>
                                                            </div>
                                                            
                                                            <form style="margin-left: 5%; width:90%" id="modal-form2" method="POST" enctype="multipart/form-data">
                                                                <div class="form-group">
                                                                    <input type="hidden" class="form-control" id="idGrupo2" />
                                                                    <input type="file" id="fileInput" />
                                                                </div>
                                                            </form>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Terminar</button>
                                                            <button type="button" class="btn btn-primary" id="btn-agregar-excel">Agregar pregunta</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- Listado Preguntas-->
                                    <div id="listadoPreguntas">
                                        <div class="x_panel">
                                            <div class="x_title" id="x_title">
                                                <h2>Preguntas Creadas</h2>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="x_content">
                                                <div class="panel-body table-responsive">
                                                    <table id="tblPreguntas" class="table table-striped table-bordered" style="width:100%">
                                                        <thead>
                                                            <th>ID</th>
                                                            <th>LISTA</th>
                                                            <th>PREGUNTA</th>
                                                            <th>TIPO RESPUESTA</th>
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
                                    <!-- Listado Registros-->
                                    <div id="listadoregistros">
                                        <div class="x_panel">
                                            <div class="x_title">
                                                <h2>Listas Creadas</h2>
                                                <SPAN title="Agregar Lista" style="float:right">
                                                    <!--span - abarcar. Es un contenedor en línea. Sirve para aplicar estilo al texto o agrupar elementos en línea.-->
                                                    <button class="btn btn-success" id="btnagregar" onclick="mostrarform(true)">
                                                        <!--Al hacer click, muestra el formulario-->
                                                        <i class="fa fa-plus-square">
                                                            <!--Muestra el texto marcado con un estilo en cursiva o italica.-->
                                                        </i> Nueva Lista
                                                    </button>
                                                </SPAN>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="x_content">
                                                <div class="panel-body table-responsive">
                                                    <table id="tbllistado" class="table table-striped table-bordered" style="width:100%">
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
        <script type="text/javascript" src="../Ajax/GruposListasnAjax.js"></script>
<?php
    } else {
        echo "<script> 
  window.history.go(-1)
  </script>";
    }
} else {
    echo "<script> 
  <!--
  window.location.replace('login.php'); 
  //-->
  </script>";
}
?>