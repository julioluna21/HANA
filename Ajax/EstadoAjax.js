var tabla;//variable global
//Función que se ejecuta al inicio
function init()
{
    mostrarform(false)
    listar();//lista 
//al oprimir el boton del formulario
    $("#demo-form2").on("submit",function(e)//e = variable que contiene el objeto
    {
            guardar(e);//guarda o edita el articulo
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
    $("#idestado").val("");
  
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
                                    url: '../Control/EstadoControl.php?op=listar',//pagina que realiza la operación
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
            url: "../Control/EstadoControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        success: function(datos)
        {
            alert(datos);
            mostrarform(false);
            tabla.ajax.reload();
            //$(location).attr('href','../Vista/index.html');    
            
            
            
        }
    });
    limpiar();
}
function mostrar(idestado)
{
    $.post("../Control/EstadoControl.php?op=mostrar",{idestado : idestado}, function(data)
    {
        
    bootbox.dialog({
        message: '<div class="text-center"><i class="fa fa-spin fa-spinner"></i> Consultando la base de datos...</div>',
        closeButton: false
        });
         setTimeout(() => {
    bootbox.hideAll()
     data = JSON.parse(data);
    mostrarform(true);
    $("#idestado").val(data.ID_ESTADOS_RELEVANCIA );
    $("#nombre").val(data.NOMBRE_ESTADOS_RELEVANCIA);
    $("#dias").val(data.DIAS_ESTADOS_RELEVANCIAS);
                 
    }, 1000);      
   
     
    });
}
function anular(idestado){
    
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
                  $.post("../Control/EstadoControl.php?op=anular",{idestado : idestado}, function(data)
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
function activar(idestado){
    
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
                  $.post("../Control/EstadoControl.php?op=activar",{idestado : idestado}, function(data)
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

function puntosNumero() {
    num=$("#dias").val();
    num=num.toString()
    //quita puntos y comas
    var num = num.replace(/[A-Za-z]|[.!"#%&/¨¡;:,_-´¡'\=\-*+?^${}()|[\]\\]/g, '');//[]()/\^`|[\]\\]|'¿´+-!"#$%&<>;:_*?=
    if (!isNaN(num)) {
        //invierte el orden numerico para poner punto cada 3 caracteres
        num = num.toString().split('').reverse().join('').replace(/(?=\d*\.?)(\d{3})/g, '$1.');
        //reordena el número para mostrar
        num = num.split('').reverse().join('').replace(/^[\.]/, '');
        // retorna el número con puntos
        $("#dias").val(num);
    }
    else {
        bootbox.alert('Solo se permiten numeros');
    }
}
init();//ejecuta la función init