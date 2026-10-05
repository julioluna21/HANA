//Reporte diario — Comunicaciones y oficios
var URL_COM = '../Control/ComunicacionControl.php';
var cfgM = null, filasM = [];

var RESULTADO_COM = {
    CUMPLE:    '<span class="rd-tag rd-tag-ok">Cumple</span>',
    NO_CUMPLE: '<span class="rd-tag rd-tag-mal">No cumple</span>',
    EN_TIEMPO: '<span class="rd-tag rd-tag-pend">En tiempo</span>',
    VENCIDA:   '<span class="rd-tag rd-tag-mal">Fuera de tiempo</span>'
};

function opciones(obj, vacio) {
    var h = vacio !== undefined ? '<option value="">' + rdEsc(vacio) + '</option>' : '';
    $.each(obj, function (k, v) { h += '<option value="' + rdEsc(k) + '">' + rdEsc(v) + '</option>'; });
    return h;
}

$(function () {
    $.getJSON(URL_COM + '?op=config')
        .done(function (c) {
            cfgM = c;
            var hp = '<option value="0">Todos</option>', hf = '', ht = '';
            for (var i = 0; i < c.proyectos.length; i++) {
                var o = '<option value="' + parseInt(c.proyectos[i].ID_PROYECTO, 10) + '">' + rdEsc(c.proyectos[i].NOM_PROYECTO) + '</option>';
                hp += o; hf += o;
            }
            $('#fProyecto').html(hp); $('#mProyecto').html(hf || '<option value="">No coordinas ningún proyecto</option>');
            $.each(c.tipos, function (k, v) { ht += '<label class="rd-opcion"><input type="radio" name="tipo" value="' + rdEsc(k) + '"> <span>' + rdEsc(v) + '</span></label>'; });
            $('#mTipos').html(ht);
            $('#mMedio').html(opciones(c.medios)); $('#mPendiente').html(opciones(c.pendiente, 'Nadie / no aplica'));
            rdPeriodo('#fMes', '#fAnio', c.hoy); $('#fMes').val('0'); $('#fAnio').prop('disabled', true);
            $('#ayudaPlazo').text('Cumple si se atiende en ' + c.diasRespuesta + ' días o menos desde la recepción.');
            listar();
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
    var h = '<option value="">Todo el proyecto</option>';
    for (var i = 0; i < cfgM.centros.length; i++) {
        var c = cfgM.centros[i];
        if (String(c.ID_PROYECTO) !== String(idProyecto)) { continue; }
        h += '<option value="' + parseInt(c.ID_CENTRO_OP, 10) + '">' + rdEsc(c.NOM_CENTRO_OP) + '</option>';
    }
    $('#mCentro').html(h).val(marcado ? String(marcado) : '');
}

function listar() {
    var q = '&proyecto=' + ($('#fProyecto').val() || 0) + '&mes=' + $('#fMes').val() + '&anio=' + $('#fAnio').val();
    $.getJSON(URL_COM + '?op=listar' + q)
        .done(function (f) {
            filasM = f;
            var h = '', at = 0, en = 0, ven = 0, cu = 0;
            for (var i = 0; i < f.length; i++) {
                var m = f[i], abierta = parseInt(m.ABIERTA, 10) === 1;
                if (abierta) { if (m.TIPO === 'POR_ENVIAR') { en++; } else { at++; } if (m.RESULTADO === 'VENCIDA') { ven++; } }
                if (m.RESULTADO === 'CUMPLE') { cu++; }
                var estado = abierta ? '<span class="rd-tag rd-tag-pend">Abierta</span>' + (m.PENDIENTE_DE ? '<br><small>Pendiente: ' + rdEsc(cfgM.pendiente[m.PENDIENTE_DE]) + '</small>' : '')
                                     : '<span class="rd-tag rd-tag-ok">Resuelta</span>';
                h += '<tr><td>' + rdEsc(cfgM.tipos[m.TIPO]) + '</td><td>' + rdEsc(m.RADICADO || '') + '</td><td>' + rdFecha(m.FECHA_RECEPCION) + '</td>' +
                     '<td>' + rdEsc(cfgM.medios[m.MEDIO] || m.MEDIO) + '</td><td>' + rdEsc(m.REMITENTE) + '</td>' +
                     '<td>' + rdEsc(m.ASUNTO) + (+m.ADJUNTOS ? ' <span class="adj-clip" title="Archivos adjuntos"><i class="fa fa-paperclip"></i> ' + parseInt(m.ADJUNTOS, 10) + '</span>' : '') +
                     (+m.RESPUESTAS ? ' <span class="adj-clip" title="Respuestas"><i class="fa fa-comments-o"></i> ' + parseInt(m.RESPUESTAS, 10) + '</span>' : '') +
                     (m.NOM_CENTRO_OP ? '<br><small>' + rdEsc(m.NOM_CENTRO_OP) + '</small>' : '') + '</td>' +
                     '<td>' + rdEsc(m.RESPONSABLE) + '</td><td>' + rdFecha(m.FECHA_ATENCION) + '</td>' +
                     '<td class="rd-num-col">' + (abierta ? m.DIAS_ABIERTA : m.TIEMPO_CIERRE) + '</td>' +
                     '<td>' + (RESULTADO_COM[m.RESULTADO] || '') + '</td><td>' + estado + '</td>' +
                     '<td><button type="button" class="btn btn-default btn-xs btn-editar-com" data-i="' + i + '"><i class="fa fa-pencil"></i></button></td></tr>';
            }
            $('#filas').html(h || '<tr><td colspan="12" class="rd-vacio">No hay comunicaciones para mostrar.</td></tr>');
            $('#cAtender').text(at); $('#cEnviar').text(en); $('#cVencidas').text(ven); $('#cCumple').text(cu);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron cargar las comunicaciones.'); });
}

function abrir(m) {
    $('#formCom')[0].reset();
    $('#mId').val(m ? m.ID_COMUNICACION : '');
    $('#tituloForm').html('<i class="fa fa-inbox"></i> ' + (m ? 'Comunicación · ' + rdEsc(m.ASUNTO) : 'Nueva comunicación'));
    $('#mFecha, #mAtencion').attr('max', cfgM.ultimoEditable);
    $('input[name="tipo"][value="' + (m ? m.TIPO : 'POR_ATENDER') + '"]').prop('checked', true);
    var proy = m ? m.ID_PROYECTO : $('#mProyecto option').first().val();
    $('#mProyecto').val(String(proy)); centrosDe(proy, m ? m.ID_CENTRO_OP : '');
    if (m) {
        $('#mRadicado').val(m.RADICADO || ''); $('#mFecha').val(m.FECHA_RECEPCION); $('#mMedio').val(m.MEDIO);
        $('#mRemitente').val(m.REMITENTE); $('#mAsunto').val(m.ASUNTO); $('#mResponsable').val(m.RESPONSABLE);
        $('#mPendiente').val(m.PENDIENTE_DE || ''); $('#mAtencion').val(m.FECHA_ATENCION || ''); $('#mRespuesta').val(m.RESPUESTA || '');
    } else {
        $('#mFecha').val(cfgM.hoy); $('#mResponsable').val(cfgM.miNombre);
    }
    $('#grupoPendiente').toggle(!$('#mAtencion').val());
    $('#btnAnular, #mMotivo').toggle(!!m && !!m.PUEDE_EDITAR);
    //Quien no la registró ni la resuelve solo responde: los datos quedan de consulta
    var editable = !m || !!m.PUEDE_EDITAR;
    $('#formCom').find('input, select, textarea').not('#mNuevaResp').prop('disabled', !editable);
    $('#btnGuardar').toggle(editable);
    //La atención (resolverla) la marca el coordinador del proyecto o el admin
    $('#grupoAtencion').toggle(m ? !!m.PUEDE_RESOLVER : puedeResolverProy($('#mProyecto').val()));
    //La conversación, con la comunicación ya guardada
    if (m) { $('#mHiloCaja').show(); cargarHilo(m.ID_COMUNICACION); } else { $('#mHiloCaja').hide(); }
    //Los archivos van con una comunicación ya guardada
    if (m) { rdAdjuntos('#mAdjuntos', 'COMUNICACION', m.ID_COMUNICACION); }
    else { $('#mAdjuntos').html('<div class="adj"><div class="adj-cab"><i class="fa fa-paperclip"></i> Archivos</div><p class="adj-vacio">Guarda primero la comunicación; enseguida podrás adjuntar el oficio y los soportes.</p></div>'); }
    rdCambiosGuardados();
    $('#panelLista').hide(); $('#panelForm').show(); window.scrollTo(0, 0);
}

function guardar(e) {
    e.preventDefault();
    hanaBoton('#btnGuardar', true);
    $.post(URL_COM + '?op=guardar', $('#formCom').serialize(), null, 'json')
        .done(function (r) {
            rdCambiosGuardados();
            listar();
            //Si era nueva, el formulario sigue abierto para adjuntar el oficio de una vez
            if (!$('#mId').val()) {
                $('#mId').val(r.id);
                $('#tituloForm').html('<i class="fa fa-inbox"></i> Comunicación · ' + rdEsc($('#mAsunto').val()));
                $('#btnAnular, #mMotivo').show();
                rdAdjuntos('#mAdjuntos', 'COMUNICACION', r.id);
                rdAviso('Guardada. Ahora puedes adjuntar el oficio o los soportes.');
                return;
            }
            rdAviso(r.mensaje); $('#panelForm').hide(); $('#panelLista').show();
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar.')); })
        .always(function () { hanaBoton('#btnGuardar', false); });
}

function anular() {
    var motivo = $.trim($('#mMotivo').val());
    if (!motivo) { alert('Escribe el motivo para anular.'); $('#mMotivo').focus(); return; }
    hanaConfirmar('¿Anular esta comunicación?', function () {
        $.post(URL_COM + '?op=anular', { id: $('#mId').val(), motivo: motivo }, null, 'json')
            .done(function (r) { rdCambiosGuardados(); rdAviso(r.mensaje); $('#panelForm').hide(); $('#panelLista').show(); listar(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo anular.')); });
    }, { aceptar: 'Sí, anular', cancelar: 'No' });
}


//---------------------------------------------------------------------------
// Conversación del oficio (como las RQ): responder, resolver y reabrir
//---------------------------------------------------------------------------
function puedeResolverProy(idProy) { return (cfgM.proyectosResuelve || []).indexOf(+idProy) >= 0; }

function cargarHilo(id) {
    $.getJSON(URL_COM + '?op=hilo&id=' + id)
        .done(function (d) {
            var h = '';
            d.hilo.forEach(function (x) {
                var cuando = rdFecha(x.FECHA) + ' ' + rdHora(String(x.FECHA).substring(11));
                if (x.TIPO === 'RESPUESTA') {
                    h += '<div class="com-msg"><div class="com-msg-cab"><strong>' + rdEsc(x.QUIEN || '') + '</strong> <span>' + cuando + '</span></div>' +
                         '<div class="com-msg-txt">' + rdEsc(x.TEXTO || '').replace(/\n/g, '<br>') + '</div></div>';
                } else {
                    var res = x.TIPO === 'RESUELTA';
                    h += '<div class="com-evento ' + (res ? 'ok' : 'pend') + '"><i class="fa ' + (res ? 'fa-check-circle' : 'fa-undo') + '"></i> <strong>' + rdEsc(x.QUIEN || '') + '</strong> la ' +
                         (res ? 'marcó como resuelta' : 'reabrió') + ' · ' + cuando + (x.TEXTO ? '<div class="com-msg-txt">' + rdEsc(x.TEXTO).replace(/\n/g, '<br>') + '</div>' : '') + '</div>';
                }
            });
            $('#mHilo').html(h || '<p class="rd-vacio" style="margin:6px 0;">Todavía no hay respuestas. Escribe la primera.</p>');
            $('#mEstado').html(d.resuelta ? '<span class="rd-tag rd-tag-ok">Resuelta</span>' : '<span class="rd-tag rd-tag-pend">Abierta</span>');
            $('#btnResolver').toggle(d.puedeResolver && !d.resuelta);
            $('#btnReabrir').toggle(d.puedeResolver && d.resuelta);
        })
        .fail(function (xhr) { $('#mHilo').html('<p class="rd-ayuda">' + rdEsc(rdError(xhr, 'No se pudo cargar la conversación.')) + '</p>'); });
}

function accionHilo(op, exigeTexto) {
    var id = $('#mId').val(), texto = $.trim($('#mNuevaResp').val());
    if (exigeTexto && !texto) { $('#mNuevaResp').focus(); return; }
    $.post(URL_COM + '?op=' + op, { id: id, texto: texto }, null, 'json')
        .done(function (r) {
            rdAviso(r.mensaje); $('#mNuevaResp').val('');
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
