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
var coberturasP = {}; //[idCargo][dia] = {cubiertas, cubrio, texto}: lo cubierto con encargos o desde otro peaje (lo guardado)
var espejoP = {};     //[idCargo][dia] = true: al guardar, anotar el comentario espejo en el otro peaje
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
    //Al cambiar de proyecto o de mes, si se estaba viendo todo el proyecto se sigue viendo así
    $('#pvProyecto, #pvMes, #pvAnio').on('change', function () { if (puedeSalir()) { cargar(esTodo() ? -1 : 0); } else { volverFiltros(); } });
    $(document).on('click', '.pv-btn-centro', function () { if (puedeSalir()) { cargar($(this).data('id')); } });
    //Excel (el botón sale con la casilla 63M): el mes elegido, del proyecto que se está viendo o de todos
    $(document).on('click', '.pv-excel', function (e) {
        e.preventDefault();
        var proyecto = $(this).data('todos') ? 0 : ($('#pvProyecto').val() || 0);
        window.location.href = '../Control/PromesaValorControl.php?op=excel&proyecto=' + encodeURIComponent(proyecto) + '&mes=' + encodeURIComponent($('#pvMes').val()) +
                               '&anio=' + encodeURIComponent($('#pvAnio').val());
    });
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

//¿Se está viendo todo el proyecto sumado (y no un peaje)?
function esTodo() { return !!(datosP && datosP.todo); }

//Trae la matriz del mes de un peaje (0 = el primero del proyecto; -1 = todo el proyecto sumado)
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
            valoresP = {}; guardadosP = {}; autoresP = {}; espejoP = {};
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
            coberturasP = {};
            (respuesta.coberturas || []).forEach(function (c) { coberturasP[c.cargo] = coberturasP[c.cargo] || {}; coberturasP[c.cargo][c.dia] = c; });
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
var CLASE_ESTADO = { CUMPLE: 'pv-cumple', DEBAJO: 'pv-debajo', ENCIMA: 'pv-encima', CUBIERTO: 'pv-cubierto' };

//¿Se puede escribir en ese día? (promesa y justificación desde "desde"; ejecutado, además, hasta hoy)
function editable(fecha, campo) {
    if (!datosP.desde || fecha < datosP.desde) { return false; }
    return campo !== 'e' || fecha <= datosP.hoy;
}

//Los botones de los peajes del proyecto, con cómo va el mes
function pintarCentros() {
    var html = '';
    //Todo el proyecto: sus peajes sumados, para consultar el proyecto completo
    if (datosP.centros.length > 1) {
        html += '<button type="button" class="btn btn-default pv-btn-centro pv-btn-todo' + (esTodo() ? ' active' : '') + '" data-id="-1" title="Todos los peajes del proyecto sumados">' +
                '<i class="fa fa-sitemap"></i> Todo el proyecto</button>';
    }
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
    if (esTodo()) {
        quien = '<strong>Todos los peajes del proyecto sumados</strong>, por cargo y día. Un día sale sin ejecutado mientras algún peaje no lo haya registrado; ' +
                'en la justificación salen los comentarios de cada peaje. <span class="rd-tag">Solo consulta</span>';
    }
    else if (!datosP.desde) { quien += ' <span class="rd-tag">Solo consulta</span>'; }
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
        html += '<div class="pv-bloque"><div class="pv-scroll"><table class="pv-tabla hana-sin-orden" data-cargo="' + id + '"><thead><tr><th class="pv-fija pv-titulo-cargo">' + rdEsc(cargo.NOMBRE) + '</th>';
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
        celdaE.add(celdaD).removeClass('pv-cumple pv-debajo pv-encima pv-cubierto').removeAttr('title');
        //Lo que se cubrió con un encargo o desde otro peaje (calculado con lo guardado: si la casilla se
        //cambió y no se ha guardado, todavía no cuenta)
        var cob = (coberturasP[id] || {})[d.dia], igual = v.p === guardadosP[id][d.dia].p && v.e === guardadosP[id][d.dia].e && v.j === guardadosP[id][d.dia].j;
        if (cob && igual) {
            celdaE.add(celdaD).attr('title', cob.texto);
            if (estado === 'DEBAJO' && Math.round((e + cob.cubiertas - p) * 100) >= 0) { estado = 'CUBIERTO'; }
        }
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
            if (v.p !== g.p || v.e !== g.e || v.j !== g.j) {
                //espejo: al guardar, el servidor anota el comentario que le toca a la casilla del otro peaje
                lista.push({ cargo: +id, dia: +dia, promesa: v.p, ejecutado: v.e, justificacion: v.j, espejo: (espejoP[id] || {})[dia] ? 1 : 0 });
            }
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

//---------------------------------------------------------------------------
// La justificación de un día: opciones fijas (con cantidad) y, si hace falta, texto libre
//---------------------------------------------------------------------------
//Cada opción se guarda como un pedazo del texto, separado por « · », siempre escrito igual (así la
//leen el reporte y el cálculo de encargos; ver PromesaValor::enlaces en Modelo/PromesaValorModelo.php):
//  «2 VACACIONES» · «1 CUBIERTO CON ENCARGO DE RECOLECTORES» · «1 CUBIERTO DESDE ANDES (RECOLECTORES)»
//  «1 INDUCCIÓN» · «1 EN ENCARGO CUBRIENDO CONTROLADORES/SUPERVISOR» · «1 CUBRIENDO A FUSCA (RECOLECTORES)»
var SEPARADOR_J = ' · ';
function motivosTodos() { return (datosP.motivos ? datosP.motivos.debajo.concat(datosP.motivos.encima) : []); }
function esOpcionJ(pedazo) {
    var m = /^(\d+) (.+)$/.exec(pedazo);
    if (!m) { return false; }
    return motivosTodos().indexOf(m[2]) !== -1 || /^CUBIERTOS? CON ENCARGO DE .+$/.test(m[2]) || /^CUBIERTOS? DESDE .+ \([^()]+\)$/.test(m[2]) ||
           /^EN ENCARGO CUBRIENDO .+$/.test(m[2]) || /^CUBRIENDO A .+ \([^()]+\)$/.test(m[2]);
}
//Separa una justificación guardada en sus opciones y el texto libre
function partirJustificacion(texto) {
    var opciones = [], libre = [];
    String(texto || '').split('·').forEach(function (pedazo) {
        pedazo = $.trim(pedazo);
        if (!pedazo) { return; }
        if (esOpcionJ(pedazo)) { opciones.push(pedazo); } else { libre.push(pedazo); }
    });
    return { opciones: opciones, libre: libre.join(SEPARADOR_J) };
}
function textoPlano(html) { return $('<textarea>').html(html || '').text(); }
function mayus(texto) { return $.trim(textoPlano(texto)).toUpperCase(); }

function cargoPorId(id) { return datosP.cargos.filter(function (c) { return parseInt(c.ID_CARGO_AUS, 10) === +id; })[0]; }
function cargoPorNombre(nombre) { return datosP.cargos.filter(function (c) { return mayus(c.NOMBRE) === nombre; })[0]; }
//La opción sin la cantidad («CUBIERTO CON ENCARGO DE X»), para comparar
function baseOpcion(pieza) { return pieza.replace(/^\d+ /, '').replace(/^CUBIERTOS /, 'CUBIERTO '); }
//Una opción con su cantidad: «2 CUBIERTOS CON ENCARGO DE X»
function conCantidad(base, n) { return n + (n > 1 && /^CUBIERTO /.test(base) ? ' CUBIERTOS ' + base.substring(9) : ' ' + base); }
function bonito(texto) { return texto.charAt(0) + texto.slice(1).toLowerCase(); }

//---------------------------------------------------------------------------
// Comentario espejo: cuando un día dice quién cubrió a quién, la otra casilla recibe el comentario que
// le toca. En el mismo peaje:
//   Jefe de peaje             «1 CUBIERTO CON ENCARGO DE CONTROLADORES/SUPERVISOR»
//   Controladores/supervisor  «1 EN ENCARGO CUBRIENDO JEFE DE PEAJE»
// Entre peajes:
//   Andes · Recolectores      «1 CUBIERTO DESDE FUSCA (RECOLECTORES)»
//   Fusca · Recolectores      «1 CUBRIENDO A ANDES (RECOLECTORES)»
// El del mismo peaje se anota aquí (se ve en la matriz antes de guardar); el de otro peaje lo anota el
// servidor al guardar, si la persona llena ese peaje (PromesaValor::cambiosEspejo). Se puede apagar
// con la casilla de las opciones, cuando el motivo de la otra casilla es otro; se recuerda en el navegador.
//---------------------------------------------------------------------------
var CLAVE_ESPEJO = 'hanaPromesaEspejo';
function espejoEncendido() { try { return window.localStorage.getItem(CLAVE_ESPEJO) !== '0'; } catch (e) { return true; } }
function recordarEspejo(si) { try { window.localStorage.setItem(CLAVE_ESPEJO, si ? '1' : '0'); } catch (e) { /* sin almacenamiento: queda encendido */ } }

//Lo que le toca a la otra casilla por una opción: {peaje (nombre; null = este peaje), cargo (nombre), pieza} o null
function espejoDe(pieza, miCargo) {
    var m, miPeaje = mayus(datosP.centro.NOM_CENTRO_OP);
    if ((m = /^(\d+) CUBIERTOS? CON ENCARGO DE (.+)$/.exec(pieza))) { return { peaje: null, cargo: m[2], pieza: m[1] + ' EN ENCARGO CUBRIENDO ' + miCargo }; }
    if ((m = /^(\d+) EN ENCARGO CUBRIENDO (.+)$/.exec(pieza))) { return { peaje: null, cargo: m[2], pieza: conCantidad('CUBIERTO CON ENCARGO DE ' + miCargo, +m[1]) }; }
    if ((m = /^(\d+) CUBIERTOS? DESDE (.+) \(([^()]+)\)$/.exec(pieza))) { return { peaje: m[2], cargo: m[3], pieza: m[1] + ' CUBRIENDO A ' + miPeaje + ' (' + miCargo + ')' }; }
    if ((m = /^(\d+) CUBRIENDO A (.+) \(([^()]+)\)$/.exec(pieza))) { return { peaje: m[2], cargo: m[3], pieza: conCantidad('CUBIERTO DESDE ' + miPeaje + ' (' + miCargo + ')', +m[1]) }; }
    return null;
}

//Qué cambia en las otras casillas cuando la justificación pasa de «antes» a «ahora»:
//[{accion 'poner'|'quitar', peaje, cargo, pieza}]
function cambiosEspejo(antes, ahora, miCargo) {
    var viejas = partirJustificacion(antes).opciones, nuevas = partirJustificacion(ahora).opciones, lista = [];
    var basesNuevas = nuevas.map(baseOpcion);
    nuevas.forEach(function (p) {
        var e = espejoDe(p, miCargo);
        if (e && viejas.indexOf(p) === -1) { e.accion = 'poner'; lista.push(e); }
    });
    viejas.forEach(function (p) {
        var e = espejoDe(p, miCargo);
        if (e && basesNuevas.indexOf(baseOpcion(p)) === -1) { e.accion = 'quitar'; lista.push(e); }
    });
    return lista;
}

//Pone o quita una opción en una justificación; la misma opción con otra cantidad se reemplaza
function cambiarOpcion(texto, pieza, poner) {
    var partes = partirJustificacion(texto), base = baseOpcion(pieza);
    var opciones = partes.opciones.filter(function (o) { return baseOpcion(o) !== base; });
    if (poner) { opciones.push(pieza); }
    return opciones.concat(partes.libre ? [partes.libre] : []).join(SEPARADOR_J);
}

//Anota los comentarios espejo de un día cuya justificación pasó de «antes» a «ahora». Los del mismo
//peaje, en la matriz; los de otro peaje se marcan para que los anote el servidor al guardar.
//Devuelve los avisos de lo que no se pudo
function aplicarEspejo(id, dia, antes, ahora) {
    var cargo = cargoPorId(id), avisos = [];
    if (!cargo) { return avisos; }
    cambiosEspejo(antes, ahora, mayus(cargo.NOMBRE)).forEach(function (c) {
        if (c.peaje !== null) { espejoP[id] = espejoP[id] || {}; espejoP[id][dia] = true; return; }
        var otro = cargoPorNombre(c.cargo), idOtro = otro ? parseInt(otro.ID_CARGO_AUS, 10) : 0;
        if (!idOtro || idOtro === +id || !valoresP[idOtro]) { return; }
        var nuevo = cambiarOpcion(valoresP[idOtro][dia].j, c.pieza, c.accion === 'poner');
        if (nuevo.length > 300) { avisos.push('No cupo el comentario en ' + textoPlano(otro.NOMBRE) + ' el día ' + dia + ' (pasa de 300 letras).'); return; }
        if (nuevo !== valoresP[idOtro][dia].j) { valoresP[idOtro][dia].j = nuevo; repintarCargo(idOtro); }
    });
    return avisos;
}
function quitarEspejo(id, dia) { if (espejoP[id]) { delete espejoP[id][dia]; } }

//Cuántas veces se usó cada opción este mes en el peaje (lo guardado): {base: veces}
function usoOpciones() {
    var cuenta = {};
    $.each(guardadosP, function (idCargo, dias) {
        $.each(dias, function (dia, g) {
            partirJustificacion(g.j).opciones.forEach(function (o) { var base = baseOpcion(o); cuenta[base] = (cuenta[base] || 0) + 1; });
        });
    });
    return cuenta;
}
//Las opciones más usadas que sirven para ese cargo y ese caso (por debajo o por encima): [{base, veces}]
function masUsadas(tipo, id, uso) {
    var debajo = tipo === 'DEBAJO', nombre = mayus((cargoPorId(id) || {}).NOMBRE || ''), m;
    var sirve = function (base) {
        if (datosP.motivos[debajo ? 'debajo' : 'encima'].indexOf(base) !== -1) { return true; }
        if ((m = /^CUBIERTO CON ENCARGO DE (.+)$/.exec(base))) { return debajo && m[1] !== nombre && !!cargoPorNombre(m[1]); }
        if ((m = /^EN ENCARGO CUBRIENDO (.+)$/.exec(base))) { return !debajo && m[1] !== nombre && !!cargoPorNombre(m[1]); }
        if (/^CUBIERTO DESDE /.test(base)) { return debajo; }
        if (/^CUBRIENDO A /.test(base)) { return !debajo; }
        return false;
    };
    return Object.keys(uso).filter(sirve).sort(function (a, b) { return uso[b] - uso[a] || (a < b ? -1 : 1); })
                 .slice(0, 6).map(function (base) { return { base: base, veces: uso[base] }; });
}

//Las opciones de la justificación de un día que no se cumplió (en la ventanita y en el registro rápido):
//cantidad, las más usadas, los motivos, encargo de otro cargo, otro peaje, lo elegido y lo que se anota
//en la otra casilla (comentario espejo, que se puede apagar).
//conf: {cont (jQuery), cargo (id), tipo 'DEBAJO'|'ENCIMA', elegidas (opciones que ya tenía), antes (la justificación
//de antes, para ver qué se quita), cuando (texto para la vista previa: «el día 3»)}
//Devuelve {elegidas(), espejo()}
function opcionesJustificacion(conf) {
    var cont = conf.cont, id = +conf.cargo, debajo = conf.tipo === 'DEBAJO', cargo = cargoPorId(id), nombreCargo = cargo ? mayus(cargo.NOMBRE) : '';
    var elegidas = (conf.elegidas || []).slice(), uso = usoOpciones();
    var otrosCargos = datosP.cargos.filter(function (c) { return parseInt(c.ID_CARGO_AUS, 10) !== id; });
    var opcionesCargo = function (lista, elegido) {
        return lista.map(function (c) { var n = mayus(c.NOMBRE); return '<option value="' + rdEsc(n) + '"' + (n === elegido ? ' selected' : '') + '>' + rdEsc(textoPlano(c.NOMBRE)) + '</option>'; }).join('');
    };
    var opcionesPeaje = (datosP.peajes || []).filter(function (p) { return parseInt(p.ID_CENTRO_OP, 10) !== parseInt(datosP.centro.ID_CENTRO_OP, 10); })
        .map(function (p) { return '<option value="' + rdEsc(mayus(p.NOM_CENTRO_OP)) + '">' + rdEsc(textoPlano(p.NOM_CENTRO_OP)) + (p.NOM_PROYECTO ? ' (' + rdEsc(textoPlano(p.NOM_PROYECTO)) + ')' : '') + '</option>'; }).join('');
    //Los motivos, primero los que más se usan en el peaje
    var motivos = (debajo ? datosP.motivos.debajo : datosP.motivos.encima).slice().sort(function (a, b) { return (uso[b] || 0) - (uso[a] || 0); });
    var usadas = masUsadas(conf.tipo, id, uso);
    cont.html(
        '<div class="pv-op-fila"><label>Cantidad</label><input type="number" class="form-control input-sm pv-cantidad" min="1" max="50" value="1" title="Cuántas personas"></div>' +
        (usadas.length ? '<div class="pv-op-titulo">Las más usadas este mes</div><div class="pv-op-motivos">' + usadas.map(function (u) {
            return '<button type="button" class="btn btn-default btn-xs pv-op-usada" data-base="' + rdEsc(u.base) + '" title="Usada ' + u.veces + (u.veces === 1 ? ' vez' : ' veces') + ' este mes">' +
                   '<i class="fa fa-star"></i> ' + rdEsc(bonito(u.base)) + '</button>';
        }).join('') + '</div>' : '') +
        '<div class="pv-op-titulo">' + (debajo ? 'Por qué faltó' : 'Por qué hubo de más') + '</div>' +
        '<div class="pv-op-motivos">' + motivos.map(function (m) { return '<button type="button" class="btn btn-default btn-xs pv-op-motivo" data-motivo="' + rdEsc(m) + '">' + rdEsc(bonito(m)) + '</button>'; }).join('') + '</div>' +
        (otrosCargos.length ?
        '<div class="pv-op-titulo">' + (debajo ? 'Lo cubrió un encargo de' : 'Encargo: cubrió a') + '</div>' +
        '<div class="pv-op-fila"><select class="form-control input-sm pv-op-encargo">' + opcionesCargo(otrosCargos, '') + '</select>' +
        '<button type="button" class="btn btn-default btn-xs pv-op-encargo-agregar"><i class="fa fa-plus"></i> Agregar</button></div>' : '') +
        '<div class="pv-op-titulo">' + (debajo ? 'Lo cubrió otro peaje' : 'Cubrió a otro peaje') + '</div>' +
        '<div class="pv-op-fila"><select class="form-control input-sm pv-op-peaje">' + opcionesPeaje + '</select></div>' +
        '<div class="pv-op-fila"><select class="form-control input-sm pv-op-peaje-cargo" title="Cargo">' + opcionesCargo(datosP.cargos, nombreCargo) + '</select>' +
        '<button type="button" class="btn btn-default btn-xs pv-op-peaje-agregar"><i class="fa fa-plus"></i> Agregar</button></div>' +
        '<div class="pv-op-elegidas"></div>' +
        '<div class="pv-op-espejo"><label><input type="checkbox" class="pv-op-espejo-si"' + (espejoEncendido() ? ' checked' : '') + '> ' +
        'Anotar automáticamente el comentario en el otro cargo o peaje</label><div class="pv-op-espejo-lista"></div></div>');

    function cantidad() { return Math.max(1, Math.min(50, parseInt(cont.find('.pv-cantidad').val(), 10) || 1)); }
    //La misma opción no se repite: se cambia la cantidad
    function agregar(pieza) {
        var base = baseOpcion(pieza);
        elegidas = elegidas.filter(function (o) { return baseOpcion(o) !== base; });
        elegidas.push(pieza);
        pintar();
    }
    //Lo elegido, como etiquetas que se pueden quitar, y lo que se anota en la otra casilla
    function pintar() {
        cont.find('.pv-op-elegidas').html(elegidas.length ? elegidas.map(function (o, i) {
            return '<span class="pv-op-elegida">' + rdEsc(o) + ' <button type="button" class="pv-op-quitar" data-i="' + i + '" title="Quitar" aria-label="Quitar">×</button></span>';
        }).join('') : '<span class="pv-suave">Elige una o varias opciones.</span>');
        var lista = cambiosEspejo(conf.antes || '', elegidas.join(SEPARADOR_J), nombreCargo), encendido = cont.find('.pv-op-espejo-si').prop('checked');
        cont.find('.pv-op-espejo').toggle(lista.length > 0).toggleClass('pv-op-espejo-apagado', !encendido);
        cont.find('.pv-op-espejo-lista').html(!lista.length ? '' : !encendido
            ? '<div class="pv-suave">No se anota nada en la otra casilla (por ejemplo, si allá el motivo es otro).</div>'
            : '<div class="pv-suave">También se anota, ' + rdEsc(conf.cuando) + ':</div>' + lista.map(function (c) {
                var donde = c.peaje === null ? rdEsc(c.cargo) + ' <span class="pv-suave">(este peaje)</span>'
                                             : rdEsc(c.peaje) + ' · ' + rdEsc(c.cargo) + ' <span class="pv-suave">(al guardar, si llenas ese peaje)</span>';
                return '<div class="pv-op-espejo-item">' + (c.accion === 'poner' ? '<i class="fa fa-plus"></i> En ' : '<i class="fa fa-minus"></i> Se quita de ') +
                       '<strong>' + donde + '</strong>: «' + rdEsc(c.pieza) + '»</div>';
            }).join(''));
    }
    cont.off('.pvop')
        .on('click.pvop', '.pv-op-usada', function () { agregar(conCantidad($(this).attr('data-base'), cantidad())); })
        .on('click.pvop', '.pv-op-motivo', function () { agregar(cantidad() + ' ' + $(this).attr('data-motivo')); })
        .on('click.pvop', '.pv-op-encargo-agregar', function () {
            var otro = cont.find('.pv-op-encargo').val();
            if (!otro) { return; }
            agregar(debajo ? conCantidad('CUBIERTO CON ENCARGO DE ' + otro, cantidad()) : cantidad() + ' EN ENCARGO CUBRIENDO ' + otro);
        })
        .on('click.pvop', '.pv-op-peaje-agregar', function () {
            var peaje = cont.find('.pv-op-peaje').val(), otro = cont.find('.pv-op-peaje-cargo').val();
            if (!peaje || !otro) { return; }
            agregar(debajo ? conCantidad('CUBIERTO DESDE ' + peaje + ' (' + otro + ')', cantidad()) : cantidad() + ' CUBRIENDO A ' + peaje + ' (' + otro + ')');
        })
        .on('click.pvop', '.pv-op-quitar', function () { elegidas.splice(+$(this).attr('data-i'), 1); pintar(); })
        .on('change.pvop', '.pv-op-espejo-si', function () { recordarEspejo(this.checked); pintar(); });
    pintar();
    return {
        elegidas: function () { return elegidas.slice(); },
        espejo: function () { return cont.find('.pv-op-espejo-si').prop('checked'); }
    };
}

//Ventanita para escribir la justificación de un día
function editarJustificacion(id, dia, celda) {
    $('#pvEditor').remove();
    var v = valoresP[id][dia], estado = estadoDia(v), cargo = cargoPorId(id);
    var conOpciones = estado === 'DEBAJO' || estado === 'ENCIMA';
    var partes = partirJustificacion(v.j);
    var editor = $('<div id="pvEditor" class="pv-editor' + (conOpciones ? ' pv-editor-ancho' : '') + '" role="dialog" aria-label="Justificación">' +
        '<div class="pv-editor-titulo">' + rdEsc(cargo ? cargo.NOMBRE : '') + ' · día ' + dia + '</div>' +
        '<div class="pv-editor-que">' + rdEsc(textoEstado(estado)) + '</div>' +
        (conOpciones ? '<div class="pv-editor-opciones"></div><div class="pv-op-titulo">Otro detalle (opcional)</div>' : '') +
        '<textarea class="form-control" rows="2" placeholder="' + (conOpciones ? 'Por ejemplo: nombre de quien cubrió, turno…' : 'Por ejemplo: incapacidad, renuncia, inducción, vacaciones…') + '"></textarea>' +
        '<div class="pv-editor-botones"><button type="button" class="btn btn-default btn-sm pv-editor-cancelar">Cancelar</button>' +
        '<button type="button" class="btn btn-success btn-sm pv-editor-ok">Aceptar</button></div></div>').appendTo('body');
    editor.find('textarea').val(partes.libre);
    var opciones = conOpciones ? opcionesJustificacion({ cont: editor.find('.pv-editor-opciones'), cargo: id, tipo: estado, elegidas: partes.opciones, antes: v.j, cuando: 'el día ' + dia }) : null;

    var r = celda.getBoundingClientRect(), ancho = editor.outerWidth(), alto = editor.outerHeight();
    editor.css({ top: Math.max(8, Math.min(r.bottom + 6, window.innerHeight - alto - 8)) + 'px', left: Math.max(8, Math.min(r.left - ancho / 2, window.innerWidth - ancho - 8)) + 'px' });
    editor.find(conOpciones ? '.pv-op-motivos .btn' : 'textarea').first().focus();
    editor.find('.pv-editor-cancelar').on('click', function () { editor.remove(); });
    editor.find('.pv-editor-ok').on('click', function () {
        var libre = $.trim(editor.find('textarea').val()).replace(/\s+/g, ' ');
        //Sin opciones (cumple o sin ejecutado) se conservan las que ya tenía
        var texto = (opciones ? opciones.elegidas() : partes.opciones).concat(libre ? [libre] : []).join(SEPARADOR_J);
        if (texto.length > 300) { window.alert('La justificación pasa de 300 letras (tiene ' + texto.length + '). Acorta el detalle.'); return; }
        var antes = v.j, avisos = [];
        v.j = texto;
        editor.remove();
        if (opciones && opciones.espejo()) { avisos = aplicarEspejo(id, dia, antes, texto); } else { quitarEspejo(id, dia); }
        repintarCargo(+id);
        if (avisos.length) { window.alert(avisos.join('\n')); }
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
        '<p class="pv-visor-texto">' + rdEsc(v.j).replace(/\n/g, '<br>') + '</p>' +
        (((coberturasP[id] || {})[dia]) ? '<p class="pv-visor-texto pv-suave">' + rdEsc(coberturasP[id][dia].texto) + '</p>' : '') +
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
var filasComentarios = [];   //las filas de la lista: {dia, cargo, estado, comentado, autor, texto, html}
var filtroC = { dia: '', cargo: '', resultado: '', comentario: '', autor: '', texto: '' }; //se mantiene al guardar o cambiar de peaje
var RESULTADOS_C = [['DEBAJO', 'Por debajo'], ['CUBIERTO', 'Cubierto'], ['ENCIMA', 'Por encima'], ['CUMPLE', 'Cumple'], ['OTRO', 'Sin ejecutado o sin promesa']];

$(function () {
    $(document).on('change input', '#pvComentarios .pvc-filtro', function () {
        filtroC[$(this).data('filtro')] = $(this).val() || '';
        pintarFilasComentarios();
    });
    $(document).on('click', '#pvcLimpiar', function () {
        $.each(filtroC, function (clave) { filtroC[clave] = ''; });
        $('#pvComentarios .pvc-filtro').val('');
        pintarFilasComentarios();
    });
});

function pintarComentarios() {
    filasComentarios = [];
    datosP.dias.forEach(function (d) {
        datosP.cargos.forEach(function (cargo) {
            var id = parseInt(cargo.ID_CARGO_AUS, 10), v = guardadosP[id][d.dia], estado = estadoDia(v);
            var incumple = estado === 'DEBAJO' || estado === 'ENCIMA';
            if (!incumple && !$.trim(v.j)) { return; }
            var p = numero(v.p), e = numero(v.e), autor = (autoresP[id] || {})[d.dia];
            //Lo cubierto con un encargo o desde otro peaje
            var cob = (coberturasP[id] || {})[d.dia];
            if (cob && estado === 'DEBAJO' && Math.round((e + cob.cubiertas - p) * 100) >= 0) { estado = 'CUBIERTO'; }
            filasComentarios.push({
                dia: d.dia, cargo: id, estado: estado || 'OTRO', comentado: !!$.trim(v.j),
                autor: autor && autor.quien ? autor.quien : '',
                texto: (v.j + ' ' + (cob ? cob.texto : '')).toLowerCase(),
                html: '<tr><td><button type="button" class="btn btn-link btn-xs pv-ir-dia" data-cargo="' + id + '" data-dia="' + d.dia + '" title="Ver ese día en la matriz">' +
                       rdEsc(fechaCorta(d.fecha)) + '</button></td><td>' + rdEsc(cargo.NOMBRE) + '</td>' +
                       '<td class="rd-num-col">' + rdEsc(v.p) + '</td><td class="rd-num-col">' + rdEsc(v.e) + '</td>' +
                       '<td class="rd-num-col ' + (CLASE_ESTADO[estado] || '') + '">' + (p !== null && e !== null ? mostrar(p - e) : '') + '</td>' +
                       '<td>' + (estado === 'CUBIERTO' ? '<span class="rd-tag rd-tag-ok">Cubierto</span>' : estado === 'DEBAJO' ? '<span class="rd-tag rd-tag-mal">Por debajo</span>' : (estado === 'ENCIMA' ? '<span class="rd-tag rd-tag-pend">Por encima</span>'
                              : (estado === 'CUMPLE' ? '<span class="rd-tag rd-tag-ok">Cumple</span>' : '<span class="rd-tag">' + rdEsc(textoEstado(estado)) + '</span>'))) + '</td>' +
                       '<td class="pv-comentario">' + ($.trim(v.j) ? rdEsc(v.j).replace(/\n/g, '<br>') : '<span class="rd-tag rd-tag-pend">Sin justificar</span>') +
                       (cob ? '<div class="pv-suave">' + rdEsc(cob.texto) + '</div>' : '') + '</td>' +
                       '<td class="pv-suave">' + (autor && autor.quien ? rdEsc(autor.quien) : '') + '</td></tr>'
            });
        });
    });
    var titulo = '<h4 class="rd-subtitulo">Comentarios y por qué no se cumplió · ' + rdEsc(datosP.centro.NOM_CENTRO_OP) + '</h4>';
    if (!filasComentarios.length) {
        $('#pvComentarios').html(titulo + '<p class="rd-vacio">Este mes no hay comentarios ni días sin cumplir en ' + (esTodo() ? 'el proyecto' : 'este peaje') + '.</p>');
        return;
    }
    //Las opciones de cada filtro salen de lo que hay en la lista
    var hay = function (prueba) { return filasComentarios.some(prueba); };
    var opDia = '<option value="">Todos los días</option>', semanas = datosP.dias.length > 28 ? 5 : 4, s;
    for (s = 0; s < semanas; s++) {
        if (hay(function (f) { return semanaDe(f.dia) === s; })) { opDia += '<option value="s' + s + '">' + rdEsc(cfgP.semanas[s]) + '</option>'; }
    }
    datosP.dias.forEach(function (d) {
        if (hay(function (f) { return f.dia === d.dia; })) { opDia += '<option value="' + d.dia + '">' + rdEsc(fechaCorta(d.fecha)) + ' (' + NOMBRE_DIA_CORTO[d.semana] + ')</option>'; }
    });
    var opCargo = '<option value="">Todos los cargos</option>';
    datosP.cargos.forEach(function (cargo) {
        var id = parseInt(cargo.ID_CARGO_AUS, 10);
        if (hay(function (f) { return f.cargo === id; })) { opCargo += '<option value="' + id + '">' + rdEsc(cargo.NOMBRE) + '</option>'; }
    });
    var opResultado = '<option value="">Todos los resultados</option><option value="INCUMPLE">No se cumplió (debajo o encima)</option>';
    RESULTADOS_C.forEach(function (r) {
        if (hay(function (f) { return f.estado === r[0]; })) { opResultado += '<option value="' + r[0] + '">' + r[1] + '</option>'; }
    });
    var autores = [];
    filasComentarios.forEach(function (f) { if (f.autor && autores.indexOf(f.autor) === -1) { autores.push(f.autor); } });
    autores.sort();
    var opAutor = '<option value="">Todos</option>' + autores.map(function (a) { return '<option value="' + rdEsc(a) + '">' + rdEsc(a) + '</option>'; }).join('');
    var campo = function (clase, etiqueta, control) { return '<div class="form-group ' + clase + '"><label>' + etiqueta + '</label>' + control + '</div>'; };
    var select = function (filtro, opciones) { return '<select class="form-control input-sm pvc-filtro" data-filtro="' + filtro + '">' + opciones + '</select>'; };
    $('#pvComentarios').html(titulo +
        '<div class="row pvc-filtros">' +
        campo('col-md-2 col-sm-4 col-xs-6', 'Día:', select('dia', opDia)) +
        campo('col-md-2 col-sm-4 col-xs-6', 'Cargo:', select('cargo', opCargo)) +
        campo('col-md-2 col-sm-4 col-xs-6', 'Resultado:', select('resultado', opResultado)) +
        campo('col-md-2 col-sm-4 col-xs-6', 'Comentario:', select('comentario', '<option value="">Todos</option><option value="CON">Con comentario</option><option value="SIN">Sin justificar</option>')) +
        campo('col-md-2 col-sm-4 col-xs-6', 'Lo escribió:', select('autor', opAutor)) +
        campo('col-md-2 col-sm-4 col-xs-6', 'Buscar en el comentario:', '<input type="search" class="form-control input-sm pvc-filtro" data-filtro="texto" placeholder="Ej.: vacaciones">') +
        '</div>' +
        '<div class="pvc-cuenta"><span id="pvcCuenta"></span> <button type="button" class="btn btn-link btn-xs" id="pvcLimpiar"><i class="fa fa-times"></i> Quitar filtros</button></div>' +
        '<div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta"><thead><tr><th>Día</th><th>Cargo</th>' +
        '<th class="rd-num-col">Promesa</th><th class="rd-num-col">Ejecutado</th><th class="rd-num-col">Diferencia</th><th>Resultado</th><th>Comentario</th><th>Lo escribió</th></tr></thead>' +
        '<tbody id="pvcFilas"></tbody></table></div>');
    //Lo que ya estaba filtrado se mantiene si la opción sigue existiendo
    $('#pvComentarios .pvc-filtro').each(function () {
        var clave = $(this).data('filtro'), valor = filtroC[clave];
        if (this.tagName === 'SELECT' && valor && !$(this).find('option').filter(function () { return this.value === valor; }).length) { valor = ''; }
        filtroC[clave] = valor;
        $(this).val(valor);
    });
    pintarFilasComentarios();
}
//Las filas de la lista que pasan los filtros elegidos
function pintarFilasComentarios() {
    var texto = $.trim(filtroC.texto).toLowerCase();
    var filas = filasComentarios.filter(function (f) {
        if (filtroC.dia !== '' && (filtroC.dia.charAt(0) === 's' ? semanaDe(f.dia) !== +filtroC.dia.substring(1) : f.dia !== +filtroC.dia)) { return false; }
        if (filtroC.cargo !== '' && f.cargo !== +filtroC.cargo) { return false; }
        if (filtroC.resultado === 'INCUMPLE' ? (f.estado !== 'DEBAJO' && f.estado !== 'ENCIMA') : (filtroC.resultado !== '' && f.estado !== filtroC.resultado)) { return false; }
        if (filtroC.comentario === 'CON' && !f.comentado) { return false; }
        if (filtroC.comentario === 'SIN' && f.comentado) { return false; }
        if (filtroC.autor !== '' && f.autor !== filtroC.autor) { return false; }
        if (texto && f.texto.indexOf(texto) === -1) { return false; }
        return true;
    });
    var filtrando = $.grep($.map(filtroC, function (v) { return v; }), function (v) { return v !== ''; }).length > 0;
    $('#pvcFilas').html(filas.length ? filas.map(function (f) { return f.html; }).join('')
                                     : '<tr><td colspan="8" class="rd-vacio">Ningún comentario cumple los filtros elegidos.</td></tr>');
    $('#pvcCuenta').text(filtrando ? filas.length + ' de ' + filasComentarios.length + (filasComentarios.length === 1 ? ' fila' : ' filas')
                                   : filasComentarios.length + (filasComentarios.length === 1 ? ' fila' : ' filas'));
    $('#pvcLimpiar').toggle(filtrando);
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
    rapidoClave = null; //los datos del peaje cambiaron: las opciones se vuelven a armar
    pintarOpcionesRapido();
}

//Las opciones de la justificación en el registro rápido (las mismas de la ventanita)
var rapidoJ = null, rapidoClave = null;
var TEXTO_TIPO_RAPIDO = { '': 'Elegir opciones…', DEBAJO: 'Quedó por debajo', ENCIMA: 'Quedó por encima' };
$(function () {
    //Las opciones solo se despliegan al elegir en el botón «Justificación» por debajo o por encima
    $(document).on('click', '.rp-tipo', function (evento) {
        evento.preventDefault();
        elegirTipoRapido($(this).attr('data-tipo') || '');
    });
    $(document).on('click', '.rp-tipo-cerrar', function () { elegirTipoRapido(''); });
    $('#rpCargo').on('change', pintarOpcionesRapido);
    //Las horas solo sugieren (marcan en el menú) si quedó por debajo o por encima; no despliegan nada
    $('#rpCargo, #rpDesde').on('change', marcarSugeridoRapido);
    $('#rpPromesa, #rpEjecutado').on('input', marcarSugeridoRapido);
});
function elegirTipoRapido(tipo) {
    $('#rpTipo').val(tipo);
    $('#rpTipoTexto').text(TEXTO_TIPO_RAPIDO[tipo] || TEXTO_TIPO_RAPIDO['']);
    $('#rpTipoBtn').toggleClass('pv-rp-tipo-debajo', tipo === 'DEBAJO').toggleClass('pv-rp-tipo-encima', tipo === 'ENCIMA');
    pintarOpcionesRapido();
}
//Lo que dan la promesa y el ejecutado escritos (lo que no se escriba se toma de lo que ya tiene el primer día):
//se marca «según las horas» en esa opción del menú
function marcarSugeridoRapido() {
    if (!datosP || !datosP.dias) { return; }
    var id = +$('#rpCargo').val(), desde = $('#rpDesde').val(), actual = { p: '', e: '' };
    datosP.dias.forEach(function (d) { if (d.fecha === desde && valoresP[id] && valoresP[id][d.dia]) { actual = valoresP[id][d.dia]; } });
    var estado = estadoDia({ p: $.trim($('#rpPromesa').val()) || actual.p, e: $.trim($('#rpEjecutado').val()) || actual.e });
    $('.rp-tipo').each(function () { $(this).find('.rp-sugerido').toggle($(this).attr('data-tipo') === estado); });
}
function tipoRapido() { var tipo = $('#rpTipo').val(); return tipo === 'DEBAJO' || tipo === 'ENCIMA' ? tipo : ''; }
function pintarOpcionesRapido() {
    marcarSugeridoRapido();
    if (!datosP || !datosP.desde || !datosP.cargos.length) { rapidoJ = null; $('#rpOpciones').hide().empty(); return; }
    var tipo = tipoRapido(), id = +$('#rpCargo').val(), clave = tipo + '|' + id;
    if (clave === rapidoClave) { return; } //no cambió: se conserva lo elegido
    rapidoClave = clave;
    var antes = rapidoJ ? rapidoJ.elegidas() : [];
    if (!tipo || !id) { rapidoJ = null; $('#rpOpciones').hide().empty(); return; }
    //Se conservan los motivos elegidos que sirven para el nuevo caso
    var motivos = tipo === 'DEBAJO' ? datosP.motivos.debajo : datosP.motivos.encima;
    var siguen = antes.filter(function (o) { return motivos.indexOf(baseOpcion(o)) !== -1; });
    $('#rpOpciones').show();
    rapidoJ = opcionesJustificacion({ cont: $('#rpOpciones'), cargo: id, tipo: tipo, elegidas: siguen, antes: '', cuando: 'en cada día que se registre' });
    $('#rpOpciones').prepend('<div class="pv-rp-titulo">' + (tipo === 'DEBAJO' ? 'Quedó por debajo de la promesa' : 'Quedó por encima de la promesa') + ': elige por qué' +
                             '<button type="button" class="btn btn-link btn-xs rp-tipo-cerrar" title="Cerrar y no usar las opciones"><i class="fa fa-times"></i> Cerrar</button></div>');
}

//Anota lo escrito en cada día del rango (solo los campos llenos) y guarda, con las mismas reglas de la matriz
function registrarRapido(evento) {
    evento.preventDefault();
    var desde = $('#rpDesde').val(), hasta = $('#rpHasta').val() || desde, id = +$('#rpCargo').val();
    var libre = $.trim($('#rpJust').val()).replace(/\s+/g, ' ');
    var campos = { p: $.trim($('#rpPromesa').val()), e: $.trim($('#rpEjecutado').val()), j: (rapidoJ ? rapidoJ.elegidas() : []).concat(libre ? [libre] : []).join(SEPARADOR_J) };
    var conEspejo = rapidoJ ? rapidoJ.espejo() : false;
    if (!desde || !id) { window.alert('Elige el día y el cargo.'); return; }
    if (hasta < desde) { window.alert('El último día no puede ser antes del primero.'); return; }
    if (campos.p === '' && campos.e === '' && campos.j === '') { window.alert('Escribe la promesa, el ejecutado o la justificación.'); return; }
    if (campos.j.length > 300) { window.alert('La justificación pasa de 300 letras (tiene ' + campos.j.length + '). Acorta el detalle.'); return; }
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
    var avisos = [];
    porAnotar.forEach(function (casilla) {
        var dia = casilla[0], campo = casilla[1], antes = valoresP[id][dia][campo];
        valoresP[id][dia][campo] = campos[campo];
        //La justificación también anota el comentario espejo (el otro cargo o peaje), si está encendido
        if (campo === 'j') {
            if (conEspejo) { avisos = avisos.concat(aplicarEspejo(id, dia, antes, campos.j)); } else { quitarEspejo(id, dia); }
        }
    });
    pintar();
    $('#rpPromesa, #rpEjecutado, #rpJust').val('');
    rapidoJ = null; rapidoClave = null; elegirTipoRapido('');
    if (avisos.length) { window.alert(avisos.join('\n')); }
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
