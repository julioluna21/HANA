var tabla;//variable global
var vrca;
var Centroslista;
//Función que se ejecuta al inicio
function init()
{
    vrca=$("#cambioclave").val();

    //Modo "Cambiar clave" del menú: solo se muestra el formulario de la clave propia.
    //No se piden la lista de usuarios ni los centros: eso es de administración (1M)
    //y el servidor ya no se lo entrega a quien no tiene ese permiso
    if(vrca=="canvu"){
        mostrarform(false);
        mostrarformcalve(true);
        $("#formularioclave").on("submit",function(e){ cambiarclave(e); });
        return;
    }

    mostrarform(false);
    listar();//lista 
    mostrarformcalve(false);
    
//al oprimir el boton del formulario
    $("#demo-form2").on("submit",function(e)//e = variable que contiene el objeto
    {
            guardar(e);//guarda o edita el articulo
    });

    $("#formularioclave").on("submit",function(e)//e = variable que contiene el objeto
    {
            cambiarclave(e);//guarda o edita el articulo
    });

$.post("../Control/UsuariosControl.php?op=centros", function(data)
    {
    
     $("#centros").html(data);
    });
    
$.post("../Control/RolesControl.php?op=select",function(data){
        $("#rolColaborador").html(data);
    });

$.post("../Control/UsuariosControl.php?op=select",function(data){
        $("#colaborador").html(data);
    });
    
$.post("../Control/UsuariosControl.php?op=centroslistatodos", function(data){
            Centroslista = data.split(',');
});    
    
$('#rolColaborador').select2({
    width: '100%' ,
    
});

$('#colaborador').select2({
    width: '100%' ,
    
});
    
  
    

}


function selecionar()
{ 
         if($('#todosc').prop('checked')){
            for(var i=0;i<Centroslista.length;i++){   
            $("#r"+Centroslista[i]).prop("checked", true);  
            }                
         }else{
           for(var i=0;i<Centroslista.length;i++){   
            $("#r"+Centroslista[i]).prop("checked", false);  
            } 
         } 
      
}


//Función mostrar formulario
function mostrarform(flag)
{
    if (flag)
    {//partes de la pagina que se muestran o se ocultan
            $('#formularioregistros').show();
            $('#listadoregistros').hide();
            $('#btnclave').hide();
            $('#formclave').hide();
            $("#btnGuardar").prop("disabled",false);
    }
    else
    {		
             $("#btnGuardar").prop("disabled",false);
             $('#formularioregistros').hide();
             $('#formclave').hide();
             $('#listadoregistros').show();
           
             
    }
}

function mostrarformcalve(flag)
{
    if (flag)
    {//partes de la pagina que se muestran o se ocultan
            $('#formclave').show();
            $('#formularioregistros').hide();
            $('#listadoregistros').hide();
           
    }
    else
    {
        
    if(vrca=="canvu"){
     window.history.go(-1);
    }else{
        $('#formclave').hide();
        $('#listadoregistros').show();
        limpiar();
    }
             
    }
}
//Función cancelarform
function cancelarform()
{
    limpiar();
    mostrarform(false);
}


//Función limpiar, pone el formulario en blanco
function limpiar()
{
    document.getElementById("demo-form2").reset();
    document.getElementById("formularioclave").reset();
    $('#rolColaborador').val("").trigger('change')
    $('#colaborador').val("").trigger('change')  
    $("#idusuario, #idusuario2").val("");
    
  
}
//Función Listar
function listar()
{
    tabla=$('#tbllistado').dataTable(//Carga variable con datos datatable
    {
            "aProcessing": true,//Activamos el procesamiento del datatables
        "aServerSide": true,//Paginación y filtrado realizados por el servidor
        dom: 'Bfrtip',//Definimos los elementos del control de tabla Bfrtip
        buttons: [
                        
                    ],
        "ajax"://metodo ajax
                            {
                                    url: '../Control/UsuariosControl.php?op=listar',//pagina que realiza la operación
                                    type : "get",//tipo de envio de datos
                                    dataType : "json",//tipo de datos
                                    error: function(e){//si error muestra mensaje
                                            console.log(e.responseText);
                                    }
                            },
            "bDestroy": true,
            "iDisplayLength": 10,//Paginación
        "order": [[ 0, "asc" ]]//Ordenar (columna,orden)
    }).DataTable();
    
}
//Función para guardar o editar
function guardar(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    hanaBoton("#btnGuardar", true); //bloquea el botón y muestra "Guardando..." mientras responde el servidor
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
            url: "../Control/UsuariosControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        //Error de conexión o del servidor: se muestra el motivo y el formulario se conserva
        error: function(xhr)
        {
            hanaErrorAjax(xhr, "No se pudo guardar el usuario.");
        },
        success: function(datos)
        {
            alert(datos); //el servidor responde con el mensaje exacto: éxito o motivo del error
            //Solo si NO fue un error se cierra el formulario; si falló, queda abierto para corregir
            if (hanaTipoMensaje(datos) !== "error") {
                mostrarform(false);
                limpiar();
                tabla.ajax.reload();
            }
        },
        complete: function()
        {
            hanaBoton("#btnGuardar", false); //pase lo que pase, el botón vuelve a quedar disponible
        }
    });
}


function cambiarclave(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    if(validaPwds()){
    var boton = $("#formularioclave button[type=submit]"); //botón de la pantalla de cambio de clave
    hanaBoton(boton, true, "Cambiando...");
    var formData = new FormData($("#formularioclave")[0]);
    $.ajax({
            url: "../Control/UsuariosControl.php?op=cambiarclave",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        error: function(xhr)
        {
            hanaErrorAjax(xhr, "No se pudo cambiar la contraseña.");
        },
        success: function(datos)
        {
            alert(datos); //el servidor responde con el mensaje exacto: éxito o motivo del error
            //Solo se sale de la pantalla si el cambio se hizo; si falló, se puede reintentar
            if (hanaTipoMensaje(datos) !== "error") {
                limpiar();
                mostrarformcalve(false);
            }
        },
        complete: function()
        {
            hanaBoton(boton, false);
        }
    });
    }
}

function mostrar(idusuario)
{
    hanaCargando(true); //aviso de carga mientras llegan los datos y los centros del usuario

    //Se piden los dos datos a la vez y se espera a que ambos lleguen.
    //Antes esto eran dos esperas fijas (1 y 1,3 segundos) que no consultaban nada
    var pideUsuario = $.post("../Control/UsuariosControl.php?op=mostrar",{idusuario : idusuario});
    var pideCentros = $.post("../Control/UsuariosControl.php?op=centroslista",{idusuario : idusuario});

    $.when(pideUsuario, pideCentros)
        .done(function(resUsuario, resCentros){
            hanaCargando(false);
            var data = JSON.parse(resUsuario[0]);
            mostrarform(true);
            $('#btnclave').show();
            $("#idusuario").val(data.ID_USUARIO_SISTEMA);
            $("#idusuario2").val(data.ID_USUARIO_SISTEMA);
            $("#nombre").val(data.NOM_USUARIO_SISTEMA);
            $('#rolColaborador').val(data.ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA).trigger('change');
            $('#colaborador').val(data.ID_COLABORADOR_USUARIOS_SISTEMA).trigger('change');

            //Marca los centros de operación que el usuario tiene asignados
            var arr = String(resCentros[0]).split(',');
            for(var i=0;i<arr.length;i++){
                $("#r"+arr[i]).prop("checked", true);
            }
        })
        .fail(function(xhr){
            hanaCargando(false);
            hanaErrorAjax(xhr, "No se pudieron cargar los datos del usuario.");
        });
}
function anular(idusuario){
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    hanaConfirmar("¿Desea anular este usuario?", function(){
        $.post("../Control/UsuariosControl.php?op=anular",{idusuario : idusuario}, function(data){
            alert(data); //el servidor responde con el mensaje exacto: éxito o motivo del error
            tabla.ajax.reload();
        })
        .fail(function(xhr){
            hanaErrorAjax(xhr, "No se pudo anular el usuario.");
        });
    });
}
function activar(idusuario){
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    hanaConfirmar("¿Desea activar este usuario?", function(){
        $.post("../Control/UsuariosControl.php?op=activar",{idusuario : idusuario}, function(data){
            alert(data); //el servidor responde con el mensaje exacto: éxito o motivo del error
            tabla.ajax.reload();
        })
        .fail(function(xhr){
            hanaErrorAjax(xhr, "No se pudo activar el usuario.");
        });
    });
}

function centros(idusuario){
    hanaCargando(true); //aviso de carga mientras llega la lista de centros
    $.post("../Control/UsuariosControl.php?op=MostrarCentros",{idusuario : idusuario}, function(data)
    {
        hanaCargando(false);
        //El servidor devuelve los nombres separados por <br>; se pasan a saltos de línea
        //para mostrarlos como texto en la ventana del sistema
        var lista = $.trim(String(data).replace(/<br\s*\/?>/gi, "\n"));
        alert(lista !== "" ? lista : "Este usuario no tiene centros de operación asignados.",
              null, { titulo: "Centros de operación asignados" });
    })
    .fail(function(xhr){
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar los centros del usuario.");
    });
}

function validaPwds() {
    var c1=$("#clave").val();
    var c2=$("#clave2").val();
        if (c2 === c1) {
            return true;
        }else{
            alert("Las contraseñas no coinciden. Vuelve a escribirlas.");
            $("#clave").val('');
            $("#clave2").val('');
            return false;
        }
}

init();//ejecuta la función init