//Ausentismo - Promesa de valor en horas (va dentro de la sección Ausentismo)
//Una matriz por peaje como la hoja del Excel: por cada cargo, las filas
//Promesa, Ejecutado, Diferencia (promesa − ejecutado) y Justificación,
//los días del mes y los totales de cada semana
var URL_PV = '../Control/PromesaValorControl.php';
var cfgP = null;      //lo de op=config
var datosP = null;    //la matriz que se está viendo (op=matriz)
var valoresP = {};    //[idCargo][dia] = {p, e, j} como están en pantalla
var guardadosP = {};  //[idCargo][dia] = {p, e, j} como están en la base
var autoresP = {};    //[idCargo][dia] = {quien, cuando}: quién hizo el último cambio de esa casilla
var NOMBRE_DIA_CORTO = ['', 'L', 'M', 'X', 'J', 'V', 'S', 'D'];

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
    //Si viene de un aviso de la campana: ?centro=&anio=&mes=
    var centroAviso = /[?&]centro=(\d+)/.exec(window.location.search);
    centroAviso = centroAviso ? +centroAviso[1] : 0;
    $.getJSON(URL_PV + '?op=config' + (centroAviso ? '&centro=' + centroAviso : ''))
        .done(function (config) {
            cfgP = config;
            var html = '';
            config.proyectos.forEach(function (proyecto) {
                html += '<option value="' + parseInt(proyecto.ID_PROYECTO, 10) + '">' + rdEsc(proyecto.NOM_PROYECTO) + (proyecto.PROPIO ? ' (el tuyo)' : '') + '</option>';
            });
            $('#pvProyecto').html(html || '<option value="">No hay proyectos para mostrar</option>');
            //Arranca en el proyecto que coordina
            var propio = config.proyectos.filter(function (proyecto) { return proyecto.PROPIO; })[0];
            if (propio) { $('#pvProyecto').val(String(parseInt(propio.ID_PROYECTO, 10))); }
            //Años: el anterior, el actual y el siguiente (la promesa se puede dejar lista)
            var anio = +config.hoy.substring(0, 4), htmlAnios = '';
            for (var a = anio + 1; a >= anio - 1; a--) { htmlAnios += '<option value="' + a + '">' + a + '</option>'; }
            $('#pvAnio').html(htmlAnios).val(String(anio));
            $('#pvMes').val(String(+config.hoy.substring(5, 7)));
            //Si viene de Ausentismo, el mismo proyecto, mes y año
            seccionDeLaDireccion('#pvProyecto', 'proyecto'); seccionDeLaDireccion('#pvMes', 'mes'); seccionDeLaDireccion('#pvAnio', 'anio');
            if (config.proyectoDelCentro) { $('#pvProyecto').val(String(config.proyectoDelCentro)); }
            cargar(config.proyectoDelCentro ? centroAviso : 0);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir la promesa de valor.'); });

    rdVigilarCambios('#formPromesa');
    seccionIrA('#irAusentismo', '#pvProyecto', '#pvMes', '#pvAnio');
    $('#pvProyecto, #pvMes, #pvAnio').on('change', function () { if (puedeSalir()) { cargar(0); } else { volverFiltros(); } });
    $(document).on('click', '.pv-btn-centro', function () { if (puedeSalir()) { cargar($(this).data('id')); } });
    $(document).on('input', '.pv-in', function () {
        var campo = $(this);
        valoresP[campo.data('cargo')][campo.data('dia')][campo.data('campo')] = $.trim(campo.val());
        repintarCargo(campo.data('cargo'));
    });
    $(document).on('click', '.pv-jcelda.pv-editable', function () { editarJustificacion($(this).data('cargo'), $(this).data('dia'), this); });
    //Quien solo consulta también lee el comentario: al tocar la casilla se abre, sin poder cambiarlo
    $(document).on('click', '.pv-jcelda.pv-con-texto:not(.pv-editable)', function () { verJustificacion($(this).data('cargo'), $(this).data('dia'), this); });
    $(document).on('click', '.pv-ir-dia', function () { irAlDia($(this).data('cargo'), $(this).data('dia')); });
    $('#pvCopiar').on('click', copiarSemana);
    $('#pvGuardar').on('click', guardar);
    $('#formPvRapido').on('submit', registrarRapido);
    $('#rpDesde').on('change', function () {                                   //el último día no puede ser antes del primero
        $('#rpHasta').attr('min', this.value || $('#rpDesde').attr('min'));
        if ($('#rpHasta').val() && $('#rpHasta').val() < this.value) { $('#rpHasta').val(''); }
    });
});

//Si hay cambios sin guardar, pregunta antes de cambiar de peaje, proyecto o mes
function puedeSalir() {
    return !hayCambios() || window.confirm('Hay casillas sin guardar. ¿Salir y perderlas?');
}
function volverFiltros() {
    if (!datosP) { return; }
    $('#pvMes').val(String(datosP.mes)); $('#pvAnio').val(String(datosP.anio)); $('#pvProyecto').val(String(datosP.proyecto));
}

//Trae la matriz del mes de un peaje (0 = el primero del proyecto)
function cargar(idCentro) {
    var proyecto = $('#pvProyecto').val();
    if (!proyecto) { $('#pvMatriz').html('<p class="rd-vacio">No hay proyectos para mostrar.</p>'); return; }
    var mes = $('#pvMes').val(), anio = $('#pvAnio').val();
    $('#pvMatriz').html('<p class="rd-vacio">Cargando…</p>');
    $.getJSON(URL_PV + '?op=matriz&proyecto=' + proyecto + '&centro=' + (idCentro || 0) + '&mes=' + mes + '&anio=' + anio)
        .done(function (respuesta) {
            datosP = respuesta; datosP.mes = +mes; datosP.anio = +anio; datosP.proyecto = +proyecto;
            if (!respuesta.centros.length) {
                $('#pvCentros, #pvQuien').html(''); $('#pvAcciones').hide();
                $('#pvMatriz').html('<p class="rd-vacio">Este proyecto no tiene peajes activos.</p>');
                return;
            }
            //Lo guardado, por cargo y día
            valoresP = {}; guardadosP = {}; autoresP = {};
            respuesta.cargos.forEach(function (cargo) {
                var id = parseInt(cargo.ID_CARGO_AUS, 10);
                valoresP[id] = {}; guardadosP[id] = {};
                respuesta.dias.forEach(function (d) { valoresP[id][d.dia] = { p: '', e: '', j: '' }; guardadosP[id][d.dia] = { p: '', e: '', j: '' }; });
            });
            respuesta.casillas.forEach(function (casilla) {
                var id = parseInt(casilla.ID_CARGO_AUS, 10), dia = parseInt(casilla.DIA, 10);
                if (!valoresP[id]) { return; } //un cargo que ya no aplica a este peaje
                var v = { p: textoHoras(casilla.PROMESA_HORAS), e: textoHoras(casilla.EJECUTADO_HORAS), j: casilla.JUSTIFICACION || '' };
                valoresP[id][dia] = $.extend({}, v); guardadosP[id][dia] = $.extend({}, v);
                autoresP[id] = autoresP[id] || {};
                autoresP[id][dia] = { quien: casilla.MODIFICO || '', cuando: casilla.FEC_MODIFICACION || '' };
            });
            pintarCentros();
            pintar();
            pintarComentarios();
            rdCambiosGuardados();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar la promesa de valor.'); });
}

//Horas guardadas ("8.00") como se muestran ("8"; "7,5")
function textoHoras(valor) {
    if (valor === null || valor === undefined || valor === '') { return ''; }
    return String(Math.round(parseFloat(valor) * 100) / 100).replace('.', ',');
}
//Lo escrito en una casilla como número (null si está vacía o no es un número)
function numero(texto) {
    texto = String(texto == null ? '' : texto).replace(',', '.').trim();
    if (texto === '' || isNaN(texto)) { return null; }
    return Math.round(parseFloat(texto) * 100) / 100;
}
function mostrar(n) { return n === null ? '' : String(Math.round(n * 100) / 100).replace('.', ','); }

//Cómo quedó un día: CUMPLE, DEBAJO, ENCIMA, SIN_REGISTRO o null (sin promesa)
function estadoDia(v) {
    var p = numero(v.p), e = numero(v.e);
    if (p === null) { return null; }
    if (e === null) { return 'SIN_REGISTRO'; }
    var d = Math.round((p - e) * 100) / 100;
    return d === 0 ? 'CUMPLE' : (d > 0 ? 'DEBAJO' : 'ENCIMA');
}
var CLASE_ESTADO = { CUMPLE: 'pv-cumple', DEBAJO: 'pv-debajo', ENCIMA: 'pv-encima' };

//¿Se puede escribir en ese día? (promesa y justificación desde "desde"; ejecutado, además, hasta hoy)
function editable(fecha, campo) {
    if (!datosP.desde || fecha < datosP.desde) { return false; }
    return campo !== 'e' || fecha <= datosP.hoy;
}

//Los botones de los peajes del proyecto, con cómo va el mes
function pintarCentros() {
    var html = '';
    datosP.centros.forEach(function (centro) {
        var id = parseInt(centro.ID_CENTRO_OP, 10), r = centro.RESUMEN, marca = '';
        if (r && r.sinJustificar > 0) { marca = '<span class="pv-marca pv-marca-mal" title="Días sin justificar">' + r.sinJustificar + '</span>'; }
        else if (r && (r.debajo + r.encima) > 0) { marca = '<span class="pv-marca pv-marca-ok" title="Todo justificado"><i class="fa fa-check"></i></span>'; }
        else if (!r || !r.conPromesa) { marca = '<span class="pv-marca" title="Sin promesa este mes">–</span>'; }
        html += '<button type="button" class="btn btn-default pv-btn-centro' + (id === parseInt(datosP.centro.ID_CENTRO_OP, 10) ? ' active' : '') + '" data-id="' + id + '">' +
                rdEsc(centro.NOM_CENTRO_OP) + (centro.TIPO_CENTRO === 'BASCULA' ? ' <small>(báscula)</small>' : '') + ' ' + marca + '</button>';
    });
    $('#pvCentros').html(html);
    var quien = datosP.coordinador ? 'Lo llena ' + rdEsc(datosP.coordinador) + ', coordinador del proyecto.' : 'El proyecto no tiene coordinador asignado.';
    if (!datosP.desde) { quien += ' <span class="rd-tag">Solo consulta</span>'; }
    else if (!cfgP.corrigeTodo && cfgP.diasCorregibles >= 0) {
        quien += ' <span class="pv-suave">Se puede registrar o corregir desde el ' + rdEsc(fechaCorta(datosP.desde)) + '.</span>';
    }
    $('#pvQuien').html(quien);
    $('#pvAcciones').toggle(!!datosP.desde);
    prepararRapido();
}
function fechaCorta(fecha) {
    var meses = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return +fecha.substring(8, 10) + ' de ' + meses[+fecha.substring(5, 7)];
}

//Las semanas del Excel: 1-7, 8-14, 15-21, 22-28 y 29-31
function semanaDe(dia) { return Math.min(4, Math.floor((dia - 1) / 7)); }

//Dibuja toda la matriz: un bloque por cargo
function pintar() {
    if (!datosP.cargos.length) { $('#pvMatriz').html('<p class="rd-vacio">No hay cargos activos para este tipo de centro (se crean en Parámetros del sistema → Cargos del ausentismo).</p>'); return; }
    var semanas = datosP.dias.length > 28 ? 5 : 4, html = '';
    datosP.cargos.forEach(function (cargo) {
        var id = parseInt(cargo.ID_CARGO_AUS, 10);
        html += '<div class="pv-bloque"><div class="pv-scroll"><table class="pv-tabla" data-cargo="' + id + '"><thead><tr><th class="pv-fija pv-titulo-cargo">' + rdEsc(cargo.NOMBRE) + '</th>';
        datosP.dias.forEach(function (d) {
            var clases = (d.semana === 7 || d.festivo ? ' pv-domingo' : '') + (d.fecha === datosP.hoy ? ' pv-hoy' : '') + (d.dia % 7 === 0 ? ' pv-fin-semana' : '');
            html += '<th class="pv-dia' + clases + '" title="' + (d.festivo ? 'Festivo' : '') + '">' + d.dia + '<small>' + NOMBRE_DIA_CORTO[d.semana] + '</small></th>';
        });
        for (var s = 0; s < semanas; s++) { html += '<th class="pv-sem">' + rdEsc(cfgP.semanas[s]) + '</th>'; }
        html += '<th class="pv-sem pv-mes">Mes</th></tr></thead><tbody>';
        [['p', 'Promesa'], ['e', 'Ejecutado']].forEach(function (fila) {
            html += '<tr class="pv-fila-' + fila[0] + '"><th class="pv-fija">' + fila[1] + '</th>';
            datosP.dias.forEach(function (d) {
                var valor = valoresP[id][d.dia][fila[0]];
                html += '<td class="pv-celda' + (d.dia % 7 === 0 ? ' pv-fin-semana' : '') + '" data-dia="' + d.dia + '">' + (editable(d.fecha, fila[0])
                    ? '<input type="text" inputmode="decimal" class="pv-in" maxlength="7" data-cargo="' + id + '" data-dia="' + d.dia + '" data-campo="' + fila[0] + '" value="' + rdEsc(valor) + '" aria-label="' + fila[1] + ' día ' + d.dia + '">'
                    : '<span class="pv-ver">' + rdEsc(valor) + '</span>') + '</td>';
            });
            for (var s2 = 0; s2 <= semanas; s2++) { html += '<td class="pv-sem" data-sem="' + (s2 === semanas ? 'mes' : s2) + '"></td>'; }
            html += '</tr>';
        });
        html += '<tr class="pv-fila-d"><th class="pv-fija">Diferencia</th>';
        datosP.dias.forEach(function (d) { html += '<td class="pv-celda pv-dif' + (d.dia % 7 === 0 ? ' pv-fin-semana' : '') + '" data-dia="' + d.dia + '"></td>'; });
        for (var s3 = 0; s3 <= semanas; s3++) { html += '<td class="pv-sem pv-dif" data-sem="' + (s3 === semanas ? 'mes' : s3) + '"></td>'; }
        html += '</tr><tr class="pv-fila-j"><th class="pv-fija">Justificación</th>';
        datosP.dias.forEach(function (d) {
            html += '<td class="pv-celda pv-jcelda' + (editable(d.fecha, 'j') ? ' pv-editable' : '') + (d.dia % 7 === 0 ? ' pv-fin-semana' : '') +
                    '" data-cargo="' + id + '" data-dia="' + d.dia + '"></td>';
        });
        html += '<td class="pv-sem" colspan="' + (semanas + 1) + '"></td></tr></tbody></table></div></div>';
    });
    $('#pvMatriz').html(html);
    datosP.cargos.forEach(function (cargo) { repintarCargo(parseInt(cargo.ID_CARGO_AUS, 10)); });
    //En el mes en curso, cada tabla arranca mostrando la semana de hoy
    var hoy = $('#pvMatriz .pv-tabla').first().find('thead .pv-hoy');
    if (hoy.length) {
        $('#pvMatriz .pv-scroll').each(function () {
            var fija = $(this).find('thead .pv-fija').outerWidth() || 0;
            this.scrollLeft = Math.max(0, hoy[0].offsetLeft - fija - 6 * hoy.outerWidth());
        });
    }
}

//Colores, diferencias, totales y justificaciones de un cargo (después de cada cambio)
function repintarCargo(id) {
    var tabla = $('.pv-tabla[data-cargo="' + id + '"]'), semanas = datosP.dias.length > 28 ? 5 : 4;
    var tot = [], i;
    for (i = 0; i <= semanas; i++) { tot.push({ p: 0, e: 0, hayP: false, completo: true }); }
    datosP.dias.forEach(function (d) {
        var v = valoresP[id][d.dia], estado = estadoDia(v), p = numero(v.p), e = numero(v.e);
        var celdaE = tabla.find('.pv-fila-e .pv-celda[data-dia="' + d.dia + '"]');
        var celdaD = tabla.find('.pv-fila-d .pv-celda[data-dia="' + d.dia + '"]');
        var celdaJ = tabla.find('.pv-fila-j .pv-celda[data-dia="' + d.dia + '"]');
        celdaE.add(celdaD).removeClass('pv-cumple pv-debajo pv-encima');
        if (CLASE_ESTADO[estado]) { celdaE.add(celdaD).addClass(CLASE_ESTADO[estado]); }
        celdaD.text(p !== null && e !== null ? mostrar(p - e) : '');
        //Justificación: se pide cuando no se cumplió
        var falta = (estado === 'DEBAJO' || estado === 'ENCIMA') && !$.trim(v.j);
        celdaJ.toggleClass('pv-sinjust', falta).toggleClass('pv-con-texto', !!v.j).attr('title', v.j || (falta ? 'Falta justificar por qué ' + (estado === 'DEBAJO' ? 'quedó por debajo' : 'quedó por encima') : ''))
              .html(v.j ? '<i class="fa fa-comment"></i>' : (falta ? '<i class="fa fa-exclamation"></i>' : ''));
        //Las celdas cambiadas y sin guardar se marcan
        ['p', 'e'].forEach(function (campo) {
            tabla.find('.pv-fila-' + campo + ' .pv-celda[data-dia="' + d.dia + '"]').toggleClass('pv-cambiada', v[campo] !== guardadosP[id][d.dia][campo]);
        });
        celdaJ.toggleClass('pv-cambiada', v.j !== guardadosP[id][d.dia].j);
        //Totales: semana y mes
        [semanaDe(d.dia), semanas].forEach(function (s) {
            if (p !== null) { tot[s].p += p; tot[s].hayP = true; if (e === null) { tot[s].completo = false; } }
            if (e !== null) { tot[s].e += e; }
        });
    });
    for (i = 0; i <= semanas; i++) {
        var clave = i === semanas ? 'mes' : i, t = tot[i], difSem = Math.round((t.p - t.e) * 100) / 100;
        tabla.find('.pv-fila-p .pv-sem[data-sem="' + clave + '"]').text(t.hayP ? mostrar(t.p) : '');
        tabla.find('.pv-fila-e .pv-sem[data-sem="' + clave + '"]').text(t.e ? mostrar(t.e) : '');
        var celda = tabla.find('.pv-fila-d .pv-sem[data-sem="' + clave + '"]').removeClass('pv-cumple pv-debajo pv-encima pv-parcial');
        celda.text(t.hayP ? mostrar(difSem) : '');
        //Color solo si la semana ya tiene todo el ejecutado; si no, gris
        if (t.hayP) { celda.addClass(!t.completo ? 'pv-parcial' : (difSem === 0 ? 'pv-cumple' : (difSem > 0 ? 'pv-debajo' : 'pv-encima'))); }
    }
    pintarEstadoGuardar();
}

//¿Hay casillas distintas de lo guardado?
function cambios() {
    var lista = [];
    $.each(valoresP, function (id, dias) {
        $.each(dias, function (dia, v) {
            var g = guardadosP[id][dia];
            if (v.p !== g.p || v.e !== g.e || v.j !== g.j) { lista.push({ cargo: +id, dia: +dia, promesa: v.p, ejecutado: v.e, justificacion: v.j }); }
        });
    });
    return lista;
}
function hayCambios() { return datosP && cambios().length > 0; }

//El botón Guardar y el contador de lo pendiente
function pintarEstadoGuardar() {
    var n = cambios().length, sinJust = 0, malas = 0;
    $.each(valoresP, function (id, dias) {
        $.each(dias, function (dia, v) {
            var estado = estadoDia(v);
            if ((estado === 'DEBAJO' || estado === 'ENCIMA') && !$.trim(v.j)) { sinJust++; }
            if ((v.p !== '' && numero(v.p) === null) || (v.e !== '' && numero(v.e) === null)) { malas++; }
        });
    });
    $('#pvGuardar').prop('disabled', n === 0).html('<i class="fa fa-save"></i> Guardar' + (n ? ' (' + n + ')' : ''));
    var textos = [];
    if (malas) { textos.push('<span class="rd-tag rd-tag-mal">' + malas + (malas === 1 ? ' casilla no es un número' : ' casillas no son un número') + '</span>'); }
    if (sinJust) { textos.push('<span class="rd-tag rd-tag-pend">' + sinJust + (sinJust === 1 ? ' día sin justificar' : ' días sin justificar') + '</span>'); }
    $('#pvPendientes').html(textos.join(' '));
    if (!n) { rdCambiosGuardados(); } else { rdHayCambios = true; }
}

//Ventanita para escribir la justificación de un día
function editarJustificacion(id, dia, celda) {
    $('#pvEditor').remove();
    var v = valoresP[id][dia], estado = estadoDia(v);
    var cargo = datosP.cargos.filter(function (c) { return parseInt(c.ID_CARGO_AUS, 10) === +id; })[0];
    var que = estado === 'DEBAJO' ? 'Quedó por debajo de la promesa' : (estado === 'ENCIMA' ? 'Quedó por encima de la promesa' : (estado === 'CUMPLE' ? 'Se cumplió la promesa' : 'Sin ejecutado todavía'));
    var editor = $('<div id="pvEditor" class="pv-editor" role="dialog" aria-label="Justificación">' +
        '<div class="pv-editor-titulo">' + rdEsc(cargo ? cargo.NOMBRE : '') + ' · día ' + dia + '</div>' +
        '<div class="pv-editor-que">' + rdEsc(que) + '</div>' +
        '<textarea class="form-control" maxlength="300" rows="3" placeholder="Por ejemplo: incapacidad, renuncia, inducción, vacaciones…"></textarea>' +
        '<div class="pv-editor-botones"><button type="button" class="btn btn-default btn-sm pv-editor-cancelar">Cancelar</button>' +
        '<button type="button" class="btn btn-success btn-sm pv-editor-ok">Aceptar</button></div></div>').appendTo('body');
    editor.find('textarea').val(v.j);
    var r = celda.getBoundingClientRect(), ancho = 280;
    editor.css({ top: Math.min(r.bottom + 6, window.innerHeight - 190) + 'px', left: Math.max(8, Math.min(r.left - ancho / 2, window.innerWidth - ancho - 8)) + 'px' });
    editor.find('textarea').focus();
    editor.find('.pv-editor-cancelar').on('click', function () { editor.remove(); });
    editor.find('.pv-editor-ok').on('click', function () {
        v.j = $.trim(editor.find('textarea').val()).replace(/\s+/g, ' ');
        editor.remove();
        repintarCargo(+id);
    });
    editor.on('keydown', function (evento) { if (evento.key === 'Escape') { editor.remove(); } });
}

//Lee el comentario de un día sin poder cambiarlo (para quien solo consulta)
function verJustificacion(id, dia, celda) {
    $('#pvEditor').remove();
    var v = valoresP[id][dia], estado = estadoDia(v), autor = (autoresP[id] || {})[dia];
    var cargo = datosP.cargos.filter(function (c) { return parseInt(c.ID_CARGO_AUS, 10) === +id; })[0];
    var visor = $('<div id="pvEditor" class="pv-editor" role="dialog" aria-label="Comentario">' +
        '<div class="pv-editor-titulo">' + rdEsc(cargo ? cargo.NOMBRE : '') + ' · día ' + dia + '</div>' +
        '<div class="pv-editor-que">' + rdEsc(textoEstado(estado)) + '</div>' +
        '<p class="pv-visor-texto">' + rdEsc(v.j) + '</p>' +
        (autor && autor.quien ? '<div class="pv-visor-autor">' + rdEsc(autor.quien) + (autor.cuando ? ' · ' + rdEsc(fechaCorta(autor.cuando.substring(0, 10))) : '') + '</div>' : '') +
        '<div class="pv-editor-botones"><button type="button" class="btn btn-default btn-sm pv-editor-cancelar">Cerrar</button></div></div>').appendTo('body');
    var r = celda.getBoundingClientRect(), ancho = 280;
    visor.css({ top: Math.min(r.bottom + 6, window.innerHeight - 190) + 'px', left: Math.max(8, Math.min(r.left - ancho / 2, window.innerWidth - ancho - 8)) + 'px' });
    visor.find('.pv-editor-cancelar').on('click', function () { visor.remove(); }).focus();
    visor.on('keydown', function (evento) { if (evento.key === 'Escape') { visor.remove(); } });
}
function textoEstado(estado) {
    return estado === 'DEBAJO' ? 'Quedó por debajo de la promesa' : (estado === 'ENCIMA' ? 'Quedó por encima de la promesa'
         : (estado === 'CUMPLE' ? 'Se cumplió la promesa' : (estado === 'SIN_REGISTRO' ? 'Sin ejecutado todavía' : 'Sin promesa ese día')));
}

//Debajo de la matriz, para todos los roles: los comentarios del mes del peaje y los días en que no
//se cumplió, con por qué (lo guardado; lo que se está escribiendo sale al guardar)
function pintarComentarios() {
    var filas = [];
    datosP.dias.forEach(function (d) {
        datosP.cargos.forEach(function (cargo) {
            var id = parseInt(cargo.ID_CARGO_AUS, 10), v = guardadosP[id][d.dia], estado = estadoDia(v);
            var incumple = estado === 'DEBAJO' || estado === 'ENCIMA';
            if (!incumple && !$.trim(v.j)) { return; }
            var p = numero(v.p), e = numero(v.e), autor = (autoresP[id] || {})[d.dia];
            filas.push('<tr><td><button type="button" class="btn btn-link btn-xs pv-ir-dia" data-cargo="' + id + '" data-dia="' + d.dia + '" title="Ver ese día en la matriz">' +
                       rdEsc(fechaCorta(d.fecha)) + '</button></td><td>' + rdEsc(cargo.NOMBRE) + '</td>' +
                       '<td class="rd-num-col">' + rdEsc(v.p) + '</td><td class="rd-num-col">' + rdEsc(v.e) + '</td>' +
                       '<td class="rd-num-col ' + (CLASE_ESTADO[estado] || '') + '">' + (p !== null && e !== null ? mostrar(p - e) : '') + '</td>' +
                       '<td>' + (estado === 'DEBAJO' ? '<span class="rd-tag rd-tag-mal">Por debajo</span>' : (estado === 'ENCIMA' ? '<span class="rd-tag rd-tag-pend">Por encima</span>'
                              : (estado === 'CUMPLE' ? '<span class="rd-tag rd-tag-ok">Cumple</span>' : '<span class="rd-tag">' + rdEsc(textoEstado(estado)) + '</span>'))) + '</td>' +
                       '<td class="pv-comentario">' + ($.trim(v.j) ? rdEsc(v.j) : '<span class="rd-tag rd-tag-pend">Sin justificar</span>') + '</td>' +
                       '<td class="pv-suave">' + (autor && autor.quien ? rdEsc(autor.quien) : '') + '</td></tr>');
        });
    });
    $('#pvComentarios').html('<h4 class="rd-subtitulo">Comentarios y por qué no se cumplió · ' + rdEsc(datosP.centro.NOM_CENTRO_OP) + '</h4>' +
        (filas.length ? '<div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta"><thead><tr><th>Día</th><th>Cargo</th>' +
                        '<th class="rd-num-col">Promesa</th><th class="rd-num-col">Ejecutado</th><th class="rd-num-col">Diferencia</th><th>Resultado</th><th>Comentario</th><th>Lo escribió</th></tr></thead><tbody>' +
                        filas.join('') + '</tbody></table></div>'
                      : '<p class="rd-vacio">Este mes no hay comentarios ni días sin cumplir en este peaje.</p>'));
}
//Desde la lista de comentarios: lleva a la casilla de ese día en la matriz
function irAlDia(id, dia) {
    var celda = $('.pv-tabla[data-cargo="' + id + '"] .pv-fila-j .pv-celda[data-dia="' + dia + '"]');
    if (!celda.length) { return; }
    var scroll = celda.closest('.pv-scroll')[0], fija = celda.closest('table').find('thead .pv-fija').outerWidth() || 0;
    scroll.scrollLeft = Math.max(0, celda[0].offsetLeft - fija - 40);
    $('html, body').animate({ scrollTop: celda.closest('.pv-bloque').offset().top - 80 }, 200);
    celda.addClass('pv-resaltar'); setTimeout(function () { celda.removeClass('pv-resaltar'); }, 1600);
}

//Registro rápido: el formulario con los cargos del peaje y los días del mes que se está viendo
function prepararRapido() {
    $('#pvRapido').toggle(!!datosP.desde && datosP.cargos.length > 0);
    if (!datosP.desde) { return; }
    var html = '', anterior = $('#rpCargo').val();
    datosP.cargos.forEach(function (cargo) { html += '<option value="' + parseInt(cargo.ID_CARGO_AUS, 10) + '">' + rdEsc(cargo.NOMBRE) + '</option>'; });
    $('#rpCargo').html(html);
    if (anterior && $('#rpCargo option[value="' + anterior + '"]').length) { $('#rpCargo').val(anterior); }
    var primero = datosP.dias[0].fecha, ultimo = datosP.dias[datosP.dias.length - 1].fecha;
    $('#rpDesde, #rpHasta').attr({ min: primero, max: ultimo });
    var dia = $('#rpDesde').val();
    if (!dia || dia < primero || dia > ultimo) { $('#rpDesde').val(datosP.hoy >= primero && datosP.hoy <= ultimo ? datosP.hoy : primero); $('#rpHasta').val(''); }
}

//Anota lo escrito en cada día del rango (solo los campos llenos) y guarda, con las mismas reglas de la matriz
function registrarRapido(evento) {
    evento.preventDefault();
    var desde = $('#rpDesde').val(), hasta = $('#rpHasta').val() || desde, id = +$('#rpCargo').val();
    var campos = { p: $.trim($('#rpPromesa').val()), e: $.trim($('#rpEjecutado').val()), j: $.trim($('#rpJust').val()).replace(/\s+/g, ' ') };
    if (!desde || !id) { window.alert('Elige el día y el cargo.'); return; }
    if (hasta < desde) { window.alert('El último día no puede ser antes del primero.'); return; }
    if (campos.p === '' && campos.e === '' && campos.j === '') { window.alert('Escribe la promesa, el ejecutado o la justificación.'); return; }
    if ((campos.p !== '' && numero(campos.p) === null) || (campos.e !== '' && numero(campos.e) === null)) { window.alert('La promesa y el ejecutado son números de horas (por ejemplo 8 o 7,5).'); return; }
    //Primero se mira qué se puede anotar; solo si se sigue, se escribe en la matriz
    var porAnotar = [], saltados = [];
    datosP.dias.forEach(function (d) {
        if (d.fecha < desde || d.fecha > hasta) { return; }
        ['p', 'e', 'j'].forEach(function (campo) {
            if (campos[campo] === '') { return; }
            if (editable(d.fecha, campo)) { porAnotar.push([d.dia, campo]); } else { saltados.push(d.dia + (campo === 'e' ? ' (ejecutado)' : '')); }
        });
    });
    if (!porAnotar.length) { window.alert('Ninguno de esos días se puede cambiar' + (saltados.length ? ' (días ' + saltados.join(', ') + ')' : '') + '.'); return; }
    if (saltados.length && !window.confirm('Estos días no se pueden cambiar y se saltan: ' + saltados.join(', ') + '. ¿Guardar los demás?')) { return; }
    porAnotar.forEach(function (casilla) { valoresP[id][casilla[0]][casilla[1]] = campos[casilla[1]]; });
    pintar();
    $('#rpPromesa, #rpEjecutado, #rpJust').val('');
    guardar();
}

//Repite la promesa de los días 1 a 7 en el resto del mes (el día 8 toma la del 1, el 9 la del 2...)
function copiarSemana() {
    var cambia = 0, pisa = 0;
    var aplicar = function (escribir) {
        datosP.cargos.forEach(function (cargo) {
            var id = parseInt(cargo.ID_CARGO_AUS, 10);
            datosP.dias.forEach(function (d) {
                if (d.dia <= 7 || !editable(d.fecha, 'p')) { return; }
                var origen = valoresP[id][((d.dia - 1) % 7) + 1].p;
                if (origen === '' || valoresP[id][d.dia].p === origen) { return; }
                if (valoresP[id][d.dia].p !== '') { pisa++; }
                cambia++;
                if (escribir) { valoresP[id][d.dia].p = origen; }
            });
        });
    };
    aplicar(false);
    if (!cambia) { rdAviso('No hay nada que copiar: la semana 1 no tiene promesa, o el resto del mes ya es igual.'); return; }
    if (pisa && !window.confirm('Se van a cambiar ' + pisa + (pisa === 1 ? ' casilla que ya tenía' : ' casillas que ya tenían') + ' promesa. ¿Seguir?')) { return; }
    aplicar(true);
    pintar();
    rdAviso('Se copió la promesa en ' + cambia + (cambia === 1 ? ' casilla' : ' casillas') + '. Revísala y dale Guardar.');
}

//Envía solo lo que cambió
function guardar() {
    var lista = cambios();
    if (!lista.length) { return; }
    var malas = lista.filter(function (c) { return (c.promesa !== '' && numero(c.promesa) === null) || (c.ejecutado !== '' && numero(c.ejecutado) === null); });
    if (malas.length) { window.alert('Hay casillas que no son un número de horas (por ejemplo el día ' + malas[0].dia + '). Corrígelas antes de guardar.'); return; }
    $('#pvGuardar').prop('disabled', true);
    $.post(URL_PV + '?op=guardar', { centro: datosP.centro.ID_CENTRO_OP, anio: datosP.anio, mes: datosP.mes, celdas: JSON.stringify(lista) }, null, 'json')
        .done(function (respuesta) {
            rdAviso(respuesta.mensaje || 'Se guardó.');
            rdCambiosGuardados();
            cargar(datosP.centro.ID_CENTRO_OP);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo guardar la promesa de valor.'); pintarEstadoGuardar(); });
}
