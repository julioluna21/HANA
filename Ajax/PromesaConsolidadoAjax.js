//Consolidado de promesa de valor, ejecutado y ausentismo (lo que ve el gerente de operaciones).
//Se pide una vez por mes (y por días) y con eso se pintan las tres vistas: todos los proyectos,
//un proyecto o un solo peaje
var URL_CONS = '../Control/PromesaValorControl.php';
var datosCons = null;       //lo que respondió el servidor para el mes elegido
var selCons = '0';          //lo que se está viendo: '0' todos, 'p<id>' un proyecto, 'c<id>' un peaje
var consPedido = 0;         //si se cambian los días muy rápido, solo se pinta la última respuesta
var MESES_CONS = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
var RES_CONS = { MAS: 'MÁS', MENOS: 'MENOS', IGUAL: 'IGUAL', SIN_DATOS: 'SIN DATOS' };
var filtroCargos = [];      //los cargos elegidos (ids); vacío = todos. Las cifras se recalculan en el servidor

$(function () {
    var hoy = $('#pvFiltros').data('hoy');
    rdPeriodo('#cMes', '#cAnio', hoy);                                        //arranca en el mes de hoy
    //Si la dirección trae ?anio=&mes=&proyecto= (o &centro=) se abre ahí
    var q = new URLSearchParams(window.location.search);
    if (/^\d{4}$/.test(q.get('anio') || '') && $('#cAnio option[value="' + q.get('anio') + '"]').length) { $('#cAnio').val(q.get('anio')); }
    if (+q.get('mes') >= 1 && +q.get('mes') <= 12) { $('#cMes').val(String(+q.get('mes'))); }
    if (parseInt(q.get('centro'), 10) > 0) { selCons = 'c' + parseInt(q.get('centro'), 10); }
    else if (parseInt(q.get('proyecto'), 10) > 0) { selCons = 'p' + parseInt(q.get('proyecto'), 10); }
    cargarCons();

    $('#cMes, #cAnio').on('change', cargarCons);
    $('#cProyecto').on('change', function () { selCons = this.value; pintarCons(); });
    //Los días: desde, hasta y los atajos
    $('#cDesde, #cHasta').on('change', function () {
        var d1 = +$('#cDesde').val(), d2 = +$('#cHasta').val();
        if (d1 > d2) { if (this.id === 'cDesde') { $('#cHasta').val(String(d1)); } else { $('#cDesde').val(String(d2)); } }
        cargarCons();
    });
    $('#cRangos [data-rango]').on('click', function () {
        var dias = $('#cHasta option').length, r = $(this).data('rango');
        $('#cDesde').val(String(r === 'q2' ? 15 : 1));
        $('#cHasta').val(String(r === 'q1' ? 14 : dias));
        cargarCons();
    });
    $('#btnImprimirCons').on('click', function () { window.print(); });
    //Ir a un proyecto o a un peaje desde las tablas, y volver
    $(document).on('click', '.cons-ir-proyecto', function (e) { e.preventDefault(); verCons('p' + $(this).data('id')); });
    $(document).on('click', '.cons-ir-peaje', function (e) { e.preventDefault(); verCons('c' + $(this).data('id')); });
    $(document).on('click', '#consVolver', function (e) { e.preventDefault(); verCons('0'); });
    $(document).on('click', '#cFiltroCargos .pv-fc', function () {
        var id = +$(this).data('id');
        if (!id) { filtroCargos = []; }
        else if (filtroCargos.indexOf(id) === -1) { filtroCargos.push(id); }
        else { filtroCargos = filtroCargos.filter(function (x) { return x !== id; }); }
        cargarCons();
    });
});

//Cambia lo que se está viendo (todos, un proyecto o un peaje) y vuelve arriba
function verCons(sel) {
    if ($('#cProyecto option[value="' + sel + '"]').length) { $('#cProyecto').val(sel); selCons = sel; }
    pintarCons();
    window.scrollTo(0, 0);
}

//Llena "desde" y "hasta" con los días del mes elegido. Si se estaba viendo todo el mes, sigue todo el mes
function diasCons() {
    var dias = new Date(+$('#cAnio').val(), +$('#cMes').val(), 0).getDate();
    var antes = $('#cHasta option').length, d1 = +$('#cDesde').val() || 1, d2 = +$('#cHasta').val() || dias;
    if (!antes || (d1 === 1 && d2 === antes)) { d1 = 1; d2 = dias; }
    if (antes !== dias) {
        var h = '';
        for (var d = 1; d <= dias; d++) { h += '<option value="' + d + '">' + d + '</option>'; }
        $('#cDesde, #cHasta').html(h);
    }
    d2 = Math.min(d2, dias); d1 = Math.min(d1, d2);
    $('#cDesde').val(String(d1)); $('#cHasta').val(String(d2));
    //El atajo del rango que se está viendo queda marcado
    $('#cRangos [data-rango]').removeClass('active');
    $('#cRangos [data-rango="' + (d1 === 1 && d2 === dias ? 'mes' : (d1 === 1 && d2 === 14 ? 'q1' : (d1 === 15 && d2 === dias ? 'q2' : ''))) + '"]').addClass('active');
}

//El mes, los días y los cargos elegidos, listos para la URL (la pantalla y el Excel)
function urlCons() {
    return '&anio=' + $('#cAnio').val() + '&mes=' + $('#cMes').val() + '&desde=' + $('#cDesde').val() + '&hasta=' + $('#cHasta').val() +
           (filtroCargos.length ? '&cargos=' + filtroCargos.join(',') : '');
}

//"Báscula X" o el nombre del peaje
function nombrePeaje(c) { return (c.tipo === 'BASCULA' ? 'Báscula ' : '') + c.nombre; }

//Trae el consolidado del mes (con los días y el filtro de cargos) y lo pinta
function cargarCons() {
    diasCons();
    var pedido = ++consPedido;
    $.getJSON(URL_CONS + '?op=consolidado' + urlCons())
        .done(function (d) {
            if (pedido !== consPedido) { return; } //llegó tarde: ya se pidió otra cosa
            datosCons = d;
            pintarFiltroCons(d.cargos || []);
            //El selector: todos los proyectos que puede ver; cada proyecto completo y cada uno de sus peajes
            var attr = function (t) { return rdEsc(t).replace(/"/g, '&quot;'); };
            var h = d.proyectos.length > 1 ? '<option value="0">' + (d.todos ? 'Todos los proyectos' : 'Mis proyectos') + '</option>' : '';
            d.proyectos.forEach(function (p) {
                h += '<optgroup label="' + attr(p.nombre) + '"><option value="p' + p.id + '">Todo ' + rdEsc(p.nombre) + '</option>';
                p.centros.forEach(function (c) { h += '<option value="c' + c.id + '">' + rdEsc(nombrePeaje(c)) + '</option>'; });
                h += '</optgroup>';
            });
            $('#cProyecto').html(h || '<option value="0">Sin proyectos</option>');
            if (!$('#cProyecto option[value="' + selCons + '"]').length) { selCons = $('#cProyecto option').first().val() || '0'; }
            $('#cProyecto').val(selCons);
            pintarCons();
        })
        .fail(function (xhr) { if (pedido === consPedido) { hanaErrorAjax(xhr, 'No se pudo cargar el consolidado.'); } });
}

//El filtro por cargo: "Todos" y un botón por cargo. Varios a la vez se suman
function pintarFiltroCons(cargos) {
    if (cargos.length < 2) { $('#cFiltroCargos').html(''); return; }
    var h = '<span><i class="fa fa-filter"></i> Cargos:</span>' +
            '<button type="button" class="pv-fc pv-fc-todos' + (filtroCargos.length ? '' : ' activo') + '" data-id="0" aria-pressed="' + !filtroCargos.length + '">Todos</button>';
    cargos.forEach(function (c) {
        var on = filtroCargos.indexOf(+c.id) !== -1;
        h += '<button type="button" class="pv-fc' + (on ? ' activo' : '') + '" data-id="' + parseInt(c.id, 10) + '" aria-pressed="' + on + '">' + rdEsc(nombreCorto(c.nombre)) + '</button>';
    });
    if (filtroCargos.length) { h += '<span class="pv-fc-nota">Las cifras, las semanas y las ausencias cuentan solo estos cargos (el Excel también).</span>'; }
    $('#cFiltroCargos').html(h);
}

//---------------------------------------------------------------------------
// Piezas que comparten todas las tablas
//---------------------------------------------------------------------------
function num(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }       //1500 -> 1.500

//Una cifra de diferencia: con signo y color; el cero sale en gris
function tdDif(n, hay) {
    if (!hay) { return '<td class="pv-cero">—</td>'; }
    return '<td class="' + (n < 0 ? 'pv-menos' : (n > 0 ? 'pv-mas' : 'pv-cero')) + '">' + (n > 0 ? '+' : (n < 0 ? '−' : '')) + num(Math.abs(n)) + '</td>';
}
//Una cifra de ausencias: el cero sale en gris para que resalte lo que sí hay
function tdNum(n, clase) { return '<td class="' + (n ? '' : 'pv-cero ') + (clase || '') + '">' + num(n) + '</td>'; }

//El % de ejecución con su barrita: menta desde 95 %, ámbar desde 85 % y vino por debajo
function pct(p) {
    if (p === null) { return '<span class="pv-suave">—</span>'; }
    var cls = p >= 95 ? '' : (p >= 85 ? ' pv-bajo' : ' pv-muy-bajo');
    return '<span class="pv-pct' + cls + '">' + String(p).replace('.', ',') + ' %<i><b style="width:' + Math.max(0, Math.min(100, p)) + '%"></b></i></span>';
}

//Los títulos de las columnas que traen todas las tablas de resumen
function cabecera(primeras, conDias) {
    var m = datosCons.motivos, h = '<thead><tr>';
    primeras.forEach(function (t, i) { h += '<th class="' + (i === 0 ? 'pv-nombre' : 'pv-prim') + '">' + t + '</th>'; });
    h += '<th>Promesa de valor</th><th>Ejecutado</th><th>Diferencia</th><th>% ejecución</th><th>Resultado</th>';
    m.forEach(function (x) { if (x.cuenta) { h += '<th class="pv-g-aus">' + rdEsc(nombreCorto(x.nombre)) + '</th>'; } });
    h += '<th class="pv-g-aus">Total ausencias</th><th class="pv-g-aus">Causa principal</th>';
    m.forEach(function (x) { if (!x.cuenta) { h += '<th class="pv-g-nocuenta" title="No cuenta como ausencia">' + rdEsc(nombreCorto(x.nombre)) + '</th>'; } });
    return h + (conDias ? '<th title="Días con ejecutado registrado, de los que ya pasaron">Días registrados</th>' : '') + '</tr></thead>';
}
//"LICENCIA NO REMUNERADA" -> "Licencia no remunerada" (cabe mejor en el título de la columna)
function nombreCorto(t) { t = String(t).toLowerCase(); return t.charAt(0).toUpperCase() + t.substring(1); }

//Las celdas de una fila (después del nombre): promesa, ejecutado, diferencia, %, resultado, ausencias y causa
function celdas(f, conDias) {
    var m = datosCons.motivos, hay = f.res !== 'SIN_DATOS';
    var h = '<td>' + (hay ? num(f.prom) : '—') + '</td><td>' + (hay ? num(f.eje) : '—') + '</td>' + tdDif(f.dif, hay) +
            '<td>' + pct(f.pct) + '</td><td class="pv-txt"><span class="pv-res pv-res-' + f.res + '">' + RES_CONS[f.res] + '</span></td>';
    m.forEach(function (x, i) { if (x.cuenta) { h += tdNum(f.aus[i]); } });
    h += tdNum(f.ausTotal) + '<td class="pv-txt">' + (f.causa ? rdEsc(nombreCorto(f.causa)) : '<span class="pv-suave">Sin ausencias</span>') + '</td>';
    m.forEach(function (x, i) { if (!x.cuenta) { h += tdNum(f.aus[i], 'pv-nocuenta'); } });
    if (conDias) {
        h += '<td class="' + (f.diasReg < f.diasEsp ? 'pv-dias-falta' : '') + '">' + (f.diasEsp ? f.diasReg + ' de ' + f.diasEsp : '<span class="pv-suave">—</span>') + '</td>';
    }
    return h;
}

//La tabla "por semana": a la izquierda la diferencia y a la derecha las ausencias.
//Solo las semanas que tocan los días elegidos
function tablaSemanas(titulo, filas, total, nombreTotal) {
    var s = datosCons.semanas, idx = datosCons.semanasRango || [0, 1, 2, 3, 4];
    var h = '<thead><tr><th class="pv-nombre" rowspan="2">' + titulo + '</th><th colspan="' + idx.length + '">Diferencia (ejecutado − promesa de valor)</th>' +
            '<th class="pv-g-aus" colspan="' + idx.length + '">Ausencias</th></tr><tr>';
    idx.forEach(function (i) { h += '<th>' + rdEsc(s[i]) + '</th>'; });
    idx.forEach(function (i) { h += '<th class="pv-g-aus">' + rdEsc(s[i]) + '</th>'; });
    h += '</tr></thead><tbody>';
    //Arma una fila de la tabla: diferencias por semana y ausencias por semana
    var fila = function (nombre, f, esTotal) {
        var r = '<tr class="' + (esTotal ? 'pv-total' : '') + '"><td class="pv-nombre">' + nombre + '</td>';
        idx.forEach(function (i) { r += tdDif(f.sem[i], f.res !== 'SIN_DATOS'); });
        idx.forEach(function (i) { r += tdNum(f.ausSem[i]); });
        return r + '</tr>';
    };
    filas.forEach(function (x) { h += fila(rdEsc(x.nombre), x.fila, false); });
    return '<div class="table-responsive"><table class="table table-bordered pv-cons">' + h + fila(nombreTotal, total, true) + '</tbody></table></div>';
}

//Arma un bloque con título, ayuda y su tabla
function seccion(titulo, ayuda, tabla) {
    return '<div class="pv-seccion"><h4 class="rd-subtitulo">' + titulo + '</h4>' + (ayuda ? '<p class="rd-ayuda" style="margin-top:0;">' + ayuda + '</p>' : '') + tabla + '</div>';
}

//La ayuda de la tabla por semana; con un rango de días, dice que solo cuentan esos días
function ayudaSemanas() {
    var p = datosCons.periodo, todoMes = p.desde === 1 && p.hasta === p.diasMes;
    return 'Semana 1 = días 1 a 7, semana 2 = 8 a 14, semana 3 = 15 a 21 y semana 4 = 22 a 28.' + (todoMes ? '' : ' Solo cuentan los días ' + p.desde + ' a ' + p.hasta + '.');
}

//El mes de un peaje, día por día, en "Promesa de valor" (si la persona tiene esa pantalla en el menú)
function enlaceDiaADia(c) {
    if (!$('.side-menu a[href="PromesaValorVista.php"]').length) { return ''; }
    return ' <a class="pv-dia-a-dia" href="PromesaValorVista.php?centro=' + c.id + '&ver=mes&anio=' + datosCons.periodo.anio + '&mes=' + datosCons.periodo.mes +
           '" title="Ver el mes de este peaje, día por día"><i class="fa fa-calendar"></i></a>';
}

//El nombre de un peaje: lleva a ese peaje en el consolidado; el calendario, a su mes día por día
function enlacePeaje(c) {
    return '<a href="#" class="cons-ir-peaje" data-id="' + parseInt(c.id, 10) + '" title="Ver solo este peaje">' + rdEsc(nombrePeaje(c)) + '</a>' + enlaceDiaADia(c);
}

//---------------------------------------------------------------------------
// Pintar: las cifras de arriba y las tablas según la vista
//---------------------------------------------------------------------------
function pintarCons() {
    var d = datosCons, p = d.periodo, sel = $('#cProyecto').val() || '0', id = parseInt(sel.substring(1), 10);
    //Lo que se está viendo: todos, un proyecto (p<id>) o un peaje (c<id>, con su proyecto)
    var proy = null, cen = null;
    if (sel.charAt(0) === 'p') { proy = d.proyectos.filter(function (x) { return x.id === id; })[0] || null; }
    if (sel.charAt(0) === 'c') { d.proyectos.forEach(function (x) { x.centros.forEach(function (c) { if (c.id === id) { proy = x; cen = c; } }); }); }
    var f = cen ? cen.fila : (proy ? proy.fila : d.total);                    //las cifras de lo que se está viendo
    //El Excel baja lo mismo que se ve
    $('#btnExcelCons').attr('href', 'PromesaConsolidadoExcelVista.php?' + urlCons().substring(1) + (cen ? '&centro=' + cen.id : (proy ? '&proyecto=' + proy.id : '')));

    //De qué periodo se habla, qué días se suman y hasta dónde llegan los datos
    var todoMes = p.desde === 1 && p.hasta === p.diasMes;
    var dias = todoMes ? '' : (p.desde === p.hasta ? ', solo el día ' + p.desde : ', del día ' + p.desde + ' al ' + p.hasta);
    var enCurso = p.corte > 0 && p.corte < p.diasMes;
    var estado = p.corte === 0 ? 'este mes todavía no empieza.'
               : (p.desde > p.corte ? 'esos días todavía no llegan.'
               : (enCurso && p.hasta > p.corte ? 'va en curso: las cifras llegan hasta hoy, día ' + p.corte + '.' : (todoMes ? 'mes completo.' : 'esos días ya pasaron.')));
    var nota = '<strong>' + nombreCorto(MESES_CONS[p.mes]) + ' de ' + p.anio + dias + '</strong>' +
               (cen ? ' · ' + rdEsc(nombrePeaje(cen)) + ' (' + rdEsc(proy.nombre) + ')' : (proy ? ' · ' + rdEsc(proy.nombre) : '')) + ' · ' + estado +
               ' La promesa y el ejecutado se comparan solo en los días que ya tienen ejecutado registrado.';
    $('#consNota').html('<p class="rd-ayuda" style="margin:0 0 10px;">' + nota + '</p>' +
        (!d.proyectos.length ? '<div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-info-circle"></i> No tienes proyectos para el consolidado: no coordinas ninguno y tu rol no tiene la casilla «Tablero: ver todos los proyectos» (20M).</div>'
         : (f.res === 'SIN_DATOS' && p.corte > 0 && p.desde <= p.corte ? '<div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-info-circle"></i> ' +
             (todoMes ? 'En este mes' : 'En esos días') + ' no hay ejecutado registrado. Abajo sale solo el ausentismo.</div>' : '')));

    //Las cifras grandes
    var hay = f.res !== 'SIN_DATOS';
    //Arma una de las tarjetas de resumen de arriba
    var k = function (rotulo, valor, pie, clase) { return '<div class="pv-kpi ' + (clase || '') + '"><span>' + rotulo + '</span><strong>' + valor + '</strong><small>' + (pie || '&nbsp;') + '</small></div>'; };
    $('#consKpis').html(
        k('Promesa de valor', hay ? num(f.prom) : '—', 'personas-día prometidas') +
        k('Ejecutado', hay ? num(f.eje) : '—', 'personas-día que trabajaron') +
        k('Diferencia', hay ? (f.dif > 0 ? '+' : (f.dif < 0 ? '−' : '')) + num(Math.abs(f.dif)) : '—', hay ? (f.dif < 0 ? 'se ejecutó menos de lo prometido' : (f.dif > 0 ? 'se ejecutó más de lo prometido' : 'se cumplió justo lo prometido')) : '',
          f.dif < 0 ? 'pv-kpi-menos' : (f.dif > 0 ? 'pv-kpi-mas' : '')) +
        k('% de ejecución', f.pct === null ? '—' : String(f.pct).replace('.', ',') + ' %', '') +
        k('Ausencias', num(f.ausTotal), todoMes ? 'en el mismo mes' : 'en los mismos días') +
        k('Causa principal', f.causa ? rdEsc(nombreCorto(f.causa)) : 'Sin ausencias', 'el motivo que más pesa', 'pv-kpi-texto') +
        k('Días registrados', f.diasEsp ? num(f.diasReg) + ' de ' + num(f.diasEsp) : '—', cen ? 'días con ejecutado' : (proy ? 'días de peaje con ejecutado' : 'días de peaje, entre todos'), 'pv-kpi-medio'));

    $('#consTablas').html(!d.proyectos.length ? '' : (cen ? tablasPeaje(proy, cen) : (proy ? tablasProyecto(proy) : tablasGeneral(d))));
}

//Todos los proyectos: como la hoja MAIN del Excel
function tablasGeneral(d) {
    var h = '', filas = '';
    //1. Resumen por proyecto (el nombre lleva a la vista de ese proyecto)
    d.proyectos.forEach(function (p) {
        filas += '<tr><td class="pv-nombre"><a href="#" class="cons-ir-proyecto" data-id="' + p.id + '" title="Ver los peajes de este proyecto">' + rdEsc(p.nombre) + '</a></td>' + celdas(p.fila, true) + '</tr>';
    });
    filas += '<tr class="pv-total"><td class="pv-nombre">Total ' + (d.proyectos.length === 1 ? '' : d.proyectos.length + ' proyectos') + '</td>' + celdas(d.total, true) + '</tr>';
    h += seccion('1. Resumen por proyecto', 'Toca el nombre de un proyecto para ver sus peajes y cargos.',
                 '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Proyecto'], true) + '<tbody>' + filas + '</tbody></table></div>');

    //2. Por semana
    h += seccion('2. Por semana', ayudaSemanas(), tablaSemanas('Proyecto', d.proyectos, d.total, 'Total'));

    //3. Por cargo, sumando todos los proyectos
    filas = '';
    d.roles.forEach(function (r) { filas += '<tr><td class="pv-nombre">' + rdEsc(r.nombre) + '</td>' + celdas(r.fila, false) + '</tr>'; });
    filas += '<tr class="pv-total"><td class="pv-nombre">Total</td>' + celdas(d.total, false) + '</tr>';
    h += seccion('3. Resumen por cargo', '', '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Cargo'], false) + '<tbody>' + filas + '</tbody></table></div>');

    //4. Detalle por peaje
    filas = '';
    d.proyectos.forEach(function (p) {
        p.centros.forEach(function (c, i) {
            filas += '<tr class="' + (i === 0 ? 'pv-corte' : '') + '"><td class="pv-nombre">' + enlacePeaje(c) + '</td><td class="pv-sub">' + rdEsc(p.nombre) + '</td>' + celdas(c.fila, true) + '</tr>';
        });
    });
    filas += '<tr class="pv-total"><td class="pv-nombre">Total</td><td></td>' + celdas(d.total, true) + '</tr>';
    h += seccion('4. Detalle por peaje', 'Toca el nombre de un peaje para ver solo ese peaje; el calendario abre su mes día por día.',
                 '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Peaje', 'Proyecto'], true) + '<tbody>' + filas + '</tbody></table></div>');
    return h;
}

//Un proyecto: como su hoja en el Excel (resumen por peaje, por semana y detalle por peaje y cargo)
function tablasProyecto(p) {
    var h = '', filas = '';
    if (datosCons.proyectos.length > 1) { h += '<p style="margin:0 0 4px;"><a href="#" id="consVolver"><i class="fa fa-chevron-left"></i> Volver a todos los proyectos</a></p>'; }
    p.centros.forEach(function (c) { filas += '<tr><td class="pv-nombre">' + enlacePeaje(c) + '</td>' + celdas(c.fila, true) + '</tr>'; });
    filas += '<tr class="pv-total"><td class="pv-nombre">Total ' + rdEsc(p.nombre) + '</td>' + celdas(p.fila, true) + '</tr>';
    h += seccion('1. Resumen por peaje', 'Toca el nombre de un peaje para ver solo ese peaje; el calendario abre su mes día por día.',
                 '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Peaje'], true) + '<tbody>' + filas + '</tbody></table></div>');

    //Las semanas, con el nombre del peaje sin enlace (cabe mejor)
    h += seccion('2. Por semana', ayudaSemanas(),
                 tablaSemanas('Peaje', p.centros.map(function (c) { return { nombre: nombrePeaje(c), fila: c.fila }; }), p.fila, 'Total ' + rdEsc(p.nombre)));

    filas = '';
    p.centros.forEach(function (c) {
        c.cargos.forEach(function (k, i) {
            filas += '<tr class="' + (i === 0 ? 'pv-corte' : '') + '"><td class="pv-nombre">' + (i === 0 ? rdEsc(c.nombre) : '') + '</td><td class="pv-sub">' + rdEsc(k.nombre) + '</td>' + celdas(k.fila, false) + '</tr>';
        });
        if (!c.cargos.length) { filas += '<tr class="pv-corte"><td class="pv-nombre">' + rdEsc(c.nombre) + '</td><td class="pv-sub" colspan="20">Sin promesa de valor ni registros en el mes</td></tr>'; }
    });
    filas += '<tr class="pv-total"><td class="pv-nombre">Total ' + rdEsc(p.nombre) + '</td><td></td>' + celdas(p.fila, false) + '</tr>';
    h += seccion('3. Detalle por peaje y cargo', '', '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Peaje', 'Cargo'], false) + '<tbody>' + filas + '</tbody></table></div>');
    return h;
}

//Un solo peaje: sus cifras por cargo y por semana
function tablasPeaje(p, c) {
    var h = '<p style="margin:0 0 4px;"><a href="#" class="cons-ir-proyecto" data-id="' + p.id + '"><i class="fa fa-chevron-left"></i> Volver a ' + rdEsc(p.nombre) + '</a>' +
            ($('.side-menu a[href="PromesaValorVista.php"]').length ? ' &nbsp;·&nbsp; ' + enlaceDiaADia(c) + ' Su mes día por día' : '') + '</p>';
    var filas = '';
    c.cargos.forEach(function (k) { filas += '<tr><td class="pv-nombre">' + rdEsc(k.nombre) + '</td>' + celdas(k.fila, false) + '</tr>'; });
    if (!c.cargos.length) { filas += '<tr><td class="pv-nombre" colspan="20">Sin promesa de valor ni registros en estos días</td></tr>'; }
    filas += '<tr class="pv-total"><td class="pv-nombre">Total ' + rdEsc(nombrePeaje(c)) + '</td>' + celdas(c.fila, false) + '</tr>';
    h += seccion('1. Resumen por cargo', '', '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Cargo'], false) + '<tbody>' + filas + '</tbody></table></div>');

    h += seccion('2. Por semana', ayudaSemanas(), tablaSemanas('Cargo', c.cargos, c.fila, 'Total ' + rdEsc(nombrePeaje(c))));
    return h;
}
