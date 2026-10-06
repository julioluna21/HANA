var tabla;//variable global
var scriptActivo = true; //script para fecha y hora
var diaListaFecha = '';   //día de la lista nueva: '' es hoy; si no, un día habilitado (AAAA-MM-DD)
var diasLista = { hoy: '', habilitados: [] }; //lo que responde el servidor: hoy y los días que le habilitaron

//Función para guardar o editar la lista de respuestas
//Previsualiza la imagen de firma seleccionada
function previsualizarFirma(input) {
    var img = input.nextElementSibling;
    if (img && input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) { img.src = e.target.result; img.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
    } else if (img) {
        img.src = ''; img.style.display = 'none';
    }
}

//Marca visualmente un campo que quedo sin responder
function marcarFaltante(elemento) {
    //Se resalta el recuadro completo de la pregunta, no solo el input
    var caja = $(elemento).closest('.form-group');
    if (caja.length) { caja.addClass('campo-faltante'); }
}

//Limpia los resaltados de una validacion anterior
function limpiarFaltantes() {
    $('.campo-faltante').removeClass('campo-faltante');
}

//Revisa que el Centro Operativo y TODAS las preguntas esten respondidas
//Devuelve true si esta completo, false si falta algo
function validarFormulario() {
    limpiarFaltantes(); //se parte de cero en cada intento

    var faltantes = []; //aqui se va guardando el nombre de lo que falta

    //1) Centro Operativo. Solo se exige cuando el <select> esta visible,
    //porque al consultar un registro ya guardado se muestra #centro1 (solo lectura)
    if ($("#centro").is(":visible")) {
        var centro = $("#selectCentro").val();
        if (!centro || $.trim(centro) === "") {
            marcarFaltante($("#selectCentro"));
            faltantes.push("Centro Operativo");
        }
    }

    //2) Preguntas. Todas las respuestas se llaman answer_<ID>, asi que primero
    //se juntan los nombres distintos (los radio comparten nombre entre si)
    var nombres = [];
    $("#demo-form2 [name^='answer_']").each(function () {
        var n = $(this).attr('name');
        if (nombres.indexOf(n) === -1) { nombres.push(n); }
    });

    $.each(nombres, function (i, nombre) {
        var campos = $("#demo-form2 [name='" + nombre + "']"); //puede ser 1 campo o varios radios
        var primero = campos.first();
        //Se usa la misma funcion que alimenta la barra de avance, para que
        //lo que la barra cuenta como respondido sea exactamente lo que la validacion acepta
        var respondido = estaRespondida(nombre);

        if (!respondido) {
            marcarFaltante(primero);

            //Se toma el numero que aparece en la etiqueta ("No.3 ...") para el aviso
            var etiqueta = primero.closest('.form-group').find('label').first().text();
            var num = etiqueta.match(/No\.\s*(\d+)/); //captura el numero de la pregunta
            faltantes.push(num ? ("Pregunta No." + num[1]) : "Una pregunta");
        }
    });

    //Todo lleno: se deja continuar
    if (faltantes.length === 0) { return true; }

    //Falta algo: se lleva al usuario al primer campo pendiente
    var primerFaltante = $('.campo-faltante').first();
    if (primerFaltante.length) {
        $('html, body').animate({ scrollTop: primerFaltante.offset().top - 120 }, 300);
    }

    //Mensaje con el detalle. Se listan maximo 10 para que no quede gigante
    var detalle = faltantes.slice(0, 10).join("\n- ");
    var extra = (faltantes.length > 10) ? ("\n... y " + (faltantes.length - 10) + " m\u00e1s.") : "";

    alert("Faltan " + faltantes.length +
          " campo(s) por diligenciar:\n\n- " + detalle + extra);

    return false;
}

function guardar(e) {
    e.preventDefault(); //No se activará la acción predeterminada del evento

    //Antes de enviar nada se revisa que el formulario este completo.
    //Si falta algo se corta aqui y NO se guarda ni se deshabilita el boton
    if (!validarFormulario()) { return; }

    hanaBoton("#btnGuardar", true); //bloquea el botón y muestra "Guardando..." mientras responde el servidor
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
        url: "../Control/ListasControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        success: function (datos) {
            var terminar = function (mensaje) {
                limpiarBorrador(); //la lista ya quedó en el servidor, el borrador sobra
                limpiar(); //se limpia solo cuando la lista de verdad quedó guardada
                mostrarform(false);
                tabla.ajax.reload();
                alert(mensaje);
            };
            //Si eligió archivos mientras llenaba la lista, se suben ya, colgados de la lista nueva
            var r = null; try { r = typeof datos === 'string' ? JSON.parse(datos) : datos; } catch (x) { r = null; }
            if (adjnPendientes.length && r && r.idLista) {
                adjnSubir(r.idLista, function (msg) { terminar(msg); });
            } else {
                terminar("Lista guardada con éxito");
            }
        },
        //Antes decia "errro" en vez de "error", asi que este bloque nunca se ejecutaba:
        //si el guardado fallaba, igual aparecia el mensaje de exito y se perdia lo respondido
        error: function (xhr) {
            hanaErrorAjax(xhr, "No se pudo guardar la lista. Lo que respondiste sigue en pantalla, revisa e intenta de nuevo.");
        },
        complete: function () {
            hanaBoton("#btnGuardar", false); //pase lo que pase, el botón vuelve a quedar disponible
        }
    });
}
//Función para editar respuestas
function guardarEditarRespuesta(e) {
    idLista = $("#idLista").val();
    hanaBoton("#btnModalGuardar", true);
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
            alert("Respuesta guardada con éxito");
        },
        //Antes decia "errro" en vez de "error" y el fallo pasaba inadvertido
        error: function (xhr) {
            hanaErrorAjax(xhr, "No se pudo guardar la respuesta.");
        },
        complete: function () {
            hanaBoton("#btnModalGuardar", false);
        }
    });
}
//muestra preguntas ok
function mostrarPreguntas(idGrupo) {
    var nombreColaborador = $("#nombreColaborador").val();
    hanaCargando(true, "Cargando las preguntas..."); //aviso mientras llegan las preguntas
    $.post("../Control/ListasControl.php?op=mostrarPreguntas", { idGrupo: idGrupo }, function (data, textStatus, xhr) {
        hanaCargando(false);
        data = JSON.parse(data);
        mostrarform(true);
        generateDynamicForm(data); //antes se esperaba 200 ms de más para dibujar el formulario
    })
    .fail(function (xhr) {
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar las preguntas de la lista.");
    });
}
//muestra respuestas ok
function mostrarRespuestas(idLista) {
    $("#btnGuardar").hide();
    hanaCargando(true, "Cargando las respuestas..."); //aviso mientras llegan las respuestas
    $.post("../Control/ListasControl.php?op=mostrarRespuestas", {idLista: idLista }, function (data, textStatus, xhr) {
        hanaCargando(false);
        data = JSON.parse(data);
        mostrarform(true);
        mostrarRespuestasForm(data); //antes se esperaba 200 ms de más para dibujar el formulario
    })
    .fail(function (xhr) {
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar las respuestas.");
    });
}
//GENERA FORM PREGUNTAS ok
function generateDynamicForm(data) {
    var formContainer = $('#formDinamico');
    formContainer.empty();
    $("#centro1").hide();
    var idGrupo = data[0].ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO;
    $("#idGrupo").val(idGrupo);

    //Encabezado con el nombre de la lista
    var groupHtml = '<div class="row"><div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" style="margin-left: 3%;"><h3>' + data[0].lista + '</h3></div></div><div class="col-12" style="margin: 1.2%;width: 95%;height: 3px;background-color: #E3E6EA;"></div>';
    formContainer.append(groupHtml);

    //Barra de avance: se queda fija arriba para ver cuanto falta sin bajar hasta el final
    formContainer.append(
        '<div class="hana-progreso" id="hanaProgreso">' +
        '  <div class="hana-progreso-texto"><span id="hanaProgresoTexto">0 de 0 respondidas</span>' +
        '      <span id="hanaProgresoFalta" class="hana-progreso-pendiente"></span></div>' +
        '  <div class="hana-progreso-barra"><div class="hana-progreso-relleno" id="hanaProgresoRelleno"></div></div>' +
        '</div>');

    // Iterar sobre las preguntas y tipos de respuesta
    $.each(data, function (index, question) {
        var idP = question.ID_DETALLE_GRUPO_LISTA_CHEQUEO;
        nPregunta = index + 1;
        var questionHtml = '<div class="form-group col-lg-10 col-md-10 col-sm-10 col-xs-10" style="box-shadow: 0 1px 3px 0 rgba(26,26,26,.12);margin-left: 2%; border-radius: 10px; background-color: #fff;">';
        questionHtml += '<div class="lista-pregunta"><label>No.' + nPregunta + "   " + question.PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO + '</label></div>';

        if (question.TIPO_RESPUESTA === 'si/no') {
            //Botones grandes en vez de los radio pequenos: en campo se responde con el dedo.
            //El input radio sigue existiendo (lo esconde el CSS), asi el formulario se envia igual
            questionHtml += '<div class="hana-opciones">';
            questionHtml += '  <label class="hana-opcion hana-opcion-si"><input type="radio" name="answer_' + idP + '" value="1"><span>S\u00ed</span></label>';
            questionHtml += '  <label class="hana-opcion hana-opcion-no"><input type="radio" name="answer_' + idP + '" value="2"><span>No</span></label>';
            questionHtml += '  <label class="hana-opcion hana-opcion-na"><input type="radio" name="answer_' + idP + '" value="3"><span>No aplica</span></label>';
            questionHtml += '</div>';
            //Ya no hace falta el <script> que desmarcaba las otras opciones: los radio
            //con el mismo name lo hacen solos, y ese script apuntaba a un campo que no existia
        } else if (question.TIPO_RESPUESTA === 'lista') {
            questionHtml += '<select class="form-control" name="answer_' + idP + '">';
            questionHtml += '<option value="">Seleccione...</option>';
            questionHtml += '<option value="Bueno">Bueno</option>';
            questionHtml += '<option value="Regular">Regular</option>';
            questionHtml += '<option value="Malo">Malo</option>';
            questionHtml += '<option value="No Aplica">No Aplica</option>';
            questionHtml += '</select>';
        } else if (question.TIPO_RESPUESTA === 'texto') {
            questionHtml += '<input class="form-control" type="text" name="answer_' + idP + '">';
        } else if (question.TIPO_RESPUESTA === 'fecha') {
            questionHtml += '<input class="form-control" type="date" name="answer_' + idP + '">';
        } else if (question.TIPO_RESPUESTA === 'datetime') {
            questionHtml += '<input class="form-control" type="time" name="answer_' + idP + '">';
        } else if (question.TIPO_RESPUESTA === 'firma') {
            //Se firma con el dedo sobre la pantalla. Antes habia que tener la firma
            //guardada como archivo de imagen en el telefono y subirla
            questionHtml += construirFirma(idP, '');
        }

        questionHtml += '</div>';
        formContainer.append(questionHtml);
    });

    //Los lienzos de firma se activan cuando ya estan dibujados en la pantalla
    activarFirmas();

    //Cada vez que se responde algo se actualiza el avance y se guarda el borrador
    formContainer.off('change.hana input.hana')
                 .on('change.hana input.hana', '[name^="answer_"]', function () {
                     $(this).closest('.form-group').removeClass('campo-faltante'); //el rojo se quita solo
                     actualizarProgreso();
                     guardarBorrador();
                 });

    preseleccionarCentroUnico(); //si solo tiene un centro, queda elegido
    restaurarBorrador(idGrupo);  //si quedo algo a medias, se ofrece recuperarlo
    actualizarProgreso();
}

//Dibuja el recuadro para firmar. valorInicial se usa al editar una lista ya guardada
function construirFirma(idPregunta, valorInicial) {
    var html = '<div class="hana-firma">';
    html += '  <canvas class="hana-firma-lienzo" data-pregunta="' + idPregunta + '"></canvas>';
    //El campo oculto es el que viaja al servidor, con la firma en formato imagen
    html += '  <input type="hidden" name="answer_' + idPregunta + '" value="' + (valorInicial || '') + '">';
    html += '  <div class="hana-firma-pie">';
    html += '    <span class="hana-firma-ayuda">Firme aqu\u00ed con el dedo o el mouse</span>';
    html += '    <button type="button" class="hana-firma-borrar">Borrar firma</button>';
    html += '  </div>';
    html += '</div>';
    return html;
}

//Deja listos todos los lienzos de firma que haya en la pantalla
function activarFirmas() {
    $('.hana-firma-lienzo').each(function () {
        var lienzo = this;
        if (lienzo.dataset.listo === '1') { return; } //ya estaba activo
        lienzo.dataset.listo = '1';

        var campo = $(lienzo).siblings('input[type="hidden"]');
        //El lienzo se dibuja con el tamano real que ocupa en pantalla, si no la firma sale corrida
        var ancho = lienzo.offsetWidth || 400;
        lienzo.width = ancho;
        lienzo.height = lienzo.offsetHeight || 170;

        var ctx = lienzo.getContext('2d');
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#1a1a1a';

        //Si venia una firma guardada, se pinta encima para poder verla
        var valor = campo.val();
        if (valor) {
            var img = new Image();
            img.onload = function () { ctx.drawImage(img, 0, 0, lienzo.width, lienzo.height); };
            img.src = valor;
            $(lienzo).addClass('hana-firma-hecha');
        }

        var dibujando = false;
        var hayTrazo = !!valor;

        //Posicion del dedo o del mouse dentro del lienzo
        function punto(e) {
            var r = lienzo.getBoundingClientRect();
            var t = (e.touches && e.touches[0]) ? e.touches[0] : e;
            return { x: t.clientX - r.left, y: t.clientY - r.top };
        }
        function empezar(e) {
            e.preventDefault();
            dibujando = true;
            var p = punto(e);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
        }
        function mover(e) {
            if (!dibujando) { return; }
            e.preventDefault();
            var p = punto(e);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            hayTrazo = true;
        }
        function terminar() {
            if (!dibujando) { return; }
            dibujando = false;
            if (hayTrazo) {
                //Se guarda como imagen PNG dentro del campo oculto
                campo.val(lienzo.toDataURL('image/png')).trigger('change');
                $(lienzo).addClass('hana-firma-hecha');
            }
        }

        lienzo.addEventListener('mousedown', empezar);
        lienzo.addEventListener('mousemove', mover);
        document.addEventListener('mouseup', terminar);
        lienzo.addEventListener('touchstart', empezar, { passive: false });
        lienzo.addEventListener('touchmove', mover, { passive: false });
        lienzo.addEventListener('touchend', terminar);

        //Boton para volver a empezar la firma
        $(lienzo).siblings('.hana-firma-pie').find('.hana-firma-borrar').on('click', function () {
            ctx.clearRect(0, 0, lienzo.width, lienzo.height);
            hayTrazo = false;
            campo.val('').trigger('change');
            $(lienzo).removeClass('hana-firma-hecha');
        });
    });
}

//Cuenta cuantas preguntas hay y cuantas estan respondidas
//Sirve tanto para la barra de avance como para la validacion al guardar
function contarRespuestas() {
    var nombres = [];
    $("#demo-form2 [name^='answer_']").each(function () {
        var n = $(this).attr('name');
        if (nombres.indexOf(n) === -1) { nombres.push(n); }
    });

    var hechas = 0;
    $.each(nombres, function (i, nombre) {
        if (estaRespondida(nombre)) { hechas++; }
    });
    return { total: nombres.length, hechas: hechas };
}

//Dice si una pregunta ya tiene respuesta. Cada tipo se revisa distinto
function estaRespondida(nombre) {
    var campos = $("#demo-form2 [name='" + nombre + "']"); //puede ser 1 campo o varios radios
    var primero = campos.first();
    if (!primero.length) { return false; }

    if (primero.is(':radio')) {
        //Si/No/No aplica: basta con que alguna opcion este marcada
        return campos.filter(':checked').length > 0;
    }
    if (primero.is(':file')) {
        //Firma cargada como archivo (formato anterior): debe haber un archivo elegido
        return primero[0].files && primero[0].files.length > 0;
    }
    //Texto, lista, fecha, hora y la firma dibujada: no puede quedar vacio
    return $.trim(primero.val() || "") !== "";
}

//Actualiza la barra de avance de la parte superior
function actualizarProgreso() {
    var barra = $('#hanaProgreso');
    if (!barra.length) { return; }

    var c = contarRespuestas();
    var pct = c.total ? Math.round((c.hechas / c.total) * 100) : 0;

    $('#hanaProgresoRelleno').css('width', pct + '%');
    $('#hanaProgresoTexto').text(c.hechas + ' de ' + c.total + ' respondidas');

    var falta = c.total - c.hechas;
    var aviso = $('#hanaProgresoFalta');
    if (falta > 0) {
        aviso.attr('class', 'hana-progreso-pendiente').text('Faltan ' + falta);
    } else {
        aviso.attr('class', 'hana-progreso-listo').text('Completa');
    }
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
    $("#listadoRespuestas").hide();

    //Fecha de hoy en la zona horaria del equipo. Antes se usaba toISOString(), que
    //devuelve la fecha en UTC: despues de las 7 p.m. en Colombia ya marcaba el dia
    //siguiente y la lista de hoy dejaba de poder editarse
    date = new Date();
    hoy = date.getFullYear() + '-' +
          ('0' + (date.getMonth() + 1)).slice(-2) + '-' +
          ('0' + date.getDate()).slice(-2);

    var colaborador = data.find(item => item.ID_COLABORADOR).ID_COLABORADOR;
    var lista = data.find(item => item.ID_LISTA).ID_LISTA;
    var grupo = data.find(item => item.ID_GRUPO).ID_GRUPO;
    var fechaLista = data.find(item => item.FECHA).FECHA;
    var centro = data.find(item => item.centro).centro;
    //El id del centro, para poder cambiarlo al editar
    var filaCentro = data.find(item => item.idCentro);
    centroListaActual = { id: filaCentro ? filaCentro.idCentro : '', nombre: centro };
    $("#fechaEncuesta").val(fechaLista);
    fechaLista = fechaLista.substring(0, 10);
    scriptActivo = false;
    $('#diaLista').hide(); //al corregir una lista, su día no cambia
    rdAdjuntos('#listaAdjuntos', 'LISTA', lista); //sus archivos (fotos de evidencia, soportes)
    $("#idGrupo").val(grupo);
    $("#idLista").val(lista);
    $("#nombreColaborador").val(colaborador);
    $("#selectCentro1").val(centro);
    var formContainer = $('#formDinamico');
    formContainer.empty();
    $("#centro").hide();
    $("#centro1").show();

    var editable = (fechaLista == hoy); //solo se puede corregir lo diligenciado hoy

    var groupHtml = '<div class="row"><div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" style="margin-left: 3%;"><h3>' + data[0].NOM_GRUPO + '</h3></div></div><div class="col-12" style="margin: 1.2%;width: 95%;height: 3px;background-color: #E3E6EA;"></div>';
    formContainer.append(groupHtml);

    //Barra con el boton para corregir toda la lista de una vez, en vez de
    //entrar pregunta por pregunta. Si la lista ya no es de hoy, se explica por que
    if (editable) {
        formContainer.append(
            '<div class="hana-progreso" id="hanaBarraEdicion">' +
            '  <button type="button" class="btn btn-primary" id="btnEditarTodas">' +
            '     <i class="fa fa-pencil"></i> Editar todas las respuestas</button>' +
            '  <button type="button" class="btn btn-success" id="btnGuardarTodasResp" style="display:none;">' +
            '     <i class="fa fa-save"></i> Guardar cambios</button>' +
            '  <button type="button" class="btn btn-default" id="btnCancelarEdicion" style="display:none;">Cancelar</button>' +
            '</div>');
    } else {
        formContainer.append(
            '<div class="hana-borrador">Esta lista se diligenci\u00f3 el ' + fechaLista +
            '. Solo se pueden corregir las respuestas del mismo d\u00eda.</div>');
    }

    // Iterar sobre las preguntas y tipos de respuesta
    $.each(data, function (index, question) {
        questionRespuesta = question.RESPUESTA;
        nPregunta = index + 1;
        var idP = question.ID_PREGUNTA;

        var questionHtml = '<div class="row">';
        questionHtml += '<div class="form-group col-lg-9 col-md-9 col-sm-9 col-xs-9" style="box-shadow: 0 1px 3px 0 rgba(26,26,26,.12);margin-left: 2%; border-radius: 10px; background-color: #fff;">';
        questionHtml += '<div class="lista-pregunta"><label>No.' + nPregunta + "   " + question.PREGUNTA + '</label></div>';
        //Cada respuesta lleva su propio identificador: es lo que permite guardarlas todas juntas
        questionHtml += '<input type="hidden" name="resp_' + idP + '" value="' + question.ID_RESPUESTA + '">';

        if (question.TIPO_RESPUESTA === 'si/no') {
            //Los mismos botones grandes del diligenciamiento. Empiezan bloqueados
            questionHtml += '<div class="hana-opciones">';
            questionHtml += '  <label class="hana-opcion hana-opcion-si"><input type="radio" name="answer_' + idP + '" value="1"' + (questionRespuesta == 1 ? ' checked' : '') + ' disabled><span>S\u00ed</span></label>';
            questionHtml += '  <label class="hana-opcion hana-opcion-no"><input type="radio" name="answer_' + idP + '" value="2"' + (questionRespuesta == 2 ? ' checked' : '') + ' disabled><span>No</span></label>';
            questionHtml += '  <label class="hana-opcion hana-opcion-na"><input type="radio" name="answer_' + idP + '" value="3"' + (questionRespuesta == 3 ? ' checked' : '') + ' disabled><span>No aplica</span></label>';
            questionHtml += '</div>';
        } else if (question.TIPO_RESPUESTA === 'lista') {
            questionHtml += '<select class="form-control" style="margin-bottom: 1%;" name="answer_' + idP + '" disabled>';
            questionHtml += '<option value="">Seleccione...</option>';
            questionHtml += '<option value="Bueno" ' + (questionRespuesta == 'Bueno' ? 'selected' : '') + '>Bueno</option>';
            questionHtml += '<option value="Regular" ' + (questionRespuesta == 'Regular' ? 'selected' : '') + '>Regular</option>';
            questionHtml += '<option value="Malo" ' + (questionRespuesta == 'Malo' ? 'selected' : '') + '>Malo</option>';
            questionHtml += '<option value="No Aplica" ' + (questionRespuesta == 'No Aplica' ? 'selected' : '') + '>No Aplica</option>';
            questionHtml += '</select>';
        } else if (question.TIPO_RESPUESTA === 'texto') {
            questionHtml += '<input class="form-control" style="margin-bottom: 1%;" type="text" name="answer_' + idP + '" value="' + questionRespuesta + '" readonly>';
        } else if (question.TIPO_RESPUESTA === 'fecha') {
            questionHtml += '<input class="form-control" style="margin-bottom: 1%;" type="date" name="answer_' + idP + '" value="' + questionRespuesta + '" readonly>';
        } else if (question.TIPO_RESPUESTA === 'datetime') {
            questionHtml += '<input class="form-control" style="margin-bottom: 1%;" type="text" name="answer_' + idP + '" value="' + questionRespuesta + '" readonly>';
        } else if (question.TIPO_RESPUESTA === 'firma') {
            //Mientras no se edite se ve la firma guardada; al editar aparece el lienzo
            questionHtml += '<div class="firma-guardada"><img src="' + questionRespuesta + '" style="max-width: 200px; max-height: 150px; display:block;" /></div>';
            questionHtml += '<div class="firma-editable" style="display:none;">' + construirFirma(idP, questionRespuesta) + '</div>';
        }
        questionHtml += '</div>';

        //Se conserva el boton de corregir una sola respuesta, para quien prefiera ese camino
        if (editable) {
            questionHtml += '<div class="form-group col-lg-2 col-md-2 col-sm-2 col-xs-2" style="margin: 1%;"><SPAN title="Corregir esta respuesta"><button class="btn btn-success btnEditaRespuesta" type="button" style="height: 50px;width: 50px;" data-toggle="modal" data-target="#respuestaModal" data-idpregunta="' + idP + '" data-pregunta="' + question.PREGUNTA + '" data-idlista="' + lista + '" data-respuesta="' + questionRespuesta + '" data-idrespuesta="' + question.ID_RESPUESTA + '" data-tipo="' + question.TIPO_RESPUESTA + '" data-grupo="' + data[0].NOM_GRUPO + '" ><i class="fa fa-pencil"></i></button></SPAN></div>';
        }
        questionHtml += '</div>';
        formContainer.append(questionHtml);
    });

    //El manejador se registra UNA sola vez, sobre el contenedor. Antes se registraba
    //dentro del recorrido, asi que con 20 preguntas quedaba repetido 20 veces
    formContainer.off('click.modalResp').on('click.modalResp', '.btnEditaRespuesta', function () {
        construirModalRespuesta($(this));
    });

    //Botones de la edicion completa
    $('#btnEditarTodas').on('click', function () { activarEdicionTotal(true); });
    $('#btnCancelarEdicion').on('click', function () { mostrarRespuestas(lista); }); //recarga tal como esta guardado
    $('#btnGuardarTodasResp').on('click', function () { guardarTodasRespuestas(lista); });
}

//Habilita o bloquea todos los campos de la lista consultada
//El centro de la lista que se está viendo: { id, nombre }
var centroListaActual = { id: '', nombre: '' };
//true mientras se corrige una lista ya guardada
var editandoLista = false;

function activarEdicionTotal(activar) {
    $('#formDinamico [name^="answer_"]').prop('disabled', !activar).prop('readonly', !activar);

    //El centro operativo: al consultar se ve como texto fijo; al editar se cambia
    //por el selector real, con el centro actual ya elegido. Antes el selector
    //nunca aparecía y el centro no se podía corregir
    editandoLista = activar;
    if (activar) {
        if (!$('#selectCentro').hasClass('select2-hidden-accessible')) { selectCentro(); }
        if (centroListaActual.id) {
            //"change.select2" solo actualiza lo que se ve. Un "change" normal dispara
            //la revisión de "ya existe una lista hoy", que encontraba ESTA misma
            //lista y cerraba el formulario
            $('#selectCentro').empty()
                .append(new Option(centroListaActual.nombre, centroListaActual.id, true, true))
                .trigger('change.select2');
        }
        $('#centro1').hide();
        $('#centro').show();
    } else {
        $('#centro').hide();
        $('#centro1').show();
    }

    //La firma cambia de imagen fija a lienzo para volver a firmar
    $('#formDinamico .firma-guardada').toggle(!activar);
    $('#formDinamico .firma-editable').toggle(activar);
    if (activar) {
        $('.hana-firma-lienzo').each(function () { this.dataset.listo = ''; });
        activarFirmas();
    }

    $('#btnEditarTodas').toggle(!activar);
    $('#btnGuardarTodasResp').toggle(activar);
    $('#btnCancelarEdicion').toggle(activar);
    //Mientras se edita toda la lista, los botones de una sola respuesta estorban
    $('.btnEditaRespuesta').toggle(!activar);
}

//Envia de una sola vez todas las respuestas corregidas
function guardarTodasRespuestas(idLista) {
    var fd = new FormData();
    fd.append('idLista', idLista);

    var enviadas = 0;
    $('#formDinamico [name^="resp_"]').each(function () {
        var idPregunta = $(this).attr('name').replace('resp_', '');
        var valor = valorRespuesta(idPregunta);
        if (valor === null) { return; } //sin responder: no se toca lo que ya estaba

        fd.append('resp_' + idPregunta, $(this).val());   //identificador de esa respuesta
        fd.append('answer_' + idPregunta, valor);         //el valor nuevo
        //El servidor exige que venga un idRespuesta; se manda el primero
        if (enviadas === 0) { fd.append('idRespuesta', $(this).val()); }
        enviadas++;
    });

    if (!enviadas) {
        alert('No hay respuestas para guardar.');
        return;
    }

    //Si se está editando y se eligió otro centro, se envía para cambiarlo
    if ($('#centro').is(':visible')) {
        var nuevoCentro = $('#selectCentro').val();
        if (!nuevoCentro) { alert('Elige el centro operativo.'); return; }
        fd.append('idCentro', nuevoCentro);
    }

    hanaBoton('#btnGuardarTodasResp', true);
    $.ajax({
        url: "../Control/ListasControl.php?op=editarUnaRespuesta",
        type: "POST",
        data: fd,
        contentType: false,
        processData: false,
        success: function () {
            alert('Se guardaron ' + enviadas + ' respuesta(s) con \u00e9xito');
            mostrarRespuestas(idLista); //se recarga para ver lo que quedó guardado
        },
        error: function (xhr) {
            hanaErrorAjax(xhr, "No se pudieron guardar las respuestas. Lo que corregiste sigue en pantalla.");
        },
        complete: function () {
            hanaBoton('#btnGuardarTodasResp', false);
        }
    });
}

//Devuelve el valor actual de una pregunta, o null si quedo sin responder
function valorRespuesta(idPregunta) {
    var campos = $('#formDinamico [name="answer_' + idPregunta + '"]');
    if (!campos.length) { return null; }

    if (campos.first().is(':radio')) {
        var marcado = campos.filter(':checked');
        return marcado.length ? marcado.val() : null;
    }
    var v = $.trim(campos.first().val() || '');
    return v === '' ? null : v;
}

//Arma la ventana para corregir una sola respuesta
function construirModalRespuesta(boton) {
    var grupo = boton.data('grupo');
    var idLista = boton.data('idlista');
    var idPregunta = boton.data('idpregunta');
    var pregunta = boton.data('pregunta');
    var idRespuesta = boton.data('idrespuesta');
    var respuesta = boton.data('respuesta');
    var tipo = boton.data('tipo');

    var cont = $('#formModalDinamico');
    cont.empty();
    cont.append('<div class="row"><div class="form-group col-lg-6 col-md-6 col-sm-6 col-xs-12" style="margin-left: 3%;"><h3>' + grupo + '</h3></div></div><div class="col-12" style="margin: 1.2%;width: 95%;height: 3px;background-color: #E3E6EA;"></div>');

    var html = '<div class="row">';
    html += '<div class="form-group col-lg-11 col-md-11 col-sm-11 col-xs-11" style="box-shadow: 0 1px 3px 0 rgba(26,26,26,.12);margin-left: 2%; border-radius: 10px; background-color: #fff;">';
    html += '<div class="lista-pregunta"><label>' + pregunta + '</label>';
    html += '<input type="hidden" class="form-control" name="idLista" id="idLista" value="' + idLista + '">';
    html += '<input type="hidden" class="form-control" name="idPregunta" id="idPregunta" value="' + idPregunta + '">';
    html += '<input type="hidden" class="form-control" name="idRespuesta" id="idRespuesta" value="' + idRespuesta + '"></div>';

    if (tipo === 'si/no') {
        html += '<div class="hana-opciones">';
        html += '  <label class="hana-opcion hana-opcion-si"><input type="radio" name="answer_' + idPregunta + '" value="1"' + (respuesta == 1 ? ' checked' : '') + '><span>S\u00ed</span></label>';
        html += '  <label class="hana-opcion hana-opcion-no"><input type="radio" name="answer_' + idPregunta + '" value="2"' + (respuesta == 2 ? ' checked' : '') + '><span>No</span></label>';
        html += '  <label class="hana-opcion hana-opcion-na"><input type="radio" name="answer_' + idPregunta + '" value="3"' + (respuesta == 3 ? ' checked' : '') + '><span>No aplica</span></label>';
        html += '</div>';
    } else if (tipo === 'lista') {
        html += '<select class="form-control" style="margin-bottom: 1%;" name="answer_' + idPregunta + '">';
        html += '<option value="">Seleccione...</option>';
        html += '<option value="Bueno" ' + (respuesta == 'Bueno' ? 'selected' : '') + '>Bueno</option>';
        html += '<option value="Regular" ' + (respuesta == 'Regular' ? 'selected' : '') + '>Regular</option>';
        html += '<option value="Malo" ' + (respuesta == 'Malo' ? 'selected' : '') + '>Malo</option>';
        html += '<option value="No Aplica" ' + (respuesta == 'No Aplica' ? 'selected' : '') + '>No Aplica</option>';
        html += '</select>';
    } else if (tipo === 'texto') {
        html += '<input class="form-control" style="margin-bottom: 1%;" type="text" name="answer_' + idPregunta + '" value="' + respuesta + '">';
    } else if (tipo === 'fecha') {
        html += '<input class="form-control" style="margin-bottom: 1%;" type="date" name="answer_' + idPregunta + '" value="' + respuesta + '">';
    } else if (tipo === 'datetime') {
        html += '<input class="form-control" style="margin-bottom: 1%;" type="text" name="answer_' + idPregunta + '" value="' + respuesta + '">';
    } else if (tipo === 'firma') {
        //Tambien aqui se firma en pantalla, en vez de subir un archivo
        html += construirFirma(idPregunta, respuesta);
    }
    html += '</div></div>';
    cont.append(html);

    activarFirmas(); //deja listo el lienzo de la ventana
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
        if (diaListaFecha) { var pf = diaListaFecha.split('-'); today.setFullYear(+pf[0], +pf[1] - 1, +pf[2]); } //un día habilitado, con la hora de ahora
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
//Hoy o un día habilitado: cambia el día de la lista nueva y vuelve a revisar si ya
//existe una lista de ese grupo para ese centro en ese día
$(document).on('click', '#diaLista [data-fecha]', function () {
    var f = $(this).attr('data-fecha');
    diaListaFecha = (f === diasLista.hoy) ? '' : f; //hoy no necesita fecha fija: usa el reloj
    rdMarcarDia('#diaLista', f);
    if ($('#selectCentro').val()) { $('#selectCentro').trigger('change'); }
});

function anular(idLista) {
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    hanaConfirmar("¿Desea anular esta lista?", function () {
        $.post("../Control/ListasControl.php?op=anular", { idLista: idLista }, function (data) {
            tabla.ajax.reload();
            alert("Lista anulada con éxito");
        })
        .fail(function (xhr) {
            hanaErrorAjax(xhr, "No se pudo anular la lista.");
        });
    });
}
function activar(idLista) {
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    hanaConfirmar("¿Desea activar esta lista?", function () {
        $.post("../Control/ListasControl.php?op=activar", { idLista: idLista }, function (data) {
            alert(data); //el servidor responde con el mensaje exacto: éxito o motivo del error
            tabla.ajax.reload();
        })
        .fail(function (xhr) {
            hanaErrorAjax(xhr, "No se pudo activar la lista.");
        });
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

//El servidor ya solo devuelve los centros asignados a este usuario.
//Si tiene uno solo, se deja seleccionado para ahorrarle el paso.
//Se llama unicamente al diligenciar una lista nueva: al consultar una ya
//guardada el selector esta oculto y preseleccionar cerraba el formulario
function preseleccionarCentroUnico() {
    $.getJSON('../Control/ListasControl.php?op=selectCentro&search=0', function (lista) {
        if (!$("#centro").is(":visible")) { return; } //ya no estamos diligenciando

        if (lista && lista.length === 1 && !$("#selectCentro").val()) {
            $("#selectCentro").append(new Option(lista[0].text, lista[0].id, true, true)).trigger('change');
        } else if (lista && lista.length === 0) {
            //Sin centros asignados no se puede diligenciar: conviene avisarlo de una vez
            $("#selectCentro").prop('disabled', true);
            alert('No tienes centros de operaci\u00f3n asignados. Pide al administrador que te los asigne antes de diligenciar listas.');
        }
    });
}
//funcion ok
function mostrarform(flag) {
    //limpia el formulario
    if (flag) {
        // $('#myModal').modal('show');
        $("#demo-form2").show();

        scriptActivo = true;
        //Lista nueva: los archivos se adjuntan después de guardarla (al corregirla)
        adjnPreparar(); //lista nueva: se pueden elegir archivos mientras se llena
        //Lista nueva: es de hoy; solo si el administrador habilitó otro día se puede escoger
        diaListaFecha = '';
        $.getJSON('../Control/ListasControl.php?op=dias', function (d) {
            diasLista = d;
            rdBotonesDia('#diaLista', d.hoy, d.habilitados, 'btn-sm');
            rdMarcarDia('#diaLista', d.hoy);
            $('#diaLista').toggle(d.habilitados.length > 0); //sin días habilitados no hay nada que escoger
        });
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
            "order": [[1, "asc"]]//Ordenar (columna,orden)
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
        buttons: [
                        
                    ],
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
//Llena el selector de posicion con las preguntas del grupo
var _dragOrdenEl = null;
function cargarPosiciones(idGrupo) {
    var sel = $("#posicion-pregunta");
    sel.html('<option value="final">Al final</option>');
    var lista = $("#listaOrden"); lista.empty();
    window.grupoReorden = idGrupo;
    if (!idGrupo) return;
    $.post("../Control/PreguntasListasControl.php?op=posiciones", { idGrupoPreguntas: idGrupo }, function (datos) {
        var arr; try { arr = JSON.parse(datos); } catch (e) { return; }
        $.each(arr, function (i, q) {
            sel.append('<option value="' + q.id + '">Despues de: ' + q.pregunta + '</option>');
            lista.append('<li class="list-group-item orden-item" draggable="true" data-id="' + q.id + '" style="cursor:move;"><i class="fa fa-bars" style="margin-right:8px;color:#999;"></i>' + q.pregunta + '</li>');
        });
        initDragOrden();
    });
}

//Reordenamiento por arrastre (drag & drop nativo)
function initDragOrden() {
    var lista = document.getElementById('listaOrden');
    if (!lista || lista.getAttribute('data-dnd') === '1') return;
    lista.setAttribute('data-dnd', '1');
    lista.addEventListener('dragstart', function (e) {
        var it = e.target.closest ? e.target.closest('.orden-item') : null;
        if (it) { _dragOrdenEl = it; setTimeout(function(){ it.style.opacity = '0.4'; }, 0); }
    });
    lista.addEventListener('dragend', function () { if (_dragOrdenEl) _dragOrdenEl.style.opacity = ''; });
    lista.addEventListener('dragover', function (e) {
        e.preventDefault();
        var target = e.target.closest ? e.target.closest('.orden-item') : null;
        if (!target || target === _dragOrdenEl) return;
        var rect = target.getBoundingClientRect();
        var after = (e.clientY - rect.top) > rect.height / 2;
        lista.insertBefore(_dragOrdenEl, after ? target.nextSibling : target);
    });
    lista.addEventListener('drop', function (e) { e.preventDefault(); guardarOrden(); });
}

//Persiste el nuevo orden de las preguntas del grupo
function guardarOrden() {
    var ids = [];
    $("#listaOrden .orden-item").each(function () { ids.push($(this).attr("data-id")); });
    if (!ids.length) return;
    $.post("../Control/PreguntasListasControl.php?op=reordenar", { idGrupoPreguntas: window.grupoReorden, orden: ids }, function () {
        if (typeof tabla !== 'undefined' && tabla) { try { tabla.ajax.reload(null, false); } catch (e) {} }
    });
}

function agregarPregunta(e) {
    //Validaciones antes de enviar: si falta algo se avisa aquí, sin ir al servidor
    if ($.trim($("#pregunta").val()) === "") { alert("Escribe el texto de la pregunta."); return; }
    if ($("#tipo-respuesta").val() === "")   { alert("Selecciona el tipo de respuesta."); return; }

    hanaBoton("#btn-agregar-pregunta", true);
    var formData1 = new FormData();
    formData1.append('idGrupo', $("#idGrupo").val());
    formData1.append('idPregunta', $("#idPregunta").val());
    formData1.append('pregunta', $("#pregunta").val());
    formData1.append('tipo-respuesta', $("#tipo-respuesta").val());
    formData1.append('posicion', $("#posicion-pregunta").val() || 'final');
    $.ajax({
        url: "../Control/PreguntasListasControl.php?op=guardar",
        type: "POST",
        data: formData1,
        contentType: false,
        processData: false,
        success: function (datos) {
            tabla.ajax.reload();
            mostrarform(false);
            alert("Pregunta guardada con éxito");
        },
        //Antes no habia manejador de error: si la pregunta no se guardaba,
        //igual aparecia el mensaje de exito
        error: function (xhr) {
            hanaErrorAjax(xhr, "No se pudo guardar la pregunta.");
        },
        complete: function () {
            hanaBoton("#btn-agregar-pregunta", false);
        }
    });
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
        cargarPosiciones(miVariable);
    });
    $("#tblRespuestas").on("mouseover", ".imagen-avance", function () {
        //console.log("hover")
        $(this).css("transform", "scale(1.5)");
    });
    $("#tblRespuestas").on("mouseout", ".imagen-avance", function () {
        //console.log("hoverout")
        $(this).css("transform", "scale(1)");
    });

    //Cuando el usuario responde un campo marcado en rojo, se le quita el resaltado
    $("#demo-form2").on("change keyup", "[name^='answer_'], #selectCentro", function () {
        $(this).closest('.form-group').removeClass('campo-faltante');
    });
    $("#selectCentro").on("change", function (params) {
        //Al corregir una lista ya guardada, esta revisión no aplica: la hace el
        //servidor al guardar, excluyendo la propia lista
        if (editandoLista) { return; }
        var selectCentro = $(this).val();
        var idGrupo = $("#idGrupo").val();
        var date = new Date();
        if (diaListaFecha) { var pf = diaListaFecha.split('-'); date.setFullYear(+pf[0], +pf[1] - 1, +pf[2]); } //el día elegido
        //Fecha local, no UTC: toISOString() adelantaba el dia despues de las 7 p.m.
        var fechaEncuesta = date.getFullYear() + '-' +
                            ('0' + (date.getMonth() + 1)).slice(-2) + '-' +
                            ('0' + date.getDate()).slice(-2);
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

//=================================================================
//  Borrador automatico
//  En campo la senal se cae, la sesion vence o se cierra el navegador
//  por error. Antes eso significaba perder toda la lista respondida.
//  Ahora lo respondido se guarda en el mismo telefono mientras se
//  diligencia, y al volver a abrir la lista se ofrece recuperarlo.
//  No viaja a ningun lado: se queda en el dispositivo.
//=================================================================

var borradorGrupo = null;   //lista que se esta diligenciando
var borradorTimer = null;   //espera un momento antes de guardar, para no hacerlo en cada tecla

//Nombre con el que se guarda. Lleva el usuario y la lista, para no mezclar
//borradores de dos personas o de dos listas distintas en el mismo equipo
function borradorClave(idGrupo) {
    var usuario = $("#nombreColaborador").val() || 'x';
    return 'hanaBorrador_' + usuario + '_' + idGrupo;
}

//Guarda lo respondido hasta el momento
function guardarBorrador() {
    if (!borradorGrupo) { return; }

    clearTimeout(borradorTimer);
    borradorTimer = setTimeout(function () {
        var datos = { fecha: new Date().getTime(), centro: $("#selectCentro").val() || '', respuestas: {} };

        $("#demo-form2 [name^='answer_']").each(function () {
            var campo = $(this);
            var nombre = campo.attr('name');
            if (campo.is(':radio')) {
                if (campo.is(':checked')) { datos.respuestas[nombre] = campo.val(); }
            } else if (!campo.is(':file')) {
                //Los archivos no se pueden guardar asi; la firma dibujada si, porque es texto
                var v = campo.val();
                if (v) { datos.respuestas[nombre] = v; }
            }
        });

        try {
            localStorage.setItem(borradorClave(borradorGrupo), JSON.stringify(datos));
        } catch (e) {
            //Si el navegador no deja guardar (modo privado o sin espacio) se sigue
            //trabajando normal: el borrador es una ayuda, no un requisito
            console.log('No se pudo guardar el borrador:', e);
        }
    }, 400);
}

//Revisa si hay un borrador de esta lista y, si lo hay, ofrece recuperarlo
function restaurarBorrador(idGrupo) {
    borradorGrupo = idGrupo;

    var guardado = null;
    try { guardado = localStorage.getItem(borradorClave(idGrupo)); } catch (e) { return; }
    if (!guardado) { return; }

    var datos;
    try { datos = JSON.parse(guardado); } catch (e) { limpiarBorrador(); return; }

    //Un borrador de hace mas de dos dias ya no sirve: se descarta solo
    if (!datos.fecha || (new Date().getTime() - datos.fecha) > 172800000) {
        limpiarBorrador();
        return;
    }

    var cuando = new Date(datos.fecha);
    var hora = ('0' + cuando.getHours()).slice(-2) + ':' + ('0' + cuando.getMinutes()).slice(-2);
    var cuantas = Object.keys(datos.respuestas || {}).length;
    if (!cuantas) { return; }

    //Aviso con dos opciones, para que el usuario decida
    var aviso = $('<div class="hana-borrador">Tienes ' + cuantas + ' respuesta(s) sin enviar de esta lista, guardadas a las ' + hora + '. ' +
                  '<button type="button" id="btnRecuperarBorrador">Recuperarlas</button> o ' +
                  '<button type="button" id="btnDescartarBorrador">empezar de nuevo</button>.</div>');
    $('#hanaProgreso').after(aviso);

    aviso.find('#btnRecuperarBorrador').on('click', function () {
        aplicarBorrador(datos);
        aviso.remove();
    });
    aviso.find('#btnDescartarBorrador').on('click', function () {
        limpiarBorrador();
        aviso.remove();
    });
}

//Vuelve a poner en pantalla las respuestas guardadas
function aplicarBorrador(datos) {
    $.each(datos.respuestas || {}, function (nombre, valor) {
        var campos = $("#demo-form2 [name='" + nombre + "']");
        if (!campos.length) { return; } //la pregunta ya no existe en la lista

        if (campos.first().is(':radio')) {
            campos.filter('[value="' + valor + '"]').prop('checked', true);
        } else {
            campos.first().val(valor);
        }
    });

    //El centro de operacion tambien se recupera, si sigue disponible
    if (datos.centro) {
        var centro = $("#selectCentro");
        if (centro.find('option[value="' + datos.centro + '"]').length) {
            centro.val(datos.centro).trigger('change');
        }
    }

    //Las firmas se vuelven a pintar sobre su lienzo
    $('.hana-firma-lienzo').each(function () { this.dataset.listo = ''; });
    activarFirmas();

    actualizarProgreso();
    alert('Se recuperaron tus respuestas. Rev\u00edsalas antes de enviar la lista.');
}

//Borra el borrador. Se llama al enviar la lista con exito o al descartarlo
function limpiarBorrador() {
    if (!borradorGrupo) { return; }
    try { localStorage.removeItem(borradorClave(borradorGrupo)); } catch (e) {}
}

//===========================================================================
// Archivos al llenar una lista nueva (como en un chat): se eligen o se
// arrastran, aparecen como etiquetas con una ✕ para quitarlos y se suben
// apenas se guarda la lista. Las mismas reglas del servidor: tipo y tamaño
//===========================================================================
var adjnPendientes = [];
var ADJN_TIPOS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'msg', 'eml'];

function adjnPreparar() {
    adjnPendientes = [];
    if (typeof HANA_ADJ_LISTAS !== 'undefined' && !HANA_ADJ_LISTAS) {
        $('#listaAdjuntos').html('<div class="adj"><div class="adj-cab"><i class="fa fa-paperclip"></i> Archivos</div><p class="adj-vacio">Los archivos en las listas están apagados en Parámetros del sistema.</p></div>');
        return;
    }
    $('#listaAdjuntos').html(
        '<div class="adj adjn">' +
          '<div class="adj-cab"><i class="fa fa-paperclip"></i> Archivos <small>(opcional)</small></div>' +
          '<div class="adjn-zona" id="adjnZona">' +
            '<i class="fa fa-cloud-upload"></i> Arrastra aquí fotos o documentos, o ' +
            '<button type="button" class="btn btn-default btn-sm" id="adjnElegir"><i class="fa fa-plus"></i> Adjuntar archivos</button>' +
            '<input type="file" id="adjnInput" multiple hidden accept="' + ADJN_TIPOS.map(function (e) { return '.' + e; }).join(',') + '">' +
          '</div>' +
          '<div class="adjn-chips" id="adjnChips"></div>' +
          '<p class="adj-vacio" id="adjnAyuda">Se suben al guardar la lista. Fotos, PDF, Word o Excel; máximo ' + (window.HANA_ADJ_MAX_MB || 10) + ' MB cada uno.</p>' +
        '</div>');
    adjnPintar();
}

function adjnTam(b) { return b < 1024 * 1024 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1024 / 1024).toFixed(1).replace('.', ',') + ' MB'; }

function adjnAgregar(lista) {
    var max = (window.HANA_ADJ_MAX_MB || 10) * 1024 * 1024, avisos = [];
    Array.prototype.forEach.call(lista || [], function (f) {
        var ext = (f.name.split('.').pop() || '').toLowerCase();
        if (ADJN_TIPOS.indexOf(ext) < 0) { avisos.push(f.name + ': ese tipo de archivo no se permite.'); return; }
        if (f.size > max) { avisos.push(f.name + ': pasa de ' + (window.HANA_ADJ_MAX_MB || 10) + ' MB.'); return; }
        //el mismo archivo dos veces no se repite
        if (adjnPendientes.some(function (x) { return x.name === f.name && x.size === f.size; })) { return; }
        adjnPendientes.push(f);
    });
    adjnPintar(avisos);
}

function adjnPintar(avisos) {
    var h = adjnPendientes.map(function (f, i) {
        var esImg = /^image\//.test(f.type);
        return '<span class="adjn-chip" title="' + rdEsc(f.name) + '"><i class="fa ' + (esImg ? 'fa-file-image-o' : (/\.pdf$/i.test(f.name) ? 'fa-file-pdf-o' : 'fa-file-o')) + '"></i> ' +
               '<span class="adjn-nom">' + rdEsc(f.name) + '</span> <small>' + adjnTam(f.size) + '</small>' +
               '<button type="button" class="adjn-quitar" data-i="' + i + '" aria-label="Quitar ' + rdEsc(f.name) + '">&times;</button></span>';
    }).join('');
    $('#adjnChips').html(h + ((avisos && avisos.length) ? '<div class="adjn-aviso"><i class="fa fa-exclamation-triangle"></i> ' + avisos.map(rdEsc).join('<br>') + '</div>' : ''));
}

//Sube los archivos elegidos, colgados de la lista recién guardada (Archivos de la lista)
function adjnSubir(idLista, alTerminar) {
    var fd = new FormData();
    fd.append('modulo', 'LISTA'); fd.append('id', idLista);
    adjnPendientes.forEach(function (f) { fd.append('archivos[]', f, f.name); });
    var n = adjnPendientes.length;
    $.ajax({ url: '../Control/AdjuntoControl.php?op=subir', type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json' })
        .done(function (r) {
            var msg = 'Lista guardada con éxito. ' + (r && r.mensaje ? r.mensaje : n + ' archivo(s) adjunto(s).');
            if (r && r.avisos && r.avisos.length) { msg += '\n\nNo se adjuntaron:\n' + r.avisos.join('\n'); }
            adjnPendientes = []; alTerminar(msg);
        })
        .fail(function (xhr) {
            adjnPendientes = [];
            alTerminar('La lista quedó guardada, pero los archivos no se pudieron subir: ' + rdError(xhr, 'error del servidor') + '\nPuedes abrir la lista y adjuntarlos desde «Corregir».');
        });
}

$(document).on('click', '#adjnElegir', function () { $('#adjnInput').trigger('click'); });
$(document).on('change', '#adjnInput', function () { adjnAgregar(this.files); this.value = ''; });
$(document).on('click', '.adjn-quitar', function () { adjnPendientes.splice(+$(this).data('i'), 1); adjnPintar(); });
$(document).on('dragover dragenter', '#adjnZona', function (e) { e.preventDefault(); $(this).addClass('arrastrando'); });
$(document).on('dragleave drop', '#adjnZona', function (e) { e.preventDefault(); $(this).removeClass('arrastrando'); });
$(document).on('drop', '#adjnZona', function (e) { adjnAgregar(e.originalEvent.dataTransfer.files); });
