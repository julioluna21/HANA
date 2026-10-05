var tabla;//variable global
//Función para guardar o editar
function guardar(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    hanaBoton("#btnGuardar", true); //bloquea el botón y muestra "Guardando..." mientras responde el servidor
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
        url: "../Control/CentroOperativoControl.php?op=guardar",
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
function mostrar(idcentro)
{
    hanaCargando(true); //ventana de "Cargando..." mientras llegan los datos del registro
    $.post("../Control/CentroOperativoControl.php?op=mostrar",{idcentro : idcentro}, function(data)
    {
        hanaCargando(false);
        data = JSON.parse(data);
        mostrarform(true);
        $("#idcentro").val(data.ID_CENTRO_OP);
        $("#nombre").val(data.NOM_CENTRO_OP);
        $("#tipo").val(data.TIPO_CENTRO || "PEAJE");
        $("#jefe").val(data.ID_COLABORADOR_JEFE ? String(data.ID_COLABORADOR_JEFE) : "");
        //Antes se escribia en #idProyecto y #nombreProyecto, campos que NO existen
        //en la vista. El proyecto quedaba vacio y el UPDATE fallaba en silencio.
        //El campo real es #selectProyecto, y como es un select2 que carga por AJAX
        //hay que crearle la opcion actual antes de seleccionarla.
        if (data.ID_PROYECTO) {
            $("#selectProyecto").empty()
                                .append(new Option(data.NOM_PROYECTO, data.ID_PROYECTO, true, true))
                                .trigger("change");
        }
    })
    .fail(function(xhr){
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar los datos del registro.");
    });
}
function anular(idcentro){
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    hanaConfirmar("¿Desea anular este registro?", function(){
        $.post("../Control/CentroOperativoControl.php?op=anular",{idcentro : idcentro}, function(data){
            tabla.ajax.reload();
            alert("Registro anulado con éxito");
        })
        .fail(function(xhr){
            hanaErrorAjax(xhr, "No se pudo anular el registro.");
        });
    });
}
function activar(idcentro){
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    //Nota: hoy ninguna pantalla muestra el botón Activar y el controlador no tiene la opción "activar"
    hanaConfirmar("¿Desea activar este registro?", function(){
        $.post("../Control/CentroOperativoControl.php?op=activar",{idcentro : idcentro}, function(data){
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
    $("#idcentro").val("");
    $("#nombre").val("");
    $("#tipo").val("PEAJE");
    $("#jefe").val("");
}

//Llena el selector de jefe con los colaboradores activos (nombre y cargo)
function cargarJefes()
{
    $.getJSON("../Control/ProyectoControl.php?op=colaboradores", function(lista){
        var h = '<option value="">Sin asignar</option>';
        for (var i = 0; i < lista.length; i++) {
            var t = $('<div>').text(lista[i].nombre + (lista[i].cargo ? ' · ' + lista[i].cargo : '')).html();
            h += '<option value="' + parseInt(lista[i].id, 10) + '">' + t + '</option>';
        }
        var actual = $("#jefe").val();
        $("#jefe").html(h).val(actual || "");
    });
}
function mostrarform(flag)
{
    //limpia el formulario
    if (flag)
    {
           // $('#myModal').modal('show');           
    $("#selectProyecto").select2({
        ajax: {
          url: '../Control/ProyectoControl.php?op=select',
          dataType: 'json',
          delay: 150,
          data: function (params) {
              var query = {
                  search: params.term
              }  
              return query;
          },
          processResults: function (data) {
            return {
                results: $.map(data, function(obj) {
                    return {
                        id: obj.id,
                        text: obj.text                        
                    };
                })                
            };            
          }
      },
      lenguage:'es',
      cache: true,
      placeholder: 'Seleccione un Proyecto...',
      allowClear: true
      });

        $("#demo-form2").show();
        $("#listadoregistros").hide();
            $("#btnGuardar").prop("disabled",false);
        $("#nombre").focus();
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
                                    url: '../Control/CentroOperativoControl.php?op=listar',//pagina que realiza la operación
                                    type : "get",//tipo de envio de datos
                                    dataType : "json",//tipo de datos
                                    error: function(e){//si error muestra mensaje
                                            console.log(e.responseText);
                                    }
                            },
            "bDestroy": true,
            "iDisplayLength": 10,//Paginación
        "order": [[ 0, "desc" ]]//Ordenar (columna,orden). Antes decia "des", que no es un valor valido
    }).DataTable();
    
}
function init()
{
    mostrarform(false)
    listar()//lista 
    cargarJefes();
//al oprimir el boton del formulario
    $("#demo-form2").on("submit",function(e)//e = variable que contiene el objeto
    {
            guardar(e);//guarda o edita el articulo
    });
}
init();