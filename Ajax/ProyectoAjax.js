var tabla;//variable global
//Función para guardar o editar
function guardar(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    hanaBoton("#btnGuardar", true); //bloquea el botón y muestra "Guardando..." mientras responde el servidor
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
        url: "../Control/ProyectoControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        //Si el servidor responde con error se muestra el motivo real.
        //El formulario NO se borra, para que el usuario pueda corregir y reintentar
        error: function(xhr)
        {
            hanaErrorAjax(xhr, "No se pudo guardar el registro.");
        },
        success: function(datos)
        {
            limpiar(); //se limpia solo cuando el registro de verdad quedó guardado
            mostrarform(false);
            tabla.ajax.reload();
            alert("Registro guardado con éxito");
        },
        complete: function()
        {
            hanaBoton("#btnGuardar", false); //pase lo que pase, el botón vuelve a quedar disponible
        }
    });
}
function mostrar(idProyecto)
{
    hanaCargando(true); //ventana de "Cargando..." mientras llegan los datos del registro
    $.post("../Control/ProyectoControl.php?op=mostrar",{idProyecto : idProyecto}, function(data)
    {
        hanaCargando(false);
        data = JSON.parse(data);
        mostrarform(true);
        $("#idProyecto").val(data.ID_PROYECTO);
        $("#nombre").val(data.NOM_PROYECTO);
        $("#coordinador").val(data.ID_COLABORADOR_COORDINADOR ? String(data.ID_COLABORADOR_COORDINADOR) : "");
    })
    .fail(function(xhr){
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar los datos del registro.");
    });
}
function anular(idProyecto){
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    hanaConfirmar("¿Desea anular este registro?", function(){
        $.post("../Control/ProyectoControl.php?op=anular",{idProyecto : idProyecto}, function(data){
            tabla.ajax.reload();
            alert("Registro anulado con éxito");
        })
        .fail(function(xhr){
            hanaErrorAjax(xhr, "No se pudo anular el registro.");
        });
    });
}
function activar(idProyecto){
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    //Nota: hoy ninguna pantalla muestra el botón Activar y el controlador no tiene la opción "activar"
    hanaConfirmar("¿Desea activar este registro?", function(){
        $.post("../Control/ProyectoControl.php?op=activar",{idProyecto : idProyecto}, function(data){
            tabla.ajax.reload();
            alert("Registro activado con éxito");
        })
        .fail(function(xhr){
            hanaErrorAjax(xhr, "No se pudo activar el registro.");
        });
    });
}
function cancelarform()
{
    limpiar();
    mostrarform(false);
}
function limpiar()
{
    //Antes decía #idproyecto (en minúscula) y el campo real es #idProyecto: el id
    //no se borraba, y al crear un proyecto después de ver otro, se editaba el anterior
    $("#idProyecto").val("");
    $("#nombre").val("");
    $("#coordinador").val("");
}

//Llena un selector con los colaboradores activos (nombre y cargo)
function cargarColaboradores(selector)
{
    $.getJSON("../Control/ProyectoControl.php?op=colaboradores", function(lista){
        var h = '<option value="">Sin asignar</option>';
        for (var i = 0; i < lista.length; i++) {
            var t = $('<div>').text(lista[i].nombre + (lista[i].cargo ? ' · ' + lista[i].cargo : '')).html();
            h += '<option value="' + parseInt(lista[i].id, 10) + '">' + t + '</option>';
        }
        var actual = $(selector).val();
        $(selector).html(h).val(actual || "");
    });
}
function mostrarform(flag)
{
    //limpia el formulario
    if (flag)
    {
           // $('#myModal').modal('show');
        $("#demo-form2").show();
        $("#listadoregistros").hide();
            $("#btnGuardar").prop("disabled",false);
    }
    else
    {	
        $("#demo-form2").hide();
        $("#listadoregistros").show();
             $("#btnGuardar").prop("disabled",false);
           
             
    }
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
                                    url: '../Control/ProyectoControl.php?op=listar',//pagina que realiza la operación
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
function init()
{
    mostrarform(false)
    listar();//lista 
    cargarColaboradores("#coordinador");
//al oprimir el boton del formulario
    $("#demo-form2").on("submit",function(e)//e = variable que contiene el objeto
    {
            guardar(e);//guarda o edita el articulo
    });
}
init();