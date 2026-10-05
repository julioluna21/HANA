//Reporte diario — Tablero por proyectos
var URL_TAB = '../Control/TableroControl.php';
var datosTab = null;       //la última respuesta del servidor
var proyectoAbierto = 0;   //0 = viendo los mosaicos de proyectos

var SIT = { LABORAL: 'Laboral', DESCANSO: 'Descanso', INCAPACIDAD: 'Incapacidad', VACACIONES: 'Vacaciones', PERMISO: 'Permiso' };
var TIPO_ARQ = { CAJA_MENOR: 'Caja menor', RECAMBIO: 'Recambio', CASETA: 'Casetas' };
var TIPO_CENTRO = { PEAJE: 'Peaje', BASCULA: 'Báscula', BASE: 'Base', OFICINA: 'Oficina' };

$(function () {
    //Se puede llegar con ?proyecto=ID
    var p = parseInt(new URLSearchParams(window.location.search).get('proyecto'), 10);
    if (p > 0) { proyectoAbierto = p; }
    cargar('');

    $('#tbFecha').on('change', function () { if (this.value) { cargar(this.value); } });
    $('#btnDiaAnt').on('click', function () { cargar(rdSumarDias(datosTab.fecha, -1)); });
    $('#btnDiaSig').on('click', function () { if (datosTab.fecha < datosTab.hoy) { cargar(rdSumarDias(datosTab.fecha, 1)); } });
    $(document).on('click', '.tb-proyecto', function () { proyectoAbierto = $(this).data('id'); pintar(); window.scrollTo(0, 0); });
    $(document).on('click', '#volverProyectos', function (e) { e.preventDefault(); proyectoAbierto = 0; pintar(); });
    $(document).on('click', '[data-persona]', function () { verPersona($(this).data('persona')); });
    $(document).on('click', '.btn-ver-lista', function (e) { e.preventDefault(); rdVerLista($(this).data('id')); });
});

function cargar(fecha) {
    $.getJSON(URL_TAB + '?op=resumen' + (fecha ? '&fecha=' + encodeURIComponent(fecha) : ''))
        .done(function (d) {
            datosTab = d;
            $('#tbFecha').val(d.fecha).attr('max', d.hoy);
            $('#btnDiaSig').prop('disabled', d.fecha >= d.hoy);
            $('#tbAlcance').text(d.verTodos ? 'Ves todos los proyectos.' : 'Ves los proyectos y peajes a tu cargo.');
            pintar();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el tablero.'); });
}

function pintar() {
    var proy = null;
    for (var i = 0; i < datosTab.proyectos.length; i++) { if (+datosTab.proyectos[i].ID_PROYECTO === +proyectoAbierto) { proy = datosTab.proyectos[i]; } }
    if (proy) { pintarProyecto(proy); } else { proyectoAbierto = 0; pintarProyectos(); }
}

//Estado del día de una persona: "Laboral · 07:40 – 16:50", "Descanso" o "Sin reporte"
function estadoDia(sit, ingreso, salida) {
    if (!sit) { return '<span class="rd-tag rd-tag-pend"><i class="fa fa-clock-o"></i> Sin reporte</span>'; }
    var t = SIT[sit] || sit;
    if (sit === 'LABORAL' && ingreso) { t += ' · ' + rdHora(ingreso) + ' – ' + (salida ? rdHora(salida) : '…'); }
    return '<span class="rd-tag ' + (sit === 'LABORAL' ? 'rd-tag-ok' : '') + '"><i class="fa fa-check"></i> ' + rdEsc(t) + '</span>';
}

//"1 lista" / "3 listas"
function pl2(n, singular, plural) { return n + ' ' + (n === 1 ? singular : plural); }

//texto: "vacante|vacantes" (singular|plural)
function chip(icono, n, texto, clase) {
    var t = texto.split('|'), palabra = (n === 1 || t.length === 1) ? t[0] : t[1];
    return '<span class="tb-chip ' + (n > 0 ? (clase || '') : 'tb-chip-cero') + '"><i class="fa ' + icono + '"></i> ' + n + ' ' + rdEsc(palabra) + '</span>';
}

//---------------------------------------------------------------------------
// Nivel 1: proyectos
//---------------------------------------------------------------------------
function pintarProyectos() {
    $('#tituloTablero').text('Tablero del reporte diario');
    $('#migasTablero').html('<a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> Tablero');
    $('#nivelCentros').hide();
    var h = '';
    for (var i = 0; i < datosTab.proyectos.length; i++) {
        var p = datosTab.proyectos[i], r = p.RESUMEN;
        //El reporte del coordinador del día: Hoy en qué estás, listas y revisión del cronograma
        var partes = [!!p.COORD_SITUACION, +p.COORD_LISTAS > 0, +p.COORD_REVISO > 0];
        var hechas = partes.filter(function (x) { return x; }).length;
        var pct = p.COORDINADOR ? Math.round(100 * hechas / 3) : 0;
        var marca = function (ok, txt) { return '<span class="tb-parte ' + (ok ? 'tb-parte-ok' : '') + '"><i class="fa ' + (ok ? 'fa-check' : 'fa-clock-o') + '"></i> ' + txt + '</span>'; };
        h += '<div class="tb-proyecto" data-id="' + parseInt(p.ID_PROYECTO, 10) + '" role="button" tabindex="0">' +
             '<div class="tb-proy-nombre">' + rdEsc(p.NOM_PROYECTO) + '</div>' +
             '<div class="tb-proy-coord">' + (p.COORDINADOR ? '<i class="fa fa-user"></i> ' + rdEsc(p.COORDINADOR) + ' ' + estadoDia(p.COORD_SITUACION, p.COORD_INGRESO, null)
                                                              : '<span class="rd-ayuda" style="display:inline">Sin coordinador asignado</span>') + '</div>' +
             '<div class="tb-avance"><div class="tb-avance-txt">' + (p.COORDINADOR ? 'Reporte del coordinador: <strong>' + hechas + ' de 3</strong>' : 'Sin coordinador asignado') + '</div>' +
             '<div class="tb-barra"><div style="width:' + pct + '%"></div></div>' +
             (p.COORDINADOR ? '<div class="tb-partes">' + marca(partes[0], 'Hoy en qué estás') + marca(partes[1], pl2(+p.COORD_LISTAS, 'lista', 'listas')) +
                              marca(partes[2], 'Cronograma revisado') + '</div>' : '') + '</div>' +
             '<div class="tb-chips">' + chip('fa-user-plus', r.vacantes, 'vacante abierta|vacantes abiertas', 'tb-chip-pend') +
             chip('fa-inbox', +p.COMUNICACIONES, 'comunicación abierta|comunicaciones abiertas', 'tb-chip-pend') +
             chip('fa-wrench', r.rq, 'RQ por aprobar', 'tb-chip-pend') +
             chip('fa-money', r.arqueos, 'arqueo|arqueos', r.arqueosDif ? 'tb-chip-mal' : 'tb-chip-ok') + '</div></div>';
    }
    $('#nivelProyectos').html(h || '<p class="rd-vacio">No tienes proyectos a tu cargo. En Proyectos y en Centros de operación se asigna quién coordina y quién es jefe.</p>').show();
}

//---------------------------------------------------------------------------
// Nivel 2: centros de un proyecto
//---------------------------------------------------------------------------
function pintarProyecto(p) {
    $('#tituloTablero').text(p.NOM_PROYECTO);
    $('#migasTablero').html('<a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> ' +
                            '<a href="#" id="volverProyectos">Tablero</a> <span>›</span> ' + rdEsc(p.NOM_PROYECTO));
    $('#nivelProyectos').hide();

    //Solo se puede abrir el reporte de quien está por debajo en la jerarquía (o el propio)
    $('#tbCoordinador').html(p.COORDINADOR
        ? '<div class="tb-persona' + (p.COORD_PUEDE_VER ? '' : ' tb-sin-acceso') + '"' +
          (p.COORD_PUEDE_VER ? ' data-persona="' + parseInt(p.ID_COLABORADOR_COORDINADOR, 10) + '" role="button" tabindex="0"' : '') + '>' +
          '<span class="tb-rol">Coordinador del proyecto</span><strong>' + rdEsc(p.COORDINADOR) + '</strong> ' +
          estadoDia(p.COORD_SITUACION, p.COORD_INGRESO, null) +
          (p.COORD_PUEDE_VER ? '<span class="tb-ver">Ver reporte <i class="fa fa-chevron-right"></i></span>' : '') + '</div>'
        : '<p class="rd-ayuda">Este proyecto no tiene coordinador asignado. Se asigna en Configuración → Proyectos.</p>');

    var h = '';
    for (var i = 0; i < p.CENTROS.length; i++) {
        var c = p.CENTROS[i];
        h += '<div class="tb-centro">' +
             '<div class="tb-centro-cab"><strong>' + rdEsc(c.NOM_CENTRO_OP) + '</strong> <span class="tb-tipo">' + rdEsc(TIPO_CENTRO[c.TIPO_CENTRO] || c.TIPO_CENTRO) + '</span></div>' +
             (c.ID_COLABORADOR_JEFE
                ? '<div class="tb-persona tb-persona-sm' + (c.PUEDE_VER ? '' : ' tb-sin-acceso') + '"' +
                  (c.PUEDE_VER ? ' data-persona="' + parseInt(c.ID_COLABORADOR_JEFE, 10) + '" role="button" tabindex="0"' : '') + '>' +
                  '<span class="tb-rol">Jefe de peaje</span>' + rdEsc(c.JEFE) +
                  (+c.RQ_PENDIENTES > 0 ? '<br><span class="rd-tag rd-tag-pend">' + pl2(+c.RQ_PENDIENTES, 'RQ por aprobar', 'RQ por aprobar') + '</span>' : '') + '</div>'
                : '<div class="rd-ayuda" style="margin:6px 0 8px;">Sin jefe asignado</div>') +
             '<div class="tb-chips">' + chip('fa-check-square-o', +c.LISTAS, 'lista|listas', 'tb-chip-ok') +
             chip('fa-money', +c.ARQUEOS, 'arqueo|arqueos', +c.ARQUEOS_DIF ? 'tb-chip-mal' : 'tb-chip-ok') +
             chip('fa-user-plus', +c.VACANTES, 'vacante|vacantes', 'tb-chip-pend') + chip('fa-wrench', +c.RQ_PENDIENTES, 'RQ por aprobar', 'tb-chip-pend') + '</div></div>';
    }
    $('#tbCentros').html(h || '<p class="rd-vacio">Este proyecto no tiene centros activos que puedas ver.</p>');
    $('#nivelCentros').show();
}

//---------------------------------------------------------------------------
// Nivel 3: el reporte de una persona ese día
//---------------------------------------------------------------------------
function verPersona(id) {
    $('#modalPersonaCuerpo').html('<p class="rd-vacio">Cargando...</p>');
    $('#modalPersona').modal('show');
    $.getJSON(URL_TAB + '?op=persona&id=' + encodeURIComponent(id) + '&fecha=' + encodeURIComponent(datosTab.fecha))
        .done(function (d) {
            $('#modalPersonaTitulo').text(d.persona.NOM_COLABORADOR + ' · ' + rdFecha(d.fecha));
            var h = d.persona.CARGO ? '<p class="rd-ayuda" style="margin-top:0">' + rdEsc(d.persona.CARGO) + '</p>' : '';

            //Hoy en qué estás: solo el coordinador lo llena
            var r = d.hoy;
            if (d.esCoordinador) { h += '<h5 class="rd-subtitulo" style="margin-top:0">Hoy en qué estás</h5>'; }
            if (!d.esCoordinador) { /* el jefe de peaje no llena Hoy en qué estás */ }
            else if (!r) { h += '<p><span class="rd-tag rd-tag-pend">Sin reporte ese día</span></p>'; }
            else {
                h += '<p>' + estadoDia(r.SITUACION, r.HORA_INGRESO, r.HORA_SALIDA) + '</p>';
                var donde = (r.LUGARES || []).concat(r.LUGAR_OTRO ? [r.LUGAR_OTRO] : []);
                if (donde.length) { h += '<p><strong>Dónde:</strong> ' + rdEsc(donde.join(' · ')) + '</p>'; }
                var horas = Object.keys(r.HORAS || {}).map(Number).sort(function (a, b) { return a - b; });
                if (horas.length) {
                    h += '<table class="table rd-tabla rd-tabla-compacta"><tbody>';
                    for (var i = 0; i < horas.length; i++) {
                        h += '<tr><td class="rd-nowrap" style="width:110px;"><strong>' + rdDos(horas[i]) + ':00 – ' + rdDos(horas[i] + 1) + ':00</strong></td>' +
                             '<td>' + rdEsc(r.HORAS[horas[i]]) + '</td></tr>';
                    }
                    h += '</tbody></table>';
                }
                if (r.OBSERVACION) { h += '<p><strong>Observación:</strong> ' + rdEsc(r.OBSERVACION) + '</p>'; }
            }

            //Cronograma: del coordinador
            if (d.esCoordinador) { h += '<h5 class="rd-subtitulo">Cronograma del día</h5>'; }
            if (d.esCoordinador && !d.cronograma.length) { h += '<p class="rd-ayuda">Nada planeado.</p>'; }
            for (var k = 0; d.esCoordinador && k < d.cronograma.length; k++) { //el cronograma es del coordinador
                var it = d.cronograma[k];
                h += '<span class="crono-item crono-' + rdEsc(it.ESTADO_REAL) + '" style="display:inline-block;margin-right:6px;">' +
                     rdEsc(it.TIPO.charAt(0) + it.TIPO.slice(1).toLowerCase() + (it.NOM_CENTRO_OP ? ' ' + it.NOM_CENTRO_OP : '') + (it.DESCRIPCION ? ': ' + it.DESCRIPCION : '')) + '</span>';
            }

            //Listas de chequeo: las llena el coordinador
            if (d.esCoordinador) { h += '<h5 class="rd-subtitulo">Listas de chequeo diligenciadas</h5>'; }
            if (!d.esCoordinador) { /* el jefe de peaje no llena listas */ }
            else if (!d.listas.length) { h += '<p class="rd-ayuda">Ninguna ese día.</p>'; }
            else {
                h += '<ul class="tb-lista">';
                for (var l = 0; l < d.listas.length; l++) {
                    h += '<li>' + rdEsc(d.listas[l].LISTA) + ' · ' + rdEsc(d.listas[l].NOM_CENTRO_OP) + ' <small>' + rdHora(String(d.listas[l].FECHA).substring(11)) + '</small> ' +
                         '<a href="#" class="btn-ver-lista" data-id="' + parseInt(d.listas[l].ID_LISTA_CHEQUEO, 10) + '"><i class="fa fa-eye"></i> Ver respuestas</a></li>';
                }
                h += '</ul>';
            }

            //RQ que pidió: lo principal de un jefe de peaje
            h += '<h5 class="rd-subtitulo">RQ</h5>';
            if (!d.rq.length) { h += '<p class="rd-ayuda">Ninguna esperando aprobación ni pedida ese día.</p>'; }
            else {
                h += '<ul class="tb-lista">';
                for (var q2 = 0; q2 < d.rq.length; q2++) {
                    var rr = d.rq[q2];
                    h += '<li><span class="cd-rq ' + (rr.TIPO_RQ === 'U' ? 'cd-rq-u' : '') + '">' + (rr.TIPO_RQ === 'U' ? 'RQ U-' : 'RQ-') + rdEsc(rr.NUMERO_RQ) + '</span> ' +
                         rdEsc(rr.NOM_CENTRO_OP) + ' · ' + rdEsc(rr.ITEMS || '') + ' <small>' + rdEsc(rr.NOM_RQ_ESTADO) + '</small></li>';
                }
                h += '</ul>';
            }

            //Arqueos: del coordinador
            if (d.esCoordinador) { h += '<h5 class="rd-subtitulo">Arqueos</h5>'; }
            if (!d.esCoordinador) { /* un jefe de peaje no hace arqueos */ }
            else if (!d.arqueos.length) { h += '<p class="rd-ayuda">Ninguno ese día.</p>'; }
            else {
                h += '<ul class="tb-lista">';
                for (var a = 0; a < d.arqueos.length; a++) {
                    var q = d.arqueos[a], dif = parseFloat(q.DIFERENCIA);
                    h += '<li>' + rdEsc(TIPO_ARQ[q.TIPO] || q.TIPO) + ' · ' + rdEsc(q.NOM_CENTRO_OP) + ' <small>' + rdHora(q.HORA) + '</small> ' +
                         (dif === 0 ? '<span class="rd-tag rd-tag-ok">Cuadra</span>' : '<span class="rd-tag rd-tag-mal">' + (dif < 0 ? 'Faltante ' : 'Sobrante ') + rdPesos(Math.abs(dif)) + '</span>') +
                         ' <a href="ArqueoPdfVista.php?ver=1&id=' + parseInt(q.ID_ARQUEO, 10) + '" target="_blank" rel="noopener"><i class="fa fa-file-pdf-o"></i> PDF</a></li>';
                }
                h += '</ul>';
            }
            $('#modalPersonaCuerpo').html(h);
        })
        .fail(function (xhr) { $('#modalPersona').modal('hide'); alert(rdError(xhr, 'No se pudo abrir el reporte.')); });
}
