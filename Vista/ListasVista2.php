<?php
session_start();
if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    if (in_array("11M", $modulosAcceso) or isset($_GET["op"])) {
        include('head.php');
?>
        <!-- Estilos de la lista de chequeo: botones grandes, barra de avance y firma en pantalla -->
        <link href="../public/css/listas.css?v=1" rel="stylesheet">
        <!-- Contenido aqui va todo el DIV del contenido.. -->

        <!--script type="text/javascript">
            function startTime() {
                today = new Date();
                Y = today.getUTCFullYear();
                M = today.getUTCMonth() + 1;
                M = checkTime(M);
                D = today.getUTCDate();
                D = checkTime(D);
                h = today.getHours();
                m = today.getMinutes();
                s = today.getSeconds();
                m = checkTime(m);
                s = checkTime(s);
                document.getElementById('fechaEncuesta').value = Y + "-" + M + "-" + D + " " + h + ":" + m + ":" + s;
                t = setTimeout('startTime()', 500);
            }

            function checkTime(i) {
                if (i < 10) {
                    i = "0" + i;
                }
                return i;
            }
            window.onload = function() {
                startTime();
            }
        </script-->
        <div class="right_col" role="main">
            <div class="">
                <div class="row">
                    <div class="col-md-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>Listas de chequeo</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <!-- Inicio Formulario -->
                                <div class="panel-body" style="align-content: center;" id="formularioregistros">
                                    <form name="demo-form2" id="demo-form2" method="POST">

                                     <div class="row">
                                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                                <label for="fechaEncuesta">Fecha de diligenciamiento:</label>
                                                <input type="hidden" class="form-control" name="idGrupo" id="idGrupo">
                                                <input type="hidden" class="form-control" name="idLista" id="idLista">
                                                <input class="form-control" name="fechaEncuesta" id="fechaEncuesta" value="" readonly>
                                                <input type="hidden" class="form-control" name="nombreColaborador" id="nombreColaborador" value="<?php echo $_SESSION['Idcolaborador'] ?>">

                                            </div>
                                            <!--div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12">
                                                <label for="nombreColaborador">Nombre del colaborador:</label>
                                                <select class="form-control" style="width: 100%;" id="nombreColaborador" name="nombreColaborador">
                                                    <option value=''>Seleccione un colaborador...</option>
                                                </select>
                                            </div-->
                                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" id="centro1">
                                                <label for="selectCentro">Centro Operativo:</label>
                                                <input class="form-control" name="selectCentro1" id="selectCentro1" value="" readonly>
                                            </div>

                                            <div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" id="centro">
                                                <label for="selectCentro">Centro Operativo:</label>
                                                <select class="form-control" style="width: 100%;" id="selectCentro" name="selectCentro">
                                                    <option value=''>Seleccione un centro operativo...</option>
                                                </select>
                                            </div>
                                        </div>
                                        <!-- Inicio Formulario dinámico background-color: #FFFFFF80 //background-color: #f6d61099; border-radius: 19px;-->
                                        <div class="row" id="formDinamico" style="background-color: #FFFFFF80; border-radius: 5px;">

                                        </div>
                                        <!-- Fin Formulario dinámico -->
                                        <div class="row">
                                            <div class="form-group col-lg-12 col-md-12 col-sm-12 col-xs-12">
                                                <SPAN title="Guardar">
                                                    <button class="btn btn-primary" type="submit" id="btnGuardar">
                                                        <i class="fa fa-save"></i> Guardar
                                                    </button>
                                                </SPAN>
                                                <SPAN title="Cancelar">
                                                    <button class="btn btn-primary" onclick="cancelarform()" type="button">
                                                        <i class="fa fa-arrow-circle-left"></i> Cancelar
                                                    </button>
                                                </SPAN>
                                            </div>
                                        </div>
                                    </form>
                                    <!-- end demo-form2 -->
                                    <!-- Agrega este código en tu archivo HTML -->
                                    <div class="modal" id="respuestaModal" tabindex="-1" role="dialog">
                                        <div class="modal-dialog" role="document">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Editar respuesta</h5>
                                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                        <span aria-hidden="true">&times;</span>
                                                    </button>
                                                </div>
                                                <div class="modal-body">
                                                    <!-- Inicio Formulario dinámico -->
                                                    <form name="modal-form2" id="modal-form2" method="POST">
                                                    <div class="row" id="formModalDinamico" style="background-color: #FFFFFF80">
                                                        
                                                </div></form>
                                                    <!-- Fin Formulario dinámico -->
                                                </div>
                                                <div class="modal-footer">
                                                    <SPAN title="Guardar respuesta">
                                                        <button class="btn btn-primary" type="button" id="btnModalGuardar">
                                                            <i class="fa fa-save"></i> Guardar
                                                        </button>
                                                    </SPAN>
                                                    <SPAN title="Cancelar">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                                                    </SPAN>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                                <!-- Listado Registros  style="text-align : center;"-->
                                <div id="listadoregistros">
                                    <div class="x_panel">
                                        <div class="x_title">
                                            <h2>Listas por responder</h2>

                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="x_content">
                                            <div class="panel-body table-responsive">
                                                <table id="tbllistado" class="table table-striped table-bordered" style="width:100%; text-align:center">
                                                    <thead>
                                                        <th>ID</th>
                                                        <th>NOMBRE DE LISTA</th>
                                                        <th>CANT. DE PREGUNTAS</th>
                                                        <th>OPCIONES</th>
                                                    </thead>
                                                    <tbody>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div><!-- End Listado Registros-->

                                <!-- Listado Respuestas-->
                                <div id="listadoRespuestas">
                                    <div class="x_panel">
                                        <div class="x_title">
                                            <h2 style="margin-right: 60%;">Respuestas guardadas</h2>
                                            <button type="button" class="btn btn-success"  id="btnAtras"><i class="fa fa-arrow-left" aria-hidden="true"></i> Atrás</button>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="x_content">
                                            <div class="panel-body table-responsive">
                                                <table id="tblRespuestas" class="table table-striped table-bordered" style="width:100%">
                                                    <thead>
                                                        <th>ID</th>
                                                        <th>LISTA</th>
                                                        <th>FECHA</th>
                                                        <th>CENTRO OPERATIVO</th>
                                                        <th>RESPONDIÓ</th>
                                                        <th>OPCIÓN</th>
                                                        <th>AVANCE</th>
                                                    </thead>
                                                    <tbody>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div><!-- End Listado Respuestas-->
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
        <style>
            /* Resalta en rojo las preguntas o campos que quedaron sin responder
               al intentar guardar. La clase la pone/quita ListasAjax.js */
            .campo-faltante{
                border: 2px solid #6E1A1E !important;
                border-radius: 10px;
                background-color: #fff5f5 !important;
                animation: parpadeoFaltante .6s ease-in-out 2; /* llama la atencion */
            }
            /* Etiqueta de la pregunta faltante, tambien en rojo */
            .campo-faltante > div:first-child{ background-color: #6E1A1E !important; }

            @keyframes parpadeoFaltante{
                0%   { box-shadow: 0 0 0 0 rgba(217,83,79,.6); }
                50%  { box-shadow: 0 0 0 8px rgba(217,83,79,0); }
                100% { box-shadow: 0 0 0 0 rgba(217,83,79,0); }
            }
        </style>
        <script type="text/javascript" src="../Ajax/ListasAjax.js?v=2"></script>
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