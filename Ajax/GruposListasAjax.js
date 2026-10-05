var tabla;//variable global
//Función para guardar o editar

function guardar(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    hanaBoton("#btnGuardar", true); //bloquea el botón y muestra "Guardando..." mientras responde el servidor
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
        url: "../Control/GruposListasControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        success: function(datos)
        {
            limpiar(); //se limpia solo cuando el registro de verdad quedó guardado
            mostrarform(false);
            alert("Lista guardada con éxito");
        },
        //Antes decia "errro" en vez de "error", asi que este bloque nunca se ejecutaba
        //y un fallo del servidor pasaba inadvertido
        error: function(xhr)
        {
            hanaErrorAjax(xhr, "No se pudo guardar la lista.");
        },
        complete: function()
        {
            hanaBoton("#btnGuardar", false); //pase lo que pase, el botón vuelve a quedar disponible
        }
    });
}
function mostrar(idLista)
{
    hanaCargando(true); //aviso de carga mientras llegan los datos de la lista
    $.post("../Control/GruposListasControl.php?op=mostrar",{idLista : idLista}, function(data)
    {
        hanaCargando(false);
        data = JSON.parse(data);
        mostrarform(true);
        idGrupoPreguntas=data.ID_GRUPO_LISTA_CHEQUEO
        $("#idLista").val(data.ID_GRUPO_LISTA_CHEQUEO);
        $("#nombre").val(data.NOM_GRUPO_LISTA_CHEQUEO);

        $("#listadoPreguntas").show();
        listarPreguntas(idGrupoPreguntas);
    })
    .fail(function(xhr){
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar los datos de la lista.");
    });
}
function mostrarPregunta(idPregunta)
{
    hanaCargando(true); //aviso de carga mientras llegan los datos de la pregunta
    $.post("../Control/PreguntasListasControl.php?op=mostrar",{idPregunta : idPregunta}, function(data)
    {
        hanaCargando(false);
        data = JSON.parse(data);
        $('#modal-preguntas').modal('show');
        $("#idGrupo").val(data.ID_GRUPO_LISTA_CHEQUEO_DETALLE_GRUPO_LISTA_CHEQUEO);
        $("#idPregunta").val(data.ID_DETALLE_GRUPO_LISTA_CHEQUEO);
        $("#pregunta").val(data.PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO);
        $("#tipo-respuesta").val(data.TIPO_RESPUESTA);

        //Si la pregunta SÍ genera novedad se muestran los campos con sus valores.
        //Si no, hay que limpiarlos y ocultarlos: antes se quedaban con lo de la
        //pregunta abierta anteriormente y esa configuración se guardaba sin querer
        if(data.GENERA_NOVEDAD==1){
            $("#titulo").show();
            $("#novedad").show();
            $("#genera-novedad").val(data.GENERA_NOVEDAD);
            $('#Titulo-novedad').val(data.TITULO_NOVEDAD).trigger('change');
        }else{
            $("#titulo").hide();
            $("#genera-novedad").val("");
            $('#Titulo-novedad').val("").trigger('change');
            //El campo "genera novedad" solo aplica a los tipos si/no y lista
            var tipoActual = data.TIPO_RESPUESTA;
            if(tipoActual=="si/no" || tipoActual=="lista"){ $("#novedad").show(); } else { $("#novedad").hide(); }
        }
    })
    .fail(function(xhr){
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudieron cargar los datos de la pregunta.");
    });
}

function anular(idLista){
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    hanaConfirmar("¿Desea anular esta lista?", function(){
        $.post("../Control/GruposListasControl.php?op=anular",{idLista : idLista}, function(data){
            tabla.ajax.reload();
            alert("Lista anulada con éxito");
        })
        .fail(function(xhr){
            hanaErrorAjax(xhr, "No se pudo anular la lista.");
        });
    });
}

function anularPregunta(idPregunta, idGrupo) {
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    hanaConfirmar("¿Desea anular esta pregunta?", function(){
        $.post("../Control/PreguntasListasControl.php?op=anular",{idPregunta : idPregunta, idGrupo:idGrupo}, function(data){
            tabla.ajax.reload();
            alert("Pregunta anulada con éxito");
        })
        .fail(function(xhr){
            hanaErrorAjax(xhr, "No se pudo anular la pregunta.");
        });
    });
}

function activar(idLista){
    //Pregunta con la ventana del sistema; solo si responde Sí se hace el cambio
    //Nota: el controlador de grupos no tiene la opción "activar"; hoy una lista
    //anulada se reactiva editándola. Queda anotado en el informe de pendientes
    hanaConfirmar("¿Desea activar esta lista?", function(){
        $.post("../Control/GruposListasControl.php?op=activar",{idLista : idLista}, function(data){
            alert(data); //el servidor responde con el mensaje exacto: éxito o motivo del error
            tabla.ajax.reload();
        })
        .fail(function(xhr){
            hanaErrorAjax(xhr, "No se pudo activar la lista.");
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
    $("#idLista").val("");
    $("#nombre").val("");
    $("#idGrupo").val("");
    $("#idPregunta").val("");
    $("#pregunta").val("");
    $("#tipo-respuesta").val("");
	$("#titulo").hide();
    $("#novedad").hide();
	$("#genera-novedad").val("");
   $('#Titulo-novedad').val("").trigger('change');
  
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
        tabla.ajax.reload();            
             
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
    //Validaciones antes de enviar: si falta algo se avisa aquí, sin ir al servidor
    if ($.trim($("#pregunta").val()) === "") { alert("Escribe el texto de la pregunta."); return; }
    if ($("#tipo-respuesta").val() === "")   { alert("Selecciona el tipo de respuesta."); return; }

    hanaBoton("#btn-agregar-pregunta", true);
    var formData1 = new FormData();
    formData1.append('idGrupo',$("#idGrupo").val());
    formData1.append('idPregunta',$("#idPregunta").val());
    formData1.append('pregunta',$("#pregunta").val());
    formData1.append('tipo-respuesta',$("#tipo-respuesta").val());
    formData1.append('genera-novedad',$("#genera-novedad").val());
    formData1.append('Titulo-novedad',$("#Titulo-novedad").val());
    $.ajax({
        url: "../Control/PreguntasListasControl.php?op=guardar",
        type: "POST",
        data: formData1,
        contentType: false,
        processData: false,
        success: function(datos)
        {
            tabla.ajax.reload();
            mostrarform(false);
            alert("Pregunta guardada con éxito");
        },
        //Antes no habia manejador de error: si la pregunta no se guardaba,
        //igual aparecia el mensaje de exito
        error: function(xhr)
        {
            hanaErrorAjax(xhr, "No se pudo guardar la pregunta.");
        },
        complete: function()
        {
            hanaBoton("#btn-agregar-pregunta", false);
        }
    });
}

function agregarPreguntaExcel(e){
    var fileInput = $('#fileInput')[0];
    var file = fileInput.files[0];
    if (!file) { alert('Selecciona primero el archivo con las preguntas.'); return; }

    hanaBoton("#btn-agregar-excel", true, "Importando...");
    var formData2 = new FormData();
    formData2.append('file', file);
    formData2.append('idGrupo', $("#idGrupo2").val());
    $.ajax({
        url: "../Control/PreguntasListasControl.php?op=importar",
        type: "POST",
        data: formData2,
        contentType: false,
        processData: false,
        success: function(datos)
        {
            //El servidor responde con el resumen: cuantas preguntas entraron
            alert(datos);
            limpiarCsv();
            tabla.ajax.reload();
            mostrarform(false);
        },
        error: function (xhr) {
            //Si alguna fila tiene problemas, el servidor devuelve la lista
            //indicando el numero de fila. No se guarda nada hasta corregirla
            hanaErrorAjax(xhr, "No se pudo importar el archivo.");
        },
        complete: function()
        {
            hanaBoton("#btn-agregar-excel", false);
        }
    });
}

//=================================================================
//  Vista previa del CSV
//  El archivo se revisa en el propio navegador antes de enviarlo, con
//  las mismas reglas que aplica el servidor. Asi el usuario ve que va a
//  importar y donde tiene errores, sin esperar una ida y vuelta.
//=================================================================

//Formas en que se puede escribir cada tipo de respuesta
var CSV_TIPOS = {
    'texto':    ['texto', 'text', 'abierta'],
    'si/no':    ['si/no', 'sino', 'si-no', 'si no', 'booleano'],
    'lista':    ['lista', 'seleccion', 'opciones'],
    'fecha':    ['fecha', 'date'],
    'datetime': ['datetime', 'fecha y hora', 'hora', 'time'],
    'firma':    ['firma', 'signature']
};

//Minusculas, sin tildes y sin espacios de sobra
function csvNormaliza(texto) {
    var t = String(texto).trim().toLowerCase();
    if (t.normalize) { t = t.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
    return t.replace(/\s+/g, ' ');
}

//Devuelve el tipo que corresponde, o cadena vacia si no se reconoce
function csvTipo(valor) {
    var n = csvNormaliza(valor);
    for (var clave in CSV_TIPOS) {
        if (CSV_TIPOS[clave].indexOf(n) !== -1) { return clave; }
    }
    return '';
}

//Lee el archivo y arma la vista previa
function revisarCsv(file) {
    var lector = new FileReader();
    lector.onerror = function () {
        $("#csvPrevia").html('<div class="hana-csv-previa"><div class="hana-csv-previa-cab error">No se pudo leer el archivo.</div></div>');
    };
    lector.onload = function (e) {
        var texto = String(e.target.result).replace(/^\uFEFF/, ''); //quita la marca de Excel
        var lineas = texto.split(/\r\n|\r|\n/);

        //Separador: el que mas aparezca en la primera linea con contenido
        var sep = ';';
        for (var i = 0; i < lineas.length; i++) {
            if (lineas[i].trim() !== '') {
                sep = (lineas[i].split(';').length >= lineas[i].split(',').length) ? ';' : ',';
                break;
            }
        }

        var filas = [], errores = 0, vistas = {};
        for (var n = 0; n < lineas.length; n++) {
            var linea = lineas[n];
            if (linea.trim() === '') { continue; }

            var celdas = linea.split(sep);
            var pregunta = (celdas[0] || '').replace(/^"|"$/g, '').trim();
            var tipoTxt  = (celdas[1] || '').replace(/^"|"$/g, '').trim();

            //Fila sin pregunta ni tipo (por ejemplo «;» que deja Excel al guardar como CSV): se ignora,
            //igual que en el servidor
            if (pregunta === '' && tipoTxt === '') { continue; }
            //La primera fila de la plantilla es el encabezado
            if (n === 0 && csvNormaliza(pregunta) === 'pregunta') { continue; }

            var problema = '';
            if (pregunta === '' || tipoTxt === '') {
                problema = 'Falta la pregunta o el tipo';
            } else if (pregunta.length > 200) {
                problema = 'Muy larga (máx. 200)';
            } else if (csvTipo(tipoTxt) === '') {
                problema = 'Tipo no válido';
            } else if (vistas[csvNormaliza(pregunta)]) {
                problema = 'Repetida (fila ' + vistas[csvNormaliza(pregunta)] + ')';
            } else {
                vistas[csvNormaliza(pregunta)] = n + 1;
            }

            if (problema) { errores++; }
            filas.push({ n: n + 1, pregunta: pregunta || '(vacía)', tipo: problema || csvTipo(tipoTxt), mala: !!problema });
        }

        dibujarPreviaCsv(filas, errores);
    };
    lector.readAsText(file, 'UTF-8');
}

//Pinta la vista previa y habilita o bloquea el boton de importar
function dibujarPreviaCsv(filas, errores) {
    var cont = $("#csvPrevia");

    if (!filas.length) {
        cont.html('<div class="hana-csv-previa"><div class="hana-csv-previa-cab error">' +
                  'El archivo no tiene ninguna pregunta.</div></div>');
        $("#btn-agregar-excel").prop("disabled", true);
        return;
    }

    var cab, clase;
    if (errores > 0) {
        clase = 'error';
        cab = '<i class="fa fa-exclamation-triangle"></i> ' + errores + ' fila(s) con problemas. ' +
              'Corrige el archivo y vuelve a subirlo: no se importará nada hasta que esté todo bien.';
    } else {
        clase = 'ok';
        cab = '<i class="fa fa-check-circle"></i> Todo listo: se importarán ' + filas.length + ' pregunta(s).';
    }

    var html = '<div class="hana-csv-previa"><div class="hana-csv-previa-cab ' + clase + '">' + cab + '</div>' +
               '<div class="hana-csv-previa-cuerpo"><table><tbody>';

    for (var i = 0; i < filas.length; i++) {
        var f = filas[i];
        //Se escribe como texto, nunca como HTML: el archivo lo trae el usuario
        html += '<tr class="' + (f.mala ? 'mala' : '') + '">' +
                '<td class="fila-num">' + f.n + '</td>' +
                '<td>' + $('<div>').text(f.pregunta).html() + '</td>' +
                '<td class="fila-tipo">' + $('<div>').text(f.tipo).html() + '</td></tr>';
    }
    html += '</tbody></table></div></div>';

    cont.html(html);
    //Solo se puede importar si el archivo esta completamente bien
    $("#btn-agregar-excel").prop("disabled", errores > 0);
}

//Deja la ventana como estaba al abrirla
function limpiarCsv() {
    $("#fileInput").val("");
    $("#csvNombre").text("");
    $("#csvPrevia").empty();
    $("#btn-agregar-excel").prop("disabled", true);
}

function init()
{
	listar();//lista 
    mostrarform(false);
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
        //Se limpia primero: al abrir "Agregar pregunta" el formulario debe quedar
        //en blanco, sin los datos de la pregunta que se haya consultado antes
        limpiar();
        modal.find('#idGrupo').val(miVariable);
        $("#titulo").hide();
        $("#novedad").hide();
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
        descargarPlantilla();
    });

    //Al elegir el archivo se revisa de una vez y se muestra que va a importarse
    $('#fileInput').on('change', function () {
        var file = this.files && this.files[0];
        if (!file) { limpiarCsv(); return; }

        //Si la pantalla no tiene los elementos nuevos, el archivo no se veria por
        //ningun lado y pareceria que no pasa nada. Mejor decirlo
        if (!$("#csvPrevia").length || !$("#csvNombre").length) {
            alert('La pantalla de carga masiva está desactualizada.\n\n' +
                  'Copia también el archivo Vista/GruposListasVista.php y vuelve a abrir con Ctrl + F5.');
            return;
        }

        $("#csvNombre").text(file.name + '  (' + Math.max(1, Math.round(file.size / 1024)) + ' KB)');

        //Un .xlsx es un archivo comprimido: el navegador no puede leerlo sin una
        //libreria extra, asi que la revision de esos la hace el servidor. La del
        //CSV si se puede mostrar aqui mismo, fila por fila
        var nombre = file.name.toLowerCase();
        if (nombre.slice(-5) === '.xlsx' || nombre.slice(-5) === '.xlsm') {
            $("#csvPrevia").html('<div class="hana-csv-previa"><div class="hana-csv-previa-cab">' +
                '<i class="fa fa-file-excel-o"></i> Archivo de Excel listo. Se leerá la hoja ' +
                '<strong>PREGUNTAS</strong> y se revisará al importar: si alguna fila tiene ' +
                'problemas, se te indicará cuál y no se guardará nada.</div></div>');
            $("#btn-agregar-excel").prop("disabled", false);
            return;
        }
        if (nombre.slice(-4) !== '.csv' && nombre.slice(-4) !== '.txt') {
            $("#csvPrevia").html('<div class="hana-csv-previa"><div class="hana-csv-previa-cab error">' +
                'El archivo debe ser de Excel (.xlsx) o CSV. Descarga la plantilla y trabaja sobre ella.</div></div>');
            $("#btn-agregar-excel").prop("disabled", true);
            return;
        }
        revisarCsv(file);
    });

    //Cada vez que se abre la ventana se parte de cero
    $('#modal-excel').on('show.bs.modal', function () { limpiarCsv(); });
	
	$("#tipo-respuesta").on('change', function () {
	var tipo=$("#tipo-respuesta").val();
    if(tipo=="si/no" || tipo=="lista"){
	$("#novedad").show();	
	}else{
		$("#novedad").hide();
		$("#genera-novedad").val("");
     $('#Titulo-novedad').val("").trigger('change');
	}
	});
	
	$("#genera-novedad").on('change', function () {
	var tipo=$("#genera-novedad").val();
    if(tipo==1){
	$("#titulo").show();	
	}else{
	$("#titulo").hide();
    $('#Titulo-novedad').val("").trigger('change');	
	}	
	  });
	
	$.post("../Control/TituloControl.php?op=select",function(data){
        $("#Titulo-novedad").html(data);
    });
		
	$('#Titulo-novedad').select2({
    width: '100%' ,
    
});	
	
}
init();

//Descarga la plantilla. Antes se hacia con window.location.href, que si el archivo
//no esta lleva al usuario a una pagina de error y lo saca de la pantalla.
//Aqui se comprueba primero que exista y, si falta, se dice exactamente que copiar
function descargarPlantilla() {
    var ruta = '../public/preguntasExcel.xlsx';

    $.ajax({ url: ruta, type: 'HEAD' })
        .done(function () {
            //Un enlace temporal: asi el navegador descarga el archivo sin salir de la pantalla
            var enlace = document.createElement('a');
            enlace.href = ruta;
            enlace.download = 'preguntasExcel.xlsx';
            document.body.appendChild(enlace);
            enlace.click();
            document.body.removeChild(enlace);
        })
        .fail(function () {
            alert('No se encontró la plantilla en el servidor.\n\n' +
                  'Falta copiar el archivo preguntasExcel.xlsx en la carpeta public.');
        });
}