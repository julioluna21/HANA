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
    
$("#miarchivo2").on("change",function(){
 $('#imagenbt').html('<i class="fa fa-picture-o" aria-hidden="true" style="font-size: 20px"></i>Imagen Cargada');
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
    $("#idobservador").val("");
  
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
                                    url: '../Control/ObservadorControl.php?op=listar',//pagina que realiza la operación
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
        url: "../Control/ObservadorControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        //Error de conexión o del servidor: se muestra el motivo y el formulario se conserva
        error: function(xhr)
        {
            hanaErrorAjax(xhr, "No se pudo guardar el registro.");
        },
        success: function(datos)
        {
            alert(datos); //el servidor responde con el mensaje exacto: éxito o motivo del error
            //Solo si NO fue un error se cierra el formulario; si falló, queda abierto para corregir
            if (hanaTipoMensaje(datos) !== "error") {
                mostrarform(false); //mostrarform también limpia el formulario
                tabla.ajax.reload();
            }
        },
        complete: function()
        {
            hanaBoton("#btnGuardar", false); //pase lo que pase, el botón vuelve a quedar disponible
        }
    });
}
function mostrar(idobservador)
{
    hanaCargando(true); //ventana de "Cargando..." mientras llegan los datos del registro
    $.post("../Control/ObservadorControl.php?op=mostrar",{idobservador : idobservador}, function(data)
    {
        hanaCargando(false);
        //Antes aqui habia una espera fija de 1 segundo con "Consultando la base de datos...".
        //Ahora el aviso de carga dura solo lo que tarda el servidor en responder
        data = JSON.parse(data);
        mostrarform(true);
        $("#idobservador").val(data.ID_OBSERVADOR_NOVEDADES_HALLAZGOS);
        $("#nombre").val(data.NOM_OBSERVADOR_NOVEDADES_HALLAZGOS);
    })
    .fail(function(xhr){
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar los datos del registro.");
    });
}
function anular(idobservador){
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    hanaConfirmar("¿Desea anular este registro?", function(){
        $.post("../Control/ObservadorControl.php?op=anular",{idobservador : idobservador}, function(data){
            alert(data); //el servidor responde con el mensaje exacto: éxito o motivo del error
            tabla.ajax.reload();
        })
        .fail(function(xhr){
            hanaErrorAjax(xhr, "No se pudo anular el registro.");
        });
    });
}
function activar(idobservador){
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    hanaConfirmar("¿Desea activar este registro?", function(){
        $.post("../Control/ObservadorControl.php?op=activar",{idobservador : idobservador}, function(data){
            alert(data); //el servidor responde con el mensaje exacto: éxito o motivo del error
            tabla.ajax.reload();
        })
        .fail(function(xhr){
            hanaErrorAjax(xhr, "No se pudo activar el registro.");
        });
    });
}


init();//ejecuta la función init

