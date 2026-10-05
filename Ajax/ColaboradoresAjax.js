var tabla;//variable global
//Función para guardar o editar
function guardar(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    hanaBoton("#btnGuardar", true); //bloquea el botón y muestra "Guardando..." mientras responde el servidor
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
        url: "../Control/ColaboradoresControl.php?op=guardar",
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
            //Si se creó su usuario, el servidor manda los datos para entrar: se muestran tal cual
            alert(typeof datos === "string" && datos.indexOf("Usuario:") >= 0 ? datos : "Registro guardado con éxito");
        },
        complete: function()
        {
            hanaBoton("#btnGuardar", false); //pase lo que pase, el botón vuelve a quedar disponible
        }
    });
}
function mostrar(idColaborador)
{
    hanaCargando(true); //ventana de "Cargando..." mientras llegan los datos del registro
    $.post("../Control/ColaboradoresControl.php?op=mostrar",{idColaborador : idColaborador}, function(data)
    {
        hanaCargando(false);
        data = JSON.parse(data);
        mostrarform(true);
        $("#idColaborador").val(data.ID_COLABORADOR);
        $("#documento").val(data.DOC_COLABORADO);
        $("#nombre").val(data.NOM_COLABORADOR);
        $("#email").val(data.MAIL_COLABORADOR);
        //El select2 de cargo se llena por AJAX, por lo que al abrir la edicion no
        //tiene ninguna opcion cargada. Si solo se hace .val(id), el valor NO queda
        //puesto, se envia vacio y el UPDATE falla porque el cargo es obligatorio.
        //Por eso se crea aqui la opcion del cargo actual y luego se selecciona.
        var idCargo  = data.ID_CARGO_COLABORADORES_COLABORADORES;
        var nomCargo = data.NOM_CARGO_COLABORADORES ? data.NOM_CARGO_COLABORADORES : idCargo;

        if (idCargo) {
            $("#selectCargo").empty()
                             .append(new Option(nomCargo, idCargo, true, true))
                             .trigger("change");
        }
    })
    .fail(function(xhr){
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar los datos del registro.");
    });
}
function anular(idColaborador){
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    hanaConfirmar("¿Desea anular este registro?", function(){
        $.post("../Control/ColaboradoresControl.php?op=anular",{idColaborador : idColaborador}, function(data){
            tabla.ajax.reload();
            alert("Registro anulado con éxito");
        })
        .fail(function(xhr){
            hanaErrorAjax(xhr, "No se pudo anular el registro.");
        });
    });
}
function activar(idColaborador){
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    //Nota: hoy ninguna pantalla muestra el botón Activar y el controlador no tiene la opción "activar"
    hanaConfirmar("¿Desea activar este registro?", function(){
        $.post("../Control/ColaboradoresControl.php?op=activar",{idColaborador : idColaborador}, function(data){
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
    $("#idColaborador").val('');
    $("#documento").val('');
    $("#nombre").val('');
    $("#email").val('');
    $("#selectCargo").val('');
    
  
}
function mostrarform(flag)
{
    //limpia el formulario
    if (flag)
    {
           // $('#myModal').modal('show');           
    $("#selectCargo").select2({
        ajax: {
          url: '../Control/cargosControl.php?op=select',
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
      placeholder: 'Seleccione un cargo...',
      allowClear: true
      });

        $("#demo-form2").show();
        $("#listadoregistros").hide();
            $("#btnGuardar").prop("disabled",false);
        $("#documento").focus();
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
                                    url: '../Control/ColaboradoresControl.php?op=listar',//pagina que realiza la operación
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
//al oprimir el boton del formulario
    $("#demo-form2").on("submit",function(e)//e = variable que contiene el objeto
    {
            guardar(e);//guarda o edita el articulo
    });
}
init();