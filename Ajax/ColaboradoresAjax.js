//Configuración - Colaboradores (Vista/ColaboradoresVista.php)
//Las personas que trabajan en los proyectos: documento, nombre y correo.
//Al registrar a una persona se elige su rol (entre los que no administran) y el servidor
//le crea el usuario; le llegan por correo el usuario y la contraseña temporal.
//El rol, el usuario, los peajes y la contraseña se cambian después en Usuarios.
//Habla con Control/ColaboradoresControl.php

var tabla; //la tabla de personas (DataTables)
var URL_COL = '../Control/ColaboradoresControl.php';

//Arranca la pantalla: la tabla de personas; el formulario se abre con «Nuevo colaborador» o «Editar»
function iniciar()
{
    mostrarform(false);
    listar();
    $("#formColaborador").on("submit", function (evento) { guardar(evento); });
}

//Los roles que se pueden dar al registrar (el básico sale elegido)
function cargarRoles()
{
    $.post(URL_COL + "?op=roles", function (opciones) {
        $("#rol").html(opciones);
    }).fail(function (xhr) { hanaErrorAjax(xhr, "No se pudieron cargar los roles."); });
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
    $("#btnGuardar").show();
}

//Abre el formulario para registrar a una persona nueva, con los roles que se pueden dar
function nuevo()
{
    limpiar();
    cargarRoles();
    $("#rol").prop("disabled", false);
    $("#ayudaRol").text("Al registrarla se le crea su usuario (su documento) y le llega por correo con una contraseña temporal. Los roles que administran, como ADMIN TEC, se dan en Usuarios.");
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

//Registra a la persona (y su usuario) o guarda los cambios de sus datos
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
        error: function (xhr) { hanaErrorAjax(xhr, "No se pudo guardar."); },
        success: function (respuesta)
        {
            alert(respuesta); //el servidor responde con el mensaje exacto (y a dónde llegaron los datos para entrar)
            if (hanaTipoMensaje(respuesta) !== "error") {
                mostrarform(false);
                limpiar();
                tabla.ajax.reload();
            }
        },
        complete: function () { hanaBoton("#btnGuardar", false); }
    });
}

//Trae a una persona y la carga en el formulario. El rol se ve, pero se cambia en Usuarios
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
        //El rol que tiene, solo para ver
        $("#rol").html($('<option>').val(persona.ID_ROL_USUARIO_SISTEMA || '').text(persona.NOM_ROL_USUARIO_SISTEMA || 'Sin rol')).prop("disabled", true);
        $("#ayudaRol").text("El rol, el usuario, los peajes y la contraseña se cambian en Usuarios.");
        //A quien administra solo lo cambia quien tiene 24M: se muestra sin poder guardar
        if (!persona.EDITABLE) {
            $('#documento, #persona, #correo').prop('readonly', true);
            $("#btnGuardar").hide();
            $("#ayudaRol").text("Esta persona tiene un rol que administra el sistema: solo la cambia quien tiene la casilla 24M.");
        }
        mostrarform(true);
    }).fail(function (xhr) {
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar los datos.");
    });
}

//Desactiva a la persona (pregunta antes). No se borra: deja de entrar y de salir en las listas
function anular(idColaborador)
{
    hanaConfirmar("¿Desactivar a esta persona? No podrá entrar y dejará de salir en las listas.", function () {
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
