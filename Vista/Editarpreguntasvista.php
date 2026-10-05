<?php
//Pantalla dedicada para editar y reordenar TODAS las preguntas de una lista de chequeo
//Se llega aqui desde el boton "Editar todas las preguntas" de GruposListasVista.php

session_start(); //se necesita la sesion para validar usuario y permisos

//Sin sesion activa se manda al login (al final del archivo)
if (isset($_SESSION['IdUsuarios'])) {

    $modulosAcceso = explode(",", $_SESSION['Modulos']); //modulos autorizados del usuario

    //12M es el modulo de Listas de Chequeo
    if (in_array("12M", $modulosAcceso) or isset($_GET["op"])) {

        include('head.php'); //cabecera comun: menu lateral, CSS, topbar

        //Lista sobre la que se va a trabajar. Se castea a int como proteccion basica
        $idGrupo = isset($_GET['idGrupo']) ? (int)$_GET['idGrupo'] : 0;
?>
        <!-- Contenido de la pagina -->
        <div class="right_col" role="main">
            <div class="">
                <div class="clearfix"></div>
                <div class="row">
                    <div class="col-md-12 col-xs-12">

                        <!-- El borde amarillo arriba avisa visualmente que se esta en modo edicion -->
                        <div class="x_panel" style="border-top:4px solid #D99C2B;">

                            <div class="x_title">
                                <h2>
                                    <i class="fa fa-pencil-square-o"></i>
                                    Editar todas las preguntas
                                    <small id="nombreLista"></small> <!-- lo rellena el JS con el nombre de la lista -->
                                </h2>
                                <span style="float:right">
                                    <!-- Agregar una pregunta nueva sin salir de esta pantalla -->
                                    <button class="btn btn-success" type="button" id="btnNuevaPregunta" onclick="abrirFormPregunta(null)">
                                        <i class="fa fa-plus"></i> Agregar pregunta
                                    </button>
                                    <!-- Vuelve al listado pero reabriendo esta misma lista -->
                                    <button class="btn btn-default" type="button" onclick="volverListas()">
                                        <i class="fa fa-arrow-circle-left"></i> Volver
                                    </button>
                                </span>
                                <div class="clearfix"></div>
                            </div>

                            <div class="x_content">

                                <!-- Formulario para agregar una pregunta, o editarla completa
                                     (incluido si genera novedad y con qué título) -->
                                <div id="panelPregunta" class="panel-pregunta" style="display:none;">
                                    <h4 id="panelPreguntaTitulo">Nueva pregunta</h4>
                                    <input type="hidden" id="fpId" value="">
                                    <div class="row">
                                        <div class="form-group col-md-8 col-xs-12">
                                            <label for="fpPregunta">Pregunta: <span style="color:#6E1A1E;">*</span></label>
                                            <input type="text" id="fpPregunta" class="form-control" maxlength="200"
                                                   placeholder="Ej: ¿El área de trabajo está limpia y ordenada?">
                                        </div>
                                        <div class="form-group col-md-4 col-xs-12">
                                            <label for="fpTipo">Tipo de respuesta: <span style="color:#6E1A1E;">*</span></label>
                                            <select id="fpTipo" class="form-control"></select>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="form-group col-md-4 col-xs-12" id="fpGeneraGrupo">
                                            <label for="fpGenera">¿Genera novedad?</label>
                                            <select id="fpGenera" class="form-control">
                                                <option value="0">No</option>
                                                <option value="1">Sí, si la respuesta es negativa</option>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-4 col-xs-12" id="fpTituloGrupo" style="display:none;">
                                            <label for="fpTitulo">Título de la novedad: <span style="color:#6E1A1E;">*</span></label>
                                            <select id="fpTitulo" class="form-control"></select>
                                        </div>
                                        <div class="form-group col-md-4 col-xs-12" id="fpPosicionGrupo">
                                            <label for="fpPosicion">Ubicación:</label>
                                            <select id="fpPosicion" class="form-control"></select>
                                        </div>
                                    </div>
                                    <div class="panel-pregunta-acciones">
                                        <button type="button" class="btn btn-default" onclick="cerrarFormPregunta()">Cancelar</button>
                                        <button type="button" class="btn btn-success" id="btnGuardarPregunta" onclick="guardarFormPregunta()">
                                            <i class="fa fa-save"></i> Guardar pregunta
                                        </button>
                                    </div>
                                </div>

                                <!-- Instrucciones para el usuario final -->
                                <div class="alert alert-info" style="margin-bottom:15px;">
                                    <i class="fa fa-info-circle"></i>
                                    Mantén presionada la <i class="fa fa-hand-paper-o"></i> y arrastra una pregunta
                                    para cambiar su orden. Cada pregunta <strong>conserva su número actual</strong>
                                    mientras la mueves (por ejemplo: 1, 3, 4, 5, 6, 2), para que sepas de dónde salió.
                                    Al presionar <strong>"Guardar cambios"</strong> se renumeran en orden (1, 2, 3...).
                                </div>

                                <!-- Encabezado de columnas. Va como fila fija y no como <table>
                                     porque las filas de abajo son elementos arrastrables de un <ul> -->
                                <div class="editor-head">
                                    <span class="c-handle"></span> <!-- columna de la manito, sin titulo -->
                                    <span class="c-orden">#</span> <!-- numero de la pregunta; se renumera solo al guardar -->
                                    <span class="c-id">ID</span> <!-- ID real en la BD -->
                                    <span class="c-pregunta">PREGUNTA</span>
                                    <span class="c-tipo">TIPO DE RESPUESTA</span>
                                    <span class="c-accion">ACCIONES</span>
                                </div>

                                <!-- Aqui EditarPreguntasAjax.js inyecta una fila por pregunta -->
                                <ul class="list-group" id="listaEditor"></ul>

                                <div style="margin-top:15px;">
                                    <!-- Guarda el orden actual + todos los textos y tipos editados -->
                                    <button class="btn btn-primary" type="button" id="btnGuardarTodo" onclick="guardarTodasPreguntas()">
                                        <i class="fa fa-save"></i> Guardar cambios
                                    </button>
                                    <button class="btn btn-default" type="button" onclick="volverListas()">
                                        Cancelar
                                    </button>
                                    <!-- Se muestra solo cuando hay reordenamientos sin guardar -->
                                    <span id="avisoPendiente" class="text-danger" style="display:none; margin-left:12px;">
                                        <i class="fa fa-exclamation-triangle"></i> Tienes cambios de orden sin guardar.
                                    </span>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- El JS lee de aqui la lista a editar. Se usa un campo oculto y no una
             variable porque el valor sobrevive a recargas de la pagina -->
        <input type="hidden" id="idGrupoEditar" value="<?php echo $idGrupo; ?>">

        <style>
            /* Formulario de agregar / editar pregunta */
            .panel-pregunta{
                background:#F7F8F9; border:1px solid #E3E6EA; border-left:4px solid #1ABB9C;
                border-radius:8px; padding:14px 16px 6px; margin-bottom:16px;
            }
            .panel-pregunta h4{ color:#6E1A1E; font-weight:bold; margin:0 0 12px; font-size:15px; }
            .panel-pregunta-acciones{ display:flex; justify-content:flex-end; gap:8px; margin-bottom:10px; }
            .btn-completa{ margin-left:4px; }

            /* Encabezado con las mismas medidas que las filas, para que alineen */
            .editor-head{
                display:flex; align-items:center; gap:10px;
                padding:8px 12px; font-size:11px; font-weight:bold;
                color:#888; letter-spacing:.5px; border-bottom:2px solid #e0e0e0;
            }

            /* Cada pregunta es un <li> en linea */
            #listaEditor .editor-item{ display:flex; align-items:center; gap:10px; padding:10px 12px; }

            /* Anchos compartidos entre encabezado y filas para que las columnas cuadren */
            .c-handle,  #listaEditor .handle     { flex:0 0 34px;  text-align:center; }
            .c-orden,   #listaEditor .badge-orden{ flex:0 0 42px;  text-align:center; }
            .c-id,      #listaEditor .txt-id     { flex:0 0 60px;  text-align:center; }
            .c-pregunta,#listaEditor .in-pregunta{ flex:1 1 auto; } /* el texto ocupa lo que sobre */
            .c-tipo,    #listaEditor .in-tipo    { flex:0 0 170px; }
            .c-accion,  #listaEditor .btn-una    { flex:0 0 60px;  text-align:center; }

            /* Cursor de manito abierta, y de puño cerrado mientras se arrastra */
            #listaEditor .handle{ cursor:grab; color:#888; font-size:20px; user-select:none; }
            #listaEditor .handle:active{ cursor:grabbing; color:#D99C2B; }

            /* Insignia con la posicion, recalculada en vivo por el JS */
            #listaEditor .badge-orden{
                background:#5A738E; color:#fff; border-radius:12px;
                padding:3px 0; font-weight:bold; font-size:12px;
            }

            #listaEditor .txt-id{ color:#999; font-size:12px; } /* el ID es solo informativo */

            #listaEditor .editor-item.dragging{ opacity:.4; background:#fcf8e3; } /* fila mientras se arrastra */
            #listaEditor .editor-item.movida{ background:#fffaf0; } /* fila movida y aun sin guardar */
        </style>

        <?php
        include('footer.php'); //pie comun: carga jQuery, Bootstrap, etc.
        ?>

        <!-- Logica de esta pantalla. El ?v= obliga al navegador a bajar la version
             nueva y no una vieja guardada en cache -->
        <script type="text/javascript" src="../Ajax/Editarpreguntasajax.js?v=7"></script>
<?php
    } else {
        //Hay sesion pero el usuario no tiene el modulo: se devuelve a la pagina anterior
        echo "<script>
  window.history.go(-1)
  </script>";
    }
} else {
    //No hay sesion: al login
    echo "<script>
  <!--
  window.location.replace('login.php');
  //-->
  </script>";
}
?>