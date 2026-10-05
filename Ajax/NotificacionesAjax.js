//Pantalla de notificaciones automaticas por correo
//Trabaja sobre Vista/NotificacionesVista.php

var tabla; //referencia a la DataTable del listado

//Textos de ayuda segun la frecuencia elegida
var _ayudas = {
    "inmediato": "Se envía un correo cada vez que alguien termina de diligenciar esta lista.",
    "diario":    "Se envía un solo correo con todo lo diligenciado el día anterior, agrupado por persona.",
    "semanal":   "Se envía un solo correo con todo lo diligenciado en los últimos 7 días, agrupado por persona."
};

//Arranque. Cada bloque va en su propio try/catch a proposito: si uno falla
//(por ejemplo la tabla), los demas campos siguen funcionando igual
$(function () {

    try { cargarListas(); }        catch (e) { console.log("cargarListas:", e); }
    try { cargarColaboradores(); } catch (e) { console.log("cargarColaboradores:", e); }
    try { listar(); }              catch (e) { console.log("listar:", e); }

    //Ayuda que cambia segun la frecuencia elegida
    $("#frecuencia").on("change", function () {
        $("#ayudaFrecuencia").text(_ayudas[$(this).val()] || "");
    }).trigger("change");
});

//Llena el <select> con las listas de chequeo existentes.
//OJO: se manda search= (vacio) a proposito. El controlador, si no recibe ese
//parametro, usa '0' por defecto y termina buscando los nombres que contengan
//un "0", devolviendo una lista vacia. Con search= vacio la consulta trae todas.
function cargarListas() {
    $.getJSON("../Control/GruposListasControl.php?op=select&search=", function (data) {
        var html = '<option value="">-- Selecciona una lista --</option>';
        $.each(data, function (i, item) {
            html += '<option value="' + item.id + '">' + item.nombre + '</option>';
        });
        $("#idGrupo").html(html);
    });
}

//Llena el atajo de colaboradores registrados (los que tienen correo)
function cargarColaboradores() {
    $.getJSON("../Control/NotificacionesControl.php?op=selectColaborador&q=", function (data) {
        var html = '<option value="">-- Agregar colaborador registrado --</option>';
        $.each(data, function (i, item) {
            //item.id es el correo, item.text es "Nombre (correo)"
            html += '<option value="' + item.id + '">' + item.text + '</option>';
        });
        $("#buscarColaborador").html(html);
    });
}

//Agrega el correo del colaborador elegido al campo de destinatarios
function agregarColaborador() {
    var correo = $("#buscarColaborador").val();
    if (!correo) { alert("Selecciona primero un colaborador."); return; }

    var actual = $.trim($("#destinatarios").val());

    //Se evita repetir el mismo correo dos veces
    if (actual.indexOf(correo) !== -1) { alert("Ese correo ya está en la lista."); return; }

    //Si ya habia algo, se separa con coma
    $("#destinatarios").val(actual === "" ? correo : actual + ", " + correo);
    $("#buscarColaborador").val(""); //se deja listo para agregar otro
}

//Pinta la tabla de notificaciones ya configuradas
function listar() {
    tabla = $('#tblNotificaciones').dataTable({
        aProcessing: true,
        aServerSide: true,
        destroy: true, //permite recargarla sin duplicar
        ajax: {
            url: '../Control/NotificacionesControl.php?op=listar',
            type: 'get',
            dataType: 'json',
            //Si la tabla notificaciones_listas no existe todavia, el error
            //aparece aqui en la consola en vez de quedar en silencio
            error: function (e) { console.log('Error al listar notificaciones:', e.responseText); }
        },
        bDestroy: true,
        iDisplayLength: 10,
        order: [] //se respeta el orden que manda el servidor
    }).DataTable();
}

//Guarda la configuracion (alta o edicion, segun si hay id)
function guardaryeditar(e) {
    e.preventDefault(); //no se recarga la pagina

    //Validaciones basicas antes de mandar nada al servidor
    if (!$("#idGrupo").val()) { alert("Selecciona la lista de chequeo."); return false; }

    var dest = $.trim($("#destinatarios").val());
    if (dest === "") { alert("Escribe al menos un destinatario."); return false; }

    hanaBoton("#btnGuardar", true); //evita doble clic

    $.post("../Control/NotificacionesControl.php?op=guardar", {
        idNotificacion: $("#idNotificacion").val(),
        idGrupo:        $("#idGrupo").val(),
        destinatarios:  dest,
        frecuencia:     $("#frecuencia").val(),
        asunto:         $("#asunto").val()
    })
    .done(function () {
        alert("Notificación guardada con éxito");
        limpiar();
        tabla.ajax.reload();
    })
    .fail(function (xhr) {
        //El servidor devuelve el motivo cuando un correo esta mal escrito
        hanaErrorAjax(xhr, "No se pudo guardar la notificación.");
    })
    .always(function () {
        hanaBoton("#btnGuardar", false);
    });

    return false;
}

//Carga una notificacion existente en el formulario para editarla
function mostrar(id) {
    hanaCargando(true); //aviso de carga mientras llega la configuración
    $.post("../Control/NotificacionesControl.php?op=mostrar", { idNotificacion: id }, function (data) {
        hanaCargando(false);
        data = JSON.parse(data);

        $("#idNotificacion").val(data.ID_NOTIFICACION);
        $("#idGrupo").val(data.ID_GRUPO_LISTA_CHEQUEO);
        $("#frecuencia").val(data.FRECUENCIA).trigger("change");
        $("#asunto").val(data.ASUNTO);

        //Los correos se muestran separados por coma y espacio, mas comodos de leer
        $("#destinatarios").val((data.DESTINATARIOS || "").split(",").join(", "));

        $('html, body').animate({ scrollTop: 0 }, 300); //sube al formulario
    })
    .fail(function (xhr) {
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudo cargar la notificación.");
    });
}

//Desactiva una notificacion (no se borra)
function anular(id) {
    hanaConfirmar("¿Desactivar esta notificación? Dejará de enviarse.", function () {
        $.post("../Control/NotificacionesControl.php?op=anular", { idNotificacion: id }, function () {
            tabla.ajax.reload();
        })
        .fail(function (xhr) {
            hanaErrorAjax(xhr, "No se pudo desactivar la notificación.");
        });
    });
}

//Reactiva una notificacion desactivada
function activar(id) {
    $.post("../Control/NotificacionesControl.php?op=activar", { idNotificacion: id }, function () {
        tabla.ajax.reload();
    })
    .fail(function (xhr) {
        hanaErrorAjax(xhr, "No se pudo activar la notificación.");
    });
}

//Manda un correo de prueba a los destinatarios escritos, sin guardar nada
function probarEnvio() {
    var dest = $.trim($("#destinatarios").val());
    if (dest === "") { alert("Escribe primero los destinatarios."); return; }

    hanaBoton("#btnProbar", true, "Enviando...");
    $.post("../Control/NotificacionesControl.php?op=probar", { destinatarios: dest })
        .done(function () { alert("Correo de prueba enviado. Revisa la bandeja de entrada (y la carpeta de spam)."); })
        .fail(function (xhr) {
            //El servidor devuelve el motivo exacto del fallo (error de SMTP, correo mal escrito, etc.)
            hanaErrorAjax(xhr, "No se pudo enviar el correo de prueba.");
        })
        .always(function () {
            hanaBoton("#btnProbar", false);
        });
}

//Deja el formulario en blanco, listo para una configuracion nueva
function limpiar() {
    $("#idNotificacion").val("");
    $("#idGrupo").val("");
    $("#asunto").val("");
    $("#destinatarios").val("");
    $("#buscarColaborador").val("");
    $("#frecuencia").val("inmediato").trigger("change");
}