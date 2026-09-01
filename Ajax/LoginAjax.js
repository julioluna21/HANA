function ini(){
    $("#LoginUsuarios").focus();
    
$("#frmAcceso").on('submit',function(e){//recibe datos de frmAcceso
    e.preventDefault();
    LoginUsuarios=$("#LoginUsuarios").val();//Datos recibidos
    ClaveUsuarios=$("#ClaveUsuarios").val();

    $.post("../Control/UsuariosControl.php?op=verificar",//Donde se envian los datos
        {"LoginUsuarios":LoginUsuarios,"ClaveUsuarios":ClaveUsuarios},
        function(data){
            data = JSON.parse(data);
            if (!data.error){//Si data diferente de nulo
                $(location).attr("href","../Vista/novedadesVista.php");
            }else{
                alert("Algo salió mal, verifique los datos ingresados");//Muestra mensaje
            }
        });
});
    
$("#frmrecuperar").on('submit',function(e){//recibe datos de frmAcceso
    e.preventDefault();
    LoginUsuarios=$("#LoginUsuariosr").val();//Datos recibidos
    $("#btrecuperar").prop("disabled",true);
    $.post("../Control/UsuariosControl.php?op=recuperar",//Donde se envian los datos
        {"LoginUsuarios":LoginUsuarios},
        function(data){
            $("#LoginUsuariosr").val('');
             $("#btrecuperar").prop("disabled",false);
            data = JSON.parse(data);
            alert(data.mensaje);
            recuperar(false);
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