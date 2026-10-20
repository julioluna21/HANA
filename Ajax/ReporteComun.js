//Reporte diario - utilidades que comparten arqueos, cronograma y lo que siga
//Todo lleva el prefijo "rd" para no chocar con las funciones de otras pantallas

//Escapa texto para pintarlo como HTML: lo que viene de la base lo escriben usuarios
function rdEsc(texto) { return $('<div>').text(texto == null ? '' : String(texto)).html(); }
//Número con dos dígitos: 7 -> "07"
function rdDos(numero) { return ('0' + numero).slice(-2); }

//"2026-09-16" -> "16/09/2026"
function rdFecha(fecha) {
    if (!fecha) { return ''; }
    var partes = String(fecha).substring(0, 10).split('-');
    return partes.length === 3 ? partes[2] + '/' + partes[1] + '/' + partes[0] : rdEsc(fecha);
}
//"08:00:00" -> "08:00"
function rdHora(hora) { return hora ? String(hora).substring(0, 5) : ''; }

//Un texto largo recortado para que quepa en una celda (ya escapado): "Visita al peaje, arqueo de..."
//En la pantalla se ve recortado; al imprimir sale completo (ver public/css/impresion.css)
function rdRecortar(texto, max) {
    texto = String(texto == null ? '' : texto).replace(/\s+/g, ' ');
    if (texto.length <= max) { return rdEsc(texto); }
    return '<span class="rd-solo-pantalla">' + rdEsc(texto.substring(0, max - 1) + '…') + '</span><span class="rd-solo-papel">' + rdEsc(texto) + '</span>';
}
//Un texto de varios renglones listo para pintar: escapado y con sus saltos de línea
function rdParrafo(texto) { return rdEsc(texto).replace(/\n/g, '<br>'); }

//Fechas sin el desfase de UTC
function rdAFecha(texto) { var partes = texto.split('-'); return new Date(+partes[0], +partes[1] - 1, +partes[2]); }
//De fecha a texto "2026-09-16", y sumar días a una fecha en texto
function rdATexto(fecha) { return fecha.getFullYear() + '-' + rdDos(fecha.getMonth() + 1) + '-' + rdDos(fecha.getDate()); }
function rdSumarDias(texto, cantidad) { var fecha = rdAFecha(texto); fecha.setDate(fecha.getDate() + cantidad); return rdATexto(fecha); }

//---------------------------------------------------------------------------
// El día de un registro: solo HOY, más los días que el administrador le
// habilitó a la persona (Parámetros del sistema → Días habilitados)
//---------------------------------------------------------------------------
var RD_DIAS_SEMANA = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

//¿Ese día se puede registrar? Hoy siempre; otro, solo si está habilitado
function rdDiaPermitido(fecha, hoy, habilitados) {
    return fecha === hoy || (habilitados || []).indexOf(fecha) !== -1;
}

//Pinta los botones del día: "Hoy" y uno por cada día habilitado ("Jue 01/10").
//Cada botón lleva data-fecha con el día en AAAA-MM-DD. tam: 'btn-sm' o 'btn-xs'
function rdBotonesDia(contenedor, hoy, habilitados, tamano) {
    var html = '<button type="button" class="btn btn-default ' + (tamano || 'btn-sm') + '" data-fecha="' + rdEsc(hoy) + '">Hoy</button>';
    (habilitados || []).forEach(function (fechaHabilitada) {
        var fecha = rdAFecha(fechaHabilitada);
        html += '<button type="button" class="btn btn-default ' + (tamano || 'btn-sm') + '" data-fecha="' + rdEsc(fechaHabilitada) + '" title="El administrador te habilitó este día">' +
             '<i class="fa fa-unlock"></i> ' + RD_DIAS_SEMANA[fecha.getDay()] + ' ' + rdDos(fecha.getDate()) + '/' + rdDos(fecha.getMonth() + 1) + '</button>';
    });
    $(contenedor).html(html);
}

//Marca el botón del día que está elegido
function rdMarcarDia(contenedor, fecha) {
    $(contenedor).find('[data-fecha]').removeClass('active').filter('[data-fecha="' + fecha + '"]').addClass('active');
}

//Amarra un campo de fecha a los días permitidos: sin días habilitados queda fijo
//en hoy; con ellos, deja escoger y si escriben otro día vuelve a hoy con un aviso
function rdLimitarFecha(campo, hoy, habilitados) {
    var dias = [hoy].concat(habilitados || []).sort();
    $(campo).attr({ min: dias[0], max: dias[dias.length - 1] })
            .prop('readonly', dias.length === 1) //un solo día posible: no hay nada que escoger
            .off('change.rdDia').on('change.rdDia', function () {
                if (!rdDiaPermitido(this.value, hoy, habilitados)) {
                    this.value = hoy;
                    rdAviso('Solo se registra hoy, o un día que el administrador te haya habilitado.', 'error');
                    $(this).trigger('change'); //para que la pantalla se entere de que volvió a hoy
                }
            });
}

//Pesos colombianos, sin centavos: 1500000 -> "$ 1.500.000"
function rdPesos(valor) {
    valor = Math.round(parseFloat(valor) || 0);
    var conMiles = Math.abs(valor).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return (valor < 0 ? '-' : '') + '$ ' + conMiles;
}
//Lo escrito en un campo de pesos -> número (solo los dígitos)
function rdLeerPesos(texto) { var digitos = String(texto || '').replace(/\D/g, ''); return digitos === '' ? 0 : parseInt(digitos, 10); }

//Los campos .rd-pesos se formatean mientras se escribe: 1500000 -> 1.500.000
$(document).on('input', '.rd-pesos', function () {
    var digitos = this.value.replace(/\D/g, '').substring(0, 12);
    this.value = digitos === '' ? '' : parseInt(digitos, 10).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
});

//El mensaje de error que manda el servidor, o uno por defecto
function rdError(xhr, base) {
    try { return JSON.parse(xhr.responseText).error || base; } catch (error) { return base; }
}

//Llena los selectores de año (el actual y el anterior) y deja el mes actual
function rdPeriodo(selMes, selAnio, hoy) {
    var anio = +hoy.substring(0, 4), html = '';
    for (var anioOpcion = anio; anioOpcion >= anio - 1; anioOpcion--) { html += '<option value="' + anioOpcion + '">' + anioOpcion + '</option>'; }
    $(selAnio).html(html).val(String(anio));
    $(selMes).val(String(+hoy.substring(5, 7)));
}

//===========================================================================
// Alerta del día: "ya registraste hoy" / "hoy todavía no has registrado"
//   rdAlertaDia('listas' | 'arqueos' | 'hoy' | 'cronograma', '#contenedor')
// Se actualiza sola cuando la persona guarda algo en ese módulo
//===========================================================================
var RD_GUARDAR = {
    hoy:        /HoyControl\.php\?op=guardar/,
    listas:     /ListasControl\.php\?op=(guardar|editarUnaRespuesta)/,
    arqueos:    /ArqueoControl\.php\?op=(guardar|anular)/,
    cronograma: /CronogramaControl\.php\?op=(vehiculoDia|agregar|estado)/
};

//Muestra arriba de la pantalla si la persona ya hizo hoy lo de este módulo o si le falta
function rdAlertaDia(modulo, contenedor) {
    //Pregunta al servidor cómo va el día y arma el aviso
    var pintar = function () {
        $.getJSON('../Control/EstadoDiaControl.php?op=estado').done(function (respuesta) {
            var estadoModulo = respuesta.estado && respuesta.estado[modulo];
            if (!estadoModulo) { return; }
            var ok = estadoModulo.ok, texto = '', extra = '';
            if (modulo === 'hoy') {
                texto = ok ? 'Ya registraste Hoy en qué estás para hoy.' : 'Hoy todavía no has registrado dónde estás.';
            } else if (modulo === 'listas') {
                texto = ok ? 'Hoy ya registraste ' + estadoModulo.n + (estadoModulo.n === 1 ? ' lista' : ' listas') + ' de chequeo' +
                           (estadoModulo.ultima ? ' (la última a las ' + rdHora(String(estadoModulo.ultima).substring(11)) + ')' : '') + '.'
                         : 'Hoy todavía no has registrado ninguna lista de chequeo.';
            } else if (modulo === 'arqueos') {
                texto = ok ? 'Hoy ya registraste ' + estadoModulo.n + (estadoModulo.n === 1 ? ' arqueo' : ' arqueos') +
                           (estadoModulo.ultima ? ' (el último a las ' + rdHora(estadoModulo.ultima) + ')' : '') + '.'
                         : 'Hoy todavía no has registrado ningún arqueo.';
            } else if (modulo === 'cronograma') {
                texto = ok ? 'Ya revisaste el cronograma hoy, a las ' + rdHora(String(estadoModulo.revisado).substring(11)) + '. Puedes seguir editándolo.'
                         : 'Hoy todavía no has revisado el cronograma.';
                if (estadoModulo.vehiculos > 0 && estadoModulo.vehiculosReg < estadoModulo.vehiculos) {
                    extra = ' Falta registrar el estado del vehículo de hoy.';
                    ok = false;
                }
            }
            $(contenedor).html('<div class="rd-alerta-dia ' + (ok ? 'rd-alerta-ok' : 'rd-alerta-pend') + '" role="status">' +
                '<i class="fa ' + (ok ? 'fa-check-circle' : 'fa-exclamation-circle') + '"></i> ' + rdEsc(texto + extra) + '</div>');
        });
    };
    pintar();
    //Al guardar algo del módulo, la alerta se vuelve a revisar
    $(document).ajaxSuccess(function (evento, xhr, opciones) {
        if (RD_GUARDAR[modulo] && RD_GUARDAR[modulo].test(opciones.url || '')) { setTimeout(pintar, 300); }
    });
}

//===========================================================================
// Avisos pequeños para los mensajes de éxito (los errores siguen en ventana,
// porque hay que leerlos). Se van solos a los pocos segundos
//===========================================================================
function rdAviso(texto, tipo) {
    var caja = $('#rdAvisos');
    if (!caja.length) { caja = $('<div id="rdAvisos" class="rd-avisos" aria-live="polite"></div>').appendTo('body'); }
    var aviso = $('<div class="rd-aviso ' + (tipo === 'error' ? 'rd-aviso-error' : '') + '"><i class="fa ' +
              (tipo === 'error' ? 'fa-times-circle' : 'fa-check-circle') + '"></i> <span></span></div>');
    aviso.find('span').text(texto);
    caja.append(aviso);
    setTimeout(function () { aviso.addClass('rd-aviso-sale'); setTimeout(function () { aviso.remove(); }, 400); }, 3800);
}

//===========================================================================
// "Tienes cambios sin guardar": avisa antes de salir de un formulario a medias
//   rdVigilarCambios('#formHoy')   se activa al escribir
//   rdCambiosGuardados()           se llama después de guardar
//===========================================================================
var rdHayCambios = false;
//Si hay cambios sin guardar en el formulario, avisa antes de salir de la página
function rdVigilarCambios(form) {
    $(document).on('input change', form + ' input, ' + form + ' textarea, ' + form + ' select', function () { rdHayCambios = true; });
    window.addEventListener('beforeunload', function (evento) {
        if (!rdHayCambios) { return; }
        evento.preventDefault();
        evento.returnValue = ''; //el navegador muestra su propio aviso
    });
}
//Se llama después de guardar, para que ya no avise al salir
function rdCambiosGuardados() { rdHayCambios = false; }

//===========================================================================
// Botón "Ahora" junto a los campos de hora que tengan la clase rd-con-ahora
//===========================================================================
$(function () {
    $('input[type=time].rd-con-ahora').each(function () {
        var campo = $(this);
        if (campo.parent().hasClass('rd-hora-grupo')) { return; }
        campo.wrap('<div class="rd-hora-grupo"></div>');
        $('<button type="button" class="btn btn-default rd-btn-ahora" title="Poner la hora actual">Ahora</button>')
            .insertAfter(campo)
            .on('click', function () {
                if (campo.prop('disabled')) { return; }
                var ahora = new Date();
                campo.val(rdDos(ahora.getHours()) + ':' + rdDos(ahora.getMinutes())).trigger('change');
            });
    });
});

//===========================================================================
// Ver las respuestas de una lista de chequeo (reporte general y tablero)
//   rdVerLista(idLista)
//===========================================================================
function rdVerLista(idLista) {
    var modal = $('#rdModalLista');
    if (!modal.length) {
        modal = $('<div class="modal fade rd-modal-encima" id="rdModalLista" tabindex="-1" role="dialog" aria-labelledby="rdModalListaTitulo">' +
            '<div class="modal-dialog modal-lg" role="document"><div class="modal-content">' +
            '<div class="modal-header rd-modal-cab"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>' +
            '<h4 class="modal-title" id="rdModalListaTitulo">Lista de chequeo</h4></div>' +
            '<div class="modal-body" id="rdModalListaCuerpo"></div></div></div></div>').appendTo('body');
    }
    $('#rdModalListaCuerpo').html('<p class="rd-vacio">Cargando...</p>');
    modal.modal('show');
    $.getJSON('../Control/ControlDiarioControl.php?op=lista&id=' + encodeURIComponent(idLista))
        .done(function (lista) {
            $('#rdModalListaTitulo').text(lista.LISTA + ' · ' + lista.NOM_CENTRO_OP);
            var total = lista.PREGUNTAS.length, resp = lista.PREGUNTAS.filter(function (cadaPregunta) { return cadaPregunta.RESPUESTA !== null && cadaPregunta.RESPUESTA !== ''; }).length;
            var noes = lista.PREGUNTAS.filter(function (cadaPregunta) { return cadaPregunta.TIPO === 'si/no' && /^no$/i.test(cadaPregunta.RESPUESTA || ''); }).length;
            var html = '<div class="rd-det-cab">' +
                    '<div><span class="rd-det-lbl">Fecha y hora</span>' + rdFecha(lista.FECHA) + ' ' + rdHora(String(lista.FECHA).substring(11)) + '</div>' +
                    '<div><span class="rd-det-lbl">Proyecto</span>' + rdEsc(lista.NOM_PROYECTO) + '</div>' +
                    '<div><span class="rd-det-lbl">Diligenció</span>' + rdEsc(lista.PERSONA || '') + '</div>' +
                    '<div><span class="rd-det-lbl">Respuestas</span><span class="rd-tag ' + (resp < total ? 'rd-tag-pend' : 'rd-tag-ok') + '">' + resp + ' de ' + total + '</span>' +
                    (noes ? ' <span class="rd-tag rd-tag-mal">' + noes + (noes === 1 ? ' respuesta "No"' : ' respuestas "No"') + '</span>' : '') + '</div></div>';
            html += '<table class="table table-bordered rd-tabla rd-tabla-compacta"><thead><tr><th style="width:36px;">#</th><th>Pregunta</th><th>Respuesta</th></tr></thead><tbody>';
            for (var i = 0; i < lista.PREGUNTAS.length; i++) {
                var pregunta = lista.PREGUNTAS[i];
                html += '<tr' + (pregunta.TIPO === 'si/no' && /^no$/i.test(pregunta.RESPUESTA || '') ? ' class="rd-fila-no"' : '') + '><td>' + (i + 1) + '</td><td>' + rdEsc(pregunta.PREGUNTA) + '</td><td>' + rdRespuesta(pregunta) + '</td></tr>';
            }
            html += '</tbody></table><div id="rdListaAdj"></div>';
            $('#rdModalListaCuerpo').html(html);
            rdAdjuntos('#rdListaAdj', 'LISTA', idLista);
        })
        .fail(function (xhr) { modal.modal('hide'); alert(rdError(xhr, 'No se pudo abrir la lista.')); });
}

//Cómo se muestra cada respuesta según el tipo de pregunta
function rdRespuesta(pregunta) {
    var respuestaTexto = pregunta.RESPUESTA;
    if (respuestaTexto === null || respuestaTexto === '') { return '<span class="rd-tag rd-tag-pend">Sin responder</span>'; }
    if (pregunta.TIPO === 'si/no') {
        return /^s[ií]$/i.test(respuestaTexto) ? '<span class="rd-tag rd-tag-ok"><i class="fa fa-check"></i> Sí</span>'
             : (/^no$/i.test(respuestaTexto) ? '<span class="rd-tag rd-tag-mal"><i class="fa fa-times"></i> No</span>' : rdEsc(respuestaTexto));
    }
    if (pregunta.TIPO === 'fecha' && /^\d{4}-\d{2}-\d{2}/.test(respuestaTexto)) { return rdFecha(respuestaTexto); }
    if (pregunta.TIPO === 'datetime' && /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/.test(respuestaTexto)) { return rdFecha(respuestaTexto) + ' ' + respuestaTexto.substring(11, 16); }
    //La firma se guarda como imagen en texto: solo se pinta si de verdad es una imagen PNG o JPG
    if (pregunta.TIPO === 'firma') {
        return /^data:image\/(png|jpe?g);base64,[A-Za-z0-9+\/=]+$/.test(respuestaTexto)
            ? '<img class="rd-firma" src="' + respuestaTexto + '" alt="Firma">' : '<span class="rd-ayuda">(firma no disponible)</span>';
    }
    return rdEsc(respuestaTexto).replace(/\n/g, '<br>');
}

//===========================================================================
// Archivos adjuntos: rdAdjuntos('#contenedor', 'COMUNICACION' | 'LISTA', id)
// Pinta la lista de archivos del registro y, si la persona puede, la zona para
// subir (se puede arrastrar o elegir varios), cambiar la descripción y quitar
//===========================================================================
var URL_ADJ = '../Control/AdjuntoControl.php';

//El icono que le corresponde a un archivo según su tipo (PDF, imagen, Excel...)
function rdIconoArchivo(tipo, nombre) {
    var ext = String(nombre).split('.').pop().toLowerCase();
    if (tipo === 'application/pdf') { return 'fa-file-pdf-o'; }
    if (/^image\//.test(tipo)) { return 'fa-file-image-o'; }
    if (/xls|csv/.test(ext)) { return 'fa-file-excel-o'; }
    if (/doc/.test(ext)) { return 'fa-file-word-o'; }
    if (/msg|eml/.test(ext)) { return 'fa-envelope-o'; }
    return 'fa-file-text-o';
}

//Tamaño de un archivo en KB o MB, para mostrarlo
function rdTamano(bytes) { bytes = +bytes || 0; return bytes > 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB'; }

//Trae y pinta los archivos adjuntos de un registro, con los botones para ver o quitar
function rdAdjuntos(contenedor, modulo, id) {
    var caja = $(contenedor);
    caja.html('<div class="adj"><div class="adj-cab"><i class="fa fa-paperclip"></i> Archivos</div><p class="rd-vacio">Cargando...</p></div>');
    $.getJSON(URL_ADJ + '?op=listar&modulo=' + encodeURIComponent(modulo) + '&id=' + encodeURIComponent(id))
        .done(function (respuesta) {
            var html = '<div class="adj"><div class="adj-cab"><i class="fa fa-paperclip"></i> Archivos' + (respuesta.archivos.length ? ' <span class="adj-num">' + respuesta.archivos.length + '</span>' : '') + '</div>';
            if (!respuesta.archivos.length) { html += '<p class="adj-vacio">' + (respuesta.editable ? 'Todavía no hay archivos. Puedes subir el oficio, fotos o soportes.' : 'Sin archivos.') + '</p>'; }
            html += '<ul class="adj-lista">';
            respuesta.archivos.forEach(function (adjunto) {
                var url = URL_ADJ + '?op=ver&id=' + parseInt(adjunto.ID_ADJUNTO, 10), img = /^image\//.test(adjunto.TIPO), pdf = adjunto.TIPO === 'application/pdf';
                //Las imágenes y los PDF se ven en el visor de la misma página (VisorAjax.js); Word, Excel o
                //correos no se pueden ver en el navegador: esos se descargan
                var abre = img ? 'data-visor="adj-' + rdEsc(modulo) + '-' + parseInt(id, 10) + '" title="' + rdEsc(adjunto.NOMBRE) + '"'
                         : (pdf ? 'data-visor-pdf data-nombre="' + rdEsc(adjunto.NOMBRE) + '" title="Ver ' + rdEsc(adjunto.NOMBRE) + '"' : 'title="Descargar ' + rdEsc(adjunto.NOMBRE) + '"');
                if (!img && !pdf) { url += '&descargar=1'; }
                html += '<li class="adj-item">' +
                     (img ? '<a href="' + url + '" ' + abre + ' class="adj-mini"><img src="' + url + '" alt="' + rdEsc(adjunto.NOMBRE) + '" loading="lazy"></a>'
                          : '<a href="' + url + '" ' + abre + ' class="adj-mini adj-icono"><i class="fa ' + rdIconoArchivo(adjunto.TIPO, adjunto.NOMBRE) + '"></i></a>') +
                     '<div class="adj-datos"><a href="' + url + '" ' + abre + ' class="adj-nombre">' + rdEsc(adjunto.NOMBRE) + '</a>' +
                     (adjunto.DESCRIPCION ? '<div class="adj-desc">' + rdEsc(adjunto.DESCRIPCION) + '</div>' : '') +
                     '<div class="adj-meta">' + rdTamano(adjunto.TAMANO) + ' · ' + rdEsc(adjunto.QUIEN || '') + ' · ' + rdFecha(adjunto.FEC_REGISTRO) + '</div></div>' +
                     '<div class="adj-acciones"><a class="btn btn-default btn-xs" href="' + URL_ADJ + '?op=ver&id=' + parseInt(adjunto.ID_ADJUNTO, 10) + '&descargar=1" title="Descargar"><i class="fa fa-download"></i></a>' +
                     (respuesta.editable ? '<button type="button" class="btn btn-default btn-xs adj-describir" data-id="' + parseInt(adjunto.ID_ADJUNTO, 10) + '" data-desc="' + rdEsc(adjunto.DESCRIPCION || '') + '" title="Escribir una descripción"><i class="fa fa-pencil"></i></button>' +
                                   '<button type="button" class="btn btn-default btn-xs adj-borrar" data-id="' + parseInt(adjunto.ID_ADJUNTO, 10) + '" data-nombre="' + rdEsc(adjunto.NOMBRE) + '" title="Quitar"><i class="fa fa-trash"></i></button>' : '') +
                     '</div></li>';
            });
            html += '</ul>';
            if (respuesta.editable) {
                html += '<label class="adj-zona"><input type="file" multiple class="adj-input" accept=".' + respuesta.tipos.join(',.') + '">' +
                     '<i class="fa fa-cloud-upload"></i> <span>Arrastra archivos aquí o <u>elígelos</u></span>' +
                     '<small>PDF, fotos, Word, Excel o correos · hasta ' + respuesta.maxMb + ' MB cada uno</small></label>' +
                     '<div class="adj-progreso" style="display:none;"><div></div></div>';
            }
            html += '</div>';
            caja.html(html).data('adj', { modulo: modulo, id: id });
        })
        .fail(function (xhr) { caja.html('<p class="rd-ayuda">' + rdEsc(rdError(xhr, 'No se pudieron cargar los archivos.')) + '</p>'); });
}

//Subir: al elegir o al soltar archivos en la zona
function rdSubirAdjuntos(caja, archivos) {
    var info = caja.data('adj');
    if (!info || !archivos || !archivos.length) { return; }
    var formulario = new FormData();
    formulario.append('modulo', info.modulo); formulario.append('id', info.id);
    for (var i = 0; i < archivos.length; i++) { formulario.append('archivos[]', archivos[i]); }
    var barra = caja.find('.adj-progreso').show().find('div').css('width', '0%');
    $.ajax({
        url: URL_ADJ + '?op=subir', type: 'POST', data: formulario, processData: false, contentType: false, dataType: 'json',
        xhr: function () {
            var xhr = $.ajaxSettings.xhr();
            if (xhr.upload) { xhr.upload.addEventListener('progress', function (progreso) { if (progreso.lengthComputable) { barra.css('width', Math.round(100 * progreso.loaded / progreso.total) + '%'); } }); }
            return xhr;
        }
    }).done(function (respuesta) {
        rdAviso(respuesta.mensaje);
        if (respuesta.avisos && respuesta.avisos.length) { alert('Algunos archivos no se subieron:\n' + respuesta.avisos.join('\n')); }
        rdAdjuntos(caja, info.modulo, info.id);
    }).fail(function (xhr) { alert(rdError(xhr, 'No se pudieron subir los archivos.')); caja.find('.adj-progreso').hide(); });
}

$(document).on('change', '.adj-input', function () { rdSubirAdjuntos($(this).closest('.adj').parent(), this.files); this.value = ''; });
$(document).on('dragover dragenter', '.adj-zona', function (evento) { evento.preventDefault(); $(this).addClass('encima'); });
$(document).on('dragleave drop', '.adj-zona', function (evento) { evento.preventDefault(); $(this).removeClass('encima'); });
$(document).on('drop', '.adj-zona', function (evento) { rdSubirAdjuntos($(this).closest('.adj').parent(), evento.originalEvent.dataTransfer.files); });
$(document).on('click', '.adj-describir', function () {
    var caja = $(this).closest('.adj').parent(), info = caja.data('adj');
    var descripcion = window.prompt('Descripción del archivo (por ejemplo: "Oficio firmado" o "Foto del carril 3"):', $(this).data('desc'));
    if (descripcion === null) { return; }
    $.post(URL_ADJ + '?op=describir', { id: $(this).data('id'), descripcion: descripcion }, null, 'json')
        .done(function (respuesta) { rdAviso(respuesta.mensaje); rdAdjuntos(caja, info.modulo, info.id); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar la descripción.')); });
});
$(document).on('click', '.adj-borrar', function () {
    var caja = $(this).closest('.adj').parent(), info = caja.data('adj'), id = $(this).data('id');
    hanaConfirmar('¿Quitar "' + $(this).data('nombre') + '"?', function () {
        $.post(URL_ADJ + '?op=borrar', { id: id }, null, 'json')
            .done(function (respuesta) { rdAviso(respuesta.mensaje); rdAdjuntos(caja, info.modulo, info.id); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo quitar el archivo.')); });
    }, { aceptar: 'Sí, quitar', cancelar: 'No' });
});

//===========================================================================
// Requisiciones: el detalle de una RQ con sus fotos, para verlo donde se
// aprueba (Reporte general, Tablero) sin tener que ir a otra pantalla
//===========================================================================
//Cada foto o soporte de una RQ se pide por su número (está guardado en la base)
function rdUrlArchivoRq(idArchivo) { return '../Control/AdjuntoControl.php?op=ver&id=' + parseInt(idArchivo, 10); }

function rdEsFoto(nombre) { return /\.(jpe?g|png|gif|webp)$/i.test(nombre || ''); }

//Las fotos y soportes de una RQ. Las fotos se ven ahí mismo: una grande y, debajo,
//las miniaturas para cambiarla. Cada archivo es { URL, RUTA, NOMBRE_ORIGINAL }
function rdRqArchivosHtml(archivos) {
    archivos = archivos || [];
    if (!archivos.length) { return '<p class="rd-ayuda">Esta RQ no tiene fotos ni soportes.</p>'; }
    var fotos = archivos.filter(function (archivo) { return rdEsFoto(archivo.RUTA); });
    var otros = archivos.filter(function (archivo) { return !rdEsFoto(archivo.RUTA); });
    var html = '';
    if (fotos.length) {
        html += '<div class="rq-visor"><a class="rq-visor-grande" href="' + rdEsc(fotos[0].URL) + '" title="Ver en grande">' + //lo abre el visor de la misma página
             '<img src="' + rdEsc(fotos[0].URL) + '" alt="Foto de la RQ"></a>';
        if (fotos.length > 1) {
            html += '<div class="rq-visor-minis">' + fotos.map(function (archivo, i) {
                return '<button type="button" class="rq-mini' + (i === 0 ? ' activa' : '') + '" data-url="' + rdEsc(archivo.URL) + '" title="' + rdEsc(archivo.NOMBRE_ORIGINAL || '').replace(/"/g, '&quot;') + '">' +
                       '<img src="' + rdEsc(archivo.URL) + '" alt="Foto ' + (i + 1) + '"></button>';
            }).join('') + '</div>';
        }
        html += '</div>';
    }
    if (otros.length) {
        html += '<div class="dq-arch">' + otros.map(function (archivo) {
            //Un PDF se ve en el visor de la misma página; otro tipo de archivo se descarga
            var pdf = /\.pdf$/i.test(archivo.RUTA || '');
            return '<a href="' + rdEsc(archivo.URL) + '" ' + (pdf ? 'data-visor-pdf data-nombre="' + rdEsc(archivo.NOMBRE_ORIGINAL || '') + '"' : 'download="' + rdEsc(archivo.NOMBRE_ORIGINAL || '') + '"') + '>' +
                   '<i class="fa ' + (pdf ? 'fa-file-pdf-o' : 'fa-download') + '"></i> ' + rdEsc(archivo.NOMBRE_ORIGINAL) + '</a>';
        }).join('') + '</div>';
    }
    return html;
}
//Tocar una miniatura la pone en grande
$(document).on('click', '.rq-mini', function () {
    var visor = $(this).closest('.rq-visor'), url = $(this).attr('data-url');
    visor.find('.rq-visor-grande').attr('href', url).find('img').attr('src', url);
    visor.find('.rq-mini').removeClass('activa'); $(this).addClass('activa');
});

//El detalle de una RQ: número, peaje, quién la pidió, los ítems con su cantidad y justificación,
//la observación y las fotos. d es lo que responde RQControl?op=mostrar; fila, lo que ya se tenía del listado
function rdRqDetalleHtml(detalle, fila) {
    fila = fila || {};
    var rq = detalle.rq || {}, esUrgente = (rq.TIPO_RQ || fila.TIPO_RQ) === 'U';
    var dias = parseInt(fila.DIAS != null ? fila.DIAS : rq.DIAS_EN_ESTADO, 10) || 0;
    var html = '<div class="dq-cab"><span class="cd-rq ' + (esUrgente ? 'cd-rq-u' : '') + '">' + (esUrgente ? 'RQ U-' : 'RQ-') + rdEsc(rq.NUMERO_RQ || fila.NUMERO_RQ) + '</span>' +
            (esUrgente ? ' <span class="rd-tag rd-tag-mal">Urgente</span>' : '') + (rq.NOM_RQ_ESTADO ? ' <span class="rd-tag">' + rdEsc(rq.NOM_RQ_ESTADO) + '</span>' : '') + '</div>' +
            '<div class="dq-datos">' +
            '<div><span>Peaje</span><b>' + rdEsc(rq.NOM_CENTRO_OP || fila.NOM_CENTRO_OP) + '</b></div>' +
            '<div><span>Proyecto</span><b>' + rdEsc(rq.NOM_PROYECTO || fila.NOM_PROYECTO) + '</b></div>' +
            '<div><span>La pidió</span><b>' + rdEsc(rq.SOLICITA || fila.SOLICITA || '') + '</b></div>' +
            '<div><span>Fecha</span><b>' + rdFecha(rq.FECHA_RQ || fila.FECHA_RQ) + ' · ' + dias + (dias === 1 ? ' día' : ' días') + ' en este estado</b></div></div>';
    var items = detalle.items || [];
    if (items.length) {
        html += '<div class="dq-sub">Qué se pide (' + items.length + ')</div><ol class="dq-items">';
        items.forEach(function (item) {
            html += '<li><b>' + rdEsc(item.DESCRIPCION) + '</b> · ' + rdEsc(String(parseFloat(item.CANTIDAD))) + ' ' + rdEsc(item.UNIDAD || '') +
                 (item.JUSTIFICACION ? '<div class="dq-just">' + rdEsc(item.JUSTIFICACION) + '</div>' : '') + '</li>';
        });
        html += '</ol>';
    }
    if (rq.OBSERVACION_SST) { html += '<div class="dq-sub">Observación</div><div class="dq-just">' + rdEsc(rq.OBSERVACION_SST) + '</div>'; }
    html += '<div class="dq-sub">Fotos y soportes (' + (detalle.archivos || []).length + ')</div>' + rdRqArchivosHtml(detalle.archivos);
    return html;
}

//Abre una ventana con el detalle de una RQ y sus fotos. Si la persona tiene el módulo en su menú,
//sale el enlace para abrirla allá (donde se aprueba, se rechaza o se corrige)
function rdVerRQ(id) {
    var ventana = $('#rdModalRQ');
    if (!ventana.length) {
        ventana = $('<div class="modal fade rd-modal-encima" id="rdModalRQ" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">' +
              '<div class="modal-header rd-modal-cab"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>' +
              '<h4 class="modal-title">Requisición</h4></div><div class="modal-body" id="rdModalRQCuerpo"></div></div></div></div>').appendTo('body');
    }
    $('#rdModalRQCuerpo').html('<p class="rd-vacio">Cargando...</p>');
    ventana.modal('show');
    $.getJSON('../Control/RQControl.php?op=mostrar&id=' + encodeURIComponent(id))
        .done(function (respuesta) {
            var puedeIr = $('.side-menu a[href="RQVista.php"]').length //solo si tiene el módulo de RQ
                ? '<div class="rd-acciones"><a class="btn btn-default" href="RQVista.php?abrir=' + parseInt(id, 10) + '"><i class="fa fa-wrench"></i> Abrir en Requisiciones</a></div>' : '';
            $('#rdModalRQCuerpo').html(rdRqDetalleHtml(respuesta, {}) + puedeIr);
        })
        .fail(function (xhr) { $('#rdModalRQCuerpo').html('<p class="rd-vacio">' + rdEsc(rdError(xhr, 'No se pudo abrir la RQ.')) + '</p>'); });
}
$(document).on('click', '.btn-ver-rq', function (evento) { evento.preventDefault(); rdVerRQ($(this).attr('data-id')); });

//Las miniaturas de una RQ en una fila de tabla: hasta 3 fotos y cuántas más hay. Tocarlas abre el detalle
function rdRqMinisFila(idRq, archivos) {
    var lista = (archivos ? String(archivos).split('|') : []).filter(function (nombre) { return nombre; });
    if (!lista.length) { return ''; }
    var fotos = lista.filter(rdEsFoto), html = '<div class="cd-rq-fotos">';
    fotos.slice(0, 3).forEach(function (foto) {
        html += '<a href="#" class="btn-ver-rq" data-id="' + parseInt(idRq, 10) + '" title="Ver las fotos de la RQ"><img src="' + rdUrlArchivoRq(foto) + '" alt="Foto de la RQ" loading="lazy"></a>';
    });
    var restantes = lista.length - Math.min(3, fotos.length);
    if (restantes > 0) { html += '<a href="#" class="btn-ver-rq cd-rq-mas" data-id="' + parseInt(idRq, 10) + '">+' + restantes + '</a>'; }
    return html + '</div>';
}
