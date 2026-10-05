//Pantalla "Editar todas las preguntas" - reordenar arrastrando y editar en linea
//Trabaja sobre Vista/Editarpreguntasvista.php (en minúsculas: el servidor distingue mayúsculas)

var idGrupoEditar = null; //lista de chequeo que se esta editando
var hayCambiosPendientes = false; //true cuando se movio algo y aun no se guarda

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
function escAttr(s) {
    return String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/"/g, "&quot;");
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
            var d = JSON.parse(data);
            if (d && d.NOM_GRUPO_LISTA_CHEQUEO) {
                $("#nombreLista").text("\u2014 " + d.NOM_GRUPO_LISTA_CHEQUEO); //guion + nombre
            }
        } catch (e) {
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

            var arr;
            try { arr = JSON.parse(datos); } catch (e) { arr = []; } //si falla el JSON, lista vacia

            //Caso lista sin preguntas todavia
            if (!arr.length) {
                lista.html('<li class="list-group-item">Esta lista a\u00fan no tiene preguntas.</li>');
                return;
            }

            $.each(arr, function (i, q) {

                //Se arma el <select> de tipos y se marca el tipo actual de la pregunta
                var opts = "";
                for (var k = 0; k < _tiposEditor.length; k++) {
                    var sel = (_tiposEditor[k][0] === q.tipo) ? " selected" : ""; //deja seleccionado el suyo
                    opts += '<option value="' + _tiposEditor[k][0] + '"' + sel + '>' + _tiposEditor[k][1] + '</option>';
                }

                lista.append(''
                    + '<li class="list-group-item editor-item" data-id="' + q.id + '"' //data-id = ID real en la BD
                    + ' data-genera="' + escAttr(q.genera) + '"' //se guarda para reenviarlo tal cual al guardar
                    + ' data-titulo="' + escAttr(q.titulo) + '"' //idem, si no se manda el modelo lo borra
                    + ' data-orden-original="' + (i + 1) + '">' //posicion con la que cargo, sirve para detectar movimientos
                    + '  <span class="handle" title="Arrastra para reordenar"><i class="fa fa-hand-paper-o"></i></span>' //la manito
                    + '  <span class="badge-orden">' + (i + 1) + '</span>' //numero de la pregunta; NO cambia al arrastrar
                    + '  <span class="txt-id" title="ID en la base de datos">#' + q.id + '</span>' //ID solo lectura
                    + '  <input type="text" class="form-control in-pregunta" value="' + escAttr(q.pregunta) + '">' //texto editable
                    + '  <select class="form-control in-tipo">' + opts + '</select>' //tipo de respuesta
                    + '  <button type="button" class="btn btn-success btn-sm btn-una" title="Guardar solo esta pregunta"'
                    + '          onclick="guardarUna(this)"><i class="fa fa-save"></i></button>' //guarda solo esta fila
                    + '  <button type="button" class="btn btn-default btn-sm btn-completa" title="Editar completa (novedad y título)"'
                    + '          onclick="abrirFormPregunta(this)"><i class="fa fa-pencil"></i></button>' //abre el formulario completo
                    + '</li>');
            });

            hayCambiosPendientes = false; //recien cargado, nada movido
            $("#avisoPendiente").hide(); //y por lo tanto sin aviso

            initDrag(); //se activan los eventos de arrastre sobre las filas nuevas
        });
}

//Revisa si el acomodo actual difiere del que se cargo, para avisar que hay cambios
//IMPORTANTE: aqui NO se toca el numero de la insignia. Cada pregunta conserva el
//numero con el que se cargo y ese numero VIAJA con ella al arrastrarla.
//Ejemplo: si se mueve la 2 al final, en pantalla queda 1,3,4,5,6,2
//Solo al presionar "Guardar cambios" el servidor renumera a 1,2,3,4,5,6
function marcarCambios() {
    var huboMovimiento = false;

    $("#listaEditor .editor-item").each(function (i) {
        var pos = i + 1; //posicion fisica que ocupa la fila ahora mismo

        //Si su numero original no coincide con donde esta parada, es que se movio
        if (parseInt($(this).attr("data-orden-original"), 10) !== pos) {
            $(this).addClass("movida"); //se resalta para que se note
            huboMovimiento = true;
        } else {
            $(this).removeClass("movida"); //volvio a su lugar original
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
        var li = this.closest('.editor-item');
        if (li) li.setAttribute('draggable', 'true');
    });

    //Al soltar el mouse se desactiva. Si la fila quedara arrastrable siempre,
    //el navegador robaria el clic y no se podria escribir en los campos de texto
    $(cont).off('mouseup.ed').on('mouseup.ed', function () {
        $('.editor-item').attr('draggable', 'false');
    });

    if (cont.getAttribute('data-dnd') === '1') return; //evita registrar los listeners dos veces
    cont.setAttribute('data-dnd', '1'); //marca el contenedor como ya preparado

    //Empieza el arrastre
    cont.addEventListener('dragstart', function (e) {
        var it = e.target.closest ? e.target.closest('.editor-item') : null;
        if (!it) return; //no se agarro una fila
        _arrastrado = it; //se recuerda cual es

        //setData es obligatorio: sin esto Firefox ni siquiera inicia el arrastre
        try { e.dataTransfer.setData('text/plain', it.getAttribute('data-id') || ''); } catch (x) {}
        e.dataTransfer.effectAllowed = 'move';

        //El setTimeout evita que el navegador capture la fila ya semitransparente
        setTimeout(function () { it.classList.add('dragging'); }, 0);
    });

    //Termina el arrastre
    cont.addEventListener('dragend', function () {
        if (_arrastrado) { _arrastrado.classList.remove('dragging'); } //quita el efecto visual
        _arrastrado = null;
        $('.editor-item').attr('draggable', 'false'); //se vuelve a proteger los inputs
        marcarCambios(); //se revisa si quedo algo fuera de su posicion original
    });

    //Mientras se pasa por encima de otra fila
    cont.addEventListener('dragover', function (e) {
        e.preventDefault(); //sin esto el navegador no deja soltar
        var t = e.target.closest ? e.target.closest('.editor-item') : null;
        if (!t || t === _arrastrado) return; //ignora si es la misma fila

        var r = t.getBoundingClientRect();
        var despues = (e.clientY - r.top) > r.height / 2; //paso la mitad? entonces va debajo
        cont.insertBefore(_arrastrado, despues ? t.nextSibling : t); //se reacomoda en vivo

        marcarCambios(); //se marca en vivo lo que quedo movido
    });

    cont.addEventListener('drop', function (e) { e.preventDefault(); }); //solo evita el comportamiento por defecto
}

//Guarda SOLO la fila del boton verde presionado (texto y tipo). No cambia el orden
function guardarUna(btn) {
    var li = $(btn).closest('.editor-item'); //fila a la que pertenece el boton

    //FormData porque el controlador espera estos nombres por POST
    var fd = new FormData();
    fd.append('idPregunta',     li.data('id'));
    fd.append('pregunta',       li.find('.in-pregunta').val());
    fd.append('tipo-respuesta', li.find('.in-tipo').val());
    fd.append('genera-novedad', li.attr('data-genera') || ''); //se reenvia igual para no borrarlo
    fd.append('Titulo-novedad', li.attr('data-titulo') || ''); //idem

    hanaBoton(btn, true, "");
    $.ajax({
        url: "../Control/PreguntasListasControl.php?op=guardar",
        type: "POST",
        data: fd,
        contentType: false, //se deja que el navegador arme el multipart
        processData: false, //no convertir el FormData a query string
        success: function () {
            alert("Pregunta guardada con éxito");
        },
        error: function (xhr) {
            hanaErrorAjax(xhr, "No se pudo guardar la pregunta.");
        },
        complete: function () {
            hanaBoton(btn, false);
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
            tipo:     $(this).find('.in-tipo').val(),
            genera:   $(this).attr('data-genera') || '',
            titulo:   $(this).attr('data-titulo') || ''
        });
    });

    if (!items.length) { alert("No hay preguntas para guardar."); return; } //nada que enviar

    hanaBoton("#btnGuardarTodo", true);
    $.post("../Control/PreguntasListasControl.php?op=guardarLote",
        { items: JSON.stringify(items) }, //se manda como texto JSON
        function () {
            alert("Cambios guardados con éxito");
            hayCambiosPendientes = false; //ya quedo todo en la BD
            cargarPreguntas(); //se recarga para ver la numeracion ya consolidada
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

    //Avisa si intenta cerrar o recargar la pestana con cambios de orden pendientes
    window.addEventListener('beforeunload', function (e) {
        if (hayCambiosPendientes) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
});

//---------------------------------------------------------------------------
// Agregar una pregunta nueva, o editarla completa
//---------------------------------------------------------------------------

//Tipos que pueden generar novedad: los que tienen una respuesta "negativa"
//(No en si/no; Malo o Regular en lista). Los demás no tienen cómo generarla
var _tiposConNovedad = ['si/no', 'lista'];
var _titulosCargados = false;

//Carga una sola vez los títulos de novedad
function cargarTitulosPregunta(listo) {
    if (_titulosCargados) { if (listo) { listo(); } return; }
    $.post("../Control/TituloControl.php?op=select", function (html) {
        //El servidor ya trae su propia opción vacía ("Seleccione Titulo..."); se
        //cambia por un texto más claro en vez de agregar una segunda
        $("#fpTitulo").html(html);
        $("#fpTitulo option[value='']").remove();
        $("#fpTitulo").prepend('<option value="">Selecciona el título...</option>');
        _titulosCargados = true;
        if (listo) { listo(); }
    });
}

//Muestra u oculta lo de "genera novedad" según el tipo elegido
function ajustarCamposNovedad() {
    var puede = _tiposConNovedad.indexOf($("#fpTipo").val()) !== -1;
    $("#fpGeneraGrupo").toggle(puede);
    if (!puede) { $("#fpGenera").val("0"); }
    $("#fpTituloGrupo").toggle(puede && $("#fpGenera").val() === "1");
}

//boton: null = pregunta nueva; si viene de una fila, se edita esa
function abrirFormPregunta(boton) {
    var opts = "";
    for (var k = 0; k < _tiposEditor.length; k++) {
        opts += '<option value="' + _tiposEditor[k][0] + '">' + _tiposEditor[k][1] + '</option>';
    }
    $("#fpTipo").html(opts);

    //Ubicación: al final, o después de cualquiera de las preguntas actuales
    var pos = '<option value="final">Al final de la lista</option>';
    $("#listaEditor .editor-item").each(function () {
        var txt = $(this).find(".in-pregunta").val();
        pos += '<option value="' + $(this).data("id") + '">Después de: ' +
               $('<div>').text(txt.length > 60 ? txt.substring(0, 60) + '...' : txt).html() + '</option>';
    });
    $("#fpPosicion").html(pos);

    if (boton) {
        //Editar: se toman los datos de la fila, incluidos los que no se ven
        var fila = $(boton).closest(".editor-item");
        $("#fpId").val(fila.data("id"));
        $("#fpPregunta").val(fila.find(".in-pregunta").val());
        $("#fpTipo").val(fila.find(".in-tipo").val());
        $("#fpGenera").val(String(fila.data("genera")) === "1" ? "1" : "0");
        $("#panelPreguntaTitulo").text("Editar pregunta #" + fila.data("id"));
        $("#fpPosicionGrupo").hide(); //la ubicación se cambia arrastrando
        cargarTitulosPregunta(function () { $("#fpTitulo").val(String(fila.data("titulo") || "")); ajustarCamposNovedad(); });
    } else {
        $("#fpId").val("");
        $("#fpPregunta").val("");
        $("#fpTipo").val("si/no");
        $("#fpGenera").val("0");
        $("#panelPreguntaTitulo").text("Nueva pregunta");
        $("#fpPosicionGrupo").show();
        cargarTitulosPregunta(function () { $("#fpTitulo").val(""); ajustarCamposNovedad(); });
    }
    ajustarCamposNovedad();
    $("#panelPregunta").slideDown(150);
    $("#fpPregunta").focus();
    $(".right_col").scrollTop(0); window.scrollTo(0, 0);
}

function cerrarFormPregunta() {
    $("#panelPregunta").slideUp(150);
}

function guardarFormPregunta() {
    var pregunta = $.trim($("#fpPregunta").val());
    var genera = $("#fpGenera").val();
    var titulo = $("#fpTitulo").val();

    if (!pregunta) { alert("Escribe la pregunta."); $("#fpPregunta").focus(); return; }
    if (pregunta.length > 200) { alert("La pregunta puede tener máximo 200 caracteres."); return; }
    if (genera === "1" && !titulo) { alert("Si genera novedad, elige el título de la novedad."); return; }

    //Si hay cambios de orden sin guardar, se perderían al recargar la lista
    var seguir = function () {
        var datos = {
            idGrupo: idGrupoEditar,
            pregunta: pregunta,
            "tipo-respuesta": $("#fpTipo").val(),
            "genera-novedad": genera,
            "Titulo-novedad": genera === "1" ? titulo : ""
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

$(function () {
    $(document).on("change", "#fpTipo, #fpGenera", ajustarCamposNovedad);
});
