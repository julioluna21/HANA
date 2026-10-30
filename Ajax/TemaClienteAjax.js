//Reporte diario - Temas con el cliente
var URL_TEMA = '../Control/TemaClienteControl.php';
var cfgT = null, filasT = [], temaAbierto = null;
var DIAS_QUIETO = 7; //días sin seguimiento para resaltar un tema abierto

//Arma las <option> de un select a partir de un objeto {valor: texto}
function temaOpciones(lista, vacio) {
    var html = vacio !== undefined ? '<option value="">' + rdEsc(vacio) + '</option>' : '';
    $.each(lista, function (clave, nombre) { html += '<option value="' + rdEsc(clave) + '">' + rdEsc(nombre) + '</option>'; });
    return html;
}

$(function () {
    $.getJSON(URL_TEMA + '?op=config')
        .done(function (config) {
            cfgT = config;
            if (!config.puedeLlenar) { $('#btnNuevo').hide(); }
            var htmlFiltro = '<option value="0">Todos</option>', htmlForm = '';
            config.proyectos.forEach(function (proyecto) {
                var opcion = '<option value="' + parseInt(proyecto.ID_PROYECTO, 10) + '">' + rdEsc(proyecto.NOM_PROYECTO) + '</option>';
                htmlFiltro += opcion; htmlForm += opcion;
            });
            $('#fProyecto').html(htmlFiltro); $('#mProyecto').html(htmlForm);
            $('#mClase').html(temaOpciones(config.clases, 'Elige...'));
            $('#mPendiente').html(temaOpciones(config.pendiente, 'Nadie / no aplica'));
            rdPeriodo('#fMes', '#fAnio', config.hoy); $('#fMes').val('0'); $('#fAnio').prop('disabled', true);
            listar();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir Temas con el cliente.'); });

    $('#fProyecto, #fAnio').on('change', listar);
    $('#fMes').on('change', function () { $('#fAnio').prop('disabled', this.value === '0'); listar(); });
    rdVigilarCambios('#formTema');
    $('#btnNuevo').on('click', function () { abrir(null); });
    $('#btnVolver').on('click', function () {
        if (rdHayCambios && !window.confirm('Hay cambios sin guardar. ¿Salir y perderlos?')) { return; }
        rdCambiosGuardados(); $('#panelForm').hide(); $('#panelLista').show();
    });
    $('#mProyecto').on('change', function () { peajesDe(this.value, ''); });
    $('#formTema').on('submit', guardar);
    $('#btnAnular').on('click', anular);
    $('#btnAnotar').on('click', function () { accion('anotar'); });
    $('#btnCerrar').on('click', function () { accion('cerrar'); });
    $('#btnReabrir').on('click', function () { accion('reabrir'); });
    $(document).on('click', '.btn-abrir-tema', function () { abrir(filasT[$(this).data('i')]); });
});

//Los peajes del proyecto elegido
function peajesDe(idProyecto, marcado) {
    var html = '<option value="">Todo el proyecto</option>';
    cfgT.centros.forEach(function (centro) {
        if (String(centro.ID_PROYECTO) !== String(idProyecto)) { return; }
        html += '<option value="' + parseInt(centro.ID_CENTRO_OP, 10) + '">' + rdEsc(centro.NOM_CENTRO_OP) + '</option>';
    });
    $('#mCentro').html(html).val(marcado ? String(marcado) : '');
}

//Trae los temas según los filtros y los pinta
function listar() {
    var filtros = '&proyecto=' + ($('#fProyecto').val() || 0) + '&mes=' + $('#fMes').val() + '&anio=' + $('#fAnio').val();
    $.getJSON(URL_TEMA + '?op=listar' + filtros)
        .done(function (filas) {
            filasT = filas;
            var html = '', abiertos = 0, quietos = 0, cerrados = 0;
            filas.forEach(function (tema, i) {
                var abierto = +tema.ABIERTO === 1, quieto = abierto && +tema.DIAS_SIN_MOVIMIENTO > DIAS_QUIETO;
                if (abierto) { abiertos++; if (quieto) { quietos++; } } else { cerrados++; }
                var estado = abierto
                    ? '<span class="rd-tag rd-tag-pend">Abierto</span>' + (tema.PENDIENTE_DE ? '<br><small>Pendiente: ' + rdEsc(cfgT.pendiente[tema.PENDIENTE_DE] || tema.PENDIENTE_DE) + '</small>' : '')
                    : '<span class="rd-tag rd-tag-ok">Cerrado el ' + rdFecha(tema.FECHA_ATENCION) + '</span>';
                var ultimo = tema.ULTIMO_TEXTO ? rdRecortar(tema.ULTIMO_TEXTO, 140) : '<span class="rd-ayuda">Sin seguimiento todavía</span>';
                var movimiento = tema.ULTIMO_MOVIMIENTO ? '<div class="tema-ultimo' + (quieto ? ' tema-quieto' : '') + '">' +
                    (quieto ? '<i class="fa fa-clock-o"></i> ' : '') + rdFecha(tema.ULTIMO_MOVIMIENTO) + '</div>' : '';
                html += '<tr><td>' + rdEsc(tema.NOM_PROYECTO) + (tema.NOM_CENTRO_OP ? '<br><small>' + rdEsc(tema.NOM_CENTRO_OP) + '</small>' : '') + '</td>' +
                        '<td>' + rdEsc(tema.ASUNTO) + (+tema.SEGUIMIENTOS ? ' <span class="adj-clip" title="Seguimientos"><i class="fa fa-comments-o"></i> ' + parseInt(tema.SEGUIMIENTOS, 10) + '</span>' : '') +
                        '<br><small>Registró: ' + rdEsc(tema.REGISTRO || '') + '</small></td>' +
                        '<td>' + rdEsc(tema.REMITENTE) + '</td><td>' + rdEsc(cfgT.clases[tema.MEDIO] || tema.MEDIO) + '</td>' +
                        '<td>' + rdFecha(tema.FECHA_RECEPCION) + '</td><td>' + ultimo + movimiento + '</td>' +
                        '<td class="rd-num-col">' + parseInt(tema.DIAS, 10) + '</td><td>' + estado + '</td>' +
                        '<td><button type="button" class="btn btn-default btn-xs btn-abrir-tema" data-i="' + i + '" title="Abrir"><i class="fa fa-folder-open-o"></i></button></td></tr>';
            });
            $('#filas').html(html || '<tr><td colspan="9" class="rd-vacio">No hay temas para mostrar.</td></tr>');
            $('#cAbiertos').text(abiertos); $('#cQuietos').text(quietos); $('#cCerrados').text(cerrados);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron cargar los temas.'); });
}

//Abre el formulario: vacío para un tema nuevo, o con los datos y el seguimiento de uno existente
function abrir(tema) {
    temaAbierto = tema;
    $('#formTema')[0].reset();
    $('#mId').val(tema ? tema.ID_COMUNICACION : '');
    $('#tituloForm').html('<i class="fa fa-commenting-o"></i> ' + (tema ? rdEsc(tema.ASUNTO) : 'Nuevo tema'));
    $('#mApertura, #mFechaCierre').attr('max', cfgT.hoy);
    var proyecto = tema ? tema.ID_PROYECTO : $('#mProyecto option').first().val();
    $('#mProyecto').val(String(proyecto)); peajesDe(proyecto, tema ? tema.ID_CENTRO_OP : '');
    if (tema) {
        $('#mApertura').val(tema.FECHA_RECEPCION); $('#mTema').val(tema.ASUNTO); $('#mConQuien').val(tema.REMITENTE);
        $('#mClase').val(tema.MEDIO); $('#mPendiente').val(tema.PENDIENTE_DE || '');
    } else {
        $('#mApertura').val(cfgT.hoy);
    }
    //Los datos los corrige quien registró el tema; los demás escriben el seguimiento
    var editable = !tema || !!tema.PUEDE_CORREGIR;
    $('#formTema').find('input, select, textarea').not('#mNuevaNota, #mFechaCierre').prop('disabled', !editable);
    $('#btnGuardar').toggle(editable && cfgT.puedeLlenar);
    $('#btnAnular, #mMotivo').toggle(!!tema && !!tema.PUEDE_CORREGIR);
    $('#grupoInicio').toggle(!tema);
    $('#grupoPendiente').toggle(!tema || +tema.ABIERTO === 1);
    $('#mSegEscribir').toggle(!!cfgT.puedeLlenar);
    if (tema) { $('#mSegCaja').show(); cargarSeguimiento(tema.ID_COMUNICACION); } else { $('#mSegCaja').hide(); }
    rdCambiosGuardados();
    $('#panelLista').hide(); $('#panelForm').show(); window.scrollTo(0, 0);
}

//Guarda el tema (nuevo o corregido)
function guardar(evento) {
    evento.preventDefault();
    hanaBoton('#btnGuardar', true);
    $.post(URL_TEMA + '?op=guardar', $('#formTema').serialize(), null, 'json')
        .done(function (respuesta) {
            rdCambiosGuardados(); rdAviso(respuesta.mensaje); listar();
            $('#panelForm').hide(); $('#panelLista').show();
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar.')); })
        .always(function () { hanaBoton('#btnGuardar', false); });
}

//Anula el tema abierto; pide el motivo antes
function anular() {
    var motivo = $.trim($('#mMotivo').val());
    if (!motivo) { alert('Escribe el motivo para anular.'); $('#mMotivo').focus(); return; }
    hanaConfirmar('¿Anular este tema?', function () {
        $.post(URL_TEMA + '?op=anular', { id: $('#mId').val(), motivo: motivo }, null, 'json')
            .done(function (respuesta) { rdCambiosGuardados(); rdAviso(respuesta.mensaje); $('#panelForm').hide(); $('#panelLista').show(); listar(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo anular.')); });
    }, { aceptar: 'Sí, anular', cancelar: 'No' });
}

//Trae el seguimiento del tema y lo pinta
function cargarSeguimiento(id) {
    $.getJSON(URL_TEMA + '?op=seguimiento&id=' + id)
        .done(function (respuesta) {
            var html = '';
            respuesta.seguimiento.forEach(function (nota) {
                var cuando = rdFecha(nota.FECHA) + ' ' + rdHora(String(nota.FECHA).substring(11));
                if (nota.TIPO === 'RESUELTA' || nota.TIPO === 'REABIERTA') {
                    var cerro = nota.TIPO === 'RESUELTA';
                    html += '<div class="com-evento ' + (cerro ? 'ok' : 'pend') + '"><i class="fa ' + (cerro ? 'fa-check-circle' : 'fa-undo') + '"></i> <strong>' +
                            rdEsc(nota.QUIEN || '') + '</strong> ' + (cerro ? 'cerró el tema' : 'lo reabrió') + ' · ' + cuando +
                            (nota.TEXTO ? '<div class="com-msg-txt">' + rdParrafo(nota.TEXTO) + '</div>' : '') + '</div>';
                } else {
                    html += '<div class="com-msg"><div class="com-msg-cab"><strong>' + rdEsc(nota.QUIEN || '') + '</strong> <span>' + cuando + '</span></div>' +
                            '<div class="com-msg-txt">' + rdParrafo(nota.TEXTO || '') + '</div></div>';
                }
            });
            $('#mSeg').html(html || '<p class="rd-vacio" style="margin:6px 0;">Todavía no hay seguimiento. Escribe en qué va el tema.</p>');
            $('#mEstado').html(respuesta.cerrado ? '<span class="rd-tag rd-tag-ok">Cerrado el ' + rdFecha(respuesta.fechaCierre) + '</span>' : '<span class="rd-tag rd-tag-pend">Abierto</span>');
            $('#cajaCerrar').toggle(!respuesta.cerrado);
            $('#btnReabrir').toggle(!!respuesta.cerrado);
            $('#mFechaCierre').attr('min', respuesta.apertura).val(cfgT.hoy);
        })
        .fail(function (xhr) { $('#mSeg').html('<p class="rd-ayuda">' + rdEsc(rdError(xhr, 'No se pudo cargar el seguimiento.')) + '</p>'); });
}

//Guardar seguimiento, cerrar o reabrir el tema que está abierto en el formulario
function accion(op) {
    var id = $('#mId').val(), texto = $.trim($('#mNuevaNota').val());
    if (op === 'anotar' && !texto) { $('#mNuevaNota').focus(); return; }
    var datos = { id: id, texto: texto };
    if (op === 'cerrar') { datos.fecha = $('#mFechaCierre').val(); }
    var enviar = function () {
        $.post(URL_TEMA + '?op=' + op, datos, null, 'json')
            .done(function (respuesta) {
                rdAviso(respuesta.mensaje); $('#mNuevaNota').val('');
                if (op !== 'anotar') { $('#grupoPendiente').toggle(op === 'reabrir'); if (op === 'cerrar') { $('#mPendiente').val(''); } }
                cargarSeguimiento(id); listar();
            })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo completar.')); });
    };
    if (op === 'cerrar') { hanaConfirmar('¿Cerrar este tema con fecha ' + rdFecha(datos.fecha) + '?', enviar, { aceptar: 'Sí, cerrar', cancelar: 'No' }); }
    else { enviar(); }
}
