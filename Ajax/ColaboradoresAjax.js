var tabla;//variable global
//Función para guardar o editar
function guardar(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    $("#btnGuardar").prop("disabled",true);
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
            url: "../Control/ColaboradoresControl.php?op=guardar",
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
function mostrar(idColaborador)
{
    $.post("../Control/ColaboradoresControl.php?op=mostrar",{idColaborador : idColaborador}, function(data)
    {
    data = JSON.parse(data);
    mostrarform(true);
    $("#idColaborador").val(data.ID_COLABORADOR);
    $("#documento").val(data.DOC_COLABORADO);
    $("#nombre").val(data.NOM_COLABORADOR);
    $("#email").val(data.MAIL_COLABORADOR);
    $("#selectCargo").val(data.ID_CARGO_COLABORADORES_COLABORADORES);
    });
}
function anular(idColaborador){
    
     if(confirm("Desea anular este registro?")){
        $.post("../Control/ColaboradoresControl.php?op=anular",{idColaborador : idColaborador}, function(data){
            tabla.ajax.reload();
            setTimeout(() => {
                alert("Registro anulado con exito")
            }, 200); 
        });
     }
        
}
function activar(idColaborador){
    
     if(confirm("Desea activar este registro?")){
        $.post("../Control/ColaboradoresControl.php?op=activar",{idColaborador : idColaborador}, function(data){
            tabla.ajax.reload();
            setTimeout(() => {
                alert("Registro activado con exito")
            }, 200); 
        });
     }
        
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
        "order": [[ 0, "des" ]]//Ordenar (columna,orden)
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