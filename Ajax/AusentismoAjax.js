//Ausentismo:la matriz novedades × días de cada cargo, como en el Excel,
//y el registro rápido: cómo se cubrió cada falta, una a la vez (día, cargo, qué pasó y personas)
var URL_AUS = '../Control/AusentismoControl.php';
var cfgAus = null, proyAus = null, centroAus = null, datosAus = null;
var cambiosAus = {};        //"cargo-novedad-dia" => cantidad (solo lo que cambió)
var DIA_SEMANA = ['D', 'L', 'M', 'M', 'J', 'V', 'S'];

//Ausentismo y Promesa de valor son las dos partes de la misma sección: al pasar de una a la
//otra (las pestañas de arriba) se lleva el proyecto, el mes y el año que se están viendo
function seccionDeLaDireccion(selector, nombre) {
    var encontrado = new RegExp('[?&]' + nombre + '=(\\d+)').exec(window.location.search);
    if (encontrado && $(selector + ' option[value="' + encontrado[1] + '"]').length) { $(selector).val(encontrado[1]); }
}
function seccionIrA(enlace, proyecto, mes, anio) {
    $(document).on('click', enlace, function (evento) {
        evento.preventDefault();
        window.location.href = $(this).attr('href') + '?proyecto=' + encodeURIComponent($(proyecto).val() || '') +
                               '&mes=' + encodeURIComponent($(mes).val() || '') + '&anio=' + encodeURIComponent($(anio).val() || '');
    });
}

$(function () {
    $.getJSON(URL_AUS + '?op=config')
        .done(function (config) {
            cfgAus = config;
            rdPeriodo('#aMes', '#aAnio', config.hoy);
            if (!config.veTodos) { $('#consAlcance option[value="todos"]').remove(); } //el consolidado de todos sus proyectos, si tiene más de uno
            var html = '';
            config.proyectos.forEach(function (proyecto) {
                html += '<option value="' + parseInt(proyecto.ID_PROYECTO, 10) + '">' + rdEsc(proyecto.NOM_PROYECTO) + (proyecto.COORDINA ? ' (el tuyo)' : (proyecto.EDITABLE && !proyecto.VE_PROYECTO ? ' (tu peaje)' : '')) + '</option>';
            });
            $('#aProyecto').html(html || '<option value="">No tienes proyectos</option>').val(String(config.proyectoInicial || ''));
            //Si viene de Promesa de valor, el mismo proyecto, mes y año
            seccionDeLaDireccion('#aProyecto', 'proyecto'); seccionDeLaDireccion('#aMes', 'mes'); seccionDeLaDireccion('#aAnio', 'anio');
            elegirProyectoAus();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir ausentismo.'); });

    rdVigilarCambios('#ausForm');
    seccionIrA('#irPromesa', '#aProyecto', '#aMes', '#aAnio');
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
    //Consolidado: el proyecto elegido, solo el peaje elegido o todos, y de qué día a qué día
    $('#consAlcance').on('change', cargarConsolidado);
    $('#consDesde, #consHasta').on('change', function () {
        var diaDesde = +$('#consDesde').val(), diaHasta = +$('#consHasta').val();
        if (diaDesde > diaHasta) { if (this.id === 'consDesde') { $('#consHasta').val(String(diaDesde)); } else { $('#consDesde').val(String(diaHasta)); } }
        cargarConsolidado();
    });
    $('.aus-rangos [data-rango]').on('click', function () {
        var dias = $('#consHasta option').length, rango = $(this).data('rango');
        $('#consDesde').val(String(rango === 'q2' ? 15 : 1));
        $('#consHasta').val(String(rango === 'q1' ? 14 : dias));
        cargarConsolidado();
    });
    $('#ausHistorial').on('toggle', function () { if (this.open) { cargarHistorial(); } });

    //Registro rápido: registrar, cambiar y borrar una ausencia
    $('#ausRapido').on('submit', registrarAusencia);
    $('#rCargo').on('change', function () { llenarMotivos('#rNovedad', +this.value, 0); }); //los motivos dependen del cargo
    $('#rFecha').on('change', function () {                                   //el último día no puede ser antes del primero
        $('#rHasta').attr('min', this.value || '');
        if ($('#rHasta').val() && $('#rHasta').val() < this.value) { $('#rHasta').val(''); }
    });
    $(document).on('change', '#cCargo', function () { llenarMotivos('#cNovedad', +this.value, 0); });
    $(document).on('click', '.btn-aus-cambiar', function () { cambiarAusencia(+$(this).data('cargo'), +$(this).data('novedad'), +$(this).data('dia')); });
    $(document).on('click', '.btn-aus-borrar', function () { borrarAusencia(+$(this).data('cargo'), +$(this).data('novedad'), +$(this).data('dia')); });
    $(document).on('submit', '#formAusCambio', guardarCambioAusencia);
});

//Si hay cambios sin guardar, pregunta antes de cambiar de centro o de mes
function confirmarSalida() {
    if (!Object.keys(cambiosAus).length) { return true; }
    if (window.confirm('Hay cambios sin guardar en este centro. ¿Continuar y perderlos?')) { cambiosAus = {}; rdCambiosGuardados(); return true; }
    return false;
}

//Al cambiar de proyecto: arma los botones de sus peajes
function elegirProyectoAus() {
    var id = +($('#aProyecto').val() || 0);
    proyAus = cfgAus.proyectos.filter(function (proyecto) { return +proyecto.ID_PROYECTO === id; })[0] || null;
    if (!proyAus) { $('#ausGrid').html('<p class="rd-vacio">No tienes proyectos para ver.</p>'); return; }
    var html = '';
    proyAus.CENTROS.forEach(function (centro) { html += '<option value="' + parseInt(centro.ID_CENTRO_OP, 10) + '">' + (centro.TIPO_CENTRO === 'BASCULA' ? 'Báscula ' : '') + rdEsc(centro.NOM_CENTRO_OP) + '</option>'; });
    $('#aCentro').html(html || '<option value="">El proyecto no tiene centros</option>');
    elegirCentroAus(+($('#aCentro').val() || 0));
}

//Al elegir un peaje: carga su mes
function elegirCentroAus(id) {
    centroAus = proyAus.CENTROS.filter(function (centro) { return +centro.ID_CENTRO_OP === id; })[0] || null;
    //En el consolidado, la opción de ver solo este peaje lleva su nombre
    $('#consAlcance option[value="centro"]').text(centroAus ? 'Solo ' + nombreCentro(centroAus) : 'Solo el peaje elegido');
    cargarAus();
}

//"Báscula X" o el nombre del peaje
function nombreCentro(centro) { return (centro.TIPO_CENTRO === 'BASCULA' ? 'Báscula ' : '') + centro.NOM_CENTRO_OP; }

//Proyecto, año y mes elegidos, listos para la URL
function periodoAus() { return '&proyecto=' + proyAus.ID_PROYECTO + '&anio=' + $('#aAnio').val() + '&mes=' + $('#aMes').val(); }

//Trae el mes del peaje elegido y pinta la grilla y los totales
function cargarAus() {
    if (!proyAus || !centroAus) { return; }
    $('#btnExcel').attr('href', 'AusentismoExcelVista.php?' + periodoAus().substring(1));
    $.getJSON(URL_AUS + '?op=mes&centro=' + centroAus.ID_CENTRO_OP + periodoAus())
        .done(function (respuesta) {
            datosAus = respuesta; cambiosAus = {}; rdCambiosGuardados();
            //Quién registra y en qué estado está el mes
            var estado = respuesta.cerrado ? '<span class="rd-tag rd-tag-mal"><i class="fa fa-lock"></i> Mes cerrado' + (respuesta.cierre && respuesta.cierre.QUIEN ? ' por ' + rdEsc(respuesta.cierre.QUIEN) + ' el ' + rdFecha(respuesta.cierre.FEC_MODIFICACION) : '') + '</span>'
                                : '<span class="rd-tag rd-tag-ok"><i class="fa fa-unlock"></i> Mes abierto</span>';
            $('#aQuien').html('<i class="fa fa-user"></i> Coordinador: <strong>' + rdEsc(respuesta.coordinador || 'sin asignar') + '</strong> ' + estado +
                              (respuesta.editable ? '' : ' <span class="adm-sello">' + rdEsc(respuesta.motivo) + '</span>'));
            $('#btnCerrar').toggle(!!respuesta.puedeCerrar);
            //El jefe de peaje ve solo su peaje: sin consolidado ni Excel del proyecto
            $('#tabConsLink').parent().toggle(!!respuesta.veProyecto);
            $('#btnExcel').toggle(!!respuesta.veProyecto);
            if (!respuesta.veProyecto && $('#tabConsolidado').hasClass('active')) { $('a[href="#tabRapido"]').tab('show'); }
            $('#ausNota').html(respuesta.editaTodo ? '<div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-unlock-alt"></i> Este mes está cerrado, pero tu permiso (25M) deja corregirlo. Cada cambio queda en el historial.</div>' : '');
            if ($('#ausHistorial').prop('open')) { cargarHistorial(); }
            $('#btnReabrir').toggle(!!(respuesta.cerrado && cfgAus.puedeReabrir));
            $('#ausAcciones').toggle(!!respuesta.editable);
            //Avance: qué centros ya tienen registrado el mes
            var html = '';
            proyAus.CENTROS.forEach(function (centro) {
                var avance = respuesta.avance[centro.ID_CENTRO_OP] || { celdas: 0 };
                html += '<span class="aus-centro-chip' + (+centro.ID_CENTRO_OP === +centroAus.ID_CENTRO_OP ? ' activo' : '') + (avance.celdas ? ' con' : '') + '" data-id="' + parseInt(centro.ID_CENTRO_OP, 10) + '" role="button" tabindex="0">' +
                     '<i class="fa ' + (avance.celdas ? 'fa-check' : 'fa-circle-o') + '"></i> ' + rdEsc(centro.NOM_CENTRO_OP) + '</span>';
            });
            $('#aCentros').html(html);
            pintarGrilla();
            pintarRapido(); //el formulario de una ausencia y la lista de las registradas
            if ($('#tabConsolidado').hasClass('active')) { cargarConsolidado(); }
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el ausentismo.'); });
}

//Los cargos que salen en el centro y las novedades de cada cargo
function cargosDelCentro(tipo) {
    var tipoCentro = tipo === 'BASCULA' ? 'BASCULA' : 'PEAJE';
    return cfgAus.cargos.filter(function (cargo) { return cargo.APLICA === 'AMBOS' || cargo.APLICA === tipoCentro; });
}
//Los motivos de un cargo. Los que ya no se usan (ESTADO 0) salen solo si hay datos con ellos en $reg
//(los registros de ese cargo en el mes): así los meses de antes se ven como se registraron
function novedadesDe(idCargo, registros) {
    return cfgAus.novedades.filter(function (novedad) {
        if (novedad.ID_CARGO_AUS && +novedad.ID_CARGO_AUS !== +idCargo) { return false; }
        return +novedad.ESTADO === 1 || conDatos(registros && registros[novedad.ID_NOVEDAD_AUS]);
    });
}
//¿Esta fila tiene al menos un día con valor?
function conDatos(vals) { for (var clave in (vals || {})) { if (+vals[clave]) { return true; } } return false; }
//El nombre de un motivo; si ya no se usa, lo dice
function nombreMotivo(novedad) { return rdEsc(novedad.NOMBRE) + (+novedad.ESTADO === 1 ? '' : ' <small style="color:#6B7076;font-weight:normal;">(motivo anterior)</small>'); }

//Encabezado de días: número y letra del día de la semana; fines de semana y hoy marcados.
//Va del día "desde" (1 si no se dice) al día "dias"
function cabeceraDias(dias, titulo, extra, desde) {
    var anio = +$('#aAnio').val(), mes = +$('#aMes').val(), html = '<thead><tr><th class="aus-fija">' + titulo + '</th>';
    for (var dia = desde || 1; dia <= dias; dia++) {
        var fecha = new Date(anio, mes - 1, dia), hoy = rdATexto(fecha) === cfgAus.hoy;
        html += '<th class="aus-dia' + (fecha.getDay() === 0 || fecha.getDay() === 6 ? ' aus-finde' : '') + (hoy ? ' aus-hoy' : '') + '">' + dia + '<small>' + DIA_SEMANA[fecha.getDay()] + '</small></th>';
    }
    return html + (extra || '<th class="aus-tot">Total</th>') + '</tr></thead>';
}

//Arma la grilla del mes: un bloque por cargo, una fila por motivo y una columna por día
function pintarGrilla() {
    var dias = datosAus.diasMes, editable = datosAus.editable, html = '', fila = 0;
    cargosDelCentro(centroAus.TIPO_CENTRO).forEach(function (cargo) {
        var registros = datosAus.registros[cargo.ID_CARGO_AUS] || {};
        html += '<div class="aus-bloque"><div class="table-responsive aus-scroll"><table class="aus-tabla" data-cargo="' + parseInt(cargo.ID_CARGO_AUS, 10) + '">' +
             cabeceraDias(dias, rdEsc(cargo.NOMBRE)) + '<tbody>';
        novedadesDe(cargo.ID_CARGO_AUS, registros).forEach(function (novedad) {
            var vals = registros[novedad.ID_NOVEDAD_AUS] || {}, cuenta = +novedad.CUENTA_AUSENCIA === 1;
            html += '<tr class="' + (cuenta ? '' : 'aus-no-cuenta') + '" data-cuenta="' + (cuenta ? 1 : 0) + '"><th class="aus-fija">' + nombreMotivo(novedad) + '</th>';
            for (var dia = 1; dia <= dias; dia++) {
                var valor = vals[dia] || '', tarde = rdATexto(new Date(+$('#aAnio').val(), +$('#aMes').val() - 1, dia)) > (cfgAus.limiteFuturo || cfgAus.hoy);
                html += '<td' + (tarde && editable ? ' class="aus-futuro" title="Todavía no se puede anotar este día"' : '') + '>' + (editable && !tarde ? '<input type="text" inputmode="numeric" maxlength="3" class="aus-celda" value="' + valor + '" data-c="' + parseInt(cargo.ID_CARGO_AUS, 10) +
                                     '" data-n="' + parseInt(novedad.ID_NOVEDAD_AUS, 10) + '" data-d="' + dia + '" data-f="' + fila + '" aria-label="' + rdEsc(novedad.NOMBRE + ' día ' + dia) + '">'
                                   : '<span class="aus-ver">' + valor + '</span>') + '</td>';
            }
            html += '<td class="aus-tot aus-tot-fila">0</td></tr>';
            fila++;
        });
        html += '</tbody><tfoot><tr><th class="aus-fija">Total del día</th>';
        for (var diaTotal = 1; diaTotal <= dias; diaTotal++) { html += '<td class="aus-tot-dia" data-d="' + diaTotal + '">0</td>'; }
        html += '<td class="aus-tot aus-tot-bloque">0</td></tr></tfoot></table></div></div>';
    });
    //Ausencias diarias: la suma de todos los cargos (sin las filas que no son ausencia)
    html += '<div class="aus-bloque aus-resumen"><div class="table-responsive aus-scroll"><table class="aus-tabla" id="ausDiarias">' +
         cabeceraDias(dias, 'Ausencias diarias', '<th class="aus-tot">Total</th><th class="aus-tot">1ª quincena</th>') + '<tbody><tr><th class="aus-fija">Todos los cargos</th>';
    for (var diaDiaria = 1; diaDiaria <= dias; diaDiaria++) { html += '<td class="aus-diaria" data-d="' + diaDiaria + '">0</td>'; }
    html += '<td class="aus-tot" id="ausTotalMes">0</td><td class="aus-tot" id="ausQuincena">0</td></tr></tbody></table></div></div>';
    $('#ausGrid').html(html);
    recalcular();
    //Deja a la vista el día de hoy
    var hoyTh = $('#ausGrid .aus-hoy').first();
    if (hoyTh.length) { $('.aus-scroll').each(function () { this.scrollLeft = Math.max(0, hoyTh.position().left - 260); }); }
}

//El número que hay en una celda (sea casilla o texto)
function valorCelda(elemento) { return parseInt($(elemento).is('input') ? $(elemento).val() : $(elemento).text(), 10) || 0; }

//Vuelve a sumar los totales por día, por fila y por bloque después de cada cambio
function recalcular() {
    var dias = datosAus.diasMes, diarias = {};
    $('#ausGrid .aus-tabla[data-cargo]').each(function () {
        var tabla = $(this), totDia = {}, totBloque = 0;
        tabla.find('tbody tr').each(function () {
            var fila = $(this), suma = 0, cuenta = fila.data('cuenta') === 1;
            fila.find('td').not('.aus-tot').each(function (i) {
                var valor = valorCelda($(this).find('.aus-celda, .aus-ver'));
                suma += valor;
                if (cuenta) { totDia[i + 1] = (totDia[i + 1] || 0) + valor; diarias[i + 1] = (diarias[i + 1] || 0) + valor; }
            });
            fila.find('.aus-tot-fila').text(suma);
            if (cuenta) { totBloque += suma; }
        });
        tabla.find('.aus-tot-dia').each(function () { var dia = +$(this).data('d'); $(this).text(totDia[dia] || 0).toggleClass('aus-hay', !!totDia[dia]); });
        tabla.find('.aus-tot-bloque').text(totBloque);
    });
    var mesTot = 0, quincena = 0;
    $('#ausDiarias .aus-diaria').each(function () {
        var dia = +$(this).data('d'), valor = diarias[dia] || 0;
        $(this).text(valor).toggleClass('aus-hay', valor > 0);
        mesTot += valor; if (dia <= 14) { quincena += valor; }
    });
    $('#ausTotalMes').text(mesTot); $('#ausQuincena').text(quincena);
    var cantidad = Object.keys(cambiosAus).length;
    $('#ausCambios').text(cantidad ? (cantidad === 1 ? '1 celda sin guardar' : cantidad + ' celdas sin guardar') : 'Todo guardado');
}

//Al escribir en una celda: deja solo números y anota el cambio para guardarlo después
function escribirCelda() {
    var valor = this.value.replace(/\D/g, '').substring(0, 3);
    if (valor !== this.value) { this.value = valor; }
    var clave = $(this).data('c') + '-' + $(this).data('n') + '-' + $(this).data('d');
    var antes = ((datosAus.registros[$(this).data('c')] || {})[$(this).data('n')] || {})[$(this).data('d')] || 0;
    if ((parseInt(valor, 10) || 0) === antes) { delete cambiosAus[clave]; $(this).removeClass('aus-cambio'); }
    else { cambiosAus[clave] = parseInt(valor, 10) || 0; $(this).addClass('aus-cambio'); }
    recalcular();
}

//Flechas y Enter para moverse entre celdas, como en una hoja de cálculo
function moverConFlechas(evento) {
    var numFila = +$(this).data('f'), dia = +$(this).data('d'), destino = null;
    if (evento.key === 'ArrowRight') { destino = [numFila, dia + 1]; }
    else if (evento.key === 'ArrowLeft') { destino = [numFila, dia - 1]; }
    else if (evento.key === 'ArrowDown' || evento.key === 'Enter') { destino = [numFila + 1, dia]; }
    else if (evento.key === 'ArrowUp') { destino = [numFila - 1, dia]; }
    if (!destino) { return; }
    var celda = $('.aus-celda[data-f="' + destino[0] + '"][data-d="' + destino[1] + '"]');
    if (celda.length) { evento.preventDefault(); celda.focus(); }
}

//Envía al servidor solo las celdas que cambiaron
function guardarAus() {
    var celdas = Object.keys(cambiosAus).map(function (clave) { var partes = clave.split('-'); return { cargo: +partes[0], novedad: +partes[1], dia: +partes[2], cantidad: cambiosAus[clave] }; });
    if (!celdas.length) { rdAviso('No hay cambios por guardar.'); return; }
    hanaBoton('#btnGuardarAus', true);
    $.post(URL_AUS + '?op=guardar&centro=' + centroAus.ID_CENTRO_OP + periodoAus(), { celdas: JSON.stringify(celdas) }, null, 'json')
        .done(function (respuesta) { cambiosAus = {}; rdCambiosGuardados(); rdAviso(respuesta.mensaje); cargarAus(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el ausentismo.')); })
        .always(function () { hanaBoton('#btnGuardarAus', false); });
}

//Cierra o reabre el mes (pide guardar antes si hay cambios)
function cierreAus(op, pregunta) {
    if (Object.keys(cambiosAus).length) { alert('Primero guarda los cambios de este centro.'); return; }
    hanaConfirmar(pregunta, function () {
        $.post(URL_AUS + '?op=' + op + periodoAus(), {}, null, 'json')
            .done(function (respuesta) { rdAviso(respuesta.mensaje); cargarAus(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo cambiar el estado del mes.')); });
    }, { aceptar: op === 'cerrar' ? 'Sí, cerrar' : 'Sí, reabrir', cancelar: 'No' });
}

//Historial: quién cambió qué celda, de cuánto a cuánto y cuándo
function cargarHistorial() {
    if (!proyAus || !centroAus) { return; }
    $.getJSON(URL_AUS + '?op=historial&centro=' + centroAus.ID_CENTRO_OP + periodoAus())
        .done(function (cambios) {
            var html = '';
            cambios.forEach(function (cambio) {
                html += '<tr><td class="rd-nowrap">' + rdFecha(cambio.CUANDO) + ' ' + rdHora(String(cambio.CUANDO).substring(11)) + '</td><td>' + rdEsc(cambio.QUIEN || '') + '</td>' +
                     '<td>Día ' + parseInt(cambio.DIA, 10) + ' · ' + rdEsc(cambio.CARGO) + ' · ' + rdEsc(cambio.NOVEDAD) + '</td>' +
                     '<td class="rd-num-col">' + parseInt(cambio.ANTES, 10) + ' → <strong>' + parseInt(cambio.DESPUES, 10) + '</strong></td></tr>';
            });
            $('#ausHistorialCuerpo').html(html ? '<div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta"><thead><tr><th>Cuándo</th><th>Quién</th><th>Qué celda</th><th class="rd-num-col">Cambio</th></tr></thead><tbody>' + html + '</tbody></table></div>'
                                           : '<p class="rd-vacio">Todavía no hay cambios registrados en este peaje este mes.</p>');
        })
        .fail(function (xhr) { $('#ausHistorialCuerpo').html('<p class="rd-ayuda">' + rdEsc(rdError(xhr, 'No se pudo cargar el historial.')) + '</p>'); });
}

//---------------------------------------------------------------------------
// Consolidado: la suma de todos los centros del proyecto (la última hoja del Excel),
// de un solo peaje o de todos los proyectos juntos, del día "desde" al día "hasta" del mes
//---------------------------------------------------------------------------
var consPedido = 0; //si se cambian los días muy rápido, solo se pinta la última respuesta

//Llena "desde" y "hasta" con los días del mes elegido. Si se estaba viendo todo el mes, sigue todo el mes
function diasConsolidado() {
    var dias = new Date(+$('#aAnio').val(), +$('#aMes').val(), 0).getDate();
    var antes = $('#consHasta option').length, diaDesde = +$('#consDesde').val() || 1, diaHasta = +$('#consHasta').val() || dias;
    if (!antes || (diaDesde === 1 && diaHasta === antes)) { diaDesde = 1; diaHasta = dias; }
    if (antes !== dias) {
        var html = '';
        for (var dia = 1; dia <= dias; dia++) { html += '<option value="' + dia + '">' + dia + '</option>'; }
        $('#consDesde, #consHasta').html(html);
    }
    diaHasta = Math.min(diaHasta, dias); diaDesde = Math.min(diaDesde, diaHasta);
    $('#consDesde').val(String(diaDesde)); $('#consHasta').val(String(diaHasta));
    //El botón del rango que se está viendo queda marcado
    $('.aus-rangos [data-rango]').removeClass('active');
    $('.aus-rangos [data-rango="' + (diaDesde === 1 && diaHasta === dias ? 'mes' : (diaDesde === 1 && diaHasta === 14 ? 'q1' : (diaDesde === 15 && diaHasta === dias ? 'q2' : ''))) + '"]').addClass('active');
}

//¿Se está viendo el consolidado de todos los proyectos? ¿O solo el del peaje elegido?
function consTodos() { return !!cfgAus.veTodos && $('#consAlcance').val() === 'todos'; }
function consCentro() { return !!centroAus && $('#consAlcance').val() === 'centro'; }

//Lo que se pide al servidor (y al Excel): el proyecto, el peaje o todos, el mes y los días
function filtroConsolidado() {
    return (consTodos() ? '&todos=1' : '&proyecto=' + proyAus.ID_PROYECTO + (consCentro() ? '&centro=' + centroAus.ID_CENTRO_OP : '')) +
           '&anio=' + $('#aAnio').val() + '&mes=' + $('#aMes').val() + '&desde=' + $('#consDesde').val() + '&hasta=' + $('#consHasta').val();
}

//Los días elegidos, en palabras: "todo el mes", "solo el día 1" o "del día 1 al 15"
function textoDias(diaDesde, diaHasta, dias) {
    if (diaDesde === 1 && diaHasta === dias) { return 'todo el mes'; }
    return diaDesde === diaHasta ? 'solo el día ' + diaDesde : 'del día ' + diaDesde + ' al ' + diaHasta;
}

function cargarConsolidado() {
    if (!proyAus) { return; }
    diasConsolidado();
    var diaDesde = +$('#consDesde').val(), diaHasta = +$('#consHasta').val(), todos = consTodos(), soloCentro = consCentro(), filtro = filtroConsolidado(), pedido = ++consPedido;
    $('#btnExcelCons').attr('href', 'AusentismoExcelVista.php?' + filtro.substring(1));
    $.getJSON(URL_AUS + '?op=consolidado' + filtro)
        .done(function (respuesta) {
            if (pedido !== consPedido) { return; } //llegó tarde: ya se pidió otro rango
            var todoMes = diaDesde === 1 && diaHasta === respuesta.diasMes, html = '', totNov = {}, quiNov = {}, diarias = {};
            //Por cargo, día por día: una fila por motivo, una columna por día del rango y el total de cada día
            cfgAus.cargos.forEach(function (cargo) {
                var registros = respuesta.porCargo[cargo.ID_CARGO_AUS], totDia = {}, totBloque = 0;
                if (!registros) { return; } //solo los cargos con datos
                html += '<div class="aus-bloque"><div class="table-responsive aus-scroll"><table class="aus-tabla">' + cabeceraDias(diaHasta, rdEsc(cargo.NOMBRE), null, diaDesde) + '<tbody>';
                novedadesDe(cargo.ID_CARGO_AUS, registros).forEach(function (novedad) {
                    var vals = registros[novedad.ID_NOVEDAD_AUS] || {}, suma = 0, cuenta = +novedad.CUENTA_AUSENCIA === 1;
                    html += '<tr class="' + (cuenta ? '' : 'aus-no-cuenta') + '"><th class="aus-fija">' + nombreMotivo(novedad) + '</th>';
                    for (var diaCelda = diaDesde; diaCelda <= diaHasta; diaCelda++) {
                        var valor = vals[diaCelda] || 0; suma += valor;
                        if (cuenta) {
                            totNov[novedad.ID_NOVEDAD_AUS] = (totNov[novedad.ID_NOVEDAD_AUS] || 0) + valor;
                            if (diaCelda <= 14) { quiNov[novedad.ID_NOVEDAD_AUS] = (quiNov[novedad.ID_NOVEDAD_AUS] || 0) + valor; }
                            totDia[diaCelda] = (totDia[diaCelda] || 0) + valor; diarias[diaCelda] = (diarias[diaCelda] || 0) + valor;
                        }
                        html += '<td class="' + (valor ? 'aus-hay' : '') + '">' + (valor || '') + '</td>';
                    }
                    if (cuenta) { totBloque += suma; }
                    html += '<td class="aus-tot">' + suma + '</td></tr>';
                });
                html += '</tbody><tfoot><tr><th class="aus-fija">Total del día</th>';
                for (var diaTotal = diaDesde; diaTotal <= diaHasta; diaTotal++) { html += '<td class="' + (totDia[diaTotal] ? 'aus-hay' : '') + '">' + (totDia[diaTotal] || 0) + '</td>'; }
                html += '<td class="aus-tot">' + totBloque + '</td></tr></tfoot></table></div></div>';
            });
            //Ausencias diarias: la suma de todos los cargos, día por día
            if (html) {
                var totalRango = 0, totalQuincena = 0;
                html += '<div class="aus-bloque aus-resumen"><div class="table-responsive aus-scroll"><table class="aus-tabla">' +
                     cabeceraDias(diaHasta, 'Ausencias diarias', '<th class="aus-tot">Total</th>' + (todoMes ? '<th class="aus-tot">1ª quincena</th>' : ''), diaDesde) + '<tbody><tr><th class="aus-fija">Todos los cargos</th>';
                for (var dia = diaDesde; dia <= diaHasta; dia++) { var valorDia = diarias[dia] || 0; totalRango += valorDia; if (dia <= 14) { totalQuincena += valorDia; } html += '<td>' + valorDia + '</td>'; }
                html += '<td class="aus-tot">' + totalRango + '</td>' + (todoMes ? '<td class="aus-tot">' + totalQuincena + '</td>' : '') + '</tr></tbody></table></div></div>';
            }
            //TOTAL por novedad. Todo el mes: el mes y la primera quincena, como la columna AI del Excel
            var htmlTotal = '<table class="table table-bordered rd-tabla rd-tabla-compacta aus-total"><thead><tr><th>Novedad</th><th class="rd-num-col">' +
                    (todoMes ? 'Total del mes' : (diaDesde === diaHasta ? 'Día ' + diaDesde : 'Días ' + diaDesde + ' a ' + diaHasta)) + '</th>' + (todoMes ? '<th class="rd-num-col">1ª quincena (días 1 a 14)</th>' : '') + '</tr></thead><tbody>';
            var granTotal = 0, granTotalQuincena = 0;
            cfgAus.novedades.filter(function (novedadTotal) { return +novedadTotal.CUENTA_AUSENCIA && (+novedadTotal.ESTADO === 1 || totNov[novedadTotal.ID_NOVEDAD_AUS]); }).forEach(function (novedadTotal) {
                var totalNovedad = totNov[novedadTotal.ID_NOVEDAD_AUS] || 0, quincenaNovedad = quiNov[novedadTotal.ID_NOVEDAD_AUS] || 0; granTotal += totalNovedad; granTotalQuincena += quincenaNovedad;
                htmlTotal += '<tr><td>' + rdEsc(novedadTotal.NOMBRE) + '</td><td class="rd-num-col">' + totalNovedad + '</td>' + (todoMes ? '<td class="rd-num-col">' + quincenaNovedad + '</td>' : '') + '</tr>';
            });
            htmlTotal += '</tbody><tfoot><tr><th>TOTAL</th><th class="rd-num-col">' + granTotal + '</th>' + (todoMes ? '<th class="rd-num-col">' + granTotalQuincena + '</th>' : '') + '</tr></tfoot></table>';
            //Los proyectos y los centros que se suman (con todos los proyectos, cada centro con su proyecto)
            var proys = todos ? cfgAus.proyectos : [proyAus], filasCentro = [];
            proys.forEach(function (proyecto) { proyecto.CENTROS.forEach(function (centro) { if (!soloCentro || +centro.ID_CENTRO_OP === +centroAus.ID_CENTRO_OP) { filasCentro.push({ p: proyecto, c: centro }); } }); });
            //Las columnas: los motivos de hoy y los anteriores que en esos días tienen datos en algún centro
            var novedadesVisibles = cfgAus.novedades.filter(function (novedad) {
                return +novedad.ESTADO === 1 || Object.keys(respuesta.porCentro).some(function (clave) { return +((respuesta.porCentro[clave] || {})[novedad.ID_NOVEDAD_AUS] || 0); });
            });
            var cabecera = function (primeras) {
                var htmlTabla = '<div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta"><thead><tr>' + primeras;
                novedadesVisibles.forEach(function (novedad) { htmlTabla += '<th class="rd-num-col">' + rdEsc(novedad.NOMBRE) + '</th>'; });
                return htmlTabla + '<th class="rd-num-col">Ausencias</th></tr></thead><tbody>';
            };
            //Una fila de la tabla: el valor de cada motivo y las ausencias (solo los motivos que cuentan); suma en "tot" para la fila TOTAL
            var fila = function (primeras, valores, total) {
                var ausencias = 0, htmlFila = '<tr>' + primeras;
                novedadesVisibles.forEach(function (novedad) {
                    var valor = valores[novedad.ID_NOVEDAD_AUS] || 0;
                    total[novedad.ID_NOVEDAD_AUS] = (total[novedad.ID_NOVEDAD_AUS] || 0) + valor;
                    if (+novedad.CUENTA_AUSENCIA) { ausencias += valor; }
                    htmlFila += '<td class="rd-num-col">' + (valor || '') + '</td>';
                });
                total.aus = (total.aus || 0) + ausencias;
                return htmlFila + '<td class="rd-num-col"><strong>' + ausencias + '</strong></td></tr>';
            };
            var pie = function (colspan, total) {
                var htmlPie = '</tbody><tfoot><tr><th' + (colspan > 1 ? ' colspan="' + colspan + '"' : '') + '>TOTAL</th>';
                novedadesVisibles.forEach(function (novedad) { htmlPie += '<th class="rd-num-col">' + (total[novedad.ID_NOVEDAD_AUS] || '') + '</th>'; });
                return htmlPie + '<th class="rd-num-col">' + (total.aus || 0) + '</th></tr></tfoot></table></div>';
            };
            //Por proyecto (solo con todos los proyectos)
            var htmlPorProyecto = '';
            if (todos) {
                var totP = {};
                htmlPorProyecto = cabecera('<th>Proyecto</th>');
                cfgAus.proyectos.forEach(function (proyecto) { htmlPorProyecto += fila('<td>' + rdEsc(proyecto.NOM_PROYECTO) + '</td>', respuesta.porProyecto[proyecto.ID_PROYECTO] || {}, totP); });
                htmlPorProyecto += pie(1, totP);
            }
            //Por centro. Si hay datos de centros que ya no están activos, van juntos en una fila para que la suma cuadre
            var totC = {}, vistos = {}, htmlPorCentro = cabecera((todos ? '<th>Proyecto</th>' : '') + '<th>Centro</th>');
            filasCentro.forEach(function (filaCentro) {
                vistos[filaCentro.c.ID_CENTRO_OP] = 1;
                htmlPorCentro += fila((todos ? '<td>' + rdEsc(filaCentro.p.NOM_PROYECTO) + '</td>' : '') + '<td>' + rdEsc(filaCentro.c.NOM_CENTRO_OP) + '</td>', respuesta.porCentro[filaCentro.c.ID_CENTRO_OP] || {}, totC);
            });
            var otros = {}, hayOtros = false;
            Object.keys(respuesta.porCentro).forEach(function (clave) {
                if (vistos[clave]) { return; }
                hayOtros = true;
                Object.keys(respuesta.porCentro[clave]).forEach(function (idNovedad) { otros[idNovedad] = (otros[idNovedad] || 0) + (+respuesta.porCentro[clave][idNovedad] || 0); });
            });
            if (hayOtros) { htmlPorCentro += fila('<td' + (todos ? ' colspan="2"' : '') + '><em>Centros que ya no están activos</em></td>', otros, totC); }
            htmlPorCentro += pie(todos ? 2 : 1, totC);
            //Con un solo peaje no hace falta la tabla por centro: sería una sola fila igual al total
            var nombre = todos ? 'todos los proyectos' : (soloCentro ? rdEsc(nombreCentro(centroAus)) + ' <small>(' + rdEsc(proyAus.NOM_PROYECTO) + ')</small>' : rdEsc(proyAus.NOM_PROYECTO));
            $('#ausConsolidado').html('<h4 class="rd-subtitulo" style="margin-top:0">Total de ' + nombre + ' <small>· ' + textoDias(diaDesde, diaHasta, respuesta.diasMes) + '</small></h4>' + htmlTotal +
                (todos ? '<h4 class="rd-subtitulo">Por proyecto</h4>' + htmlPorProyecto : '') +
                (soloCentro ? '' : '<h4 class="rd-subtitulo">Por centro</h4>' + htmlPorCentro) +
                '<h4 class="rd-subtitulo">Por cargo, día por día</h4>' + (html || '<p class="rd-vacio">Sin datos en esos días.</p>'));
        })
        .fail(function (xhr) { if (pedido === consPedido) { hanaErrorAjax(xhr, 'No se pudo cargar el consolidado.'); } });
}

//===========================================================================
// Registro rápido: una ausencia a la vez
//   Se elige el día, el cargo, el motivo y cuántas personas. El servidor lo
//   anota en la misma celda de la matriz (y en el historial), así que el
//   consolidado y el Excel no cambian.
//===========================================================================
function nombreCargoAus(id) {
    var cargo = cfgAus.cargos.filter(function (item) { return +item.ID_CARGO_AUS === +id; })[0];
    return cargo ? cargo.NOMBRE : 'Cargo ' + id; //un cargo desactivado ya no viene en la lista
}
//Busca un motivo de ausencia por su id
function novedadAus(id) {
    return cfgAus.novedades.filter(function (item) { return +item.ID_NOVEDAD_AUS === +id; })[0] || null;
}
//"2026-10" del mes que se está viendo
function mesVistoAus() { return $('#aAnio').val() + '-' + rdDos(+$('#aMes').val()); }

//Llena un selector de cargos con los que aplican al centro
function llenarCargos(seleccion, elegido) {
    var html = '<option value="">Elige el cargo</option>';
    cargosDelCentro(centroAus.TIPO_CENTRO).forEach(function (cargo) {
        html += '<option value="' + parseInt(cargo.ID_CARGO_AUS, 10) + '"' + (+cargo.ID_CARGO_AUS === +elegido ? ' selected' : '') + '>' + rdEsc(cargo.NOMBRE) + '</option>';
    });
    $(seleccion).html(html);
}
//Llena un selector de motivos con los que aplican a ese cargo
function llenarMotivos(seleccion, idCargo, elegido) {
    var html = '<option value="">' + (idCargo ? 'Elige qué pasó' : 'Primero elige el cargo') + '</option>';
    if (idCargo) {
        //Para escoger, solo los motivos de hoy (y el que ya tenía, si se está corrigiendo uno anterior)
        cfgAus.novedades.filter(function (novedad) {
            return (!novedad.ID_CARGO_AUS || +novedad.ID_CARGO_AUS === +idCargo) && (+novedad.ESTADO === 1 || +novedad.ID_NOVEDAD_AUS === +elegido);
        }).forEach(function (novedad) {
            html += '<option value="' + parseInt(novedad.ID_NOVEDAD_AUS, 10) + '"' + (+novedad.ID_NOVEDAD_AUS === +elegido ? ' selected' : '') + '>' +
                 rdEsc(novedad.NOMBRE) + (+novedad.CUENTA_AUSENCIA ? '' : ' (no cuenta como ausencia)') + '</option>';
        });
    }
    $(seleccion).html(html);
}

//Deja listo el formulario para el centro y el mes que se están viendo, y pinta la lista
function pintarRapido() {
    var mes = mesVistoAus();
    var primero = mes + '-01', ultimo = mes + '-' + rdDos(datosAus.diasMes);
    //El día arranca en hoy si hoy es de este mes; si no, en el primero del mes.
    //Se puede anotar hasta unos días adelante (una incapacidad que ya se sabe): el servidor dice hasta cuándo
    var limite = cfgAus.limiteFuturo || cfgAus.hoy;
    $('#rFecha').attr({ min: primero, max: ultimo < limite ? ultimo : limite }).val(cfgAus.hoy.substring(0, 7) === mes ? cfgAus.hoy : (primero > limite ? '' : primero));
    $('#rHasta').attr({ min: $('#rFecha').val() || primero, max: limite }).val('');
    $('#ausFuturoAyuda').text(+cfgAus.diasFuturo > 0 ? 'Se puede anotar hasta el ' + rdFecha(limite) + ' (' + cfgAus.diasFuturo + (+cfgAus.diasFuturo === 1 ? ' día' : ' días') + ' adelante).' : '');
    llenarCargos('#rCargo', 0);
    llenarMotivos('#rNovedad', 0, 0);
    $('#rCantidad').val(1);
    $('#ausRapido').toggle(!!datosAus.editable); //mes cerrado o solo consulta: no se registra
    $('#ausNotaRapido').html(datosAus.editable ? '' : '<div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-lock"></i> ' + rdEsc(datosAus.motivo) + '</div>');
    pintarAusencias();
}

//La lista de ausencias del mes: una fila por cada celda de la matriz que tiene personas
function pintarAusencias() {
    var filas = [];
    $.each(datosAus.registros || {}, function (idCargo, porNovedad) {
        $.each(porNovedad, function (idNovedad, porDia) {
            $.each(porDia, function (dia, cantidad) {
                if (+cantidad > 0) { filas.push({ cargo: +idCargo, novedad: +idNovedad, dia: +dia, cantidad: +cantidad }); }
            });
        });
    });
    filas.sort(function (primera, segunda) { return segunda.dia - primera.dia || primera.cargo - segunda.cargo || primera.novedad - segunda.novedad; }); //lo más reciente arriba

    var mes = mesVistoAus(), html = '', total = 0;
    filas.forEach(function (fila) {
        var novedad = novedadAus(fila.novedad), fecha = rdAFecha(mes + '-' + rdDos(fila.dia));
        var cuenta = !novedad || +novedad.CUENTA_AUSENCIA === 1;
        if (cuenta) { total += fila.cantidad; }
        var datos = ' data-cargo="' + fila.cargo + '" data-novedad="' + fila.novedad + '" data-dia="' + fila.dia + '"';
        html += '<tr><td class="aus-lista-dia">' + RD_DIAS_SEMANA[fecha.getDay()] + ' ' + rdDos(fila.dia) + '/' + mes.substring(5) + '</td>' +
             '<td>' + rdEsc(nombreCargoAus(fila.cargo)) + '</td>' +
             '<td' + (cuenta ? '' : ' class="aus-no-cuenta"') + '>' + (novedad ? nombreMotivo(novedad) : rdEsc('Motivo ' + fila.novedad)) + (cuenta ? '' : ' (no cuenta como ausencia)') + '</td>' +
             '<td class="rd-num-col"><strong>' + fila.cantidad + '</strong></td>' +
             '<td class="rd-nowrap">' + (datosAus.editable
                 ? '<button type="button" class="btn btn-default btn-xs btn-aus-cambiar"' + datos + '><i class="fa fa-pencil"></i> Cambiar</button> ' +
                   '<button type="button" class="btn btn-default btn-xs btn-aus-borrar"' + datos + '><i class="fa fa-trash"></i> Borrar</button>'
                 : '') + '</td></tr>';
    });
    $('#ausLista').html(html || '<tr><td colspan="5" class="rd-vacio">Este peaje no tiene ausencias registradas en el mes.</td></tr>');
    $('#ausTotalMes').html(filas.length ? '<span class="rd-tag rd-tag-pend">' + total + (total === 1 ? ' ausencia' : ' ausencias') + '</span>' : '');
}

//La matriz y el registro rápido escriben en las mismas celdas: no se mezclan cambios a medias
function matrizSinGuardar() {
    if (!Object.keys(cambiosAus).length) { return false; }
    alert('Tienes cambios sin guardar en la matriz del mes. Guárdalos primero.');
    return true;
}

//URL del controlador con el peaje y el periodo elegidos
function urlAusencia(op) { return URL_AUS + '?op=' + op + '&centro=' + centroAus.ID_CENTRO_OP + periodoAus(); }

//Registra la ausencia de una persona en uno o varios días
function registrarAusencia(evento) {
    evento.preventDefault();
    if (matrizSinGuardar()) { return; }
    if (!$('#rFecha').val()) { alert('Elige el día de la ausencia.'); return; }
    if ($('#rHasta').val() && $('#rHasta').val() < $('#rFecha').val()) { alert('El último día no puede ser antes del primero.'); return; }
    if (!$('#rCargo').val()) { alert('Elige el cargo.'); return; }
    if (!$('#rNovedad').val()) { alert('Elige qué pasó.'); return; }
    hanaBoton('#btnRegistrarAus', true);
    $.post(urlAusencia('ausencia'), $('#ausRapido').serialize(), null, 'json')
        .done(function (respuesta) { rdAviso(respuesta.mensaje); cargarAus(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo registrar la ausencia.')); })
        .always(function () { hanaBoton('#btnRegistrarAus', false); });
}

//Ventana para cambiar una ausencia: el día, el cargo, el motivo o cuántas personas
function cambiarAusencia(cargo, novedad, dia) {
    if (matrizSinGuardar()) { return; }
    var mes = mesVistoAus(), fecha = mes + '-' + rdDos(dia);
    var cantidad = ((datosAus.registros[cargo] || {})[novedad] || {})[dia] || 1;
    var html = '<form id="formAusCambio" autocomplete="off">' +
            //La ausencia como está hoy, para que el servidor sepa cuál se cambia
            '<input type="hidden" name="fecha0" value="' + fecha + '"><input type="hidden" name="cargo0" value="' + cargo + '"><input type="hidden" name="novedad0" value="' + novedad + '">' +
            '<div class="row"><div class="form-group col-sm-6"><label for="cFecha">Día:</label>' +
            '<input type="date" id="cFecha" name="fecha" class="form-control" min="' + mes + '-01" max="' + ((mes + '-' + rdDos(datosAus.diasMes)) < (cfgAus.limiteFuturo || cfgAus.hoy) ? mes + '-' + rdDos(datosAus.diasMes) : (cfgAus.limiteFuturo || cfgAus.hoy)) + '" value="' + fecha + '"></div>' +
            '<div class="form-group col-sm-6"><label for="cCantidad">Personas:</label>' +
            '<input type="number" id="cCantidad" name="cantidad" class="form-control" min="1" max="999" value="' + parseInt(cantidad, 10) + '"></div></div>' +
            '<div class="form-group"><label for="cCargo">Cargo:</label><select id="cCargo" name="cargo" class="form-control"></select></div>' +
            '<div class="form-group"><label for="cNovedad">¿Qué pasó?</label><select id="cNovedad" name="novedad" class="form-control"></select></div>' +
            '<p class="rd-ayuda">El cambio queda en el historial, con tu nombre y la hora.</p>' +
            '<div class="rd-acciones"><button type="submit" class="btn btn-success" id="btnGuardarCambioAus"><i class="fa fa-save"></i> Guardar cambio</button></div></form>';
    var ventana = $('#modalAus');
    if (!ventana.length) {
        ventana = $('<div class="modal fade" id="modalAus" tabindex="-1" role="dialog"><div class="modal-dialog" role="document"><div class="modal-content">' +
              '<div class="modal-header rd-modal-cab"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>' +
              '<h4 class="modal-title">Cambiar la ausencia</h4></div><div class="modal-body" id="modalAusCuerpo"></div></div></div></div>').appendTo('body');
    }
    $('#modalAusCuerpo').html(html);
    llenarCargos('#cCargo', cargo);
    llenarMotivos('#cNovedad', cargo, novedad);
    ventana.modal('show');
}

//Guarda el cambio de cargo o motivo de una ausencia ya registrada
function guardarCambioAusencia(evento) {
    evento.preventDefault();
    if (!$('#cCargo').val() || !$('#cNovedad').val()) { alert('Elige el cargo y qué pasó.'); return; }
    hanaBoton('#btnGuardarCambioAus', true);
    $.post(urlAusencia('ausenciaCambiar'), $(this).serialize(), null, 'json')
        .done(function (respuesta) { $('#modalAus').modal('hide'); rdAviso(respuesta.mensaje); cargarAus(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo cambiar la ausencia.')); })
        .always(function () { hanaBoton('#btnGuardarCambioAus', false); });
}

//Borra una ausencia de un día (pregunta antes)
function borrarAusencia(cargo, novedad, dia) {
    if (matrizSinGuardar()) { return; }
    var novedadElegida = novedadAus(novedad);
    hanaConfirmar('¿Borrar la ausencia del día ' + dia + ' (' + nombreCargoAus(cargo) + ', ' + (novedadElegida ? novedadElegida.NOMBRE : 'motivo ' + novedad) + ')? Queda anotado en el historial.', function () {
        $.post(urlAusencia('ausenciaBorrar'), { fecha: mesVistoAus() + '-' + rdDos(dia), cargo: cargo, novedad: novedad }, null, 'json')
            .done(function (respuesta) { rdAviso(respuesta.mensaje); cargarAus(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo borrar la ausencia.')); });
    }, { aceptar: 'Sí, borrar', cancelar: 'No' });
}
