var tabla;//variable global
//Función para guardar o editar

function guardar(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    $("#btnGuardar").prop("disabled",true);
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
        url: "../Control/GruposListasControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        success: function(datos)
        {   //console.log(datos) 
            tabla.ajax.reload();  
            mostrarform(false);   
            //$(location).attr('href','../Vista/index.html');   
            setTimeout(() => {
                alert("Registro realizado con exito")
            }, 200);             
        },
        errro: function(error){
            console.error(error)
        }
    });
    limpiar();
}
function mostrar(idLista)
{
    $.post("../Control/GruposListasControl.php?op=mostrar",{idLista : idLista}, function(data)
    {
        data = JSON.parse(data);
        mostrarform(true);
        idGrupoPreguntas=data.ID_GRUPO_LISTA_CHEQUEO
        $("#idLista").val(data.ID_GRUPO_LISTA_CHEQUEO);
        $("#nombre").val(data.NOM_GRUPO_LISTA_CHEQUEO);
        
        $("#listadoPreguntas").show();
        listarPreguntas(idGrupoPreguntas);
    });
}
function mostrarPregunta(idPregunta)
{
    $.post("../Control/PreguntasListasControl.php?op=mostrar",{idPregunta : idPregunta}, function(data)
    {   
        data = JSON.parse(data);
        //console.log('data',data)
        $('#modal-preguntas').modal('show');
        $("#idGrupo").val(data.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO);
        $("#idPregunta").val(data.ID_DETALLE_GRUPO_LISTA_CHEQUEO);
        $("#pregunta").val(data.PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO);
        $("#tipo-respuesta").val(data.TIPO_RESPUESTA);
        
    });
}

function anular(idLista){
    
     if(confirm("Desea anular este registro?")){
        $.post("../Control/GruposListasControl.php?op=anular",{idLista : idLista}, function(data){
            tabla.ajax.reload();
            setTimeout(() => {
                alert("Registro anulado con exito")
            }, 200); 
        });
     }        
}

function anularPregunta(idPregunta, idGrupo) { 
    if(confirm("Desea anular este registro?")){
       $.post("../Control/PreguntasListasControl.php?op=anular",{idPregunta : idPregunta, idGrupo:idGrupo}, function(data){
           tabla.ajax.reload();
           setTimeout(() => {
               alert("Registro anulado con exito")
           }, 200); 
       });
    }

 }

function activar(idLista){
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
                  $.post("../Control/GruposListasControl.php?op=activar",{idLista : idLista}, function(data)
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
    $("#idLista").val("");
    $("#nombre").val("");
    $("#idGrupo").val("");
    $("#idPregunta").val("");
    $("#pregunta").val("");
    $("#tipo-respuesta").val("");
    
  
}
function mostrarform(flag)
{
    var formContainer = $('#x_title');
    //limpia el formulario
    if (flag)
    {
           // $('#myModal').modal('show');
        $("#demo-form2").show();
        $("#listadoPreguntas").hide();
        $("#listadoregistros").hide();
        $("#btnGuardar").prop("disabled",false);
    }
    else
    {	limpiar(); 
        $("#demo-form2").hide();
        $('#modal-preguntas').modal('hide');
        $('#modal-excel').modal('hide');
        $("#listadoregistros").show();
        $("#listadoPreguntas").hide();
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
                                    url: '../Control/GruposListasControl.php?op=listar',//pagina que realiza la operación
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
//Función Listar
function listarPreguntas(idGrupoPreguntas)
{   var idGrupoPreguntas ={'idGrupoPreguntas':idGrupoPreguntas};
    tabla=$('#tblPreguntas').dataTable(//Carga variable con datos datatable
    {
            "aProcessing": true,//Activamos el procesamiento del datatables
        "aServerSide": true,//Paginación y filtrado realizados por el servidor
        dom: 'Bfrtip',//Definimos los elementos del control de tabla Bfrtip
        buttons: [
                        
                    ],
        "ajax"://metodo ajax
                            {
                                    url: '../Control/PreguntasListasControl.php?op=listar',//pagina que realiza la operación
                                    type : "post",//tipo de envio de datos
                                    data: idGrupoPreguntas,
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
function agregarPregunta(e){
    //e.preventDefault();
    var formData1 = new FormData();
    formData1.append('idGrupo',$("#idGrupo").val());
    formData1.append('idPregunta',$("#idPregunta").val());
    formData1.append('pregunta',$("#pregunta").val());
    formData1.append('tipo-respuesta',$("#tipo-respuesta").val());
    $.ajax({
        url: "../Control/PreguntasListasControl.php?op=guardar",
        type: "POST",
        data: formData1,
        contentType: false,
        processData: false,
        success: function(datos)
        {  
            tabla.ajax.reload();
             console.log("datos ",datos) 
             mostrarform(false);
            //$(location).attr('href','../Vista/index.html');   
            setTimeout(() => {
                alert("Registro realizado con exito")
            }, 200);             
        }
    });
    /*/ Variable global para almacenar el índice del elemento en edición
    var indiceEnEdicion = -1;
    $("#pregunta").focus();
    // Obtiene los valores de los campos del formulario.
    const pregunta = $("#pregunta").val();
    const tipoRespuesta = $("#tipo-respuesta").val();
    // Agrega a la lista
    $("#listaPreguntas").append("<li class='list-group-item' style='font-size: 16px'>Pregunta: <span class='pregunta'>" + pregunta + "</span>, Tipo de respuesta: <span class='tipoRespuesta'>" + tipoRespuesta + "</span> <button class='eliminarBtn btn-success rounded-circle'><i class='fa fa-trash' style='color:white;'></i></button></li>");
    //$("#listaPreguntas").append("<input class='form-control pregunta' style='font-size: 18px' value=" + pregunta + "></input><input class='form-control tipoRespuesta' style='font-size: 18px' value=" + tipoRespuesta + "></input> <button class='eliminarBtn btn-success'><i class='fa fa-trash' style='color:white;'></i></button>");
    // Limpia el formulario y cierra el modal
    $("#pregunta").val("");
    $("#pregunta").focus();
    
    // Agregar evento para eliminar elementos al hacer clic en el botón "Eliminar"
    $("#listaPreguntas").on("click", ".eliminarBtn", function () {
        $(this).closest("li").remove();
    });*/
     
}

function agregarPreguntaExcel(e){
    //e.preventDefault();
    var fileInput = $('#fileInput')[0];
    var file = fileInput.files[0];
    if (file) {
        var formData2 = new FormData();
        formData2.append('file', file);
        formData2.append('idGrupo',$("#idGrupo2").val());
        $.ajax({
            url: "../Control/PreguntasListasControl.php?op=importar",
            type: "POST",
            data: formData2,
            contentType: false,
            processData: false,
            success: function(datos)
            {  
                tabla.ajax.reload();
                console.log("datos ",datos) 
                mostrarform(false);
                setTimeout(() => {
                    alert("Registro realizado con exito")
                }, 200);             
            },
            error: function () {
                alert('Error al procesar el archivo.');
            }
        });
    } else {
        alert('Selecciona un archivo Excel.');
    }    
}

function init()
{
    mostrarform(false);
    listar();//lista 
//al oprimir el boton del formulario
    $("#demo-form2").on("submit",function(e)//e = variable que contiene el objeto
    {
            guardar(e);//guarda o edita el articulo
    });

    // Agrega un evento al botón "Agregar pregunta".
    $("#btn-agregar-pregunta").on("click", function(e) {
        agregarPregunta(e);
      });

      // Agrega un evento al botón "Agregar pregunta".
      $("#btn-agregar-excel").on("click", function(e) {
          agregarPreguntaExcel(e);
        });
      //captura la variable grupos para add al modal form
    $('#modal-preguntas').on('show.bs.modal', function (event) {
        //e.preventDefault;
        var button = $(event.relatedTarget);
        var miVariable = button.data('idgrupo'); // Extraer el valor de data-mi-variable
        // Actualizar el contenido del modal con la variable
        var modal = $(this);
        modal.find('#idGrupo').val(miVariable);
      });
      //captura la variable grupos para add al modal form
    $('#modal-excel').on('show.bs.modal', function (event) {
        //e.preventDefault;
        var button = $(event.relatedTarget);
        var miVariable = button.data('idgrupo'); // Extraer el valor de data-mi-variable
        // Actualizar el contenido del modal con la variable
        var modal = $(this);
        modal.find('#idGrupo2').val(miVariable);
      });
    $('#descargarCSV').on("click",function() {
        window.location.href = '../public/preguntasExcel.csv ';
    });
}
init();