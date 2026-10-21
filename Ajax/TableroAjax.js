//Reporte diario - Tablero por proyectos
//Además de mostrar, enlaza: cada cifra lleva al Reporte general de ese día y ese proyecto, en la pestaña
//del tema, y la franja "Ir a" lleva a los módulos que la persona tiene en su menú
var URL_TAB = '../Control/TableroControl.php';
var datosTab = null;       //la última respuesta del servidor
var proyectoAbierto = 0;   //0 = viendo los mosaicos de proyectos

var SIT = { LABORAL: 'Laboral', DESCANSO: 'Descanso', INCAPACIDAD: 'Incapacidad', VACACIONES: 'Vacaciones', PERMISO: 'Permiso' };
var TIPO_ARQ = { CAJA_MENOR: 'Caja menor', RECAMBIO: 'Recambio', CASETA: 'Casetas' };
var TIPO_CENTRO = { PEAJE: 'Peaje', BASCULA: 'Báscula', BASE: 'Base', OFICINA: 'Oficina' };

$(function () {
    //Se puede llegar con ?proyecto=ID
    var idProyectoUrl = parseInt(new URLSearchParams(window.location.search).get('proyecto'), 10);
    if (idProyectoUrl > 0) { proyectoAbierto = idProyectoUrl; }
    cargar('');

    $('#tbFecha').on('change', function () { if (this.value) { cargar(this.value); } });
    $('#btnDiaAnt').on('click', function () { cargar(rdSumarDias(datosTab.fecha, -1)); });
    $('#btnDiaSig').on('click', function () { if (datosTab.fecha < datosTab.hoy) { cargar(rdSumarDias(datosTab.fecha, 1)); } });
    $(document).on('click', '.tb-proyecto', function (evento) {
        if ($(evento.target).closest('a').length) { return; } //un enlace de adentro lleva a su módulo: no abre el proyecto
        proyectoAbierto = $(this).data('id'); pintar(); window.scrollTo(0, 0);
    });
    $(document).on('click', '#volverProyectos', function (evento) { evento.preventDefault(); proyectoAbierto = 0; pintar(); });
    $(document).on('click', '[data-persona]', function () { verPersona($(this).data('persona')); });
    $(document).on('click', '.btn-ver-lista', function (evento) { evento.preventDefault(); rdVerLista($(this).data('id')); });
});

//Trae el resumen del día elegido y pinta el tablero
function cargar(fecha) {
    $.getJSON(URL_TAB + '?op=resumen' + (fecha ? '&fecha=' + encodeURIComponent(fecha) : ''))
        .done(function (respuesta) {
            datosTab = respuesta;
            $('#tbFecha').val(respuesta.fecha).attr('max', respuesta.hoy);
            $('#btnDiaSig').prop('disabled', respuesta.fecha >= respuesta.hoy);
            $('#tbAlcance').text(respuesta.verTodos ? 'Ves todos los proyectos.' : 'Ves los proyectos y peajes a tu cargo.');
            pintar();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el tablero.'); });
}

//Si hay un proyecto abierto, muestra su detalle; si no, la vista de todos los proyectos
function pintar() {
    var proy = null;
    for (var i = 0; i < datosTab.proyectos.length; i++) { if (+datosTab.proyectos[i].ID_PROYECTO === +proyectoAbierto) { proy = datosTab.proyectos[i]; } }
    if (proy) { pintarProyecto(proy); } else { proyectoAbierto = 0; pintarProyectos(); }
}

//Estado del día de una persona: "Laboral · 07:40 - 16:50", "Descanso" o "Sin reporte"
function estadoDia(situacion, ingreso, salida) {
    if (!situacion) { return '<span class="rd-tag rd-tag-pend"><i class="fa fa-clock-o"></i> Sin reporte</span>'; }
    var nombreSituacion = SIT[situacion] || situacion;
    if (situacion === 'LABORAL' && ingreso) { nombreSituacion += ' · ' + rdHora(ingreso) + ' – ' + (salida ? rdHora(salida) : '…'); }
    return '<span class="rd-tag ' + (situacion === 'LABORAL' ? 'rd-tag-ok' : '') + '"><i class="fa fa-check"></i> ' + rdEsc(nombreSituacion) + '</span>';
}

//"1 lista" / "3 listas"
function cantidadTexto(cantidad, singular, plural) { return cantidad + ' ' + (cantidad === 1 ? singular : plural); }

//A dónde lleva una cifra del tablero: al Reporte general de ese día y ese proyecto, en la pestaña del tema.
//ver: hoy, listas, arqueos, cronograma, vehículos, vacantes, comunicaciones o rq (lo entiende ControlDiarioAjax.js)
function irReporte(vista, idProyecto) {
    return 'ControlDiarioVista.php?fecha=' + encodeURIComponent(datosTab.fecha) + '&proyecto=' + parseInt(idProyecto, 10) + '&ver=' + vista;
}

//texto: "vacante|vacantes" (singular|plural). Con href, la cifra es un enlace
function chip(icono, cantidad, texto, clase, href) {
    var formas = texto.split('|'), palabra = (cantidad === 1 || formas.length === 1) ? formas[0] : formas[1];
    var dentro = '<i class="fa ' + icono + '"></i> ' + cantidad + ' ' + rdEsc(palabra);
    var claseChip = 'tb-chip ' + (cantidad > 0 ? (clase || '') : 'tb-chip-cero');
    return href ? '<a class="' + claseChip + ' tb-enlace" href="' + href + '" title="Ver el detalle en el Reporte general">' + dentro + ' <i class="fa fa-angle-right"></i></a>'
                : '<span class="' + claseChip + '">' + dentro + '</span>';
}

//Los módulos a los que se puede ir desde el tablero. Solo salen los que la persona tiene en su menú lateral,
//así no hay que repetir aquí las reglas de permisos
var MODULOS_IR = [['RQVista.php', 'fa-wrench', 'Requisiciones'], ['VacantesVista.php', 'fa-user-plus', 'Vacantes'], ['ComunicacionesVista.php', 'fa-inbox', 'Comunicaciones'],
                  ['ArqueosVista.php', 'fa-money', 'Arqueos'], ['ListasVista.php', 'fa-check-square-o', 'Listas de chequeo'], ['CronogramaVista.php', 'fa-calendar', 'Cronograma y vehículo'],
                  ['AusentismoVista.php', 'fa-user-times', 'Ausentismo'], ['novedadesVista.php', 'fa-comment', 'Novedades'], ['InformeMensualVista.php', 'fa-file-pdf-o', 'Informe de gestión']];

//Botones para ir a los reportes y módulos de un proyecto
function franjaIrA(idProyecto) {
    var html = '<a class="btn btn-default btn-sm" href="' + irReporte('hoy', idProyecto) + '"><i class="fa fa-list-alt"></i> Reporte general de este proyecto</a>';
    MODULOS_IR.forEach(function (modulo) {
        if ($('.side-menu a[href="' + modulo[0] + '"]').length) { html += '<a class="btn btn-default btn-sm" href="' + modulo[0] + '"><i class="fa ' + modulo[1] + '"></i> ' + modulo[2] + '</a>'; }
    });
    return '<span class="tb-ir-rotulo">Ir a:</span>' + html;
}

//---------------------------------------------------------------------------
// Nivel 1: proyectos
//---------------------------------------------------------------------------
function pintarProyectos() {
    $('#tituloTablero').text('Tablero del reporte diario');
    $('#migasTablero').html('<a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> Tablero');
    $('#nivelCentros').hide();
    var html = '';
    for (var i = 0; i < datosTab.proyectos.length; i++) {
        var proyecto = datosTab.proyectos[i], resumen = proyecto.RESUMEN;
        //El reporte del coordinador del día: Hoy en qué estás, listas y revisión del cronograma
        var partes = [!!proyecto.COORD_SITUACION, +proyecto.COORD_LISTAS > 0, +proyecto.COORD_REVISO > 0];
        var hechas = partes.filter(function (hecha) { return hecha; }).length;
        var porcentaje = proyecto.COORDINADOR ? Math.round(100 * hechas / 3) : 0;
        var idProyecto = parseInt(proyecto.ID_PROYECTO, 10);
        //Cada parte del reporte del coordinador lleva a su pestaña del Reporte general
        var marca = function (ok, texto, vista) { return '<a class="tb-parte tb-enlace ' + (ok ? 'tb-parte-ok' : '') + '" href="' + irReporte(vista, idProyecto) + '"><i class="fa ' + (ok ? 'fa-check' : 'fa-clock-o') + '"></i> ' + texto + '</a>'; };
        html += '<div class="tb-proyecto" data-id="' + parseInt(proyecto.ID_PROYECTO, 10) + '" role="button" tabindex="0">' +
             '<div class="tb-proy-nombre">' + rdEsc(proyecto.NOM_PROYECTO) + '</div>' +
             '<div class="tb-proy-coord">' + (proyecto.COORDINADOR ? '<i class="fa fa-user"></i> ' + rdEsc(proyecto.COORDINADOR) + ' ' + estadoDia(proyecto.COORD_SITUACION, proyecto.COORD_INGRESO, null)
                                                              : '<span class="rd-ayuda" style="display:inline">Sin coordinador asignado</span>') + '</div>' +
             '<div class="tb-avance"><div class="tb-avance-txt">' + (proyecto.COORDINADOR ? 'Reporte del coordinador: <strong>' + hechas + ' de 3</strong>' : 'Sin coordinador asignado') + '</div>' +
             '<div class="tb-barra"><div style="width:' + porcentaje + '%"></div></div>' +
             (proyecto.COORDINADOR ? '<div class="tb-partes">' + marca(partes[0], 'Hoy en qué estás', 'hoy') + marca(partes[1], cantidadTexto(+proyecto.COORD_LISTAS, 'lista', 'listas'), 'listas') +
                              marca(partes[2], 'Cronograma revisado', 'cronograma') + '</div>' : '') + '</div>' +
             '<div class="tb-chips">' + chip('fa-user-plus', resumen.vacantes, 'vacante abierta|vacantes abiertas', 'tb-chip-pend', irReporte('vacantes', idProyecto)) +
             chip('fa-inbox', +proyecto.COMUNICACIONES, 'comunicación abierta|comunicaciones abiertas', 'tb-chip-pend', irReporte('comunicaciones', idProyecto)) +
             chip('fa-wrench', resumen.rq, 'RQ por aprobar', 'tb-chip-pend', irReporte('rq', idProyecto)) +
             chip('fa-money', resumen.arqueos, 'arqueo|arqueos', resumen.arqueosDif ? 'tb-chip-mal' : 'tb-chip-ok', irReporte('arqueos', idProyecto)) + '</div></div>';
    }
    $('#nivelProyectos').html(html || '<p class="rd-vacio">No tienes proyectos a tu cargo. En Proyectos y en Centros de operación se asigna quién coordina y quién es jefe.</p>').show();
}

//---------------------------------------------------------------------------
// Nivel 2: centros de un proyecto
//---------------------------------------------------------------------------
function pintarProyecto(proyecto) {
    $('#tituloTablero').text(proyecto.NOM_PROYECTO);
    $('#migasTablero').html('<a href="ReporteDiarioVista.php"><i class="fa fa-calendar-check-o"></i> Reporte diario</a> <span>›</span> ' +
                            '<a href="#" id="volverProyectos">Tablero</a> <span>›</span> ' + rdEsc(proyecto.NOM_PROYECTO));
    $('#nivelProyectos').hide();
    var idProyecto = parseInt(proyecto.ID_PROYECTO, 10);
    $('#tbIrA').html(franjaIrA(idProyecto)); //los enlaces a los demás módulos

    //Solo se puede abrir el reporte de quien está por debajo en la jerarquía (o el propio)
    $('#tbCoordinador').html(proyecto.COORDINADOR
        ? '<div class="tb-persona' + (proyecto.COORD_PUEDE_VER ? '' : ' tb-sin-acceso') + '"' +
          (proyecto.COORD_PUEDE_VER ? ' data-persona="' + parseInt(proyecto.ID_COLABORADOR_COORDINADOR, 10) + '" role="button" tabindex="0"' : '') + '>' +
          '<span class="tb-rol">Coordinador del proyecto</span><strong>' + rdEsc(proyecto.COORDINADOR) + '</strong> ' +
          estadoDia(proyecto.COORD_SITUACION, proyecto.COORD_INGRESO, null) +
          (proyecto.COORD_PUEDE_VER ? '<span class="tb-ver">Ver reporte <i class="fa fa-chevron-right"></i></span>' : '') + '</div>'
        : '<p class="rd-ayuda">Este proyecto no tiene coordinador asignado. Se asigna en Configuración → Proyectos.</p>');

    var html = '';
    for (var i = 0; i < proyecto.CENTROS.length; i++) {
        var centro = proyecto.CENTROS[i];
        html += '<div class="tb-centro">' +
             '<div class="tb-centro-cab"><strong>' + rdEsc(centro.NOM_CENTRO_OP) + '</strong> <span class="tb-tipo">' + rdEsc(TIPO_CENTRO[centro.TIPO_CENTRO] || centro.TIPO_CENTRO) + '</span></div>' +
             (centro.ID_COLABORADOR_JEFE
                ? '<div class="tb-persona tb-persona-sm' + (centro.PUEDE_VER ? '' : ' tb-sin-acceso') + '"' +
                  (centro.PUEDE_VER ? ' data-persona="' + parseInt(centro.ID_COLABORADOR_JEFE, 10) + '" role="button" tabindex="0"' : '') + '>' +
                  '<span class="tb-rol">Jefe de peaje</span>' + rdEsc(centro.JEFE) +
                  (+centro.RQ_PENDIENTES > 0 ? '<br><span class="rd-tag rd-tag-pend">' + cantidadTexto(+centro.RQ_PENDIENTES, 'RQ por aprobar', 'RQ por aprobar') + '</span>' : '') + '</div>'
                : '<div class="rd-ayuda" style="margin:6px 0 8px;">Sin jefe asignado</div>') +
             '<div class="tb-chips">' + chip('fa-check-square-o', +centro.LISTAS, 'lista|listas', 'tb-chip-ok', irReporte('listas', idProyecto)) +
             chip('fa-money', +centro.ARQUEOS, 'arqueo|arqueos', +centro.ARQUEOS_DIF ? 'tb-chip-mal' : 'tb-chip-ok', irReporte('arqueos', idProyecto)) +
             chip('fa-user-plus', +centro.VACANTES, 'vacante|vacantes', 'tb-chip-pend', irReporte('vacantes', idProyecto)) +
             chip('fa-wrench', +centro.RQ_PENDIENTES, 'RQ por aprobar', 'tb-chip-pend', irReporte('rq', idProyecto)) + '</div></div>';
    }
    $('#tbCentros').html(html || '<p class="rd-vacio">Este proyecto no tiene centros activos que puedas ver.</p>');
    $('#nivelCentros').show();
}

//---------------------------------------------------------------------------
// Nivel 3: el reporte de una persona ese día
//---------------------------------------------------------------------------
function verPersona(id) {
    $('#modalPersonaCuerpo').html('<p class="rd-vacio">Cargando...</p>');
    $('#modalPersona').modal('show');
    $.getJSON(URL_TAB + '?op=persona&id=' + encodeURIComponent(id) + '&fecha=' + encodeURIComponent(datosTab.fecha))
        .done(function (respuesta) {
            $('#modalPersonaTitulo').text(respuesta.persona.NOM_COLABORADOR + ' · ' + rdFecha(respuesta.fecha));
            var html = respuesta.persona.ROL ? '<p class="rd-ayuda" style="margin-top:0">' + rdEsc(respuesta.persona.ROL) + '</p>' : '';

            //Hoy en qué estás: solo el coordinador lo llena
            var registroHoy = respuesta.hoy;
            if (respuesta.esCoordinador) { html += '<h5 class="rd-subtitulo" style="margin-top:0">Hoy en qué estás</h5>'; }
            if (!respuesta.esCoordinador) { /* el jefe de peaje no llena Hoy en qué estás */ }
            else if (!registroHoy) { html += '<p><span class="rd-tag rd-tag-pend">Sin reporte ese día</span></p>'; }
            else {
                html += '<p>' + estadoDia(registroHoy.SITUACION, registroHoy.HORA_INGRESO, registroHoy.HORA_SALIDA) + '</p>';
                var donde = (registroHoy.LUGARES || []).concat(registroHoy.LUGAR_OTRO ? [registroHoy.LUGAR_OTRO] : []);
                if (donde.length) { html += '<p><strong>Dónde:</strong> ' + rdEsc(donde.join(' · ')) + '</p>'; }
                if (registroHoy.ACTIVIDAD) { html += '<p><strong>Qué hizo:</strong><br>' + rdParrafo(registroHoy.ACTIVIDAD) + '</p>'; } //un solo texto para todo el día
                if (registroHoy.OBSERVACION) { html += '<p><strong>Observación:</strong> ' + rdEsc(registroHoy.OBSERVACION) + '</p>'; }
            }

            //Cronograma: del coordinador
            if (respuesta.esCoordinador) { html += '<h5 class="rd-subtitulo">Cronograma del día</h5>'; }
            if (respuesta.esCoordinador && !respuesta.cronograma.length) { html += '<p class="rd-ayuda">Nada planeado.</p>'; }
            for (var numVisita = 0; respuesta.esCoordinador && numVisita < respuesta.cronograma.length; numVisita++) { //el cronograma es del coordinador
                var visita = respuesta.cronograma[numVisita];
                html += '<span class="crono-item crono-' + rdEsc(visita.ESTADO_REAL) + '" style="display:inline-block;margin-right:6px;">' +
                     rdEsc(visita.TIPO.charAt(0) + visita.TIPO.slice(1).toLowerCase() + (visita.NOM_CENTRO_OP ? ' ' + visita.NOM_CENTRO_OP : '') + (visita.DESCRIPCION ? ': ' + visita.DESCRIPCION : '')) + '</span>';
            }

            //Listas de chequeo: las llena el coordinador
            if (respuesta.esCoordinador) { html += '<h5 class="rd-subtitulo">Listas de chequeo diligenciadas</h5>'; }
            if (!respuesta.esCoordinador) { /* el jefe de peaje no llena listas */ }
            else if (!respuesta.listas.length) { html += '<p class="rd-ayuda">Ninguna ese día.</p>'; }
            else {
                html += '<ul class="tb-lista">';
                for (var numLista = 0; numLista < respuesta.listas.length; numLista++) {
                    html += '<li>' + rdEsc(respuesta.listas[numLista].LISTA) + ' · ' + rdEsc(respuesta.listas[numLista].NOM_CENTRO_OP) + ' <small>' + rdHora(String(respuesta.listas[numLista].FECHA).substring(11)) + '</small> ' +
                         '<a href="#" class="btn-ver-lista" data-id="' + parseInt(respuesta.listas[numLista].ID_LISTA_CHEQUEO, 10) + '"><i class="fa fa-eye"></i> Ver respuestas</a></li>';
                }
                html += '</ul>';
            }

            //RQ que pidió: lo principal de un jefe de peaje
            html += '<h5 class="rd-subtitulo">RQ</h5>';
            if (!respuesta.rq.length) { html += '<p class="rd-ayuda">Ninguna esperando aprobación ni pedida ese día.</p>'; }
            else {
                html += '<ul class="tb-lista">';
                for (var numRq = 0; numRq < respuesta.rq.length; numRq++) {
                    var rq = respuesta.rq[numRq];
                    html += '<li><span class="cd-rq ' + (rq.TIPO_RQ === 'U' ? 'cd-rq-u' : '') + '">' + (rq.TIPO_RQ === 'U' ? 'RQ U-' : 'RQ-') + rdEsc(rq.NUMERO_RQ) + '</span> ' +
                         rdEsc(rq.NOM_CENTRO_OP) + ' · ' + rdEsc(rq.ITEMS || '') + ' <small>' + rdEsc(rq.NOM_RQ_ESTADO) + '</small> ' +
                         '<a href="#" class="btn-ver-rq" data-id="' + parseInt(rq.ID_RQ, 10) + '"><i class="fa fa-eye"></i> Ver detalle y fotos</a></li>';
                }
                html += '</ul>';
            }

            //Arqueos: del coordinador
            if (respuesta.esCoordinador) { html += '<h5 class="rd-subtitulo">Arqueos</h5>'; }
            if (!respuesta.esCoordinador) { /* un jefe de peaje no hace arqueos */ }
            else if (!respuesta.arqueos.length) { html += '<p class="rd-ayuda">Ninguno ese día.</p>'; }
            else {
                html += '<ul class="tb-lista">';
                for (var numArqueo = 0; numArqueo < respuesta.arqueos.length; numArqueo++) {
                    var arqueo = respuesta.arqueos[numArqueo], diferencia = parseFloat(arqueo.DIFERENCIA);
                    html += '<li>' + rdEsc(TIPO_ARQ[arqueo.TIPO] || arqueo.TIPO) + ' · ' + rdEsc(arqueo.NOM_CENTRO_OP) + ' <small>' + rdHora(arqueo.HORA) + '</small> ' +
                         (diferencia === 0 ? '<span class="rd-tag rd-tag-ok">Cuadra</span>' : '<span class="rd-tag rd-tag-mal">' + (diferencia < 0 ? 'Faltante ' : 'Sobrante ') + rdPesos(Math.abs(diferencia)) + '</span>') +
                         ' <a href="ArqueoPdfVista.php?ver=1&id=' + parseInt(arqueo.ID_ARQUEO, 10) + '" target="_blank" rel="noopener"><i class="fa fa-file-pdf-o"></i> PDF</a></li>';
                }
                html += '</ul>';
            }
            $('#modalPersonaCuerpo').html(html);
        })
        .fail(function (xhr) { $('#modalPersona').modal('hide'); alert(rdError(xhr, 'No se pudo abrir el reporte.')); });
}
