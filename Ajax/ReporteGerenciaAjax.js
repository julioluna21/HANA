//Consultas - Reporte a la Gerencia (de un día o de un rango de días, hasta 31)
//Con la estructura del informe: encabezado, 1. Resultados, 2. Gestiones prioritarias y compromisos
//con clientes, 3. Avances por proyecto, 4. Talento humano y SST, 5. Compromisos y la firma.
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
    $('#btnPdf').on('click', abrirPdf);
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
        '<textarea class="form-control ger-a-texto" rows="2" maxlength="4000" placeholder="Lo que se informa. Los renglones que empiezan con • salen como viñetas."></textarea>' +
    '</div>';
}

//Un renglón de la tabla de compromisos
function htmlCompromiso() {
    return '<tr><td><input type="text" class="form-control input-sm ger-c-gestion" maxlength="300" placeholder="Gestión" aria-label="Gestión"></td>' +
        '<td class="ger-fecha-col"><input type="text" class="form-control input-sm ger-c-fecha" maxlength="60" placeholder="20 de noviembre" aria-label="Fecha objetivo"></td>' +
        '<td><span class="ger-mover"><button type="button" class="btn btn-default btn-sm ger-subir" title="Subir"><i class="fa fa-arrow-up"></i></button>' +
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
        fila.find('.ger-c-gestion').val(c.gestion || '');
        fila.find('.ger-c-fecha').val(c.fecha || '');
    });
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
        '<div class="ger-fecha-informe">' + rdEsc(repG.fechaInforme) + '</div><div class="ger-titulo-informe">' + rdEsc(repG.titulo) + '</div>' +
        '<p class="rd-ayuda">El destinatario, la presentación y la firma se toman del último informe guardado: cámbialos solo si hace falta.</p>' +
        '<div class="row">' +
            '<div class="col-sm-2"><label for="eTrato">Trato</label><input type="text" id="eTrato" class="form-control input-sm" maxlength="40" placeholder="Doctora"></div>' +
            '<div class="col-sm-5"><label for="eDestinatario">Destinatario</label><input type="text" id="eDestinatario" class="form-control input-sm" maxlength="120"></div>' +
            '<div class="col-sm-5"><label for="eCargo">Cargo del destinatario</label><input type="text" id="eCargo" class="form-control input-sm" maxlength="150"></div>' +
            '<div class="col-sm-12"><label for="ePresentacion">Presentación (va después de «Cordial saludo:»)</label><textarea id="ePresentacion" class="form-control" rows="2" maxlength="1500"></textarea></div>' +
            '<div class="col-sm-6"><label for="eFirma">Firma (nombre)</label><input type="text" id="eFirma" class="form-control input-sm" maxlength="120"></div>' +
            '<div class="col-sm-6"><label for="eFirmaCargo">Cargo de quien firma</label><input type="text" id="eFirmaCargo" class="form-control input-sm" maxlength="150"></div>' +
        '</div></div>' +

        '<div class="ger-seccion"><div class="ger-sec-cab"><h3>' + (++n) + '. ' + rdEsc(s.resultados) + '</h3>' + botonTraer('resultados') + '</div>' +
        '<div class="ger-subtitulo">' + rdEsc(repG.tituloPromesa) + '</div>' +
        '<textarea id="gResultados" class="form-control" rows="3" maxlength="4000"></textarea>' +
        '<p class="rd-ayuda" style="margin-top:4px;">La cifra grande del PDF sale de este texto («cumplimiento del 98,5 %, con 18 de 19 peajes…»): si la corriges aquí, el PDF muestra la corregida.</p></div>' +

        '<div class="ger-seccion"><div class="ger-sec-cab"><h3>' + (++n) + '. ' + rdEsc(s.prioritarias) + '</h3>' + botonTraer('prioritarias') + '</div>' +
        '<p class="rd-ayuda">Los temas con el cliente que se movieron y los oficios en gestión. Cada uno sale como «PROYECTO | Asunto».</p>' +
        '<div class="ger-lista" id="gPrioritarias"></div>' +
        '<button type="button" class="btn btn-default btn-sm ger-agregar" data-destino="#gPrioritarias" data-con-proyecto="1"><i class="fa fa-plus"></i> Agregar gestión</button></div>' +

        '<div class="ger-seccion"><div class="ger-sec-cab"><h3>' + (++n) + '. ' + rdEsc(s.proyectos) + '</h3></div>' +
        '<p class="rd-ayuda">Por proyecto: los temas internos con Regency y las novedades reportadas. Los proyectos sin apartados no salen en el PDF.</p>' +
        repG.proyectos.map(function (p) {
            return '<div class="ger-proyecto"><h4><span>' + rdEsc(p.nombre) + '</span>' + botonTraer('proyectos', p.id) + '</h4>' +
                   '<div class="ger-lista" id="gProyecto' + parseInt(p.id, 10) + '"></div>' +
                   '<button type="button" class="btn btn-default btn-sm ger-agregar" data-destino="#gProyecto' + parseInt(p.id, 10) + '"><i class="fa fa-plus"></i> Agregar apartado</button></div>';
        }).join('') + '</div>' +

        '<div class="ger-seccion"><div class="ger-sec-cab"><h3>' + (++n) + '. ' + rdEsc(s.transversal) + '</h3>' + botonTraer('transversal') + '</div>' +
        '<p class="rd-ayuda">Los temas de personal y las vacantes en proceso.</p>' +
        '<div class="ger-lista" id="gTransversal"></div>' +
        '<button type="button" class="btn btn-default btn-sm ger-agregar" data-destino="#gTransversal" data-con-proyecto="1"><i class="fa fa-plus"></i> Agregar apartado</button></div>' +

        '<div class="ger-seccion"><div class="ger-sec-cab"><h3>' + (++n) + '. ' + rdEsc(s.compromisos) + '</h3>' + botonTraer('compromisos') + '</div>' +
        '<p class="rd-ayuda">Lo programado en el cronograma para los próximos días y las fechas comprometidas por Gestión Humana.</p>' +
        '<table class="table table-bordered rd-tabla rd-tabla-compacta ger-compromisos" id="gCompromisos"><thead><tr><th>Gestión</th><th class="ger-fecha-col">Fecha objetivo</th><th></th></tr></thead><tbody></tbody></table>' +
        '<button type="button" class="btn btn-default btn-sm ger-agregar" data-destino="#gCompromisos"><i class="fa fa-plus"></i> Agregar compromiso</button></div>';
    $('#gInforme').html(html);

    var e = r.encabezado || {};
    $('#eTrato').val(e.trato || ''); $('#eDestinatario').val(e.destinatario || ''); $('#eCargo').val(e.cargo || '');
    $('#ePresentacion').val(e.presentacion || ''); $('#eFirma').val(e.firma || ''); $('#eFirmaCargo').val(e.firmaCargo || '');
    $('#gResultados').val(r.resultados || '');
    pintarApartados($('#gPrioritarias'), r.prioritarias, true);
    repG.proyectos.forEach(function (p) { pintarApartados($('#gProyecto' + p.id), ((r.proyectos || {})[p.id] || {}).apartados, false); });
    pintarApartados($('#gTransversal'), r.transversal, true);
    pintarCompromisos(r.compromisos);
    $('#gInforme textarea').each(function () { ajustarAlto(this); });
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
    else if (seccion === 'compromisos') { pintarCompromisos(s.compromisos); }
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
        var c = { gestion: $.trim($(this).find('.ger-c-gestion').val()), fecha: $.trim($(this).find('.ger-c-fecha').val()) };
        if (c.gestion || c.fecha) { compromisos.push(c); }
    });
    return {
        encabezado: { trato: $.trim($('#eTrato').val()), destinatario: $.trim($('#eDestinatario').val()), cargo: $.trim($('#eCargo').val()),
                      presentacion: $.trim($('#ePresentacion').val()), firma: $.trim($('#eFirma').val()), firmaCargo: $.trim($('#eFirmaCargo').val()) },
        resultados: $.trim($('#gResultados').val()),
        prioritarias: leerApartados($('#gPrioritarias'), true),
        proyectos: proyectos,
        transversal: leerApartados($('#gTransversal'), true),
        compromisos: compromisos
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
    lineas.push(repG.fechaInforme, '', '*' + repG.titulo + '*', '');
    if (e.trato) { lineas.push(e.trato); }
    if (e.destinatario) { lineas.push('*' + e.destinatario + '*'); }
    if (e.cargo) { lineas.push(e.cargo); }
    lineas.push('', 'Cordial saludo:', '');
    if (e.presentacion) { lineas.push(e.presentacion, ''); }
    lineas.push('*' + (++n) + '. ' + s.resultados + '*', '*' + repG.tituloPromesa + '*', r.resultados || 'Sin información.', '');
    lineas.push('*' + (++n) + '. ' + s.prioritarias + '*'); apartados(r.prioritarias);
    lineas.push('*' + (++n) + '. ' + s.proyectos + '*');
    var hay = false;
    repG.proyectos.forEach(function (p) {
        var lista = r.proyectos[p.id].apartados;
        if (!lista.length) { return; }
        hay = true;
        lineas.push('*' + p.nombre.toUpperCase() + '*'); apartados(lista);
    });
    if (!hay) { lineas.push('Sin avances registrados.', ''); }
    lineas.push('*' + (++n) + '. ' + s.transversal + '*'); apartados(r.transversal);
    lineas.push('*' + (++n) + '. ' + s.compromisos + '*');
    if (r.compromisos.length) { r.compromisos.forEach(function (c) { lineas.push('• ' + c.gestion + ' — ' + c.fecha); }); }
    else { lineas.push('Sin compromisos registrados.'); }
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
    var html = '<p><strong>' + rdEsc(repG.fechaInforme) + '</strong></p><h1>' + rdEsc(repG.titulo) + '</h1>' +
               parrafo(e.trato) + '<p><strong>' + rdEsc(e.destinatario) + '</strong></p>' + parrafo(e.cargo) +
               parrafo('Cordial saludo:') + parrafo(e.presentacion) +
               '<h2>' + (++n) + '. ' + rdEsc(s.resultados) + '</h2><h4>' + rdEsc(repG.tituloPromesa) + '</h4>' + parrafo(r.resultados) +
               '<h2>' + (++n) + '. ' + rdEsc(s.prioritarias) + '</h2>' + apartados(r.prioritarias) +
               '<h2>' + (++n) + '. ' + rdEsc(s.proyectos) + '</h2>';
    repG.proyectos.forEach(function (p) {
        var lista = r.proyectos[p.id].apartados;
        if (lista.length) { html += '<h4>' + rdEsc(p.nombre.toUpperCase()) + '</h4>' + apartados(lista); }
    });
    html += '<h2>' + (++n) + '. ' + rdEsc(s.transversal) + '</h2>' + apartados(r.transversal) +
            '<h2>' + (++n) + '. ' + rdEsc(s.compromisos) + '</h2>' +
            (r.compromisos.length ? '<table><thead><tr><th>Gestión</th><th>Fecha objetivo</th></tr></thead><tbody>' +
                r.compromisos.map(function (c) { return '<tr><td>' + rdEsc(c.gestion) + '</td><td>' + rdEsc(c.fecha) + '</td></tr>'; }).join('') + '</tbody></table>'
                                  : parrafo('Sin compromisos registrados.')) +
            '<p style="margin-top:18px;">Atentamente,</p><p style="margin-top:24px;"><strong>' + rdEsc(e.firma) + '</strong></p>' + parrafo(e.firmaCargo);
    $('#gerImpresion').html(html);
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
