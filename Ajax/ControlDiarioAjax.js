//Reporte diario - Reporte general del día
//Pinta el resumen por módulo y una tabla por pestaña. Todo lo que viene de la
//base se escapa con rdEsc antes de pintarlo

var URL_CD = '../Control/ControlDiarioControl.php';
var datosCD = null;

var SIT_CD = { LABORAL: 'Laboral', DESCANSO: 'Descanso', INCAPACIDAD: 'Incapacidad', VACACIONES: 'Vacaciones', PERMISO: 'Permiso' };
var ARQ_CD = { CAJA_MENOR: 'Caja menor', RECAMBIO: 'Recambio', CASETA: 'Casetas' };
var VH_CD = { OPERATIVO: 'Operativo', INOPERATIVO: 'Inoperativo' };
var TR_CD = { BUS: 'Bus', TAXI: 'Taxi', APP: 'Aplicación (Didi, Uber…)', PARTICULAR: 'Vehículo particular', OTRO: 'Otro', NO_VIAJO: 'No se desplazó' };
var CRONO_CD = { PROGRAMADA: 'Programada', REALIZADA: 'Realizada', NO_REALIZADA: 'No realizada', CANCELADA: 'Cancelada', AUSENCIA: 'Ausencia' };
var PEND_CD = { COORDINADOR_OP: 'Coordinador operativo', DIRECCION: 'Dirección', COMUNICACIONES: 'Comunicaciones', GH: 'Gestión Humana', CONTABLE: 'Contable' };

//"1 no cuadra" / "3 no cuadran"
function cantidadTexto(cantidad, singular, plural) { return cantidad + ' ' + (cantidad === 1 ? singular : plural); }

//Etiqueta de color para los textos de las tablas
function tag(texto, clase) { return '<span class="rd-tag ' + (clase || '') + '">' + texto + '</span>'; }
//---------------------------------------------------------------------------
// Quién sí y quién no (los coordinadores, como en "Hoy en qué estás")
//   conteo: {idPersona: texto que va al lado del nombre}. Los que no están, faltan
//---------------------------------------------------------------------------
function quienSiNo(seleccion, titulo, conteo, etqSi, etqNo) {
    var conRegistro = [], sin = [];
    datosCD.hoyEnQue.forEach(function (persona) {
        if (conteo[persona.ID]) { conRegistro.push(rdEsc(persona.NOMBRE) + ' <small>(' + conteo[persona.ID] + ')</small>'); } else { sin.push(rdEsc(persona.NOMBRE)); }
    });
    $(seleccion).html(datosCD.hoyEnQue.length
        ? '<div class="cd-revision"><strong>' + titulo + ': ' + conRegistro.length + ' de ' + datosCD.hoyEnQue.length + '</strong>' +
          (sin.length ? '<div>' + tag(etqNo, 'rd-tag-pend') + ' ' + sin.join(', ') + '</div>' : '') +
          (conRegistro.length ? '<div>' + tag(etqSi, 'rd-tag-ok') + ' ' + conRegistro.join(', ') + '</div>' : '') + '</div>'
        : '');
}

//---------------------------------------------------------------------------
// Una tarjeta por proyecto (salen de Configuración → Proyectos: si se crea uno
// nuevo, aparece solo). Al tocar una, la tabla muestra solo ese proyecto
//   datosProy: {idProyecto: {n: número grande, lineas: [textos pequeños]}}
//---------------------------------------------------------------------------
var filtroProyTab = {};
//Arma la tabla resumen por proyecto de una pestaña
function porProyecto(seleccion, tbody, unidad, datosProy) {
    var fijo = +($('#cdProyecto').val() || 0), html = '';
    datosCD.proyectos.forEach(function (proyecto) {
        if (fijo && proyecto.id !== fijo) { return; }
        var datosProyecto = datosProy[proyecto.id] || { n: 0, lineas: [] };
        html += '<button type="button" class="cd-proy' + (filtroProyTab[tbody] === proyecto.id ? ' activo' : '') + (datosProyecto.n ? '' : ' cero') + '" data-proy="' + proyecto.id + '" data-tb="' + tbody + '">' +
             '<span class="cd-proy-nom">' + rdEsc(proyecto.nombre) + '</span><span class="cd-proy-n">' + datosProyecto.n + '</span><span class="cd-proy-u">' + unidad + '</span>' +
             datosProyecto.lineas.map(function (linea) { return '<span class="cd-proy-l">' + linea + '</span>'; }).join('') + '</button>';
    });
    $(seleccion).html(html ? '<div class="cd-proyectos">' + html + '</div>' + (filtroProyTab[tbody] ? '<p class="rd-ayuda" style="margin:2px 0 8px;">Mostrando solo un proyecto. Toca la tarjeta otra vez para ver todos.</p>' : '') : '');
    aplicarFiltroProy(tbody);
}
//Muestra solo las filas del proyecto en que se hizo clic
function aplicarFiltroProy(tbody) {
    var idProyectoFiltro = filtroProyTab[tbody];
    $(tbody + ' tr[data-proy]').each(function () { $(this).toggle(!idProyectoFiltro || +$(this).data('proy') === idProyectoFiltro); });
}
$(document).on('click', '.cd-proy', function () {
    var tabla = $(this).data('tb'), idProyecto = +$(this).data('proy');
    filtroProyTab[tabla] = filtroProyTab[tabla] === idProyecto ? 0 : idProyecto;
    $('.cd-proy[data-tb="' + tabla + '"]').each(function () { $(this).toggleClass('activo', +$(this).data('proy') === filtroProyTab[tabla]); });
    aplicarFiltroProy(tabla);
});

//Fila de "no hay datos" que ocupa todas las columnas
function vacio(columnas, texto) { return '<tr><td colspan="' + columnas + '" class="rd-vacio">' + texto + '</td></tr>'; }

//Qué pestaña abre cada valor de ?ver=
var VER_TAB = { hoy: 'tabHoy', listas: 'tabListas', arqueos: 'tabArqueos', cronograma: 'tabCrono', vehiculos: 'tabVh', vacantes: 'tabVac', comunicaciones: 'tabCom', rq: 'tabRq',
                ausentismo: 'tabAus', promesa: 'tabAus' };

$(function () {
    //Se puede llegar con ?fecha=AAAA-MM-DD&proyecto=ID&ver=rq (desde el tablero): abre ese día, ese proyecto y esa pestaña
    var parametrosUrl = new URLSearchParams(window.location.search);
    var fechaUrl = /^\d{4}-\d{2}-\d{2}$/.test(parametrosUrl.get('fecha') || '') ? parametrosUrl.get('fecha') : '';
    var proyUrl = parseInt(parametrosUrl.get('proyecto'), 10) || 0;
    cargar(fechaUrl, proyUrl);
    if (VER_TAB[parametrosUrl.get('ver')]) {
        $('#cdPestanas a[href="#' + VER_TAB[parametrosUrl.get('ver')] + '"]').tab('show');
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

//Trae todo lo del día elegido (y del proyecto, si se filtró) y pinta cada pestaña
function cargar(fecha, proyecto) {
    var idProyecto = proyecto !== undefined ? proyecto : ($('#cdProyecto').val() || 0);
    $.getJSON(URL_CD + '?op=dia' + (fecha ? '&fecha=' + encodeURIComponent(fecha) : '') + '&proyecto=' + encodeURIComponent(idProyecto))
        .done(function (respuesta) {
            datosCD = respuesta;
            $('#cdFecha').val(respuesta.fecha).attr('max', respuesta.ultimo);
            $('#btnDiaSig').prop('disabled', respuesta.fecha >= respuesta.ultimo);
            $('#btnHoy').toggle(respuesta.fecha !== respuesta.hoy);

            //El filtro de proyectos se llena una vez y conserva lo elegido
            if ($('#cdProyecto option').length <= 1) {
                var html = '<option value="0">Todos los proyectos</option>';
                for (var i = 0; i < respuesta.proyectos.length; i++) { html += '<option value="' + respuesta.proyectos[i].id + '">' + rdEsc(respuesta.proyectos[i].nombre) + '</option>'; }
                $('#cdProyecto').html(html).val(String(idProyecto));
            }
            $('#cdAlcance').text(respuesta.verTodos ? 'Ves lo de todos los proyectos.' : 'Ves lo de los proyectos y peajes a tu cargo.');
            $('#cdTituloImprimir').text('Reporte general · ' + rdFecha(respuesta.fecha) + ($('#cdProyecto').val() !== '0' ? ' · ' + $('#cdProyecto option:selected').text() : ''));

            pintarResumen();
            pintarHoy(); pintarListas(); pintarArqueos(); pintarCrono(); pintarVh(); pintarVac(); pintarCom(); pintarRq(); pintarAus();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el reporte general.'); });
}

//---------------------------------------------------------------------------
// Resumen
//---------------------------------------------------------------------------
function pintarResumen() {
    var datos = datosCD;
    var reportaron = datos.hoyEnQue.filter(function (persona) { return persona.REGISTRO; }).length, total = datos.hoyEnQue.length;
    var incompletas = datos.listas.filter(function (lista) { return +lista.RESPUESTAS < +lista.PREGUNTAS; }).length;
    var arqDif = datos.arqueos.filter(function (arqueo) { return parseFloat(arqueo.DIFERENCIA) !== 0; }).length;
    var visitas = datos.cronograma.filter(function (visita) { return visita.ESTADO_REAL !== 'AUSENCIA' && visita.ESTADO_REAL !== 'CANCELADA'; });
    var hechas = visitas.filter(function (visita) { return visita.ESTADO_REAL === 'REALIZADA'; }).length;
    var noHechas = visitas.filter(function (visita) { return visita.ESTADO_REAL === 'NO_REALIZADA'; }).length;
    var vhReg = datos.vehiculos.filter(function (vehiculo) { return vehiculo.ESTADO_VH; }).length;
    var vhMal = datos.vehiculos.filter(function (vehiculo) { return vehiculo.ESTADO_VH && vehiculo.ESTADO_VH !== 'OPERATIVO'; }).length;
    var vacFuera = datos.vacantes.filter(function (vacante) { return vacante.RESULTADO_ANS === 'VENCIDA'; }).length;
    var comFuera = datos.comunicaciones.filter(function (oficio) { return oficio.RESULTADO === 'VENCIDA'; }).length;
    var rqUrgentes = datos.rq.filter(function (rq) { return rq.TIPO_RQ === 'U'; }).length;
    var sinRevisar = datos.hoyEnQue.filter(function (persona) { return !datos.revisionCrono[persona.ID]; }).length;
    //Las listas las llena el coordinador: quién registró al menos una ese día
    var conListaIds = {};
    datos.listas.forEach(function (lista) { conListaIds[lista.ID_PERSONA] = true; });
    var coordConLista = datos.hoyEnQue.filter(function (persona) { return conListaIds[persona.ID]; }).length, coordSinLista = total - coordConLista;

    //Estado de cada tarjeta: ok (verde), pend (ámbar) o mal (vino)
    var tarjetas = [
        ['tabHoy', 'fa-map-marker', 'Hoy en qué estás', total ? reportaron + ' de ' + total : '0', total ? (total === 1 ? 'coordinador reportó' : 'coordinadores reportaron') : 'no hay coordinadores asignados',
         !total ? '' : (reportaron === total ? 'ok' : 'pend'), total - reportaron > 0 ? cantidadTexto(total - reportaron, 'falta', 'faltan') : ''],
        ['tabListas', 'fa-check-square-o', 'Listas de chequeo', total ? coordConLista + ' de ' + total : datos.listas.length,
         total ? 'coordinadores registraron listas · ' + cantidadTexto(datos.listas.length, 'lista', 'listas') : 'diligenciadas',
         coordSinLista ? 'pend' : (incompletas ? 'pend' : 'ok'),
         coordSinLista ? cantidadTexto(coordSinLista, 'coordinador sin listas', 'coordinadores sin listas') : (incompletas ? cantidadTexto(incompletas, 'incompleta', 'incompletas') : '')],
        ['tabArqueos', 'fa-money', 'Arqueos', datos.arqueos.length, 'registrados', arqDif ? 'mal' : (datos.arqueos.length ? 'ok' : 'pend'), arqDif ? cantidadTexto(arqDif, 'no cuadra', 'no cuadran') : ''],
        ['tabCrono', 'fa-calendar', 'Cronograma', visitas.length ? hechas + ' de ' + visitas.length : '0', 'actividades realizadas',
         noHechas ? 'mal' : (sinRevisar ? 'pend' : (visitas.length && hechas === visitas.length ? 'ok' : '')),
         noHechas ? cantidadTexto(noHechas, 'no realizada', 'no realizadas') : (sinRevisar ? cantidadTexto(sinRevisar, 'no lo ha revisado', 'no lo han revisado') : '')],
        ['tabVh', 'fa-car', 'Vehículos', datos.vehiculos.length ? vhReg + ' de ' + datos.vehiculos.length : '0', 'con estado del día',
         vhMal ? 'mal' : (datos.vehiculos.length && vhReg === datos.vehiculos.length ? 'ok' : (datos.vehiculos.length ? 'pend' : '')), vhMal ? cantidadTexto(vhMal, 'no operativo', 'no operativos') : ''],
        ['tabVac', 'fa-user-plus', 'Vacantes abiertas', datos.vacantes.length, 'por cubrir', vacFuera ? 'mal' : (datos.vacantes.length ? 'pend' : 'ok'), vacFuera ? cantidadTexto(vacFuera, 'fuera del acuerdo', 'fuera del acuerdo') : ''],
        ['tabCom', 'fa-inbox', 'Comunicaciones', datos.comunicaciones.length, 'abiertas', comFuera ? 'mal' : (datos.comunicaciones.length ? 'pend' : 'ok'), comFuera ? cantidadTexto(comFuera, 'fuera de tiempo', 'fuera de tiempo') : ''],
        ['tabRq', 'fa-wrench', 'RQ por aprobar', datos.rq.length, 'esperando', rqUrgentes ? 'mal' : (datos.rq.length ? 'pend' : 'ok'), rqUrgentes ? cantidadTexto(rqUrgentes, 'urgente', 'urgentes') : '']
    ];
    //Ausentismo (con la promesa de valor adentro), si el módulo está encendido
    if (datos.modAusentismo || datos.modPromesa) {
        var resumenAusencias = resumenAus();
        tarjetas.push(['tabAus', 'fa-user-times', 'Ausentismo', resumenAusencias.aus, resumenAusencias.aus === 1 ? 'ausencia en el día' : 'ausencias en el día',
                resumenAusencias.prom ? (resumenAusencias.sinExplicar > 0 ? 'mal' : (resumenAusencias.faltan ? 'pend' : 'ok')) : '',
                resumenAusencias.prom ? (resumenAusencias.faltan ? cantidadTexto(resumenAusencias.faltan, 'peaje sin ejecutado', 'peajes sin ejecutado') : 'Promesa ' + resumenAusencias.prom + ' · ejecutado ' + resumenAusencias.eje) : '']);
    }
    var html = '';
    for (var i = 0; i < tarjetas.length; i++) {
        html += '<div class="cd-tarjeta cd-' + (tarjetas[i][5] || 'neutro') + '" data-tab="' + tarjetas[i][0] + '" role="button" tabindex="0">' +
             '<div class="cd-t-cab"><i class="fa ' + tarjetas[i][1] + '"></i> ' + tarjetas[i][2] + '</div>' +
             '<div class="cd-t-num">' + tarjetas[i][3] + '</div><div class="cd-t-txt">' + tarjetas[i][4] + '</div>' +
             (tarjetas[i][6] ? '<div class="cd-t-alerta">' + tarjetas[i][6] + '</div>' : '') + '</div>';
    }
    $('#cdResumen').html(html);

    //Las pestañas no llevan números: el resumen de arriba ya los muestra
}

//---------------------------------------------------------------------------
// Pestañas
//---------------------------------------------------------------------------
function pintarHoy() {
    var filtroHoy = $('input[name="fHoy"]:checked').val(), html = '';
    //Primero los que faltan: es lo que hay que perseguir
    var filas = datosCD.hoyEnQue.slice().sort(function (primera, segunda) { return (primera.REGISTRO ? 1 : 0) - (segunda.REGISTRO ? 1 : 0); });
    for (var i = 0; i < filas.length; i++) {
        var persona = filas[i], registro = persona.REGISTRO;
        if ((filtroHoy === 'faltan' && registro) || (filtroHoy === 'reportaron' && !registro)) { continue; }
        var quien = '<strong>' + rdEsc(persona.NOMBRE) + '</strong><br><small class="rd-ayuda" style="display:inline">' + rdEsc(persona.ROLES.join(' · ')) + '</small>';
        if (!registro) {
            html += '<tr class="cd-falta"><td>' + quien + '</td><td>' + tag('<i class="fa fa-clock-o"></i> Sin reporte', 'rd-tag-pend') + '</td><td colspan="4"></td>' +
                 '<td class="rd-no-imprimir"></td></tr>';
            continue;
        }
        var esLaboral = registro.SITUACION === 'LABORAL';
        var donde = [registro.LUGARES, registro.LUGAR_OTRO].filter(function (lugar) { return lugar; }).join(' · ');
        html += '<tr><td>' + quien + '</td>' +
             '<td>' + tag(rdEsc(SIT_CD[registro.SITUACION] || registro.SITUACION), esLaboral ? 'rd-tag-ok' : '') + '</td>' +
             '<td>' + rdEsc(esLaboral ? donde : '') + (registro.OBSERVACION ? '<br><small>' + rdEsc(registro.OBSERVACION) + '</small>' : '') + '</td>' +
             '<td class="rd-nowrap">' + (esLaboral ? rdHora(registro.HORA_INGRESO) + ' – ' + (registro.HORA_SALIDA ? rdHora(registro.HORA_SALIDA) : '…') : '') + '</td>' +
             '<td><small>' + (esLaboral ? rdRecortar(registro.ACTIVIDAD, 90) : '') + '</small></td>' +
             '<td class="rd-nowrap"><small>' + rdHora(String(registro.FEC_MODIFICACION || registro.FEC_REGISTRO).substring(11)) + '</small></td>' +
             '<td class="rd-no-imprimir"><button type="button" class="btn btn-success btn-xs btn-persona-cd" data-id="' + parseInt(persona.ID, 10) + '"><i class="fa fa-eye"></i> Ver</button></td></tr>';
    }
    $('#tbHoy').html(html || vacio(7, datosCD.hoyEnQue.length ? 'Nadie en este filtro.' : 'No hay coordinadores asignados en lo que ves. Se asignan en Configuración → Proyectos.'));
}

//Pestaña de listas de chequeo: quién llenó listas ese día y cuántas
function pintarListas() {
    //Las listas las llenan los coordinadores: quién registró ese día y cuántas
    var cuantas = {};
    datosCD.listas.forEach(function (listaPersona) { cuantas[listaPersona.ID_PERSONA] = (cuantas[listaPersona.ID_PERSONA] || 0) + 1; });
    Object.keys(cuantas).forEach(function (idPersona) { cuantas[idPersona] = cantidadTexto(cuantas[idPersona], 'lista', 'listas'); });
    quienSiNo('#cdCentrosListas', 'Coordinadores que registraron listas', cuantas, 'Con listas', 'Sin listas');

    var html = '', filas = datosCD.listas;
    for (var i = 0; i < filas.length; i++) {
        var lista = filas[i], completa = +lista.RESPUESTAS >= +lista.PREGUNTAS;
        html += '<tr><td class="rd-nowrap">' + rdHora(String(lista.FECHA).substring(11)) + '</td><td>' + rdEsc(lista.LISTA) + '</td><td>' + rdEsc(lista.NOM_CENTRO_OP) + '</td>' +
             '<td>' + rdEsc(lista.NOM_PROYECTO) + '</td><td>' + rdEsc(lista.PERSONA || '') + '</td>' +
             '<td>' + tag(parseInt(lista.RESPUESTAS, 10) + ' de ' + parseInt(lista.PREGUNTAS, 10), completa ? 'rd-tag-ok' : 'rd-tag-pend') + '</td>' +
             '<td class="rd-no-imprimir"><button type="button" class="btn btn-success btn-xs btn-ver-lista" data-id="' + parseInt(lista.ID_LISTA_CHEQUEO, 10) + '">' +
             '<i class="fa fa-eye"></i> Ver respuestas</button></td></tr>';
    }
    $('#tbListas').html(html || vacio(7, 'Ninguna lista diligenciada este día.'));
}

//Pestaña de arqueos: quién hizo arqueos ese día y el detalle de cada uno
function pintarArqueos() {
    var cuantos = {};
    datosCD.arqueos.forEach(function (arqueoPersona) { if (arqueoPersona.ID_PERSONA) { cuantos[arqueoPersona.ID_PERSONA] = (cuantos[arqueoPersona.ID_PERSONA] || 0) + 1; } });
    Object.keys(cuantos).forEach(function (idPersona) { cuantos[idPersona] = cantidadTexto(cuantos[idPersona], 'arqueo', 'arqueos'); });
    quienSiNo('#cdArqueosQuien', 'Coordinadores que hicieron arqueos', cuantos, 'Con arqueo', 'Sin arqueo');
    var html = '', filas = datosCD.arqueos;
    for (var i = 0; i < filas.length; i++) {
        var arqueo = filas[i], diferencia = parseFloat(arqueo.DIFERENCIA);
        html += '<tr><td class="rd-nowrap">' + rdHora(arqueo.HORA) + '</td><td>' + rdEsc(ARQ_CD[arqueo.TIPO] || arqueo.TIPO) + '</td><td>' + rdEsc(arqueo.NOM_CENTRO_OP) + '</td>' +
             '<td>' + rdEsc(arqueo.RESPONSABLE) + '</td><td>' + rdEsc(arqueo.ARQUEA || '') + '</td>' +
             '<td class="rd-num-col">' + rdPesos(arqueo.TOTAL_ARQUEO) + '</td><td class="rd-num-col">' + rdPesos(arqueo.FONDO_AUTORIZADO) + '</td>' +
             '<td>' + (diferencia === 0 ? tag('Cuadra', 'rd-tag-ok') : tag((diferencia < 0 ? 'Faltante ' : 'Sobrante ') + rdPesos(Math.abs(diferencia)), 'rd-tag-mal')) +
             (diferencia !== 0 && arqueo.OBSERVACION ? '<br><small>' + rdEsc(arqueo.OBSERVACION) + '</small>' : '') + '</td>' +
             '<td class="rd-no-imprimir"><a class="btn btn-default btn-xs" target="_blank" rel="noopener" href="ArqueoPdfVista.php?ver=1&id=' + parseInt(arqueo.ID_ARQUEO, 10) + '"><i class="fa fa-file-pdf-o"></i> PDF</a></td></tr>';
    }
    $('#tbArqueos').html(html || vacio(9, 'Ningún arqueo este día.'));
}

//Pestaña de cronograma: quién lo revisó y qué tenía programado
function pintarCrono() {
    //Quién revisó el cronograma ese día (con la hora)
    var revisadas = {};
    Object.keys(datosCD.revisionCrono || {}).forEach(function (idPersona) { revisadas[idPersona] = 'a las ' + rdHora(String(datosCD.revisionCrono[idPersona]).substring(11)); });
    quienSiNo('#cdRevision', 'Revisaron el cronograma', revisadas, 'Revisado', 'Sin revisar');

    var html = '', filas = datosCD.cronograma;
    var clase = { REALIZADA: 'rd-tag-ok', NO_REALIZADA: 'rd-tag-mal', PROGRAMADA: 'rd-tag-pend', CANCELADA: '', AUSENCIA: '' };
    for (var i = 0; i < filas.length; i++) {
        var visita = filas[i];
        var actividad = visita.TIPO.charAt(0) + visita.TIPO.slice(1).toLowerCase() + (visita.NOM_CENTRO_OP ? ' ' + visita.NOM_CENTRO_OP : '') + (visita.DESCRIPCION ? ': ' + visita.DESCRIPCION : '');
        html += '<tr><td>' + rdEsc(visita.PERSONA) + '</td><td>' + rdEsc(actividad) + '</td>' +
             '<td>' + tag(CRONO_CD[visita.ESTADO_REAL] + (parseInt(visita.CONFIRMADA_HOY, 10) ? ' <small>(Hoy en qué estás)</small>' : ''), clase[visita.ESTADO_REAL]) + '</td>' +
             '<td>' + rdEsc(visita.OBSERVACION || '') + '</td></tr>';
    }
    $('#tbCrono').html(html || vacio(4, 'Nada planeado para este día.'));
}

//Pestaña de vehículos: el estado de cada vehículo ese día
function pintarVh() {
    var html = '', filas = datosCD.vehiculos;
    var clase = { OPERATIVO: 'rd-tag-ok', INOPERATIVO: 'rd-tag-mal' };
    for (var i = 0; i < filas.length; i++) {
        var vehiculo = filas[i];
        html += '<tr><td><strong>' + rdEsc(vehiculo.PLACA) + '</strong>' + (vehiculo.DESCRIPCION ? ' <small>' + rdEsc(vehiculo.DESCRIPCION) + '</small>' : '') + '</td>' +
             '<td>' + rdEsc(vehiculo.RESPONSABLE || 'Sin asignar') + '</td>' +
             '<td>' + (vehiculo.ESTADO_VH ? tag(rdEsc(VH_CD[vehiculo.ESTADO_VH]), clase[vehiculo.ESTADO_VH]) : tag('Sin registro', 'rd-tag-pend')) +
                 (vehiculo.TRANSPORTE ? '<br><small>Se transportó en: ' + rdEsc(TR_CD[vehiculo.TRANSPORTE] || vehiculo.TRANSPORTE) + '</small>' : '') + '</td>' +
             '<td>' + rdEsc(vehiculo.OBSERVACION || '') + '</td></tr>';
    }
    $('#tbVh').html(html || vacio(4, 'No hay vehículos registrados en lo que ves.'));
}

//Pestaña de vacantes: las abiertas por proyecto y cuántas van fuera del ANS
function pintarVac() {
    var html = '', filas = datosCD.vacantes, totalesProyecto = {}, ausencias = datosCD.ausentismoDia || {};
    filas.forEach(function (vacanteFila) { var idProyecto = +vacanteFila.ID_PROYECTO; totalesProyecto[idProyecto] = totalesProyecto[idProyecto] || { n: 0, fuera: 0 }; totalesProyecto[idProyecto].n++; if (vacanteFila.RESULTADO_ANS === 'VENCIDA') { totalesProyecto[idProyecto].fuera++; } });
    var datosProyecto = {};
    datosCD.proyectos.forEach(function (proyecto) { var totales = totalesProyecto[proyecto.id] || { n: 0, fuera: 0 };
        datosProyecto[proyecto.id] = { n: totales.n, lineas: [(totales.fuera ? '<b class="cd-mal">' + cantidadTexto(totales.fuera, 'fuera del acuerdo', 'fuera del acuerdo') + '</b>' : 'todas en tiempo'), 'Ausencias del día: <b>' + (ausencias[proyecto.id] || 0) + '</b>'] }; });
    porProyecto('#cdVacProy', '#tbVac', 'vacantes abiertas', datosProyecto);
    for (var i = 0; i < filas.length; i++) {
        var vacante = filas[i];
        html += '<tr data-proy="' + parseInt(vacante.ID_PROYECTO, 10) + '"><td>' + rdEsc(vacante.NOM_CENTRO_OP) + '</td><td>' + rdEsc(vacante.CARGO) + '</td><td>' + rdFecha(vacante.FECHA_VACANTE) + '</td><td>' + rdFecha(vacante.FECHA_ANS) + '</td>' +
             '<td class="rd-num-col">' + vacante.DIAS_A_HOY + '</td>' +
             '<td>' + (vacante.RESULTADO_ANS === 'VENCIDA' ? tag('Fuera del acuerdo', 'rd-tag-mal') : tag('En tiempo', 'rd-tag-pend')) + '</td>' +
             '<td>' + rdEsc((vacante.ESTADO_GH || '') + (vacante.FECHA_COMPROMISO_GH ? ' ' + rdFecha(vacante.FECHA_COMPROMISO_GH) : '')) + '</td></tr>';
    }
    $('#tbVac').html(html || vacio(7, 'No hay vacantes abiertas.'));
    aplicarFiltroProy('#tbVac');
}

//Pestaña de comunicaciones: las abiertas por proyecto y cuántas van vencidas
function pintarCom() {
    var html = '', filas = datosCD.comunicaciones, totalesProyecto = {};
    filas.forEach(function (oficioFila) { var idProyecto = +oficioFila.ID_PROYECTO; totalesProyecto[idProyecto] = totalesProyecto[idProyecto] || { n: 0, fuera: 0, env: 0 }; totalesProyecto[idProyecto].n++; if (oficioFila.RESULTADO === 'VENCIDA') { totalesProyecto[idProyecto].fuera++; } if (oficioFila.TIPO === 'POR_ENVIAR') { totalesProyecto[idProyecto].env++; } });
    var datosProyecto = {};
    datosCD.proyectos.forEach(function (proyecto) { var totales = totalesProyecto[proyecto.id] || { n: 0, fuera: 0, env: 0 };
        datosProyecto[proyecto.id] = { n: totales.n, lineas: [(totales.n - totales.env) + ' por atender · ' + totales.env + ' por enviar', (totales.fuera ? '<b class="cd-mal">' + totales.fuera + ' fuera de tiempo</b>' : 'todas en tiempo')] }; });
    porProyecto('#cdComProy', '#tbCom', 'abiertas', datosProyecto);
    for (var i = 0; i < filas.length; i++) {
        var oficio = filas[i];
        html += '<tr data-proy="' + parseInt(oficio.ID_PROYECTO, 10) + '"><td>' + (oficio.TIPO === 'POR_ENVIAR' ? 'Por enviar' : 'Por atender') + '</td><td>' + rdFecha(oficio.FECHA_RECEPCION) + '</td>' +
             '<td>' + rdEsc(oficio.REMITENTE) + '</td><td>' + rdEsc(oficio.ASUNTO) + '</td><td>' + rdEsc(oficio.REGISTRO || '') + '</td>' +
             '<td class="rd-num-col">' + oficio.DIAS_ABIERTA + '</td>' +
             '<td>' + (oficio.RESULTADO === 'VENCIDA' ? tag('Fuera de tiempo', 'rd-tag-mal') : tag('En tiempo', 'rd-tag-pend')) + '</td>' +
             '<td>' + rdEsc(PEND_CD[oficio.PENDIENTE_DE] || '') + '</td></tr>';
    }
    $('#tbCom').html(html || vacio(8, 'No hay comunicaciones abiertas.'));
    aplicarFiltroProy('#tbCom');
}

//---------------------------------------------------------------------------
// Ausentismo del día, con la promesa de valor adentro
//---------------------------------------------------------------------------
//Las cuentas por peaje: ausencias (las que cuentan), promesa y ejecutado.
//Devuelve los totales y [idCentro] = {aus, prom, eje, conEje}
function resumenAus() {
    var datos = datosCD, porCentro = {}, total = { aus: 0, prom: 0, eje: 0, faltan: 0, sinExplicar: 0, centros: porCentro };
    (datos.ausentismo || []).forEach(function (ausencia) {
        var idCentro = +ausencia.ID_CENTRO_OP; porCentro[idCentro] = porCentro[idCentro] || { aus: 0, prom: 0, eje: 0, conEje: false, porCargo: {} };
        if (+ausencia.CUENTA) { porCentro[idCentro].aus += +ausencia.CANTIDAD; total.aus += +ausencia.CANTIDAD; porCentro[idCentro].porCargo[+ausencia.ID_CARGO_AUS] = (porCentro[idCentro].porCargo[+ausencia.ID_CARGO_AUS] || 0) + +ausencia.CANTIDAD; }
    });
    $.each(datos.promesa || {}, function (idCentroTexto, cargos) {
        var idCentro = +idCentroTexto; porCentro[idCentro] = porCentro[idCentro] || { aus: 0, prom: 0, eje: 0, conEje: false, porCargo: {} };
        $.each(cargos, function (idCa, cifras) {
            porCentro[idCentro].prom += +cifras.promesa;
            if (cifras.ejecutado !== null) { porCentro[idCentro].eje += +cifras.ejecutado; porCentro[idCentro].conEje = true; }
        });
    });
    $.each(porCentro, function (idCentro, centro) {
        if (!centro.prom) { return; }
        total.prom += centro.prom;
        if (!centro.conEje) { total.faltan++; return; }
        total.eje += centro.eje;
        if (centro.prom - centro.eje > centro.aus) { total.sinExplicar++; }                    //faltó gente que no está en el ausentismo
    });
    return total;
}

//Muestra una diferencia con su signo (+ o −) y en rojo si es negativa
function difCD(diferencia) { return '<span class="' + (diferencia < 0 ? 'cd-mal' : '') + '">' + (diferencia > 0 ? '+' : (diferencia < 0 ? '−' : '')) + Math.abs(diferencia) + '</span>'; }

//Pestaña de ausentismo: el resumen del día por peaje (solo si el módulo está activo)
function pintarAus() {
    var datos = datosCD, conAus = !!(datos.modAusentismo || datos.modPromesa);
    //Se muestra u oculta solo la pestaña: el contenido lo muestra Bootstrap cuando la pestaña está elegida
    $('#cdPestanaAus').toggle(conAus);
    if (!conAus) {
        if ($('#tabAus').hasClass('active')) { $('#cdPestanas a[href="#tabHoy"]').tab('show'); }
        return;
    }
    var resumen = resumenAus(), centros = {}, html = '';
    (datos.centros || []).forEach(function (centro) { centros[centro.ID_CENTRO_OP] = centro; });

    //Una tarjeta por proyecto: ausencias y cómo va la promesa
    var totalesProyecto = {};
    $.each(resumen.centros, function (idCentro, totalesCentro) {
        var centro = centros[idCentro]; if (!centro) { return; }
        var idProyecto = +centro.ID_PROYECTO; totalesProyecto[idProyecto] = totalesProyecto[idProyecto] || { aus: 0, prom: 0, eje: 0, faltan: 0 };
        totalesProyecto[idProyecto].aus += totalesCentro.aus; totalesProyecto[idProyecto].prom += totalesCentro.prom; if (totalesCentro.conEje) { totalesProyecto[idProyecto].eje += totalesCentro.eje; } else if (totalesCentro.prom) { totalesProyecto[idProyecto].faltan++; }
    });
    var datosProyecto = {};
    datos.proyectos.forEach(function (proyecto) {
        var totales = totalesProyecto[proyecto.id] || { aus: 0, prom: 0, eje: 0, faltan: 0 };
        datosProyecto[proyecto.id] = { n: totales.aus, lineas: datos.modPromesa && totales.prom ? ['Promesa ' + totales.prom + ' · ejecutado ' + totales.eje, totales.faltan ? '<b class="cd-mal">' + cantidadTexto(totales.faltan, 'peaje sin ejecutado', 'peajes sin ejecutado') + '</b>' : 'todos con ejecutado'] : ['sin promesa de valor'] };
    });
    porProyecto('#cdAusProy', '#tbAus', 'ausencias', datosProyecto);

    //Las ausencias del día
    (datos.ausentismo || []).forEach(function (ausencia) {
        html += '<tr data-proy="' + parseInt(ausencia.ID_PROYECTO, 10) + '"' + (+ausencia.CUENTA ? '' : ' class="aus-no-cuenta"') + '><td>' + (ausencia.TIPO_CENTRO === 'BASCULA' ? 'Báscula ' : '') + rdEsc(ausencia.NOM_CENTRO_OP) + '</td>' +
             '<td>' + rdEsc(ausencia.NOM_PROYECTO) + '</td><td>' + rdEsc(ausencia.CARGO) + '</td><td>' + rdEsc(ausencia.MOTIVO) + (+ausencia.CUENTA ? '' : ' <small>(no cuenta como ausencia)</small>') + '</td>' +
             '<td class="rd-num-col"><strong>' + parseInt(ausencia.CANTIDAD, 10) + '</strong></td><td>' + rdEsc(ausencia.QUIEN || '') + '</td></tr>';
    });
    $('#tbAus').html(datos.modAusentismo ? (html || vacio(6, 'No hay ausencias registradas ese día.')) : vacio(6, 'El módulo de ausentismo está apagado.'));
    aplicarFiltroProy('#tbAus');

    //La promesa de valor: por peaje y por cargo
    $('#cdPromesaAus').toggle(!!datos.modPromesa);
    if (!datos.modPromesa) { return; }
    var htmlProyectos = '', porCargo = {}, nombres = {};
    (datos.cargosAus || []).forEach(function (cargo) { nombres[cargo.id] = cargo.nombre; });
    (datos.centros || []).forEach(function (centro) {
        var totalesCentro = resumen.centros[centro.ID_CENTRO_OP];
        if (!totalesCentro || !totalesCentro.prom) { return; }
        var estado = !totalesCentro.conEje ? tag('Falta el ejecutado', 'rd-tag-pend') : (totalesCentro.prom - totalesCentro.eje > totalesCentro.aus ? tag('Faltan ' + (totalesCentro.prom - totalesCentro.eje - totalesCentro.aus) + ' por explicar', 'rd-tag-mal') : tag('Registrado', 'rd-tag-ok'));
        htmlProyectos += '<tr data-proy="' + parseInt(centro.ID_PROYECTO, 10) + '"><td>' + (centro.TIPO_CENTRO === 'BASCULA' ? 'Báscula ' : '') + rdEsc(centro.NOM_CENTRO_OP) + '</td><td>' + rdEsc(centro.NOM_PROYECTO) + '</td>' +
              '<td class="rd-num-col">' + totalesCentro.prom + '</td><td class="rd-num-col">' + (totalesCentro.conEje ? totalesCentro.eje : '—') + '</td>' +
              '<td class="rd-num-col">' + (totalesCentro.conEje ? difCD(totalesCentro.eje - totalesCentro.prom) : '—') + '</td><td class="rd-num-col">' + totalesCentro.aus + '</td><td>' + estado + '</td></tr>';
        $.each(datos.promesa[centro.ID_CENTRO_OP] || {}, function (idCa, cifras) {
            var idCargo = +idCa; porCargo[idCargo] = porCargo[idCargo] || { prom: 0, eje: 0, aus: 0, conEje: false };
            porCargo[idCargo].prom += +cifras.promesa;
            if (cifras.ejecutado !== null) { porCargo[idCargo].eje += +cifras.ejecutado; porCargo[idCargo].conEje = true; }
        });
        $.each(totalesCentro.porCargo, function (idCa, ausencias) { var idCargo = +idCa; porCargo[idCargo] = porCargo[idCargo] || { prom: 0, eje: 0, aus: 0, conEje: false }; porCargo[idCargo].aus += ausencias; });
    });
    $('#tbPvPeaje').html(htmlProyectos || vacio(7, 'Ese día no hay promesa de valor definida en los peajes que ves.'));
    aplicarFiltroProyPv();
    var htmlCargos = '';
    (datos.cargosAus || []).forEach(function (cargo) {
        var totalesCargo = porCargo[cargo.id]; if (!totalesCargo || !totalesCargo.prom) { return; }
        htmlCargos += '<tr><td>' + rdEsc(cargo.nombre) + '</td><td class="rd-num-col">' + totalesCargo.prom + '</td><td class="rd-num-col">' + (totalesCargo.conEje ? totalesCargo.eje : '—') + '</td>' +
              '<td class="rd-num-col">' + (totalesCargo.conEje ? difCD(totalesCargo.eje - totalesCargo.prom) : '—') + '</td><td class="rd-num-col">' + totalesCargo.aus + '</td></tr>';
    });
    $('#tbPvCargo').html(htmlCargos || vacio(5, 'Sin promesa de valor ese día.'));
}

//La tabla de la promesa por peaje sigue la misma tarjeta de proyecto elegida arriba
function aplicarFiltroProyPv() {
    var idProyectoFiltro = filtroProyTab['#tbAus'];
    $('#tbPvPeaje tr[data-proy]').each(function () { $(this).toggle(!idProyectoFiltro || +$(this).data('proy') === idProyectoFiltro); });
}
$(document).on('click', '.cd-proy[data-tb="#tbAus"]', aplicarFiltroProyPv);

//Pestaña de requisiciones: las pendientes por proyecto y cuántas son urgentes
function pintarRq() {
    var html = '', filas = datosCD.rq, totalesProyecto = {}, decide = !!datosCD.puedeAprobar;
    filas.forEach(function (rqFila) { var idProyecto = +rqFila.ID_PROYECTO; totalesProyecto[idProyecto] = totalesProyecto[idProyecto] || { n: 0, u: 0, max: 0 }; totalesProyecto[idProyecto].n++; if (rqFila.TIPO_RQ === 'U') { totalesProyecto[idProyecto].u++; } totalesProyecto[idProyecto].max = Math.max(totalesProyecto[idProyecto].max, +rqFila.DIAS); });
    var datosProyecto = {};
    datosCD.proyectos.forEach(function (proyecto) { var totales = totalesProyecto[proyecto.id] || { n: 0, u: 0, max: 0 };
        datosProyecto[proyecto.id] = { n: totales.n, lineas: [(totales.u ? '<b class="cd-mal">' + cantidadTexto(totales.u, 'urgente', 'urgentes') + '</b>' : 'ninguna urgente'), (totales.n ? 'la más antigua: ' + cantidadTexto(totales.max, 'día', 'días') : '—')] }; });
    porProyecto('#cdRqProy', '#tbRq', 'por aprobar', datosProyecto);
    $('.cd-col-decidir').toggle(decide);
    for (var i = 0; i < filas.length; i++) {
        var rq = filas[i], esUrgente = rq.TIPO_RQ === 'U', id = parseInt(rq.ID_RQ, 10);
        html += '<tr data-proy="' + parseInt(rq.ID_PROYECTO, 10) + '" data-rq="' + id + '"><td><a href="RQVista.php?abrir=' + id + '" title="Abrir esta RQ en Requisiciones"><span class="cd-rq ' + (esUrgente ? 'cd-rq-u' : '') + '">' + (esUrgente ? 'RQ U-' : 'RQ-') + rdEsc(rq.NUMERO_RQ) + '</span></a></td>' +
             '<td>' + rdEsc(rq.NOM_CENTRO_OP) + '<br><small>' + rdEsc(rq.NOM_PROYECTO) + '</small></td><td>' + rdEsc(rq.SOLICITA || '') + '</td><td>' + rdEsc(rq.ITEMS || '') + rdRqMinisFila(id, rq.ARCHIVOS) +
             '<a href="#" class="btn-ver-rq cd-rq-ver rd-no-imprimir" data-id="' + id + '"><i class="fa fa-eye"></i> Ver detalle y fotos</a></td>' +
             '<td class="rd-num-col">' + parseInt(rq.DIAS, 10) + '</td>' +
             (decide ? '<td class="rd-no-imprimir cd-decidir"><button type="button" class="btn btn-success btn-xs cd-aprobar" data-id="' + id + '"><i class="fa fa-check"></i> Aprobar</button> ' +
                       '<button type="button" class="btn btn-default btn-xs cd-rechazar" data-id="' + id + '"><i class="fa fa-times"></i> Rechazar</button>' +
                       '<div class="cd-motivo" hidden><textarea class="form-control input-sm" rows="2" maxlength="500" placeholder="¿Por qué se rechaza? (lo verá quien la pidió)"></textarea>' +
                       '<button type="button" class="btn btn-danger btn-xs cd-confirmar-rechazo" data-id="' + id + '">Confirmar rechazo</button></div></td>' : '') + '</tr>';
    }
    $('#tbRq').html(html || vacio(decide ? 6 : 5, 'No hay RQ esperando aprobación.'));
    aplicarFiltroProy('#tbRq');
}

//Aprobar o rechazar desde el reporte: usa la misma operación de la pantalla de RQ (mismas reglas, 18M)
function decidirRq(id, estado, observacion, boton) {
    hanaBoton(boton, true);
    $.post('../Control/RQControl.php?op=cambiarEstado', { id: id, estado: estado, observacion: observacion || '' }, null, 'json')
        .done(function (respuesta) { rdAviso(respuesta.mensaje || (estado === 3 ? 'RQ aprobada.' : 'RQ rechazada.')); cargar(datosCD.fecha); })
        .fail(function (xhr) { hanaBoton(boton, false); alert(rdError(xhr, 'No se pudo cambiar el estado de la RQ.')); });
}
//El detalle de una RQ para la ventana de confirmación: número, peaje, quién la pidió,
//los ítems con su cantidad y justificación, la observación y los soportes. Todo escapado
function detalleRqHtml(detalle, fila) {
    return rdRqDetalleHtml(detalle, fila); //el mismo detalle con fotos que usa el tablero (ReporteComun.js)
}
$(document).on('click', '.cd-aprobar', function () {
    var boton = this, id = $(boton).data('id');
    var fila = (datosCD.rq || []).filter(function (rq) { return +rq.ID_RQ === +id; })[0] || {};
    hanaBoton(boton, true);
    //Se trae la RQ completa (ítems, justificación, soportes) para saber exactamente qué se aprueba
    $.getJSON('../Control/RQControl.php?op=mostrar&id=' + encodeURIComponent(id))
        .done(function (respuesta) {
            hanaBoton(boton, false);
            hanaConfirmar('¿Aprobar esta RQ? A quien la pidió le llega el aviso.', function () { decidirRq(id, 3, 'Aprobada desde el reporte general.', boton); },
                          { aceptar: 'Sí, aprobar', cancelar: 'No', titulo: 'Aprobar RQ', detalleHtml: detalleRqHtml(respuesta, fila) });
        })
        .fail(function (xhr) { hanaBoton(boton, false); alert(rdError(xhr, 'No se pudo abrir la RQ.')); });
});
$(document).on('click', '.cd-rechazar', function () {
    var caja = $(this).siblings('.cd-motivo'); caja.prop('hidden', !caja.prop('hidden')); caja.find('textarea').focus();
});
$(document).on('click', '.cd-confirmar-rechazo', function () {
    var texto = $.trim($(this).siblings('textarea').val());
    if (!texto) { $(this).siblings('textarea').focus(); return; }
    decidirRq($(this).data('id'), 7, texto, this);
});

//---------------------------------------------------------------------------
// El reporte de una persona (usa el mismo servicio del tablero, con su regla)
//---------------------------------------------------------------------------
function verPersona(id) {
    $('#modalPersonaCuerpo').html('<p class="rd-vacio">Cargando...</p>');
    $('#modalPersona').modal('show');
    $.getJSON('../Control/TableroControl.php?op=persona&id=' + encodeURIComponent(id) + '&fecha=' + encodeURIComponent(datosCD.fecha))
        .done(function (respuesta) {
            $('#modalPersonaTitulo').text(respuesta.persona.NOM_COLABORADOR + ' · ' + rdFecha(respuesta.fecha));
            var registroHoy = respuesta.hoy, html = '';
            if (!registroHoy) { html += '<p>' + tag('Sin reporte ese día', 'rd-tag-pend') + '</p>'; }
            else {
                html += '<p>' + tag(rdEsc(SIT_CD[registroHoy.SITUACION] || registroHoy.SITUACION), registroHoy.SITUACION === 'LABORAL' ? 'rd-tag-ok' : '') +
                     (registroHoy.HORA_INGRESO ? ' ' + rdHora(registroHoy.HORA_INGRESO) + ' – ' + (registroHoy.HORA_SALIDA ? rdHora(registroHoy.HORA_SALIDA) : '…') : '') + '</p>';
                var donde = (registroHoy.LUGARES || []).concat(registroHoy.LUGAR_OTRO ? [registroHoy.LUGAR_OTRO] : []);
                if (donde.length) { html += '<p><strong>Dónde:</strong> ' + rdEsc(donde.join(' · ')) + '</p>'; }
                if (registroHoy.ACTIVIDAD) { html += '<p><strong>Qué hizo:</strong><br>' + rdParrafo(registroHoy.ACTIVIDAD) + '</p>'; } //un solo texto para todo el día
                if (registroHoy.OBSERVACION) { html += '<p><strong>Observación:</strong> ' + rdEsc(registroHoy.OBSERVACION) + '</p>'; }
            }
            $('#modalPersonaCuerpo').html(html);
        })
        .fail(function (xhr) { $('#modalPersona').modal('hide'); alert(rdError(xhr, 'No se pudo abrir el reporte.')); });
}
