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
            var htmlCentros = '<option value="0">Todos</option>', htmlFormCentros = '';
            for (var i = 0; i < config.centros.length; i++) {
                var id = parseInt(config.centros[i].ID_CENTRO_OP, 10), nombreCentro = rdEsc(config.centros[i].NOM_CENTRO_OP + ' · ' + config.centros[i].NOM_PROYECTO);
                htmlCentros += '<option value="' + id + '">' + nombreCentro + '</option>'; htmlFormCentros += '<option value="' + id + '">' + nombreCentro + '</option>';
            }
            $('#fCentro').html(htmlCentros); $('#vCentro').html(htmlFormCentros || '<option value="">Tus proyectos no tienen centros activos</option>');
            var htmlEstados = '', htmlEstadosGh = '<option value="">Sin información</option>', htmlMotivos = '';
            $.each(config.estados, function (clave, nombre) { htmlEstados += '<option value="' + rdEsc(clave) + '">' + rdEsc(nombre) + '</option>'; });
            $.each(config.estadosGh, function (clave, nombre) { htmlEstadosGh += '<option value="' + rdEsc(clave) + '">' + rdEsc(nombre) + '</option>'; });
            for (var numMotivo = 0; numMotivo < config.motivos.length; numMotivo++) { htmlMotivos += '<option value="' + rdEsc(config.motivos[numMotivo]) + '">'; }
            $('#vEstado').html(htmlEstados); $('#vGh').html(htmlEstadosGh); $('#listaMotivos').html(htmlMotivos);
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
    $('#fMes').on('change', function () { $('#fAnio').prop('disabled', this.value === '0'); listar(); });
    rdVigilarCambios('#formVac');
    $('#btnNueva').on('click', function () { abrir(null); });
    $('#btnCancelar').on('click', function () {
        if (rdHayCambios && !window.confirm('La vacante tiene cambios sin guardar. ¿Salir y perderlos?')) { return; }
        rdCambiosGuardados();
        $('#panelForm').hide(); $('#panelLista').show();
    });
    $('#vEstado').on('change', mostrarCierre);
    $('#formVac').on('submit', guardar);
    $(document).on('click', '.btn-editar-vac', function () { abrir(filasV[$(this).data('i')]); });
});

//Trae las vacantes según los filtros y las pinta en la tabla
function listar() {
    var filtros = '&centro=' + ($('#fCentro').val() || 0) + '&mes=' + $('#fMes').val() + '&anio=' + $('#fAnio').val();
    $.getJSON(URL_VAC + '?op=listar' + filtros)
        .done(function (filas) {
            filasV = filas;
            var html = '', abiertas = 0, vencidas = 0, cumplen = 0, noCumplen = 0;
            for (var i = 0; i < filas.length; i++) {
                var vacante = filas[i], abierta = vacante.ESTADO_VACANTE === 'ABIERTA';
                if (abierta) { abiertas++; if (vacante.RESULTADO_ANS === 'VENCIDA') { vencidas++; } }
                if (vacante.RESULTADO_ANS === 'CUMPLE') { cumplen++; } if (vacante.RESULTADO_ANS === 'NO_CUMPLE') { noCumplen++; }
                var comp = (vacante.ESTADO_GH ? cfgV.estadosGh[vacante.ESTADO_GH] : '') + (vacante.FECHA_COMPROMISO_GH ? ' ' + rdFecha(vacante.FECHA_COMPROMISO_GH).substring(0, 5) : '');
                html += '<tr' + (vacante.ESTADO_VACANTE === 'CANCELADA' ? ' class="rd-anulado"' : '') + '>' +
                     '<td>' + rdEsc(vacante.NUMERO_RQ_GH || '') + '</td><td>' + rdEsc(vacante.NOM_CENTRO_OP) + '</td><td>' + rdEsc(vacante.CARGO) + '</td>' +
                     '<td>' + rdFecha(vacante.FECHA_VACANTE) + '</td><td>' + rdFecha(vacante.FECHA_ANS) + '</td><td>' + rdFecha(vacante.FECHA_CIERRE) + '</td>' +
                     '<td class="rd-num-col">' + (abierta ? vacante.DIAS_A_HOY + ' a hoy' : (vacante.TIEMPO_CIERRE !== null ? vacante.TIEMPO_CIERRE + ' en cerrar' : '')) + '</td>' +
                     '<td>' + rdEsc(vacante.MOTIVO) + '</td><td>' + rdEsc(cfgV.estados[vacante.ESTADO_VACANTE]) + '</td>' +
                     '<td>' + (RESULTADO_ANS[vacante.RESULTADO_ANS] || '') + '</td><td>' + rdEsc(vacante.NOMBRE_REEMPLAZO || '') + '</td>' +
                     '<td>' + rdEsc(comp) + '</td>' +
                     '<td>' + (cfgV.puedeLlenar !== false ? '<button type="button" class="btn btn-default btn-xs btn-editar-vac" data-i="' + i + '"><i class="fa fa-pencil"></i></button>' : '') + '</td></tr>';
            }
            $('#filas').html(html || '<tr><td colspan="13" class="rd-vacio">No hay vacantes para mostrar.</td></tr>');
            $('#cAbiertas').text(abiertas); $('#cVencidas').text(vencidas); $('#cCumple').text(cumplen); $('#cNoCumple').text(noCumplen);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron cargar las vacantes.'); });
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
        $('#vEstado').val(vacante.ESTADO_VACANTE); $('#vObs').val(vacante.OBSERVACION || '');
    } else {
        $('#vFecha').val(cfgV.hoy); $('#vEstado').val('ABIERTA');
    }
    mostrarCierre();
    rdCambiosGuardados();
    $('#panelLista').hide(); $('#panelForm').show(); window.scrollTo(0, 0);
}

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
