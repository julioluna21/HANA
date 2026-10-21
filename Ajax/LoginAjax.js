/*
  HANA - Inicio de sesión (lo que pasa en la pantalla)
  ---------------------------------------------------------------------------
  Envía el usuario y la clave a Control/UsuariosControl.php?op=verificar.
  Si son correctos entra a HANA; si no, muestra el aviso que manda el servidor
  (clave equivocada, espera por intentos fallidos, usuario o rol desactivado).
  También maneja el formulario de "Olvidé mi contraseña", que pide que se
  mande al correo un enlace para crear una clave nueva.
*/
function iniciar(){
    $("#LoginUsuarios").focus();
    
$("#frmAcceso").on('submit',function(evento){ //inicio de sesión: usuario y clave
    evento.preventDefault();
    LoginUsuarios=$("#LoginUsuarios").val();//Datos recibidos
    ClaveUsuarios=$("#ClaveUsuarios").val();

    //Mientras valida, el botón queda bloqueado: evita que se envíe dos veces
    var boton = $("#frmAcceso button[type=submit]");
    hanaBoton(boton, true, "Entrando...");

    $.post("../Control/UsuariosControl.php?op=verificar",//Donde se envían los datos
        {"LoginUsuarios":LoginUsuarios,"ClaveUsuarios":ClaveUsuarios},
        function(data){
            //Si el servidor no respondió lo esperado (por ejemplo, un error de PHP), se muestra
            //un mensaje claro en vez de romperse ("Unexpected token '<' ... is not valid JSON")
            try { data = (typeof data === 'string') ? JSON.parse(data) : data; }
            catch (error) {
                hanaBoton(boton, false);
                console.error('Respuesta inesperada del servidor al iniciar sesión:', data);
                alert('No se pudo iniciar sesión por un problema del sistema. Intenta de nuevo en unos minutos; si sigue pasando, avísale al administrador del sistema.');
                return;
            }
            if (!data.error){//Si data diferente de nulo
                $(location).attr("href","../Vista/InicioVista.php"); //entra por la pantalla de bienvenida
            }else{
                hanaBoton(boton, false); //deja reintentar
                $("#ClaveUsuarios").val("").focus(); //limpia solo la contraseña, el usuario se conserva
                alert(data.mensaje || "Usuario o contraseña incorrectos. Verifica los datos e intenta de nuevo.");
            }
        })
        .fail(function(xhr){
            hanaBoton(boton, false);
            hanaErrorAjax(xhr, "No se pudo iniciar sesión.");
        });
});
    
$("#frmrecuperar").on('submit',function(evento){ //"Olvidé mi contraseña": pide el enlace al correo
    evento.preventDefault();
    LoginUsuarios=$("#LoginUsuariosr").val();//Datos recibidos
    hanaBoton("#btrecuperar", true, "Enviando...");
    $.post("../Control/UsuariosControl.php?op=recuperar",//Donde se envían los datos
        {"LoginUsuarios":LoginUsuarios},
        function(data){
            $("#LoginUsuariosr").val('');
            data = (typeof data === 'string') ? JSON.parse(data) : data;
            //Se vuelve al login solo después de que el usuario cierre el aviso,
            //para que alcance a leer el mensaje
            alert(data.mensaje, function(){ recuperar(false); });
        })
        .fail(function(xhr){
            hanaErrorAjax(xhr, "No se pudo recuperar la contraseña.");
        })
        .always(function(){
            hanaBoton("#btrecuperar", false);
        });
});    

recuperar(false);
}

//Cambia entre el formulario de entrar y el de recuperar la clave
function recuperar(op){
    if(op){
    $("#recuperar").show();  
    $("#login").hide();  
    }else{
    $("#recuperar").hide(); 
    $("#login").show();      
    }
    
}

iniciar();