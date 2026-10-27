//Configuración - Usuarios (Vista/UsuariosVista.php)
//El usuario de cada persona para entrar a HANA: crearlo a quien no tiene, su nombre de
//usuario, su rol, la contraseña (la escribe el administrador y le llega por correo) y
//bloquear o devolver el acceso. Sus datos (documento, nombre, correo y peajes) solo se ven: se cambian en
//Colaboradores, donde también se registra a las personas nuevas.
//El número que se usa es el de la persona (ID_COLABORADOR).
//Habla con Control/UsuariosControl.php

var tabla; //la tabla de personas (DataTables)
var vrca;  //"canvu" cuando la pantalla se abre para cambiar la clave propia

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
    //Ver u ocultar la contraseña que se escribe
    $("#btnVerClave").on("click", function(){
        var ver = $("#claveNueva").attr("type") === "password";
        $("#claveNueva, #claveNueva2").attr("type", ver ? "text" : "password");
        $(this).find("i").toggleClass("fa-eye", !ver).toggleClass("fa-eye-slash", ver);
    });
    //Una contraseña al azar, ya escrita en los dos campos y a la vista
    $("#btnGenerarClave").on("click", function(){
        var letras = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789", clave = "", azar = new Uint32Array(10);
        window.crypto.getRandomValues(azar);
        for (var i = 0; i < azar.length; i++) { clave += letras.charAt(azar[i] % letras.length); }
        $("#claveNueva, #claveNueva2").val(clave).attr("type", "text");
        $("#btnVerClave i").removeClass("fa-eye").addClass("fa-eye-slash");
    });

    //Los roles activos (aquí se puede dar cualquiera, también los que administran)
    $.post("../Control/RolesControl.php?op=select", function(data){
        $("#rol").html(data);
    });
    $('#rol').select2({ width: '100%' });
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
    $("#claveNueva, #claveNueva2").attr("type", "password");
    $("#btnVerClave i").removeClass("fa-eye-slash").addClass("fa-eye");
    $('#rol').val("").trigger('change');
    $("#idusuario").val("");
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

//Guarda el usuario de la persona: si no tenía, se le crea; si se escribió una contraseña, se le asigna (le llega por correo)
function guardar(evento)
{
    evento.preventDefault();
    //La contraseña escrita: mínimo de caracteres y las dos iguales (el servidor lo vuelve a revisar)
    var clave = $("#claveNueva").val(), clave2 = $("#claveNueva2").val();
    if (clave !== "" || clave2 !== "") {
        if (clave.length < CLAVE_LARGO_MINIMO) { alert("La contraseña debe tener al menos " + CLAVE_LARGO_MINIMO + " caracteres."); $("#claveNueva").focus(); return; }
        if (clave !== clave2) { alert("Las dos contraseñas no coinciden. Vuelve a escribirlas."); $("#claveNueva2").focus(); return; }
    }
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

//Carga a una persona en el formulario. crear = true cuando todavía no tiene usuario
function cargarPersona(idusuario, crear)
{
    hanaCargando(true);
    $.post("../Control/UsuariosControl.php?op=mostrar", {idusuario : idusuario}, function(respuesta){
        hanaCargando(false);
        var data = JSON.parse(respuesta);
        if (!data) { alert("Esa persona ya no existe. Actualiza la página."); return; }
        limpiar();
        $("#idusuario").val(data.ID_COLABORADOR);
        $("#documento").val(data.DOC_COLABORADOR);
        $("#persona").val(data.NOM_COLABORADOR);
        $("#correo").val(data.MAIL_COLABORADOR);
        if (crear) {
            //Se le propone su documento como nombre de usuario
            $("#usuario").val(data.DOC_COLABORADOR || "");
            $('#rol').val("").trigger('change');
            $("#ayudaUsuario").text("Todavía no tiene usuario. Al guardar se le crea.");
            $("#lblClaveNueva").text("Contraseña:");
            $("#ayudaClave").text("Escríbela tú, o déjala vacía y el sistema le pone una temporal al azar. Le llega por correo con su usuario.");
        } else {
            $("#usuario").val(data.USUARIO || "");
            //Si su rol está apagado no sale en la lista: se agrega para verlo
            if (data.ID_ROL_USUARIO_SISTEMA && !$('#rol option[value="' + data.ID_ROL_USUARIO_SISTEMA + '"]').length) {
                $('#rol').append(new Option(data.NOM_ROL_USUARIO_SISTEMA || data.ID_ROL_USUARIO_SISTEMA, data.ID_ROL_USUARIO_SISTEMA));
            }
            $('#rol').val(data.ID_ROL_USUARIO_SISTEMA).trigger('change');
            $("#ayudaUsuario").text(String(data.ACCESO) === "1" ? "Puede entrar a HANA." : "Su acceso está bloqueado: no puede entrar hasta que se le devuelva (botón del candado en la tabla).");
            $("#lblClaveNueva").text("Contraseña nueva (opcional):");
            $("#ayudaClave").text("Déjala vacía para no cambiarla. Si escribes una, la anterior deja de servir y la nueva le llega por correo.");
        }
        mostrarform(true);
    }).fail(function(xhr){
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar los datos.");
    });
}

//Editar el usuario de una persona
function mostrar(idusuario) { cargarPersona(idusuario, false); }

//Crearle el usuario a una persona que no tiene
function crearUsuario(idusuario) { cargarPersona(idusuario, true); }

//Bloquea el acceso (pregunta antes): no puede entrar, pero la persona sigue en las listas
function anular(idusuario){
    hanaConfirmar("¿Bloquear el acceso de esta persona? No podrá entrar a HANA, pero sigue saliendo en las listas. A la persona se la desactiva en Colaboradores.", function(){
        $.post("../Control/UsuariosControl.php?op=anular", {idusuario : idusuario}, function(data){
            alert(data);
            tabla.ajax.reload();
        }).fail(function(xhr){ hanaErrorAjax(xhr, "No se pudo bloquear el acceso."); });
    });
}
//Le devuelve el acceso (pregunta antes)
function activar(idusuario){
    hanaConfirmar("¿Devolverle el acceso? Podrá volver a entrar a HANA con su contraseña.", function(){
        $.post("../Control/UsuariosControl.php?op=activar", {idusuario : idusuario}, function(data){
            alert(data);
            tabla.ajax.reload();
        }).fail(function(xhr){ hanaErrorAjax(xhr, "No se pudo devolver el acceso."); });
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
//CLAVE_LARGO_MINIMO lo escribe la pantalla (UsuariosVista.php) con el de Parámetros del sistema
if (typeof CLAVE_LARGO_MINIMO === 'undefined') { var CLAVE_LARGO_MINIMO = 4; }
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
