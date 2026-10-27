//Reporte diario - Cronograma de visitas y vehículo
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
//Calendario o lista. En el celular arranca en lista: en el calendario solo se ven rayas de color
var vistaCrono = window.innerWidth < 768 ? 'lista' : 'calendario';

var NOMBRE_DIA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
var NOMBRE_MES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
var NOMBRE_ESTADO = { PROGRAMADA: 'Programada', REALIZADA: 'Realizada', NO_REALIZADA: 'No realizada', CANCELADA: 'Cancelada', AUSENCIA: '' };
var NOMBRE_SIT = { LABORAL: 'Laboral', DESCANSO: 'Descanso', INCAPACIDAD: 'Incapacidad', VACACIONES: 'Vacaciones', PERMISO: 'Permiso' };

$(function () {
    $.getJSON(URL_CRONO + '?op=config')
        .done(function (config) {
            cfgC = config;
            //Abrir el cronograma cuenta como revisarlo hoy (para la alerta y el reporte
            //general). Solo para el coordinador: quien consulta otro proyecto no lo "revisa"
            if (config.usaCrono) {
                $.post('../Control/EstadoDiaControl.php?op=visto', { modulo: 'CRONOGRAMA' })
                    .always(function () { rdAlertaDia('cronograma', '#alertaDia'); });
            }
            rdPeriodo('#cMes', '#cAnio', config.hoy);
            //Se puede planear el año siguiente
            $('#cAnio').prepend('<option value="' + (+config.hoy.substring(0, 4) + 1) + '">' + (+config.hoy.substring(0, 4) + 1) + '</option>');
            //Los proyectos que puede ver: arranca en el que coordina
            var htmlProyectos = '';
            for (var i = 0; i < config.proyectos.length; i++) {
                htmlProyectos += '<option value="' + parseInt(config.proyectos[i].ID_PROYECTO, 10) + '">' + rdEsc(config.proyectos[i].NOM_PROYECTO) +
                      (config.proyectos[i].PROPIO ? ' (el tuyo)' : '') + '</option>';
            }
            $('#cProyecto').html(htmlProyectos || '<option value="">No tienes proyectos para ver</option>').val(String(config.proyectoInicial || ''));
            $('#btnVehiculos').toggle(!!config.adminVh);
            //Desde Parámetros del sistema se llega directo: CronogramaVista.php#vehiculos
            if (location.hash === '#vehiculos' && config.adminVh) { setTimeout(function () { $('#btnVehiculos').trigger('click'); }, 0); }
            //Quien no llena cronograma (por ejemplo el director de operaciones, con 34M) solo lo consulta
            if (!config.usaCrono) {
                $('#cAyuda').text('Toca un día para ver lo planeado, lo que se hizo y el estado del vehículo. ' +
                                  'Es solo consulta: cada coordinador llena el cronograma de su proyecto.');
            }
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
    var htmlCentros = '<option value="0">Todos</option>';
    proyActual.CENTROS.forEach(function (centro) {
        htmlCentros += '<option value="' + parseInt(centro.ID_CENTRO_OP, 10) + '">' + (centro.TIPO_CENTRO === 'BASCULA' ? 'Báscula ' : '') + rdEsc(centro.NOM_CENTRO_OP) + '</option>';
    });
    $('#cCentro').html(htmlCentros);
    var htmlVehiculos = '';
    proyActual.VEHICULOS.forEach(function (vehiculo) {
        htmlVehiculos += '<option value="' + parseInt(vehiculo.ID_VEHICULO, 10) + '">' + rdEsc(vehiculo.PLACA) + (vehiculo.DESCRIPCION ? ' · ' + rdEsc(vehiculo.DESCRIPCION) : '') + '</option>';
    });
    $('#cVehiculo').html(htmlVehiculos ? htmlVehiculos + '<option value="0">Sin vehículo</option>' : '<option value="0">El proyecto no tiene vehículo</option>');
    cargarMes();
}

//Trae el mes del proyecto elegido (actividades, situación de cada día y vehículo)
function cargarMes() {
    if (!proyActual) { return; }
    var filtros = '&proyecto=' + proyActual.ID_PROYECTO + '&anio=' + $('#cAnio').val() + '&mes=' + $('#cMes').val() + '&vehiculo=' + ($('#cVehiculo').val() || 0);
    $.getJSON(URL_CRONO + '?op=mes' + filtros)
        .done(function (respuesta) {
            datosMes = respuesta;
            proyEditable = !!respuesta.editable;
            $('#cQuien').html(respuesta.coordinador
                ? '<i class="fa fa-user"></i> Coordinador del proyecto: <strong>' + rdEsc(respuesta.coordinador) + '</strong>' +
                  (!proyEditable ? ' <span class="adm-sello">Solo consulta</span>' : (respuesta.propio ? '' : ' <span class="adm-sello">Lo editas como ADMIN TEC</span>'))
                : '<span class="rd-ayuda" style="display:inline">Este proyecto no tiene coordinador asignado.</span>');
            pintarCalendario();
            if (diaAbierto && $('#modalDia').is(':visible')) { abrirDia(diaAbierto); }
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el mes.'); });
}

//¿Ese día se visitó el peaje elegido? Por Hoy en qué estás o, si aún no pasa, por lo planeado
function visitoCentro(fecha, datos) {
    var idCentro = +($('#cCentro').val() || 0);
    if (!idCentro) { return true; }
    if ((datos.visitados && datos.visitados[fecha] || []).indexOf(idCentro) >= 0) { return true; }
    //Lo planeado solo cuenta de hoy en adelante: en días pasados vale la visita real
    if (fecha < cfgC.hoy) { return false; }
    return datos.items.some(function (item) { return item.FECHA === fecha && item.TIPO === 'VISITA' && +item.ID_CENTRO_OP === idCentro && item.ESTADO_REAL !== 'CANCELADA'; });
}

//Con un peaje elegido: cuántas veces se visitó y cuándo
function resumenCentro(datos, desde, hasta) {
    var idCentro = +($('#cCentro').val() || 0);
    if (!idCentro) { $('#cResumenCentro').empty(); return; }
    var hechas = [], planeadas = [];
    for (var fecha = desde; fecha <= hasta; fecha = rdSumarDias(fecha, 1)) {
        if ((datos.visitados && datos.visitados[fecha] || []).indexOf(idCentro) >= 0) { hechas.push(+fecha.substring(8, 10)); }
        else if (fecha >= cfgC.hoy && visitoCentro(fecha, datos)) { planeadas.push(+fecha.substring(8, 10)); }
    }
    var nombre = $('#cCentro option:selected').text();
    $('#cResumenCentro').html('<div class="cd-revision"><strong>' + rdEsc(nombre) + ':</strong> ' +
        (hechas.length ? 'visitado ' + hechas.length + (hechas.length === 1 ? ' día' : ' días') + ' (días ' + hechas.join(', ') + ')' : 'no se visitó en este periodo') +
        (planeadas.length ? ' · visita planeada: días ' + planeadas.join(', ') : '') + '</div>');
}

//Pinta el mes en la vista que esté activa: calendario o lista
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
        var item = datosMes.items[i];
        (porDia[item.FECHA] = porDia[item.FECHA] || []).push(item);
    }

    var html = '';
    ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].forEach(function (nombreDia) { html += '<div class="crono-cab">' + nombreDia + '</div>'; });
    //Celdas vacías antes del día 1 (la semana empieza en lunes)
    var hueco = (primero.getDay() + 6) % 7;
    for (var numHueco = 0; numHueco < hueco; numHueco++) { html += '<div class="crono-dia crono-vacio"></div>'; }

    for (var dia = 1; dia <= dias; dia++) {
        var fecha = anio + '-' + rdDos(mes) + '-' + rdDos(dia);
        var items = porDia[fecha] || [], situacion = datosMes.situaciones[fecha], vehiculoDia = datosMes.vehiculo[fecha];
        var clase = 'crono-dia' + (fecha === cfgC.hoy ? ' crono-hoy' : '') + (fecha < cfgC.hoy ? ' crono-pasado' : '') + (visitoCentro(fecha, datosMes) ? '' : ' crono-atenuado');
        html += '<div class="' + clase + '" data-fecha="' + fecha + '" role="button" tabindex="0">' +
             '<div class="crono-num">' + dia + (situacion ? ' <span class="crono-sit">' + rdEsc(NOMBRE_SIT[situacion] || situacion) + '</span>' : '') + '</div>';
        for (var numItem = 0; numItem < items.length && numItem < 3; numItem++) { html += '<div class="crono-item crono-' + items[numItem].ESTADO_REAL + '">' + rdEsc(textoItem(items[numItem])) + '</div>'; }
        if (items.length > 3) { html += '<div class="crono-mas">+' + (items.length - 3) + ' más</div>'; }
        html += lineaLugar(fecha, datosMes) + lineaVh(fecha, datosMes, false);
        html += '</div>';
    }
    $('#calendario').html(html);
    resumenCentro(datosMes, anio + '-' + rdDos(mes) + '-01', rdATexto(new Date(anio, mes, 0)));
}

//---------------------------------------------------------------------------
// Las dos líneas fijas de cada día (como en la hoja del Excel):
//   📍 en qué peaje estuvo (de Hoy en qué estás)
//   🚗 la placa y el estado del vehículo
//---------------------------------------------------------------------------
var CORTO_VH = { OPERATIVO: 'Operativo', INOPERATIVO: 'Inoperativo' };

//La placa del vehículo elegido en el filtro
function placaElegida() {
    var id = +($('#cVehiculo').val() || 0), vehiculos = proyActual ? proyActual.VEHICULOS : [];
    for (var i = 0; i < vehiculos.length; i++) { if (+vehiculos[i].ID_VEHICULO === id) { return vehiculos[i].PLACA; } }
    return '';
}

//Línea con el lugar donde estuvo la persona ese día (si se sabe)
function lineaLugar(fecha, datos) {
    var lugar = datos.lugares && datos.lugares[fecha];
    return lugar ? '<div class="crono-lugar" title="Estuvo en ' + rdEsc(lugar) + '"><i class="fa fa-map-marker"></i> ' + rdEsc(lugar) + '</div>' : '';
}

//Con vehículo elegido: la placa y su estado; si el día ya pasó y no se registró, "Sin registro"
function lineaVh(fecha, datos, largo) {
    var placa = placaElegida();
    if (!placa) { return ''; }
    var vehiculoDia = datos.vehiculo[fecha];
    if (!vehiculoDia && fecha > cfgC.hoy) { return ''; }
    var estado = vehiculoDia ? vehiculoDia.ESTADO_VH : 'SIN';
    var texto = vehiculoDia ? (largo ? cfgC.estadosVh[estado] : CORTO_VH[estado]) : 'Sin registro';
    var html = '<div class="crono-vhtxt crono-vhtxt-' + rdEsc(estado) + '" title="' + rdEsc(placa + ': ' + texto) + '"><i class="fa fa-car"></i> ' +
            '<strong>' + rdEsc(placa) + '</strong> · ' + rdEsc(texto) +
            (largo && vehiculoDia && vehiculoDia.OBSERVACION ? ' <span class="crono-vh-obs">— ' + rdEsc(vehiculoDia.OBSERVACION) + '</span>' : '') + '</div>';
    //Si el vehículo no estaba operativo: cómo se transportó ese día
    if (vehiculoDia && vehiculoDia.TRANSPORTE) {
        html += '<div class="crono-transporte"><i class="fa ' + (TRANSPORTE_ICONO[vehiculoDia.TRANSPORTE] || 'fa-road') + '"></i> ' +
             (largo ? 'Se transportó en: ' + rdEsc(cfgC.transportes[vehiculoDia.TRANSPORTE] || vehiculoDia.TRANSPORTE)
                    : rdRecortar(cfgC.transportes[vehiculoDia.TRANSPORTE] || vehiculoDia.TRANSPORTE, 16)) + '</div>';
    }
    return html;
}

//"Visita Trapiche", "Reunión: comité", "Descanso"
function textoItem(item) {
    var nombreTipo = cfgC.tipos[item.TIPO] || item.TIPO;
    if (item.TIPO === 'VISITA' && item.NOM_CENTRO_OP) { nombreTipo += ' ' + item.NOM_CENTRO_OP; }
    if (item.DESCRIPCION) { nombreTipo += (item.TIPO === 'VISITA' ? ' · ' : ': ') + item.DESCRIPCION; }
    return nombreTipo;
}

//---------------------------------------------------------------------------
// Vista de lista: cada día con TODA su información, sin recortes
//---------------------------------------------------------------------------
var datosProximos = null; //los próximos 15 días, para la lista

function pintarLista() {
    //Próximos 15 días: se piden aparte, porque pueden cruzar al mes siguiente
    if ($('#cDesdeHoy').is(':checked')) {
        $.getJSON(URL_CRONO + '?op=proximos&proyecto=' + (proyActual ? proyActual.ID_PROYECTO : 0) + '&vehiculo=' + ($('#cVehiculo').val() || 0))
            .done(function (respuesta) {
                datosProximos = respuesta;
                dibujarLista(respuesta, respuesta.desde, rdSumarDias(respuesta.hasta, -1));
                //Si la ventana de un día está abierta, se refresca con los datos nuevos
                if (diaAbierto && $('#modalDia').is(':visible')) { abrirDia(diaAbierto); }
            })
            .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron cargar los próximos días.'); });
        return;
    }
    var anio = +$('#cAnio').val(), mes = +$('#cMes').val();
    dibujarLista(datosMes, anio + '-' + rdDos(mes) + '-01', rdATexto(new Date(anio, mes, 0)));
}

//Vista de lista: las actividades agrupadas por día
function dibujarLista(datos, desde, hasta) {
    var porDia = {};
    for (var i = 0; i < datos.items.length; i++) { (porDia[datos.items[i].FECHA] = porDia[datos.items[i].FECHA] || []).push(datos.items[i]); }

    var html = '', mostrados = 0;
    resumenCentro(datos, desde, hasta);
    for (var fechaDia = desde; fechaDia <= hasta; fechaDia = rdSumarDias(fechaDia, 1)) {
        if (!visitoCentro(fechaDia, datos)) { continue; } //con un peaje elegido, solo los días que se visitó
        var dia = +fechaDia.substring(8, 10);
        var items = porDia[fechaDia] || [], situacion = datos.situaciones[fechaDia], vehiculoDia = datos.vehiculo[fechaDia];
        var fecha = rdAFecha(fechaDia);
        mostrados++;
        html += '<div class="crono-l-dia' + (fechaDia === cfgC.hoy ? ' crono-l-hoy' : '') + (fechaDia < cfgC.hoy ? ' crono-l-pasado' : '') + '" data-fecha="' + fechaDia + '">' +
             '<div class="crono-l-cab" role="button" tabindex="0"><strong>' + NOMBRE_DIA[fecha.getDay()].charAt(0).toUpperCase() + NOMBRE_DIA[fecha.getDay()].slice(1) +
             ' ' + dia + (dia === 1 || fechaDia === desde ? ' de ' + NOMBRE_MES[fecha.getMonth() + 1] : '') + '</strong>' + (fechaDia === cfgC.hoy ? ' <span class="rd-tag rd-tag-ok">Hoy</span>' : '') +
             (situacion ? ' <span class="rd-tag">' + rdEsc(NOMBRE_SIT[situacion] || situacion) + '</span>' : '') +
             '<span class="crono-l-abrir">' + (proyEditable && diaEditableC(fechaDia) ? 'Editar' : 'Ver') + ' <i class="fa fa-chevron-right"></i></span></div>';
        //Las dos líneas fijas del día: dónde estuvo y el vehículo
        var lugar = lineaLugar(fechaDia, datos), vehiculo = lineaVh(fechaDia, datos, true);
        if (lugar || vehiculo) { html += '<div class="crono-l-item crono-l-fijas">' + lugar + vehiculo + '</div>'; }
        if (!items.length) {
            html += '<div class="crono-l-vacio">Nada planeado.</div>';
        }
        for (var numItem = 0; numItem < items.length; numItem++) {
            var item = items[numItem];
            html += '<div class="crono-l-item"><span class="crono-item crono-' + item.ESTADO_REAL + '">' + rdEsc(textoItem(item)) + '</span>' +
                 (NOMBRE_ESTADO[item.ESTADO_REAL] ? ' <small>' + NOMBRE_ESTADO[item.ESTADO_REAL] + (parseInt(item.CONFIRMADA_HOY, 10) ? ' (según Hoy en qué estás)' : '') + '</small>' : '') +
                 (item.OBSERVACION ? '<div class="rd-ayuda">' + rdEsc(item.OBSERVACION) + '</div>' : '') + '</div>';
        }
        html += '</div>';
    }
    $('#listaCrono').html(html || '<p class="rd-vacio">' + (+($('#cCentro').val() || 0) ? 'Ese peaje no se visitó en estos días.' : 'No hay días para mostrar.') + '</p>');
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

//¿Ese día es festivo? (la lista viene del servidor)
function esFestivoC(fecha) { return (cfgC.festivos || []).indexOf(fecha) !== -1; }

//¿Se puede registrar el estado del vehículo ese día? Hoy o un día habilitado; y los sábados,
//domingos y festivos: por adelantado y también después, hasta donde digan los parámetros (la regla de verdad está en el servidor)
function vhDiaPermitido(fecha) {
    if (rdDiaPermitido(fecha, cfgC.hoy, cfgC.habilitados)) { return true; }
    var diaSemana = rdAFecha(fecha).getDay();
    if (!(diaSemana === 0 || diaSemana === 6 || esFestivoC(fecha))) { return false; }
    if (fecha > cfgC.hoy) { return !!cfgC.vhFindeHasta && fecha <= cfgC.vhFindeHasta; }
    return !!cfgC.vhFindeDesde && fecha >= cfgC.vhFindeDesde;
}

//¿Ese día del cronograma se puede modificar? De hoy en adelante sí (se planea);
//uno pasado, solo si el administrador lo habilitó
function diaEditableC(fecha) {
    return fecha >= cfgC.primerEditable || (cfgC.habilitados || []).indexOf(fecha) !== -1;
}

//Abre la ventana de un día con sus actividades y el estado del vehículo
function abrirDia(fecha) {
    diaAbierto = fecha;
    var fechaDia = rdAFecha(fecha);
    $('#modalDiaTitulo').text(NOMBRE_DIA[fechaDia.getDay()] + ' ' + fechaDia.getDate() + ' de ' + NOMBRE_MES[fechaDia.getMonth() + 1]);
    var editable = proyEditable && diaEditableC(fecha) && fecha <= cfgC.ultimoPlan;
    var datosFuente = fuenteDe(fecha);
    var items = datosFuente.items.filter(function (itemDia) { return itemDia.FECHA === fecha; });
    var html = '';

    {
        var situacion = datosFuente.situaciones[fecha];
        if (situacion) {
            html += '<p class="rd-ayuda" style="margin-top:0;">En "Hoy en qué estás": <strong>' + rdEsc(NOMBRE_SIT[situacion] || situacion) + '</strong>' +
                 (datosFuente.lugares && datosFuente.lugares[fecha] ? ' · Estuvo en <strong>' + rdEsc(datosFuente.lugares[fecha]) + '</strong>' : '') + '</p>';
        }

        html += '<h5 class="rd-subtitulo" style="margin-top:0;">Cronograma</h5>';
        if (!items.length) { html += '<p class="rd-vacio" style="padding:6px !important;">Nada planeado para este día.</p>'; }
        for (var i = 0; i < items.length; i++) {
            var item = items[i], id = parseInt(item.ID_CRONOGRAMA, 10);
            html += '<div class="crono-fila"><div><span class="crono-item crono-' + item.ESTADO_REAL + '">' + rdEsc(textoItem(item)) + '</span>' +
                 (NOMBRE_ESTADO[item.ESTADO_REAL] ? ' <small>' + NOMBRE_ESTADO[item.ESTADO_REAL] + (parseInt(item.CONFIRMADA_HOY, 10) ? ' (según Hoy en qué estás)' : '') + '</small>' : '') +
                 (item.OBSERVACION ? '<div class="rd-ayuda">' + rdEsc(item.OBSERVACION) + '</div>' : '') + '</div>';
            if (editable) {
                html += '<div class="crono-acciones">';
                if (item.ESTADO === 'PROGRAMADA' && item.TIPO !== 'DESCANSO' && item.TIPO !== 'VACACIONES' && fecha <= cfgC.ultimoEditable && !parseInt(item.CONFIRMADA_HOY, 10)) {
                    html += '<button type="button" class="btn btn-success btn-xs btn-estado-crono" data-id="' + id + '" data-estado="REALIZADA">Realizada</button>';
                }
                if (item.ESTADO !== 'CANCELADA') {
                    html += '<button type="button" class="btn btn-default btn-xs btn-estado-crono" data-id="' + id + '" data-estado="CANCELADA">Cancelar</button>';
                } else {
                    html += '<button type="button" class="btn btn-default btn-xs btn-estado-crono" data-id="' + id + '" data-estado="PROGRAMADA">Reactivar</button>';
                }
                if (fecha >= cfgC.hoy) { html += '<button type="button" class="btn btn-link btn-xs btn-quitar-crono" data-id="' + id + '" title="Quitar"><i class="fa fa-trash-o"></i></button>'; }
                html += '</div>';
            }
            html += '</div>';
        }

        if (editable) {
            var opcionesTipo = '', opcionesCentro = '';
            $.each(cfgC.tipos, function (codigo, nombre) { opcionesTipo += '<option value="' + rdEsc(codigo) + '">' + rdEsc(nombre) + '</option>'; });
            for (var numCentro = 0; numCentro < cfgC.centros.length; numCentro++) {
                opcionesCentro += '<option value="' + parseInt(cfgC.centros[numCentro].ID_CENTRO_OP, 10) + '">' + rdEsc(cfgC.centros[numCentro].NOM_CENTRO_OP) + '</option>';
            }
            html += '<div class="crono-nuevo"><div class="row">' +
                 '<div class="form-group col-sm-4 col-xs-6"><label for="nuevoTipo">Qué:</label><select id="nuevoTipo" class="form-control input-sm">' + opcionesTipo + '</select></div>' +
                 '<div class="form-group col-sm-8 col-xs-6" id="nuevoCentroGrupo"><label for="nuevoCentro">Peaje:</label><select id="nuevoCentro" class="form-control input-sm">' +
                 (opcionesCentro || '<option value="">Tus proyectos no tienen peajes activos</option>') + '</select></div>' +
                 '<div class="form-group col-xs-12"><label for="nuevoDesc">Detalle:</label><input type="text" id="nuevoDesc" class="form-control input-sm" maxlength="150" placeholder="Opcional"></div>' +
                 '</div><div class="rd-acciones"><button type="button" class="btn btn-success btn-sm" id="btnAgregarCrono"><i class="fa fa-plus"></i> Agregar</button></div></div>';
        }
    }

    //Vehículo: hoy, o un día que el administrador habilitó. Los sábados, domingos y festivos
    //también se pueden dejar registrados por adelantado (nunca un día que ya pasó)
    var idVh = +($('#cVehiculo').val() || 0);
    if (idVh > 0) {
        var vehiculoDia = datosFuente.vehiculo[fecha];
        html += '<h5 class="rd-subtitulo">Vehículo ' + rdEsc($('#cVehiculo option:selected').text()) + '</h5>';
        if (proyEditable && vhDiaPermitido(fecha)) {
            if (fecha > cfgC.hoy) { html += '<p class="rd-ayuda" style="margin-top:0;"><i class="fa fa-calendar-plus-o"></i> Es ' + (esFestivoC(fecha) ? 'festivo' : 'fin de semana') + ': puedes dejar registrado desde ya el estado del vehículo.</p>'; }
            var opcionesVehiculo = '', opTr = '<option value="">¿Cómo te transportaste?</option>';
            $.each(cfgC.transportes, function (codigo, nombre) {
                opTr += '<option value="' + rdEsc(codigo) + '"' + (vehiculoDia && vehiculoDia.TRANSPORTE === codigo ? ' selected' : '') + '>' + rdEsc(nombre) + '</option>';
            });
            $.each(cfgC.estadosVh, function (codigo, nombre) {
                opcionesVehiculo += '<option value="' + rdEsc(codigo) + '"' + (vehiculoDia && vehiculoDia.ESTADO_VH === codigo ? ' selected' : '') + '>' + rdEsc(nombre) + '</option>';
            });
            html += '<div class="row"><div class="form-group col-sm-5 col-xs-12"><select id="vhDiaEstado" class="form-control input-sm">' + opcionesVehiculo + '</select></div>' +
                 '<div class="form-group col-sm-7 col-xs-12"><input type="text" id="vhDiaObs" class="form-control input-sm" maxlength="300" ' +
                 'placeholder="Obligatoria si no está operativo" value="' + rdEsc(vehiculoDia ? vehiculoDia.OBSERVACION : '') + '"></div></div>' +
                 //Si el vehículo no estaba operativo, el coordinador igual tenía que llegar: cómo lo hizo
                 '<div class="row" id="vhDiaTransporteGrupo"><div class="form-group col-sm-5 col-xs-12">' +
                 '<label for="vhDiaTransporte" class="rd-ayuda" style="margin:0 0 3px;">Sin vehículo, ¿cómo llegaste?</label>' +
                 '<select id="vhDiaTransporte" class="form-control input-sm">' + opTr + '</select></div>' +
                 '<div class="col-sm-7 col-xs-12 rd-ayuda" style="padding-top:22px;">Escribe en la observación el detalle: a dónde fuiste y qué pasó.</div></div>' +
                 '<div class="rd-acciones"><button type="button" class="btn btn-success btn-sm" id="btnGuardarVhDia"><i class="fa fa-save"></i> Guardar estado</button></div>';
        } else {
            html += vehiculoDia ? '<p><span class="crono-vh crono-vh-' + rdEsc(vehiculoDia.ESTADO_VH) + '">' + rdEsc(cfgC.estadosVh[vehiculoDia.ESTADO_VH]) + '</span> ' + rdEsc(vehiculoDia.OBSERVACION || '') +
                      (vehiculoDia.TRANSPORTE ? '<br><i class="fa ' + (TRANSPORTE_ICONO[vehiculoDia.TRANSPORTE] || 'fa-road') + '"></i> Se transportó en: ' + rdEsc(cfgC.transportes[vehiculoDia.TRANSPORTE] || vehiculoDia.TRANSPORTE) : '') + '</p>'
                    : '<p class="rd-ayuda">Sin registro para este día.</p>';
        }
    }

    $('#modalDiaCuerpo').html(html);
    $('#nuevoTipo').trigger('change');
    $('#vhDiaEstado').trigger('change');
    if (!$('#modalDia').is(':visible')) { $('#modalDia').modal('show'); }
}

//Agrega una actividad al día abierto
function agregarItem() {
    var tipo = $('#nuevoTipo').val();
    //El proyecto que se está viendo: el ADMIN TEC escribe en el cronograma de su coordinador
    var datos = { fecha: diaAbierto, tipo: tipo, descripcion: $('#nuevoDesc').val(), proyecto: proyActual ? proyActual.ID_PROYECTO : '' };
    if (tipo === 'VISITA') { datos.centro = $('#nuevoCentro').val(); }
    hanaBoton('#btnAgregarCrono', true);
    $.post(URL_CRONO + '?op=agregar', datos, null, 'json')
        .done(function () { cargarMes(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo agregar.')); hanaBoton('#btnAgregarCrono', false); });
}

//Cambia el estado de una actividad (cumplida, no cumplida...) y pide la observación si hace falta
function cambiarEstado() {
    var id = $(this).data('id'), estado = $(this).data('estado');
    var enviar = function (observacion) {
        $.post(URL_CRONO + '?op=estado', { id: id, estado: estado, observacion: observacion || '' }, null, 'json')
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

//Quita una actividad del cronograma (pregunta antes)
function quitarItem() {
    var id = $(this).data('id');
    hanaConfirmar('¿Quitar esta actividad del cronograma?', function () {
        $.post(URL_CRONO + '?op=quitar', { id: id }, null, 'json')
            .done(function () { cargarMes(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo quitar.')); });
    }, { aceptar: 'Sí, quitar', cancelar: 'No' });
}

//Guarda cómo estuvo el vehículo el día abierto y en qué se movió la persona si no estuvo operativo
function guardarVhDia() {
    hanaBoton('#btnGuardarVhDia', true);
    $.post(URL_CRONO + '?op=vehiculoDia', { vehiculo: $('#cVehiculo').val(), fecha: diaAbierto,
                                            estado: $('#vhDiaEstado').val(), observacion: $('#vhDiaObs').val(),
                                            transporte: $('#vhDiaEstado').val() === 'OPERATIVO' ? '' : ($('#vhDiaTransporte').val() || '') }, null, 'json')
        .done(function () { cargarMes(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el estado.')); hanaBoton('#btnGuardarVhDia', false); });
}

//---------------------------------------------------------------------------
// Vehículos de sus proyectos: agregar, cambiar y quitar (casilla 47M)
//---------------------------------------------------------------------------
var catalogoVh = [];

//Muestra el panel de administración de vehículos
function abrirVehiculos() {
    $('#panelCalendario').hide();
    $('#panelVehiculos').show();
    $('#formVh')[0].reset(); $('#vhId').val('');
    //Proyecto del vehículo: uno de los suyos
    var opciones = '';
    for (var p = 0; p < (cfgC.proyectos || []).length; p++) {
        opciones += '<option value="' + parseInt(cfgC.proyectos[p].ID_PROYECTO, 10) + '">' + rdEsc(cfgC.proyectos[p].NOM_PROYECTO) + '</option>';
    }
    $('#vhProyecto').html(opciones);
    if (proyActual) { $('#vhProyecto').val(String(proyActual.ID_PROYECTO)); }
    //Responsables: los colaboradores activos
    $.getJSON('../Control/ProyectoControl.php?op=colaboradores', function (lista) {
        var html = '<option value="">Sin asignar</option>';
        for (var i = 0; i < lista.length; i++) {
            html += '<option value="' + parseInt(lista[i].id, 10) + '">' + rdEsc(lista[i].nombre + (lista[i].rol ? ' · ' + lista[i].rol : '')) + '</option>';
        }
        $('#vhResp').html(html);
    });
    listarVehiculos();
}

//Trae los vehículos y los pinta en la tabla del panel
function listarVehiculos() {
    $.getJSON(URL_CRONO + '?op=catalogo')
        .done(function (filas) {
            catalogoVh = filas;
            var html = '';
            for (var i = 0; i < filas.length; i++) {
                var vehiculo = filas[i];
                html += '<tr><td><strong>' + rdEsc(vehiculo.PLACA) + '</strong></td><td>' + rdEsc(vehiculo.DESCRIPCION) + '</td><td>' + rdEsc(vehiculo.NOM_PROYECTO || 'Sin proyecto') + '</td><td>' + rdEsc(vehiculo.RESPONSABLE || 'Sin asignar') + '</td>' +
                     '<td>' + (parseInt(vehiculo.ESTADO, 10) === 1 ? '<span class="rd-tag rd-tag-ok">Activo</span>' : '<span class="rd-tag">Inactivo</span>') + '</td>' +
                     '<td><button type="button" class="btn btn-default btn-xs btn-editar-vh" data-i="' + i + '"><i class="fa fa-pencil"></i> Editar</button></td></tr>';
            }
            $('#filasVh').html(html || '<tr><td colspan="6" class="rd-vacio">Todavía no hay vehículos en tus proyectos.</td></tr>');
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudieron cargar los vehículos.')); });
}

//Carga un vehículo en el formulario para editarlo
function editarVehiculo() {
    var vehiculo = catalogoVh[$(this).data('i')];
    $('#vhId').val(vehiculo.ID_VEHICULO); $('#vhPlaca').val(vehiculo.PLACA); $('#vhDesc').val(vehiculo.DESCRIPCION || '');
    $('#vhResp').val(vehiculo.ID_COLABORADOR_RESPONSABLE ? String(vehiculo.ID_COLABORADOR_RESPONSABLE) : ''); $('#vhEstado').val(String(vehiculo.ESTADO));
    if (vehiculo.ID_PROYECTO) {
        if (!$('#vhProyecto option[value="' + parseInt(vehiculo.ID_PROYECTO, 10) + '"]').length) { $('#vhProyecto').append('<option value="' + parseInt(vehiculo.ID_PROYECTO, 10) + '">' + rdEsc(vehiculo.NOM_PROYECTO || '') + '</option>'); }
        $('#vhProyecto').val(String(vehiculo.ID_PROYECTO));
    }
    $('#vhPlaca').focus();
}

//Guarda el vehículo del formulario
function guardarVehiculo(evento) {
    evento.preventDefault();
    hanaBoton('#btnGuardarVh', true);
    $.post(URL_CRONO + '?op=guardarVehiculo', $('#formVh').serialize(), null, 'json')
        .done(function (respuesta) {
            rdAviso(respuesta.mensaje);
            $('#formVh')[0].reset(); $('#vhId').val('');
            listarVehiculos();
            //El selector del calendario se actualiza con los cambios
            $.getJSON(URL_CRONO + '?op=config', function (config) { cfgC.proyectos = config.proyectos; elegirProyecto(); });
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el vehículo.')); })
        .always(function () { hanaBoton('#btnGuardarVh', false); });
}
