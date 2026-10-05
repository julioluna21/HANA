function ini(){
    $("#LoginUsuarios").focus();
    
$("#frmAcceso").on('submit',function(e){//recibe datos de frmAcceso
    e.preventDefault();
    LoginUsuarios=$("#LoginUsuarios").val();//Datos recibidos
    ClaveUsuarios=$("#ClaveUsuarios").val();

    //Mientras valida, el botón queda bloqueado: evita que se envíe dos veces
    var boton = $("#frmAcceso button[type=submit]");
    hanaBoton(boton, true, "Entrando...");

    $.post("../Control/UsuariosControl.php?op=verificar",//Donde se envian los datos
        {"LoginUsuarios":LoginUsuarios,"ClaveUsuarios":ClaveUsuarios},
        function(data){
            //Si el servidor no respondió lo esperado (por ejemplo, un error de PHP), se muestra
            //un mensaje claro en vez de romperse ("Unexpected token '<' ... is not valid JSON")
            try { data = (typeof data === 'string') ? JSON.parse(data) : data; }
            catch (err) {
                hanaBoton(boton, false);
                console.error('Respuesta inesperada del servidor al iniciar sesión:', data);
                alert('El servidor respondió con un error y no se pudo iniciar sesión. Revisa que exista Conexion/Global.php con los datos correctos de la base de datos (ver LEEME.md).');
                return;
            }
            if (!data.error){//Si data diferente de nulo
                $(location).attr("href","../Vista/InicioVista.php"); //entra por la pantalla de bienvenida
            }else{
                hanaBoton(boton, false); //deja reintentar
                $("#ClaveUsuarios").val("").focus(); //limpia solo la contraseña, el usuario se conserva
                alert("Usuario o contraseña incorrectos. Verifica los datos e intenta de nuevo.");
            }
        })
        .fail(function(xhr){
            hanaBoton(boton, false);
            hanaErrorAjax(xhr, "No se pudo iniciar sesión.");
        });
});
    
$("#frmrecuperar").on('submit',function(e){//recibe datos de frmAcceso
    e.preventDefault();
    LoginUsuarios=$("#LoginUsuariosr").val();//Datos recibidos
    hanaBoton("#btrecuperar", true, "Enviando...");
    $.post("../Control/UsuariosControl.php?op=recuperar",//Donde se envian los datos
        {"LoginUsuarios":LoginUsuarios},
        function(data){
            $("#LoginUsuariosr").val('');
            data = JSON.parse(data);
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

function recuperar(op){
    if(op){
    $("#recuperar").show();  
    $("#login").hide();  
    }else{
    $("#recuperar").hide(); 
    $("#login").show();      
    }
    
}

ini();