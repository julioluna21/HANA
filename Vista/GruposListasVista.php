<?php
session_start();
if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    if (in_array("12M",$modulosAcceso) or isset($_GET["op"])) {
        include('head.php');
?>
        <!-- Estilos de la carga masiva de preguntas -->
        <link href="../public/css/csv.css?v=1" rel="stylesheet">
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
                                            <SPAN title="Guardar">
                                                <button class="btn btn-primary" type="submit" id="btnGuardar"><i class="fa fa-save"></i> Guardar
                                                </button>
                                            </SPAN>
                                            <!--ejecuta cancelar formulario-->
                                            <SPAN title="Cancelar">
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
                                                                        <option value="fecha">Fecha</option>
                                                                        <option value="datetime">Fecha y hora</option>
                                                                        <option value="firma">Firma</option>
                                                                    </select>
                                                                </div>
																                                                                <div class="form-group">
                                                                    <label for="posicion-pregunta">Posición</label>
                                                                    <select class="form-control" id="posicion-pregunta">
                                                                        <option value="final">Al final</option>
                                                                    </select>
                                                                </div>
<div class="form-group" id="novedad">
                                                                    <label for="genera-novedad">¿La pregunta genera novedad?</label>
                                                                    <select class="form-control" id="genera-novedad">
                                                                        <option value="">Seleccione una opción</option>
                                                                        <option value="1">Sí</option>
                                                                        <option value="0">No</option>
                                                                    </select>
                                                                </div>
																<div class="form-group" id="titulo">
                                                                    <label for="Titulo-novedad">Título asociado</label>
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
                                                            <h5 class="modal-title" id="modal-excel-label">Agregar preguntas desde un archivo</h5>
                                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                                <span aria-hidden="true">&times;</span>
                                                            </button>
                                                        </div>
                                                        <div class="modal-body hana-csv">

                                                            <!-- Paso 1: la plantilla, para que nadie tenga que adivinar el formato -->
                                                            <div class="hana-csv-paso">
                                                                <span class="hana-csv-num">1</span>
                                                                <div>
                                                                    <strong>Descarga la plantilla</strong>
                                                                    <p>Trae dos hojas: <b>INSTRUCCIONES</b>, con la explicación completa, y
                                                                       <b>PREGUNTAS</b>, donde escribes. Puedes borrar la de instrucciones
                                                                       antes de subir el archivo, o dejarla: el sistema siempre lee la hoja PREGUNTAS.</p>
                                                                    <button type="button" class="btn btn-default" id="descargarCSV">
                                                                        <i class="fa fa-download"></i> Descargar plantilla de Excel
                                                                    </button>
                                                                </div>
                                                            </div>

                                                            <!-- Paso 2: que escribir, explicado con la tabla de tipos -->
                                                            <div class="hana-csv-paso">
                                                                <span class="hana-csv-num">2</span>
                                                                <div>
                                                                    <strong>Completa las dos columnas</strong>
                                                                    <p>En la hoja <b>PREGUNTAS</b>, una pregunta por fila.
                                                                       La primera fila es el encabezado y no se importa.</p>

                                                                    <table class="hana-csv-tabla">
                                                                        <thead>
                                                                            <tr><th>PREGUNTA</th><th>TIPO DE RESPUESTA</th></tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr><td>¿El área está limpia y ordenada?</td><td><code>si/no</code></td></tr>
                                                                            <tr><td>Estado de la señalización</td><td><code>lista</code></td></tr>
                                                                        </tbody>
                                                                    </table>

                                                                    <p class="hana-csv-tipos"><strong>Tipos válidos:</strong>
                                                                        <code>texto</code> escribe libremente ·
                                                                        <code>si/no</code> botones Sí, No y No aplica ·
                                                                        <code>lista</code> Bueno, Regular, Malo o No aplica ·
                                                                        <code>fecha</code> ·
                                                                        <code>datetime</code> hora ·
                                                                        <code>firma</code> se firma en la pantalla
                                                                    </p>
                                                                </div>
                                                            </div>

                                                            <!-- Paso 3: subir y revisar antes de guardar -->
                                                            <div class="hana-csv-paso">
                                                                <span class="hana-csv-num">3</span>
                                                                <div>
                                                                    <strong>Sube el archivo y revisa</strong>
                                                                    <p>Se acepta Excel (.xlsx) o CSV. Antes de guardar nada verás lo que se va a importar.</p>

                                                                    <form id="modal-form2" method="POST" enctype="multipart/form-data">
                                                                        <input type="hidden" class="form-control" id="idGrupo2" />
                                                                        <input type="file" id="fileInput" accept=".xlsx,.xlsm,.csv" />
                                                                        <label for="fileInput" class="hana-csv-boton">
                                                                            <i class="fa fa-file-text-o"></i> Elegir archivo CSV
                                                                        </label>
                                                                        <span class="hana-csv-nombre" id="csvNombre"></span>
                                                                    </form>

                                                                    <!-- Aqui el navegador muestra la vista previa -->
                                                                    <div id="csvPrevia"></div>
                                                                </div>
                                                            </div>

                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                                                            <button type="button" class="btn btn-primary" id="btn-agregar-excel" disabled>Importar preguntas</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- Listado Preguntas-->
                                    <div id="listadoPreguntas">
                                        <div class="x_panel">
                                            <div class="x_title" id="x_title">
                                                <h2>Preguntas creadas</h2>
                                                <span title="Editar todas las preguntas" style="float:right">
                                                    <button class="btn btn-warning" type="button" id="btnEditarTodas" onclick="irAEditarTodas()">
                                                        <i class="fa fa-pencil"></i> Editar todas las preguntas
                                                    </button>
                                                </span>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="x_content">
                                                <div class="panel-body table-responsive">
                                                    <table id="tblPreguntas" class="table table-striped table-bordered" style="width:100%">
                                                        <thead>
                                                            <th>ID</th>
                                                            <th>POSICIÓN</th>
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
                                                <h2>Listas creadas</h2>
                                                <SPAN title="Agregar lista" style="float:right">
                                                    <!--span - abarcar. Es un contenedor en línea. Sirve para aplicar estilo al texto o agrupar elementos en línea.-->
                                                    <button class="btn btn-success" id="btnagregar" onclick="mostrarform(true)">
                                                        <!--Al hacer click, muestra el formulario-->
                                                        <i class="fa fa-plus-square">
                                                            <!--Muestra el texto marcado con un estilo en cursiva o italica.-->
                                                        </i> Nueva lista
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
        <script type="text/javascript" src="../Ajax/GruposListasnAjax.js?v=5"></script>
        <script type="text/javascript">
        //Esta funcion se define aqui a proposito: si el navegador tiene cacheado
        //GruposListasnAjax.js, el boton igual sigue funcionando
        function irAEditarTodas() {
            //El ID se lee del campo oculto del formulario, no de una variable en memoria
            var id = $("#idLista").val() || window.grupoActual;

            //Si no hay lista abierta no se puede editar nada
            if (!id) {
                alert("Abre primero una lista (bot\u00f3n del ojo) para editar sus preguntas.");
                return;
            }

            //Se le agrega ?idLista= a la URL actual antes de salir. Asi, al presionar
            //"atras" en el navegador, se regresa aqui con la lista ya abierta
            try {
                history.replaceState(null, "", "GruposListasVista.php?idLista=" + encodeURIComponent(id));
            } catch (e) {
                //Navegador sin history API: simplemente se sigue de largo
            }

            //El archivo se llama Editarpreguntasvista.php, en minúsculas. En Windows da
            //igual, pero el servidor es Linux y distingue mayúsculas: con el nombre
            //en mayúsculas este botón llevaba a una página que no existe
            window.location.href = "Editarpreguntasvista.php?idGrupo=" + encodeURIComponent(id);
        }

        //Al cargar la pagina se revisa si viene ?idLista= en la URL
        $(function () {
            var params = new URLSearchParams(window.location.search);
            var idLista = params.get("idLista");

            //Si viene, se reabre esa lista sola y se muestran sus preguntas
            if (idLista && typeof mostrar === "function") {
                mostrar(idLista);
            }
        });
        </script>
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