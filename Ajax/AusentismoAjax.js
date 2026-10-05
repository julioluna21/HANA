//Ausentismo (Fase 3): la matriz novedades × días de cada cargo, como en el Excel
var URL_AUS = '../Control/AusentismoControl.php';
var cfgAus = null, proyAus = null, centroAus = null, datosAus = null;
var cambiosAus = {};        //"cargo-novedad-dia" => cantidad (solo lo que cambió)
var DIA_SEMANA = ['D', 'L', 'M', 'M', 'J', 'V', 'S'];

$(function () {
    $.getJSON(URL_AUS + '?op=config')
        .done(function (c) {
            cfgAus = c;
            rdPeriodo('#aMes', '#aAnio', c.hoy);
            var h = '';
            c.proyectos.forEach(function (p) {
                h += '<option value="' + parseInt(p.ID_PROYECTO, 10) + '">' + rdEsc(p.NOM_PROYECTO) + (p.COORDINA ? ' (el tuyo)' : (p.EDITABLE && !p.VE_PROYECTO ? ' (tu peaje)' : '')) + '</option>';
            });
            $('#aProyecto').html(h || '<option value="">No tienes proyectos</option>').val(String(c.proyectoInicial || ''));
            elegirProyectoAus();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir ausentismo.'); });

    rdVigilarCambios('#ausForm');
    $('#aProyecto').on('change', function () { if (confirmarSalida()) { elegirProyectoAus(); } });
    $('#aCentro').on('change', function () { if (confirmarSalida()) { elegirCentroAus(+this.value); } else { $(this).val(String(centroAus.ID_CENTRO_OP)); } });
    $('#aMes, #aAnio').on('change', function () { if (confirmarSalida()) { cargarAus(); } });
    $(document).on('click', '.aus-centro-chip', function () { if (confirmarSalida()) { $('#aCentro').val(String($(this).data('id'))); elegirCentroAus(+$(this).data('id')); } });
    $(document).on('input', '.aus-celda', escribirCelda);
    $(document).on('keydown', '.aus-celda', moverConFlechas);
    $(document).on('focus', '.aus-celda', function () { this.select(); });
    $('#btnGuardarAus').on('click', guardarAus);
    $('#btnCerrar').on('click', function () { cierreAus('cerrar', '¿Cerrar el ausentismo de este mes? Después ya no se podrá corregir, salvo que lo reabra quien tenga el permiso.'); });
    $('#btnReabrir').on('click', function () { cierreAus('reabrir', '¿Reabrir este mes para que el coordinador lo pueda corregir?'); });
    $('#tabConsLink').on('shown.bs.tab', cargarConsolidado);
    $('#ausHistorial').on('toggle', function () { if (this.open) { cargarHistorial(); } });
});

function confirmarSalida() {
    if (!Object.keys(cambiosAus).length) { return true; }
    if (window.confirm('Hay cambios sin guardar en este centro. ¿Continuar y perderlos?')) { cambiosAus = {}; rdCambiosGuardados(); return true; }
    return false;
}

function elegirProyectoAus() {
    var id = +($('#aProyecto').val() || 0);
    proyAus = cfgAus.proyectos.filter(function (p) { return +p.ID_PROYECTO === id; })[0] || null;
    if (!proyAus) { $('#ausGrid').html('<p class="rd-vacio">No tienes proyectos para ver.</p>'); return; }
    var h = '';
    proyAus.CENTROS.forEach(function (c) { h += '<option value="' + parseInt(c.ID_CENTRO_OP, 10) + '">' + (c.TIPO_CENTRO === 'BASCULA' ? 'Báscula ' : '') + rdEsc(c.NOM_CENTRO_OP) + '</option>'; });
    $('#aCentro').html(h || '<option value="">El proyecto no tiene centros</option>');
    elegirCentroAus(+($('#aCentro').val() || 0));
}

function elegirCentroAus(id) {
    centroAus = proyAus.CENTROS.filter(function (c) { return +c.ID_CENTRO_OP === id; })[0] || null;
    cargarAus();
}

function periodoAus() { return '&proyecto=' + proyAus.ID_PROYECTO + '&anio=' + $('#aAnio').val() + '&mes=' + $('#aMes').val(); }

function cargarAus() {
    if (!proyAus || !centroAus) { return; }
    $('#btnExcel').attr('href', 'AusentismoExcelVista.php?' + periodoAus().substring(1));
    $.getJSON(URL_AUS + '?op=mes&centro=' + centroAus.ID_CENTRO_OP + periodoAus())
        .done(function (d) {
            datosAus = d; cambiosAus = {}; rdCambiosGuardados();
            //Quién registra y en qué estado está el mes
            var est = d.cerrado ? '<span class="rd-tag rd-tag-mal"><i class="fa fa-lock"></i> Mes cerrado' + (d.cierre && d.cierre.QUIEN ? ' por ' + rdEsc(d.cierre.QUIEN) + ' el ' + rdFecha(d.cierre.FEC_MODIFICACION) : '') + '</span>'
                                : '<span class="rd-tag rd-tag-ok"><i class="fa fa-unlock"></i> Mes abierto</span>';
            $('#aQuien').html('<i class="fa fa-user"></i> Coordinador: <strong>' + rdEsc(d.coordinador || 'sin asignar') + '</strong> ' + est +
                              (d.editable ? '' : ' <span class="adm-sello">' + rdEsc(d.motivo) + '</span>'));
            $('#btnCerrar').toggle(!!d.puedeCerrar);
            //El jefe de peaje ve solo su peaje: sin consolidado ni Excel del proyecto
            $('#tabConsLink').parent().toggle(!!d.veProyecto);
            $('#btnExcel').toggle(!!d.veProyecto);
            if (!d.veProyecto && $('#tabConsolidado').hasClass('active')) { $('a[href="#tabRegistrar"]').tab('show'); }
            $('#ausNota').html(d.editaTodo ? '<div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-unlock-alt"></i> Este mes está cerrado, pero tu permiso (25M) deja corregirlo. Cada cambio queda en el historial.</div>' : '');
            if ($('#ausHistorial').prop('open')) { cargarHistorial(); }
            $('#btnReabrir').toggle(!!(d.cerrado && cfgAus.puedeReabrir));
            $('#ausAcciones').toggle(!!d.editable);
            //Avance: qué centros ya tienen registrado el mes
            var h = '';
            proyAus.CENTROS.forEach(function (c) {
                var a = d.avance[c.ID_CENTRO_OP] || { celdas: 0 };
                h += '<span class="aus-centro-chip' + (+c.ID_CENTRO_OP === +centroAus.ID_CENTRO_OP ? ' activo' : '') + (a.celdas ? ' con' : '') + '" data-id="' + parseInt(c.ID_CENTRO_OP, 10) + '" role="button" tabindex="0">' +
                     '<i class="fa ' + (a.celdas ? 'fa-check' : 'fa-circle-o') + '"></i> ' + rdEsc(c.NOM_CENTRO_OP) + '</span>';
            });
            $('#aCentros').html(h);
            pintarGrilla();
            if ($('#tabConsolidado').hasClass('active')) { cargarConsolidado(); }
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el ausentismo.'); });
}

//Los cargos que salen en el centro y las novedades de cada cargo
function cargosDelCentro(tipo) {
    var t = tipo === 'BASCULA' ? 'BASCULA' : 'PEAJE';
    return cfgAus.cargos.filter(function (c) { return c.APLICA === 'AMBOS' || c.APLICA === t; });
}
function novedadesDe(idCargo) {
    return cfgAus.novedades.filter(function (n) { return !n.ID_CARGO_AUS || +n.ID_CARGO_AUS === +idCargo; });
}

//Encabezado de días: número y letra del día de la semana; fines de semana y hoy marcados
function cabeceraDias(dias, titulo, extra) {
    var anio = +$('#aAnio').val(), mes = +$('#aMes').val(), h = '<thead><tr><th class="aus-fija">' + titulo + '</th>';
    for (var d = 1; d <= dias; d++) {
        var f = new Date(anio, mes - 1, d), hoy = rdATexto(f) === cfgAus.hoy;
        h += '<th class="aus-dia' + (f.getDay() === 0 || f.getDay() === 6 ? ' aus-finde' : '') + (hoy ? ' aus-hoy' : '') + '">' + d + '<small>' + DIA_SEMANA[f.getDay()] + '</small></th>';
    }
    return h + (extra || '<th class="aus-tot">Total</th>') + '</tr></thead>';
}

function pintarGrilla() {
    var dias = datosAus.diasMes, ed = datosAus.editable, h = '', fila = 0;
    cargosDelCentro(centroAus.TIPO_CENTRO).forEach(function (c) {
        var reg = datosAus.registros[c.ID_CARGO_AUS] || {};
        h += '<div class="aus-bloque"><div class="table-responsive aus-scroll"><table class="aus-tabla" data-cargo="' + parseInt(c.ID_CARGO_AUS, 10) + '">' +
             cabeceraDias(dias, rdEsc(c.NOMBRE)) + '<tbody>';
        novedadesDe(c.ID_CARGO_AUS).forEach(function (n) {
            var vals = reg[n.ID_NOVEDAD_AUS] || {}, cuenta = +n.CUENTA_AUSENCIA === 1;
            h += '<tr class="' + (cuenta ? '' : 'aus-no-cuenta') + '" data-cuenta="' + (cuenta ? 1 : 0) + '"><th class="aus-fija">' + rdEsc(n.NOMBRE) + '</th>';
            for (var d = 1; d <= dias; d++) {
                var v = vals[d] || '';
                h += '<td>' + (ed ? '<input type="text" inputmode="numeric" maxlength="3" class="aus-celda" value="' + v + '" data-c="' + parseInt(c.ID_CARGO_AUS, 10) +
                                     '" data-n="' + parseInt(n.ID_NOVEDAD_AUS, 10) + '" data-d="' + d + '" data-f="' + fila + '" aria-label="' + rdEsc(n.NOMBRE + ' día ' + d) + '">'
                                   : '<span class="aus-ver">' + v + '</span>') + '</td>';
            }
            h += '<td class="aus-tot aus-tot-fila">0</td></tr>';
            fila++;
        });
        h += '</tbody><tfoot><tr><th class="aus-fija">Total del día</th>';
        for (var d2 = 1; d2 <= dias; d2++) { h += '<td class="aus-tot-dia" data-d="' + d2 + '">0</td>'; }
        h += '<td class="aus-tot aus-tot-bloque">0</td></tr></tfoot></table></div></div>';
    });
    //Ausencias diarias: la suma de todos los cargos (sin las filas que no son ausencia)
    h += '<div class="aus-bloque aus-resumen"><div class="table-responsive aus-scroll"><table class="aus-tabla" id="ausDiarias">' +
         cabeceraDias(dias, 'Ausencias diarias', '<th class="aus-tot">Total</th><th class="aus-tot">1ª quincena</th>') + '<tbody><tr><th class="aus-fija">Todos los cargos</th>';
    for (var d3 = 1; d3 <= dias; d3++) { h += '<td class="aus-diaria" data-d="' + d3 + '">0</td>'; }
    h += '<td class="aus-tot" id="ausTotalMes">0</td><td class="aus-tot" id="ausQuincena">0</td></tr></tbody></table></div></div>';
    $('#ausGrid').html(h);
    recalcular();
    //Deja a la vista el día de hoy
    var hoyTh = $('#ausGrid .aus-hoy').first();
    if (hoyTh.length) { $('.aus-scroll').each(function () { this.scrollLeft = Math.max(0, hoyTh.position().left - 260); }); }
}

function valorCelda(el) { return parseInt($(el).is('input') ? $(el).val() : $(el).text(), 10) || 0; }

function recalcular() {
    var dias = datosAus.diasMes, diarias = {};
    $('#ausGrid .aus-tabla[data-cargo]').each(function () {
        var t = $(this), totDia = {}, totBloque = 0;
        t.find('tbody tr').each(function () {
            var tr = $(this), suma = 0, cuenta = tr.data('cuenta') === 1;
            tr.find('td').not('.aus-tot').each(function (i) {
                var v = valorCelda($(this).find('.aus-celda, .aus-ver'));
                suma += v;
                if (cuenta) { totDia[i + 1] = (totDia[i + 1] || 0) + v; diarias[i + 1] = (diarias[i + 1] || 0) + v; }
            });
            tr.find('.aus-tot-fila').text(suma);
            if (cuenta) { totBloque += suma; }
        });
        t.find('.aus-tot-dia').each(function () { var d = +$(this).data('d'); $(this).text(totDia[d] || 0).toggleClass('aus-hay', !!totDia[d]); });
        t.find('.aus-tot-bloque').text(totBloque);
    });
    var mesTot = 0, quincena = 0;
    $('#ausDiarias .aus-diaria').each(function () {
        var d = +$(this).data('d'), v = diarias[d] || 0;
        $(this).text(v).toggleClass('aus-hay', v > 0);
        mesTot += v; if (d <= 14) { quincena += v; }
    });
    $('#ausTotalMes').text(mesTot); $('#ausQuincena').text(quincena);
    var n = Object.keys(cambiosAus).length;
    $('#ausCambios').text(n ? (n === 1 ? '1 celda sin guardar' : n + ' celdas sin guardar') : 'Todo guardado');
}

function escribirCelda() {
    var v = this.value.replace(/\D/g, '').substring(0, 3);
    if (v !== this.value) { this.value = v; }
    var k = $(this).data('c') + '-' + $(this).data('n') + '-' + $(this).data('d');
    var antes = ((datosAus.registros[$(this).data('c')] || {})[$(this).data('n')] || {})[$(this).data('d')] || 0;
    if ((parseInt(v, 10) || 0) === antes) { delete cambiosAus[k]; $(this).removeClass('aus-cambio'); }
    else { cambiosAus[k] = parseInt(v, 10) || 0; $(this).addClass('aus-cambio'); }
    recalcular();
}

//Flechas y Enter para moverse entre celdas, como en una hoja de cálculo
function moverConFlechas(e) {
    var f = +$(this).data('f'), d = +$(this).data('d'), destino = null;
    if (e.key === 'ArrowRight') { destino = [f, d + 1]; }
    else if (e.key === 'ArrowLeft') { destino = [f, d - 1]; }
    else if (e.key === 'ArrowDown' || e.key === 'Enter') { destino = [f + 1, d]; }
    else if (e.key === 'ArrowUp') { destino = [f - 1, d]; }
    if (!destino) { return; }
    var el = $('.aus-celda[data-f="' + destino[0] + '"][data-d="' + destino[1] + '"]');
    if (el.length) { e.preventDefault(); el.focus(); }
}

function guardarAus() {
    var celdas = Object.keys(cambiosAus).map(function (k) { var p = k.split('-'); return { cargo: +p[0], novedad: +p[1], dia: +p[2], cantidad: cambiosAus[k] }; });
    if (!celdas.length) { rdAviso('No hay cambios por guardar.'); return; }
    hanaBoton('#btnGuardarAus', true);
    $.post(URL_AUS + '?op=guardar&centro=' + centroAus.ID_CENTRO_OP + periodoAus(), { celdas: JSON.stringify(celdas) }, null, 'json')
        .done(function (r) { cambiosAus = {}; rdCambiosGuardados(); rdAviso(r.mensaje); cargarAus(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el ausentismo.')); })
        .always(function () { hanaBoton('#btnGuardarAus', false); });
}

function cierreAus(op, pregunta) {
    if (Object.keys(cambiosAus).length) { alert('Primero guarda los cambios de este centro.'); return; }
    hanaConfirmar(pregunta, function () {
        $.post(URL_AUS + '?op=' + op + periodoAus(), {}, null, 'json')
            .done(function (r) { rdAviso(r.mensaje); cargarAus(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo cambiar el estado del mes.')); });
    }, { aceptar: op === 'cerrar' ? 'Sí, cerrar' : 'Sí, reabrir', cancelar: 'No' });
}

//Historial: quién cambió qué celda, de cuánto a cuánto y cuándo
function cargarHistorial() {
    if (!proyAus || !centroAus) { return; }
    $.getJSON(URL_AUS + '?op=historial&centro=' + centroAus.ID_CENTRO_OP + periodoAus())
        .done(function (f) {
            var h = '';
            f.forEach(function (x) {
                h += '<tr><td class="rd-nowrap">' + rdFecha(x.CUANDO) + ' ' + rdHora(String(x.CUANDO).substring(11)) + '</td><td>' + rdEsc(x.QUIEN || '') + '</td>' +
                     '<td>Día ' + parseInt(x.DIA, 10) + ' · ' + rdEsc(x.CARGO) + ' · ' + rdEsc(x.NOVEDAD) + '</td>' +
                     '<td class="rd-num-col">' + parseInt(x.ANTES, 10) + ' → <strong>' + parseInt(x.DESPUES, 10) + '</strong></td></tr>';
            });
            $('#ausHistorialCuerpo').html(h ? '<div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta"><thead><tr><th>Cuándo</th><th>Quién</th><th>Qué celda</th><th class="rd-num-col">Cambio</th></tr></thead><tbody>' + h + '</tbody></table></div>'
                                           : '<p class="rd-vacio">Todavía no hay cambios registrados en este peaje este mes.</p>');
        })
        .fail(function (xhr) { $('#ausHistorialCuerpo').html('<p class="rd-ayuda">' + rdEsc(rdError(xhr, 'No se pudo cargar el historial.')) + '</p>'); });
}

//---------------------------------------------------------------------------
// Consolidado del proyecto: la suma de todos sus centros (la última hoja del Excel)
//---------------------------------------------------------------------------
function cargarConsolidado() {
    if (!proyAus) { return; }
    $.getJSON(URL_AUS + '?op=consolidado' + periodoAus())
        .done(function (d) {
            var dias = d.diasMes, h = '', totNov = {}, quiNov = {};
            cfgAus.cargos.forEach(function (c) {
                var reg = d.porCargo[c.ID_CARGO_AUS];
                if (!reg) { return; } //solo los cargos con datos
                h += '<div class="aus-bloque"><div class="table-responsive aus-scroll"><table class="aus-tabla">' + cabeceraDias(dias, rdEsc(c.NOMBRE)) + '<tbody>';
                novedadesDe(c.ID_CARGO_AUS).forEach(function (n) {
                    var vals = reg[n.ID_NOVEDAD_AUS] || {}, suma = 0;
                    h += '<tr class="' + (+n.CUENTA_AUSENCIA ? '' : 'aus-no-cuenta') + '"><th class="aus-fija">' + rdEsc(n.NOMBRE) + '</th>';
                    for (var dd = 1; dd <= dias; dd++) {
                        var v = vals[dd] || 0; suma += v;
                        if (+n.CUENTA_AUSENCIA) { totNov[n.ID_NOVEDAD_AUS] = (totNov[n.ID_NOVEDAD_AUS] || 0) + v; if (dd <= 14) { quiNov[n.ID_NOVEDAD_AUS] = (quiNov[n.ID_NOVEDAD_AUS] || 0) + v; } }
                        h += '<td class="' + (v ? 'aus-hay' : '') + '">' + (v || '') + '</td>';
                    }
                    h += '<td class="aus-tot">' + suma + '</td></tr>';
                });
                h += '</tbody></table></div></div>';
            });
            //TOTAL por novedad: el mes y la primera quincena, como la columna AI del Excel
            var t = '<table class="table table-bordered rd-tabla rd-tabla-compacta aus-total"><thead><tr><th>Novedad</th><th class="rd-num-col">Total del mes</th><th class="rd-num-col">1ª quincena (días 1 a 14)</th></tr></thead><tbody>';
            var gm = 0, gq = 0;
            cfgAus.novedades.filter(function (n) { return +n.CUENTA_AUSENCIA; }).forEach(function (n) {
                var m = totNov[n.ID_NOVEDAD_AUS] || 0, qq = quiNov[n.ID_NOVEDAD_AUS] || 0; gm += m; gq += qq;
                t += '<tr><td>' + rdEsc(n.NOMBRE) + '</td><td class="rd-num-col">' + m + '</td><td class="rd-num-col">' + qq + '</td></tr>';
            });
            t += '</tbody><tfoot><tr><th>TOTAL</th><th class="rd-num-col">' + gm + '</th><th class="rd-num-col">' + gq + '</th></tr></tfoot></table>';
            //Por centro
            var nov = cfgAus.novedades;
            var pc = '<div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta"><thead><tr><th>Centro</th>';
            nov.forEach(function (n) { pc += '<th class="rd-num-col">' + rdEsc(n.NOMBRE) + '</th>'; });
            pc += '<th class="rd-num-col">Ausencias</th></tr></thead><tbody>';
            proyAus.CENTROS.forEach(function (c) {
                var r = d.porCentro[c.ID_CENTRO_OP] || {}, aus = 0;
                pc += '<tr><td>' + rdEsc(c.NOM_CENTRO_OP) + '</td>';
                nov.forEach(function (n) { var v = r[n.ID_NOVEDAD_AUS] || 0; if (+n.CUENTA_AUSENCIA) { aus += v; } pc += '<td class="rd-num-col">' + (v || '') + '</td>'; });
                pc += '<td class="rd-num-col"><strong>' + aus + '</strong></td></tr>';
            });
            pc += '</tbody></table></div>';
            $('#ausConsolidado').html('<h4 class="rd-subtitulo" style="margin-top:0">Total del proyecto</h4>' + t +
                '<h4 class="rd-subtitulo">Por centro</h4>' + pc + '<h4 class="rd-subtitulo">Por cargo, día por día</h4>' + (h || '<p class="rd-vacio">Sin datos en este mes.</p>'));
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el consolidado.'); });
}
