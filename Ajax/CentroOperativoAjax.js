var tabla;//variable global
//Función para guardar o editar
function guardar(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    $("#btnGuardar").prop("disabled",true);
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
            url: "../Control/CentroOperativoControl.php?op=guardar",
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
function mostrar(idcentro)
{
    $.post("../Control/CentroOperativoControl.php?op=mostrar",{idcentro : idcentro}, function(data)
    {
    data = JSON.parse(data);
    mostrarform(true);
    $("#idcentro").val(data.ID_CENTRO_OP);
    $("#nombre").val(data.NOM_CENTRO_OP);
    $("#idProyecto").val(data.ID_PROYECTO);
    $("#nombreProyecto").val(data.NOM_PROYECTO);
    });
}
function anular(idcentro){
    
     if(confirm("Desea anular este registro?")){
        $.post("../Control/CentroOperativoControl.php?op=anular",{idcentro : idcentro}, function(data){
            tabla.ajax.reload();
            setTimeout(() => {
                alert("Registro anulado con exito")
            }, 200); 
        });
     }
        
}
function activar(idcentro){
    
     if(confirm("Desea activar este registro?")){
        $.post("../Control/CentroOperativoControl.php?op=activar",{idcentro : idcentro}, function(data){
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
    $("#idcentro").val("");
    $("#nombre").val("");
    //$("#idProyecto").val("");
    //$("#nombreProyecto").val("");
    
  
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