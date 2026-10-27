//Pantalla "Editar todas las preguntas" - reordenar arrastrando y editar en linea
//Trabaja sobre Vista/Editarpreguntasvista.php (en minúsculas: el servidor distingue mayúsculas)

var idGrupoEditar = null; //lista de chequeo que se esta editando
var hayCambiosPendientes = false; //true cuando se movió algo y aun no se guarda

//Tipos de respuesta disponibles. Ojo: la BD tiene UNA sola columna (TIPO_RESPUESTA)
var _tiposEditor = [
    ["texto",    "Texto"],
    ["si/no",    "S\u00ed/No"],
    ["lista",    "Lista"],
    ["fecha",    "Fecha"],
    ["datetime", "Fecha y hora"],
    ["firma",    "Firma"]
];

//Escapa comillas y & para poder meter texto de la BD dentro de un atributo HTML
function escAttr(texto) {
    return String(texto == null ? "" : texto).replace(/&/g, "&amp;").replace(/"/g, "&quot;");
}

//Regresa al listado reabriendo la misma lista (por eso se manda ?idLista=)
function volverListas() {
    //Si dejo cambios de orden sin guardar, se le avisa antes de salir
    if (hayCambiosPendientes) {
        //Pregunta con la ventana del sistema; solo si acepta se sale de la pantalla
        hanaConfirmar("Tienes cambios de orden sin guardar. \u00bfDeseas salir de todas formas?", function () {
            hayCambiosPendientes = false; //evita el segundo aviso del navegador al cambiar de página
            window.location.href = "GruposListasVista.php?idLista=" + encodeURIComponent(idGrupoEditar);
        }, { aceptar: "Salir sin guardar", cancelar: "Seguir editando" });
        return; //se espera la respuesta del usuario
    }
    window.location.href = "GruposListasVista.php?idLista=" + encodeURIComponent(idGrupoEditar);
}

//Pone el nombre de la lista en el titulo, para saber que se esta modificando
function cargarNombreLista() {
    $.post("../Control/GruposListasControl.php?op=mostrar", { idLista: idGrupoEditar }, function (data) {
        try {
            var datos = JSON.parse(data);
            if (datos && datos.NOM_GRUPO_LISTA_CHEQUEO) {
                $("#nombreLista").text("\u2014 " + datos.NOM_GRUPO_LISTA_CHEQUEO); //guion + nombre
            }
        } catch (error) {
            //Si el nombre no llega no pasa nada, la pantalla igual funciona
        }
    });
}

//Trae las preguntas de la lista y arma una fila por cada una
function cargarPreguntas() {
    var lista = $("#listaEditor");
    lista.empty(); //se limpia antes de volver a pintar

    $.post("../Control/PreguntasListasControl.php?op=posiciones",
        { idGrupoPreguntas: idGrupoEditar },
        function (datos) {

            var preguntas;
            try { preguntas = JSON.parse(datos); } catch (error) { preguntas = []; } //si falla el JSON, lista vacía

            //Caso lista sin preguntas todavía
            if (!preguntas.length) {
                lista.html('<li class="list-group-item">Esta lista a\u00fan no tiene preguntas.</li>');
                return;
            }

            $.each(preguntas, function (i, pregunta) {

                //Se arma el <select> de tipos y se marca el tipo actual de la pregunta
                var opts = "";
                for (var numTipo = 0; numTipo < _tiposEditor.length; numTipo++) {
                    var marcado = (_tiposEditor[numTipo][0] === pregunta.tipo) ? " selected" : ""; //deja seleccionado el suyo
                    opts += '<option value="' + _tiposEditor[numTipo][0] + '"' + marcado + '>' + _tiposEditor[numTipo][1] + '</option>';
                }

                lista.append(''
                    + '<li class="list-group-item editor-item" data-id="' + pregunta.id + '"' //data-id = ID real en la BD
                    + ' data-orden-original="' + (i + 1) + '">' //posición con la que cargo, sirve para detectar movimientos
                    + '  <span class="handle" title="Arrastra para reordenar"><i class="fa fa-hand-paper-o"></i></span>' //la manito
                    + '  <span class="badge-orden">' + (i + 1) + '</span>' //número de la pregunta; NO cambia al arrastrar
                    + '  <span class="txt-id" title="Número de la pregunta">#' + pregunta.id + '</span>' //ID solo lectura
                    + '  <input type="text" class="form-control in-pregunta" value="' + escAttr(pregunta.pregunta) + '">' //texto editable
                    + '  <select class="form-control in-tipo">' + opts + '</select>' //tipo de respuesta
                    + '  <button type="button" class="btn btn-success btn-sm btn-una" title="Guardar solo esta pregunta"'
                    + '          onclick="guardarUna(this)"><i class="fa fa-save"></i></button>' //guarda solo esta fila
                    + '  <button type="button" class="btn btn-default btn-sm btn-completa" title="Editar completa"'
                    + '          onclick="abrirFormPregunta(this)"><i class="fa fa-pencil"></i></button>' //abre el formulario completo
                    + '</li>');
            });

            hayCambiosPendientes = false; //recién cargado, nada movido
            $("#avisoPendiente").hide(); //y por lo tanto sin aviso

            initDrag(); //se activan los eventos de arrastre sobre las filas nuevas
        });
}

//Revisa si el acomodo actual difiere del que se cargo, para avisar que hay cambios
//IMPORTANTE: aquí NO se toca el número de la insignia. Cada pregunta conserva el
//número con el que se cargo y ese número VIAJA con ella al arrastrarla.
//Ejemplo: si se mueve la 2 al final, en pantalla queda 1,3,4,5,6,2
//Solo al presionar "Guardar cambios" el servidor renumera a 1,2,3,4,5,6
function marcarCambios() {
    var huboMovimiento = false;

    $("#listaEditor .editor-item").each(function (i) {
        var posicion = i + 1; //posición física que ocupa la fila ahora mismo

        //Si su número original no coincide con donde esta parada, es que se movió
        if (parseInt($(this).attr("data-orden-original"), 10) !== posicion) {
            $(this).addClass("movida"); //se resalta para que se note
            huboMovimiento = true;
        } else {
            $(this).removeClass("movida"); //volvió a su lugar original
        }
    });

    hayCambiosPendientes = huboMovimiento;

    //Aviso rojo de "hay cambios sin guardar"
    if (huboMovimiento) { $("#avisoPendiente").show(); } else { $("#avisoPendiente").hide(); }
}

var _arrastrado = null; //fila que se esta arrastrando en este momento

//Prepara el arrastrar y soltar sobre la lista de preguntas
function initDrag() {
    var cont = document.getElementById('listaEditor');
    if (!cont) return; //si no existe el contenedor no hay nada que hacer

    //Al presionar la manito la fila se vuelve arrastrable
    $(cont).off('mousedown', '.handle').on('mousedown', '.handle', function () {
        var fila = this.closest('.editor-item');
        if (fila) fila.setAttribute('draggable', 'true');
    });

    //Al soltar el mouse se desactiva. Si la fila quedara arrastrable siempre,
    //el navegador robaría el clic y no se podría escribir en los campos de texto
    $(cont).off('mouseup.ed').on('mouseup.ed', function () {
        $('.editor-item').attr('draggable', 'false');
    });

    if (cont.getAttribute('data-dnd') === '1') return; //evita registrar los listeners dos veces
    cont.setAttribute('data-dnd', '1'); //marca el contenedor como ya preparado

    //Empieza el arrastre
    cont.addEventListener('dragstart', function (evento) {
        var fila = evento.target.closest ? evento.target.closest('.editor-item') : null;
        if (!fila) return; //no se agarro una fila
        _arrastrado = fila; //se recuerda cual es

        //setData es obligatorio: sin esto Firefox ni siquiera inicia el arrastre
        try { evento.dataTransfer.setData('text/plain', fila.getAttribute('data-id') || ''); } catch (error) {}
        evento.dataTransfer.effectAllowed = 'move';

        //El setTimeout evita que el navegador capture la fila ya semitransparente
        setTimeout(function () { fila.classList.add('dragging'); }, 0);
    });

    //Termina el arrastre
    cont.addEventListener('dragend', function () {
        if (_arrastrado) { _arrastrado.classList.remove('dragging'); } //quita el efecto visual
        _arrastrado = null;
        $('.editor-item').attr('draggable', 'false'); //se vuelve a proteger los inputs
        marcarCambios(); //se revisa si quedo algo fuera de su posición original
    });

    //Mientras se pasa por encima de otra fila
    cont.addEventListener('dragover', function (evento) {
        evento.preventDefault(); //sin esto el navegador no deja soltar
        var filaDestino = evento.target.closest ? evento.target.closest('.editor-item') : null;
        if (!filaDestino || filaDestino === _arrastrado) return; //ignora si es la misma fila

        var caja = filaDestino.getBoundingClientRect();
        var despues = (evento.clientY - caja.top) > caja.height / 2; //paso la mitad? entonces va debajo
        cont.insertBefore(_arrastrado, despues ? filaDestino.nextSibling : filaDestino); //se reacomoda en vivo

        marcarCambios(); //se marca en vivo lo que quedo movido
    });

    cont.addEventListener('drop', function (evento) { evento.preventDefault(); }); //solo evita el comportamiento por defecto

    //En el celular el arrastre de arriba no funciona (es solo para mouse): se hace con el dedo.
    //Se toca y se mantiene la manito, y la fila sigue al dedo hasta que se suelta
    cont.addEventListener('touchstart', function (evento) {
        var manito = evento.target.closest ? evento.target.closest('.handle') : null;
        if (!manito) return;
        _arrastrado = manito.closest('.editor-item');
        if (_arrastrado) { _arrastrado.classList.add('dragging'); }
    }, { passive: true });
    cont.addEventListener('touchmove', function (evento) {
        if (!_arrastrado) return;
        evento.preventDefault(); //mientras se arrastra, la página no se desplaza
        var dedo = evento.touches[0];
        var debajo = document.elementFromPoint(dedo.clientX, dedo.clientY);
        var filaDestino = debajo && debajo.closest ? debajo.closest('#listaEditor .editor-item') : null;
        if (!filaDestino || filaDestino === _arrastrado) return;
        var caja = filaDestino.getBoundingClientRect();
        var despues = (dedo.clientY - caja.top) > caja.height / 2;
        cont.insertBefore(_arrastrado, despues ? filaDestino.nextSibling : filaDestino);
        marcarCambios();
    }, { passive: false });
    var soltar = function () {
        if (!_arrastrado) return;
        _arrastrado.classList.remove('dragging');
        _arrastrado = null;
        marcarCambios();
    };
    cont.addEventListener('touchend', soltar);
    cont.addEventListener('touchcancel', soltar);
}

//Guarda SOLO la fila del botón verde presionado (texto y tipo). No cambia el orden
function guardarUna(boton) {
    var fila = $(boton).closest('.editor-item'); //fila a la que pertenece el botón

    //FormData porque el controlador espera estos nombres por POST
    var formulario = new FormData();
    formulario.append('idPregunta',     fila.data('id'));
    formulario.append('pregunta',       fila.find('.in-pregunta').val());
    formulario.append('tipo-respuesta', fila.find('.in-tipo').val());

    hanaBoton(boton, true, "");
    $.ajax({
        url: "../Control/PreguntasListasControl.php?op=guardar",
        type: "POST",
        data: formulario,
        contentType: false, //se deja que el navegador arme el multipart
        processData: false, //no convertir el FormData a query string
        success: function () {
            alert("Pregunta guardada con éxito");
        },
        error: function (xhr) {
            hanaErrorAjax(xhr, "No se pudo guardar la pregunta.");
        },
        complete: function () {
            hanaBoton(boton, false);
        }
    });
}

//Confirma los cambios: guarda el orden actual + los textos y tipos editados
function guardarTodasPreguntas() {
    var items = [];

    //El orden del recorrido ES el orden que se va a guardar en la BD
    $("#listaEditor .editor-item").each(function () {
        items.push({
            id:       $(this).data('id'),
            pregunta: $(this).find('.in-pregunta').val(),
            tipo:     $(this).find('.in-tipo').val()
        });
    });

    if (!items.length) { alert("No hay preguntas para guardar."); return; } //nada que enviar

    hanaBoton("#btnGuardarTodo", true);
    $.post("../Control/PreguntasListasControl.php?op=guardarLote",
        { items: JSON.stringify(items) }, //se manda como texto JSON
        function () {
            alert("Cambios guardados con éxito");
            hayCambiosPendientes = false; //ya quedo todo en la BD
            cargarPreguntas(); //se recarga para ver la numeración ya consolidada
        })
        .fail(function (xhr) {
            hanaErrorAjax(xhr, "No se pudieron guardar los cambios.");
        })
        .always(function () {
            hanaBoton("#btnGuardarTodo", false);
        });
}

//Arranque de la pantalla, cuando el DOM ya esta listo
$(function () {
    idGrupoEditar = $("#idGrupoEditar").val(); //la lista viene del campo oculto de la vista

    //Sin lista valida no hay nada que editar
    if (!idGrupoEditar || idGrupoEditar === "0") {
        //Se espera a que cierre el aviso antes de salir, para que alcance a leerlo
        alert("No se recibi\u00f3 la lista a editar.", function () {
            window.location.href = "GruposListasVista.php";
        });
        return;
    }

    cargarNombreLista(); //titulo
    cargarPreguntas(); //filas

    //Avisa si intenta cerrar o recargar la pestaña con cambios de orden pendientes
    window.addEventListener('beforeunload', function (evento) {
        if (hayCambiosPendientes) {
            evento.preventDefault();
            evento.returnValue = '';
        }
    });
});

//---------------------------------------------------------------------------
// Agregar una pregunta nueva, o editarla completa
//---------------------------------------------------------------------------

//boton: null = pregunta nueva; si viene de una fila, se edita esa
function abrirFormPregunta(boton) {
    var opts = "";
    for (var numTipo = 0; numTipo < _tiposEditor.length; numTipo++) {
        opts += '<option value="' + _tiposEditor[numTipo][0] + '">' + _tiposEditor[numTipo][1] + '</option>';
    }
    $("#fpTipo").html(opts);

    //Ubicación: al final, o después de cualquiera de las preguntas actuales
    var posicion = '<option value="final">Al final de la lista</option>';
    $("#listaEditor .editor-item").each(function () {
        var texto = $(this).find(".in-pregunta").val();
        posicion += '<option value="' + $(this).data("id") + '">Después de: ' +
               $('<div>').text(texto.length > 60 ? texto.substring(0, 60) + '...' : texto).html() + '</option>';
    });
    $("#fpPosicion").html(posicion);

    if (boton) {
        //Editar: se toman los datos de la fila
        var fila = $(boton).closest(".editor-item");
        $("#fpId").val(fila.data("id"));
        $("#fpPregunta").val(fila.find(".in-pregunta").val());
        $("#fpTipo").val(fila.find(".in-tipo").val());
        $("#panelPreguntaTitulo").text("Editar pregunta #" + fila.data("id"));
        $("#fpPosicionGrupo").hide(); //la ubicación se cambia arrastrando
    } else {
        $("#fpId").val("");
        $("#fpPregunta").val("");
        $("#fpTipo").val("si/no");
        $("#panelPreguntaTitulo").text("Nueva pregunta");
        $("#fpPosicionGrupo").show();
    }
    $("#panelPregunta").slideDown(150);
    $("#fpPregunta").focus();
    $(".right_col").scrollTop(0); window.scrollTo(0, 0);
}

//Cierra el panel de edición de una pregunta
function cerrarFormPregunta() {
    $("#panelPregunta").slideUp(150);
}

//Revisa y guarda la pregunta del panel de edición
function guardarFormPregunta() {
    var pregunta = $.trim($("#fpPregunta").val());

    if (!pregunta) { alert("Escribe la pregunta."); $("#fpPregunta").focus(); return; }
    if (pregunta.length > 200) { alert("La pregunta puede tener máximo 200 caracteres."); return; }

    //Si hay cambios de orden sin guardar, se perderían al recargar la lista
    var seguir = function () {
        var datos = {
            idGrupo: idGrupoEditar,
            pregunta: pregunta,
            "tipo-respuesta": $("#fpTipo").val()
        };
        if ($("#fpId").val()) { datos.idPregunta = $("#fpId").val(); }
        else { datos.posicion = $("#fpPosicion").val(); }

        hanaBoton("#btnGuardarPregunta", true);
        $.ajax({ url: "../Control/PreguntasListasControl.php?op=guardar", type: "POST", data: datos })
            .done(function () {
                alert($("#fpId").val() ? "Pregunta actualizada con éxito" : "Pregunta agregada con éxito", function () {
                    cerrarFormPregunta();
                    cargarPreguntas();
                });
            })
            .fail(function (xhr) { hanaErrorAjax(xhr, "No se pudo guardar la pregunta."); })
            .always(function () { hanaBoton("#btnGuardarPregunta", false); });
    };

    if (hayCambiosPendientes) {
        hanaConfirmar("Tienes cambios de orden sin guardar y se perderán al recargar la lista. ¿Continuar?",
                      seguir, { aceptar: "Sí, continuar", cancelar: "No" });
    } else {
        seguir();
    }
}

