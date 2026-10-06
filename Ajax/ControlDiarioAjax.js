//Reporte diario — Reporte general del día
//Pinta el resumen por módulo y una tabla por pestaña. Todo lo que viene de la
//base se escapa con rdEsc antes de pintarlo

var URL_CD = '../Control/ControlDiarioControl.php';
var datosCD = null;

var SIT_CD = { LABORAL: 'Laboral', DESCANSO: 'Descanso', INCAPACIDAD: 'Incapacidad', VACACIONES: 'Vacaciones', PERMISO: 'Permiso' };
var ARQ_CD = { CAJA_MENOR: 'Caja menor', RECAMBIO: 'Recambio', CASETA: 'Casetas' };
var VH_CD = { OPERATIVO: 'Operativo', INOPERATIVO: 'Inoperativo', FALLA_FLOTA: 'Falla reportada a flota' };
var TR_CD = { BUS: 'Bus', TAXI: 'Taxi', APP: 'Aplicación (Didi, Uber…)', PARTICULAR: 'Vehículo particular', OTRO: 'Otro', NO_VIAJO: 'No se desplazó' };
var CRONO_CD = { PROGRAMADA: 'Programada', REALIZADA: 'Realizada', NO_REALIZADA: 'No realizada', CANCELADA: 'Cancelada', AUSENCIA: 'Ausencia' };
var PEND_CD = { COORDINADOR_OP: 'Coordinador operativo', DIRECCION: 'Dirección', COMUNICACIONES: 'Comunicaciones', GH: 'Gestión Humana', CONTABLE: 'Contable' };

//"1 no cuadra" / "3 no cuadran"
function pl(n, singular, plural) { return n + ' ' + (n === 1 ? singular : plural); }

function tag(txt, clase) { return '<span class="rd-tag ' + (clase || '') + '">' + txt + '</span>'; }
//---------------------------------------------------------------------------
// Quién sí y quién no (los coordinadores, como en «Hoy en qué estás»)
//   conteo: {idPersona: texto que va al lado del nombre}. Los que no están, faltan
//---------------------------------------------------------------------------
function quienSiNo(sel, titulo, conteo, etqSi, etqNo) {
    var con = [], sin = [];
    datosCD.hoyEnQue.forEach(function (p) {
        if (conteo[p.ID]) { con.push(rdEsc(p.NOMBRE) + ' <small>(' + conteo[p.ID] + ')</small>'); } else { sin.push(rdEsc(p.NOMBRE)); }
    });
    $(sel).html(datosCD.hoyEnQue.length
        ? '<div class="cd-revision"><strong>' + titulo + ': ' + con.length + ' de ' + datosCD.hoyEnQue.length + '</strong>' +
          (sin.length ? '<div>' + tag(etqNo, 'rd-tag-pend') + ' ' + sin.join(', ') + '</div>' : '') +
          (con.length ? '<div>' + tag(etqSi, 'rd-tag-ok') + ' ' + con.join(', ') + '</div>' : '') + '</div>'
        : '');
}

//---------------------------------------------------------------------------
// Una tarjeta por proyecto (salen de Configuración → Proyectos: si se crea uno
// nuevo, aparece solo). Al tocar una, la tabla muestra solo ese proyecto
//   datosProy: {idProyecto: {n: número grande, lineas: [textos pequeños]}}
//---------------------------------------------------------------------------
var filtroProyTab = {};
function porProyecto(sel, tbody, unidad, datosProy) {
    var fijo = +($('#cdProyecto').val() || 0), h = '';
    datosCD.proyectos.forEach(function (p) {
        if (fijo && p.id !== fijo) { return; }
        var d = datosProy[p.id] || { n: 0, lineas: [] };
        h += '<button type="button" class="cd-proy' + (filtroProyTab[tbody] === p.id ? ' activo' : '') + (d.n ? '' : ' cero') + '" data-proy="' + p.id + '" data-tb="' + tbody + '">' +
             '<span class="cd-proy-nom">' + rdEsc(p.nombre) + '</span><span class="cd-proy-n">' + d.n + '</span><span class="cd-proy-u">' + unidad + '</span>' +
             d.lineas.map(function (l) { return '<span class="cd-proy-l">' + l + '</span>'; }).join('') + '</button>';
    });
    $(sel).html(h ? '<div class="cd-proyectos">' + h + '</div>' + (filtroProyTab[tbody] ? '<p class="rd-ayuda" style="margin:2px 0 8px;">Mostrando solo un proyecto. Toca la tarjeta otra vez para ver todos.</p>' : '') : '');
    aplicarFiltroProy(tbody);
}
function aplicarFiltroProy(tbody) {
    var f = filtroProyTab[tbody];
    $(tbody + ' tr[data-proy]').each(function () { $(this).toggle(!f || +$(this).data('proy') === f); });
}
$(document).on('click', '.cd-proy', function () {
    var tb = $(this).data('tb'), p = +$(this).data('proy');
    filtroProyTab[tb] = filtroProyTab[tb] === p ? 0 : p;
    $('.cd-proy[data-tb="' + tb + '"]').each(function () { $(this).toggleClass('activo', +$(this).data('proy') === filtroProyTab[tb]); });
    aplicarFiltroProy(tb);
});

function vacio(n, txt) { return '<tr><td colspan="' + n + '" class="rd-vacio">' + txt + '</td></tr>'; }

//Qué pestaña abre cada valor de ?ver=
var VER_TAB = { hoy: 'tabHoy', listas: 'tabListas', arqueos: 'tabArqueos', cronograma: 'tabCrono', vehiculos: 'tabVh', vacantes: 'tabVac', comunicaciones: 'tabCom', rq: 'tabRq' };

$(function () {
    //Se puede llegar con ?fecha=AAAA-MM-DD&proyecto=ID&ver=rq (desde el tablero): abre ese día, ese proyecto y esa pestaña
    var q = new URLSearchParams(window.location.search);
    var fechaUrl = /^\d{4}-\d{2}-\d{2}$/.test(q.get('fecha') || '') ? q.get('fecha') : '';
    var proyUrl = parseInt(q.get('proyecto'), 10) || 0;
    cargar(fechaUrl, proyUrl);
    if (VER_TAB[q.get('ver')]) {
        $('#cdPestanas a[href="#' + VER_TAB[q.get('ver')] + '"]').tab('show');
        setTimeout(function () { $('html, body').animate({ scrollTop: $('#cdPestanas').offset().top - 80 }, 200); }, 600); //cuando ya cargó
    }
    $('#cdFecha').on('change', function () { if (this.value) { cargar(this.value); } });
    $('#btnDiaAnt').on('click', function () { cargar(rdSumarDias(datosCD.fecha, -1)); });
    $('#btnDiaSig').on('click', function () { if (datosCD.fecha < datosCD.ultimo) { cargar(rdSumarDias(datosCD.fecha, 1)); } });
    $('#btnHoy').on('click', function () { cargar(datosCD.hoy); });
    $('#cdProyecto').on('change', function () { cargar(datosCD.fecha); });
    $(document).on('change', 'input[name="fHoy"]', pintarHoy);
    //Una tarjeta del resumen abre su pestaña
    $(document).on('click', '.cd-tarjeta[data-tab]', function () {
        $('#cdPestanas a[href="#' + $(this).data('tab') + '"]').tab('show');
        $('html, body').animate({ scrollTop: $('#cdPestanas').offset().top - 80 }, 200);
    });
    $(document).on('click', '.btn-persona-cd', function () { verPersona($(this).data('id')); });
    $(document).on('click', '.btn-ver-lista', function () { rdVerLista($(this).data('id')); });
});

function cargar(fecha, proyecto) {
    var p = proyecto !== undefined ? proyecto : ($('#cdProyecto').val() || 0);
    $.getJSON(URL_CD + '?op=dia' + (fecha ? '&fecha=' + encodeURIComponent(fecha) : '') + '&proyecto=' + encodeURIComponent(p))
        .done(function (d) {
            datosCD = d;
            $('#cdFecha').val(d.fecha).attr('max', d.ultimo);
            $('#btnDiaSig').prop('disabled', d.fecha >= d.ultimo);
            $('#btnHoy').toggle(d.fecha !== d.hoy);

            //El filtro de proyectos se llena una vez y conserva lo elegido
            if ($('#cdProyecto option').length <= 1) {
                var h = '<option value="0">Todos los proyectos</option>';
                for (var i = 0; i < d.proyectos.length; i++) { h += '<option value="' + d.proyectos[i].id + '">' + rdEsc(d.proyectos[i].nombre) + '</option>'; }
                $('#cdProyecto').html(h).val(String(p));
            }
            $('#cdAlcance').text(d.verTodos ? 'Ves lo de todos los proyectos.' : 'Ves lo de los proyectos y peajes a tu cargo.');
            $('#cdTituloImprimir').text('Reporte general · ' + rdFecha(d.fecha) + ($('#cdProyecto').val() !== '0' ? ' · ' + $('#cdProyecto option:selected').text() : ''));

            pintarResumen();
            pintarHoy(); pintarListas(); pintarArqueos(); pintarCrono(); pintarVh(); pintarVac(); pintarCom(); pintarRq();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el reporte general.'); });
}

//---------------------------------------------------------------------------
// Resumen
//---------------------------------------------------------------------------
function pintarResumen() {
    var d = datosCD;
    var rep = d.hoyEnQue.filter(function (x) { return x.REGISTRO; }).length, tot = d.hoyEnQue.length;
    var incompletas = d.listas.filter(function (l) { return +l.RESPUESTAS < +l.PREGUNTAS; }).length;
    var arqDif = d.arqueos.filter(function (a) { return parseFloat(a.DIFERENCIA) !== 0; }).length;
    var visitas = d.cronograma.filter(function (c) { return c.ESTADO_REAL !== 'AUSENCIA' && c.ESTADO_REAL !== 'CANCELADA'; });
    var hechas = visitas.filter(function (c) { return c.ESTADO_REAL === 'REALIZADA'; }).length;
    var noHechas = visitas.filter(function (c) { return c.ESTADO_REAL === 'NO_REALIZADA'; }).length;
    var vhReg = d.vehiculos.filter(function (v) { return v.ESTADO_VH; }).length;
    var vhMal = d.vehiculos.filter(function (v) { return v.ESTADO_VH && v.ESTADO_VH !== 'OPERATIVO'; }).length;
    var vacFuera = d.vacantes.filter(function (v) { return v.RESULTADO_ANS === 'VENCIDA'; }).length;
    var comFuera = d.comunicaciones.filter(function (c) { return c.RESULTADO === 'VENCIDA'; }).length;
    var rqU = d.rq.filter(function (r) { return r.TIPO_RQ === 'U'; }).length;
    var sinRevisar = d.hoyEnQue.filter(function (p) { return !d.revisionCrono[p.ID]; }).length;
    //Las listas las llena el coordinador: quién registró al menos una ese día
    var conListaIds = {};
    d.listas.forEach(function (l) { conListaIds[l.ID_PERSONA] = true; });
    var coordConLista = d.hoyEnQue.filter(function (p) { return conListaIds[p.ID]; }).length, coordSinLista = tot - coordConLista;

    //Estado de cada tarjeta: ok (verde), pend (ámbar) o mal (vino)
    var t = [
        ['tabHoy', 'fa-map-marker', 'Hoy en qué estás', tot ? rep + ' de ' + tot : '0', tot ? (tot === 1 ? 'coordinador reportó' : 'coordinadores reportaron') : 'no hay coordinadores asignados',
         !tot ? '' : (rep === tot ? 'ok' : 'pend'), tot - rep > 0 ? pl(tot - rep, 'falta', 'faltan') : ''],
        ['tabListas', 'fa-check-square-o', 'Listas de chequeo', tot ? coordConLista + ' de ' + tot : d.listas.length,
         tot ? 'coordinadores registraron listas · ' + pl(d.listas.length, 'lista', 'listas') : 'diligenciadas',
         coordSinLista ? 'pend' : (incompletas ? 'pend' : 'ok'),
         coordSinLista ? pl(coordSinLista, 'coordinador sin listas', 'coordinadores sin listas') : (incompletas ? pl(incompletas, 'incompleta', 'incompletas') : '')],
        ['tabArqueos', 'fa-money', 'Arqueos', d.arqueos.length, 'registrados', arqDif ? 'mal' : (d.arqueos.length ? 'ok' : 'pend'), arqDif ? pl(arqDif, 'no cuadra', 'no cuadran') : ''],
        ['tabCrono', 'fa-calendar', 'Cronograma', visitas.length ? hechas + ' de ' + visitas.length : '0', 'actividades realizadas',
         noHechas ? 'mal' : (sinRevisar ? 'pend' : (visitas.length && hechas === visitas.length ? 'ok' : '')),
         noHechas ? pl(noHechas, 'no realizada', 'no realizadas') : (sinRevisar ? pl(sinRevisar, 'no lo ha revisado', 'no lo han revisado') : '')],
        ['tabVh', 'fa-car', 'Vehículos', d.vehiculos.length ? vhReg + ' de ' + d.vehiculos.length : '0', 'con estado del día',
         vhMal ? 'mal' : (d.vehiculos.length && vhReg === d.vehiculos.length ? 'ok' : (d.vehiculos.length ? 'pend' : '')), vhMal ? pl(vhMal, 'no operativo', 'no operativos') : ''],
        ['tabVac', 'fa-user-plus', 'Vacantes abiertas', d.vacantes.length, 'por cubrir', vacFuera ? 'mal' : (d.vacantes.length ? 'pend' : 'ok'), vacFuera ? pl(vacFuera, 'fuera del acuerdo', 'fuera del acuerdo') : ''],
        ['tabCom', 'fa-inbox', 'Comunicaciones', d.comunicaciones.length, 'abiertas', comFuera ? 'mal' : (d.comunicaciones.length ? 'pend' : 'ok'), comFuera ? pl(comFuera, 'fuera de tiempo', 'fuera de tiempo') : ''],
        ['tabRq', 'fa-wrench', 'RQ por aprobar', d.rq.length, 'esperando', rqU ? 'mal' : (d.rq.length ? 'pend' : 'ok'), rqU ? pl(rqU, 'urgente', 'urgentes') : '']
    ];
    var h = '';
    for (var i = 0; i < t.length; i++) {
        h += '<div class="cd-tarjeta cd-' + (t[i][5] || 'neutro') + '" data-tab="' + t[i][0] + '" role="button" tabindex="0">' +
             '<div class="cd-t-cab"><i class="fa ' + t[i][1] + '"></i> ' + t[i][2] + '</div>' +
             '<div class="cd-t-num">' + t[i][3] + '</div><div class="cd-t-txt">' + t[i][4] + '</div>' +
             (t[i][6] ? '<div class="cd-t-alerta">' + t[i][6] + '</div>' : '') + '</div>';
    }
    $('#cdResumen').html(h);

    //Las pestañas no llevan números: el resumen de arriba ya los muestra
}

//---------------------------------------------------------------------------
// Pestañas
//---------------------------------------------------------------------------
function pintarHoy() {
    var f = $('input[name="fHoy"]:checked').val(), h = '';
    //Primero los que faltan: es lo que hay que perseguir
    var filas = datosCD.hoyEnQue.slice().sort(function (a, b) { return (a.REGISTRO ? 1 : 0) - (b.REGISTRO ? 1 : 0); });
    for (var i = 0; i < filas.length; i++) {
        var p = filas[i], r = p.REGISTRO;
        if ((f === 'faltan' && r) || (f === 'reportaron' && !r)) { continue; }
        var quien = '<strong>' + rdEsc(p.NOMBRE) + '</strong><br><small class="rd-ayuda" style="display:inline">' + rdEsc(p.ROLES.join(' · ')) + '</small>';
        if (!r) {
            h += '<tr class="cd-falta"><td>' + quien + '</td><td>' + tag('<i class="fa fa-clock-o"></i> Sin reporte', 'rd-tag-pend') + '</td><td colspan="4"></td>' +
                 '<td class="rd-no-imprimir"></td></tr>';
            continue;
        }
        var lab = r.SITUACION === 'LABORAL';
        var donde = [r.LUGARES, r.LUGAR_OTRO].filter(function (x) { return x; }).join(' · ');
        h += '<tr><td>' + quien + '</td>' +
             '<td>' + tag(rdEsc(SIT_CD[r.SITUACION] || r.SITUACION), lab ? 'rd-tag-ok' : '') + '</td>' +
             '<td>' + rdEsc(lab ? donde : '') + (r.OBSERVACION ? '<br><small>' + rdEsc(r.OBSERVACION) + '</small>' : '') + '</td>' +
             '<td class="rd-nowrap">' + (lab ? rdHora(r.HORA_INGRESO) + ' – ' + (r.HORA_SALIDA ? rdHora(r.HORA_SALIDA) : '…') : '') + '</td>' +
             '<td><small>' + (lab ? rdRecortar(r.ACTIVIDAD, 90) : '') + '</small></td>' +
             '<td class="rd-nowrap"><small>' + rdHora(String(r.FEC_MODIFICACION || r.FEC_REGISTRO).substring(11)) + '</small></td>' +
             '<td class="rd-no-imprimir"><button type="button" class="btn btn-success btn-xs btn-persona-cd" data-id="' + parseInt(p.ID, 10) + '"><i class="fa fa-eye"></i> Ver</button></td></tr>';
    }
    $('#tbHoy').html(h || vacio(7, datosCD.hoyEnQue.length ? 'Nadie en este filtro.' : 'No hay coordinadores asignados en lo que ves. Se asignan en Configuración → Proyectos.'));
}

function pintarListas() {
    //Las listas las llenan los coordinadores: quién registró ese día y cuántas
    var cuantas = {};
    datosCD.listas.forEach(function (l) { cuantas[l.ID_PERSONA] = (cuantas[l.ID_PERSONA] || 0) + 1; });
    Object.keys(cuantas).forEach(function (k) { cuantas[k] = pl(cuantas[k], 'lista', 'listas'); });
    quienSiNo('#cdCentrosListas', 'Coordinadores que registraron listas', cuantas, 'Con listas', 'Sin listas');

    var h = '', f = datosCD.listas;
    for (var i = 0; i < f.length; i++) {
        var l = f[i], completa = +l.RESPUESTAS >= +l.PREGUNTAS;
        h += '<tr><td class="rd-nowrap">' + rdHora(String(l.FECHA).substring(11)) + '</td><td>' + rdEsc(l.LISTA) + '</td><td>' + rdEsc(l.NOM_CENTRO_OP) + '</td>' +
             '<td>' + rdEsc(l.NOM_PROYECTO) + '</td><td>' + rdEsc(l.PERSONA || '') + '</td>' +
             '<td>' + tag(parseInt(l.RESPUESTAS, 10) + ' de ' + parseInt(l.PREGUNTAS, 10), completa ? 'rd-tag-ok' : 'rd-tag-pend') + '</td>' +
             '<td class="rd-no-imprimir"><button type="button" class="btn btn-success btn-xs btn-ver-lista" data-id="' + parseInt(l.ID_LISTA_CHEQUEO, 10) + '">' +
             '<i class="fa fa-eye"></i> Ver respuestas</button></td></tr>';
    }
    $('#tbListas').html(h || vacio(7, 'Ninguna lista diligenciada este día.'));
}

function pintarArqueos() {
    var cuantos = {};
    datosCD.arqueos.forEach(function (a) { if (a.ID_PERSONA) { cuantos[a.ID_PERSONA] = (cuantos[a.ID_PERSONA] || 0) + 1; } });
    Object.keys(cuantos).forEach(function (k) { cuantos[k] = pl(cuantos[k], 'arqueo', 'arqueos'); });
    quienSiNo('#cdArqueosQuien', 'Coordinadores que hicieron arqueos', cuantos, 'Con arqueo', 'Sin arqueo');
    var h = '', f = datosCD.arqueos;
    for (var i = 0; i < f.length; i++) {
        var a = f[i], dif = parseFloat(a.DIFERENCIA);
        h += '<tr><td class="rd-nowrap">' + rdHora(a.HORA) + '</td><td>' + rdEsc(ARQ_CD[a.TIPO] || a.TIPO) + '</td><td>' + rdEsc(a.NOM_CENTRO_OP) + '</td>' +
             '<td>' + rdEsc(a.RESPONSABLE) + '</td><td>' + rdEsc(a.ARQUEA || '') + '</td>' +
             '<td class="rd-num-col">' + rdPesos(a.TOTAL_ARQUEO) + '</td><td class="rd-num-col">' + rdPesos(a.FONDO_AUTORIZADO) + '</td>' +
             '<td>' + (dif === 0 ? tag('Cuadra', 'rd-tag-ok') : tag((dif < 0 ? 'Faltante ' : 'Sobrante ') + rdPesos(Math.abs(dif)), 'rd-tag-mal')) +
             (dif !== 0 && a.OBSERVACION ? '<br><small>' + rdEsc(a.OBSERVACION) + '</small>' : '') + '</td>' +
             '<td class="rd-no-imprimir"><a class="btn btn-default btn-xs" target="_blank" rel="noopener" href="ArqueoPdfVista.php?ver=1&id=' + parseInt(a.ID_ARQUEO, 10) + '"><i class="fa fa-file-pdf-o"></i> PDF</a></td></tr>';
    }
    $('#tbArqueos').html(h || vacio(9, 'Ningún arqueo este día.'));
}

function pintarCrono() {
    //Quién revisó el cronograma ese día (con la hora)
    var rev = {};
    Object.keys(datosCD.revisionCrono || {}).forEach(function (k) { rev[k] = 'a las ' + rdHora(String(datosCD.revisionCrono[k]).substring(11)); });
    quienSiNo('#cdRevision', 'Revisaron el cronograma', rev, 'Revisado', 'Sin revisar');

    var h = '', f = datosCD.cronograma;
    var clase = { REALIZADA: 'rd-tag-ok', NO_REALIZADA: 'rd-tag-mal', PROGRAMADA: 'rd-tag-pend', CANCELADA: '', AUSENCIA: '' };
    for (var i = 0; i < f.length; i++) {
        var c = f[i];
        var act = c.TIPO.charAt(0) + c.TIPO.slice(1).toLowerCase() + (c.NOM_CENTRO_OP ? ' ' + c.NOM_CENTRO_OP : '') + (c.DESCRIPCION ? ': ' + c.DESCRIPCION : '');
        h += '<tr><td>' + rdEsc(c.PERSONA) + '</td><td>' + rdEsc(act) + '</td>' +
             '<td>' + tag(CRONO_CD[c.ESTADO_REAL] + (parseInt(c.CONFIRMADA_HOY, 10) ? ' <small>(Hoy en qué estás)</small>' : ''), clase[c.ESTADO_REAL]) + '</td>' +
             '<td>' + rdEsc(c.OBSERVACION || '') + '</td></tr>';
    }
    $('#tbCrono').html(h || vacio(4, 'Nada planeado para este día.'));
}

function pintarVh() {
    var h = '', f = datosCD.vehiculos;
    var clase = { OPERATIVO: 'rd-tag-ok', INOPERATIVO: 'rd-tag-mal', FALLA_FLOTA: 'rd-tag-pend' };
    for (var i = 0; i < f.length; i++) {
        var v = f[i];
        h += '<tr><td><strong>' + rdEsc(v.PLACA) + '</strong>' + (v.DESCRIPCION ? ' <small>' + rdEsc(v.DESCRIPCION) + '</small>' : '') + '</td>' +
             '<td>' + rdEsc(v.RESPONSABLE || 'Sin asignar') + '</td>' +
             '<td>' + (v.ESTADO_VH ? tag(rdEsc(VH_CD[v.ESTADO_VH]), clase[v.ESTADO_VH]) : tag('Sin registro', 'rd-tag-pend')) +
                 (v.TRANSPORTE ? '<br><small>Se transportó en: ' + rdEsc(TR_CD[v.TRANSPORTE] || v.TRANSPORTE) + '</small>' : '') + '</td>' +
             '<td>' + rdEsc(v.OBSERVACION || '') + '</td></tr>';
    }
    $('#tbVh').html(h || vacio(4, 'No hay vehículos registrados en lo que ves.'));
}

function pintarVac() {
    var h = '', f = datosCD.vacantes, pp = {}, aus = datosCD.ausentismoDia || {};
    f.forEach(function (v) { var k = +v.ID_PROYECTO; pp[k] = pp[k] || { n: 0, fuera: 0 }; pp[k].n++; if (v.RESULTADO_ANS === 'VENCIDA') { pp[k].fuera++; } });
    var dp = {};
    datosCD.proyectos.forEach(function (p) { var x = pp[p.id] || { n: 0, fuera: 0 };
        dp[p.id] = { n: x.n, lineas: [(x.fuera ? '<b class="cd-mal">' + pl(x.fuera, 'fuera del acuerdo', 'fuera del acuerdo') + '</b>' : 'todas en tiempo'), 'Ausencias del día: <b>' + (aus[p.id] || 0) + '</b>'] }; });
    porProyecto('#cdVacProy', '#tbVac', 'vacantes abiertas', dp);
    for (var i = 0; i < f.length; i++) {
        var v = f[i];
        h += '<tr data-proy="' + parseInt(v.ID_PROYECTO, 10) + '"><td>' + rdEsc(v.NOM_CENTRO_OP) + '</td><td>' + rdEsc(v.CARGO) + '</td><td>' + rdFecha(v.FECHA_VACANTE) + '</td><td>' + rdFecha(v.FECHA_ANS) + '</td>' +
             '<td class="rd-num-col">' + v.DIAS_A_HOY + '</td>' +
             '<td>' + (v.RESULTADO_ANS === 'VENCIDA' ? tag('Fuera del acuerdo', 'rd-tag-mal') : tag('En tiempo', 'rd-tag-pend')) + '</td>' +
             '<td>' + rdEsc((v.ESTADO_GH || '') + (v.FECHA_COMPROMISO_GH ? ' ' + rdFecha(v.FECHA_COMPROMISO_GH) : '')) + '</td></tr>';
    }
    $('#tbVac').html(h || vacio(7, 'No hay vacantes abiertas.'));
    aplicarFiltroProy('#tbVac');
}

function pintarCom() {
    var h = '', f = datosCD.comunicaciones, pp = {};
    f.forEach(function (c) { var k = +c.ID_PROYECTO; pp[k] = pp[k] || { n: 0, fuera: 0, env: 0 }; pp[k].n++; if (c.RESULTADO === 'VENCIDA') { pp[k].fuera++; } if (c.TIPO === 'POR_ENVIAR') { pp[k].env++; } });
    var dp = {};
    datosCD.proyectos.forEach(function (p) { var x = pp[p.id] || { n: 0, fuera: 0, env: 0 };
        dp[p.id] = { n: x.n, lineas: [(x.n - x.env) + ' por atender · ' + x.env + ' por enviar', (x.fuera ? '<b class="cd-mal">' + x.fuera + ' fuera de tiempo</b>' : 'todas en tiempo')] }; });
    porProyecto('#cdComProy', '#tbCom', 'abiertas', dp);
    for (var i = 0; i < f.length; i++) {
        var c = f[i];
        h += '<tr data-proy="' + parseInt(c.ID_PROYECTO, 10) + '"><td>' + (c.TIPO === 'POR_ENVIAR' ? 'Por enviar' : 'Por atender') + '</td><td>' + rdFecha(c.FECHA_RECEPCION) + '</td>' +
             '<td>' + rdEsc(c.REMITENTE) + '</td><td>' + rdEsc(c.ASUNTO) + '</td><td>' + rdEsc(c.RESPONSABLE) + '</td>' +
             '<td class="rd-num-col">' + c.DIAS_ABIERTA + '</td>' +
             '<td>' + (c.RESULTADO === 'VENCIDA' ? tag('Fuera de tiempo', 'rd-tag-mal') : tag('En tiempo', 'rd-tag-pend')) + '</td>' +
             '<td>' + rdEsc(PEND_CD[c.PENDIENTE_DE] || '') + '</td></tr>';
    }
    $('#tbCom').html(h || vacio(8, 'No hay comunicaciones abiertas.'));
    aplicarFiltroProy('#tbCom');
}

function pintarRq() {
    var h = '', f = datosCD.rq, pp = {}, decide = !!datosCD.puedeAprobar;
    f.forEach(function (r) { var k = +r.ID_PROYECTO; pp[k] = pp[k] || { n: 0, u: 0, max: 0 }; pp[k].n++; if (r.TIPO_RQ === 'U') { pp[k].u++; } pp[k].max = Math.max(pp[k].max, +r.DIAS); });
    var dp = {};
    datosCD.proyectos.forEach(function (p) { var x = pp[p.id] || { n: 0, u: 0, max: 0 };
        dp[p.id] = { n: x.n, lineas: [(x.u ? '<b class="cd-mal">' + pl(x.u, 'urgente', 'urgentes') + '</b>' : 'ninguna urgente'), (x.n ? 'la más antigua: ' + pl(x.max, 'día', 'días') : '—')] }; });
    porProyecto('#cdRqProy', '#tbRq', 'por aprobar', dp);
    $('.cd-col-decidir').toggle(decide);
    for (var i = 0; i < f.length; i++) {
        var r = f[i], u = r.TIPO_RQ === 'U', id = parseInt(r.ID_RQ, 10);
        h += '<tr data-proy="' + parseInt(r.ID_PROYECTO, 10) + '" data-rq="' + id + '"><td><a href="RQVista.php?abrir=' + id + '" title="Abrir esta RQ en Requisiciones"><span class="cd-rq ' + (u ? 'cd-rq-u' : '') + '">' + (u ? 'RQ U-' : 'RQ-') + rdEsc(r.NUMERO_RQ) + '</span></a></td>' +
             '<td>' + rdEsc(r.NOM_CENTRO_OP) + '<br><small>' + rdEsc(r.NOM_PROYECTO) + '</small></td><td>' + rdEsc(r.SOLICITA || '') + '</td><td>' + rdEsc(r.ITEMS || '') + rdRqMinisFila(id, r.ARCHIVOS) +
             '<a href="#" class="btn-ver-rq cd-rq-ver rd-no-imprimir" data-id="' + id + '"><i class="fa fa-eye"></i> Ver detalle y fotos</a></td>' +
             '<td class="rd-num-col">' + parseInt(r.DIAS, 10) + '</td>' +
             (decide ? '<td class="rd-no-imprimir cd-decidir"><button type="button" class="btn btn-success btn-xs cd-aprobar" data-id="' + id + '"><i class="fa fa-check"></i> Aprobar</button> ' +
                       '<button type="button" class="btn btn-default btn-xs cd-rechazar" data-id="' + id + '"><i class="fa fa-times"></i> Rechazar</button>' +
                       '<div class="cd-motivo" hidden><textarea class="form-control input-sm" rows="2" maxlength="500" placeholder="¿Por qué se rechaza? (lo verá quien la pidió)"></textarea>' +
                       '<button type="button" class="btn btn-danger btn-xs cd-confirmar-rechazo" data-id="' + id + '">Confirmar rechazo</button></div></td>' : '') + '</tr>';
    }
    $('#tbRq').html(h || vacio(decide ? 6 : 5, 'No hay RQ esperando aprobación.'));
    aplicarFiltroProy('#tbRq');
}

//Aprobar o rechazar desde el reporte: usa la misma operación de la pantalla de RQ (mismas reglas, 18M)
function decidirRq(id, estado, obs, boton) {
    hanaBoton(boton, true);
    $.post('../Control/RQControl.php?op=cambiarEstado', { id: id, estado: estado, observacion: obs || '' }, null, 'json')
        .done(function (r) { rdAviso(r.mensaje || (estado === 3 ? 'RQ aprobada.' : 'RQ rechazada.')); cargar(datosCD.fecha); })
        .fail(function (xhr) { hanaBoton(boton, false); alert(rdError(xhr, 'No se pudo cambiar el estado de la RQ.')); });
}
//El detalle de una RQ para la ventana de confirmación: número, peaje, quién la pidió,
//los ítems con su cantidad y justificación, la observación y los soportes. Todo escapado
function detalleRqHtml(d, fila) {
    return rdRqDetalleHtml(d, fila); //el mismo detalle con fotos que usa el tablero (ReporteComun.js)
}
$(document).on('click', '.cd-aprobar', function () {
    var b = this, id = $(b).data('id');
    var fila = (datosCD.rq || []).filter(function (r) { return +r.ID_RQ === +id; })[0] || {};
    hanaBoton(b, true);
    //Se trae la RQ completa (ítems, justificación, soportes) para saber exactamente qué se aprueba
    $.getJSON('../Control/RQControl.php?op=mostrar&id=' + encodeURIComponent(id))
        .done(function (d) {
            hanaBoton(b, false);
            hanaConfirmar('¿Aprobar esta RQ? A quien la pidió le llega el aviso.', function () { decidirRq(id, 3, 'Aprobada desde el reporte general.', b); },
                          { aceptar: 'Sí, aprobar', cancelar: 'No', titulo: 'Aprobar RQ', detalleHtml: detalleRqHtml(d, fila) });
        })
        .fail(function (xhr) { hanaBoton(b, false); alert(rdError(xhr, 'No se pudo abrir la RQ.')); });
});
$(document).on('click', '.cd-rechazar', function () {
    var caja = $(this).siblings('.cd-motivo'); caja.prop('hidden', !caja.prop('hidden')); caja.find('textarea').focus();
});
$(document).on('click', '.cd-confirmar-rechazo', function () {
    var t = $.trim($(this).siblings('textarea').val());
    if (!t) { $(this).siblings('textarea').focus(); return; }
    decidirRq($(this).data('id'), 7, t, this);
});

//---------------------------------------------------------------------------
// El reporte de una persona (usa el mismo servicio del tablero, con su regla)
//---------------------------------------------------------------------------
function verPersona(id) {
    $('#modalPersonaCuerpo').html('<p class="rd-vacio">Cargando...</p>');
    $('#modalPersona').modal('show');
    $.getJSON('../Control/TableroControl.php?op=persona&id=' + encodeURIComponent(id) + '&fecha=' + encodeURIComponent(datosCD.fecha))
        .done(function (d) {
            $('#modalPersonaTitulo').text(d.persona.NOM_COLABORADOR + ' · ' + rdFecha(d.fecha));
            var r = d.hoy, h = '';
            if (!r) { h += '<p>' + tag('Sin reporte ese día', 'rd-tag-pend') + '</p>'; }
            else {
                h += '<p>' + tag(rdEsc(SIT_CD[r.SITUACION] || r.SITUACION), r.SITUACION === 'LABORAL' ? 'rd-tag-ok' : '') +
                     (r.HORA_INGRESO ? ' ' + rdHora(r.HORA_INGRESO) + ' – ' + (r.HORA_SALIDA ? rdHora(r.HORA_SALIDA) : '…') : '') + '</p>';
                var donde = (r.LUGARES || []).concat(r.LUGAR_OTRO ? [r.LUGAR_OTRO] : []);
                if (donde.length) { h += '<p><strong>Dónde:</strong> ' + rdEsc(donde.join(' · ')) + '</p>'; }
                if (r.ACTIVIDAD) { h += '<p><strong>Qué hizo:</strong><br>' + rdParrafo(r.ACTIVIDAD) + '</p>'; } //un solo texto para todo el día
                if (r.OBSERVACION) { h += '<p><strong>Observación:</strong> ' + rdEsc(r.OBSERVACION) + '</p>'; }
            }
            $('#modalPersonaCuerpo').html(h);
        })
        .fail(function (xhr) { $('#modalPersona').modal('hide'); alert(rdError(xhr, 'No se pudo abrir el reporte.')); });
}
