var tabla;//variable global
var vrca;
var Centroslista;
//Función que se ejecuta al inicio
function init()
{
    mostrarform(false);
    listar();//lista 
    vrca=$("#cambioclave").val();
    if(vrca=="canvu"){
        mostrarformcalve(true);
    }else{
         mostrarformcalve(false);
    }
    
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
    $("#btnGuardar").prop("disabled",true);
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
            url: "../Control/UsuariosControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        success: function(datos)
        {
            alert(datos);
            mostrarform(false);
            limpiar();
            tabla.ajax.reload();
            //$(location).attr('href','../Vista/index.html');    
            
            
            
        }
    });
    limpiar();
}


function cambiarclave(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    if(validaPwds()){
    var formData = new FormData($("#formularioclave")[0]);
    $.ajax({
            url: "../Control/UsuariosControl.php?op=cambiarclave",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        success: function(datos)
        {
            alert(datos);
            mostrarformcalve(false);    
            
            
            
        }
    });
    limpiar();    
    }
    
}

function mostrar(idusuario)
{
    
    setTimeout(() => {
     $.post("../Control/UsuariosControl.php?op=centroslista",{idusuario : idusuario}, function(data)
    {
            
            var arr = data.split(',');
            for(var i=0;i<arr.length;i++){   
            $("#r"+arr[i]).prop("checked", true);  
            }  
    });             
    }, 1300); 
    
    $.post("../Control/UsuariosControl.php?op=mostrar",{idusuario : idusuario}, function(data)
    {
        
    bootbox.dialog({
        message: '<div class="text-center"><i class="fa fa-spin fa-spinner"></i> Consultando la base de datos...</div>',
        closeButton: false
        });
         setTimeout(() => {
    bootbox.hideAll()
     data = JSON.parse(data);
    mostrarform(true);
    $('#btnclave').show();         
    $("#idusuario").val(data.ID_USUARIO_SISTEMA);
    $("#idusuario2").val(data.ID_USUARIO_SISTEMA);             
    $("#nombre").val(data.NOM_USUARIO_SISTEMA);
    $('#rolColaborador').val(data.ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA).trigger('change');
    $('#colaborador').val(data.ID_COLABORADOR_USUARIOS_SISTEMA).trigger('change');         
                     
    }, 1000);      
   
     
    });
    
    
}
function anular(idusuario){
    
     bootbox.confirm({
            message: "Desea anular este registro?",
            buttons: {
                confirm: {
                    label: 'SI'
                },
                cancel: {
                    label: 'NO'
                }
            },
            callback: function (result) {
                if (result) {
                  $.post("../Control/UsuariosControl.php?op=anular",{idusuario : idusuario}, function(data)
            {
      bootbox.alert({
                        title: 'Desactivado!',
                        message: data,
                        size: 'small',
                        closeButton: false
         });              
         setTimeout(() => {
                        bootbox.hideAll()
        }, 1500);              
                      
     tabla.ajax.reload();
        
    });
                }
            }
        });
    
}
function activar(idusuario){
    
     bootbox.confirm({
            message: "Desea activar este registro?",
            buttons: {
                confirm: {
                    label: 'SI'
                },
                cancel: {
                    label: 'NO'
                }
            },
            callback: function (result) {
                if (result) {
                  $.post("../Control/UsuariosControl.php?op=activar",{idusuario : idusuario}, function(data)
            {
      bootbox.alert({
                        title: 'Activado!',
                        message: data,
                        size: 'small',
                        closeButton: false
                    });      
                      
        setTimeout(() => {
                        bootbox.hideAll()
        }, 1500);              
         tabla.ajax.reload();
        
    });
                }
            }
        });
    
}

function centros(idusuario){
    
$.post("../Control/UsuariosControl.php?op=MostrarCentros",{idusuario : idusuario}, function(data)
            {
      bootbox.alert({
                        title: 'Centros operativos asociados',
                        message: data,
                        size: 'small',
                        
                    });      
    });
      
    
}

function validaPwds() {
    var c1=$("#clave").val();
    var c2=$("#clave2").val();
        if (c2 === c1) {
            return true;
        }else{
            bootbox.alert("La clave no es igual");
            $("#clave").val('');
            $("#clave2").val('');
            return false;
        }
    
    
}

init();//ejecuta la función init