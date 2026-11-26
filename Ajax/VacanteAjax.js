//Reporte diario - Vacantes
var URL_VAC = '../Control/VacanteControl.php';
var cfgV = null, filasV = [];
var cierreV = ''; //la fecha de cierre que ya tiene la vacante abierta en el formulario (se pone sola al guardar)

var RESULTADO_ANS = {
    CUMPLE:    '<span class="rd-tag rd-tag-ok">Cumple</span>',
    NO_CUMPLE: '<span class="rd-tag rd-tag-mal">No cumple</span>',
    EN_TIEMPO: '<span class="rd-tag rd-tag-pend">En tiempo</span>',
    VENCIDA:   '<span class="rd-tag rd-tag-mal">Fuera del acuerdo</span>'
};

$(function () {
    $.getJSON(URL_VAC + '?op=config')
        .done(function (config) {
            cfgV = config;
            var htmlFormCentros = '', proyectos = {}, htmlProyectos = '<option value="0">Todos</option>';
            for (var i = 0; i < config.centros.length; i++) {
                var id = parseInt(config.centros[i].ID_CENTRO_OP, 10), nombreCentro = rdEsc(config.centros[i].NOM_CENTRO_OP + ' · ' + config.centros[i].NOM_PROYECTO);
                htmlFormCentros += '<option value="' + id + '">' + nombreCentro + '</option>';
                proyectos[parseInt(config.centros[i].ID_PROYECTO, 10)] = config.centros[i].NOM_PROYECTO;
            }
            Object.keys(proyectos).sort(function (a, b) { return proyectos[a].localeCompare(proyectos[b]); }).forEach(function (idProyecto) {
                htmlProyectos += '<option value="' + parseInt(idProyecto, 10) + '">' + rdEsc(proyectos[idProyecto]) + '</option>';
            });
            $('#fProyecto').html(htmlProyectos);
            //Con un solo proyecto, el filtro no hace falta
            $('#fProyecto').closest('.form-group').toggle(Object.keys(proyectos).length > 1);
            centrosDelFiltro();
            $('#vCentro').html(htmlFormCentros || '<option value="">Tus proyectos no tienen centros activos</option>');
            var htmlEstados = '', htmlEstadosGh = '<option value="">Sin información</option>', htmlMotivos = '';
            $.each(config.estados, function (clave, nombre) { htmlEstados += '<option value="' + rdEsc(clave) + '">' + rdEsc(nombre) + '</option>'; });
            $.each(config.estadosGh, function (clave, nombre) { htmlEstadosGh += '<option value="' + rdEsc(clave) + '">' + rdEsc(nombre) + '</option>'; });
            for (var numMotivo = 0; numMotivo < config.motivos.length; numMotivo++) { htmlMotivos += '<option value="' + rdEsc(config.motivos[numMotivo]) + '">'; }
            $('#vEstado').html(htmlEstados); $('#vGh').html(htmlEstadosGh); $('#listaMotivos').html(htmlMotivos);
            //Sugerencias para «Quién la cubre» (sigue siendo texto libre)
            $('#listaCargos').html((config.cargos || []).map(function (cargo) { return '<option value="' + rdEsc(cargo) + '">'; }).join(''));
            $('#listaCubren').html((config.nombresCubren || []).map(function (nombre) { return '<option value="' + rdEsc(nombre) + '">'; }).join(''));
            rdPeriodo('#fMes', '#fAnio', config.hoy); $('#fMes').val('0'); $('#fAnio').prop('disabled', true);
            //La fecha comprometida por GH se llena sola con el acuerdo de servicio; solo el administrador la cambia
            $('#vCompromiso').prop('readonly', !config.editaCompromiso);
            $('#ayudaCompromiso').text(config.editaCompromiso ? 'Vacía = la del acuerdo de servicio. Tienes permiso para cambiarla.'
                                                            : 'Automática: es la fecha del acuerdo de servicio. La cambia quien tenga ese permiso.');
            //Quien solo consulta no registra ni edita
            $('#btnNueva').toggle(config.puedeLlenar !== false);
            $('#ayudaAns').text('El acuerdo de servicio es de ' + config.diasAns + ' días hábiles desde la vacante, sin contar sábados, domingos ni festivos.');
            listar();
            //Abrir la pantalla cuenta como revisarla hoy (recordatorio de las 9 a. m.)
            $.post('../Control/EstadoDiaControl.php?op=visto', { modulo: 'VACANTES' });
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir vacantes.'); });

    $('#fCentro, #fAnio').on('change', listar);
    $('#fProyecto').on('change', function () { centrosDelFiltro(); listar(); });
    $('#fMes').on('change', function () { $('#fAnio').prop('disabled', this.value === '0'); listar(); });
    rdVigilarCambios('#formVac');
    $('#btnNueva').on('click', function () { abrir(null); });
    //Excel (el botón sale con la casilla 63M): lo mismo que se está viendo, con los mismos filtros
    $('#btnExcel').on('click', function () {
        window.location.href = '../Control/VacanteControl.php?op=excel&proyecto=' + encodeURIComponent($('#fProyecto').val() || 0) + '&centro=' + encodeURIComponent($('#fCentro').val() || 0) +
                               '&mes=' + encodeURIComponent($('#fMes').val() || 0) + '&anio=' + encodeURIComponent($('#fAnio').val() || '');
    });
    $('#btnCancelar').on('click', function () {
        if (rdHayCambios && !window.confirm('La vacante tiene cambios sin guardar. ¿Salir y perderlos?')) { return; }
        rdCambiosGuardados();
        $('#panelForm').hide(); $('#panelLista').show();
    });
    $('#vEstado').on('change', mostrarCierre);
    $('#formVac').on('submit', guardar);
    $('#verRespuestasGh').on('click', verRespuestasGh);
    $(document).on('click', '.btn-editar-vac', function () { abrir(filasV[$(this).data('i')]); });
});

//El filtro de centros: los del proyecto elegido (o todos). Si el centro que estaba elegido no es de
//ese proyecto, vuelve a «Todos»
function centrosDelFiltro() {
    var proyecto = +($('#fProyecto').val() || 0), antes = $('#fCentro').val(), html = '<option value="0">Todos</option>';
    cfgV.centros.forEach(function (centro) {
        if (proyecto && +centro.ID_PROYECTO !== proyecto) { return; }
        html += '<option value="' + parseInt(centro.ID_CENTRO_OP, 10) + '">' + rdEsc(centro.NOM_CENTRO_OP + (proyecto ? '' : ' · ' + centro.NOM_PROYECTO)) + '</option>';
    });
    $('#fCentro').html(html);
    $('#fCentro').val(antes && $('#fCentro option[value="' + antes + '"]').length ? antes : '0');
}

//Trae las vacantes según los filtros y las pinta en la tabla
function listar() {
    var filtros = '&proyecto=' + ($('#fProyecto').val() || 0) + '&centro=' + ($('#fCentro').val() || 0) + '&mes=' + $('#fMes').val() + '&anio=' + $('#fAnio').val();
    $.getJSON(URL_VAC + '?op=listar' + filtros)
        .done(function (filas) {
            filasV = filas;
            var html = '', abiertas = 0, vencidas = 0, cumplen = 0, noCumplen = 0;
            for (var i = 0; i < filas.length; i++) {
                var vacante = filas[i], abierta = vacante.ESTADO_VACANTE === 'ABIERTA';
                if (abierta) { abiertas++; if (vacante.RESULTADO_ANS === 'VENCIDA') { vencidas++; } }
                if (vacante.RESULTADO_ANS === 'CUMPLE') { cumplen++; } if (vacante.RESULTADO_ANS === 'NO_CUMPLE') { noCumplen++; }
                var gh = rdCeldaGh(vacante, cfgV.estadosGh);
                html += '<tr' + (vacante.ESTADO_VACANTE === 'CANCELADA' ? ' class="rd-anulado"' : '') + '>' +
                     '<td>' + rdEsc(vacante.NUMERO_RQ_GH || '') + '</td><td>' + rdEsc(vacante.NOM_CENTRO_OP) + '</td><td>' + rdEsc(vacante.CARGO) + '</td>' +
                     '<td>' + rdFecha(vacante.FECHA_VACANTE) + '</td><td>' + rdFecha(vacante.FECHA_ANS) + '</td><td>' + rdFecha(vacante.FECHA_CIERRE) + '</td>' +
                     '<td class="rd-num-col">' + (abierta ? vacante.DIAS_A_HOY + ' a hoy' : (vacante.TIEMPO_CIERRE !== null ? vacante.TIEMPO_CIERRE + ' en cerrar' : '')) + '</td>' +
                     '<td>' + rdEsc(vacante.MOTIVO) + '</td><td>' + rdEsc(vacante.NOMBRE_SALE || '') + '</td><td>' + rdEsc(cfgV.estados[vacante.ESTADO_VACANTE]) + '</td>' +
                     '<td>' + (RESULTADO_ANS[vacante.RESULTADO_ANS] || '') + '</td>' +
                     '<td>' + (vacante.NOMBRE_REEMPLAZO ? (abierta ? '<small class="rd-ayuda">Cubriendo:</small> ' : '<i class="fa fa-user"></i> ') + rdEsc(vacante.NOMBRE_REEMPLAZO)
                                                       : (abierta ? '<span class="rd-ayuda">Nadie</span>' : '')) + '</td>' +
                     '<td class="vac-gh">' + gh + '</td>' +
                     '<td class="rd-col-acciones">' + (cfgV.puedeLlenar !== false ? '<button type="button" class="btn btn-default btn-xs btn-editar-vac" data-i="' + i + '"><i class="fa fa-pencil"></i></button>' : '') + '</td></tr>';
            }
            $('#filas').html(html || '<tr><td colspan="14" class="rd-vacio">No hay vacantes para mostrar.</td></tr>');
            $('#cAbiertas').text(abiertas); $('#cVencidas').text(vencidas); $('#cCumple').text(cumplen); $('#cNoCumple').text(noCumplen);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron cargar las vacantes.'); });
}

//Las respuestas anteriores de Gestión Humana de la vacante que se está editando
function verRespuestasGh(evento) {
    evento.preventDefault();
    var id = $('#vId').val();
    if (!id) { return; }
    $.getJSON(URL_VAC + '?op=respuestasGh&id=' + encodeURIComponent(id))
        .done(function (lista) {
            $('#respuestasGh').html(lista.length ? lista.map(function (r) {
                return '<div class="vac-resp-item"><div>' + rdParrafo(r.TEXTO) + '</div><small class="rd-ayuda">' + rdFecha(String(r.FECHA).substring(0, 10)) +
                       ' ' + rdHora(String(r.FECHA).substring(11, 16)) + (r.QUIEN ? ' · ' + rdEsc(r.QUIEN) : '') + '</small></div>';
            }).join('') : '<p class="rd-ayuda">Todavía no hay respuestas.</p>').show();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron traer las respuestas de Gestión Humana.'); });
}

//Abre el formulario: vacío para una nueva, o con los datos de una existente
function abrir(vacante) {
    $('#formVac')[0].reset();
    cierreV = vacante && vacante.ESTADO_VACANTE !== 'ABIERTA' ? (vacante.FECHA_CIERRE || '') : '';
    $('#vId').val(vacante ? vacante.ID_VACANTE : '');
    $('#tituloForm').html('<i class="fa fa-user-plus"></i> ' + (vacante ? 'Vacante · ' + rdEsc(vacante.CARGO) : 'Nueva vacante'));
    $('#vFecha').attr('max', cfgV.ultimoEditable);
    if (vacante) {
        $('#vCentro').val(String(vacante.ID_CENTRO_OP)); $('#vRq').val(vacante.NUMERO_RQ_GH || ''); $('#vCargo').val(vacante.CARGO);
        $('#vFecha').val(vacante.FECHA_VACANTE); $('#vMotivo').val(vacante.MOTIVO); $('#vGh').val(vacante.ESTADO_GH || '');
        $('#vCompromiso').val(vacante.FECHA_COMPROMISO_GH || ''); $('#vNombre').val(vacante.NOMBRE_REEMPLAZO || '');
        $('#vSale').val(vacante.NOMBRE_SALE || ''); //quién deja el puesto (viene del historial)
        $('#vEstado').val(vacante.ESTADO_VACANTE); $('#vObs').val(vacante.OBSERVACION || '');
        $('#vRespuestaGh').val(vacante.RESPUESTA_GH || '');
    } else {
        $('#vFecha').val(cfgV.hoy); $('#vEstado').val('ABIERTA');
    }
    //La fecha comprometida con la que se abrió: si se cambia en una vacante abierta, se pide el motivo
    compromisoV = vacante && vacante.ESTADO_VACANTE === 'ABIERTA' ? (vacante.FECHA_COMPROMISO_GH || '') : null;
    $('#grupoMotivoCompromiso').hide(); $('#vCambiosCompromiso').hide().empty();
    if (vacante) {
        $.getJSON(URL_VAC + '?op=cambiosCompromiso&id=' + vacante.ID_VACANTE).done(function (c) {
            if (!+c.veces) { return; }
            $('#vCambiosCompromiso').text('Se ha corrido ' + (+c.veces === 1 ? '1 vez' : c.veces + ' veces') + ' (antes: ' + c.anteriores.map(rdFecha).join(', ') + ').' +
                                          (c.motivo ? ' Último motivo: ' + c.motivo : '')).show();
        });
    }
    $('#respuestasGh').hide().empty();
    $('#verRespuestasGh').toggle(!!(vacante && vacante.RESPUESTA_GH));
    mostrarCierre();
    rdCambiosGuardados();
    $('#panelLista').hide(); $('#panelForm').show(); window.scrollTo(0, 0);
}

//La fecha comprometida con la que se abrió la vacante (null si es nueva o está cerrada)
var compromisoV = null;
$(document).on('change input', '#vCompromiso', function () {
    $('#grupoMotivoCompromiso').toggle(compromisoV !== null && compromisoV !== '' && this.value !== '' && this.value !== compromisoV);
});

//Solo una vacante cubierta o cancelada tiene fecha de cierre, y se pone sola al guardar:
//aquí solo se muestra (la que ya tenía, o hoy si se va a cerrar ahora)
function mostrarCierre() {
    var cerrada = $('#vEstado').val() !== 'ABIERTA';
    $('#grupoCierre').toggle(cerrada);
    $('#vCierreTxt').text(cierreV ? rdFecha(cierreV) : rdFecha(cfgV.hoy) + ' (hoy, al guardar)');
}

//Guarda la vacante y vuelve a la lista
function guardar(evento) {
    evento.preventDefault();
    hanaBoton('#btnGuardar', true);
    $.post(URL_VAC + '?op=guardar', $('#formVac').serialize(), null, 'json')
        .done(function (respuesta) { rdCambiosGuardados(); rdAviso(respuesta.mensaje); $('#panelForm').hide(); $('#panelLista').show(); listar(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar la vacante.')); })
        .always(function () { hanaBoton('#btnGuardar', false); });
}
