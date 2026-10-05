//Administración del reporte diario — consulta de todo lo que llenan los coordinadores
//Una sola lógica para las cinco pantallas: cada una trae <div id="admPagina" data-pagina="...">
//y los filtros que usa. Todo lo que viene de la base se escapa con rdEsc

var URL_ADM = '../Control/AdminReporteControl.php';
var cfgA = null;
var paginaA = '';
var filasA = [];

function tagA(txt, clase) { return '<span class="rd-tag ' + (clase || '') + '">' + txt + '</span>'; }
function vacioA(n, txt) { return '<tr><td colspan="' + n + '" class="rd-vacio">' + txt + '</td></tr>'; }
function opcionesA(lista, valor, texto, primero) {
    var h = primero !== undefined ? '<option value="0">' + rdEsc(primero) + '</option>' : '';
    for (var i = 0; i < lista.length; i++) { h += '<option value="' + parseInt(lista[i][valor], 10) + '">' + rdEsc(lista[i][texto]) + '</option>'; }
    return h;
}
function filtrosA() {
    var q = '';
    ['Mes', 'Anio', 'Proyecto', 'Centro', 'Persona', 'Tipo'].forEach(function (n) {
        var el = $('#a' + n);
        if (el.length) { q += '&' + n.toLowerCase() + '=' + encodeURIComponent(el.val() || 0); }
    });
    return q;
}

$(function () {
    paginaA = $('#admPagina').data('pagina');
    $.getJSON(URL_ADM + '?op=config')
        .done(function (c) {
            cfgA = c;
            rdPeriodo('#aMes', '#aAnio', c.hoy);
            //Vacantes y comunicaciones arrancan en "todas las abiertas"
            if ($('#aMes option[value="0"]').length) { $('#aMes').val('0'); $('#aAnio').prop('disabled', true); }
            $('#aProyecto').html(opcionesA(c.proyectos, 'ID_PROYECTO', 'NOM_PROYECTO', 'Todos los proyectos'));
            $('#aPersona').html(opcionesA(c.personas, 'ID_COLABORADOR', 'NOM_COLABORADOR',
                                          paginaA === 'cronograma' ? undefined : 'Todas las personas'));
            if ($('#aTipo').length) {
                var ht = '<option value="">Todos</option>';
                $.each(c.tiposArqueo, function (k, v) { ht += '<option value="' + rdEsc(k) + '">' + rdEsc(v) + '</option>'; });
                $('#aTipo').html(ht);
            }
            llenarCentrosA();
            cargarA();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir la administración del reporte.'); });

    $('#aProyecto').on('change', function () { llenarCentrosA(); cargarA(); });
    $('#aCentro, #aPersona, #aTipo, #aAnio').on('change', cargarA);
    $('#aMes').on('change', function () {
        if ($('#aMes option[value="0"]').length) { $('#aAnio').prop('disabled', this.value === '0'); }
        cargarA();
    });
    $(document).on('click', '.btn-adm-hoy', function () { verHoyA($(this).data('persona'), $(this).data('fecha')); });
    $(document).on('click', '.btn-adm-arq', function () { verArqueoA($(this).data('id')); });
});

//Los centros del proyecto elegido
function llenarCentrosA() {
    if (!$('#aCentro').length) { return; }
    var p = +($('#aProyecto').val() || 0);
    var lista = cfgA.centros.filter(function (c) { return !p || +c.ID_PROYECTO === p; });
    $('#aCentro').html(opcionesA(lista, 'ID_CENTRO_OP', 'NOM_CENTRO_OP', 'Todos los centros'));
}

function cargarA() {
    var op = { hoy: 'hoy', arqueos: 'arqueos', cronograma: 'cronograma', vacantes: 'vacantes', comunicaciones: 'comunicaciones' }[paginaA];
    if (paginaA === 'cronograma' && !$('#aPersona').val()) {
        $('#admResultado').html('<p class="rd-vacio">No hay coordinadores asignados todavía.</p>');
        return;
    }
    $('#admResultado').css('opacity', .5);
    $.getJSON(URL_ADM + '?op=' + op + filtrosA())
        .done(function (d) {
            $('#admResultado').css('opacity', 1);
            ({ hoy: pintarHoyA, arqueos: pintarArqueosA, cronograma: pintarCronoA, vacantes: pintarVacA, comunicaciones: pintarComA })[paginaA](d);
        })
        .fail(function (xhr) { $('#admResultado').css('opacity', 1); hanaErrorAjax(xhr, 'No se pudo consultar.'); });
}

//---------------------------------------------------------------------------
// Hoy en qué estás
//---------------------------------------------------------------------------
function pintarHoyA(f) {
    var h = '', personas = {};
    for (var i = 0; i < f.length; i++) {
        var r = f[i], lab = r.SITUACION === 'LABORAL';
        personas[r.ID_COLABORADOR] = true;
        var donde = [r.LUGARES, r.LUGAR_OTRO].filter(function (x) { return x; }).join(' · ');
        h += '<tr><td class="rd-nowrap">' + rdFecha(r.FECHA) + '</td><td>' + rdEsc(r.NOM_COLABORADOR) + '</td>' +
             '<td>' + tagA(rdEsc(cfgA.situaciones[r.SITUACION] || r.SITUACION), lab ? 'rd-tag-ok' : '') + '</td>' +
             '<td>' + rdEsc(lab ? donde : '') + (r.OBSERVACION ? '<br><small>' + rdEsc(r.OBSERVACION) + '</small>' : '') + '</td>' +
             '<td class="rd-nowrap">' + (lab && r.HORA_INGRESO ? rdHora(r.HORA_INGRESO) + ' – ' + (r.HORA_SALIDA ? rdHora(r.HORA_SALIDA) : '…') : '') + '</td>' +
             '<td class="rd-num-col">' + (lab ? parseInt(r.BLOQUES, 10) + ' h' : '') + '</td>' +
             '<td class="rd-nowrap"><small>' + rdFecha(String(r.FEC_MODIFICACION || r.FEC_REGISTRO).substring(0, 10)) + ' ' +
                 rdHora(String(r.FEC_MODIFICACION || r.FEC_REGISTRO).substring(11)) + '</small></td>' +
             '<td><button type="button" class="btn btn-success btn-xs btn-adm-hoy" data-persona="' + parseInt(r.ID_COLABORADOR, 10) +
                 '" data-fecha="' + rdEsc(r.FECHA) + '"><i class="fa fa-eye"></i> Ver</button></td></tr>';
    }
    $('#admResumen').html(tagA(f.length + (f.length === 1 ? ' registro' : ' registros'), 'rd-tag-ok') + ' ' +
                          tagA(Object.keys(personas).length + (Object.keys(personas).length === 1 ? ' persona' : ' personas')));
    $('#admFilas').html(h || vacioA(8, 'No hay registros en este mes.'));
}

function verHoyA(persona, fecha) {
    $('#admModalCuerpo').html('<p class="rd-vacio">Cargando...</p>');
    $('#admModal').modal('show');
    $.getJSON(URL_ADM + '?op=hoyDetalle&persona=' + encodeURIComponent(persona) + '&fecha=' + encodeURIComponent(fecha))
        .done(function (r) {
            $('#admModalTitulo').text(r.NOM_COLABORADOR + ' · ' + rdFecha(r.FECHA));
            var lab = r.SITUACION === 'LABORAL';
            var h = '<p>' + tagA(rdEsc(cfgA.situaciones[r.SITUACION] || r.SITUACION), lab ? 'rd-tag-ok' : '') +
                    (lab && r.HORA_INGRESO ? ' ' + rdHora(r.HORA_INGRESO) + ' – ' + (r.HORA_SALIDA ? rdHora(r.HORA_SALIDA) : '…') : '') + '</p>';
            var donde = (r.LUGARES || []).concat(r.LUGAR_OTRO ? [r.LUGAR_OTRO] : []);
            if (donde.length) { h += '<p><strong>Dónde:</strong> ' + rdEsc(donde.join(' · ')) + '</p>'; }
            var horas = Object.keys(r.HORAS || {}).map(Number).sort(function (a, b) { return a - b; });
            if (horas.length) {
                h += '<table class="table rd-tabla rd-tabla-compacta"><tbody>';
                for (var i = 0; i < horas.length; i++) {
                    h += '<tr><td class="rd-nowrap" style="width:110px;"><strong>' + rdDos(horas[i]) + ':00 – ' + rdDos(horas[i] + 1) + ':00</strong></td><td>' + rdEsc(r.HORAS[horas[i]]) + '</td></tr>';
                }
                h += '</tbody></table>';
            }
            if (r.OBSERVACION) { h += '<p><strong>Observación:</strong> ' + rdEsc(r.OBSERVACION) + '</p>'; }
            $('#admModalCuerpo').html(h);
        })
        .fail(function (xhr) { $('#admModal').modal('hide'); alert(rdError(xhr, 'No se pudo abrir el registro.')); });
}

//---------------------------------------------------------------------------
// Arqueos
//---------------------------------------------------------------------------
function difA(d) {
    d = parseFloat(d) || 0;
    return d === 0 ? tagA('<i class="fa fa-check"></i> Cuadra', 'rd-tag-ok') : tagA((d < 0 ? 'Faltante ' : 'Sobrante ') + rdPesos(Math.abs(d)), 'rd-tag-mal');
}

function pintarArqueosA(f) {
    var h = '', total = 0, cuadran = 0, dif = 0;
    for (var i = 0; i < f.length; i++) {
        var a = f[i], vig = parseInt(a.ESTADO, 10) === 1, d = parseFloat(a.DIFERENCIA);
        if (vig) { total++; if (d === 0) { cuadran++; } else { dif++; } }
        h += '<tr' + (vig ? '' : ' class="rd-anulado"') + '><td class="rd-nowrap">' + rdFecha(a.FECHA) + ' <small>' + rdHora(a.HORA) + '</small></td>' +
             '<td>' + rdEsc(cfgA.tiposArqueo[a.TIPO] || a.TIPO) + '</td><td>' + rdEsc(a.NOM_CENTRO_OP) + '</td><td>' + rdEsc(a.NOM_PROYECTO) + '</td>' +
             '<td>' + rdEsc(a.RESPONSABLE) + '</td><td>' + rdEsc(a.ARQUEA || '') + '</td>' +
             '<td class="rd-num-col">' + rdPesos(a.TOTAL_ARQUEO) + '</td><td class="rd-num-col">' + rdPesos(a.FONDO_AUTORIZADO) + '</td>' +
             '<td>' + (vig ? difA(d) : tagA('Anulado')) + '</td>' +
             '<td class="rd-nowrap"><button type="button" class="btn btn-success btn-xs btn-adm-arq" data-id="' + parseInt(a.ID_ARQUEO, 10) + '"><i class="fa fa-eye"></i> Ver</button> ' +
             '<a class="btn btn-default btn-xs" target="_blank" rel="noopener" href="ArqueoPdfVista.php?ver=1&id=' + parseInt(a.ID_ARQUEO, 10) + '"><i class="fa fa-file-pdf-o"></i> PDF</a></td></tr>';
    }
    $('#admResumen').html(tagA(total + ' arqueos') + ' ' + tagA(cuadran + ' cuadran', 'rd-tag-ok') + ' ' + (dif ? tagA(dif + ' con diferencia', 'rd-tag-mal') : ''));
    $('#admFilas').html(h || vacioA(10, 'No hay arqueos con estos filtros.'));
}

function verArqueoA(id) {
    $('#admModalCuerpo').html('<p class="rd-vacio">Cargando...</p>');
    $('#admModal').modal('show');
    $.getJSON(URL_ADM + '?op=arqueo&id=' + encodeURIComponent(id))
        .done(function (a) {
            $('#admModalTitulo').text('Arqueo de ' + (cfgA.tiposArqueo[a.TIPO] || a.TIPO).toLowerCase() + ' · ' + a.NOM_CENTRO_OP + ' · ' + rdFecha(a.FECHA));
            var h = '<p><strong>Responsable:</strong> ' + rdEsc(a.RESPONSABLE) + ' · <strong>Arqueó:</strong> ' + rdEsc(a.ARQUEA || '') + ' · ' +
                    (parseInt(a.ESTADO, 10) === 1 ? difA(a.DIFERENCIA) : tagA('Anulado: ' + rdEsc(a.MOTIVO_ANULACION || ''))) + '</p>';
            h += '<table class="table rd-tabla rd-tabla-compacta"><thead><tr><th>Concepto</th><th>Fecha</th><th>Observación</th><th class="rd-num-col">Valor</th></tr></thead><tbody>';
            for (var i = 0; i < a.LINEAS.length; i++) {
                var l = a.LINEAS[i];
                //Casetas: el renglón trae el nombre que tenía el campo ese día
                var nom = l.NOMBRE || (l.CLASE === 'DOCUMENTO' ? cfgA.documentos[l.CONCEPTO] : (cfgA.efectivo[a.TIPO] || {})[l.CONCEPTO]);
                h += '<tr><td>' + rdEsc(nom || l.CONCEPTO) + '</td><td>' + rdFecha(l.FECHA) + '</td><td>' + rdEsc(l.OBSERVACION || '') + '</td><td class="rd-num-col">' + rdPesos(l.VALOR) + '</td></tr>';
            }
            h += '</tbody><tfoot><tr><th colspan="3">Total arqueo</th><th class="rd-num-col">' + rdPesos(a.TOTAL_ARQUEO) + '</th></tr>' +
                 '<tr><th colspan="3">Fondo autorizado</th><th class="rd-num-col">' + rdPesos(a.FONDO_AUTORIZADO) + '</th></tr>' +
                 '<tr><th colspan="3">Diferencia</th><th class="rd-num-col">' + rdPesos(a.DIFERENCIA) + '</th></tr></tfoot></table>';
            if (a.OBSERVACION) { h += '<p><strong>Observación:</strong> ' + rdEsc(a.OBSERVACION) + '</p>'; }
            h += '<div class="rd-acciones"><a class="btn btn-success" target="_blank" rel="noopener" href="ArqueoPdfVista.php?ver=1&id=' + parseInt(a.ID_ARQUEO, 10) + '"><i class="fa fa-file-pdf-o"></i> Ver PDF</a></div>';
            $('#admModalCuerpo').html(h);
        })
        .fail(function (xhr) { $('#admModal').modal('hide'); alert(rdError(xhr, 'No se pudo abrir el arqueo.')); });
}

//---------------------------------------------------------------------------
// Cronograma y vehículo de una persona (vista de lista, solo lectura)
//---------------------------------------------------------------------------
function pintarCronoA(d) {
    var NOMBRE_DIA = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    var EST = { PROGRAMADA: 'Programada', REALIZADA: 'Realizada', NO_REALIZADA: 'No realizada', CANCELADA: 'Cancelada', AUSENCIA: '' };
    var porDia = {};
    for (var i = 0; i < d.items.length; i++) { (porDia[d.items[i].FECHA] = porDia[d.items[i].FECHA] || []).push(d.items[i]); }
    var ultimo = rdSumarDias(d.hasta, -1), h = '', hechas = 0, total = 0;
    for (var f = d.desde; f <= ultimo; f = rdSumarDias(f, 1)) {
        var its = porDia[f] || [], sit = d.situaciones[f], fecha = rdAFecha(f);
        var vhs = d.vehiculos.map(function (v) { return v.DIAS[f] ? { placa: v.PLACA, e: v.DIAS[f] } : null; }).filter(function (x) { return x; });
        var lug = d.lugares && d.lugares[f];
        if (!its.length && !sit && !vhs.length) { continue; } //solo los días con algo
        h += '<div class="crono-l-dia' + (f === cfgA.hoy ? ' crono-l-hoy' : '') + '"><div class="crono-l-cab" style="cursor:default;"><strong>' +
             NOMBRE_DIA[fecha.getDay()] + ' ' + fecha.getDate() + '</strong>' + (f === cfgA.hoy ? ' ' + tagA('Hoy', 'rd-tag-ok') : '') +
             (sit ? ' ' + tagA(rdEsc(cfgA.situaciones[sit] || sit)) : '');
        h += '</div>';
        //Las dos líneas fijas del día: dónde estuvo y el vehículo (placa y estado)
        var fijas = (lug ? '<div class="crono-lugar"><i class="fa fa-map-marker"></i> ' + rdEsc(lug) + '</div>' : '');
        vhs.forEach(function (x) {
            fijas += '<div class="crono-vhtxt crono-vhtxt-' + rdEsc(x.e.ESTADO_VH) + '"><i class="fa fa-car"></i> <strong>' + rdEsc(x.placa) + '</strong> · ' +
                     rdEsc(cfgA.estadosVh[x.e.ESTADO_VH]) + (x.e.OBSERVACION ? ' <span class="crono-vh-obs">— ' + rdEsc(x.e.OBSERVACION) + '</span>' : '') + '</div>' +
                     (x.e.TRANSPORTE ? '<div class="crono-transporte"><i class="fa fa-road"></i> Se transportó en: ' + rdEsc(cfgA.transportes[x.e.TRANSPORTE] || x.e.TRANSPORTE) + '</div>' : '');
        });
        if (fijas) { h += '<div class="crono-l-item crono-l-fijas">' + fijas + '</div>'; }
        for (var k = 0; k < its.length; k++) {
            var it = its[k];
            if (it.ESTADO_REAL !== 'AUSENCIA' && it.ESTADO_REAL !== 'CANCELADA') { total++; if (it.ESTADO_REAL === 'REALIZADA') { hechas++; } }
            var txt = (cfgA.tiposCrono[it.TIPO] || it.TIPO) + (it.NOM_CENTRO_OP ? ' ' + it.NOM_CENTRO_OP : '') + (it.DESCRIPCION ? ': ' + it.DESCRIPCION : '');
            h += '<div class="crono-l-item"><span class="crono-item crono-' + it.ESTADO_REAL + '">' + rdEsc(txt) + '</span>' +
                 (EST[it.ESTADO_REAL] ? ' <small>' + EST[it.ESTADO_REAL] + (parseInt(it.CONFIRMADA_HOY, 10) ? ' (según Hoy en qué estás)' : '') + '</small>' : '') +
                 (it.OBSERVACION ? '<div class="rd-ayuda">' + rdEsc(it.OBSERVACION) + '</div>' : '') + '</div>';
        }
        h += '</div>';
    }
    $('#admResumen').html(tagA(hechas + ' de ' + total + ' actividades realizadas', hechas === total && total ? 'rd-tag-ok' : '') + ' ' +
                          (d.vehiculos.length ? tagA(d.vehiculos.length + (d.vehiculos.length === 1 ? ' vehículo a su cargo' : ' vehículos a su cargo')) : ''));
    $('#admResultado').html(h ? '<div class="crono-lista">' + h + '</div>' : '<p class="rd-vacio">Nada registrado para esta persona en este mes.</p>');
}

//---------------------------------------------------------------------------
// Vacantes
//---------------------------------------------------------------------------
function pintarVacA(f) {
    var R = { CUMPLE: tagA('Cumple', 'rd-tag-ok'), NO_CUMPLE: tagA('No cumple', 'rd-tag-mal'), EN_TIEMPO: tagA('En tiempo', 'rd-tag-pend'), VENCIDA: tagA('Fuera del acuerdo', 'rd-tag-mal') };
    var h = '', ab = 0, fuera = 0;
    for (var i = 0; i < f.length; i++) {
        var v = f[i], abierta = v.ESTADO_VACANTE === 'ABIERTA';
        if (abierta) { ab++; if (v.RESULTADO_ANS === 'VENCIDA') { fuera++; } }
        h += '<tr' + (v.ESTADO_VACANTE === 'CANCELADA' ? ' class="rd-anulado"' : '') + '><td>' + rdEsc(v.NUMERO_RQ_GH || '') + '</td>' +
             '<td>' + rdEsc(v.NOM_PROYECTO) + '</td><td>' + rdEsc(v.NOM_CENTRO_OP) + '</td><td>' + rdEsc(v.CARGO) + '</td>' +
             '<td>' + rdFecha(v.FECHA_VACANTE) + '</td><td>' + rdFecha(v.FECHA_ANS) + '</td><td>' + rdFecha(v.FECHA_CIERRE) + '</td>' +
             '<td class="rd-num-col">' + (abierta ? v.DIAS_A_HOY + ' a hoy' : (v.TIEMPO_CIERRE !== null ? v.TIEMPO_CIERRE + ' en cerrar' : '')) + '</td>' +
             '<td>' + rdEsc(v.MOTIVO) + '</td><td>' + rdEsc(cfgA.estadosVacante[v.ESTADO_VACANTE]) + '</td><td>' + (R[v.RESULTADO_ANS] || '') + '</td>' +
             '<td>' + rdEsc(v.NOMBRE_REEMPLAZO || '') + '</td>' +
             '<td>' + rdEsc((v.ESTADO_GH ? cfgA.estadosGh[v.ESTADO_GH] : '') + (v.FECHA_COMPROMISO_GH ? ' ' + rdFecha(v.FECHA_COMPROMISO_GH) : '')) + '</td></tr>';
    }
    $('#admResumen').html(tagA(f.length + ' vacantes') + ' ' + tagA(ab + ' abiertas', 'rd-tag-pend') + ' ' + (fuera ? tagA(fuera + ' fuera del acuerdo', 'rd-tag-mal') : ''));
    $('#admFilas').html(h || vacioA(13, 'No hay vacantes con estos filtros.'));
}

//---------------------------------------------------------------------------
// Comunicaciones
//---------------------------------------------------------------------------
function pintarComA(f) {
    var R = { CUMPLE: tagA('Cumple', 'rd-tag-ok'), NO_CUMPLE: tagA('No cumple', 'rd-tag-mal'), EN_TIEMPO: tagA('En tiempo', 'rd-tag-pend'), VENCIDA: tagA('Fuera de tiempo', 'rd-tag-mal') };
    var h = '', ab = 0, fuera = 0;
    for (var i = 0; i < f.length; i++) {
        var m = f[i], abierta = parseInt(m.ABIERTA, 10) === 1;
        if (abierta) { ab++; if (m.RESULTADO === 'VENCIDA') { fuera++; } }
        h += '<tr><td>' + rdEsc(cfgA.tiposCom[m.TIPO]) + '</td><td>' + rdEsc(m.NOM_PROYECTO) + (m.NOM_CENTRO_OP ? '<br><small>' + rdEsc(m.NOM_CENTRO_OP) + '</small>' : '') + '</td>' +
             '<td>' + rdEsc(m.RADICADO || '') + '</td><td>' + rdFecha(m.FECHA_RECEPCION) + '</td><td>' + rdEsc(cfgA.medios[m.MEDIO] || m.MEDIO) + '</td>' +
             '<td>' + rdEsc(m.REMITENTE) + '</td><td>' + rdEsc(m.ASUNTO) + (m.RESPUESTA ? '<br><small>' + rdEsc(m.RESPUESTA) + '</small>' : '') + '</td>' +
             '<td>' + rdEsc(m.RESPONSABLE) + '</td><td>' + rdFecha(m.FECHA_ATENCION) + '</td>' +
             '<td class="rd-num-col">' + (abierta ? m.DIAS_ABIERTA : m.TIEMPO_CIERRE) + '</td><td>' + (R[m.RESULTADO] || '') + '</td>' +
             '<td>' + (abierta ? tagA('Abierta', 'rd-tag-pend') + (m.PENDIENTE_DE ? '<br><small>' + rdEsc(cfgA.pendiente[m.PENDIENTE_DE]) + '</small>' : '') : tagA('Cerrada')) + '</td></tr>';
    }
    $('#admResumen').html(tagA(f.length + ' comunicaciones') + ' ' + tagA(ab + ' abiertas', 'rd-tag-pend') + ' ' + (fuera ? tagA(fuera + ' fuera de tiempo', 'rd-tag-mal') : ''));
    $('#admFilas').html(h || vacioA(12, 'No hay comunicaciones con estos filtros.'));
}
