//Consultas - Reporte a la Gerencia (de un día o de un rango de días, hasta 31)
//Con la estructura del informe: encabezado, 1. Promesa de valor, 2. Avances y gestión por proyecto,
//3. Gestiones transversales, 4. Avance de los planes de trabajo, 5. Compromisos y seguimiento,
//6. Asuntos que requieren decisión de la Gerencia, el estado de vacantes y la firma.
//Cada sección la propone el sistema (repG.sistema) y se corrige, se agrega o se quita a mano
var URL_GER = '../Control/ReporteGerenciaControl.php';
var cfgG = null, repG = null;
var GER_MAX_DIAS = 31;
//Reportes guardados: se muestran por mes. mesG es el mes que se está viendo en la lista y diasMesG sus
//reportes: [{desde, hasta}] (desde = hasta cuando es de un solo día)
var mesG = '', diasMesG = [];
var MESES_G = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
var DIAS_G = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

$(function () {
    $.getJSON(URL_GER + '?op=config')
        .done(function (config) {
            cfgG = config;
            $('#gFecha, #gDesde').attr('max', config.hoy).val(config.hoy);
            abrirDia(config.hoy, config.hoy);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir el reporte a la Gerencia.'); });

    //El último día: si queda antes del primero, el periodo pasa a ser solo ese día
    $('#gFecha').on('change', function () {
        if (!this.value) { return; }
        var desde = $('#gDesde').val();
        cambiarDia(this.value, desde && desde <= this.value ? desde : this.value);
    });
    //El primer día: si queda después del último, el periodo pasa a ser solo ese día
    $('#gDesde').on('change', function () {
        if (!this.value) { return; }
        var hasta = $('#gFecha').val();
        cambiarDia(hasta && hasta >= this.value ? hasta : this.value, this.value);
    });
    //Las flechas corren todo el periodo un día (sin pasar de hoy)
    $('#btnAntes').on('click', function () { cambiarDia(rdSumarDias($('#gFecha').val(), -1), rdSumarDias($('#gDesde').val(), -1)); });
    $('#btnDespues').on('click', function () { var dia = rdSumarDias($('#gFecha').val(), 1); if (dia <= cfgG.hoy) { cambiarDia(dia, rdSumarDias($('#gDesde').val(), 1)); } });
    //Atajos: solo el último día, la semana (desde el lunes) o el mes (desde el 1) hasta el último día
    $('[data-ger-periodo]').on('click', function () {
        var hasta = $('#gFecha').val() || cfgG.hoy, cual = $(this).data('ger-periodo'), desde = hasta;
        if (cual === 'semana') { var diaSemana = rdAFecha(hasta).getDay(); desde = rdSumarDias(hasta, -((diaSemana + 6) % 7)); }
        if (cual === 'mes') { desde = hasta.substring(0, 8) + '01'; }
        cambiarDia(hasta, desde);
    });
    $('#btnGuardar, #btnGuardar2').on('click', guardar);
    $('#btnTraerTodo').on('click', function () {
        hanaConfirmar('¿Volver a llenar todas las secciones con lo que hay en el sistema? Se reemplaza lo que escribiste (lo guardado no cambia hasta que guardes). El encabezado y la firma no cambian.', function () {
            pintarInforme($.extend({}, repG.sistema, { encabezado: leerReporte().encabezado }));
            rdHayCambios = true;
        }, { aceptar: 'Sí, traer todo', cancelar: 'No' });
    });
    $('#btnCopiar').on('click', copiar);
    $('#btnPdf, #btnPdfFlota').on('click', abrirPdf);
    $('#btnWord, #btnWordFlota').on('click', descargarWord);
    $('#btnGuardarFlota').on('click', guardar);
    //Los botones de guardar y exportar quedan siempre a la vista: cuando los de arriba salen de la pantalla
    //al bajar, aparece la barra flotante abajo a la derecha
    $(window).on('scroll resize', mostrarFlotante);
    window.addEventListener('beforeprint', armarImpresion);
    $(document).on('click', '.ger-traer', function () { traerSeccion($(this).data('seccion'), $(this).data('proyecto')); });
    $(document).on('click', '.ger-agregar', function () { agregarApartado($(this)); });
    $(document).on('click', '.ger-quitar', function () { $(this).closest('.ger-apartado, tr').remove(); rdHayCambios = true; });
    $(document).on('click', '.ger-subir, .ger-bajar', function () {
        var fila = $(this).closest('.ger-apartado, tr');
        if ($(this).hasClass('ger-subir')) { fila.prev('.ger-apartado, tr').before(fila); } else { fila.next('.ger-apartado, tr').after(fila); }
        rdHayCambios = true;
    });
    $(document).on('click', '.ger-dias-mes a', function (evento) { evento.preventDefault(); cambiarDia($(this).data('dia'), $(this).data('desde')); });
    $('#gMes').on('change', function () { verMes(this.value); });
    $(document).on('input change', '#gInforme input, #gInforme textarea, #gInforme select', function () { rdHayCambios = true; if (this.tagName === 'TEXTAREA') { ajustarAlto(this); } });
    //Tabla de vacantes: quitar y agregar filas; los totales y los días se recalculan al escribir
    $(document).on('click', '#gPlanAgregar', function () { $('#gPlanes tbody').append(htmlPlan({})); $('#gPlanes tbody tr:last input').first().trigger('focus'); rdHayCambios = true; });
    $(document).on('click', '#gEscalarAgregar', function () { $(htmlEscalamiento({})).appendTo('#gEscalamientos').find('.ger-x-asunto').trigger('focus'); rdHayCambios = true; });
    $(document).on('input change', '#gCompromisos .ger-c-fecha, #gCompromisos .ger-c-cumplido', estadosCompromisos);
    $(document).on('click', '.ger-v-quitar', function () { $(this).closest('tr').remove(); rdHayCambios = true; totalesVacantes(); });
    $(document).on('click', '#gVacAgregar', function () {
        $('#gVacantes tbody').append(htmlFilaVacante({ proyecto: 0, centro: '', total: 1, antigua: '', cargo: '', compromiso: '' }));
        $('#gVacantes tbody tr:last .ger-v-centro').trigger('focus'); rdHayCambios = true; totalesVacantes();
    });
    $(document).on('input change', '#gVacantes input, #gVacantes select', function () {
        totalesVacantes();
        if ($(this).hasClass('ger-v-antigua')) {
            var dias = this.value ? diasEntreG(this.value, repG.fecha) : null;
            $(this).siblings('.ger-dias').text(dias !== null ? dias + (dias === 1 ? ' día' : ' días') : '');
        }
    });
    window.addEventListener('beforeunload', function (evento) { if (rdHayCambios) { evento.preventDefault(); evento.returnValue = ''; } });
});

//Cambia de periodo (dia = último día; desde = primer día, o el mismo día), avisando si hay cambios sin guardar
function cambiarDia(dia, desde) {
    desde = desde && desde <= dia ? desde : dia;
    var volver = function () { $('#gFecha').val(repG ? repG.fecha : cfgG.hoy); $('#gDesde').val(repG ? repG.desde : cfgG.hoy); };
    if ((rdAFecha(dia) - rdAFecha(desde)) / 86400000 + 1 > GER_MAX_DIAS) { alert('El reporte puede cubrir hasta ' + GER_MAX_DIAS + ' días. Acorta el rango.'); volver(); return; }
    if (rdHayCambios && !window.confirm('El reporte tiene cambios sin guardar. ¿Cambiar de periodo y perderlos?')) { volver(); return; }
    $('#gFecha').val(dia); $('#gDesde').val(desde);
    abrirDia(dia, desde);
}

//¿El reporte abierto es de un rango de días?
function esRango() { return repG && repG.desde && repG.desde < repG.fecha; }

//Trae el reporte de un periodo (o de un día) y lo pinta
function abrirDia(dia, desde) {
    $('#gEstado').removeClass('ok').text('Cargando...');
    $.getJSON(URL_GER + '?op=abrir&fecha=' + encodeURIComponent(dia) + '&desde=' + encodeURIComponent(desde || dia))
        .done(function (reporte) {
            repG = reporte; rdHayCambios = false;
            $('#gDesde').val(reporte.desde); $('#gFecha').val(reporte.fecha);
            $('#btnDespues').prop('disabled', reporte.fecha >= cfgG.hoy);
            pintarInforme(reporte.reporte);
            pintarEstado();
            //La lista de días guardados se pone en el mes del día abierto
            mesG = reporte.mes; diasMesG = reporte.guardadosMes || [];
            pintarGuardados();
        })
        .fail(function (xhr) { $('#gEstado').text(rdError(xhr, 'No se pudo cargar el reporte.')); });
}

//Lo que dice la barra de estado: guardado o propuesto por el sistema
function pintarEstado() {
    var g = repG.guardado;
    if (g) {
        //Solo quién lo guardó por última vez, el día y la hora
        $('#gEstado').addClass('ok').html('<i class="fa fa-check-circle"></i> Reporte ' + (esRango() ? 'del periodo' : 'del') + ' <strong>' + rdEsc(repG.diaCorto) + '</strong> guardado por ' + rdEsc(g.quien || '') +
            ' el ' + rdFecha(g.fecha) + ' a las ' + rdHora(String(g.fecha).substring(11)) + '.');
    } else {
        $('#gEstado').removeClass('ok').html('<i class="fa fa-info-circle"></i> El reporte ' + (esRango() ? 'del periodo' : 'del') + ' <strong>' + rdEsc(repG.diaCorto) + '</strong> todavía no se ha guardado: lo que ves lo propone el sistema.');
    }
}

//"2026-11" -> "noviembre 2026"
function nombreMes(mes) { return MESES_G[parseInt(mes.substring(5, 7), 10) - 1] + ' ' + mes.substring(0, 4); }

//La lista de meses (los que tienen reportes guardados, más el de hoy y el que se está viendo) y,
//al lado, los reportes guardados del mes elegido, como botoncitos: "mié 4" (un día) o "10–14" (un periodo)
function pintarGuardados() {
    var meses = (cfgG.meses || []).slice();
    [cfgG.hoy.substring(0, 7), mesG].forEach(function (mes) { if (mes && meses.indexOf(mes) < 0) { meses.push(mes); } });
    meses.sort().reverse();
    $('#gMes').html(meses.map(function (mes) {
        return '<option value="' + rdEsc(mes) + '"' + (mes === mesG ? ' selected' : '') + '>' + rdEsc(nombreMes(mes)) + '</option>';
    }).join(''));
    var html = diasMesG.map(function (periodo) {
        var hasta = rdAFecha(periodo.hasta), desde = rdAFecha(periodo.desde), rango = periodo.desde < periodo.hasta;
        var actual = repG && periodo.hasta === repG.fecha && periodo.desde === repG.desde;
        var texto = rango ? (desde.getMonth() === hasta.getMonth() ? desde.getDate() : desde.getDate() + '/' + (desde.getMonth() + 1)) + '–' + hasta.getDate()
                          : DIAS_G[hasta.getDay()] + ' ' + hasta.getDate();
        return '<a href="#" data-dia="' + rdEsc(periodo.hasta) + '" data-desde="' + rdEsc(periodo.desde) + '"' + (actual ? ' class="actual"' : '') +
               ' title="' + rdEsc(rango ? 'Del ' + rdFecha(periodo.desde) + ' al ' + rdFecha(periodo.hasta) : rdFecha(periodo.hasta)) + '">' + texto + '</a>';
    }).join('');
    $('#gDiasMes').html(html || '<span class="vacio">Ningún reporte guardado en este mes.</span>');
}

//Trae los días guardados de otro mes (el día abierto no cambia hasta que se elija uno)
function verMes(mes) {
    $.getJSON(URL_GER + '?op=guardados&mes=' + encodeURIComponent(mes))
        .done(function (respuesta) { mesG = respuesta.mes; diasMesG = respuesta.guardados || []; pintarGuardados(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudieron traer los reportes guardados de ese mes.')); });
}

//-----------------------------------------------------------------------
// El informe en la pantalla, por secciones
//-----------------------------------------------------------------------

//Las opciones del proyecto de un apartado: «General» (de todo el informe) o uno de los proyectos
function opcionesProyecto(elegido) {
    return '<option value="0">General</option>' + repG.proyectos.map(function (p) {
        return '<option value="' + parseInt(p.id, 10) + '"' + (+elegido === +p.id ? ' selected' : '') + '>' + rdEsc(p.nombre) + '</option>';
    }).join('');
}

//Un apartado: [proyecto] título y texto, con botones para subirlo, bajarlo o quitarlo
function htmlApartado(a, conProyecto) {
    return '<div class="ger-apartado">' +
        '<div class="ger-apartado-cab">' +
            (conProyecto ? '<select class="form-control input-sm ger-a-proyecto" aria-label="Proyecto">' + opcionesProyecto(a.proyecto || 0) + '</select>' : '') +
            '<input type="text" class="form-control input-sm ger-a-titulo" maxlength="200" placeholder="Título del apartado" aria-label="Título">' +
            '<span class="ger-mover"><button type="button" class="btn btn-default btn-sm ger-subir" title="Subir"><i class="fa fa-arrow-up"></i></button>' +
            '<button type="button" class="btn btn-default btn-sm ger-bajar" title="Bajar"><i class="fa fa-arrow-down"></i></button>' +
            '<button type="button" class="btn btn-default btn-sm ger-quitar" title="Quitar este apartado"><i class="fa fa-trash-o"></i></button></span>' +
        '</div>' +
        '<textarea class="form-control ger-a-texto" rows="2" maxlength="8000" placeholder="Lo que se informa. Los renglones que empiezan con • salen como viñetas."></textarea>' +
    '</div>';
}

//Un renglón de la tabla de compromisos: asunto, qué se está haciendo, quién responde, para cuándo y si ya se cumplió.
//El detalle (días abierto, fecha corrida) lo pone el sistema y el origen dice de dónde salió (tema, cronograma o a mano)
function htmlCompromiso() {
    return '<tr data-origen="manual"><td class="ger-manito" title="Arrastra para cambiarlo de lugar"><i class="fa fa-hand-paper-o"></i></td>' +
        '<td><select class="form-control input-sm ger-c-proyecto" aria-label="Proyecto">' + opcionesProyecto(0) + '</select></td>' +
        '<td><textarea class="form-control input-sm ger-c-gestion" rows="1" maxlength="300" placeholder="Asunto" aria-label="Asunto"></textarea><small class="ger-c-detalle rd-ayuda"></small></td>' +
        '<td><textarea class="form-control input-sm ger-c-accion" rows="1" maxlength="600" placeholder="Qué se está haciendo" aria-label="Qué se está haciendo"></textarea></td>' +
        '<td><input type="text" class="form-control input-sm ger-c-responsable" maxlength="120" placeholder="Quién responde" aria-label="Responsable"></td>' +
        '<td class="ger-fecha-col"><input type="date" class="form-control input-sm ger-c-fecha" aria-label="Fecha comprometida"></td>' +
        '<td class="text-center"><input type="checkbox" class="ger-c-cumplido" aria-label="Cumplido"></td>' +
        '<td class="ger-c-estado text-center"></td>' +
        '<td class="rd-col-acciones"><span class="ger-mover"><button type="button" class="btn btn-default btn-sm ger-subir" title="Subir"><i class="fa fa-arrow-up"></i></button>' +
        '<button type="button" class="btn btn-default btn-sm ger-bajar" title="Bajar"><i class="fa fa-arrow-down"></i></button>' +
        '<button type="button" class="btn btn-default btn-sm ger-quitar" title="Quitar"><i class="fa fa-trash-o"></i></button></span></td></tr>';
}

//Pone los apartados en un contenedor (los textos van con .val(), nunca dentro del HTML)
function pintarApartados(contenedor, lista, conProyecto) {
    contenedor.empty();
    (lista || []).forEach(function (a) {
        var bloque = $(htmlApartado(a, conProyecto)).appendTo(contenedor);
        bloque.find('.ger-a-titulo').val(a.titulo || '');
        bloque.find('.ger-a-texto').val(a.texto || '');
    });
    contenedor.find('textarea').each(function () { ajustarAlto(this); });
}

function pintarCompromisos(lista) {
    var cuerpo = $('#gCompromisos tbody').empty();
    (lista || []).forEach(function (c) {
        var fila = $(htmlCompromiso()).appendTo(cuerpo);
        fila.attr('data-origen', c.origen || 'manual');
        fila.find('.ger-c-proyecto').val(String(+c.proyecto || 0));
        fila.find('.ger-c-gestion').val(c.gestion || '');
        fila.find('.ger-c-detalle').text(c.detalle || '');
        fila.find('.ger-c-accion').val(c.accion || '');
        fila.find('.ger-c-responsable').val(c.responsable || '');
        fila.find('.ger-c-fecha').val(/^\d{4}-\d{2}-\d{2}$/.test(c.fecha || '') ? c.fecha : '');
        fila.find('.ger-c-cumplido').prop('checked', !!c.cumplido);
    });
    cuerpo.find('textarea').each(function () { ajustarAlto(this); });
    estadosCompromisos();
}

//En qué va cada compromiso el día del reporte (como lo pone el PDF)
function estadoCompromisoG(c) {
    if (c.cumplido) { return ['Cumplido', 'cumplido']; }
    if (!c.fecha) { return ['Sin fecha', 'sinfecha']; }
    if (c.fecha < repG.fecha) { var dias = diasEntreG(c.fecha, repG.fecha); return ['Vencido (' + dias + (dias === 1 ? ' día)' : ' días)'), 'vencido']; }
    return c.fecha === repG.fecha ? ['Vence hoy', 'hoy'] : ['En tiempo', 'tiempo'];
}
function estadosCompromisos() {
    $('#gCompromisos tbody tr').each(function () {
        var estado = estadoCompromisoG({ cumplido: $(this).find('.ger-c-cumplido').is(':checked'), fecha: $(this).find('.ger-c-fecha').val() });
        $(this).find('.ger-c-estado').html('<span class="ger-cestado ger-cestado-' + estado[1] + '">' + rdEsc(estado[0]) + '</span>');
    });
}

//Avance de los planes de trabajo: un renglón por entregable
function htmlPlan(p) {
    p = p || {};
    var num = function (v) { return v === null || v === undefined ? '' : String(v); };
    return '<tr><td><input type="text" class="form-control input-sm ger-p-proyecto" maxlength="60" placeholder="RDV" value="' + rdEsc(p.proyecto || '') + '"></td>' +
        '<td><textarea class="form-control input-sm ger-p-entregable" rows="1" maxlength="300" placeholder="Entregable">' + rdEsc(p.entregable || '') + '</textarea></td>' +
        '<td><input type="number" min="0" max="100" step="0.1" class="form-control input-sm ger-p-programado" value="' + rdEsc(num(p.programado)) + '"></td>' +
        '<td><input type="number" min="0" max="100" step="0.1" class="form-control input-sm ger-p-real" value="' + rdEsc(num(p.real)) + '"></td>' +
        '<td><input type="date" class="form-control input-sm ger-p-fecha" value="' + rdEsc(p.fecha || '') + '"></td>' +
        '<td><button type="button" class="btn btn-default btn-sm ger-quitar" title="Quitar"><i class="fa fa-trash-o"></i></button></td></tr>';
}

//Un asunto que requiere decisión de la Gerencia
function htmlEscalamiento(x) {
    x = x || {};
    return '<div class="ger-apartado ger-escalamiento">' +
        '<div class="ger-apartado-cab"><select class="form-control input-sm ger-x-proyecto" aria-label="Proyecto">' + opcionesProyecto(x.proyecto || 0) + '</select>' +
        '<input type="text" class="form-control input-sm ger-x-asunto" maxlength="300" placeholder="Asunto" aria-label="Asunto">' +
        '<span class="ger-mover"><button type="button" class="btn btn-default btn-sm ger-quitar" title="Quitar"><i class="fa fa-trash-o"></i></button></span></div>' +
        '<label>Impacto contractual</label><textarea class="form-control ger-x-impacto" rows="1" maxlength="1500" placeholder="Qué obligación o pago se afecta y desde cuándo"></textarea>' +
        '<label>Gestiones adelantadas</label><textarea class="form-control ger-x-gestiones" rows="1" maxlength="1500" placeholder="Qué se ha hecho y con quién"></textarea>' +
        '<label>Decisión requerida</label><textarea class="form-control ger-x-decision" rows="1" maxlength="1000" placeholder="Qué tiene que decidir la Gerencia y para cuándo"></textarea>' +
    '</div>';
}
function pintarEscalamientos(lista) {
    var caja = $('#gEscalamientos').empty();
    (lista || []).forEach(function (x) {
        var bloque = $(htmlEscalamiento(x)).appendTo(caja);
        bloque.find('.ger-x-asunto').val(x.asunto || ''); bloque.find('.ger-x-impacto').val(x.impacto || '');
        bloque.find('.ger-x-gestiones').val(x.gestiones || ''); bloque.find('.ger-x-decision').val(x.decision || '');
    });
    caja.find('textarea').each(function () { ajustarAlto(this); });
}

//El botón «Traer del sistema» de una sección
function botonTraer(seccion, proyecto) {
    return '<a class="ger-traer" data-seccion="' + seccion + '"' + (proyecto ? ' data-proyecto="' + parseInt(proyecto, 10) + '"' : '') +
           ' title="Vuelve a llenar esta sección con lo que hay en el sistema"><i class="fa fa-refresh"></i> Traer del sistema</a>';
}

//Arma todo el informe en la pantalla con un reporte {encabezado, resultados, prioritarias, proyectos, transversal, compromisos}
function pintarInforme(r) {
    var s = cfgG.secciones, n = 0;
    var html =
        '<div class="ger-seccion ger-encabezado"><div class="ger-sec-cab"><h3>Encabezado del informe</h3></div>' +
        '<div class="ger-titulo-informe">' + rdEsc(repG.titulo) + '</div><div class="ger-fecha-informe">' + rdEsc(repG.subtitulo || '') + '</div>' +
        '<div class="ger-fecha-informe">' + rdEsc(repG.fechaInforme) + '</div>' +
        '<p class="rd-ayuda">El destinatario, la presentación y la firma se toman del último informe guardado: cámbialos solo si hace falta. ' +
        'Sale como «Dirigido a: Dra. Johanna Vera Martínez» y, debajo, el cargo. En la presentación, la fecha de «con corte al …» se pone sola con el día del reporte.</p>' +
        '<div class="row">' +
            '<div class="col-sm-2"><label for="eTrato">Trato</label><input type="text" id="eTrato" class="form-control input-sm" maxlength="40" placeholder="Dra."></div>' +
            '<div class="col-sm-5"><label for="eDestinatario">Destinatario</label><input type="text" id="eDestinatario" class="form-control input-sm" maxlength="120"></div>' +
            '<div class="col-sm-5"><label for="eCargo">Cargo del destinatario</label><input type="text" id="eCargo" class="form-control input-sm" maxlength="150"></div>' +
            '<div class="col-sm-12"><label for="ePresentacion">Presentación (va después de «Cordial saludo:»)</label><textarea id="ePresentacion" class="form-control" rows="2" maxlength="1500"></textarea></div>' +
            '<div class="col-sm-6"><label for="eFirma">Firma (nombre)</label><input type="text" id="eFirma" class="form-control input-sm" maxlength="120"></div>' +
            '<div class="col-sm-6"><label for="eFirmaCargo">Cargo de quien firma</label><input type="text" id="eFirmaCargo" class="form-control input-sm" maxlength="150"></div>' +
        '</div></div>' +

        '<div class="ger-seccion"><div class="ger-sec-cab"><h3>' + (++n) + '. ' + rdEsc(s.resultados) + '</h3>' + botonTraer('resultados') + '</div>' +
        '<div class="ger-subtitulo">' + rdEsc(repG.tituloPromesa) + '</div>' +
        '<textarea id="gResultados" class="form-control" rows="3" maxlength="8000"></textarea>' +
        '<p class="rd-ayuda" style="margin-top:4px;">El PDF y el Word salen de este texto. La primera línea va en negrilla y separa el cumplimiento real de las horas de las estaciones que cumplieron el 100 % ' +
        '(«Cumplimiento real de horas: 98,3 % (…). 16 de 20 centros de operación cumplieron el 100 %.»): de ahí sale la cifra grande. Los porcentajes de cada proyecto salen de la línea «Por proyecto: …». ' +
        'Debajo va cada peaje que no cumplió («ANDES (ACCENORTE):», con viñetas por día) y un renglón <strong>«Plan de cobertura:»</strong> para escribir qué se hace mientras se cubre; si queda en blanco no sale.</p></div>' +

        '<div class="ger-seccion"><div class="ger-sec-cab"><h3>' + (++n) + '. ' + rdEsc(s.proyectos) + '</h3></div>' +
        '<div class="ger-sec-cab ger-sub-cab"><div class="ger-subtitulo">' + rdEsc(s.prioritarias) + '</div>' + botonTraer('prioritarias') + '</div>' +
        '<p class="rd-ayuda">Los temas con el cliente que se movieron y los oficios en gestión. Cada una sale como viñeta: «• PROYECTO – Asunto: lo que se informa».</p>' +
        '<div class="ger-lista" id="gPrioritarias"></div>' +
        '<button type="button" class="btn btn-default btn-sm ger-agregar" data-destino="#gPrioritarias" data-con-proyecto="1"><i class="fa fa-plus"></i> Agregar gestión</button>' +
        '<p class="rd-ayuda" style="margin-top:12px;">Luego, por proyecto (' + n + '.1, ' + n + '.2…), lo que se movió ayer y hoy: temas con Regency y actualizaciones de los temas con el cliente, oficios atendidos, vacantes cubiertas o canceladas y novedades. ' +
        'Cada apartado sale como viñeta con su título en negrilla. Los proyectos sin apartados no salen.</p>' +
        repG.proyectos.map(function (p) {
            return '<div class="ger-proyecto"><h4><span>' + rdEsc(p.nombre) + '</span>' + botonTraer('proyectos', p.id) + '</h4>' +
                   '<div class="ger-lista" id="gProyecto' + parseInt(p.id, 10) + '"></div>' +
                   '<button type="button" class="btn btn-default btn-sm ger-agregar" data-destino="#gProyecto' + parseInt(p.id, 10) + '"><i class="fa fa-plus"></i> Agregar apartado</button></div>';
        }).join('') + '</div>' +

        '<div class="ger-seccion"><div class="ger-sec-cab"><h3>' + (++n) + '. ' + rdEsc(s.transversal) + '</h3>' + botonTraer('transversal') + '</div>' +
        '<p class="rd-ayuda">Lo que toca a varios proyectos o áreas (personal, seguridad, SST…). Cada uno sale con su título en negrilla y el texto debajo. Si no hay nada, la sección no sale.</p>' +
        '<div class="ger-lista" id="gTransversal"></div>' +
        '<button type="button" class="btn btn-default btn-sm ger-agregar" data-destino="#gTransversal" data-con-proyecto="1"><i class="fa fa-plus"></i> Agregar apartado</button></div>' +

        '<div class="ger-seccion"><div class="ger-sec-cab"><h3>' + (++n) + '. ' + rdEsc(s.planes) + '</h3></div>' +
        '<p class="rd-ayuda">Para los planes que no están en HANA (por ejemplo, el de RDV): cada entregable con el porcentaje programado y el real a hoy, y la fecha comprometida. ' +
        'Pasa solo al reporte del día siguiente; ahí solo se actualizan los números. Si no hay nada, la sección no sale.</p>' +
        '<table class="table table-bordered rd-tabla rd-tabla-compacta" id="gPlanes"><thead><tr><th style="width:110px;">Proyecto</th><th>Entregable</th><th style="width:100px;">% programado</th>' +
        '<th style="width:100px;">% real</th><th style="width:150px;">Fecha comprometida</th><th style="width:40px;"></th></tr></thead><tbody></tbody></table>' +
        '<button type="button" class="btn btn-default btn-sm" id="gPlanAgregar"><i class="fa fa-plus"></i> Agregar entregable</button></div>' +

        '<div class="ger-seccion"><div class="ger-sec-cab"><h3>' + (++n) + '. ' + rdEsc(s.compromisos) + '</h3>' + botonTraer('compromisos') + '</div>' +
        '<p class="rd-ayuda">Se arma sola con los temas con el cliente y con Regency (lo que va, quién responde y la fecha que puso el coordinador en Temas con el cliente) y con lo programado en el cronograma. ' +
        'Completa lo que falte y agrega lo que no esté en ningún módulo. Lo que agregues a mano y no esté cumplido pasa al reporte del día siguiente. Si no hay nada, la sección no sale.</p>' +
        '<p class="rd-ayuda">Mantén presionada la <i class="fa fa-hand-paper-o"></i> y arrastra un compromiso para ponerlo donde lo necesites.</p>' +
        '<div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta ger-compromisos" id="gCompromisos"><thead><tr><th class="ger-manito-col"></th><th style="width:120px;">Proyecto</th>' +
        '<th class="ger-c-col-asunto">Asunto</th><th class="ger-c-col-accion">Qué se está haciendo</th><th style="width:140px;">Responsable</th><th class="ger-fecha-col">Fecha</th><th style="width:60px;">Cumplido</th><th style="width:90px;">Estado</th><th class="rd-col-acciones"></th></tr></thead><tbody></tbody></table></div>' +
        '<button type="button" class="btn btn-default btn-sm ger-agregar" data-destino="#gCompromisos"><i class="fa fa-plus"></i> Agregar compromiso</button></div>' +

        '<div class="ger-seccion"><div class="ger-sec-cab"><h3>' + (++n) + '. ' + rdEsc(s.escalamientos) + '</h3></div>' +
        '<p class="rd-ayuda">Solo lo que necesita una decisión de la Gerencia: el impacto en el contrato, lo que ya se hizo y la decisión que se pide. Pasa al reporte del día siguiente hasta que lo quites. Si no hay nada, la sección no sale.</p>' +
        '<div class="ger-lista" id="gEscalamientos"></div>' +
        '<button type="button" class="btn btn-default btn-sm" id="gEscalarAgregar"><i class="fa fa-plus"></i> Agregar asunto</button></div>' +

        '<div class="ger-seccion"><div class="ger-sec-cab"><h3>' + rdEsc(s.vacantes) + '</h3>' + botonTraer('vacantes') + '</div>' +
        '<p class="rd-ayuda">Sale del módulo de Vacantes: un renglón por estación con vacantes abiertas, la fecha de la más antigua (los días se cuentan solos), el cargo de esa vacante y la fecha que comprometió Gestión Humana. ' +
        'El PDF y el Word arman con esto el resumen por proyecto y el detalle. Se puede corregir aquí; queda guardada con el reporte.</p>' +
        '<div id="gVacantes"></div>' +
        '<label for="gNotaVacantes" style="margin-top:6px;">Nota (va debajo de la tabla)</label>' +
        '<textarea id="gNotaVacantes" class="form-control" rows="1" maxlength="1000" placeholder="GICA no presenta vacantes."></textarea></div>';
    $('#gInforme').html(html);
    arrastrable(document.querySelector('#gCompromisos tbody'));

    var e = r.encabezado || {};
    $('#eTrato').val(e.trato || ''); $('#eDestinatario').val(e.destinatario || ''); $('#eCargo').val(e.cargo || '');
    $('#ePresentacion').val(e.presentacion || ''); $('#eFirma').val(e.firma || ''); $('#eFirmaCargo').val(e.firmaCargo || '');
    $('#gResultados').val(r.resultados || '');
    pintarApartados($('#gPrioritarias'), r.prioritarias, true);
    repG.proyectos.forEach(function (p) { pintarApartados($('#gProyecto' + p.id), ((r.proyectos || {})[p.id] || {}).apartados, false); });
    pintarApartados($('#gTransversal'), r.transversal, true);
    pintarCompromisos(r.compromisos);
    $('#gPlanes tbody').html((r.planes || []).map(htmlPlan).join(''));
    pintarEscalamientos(r.escalamientos);
    pintarVacantes(r.vacantes);
    $('#gNotaVacantes').val(r.notaVacantes || '');
    $('#gInforme textarea').each(function () { ajustarAlto(this); });
    mostrarFlotante();
}

//Días entre dos fechas AAAA-MM-DD
function diasEntreG(desde, hasta) { return Math.max(0, Math.round((rdAFecha(hasta) - rdAFecha(desde)) / 86400000)); }

//La tabla de vacantes abiertas, editable: cada fila es una estación (proyecto, estación, cuántas, la fecha
//de la más antigua, el cargo de esa vacante y la fecha que comprometió Gestión Humana). Se puede corregir,
//quitar o agregar filas; el PDF y el Word arman el resumen por proyecto y el detalle
function pintarVacantes(lista) {
    var filas = (lista || []).map(function (v) {
        return { proyecto: +v.proyecto || 0, centro: v.centro || '', total: +v.total || 0, antigua: v.antigua || '', cargo: v.cargo || '', compromiso: v.compromiso || '' };
    });
    $('#gVacantes').html('<table class="table table-bordered rd-tabla rd-tabla-compacta ger-tabla ger-vac-edit"><thead><tr><th style="width:140px;">Proyecto</th>' +
        '<th style="width:150px;">Estación</th><th class="text-center" style="width:80px;">Vacantes</th><th style="width:160px;">Fecha de la más antigua</th>' +
        '<th>Cargo de la más antigua</th><th style="width:150px;">Compromiso GH</th><th style="width:40px;"></th></tr></thead>' +
        '<tbody>' + filas.map(htmlFilaVacante).join('') + '</tbody></table>' +
        '<div class="ger-vac-pie"><button type="button" class="btn btn-default btn-sm" id="gVacAgregar"><i class="fa fa-plus"></i> Agregar fila</button>' +
        ' <span id="gVacTotales" class="rd-ayuda"></span></div>' +
        //Los centros del sistema, para elegirlos al escribir (si se escribe distinto, al guardar se corrige)
        '<datalist id="gVacCentros">' + ((repG && repG.centros) || []).map(function (c) { return '<option value="' + rdEsc(c) + '">'; }).join('') + '</datalist>');
    $('#gVacantes textarea').each(function () { ajustarAlto(this); });
    totalesVacantes();
}

function htmlFilaVacante(v) {
    var dias = v.antigua ? diasEntreG(v.antigua, repG.fecha) : null;
    return '<tr><td><select class="form-control input-sm ger-v-proyecto">' + opcionesProyecto(v.proyecto).replace('>General<', '>Sin proyecto<') + '</select></td>' +
        '<td><input type="text" class="form-control input-sm ger-v-centro" maxlength="120" list="gVacCentros" autocomplete="off" value="' + rdEsc(v.centro) + '"></td>' +
        '<td><input type="number" class="form-control input-sm ger-v-total text-center" min="1" max="999" value="' + (v.total || '') + '"></td>' +
        '<td><input type="date" class="form-control input-sm ger-v-antigua" value="' + rdEsc(v.antigua || '') + '">' +
        '<span class="ger-dias">' + (dias !== null ? dias + (dias === 1 ? ' día' : ' días') : '') + '</span></td>' +
        '<td><input type="text" class="form-control input-sm ger-v-cargo" maxlength="120" value="' + rdEsc(v.cargo || '') + '"></td>' +
        '<td><input type="date" class="form-control input-sm ger-v-compromiso" value="' + rdEsc(v.compromiso || '') + '"></td>' +
        '<td class="text-center"><button type="button" class="btn btn-default btn-xs ger-v-quitar" title="Quitar esta fila"><i class="fa fa-times"></i></button></td></tr>';
}

//Lo que está en la tabla de vacantes de la pantalla (las filas sin centro o sin cantidad no cuentan)
function leerVacantes() {
    var lista = [];
    $('#gVacantes tbody tr').each(function () {
        var fila = $(this), centro = $.trim(fila.find('.ger-v-centro').val()), total = parseInt(fila.find('.ger-v-total').val(), 10) || 0;
        if (!centro || total < 1) { return; }
        lista.push({ proyecto: +fila.find('.ger-v-proyecto').val() || 0, centro: centro, total: total, antigua: fila.find('.ger-v-antigua').val() || null,
                     cargo: $.trim(fila.find('.ger-v-cargo').val()), compromiso: fila.find('.ger-v-compromiso').val() || null });
    });
    return lista;
}

//Debajo de la tabla: el total general y el de cada proyecto
function totalesVacantes() {
    var lista = leerVacantes(), total = 0, porProyecto = {}, orden = [];
    lista.forEach(function (v) {
        total += v.total;
        if (porProyecto[v.proyecto] === undefined) { porProyecto[v.proyecto] = 0; orden.push(v.proyecto); }
        porProyecto[v.proyecto] += v.total;
    });
    $('#gVacTotales').text(lista.length ? 'Total: ' + total + (total === 1 ? ' vacante' : ' vacantes') + ' · ' +
        orden.map(function (id) { return (nombreProyecto(id) || 'Sin proyecto') + ': ' + porProyecto[id]; }).join(' · ') : 'No hay vacantes abiertas en la tabla.');
}

//El resumen de las vacantes por proyecto: [{proyecto, total, dias (de la más antigua)}], en el orden de la tabla
function resumenVacantes(lista) {
    var orden = [], porProyecto = {};
    lista.forEach(function (v) {
        if (!porProyecto[v.proyecto]) { porProyecto[v.proyecto] = { proyecto: v.proyecto, total: 0, dias: null }; orden.push(v.proyecto); }
        var p = porProyecto[v.proyecto], dias = v.antigua ? diasEntreG(v.antigua, repG.fecha) : null;
        p.total += v.total;
        if (dias !== null && (p.dias === null || dias > p.dias)) { p.dias = dias; }
    });
    return orden.map(function (id) { return porProyecto[id]; });
}

//«23-nov»
function diaMesTablaG(fecha) {
    var meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'], f = rdAFecha(fecha);
    return ('0' + f.getDate()).slice(-2) + '-' + meses[f.getMonth()];
}

//Las tablas de vacantes para imprimir: el resumen por proyecto y el detalle por estación
function tablaVacantesHtml(lista) {
    var resumen = resumenVacantes(lista), total = 0, mayor = null;
    var html = resumen.map(function (p) {
        total += p.total;
        if (p.dias !== null && (mayor === null || p.dias > mayor)) { mayor = p.dias; }
        return '<tr><td>' + rdEsc((nombreProyecto(p.proyecto) || 'Sin proyecto').toUpperCase()) + '</td><td class="text-center">' + p.total + '</td><td class="text-center">' + (p.dias === null ? '—' : p.dias) + '</td></tr>';
    }).join('');
    html += '<tr class="ger-total"><td>TOTAL</td><td class="text-center">' + total + '</td><td class="text-center">' + (mayor === null ? '—' : mayor) + '</td></tr>';
    var detalle = lista.map(function (v) {
        var dias = v.antigua ? diasEntreG(v.antigua, repG.fecha) : null;
        return '<tr><td>' + rdEsc((nombreProyecto(v.proyecto) || '').toUpperCase()) + '</td><td>' + rdEsc(v.centro.toUpperCase()) + '</td><td class="text-center">' + v.total + '</td>' +
               '<td class="text-center">' + (v.antigua ? rdFecha(v.antigua) : '—') + '</td><td class="text-center">' + (dias === null ? '—' : dias) + '</td>' +
               '<td>' + rdEsc((v.cargo || '—').toUpperCase()) + '</td><td class="text-center">' + (v.compromiso ? diaMesTablaG(v.compromiso) : '—') + '</td></tr>';
    }).join('');
    return '<h4>Vacantes operativas – peajes y básculas</h4>' +
           '<table class="table table-bordered rd-tabla ger-tabla"><thead><tr><th>Proyecto</th><th>Cantidad de vacantes</th><th>Vacante más antigua (días)</th></tr></thead><tbody>' + html + '</tbody></table>' +
           '<h4>Detalle de vacantes por proyecto y estación</h4>' +
           '<table class="table table-bordered rd-tabla ger-tabla"><thead><tr><th>Proyecto</th><th>Estación</th><th>Cantidad</th><th>Fecha de la más antigua</th><th>Antigüedad (días)</th><th>Cargo de la más antigua</th><th>Compromiso GH</th></tr></thead><tbody>' + detalle + '</tbody></table>';
}

//La barra flotante con Guardar, PDF y Word: aparece cuando los botones de arriba ya no se ven
function mostrarFlotante() {
    var botones = $('.ger-botones');
    if (!botones.length || !repG) { $('#gerFlotante').removeClass('visible'); return; }
    var abajo = botones.offset().top + botones.outerHeight();
    $('#gerFlotante').toggleClass('visible', $(window).scrollTop() > abajo);
}

//Arrastrar con la manito para cambiar el orden de las filas de una tabla (como en las preguntas de las
//listas de chequeo). La fila solo se vuelve arrastrable mientras se presiona la manito: así se puede
//seguir escribiendo en sus campos. En el celular se hace con el dedo sobre la manito
function arrastrable(cuerpo) {
    if (!cuerpo) { return; }
    var arrastrada = null;
    var moverA = function (x, y) {
        var debajo = document.elementFromPoint(x, y);
        var destino = debajo && debajo.closest ? debajo.closest('tr') : null;
        if (!destino || destino === arrastrada || destino.parentNode !== cuerpo) { return; }
        var caja = destino.getBoundingClientRect();
        cuerpo.insertBefore(arrastrada, (y - caja.top) > caja.height / 2 ? destino.nextSibling : destino);
        rdHayCambios = true;
    };
    $(cuerpo).on('mousedown', '.ger-manito', function () { this.parentNode.setAttribute('draggable', 'true'); });
    $(document).off('mouseup.gerArrastre').on('mouseup.gerArrastre', function () { $('#gCompromisos tbody tr').attr('draggable', 'false'); });
    cuerpo.addEventListener('dragstart', function (evento) {
        arrastrada = evento.target.closest ? evento.target.closest('tr') : null;
        if (!arrastrada) { return; }
        try { evento.dataTransfer.setData('text/plain', ''); } catch (error) {} //sin esto Firefox no arrastra
        evento.dataTransfer.effectAllowed = 'move';
        setTimeout(function () { if (arrastrada) { arrastrada.classList.add('ger-arrastrando'); } }, 0);
    });
    cuerpo.addEventListener('dragover', function (evento) { evento.preventDefault(); if (arrastrada) { moverA(evento.clientX, evento.clientY); } });
    cuerpo.addEventListener('drop', function (evento) { evento.preventDefault(); });
    cuerpo.addEventListener('dragend', function () {
        if (arrastrada) { arrastrada.classList.remove('ger-arrastrando'); arrastrada.setAttribute('draggable', 'false'); }
        arrastrada = null;
    });
    cuerpo.addEventListener('touchstart', function (evento) {
        var manito = evento.target.closest ? evento.target.closest('.ger-manito') : null;
        if (!manito) { return; }
        arrastrada = manito.parentNode; arrastrada.classList.add('ger-arrastrando');
    }, { passive: true });
    cuerpo.addEventListener('touchmove', function (evento) {
        if (!arrastrada) { return; }
        evento.preventDefault(); //mientras se arrastra, la página no se desplaza
        moverA(evento.touches[0].clientX, evento.touches[0].clientY);
    }, { passive: false });
    var soltar = function () { if (arrastrada) { arrastrada.classList.remove('ger-arrastrando'); } arrastrada = null; };
    cuerpo.addEventListener('touchend', soltar);
    cuerpo.addEventListener('touchcancel', soltar);
}

//«Agregar»: un apartado o un compromiso en blanco al final de su lista
function agregarApartado(boton) {
    var destino = $(boton.data('destino'));
    if (destino.is('table')) { $(htmlCompromiso()).appendTo(destino.find('tbody')).find('input').first().focus(); }
    else { $(htmlApartado({ proyecto: 0 }, !!boton.data('con-proyecto'))).appendTo(destino).find('.ger-a-titulo').focus(); }
    rdHayCambios = true;
}

//«Traer del sistema» de una sección (o de un proyecto en los avances)
function traerSeccion(seccion, proyecto) {
    var s = repG.sistema;
    if (seccion === 'resultados') { $('#gResultados').val(s.resultados || ''); ajustarAlto($('#gResultados')[0]); }
    else if (seccion === 'prioritarias') { pintarApartados($('#gPrioritarias'), s.prioritarias, true); }
    else if (seccion === 'transversal') { pintarApartados($('#gTransversal'), s.transversal, true); }
    else if (seccion === 'compromisos') {
        //Lo que se agregó a mano y no está cumplido (primero), más lo del sistema
        pintarCompromisos(leerReporte().compromisos.filter(function (c) { return c.origen === 'manual' && !c.cumplido; }).concat(s.compromisos || []));
    }
    else if (seccion === 'vacantes') { pintarVacantes(s.vacantes); $('#gNotaVacantes').val(s.notaVacantes || ''); }
    else if (seccion === 'proyectos') { pintarApartados($('#gProyecto' + proyecto), ((s.proyectos || {})[proyecto] || {}).apartados, false); }
    rdHayCambios = true;
}

//Lo que hay escrito en la pantalla, con la estructura del informe
function leerApartados(contenedor, conProyecto) {
    var lista = [];
    contenedor.children('.ger-apartado').each(function () {
        var a = { titulo: $.trim($(this).find('.ger-a-titulo').val()), texto: $.trim($(this).find('.ger-a-texto').val()) };
        if (conProyecto) { a = $.extend({ proyecto: parseInt($(this).find('.ger-a-proyecto').val(), 10) || 0 }, a); }
        if (a.titulo || a.texto) { lista.push(a); }
    });
    return lista;
}

function leerReporte() {
    var proyectos = {};
    repG.proyectos.forEach(function (p) { proyectos[p.id] = { apartados: leerApartados($('#gProyecto' + p.id), false) }; });
    var compromisos = [];
    $('#gCompromisos tbody tr').each(function () {
        var c = { proyecto: +$(this).find('.ger-c-proyecto').val() || 0, gestion: $.trim($(this).find('.ger-c-gestion').val()), accion: $.trim($(this).find('.ger-c-accion').val()),
                  responsable: $.trim($(this).find('.ger-c-responsable').val()), fecha: $(this).find('.ger-c-fecha').val() || null,
                  cumplido: $(this).find('.ger-c-cumplido').is(':checked'), detalle: $(this).find('.ger-c-detalle').text(), origen: $(this).attr('data-origen') || 'manual' };
        if (c.gestion || c.accion) { compromisos.push(c); }
    });
    var planes = [];
    $('#gPlanes tbody tr').each(function () {
        var numero = function (v) { return v === '' ? null : parseFloat(v); };
        var p = { proyecto: $.trim($(this).find('.ger-p-proyecto').val()), entregable: $.trim($(this).find('.ger-p-entregable').val()),
                  programado: numero($(this).find('.ger-p-programado').val()), real: numero($(this).find('.ger-p-real').val()), fecha: $(this).find('.ger-p-fecha').val() || null };
        if (p.entregable) { planes.push(p); }
    });
    var escalamientos = [];
    $('#gEscalamientos .ger-escalamiento').each(function () {
        var x = { proyecto: +$(this).find('.ger-x-proyecto').val() || 0, asunto: $.trim($(this).find('.ger-x-asunto').val()), impacto: $.trim($(this).find('.ger-x-impacto').val()),
                  gestiones: $.trim($(this).find('.ger-x-gestiones').val()), decision: $.trim($(this).find('.ger-x-decision').val()) };
        if (x.asunto || x.impacto || x.gestiones || x.decision) { escalamientos.push(x); }
    });
    return {
        encabezado: { trato: $.trim($('#eTrato').val()), destinatario: $.trim($('#eDestinatario').val()), cargo: $.trim($('#eCargo').val()),
                      presentacion: $.trim($('#ePresentacion').val()), firma: $.trim($('#eFirma').val()), firmaCargo: $.trim($('#eFirmaCargo').val()) },
        resultados: $.trim($('#gResultados').val()),
        prioritarias: leerApartados($('#gPrioritarias'), true),
        proyectos: proyectos,
        transversal: leerApartados($('#gTransversal'), true),
        vacantes: leerVacantes(),
        notaVacantes: $.trim($('#gNotaVacantes').val()),
        compromisos: compromisos,
        escalamientos: escalamientos,
        planes: planes
    };
}

//Lo que se envía al guardar o al pedir el PDF
function paraEnviar() { return $.extend({ fecha: repG.fecha, desde: repG.desde }, leerReporte()); }

//El nombre de un proyecto por su ID ('' si es general)
function nombreProyecto(id) {
    var p = repG.proyectos.filter(function (x) { return +x.id === +id; })[0];
    return p ? p.nombre : '';
}

//El alto del cuadro se ajusta al texto
function ajustarAlto(campo) {
    if (!campo) { return; }
    campo.style.height = 'auto';
    campo.style.height = Math.min(Math.max(campo.scrollHeight + 2, 54), 600) + 'px';
}

//Guarda el reporte del día
function guardar() {
    if (!repG) { return; }
    hanaBoton('#btnGuardar', true); hanaBoton('#btnGuardar2', true);
    $.post(URL_GER + '?op=guardar', { reporte: JSON.stringify(paraEnviar()) }, null, 'json')
        .done(function (respuesta) {
            rdHayCambios = false; rdAviso(respuesta.mensaje);
            repG.guardado = { quien: 'ti', fecha: respuesta.fecha };
            //El día recién guardado entra a la lista de su mes (y el mes, a la lista de meses)
            if ((cfgG.meses || []).indexOf(repG.mes) < 0) { cfgG.meses = (cfgG.meses || []).concat([repG.mes]); }
            var yaEsta = diasMesG.some(function (p) { return p.hasta === repG.fecha && p.desde === repG.desde; });
            if (mesG === repG.mes && !yaEsta) {
                diasMesG.push({ desde: repG.desde, hasta: repG.fecha });
                diasMesG.sort(function (a, b) { return (a.hasta + a.desde) < (b.hasta + b.desde) ? -1 : 1; });
            }
            pintarEstado(); pintarGuardados();
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el reporte.')); })
        .always(function () { hanaBoton('#btnGuardar', false); hanaBoton('#btnGuardar2', false); });
}

//El informe como texto plano (para un correo o WhatsApp), con la misma estructura
function comoTexto() {
    var r = leerReporte(), e = r.encabezado, s = cfgG.secciones, n = 0, lineas = [];
    var apartados = function (lista) {
        if (!lista.length) { lista = [{ titulo: '', texto: 'Sin información.' }]; }
        lista.forEach(function (a) {
            var titulo = (a.proyecto ? nombreProyecto(a.proyecto) + ' | ' : '') + a.titulo;
            if (titulo) { lineas.push('*' + titulo + '*'); }
            if (a.texto) { lineas.push(a.texto); }
            lineas.push('');
        });
    };
    lineas.push('*' + repG.titulo + '*', '*' + (repG.subtitulo || '') + '*', '', '*' + repG.fechaInforme + '*', '');
    var destinatario = $.trim((e.trato || '') + ' ' + (e.destinatario || ''));
    if (destinatario) { lineas.push('*Dirigido a:* ' + destinatario); }
    if (e.cargo) { lineas.push(e.cargo); }
    lineas.push('', 'Cordial saludo:', '');
    if (e.presentacion) { lineas.push(e.presentacion, ''); }
    lineas.push('*' + (++n) + '. ' + s.resultados + '*', '*' + repG.tituloPromesa + '*', r.resultados || 'Sin información.', '');
    lineas.push('*' + (++n) + '. ' + s.proyectos + '*');
    if (r.prioritarias.length) { lineas.push('*' + s.prioritarias + ':*'); apartados(r.prioritarias); }
    var k = 0;
    repG.proyectos.forEach(function (p) {
        var lista = r.proyectos[p.id].apartados;
        if (!lista.length) { return; }
        lineas.push('*' + n + '.' + (++k) + '. ' + p.nombre.toUpperCase() + '*'); apartados(lista);
    });
    if (!k && !r.prioritarias.length) { lineas.push('Sin avances registrados.', ''); }
    if (r.transversal.length) { lineas.push('*' + (++n) + '. ' + s.transversal + '*'); apartados(r.transversal); }
    if (r.planes.length) {
        lineas.push('*' + (++n) + '. ' + s.planes + '*');
        r.planes.forEach(function (p) {
            lineas.push('• ' + (p.proyecto ? p.proyecto + ' – ' : '') + p.entregable + ': programado ' + (p.programado === null ? '—' : p.programado + ' %') + ', real ' + (p.real === null ? '—' : p.real + ' %') +
                        (p.fecha ? '. Fecha: ' + rdFecha(p.fecha) : ''));
        });
        lineas.push('');
    }
    if (r.compromisos.length) {
        lineas.push('*' + (++n) + '. ' + s.compromisos + '*');
        r.compromisos.forEach(function (c) {
            lineas.push('• ' + (nombreProyecto(c.proyecto) ? nombreProyecto(c.proyecto) + ' – ' : '') + c.gestion + ': ' + (c.accion || 'sin acción registrada') +
                        ' Responsable: ' + (c.responsable || 'sin responsable') + '. Fecha: ' + (c.fecha ? rdFecha(c.fecha) : 'sin fecha') + '. Estado: ' + estadoCompromisoG(c)[0] + '.');
        });
        lineas.push('');
    }
    if (r.escalamientos.length) {
        lineas.push('*' + (++n) + '. ' + s.escalamientos + '*');
        r.escalamientos.forEach(function (x) {
            lineas.push('*' + (nombreProyecto(x.proyecto) ? nombreProyecto(x.proyecto) + ' – ' : '') + (x.asunto || 'Asunto') + '*');
            if (x.impacto) { lineas.push('• Impacto contractual: ' + x.impacto); }
            if (x.gestiones) { lineas.push('• Gestiones adelantadas: ' + x.gestiones); }
            if (x.decision) { lineas.push('• Decisión requerida: ' + x.decision); }
        });
        lineas.push('');
    }
    lineas.push('*' + s.vacantes + '*');
    if (r.vacantes.length) {
        var totalVacantes = 0;
        resumenVacantes(r.vacantes).forEach(function (p) {
            totalVacantes += p.total;
            lineas.push('• ' + (nombreProyecto(p.proyecto) || 'Sin proyecto') + ': ' + p.total + (p.total === 1 ? ' vacante' : ' vacantes') + (p.dias !== null ? ', la más antigua de ' + p.dias + (p.dias === 1 ? ' día' : ' días') : ''));
        });
        lineas.push('Total: ' + totalVacantes + (totalVacantes === 1 ? ' vacante abierta' : ' vacantes abiertas'));
        r.vacantes.forEach(function (v) {
            var dias = v.antigua ? diasEntreG(v.antigua, repG.fecha) : null;
            lineas.push('– ' + (nombreProyecto(v.proyecto) ? nombreProyecto(v.proyecto) + ' – ' : '') + v.centro + ': ' + v.total + (dias !== null ? ', la más antigua de ' + dias + (dias === 1 ? ' día' : ' días') : '') +
                        (v.cargo ? ' (' + v.cargo + ')' : '') + (v.compromiso ? '. Compromiso GH: ' + diaMesTablaG(v.compromiso) : ''));
        });
    } else { lineas.push('No hay vacantes abiertas.'); }
    if (r.notaVacantes) { lineas.push('Nota: ' + r.notaVacantes); }
    lineas.push('', 'Atentamente,', '', e.firma ? '*' + e.firma + '*' : '', e.firmaCargo || '');
    return lineas.join('\n').replace(/\n{3,}/g, '\n\n').trim();
}

function copiar() {
    if (!repG) { return; }
    var texto = comoTexto();
    var hecho = function () { rdAviso('Reporte copiado. Pégalo en el correo o en WhatsApp.'); };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(texto).then(hecho, function () { copiarViejo(texto, hecho); });
    } else { copiarViejo(texto, hecho); }
}

//Para navegadores o conexiones sin el portapapeles moderno (http sin s)
function copiarViejo(texto, hecho) {
    var caja = $('<textarea style="position:fixed;top:-1000px;left:0;opacity:0;"></textarea>').val(texto).appendTo('body');
    caja[0].select();
    try { if (document.execCommand('copy')) { hecho(); } else { throw new Error('no'); } }
    catch (error) { alert('No se pudo copiar solo. Selecciona el texto con el mouse y cópialo.'); }
    caja.remove();
}

//Lo que sale en el papel: el informe limpio, sin cuadros ni botones
function armarImpresion() {
    if (!repG || !$('#gResultados').length) { return; }
    var r = leerReporte(), e = r.encabezado, s = cfgG.secciones, n = 0;
    var parrafo = function (texto) { return '<p>' + rdEsc(texto || '') + '</p>'; };
    var apartados = function (lista) {
        if (!lista.length) { return parrafo('Sin información.'); }
        return lista.map(function (a) {
            return '<h4>' + rdEsc((a.proyecto ? nombreProyecto(a.proyecto) + ' | ' : '') + a.titulo) + '</h4>' + parrafo(a.texto);
        }).join('');
    };
    var html = '<h1>' + rdEsc(repG.titulo) + '<br>' + rdEsc(repG.subtitulo || '') + '</h1><p><strong>' + rdEsc(repG.fechaInforme) + '</strong></p>' +
               '<p><strong>Dirigido a:</strong> ' + rdEsc($.trim((e.trato || '') + ' ' + (e.destinatario || ''))) + '</p>' + parrafo(e.cargo) +
               parrafo('Cordial saludo:') + parrafo(e.presentacion) +
               '<h2>' + (++n) + '. ' + rdEsc(s.resultados) + '</h2><h4>' + rdEsc(repG.tituloPromesa) + '</h4>' + parrafo(r.resultados) +
               '<h2>' + (++n) + '. ' + rdEsc(s.proyectos) + '</h2>' + (r.prioritarias.length ? '<h4>' + rdEsc(s.prioritarias) + ':</h4>' + apartados(r.prioritarias) : '');
    var k = 0;
    repG.proyectos.forEach(function (p) {
        var lista = r.proyectos[p.id].apartados;
        if (lista.length) { html += '<h4>' + n + '.' + (++k) + '. ' + rdEsc(p.nombre.toUpperCase()) + '</h4>' + apartados(lista); }
    });
    if (r.transversal.length) { html += '<h2>' + (++n) + '. ' + rdEsc(s.transversal) + '</h2>' + apartados(r.transversal); }
    if (r.planes.length) {
        html += '<h2>' + (++n) + '. ' + rdEsc(s.planes) + '</h2><table><thead><tr><th>Proyecto</th><th>Entregable</th><th>Programado</th><th>Real</th><th>Fecha comprometida</th></tr></thead><tbody>' +
                r.planes.map(function (p) {
                    return '<tr><td>' + rdEsc(p.proyecto) + '</td><td>' + rdEsc(p.entregable) + '</td><td>' + (p.programado === null ? '—' : p.programado + ' %') + '</td><td>' +
                           (p.real === null ? '—' : p.real + ' %') + '</td><td>' + (p.fecha ? rdFecha(p.fecha) : '—') + '</td></tr>';
                }).join('') + '</tbody></table>';
    }
    if (r.compromisos.length) {
        html += '<h2>' + (++n) + '. ' + rdEsc(s.compromisos) + '</h2><table><thead><tr><th>Proyecto</th><th>Asunto</th><th>Qué se está haciendo</th><th>Responsable</th><th>Fecha</th><th>Estado</th></tr></thead><tbody>' +
                r.compromisos.map(function (c) {
                    return '<tr><td>' + rdEsc(nombreProyecto(c.proyecto) || 'General') + '</td><td>' + rdEsc(c.gestion) + (c.detalle ? '<br><small>' + rdEsc(c.detalle) + '</small>' : '') + '</td><td>' +
                           rdEsc(c.accion || '—') + '</td><td>' + rdEsc(c.responsable || '—') + '</td><td>' + (c.fecha ? rdFecha(c.fecha) : '—') + '</td><td>' + rdEsc(estadoCompromisoG(c)[0]) + '</td></tr>';
                }).join('') + '</tbody></table>';
    }
    if (r.escalamientos.length) {
        html += '<h2>' + (++n) + '. ' + rdEsc(s.escalamientos) + '</h2>' + r.escalamientos.map(function (x) {
            return '<h4>' + rdEsc((nombreProyecto(x.proyecto) ? nombreProyecto(x.proyecto) + ' – ' : '') + (x.asunto || 'Asunto')) + '</h4>' +
                   (x.impacto ? '<p><strong>Impacto contractual:</strong> ' + rdEsc(x.impacto) + '</p>' : '') +
                   (x.gestiones ? '<p><strong>Gestiones adelantadas:</strong> ' + rdEsc(x.gestiones) + '</p>' : '') +
                   (x.decision ? '<p><strong>Decisión requerida:</strong> ' + rdEsc(x.decision) + '</p>' : '');
        }).join('');
    }
    html += '<h2>' + rdEsc(s.vacantes) + '</h2>' + (r.vacantes.length ? tablaVacantesHtml(r.vacantes) : parrafo('No hay vacantes abiertas.')) +
            (r.notaVacantes ? '<p><strong>Nota:</strong> ' + rdEsc(r.notaVacantes) + '</p>' : '') +
            '<p style="margin-top:18px;">Atentamente,</p><p style="margin-top:24px;"><strong>' + rdEsc(e.firma) + '</strong></p>' + parrafo(e.firmaCargo);
    $('#gerImpresion').html(html);
}

//El Word (.docx) del informe, con lo que está en la pantalla en este momento (aunque no se haya guardado).
//Se pide por detrás y se descarga; si algo falla, se muestra el mensaje del servidor
function descargarWord() {
    if (!repG) { return; }
    if (!window.fetch || !window.Blob || !window.URL) { alert('Este navegador no permite descargar el Word. Usa Chrome, Edge o Firefox actualizados.'); return; }
    hanaBoton('#btnWord', true); hanaBoton('#btnWordFlota', true);
    var datos = new FormData();
    datos.append('reporte', JSON.stringify(paraEnviar()));
    fetch('ReporteGerenciaWordVista.php', { method: 'POST', body: datos, credentials: 'same-origin' })
        .then(function (respuesta) {
            if (!respuesta.ok || (respuesta.headers.get('Content-Type') || '').indexOf('wordprocessingml') < 0) {
                return respuesta.text().then(function (texto) { throw new Error(texto && texto.length < 400 ? texto : 'No se pudo generar el Word.'); });
            }
            var disposicion = respuesta.headers.get('Content-Disposition') || '', hallado = /filename="([^"]+)"/.exec(disposicion);
            var nombre = hallado ? hallado[1] : 'REPORTE_DIARIO_' + repG.fecha + '.docx';
            return respuesta.blob().then(function (archivo) {
                var enlace = document.createElement('a');
                enlace.href = URL.createObjectURL(archivo); enlace.download = nombre;
                document.body.appendChild(enlace); enlace.click();
                setTimeout(function () { URL.revokeObjectURL(enlace.href); enlace.remove(); }, 2000);
            });
        })
        .catch(function (error) { alert(error.message || 'No se pudo generar el Word.'); })
        .then(function () { hanaBoton('#btnWord', false); hanaBoton('#btnWordFlota', false); });
}

//El PDF para la Gerencia. Lleva lo que está en la pantalla en este momento, aunque no se haya
//guardado; se abre en otra pestaña, desde donde se descarga o se imprime
function abrirPdf() {
    if (!repG) { return; }
    var form = $('<form method="post" target="_blank" action="ReporteGerenciaPdfVista.php" style="display:none;"></form>');
    $('<input type="hidden" name="reporte">').val(JSON.stringify(paraEnviar())).appendTo(form);
    form.appendTo('body').trigger('submit');
    setTimeout(function () { form.remove(); }, 1000);
}
