//Reporte diario — utilidades que comparten arqueos, cronograma y lo que siga
//Todo lleva el prefijo "rd" para no chocar con las funciones de otras pantallas

//Escapa texto para pintarlo como HTML: lo que viene de la base lo escriben usuarios
function rdEsc(t) { return $('<div>').text(t == null ? '' : String(t)).html(); }
function rdDos(n) { return ('0' + n).slice(-2); }

//"2026-09-16" -> "16/09/2026"
function rdFecha(f) {
    if (!f) { return ''; }
    var p = String(f).substring(0, 10).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : rdEsc(f);
}
//"08:00:00" -> "08:00"
function rdHora(t) { return t ? String(t).substring(0, 5) : ''; }

//Un texto largo recortado para que quepa en una celda (ya escapado): "Visita al peaje, arqueo de…"
function rdRecortar(t, max) {
    t = String(t == null ? '' : t).replace(/\s+/g, ' ');
    return rdEsc(t.length > max ? t.substring(0, max - 1) + '…' : t);
}
//Un texto de varios renglones listo para pintar: escapado y con sus saltos de línea
function rdParrafo(t) { return rdEsc(t).replace(/\n/g, '<br>'); }

//Fechas sin el desfase de UTC
function rdAFecha(txt) { var p = txt.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
function rdATexto(d) { return d.getFullYear() + '-' + rdDos(d.getMonth() + 1) + '-' + rdDos(d.getDate()); }
function rdSumarDias(txt, n) { var d = rdAFecha(txt); d.setDate(d.getDate() + n); return rdATexto(d); }

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
function rdBotonesDia(contenedor, hoy, habilitados, tam) {
    var h = '<button type="button" class="btn btn-default ' + (tam || 'btn-sm') + '" data-fecha="' + rdEsc(hoy) + '">Hoy</button>';
    (habilitados || []).forEach(function (f) {
        var d = rdAFecha(f);
        h += '<button type="button" class="btn btn-default ' + (tam || 'btn-sm') + '" data-fecha="' + rdEsc(f) + '" title="El administrador te habilitó este día">' +
             '<i class="fa fa-unlock"></i> ' + RD_DIAS_SEMANA[d.getDay()] + ' ' + rdDos(d.getDate()) + '/' + rdDos(d.getMonth() + 1) + '</button>';
    });
    $(contenedor).html(h);
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
function rdPesos(n) {
    n = Math.round(parseFloat(n) || 0);
    var s = Math.abs(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return (n < 0 ? '-' : '') + '$ ' + s;
}
//Lo escrito en un campo de pesos -> número (solo los dígitos)
function rdLeerPesos(v) { var d = String(v || '').replace(/\D/g, ''); return d === '' ? 0 : parseInt(d, 10); }

//Los campos .rd-pesos se formatean mientras se escribe: 1500000 -> 1.500.000
$(document).on('input', '.rd-pesos', function () {
    var d = this.value.replace(/\D/g, '').substring(0, 12);
    this.value = d === '' ? '' : parseInt(d, 10).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
});

//El mensaje de error que manda el servidor, o uno por defecto
function rdError(xhr, base) {
    try { return JSON.parse(xhr.responseText).error || base; } catch (e) { return base; }
}

//Llena los selectores de año (el actual y el anterior) y deja el mes actual
function rdPeriodo(selMes, selAnio, hoy) {
    var anio = +hoy.substring(0, 4), h = '';
    for (var a = anio; a >= anio - 1; a--) { h += '<option value="' + a + '">' + a + '</option>'; }
    $(selAnio).html(h).val(String(anio));
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

function rdAlertaDia(modulo, contenedor) {
    var pintar = function () {
        $.getJSON('../Control/EstadoDiaControl.php?op=estado').done(function (d) {
            var e = d.estado && d.estado[modulo];
            if (!e) { return; }
            var ok = e.ok, txt = '', extra = '';
            if (modulo === 'hoy') {
                txt = ok ? 'Ya registraste Hoy en qué estás para hoy.' : 'Hoy todavía no has registrado dónde estás.';
            } else if (modulo === 'listas') {
                txt = ok ? 'Hoy ya registraste ' + e.n + (e.n === 1 ? ' lista' : ' listas') + ' de chequeo' +
                           (e.ultima ? ' (la última a las ' + rdHora(String(e.ultima).substring(11)) + ')' : '') + '.'
                         : 'Hoy todavía no has registrado ninguna lista de chequeo.';
            } else if (modulo === 'arqueos') {
                txt = ok ? 'Hoy ya registraste ' + e.n + (e.n === 1 ? ' arqueo' : ' arqueos') +
                           (e.ultima ? ' (el último a las ' + rdHora(e.ultima) + ')' : '') + '.'
                         : 'Hoy todavía no has registrado ningún arqueo.';
            } else if (modulo === 'cronograma') {
                txt = ok ? 'Ya revisaste el cronograma hoy, a las ' + rdHora(String(e.revisado).substring(11)) + '. Puedes seguir editándolo.'
                         : 'Hoy todavía no has revisado el cronograma.';
                if (e.vehiculos > 0 && e.vehiculosReg < e.vehiculos) {
                    extra = ' Falta registrar el estado del vehículo de hoy.';
                    ok = false;
                }
            }
            $(contenedor).html('<div class="rd-alerta-dia ' + (ok ? 'rd-alerta-ok' : 'rd-alerta-pend') + '" role="status">' +
                '<i class="fa ' + (ok ? 'fa-check-circle' : 'fa-exclamation-circle') + '"></i> ' + rdEsc(txt + extra) + '</div>');
        });
    };
    pintar();
    //Al guardar algo del módulo, la alerta se vuelve a revisar
    $(document).ajaxSuccess(function (ev, xhr, opciones) {
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
    var a = $('<div class="rd-aviso ' + (tipo === 'error' ? 'rd-aviso-error' : '') + '"><i class="fa ' +
              (tipo === 'error' ? 'fa-times-circle' : 'fa-check-circle') + '"></i> <span></span></div>');
    a.find('span').text(texto);
    caja.append(a);
    setTimeout(function () { a.addClass('rd-aviso-sale'); setTimeout(function () { a.remove(); }, 400); }, 3800);
}

//===========================================================================
// "Tienes cambios sin guardar": avisa antes de salir de un formulario a medias
//   rdVigilarCambios('#formHoy')   se activa al escribir
//   rdCambiosGuardados()           se llama después de guardar
//===========================================================================
var rdHayCambios = false;
function rdVigilarCambios(form) {
    $(document).on('input change', form + ' input, ' + form + ' textarea, ' + form + ' select', function () { rdHayCambios = true; });
    window.addEventListener('beforeunload', function (e) {
        if (!rdHayCambios) { return; }
        e.preventDefault();
        e.returnValue = ''; //el navegador muestra su propio aviso
    });
}
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
                var d = new Date();
                campo.val(rdDos(d.getHours()) + ':' + rdDos(d.getMinutes())).trigger('change');
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
        .done(function (l) {
            $('#rdModalListaTitulo').text(l.LISTA + ' · ' + l.NOM_CENTRO_OP);
            var total = l.PREGUNTAS.length, resp = l.PREGUNTAS.filter(function (p) { return p.RESPUESTA !== null && p.RESPUESTA !== ''; }).length;
            var noes = l.PREGUNTAS.filter(function (p) { return p.TIPO === 'si/no' && /^no$/i.test(p.RESPUESTA || ''); }).length;
            var h = '<div class="rd-det-cab">' +
                    '<div><span class="rd-det-lbl">Fecha y hora</span>' + rdFecha(l.FECHA) + ' ' + rdHora(String(l.FECHA).substring(11)) + '</div>' +
                    '<div><span class="rd-det-lbl">Proyecto</span>' + rdEsc(l.NOM_PROYECTO) + '</div>' +
                    '<div><span class="rd-det-lbl">Diligenció</span>' + rdEsc(l.PERSONA || '') + '</div>' +
                    '<div><span class="rd-det-lbl">Respuestas</span><span class="rd-tag ' + (resp < total ? 'rd-tag-pend' : 'rd-tag-ok') + '">' + resp + ' de ' + total + '</span>' +
                    (noes ? ' <span class="rd-tag rd-tag-mal">' + noes + (noes === 1 ? ' respuesta "No"' : ' respuestas "No"') + '</span>' : '') + '</div></div>';
            h += '<table class="table table-bordered rd-tabla rd-tabla-compacta"><thead><tr><th style="width:36px;">#</th><th>Pregunta</th><th>Respuesta</th></tr></thead><tbody>';
            for (var i = 0; i < l.PREGUNTAS.length; i++) {
                var p = l.PREGUNTAS[i];
                h += '<tr' + (p.TIPO === 'si/no' && /^no$/i.test(p.RESPUESTA || '') ? ' class="rd-fila-no"' : '') + '><td>' + (i + 1) + '</td><td>' + rdEsc(p.PREGUNTA) + '</td><td>' + rdRespuesta(p) + '</td></tr>';
            }
            h += '</tbody></table><div id="rdListaAdj"></div>';
            $('#rdModalListaCuerpo').html(h);
            rdAdjuntos('#rdListaAdj', 'LISTA', idLista);
        })
        .fail(function (xhr) { modal.modal('hide'); alert(rdError(xhr, 'No se pudo abrir la lista.')); });
}

//Cómo se muestra cada respuesta según el tipo de pregunta
function rdRespuesta(p) {
    var r = p.RESPUESTA;
    if (r === null || r === '') { return '<span class="rd-tag rd-tag-pend">Sin responder</span>'; }
    if (p.TIPO === 'si/no') {
        return /^s[ií]$/i.test(r) ? '<span class="rd-tag rd-tag-ok"><i class="fa fa-check"></i> Sí</span>'
             : (/^no$/i.test(r) ? '<span class="rd-tag rd-tag-mal"><i class="fa fa-times"></i> No</span>' : rdEsc(r));
    }
    if (p.TIPO === 'fecha' && /^\d{4}-\d{2}-\d{2}/.test(r)) { return rdFecha(r); }
    if (p.TIPO === 'datetime' && /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/.test(r)) { return rdFecha(r) + ' ' + r.substring(11, 16); }
    //La firma se guarda como imagen en texto: solo se pinta si de verdad es una imagen PNG o JPG
    if (p.TIPO === 'firma') {
        return /^data:image\/(png|jpe?g);base64,[A-Za-z0-9+\/=]+$/.test(r)
            ? '<img class="rd-firma" src="' + r + '" alt="Firma">' : '<span class="rd-ayuda">(firma no disponible)</span>';
    }
    return rdEsc(r).replace(/\n/g, '<br>');
}

//===========================================================================
// Archivos adjuntos: rdAdjuntos('#contenedor', 'COMUNICACION' | 'LISTA', id)
// Pinta la lista de archivos del registro y, si la persona puede, la zona para
// subir (se puede arrastrar o elegir varios), cambiar la descripción y quitar
//===========================================================================
var URL_ADJ = '../Control/AdjuntoControl.php';

function rdIconoArchivo(tipo, nombre) {
    var ext = String(nombre).split('.').pop().toLowerCase();
    if (tipo === 'application/pdf') { return 'fa-file-pdf-o'; }
    if (/^image\//.test(tipo)) { return 'fa-file-image-o'; }
    if (/xls|csv/.test(ext)) { return 'fa-file-excel-o'; }
    if (/doc/.test(ext)) { return 'fa-file-word-o'; }
    if (/msg|eml/.test(ext)) { return 'fa-envelope-o'; }
    return 'fa-file-text-o';
}

function rdTamano(b) { b = +b || 0; return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }

function rdAdjuntos(contenedor, modulo, id) {
    var caja = $(contenedor);
    caja.html('<div class="adj"><div class="adj-cab"><i class="fa fa-paperclip"></i> Archivos</div><p class="rd-vacio">Cargando...</p></div>');
    $.getJSON(URL_ADJ + '?op=listar&modulo=' + encodeURIComponent(modulo) + '&id=' + encodeURIComponent(id))
        .done(function (d) {
            var h = '<div class="adj"><div class="adj-cab"><i class="fa fa-paperclip"></i> Archivos' + (d.archivos.length ? ' <span class="adj-num">' + d.archivos.length + '</span>' : '') + '</div>';
            if (!d.archivos.length) { h += '<p class="adj-vacio">' + (d.editable ? 'Todavía no hay archivos. Puedes subir el oficio, fotos o soportes.' : 'Sin archivos.') + '</p>'; }
            h += '<ul class="adj-lista">';
            d.archivos.forEach(function (a) {
                var url = URL_ADJ + '?op=ver&id=' + parseInt(a.ID_ADJUNTO, 10), img = /^image\//.test(a.TIPO);
                h += '<li class="adj-item">' +
                     (img ? '<a href="' + url + '" target="_blank" rel="noopener" class="adj-mini"><img src="' + url + '" alt="" loading="lazy"></a>'
                          : '<a href="' + url + '" target="_blank" rel="noopener" class="adj-mini adj-icono"><i class="fa ' + rdIconoArchivo(a.TIPO, a.NOMBRE) + '"></i></a>') +
                     '<div class="adj-datos"><a href="' + url + '" target="_blank" rel="noopener" class="adj-nombre">' + rdEsc(a.NOMBRE) + '</a>' +
                     (a.DESCRIPCION ? '<div class="adj-desc">' + rdEsc(a.DESCRIPCION) + '</div>' : '') +
                     '<div class="adj-meta">' + rdTamano(a.TAMANO) + ' · ' + rdEsc(a.QUIEN || '') + ' · ' + rdFecha(a.FEC_REGISTRO) + '</div></div>' +
                     '<div class="adj-acciones"><a class="btn btn-default btn-xs" href="' + url + '&descargar=1" title="Descargar"><i class="fa fa-download"></i></a>' +
                     (d.editable ? '<button type="button" class="btn btn-default btn-xs adj-describir" data-id="' + parseInt(a.ID_ADJUNTO, 10) + '" data-desc="' + rdEsc(a.DESCRIPCION || '') + '" title="Escribir una descripción"><i class="fa fa-pencil"></i></button>' +
                                   '<button type="button" class="btn btn-default btn-xs adj-borrar" data-id="' + parseInt(a.ID_ADJUNTO, 10) + '" data-nombre="' + rdEsc(a.NOMBRE) + '" title="Quitar"><i class="fa fa-trash"></i></button>' : '') +
                     '</div></li>';
            });
            h += '</ul>';
            if (d.editable) {
                h += '<label class="adj-zona"><input type="file" multiple class="adj-input" accept=".' + d.tipos.join(',.') + '">' +
                     '<i class="fa fa-cloud-upload"></i> <span>Arrastra archivos aquí o <u>elígelos</u></span>' +
                     '<small>PDF, fotos, Word, Excel o correos · hasta ' + d.maxMb + ' MB cada uno</small></label>' +
                     '<div class="adj-progreso" style="display:none;"><div></div></div>';
            }
            h += '</div>';
            caja.html(h).data('adj', { modulo: modulo, id: id });
        })
        .fail(function (xhr) { caja.html('<p class="rd-ayuda">' + rdEsc(rdError(xhr, 'No se pudieron cargar los archivos.')) + '</p>'); });
}

//Subir: al elegir o al soltar archivos en la zona
function rdSubirAdjuntos(caja, archivos) {
    var info = caja.data('adj');
    if (!info || !archivos || !archivos.length) { return; }
    var fd = new FormData();
    fd.append('modulo', info.modulo); fd.append('id', info.id);
    for (var i = 0; i < archivos.length; i++) { fd.append('archivos[]', archivos[i]); }
    var barra = caja.find('.adj-progreso').show().find('div').css('width', '0%');
    $.ajax({
        url: URL_ADJ + '?op=subir', type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        xhr: function () {
            var x = $.ajaxSettings.xhr();
            if (x.upload) { x.upload.addEventListener('progress', function (e) { if (e.lengthComputable) { barra.css('width', Math.round(100 * e.loaded / e.total) + '%'); } }); }
            return x;
        }
    }).done(function (r) {
        rdAviso(r.mensaje);
        if (r.avisos && r.avisos.length) { alert('Algunos archivos no se subieron:\n' + r.avisos.join('\n')); }
        rdAdjuntos(caja, info.modulo, info.id);
    }).fail(function (xhr) { alert(rdError(xhr, 'No se pudieron subir los archivos.')); caja.find('.adj-progreso').hide(); });
}

$(document).on('change', '.adj-input', function () { rdSubirAdjuntos($(this).closest('.adj').parent(), this.files); this.value = ''; });
$(document).on('dragover dragenter', '.adj-zona', function (e) { e.preventDefault(); $(this).addClass('encima'); });
$(document).on('dragleave drop', '.adj-zona', function (e) { e.preventDefault(); $(this).removeClass('encima'); });
$(document).on('drop', '.adj-zona', function (e) { rdSubirAdjuntos($(this).closest('.adj').parent(), e.originalEvent.dataTransfer.files); });
$(document).on('click', '.adj-describir', function () {
    var caja = $(this).closest('.adj').parent(), info = caja.data('adj');
    var d = window.prompt('Descripción del archivo (por ejemplo: "Oficio firmado" o "Foto del carril 3"):', $(this).data('desc'));
    if (d === null) { return; }
    $.post(URL_ADJ + '?op=describir', { id: $(this).data('id'), descripcion: d }, null, 'json')
        .done(function (r) { rdAviso(r.mensaje); rdAdjuntos(caja, info.modulo, info.id); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar la descripción.')); });
});
$(document).on('click', '.adj-borrar', function () {
    var caja = $(this).closest('.adj').parent(), info = caja.data('adj'), id = $(this).data('id');
    hanaConfirmar('¿Quitar "' + $(this).data('nombre') + '"?', function () {
        $.post(URL_ADJ + '?op=borrar', { id: id }, null, 'json')
            .done(function (r) { rdAviso(r.mensaje); rdAdjuntos(caja, info.modulo, info.id); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo quitar el archivo.')); });
    }, { aceptar: 'Sí, quitar', cancelar: 'No' });
});

//===========================================================================
// Requisiciones: el detalle de una RQ con sus fotos, para verlo donde se
// aprueba (Reporte general, Tablero) sin tener que ir a otra pantalla
//===========================================================================
var RD_RUTA_RQ = '../public/rq/'; //donde quedan los archivos de las RQ

function rdEsFoto(nombre) { return /\.(jpe?g|png|gif|webp)$/i.test(nombre || ''); }

//Las fotos y soportes de una RQ. Las fotos se ven ahí mismo: una grande y, debajo,
//las miniaturas para cambiarla. Cada archivo es { URL, RUTA, NOMBRE_ORIGINAL }
function rdRqArchivosHtml(archivos) {
    archivos = archivos || [];
    if (!archivos.length) { return '<p class="rd-ayuda">Esta RQ no tiene fotos ni soportes.</p>'; }
    var fotos = archivos.filter(function (a) { return rdEsFoto(a.RUTA); });
    var otros = archivos.filter(function (a) { return !rdEsFoto(a.RUTA); });
    var h = '';
    if (fotos.length) {
        h += '<div class="rq-visor"><a class="rq-visor-grande" href="' + rdEsc(fotos[0].URL) + '" target="_blank" rel="noopener" title="Abrir en tamaño completo">' +
             '<img src="' + rdEsc(fotos[0].URL) + '" alt="Foto de la RQ"></a>';
        if (fotos.length > 1) {
            h += '<div class="rq-visor-minis">' + fotos.map(function (a, i) {
                return '<button type="button" class="rq-mini' + (i === 0 ? ' activa' : '') + '" data-url="' + rdEsc(a.URL) + '" title="' + rdEsc(a.NOMBRE_ORIGINAL || '').replace(/"/g, '&quot;') + '">' +
                       '<img src="' + rdEsc(a.URL) + '" alt="Foto ' + (i + 1) + '"></button>';
            }).join('') + '</div>';
        }
        h += '</div>';
    }
    if (otros.length) {
        h += '<div class="dq-arch">' + otros.map(function (a) {
            return '<a href="' + rdEsc(a.URL) + '" target="_blank" rel="noopener"><i class="fa fa-file-pdf-o"></i> ' + rdEsc(a.NOMBRE_ORIGINAL) + '</a>';
        }).join('') + '</div>';
    }
    return h;
}
//Tocar una miniatura la pone en grande
$(document).on('click', '.rq-mini', function () {
    var visor = $(this).closest('.rq-visor'), url = $(this).attr('data-url');
    visor.find('.rq-visor-grande').attr('href', url).find('img').attr('src', url);
    visor.find('.rq-mini').removeClass('activa'); $(this).addClass('activa');
});

//El detalle de una RQ: número, peaje, quién la pidió, los ítems con su cantidad y justificación,
//la observación y las fotos. d es lo que responde RQControl?op=mostrar; fila, lo que ya se tenía del listado
function rdRqDetalleHtml(d, fila) {
    fila = fila || {};
    var q = d.rq || {}, u = (q.TIPO_RQ || fila.TIPO_RQ) === 'U';
    var dias = parseInt(fila.DIAS != null ? fila.DIAS : q.DIAS_EN_ESTADO, 10) || 0;
    var h = '<div class="dq-cab"><span class="cd-rq ' + (u ? 'cd-rq-u' : '') + '">' + (u ? 'RQ U-' : 'RQ-') + rdEsc(q.NUMERO_RQ || fila.NUMERO_RQ) + '</span>' +
            (u ? ' <span class="rd-tag rd-tag-mal">Urgente</span>' : '') + (q.NOM_RQ_ESTADO ? ' <span class="rd-tag">' + rdEsc(q.NOM_RQ_ESTADO) + '</span>' : '') + '</div>' +
            '<div class="dq-datos">' +
            '<div><span>Peaje</span><b>' + rdEsc(q.NOM_CENTRO_OP || fila.NOM_CENTRO_OP) + '</b></div>' +
            '<div><span>Proyecto</span><b>' + rdEsc(q.NOM_PROYECTO || fila.NOM_PROYECTO) + '</b></div>' +
            '<div><span>La pidió</span><b>' + rdEsc(q.SOLICITA || fila.SOLICITA || '') + '</b></div>' +
            '<div><span>Fecha</span><b>' + rdFecha(q.FECHA_RQ || fila.FECHA_RQ) + ' · ' + dias + (dias === 1 ? ' día' : ' días') + ' en este estado</b></div></div>';
    var it = d.items || [];
    if (it.length) {
        h += '<div class="dq-sub">Qué se pide (' + it.length + ')</div><ol class="dq-items">';
        it.forEach(function (x) {
            h += '<li><b>' + rdEsc(x.DESCRIPCION) + '</b> · ' + rdEsc(String(parseFloat(x.CANTIDAD))) + ' ' + rdEsc(x.UNIDAD || '') +
                 (x.JUSTIFICACION ? '<div class="dq-just">' + rdEsc(x.JUSTIFICACION) + '</div>' : '') + '</li>';
        });
        h += '</ol>';
    }
    if (q.OBSERVACION_SST) { h += '<div class="dq-sub">Observación</div><div class="dq-just">' + rdEsc(q.OBSERVACION_SST) + '</div>'; }
    h += '<div class="dq-sub">Fotos y soportes (' + (d.archivos || []).length + ')</div>' + rdRqArchivosHtml(d.archivos);
    return h;
}

//Abre una ventana con el detalle de una RQ y sus fotos. Si la persona tiene el módulo en su menú,
//sale el enlace para abrirla allá (donde se aprueba, se rechaza o se corrige)
function rdVerRQ(id) {
    var m = $('#rdModalRQ');
    if (!m.length) {
        m = $('<div class="modal fade rd-modal-encima" id="rdModalRQ" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">' +
              '<div class="modal-header rd-modal-cab"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>' +
              '<h4 class="modal-title">Requisición</h4></div><div class="modal-body" id="rdModalRQCuerpo"></div></div></div></div>').appendTo('body');
    }
    $('#rdModalRQCuerpo').html('<p class="rd-vacio">Cargando...</p>');
    m.modal('show');
    $.getJSON('../Control/RQControl.php?op=mostrar&id=' + encodeURIComponent(id))
        .done(function (d) {
            var ir = $('.side-menu a[href="RQVista.php"]').length //solo si tiene el módulo de RQ
                ? '<div class="rd-acciones"><a class="btn btn-default" href="RQVista.php?abrir=' + parseInt(id, 10) + '"><i class="fa fa-wrench"></i> Abrir en Requisiciones</a></div>' : '';
            $('#rdModalRQCuerpo').html(rdRqDetalleHtml(d, {}) + ir);
        })
        .fail(function (xhr) { $('#rdModalRQCuerpo').html('<p class="rd-vacio">' + rdEsc(rdError(xhr, 'No se pudo abrir la RQ.')) + '</p>'); });
}
$(document).on('click', '.btn-ver-rq', function (e) { e.preventDefault(); rdVerRQ($(this).attr('data-id')); });

//Las miniaturas de una RQ en una fila de tabla: hasta 3 fotos y cuántas más hay. Tocarlas abre el detalle
function rdRqMinisFila(idRq, archivos) {
    var lista = (archivos ? String(archivos).split('|') : []).filter(function (x) { return x; });
    if (!lista.length) { return ''; }
    var fotos = lista.filter(rdEsFoto), h = '<div class="cd-rq-fotos">';
    fotos.slice(0, 3).forEach(function (f) {
        h += '<a href="#" class="btn-ver-rq" data-id="' + parseInt(idRq, 10) + '" title="Ver las fotos de la RQ"><img src="' + RD_RUTA_RQ + rdEsc(f) + '" alt="Foto de la RQ" loading="lazy"></a>';
    });
    var mas = lista.length - Math.min(3, fotos.length);
    if (mas > 0) { h += '<a href="#" class="btn-ver-rq cd-rq-mas" data-id="' + parseInt(idRq, 10) + '">+' + mas + '</a>'; }
    return h + '</div>';
}
