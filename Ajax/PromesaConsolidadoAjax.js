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
    var parametrosUrl = new URLSearchParams(window.location.search);
    if (/^\d{4}$/.test(parametrosUrl.get('anio') || '') && $('#cAnio option[value="' + parametrosUrl.get('anio') + '"]').length) { $('#cAnio').val(parametrosUrl.get('anio')); }
    if (+parametrosUrl.get('mes') >= 1 && +parametrosUrl.get('mes') <= 12) { $('#cMes').val(String(+parametrosUrl.get('mes'))); }
    if (parseInt(parametrosUrl.get('centro'), 10) > 0) { selCons = 'c' + parseInt(parametrosUrl.get('centro'), 10); }
    else if (parseInt(parametrosUrl.get('proyecto'), 10) > 0) { selCons = 'p' + parseInt(parametrosUrl.get('proyecto'), 10); }
    cargarCons();

    $('#cMes, #cAnio').on('change', cargarCons);
    $('#cProyecto').on('change', function () { selCons = this.value; pintarCons(); });
    //Los días: desde, hasta y los atajos
    $('#cDesde, #cHasta').on('change', function () {
        var diaDesde = +$('#cDesde').val(), diaHasta = +$('#cHasta').val();
        if (diaDesde > diaHasta) { if (this.id === 'cDesde') { $('#cHasta').val(String(diaDesde)); } else { $('#cDesde').val(String(diaHasta)); } }
        cargarCons();
    });
    $('#cRangos [data-rango]').on('click', function () {
        var dias = $('#cHasta option').length, rango = $(this).data('rango');
        $('#cDesde').val(String(rango === 'q2' ? 15 : 1));
        $('#cHasta').val(String(rango === 'q1' ? 14 : dias));
        cargarCons();
    });
    $('#btnImprimirCons').on('click', function () { window.print(); });
    //Ir a un proyecto o a un peaje desde las tablas, y volver
    $(document).on('click', '.cons-ir-proyecto', function (evento) { evento.preventDefault(); verCons('p' + $(this).data('id')); });
    $(document).on('click', '.cons-ir-peaje', function (evento) { evento.preventDefault(); verCons('c' + $(this).data('id')); });
    $(document).on('click', '#consVolver', function (evento) { evento.preventDefault(); verCons('0'); });
    $(document).on('click', '#cFiltroCargos .pv-fc', function () {
        var id = +$(this).data('id');
        if (!id) { filtroCargos = []; }
        else if (filtroCargos.indexOf(id) === -1) { filtroCargos.push(id); }
        else { filtroCargos = filtroCargos.filter(function (idCargo) { return idCargo !== id; }); }
        cargarCons();
    });
});

//Cambia lo que se está viendo (todos, un proyecto o un peaje) y vuelve arriba
function verCons(seleccion) {
    if ($('#cProyecto option[value="' + seleccion + '"]').length) { $('#cProyecto').val(seleccion); selCons = seleccion; }
    pintarCons();
    window.scrollTo(0, 0);
}

//Llena "desde" y "hasta" con los días del mes elegido. Si se estaba viendo todo el mes, sigue todo el mes
function diasCons() {
    var dias = new Date(+$('#cAnio').val(), +$('#cMes').val(), 0).getDate();
    var antes = $('#cHasta option').length, diaDesde = +$('#cDesde').val() || 1, diaHasta = +$('#cHasta').val() || dias;
    if (!antes || (diaDesde === 1 && diaHasta === antes)) { diaDesde = 1; diaHasta = dias; }
    if (antes !== dias) {
        var html = '';
        for (var dia = 1; dia <= dias; dia++) { html += '<option value="' + dia + '">' + dia + '</option>'; }
        $('#cDesde, #cHasta').html(html);
    }
    diaHasta = Math.min(diaHasta, dias); diaDesde = Math.min(diaDesde, diaHasta);
    $('#cDesde').val(String(diaDesde)); $('#cHasta').val(String(diaHasta));
    //El atajo del rango que se está viendo queda marcado
    $('#cRangos [data-rango]').removeClass('active');
    $('#cRangos [data-rango="' + (diaDesde === 1 && diaHasta === dias ? 'mes' : (diaDesde === 1 && diaHasta === 14 ? 'q1' : (diaDesde === 15 && diaHasta === dias ? 'q2' : ''))) + '"]').addClass('active');
}

//El mes, los días y los cargos elegidos, listos para la URL (la pantalla y el Excel)
function urlCons() {
    return '&anio=' + $('#cAnio').val() + '&mes=' + $('#cMes').val() + '&desde=' + $('#cDesde').val() + '&hasta=' + $('#cHasta').val() +
           (filtroCargos.length ? '&cargos=' + filtroCargos.join(',') : '');
}

//"Báscula X" o el nombre del peaje
function nombrePeaje(centro) { return (centro.tipo === 'BASCULA' ? 'Báscula ' : '') + centro.nombre; }

//Trae el consolidado del mes (con los días y el filtro de cargos) y lo pinta
function cargarCons() {
    diasCons();
    var pedido = ++consPedido;
    $.getJSON(URL_CONS + '?op=consolidado' + urlCons())
        .done(function (respuesta) {
            if (pedido !== consPedido) { return; } //llegó tarde: ya se pidió otra cosa
            datosCons = respuesta;
            pintarFiltroCons(respuesta.cargos || []);
            //El selector: todos los proyectos que puede ver; cada proyecto completo y cada uno de sus peajes
            var attr = function (texto) { return rdEsc(texto).replace(/"/g, '&quot;'); };
            var html = respuesta.proyectos.length > 1 ? '<option value="0">' + (respuesta.todos ? 'Todos los proyectos' : 'Mis proyectos') + '</option>' : '';
            respuesta.proyectos.forEach(function (proyecto) {
                html += '<optgroup label="' + attr(proyecto.nombre) + '"><option value="p' + proyecto.id + '">Todo ' + rdEsc(proyecto.nombre) + '</option>';
                proyecto.centros.forEach(function (centro) { html += '<option value="c' + centro.id + '">' + rdEsc(nombrePeaje(centro)) + '</option>'; });
                html += '</optgroup>';
            });
            $('#cProyecto').html(html || '<option value="0">Sin proyectos</option>');
            if (!$('#cProyecto option[value="' + selCons + '"]').length) { selCons = $('#cProyecto option').first().val() || '0'; }
            $('#cProyecto').val(selCons);
            pintarCons();
        })
        .fail(function (xhr) { if (pedido === consPedido) { hanaErrorAjax(xhr, 'No se pudo cargar el consolidado.'); } });
}

//El filtro por cargo: "Todos" y un botón por cargo. Varios a la vez se suman
function pintarFiltroCons(cargos) {
    if (cargos.length < 2) { $('#cFiltroCargos').html(''); return; }
    var html = '<span><i class="fa fa-filter"></i> Cargos:</span>' +
            '<button type="button" class="pv-fc pv-fc-todos' + (filtroCargos.length ? '' : ' activo') + '" data-id="0" aria-pressed="' + !filtroCargos.length + '">Todos</button>';
    cargos.forEach(function (cargo) {
        var marcado = filtroCargos.indexOf(+cargo.id) !== -1;
        html += '<button type="button" class="pv-fc' + (marcado ? ' activo' : '') + '" data-id="' + parseInt(cargo.id, 10) + '" aria-pressed="' + marcado + '">' + rdEsc(nombreCorto(cargo.nombre)) + '</button>';
    });
    if (filtroCargos.length) { html += '<span class="pv-fc-nota">Las cifras, las semanas y las ausencias cuentan solo estos cargos (el Excel también).</span>'; }
    $('#cFiltroCargos').html(html);
}

//---------------------------------------------------------------------------
// Piezas que comparten todas las tablas
//---------------------------------------------------------------------------
function conMiles(numero) { return String(numero).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }       //1500 -> 1.500

//Una cifra de diferencia: con signo y color; el cero sale en gris
function tdDif(diferencia, hay) {
    if (!hay) { return '<td class="pv-cero">—</td>'; }
    return '<td class="' + (diferencia < 0 ? 'pv-menos' : (diferencia > 0 ? 'pv-mas' : 'pv-cero')) + '">' + (diferencia > 0 ? '+' : (diferencia < 0 ? '−' : '')) + conMiles(Math.abs(diferencia)) + '</td>';
}
//Una cifra de ausencias: el cero sale en gris para que resalte lo que sí hay
function tdNum(valor, clase) { return '<td class="' + (valor ? '' : 'pv-cero ') + (clase || '') + '">' + conMiles(valor) + '</td>'; }

//El % de ejecución con su barrita: menta desde 95 %, ámbar desde 85 % y vino por debajo
function textoPorcentaje(porcentaje) {
    if (porcentaje === null) { return '<span class="pv-suave">—</span>'; }
    var clase = porcentaje >= 95 ? '' : (porcentaje >= 85 ? ' pv-bajo' : ' pv-muy-bajo');
    return '<span class="pv-pct' + clase + '">' + String(porcentaje).replace('.', ',') + ' %<i><b style="width:' + Math.max(0, Math.min(100, porcentaje)) + '%"></b></i></span>';
}

//Los títulos de las columnas que traen todas las tablas de resumen
function cabecera(primeras, conDias) {
    var motivos = datosCons.motivos, html = '<thead><tr>';
    primeras.forEach(function (titulo, i) { html += '<th class="' + (i === 0 ? 'pv-nombre' : 'pv-prim') + '">' + titulo + '</th>'; });
    html += '<th>Promesa de valor</th><th>Ejecutado</th><th>Diferencia</th><th>% ejecución</th><th>Resultado</th>';
    motivos.forEach(function (motivo) { if (motivo.cuenta) { html += '<th class="pv-g-aus">' + rdEsc(nombreCorto(motivo.nombre)) + '</th>'; } });
    html += '<th class="pv-g-aus">Total ausencias</th><th class="pv-g-aus">Causa principal</th>';
    motivos.forEach(function (motivo) { if (!motivo.cuenta) { html += '<th class="pv-g-nocuenta" title="No cuenta como ausencia">' + rdEsc(nombreCorto(motivo.nombre)) + '</th>'; } });
    return html + (conDias ? '<th title="Días con ejecutado registrado, de los que ya pasaron">Días registrados</th>' : '') + '</tr></thead>';
}
//"LICENCIA NO REMUNERADA" -> "Licencia no remunerada" (cabe mejor en el título de la columna)
function nombreCorto(texto) { texto = String(texto).toLowerCase(); return texto.charAt(0).toUpperCase() + texto.substring(1); }

//Las celdas de una fila (después del nombre): promesa, ejecutado, diferencia, %, resultado, ausencias y causa
function celdas(filaDatos, conDias) {
    var motivos = datosCons.motivos, hay = filaDatos.res !== 'SIN_DATOS';
    var html = '<td>' + (hay ? conMiles(filaDatos.prom) : '—') + '</td><td>' + (hay ? conMiles(filaDatos.eje) : '—') + '</td>' + tdDif(filaDatos.dif, hay) +
            '<td>' + textoPorcentaje(filaDatos.pct) + '</td><td class="pv-txt"><span class="pv-res pv-res-' + filaDatos.res + '">' + RES_CONS[filaDatos.res] + '</span></td>';
    motivos.forEach(function (motivo, i) { if (motivo.cuenta) { html += tdNum(filaDatos.aus[i]); } });
    html += tdNum(filaDatos.ausTotal) + '<td class="pv-txt">' + (filaDatos.causa ? rdEsc(nombreCorto(filaDatos.causa)) : '<span class="pv-suave">Sin ausencias</span>') + '</td>';
    motivos.forEach(function (motivo, i) { if (!motivo.cuenta) { html += tdNum(filaDatos.aus[i], 'pv-nocuenta'); } });
    if (conDias) {
        html += '<td class="' + (filaDatos.diasReg < filaDatos.diasEsp ? 'pv-dias-falta' : '') + '">' + (filaDatos.diasEsp ? filaDatos.diasReg + ' de ' + filaDatos.diasEsp : '<span class="pv-suave">—</span>') + '</td>';
    }
    return html;
}

//La tabla "por semana": a la izquierda la diferencia y a la derecha las ausencias.
//Solo las semanas que tocan los días elegidos
function tablaSemanas(titulo, filas, total, nombreTotal) {
    var semanas = datosCons.semanas, semanasRango = datosCons.semanasRango || [0, 1, 2, 3, 4];
    var html = '<thead><tr><th class="pv-nombre" rowspan="2">' + titulo + '</th><th colspan="' + semanasRango.length + '">Diferencia (ejecutado − promesa de valor)</th>' +
            '<th class="pv-g-aus" colspan="' + semanasRango.length + '">Ausencias</th></tr><tr>';
    semanasRango.forEach(function (i) { html += '<th>' + rdEsc(semanas[i]) + '</th>'; });
    semanasRango.forEach(function (i) { html += '<th class="pv-g-aus">' + rdEsc(semanas[i]) + '</th>'; });
    html += '</tr></thead><tbody>';
    //Arma una fila de la tabla: diferencias por semana y ausencias por semana
    var fila = function (nombre, filaDatos, esTotal) {
        var htmlFila = '<tr class="' + (esTotal ? 'pv-total' : '') + '"><td class="pv-nombre">' + nombre + '</td>';
        semanasRango.forEach(function (i) { htmlFila += tdDif(filaDatos.sem[i], filaDatos.res !== 'SIN_DATOS'); });
        semanasRango.forEach(function (i) { htmlFila += tdNum(filaDatos.ausSem[i]); });
        return htmlFila + '</tr>';
    };
    filas.forEach(function (item) { html += fila(rdEsc(item.nombre), item.fila, false); });
    return '<div class="table-responsive"><table class="table table-bordered pv-cons">' + html + fila(nombreTotal, total, true) + '</tbody></table></div>';
}

//Arma un bloque con título, ayuda y su tabla
function seccion(titulo, ayuda, tabla) {
    return '<div class="pv-seccion"><h4 class="rd-subtitulo">' + titulo + '</h4>' + (ayuda ? '<p class="rd-ayuda" style="margin-top:0;">' + ayuda + '</p>' : '') + tabla + '</div>';
}

//La ayuda de la tabla por semana; con un rango de días, dice que solo cuentan esos días
function ayudaSemanas() {
    var periodo = datosCons.periodo, todoMes = periodo.desde === 1 && periodo.hasta === periodo.diasMes;
    return 'Semana 1 = días 1 a 7, semana 2 = 8 a 14, semana 3 = 15 a 21 y semana 4 = 22 a 28.' + (todoMes ? '' : ' Solo cuentan los días ' + periodo.desde + ' a ' + periodo.hasta + '.');
}

//El mes de un peaje, día por día, en "Promesa de valor" (si la persona tiene esa pantalla en el menú)
function enlaceDiaADia(centro) {
    if (!$('.side-menu a[href="PromesaValorVista.php"]').length) { return ''; }
    return ' <a class="pv-dia-a-dia" href="PromesaValorVista.php?centro=' + centro.id + '&ver=mes&anio=' + datosCons.periodo.anio + '&mes=' + datosCons.periodo.mes +
           '" title="Ver el mes de este peaje, día por día"><i class="fa fa-calendar"></i></a>';
}

//El nombre de un peaje: lleva a ese peaje en el consolidado; el calendario, a su mes día por día
function enlacePeaje(centro) {
    return '<a href="#" class="cons-ir-peaje" data-id="' + parseInt(centro.id, 10) + '" title="Ver solo este peaje">' + rdEsc(nombrePeaje(centro)) + '</a>' + enlaceDiaADia(centro);
}

//---------------------------------------------------------------------------
// Pintar: las cifras de arriba y las tablas según la vista
//---------------------------------------------------------------------------
function pintarCons() {
    var datos = datosCons, periodo = datos.periodo, seleccion = $('#cProyecto').val() || '0', id = parseInt(seleccion.substring(1), 10);
    //Lo que se está viendo: todos, un proyecto (p<id>) o un peaje (c<id>, con su proyecto)
    var proy = null, centroElegido = null;
    if (seleccion.charAt(0) === 'p') { proy = datos.proyectos.filter(function (proyecto) { return proyecto.id === id; })[0] || null; }
    if (seleccion.charAt(0) === 'c') { datos.proyectos.forEach(function (proyecto) { proyecto.centros.forEach(function (centro) { if (centro.id === id) { proy = proyecto; centroElegido = centro; } }); }); }
    var filaDatos = centroElegido ? centroElegido.fila : (proy ? proy.fila : datos.total);                    //las cifras de lo que se está viendo
    //El Excel baja lo mismo que se ve
    $('#btnExcelCons').attr('href', 'PromesaConsolidadoExcelVista.php?' + urlCons().substring(1) + (centroElegido ? '&centro=' + centroElegido.id : (proy ? '&proyecto=' + proy.id : '')));

    //De qué periodo se habla, qué días se suman y hasta dónde llegan los datos
    var todoMes = periodo.desde === 1 && periodo.hasta === periodo.diasMes;
    var dias = todoMes ? '' : (periodo.desde === periodo.hasta ? ', solo el día ' + periodo.desde : ', del día ' + periodo.desde + ' al ' + periodo.hasta);
    var enCurso = periodo.corte > 0 && periodo.corte < periodo.diasMes;
    var estado = periodo.corte === 0 ? 'este mes todavía no empieza.'
               : (periodo.desde > periodo.corte ? 'esos días todavía no llegan.'
               : (enCurso && periodo.hasta > periodo.corte ? 'va en curso: las cifras llegan hasta hoy, día ' + periodo.corte + '.' : (todoMes ? 'mes completo.' : 'esos días ya pasaron.')));
    var nota = '<strong>' + nombreCorto(MESES_CONS[periodo.mes]) + ' de ' + periodo.anio + dias + '</strong>' +
               (centroElegido ? ' · ' + rdEsc(nombrePeaje(centroElegido)) + ' (' + rdEsc(proy.nombre) + ')' : (proy ? ' · ' + rdEsc(proy.nombre) : '')) + ' · ' + estado +
               ' La promesa y el ejecutado se comparan solo en los días que ya tienen ejecutado registrado.';
    $('#consNota').html('<p class="rd-ayuda" style="margin:0 0 10px;">' + nota + '</p>' +
        (!datos.proyectos.length ? '<div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-info-circle"></i> No tienes proyectos para el consolidado: no coordinas ninguno y tu rol no tiene la casilla «Tablero: ver todos los proyectos» (20M).</div>'
         : (filaDatos.res === 'SIN_DATOS' && periodo.corte > 0 && periodo.desde <= periodo.corte ? '<div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-info-circle"></i> ' +
             (todoMes ? 'En este mes' : 'En esos días') + ' no hay ejecutado registrado. Abajo sale solo el ausentismo.</div>' : '')));

    //Las cifras grandes
    var hay = filaDatos.res !== 'SIN_DATOS';
    //Arma una de las tarjetas de resumen de arriba
    var tarjetaKpi = function (rotulo, valor, pie, clase) { return '<div class="pv-kpi ' + (clase || '') + '"><span>' + rotulo + '</span><strong>' + valor + '</strong><small>' + (pie || '&nbsp;') + '</small></div>'; };
    $('#consKpis').html(
        tarjetaKpi('Promesa de valor', hay ? conMiles(filaDatos.prom) : '—', 'personas-día prometidas') +
        tarjetaKpi('Ejecutado', hay ? conMiles(filaDatos.eje) : '—', 'personas-día que trabajaron') +
        tarjetaKpi('Diferencia', hay ? (filaDatos.dif > 0 ? '+' : (filaDatos.dif < 0 ? '−' : '')) + conMiles(Math.abs(filaDatos.dif)) : '—', hay ? (filaDatos.dif < 0 ? 'se ejecutó menos de lo prometido' : (filaDatos.dif > 0 ? 'se ejecutó más de lo prometido' : 'se cumplió justo lo prometido')) : '',
          filaDatos.dif < 0 ? 'pv-kpi-menos' : (filaDatos.dif > 0 ? 'pv-kpi-mas' : '')) +
        tarjetaKpi('% de ejecución', filaDatos.pct === null ? '—' : String(filaDatos.pct).replace('.', ',') + ' %', '') +
        tarjetaKpi('Ausencias', conMiles(filaDatos.ausTotal), todoMes ? 'en el mismo mes' : 'en los mismos días') +
        tarjetaKpi('Causa principal', filaDatos.causa ? rdEsc(nombreCorto(filaDatos.causa)) : 'Sin ausencias', 'el motivo que más pesa', 'pv-kpi-texto') +
        tarjetaKpi('Días registrados', filaDatos.diasEsp ? conMiles(filaDatos.diasReg) + ' de ' + conMiles(filaDatos.diasEsp) : '—', centroElegido ? 'días con ejecutado' : (proy ? 'días de peaje con ejecutado' : 'días de peaje, entre todos'), 'pv-kpi-medio'));

    $('#consTablas').html(!datos.proyectos.length ? '' : (centroElegido ? tablasPeaje(proy, centroElegido) : (proy ? tablasProyecto(proy) : tablasGeneral(datos))));
}

//Todos los proyectos: como la hoja MAIN del Excel
function tablasGeneral(datos) {
    var html = '', filas = '';
    //1. Resumen por proyecto (el nombre lleva a la vista de ese proyecto)
    datos.proyectos.forEach(function (proyecto) {
        filas += '<tr><td class="pv-nombre"><a href="#" class="cons-ir-proyecto" data-id="' + proyecto.id + '" title="Ver los peajes de este proyecto">' + rdEsc(proyecto.nombre) + '</a></td>' + celdas(proyecto.fila, true) + '</tr>';
    });
    filas += '<tr class="pv-total"><td class="pv-nombre">Total ' + (datos.proyectos.length === 1 ? '' : datos.proyectos.length + ' proyectos') + '</td>' + celdas(datos.total, true) + '</tr>';
    html += seccion('1. Resumen por proyecto', 'Toca el nombre de un proyecto para ver sus peajes y cargos.',
                 '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Proyecto'], true) + '<tbody>' + filas + '</tbody></table></div>');

    //2. Por semana
    html += seccion('2. Por semana', ayudaSemanas(), tablaSemanas('Proyecto', datos.proyectos, datos.total, 'Total'));

    //3. Por cargo, sumando todos los proyectos
    filas = '';
    datos.roles.forEach(function (rol) { filas += '<tr><td class="pv-nombre">' + rdEsc(rol.nombre) + '</td>' + celdas(rol.fila, false) + '</tr>'; });
    filas += '<tr class="pv-total"><td class="pv-nombre">Total</td>' + celdas(datos.total, false) + '</tr>';
    html += seccion('3. Resumen por cargo', '', '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Cargo'], false) + '<tbody>' + filas + '</tbody></table></div>');

    //4. Detalle por peaje
    filas = '';
    datos.proyectos.forEach(function (proyecto) {
        proyecto.centros.forEach(function (centro, i) {
            filas += '<tr class="' + (i === 0 ? 'pv-corte' : '') + '"><td class="pv-nombre">' + enlacePeaje(centro) + '</td><td class="pv-sub">' + rdEsc(proyecto.nombre) + '</td>' + celdas(centro.fila, true) + '</tr>';
        });
    });
    filas += '<tr class="pv-total"><td class="pv-nombre">Total</td><td></td>' + celdas(datos.total, true) + '</tr>';
    html += seccion('4. Detalle por peaje', 'Toca el nombre de un peaje para ver solo ese peaje; el calendario abre su mes día por día.',
                 '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Peaje', 'Proyecto'], true) + '<tbody>' + filas + '</tbody></table></div>');
    return html;
}

//Un proyecto: como su hoja en el Excel (resumen por peaje, por semana y detalle por peaje y cargo)
function tablasProyecto(proyecto) {
    var html = '', filas = '';
    if (datosCons.proyectos.length > 1) { html += '<p style="margin:0 0 4px;"><a href="#" id="consVolver"><i class="fa fa-chevron-left"></i> Volver a todos los proyectos</a></p>'; }
    proyecto.centros.forEach(function (centro) { filas += '<tr><td class="pv-nombre">' + enlacePeaje(centro) + '</td>' + celdas(centro.fila, true) + '</tr>'; });
    filas += '<tr class="pv-total"><td class="pv-nombre">Total ' + rdEsc(proyecto.nombre) + '</td>' + celdas(proyecto.fila, true) + '</tr>';
    html += seccion('1. Resumen por peaje', 'Toca el nombre de un peaje para ver solo ese peaje; el calendario abre su mes día por día.',
                 '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Peaje'], true) + '<tbody>' + filas + '</tbody></table></div>');

    //Las semanas, con el nombre del peaje sin enlace (cabe mejor)
    html += seccion('2. Por semana', ayudaSemanas(),
                 tablaSemanas('Peaje', proyecto.centros.map(function (centro) { return { nombre: nombrePeaje(centro), fila: centro.fila }; }), proyecto.fila, 'Total ' + rdEsc(proyecto.nombre)));

    filas = '';
    proyecto.centros.forEach(function (centro) {
        centro.cargos.forEach(function (cargo, i) {
            filas += '<tr class="' + (i === 0 ? 'pv-corte' : '') + '"><td class="pv-nombre">' + (i === 0 ? rdEsc(centro.nombre) : '') + '</td><td class="pv-sub">' + rdEsc(cargo.nombre) + '</td>' + celdas(cargo.fila, false) + '</tr>';
        });
        if (!centro.cargos.length) { filas += '<tr class="pv-corte"><td class="pv-nombre">' + rdEsc(centro.nombre) + '</td><td class="pv-sub" colspan="20">Sin promesa de valor ni registros en el mes</td></tr>'; }
    });
    filas += '<tr class="pv-total"><td class="pv-nombre">Total ' + rdEsc(proyecto.nombre) + '</td><td></td>' + celdas(proyecto.fila, false) + '</tr>';
    html += seccion('3. Detalle por peaje y cargo', '', '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Peaje', 'Cargo'], false) + '<tbody>' + filas + '</tbody></table></div>');
    return html;
}

//Un solo peaje: sus cifras por cargo y por semana
function tablasPeaje(proyecto, centro) {
    var html = '<p style="margin:0 0 4px;"><a href="#" class="cons-ir-proyecto" data-id="' + proyecto.id + '"><i class="fa fa-chevron-left"></i> Volver a ' + rdEsc(proyecto.nombre) + '</a>' +
            ($('.side-menu a[href="PromesaValorVista.php"]').length ? ' &nbsp;·&nbsp; ' + enlaceDiaADia(centro) + ' Su mes día por día' : '') + '</p>';
    var filas = '';
    centro.cargos.forEach(function (cargo) { filas += '<tr><td class="pv-nombre">' + rdEsc(cargo.nombre) + '</td>' + celdas(cargo.fila, false) + '</tr>'; });
    if (!centro.cargos.length) { filas += '<tr><td class="pv-nombre" colspan="20">Sin promesa de valor ni registros en estos días</td></tr>'; }
    filas += '<tr class="pv-total"><td class="pv-nombre">Total ' + rdEsc(nombrePeaje(centro)) + '</td>' + celdas(centro.fila, false) + '</tr>';
    html += seccion('1. Resumen por cargo', '', '<div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera(['Cargo'], false) + '<tbody>' + filas + '</tbody></table></div>');

    html += seccion('2. Por semana', ayudaSemanas(), tablaSemanas('Cargo', centro.cargos, centro.fila, 'Total ' + rdEsc(nombrePeaje(centro))));
    return html;
}
