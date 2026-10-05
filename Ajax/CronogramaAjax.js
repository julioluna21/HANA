//Reporte diario — Cronograma de visitas y vehículo
//Pinta el calendario del mes y abre cada día en una ventana para planear,
//marcar lo hecho y registrar el estado del vehículo

var URL_CRONO = '../Control/CronogramaControl.php';
var cfgC = null;
var datosMes = { items: [], situaciones: {}, vehiculo: {} };
var diaAbierto = '';
var proyActual = null;      //el proyecto que se está viendo (con sus centros y vehículos)
var proyEditable = false;   //solo el cronograma propio se edita; el de otro proyecto es consulta
var TRANSPORTE_ICONO = { BUS: 'fa-bus', TAXI: 'fa-taxi', APP: 'fa-car', PARTICULAR: 'fa-car', OTRO: 'fa-road', NO_VIAJO: 'fa-home' };
//En el cuadrito del día cabe poco: nombres cortos
var TRANSPORTE_CORTO = { BUS: 'Bus', TAXI: 'Taxi', APP: 'Didi / app', PARTICULAR: 'Particular', OTRO: 'Otro', NO_VIAJO: 'No viajó' };
//Calendario o lista. En el celular arranca en lista: en el calendario solo se ven rayas de color
var vistaCrono = window.innerWidth < 768 ? 'lista' : 'calendario';

var NOMBRE_DIA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
var NOMBRE_MES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
var NOMBRE_ESTADO = { PROGRAMADA: 'Programada', REALIZADA: 'Realizada', NO_REALIZADA: 'No realizada', CANCELADA: 'Cancelada', AUSENCIA: '' };
var NOMBRE_SIT = { LABORAL: 'Laboral', DESCANSO: 'Descanso', INCAPACIDAD: 'Incapacidad', VACACIONES: 'Vacaciones', PERMISO: 'Permiso' };

$(function () {
    $.getJSON(URL_CRONO + '?op=config')
        .done(function (c) {
            cfgC = c;
            //Abrir el cronograma cuenta como revisarlo hoy (para la alerta y el reporte
            //general). Solo para el coordinador: quien consulta otro proyecto no lo "revisa"
            if (c.usaCrono) {
                $.post('../Control/EstadoDiaControl.php?op=visto', { modulo: 'CRONOGRAMA' })
                    .always(function () { rdAlertaDia('cronograma', '#alertaDia'); });
            }
            rdPeriodo('#cMes', '#cAnio', c.hoy);
            //Se puede planear el año siguiente
            $('#cAnio').prepend('<option value="' + (+c.hoy.substring(0, 4) + 1) + '">' + (+c.hoy.substring(0, 4) + 1) + '</option>');
            //Los proyectos que puede ver: arranca en el que coordina
            var hp = '';
            for (var i = 0; i < c.proyectos.length; i++) {
                hp += '<option value="' + parseInt(c.proyectos[i].ID_PROYECTO, 10) + '">' + rdEsc(c.proyectos[i].NOM_PROYECTO) +
                      (c.proyectos[i].EDITABLE ? ' (el tuyo)' : '') + '</option>';
            }
            $('#cProyecto').html(hp || '<option value="">No tienes proyectos para ver</option>').val(String(c.proyectoInicial || ''));
            $('#btnVehiculos').toggle(!!c.adminVh);
            elegirProyecto();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir el cronograma.'); });

    $('#cMes, #cAnio, #cVehiculo').on('change', cargarMes);
    $('#cProyecto').on('change', elegirProyecto);
    //El filtro de peaje no pide datos nuevos: solo vuelve a pintar
    $('#cCentro').on('change', function () { pintarCalendario(); });
    $(document).on('click', '.crono-vistas [data-vista]', function () { vistaCrono = $(this).data('vista'); pintarCalendario(); });
    //Al cambiar de mes en la lista, se pasa a ver ese mes completo
    $('#cMes, #cAnio').on('change', function () { $('#cDesdeHoy').prop('checked', false); });
    $('#cDesdeHoy').on('change', function () { if (vistaCrono === 'lista') { pintarLista(); } });
    $(document).on('click', '.crono-l-dia[data-fecha] .crono-l-cab', function () { abrirDia($(this).closest('.crono-l-dia').data('fecha')); });
    $(document).on('click', '.crono-dia[data-fecha]', function () { abrirDia($(this).data('fecha')); });

    //Ventana del día
    $(document).on('change', '#nuevoTipo', function () {
        var visita = this.value === 'VISITA';
        $('#nuevoCentroGrupo').toggle(visita);
        $('#nuevoDesc').attr('placeholder', visita ? 'Opcional' : 'Ej: comité con la interventoría');
    });
    $(document).on('click', '#btnAgregarCrono', agregarItem);
    $(document).on('click', '.btn-estado-crono', cambiarEstado);
    $(document).on('click', '.btn-quitar-crono', quitarItem);
    $(document).on('click', '#btnGuardarVhDia', guardarVhDia);
    //El transporte alterno solo se pide si el vehículo no estaba operativo
    $(document).on('change', '#vhDiaEstado', function () { $('#vhDiaTransporteGrupo').toggle(this.value !== 'OPERATIVO'); });

    //Vehículos
    $('#btnVehiculos').on('click', abrirVehiculos);
    $('#btnVolverVh').on('click', function () { $('#panelVehiculos').hide(); $('#panelCalendario').show(); });
    $('#formVh').on('submit', guardarVehiculo);
    $(document).on('click', '.btn-editar-vh', editarVehiculo);
});

//---------------------------------------------------------------------------
// Calendario
//---------------------------------------------------------------------------
//Al elegir un proyecto: sus peajes y básculas, y su vehículo (el primero, solo)
function elegirProyecto() {
    var id = +($('#cProyecto').val() || 0);
    proyActual = null;
    for (var i = 0; i < cfgC.proyectos.length; i++) { if (+cfgC.proyectos[i].ID_PROYECTO === id) { proyActual = cfgC.proyectos[i]; } }
    if (!proyActual) { $('#calendario, #listaCrono').empty(); $('#cQuien').html('<p class="rd-vacio">No tienes proyectos para ver.</p>'); return; }
    var hc = '<option value="0">Todos</option>';
    proyActual.CENTROS.forEach(function (c) {
        hc += '<option value="' + parseInt(c.ID_CENTRO_OP, 10) + '">' + (c.TIPO_CENTRO === 'BASCULA' ? 'Báscula ' : '') + rdEsc(c.NOM_CENTRO_OP) + '</option>';
    });
    $('#cCentro').html(hc);
    var hv = '';
    proyActual.VEHICULOS.forEach(function (v) {
        hv += '<option value="' + parseInt(v.ID_VEHICULO, 10) + '">' + rdEsc(v.PLACA) + (v.DESCRIPCION ? ' · ' + rdEsc(v.DESCRIPCION) : '') + '</option>';
    });
    $('#cVehiculo').html(hv ? hv + '<option value="0">Sin vehículo</option>' : '<option value="0">El proyecto no tiene vehículo</option>');
    cargarMes();
}

function cargarMes() {
    if (!proyActual) { return; }
    var q = '&proyecto=' + proyActual.ID_PROYECTO + '&anio=' + $('#cAnio').val() + '&mes=' + $('#cMes').val() + '&vehiculo=' + ($('#cVehiculo').val() || 0);
    $.getJSON(URL_CRONO + '?op=mes' + q)
        .done(function (d) {
            datosMes = d;
            proyEditable = !!d.editable;
            $('#cQuien').html(d.coordinador
                ? '<i class="fa fa-user"></i> Coordinador del proyecto: <strong>' + rdEsc(d.coordinador) + '</strong>' +
                  (proyEditable ? '' : ' <span class="adm-sello">Solo consulta</span>')
                : '<span class="rd-ayuda" style="display:inline">Este proyecto no tiene coordinador asignado.</span>');
            pintarCalendario();
            if (diaAbierto && $('#modalDia').is(':visible')) { abrirDia(diaAbierto); }
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el mes.'); });
}

//¿Ese día se visitó el peaje elegido? Por Hoy en qué estás o, si aún no pasa, por lo planeado
function visitoCentro(f, datos) {
    var idC = +($('#cCentro').val() || 0);
    if (!idC) { return true; }
    if ((datos.visitados && datos.visitados[f] || []).indexOf(idC) >= 0) { return true; }
    //Lo planeado solo cuenta de hoy en adelante: en días pasados vale la visita real
    if (f < cfgC.hoy) { return false; }
    return datos.items.some(function (it) { return it.FECHA === f && it.TIPO === 'VISITA' && +it.ID_CENTRO_OP === idC && it.ESTADO_REAL !== 'CANCELADA'; });
}

//Con un peaje elegido: cuántas veces se visitó y cuándo
function resumenCentro(datos, desde, hasta) {
    var idC = +($('#cCentro').val() || 0);
    if (!idC) { $('#cResumenCentro').empty(); return; }
    var hechas = [], planeadas = [];
    for (var f = desde; f <= hasta; f = rdSumarDias(f, 1)) {
        if ((datos.visitados && datos.visitados[f] || []).indexOf(idC) >= 0) { hechas.push(+f.substring(8, 10)); }
        else if (f >= cfgC.hoy && visitoCentro(f, datos)) { planeadas.push(+f.substring(8, 10)); }
    }
    var nom = $('#cCentro option:selected').text();
    $('#cResumenCentro').html('<div class="cd-revision"><strong>' + rdEsc(nom) + ':</strong> ' +
        (hechas.length ? 'visitado ' + hechas.length + (hechas.length === 1 ? ' día' : ' días') + ' (días ' + hechas.join(', ') + ')' : 'no se visitó en este periodo') +
        (planeadas.length ? ' · visita planeada: días ' + planeadas.join(', ') : '') + '</div>');
}

function pintarCalendario() {
    //Qué vista está activa
    $('.crono-vistas [data-vista]').removeClass('active').filter('[data-vista="' + vistaCrono + '"]').addClass('active');
    $('#calendario').toggle(vistaCrono === 'calendario');
    $('#listaCrono').toggle(vistaCrono === 'lista');
    $('#cDesdeHoyGrupo').toggle(vistaCrono === 'lista');
    if (vistaCrono === 'lista') { pintarLista(); return; }

    var anio = +$('#cAnio').val(), mes = +$('#cMes').val();
    var primero = new Date(anio, mes - 1, 1), dias = new Date(anio, mes, 0).getDate();
    var porDia = {};
    for (var i = 0; i < datosMes.items.length; i++) {
        var it = datosMes.items[i];
        (porDia[it.FECHA] = porDia[it.FECHA] || []).push(it);
    }

    var h = '';
    ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].forEach(function (n) { h += '<div class="crono-cab">' + n + '</div>'; });
    //Celdas vacías antes del día 1 (la semana empieza en lunes)
    var hueco = (primero.getDay() + 6) % 7;
    for (var v = 0; v < hueco; v++) { h += '<div class="crono-dia crono-vacio"></div>'; }

    for (var d = 1; d <= dias; d++) {
        var f = anio + '-' + rdDos(mes) + '-' + rdDos(d);
        var its = porDia[f] || [], sit = datosMes.situaciones[f], vh = datosMes.vehiculo[f];
        var cls = 'crono-dia' + (f === cfgC.hoy ? ' crono-hoy' : '') + (f < cfgC.hoy ? ' crono-pasado' : '') + (visitoCentro(f, datosMes) ? '' : ' crono-atenuado');
        h += '<div class="' + cls + '" data-fecha="' + f + '" role="button" tabindex="0">' +
             '<div class="crono-num">' + d + (sit ? ' <span class="crono-sit">' + rdEsc(NOMBRE_SIT[sit] || sit) + '</span>' : '') + '</div>';
        for (var k = 0; k < its.length && k < 3; k++) { h += '<div class="crono-item crono-' + its[k].ESTADO_REAL + '">' + rdEsc(textoItem(its[k])) + '</div>'; }
        if (its.length > 3) { h += '<div class="crono-mas">+' + (its.length - 3) + ' más</div>'; }
        h += lineaLugar(f, datosMes) + lineaVh(f, datosMes, false);
        h += '</div>';
    }
    $('#calendario').html(h);
    resumenCentro(datosMes, anio + '-' + rdDos(mes) + '-01', rdATexto(new Date(anio, mes, 0)));
}

//---------------------------------------------------------------------------
// Las dos líneas fijas de cada día (como en la hoja del Excel):
//   📍 en qué peaje estuvo (de Hoy en qué estás)
//   🚗 la placa y el estado del vehículo
//---------------------------------------------------------------------------
var CORTO_VH = { OPERATIVO: 'Operativo', INOPERATIVO: 'Inoperativo', FALLA_FLOTA: 'Falla reportada' };

function placaElegida() {
    var id = +($('#cVehiculo').val() || 0), vs = proyActual ? proyActual.VEHICULOS : [];
    for (var i = 0; i < vs.length; i++) { if (+vs[i].ID_VEHICULO === id) { return vs[i].PLACA; } }
    return '';
}

function lineaLugar(f, datos) {
    var l = datos.lugares && datos.lugares[f];
    return l ? '<div class="crono-lugar" title="Estuvo en ' + rdEsc(l) + '"><i class="fa fa-map-marker"></i> ' + rdEsc(l) + '</div>' : '';
}

//Con vehículo elegido: la placa y su estado; si el día ya pasó y no se registró, "Sin registro"
function lineaVh(f, datos, largo) {
    var placa = placaElegida();
    if (!placa) { return ''; }
    var vh = datos.vehiculo[f];
    if (!vh && f > cfgC.hoy) { return ''; }
    var est = vh ? vh.ESTADO_VH : 'SIN';
    var txt = vh ? (largo ? cfgC.estadosVh[est] : CORTO_VH[est]) : 'Sin registro';
    var h = '<div class="crono-vhtxt crono-vhtxt-' + rdEsc(est) + '" title="' + rdEsc(placa + ': ' + txt) + '"><i class="fa fa-car"></i> ' +
            '<strong>' + rdEsc(placa) + '</strong> · ' + rdEsc(txt) +
            (largo && vh && vh.OBSERVACION ? ' <span class="crono-vh-obs">— ' + rdEsc(vh.OBSERVACION) + '</span>' : '') + '</div>';
    //Si el vehículo no estaba operativo: cómo se transportó ese día
    if (vh && vh.TRANSPORTE) {
        h += '<div class="crono-transporte"><i class="fa ' + (TRANSPORTE_ICONO[vh.TRANSPORTE] || 'fa-road') + '"></i> ' +
             (largo ? 'Se transportó en: ' + rdEsc(cfgC.transportes[vh.TRANSPORTE] || vh.TRANSPORTE)
                    : rdEsc(TRANSPORTE_CORTO[vh.TRANSPORTE] || vh.TRANSPORTE)) + '</div>';
    }
    return h;
}

//"Visita Trapiche", "Reunión: comité", "Descanso"
function textoItem(it) {
    var t = cfgC.tipos[it.TIPO] || it.TIPO;
    if (it.TIPO === 'VISITA' && it.NOM_CENTRO_OP) { t += ' ' + it.NOM_CENTRO_OP; }
    if (it.DESCRIPCION) { t += (it.TIPO === 'VISITA' ? ' · ' : ': ') + it.DESCRIPCION; }
    return t;
}

//---------------------------------------------------------------------------
// Vista de lista: cada día con TODA su información, sin recortes
//---------------------------------------------------------------------------
var datosProximos = null; //los próximos 15 días, para la lista

function pintarLista() {
    //Próximos 15 días: se piden aparte, porque pueden cruzar al mes siguiente
    if ($('#cDesdeHoy').is(':checked')) {
        $.getJSON(URL_CRONO + '?op=proximos&proyecto=' + (proyActual ? proyActual.ID_PROYECTO : 0) + '&vehiculo=' + ($('#cVehiculo').val() || 0))
            .done(function (d) {
                datosProximos = d;
                dibujarLista(d, d.desde, rdSumarDias(d.hasta, -1));
                //Si la ventana de un día está abierta, se refresca con los datos nuevos
                if (diaAbierto && $('#modalDia').is(':visible')) { abrirDia(diaAbierto); }
            })
            .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron cargar los próximos días.'); });
        return;
    }
    var anio = +$('#cAnio').val(), mes = +$('#cMes').val();
    dibujarLista(datosMes, anio + '-' + rdDos(mes) + '-01', rdATexto(new Date(anio, mes, 0)));
}

function dibujarLista(datos, desde, hasta) {
    var porDia = {};
    for (var i = 0; i < datos.items.length; i++) { (porDia[datos.items[i].FECHA] = porDia[datos.items[i].FECHA] || []).push(datos.items[i]); }

    var h = '', mostrados = 0;
    resumenCentro(datos, desde, hasta);
    for (var f = desde; f <= hasta; f = rdSumarDias(f, 1)) {
        if (!visitoCentro(f, datos)) { continue; } //con un peaje elegido, solo los días que se visitó
        var d = +f.substring(8, 10);
        var its = porDia[f] || [], sit = datos.situaciones[f], vh = datos.vehiculo[f];
        var fecha = rdAFecha(f);
        mostrados++;
        h += '<div class="crono-l-dia' + (f === cfgC.hoy ? ' crono-l-hoy' : '') + (f < cfgC.hoy ? ' crono-l-pasado' : '') + '" data-fecha="' + f + '">' +
             '<div class="crono-l-cab" role="button" tabindex="0"><strong>' + NOMBRE_DIA[fecha.getDay()].charAt(0).toUpperCase() + NOMBRE_DIA[fecha.getDay()].slice(1) +
             ' ' + d + (d === 1 || f === desde ? ' de ' + NOMBRE_MES[fecha.getMonth() + 1] : '') + '</strong>' + (f === cfgC.hoy ? ' <span class="rd-tag rd-tag-ok">Hoy</span>' : '') +
             (sit ? ' <span class="rd-tag">' + rdEsc(NOMBRE_SIT[sit] || sit) + '</span>' : '') +
             '<span class="crono-l-abrir">' + (proyEditable && f >= cfgC.primerEditable ? 'Editar' : 'Ver') + ' <i class="fa fa-chevron-right"></i></span></div>';
        //Las dos líneas fijas del día: dónde estuvo y el vehículo
        var lug = lineaLugar(f, datos), veh = lineaVh(f, datos, true);
        if (lug || veh) { h += '<div class="crono-l-item crono-l-fijas">' + lug + veh + '</div>'; }
        if (!its.length) {
            h += '<div class="crono-l-vacio">Nada planeado.</div>';
        }
        for (var k = 0; k < its.length; k++) {
            var it = its[k];
            h += '<div class="crono-l-item"><span class="crono-item crono-' + it.ESTADO_REAL + '">' + rdEsc(textoItem(it)) + '</span>' +
                 (NOMBRE_ESTADO[it.ESTADO_REAL] ? ' <small>' + NOMBRE_ESTADO[it.ESTADO_REAL] + (parseInt(it.CONFIRMADA_HOY, 10) ? ' (según Hoy en qué estás)' : '') + '</small>' : '') +
                 (it.OBSERVACION ? '<div class="rd-ayuda">' + rdEsc(it.OBSERVACION) + '</div>' : '') + '</div>';
        }
        h += '</div>';
    }
    $('#listaCrono').html(h || '<p class="rd-vacio">' + (+($('#cCentro').val() || 0) ? 'Ese peaje no se visitó en estos días.' : 'No hay días para mostrar.') + '</p>');
}

//---------------------------------------------------------------------------
// Un día
//---------------------------------------------------------------------------
//De dónde sale la información de un día: de los próximos 15 días (si se está
//viendo esa lista y el día está en ella) o del mes elegido
function fuenteDe(fecha) {
    if (vistaCrono === 'lista' && $('#cDesdeHoy').is(':checked') && datosProximos &&
        fecha >= datosProximos.desde && fecha < datosProximos.hasta) { return datosProximos; }
    return datosMes;
}

function abrirDia(fecha) {
    diaAbierto = fecha;
    var d = rdAFecha(fecha);
    $('#modalDiaTitulo').text(NOMBRE_DIA[d.getDay()] + ' ' + d.getDate() + ' de ' + NOMBRE_MES[d.getMonth() + 1]);
    var editable = proyEditable && fecha >= cfgC.primerEditable && fecha <= cfgC.ultimoPlan;
    var ds = fuenteDe(fecha);
    var its = ds.items.filter(function (x) { return x.FECHA === fecha; });
    var h = '';

    {
        var sit = ds.situaciones[fecha];
        if (sit) {
            h += '<p class="rd-ayuda" style="margin-top:0;">En "Hoy en qué estás": <strong>' + rdEsc(NOMBRE_SIT[sit] || sit) + '</strong>' +
                 (ds.lugares && ds.lugares[fecha] ? ' · Estuvo en <strong>' + rdEsc(ds.lugares[fecha]) + '</strong>' : '') + '</p>';
        }

        h += '<h5 class="rd-subtitulo" style="margin-top:0;">Cronograma</h5>';
        if (!its.length) { h += '<p class="rd-vacio" style="padding:6px !important;">Nada planeado para este día.</p>'; }
        for (var i = 0; i < its.length; i++) {
            var it = its[i], id = parseInt(it.ID_CRONOGRAMA, 10);
            h += '<div class="crono-fila"><div><span class="crono-item crono-' + it.ESTADO_REAL + '">' + rdEsc(textoItem(it)) + '</span>' +
                 (NOMBRE_ESTADO[it.ESTADO_REAL] ? ' <small>' + NOMBRE_ESTADO[it.ESTADO_REAL] + (parseInt(it.CONFIRMADA_HOY, 10) ? ' (según Hoy en qué estás)' : '') + '</small>' : '') +
                 (it.OBSERVACION ? '<div class="rd-ayuda">' + rdEsc(it.OBSERVACION) + '</div>' : '') + '</div>';
            if (editable) {
                h += '<div class="crono-acciones">';
                if (it.ESTADO === 'PROGRAMADA' && it.TIPO !== 'DESCANSO' && it.TIPO !== 'VACACIONES' && fecha <= cfgC.ultimoEditable && !parseInt(it.CONFIRMADA_HOY, 10)) {
                    h += '<button type="button" class="btn btn-success btn-xs btn-estado-crono" data-id="' + id + '" data-estado="REALIZADA">Realizada</button>';
                }
                if (it.ESTADO !== 'CANCELADA') {
                    h += '<button type="button" class="btn btn-default btn-xs btn-estado-crono" data-id="' + id + '" data-estado="CANCELADA">Cancelar</button>';
                } else {
                    h += '<button type="button" class="btn btn-default btn-xs btn-estado-crono" data-id="' + id + '" data-estado="PROGRAMADA">Reactivar</button>';
                }
                if (fecha >= cfgC.hoy) { h += '<button type="button" class="btn btn-link btn-xs btn-quitar-crono" data-id="' + id + '" title="Quitar"><i class="fa fa-trash-o"></i></button>'; }
                h += '</div>';
            }
            h += '</div>';
        }

        if (editable) {
            var opT = '', opC = '';
            $.each(cfgC.tipos, function (cod, nom) { opT += '<option value="' + rdEsc(cod) + '">' + rdEsc(nom) + '</option>'; });
            for (var c = 0; c < cfgC.centros.length; c++) {
                opC += '<option value="' + parseInt(cfgC.centros[c].ID_CENTRO_OP, 10) + '">' + rdEsc(cfgC.centros[c].NOM_CENTRO_OP) + '</option>';
            }
            h += '<div class="crono-nuevo"><div class="row">' +
                 '<div class="form-group col-sm-4 col-xs-6"><label for="nuevoTipo">Qué:</label><select id="nuevoTipo" class="form-control input-sm">' + opT + '</select></div>' +
                 '<div class="form-group col-sm-8 col-xs-6" id="nuevoCentroGrupo"><label for="nuevoCentro">Peaje:</label><select id="nuevoCentro" class="form-control input-sm">' +
                 (opC || '<option value="">Tus proyectos no tienen peajes activos</option>') + '</select></div>' +
                 '<div class="form-group col-xs-12"><label for="nuevoDesc">Detalle:</label><input type="text" id="nuevoDesc" class="form-control input-sm" maxlength="150" placeholder="Opcional"></div>' +
                 '</div><div class="rd-acciones"><button type="button" class="btn btn-success btn-sm" id="btnAgregarCrono"><i class="fa fa-plus"></i> Agregar</button></div></div>';
        }
    }

    //Vehículo: ayer, hoy y mañana
    var idVh = +($('#cVehiculo').val() || 0);
    if (idVh > 0) {
        var vh = ds.vehiculo[fecha];
        h += '<h5 class="rd-subtitulo">Vehículo ' + rdEsc($('#cVehiculo option:selected').text()) + '</h5>';
        if (proyEditable && fecha >= cfgC.primerEditable && fecha <= cfgC.ultimoEditable) {
            var opV = '', opTr = '<option value="">¿Cómo te transportaste?</option>';
            $.each(cfgC.transportes, function (cod, nom) {
                opTr += '<option value="' + rdEsc(cod) + '"' + (vh && vh.TRANSPORTE === cod ? ' selected' : '') + '>' + rdEsc(nom) + '</option>';
            });
            $.each(cfgC.estadosVh, function (cod, nom) {
                opV += '<option value="' + rdEsc(cod) + '"' + (vh && vh.ESTADO_VH === cod ? ' selected' : '') + '>' + rdEsc(nom) + '</option>';
            });
            h += '<div class="row"><div class="form-group col-sm-5 col-xs-12"><select id="vhDiaEstado" class="form-control input-sm">' + opV + '</select></div>' +
                 '<div class="form-group col-sm-7 col-xs-12"><input type="text" id="vhDiaObs" class="form-control input-sm" maxlength="300" ' +
                 'placeholder="Obligatoria si no está operativo" value="' + rdEsc(vh ? vh.OBSERVACION : '') + '"></div></div>' +
                 //Si el vehículo no estaba operativo, el coordinador igual tenía que llegar: cómo lo hizo
                 '<div class="row" id="vhDiaTransporteGrupo"><div class="form-group col-sm-5 col-xs-12">' +
                 '<label for="vhDiaTransporte" class="rd-ayuda" style="margin:0 0 3px;">Sin vehículo, ¿cómo llegaste?</label>' +
                 '<select id="vhDiaTransporte" class="form-control input-sm">' + opTr + '</select></div>' +
                 '<div class="col-sm-7 col-xs-12 rd-ayuda" style="padding-top:22px;">Escribe en la observación el detalle: a dónde fuiste y qué pasó.</div></div>' +
                 '<div class="rd-acciones"><button type="button" class="btn btn-success btn-sm" id="btnGuardarVhDia"><i class="fa fa-save"></i> Guardar estado</button></div>';
        } else {
            h += vh ? '<p><span class="crono-vh crono-vh-' + rdEsc(vh.ESTADO_VH) + '">' + rdEsc(cfgC.estadosVh[vh.ESTADO_VH]) + '</span> ' + rdEsc(vh.OBSERVACION || '') +
                      (vh.TRANSPORTE ? '<br><i class="fa ' + (TRANSPORTE_ICONO[vh.TRANSPORTE] || 'fa-road') + '"></i> Se transportó en: ' + rdEsc(cfgC.transportes[vh.TRANSPORTE] || vh.TRANSPORTE) : '') + '</p>'
                    : '<p class="rd-ayuda">Sin registro para este día.</p>';
        }
    }

    $('#modalDiaCuerpo').html(h);
    $('#nuevoTipo').trigger('change');
    $('#vhDiaEstado').trigger('change');
    if (!$('#modalDia').is(':visible')) { $('#modalDia').modal('show'); }
}

function agregarItem() {
    var tipo = $('#nuevoTipo').val();
    var datos = { fecha: diaAbierto, tipo: tipo, descripcion: $('#nuevoDesc').val() };
    if (tipo === 'VISITA') { datos.centro = $('#nuevoCentro').val(); }
    hanaBoton('#btnAgregarCrono', true);
    $.post(URL_CRONO + '?op=agregar', datos, null, 'json')
        .done(function () { cargarMes(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo agregar.')); hanaBoton('#btnAgregarCrono', false); });
}

function cambiarEstado() {
    var id = $(this).data('id'), estado = $(this).data('estado');
    var enviar = function (obs) {
        $.post(URL_CRONO + '?op=estado', { id: id, estado: estado, observacion: obs || '' }, null, 'json')
            .done(function () { cargarMes(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar.')); });
    };
    if (estado === 'CANCELADA') {
        var motivo = window.prompt('¿Por qué se cancela?');
        if (motivo === null) { return; }
        if (!$.trim(motivo)) { alert('Explica por qué se cancela.'); return; }
        enviar(motivo);
    } else {
        enviar('');
    }
}

function quitarItem() {
    var id = $(this).data('id');
    hanaConfirmar('¿Quitar esta actividad del cronograma?', function () {
        $.post(URL_CRONO + '?op=quitar', { id: id }, null, 'json')
            .done(function () { cargarMes(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo quitar.')); });
    }, { aceptar: 'Sí, quitar', cancelar: 'No' });
}

function guardarVhDia() {
    hanaBoton('#btnGuardarVhDia', true);
    $.post(URL_CRONO + '?op=vehiculoDia', { vehiculo: $('#cVehiculo').val(), fecha: diaAbierto,
                                            estado: $('#vhDiaEstado').val(), observacion: $('#vhDiaObs').val(),
                                            transporte: $('#vhDiaEstado').val() === 'OPERATIVO' ? '' : ($('#vhDiaTransporte').val() || '') }, null, 'json')
        .done(function () { cargarMes(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el estado.')); hanaBoton('#btnGuardarVhDia', false); });
}

//---------------------------------------------------------------------------
// Catálogo de vehículos (5M)
//---------------------------------------------------------------------------
var catalogoVh = [];

function abrirVehiculos() {
    $('#panelCalendario').hide();
    $('#panelVehiculos').show();
    $('#formVh')[0].reset(); $('#vhId').val('');
    //Responsables: los colaboradores activos
    $.getJSON('../Control/ProyectoControl.php?op=colaboradores', function (lista) {
        var h = '<option value="">Sin asignar</option>';
        for (var i = 0; i < lista.length; i++) {
            h += '<option value="' + parseInt(lista[i].id, 10) + '">' + rdEsc(lista[i].nombre + (lista[i].cargo ? ' · ' + lista[i].cargo : '')) + '</option>';
        }
        $('#vhResp').html(h);
    });
    listarVehiculos();
}

function listarVehiculos() {
    $.getJSON(URL_CRONO + '?op=catalogo')
        .done(function (filas) {
            catalogoVh = filas;
            var h = '';
            for (var i = 0; i < filas.length; i++) {
                var v = filas[i];
                h += '<tr><td><strong>' + rdEsc(v.PLACA) + '</strong></td><td>' + rdEsc(v.DESCRIPCION) + '</td><td>' + rdEsc(v.RESPONSABLE || 'Sin asignar') + '</td>' +
                     '<td>' + (parseInt(v.ESTADO, 10) === 1 ? '<span class="rd-tag rd-tag-ok">Activo</span>' : '<span class="rd-tag">Inactivo</span>') + '</td>' +
                     '<td><button type="button" class="btn btn-default btn-xs btn-editar-vh" data-i="' + i + '"><i class="fa fa-pencil"></i> Editar</button></td></tr>';
            }
            $('#filasVh').html(h || '<tr><td colspan="5" class="rd-vacio">Todavía no hay vehículos.</td></tr>');
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudieron cargar los vehículos.')); });
}

function editarVehiculo() {
    var v = catalogoVh[$(this).data('i')];
    $('#vhId').val(v.ID_VEHICULO); $('#vhPlaca').val(v.PLACA); $('#vhDesc').val(v.DESCRIPCION || '');
    $('#vhResp').val(v.ID_COLABORADOR_RESPONSABLE ? String(v.ID_COLABORADOR_RESPONSABLE) : ''); $('#vhEstado').val(String(v.ESTADO));
    $('#vhPlaca').focus();
}

function guardarVehiculo(e) {
    e.preventDefault();
    hanaBoton('#btnGuardarVh', true);
    $.post(URL_CRONO + '?op=guardarVehiculo', $('#formVh').serialize(), null, 'json')
        .done(function (r) {
            rdAviso(r.mensaje);
            $('#formVh')[0].reset(); $('#vhId').val('');
            listarVehiculos();
            //El selector del calendario se actualiza con los cambios
            $.getJSON(URL_CRONO + '?op=config', function (c) { cfgC.proyectos = c.proyectos; elegirProyecto(); });
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el vehículo.')); })
        .always(function () { hanaBoton('#btnGuardarVh', false); });
}
