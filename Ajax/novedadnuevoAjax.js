var tabla;//variable global
var vistaNov = 'mias'; //"mias" = Mis registros; "todas" = lo que mi rol puede ver
var novedadesPrimeraCarga = true;  //solo la primera carga puede cambiar el filtro sola
var suprimirCambioFiltro = false;  //evita que el cambio automatico vuelva a listar
//Función que se ejecuta al inicio
function init()
{
    mostrarcontenedor(true);
    mostrarform(false)
    //El listado inicial lo decide decidirFiltroInicial(), mas abajo: primero hay
    //que saber si este usuario tiene novedades asignadas
//al oprimir el boton del formulario
    $("#demo-form2").on("submit",function(e)//e = variable que contiene el objeto
    {
            guardar(e);//guarda o edita el articulo
    });
    
    $("#respuesta").on("submit",function(e)//e = variable que contiene el objeto
    {
            respuesta(e);//guarda o edita el articulo
    });
    
    $.post("../Control/TituloControl.php?op=select",function(data){
        $("#titulo").html(data);
    });
    
     $.post("../Control/UsuariosControl.php?op=select2",function(data){
        $("#centroop").html(data);
    });
	
    $.post("../Control/NovedadesControl.php?op=select",function(data){
        $("#validador").html(data);
        decidirFiltroInicial(); //se decide cuando las opciones ya existen

        //Si se llegó desde una notificación de la campana, se abre esa novedad
        //de una vez, sin tener que buscarla en la tabla
        var abrirId = new URLSearchParams(window.location.search).get('abrir');
        if (abrirId && /^\d+$/.test(abrirId)) {
            mostrar(parseInt(abrirId, 10));
            //Se limpia la dirección para que al recargar no la vuelva a abrir
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', 'novedadesVista.php');
            }
        }
    });
    
     $.post("../Control/ObservadorControl.php?op=select",function(data){
        $("#observador").html(data);
    });
    
     $.post("../Control/EstadoControl.php?op=select",function(data){
        $("#prioridad").html(data);
    });
    
    $.post("../Control/UsuariosControl.php?op=select",function(data){
        $("#colaborador").html(data);
    });
    
$('#titulo').select2({
    width: '100%' ,
    
});
    
$('#centroop').select2({
    width: '100%' ,
    
});
    
$('#observador').select2({
    width: '100%' ,
    
});
    
$('#prioridad').select2({
    width: '100%' ,
    
});
    
$('#colaborador').select2({
    width: '100%' ,
    
}); 
	
$('#validador').select2({
    width: '100%' ,
    
});	
    
    cargarRolesNovedad();

    //El control de vista: al cambiarlo se vuelve a listar con los filtros actuales
    $('#vistaNovedades').on('click', '.hana-vista-btn', function () {
        if ($(this).hasClass('activo')) { return; }
        fijarVista($(this).data('vista'));
        avisoFiltro(false);
        condiciones();
    });

    //Los filtros se aplican solos al cambiarlos. El boton de buscar sigue ahi
    //para quien lo use, sobre todo despues de escribir las fechas
    $("#validador, #validador2").on("change", function () {
        if (suprimirCambioFiltro) { return; } //lo cambio el sistema, no el usuario
        avisoFiltro(false); //el usuario ya tomo el control del filtro
        condiciones();
    });

    //La foto de la novedad y la del cierre pasan por el mismo tratamiento:
    //vista previa, nombre del archivo y reduccion de peso antes de enviarla
    prepararFoto("#miarchivo2", "#imagenbt", "#fotoPrevia", "#fotoPreviaImg", "#fotoNombre", "#fotoQuitar");
    prepararFoto("#miarchivo", "#imagenbt2", "#fotoPrevia2", "#fotoPreviaImg2", "#fotoNombre2", "#fotoQuitar2");

    //Botones de foto: "Tomar foto" y "Elegir de mis archivos".
    //El atributo capture se pone y se quita justo antes de abrir el campo:
    //con capture el celular va directo a la camara; sin el, muestra el
    //explorador con las fotos ya tomadas. Asi el usuario elige el camino
    $(document).on("click", ".hana-foto-boton", function () {
        var campo = $($(this).data("campo"));
        if (!campo.length) { return; }

        if ($(this).data("modo") === "camara") {
            campo.attr("capture", "environment");
        } else {
            campo.removeAttr("capture"); //abre el explorador de archivos
        }
        campo[0].click();
    });

    //Al marcar "Finalizada" se muestra el campo de la foto de cierre.
    //Antes el sistema abria el selector de archivos sin decir para que
    $("input[name='estadoNovedad']").on("change", function () {
        $("#respFoto").toggle($(this).val() === "3");
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

function mostrarcontenedor(flag)
{
    if (flag)
    {//partes de la pagina que se muestran o se ocultan
            $('#tablasform').show();
            $('#novedadeschat').hide();
            mostrarform(false);
    }
    else
    {		
             $('#tablasform').hide();
             $('#novedadeschat').show();
             limpiar2();
             
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
    $('#titulo,#centroop,#observador,#prioridad,#colaborador').val("").trigger('change');
    //Se usa limpiarFoto para que el boton, la vista previa y el archivo
    //queden todos en el mismo estado. Antes aqui solo se reponia el icono
    limpiarFoto("#miarchivo2", "#imagenbt", "#fotoPrevia");
  
}

function limpiar2()
{
    document.getElementById("respuesta").reset();
    $("#estadoNovedad").val("");
    $("#IDnovedad").val("");
    $("#btnenvio").prop("disabled",false);
  
}
//Función Listar
function listar(validador,validador2,fechai,fechaf)
{
    tabla=$('#tbllistado').dataTable(//Carga variable con datos datatable
    {
            "aProcessing": true,//Activamos el procesamiento del datatables
        "aServerSide": true,//Paginación y filtrado realizados por el servidor
        dom: 'Bfrtip',//Definimos los elementos del control de tabla Bfrtip
        buttons: [
                        
                    ],
        //El idioma completo de las tablas se define una sola vez en Ajax/ComunAjax.js
        
        "ajax"://metodo ajax
                            {
                                    url: '../Control/NovedadesControl.php?op=listar&vista='+vistaNov+'&validador='+validador+"&validador2="+validador2+"&fechai="+fechai+"&fechaf="+fechaf,//pagina que realiza la operación
                                    type : "get",//tipo de envio de datos
                                    dataType : "json",//tipo de datos
                                    error: function(e){//si error muestra mensaje
                                            console.log(e.responseText);
                                    }
                            },
            "bDestroy": true,
            "iDisplayLength": 10,//Paginación
        "order": [[ 2, "desc" ]]//Ordenar (columna,orden)
    }).DataTable();
    
    
}
//Función para guardar o editar
function guardar(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento
    hanaBoton("#btnGuardar", true); //bloquea el botón y muestra "Guardando..." mientras responde el servidor
    var formData = new FormData($("#demo-form2")[0]);
    $.ajax({
        url: "../Control/NovedadesControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        success: function(datos)
        {
            alert(datos); //el servidor responde con el mensaje exacto: éxito o motivo del error
            //Solo si NO fue un error se cierra el formulario; si falló, lo escrito se conserva
            if (hanaTipoMensaje(datos) !== "error") {
                limpiar();
                limpiarFoto("#miarchivo2", "#imagenbt", "#fotoPrevia");
                mostrarform(false);
                tabla.ajax.reload();
            }
        },
        //Antes no habia manejador de error: una novedad que no se guardaba
        //igual mostraba el mensaje del servidor y se perdia la foto cargada
        error: function(xhr)
        {
            hanaErrorAjax(xhr, "No se pudo guardar la novedad.");
        },
        complete: function()
        {
            hanaBoton("#btnGuardar", false); //pase lo que pase, el botón vuelve a quedar disponible
        }
    });
}

function respuesta(e)
{
    e.preventDefault(); //No se activará la acción predeterminada del evento

    //El estado se lee del boton marcado. Antes era un campo oculto que llenaba
    //el menu escondido detras del reloj de arena
    var estado=$("input[name='estadoNovedad']:checked").val() || "";
    var permisoau=$("#esdaoauditoria").val();
    var id=$("#IDnovedad").val();
    if(estado!="" || permisoau==1){

       hanaBoton("#btnenvio", true, "Enviando...");
       var formData = new FormData($("#respuesta")[0]);
       $.ajax({
        url: "../Control/NovedadesControl.php?op=guardar",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        success: function(datos)
        {
            alert(datos); //el servidor responde con el mensaje exacto: éxito o motivo del error
            //Solo si NO fue un error se limpia la respuesta; si falló, el texto se conserva
            if (hanaTipoMensaje(datos) !== "error") {
                mostrarres(id);
                $("input[name='estadoNovedad']").prop("checked", false);
                $("#respFoto").hide();
                limpiarFoto("#miarchivo", "#imagenbt2", "#fotoPrevia", "#fotoPrevia2");
                $("#nrespuesta").val("");
                tabla.ajax.reload();
            }
        },
        //Antes no habia manejador de error: si la respuesta no se guardaba,
        //el botón quedaba bloqueado y había que recargar la pantalla
        error: function(xhr)
        {
            hanaErrorAjax(xhr, "No se pudo enviar la respuesta.");
        },
        complete: function()
        {
            hanaBoton("#btnenvio", false);
        }
    });

    }else{
      alert("Selecciona el estado de la respuesta antes de enviarla.");
    }
}

function mostrar(IDnovedad)
{
    hanaCargando(true); //aviso de carga mientras llega la novedad
    $.post("../Control/NovedadesControl.php?op=mostrarnovedad",{IDnovedad : IDnovedad}, function(data)
    {
        //Antes aqui habia una espera fija de 1 segundo con "Consultando la base de datos...",
        //aunque los datos ya hubieran llegado. Ahora la ventana dura solo lo que tarda el servidor
        hanaCargando(false);
        data = JSON.parse(data);
        mostrarcontenedor(false);
        $("#IDnovedad").val(IDnovedad);
        $("#encabezado").html('<h6 class="mb-0 d-block">'+data.titulo+' <span class="badge badge-indicator badge-success">. </span></h6><br><span class="text-muted text-small">'+data.fechacreacion+'</span>');
        $("#contenido").html(data.contenido);
        var div = document.getElementById('contenido');
        div.scrollTop = '99999';
    })
    .fail(function(xhr){
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudo cargar la novedad.");
    });
}

function solover(IDnovedad)
{
    hanaCargando(true); //aviso de carga mientras llega la novedad
    $.post("../Control/NovedadesControl.php?op=mostrarnovedad",{IDnovedad : IDnovedad}, function(data)
    {
        //Antes aqui habia una espera fija de 1 segundo con "Consultando la base de datos..."
        hanaCargando(false);
        data = JSON.parse(data);
        mostrarcontenedor(false);
        $("#btnenvio").prop("disabled",true);
        $("#encabezado").html('<h6 class="mb-0 d-block">'+data.titulo+' <span class="badge badge-indicator badge-success">.  </span></h6> <br><span class="text-muted text-small">  fecha:'+data.fechacreacion+'</span>');
        $("#contenido").html(data.contenido);
        var div = document.getElementById('contenido');
        div.scrollTop = '99999';
    })
    .fail(function(xhr){
        hanaCargando(false);
        hanaErrorAjax(xhr, "No se pudo cargar la novedad.");
    });
}

function mostrarres(IDnovedad)
{
    $.post("../Control/NovedadesControl.php?op=mostrarnovedad",{IDnovedad : IDnovedad}, function(data)
    {
        data = JSON.parse(data);
        $("#contenido").html(data.contenido);
        if(data.estado=="Cerrada"){
            $("#btnenvio").prop("disabled",true);
        }else if(data.estado=="Finalizada" && data.permiso!=1){
            $("#btnenvio").prop("disabled",true);
        }
        var div = document.getElementById('contenido');
        div.scrollTop = '9999';
    })
    .fail(function(xhr){
        hanaErrorAjax(xhr, "No se pudo actualizar la conversación de la novedad.");
    });
}

function novedades(op)
{
    if(op==2){
    $("#estadoNovedad").val("2");    
    }else if(op==3){
    $("#estadoNovedad").val("3");     
    }else if(op==4){
    $("#estadoNovedad").val("4");     
    }
    
   
}

function condiciones()
{
 var con1=$("#validador").val();
 var con2=$("#validador2").val();
 var fechai=$("#fechai").val();
 var fechaf=$("#fechaf").val();
  if(fechai!="" && fechaf!=""){
      if(fechai<=fechaf){
         listar(con1,con2,fechai,fechaf);
      }else{
          alert("La fecha inicial no puede ser posterior a la fecha final.");
      }

  }else{
      listar(con1,con2,'','');
  }
}




init();//ejecuta la función init

//=================================================================
//  Fotos de las novedades
//  Antes el campo estaba escondido y la foto del celular se subia tal
//  cual: entre 3 y 8 MB, lenta con datos moviles y a veces por encima
//  del limite de subida del servidor. Ahora se ve lo que se cargo y la
//  imagen se reduce en el propio telefono antes de enviarla.
//=================================================================

var FOTO_LADO_MAX = 1600; //lado mayor de la imagen enviada, en pixeles
var FOTO_CALIDAD  = 0.72; //calidad del JPEG: por debajo de esto se nota

//Deja listo un campo de foto: vista previa, nombre y reduccion de peso
function prepararFoto(campo, boton, previa, imagen, nombre, quitar) {
    var $campo = $(campo);
    if (!$campo.length) { return; }

    $campo.on("change", function () {
        var archivo = this.files && this.files[0];
        if (!archivo) { limpiarFoto(campo, boton, previa); return; }

        //Si llega algo que no es imagen se avisa y no se acepta
        if (archivo.type.indexOf("image/") !== 0) {
            alert("El archivo debe ser una imagen. Elige una foto e intenta de nuevo.");
            limpiarFoto(campo, boton, previa);
            return;
        }

        var $boton = $(boton);
        var textoBoton = $boton.html();
        $boton.html('<i class="fa fa-spinner fa-spin"></i> Preparando la foto...');

        comprimirImagen(archivo, function (comprimida) {
            //Se reemplaza el archivo del formulario por el ya reducido
            if (comprimida && window.DataTransfer) {
                try {
                    var dt = new DataTransfer();
                    dt.items.add(comprimida);
                    $campo[0].files = dt.files;
                } catch (e) {
                    //Si el navegador no lo permite se envia la original: es mas
                    //lento, pero no se pierde la foto
                    console.log("No se pudo reemplazar el archivo:", e);
                }
            }

            var usada = ($campo[0].files && $campo[0].files[0]) || archivo;
            $boton.html(textoBoton);
            $(campo).closest(".form-group, .hana-resp-foto").find(".hana-foto").addClass("tiene-foto");

            //Vista previa con el nombre y el peso final
            var lector = new FileReader();
            lector.onload = function (e) {
                $(imagen).attr("src", e.target.result);
                $(nombre).text(usada.name + "  (" + Math.round(usada.size / 1024) + " KB)");
                $(previa).show();
            };
            lector.readAsDataURL(usada);
        });
    });

    //Boton para descartar la foto elegida
    $(quitar).on("click", function () { limpiarFoto(campo, boton, previa); });
}

//Borra la foto elegida y deja el campo como al principio
function limpiarFoto(campo, boton, previa, previa2) {
    $(campo).val("");
    $(previa).hide();
    if (previa2) { $(previa2).hide(); }
    $(campo).closest(".form-group, .hana-resp-foto").find(".hana-foto").removeClass("tiene-foto");
    if (boton) {
        //Los botones son fijos; solo se repone el del "Tomar foto" por si quedo
        //mostrando "Preparando la foto..."
        $(boton).html('<i class="fa fa-camera" aria-hidden="true"></i> Tomar foto');
    }
}

//Reduce el tamano y el peso de una imagen usando el navegador.
//Devuelve el archivo nuevo; si algo falla, devuelve el original
function comprimirImagen(archivo, alTerminar) {
    //Las imagenes ya livianas no se tocan: recomprimir solo les quita calidad
    if (archivo.size < 400 * 1024) { alTerminar(archivo); return; }

    var lector = new FileReader();
    lector.onerror = function () { alTerminar(archivo); };
    lector.onload = function (e) {
        var img = new Image();
        img.onerror = function () { alTerminar(archivo); };
        img.onload = function () {
            try {
                //Se calcula el tamano nuevo conservando la proporcion
                var ancho = img.width, alto = img.height;
                var mayor = Math.max(ancho, alto);
                if (mayor > FOTO_LADO_MAX) {
                    var escala = FOTO_LADO_MAX / mayor;
                    ancho = Math.round(ancho * escala);
                    alto  = Math.round(alto  * escala);
                }

                var lienzo = document.createElement("canvas");
                lienzo.width = ancho;
                lienzo.height = alto;
                var ctx = lienzo.getContext("2d");
                ctx.drawImage(img, 0, 0, ancho, alto);

                lienzo.toBlob(function (blob) {
                    //Si por lo que sea la comprimida pesa mas, se deja la original
                    if (!blob || blob.size >= archivo.size) { alTerminar(archivo); return; }

                    var nombre = archivo.name.replace(/\.[^.]+$/, "") + ".jpg";
                    var nuevo;
                    try {
                        nuevo = new File([blob], nombre, { type: "image/jpeg", lastModified: Date.now() });
                    } catch (e) {
                        //Navegadores viejos no permiten crear un File
                        blob.name = nombre;
                        nuevo = blob;
                    }
                    alTerminar(nuevo);
                }, "image/jpeg", FOTO_CALIDAD);
            } catch (err) {
                console.log("No se pudo reducir la foto:", err);
                alTerminar(archivo);
            }
        };
        img.src = e.target.result;
    };
    lector.readAsDataURL(archivo);
}

//Decide con que filtro abre la pantalla.
//Lo normal es "Asignadas a mi usuario", para no soltar de entrada las novedades de
//todas las concesiones. Pero hay personas que no tienen ninguna asignada: a ellas
//antes les quedaba la tabla vacia y parecia que el sistema fallaba. Por eso primero
//se pregunta cuantas tiene, y si no tiene ninguna se abre en "Todos" con un aviso
function decidirFiltroInicial() {
    //Se abre en "Mis registros" (lo que la persona creó o tiene asignado).
    //Si no tiene ninguno, se pasa a lo que su rol puede ver, con un aviso,
    //para que la pantalla no quede vacía y parezca que el sistema falla
    $.getJSON('../Control/NovedadesControl.php?op=listar&vista=mias&validador=todo&validador2=0&fechai=&fechaf=')
        .done(function (json) {
            var propias = (json && json.aaData) ? json.aaData.length : 0;
            fijarVista(propias > 0 ? 'mias' : 'todas');
            avisoFiltro(propias === 0);
            fijarFiltro('todo');
            listar('todo', 0, '', '');
        })
        .fail(function () {
            fijarVista('mias');
            fijarFiltro('todo');
            listar('todo', 0, '', '');
        });
}

//Marca en pantalla la vista elegida
function fijarVista(vista) {
    vistaNov = (vista === 'todas') ? 'todas' : 'mias';
    $('#vistaNovedades .hana-vista-btn').removeClass('activo').attr('aria-pressed', 'false');
    $('#vistaNovedades .hana-vista-btn[data-vista="' + vistaNov + '"]').addClass('activo').attr('aria-pressed', 'true');
}

//Carga los roles del selector "Rol a notificar" y ajusta el nombre del botón de vista
function cargarRolesNovedad() {
    $.getJSON('../Control/NovedadesControl.php?op=roles')
        .done(function (d) {
            var h = '<option value="">Selecciona el rol...</option>';
            for (var i = 0; i < d.roles.length; i++) {
                h += '<option value="' + parseInt(d.roles[i].id, 10) + '">' + $('<div>').text(d.roles[i].nombre).html() + '</option>';
            }
            $('#rolDestino').html(h);
            //Quien tiene el permiso ve todas; los demás, las de su rol
            $('#vistaNovedades .hana-vista-todas').text(d.verTodas ? 'Todas las novedades' : 'Las de mi rol');
        })
        .fail(function () { $('#rolDestino').html('<option value="">No se pudieron cargar los roles</option>'); });
}

//Pone un valor en el filtro sin que eso dispare otra consulta
function fijarFiltro(valor) {
    suprimirCambioFiltro = true;
    $("#validador").val(valor).trigger('change');
    suprimirCambioFiltro = false;
}

//Muestra u oculta el aviso de que se cambio el filtro solo
function avisoFiltro(mostrar) {
    var aviso = $("#avisoFiltro");
    if (!mostrar) { aviso.remove(); return; }
    if (aviso.length) { return; }

    $('<div class="hana-aviso-filtro" id="avisoFiltro">' +
      'No tienes novedades propias ni asignadas, as\u00ed que se est\u00e1n mostrando las que puedes ver. ' +
      'Puedes volver a <strong>Mis registros</strong> con el bot\u00f3n de arriba.</div>')
      .insertBefore($("#tbllistado").closest(".panel-body"));
}