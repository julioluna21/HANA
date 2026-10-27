//Reporte general - consulta de todo lo que llenan los coordinadores (Hoy en qué estás, Arqueos y Vacantes)
//Una sola lógica para las cinco pantallas: cada una trae <div id="admPagina" data-pagina="...">
//y los filtros que usa. Todo lo que viene de la base se escapa con rdEsc

var URL_ADM = '../Control/AdminReporteControl.php';
var cfgA = null;
var paginaA = '';
var filasA = [];

//Pequeñas ayudas para armar HTML: una etiqueta de color y una fila de "no hay datos"
function tagA(texto, clase) { return '<span class="rd-tag ' + (clase || '') + '">' + texto + '</span>'; }
function vacioA(columnas, texto) { return '<tr><td colspan="' + columnas + '" class="rd-vacio">' + texto + '</td></tr>'; }
//Arma las <option> de un select a partir de una lista (con una primera opción opcional)
function opcionesA(lista, valor, texto, primero) {
    var html = primero !== undefined ? '<option value="0">' + rdEsc(primero) + '</option>' : '';
    for (var i = 0; i < lista.length; i++) { html += '<option value="' + parseInt(lista[i][valor], 10) + '">' + rdEsc(lista[i][texto]) + '</option>'; }
    return html;
}
//Junta los filtros que tenga la página (mes, año, proyecto...) para mandarlos en la URL
function filtrosA() {
    var filtros = '';
    ['Mes', 'Anio', 'Proyecto', 'Centro', 'Persona', 'Tipo'].forEach(function (filtro) {
        var campo = $('#a' + filtro);
        if (campo.length) { filtros += '&' + filtro.toLowerCase() + '=' + encodeURIComponent(campo.val() || 0); }
    });
    return filtros;
}

$(function () {
    paginaA = $('#admPagina').data('pagina');
    $.getJSON(URL_ADM + '?op=config')
        .done(function (config) {
            cfgA = config;
            rdPeriodo('#aMes', '#aAnio', config.hoy);
            //Vacantes y comunicaciones arrancan en "todas las abiertas"
            if ($('#aMes option[value="0"]').length) { $('#aMes').val('0'); $('#aAnio').prop('disabled', true); }
            $('#aProyecto').html(opcionesA(config.proyectos, 'ID_PROYECTO', 'NOM_PROYECTO', 'Todos los proyectos'));
            $('#aPersona').html(opcionesA(config.personas, 'ID_COLABORADOR', 'NOM_COLABORADOR',
                                          paginaA === 'cronograma' ? undefined : 'Todas las personas'));
            if ($('#aTipo').length) {
                var htmlTipos = '<option value="">Todos</option>';
                $.each(config.tiposArqueo, function (clave, nombre) { htmlTipos += '<option value="' + rdEsc(clave) + '">' + rdEsc(nombre) + '</option>'; });
                $('#aTipo').html(htmlTipos);
            }
            llenarCentrosA();
            cargarA();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir la consulta.'); });

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
    var idProyecto = +($('#aProyecto').val() || 0);
    var lista = cfgA.centros.filter(function (centro) { return !idProyecto || +centro.ID_PROYECTO === idProyecto; });
    $('#aCentro').html(opcionesA(lista, 'ID_CENTRO_OP', 'NOM_CENTRO_OP', 'Todos los centros'));
}

//Pide al servidor los datos de la pestaña abierta y los pinta en la tabla
function cargarA() {
    var op = { hoy: 'hoy', arqueos: 'arqueos', cronograma: 'cronograma', vacantes: 'vacantes', comunicaciones: 'comunicaciones' }[paginaA];
    if (paginaA === 'cronograma' && !$('#aPersona').val()) {
        $('#admResultado').html('<p class="rd-vacio">No hay coordinadores asignados todavía.</p>');
        return;
    }
    $('#admResultado').css('opacity', .5);
    $.getJSON(URL_ADM + '?op=' + op + filtrosA())
        .done(function (respuesta) {
            $('#admResultado').css('opacity', 1);
            ({ hoy: pintarHoyA, arqueos: pintarArqueosA, cronograma: pintarCronoA, vacantes: pintarVacA, comunicaciones: pintarComA })[paginaA](respuesta);
        })
        .fail(function (xhr) { $('#admResultado').css('opacity', 1); hanaErrorAjax(xhr, 'No se pudo consultar.'); });
}

//---------------------------------------------------------------------------
// Hoy en qué estás
//---------------------------------------------------------------------------
function pintarHoyA(filas) {
    var html = '', personas = {};
    for (var i = 0; i < filas.length; i++) {
        var registro = filas[i], esLaboral = registro.SITUACION === 'LABORAL';
        personas[registro.ID_COLABORADOR] = true;
        var donde = [registro.LUGARES, registro.LUGAR_OTRO].filter(function (lugar) { return lugar; }).join(' · ');
        html += '<tr><td class="rd-nowrap">' + rdFecha(registro.FECHA) + '</td><td>' + rdEsc(registro.NOM_COLABORADOR) + '</td>' +
             '<td>' + tagA(rdEsc(cfgA.situaciones[registro.SITUACION] || registro.SITUACION), esLaboral ? 'rd-tag-ok' : '') + '</td>' +
             '<td>' + rdEsc(esLaboral ? donde : '') + (registro.OBSERVACION ? '<br><small>' + rdEsc(registro.OBSERVACION) + '</small>' : '') + '</td>' +
             '<td class="rd-nowrap">' + (esLaboral && registro.HORA_INGRESO ? rdHora(registro.HORA_INGRESO) + ' – ' + (registro.HORA_SALIDA ? rdHora(registro.HORA_SALIDA) : '…') : '') + '</td>' +
             '<td><small>' + (esLaboral ? rdRecortar(registro.ACTIVIDAD, 90) : '') + '</small></td>' +
             '<td class="rd-nowrap"><small>' + rdFecha(String(registro.FEC_MODIFICACION || registro.FEC_REGISTRO).substring(0, 10)) + ' ' +
                 rdHora(String(registro.FEC_MODIFICACION || registro.FEC_REGISTRO).substring(11)) + '</small></td>' +
             '<td><button type="button" class="btn btn-success btn-xs btn-adm-hoy" data-persona="' + parseInt(registro.ID_COLABORADOR, 10) +
                 '" data-fecha="' + rdEsc(registro.FECHA) + '"><i class="fa fa-eye"></i> Ver</button></td></tr>';
    }
    $('#admResumen').html(tagA(filas.length + (filas.length === 1 ? ' registro' : ' registros'), 'rd-tag-ok') + ' ' +
                          tagA(Object.keys(personas).length + (Object.keys(personas).length === 1 ? ' persona' : ' personas')));
    $('#admFilas').html(html || vacioA(8, 'No hay registros en este mes.'));
}

//Abre en una ventana el detalle del reporte diario de una persona en una fecha
function verHoyA(persona, fecha) {
    $('#admModalCuerpo').html('<p class="rd-vacio">Cargando...</p>');
    $('#admModal').modal('show');
    $.getJSON(URL_ADM + '?op=hoyDetalle&persona=' + encodeURIComponent(persona) + '&fecha=' + encodeURIComponent(fecha))
        .done(function (respuesta) {
            $('#admModalTitulo').text(respuesta.NOM_COLABORADOR + ' · ' + rdFecha(respuesta.FECHA));
            var esLaboral = respuesta.SITUACION === 'LABORAL';
            var html = '<p>' + tagA(rdEsc(cfgA.situaciones[respuesta.SITUACION] || respuesta.SITUACION), esLaboral ? 'rd-tag-ok' : '') +
                    (esLaboral && respuesta.HORA_INGRESO ? ' ' + rdHora(respuesta.HORA_INGRESO) + ' – ' + (respuesta.HORA_SALIDA ? rdHora(respuesta.HORA_SALIDA) : '…') : '') + '</p>';
            var donde = (respuesta.LUGARES || []).concat(respuesta.LUGAR_OTRO ? [respuesta.LUGAR_OTRO] : []);
            if (donde.length) { html += '<p><strong>Dónde:</strong> ' + rdEsc(donde.join(' · ')) + '</p>'; }
            if (respuesta.ACTIVIDAD) { html += '<p><strong>Qué hizo:</strong><br>' + rdParrafo(respuesta.ACTIVIDAD) + '</p>'; } //un solo texto para todo el día
            if (respuesta.OBSERVACION) { html += '<p><strong>Observación:</strong> ' + rdEsc(respuesta.OBSERVACION) + '</p>'; }
            $('#admModalCuerpo').html(html);
        })
        .fail(function (xhr) { $('#admModal').modal('hide'); alert(rdError(xhr, 'No se pudo abrir el registro.')); });
}

//---------------------------------------------------------------------------
// Arqueos
//---------------------------------------------------------------------------
function difA(diferencia) {
    diferencia = parseFloat(diferencia) || 0;
    return diferencia === 0 ? tagA('<i class="fa fa-check"></i> Cuadra', 'rd-tag-ok') : tagA((diferencia < 0 ? 'Faltante ' : 'Sobrante ') + rdPesos(Math.abs(diferencia)), 'rd-tag-mal');
}

//Pinta la tabla de arqueos con el conteo de cuántos cuadran y cuántos tienen diferencia
function pintarArqueosA(filas) {
    var html = '', total = 0, cuadran = 0, diferenciaArqueo = 0;
    for (var i = 0; i < filas.length; i++) {
        var arqueo = filas[i], vigente = parseInt(arqueo.ESTADO, 10) === 1, diferencia = parseFloat(arqueo.DIFERENCIA);
        if (vigente) { total++; if (diferencia === 0) { cuadran++; } else { diferenciaArqueo++; } }
        html += '<tr' + (vigente ? '' : ' class="rd-anulado"') + '><td class="rd-nowrap">' + rdFecha(arqueo.FECHA) + ' <small>' + rdHora(arqueo.HORA) + '</small></td>' +
             '<td>' + rdEsc(cfgA.tiposArqueo[arqueo.TIPO] || arqueo.TIPO) + '</td><td>' + rdEsc(arqueo.NOM_CENTRO_OP) + '</td><td>' + rdEsc(arqueo.NOM_PROYECTO) + '</td>' +
             '<td>' + rdEsc(arqueo.RESPONSABLE) + '</td><td>' + rdEsc(arqueo.ARQUEA || '') + '</td>' +
             '<td class="rd-num-col">' + rdPesos(arqueo.TOTAL_ARQUEO) + '</td><td class="rd-num-col">' + rdPesos(arqueo.FONDO_AUTORIZADO) + '</td>' +
             '<td>' + (vigente ? difA(diferencia) : tagA('Anulado')) + '</td>' +
             '<td class="rd-nowrap"><button type="button" class="btn btn-success btn-xs btn-adm-arq" data-id="' + parseInt(arqueo.ID_ARQUEO, 10) + '"><i class="fa fa-eye"></i> Ver</button> ' +
             '<a class="btn btn-default btn-xs" target="_blank" rel="noopener" href="ArqueoPdfVista.php?ver=1&id=' + parseInt(arqueo.ID_ARQUEO, 10) + '"><i class="fa fa-file-pdf-o"></i> PDF</a></td></tr>';
    }
    $('#admResumen').html(tagA(total + ' arqueos') + ' ' + tagA(cuadran + ' cuadran', 'rd-tag-ok') + ' ' + (diferenciaArqueo ? tagA(diferenciaArqueo + ' con diferencia', 'rd-tag-mal') : ''));
    $('#admFilas').html(html || vacioA(10, 'No hay arqueos con estos filtros.'));
}

//Abre en una ventana el detalle de un arqueo
function verArqueoA(id) {
    $('#admModalCuerpo').html('<p class="rd-vacio">Cargando...</p>');
    $('#admModal').modal('show');
    $.getJSON(URL_ADM + '?op=arqueo&id=' + encodeURIComponent(id))
        .done(function (arqueo) {
            $('#admModalTitulo').text('Arqueo de ' + (cfgA.tiposArqueo[arqueo.TIPO] || arqueo.TIPO).toLowerCase() + ' · ' + arqueo.NOM_CENTRO_OP + ' · ' + rdFecha(arqueo.FECHA));
            var html = '<p><strong>Responsable:</strong> ' + rdEsc(arqueo.RESPONSABLE) + ' · <strong>Arqueó:</strong> ' + rdEsc(arqueo.ARQUEA || '') + ' · ' +
                    (parseInt(arqueo.ESTADO, 10) === 1 ? difA(arqueo.DIFERENCIA) : tagA('Anulado: ' + rdEsc(arqueo.MOTIVO_ANULACION || ''))) + '</p>';
            html += '<table class="table rd-tabla rd-tabla-compacta"><thead><tr><th>Concepto</th><th>Fecha</th><th>Observación</th><th class="rd-num-col">Valor</th></tr></thead><tbody>';
            for (var i = 0; i < arqueo.LINEAS.length; i++) {
                var linea = arqueo.LINEAS[i];
                //Cada renglón trae el nombre que tenía el concepto el día del arqueo
                var nombre = linea.NOMBRE || linea.CODIGO;
                html += '<tr><td>' + rdEsc(nombre) + '</td><td>' + rdFecha(linea.FECHA) + '</td><td>' + rdEsc(linea.OBSERVACION || '') + '</td><td class="rd-num-col">' + rdPesos(linea.VALOR) + '</td></tr>';
            }
            html += '</tbody><tfoot><tr><th colspan="3">Total arqueo</th><th class="rd-num-col">' + rdPesos(arqueo.TOTAL_ARQUEO) + '</th></tr>' +
                 '<tr><th colspan="3">Fondo autorizado</th><th class="rd-num-col">' + rdPesos(arqueo.FONDO_AUTORIZADO) + '</th></tr>' +
                 '<tr><th colspan="3">Diferencia</th><th class="rd-num-col">' + rdPesos(arqueo.DIFERENCIA) + '</th></tr></tfoot></table>';
            if (arqueo.OBSERVACION) { html += '<p><strong>Observación:</strong> ' + rdEsc(arqueo.OBSERVACION) + '</p>'; }
            html += '<div class="rd-acciones"><a class="btn btn-success" target="_blank" rel="noopener" href="ArqueoPdfVista.php?ver=1&id=' + parseInt(arqueo.ID_ARQUEO, 10) + '"><i class="fa fa-file-pdf-o"></i> Ver PDF</a></div>';
            $('#admModalCuerpo').html(html);
        })
        .fail(function (xhr) { $('#admModal').modal('hide'); alert(rdError(xhr, 'No se pudo abrir el arqueo.')); });
}

//---------------------------------------------------------------------------
// Cronograma y vehículo de una persona (vista de lista, solo lectura)
//---------------------------------------------------------------------------
function pintarCronoA(datos) {
    var NOMBRE_DIA = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    var EST = { PROGRAMADA: 'Programada', REALIZADA: 'Realizada', NO_REALIZADA: 'No realizada', CANCELADA: 'Cancelada', AUSENCIA: '' };
    var porDia = {};
    for (var i = 0; i < datos.items.length; i++) { (porDia[datos.items[i].FECHA] = porDia[datos.items[i].FECHA] || []).push(datos.items[i]); }
    var ultimo = rdSumarDias(datos.hasta, -1), html = '', hechas = 0, total = 0;
    for (var dia = datos.desde; dia <= ultimo; dia = rdSumarDias(dia, 1)) {
        var items = porDia[dia] || [], situacion = datos.situaciones[dia], fecha = rdAFecha(dia);
        var vehiculos = datos.vehiculos.map(function (vehiculo) { return vehiculo.DIAS[dia] ? { placa: vehiculo.PLACA, e: vehiculo.DIAS[dia] } : null; }).filter(function (conDato) { return conDato; });
        var lugar = datos.lugares && datos.lugares[dia];
        if (!items.length && !situacion && !vehiculos.length) { continue; } //solo los días con algo
        html += '<div class="crono-l-dia' + (dia === cfgA.hoy ? ' crono-l-hoy' : '') + '"><div class="crono-l-cab" style="cursor:default;"><strong>' +
             NOMBRE_DIA[fecha.getDay()] + ' ' + fecha.getDate() + '</strong>' + (dia === cfgA.hoy ? ' ' + tagA('Hoy', 'rd-tag-ok') : '') +
             (situacion ? ' ' + tagA(rdEsc(cfgA.situaciones[situacion] || situacion)) : '');
        html += '</div>';
        //Las dos líneas fijas del día: dónde estuvo y el vehículo (placa y estado)
        var fijas = (lugar ? '<div class="crono-lugar"><i class="fa fa-map-marker"></i> ' + rdEsc(lugar) + '</div>' : '');
        vehiculos.forEach(function (vehiculo) {
            fijas += '<div class="crono-vhtxt crono-vhtxt-' + rdEsc(vehiculo.e.ESTADO_VH) + '"><i class="fa fa-car"></i> <strong>' + rdEsc(vehiculo.placa) + '</strong> · ' +
                     rdEsc(cfgA.estadosVh[vehiculo.e.ESTADO_VH]) + (vehiculo.e.OBSERVACION ? ' <span class="crono-vh-obs">— ' + rdEsc(vehiculo.e.OBSERVACION) + '</span>' : '') + '</div>' +
                     (vehiculo.e.TRANSPORTE ? '<div class="crono-transporte"><i class="fa fa-road"></i> Se transportó en: ' + rdEsc(cfgA.transportes[vehiculo.e.TRANSPORTE] || vehiculo.e.TRANSPORTE) + '</div>' : '');
        });
        if (fijas) { html += '<div class="crono-l-item crono-l-fijas">' + fijas + '</div>'; }
        for (var numVisita = 0; numVisita < items.length; numVisita++) {
            var visita = items[numVisita];
            if (visita.ESTADO_REAL !== 'AUSENCIA' && visita.ESTADO_REAL !== 'CANCELADA') { total++; if (visita.ESTADO_REAL === 'REALIZADA') { hechas++; } }
            var texto = (cfgA.tiposCrono[visita.TIPO] || visita.TIPO) + (visita.NOM_CENTRO_OP ? ' ' + visita.NOM_CENTRO_OP : '') + (visita.DESCRIPCION ? ': ' + visita.DESCRIPCION : '');
            html += '<div class="crono-l-item"><span class="crono-item crono-' + visita.ESTADO_REAL + '">' + rdEsc(texto) + '</span>' +
                 (EST[visita.ESTADO_REAL] ? ' <small>' + EST[visita.ESTADO_REAL] + (parseInt(visita.CONFIRMADA_HOY, 10) ? ' (según Hoy en qué estás)' : '') + '</small>' : '') +
                 (visita.OBSERVACION ? '<div class="rd-ayuda">' + rdEsc(visita.OBSERVACION) + '</div>' : '') + '</div>';
        }
        html += '</div>';
    }
    $('#admResumen').html(tagA(hechas + ' de ' + total + ' actividades realizadas', hechas === total && total ? 'rd-tag-ok' : '') + ' ' +
                          (datos.vehiculos.length ? tagA(datos.vehiculos.length + (datos.vehiculos.length === 1 ? ' vehículo a su cargo' : ' vehículos a su cargo')) : ''));
    $('#admResultado').html(html ? '<div class="crono-lista">' + html + '</div>' : '<p class="rd-vacio">Nada registrado para esta persona en este mes.</p>');
}

//---------------------------------------------------------------------------
// Vacantes
//---------------------------------------------------------------------------
function pintarVacA(filas) {
    var ETIQUETA_RESULTADO = { CUMPLE: tagA('Cumple', 'rd-tag-ok'), NO_CUMPLE: tagA('No cumple', 'rd-tag-mal'), EN_TIEMPO: tagA('En tiempo', 'rd-tag-pend'), VENCIDA: tagA('Fuera del acuerdo', 'rd-tag-mal') };
    var html = '', abiertas = 0, fuera = 0;
    for (var i = 0; i < filas.length; i++) {
        var vacante = filas[i], abierta = vacante.ESTADO_VACANTE === 'ABIERTA';
        if (abierta) { abiertas++; if (vacante.RESULTADO_ANS === 'VENCIDA') { fuera++; } }
        html += '<tr' + (vacante.ESTADO_VACANTE === 'CANCELADA' ? ' class="rd-anulado"' : '') + '><td>' + rdEsc(vacante.NUMERO_RQ_GH || '') + '</td>' +
             '<td>' + rdEsc(vacante.NOM_PROYECTO) + '</td><td>' + rdEsc(vacante.NOM_CENTRO_OP) + '</td><td>' + rdEsc(vacante.CARGO) + '</td>' +
             '<td>' + rdFecha(vacante.FECHA_VACANTE) + '</td><td>' + rdFecha(vacante.FECHA_ANS) + '</td><td>' + rdFecha(vacante.FECHA_CIERRE) + '</td>' +
             '<td class="rd-num-col">' + (abierta ? vacante.DIAS_A_HOY + ' a hoy' : (vacante.TIEMPO_CIERRE !== null ? vacante.TIEMPO_CIERRE + ' en cerrar' : '')) + '</td>' +
             '<td>' + rdEsc(vacante.MOTIVO) + '</td><td>' + rdEsc(cfgA.estadosVacante[vacante.ESTADO_VACANTE]) + '</td><td>' + (ETIQUETA_RESULTADO[vacante.RESULTADO_ANS] || '') + '</td>' +
             '<td>' + rdEsc(vacante.NOMBRE_REEMPLAZO || '') + '</td>' +
             '<td>' + rdEsc((vacante.ESTADO_GH ? cfgA.estadosGh[vacante.ESTADO_GH] : '') + (vacante.FECHA_COMPROMISO_GH ? ' ' + rdFecha(vacante.FECHA_COMPROMISO_GH) : '')) + '</td></tr>';
    }
    $('#admResumen').html(tagA(filas.length + ' vacantes') + ' ' + tagA(abiertas + ' abiertas', 'rd-tag-pend') + ' ' + (fuera ? tagA(fuera + ' fuera del acuerdo', 'rd-tag-mal') : ''));
    $('#admFilas').html(html || vacioA(13, 'No hay vacantes con estos filtros.'));
}

//---------------------------------------------------------------------------
// Comunicaciones
//---------------------------------------------------------------------------
function pintarComA(filas) {
    var ETIQUETA_RESULTADO = { CUMPLE: tagA('Cumple', 'rd-tag-ok'), NO_CUMPLE: tagA('No cumple', 'rd-tag-mal'), EN_TIEMPO: tagA('En tiempo', 'rd-tag-pend'), VENCIDA: tagA('Fuera de tiempo', 'rd-tag-mal') };
    var html = '', abiertas = 0, fuera = 0;
    for (var i = 0; i < filas.length; i++) {
        var oficio = filas[i], abierta = parseInt(oficio.ABIERTA, 10) === 1;
        if (abierta) { abiertas++; if (oficio.RESULTADO === 'VENCIDA') { fuera++; } }
        html += '<tr><td>' + rdEsc(cfgA.tiposCom[oficio.TIPO]) + '</td><td>' + rdEsc(oficio.NOM_PROYECTO) + (oficio.NOM_CENTRO_OP ? '<br><small>' + rdEsc(oficio.NOM_CENTRO_OP) + '</small>' : '') + '</td>' +
             '<td>' + rdEsc(oficio.RADICADO || '') + '</td><td>' + rdFecha(oficio.FECHA_RECEPCION) + '</td><td>' + rdEsc(cfgA.medios[oficio.MEDIO] || oficio.MEDIO) + '</td>' +
             '<td>' + rdEsc(oficio.REMITENTE) + '</td><td>' + rdEsc(oficio.ASUNTO) + (oficio.RESPUESTA ? '<br><small>' + rdEsc(oficio.RESPUESTA) + '</small>' : '') + '</td>' +
             '<td>' + rdEsc(oficio.REGISTRO || '') + '</td><td>' + rdFecha(oficio.FECHA_ATENCION) + '</td>' +
             '<td class="rd-num-col">' + (abierta ? oficio.DIAS_ABIERTA : oficio.TIEMPO_CIERRE) + '</td><td>' + (ETIQUETA_RESULTADO[oficio.RESULTADO] || '') + '</td>' +
             '<td>' + (abierta ? tagA('Abierta', 'rd-tag-pend') + (oficio.PENDIENTE_DE ? '<br><small>' + rdEsc(cfgA.pendiente[oficio.PENDIENTE_DE]) + '</small>' : '') : tagA('Cerrada')) + '</td></tr>';
    }
    $('#admResumen').html(tagA(filas.length + ' comunicaciones') + ' ' + tagA(abiertas + ' abiertas', 'rd-tag-pend') + ' ' + (fuera ? tagA(fuera + ' fuera de tiempo', 'rd-tag-mal') : ''));
    $('#admFilas').html(html || vacioA(12, 'No hay comunicaciones con estos filtros.'));
}
