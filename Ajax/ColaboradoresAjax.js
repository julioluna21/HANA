//Configuración - Colaboradores (Vista/ColaboradoresVista.php)
//Las personas que trabajan en los proyectos: documento, nombre, correo y peajes asignados.
//Al registrar a una persona sale una ventana que pregunta si se le crea el usuario para
//entrar a HANA (con un rol que no administre); la contraseña temporal le llega por correo.
//El usuario, el rol y la contraseña se cambian después en Usuarios.
//Habla con Control/ColaboradoresControl.php

var tabla; //la tabla de personas (DataTables)
var URL_COL = '../Control/ColaboradoresControl.php';
var listaCentros = []; //los números de todos los peajes activos ("Seleccionar todos")

//Arranca la pantalla: la tabla de personas; el formulario se abre con «Nuevo colaborador» o «Editar»
function iniciar()
{
    mostrarform(false);
    listar();
    $("#formColaborador").on("submit", function (evento) { guardar(evento); });
    $("#formUsuario").on("submit", function (evento) { crearUsuario(evento); });
    //Las casillas de los peajes, agrupadas por proyecto
    $.post(URL_COL + "?op=centros", function (html) { $("#centros").html(html); });
    $.post(URL_COL + "?op=centroslistatodos", function (ids) { listaCentros = String(ids).split(','); });
}

//Marca o desmarca todos los peajes con la casilla "Seleccionar todos"
function seleccionarTodos()
{
    var marcar = $('#todosc').prop('checked');
    for (var i = 0; i < listaCentros.length; i++) { $("#r" + listaCentros[i]).prop("checked", marcar); }
}

//Muestra el formulario (true) o la tabla (false)
function mostrarform(mostrar)
{
    if (mostrar) {
        $('#formularioregistros').show();
        $('#listadoregistros').hide();
        $("#btnGuardar").prop("disabled", false);
        $("#documento").focus();
    } else {
        $('#formularioregistros').hide();
        $('#listadoregistros').show();
    }
}

//Cierra el formulario sin guardar
function cancelarform()
{
    limpiar();
    mostrarform(false);
}

//Deja el formulario en blanco
function limpiar()
{
    document.getElementById("formColaborador").reset();
    $("#idcolaborador").val("");
    $('#documento, #persona, #correo').prop('readonly', false);
    $('#centros input[type=checkbox]').prop("checked", false).prop("disabled", false);
    $("#usuarioActual").text("—");
    $("#ayudaUsuario").text("Al registrarla se pregunta si se le crea el usuario. El usuario, el rol y la contraseña se cambian en Usuarios.");
    $("#btnGuardar").show();
}

//Abre el formulario para registrar a una persona nueva
function nuevo()
{
    limpiar();
    mostrarform(true);
}

//La tabla de personas
function listar()
{
    tabla = $('#tbllistado').dataTable({
        "aProcessing": true,
        "aServerSide": true,
        dom: 'Bfrtip',
        buttons: [],
        "ajax": {
            url: URL_COL + '?op=listar',
            type: "get",
            dataType: "json",
            error: function (xhr) { console.log(xhr.responseText); }
        },
        "bDestroy": true,
        "iDisplayLength": 10,
        "order": []
    }).DataTable();
}

//Registra a la persona o guarda los cambios de sus datos y sus peajes.
//Si la persona es nueva, después pregunta si se le crea el usuario
function guardar(evento)
{
    evento.preventDefault();
    hanaBoton("#btnGuardar", true);
    $.ajax({
        url: URL_COL + "?op=guardar",
        type: "POST",
        data: new FormData($("#formColaborador")[0]),
        contentType: false,
        processData: false,
        dataType: "json",
        error: function (xhr) { hanaErrorAjax(xhr, "No se pudo guardar."); },
        success: function (respuesta)
        {
            if (respuesta.error) { alert(respuesta.mensaje); return; }
            mostrarform(false);
            limpiar();
            tabla.ajax.reload();
            if (respuesta.nuevo) {
                abrirVentanaUsuario(respuesta.id, respuesta.nombre, respuesta.documento, respuesta.mensaje);
            } else {
                alert(respuesta.mensaje);
            }
        },
        complete: function () { hanaBoton("#btnGuardar", false); }
    });
}

//La ventana «¿Crear el usuario?»: nombre de usuario propuesto (el documento) y los roles que se pueden dar aquí
function abrirVentanaUsuario(idPersona, nombre, documento, aviso)
{
    $("#usuIdPersona").val(idPersona);
    $("#usuNombre").val(documento || "");
    $("#usuPregunta").text((aviso ? aviso + " " : "") + "¿Le creamos a " + nombre + " un usuario para entrar a HANA?");
    $("#usuRol").html("");
    $.post(URL_COL + "?op=roles", function (opciones) { $("#usuRol").html(opciones); })
        .fail(function (xhr) { hanaErrorAjax(xhr, "No se pudieron cargar los roles."); });
    $("#modalUsuario").modal("show");
}

//Desde la tabla: crearle el usuario a una persona que no tiene
function preguntarUsuario(idPersona)
{
    $.post(URL_COL + "?op=mostrar", { idcolaborador: idPersona }, function (respuesta) {
        var persona = JSON.parse(respuesta);
        if (!persona) { alert("Esa persona ya no existe. Actualiza la página."); return; }
        abrirVentanaUsuario(persona.ID_COLABORADOR, persona.NOM_COLABORADOR, persona.DOC_COLABORADOR, "");
    }).fail(function (xhr) { hanaErrorAjax(xhr, "No se pudieron cargar los datos."); });
}

//«Sí, crear el usuario»: lo crea y le manda la contraseña temporal por correo
function crearUsuario(evento)
{
    evento.preventDefault();
    hanaBoton("#btnUsuarioSi", true);
    $.post(URL_COL + "?op=crearUsuario", $("#formUsuario").serialize(), function (respuesta) {
        if (hanaTipoMensaje(respuesta) === "error") { alert(respuesta); return; }
        $("#modalUsuario").modal("hide");
        alert(respuesta); //a dónde le llegaron los datos (o los datos, si el correo no salió)
        tabla.ajax.reload();
    }).fail(function (xhr) { hanaErrorAjax(xhr, "No se pudo crear el usuario."); })
      .always(function () { hanaBoton("#btnUsuarioSi", false); });
}

//Trae a una persona con sus peajes y la carga en el formulario. Su usuario se ve, pero se cambia en Usuarios
function mostrar(idColaborador)
{
    hanaCargando(true);
    $.post(URL_COL + "?op=mostrar", { idcolaborador: idColaborador }, function (respuesta) {
        hanaCargando(false);
        var persona = JSON.parse(respuesta);
        if (!persona) { alert("Esa persona ya no existe. Actualiza la página."); return; }
        limpiar();
        $("#idcolaborador").val(persona.ID_COLABORADOR);
        $("#documento").val(persona.DOC_COLABORADOR);
        $("#persona").val(persona.NOM_COLABORADOR);
        $("#correo").val(persona.MAIL_COLABORADOR);
        $("#usuarioActual").text(persona.USUARIO ? persona.USUARIO + " · " + (persona.NOM_ROL_USUARIO_SISTEMA || "sin rol") : "Sin usuario");
        $("#ayudaUsuario").text(persona.USUARIO ? "El usuario, el rol y la contraseña se cambian en Usuarios."
                                                : "Para crearle el usuario, usa el botón de la tabla o la pantalla Usuarios.");
        $.each(persona.PEAJES || [], function (i, idCentro) { $("#r" + idCentro).prop("checked", true); });
        //A quien administra solo lo cambia quien tiene 24M: se muestra sin poder guardar
        if (!persona.EDITABLE) {
            $('#documento, #persona, #correo').prop('readonly', true);
            $('#centros input[type=checkbox]').prop("disabled", true);
            $("#btnGuardar").hide();
            $("#ayudaUsuario").text("Esta persona tiene un rol que administra el sistema: solo la cambia quien tiene la casilla 24M.");
        }
        mostrarform(true);
    }).fail(function (xhr) {
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar los datos.");
    });
}

//Muestra en una ventana los peajes asignados a una persona
function centros(idColaborador)
{
    hanaCargando(true);
    $.post(URL_COL + "?op=MostrarCentros", { idcolaborador: idColaborador }, function (data) {
        hanaCargando(false);
        var lista = $.trim(String(data).replace(/<br\s*\/?>/gi, "\n"));
        alert(lista !== "" ? $("<textarea>").html(lista).text() : "Esta persona no tiene peajes asignados.", null, { titulo: "Peajes asignados" });
    }).fail(function (xhr) {
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar los peajes.");
    });
}

//Desactiva a la persona (pregunta antes). No se borra: deja de salir en las listas y no entra
function anular(idColaborador)
{
    hanaConfirmar("¿Desactivar a esta persona? Dejará de salir en las listas y, si tiene usuario, no podrá entrar.", function () {
        $.post(URL_COL + "?op=anular", { idcolaborador: idColaborador }, function (respuesta) {
            alert(respuesta);
            tabla.ajax.reload();
        }).fail(function (xhr) { hanaErrorAjax(xhr, "No se pudo desactivar."); });
    });
}

//Vuelve a activar a la persona (pregunta antes)
function activar(idColaborador)
{
    hanaConfirmar("¿Activar a esta persona?", function () {
        $.post(URL_COL + "?op=activar", { idcolaborador: idColaborador }, function (respuesta) {
            alert(respuesta);
            tabla.ajax.reload();
        }).fail(function (xhr) { hanaErrorAjax(xhr, "No se pudo activar."); });
    });
}

iniciar();
