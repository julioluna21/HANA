//Consolidado de promesa de valor, ejecutado y ausentismo (lo que ve el gerente de operaciones).
//Se pide una vez por mes y con eso se pintan las dos vistas: todos los proyectos o uno solo
var URL_CONS = '../Control/PromesaValorControl.php';
var datosCons = null;       //lo que respondió el servidor para el mes elegido
var MESES_CONS = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
var RES_CONS = { MAS: 'MÁS', MENOS: 'MENOS', IGUAL: 'IGUAL', SIN_DATOS: 'SIN DATOS' };
var filtroCons = [];        //los cargos elegidos (ids); vacío = todos. Las cifras se recalculan en el servidor

$(function () {
    var hoy = $('#pvFiltros').data('hoy');
    rdPeriodo('#cMes', '#cAnio', hoy);                                        //arranca en el mes de hoy
    //Si la dirección trae ?anio=&mes=&proyecto= se abre ahí
    var q = new URLSearchParams(window.location.search);
    if (/^\d{4}$/.test(q.get('anio') || '') && $('#cAnio option[value="' + q.get('anio') + '"]').length) { $('#cAnio').val(q.get('anio')); }
    if (+q.get('mes') >= 1 && +q.get('mes') <= 12) { $('#cMes').val(String(+q.get('mes'))); }
    cargarCons(parseInt(q.get('proyecto'), 10) || 0);

    $('#cMes, #cAnio').on('change', function () { cargarCons(+$('#cProyecto').val() || 0); });
    $('#cProyecto').on('change', pintarCons);
    $('#btnImprimirCons').on('click', function () { window.print(); });
    $(document).on('click', '.cons-ir-proyecto', function (e) { e.preventDefault(); $('#cProyecto').val(String($(this).data('id'))); pintarCons(); window.scrollTo(0, 0); });
    $(document).on('click', '#consVolver', function (e) { e.preventDefault(); $('#cProyecto').val('0'); pintarCons(); });
    $(document).on('click', '#cFiltroCargos .pv-fc', function () {
        var id = +$(this).data('id');
        if (!id) { filtroCons = []; }
        else if (filtroCons.indexOf(id) === -1) { filtroCons.push(id); }
        else { filtroCons = filtroCons.filter(function (x) { return x !== id; }); }
        cargarCons(+$('#cProyecto').val() || 0);
    });
});

//Trae el consolidado del mes (con el filtro de cargos, si hay) y lo pinta
function cargarCons(idProyecto) {
    var anio = $('#cAnio').val(), mes = $('#cMes').val();
    var cargos = filtroCons.length ? '&cargos=' + filtroCons.join(',') : '';
    $('#btnExcelCons').attr('href', 'PromesaConsolidadoExcelVista.php?anio=' + anio + '&mes=' + mes + cargos);
    $.getJSON(URL_CONS + '?op=consolidado&anio=' + anio + '&mes=' + mes + cargos)
        .done(function (d) {
            datosCons = d;
            pintarFiltroCons(d.cargos || []);
            //El selector: todos los proyectos que puede ver, o uno
            var h = d.proyectos.length > 1 ? '<option value="0">' + (d.todos ? 'Todos los proyectos' : 'Mis proyectos') + '</option>' : '';
            d.proyectos.forEach(function (p) { h += '<option value="' + p.id + '">' + rdEsc(p.nombre) + '</option>'; });
            $('#cProyecto').html(h || '<option value="0">Sin proyectos</option>');
            if (idProyecto && $('#cProyecto option[value="' + idProyecto + '"]').length) { $('#cProyecto').val(String(idProyecto)); }
            pintarCons();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el consolidado.'); });
}

//El filtro por cargo: "Todos" y un botón por cargo. Varios a la vez se suman
function pintarFiltroCons(cargos) {
    if (cargos.length < 2) { $('#cFiltroCargos').html(''); return; }
    var h = '<span><i class="fa fa-filter"></i> Cargos:</span>' +
            '<button type="button" class="pv-fc pv-fc-todos' + (filtroCons.length ? '' : ' activo') + '" data-id="0" aria-pressed="' + !filtroCons.length + '">Todos</button>';
    cargos.forEach(function (c) {
        var on = filtroCons.indexOf(+c.id) !== -1;
        h += '<button type="button" class="pv-fc' + (on ? ' activo' : '') + '" data-id="' + parseInt(c.id, 10) + '" aria-pressed="' + on + '">' + rdEsc(nombreCorto(c.nombre)) + '</button>';
    });
    if (filtroCons.length) { h += '<span class="pv-fc-nota">Las cifras, las semanas y las ausencias cuentan solo estos cargos (el Excel también).</span>'; }
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

//La tabla "por semana": a la izquierda la diferencia y a la derecha las ausencias
function tablaSemanas(titulo, filas, total, nombreTotal) {
    var s = datosCons.semanas, h = '<thead><tr><th class="pv-nombre" rowspan="2">' + titulo + '</th><th colspan="5">Diferencia (ejecutado − promesa de valor)</th>' +
            '<th class="pv-g-aus" colspan="5">Ausencias</th></tr><tr>';
    s.forEach(function (x) { h += '<th>' + rdEsc(x) + '</th>'; });
    s.forEach(function (x) { h += '<th class="pv-g-aus">' + rdEsc(x) + '</th>'; });
    h += '</tr></thead><tbody>';
    //Arma una fila de la tabla: diferencias por semana y ausencias por semana
    var fila = function (nombre, f, esTotal) {
        var r = '<tr class="' + (esTotal ? 'pv-total' : '') + '"><td class="pv-nombre">' + nombre + '</td>';
        f.sem.forEach(function (n) { r += tdDif(n, f.res !== 'SIN_DATOS'); });
        f.ausSem.forEach(function (n) { r += tdNum(n); });
        return r + '</tr>';
    };
    filas.forEach(function (x) { h += fila(rdEsc(x.nombre), x.fila, false); });
    return '<div class="table-responsive"><table class="table table-bordered pv-cons">' + h + fila(nombreTotal, total, true) + '</tbody></table></div>';
}

//Arma un bloque con título, ayuda y su tabla
function seccion(titulo, ayuda, tabla) {
    return '<div class="pv-seccion"><h4 class="rd-subtitulo">' + titulo + '</h4>' + (ayuda ? '<p class="rd-ayuda" style="margin-top:0;">' + ayuda + '</p>' : '') + tabla + '</div>';
}

//El nombre de un peaje: enlace a su mes en "Promesa de valor", si la persona tiene esa pantalla en el menú
function enlacePeaje(c) {
    var nombre = (c.tipo === 'BASCULA' ? 'Báscula ' : '') + rdEsc(c.nombre);
    if (!$('.side-menu a[href="PromesaValorVista.php"]').length) { return nombre; }
    return '<a href="PromesaValorVista.php?centro=' + c.id + '&ver=mes&anio=' + datosCons.periodo.anio + '&mes=' + datosCons.periodo.mes + '" title="Ver el mes de este peaje, día por día">' + nombre + '</a>';
}

//---------------------------------------------------------------------------
// Pintar: las cifras de arriba y las tablas según la vista
//---------------------------------------------------------------------------
function pintarCons() {
    var d = datosCons, idP = +$('#cProyecto').val() || 0, p = d.periodo;
    var proy = d.proyectos.filter(function (x) { return x.id === idP; })[0] || null;
    var f = proy ? proy.fila : d.total;                                       //las cifras de lo que se está viendo

    //De qué periodo se habla y hasta dónde llegan los datos
    var enCurso = p.corte > 0 && p.corte < p.diasMes;
    var nota = '<strong>' + nombreCorto(MESES_CONS[p.mes]) + ' de ' + p.anio + '</strong> · ' +
               (p.corte === 0 ? 'este mes todavía no empieza.' : (enCurso ? 'va en curso: las cifras llegan hasta hoy, día ' + p.corte + '.' : 'mes completo.')) +
               ' La promesa y el ejecutado se comparan solo en los días que ya tienen ejecutado registrado.';
    $('#consNota').html('<p class="rd-ayuda" style="margin:0 0 10px;">' + nota + '</p>' +
        (!d.proyectos.length ? '<div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-info-circle"></i> No tienes proyectos para el consolidado: no coordinas ninguno y tu rol no tiene la casilla «Tablero: ver todos los proyectos» (20M).</div>'
         : (f.res === 'SIN_DATOS' && p.corte > 0 ? '<div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-info-circle"></i> En este mes no hay ejecutado registrado. Abajo sale solo el ausentismo.</div>' : '')));

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
        k('Ausencias', num(f.ausTotal), 'en el mismo mes') +
        k('Causa principal', f.causa ? rdEsc(nombreCorto(f.causa)) : 'Sin ausencias', 'el motivo que más pesa', 'pv-kpi-texto') +
        k('Días registrados', f.diasEsp ? num(f.diasReg) + ' de ' + num(f.diasEsp) : '—', proy ? 'días de peaje con ejecutado' : 'días de peaje, entre todos', 'pv-kpi-medio'));

    $('#consTablas').html(!d.proyectos.length ? '' : (proy ? tablasProyecto(proy) : tablasGeneral(d)));
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
    h += seccion('2. Por semana', 'Semana 1 = días 1 a 7, semana 2 = 8 a 14, semana 3 = 15 a 21 y semana 4 = 22 a 28.',
                 tablaSemanas('Proyecto', d.proyectos, d.total, 'Total'));

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
    h += seccion('4. Detalle por peaje', '', '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Peaje', 'Proyecto'], true) + '<tbody>' + filas + '</tbody></table></div>');
    return h;
}

//Un proyecto: como su hoja en el Excel (resumen por peaje, por semana y detalle por peaje y cargo)
function tablasProyecto(p) {
    var h = '', filas = '';
    if (datosCons.proyectos.length > 1) { h += '<p style="margin:0 0 4px;"><a href="#" id="consVolver"><i class="fa fa-chevron-left"></i> Volver a todos los proyectos</a></p>'; }
    p.centros.forEach(function (c) { filas += '<tr><td class="pv-nombre">' + enlacePeaje(c) + '</td>' + celdas(c.fila, true) + '</tr>'; });
    filas += '<tr class="pv-total"><td class="pv-nombre">Total ' + rdEsc(p.nombre) + '</td>' + celdas(p.fila, true) + '</tr>';
    h += seccion('1. Resumen por peaje', 'Toca el nombre de un peaje para ver su mes día por día.',
                 '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Peaje'], true) + '<tbody>' + filas + '</tbody></table></div>');

    h += seccion('2. Por semana', 'Semana 1 = días 1 a 7, semana 2 = 8 a 14, semana 3 = 15 a 21 y semana 4 = 22 a 28.',
                 tablaSemanas('Peaje', p.centros, p.fila, 'Total ' + rdEsc(p.nombre)));

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
