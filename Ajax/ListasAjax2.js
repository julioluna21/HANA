var tabla;//variable global
var scriptActivo = true; //script para fecha y hora

//Función para guardar o editar la lista de respuestas
function guardar(e) {
    e.preventDefault(); //No se activará la acción predeterminada del evento
    $("#btnGuardar").prop("disabled", true);
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
        url: "../Control/ListasControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        success: function (datos) {
            mostrarform(false);
            tabla.ajax.reload(); 
            setTimeout(() => {
                alert("Registro realizado con exito")
            }, 200);
        },
        errro: function (error) {
            console.error(error)
        }
    });
    limpiar();
}
//Función para editar respuestas
function guardarEditarRespuesta(e) {
    idLista = $("#idLista").val();
    var formData = new FormData($("#modal-form2")[0]);
    $.ajax({
        url: "../Control/ListasControl.php?op=editarUnaRespuesta",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        success: function (datos) {
            $('#respuestaModal').modal('hide');
            mostrarRespuestas(idLista);
            setTimeout(() => {
                alert("Registro realizado con exito")
            }, 200);
        },
        errro: function (error) {
            console.error(error)
        }
    });
    limpiar();
}
//muestra preguntas ok
function mostrarPreguntas(idGrupo) {
    var date = new Date();
    var fechaEncuesta = date.toISOString().split('T')[0];
    var nombreColaborador = $("#nombreColaborador").val();
    $.post("../Control/ListasControl.php?op=mostrarPreguntas", { idGrupo: idGrupo }, function (data, textStatus, xhr) {
        data = JSON.parse(data);
        //console.log('data',data)
        mostrarform(true);
        setTimeout(() => {
            generateDynamicForm(data);
        }, 200);


    });
}
//muestra respuestas ok
function mostrarRespuestas(idLista) {
    $("#btnGuardar").hide();
    $.post("../Control/ListasControl.php?op=mostrarRespuestas", {idLista: idLista }, function (data, textStatus, xhr) {
       data = JSON.parse(data);
        mostrarform(true);
        setTimeout(() => {
            mostrarRespuestasForm(data);
        }, 200);
    });
}
//GENERA FORM PREGUNTAS ok
function generateDynamicForm(data) {
    var formContainer = $('#formDinamico');
    formContainer.empty();
    $("#centro1").hide();
    var idGrupo = data[0].ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO;
    $("#idGrupo").val(idGrupo);
    var groupHtml = '<div class="row"><div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" style="margin-left: 3%;"><h3>' + data[0].lista + '</h3></div></div><div class="col-12" style="margin: 1.2%;width: 95%;height: 3px;background-color: #a4a0a0;"></div>';

    formContainer.append(groupHtml);
    // Iterar sobre las preguntas y tipos de respuesta border: 2px inset #76797c;
    $.each(data, function (index, question) {
        nPregunta = index+1;
        var questionHtml = '<div class="form-group col-lg-10 col-md-10 col-sm-10 col-xs-10" style="box-shadow: 5px 5px 11px 0px #425e4f;margin-left: 2%; border-radius: 10px; background-color: #fff;">';//background-color: #f0f5f7c4; 
        questionHtml += '<div   style="background-color: #5395a8; color: white;border-radius: 5px;"><label>No.' +nPregunta+"   "+ question.PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO + '</label></div>';
        if (question.TIPO_RESPUESTA === 'si/no') {
            questionHtml += '<div class="form-radio form-radio-inline"><input class="form-radio-input" type="radio" name="answer_' + question.ID_DETALLE_GRUPO_LISTA_CHEQUEO + '" value="1"> Sí</div>';
            questionHtml += '<div class="form-radio form-radio-inline"><input class="form-radio-input" type="radio" name="answer_' + question.ID_DETALLE_GRUPO_LISTA_CHEQUEO + '" value="2"> No</div>';
            questionHtml += '<div class="form-radio form-radio-inline"><input class="form-radio-input" type="radio" name="answer_' + question.ID_DETALLE_GRUPO_LISTA_CHEQUEO + '" value="3"> No Aplica</div>';
            // Agregar evento de cambio para desmarcar el otro radiobox
questionHtml += '<script>';
questionHtml += '$(".form-radio-input[name=answer_' + question.ID_PREGUNTA + ']").on("change", function() {';
questionHtml += '$(".form-radio-input[name=answer_' + question.ID_PREGUNTA + ']").not(this).prop("checked", false);';
questionHtml += '});';
questionHtml += '</script>';
        } else if (question.TIPO_RESPUESTA === 'lista') {
            questionHtml += '<select class="form-control" name="answer_' + question.ID_DETALLE_GRUPO_LISTA_CHEQUEO + '">';
            // Aquí puedes agregar opciones desde una consulta
            questionHtml += '<option value="">Seleccione...</option>';
            questionHtml += '<option value="Bueno">Bueno</option>';
            questionHtml += '<option value="regular">regular</option>';
            questionHtml += '<option value="Malo">Malo</option>';
            questionHtml += '<option value="No Aplica">No Aplica</option>';
            questionHtml += '</select>';
        } else if (question.TIPO_RESPUESTA === 'texto') {
            questionHtml += '<input class="form-control" type="text" name="answer_' + question.ID_DETALLE_GRUPO_LISTA_CHEQUEO + '">';
        } /*else if (question.TIPO_RESPUESTA === 'multiselect') {
            // Puedes adaptar esta sección para manejar selección múltiple
            questionHtml += '<select class="form-control" name="answer_' + question.ID_DETALLE_GRUPO_LISTA_CHEQUEO + '" multiple>';
            // Opciones desde una consulta
            questionHtml += '<option value="Bueno">Bueno</option>';
            questionHtml += '<option value="regula">regular</option>';
            questionHtml += '<option value="Malo">Malo</option>';
            questionHtml += '</select>';
        }*/

        questionHtml += '</div>';
        formContainer.append(questionHtml);

    });
}
function selectCentroId(sel) {
    //console.log("sel: ",sel)
    /**
    permite preseleccionar un select2
    */

    $('#selectCentro').select2({
        ajax: {
            url: '../Control/ListasControl.php'
        }
    });
    // Fetch the preselected item, and add to the control
    var studentSelect = $('#selectCentro');
    $.ajax({
        type: 'GET',
        url: '../Control/ListasControl.php?op=selectCentro&search=' + sel
    }).then(function (data) {
        data = JSON.parse(data);
        //console.log(data[0].id)
        // create the option and append to Select2
        var option = new Option(data[0].text, data[0].id, true, true);
        studentSelect.append(option).trigger('change');

        // manually trigger the `select2:select` event
        studentSelect.trigger({
            type: 'select2:select',
            params: {
                data: data
            }
        });
    });
}

//Genera inputs ok
function mostrarRespuestasForm(data) {
    //console.log(data)
    $("#listadoRespuestas").hide();
    date = new Date();
    hoy = date.toISOString().split('T')[0];
    var colaborador = data.find(item => item.ID_COLABORADOR).ID_COLABORADOR;
    var lista = data.find(item => item.ID_LISTA).ID_LISTA;
    var grupo = data.find(item => item.ID_GRUPO).ID_GRUPO;
    var fechaLista = data.find(item => item.FECHA).FECHA;
    var centro = data.find(item => item.centro).centro;
    $("#fechaEncuesta").val(fechaLista);
    //fechaLista = data[0].FECHA;
    fechaLista = fechaLista.substring(0,10);
    //console.log(hoy+", "+fechaLista);
    scriptActivo = false;
    $("#idGrupo").val(grupo);
    $("#idLista").val(lista);
    $("#nombreColaborador").val(colaborador);
    $("#selectCentro1").val(centro);
    var formContainer = $('#formDinamico');
    formContainer.empty();
    $("#centro").hide();
    $("#centro1").show();
    var groupHtml = '<div class="row"><div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" style="margin-left: 3%;"><h3>' + data[0].NOM_GRUPO + '</h3></div></div><div class="col-12" style="margin: 1.2%;width: 95%;height: 3px;background-color: #a4a0a0;"></div>';

    formContainer.append(groupHtml);
    // Iterar sobre las preguntas y tipos de respuesta
    $.each(data, function (index, question) {
        questionRespuesta = question.RESPUESTA;
        nPregunta = index+1;
        //console.log(nPregunta)
        //console.log(question.ID_RESPUESTA)background-color: #bcd4db;
        //console.log(questionRespuesta);
        var questionHtml = '<div class="row">';
        questionHtml += '<div class="form-group col-lg-9 col-md-9 col-sm-9 col-xs-9" style="box-shadow: 5px 5px 11px 0px #425e4f;margin-left: 2%; border-radius: 10px; background-color: #fff;">';//background-color: #f0f5f7c4; 
        questionHtml += '<div   style="    background-color: #5395a8; color: white;border-radius: 5px;"><label>No.' +nPregunta+"   "+ question.PREGUNTA + '</label></div>';
        if (question.TIPO_RESPUESTA === 'si/no') {
            questionHtml += '<div class="form-radio form-radio-inline" ><input class="form-radio-input" style="margin-bottom: 1%;" type="radio" name="answer_' + question.ID_PREGUNTA + '" value="1"' + (questionRespuesta
                == 1 ? 'checked' : '') + ' disabled> Sí</div>';
            questionHtml += '<div class="form-radio form-radio-inline"><input class="form-radio-input" style="margin-bottom: 1%;" type="radio" name="answer_' + question.ID_PREGUNTA + '" value="2"' + (questionRespuesta
                == 2 ? 'checked' : '') + ' disabled> No</div>';
                questionHtml += '<div class="form-radio form-radio-inline"><input class="form-radio-input" style="margin-bottom: 1%;" type="radio" name="answer_' + question.ID_PREGUNTA + '" value="3"' + (questionRespuesta
                    == 3 ? 'checked' : '') + ' disabled> No Aplica</div>';
                    // Agregar evento de cambio para desmarcar el otro radiobox
        questionHtml += '<script>';
        questionHtml += '$(".form-radio-input[name=answer_' + question.ID_PREGUNTA + ']").on("change", function() {';
        questionHtml += '$(".form-radio-input[name=answer_' + question.ID_PREGUNTA + ']").not(this).prop("checked", false);';
        questionHtml += '});';
        questionHtml += '</script>';
        } else if (question.TIPO_RESPUESTA === 'lista') {
            questionHtml += '<select class="form-control" style="margin-bottom: 1%;" name="answer_' + question.ID_PREGUNTA + '" disabled>';
            questionHtml += '<option value="">Seleccione...</option>';
            questionHtml += '<option value="Bueno" ' + (questionRespuesta == 'Bueno' ? 'selected' : '') + '>Bueno</option>';
            questionHtml += '<option value="Regular" ' + (questionRespuesta == 'Regular' ? 'selected' : '') + '>Regular</option>';
            questionHtml += '<option value="Malo" ' + (questionRespuesta == 'Malo' ? 'selected' : '') + '>Malo</option>';
            questionHtml += '<option value="No Aplica" ' + (questionRespuesta == 'No Aplica' ? 'selected' : '') + '>No Aplica</option>';
            questionHtml += '</select>';
        } else if (question.TIPO_RESPUESTA === 'texto') {
            questionHtml += '<input class="form-control" style="margin-bottom: 1%;" type="text" name="answer_' + question.ID_PREGUNTA + '" value="' + questionRespuesta + '" readonly>';
        }
        questionHtml += '</div>';
        if(fechaLista == hoy){            
        questionHtml += '<div class="form-group col-lg-2 col-md-2 col-sm-2 col-xs-2" style="margin: 1%;"><SPAN title="Guardar Cambios"><button class="btn btn-success btnEditaRespuesta" type="button" style="height: 50px;width: 50px;" data-toggle="modal" data-target="#respuestaModal" data-idpregunta="' + question.ID_PREGUNTA + '" data-pregunta="' + question.PREGUNTA + '" data-idlista="' + lista + '" data-respuesta="' + questionRespuesta + '" data-idrespuesta="' + question.ID_RESPUESTA + '" data-tipo="' + question.TIPO_RESPUESTA + '" data-grupo="' + data[0].NOM_GRUPO + '" ><i class="fa fa-save"></i></button></SPAN></div>';
        }
        questionHtml += '</div>';
        // Agrega el evento de clic al botón "btnEditaRespuesta"
        formContainer.append(questionHtml);

        // Agrega un evento de clic al botón del modal para mostrar la pregunta y respuesta
        $('.btnEditaRespuesta').on('click', function () {
            var grupo = $(this).data('grupo');
            var idLista = $(this).data('idlista');
            var idPregunta = $(this).data('idpregunta');
            var pregunta = $(this).data('pregunta');
            var idRespuesta = $(this).data('idrespuesta');
            var respuesta = $(this).data('respuesta');
            var tipo = $(this).data('tipo');
            var fomModalContainer = $('#formModalDinamico');
            fomModalContainer.empty();
            var groupHtml = '<div class="row"><div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" style="margin-left: 3%;"><h3>' + grupo + '</h3></div></div><div class="col-12" style="margin: 1.2%;width: 95%;height: 3px;background-color: #a4a0a0;"></div>';

            fomModalContainer.append(groupHtml);
            //console.log(index)
            //console.log(question)
            var questionModalHtml = '<div class="row">';
            questionModalHtml += '<div class="form-group col-lg-11 col-md-11 col-sm-11 col-xs-11" style="box-shadow: 5px 5px 11px 0px #425e4f;margin-left: 2%; border-radius: 10px; background-color: #fff;">';// background-color: #f0f5f7c4;
            questionModalHtml += '<div   style="    background-color: #5395a8; color: white;border-radius: 5px;"><label>' + pregunta + '</label><input type="hidden" class="form-control" name="idLista" id="idLista" value="' + idLista + '"><input type="hidden" class="form-control" name="idPregunta" id="idPregunta" value="' + idPregunta + '"><input type="hidden" class="form-control" name="idRespuesta" id="idRespuesta" value="' + idRespuesta + '"></div>';
            if (tipo === 'si/no') {
                questionModalHtml += '<div class="form-radio form-radio-inline"><input class="form-radio-input" style="margin-bottom: 1%;" type="radio" name="answer_' + idPregunta + '" value="1"' + (respuesta
                    == 1 ? 'checked' : '') + '> Sí</div>';
                questionModalHtml += '<div class="form-radio form-radio-inline"><input class="form-radio-input" style="margin-bottom: 1%;" type="radio" name="answer_' + idPregunta + '" value="2"' + (respuesta
                    == 2 ? 'checked' : '') + '> No</div>';
                    questionModalHtml += '<div class="form-radio form-radio-inline"><input class="form-radio-input" style="margin-bottom: 1%;" type="radio" name="answer_' + idPregunta + '" value="3"' + (respuesta
                        == 3 ? 'checked' : '') + '> No Aplica</div>';
                    // Agregar evento de cambio para desmarcar el otro checkbox
       questionModalHtml += '<script>';
       questionModalHtml += '$(".form-radio-input[name=answer_' + idPregunta + ']").on("change", function() {';
       questionModalHtml += '$(".form-radio-input[name=answer_' + idPregunta + ']").not(this).prop("checked", false);';
       questionModalHtml += '});';
       questionModalHtml += '</script>';
            } else if (tipo === 'lista') {
                questionModalHtml += '<select class="form-control" style="margin-bottom: 1%;" name="answer_' + idPregunta + '">';
                questionModalHtml += '<option value="">Seleccione...</option>';
                questionModalHtml += '<option value="Bueno" ' + (respuesta == 'Bueno' ? 'selected' : '') + '>Bueno</option>';
                questionModalHtml += '<option value="Regular" ' + (respuesta == 'Regular' ? 'selected' : '') + '>Regular</option>';
                questionModalHtml += '<option value="Malo" ' + (respuesta == 'Malo' ? 'selected' : '') + '>Malo</option>';
                questionModalHtml += '<option value="No Aplica" ' + (respuesta == 'No Aplica' ? 'selected' : '') + '>No Aplica</option>';
                questionModalHtml += '</select>';
            } else if (tipo === 'texto') {
                questionModalHtml += '<input class="form-control" style="margin-bottom: 1%;" type="text" name="answer_' + idPregunta + '" value="' + respuesta + '">';
            }
            questionModalHtml += '</div></div>';
            // Agrega el evento de clic al botón "btnEditaRespuesta"
            fomModalContainer.append(questionModalHtml);
        });
    });
}
function checkTime(i) {//función para anteponer cero a mes, dia, minutos y segundos
    if (i < 10) {
        i = "0" + i;
    }
    return i;
}
function controlarStartTime() {//agrega fecha hora en real time
    if (scriptActivo) {
        // Tu código original de startTime aquí
        today = new Date();
        Y = today.getFullYear();
        M = today.getMonth() + 1;
        M = checkTime(M);
        D = today.getDate();
        D = checkTime(D);
        h = today.getHours();
        m = today.getMinutes();
        s = today.getSeconds();
        m = checkTime(m);
        s = checkTime(s);
        $('#fechaEncuesta').val(Y + "-" + M + "-" + D + " " + h + ":" + m + ":" + s);
        t = setTimeout(controlarStartTime, 500);
    }
}
function anular(idLista) {

    if (confirm("Desea anular este registro?")) {
        $.post("../Control/ListasControl.php?op=anular", { idLista: idLista }, function (data) {
            tabla.ajax.reload();
            setTimeout(() => {
                alert("Registro anulado con exito")
            }, 200);
        });
    }

}
function activar(idLista) {
    bootbox.confirm({
        message: "Desea activar este registro?",
        buttons: {
            confirm: {
                label: 'SI'
            },
            cancel: {
                label: 'NO'
            }
        },
        callback: function (result) {
            if (result) {
                $.post("../Control/ListasControl.php?op=activar", { idLista: idLista }, function (data) {
                    bootbox.alert({
                        title: 'Activado!',
                        message: data,
                        size: 'small',
                        closeButton: false
                    });

                    setTimeout(() => {
                        bootbox.hideAll()
                    }, 1500);
                    tabla.ajax.reload();

                });
            }
        }
    });

}
function cancelarform() {
    limpiar();
    mostrarform(false);

    //location.reload();
}
function limpiar() {
    $("#idLista").val("");
    $("#idGrupo").val("");
    $("#nombre").val("");    
    $('#selectCentro').val('').trigger('change');
}
//funcion ok
function selectColaborador() {
    $("#nombreColaborador").select2({
        ajax: {
            url: '../Control/ListasControl.php?op=selectColaborador',
            dataType: 'json',
            delay: 150,
            data: function (params) {
                var query = {
                    search: params.term
                }
                return query;
            },
            processResults: function (data) {
                return {
                    results: $.map(data, function (obj) {
                        return {
                            id: obj.id,
                            text: obj.text
                        };
                    })
                };
            }
        },
        lenguage: 'es',
        cache: true,
        placeholder: 'Seleccione un colaborador...',
        allowClear: true
    });
}
//funcion ok
function selectCentro() {
    $("#selectCentro").select2({
        ajax: {
            url: '../Control/ListasControl.php?op=selectCentro',
            dataType: 'json',
            delay: 150,
            data: function (params) {
                var query = {
                    search: params.term
                }
                return query;
            },
            processResults: function (data) {
                return {
                    results: $.map(data, function (obj) {
                        return {
                            id: obj.id,
                            text: obj.text
                        };
                    })
                };
            }
        },
        lenguage: 'es',
        cache: true,
        placeholder: 'Seleccione un centro operativo...',
        allowClear: true
    });
}
//funcion ok
function mostrarform(flag) {
    //limpia el formulario
    if (flag) {
        // $('#myModal').modal('show');
        $("#demo-form2").show();

        scriptActivo = true;
        controlarStartTime();
        //selectColaborador();
        selectCentro();
        $("#listadoregistros").hide();
        $("#btnGuardar").prop("disabled", false);
    }
    else {
        limpiar();
        $("#demo-form2").hide();
        $("#listadoregistros").show();
        $("#listadoRespuestas").hide();
        $("#btnGuardar").prop("disabled", false);


    }
}
//Función Listar grupos ok
function listar() {
    tabla = $('#tbllistado').dataTable(//Carga variable con datos datatable
        {
            "aProcessing": true,//Activamos el procesamiento del datatables
            "aServerSide": true,//Paginación y filtrado realizados por el servidor
            dom: 'Bfrtip',//Definimos los elementos del control de tabla Bfrtip
            buttons: [

            ],
            "ajax"://metodo ajax
            {
                url: '../Control/ListasControl.php?op=listar',//pagina que realiza la operación
                type: "get",//tipo de envio de datos
                dataType: "json",//tipo de datos
                error: function (e) {//si error muestra mensaje
                    console.log(e.responseText);
                }/*,
                complete: function(data){
                  console.log(data)
                }*/
            },
            "bDestroy": true,
            "iDisplayLength": 10,//Paginación
            "order": [[0, "asc"]]
        }).DataTable();

}
//Función ok
function listarPreguntas(idGrupoPreguntas) {
    var idGrupoPreguntas = { 'idGrupoPreguntas': idGrupoPreguntas };
    tabla = $('#tblPreguntas').dataTable(//Carga variable con datos datatable
        {
            "aProcessing": true,//Activamos el procesamiento del datatables
            "aServerSide": true,//Paginación y filtrado realizados por el servidor
            dom: 'Bfrtip',//Definimos los elementos del control de tabla Bfrtip

            buttons: [

            ],
            "ajax"://metodo ajax
            {
                url: '../Control/PreguntasListasControl.php?op=listar',//pagina que realiza la operación
                type: "post",//tipo de envio de datos
                data: idGrupoPreguntas,
                dataType: "json",//tipo de datos
                error: function (e) {//si error muestra mensaje
                    console.log(e.responseText);
                }
            },
            "bDestroy": true,
            "iDisplayLength": 10,//Paginación
            "order": [[0, "asc"]]//Ordenar (columna,orden)
        }).DataTable();

}
//Función ok
function listarRespuestas(idGrupo) {

    $("#listadoregistros").hide();
    $("#listadoRespuestas").show();
    var idGrupo = { 'idGrupo': idGrupo };
    tabla = $('#tblRespuestas').dataTable(//Carga variable con datos datatable
        {
            "aProcessing": true,//Activamos el procesamiento del datatables
            "aServerSide": true,//Paginación y filtrado realizados por el servidor
            dom: 'Bfrtip',//Definimos los elementos del control de tabla Bfrtip
            buttons: [],
            "ajax"://metodo ajax
            {
                url: '../Control/ListasControl.php?op=listarPorCentro',//listarRespuestas',//pagina que realiza la operación
                type: "post",//tipo de envio de datos
                data: idGrupo,
                dataType: "json",//tipo de datos
                error: function (e) {//si error muestra mensaje
                    console.log(e.responseText);
                }
            },
            "bDestroy": true,
            "iDisplayLength": 10,//Paginación
            "order": [[0, "asc"]]//Ordenar (columna,orden)
        }).DataTable();

}
function agregarPregunta(e) {
    e.preventDefault();
    var formData1 = new FormData();
    formData1.append('idGrupo', $("#idGrupo").val());
    formData1.append('idPregunta', $("#idPregunta").val());
    formData1.append('pregunta', $("#pregunta").val());
    formData1.append('tipo-respuesta', $("#tipo-respuesta").val());
    $.ajax({
        url: "../Control/PreguntasListasControl.php?op=guardar",
        type: "POST",
        data: formData1,
        contentType: false,
        processData: false,
        success: function (datos) {
            //console.log("datos ", datos)
            $('#modal-preguntas').modal('hide');
            tabla.ajax.reload();
            /*$("#listaPreguntas").empty();
            $('#myModal').modal('hide');
            tabla.ajax.reload();
            $(location).attr('href','../Vista/index.html');   
            */setTimeout(() => {
                alert("Registro realizado con exito")
            }, 200);
        }
    });
    /*/ Variable global para almacenar el índice del elemento en edición
    var indiceEnEdicion = -1;
    $("#pregunta").focus();
    // Obtiene los valores de los campos del formulario.
    const pregunta = $("#pregunta").val();
    const tipoRespuesta = $("#tipo-respuesta").val();
    // Agrega a la lista
    $("#listaPreguntas").append("<li class='list-group-item' style='font-size: 16px'>Pregunta: <span class='pregunta'>" + pregunta + "</span>, Tipo de respuesta: <span class='tipoRespuesta'>" + tipoRespuesta + "</span> <button class='eliminarBtn btn-success rounded-circle'><i class='fa fa-trash' style='color:white;'></i></button></li>");
    //$("#listaPreguntas").append("<input class='form-control pregunta' style='font-size: 18px' value=" + pregunta + "></input><input class='form-control tipoRespuesta' style='font-size: 18px' value=" + tipoRespuesta + "></input> <button class='eliminarBtn btn-success'><i class='fa fa-trash' style='color:white;'></i></button>");
    // Limpia el formulario y cierra el modal
    $("#pregunta").val("");
    $("#pregunta").focus();
    
    // Agregar evento para eliminar elementos al hacer clic en el botón "Eliminar"
    $("#listaPreguntas").on("click", ".eliminarBtn", function () {
        $(this).closest("li").remove();
    });*/

}
function init() {
    mostrarform(false)
    listar();//lista OK
    //al oprimir el boton del formulario
    $("#demo-form2").on("submit", function (e)//e = variable que contiene el objeto
    {
        guardar(e);//guarda o edita el articulo
    });
    //al oprimir el boton del formulario
    $("#btnModalGuardar").on("click", function (e)//e = variable que contiene el objeto
    {
        guardarEditarRespuesta(e);//guarda o edita el articulo
    });
    // Agrega un evento al botón "Agregar pregunta".
    $("#btn-agregar-pregunta").on("click", function (e) {
        agregarPregunta(e);
    });
    // Agrega un evento al botón "Agregar pregunta".
    $("#btnAtras").on("click", function (e) {
        $(location).attr('href', '../Vista/ListasVista.php');
    });
    // Agrega un evento al botón "Agregar pregunta" NO SE USA.
    $('.btnEditaRespuesta').on('click', function () {
        var pregunta = $(this).data('pregunta');
        var respuesta = $(this).data('respuesta');
        $('#preguntaRespuestaTexto').text('Pregunta: ' + pregunta + ' | Respuesta: ' + respuesta);
    });
    //captura la variable grupos para add al modal form
    $('#modal-preguntas').on('show.bs.modal', function (event) {
        //e.preventDefault;
        var button = $(event.relatedTarget);
        var miVariable = button.data('idgrupo'); // Extraer el valor de data-mi-variable
        // Actualizar el contenido del modal con la variable
        var modal = $(this);
        modal.find('#idGrupo').val(miVariable);
    });
    $("#tblRespuestas").on("mouseover", ".imagen-avance", function () {
        //console.log("hover")
        $(this).css("transform", "scale(1.5)");
    });
    $("#tblRespuestas").on("mouseout", ".imagen-avance", function () {
        //console.log("hoverout")
        $(this).css("transform", "scale(1)");
    });

    $("#selectCentro").on("change", function (params) {
        var selectCentro = $(this).val();
        var idGrupo = $("#idGrupo").val();
        var date = new Date();
        var fechaEncuesta = date.toISOString().split('T')[0];
        $.post("../Control/ListasControl.php?op=validaListaIniciada", { idGrupo: idGrupo, selectCentro: selectCentro, fechaEncuesta: fechaEncuesta }, function (params) {
            data = JSON.parse(params);
            if (data) {
                alert("Ya tienes una lista creada para la estación y fecha seleccionada.\n Puedes verla y editarla en la opción 'Mostrar Respuestas'");
                mostrarform(false);
                //$("#selectCentro").attr("readonly", true)
                //$("#demo-form2").hide();
                //listarRespuestas(idGrupo);

            }
        });
    });
}
init();