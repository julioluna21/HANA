/*
  HANA - Crear una contraseña nueva (lo que pasa en Vista/RestablecerVista.php)
  ---------------------------------------------------------------------------
  1. Al abrir, pregunta al servidor si el enlace sirve (op=revisarEnlace).
     Si sirve, saluda a la persona y muestra el formulario; si no, explica por qué.
  2. Al guardar, revisa aquí que las dos claves coincidan y tengan el mínimo de
     caracteres, y las manda al servidor (op=restablecer), que vuelve a revisar todo.
  3. Si quedó guardada, avisa y lleva al inicio de sesión.
*/
var rsMinimo = 4; //el servidor manda el mínimo real al revisar el enlace (Parámetros del sistema)

//Los tres datos del enlace (u, v, f), tal como los dejó la página
function rsDatosEnlace() {
    return { u: $("#rsU").val(), v: $("#rsV").val(), f: $("#rsF").val() };
}

//Muestra un aviso en la página y, si el enlace no sirve, oculta el formulario
function rsAviso(texto, mostrarFormulario) {
    $("#rsAviso").text(texto); //.text: el texto nunca se interpreta como HTML
    $("#frmRestablecer").toggle(!!mostrarFormulario);
}

//1. ¿El enlace sirve?
function rsRevisarEnlace() {
    $.post("../Control/UsuariosControl.php?op=revisarEnlace", rsDatosEnlace(), function (respuesta) {
        if (respuesta && respuesta.valido) {
            rsMinimo = parseInt(respuesta.minimo, 10) || rsMinimo;
            $("#rsRegla").text("Mínimo " + rsMinimo + " caracteres. No puede empezar ni terminar con espacios.");
            rsAviso("Hola, " + respuesta.nombre + ". Escribe la contraseña nueva para tu usuario «" + respuesta.usuario + "».", true);
            $("#rsClave").focus();
        } else {
            rsAviso((respuesta && respuesta.mensaje) || "Este enlace ya no sirve. Pide uno nuevo con «Olvidé mi contraseña».", false);
        }
    }, "json").fail(function (xhr) {
        rsAviso("No se pudo revisar el enlace. Intenta de nuevo en un momento.", false);
        hanaErrorAjax(xhr, "No se pudo revisar el enlace.");
    });
}

//2. Guardar la clave nueva
$("#frmRestablecer").on("submit", function (evento) {
    evento.preventDefault();
    var clave = $("#rsClave").val(), clave2 = $("#rsClave2").val();
    if (clave.length < rsMinimo) { alert("La contraseña debe tener al menos " + rsMinimo + " caracteres."); $("#rsClave").focus(); return; }
    if (clave !== clave2) { alert("Las dos contraseñas no coinciden. Vuelve a escribirlas."); $("#rsClave2").val("").focus(); return; }

    var boton = $("#rsGuardar");
    hanaBoton(boton, true, "Guardando...");
    var datos = rsDatosEnlace();
    datos.clave = clave;
    datos.clave2 = clave2;
    $.post("../Control/UsuariosControl.php?op=restablecer", datos, function (respuesta) {
        if (respuesta && respuesta.ok) {
            //3. Listo: al cerrar el aviso, al inicio de sesión
            rsAviso(respuesta.mensaje, false);
            alert(respuesta.mensaje, function () { window.location.replace("login.php"); });
        } else {
            hanaBoton(boton, false);
            alert((respuesta && respuesta.mensaje) || "No se pudo guardar la contraseña.");
        }
    }, "json").fail(function (xhr) {
        hanaBoton(boton, false);
        hanaErrorAjax(xhr, "No se pudo guardar la contraseña.");
    });
});

rsRevisarEnlace();
