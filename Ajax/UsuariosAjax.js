//Configuración - Usuarios (Vista/UsuariosVista.php)
//El acceso de cada persona: rol, nombre de usuario, peajes asignados y contraseña
//temporal (le llega por correo). Sus datos (documento, nombre, correo) solo se ven:
//se cambian en Colaboradores, donde también se registra a las personas nuevas.
//Todo vive en la misma fila (colaboradores) y el número de usuario es el del colaborador.
//Habla con Control/UsuariosControl.php

var tabla;//variable global
var vrca;
var Centroslista;

//Función que se ejecuta al inicio
function init()
{
    vrca = $("#cambioclave").val();

    //Modo "Cambiar clave" del menú: solo el formulario de la clave propia
    if (vrca == "canvu") {
        $('#formularioregistros, #listadoregistros').hide();
        $('#formclave').show();
        $("#formularioclave").on("submit", function(evento){ cambiarclave(evento); });
        return;
    }

    $('#formclave').hide();
    mostrarform(false);
    listar();

    $("#demo-form2").on("submit", function(evento){ guardar(evento); });

    //Los peajes (casillas agrupadas por proyecto) y los roles activos
    $.post("../Control/UsuariosControl.php?op=centros", function(data){
        $("#centros").html(data);
    });
    $.post("../Control/RolesControl.php?op=select", function(data){
        $("#rol").html(data);
    });
    $.post("../Control/UsuariosControl.php?op=centroslistatodos", function(data){
        Centroslista = String(data).split(',');
    });
    $('#rol').select2({ width: '100%' });
}

//Marca o desmarca todos los centros con la casilla "Seleccionar todos"
function seleccionarTodos()
{
    var marcar = $('#todosc').prop('checked');
    for (var i = 0; i < (Centroslista || []).length; i++) {
        $("#r" + Centroslista[i]).prop("checked", marcar);
    }
}

//Muestra el formulario (true) o la tabla (false)
function mostrarform(flag)
{
    if (flag) {
        $('#formularioregistros').show();
        $('#listadoregistros').hide();
        $("#btnGuardar").prop("disabled", false);
        $("#rol").focus();
    } else {
        $('#formularioregistros').hide();
        $('#listadoregistros').show();
        $("#btnGuardar").prop("disabled", false);
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
    document.getElementById("demo-form2").reset();
    document.getElementById("formularioclave").reset();
    $('#rol').val("").trigger('change');
    $("#idusuario").val("");
    $('#centros input[type=checkbox]').prop("checked", false);
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
            url: '../Control/UsuariosControl.php?op=listar',
            type: "get",
            dataType: "json",
            error: function(xhr){ console.log(xhr.responseText); }
        },
        "bDestroy": true,
        "iDisplayLength": 10,
        "order": []
    }).DataTable();
}

//Guarda el acceso de la persona: rol, usuario y peajes
function guardar(evento)
{
    evento.preventDefault();
    hanaBoton("#btnGuardar", true);
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
        url: "../Control/UsuariosControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        error: function(xhr){ hanaErrorAjax(xhr, "No se pudo guardar."); },
        success: function(datos)
        {
            alert(datos); //el servidor responde con el mensaje exacto (y la contraseña temporal, si la hay)
            if (hanaTipoMensaje(datos) !== "error") {
                mostrarform(false);
                limpiar();
                tabla.ajax.reload();
            }
        },
        complete: function(){ hanaBoton("#btnGuardar", false); }
    });
}

//Trae a una persona con sus peajes y la carga en el formulario
function mostrar(idusuario)
{
    hanaCargando(true);
    var pidePersona = $.post("../Control/UsuariosControl.php?op=mostrar", {idusuario : idusuario});
    var pideCentros = $.post("../Control/UsuariosControl.php?op=centroslista", {idusuario : idusuario});
    $.when(pidePersona, pideCentros)
        .done(function(resPersona, resCentros){
            hanaCargando(false);
            var data = JSON.parse(resPersona[0]);
            limpiar();
            $("#idusuario").val(data.ID_COLABORADOR);
            $("#documento").val(data.DOC_COLABORADOR);
            $("#persona").val(data.NOM_COLABORADOR);
            $("#correo").val(data.MAIL_COLABORADOR);
            $("#usuario").val(data.USUARIO || "");
            //Si su rol está apagado no sale en la lista: se agrega para verlo
            if (data.ID_ROL_USUARIO_SISTEMA && !$('#rol option[value="' + data.ID_ROL_USUARIO_SISTEMA + '"]').length) {
                $('#rol').append(new Option(data.NOM_ROL_USUARIO_SISTEMA || data.ID_ROL_USUARIO_SISTEMA, data.ID_ROL_USUARIO_SISTEMA));
            }
            $('#rol').val(data.ID_ROL_USUARIO_SISTEMA).trigger('change');
            var lista = String(resCentros[0]).split(',');
            for (var i = 0; i < lista.length; i++) { $("#r" + lista[i]).prop("checked", true); }
            mostrarform(true);
            //La contraseña temporal solo sirve para quien ya tiene usuario
            $('#btnclave').toggle(!!data.USUARIO);
        })
        .fail(function(xhr){
            hanaCargando(false);
            hanaErrorAjax(xhr, "No se pudieron cargar los datos.");
        });
}

//Le da a la persona una contraseña temporal nueva: le llega por correo (si el correo no sale, se muestra aquí una sola vez)
function claveTemporal()
{
    var id = $("#idusuario").val();
    if (!id) { return; }
    hanaConfirmar("¿Darle una contraseña temporal nueva? La que tiene deja de servir.", function(){
        $.post("../Control/UsuariosControl.php?op=claveTemporal", {idusuario : id}, function(data){
            alert(data);
        }).fail(function(xhr){ hanaErrorAjax(xhr, "No se pudo cambiar la contraseña."); });
    });
}

//Desactiva a la persona (pregunta antes)
function anular(idusuario){
    hanaConfirmar("¿Desactivar a esta persona? No podrá entrar y dejará de salir en las listas.", function(){
        $.post("../Control/UsuariosControl.php?op=anular", {idusuario : idusuario}, function(data){
            alert(data);
            tabla.ajax.reload();
        }).fail(function(xhr){ hanaErrorAjax(xhr, "No se pudo desactivar."); });
    });
}
//Vuelve a activar a la persona (pregunta antes)
function activar(idusuario){
    hanaConfirmar("¿Activar a esta persona?", function(){
        $.post("../Control/UsuariosControl.php?op=activar", {idusuario : idusuario}, function(data){
            alert(data);
            tabla.ajax.reload();
        }).fail(function(xhr){ hanaErrorAjax(xhr, "No se pudo activar."); });
    });
}

//Muestra en una ventana los peajes asignados a una persona
function centros(idusuario){
    hanaCargando(true);
    $.post("../Control/UsuariosControl.php?op=MostrarCentros", {idusuario : idusuario}, function(data)
    {
        hanaCargando(false);
        var lista = $.trim(String(data).replace(/<br\s*\/?>/gi, "\n"));
        alert(lista !== "" ? lista : "Esta persona no tiene peajes asignados.", null, { titulo: "Peajes asignados" });
    })
    .fail(function(xhr){
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar los peajes.");
    });
}

//Cambia la clave propia: revisa que las dos coincidan
function cambiarclave(evento)
{
    evento.preventDefault();
    if (!validaPwds()) { return; }
    var boton = $("#formularioclave button[type=submit]");
    hanaBoton(boton, true, "Cambiando...");
    $.ajax({
        url: "../Control/UsuariosControl.php?op=cambiarclave",
        type: "POST",
        data: new FormData($("#formularioclave")[0]),
        contentType: false,
        processData: false,
        error: function(xhr){ hanaErrorAjax(xhr, "No se pudo cambiar la contraseña."); },
        success: function(datos)
        {
            alert(datos);
            if (hanaTipoMensaje(datos) !== "error") { window.history.go(-1); }
        },
        complete: function(){ hanaBoton(boton, false); }
    });
}

//¿La clave nueva sirve? Tiene el mínimo de caracteres y las dos escritas son iguales.
//El servidor vuelve a revisar las mismas reglas (Modelo/HanaClave.php)
var CLAVE_LARGO_MINIMO = 8;
function validaPwds() {
    var clave = $("#clave").val(), claveRepetida = $("#clave2").val();
    if (clave.length < CLAVE_LARGO_MINIMO) {
        alert("La contraseña debe tener al menos " + CLAVE_LARGO_MINIMO + " caracteres.");
        $("#clave").focus();
        return false;
    }
    if (claveRepetida === clave) { return true; }
    alert("Las contraseñas no coinciden. Vuelve a escribirlas.");
    $("#clave, #clave2").val('');
    return false;
}

init();
