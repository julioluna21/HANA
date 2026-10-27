//Reporte diario - Comunicaciones y oficios
var URL_COM = '../Control/ComunicacionControl.php';
var cfgM = null, filasM = [];

var RESULTADO_COM = {
    CUMPLE:    '<span class="rd-tag rd-tag-ok">Cumple</span>',
    NO_CUMPLE: '<span class="rd-tag rd-tag-mal">No cumple</span>',
    EN_TIEMPO: '<span class="rd-tag rd-tag-pend">En tiempo</span>',
    VENCIDA:   '<span class="rd-tag rd-tag-mal">Fuera de tiempo</span>'
};

//Arma las <option> de un select a partir de un objeto {valor: texto}
function opciones(opcion, vacio) {
    var html = vacio !== undefined ? '<option value="">' + rdEsc(vacio) + '</option>' : '';
    $.each(opcion, function (clave, nombre) { html += '<option value="' + rdEsc(clave) + '">' + rdEsc(nombre) + '</option>'; });
    return html;
}

$(function () {
    $.getJSON(URL_COM + '?op=config')
        .done(function (config) {
            cfgM = config;
            //Quien solo consulta no registra ni responde
            if (config.puedeLlenar === false) { $('#btnNueva').hide(); $('#mNuevaResp, #btnResponder').hide(); }
            var htmlProyectos = '<option value="0">Todos</option>', htmlFormProyectos = '', htmlTipos = '';
            for (var i = 0; i < config.proyectos.length; i++) {
                var opcion = '<option value="' + parseInt(config.proyectos[i].ID_PROYECTO, 10) + '">' + rdEsc(config.proyectos[i].NOM_PROYECTO) + '</option>';
                htmlProyectos += opcion; htmlFormProyectos += opcion;
            }
            $('#fProyecto').html(htmlProyectos); $('#mProyecto').html(htmlFormProyectos || '<option value="">No coordinas ningún proyecto</option>');
            $.each(config.tipos, function (clave, nombre) { htmlTipos += '<label class="rd-opcion"><input type="radio" name="tipo" value="' + rdEsc(clave) + '"> <span>' + rdEsc(nombre) + '</span></label>'; });
            $('#mTipos').html(htmlTipos);
            $('#mMedio').html(opciones(config.medios)); $('#mPendiente').html(opciones(config.pendiente, 'Nadie / no aplica'));
            rdPeriodo('#fMes', '#fAnio', config.hoy); $('#fMes').val('0'); $('#fAnio').prop('disabled', true);
            $('#ayudaPlazo').text('Cumple si se atiende en ' + config.diasRespuesta + ' días o menos desde la recepción.');
            listar();
            //Abrir la pantalla cuenta como revisarla hoy (recordatorio de las 9 a. m.)
            $.post('../Control/EstadoDiaControl.php?op=visto', { modulo: 'COMUNICACIONES' });
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir comunicaciones.'); });

    $('#fProyecto, #fAnio').on('change', listar);
    $('#fMes').on('change', function () { $('#fAnio').prop('disabled', this.value === '0'); listar(); });
    rdVigilarCambios('#formCom');
    $('#btnNueva').on('click', function () { abrir(null); });
    $('#btnCancelar').on('click', function () {
        if (rdHayCambios && !window.confirm('La comunicación tiene cambios sin guardar. ¿Salir y perderlos?')) { return; }
        rdCambiosGuardados();
        $('#panelForm').hide(); $('#panelLista').show();
    });
    $('#mProyecto').on('change', function () { centrosDe(this.value, ''); });
    $('#mAtencion').on('change', function () { $('#grupoPendiente').toggle(!this.value); });
    $('#formCom').on('submit', guardar);
    $('#btnAnular').on('click', anular);
    $(document).on('click', '.btn-editar-com', function () { abrir(filasM[$(this).data('i')]); });
});

//Los centros del proyecto elegido
function centrosDe(idProyecto, marcado) {
    var html = '<option value="">Todo el proyecto</option>';
    for (var i = 0; i < cfgM.centros.length; i++) {
        var centro = cfgM.centros[i];
        if (String(centro.ID_PROYECTO) !== String(idProyecto)) { continue; }
        html += '<option value="' + parseInt(centro.ID_CENTRO_OP, 10) + '">' + rdEsc(centro.NOM_CENTRO_OP) + '</option>';
    }
    $('#mCentro').html(html).val(marcado ? String(marcado) : '');
}

//Trae las comunicaciones según los filtros y las pinta en la tabla
function listar() {
    var filtros = '&proyecto=' + ($('#fProyecto').val() || 0) + '&mes=' + $('#fMes').val() + '&anio=' + $('#fAnio').val();
    $.getJSON(URL_COM + '?op=listar' + filtros)
        .done(function (filas) {
            filasM = filas;
            var html = '', porAtender = 0, porEnviar = 0, vencidas = 0, cumplen = 0;
            for (var i = 0; i < filas.length; i++) {
                var oficio = filas[i], abierta = parseInt(oficio.ABIERTA, 10) === 1;
                if (abierta) { if (oficio.TIPO === 'POR_ENVIAR') { porEnviar++; } else { porAtender++; } if (oficio.RESULTADO === 'VENCIDA') { vencidas++; } }
                if (oficio.RESULTADO === 'CUMPLE') { cumplen++; }
                var estado = abierta ? '<span class="rd-tag rd-tag-pend">Abierta</span>' + (oficio.PENDIENTE_DE ? '<br><small>Pendiente: ' + rdEsc(cfgM.pendiente[oficio.PENDIENTE_DE]) + '</small>' : '')
                                     : '<span class="rd-tag rd-tag-ok">Resuelta</span>';
                html += '<tr><td>' + rdEsc(cfgM.tipos[oficio.TIPO]) + '</td><td>' + rdEsc(oficio.RADICADO || '') + '</td><td>' + rdFecha(oficio.FECHA_RECEPCION) + '</td>' +
                     '<td>' + rdEsc(cfgM.medios[oficio.MEDIO] || oficio.MEDIO) + '</td><td>' + rdEsc(oficio.REMITENTE) + '</td>' +
                     '<td>' + rdEsc(oficio.ASUNTO) + (+oficio.ADJUNTOS ? ' <span class="adj-clip" title="Archivos adjuntos"><i class="fa fa-paperclip"></i> ' + parseInt(oficio.ADJUNTOS, 10) + '</span>' : '') +
                     (+oficio.RESPUESTAS ? ' <span class="adj-clip" title="Respuestas"><i class="fa fa-comments-o"></i> ' + parseInt(oficio.RESPUESTAS, 10) + '</span>' : '') +
                     (oficio.NOM_CENTRO_OP ? '<br><small>' + rdEsc(oficio.NOM_CENTRO_OP) + '</small>' : '') + '</td>' +
                     '<td>' + rdEsc(oficio.REGISTRO || '') + '</td><td>' + rdFecha(oficio.FECHA_ATENCION) + '</td>' +
                     '<td class="rd-num-col">' + (abierta ? oficio.DIAS_ABIERTA : oficio.TIEMPO_CIERRE) + '</td>' +
                     '<td>' + (RESULTADO_COM[oficio.RESULTADO] || '') + '</td><td>' + estado + '</td>' +
                     '<td><button type="button" class="btn btn-default btn-xs btn-editar-com" data-i="' + i + '"><i class="fa fa-pencil"></i></button></td></tr>';
            }
            $('#filas').html(html || '<tr><td colspan="12" class="rd-vacio">No hay comunicaciones para mostrar.</td></tr>');
            $('#cAtender').text(porAtender); $('#cEnviar').text(porEnviar); $('#cVencidas').text(vencidas); $('#cCumple').text(cumplen);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron cargar las comunicaciones.'); });
}

//Abre el formulario: vacío para una nueva, o con los datos de una existente
function abrir(oficio) {
    $('#formCom')[0].reset();
    $('#mId').val(oficio ? oficio.ID_COMUNICACION : '');
    $('#tituloForm').html('<i class="fa fa-inbox"></i> ' + (oficio ? 'Comunicación · ' + rdEsc(oficio.ASUNTO) : 'Nueva comunicación'));
    $('#mFecha, #mAtencion').attr('max', cfgM.ultimoEditable);
    $('input[name="tipo"][value="' + (oficio ? oficio.TIPO : 'POR_ATENDER') + '"]').prop('checked', true);
    var proy = oficio ? oficio.ID_PROYECTO : $('#mProyecto option').first().val();
    $('#mProyecto').val(String(proy)); centrosDe(proy, oficio ? oficio.ID_CENTRO_OP : '');
    if (oficio) {
        $('#mRadicado').val(oficio.RADICADO || ''); $('#mFecha').val(oficio.FECHA_RECEPCION); $('#mMedio').val(oficio.MEDIO);
        $('#mRemitente').val(oficio.REMITENTE); $('#mAsunto').val(oficio.ASUNTO);
        $('#mPendiente').val(oficio.PENDIENTE_DE || ''); $('#mAtencion').val(oficio.FECHA_ATENCION || ''); $('#mRespuesta').val(oficio.RESPUESTA || '');
    } else {
        $('#mFecha').val(cfgM.hoy);
    }
    $('#grupoPendiente').toggle(!$('#mAtencion').val());
    $('#btnAnular, #mMotivo').toggle(!!oficio && !!oficio.PUEDE_EDITAR);
    //Quien no la registró ni la resuelve solo responde: los datos quedan de consulta
    var editable = !oficio || !!oficio.PUEDE_EDITAR;
    $('#formCom').find('input, select, textarea').not('#mNuevaResp').prop('disabled', !editable);
    $('#btnGuardar').toggle(editable);
    //La atención (resolverla) la marca el coordinador del proyecto o el admin
    $('#grupoAtencion').toggle(oficio ? !!oficio.PUEDE_RESOLVER : puedeResolverProy($('#mProyecto').val()));
    //La conversación, con la comunicación ya guardada
    if (oficio) { $('#mHiloCaja').show(); cargarHilo(oficio.ID_COMUNICACION); } else { $('#mHiloCaja').hide(); }
    //Los archivos van con una comunicación ya guardada
    if (oficio) { rdAdjuntos('#mAdjuntos', 'COMUNICACION', oficio.ID_COMUNICACION); }
    else { $('#mAdjuntos').html('<div class="adj"><div class="adj-cab"><i class="fa fa-paperclip"></i> Archivos</div><p class="adj-vacio">Guarda primero la comunicación; enseguida podrás adjuntar el oficio y los soportes.</p></div>'); }
    rdCambiosGuardados();
    $('#panelLista').hide(); $('#panelForm').show(); window.scrollTo(0, 0);
}

//Guarda la comunicación
function guardar(evento) {
    evento.preventDefault();
    hanaBoton('#btnGuardar', true);
    $.post(URL_COM + '?op=guardar', $('#formCom').serialize(), null, 'json')
        .done(function (respuesta) {
            rdCambiosGuardados();
            listar();
            //Si era nueva, el formulario sigue abierto para adjuntar el oficio de una vez
            if (!$('#mId').val()) {
                $('#mId').val(respuesta.id);
                $('#tituloForm').html('<i class="fa fa-inbox"></i> Comunicación · ' + rdEsc($('#mAsunto').val()));
                $('#btnAnular, #mMotivo').show();
                rdAdjuntos('#mAdjuntos', 'COMUNICACION', respuesta.id);
                rdAviso('Guardada. Ahora puedes adjuntar el oficio o los soportes.');
                return;
            }
            rdAviso(respuesta.mensaje); $('#panelForm').hide(); $('#panelLista').show();
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar.')); })
        .always(function () { hanaBoton('#btnGuardar', false); });
}

//Anula la comunicación abierta; pide el motivo antes
function anular() {
    var motivo = $.trim($('#mMotivo').val());
    if (!motivo) { alert('Escribe el motivo para anular.'); $('#mMotivo').focus(); return; }
    hanaConfirmar('¿Anular esta comunicación?', function () {
        $.post(URL_COM + '?op=anular', { id: $('#mId').val(), motivo: motivo }, null, 'json')
            .done(function (respuesta) { rdCambiosGuardados(); rdAviso(respuesta.mensaje); $('#panelForm').hide(); $('#panelLista').show(); listar(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo anular.')); });
    }, { aceptar: 'Sí, anular', cancelar: 'No' });
}


//---------------------------------------------------------------------------
// Conversación del oficio (como las RQ): responder, resolver y reabrir
//---------------------------------------------------------------------------
function puedeResolverProy(idProy) { return (cfgM.proyectosResuelve || []).indexOf(+idProy) >= 0; }

//Trae la conversación de la comunicación (respuestas y notas) y la pinta
function cargarHilo(id) {
    $.getJSON(URL_COM + '?op=hilo&id=' + id)
        .done(function (respuesta) {
            var html = '';
            respuesta.hilo.forEach(function (mensaje) {
                var cuando = rdFecha(mensaje.FECHA) + ' ' + rdHora(String(mensaje.FECHA).substring(11));
                if (mensaje.TIPO === 'RESPUESTA') {
                    html += '<div class="com-msg"><div class="com-msg-cab"><strong>' + rdEsc(mensaje.QUIEN || '') + '</strong> <span>' + cuando + '</span></div>' +
                         '<div class="com-msg-txt">' + rdEsc(mensaje.TEXTO || '').replace(/\n/g, '<br>') + '</div></div>';
                } else {
                    var resuelta = mensaje.TIPO === 'RESUELTA';
                    html += '<div class="com-evento ' + (resuelta ? 'ok' : 'pend') + '"><i class="fa ' + (resuelta ? 'fa-check-circle' : 'fa-undo') + '"></i> <strong>' + rdEsc(mensaje.QUIEN || '') + '</strong> la ' +
                         (resuelta ? 'marcó como resuelta' : 'reabrió') + ' · ' + cuando + (mensaje.TEXTO ? '<div class="com-msg-txt">' + rdEsc(mensaje.TEXTO).replace(/\n/g, '<br>') + '</div>' : '') + '</div>';
                }
            });
            $('#mHilo').html(html || '<p class="rd-vacio" style="margin:6px 0;">Todavía no hay respuestas. Escribe la primera.</p>');
            $('#mEstado').html(respuesta.resuelta ? '<span class="rd-tag rd-tag-ok">Cerrada el ' + rdFecha(respuesta.fechaCierre) + '</span>' : '<span class="rd-tag rd-tag-pend">Abierta</span>');
            $('#btnResolver').toggle(respuesta.puedeResolver && !respuesta.resuelta);
            $('#btnReabrir').toggle(respuesta.puedeResolver && respuesta.resuelta);
        })
        .fail(function (xhr) { $('#mHilo').html('<p class="rd-ayuda">' + rdEsc(rdError(xhr, 'No se pudo cargar la conversación.')) + '</p>'); });
}

//Responder, resolver o reabrir la comunicación abierta
function accionHilo(op, exigeTexto) {
    var id = $('#mId').val(), texto = $.trim($('#mNuevaResp').val());
    if (exigeTexto && !texto) { $('#mNuevaResp').focus(); return; }
    $.post(URL_COM + '?op=' + op, { id: id, texto: texto }, null, 'json')
        .done(function (respuesta) {
            rdAviso(respuesta.mensaje); $('#mNuevaResp').val('');
            if (op === 'resolver') { $('#mAtencion').val(cfgM.hoy); } else if (op === 'reabrir') { $('#mAtencion').val(''); }
            cargarHilo(id); listar();
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo completar.')); });
}

$(function () {
    $('#btnResponder').on('click', function () { accionHilo('responder', true); });
    $('#btnResolver').on('click', function () { accionHilo('resolver', false); });
    $('#btnReabrir').on('click', function () { accionHilo('reabrir', false); });
    $('#mProyecto').on('change', function () { if (!$('#mId').val()) { $('#grupoAtencion').toggle(puedeResolverProy(this.value)); } });
});
