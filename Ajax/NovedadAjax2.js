var tabla;//variable global
//Función que se ejecuta al inicio
function init()
{
    mostrarcontenedor(true);
    mostrarform(false)
    listar(1,0);//lista 
//al oprimir el boton del formulario
    $("#demo-form2").on("submit",function(e)//e = variable que contiene el objeto
    {
            guardar(e);//guarda o edita el articulo
    });
    
    $("#respuesta").on("submit",function(e)//e = variable que contiene el objeto
    {
            respuesta(e);//guarda o edita el articulo
    });
    
    $.post("../Control/TituloControl.php?op=select",function(data){
        $("#titulo").html(data);
    });
    
     $.post("../Control/UsuariosControl.php?op=select2",function(data){
        $("#centroop").html(data);
    });
    
     $.post("../Control/ObservadorControl.php?op=select",function(data){
        $("#observador").html(data);
    });
    
     $.post("../Control/EstadoControl.php?op=select",function(data){
        $("#prioridad").html(data);
    });
    
    $.post("../Control/UsuariosControl.php?op=select",function(data){
        $("#colaborador").html(data);
    });
    
$('#titulo').select2({
    width: '100%' ,
    
});
    
$('#centroop').select2({
    width: '100%' ,
    
});
    
$('#observador').select2({
    width: '100%' ,
    
});
    
$('#prioridad').select2({
    width: '100%' ,
    
});
    
$('#colaborador').select2({
    width: '100%' ,
    
});     
    
$("#miarchivo2").on("change",function(){
 $('#imagenbt').html('<i class="fa fa-picture-o" aria-hidden="true" style="font-size: 20px"></i> Imagen Cargada');
});    

}
//Función mostrar formulario
function mostrarform(flag)
{
    limpiar()
    if (flag)
    {//partes de la pagina que se muestran o se ocultan
            $('#formularioregistros').show();
            $('#listadoregistros').hide();
            $("#btnGuardar").prop("disabled",false);
    }
    else
    {		
             $("#btnGuardar").prop("disabled",false);
             $('#formularioregistros').hide();
             $('#listadoregistros').show();
           
             
    }
}

function mostrarcontenedor(flag)
{
    if (flag)
    {//partes de la pagina que se muestran o se ocultan
            $('#tablasform').show();
            $('#novedadeschat').hide();
            mostrarform(false);
    }
    else
    {		
             $('#tablasform').hide();
             $('#novedadeschat').show();
             limpiar2();
             
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
    $('#titulo,#centroop,#observador,#prioridad,#colaborador').val("").trigger('change');
    $('#imagenbt').html('<i class="fa fa-picture-o" aria-hidden="true" style="font-size: 20px"></i>');
  
}

function limpiar2()
{
    document.getElementById("respuesta").reset();
    $("#estadoNovedad").val("");
    $("#IDnovedad").val("");
    $("#btnenvio").prop("disabled",false);
  
}
//Función Listar
function listar(validador,validador2)
{
    tabla=$('#tbllistado').dataTable(//Carga variable con datos datatable
    {
            "aProcessing": true,//Activamos el procesamiento del datatables
        "aServerSide": true,//Paginación y filtrado realizados por el servidor
        dom: 'Bfrtip',//Definimos los elementos del control de tabla Bfrtip
        buttons: [
                        
                    ],
          language: {
      search: 'Buscar ',
      paginate: {
        first: 'Primero',
        previous: 'Anterior',
        next: 'Siguiente',
        last: 'Último'
      }},
        
        "ajax"://metodo ajax
                            {
                                    url: '../Control/NovedadesControl.php?op=listar&validador='+validador+"&validador2="+validador2,//pagina que realiza la operación
                                    type : "get",//tipo de envio de datos
                                    dataType : "json",//tipo de datos
                                    error: function(e){//si error muestra mensaje
                                            console.log(e.responseText);
                                    }
                            },
            "bDestroy": true,
            "iDisplayLength": 10,//Paginación
        "order": [[ 2, "desc" ]]//Ordenar (columna,orden)
    }).DataTable();
    
    
}
//Función para guardar o editar
function guardar(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    $("#btnGuardar").prop("disabled",true);
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
        url: "../Control/NovedadesControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        success: function(datos)
        {
            alert(datos);
            console.log(datos);
            mostrarform(false);
            tabla.ajax.reload();
            //$(location).attr('href','../Vista/index.html');    
            
            
            
        }
    });
    limpiar();
}

function respuesta(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    
    var estado=$("#estadoNovedad").val();  
    var id=$("#IDnovedad").val(); 
    if(estado!=""){
    
       $("#btnenvio").prop("disabled",true);
       var formData = new FormData($("#respuesta")[0]);
       $.ajax({
        url: "../Control/NovedadesControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        success: function(datos)
        {
            alert(datos);
            mostrarres(id);
            $("#estadoNovedad").val("");
            $("#nrespuesta").val("");
            $("#btnenvio").prop("disabled",false);
            tabla.ajax.reload();
        }
    });
   
        
    }else{
      bootbox.alert("Debe seleccionar un estado a la respuesta");  
    }
    
}

function mostrar(IDnovedad)
{
    $.post("../Control/NovedadesControl.php?op=mostrarnovedad",{IDnovedad : IDnovedad}, function(data)
    {
        
    bootbox.dialog({
        message: '<div class="text-center"><i class="fa fa-spin fa-spinner"></i> Consultando la base de datos...</div>',
        closeButton: false
        });
    setTimeout(() => {
    bootbox.hideAll()
    data = JSON.parse(data);
    mostrarcontenedor(false);
    $("#IDnovedad").val(IDnovedad);     
    $("#encabezado").html('<h6 class="mb-0 d-block">'+data.titulo+' <span class="badge badge-indicator badge-success">. </span></h6><br><span class="text-muted text-small">'+data.fechacreacion+'</span>');
    $("#contenido").html(data.contenido);
    var div = document.getElementById('contenido');
    div.scrollTop = '99999';    
                 
    }, 1000);      
   
     
    });
}

function solover(IDnovedad)
{
    $.post("../Control/NovedadesControl.php?op=mostrarnovedad",{IDnovedad : IDnovedad}, function(data)
    {
        
    bootbox.dialog({
        message: '<div class="text-center"><i class="fa fa-spin fa-spinner"></i> Consultando la base de datos...</div>',
        closeButton: false
        });
    setTimeout(() => {
    bootbox.hideAll()
    data = JSON.parse(data);
    mostrarcontenedor(false);
    $("#btnenvio").prop("disabled",true);    
    $("#encabezado").html('<h6 class="mb-0 d-block">'+data.titulo+' <span class="badge badge-indicator badge-success">.  </span></h6> <br><span class="text-muted text-small">  fecha:'+data.fechacreacion+'</span>');
    $("#contenido").html(data.contenido);
    var div = document.getElementById('contenido');
    div.scrollTop = '99999';    
                 
    }, 1000);      
   
     
    });
}

function mostrarres(IDnovedad)
{
    $.post("../Control/NovedadesControl.php?op=mostrarnovedad",{IDnovedad : IDnovedad}, function(data)
    {
    data = JSON.parse(data);
    $("#contenido").html(data.contenido);
    if(data.estado=="Cerrada"){
     $("#btnenvio").prop("disabled",true); 
    }else if(data.estado=="Finalizada" && data.permiso!=1){
      $("#btnenvio").prop("disabled",true);   
    }    
    var div = document.getElementById('contenido');
    div.scrollTop = '9999';    
    });
}

function novedades(op)
{
    if(op==2){
    $("#estadoNovedad").val("2");    
    }else if(op==3){
    $("#estadoNovedad").val("3");     
    }else if(op==4){
    $("#estadoNovedad").val("4");     
    }
    
   
}

function condiciones()
{
 var con1=$("#validador").val();
 var con2=$("#validador2").val(); 
 listar(con1,con2);
}


init();//ejecuta la función init