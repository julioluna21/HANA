//Archivo sin uso: la pantalla de Observadores se quitó. Se conserva solo como referencia.
//Configuración - Observadores de las novedades (Vista/ObservadorVista.php)
//Tabla, formulario y botones de activar/desactivar. Habla con Control/ObservadorControl.php

var tabla;//variable global
//Función que se ejecuta al inicio
function init()
{
    mostrarform(false)
    listar();//lista 
//al oprimir el botón del formulario
    $("#demo-form2").on("submit",function(evento) //evento: el envío del formulario
    {
            guardar(evento);//guarda o edita el registro
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
    {//partes de la página que se muestran o se ocultan
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
        "ajax"://método ajax
                            {
                                    url: '../Control/ObservadorControl.php?op=listar',//página que realiza la operación
                                    type : "get",//tipo de envío de datos
                                    dataType : "json",//tipo de datos
                                    error: function (xhr) { //si falla, se muestra el motivo
                                            console.log(xhr.responseText);
                                    }
                            },
            "bDestroy": true,
            "iDisplayLength": 10,//Paginación
        "order": [[ 0, "asc" ]]//Ordenar (columna,orden)
    }).DataTable();
    
}
//Función para guardar o editar
function guardar(evento)
{
    evento.preventDefault(); //No se activará la acción predeterminada del evento
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
//Trae un observador y lo carga en el formulario para editarlo
function mostrar(idobservador)
{
    hanaCargando(true); //ventana de "Cargando..." mientras llegan los datos del registro
    $.post("../Control/ObservadorControl.php?op=mostrar",{idobservador : idobservador}, function(data)
    {
        hanaCargando(false);
        //El aviso de carga dura solo lo que tarda el servidor en responder
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
//Desactiva un observador (pregunta antes)
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
//Vuelve a activar un observador (pregunta antes)
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

