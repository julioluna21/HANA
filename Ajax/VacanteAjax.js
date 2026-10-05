//Reporte diario — Vacantes
var URL_VAC = '../Control/VacanteControl.php';
var cfgV = null, filasV = [];

var RESULTADO_ANS = {
    CUMPLE:    '<span class="rd-tag rd-tag-ok">Cumple</span>',
    NO_CUMPLE: '<span class="rd-tag rd-tag-mal">No cumple</span>',
    EN_TIEMPO: '<span class="rd-tag rd-tag-pend">En tiempo</span>',
    VENCIDA:   '<span class="rd-tag rd-tag-mal">Fuera del acuerdo</span>'
};

$(function () {
    $.getJSON(URL_VAC + '?op=config')
        .done(function (c) {
            cfgV = c;
            var hc = '<option value="0">Todos</option>', hf = '';
            for (var i = 0; i < c.centros.length; i++) {
                var id = parseInt(c.centros[i].ID_CENTRO_OP, 10), nom = rdEsc(c.centros[i].NOM_CENTRO_OP + ' · ' + c.centros[i].NOM_PROYECTO);
                hc += '<option value="' + id + '">' + nom + '</option>'; hf += '<option value="' + id + '">' + nom + '</option>';
            }
            $('#fCentro').html(hc); $('#vCentro').html(hf || '<option value="">Tus proyectos no tienen centros activos</option>');
            var he = '', hg = '<option value="">Sin información</option>', hm = '';
            $.each(c.estados, function (k, v) { he += '<option value="' + rdEsc(k) + '">' + rdEsc(v) + '</option>'; });
            $.each(c.estadosGh, function (k, v) { hg += '<option value="' + rdEsc(k) + '">' + rdEsc(v) + '</option>'; });
            for (var m = 0; m < c.motivos.length; m++) { hm += '<option value="' + rdEsc(c.motivos[m]) + '">'; }
            $('#vEstado').html(he); $('#vGh').html(hg); $('#listaMotivos').html(hm);
            rdPeriodo('#fMes', '#fAnio', c.hoy); $('#fMes').val('0'); $('#fAnio').prop('disabled', true);
            $('#ayudaAns').text('El acuerdo de servicio es de ' + c.diasAns + ' días hábiles desde la vacante, sin contar sábados, domingos ni festivos.');
            listar();
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

function listar() {
    var q = '&centro=' + ($('#fCentro').val() || 0) + '&mes=' + $('#fMes').val() + '&anio=' + $('#fAnio').val();
    $.getJSON(URL_VAC + '?op=listar' + q)
        .done(function (f) {
            filasV = f;
            var h = '', ab = 0, ven = 0, cu = 0, nc = 0;
            for (var i = 0; i < f.length; i++) {
                var v = f[i], abierta = v.ESTADO_VACANTE === 'ABIERTA';
                if (abierta) { ab++; if (v.RESULTADO_ANS === 'VENCIDA') { ven++; } }
                if (v.RESULTADO_ANS === 'CUMPLE') { cu++; } if (v.RESULTADO_ANS === 'NO_CUMPLE') { nc++; }
                var comp = (v.ESTADO_GH ? cfgV.estadosGh[v.ESTADO_GH] : '') + (v.FECHA_COMPROMISO_GH ? ' ' + rdFecha(v.FECHA_COMPROMISO_GH).substring(0, 5) : '');
                h += '<tr' + (v.ESTADO_VACANTE === 'CANCELADA' ? ' class="rd-anulado"' : '') + '>' +
                     '<td>' + rdEsc(v.NUMERO_RQ_GH || '') + '</td><td>' + rdEsc(v.NOM_CENTRO_OP) + '</td><td>' + rdEsc(v.CARGO) + '</td>' +
                     '<td>' + rdFecha(v.FECHA_VACANTE) + '</td><td>' + rdFecha(v.FECHA_ANS) + '</td><td>' + rdFecha(v.FECHA_CIERRE) + '</td>' +
                     '<td class="rd-num-col">' + (abierta ? v.DIAS_A_HOY + ' a hoy' : (v.TIEMPO_CIERRE !== null ? v.TIEMPO_CIERRE + ' en cerrar' : '')) + '</td>' +
                     '<td>' + rdEsc(v.MOTIVO) + '</td><td>' + rdEsc(cfgV.estados[v.ESTADO_VACANTE]) + '</td>' +
                     '<td>' + (RESULTADO_ANS[v.RESULTADO_ANS] || '') + '</td><td>' + rdEsc(v.NOMBRE_REEMPLAZO || '') + '</td>' +
                     '<td>' + rdEsc(comp) + '</td>' +
                     '<td><button type="button" class="btn btn-default btn-xs btn-editar-vac" data-i="' + i + '"><i class="fa fa-pencil"></i></button></td></tr>';
            }
            $('#filas').html(h || '<tr><td colspan="13" class="rd-vacio">No hay vacantes para mostrar.</td></tr>');
            $('#cAbiertas').text(ab); $('#cVencidas').text(ven); $('#cCumple').text(cu); $('#cNoCumple').text(nc);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron cargar las vacantes.'); });
}

function abrir(v) {
    $('#formVac')[0].reset();
    $('#vId').val(v ? v.ID_VACANTE : '');
    $('#tituloForm').html('<i class="fa fa-user-plus"></i> ' + (v ? 'Vacante · ' + rdEsc(v.CARGO) : 'Nueva vacante'));
    $('#vFecha, #vCierre').attr('max', cfgV.ultimoEditable);
    if (v) {
        $('#vCentro').val(String(v.ID_CENTRO_OP)); $('#vRq').val(v.NUMERO_RQ_GH || ''); $('#vCargo').val(v.CARGO);
        $('#vFecha').val(v.FECHA_VACANTE); $('#vMotivo').val(v.MOTIVO); $('#vGh').val(v.ESTADO_GH || '');
        $('#vCompromiso').val(v.FECHA_COMPROMISO_GH || ''); $('#vNombre').val(v.NOMBRE_REEMPLAZO || '');
        $('#vEstado').val(v.ESTADO_VACANTE); $('#vCierre').val(v.FECHA_CIERRE || ''); $('#vObs').val(v.OBSERVACION || '');
    } else {
        $('#vFecha').val(cfgV.hoy); $('#vEstado').val('ABIERTA');
    }
    mostrarCierre();
    rdCambiosGuardados();
    $('#panelLista').hide(); $('#panelForm').show(); window.scrollTo(0, 0);
}

//Solo una vacante cubierta o cancelada lleva fecha de cierre
function mostrarCierre() {
    var cerrada = $('#vEstado').val() !== 'ABIERTA';
    $('#grupoCierre').toggle(cerrada);
    if (cerrada && !$('#vCierre').val()) { $('#vCierre').val(cfgV.hoy); }
}

function guardar(e) {
    e.preventDefault();
    hanaBoton('#btnGuardar', true);
    $.post(URL_VAC + '?op=guardar', $('#formVac').serialize(), null, 'json')
        .done(function (r) { rdCambiosGuardados(); rdAviso(r.mensaje); $('#panelForm').hide(); $('#panelLista').show(); listar(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar la vacante.')); })
        .always(function () { hanaBoton('#btnGuardar', false); });
}
