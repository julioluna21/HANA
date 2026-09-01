var tabla;//variable global
//Función para guardar o editar
function guardar(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    $("#btnGuardar").prop("disabled",true);
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
            url: "../Control/cargosControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        success: function(datos)
        {            
            mostrarform(false);
            //$('#myModal').modal('hide');
            tabla.ajax.reload();
            //$(location).attr('href','../Vista/index.html');   
            setTimeout(() => {
                alert("Registro realizado con exito")
            }, 200);             
        }
    });
    limpiar();
}
function mostrar(idCargo)
{
    $.post("../Control/cargosControl.php?op=mostrar",{idCargo : idCargo}, function(data)
    {
    data = JSON.parse(data);
    mostrarform(true);
    $("#idCargo").val(data.ID_CARGO_COLABORADORES);
    $("#nombre").val(data.NOM_CARGO_COLABORADORES);
    $("#permisoNovedad").val(data.PERMISO_ASIGNAR_NOVEDAD);    
    });
}
function anular(idCargo){
    
     if(confirm("Desea anular este registro?")){
        $.post("../Control/cargosControl.php?op=anular",{idCargo : idCargo}, function(data){
            tabla.ajax.reload();
            setTimeout(() => {
                alert("Registro anulado con exito")
            }, 200); 
        });
     }
        
}
function activar(idCargo){
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
                  $.post("../Control/cargosControl.php?op=activar",{idCargo : idCargo}, function(data)
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
function cancelarform()
{
    limpiar();
    mostrarform(false);
}
function limpiar()
{
    $("#idCargo").val("");
  document.getElementById("demo-form2").reset();

    
  
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
                                    url: '../Control/cargosControl.php?op=listar',//pagina que realiza la operación
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
//al oprimir el boton del formulario
    $("#demo-form2").on("submit",function(e)//e = variable que contiene el objeto
    {
            guardar(e);//guarda o edita el articulo
    });
}
init();