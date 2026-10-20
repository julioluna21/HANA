//Reporte diario - Arqueos
//Formulario con cálculo en vivo (el servidor vuelve a calcular al guardar),
//listado del mes, detalle con impresión y configuración de fondos

var URL_ARQ = '../Control/ArqueoControl.php';
var cfgArq = null;

$(function () {
    rdAlertaDia('arqueos', '#alertaDia');
    rdVigilarCambios('#formArq');
    //Botones del día: Hoy y los días que el administrador habilitó
    $(document).on('click', '#arqRapido [data-fecha]', function () {
        $('#arqFecha').val($(this).attr('data-fecha')).trigger('change');
    });
    $(document).on('change', '#arqFecha', marcarDiaArq);

    $.getJSON(URL_ARQ + '?op=config')
        .done(function (config) {
            cfgArq = config;
            $('#btnNuevoArq').toggle(!!config.puedeArquear);
            $('#btnFondos').toggle(!!config.puedeFondos);
            $('#btnCampos').toggle(!!config.puedeCampos);

            //Filtros
            var htmlCentros = '<option value="0">Todos</option>';
            for (var i = 0; i < config.centros.length; i++) {
                htmlCentros += '<option value="' + parseInt(config.centros[i].ID_CENTRO_OP, 10) + '">' + rdEsc(config.centros[i].NOM_CENTRO_OP) + '</option>';
            }
            $('#fCentroArq').html(htmlCentros);
            var htmlTipos = '<option value="">Todos</option>', htmlTiposForm = '';
            $.each(config.tipos, function (codigo, nombre) {
                htmlTipos += '<option value="' + rdEsc(codigo) + '">' + rdEsc(nombre) + '</option>';
                htmlTiposForm += '<label class="rd-opcion"><input type="radio" name="tipo" value="' + rdEsc(codigo) + '"> <span>' + rdEsc(nombre) + '</span></label>';
            });
            $('#fTipoArq').html(htmlTipos);
            $('#arqTipos').html(htmlTiposForm);
            rdPeriodo('#fMesArq', '#fAnioArq', config.hoy);
            listarArq();

            if (!config.centros.length && config.puedeArquear) {
                $('#filasArq').html('<tr><td colspan="8" class="rd-vacio">Tus proyectos no tienen centros activos.</td></tr>');
            }
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir la pantalla de arqueos.'); });

    $('#fCentroArq, #fTipoArq, #fMesArq, #fAnioArq').on('change', listarArq);
    $('#btnNuevoArq').on('click', abrirFormulario);
    $('#btnCancelarArq').on('click', function () {
        if (rdHayCambios && !window.confirm('El arqueo tiene datos sin guardar. ¿Salir y perderlos?')) { return; }
        rdCambiosGuardados();
        mostrarPanel('#panelListaArq');
    });
    $('#btnFondos').on('click', abrirFondos);
    $('#btnVolverFondos').on('click', function () { mostrarPanel('#panelListaArq'); listarArq(); });

    $(document).on('change', 'input[name="tipo"]', function () { pintarTipo(); });
    //En el arqueo de casetas, cada peaje tiene sus campos: al cambiar de peaje se vuelven a pintar
    $('#arqCentro').on('change', function () { if (tipoArq() === 'CASETA') { pintarCampos(); } else { calcular(); } });
    $('#btnCampos').on('click', abrirCampos);
    $('#btnVolverCampos').on('click', function () { mostrarPanel('#panelListaArq'); listarArq(); });
    $('#cmpCentro').on('change', cargarCampos);
    $('#btnAgregarCampo').on('click', agregarCampo);
    $(document).on('change', '.cmp-editar', guardarCampoFila);
    $(document).on('input change', '#formArq .rd-pesos, #formArq select', calcular);
    $('#btnAgregarDoc').on('click', function () { agregarDoc(); });
    $(document).on('click', '.rd-quitar-doc', function () { $(this).closest('tr').remove(); calcular(); });
    $('#formArq').on('submit', guardarArq);

    $(document).on('click', '.btn-ver-arq', function () { verArq($(this).data('id')); });
    $(document).on('click', '#btnAnularArq', anularArq);
    $(document).on('click', '.btn-fondo', guardarFondo);
});

//Muestra solo uno de los paneles de la pantalla (lista, formulario, fondos o campos)
function mostrarPanel(id) {
    $('#panelListaArq, #panelFormArq, #panelFondos, #panelCampos').hide();
    $(id).show();
    window.scrollTo(0, 0);
}

//---------------------------------------------------------------------------
// Listado
//---------------------------------------------------------------------------
function listarArq() {
    var filtros = '&centro=' + encodeURIComponent($('#fCentroArq').val() || 0) + '&tipo=' + encodeURIComponent($('#fTipoArq').val() || '') +
            '&mes=' + encodeURIComponent($('#fMesArq').val()) + '&anio=' + encodeURIComponent($('#fAnioArq').val());
    $.getJSON(URL_ARQ + '?op=listar' + filtros)
        .done(function (filas) {
            var html = '', total = 0, cuadran = 0, diferenciaArqueo = 0;
            for (var i = 0; i < filas.length; i++) {
                var arqueo = filas[i], vigente = parseInt(arqueo.ESTADO, 10) === 1, diferencia = parseFloat(arqueo.DIFERENCIA);
                if (vigente) { total++; if (diferencia === 0) { cuadran++; } else { diferenciaArqueo++; } }
                html += '<tr' + (vigente ? '' : ' class="rd-anulado"') + '>' +
                     '<td>' + rdFecha(arqueo.FECHA) + ' <small>' + rdHora(arqueo.HORA) + '</small></td>' +
                     '<td>' + rdEsc(cfgArq.tipos[arqueo.TIPO] || arqueo.TIPO) + (arqueo.CASETA ? '<br><small>Caseta ' + rdEsc(arqueo.CASETA) + '</small>' : '') + '</td>' +
                     '<td>' + rdEsc(arqueo.NOM_CENTRO_OP) + '</td>' +
                     '<td>' + rdEsc(arqueo.RESPONSABLE) + '</td>' +
                     '<td class="rd-num-col">' + rdPesos(arqueo.TOTAL_ARQUEO) + '</td>' +
                     '<td class="rd-num-col">' + rdPesos(arqueo.FONDO_AUTORIZADO) + '</td>' +
                     '<td>' + (vigente ? etiquetaDif(diferencia) : '<span class="rd-tag">Anulado</span>') + '</td>' +
                     '<td class="rd-nowrap"><button type="button" class="btn btn-success btn-xs btn-ver-arq" data-id="' + parseInt(arqueo.ID_ARQUEO, 10) + '">' +
                     '<i class="fa fa-eye"></i> Ver</button> ' +
                     '<a class="btn btn-default btn-xs" href="ArqueoPdfVista.php?id=' + parseInt(arqueo.ID_ARQUEO, 10) + '" title="Descargar PDF">' +
                     '<i class="fa fa-file-pdf-o"></i> PDF</a></td></tr>';
            }
            $('#filasArq').html(html || '<tr><td colspan="8" class="rd-vacio">No hay arqueos en este periodo.</td></tr>');
            $('#cntArq').text(total); $('#cntCuadran').text(cuadran); $('#cntDif').text(diferenciaArqueo);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron cargar los arqueos.'); });
}

//Cuadra (verde) o la diferencia: faltante si falta dinero, sobrante si sobra
function etiquetaDif(diferencia) {
    diferencia = parseFloat(diferencia) || 0;
    if (diferencia === 0) { return '<span class="rd-tag rd-tag-ok"><i class="fa fa-check"></i> Cuadra</span>'; }
    return '<span class="rd-tag rd-tag-mal">' + (diferencia < 0 ? 'Faltante ' : 'Sobrante ') + rdPesos(Math.abs(diferencia)) + '</span>';
}

//---------------------------------------------------------------------------
// Formulario
//---------------------------------------------------------------------------
function abrirFormulario() {
    $('#formArq')[0].reset();
    $('#arqFecha').val(cfgArq.hoy);
    rdLimitarFecha('#arqFecha', cfgArq.hoy, cfgArq.habilitados);            //solo hoy o un día habilitado
    rdBotonesDia('#arqRapido', cfgArq.hoy, cfgArq.habilitados, 'btn-xs');
    var ahora = new Date();
    $('#arqHora').val(rdDos(ahora.getHours()) + ':' + rdDos(ahora.getMinutes()));
    $('input[name="tipo"]').first().prop('checked', true);
    pintarTipo();
    marcarDiaArq();
    rdCambiosGuardados();
    mostrarPanel('#panelFormArq');
}

//Marca el botón del día elegido en la fecha del arqueo
function marcarDiaArq() {
    rdMarcarDia('#arqRapido', $('#arqFecha').val());
}

//Según el tipo: los centros que tienen ese fondo, las líneas de efectivo y los documentos
function pintarTipo() {
    var tipo = $('input[name="tipo"]:checked').val();
    if (!tipo) { return; }

    var caseta = tipo === 'CASETA';
    var html = '', cantidadCentros = 0;
    for (var i = 0; i < cfgArq.centros.length; i++) {
        var centro = cfgArq.centros[i], fondo = centro.FONDOS[tipo];
        //El arqueo de casetas no usa fondo: salen todos los peajes
        if (caseta) { fondo = ''; }
        else if (fondo === undefined) { continue; }
        cantidadCentros++;
        html += '<option value="' + parseInt(centro.ID_CENTRO_OP, 10) + '" data-fondo="' + parseFloat(fondo) + '">' +
             rdEsc(centro.NOM_CENTRO_OP) + ' · ' + rdEsc(centro.NOM_PROYECTO) + '</option>';
    }
    $('#arqCentro').html(cantidadCentros ? html : '<option value="">Ningún centro tuyo tiene este fondo autorizado</option>');

    //Efectivo: un campo por concepto. El de billetes y monedas (EFECTIVO) va sin observación propia:
    //se guarda solo como total del arqueo; lo que haya que explicar va en la observación general
    var htmlEfectivo = '';
    $.each(cfgArq.efectivo[tipo], function (codigo, nombre) {
        var soloTotal = codigo === 'EFECTIVO';
        htmlEfectivo += '<div class="row rd-linea-ef"><div class="form-group col-sm-5 col-xs-12"><label>' + rdEsc(nombre) + ':</label>' +
              '<input type="text" inputmode="numeric" class="form-control rd-pesos" name="efectivo[' + rdEsc(codigo) + ']" placeholder="$ 0"></div>' +
              (soloTotal ? '' : '<div class="form-group col-sm-7 col-xs-12"><label>Observación:</label>' +
              '<input type="text" class="form-control" name="efectivoObs[' + rdEsc(codigo) + ']" maxlength="300"></div>') + '</div>';
    });
    $('#arqEfectivo').html(htmlEfectivo);

    //Documentos: solo en caja menor
    $('#arqDocs').empty();
    var docs = tipo === 'CAJA_MENOR';
    $('#arqBloqueDocs').toggle(docs);
    if (docs) { agregarDoc(); }
    //El cargo del responsable aparece en el formato de recambio
    $('#arqCargo').closest('.form-group').toggle(tipo === 'RECAMBIO');
    //Casetas: la recolectora, la caseta y los campos del peaje, en vez del efectivo del fondo
    $('#arqBloqueCaseta').toggle(caseta);
    $('#arqBloqueEfectivo').toggle(!caseta);
    $('#arqResponsableTxt').text(caseta ? 'Recolectora' : 'Responsable del dinero');
    $('#arqResponsable').attr('placeholder', caseta ? 'Nombre de la recolectora' : 'Nombre completo');
    $('#arqObs').attr('placeholder', caseta ? 'Obligatoria si hay faltante o sobrante: explica la novedad.' : 'Obligatoria si el arqueo no cuadra con el fondo autorizado.');
    if (caseta) { $('#arqEfectivo').empty(); pintarCampos(); return; }
    $('#arqCampos').empty();
    calcular();
}

//El tipo de arqueo elegido: caja menor o recambio
function tipoArq() { return $('input[name="tipo"]:checked').val(); }

//Casetas: un renglón por cada campo configurado para el peaje elegido
var camposCaseta = [];
//Trae los campos de documentos del peaje elegido y arma un renglón por cada uno
function pintarCampos() {
    var centro = $('#arqCentro').val();
    if (!centro) { $('#arqCampos').html('<p class="rd-vacio">Elige el peaje.</p>'); return; }
    $.getJSON(URL_ARQ + '?op=campos&centro=' + encodeURIComponent(centro))
        .done(function (campos) {
            camposCaseta = campos;
            var html = '';
            campos.forEach(function (campo) {
                var nota = { ESPERADO: 'Lo que registra el sistema de la caseta', SUMA: 'Suma al total recaudo', RESTA: 'Resta del total recaudo', INFO: 'No entra en la cuenta' }[campo.ROL];
                html += '<div class="row rd-linea-ef arq-campo arq-rol-' + rdEsc(campo.ROL) + '"><div class="form-group col-sm-6 col-xs-12">' +
                     '<label>' + rdEsc(campo.NOMBRE) + ':</label><div class="rd-ayuda" style="margin:-3px 0 4px;">' + rdEsc(nota) + '</div>' +
                     '<input type="text" inputmode="numeric" class="form-control rd-pesos" name="campo[' + parseInt(campo.ID_CONCEPTO, 10) + ']" data-rol="' + rdEsc(campo.ROL) + '" placeholder="$ 0"></div></div>';
            });
            $('#arqCampos').html(html || '<p class="rd-vacio">Este peaje no tiene campos configurados. Pídelos en "Campos de casetas".</p>');
            calcular();
        })
        .fail(function (xhr) { $('#arqCampos').html('<p class="rd-ayuda">' + rdEsc(rdError(xhr, 'No se pudieron cargar los campos.')) + '</p>'); });
}

//Agrega un renglón vacío a la tabla de documentos del arqueo
function agregarDoc() {
    var opciones = '<option value="">Concepto...</option>';
    $.each(cfgArq.documentos, function (codigo, nombre) { opciones += '<option value="' + rdEsc(codigo) + '">' + rdEsc(nombre) + '</option>'; });
    $('#arqDocs').append('<tr>' +
        '<td><input type="date" name="docFecha[]" class="form-control input-sm" max="' + cfgArq.ultimoEditable + '"></td>' +
        '<td><select name="docConcepto[]" class="form-control input-sm">' + opciones + '</select></td>' +
        '<td><input type="text" inputmode="numeric" name="docValor[]" class="form-control input-sm rd-pesos" placeholder="$ 0"></td>' +
        '<td><input type="text" name="docObs[]" class="form-control input-sm" maxlength="300" placeholder="Ej: faltante por arqueo"></td>' +
        '<td><button type="button" class="btn btn-link rd-quitar-doc" title="Quitar"><i class="fa fa-times"></i></button></td></tr>');
}

//Los mismos cálculos del formato: efectivo + documentos = total; total - fondo = diferencia
function calcular() {
    //Casetas: total recaudo = lo que suma - lo que resta; novedad = total recaudo - recaudo de la caseta
    if (tipoArq() === 'CASETA') {
        var esperado = 0, recaudo = 0, info = [];
        $('#arqCampos .rd-pesos').each(function () {
            var valor = rdLeerPesos(this.value), rol = $(this).data('rol');
            if (rol === 'ESPERADO') { esperado += valor; } else if (rol === 'SUMA') { recaudo += valor; } else if (rol === 'RESTA') { recaudo -= valor; }
            else if (valor) { info.push($(this).closest('.form-group').find('label').text().replace(/:$/, '') + ': ' + rdPesos(valor)); }
        });
        var novedad = recaudo - esperado;
        var htmlTotales = '<div><span>Recaudo caseta</span><strong>' + rdPesos(esperado) + '</strong></div>' +
                 '<div><span>Total recaudo</span><strong>' + rdPesos(recaudo) + '</strong></div>' +
                 '<div class="rd-total-dif"><span>Novedad</span>' + (esperado ? (novedad === 0 ? etiquetaDif(0) : '<span class="rd-tag ' + (novedad < 0 ? 'rd-tag-mal' : 'rd-tag-pend') + '">' +
                 (novedad < 0 ? 'Faltante ' : 'Sobrante ') + rdPesos(Math.abs(novedad)) + '</span>') : '<span class="rd-ayuda">Escribe el recaudo de la caseta</span>') + '</div>';
        info.forEach(function (textoInfo) { htmlTotales += '<div class="arq-info"><span>' + rdEsc(textoInfo) + '</span></div>'; });
        $('#arqFondoTxt').text('');
        $('#arqTotales').html(htmlTotales);
        return;
    }
    var efectivo = 0, documentos = 0;
    $('#arqEfectivo .rd-pesos').each(function () { efectivo += rdLeerPesos(this.value); });
    $('#arqDocs .rd-pesos').each(function () { documentos += rdLeerPesos(this.value); });
    var total = efectivo + documentos;
    var op = $('#arqCentro option:selected'), fondo = parseFloat(op.data('fondo'));
    $('#arqFondoTxt').text(isNaN(fondo) ? '' : 'Fondo autorizado: ' + rdPesos(fondo));

    var html = '<div><span>Efectivo</span><strong>' + rdPesos(efectivo) + '</strong></div>';
    if ($('#arqBloqueDocs').is(':visible')) { html += '<div><span>Documentos</span><strong>' + rdPesos(documentos) + '</strong></div>'; }
    html += '<div><span>Total arqueo</span><strong>' + rdPesos(total) + '</strong></div>';
    if (!isNaN(fondo)) {
        html += '<div><span>Fondo autorizado</span><strong>' + rdPesos(fondo) + '</strong></div>' +
             '<div class="rd-total-dif"><span>Diferencia</span>' + etiquetaDif(total - fondo) + '</div>';
    }
    $('#arqTotales').html(html);
}

//Revisa el formulario y guarda el arqueo
function guardarArq(evento) {
    evento.preventDefault();
    if (!$('#arqCentro').val()) { alert('Elige el centro. Si no aparece, ese centro no tiene fondo autorizado.'); return; }
    if (!$.trim($('#arqResponsable').val())) { alert('Escribe quién es la persona responsable del dinero.'); $('#arqResponsable').focus(); return; }
    if (!$('#arqHora').val()) { alert('Escribe la hora del arqueo.'); return; }

    hanaBoton('#btnGuardarArq', true);
    $.ajax({ url: URL_ARQ + '?op=guardar', type: 'POST', data: $('#formArq').serialize(), dataType: 'json' })
        .done(function (respuesta) {
            rdCambiosGuardados();
            mostrarPanel('#panelListaArq');
            listarArq();
            rdAviso(respuesta.mensaje + ' Se está descargando el PDF para imprimir y firmar.');
            //El PDF del arqueo se descarga solo, con la forma del formato de siempre
            descargarPdf(respuesta.id);
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo registrar el arqueo.')); })
        .always(function () { hanaBoton('#btnGuardarArq', false); });
}

//---------------------------------------------------------------------------
// Detalle
//---------------------------------------------------------------------------
function verArq(id) {
    $('#modalArqCuerpo').html('<p class="rd-vacio">Cargando...</p>');
    $('#modalArq').modal('show');
    $.getJSON(URL_ARQ + '?op=mostrar&id=' + encodeURIComponent(id))
        .done(function (arqueo) {
            var vigente = parseInt(arqueo.ESTADO, 10) === 1;
            $('#modalArqTitulo').text('Arqueo de ' + (cfgArq.tipos[arqueo.TIPO] || arqueo.TIPO).toLowerCase() + ' · ' + arqueo.NOM_CENTRO_OP);
            var html = '<div class="rd-det-cab">' +
                    '<div><span class="rd-det-lbl">Fecha</span>' + rdFecha(arqueo.FECHA) + ' ' + rdHora(arqueo.HORA) + (arqueo.HORA_FIN ? ' – ' + rdHora(arqueo.HORA_FIN) : '') + '</div>' +
                    '<div><span class="rd-det-lbl">Responsable</span>' + rdEsc(arqueo.RESPONSABLE) + (arqueo.CARGO_RESPONSABLE ? '<br><small>' + rdEsc(arqueo.CARGO_RESPONSABLE) + '</small>' : '') + '</div>' +
                    '<div><span class="rd-det-lbl">Arqueó</span>' + rdEsc(arqueo.ARQUEA) + '</div>' +
                    '<div><span class="rd-det-lbl">Resultado</span>' + (vigente ? etiquetaDif(arqueo.DIFERENCIA) : '<span class="rd-tag">Anulado</span>') + '</div></div>';
            if (!vigente) { html += '<p class="rd-aviso-mal">Anulado: ' + rdEsc(arqueo.MOTIVO_ANULACION) + '</p>'; }

            html += '<table class="table rd-tabla"><thead><tr><th>Concepto</th><th>Fecha</th><th>Observación</th><th class="rd-num-col">Valor</th></tr></thead><tbody>';
            for (var i = 0; i < arqueo.LINEAS.length; i++) {
                var linea = arqueo.LINEAS[i];
                //Cada renglón trae el nombre que tenía el concepto el día del arqueo
                var nombre = linea.NOMBRE || linea.CODIGO;
                html += '<tr><td>' + rdEsc(nombre) + '</td><td>' + rdFecha(linea.FECHA) + '</td><td>' + rdEsc(linea.OBSERVACION) + '</td>' +
                     '<td class="rd-num-col">' + rdPesos(linea.VALOR) + '</td></tr>';
            }
            html += '</tbody><tfoot>' +
                 '<tr><th colspan="3">Total arqueo</th><th class="rd-num-col">' + rdPesos(arqueo.TOTAL_ARQUEO) + '</th></tr>' +
                 '<tr><th colspan="3">Fondo autorizado</th><th class="rd-num-col">' + rdPesos(arqueo.FONDO_AUTORIZADO) + '</th></tr>' +
                 '<tr><th colspan="3">Diferencia</th><th class="rd-num-col">' + rdPesos(arqueo.DIFERENCIA) + '</th></tr></tfoot></table>';
            if (arqueo.OBSERVACION) { html += '<p><strong>Observación:</strong> ' + rdEsc(arqueo.OBSERVACION) + '</p>'; }

            html += '<div class="rd-acciones">';
            if (arqueo.PUEDE_ANULAR) {
                html += '<input type="text" id="arqMotivo" class="form-control rd-motivo" maxlength="300" placeholder="Motivo para anular">' +
                     '<button type="button" class="btn btn-default" id="btnAnularArq" data-id="' + parseInt(arqueo.ID_ARQUEO, 10) + '"><i class="fa fa-ban"></i> Anular</button>';
            }
            html += '<a class="btn btn-default" target="_blank" rel="noopener" href="ArqueoPdfVista.php?ver=1&id=' + parseInt(arqueo.ID_ARQUEO, 10) + '">' +
                 '<i class="fa fa-eye"></i> Ver PDF</a>' +
                 '<a class="btn btn-success" href="ArqueoPdfVista.php?id=' + parseInt(arqueo.ID_ARQUEO, 10) + '">' +
                 '<i class="fa fa-file-pdf-o"></i> Descargar PDF</a></div>';
            $('#modalArqCuerpo').html(html);
        })
        .fail(function (xhr) { $('#modalArq').modal('hide'); alert(rdError(xhr, 'No se pudo abrir el arqueo.')); });
}

//Descarga el PDF. El servidor lo manda como archivo adjunto, así que en el
//computador se descarga sin salir de la pantalla; en el iPhone se abre el PDF
function descargarPdf(id) {
    window.location.href = 'ArqueoPdfVista.php?id=' + parseInt(id, 10);
}

//Anula un arqueo; pide el motivo antes
function anularArq() {
    var id = $(this).data('id'), motivo = $.trim($('#arqMotivo').val());
    if (!motivo) { alert('Escribe por qué se anula el arqueo.'); $('#arqMotivo').focus(); return; }
    hanaConfirmar('¿Anular este arqueo? No se borra: queda anulado con el motivo.', function () {
        $.post(URL_ARQ + '?op=anular', { id: id, motivo: motivo }, null, 'json')
            .done(function (respuesta) { $('#modalArq').modal('hide'); rdAviso(respuesta.mensaje); listarArq(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo anular.')); });
    }, { aceptar: 'Sí, anular', cancelar: 'No' });
}

//---------------------------------------------------------------------------
// Fondos autorizados (permiso 5M)
//---------------------------------------------------------------------------
function abrirFondos() {
    mostrarPanel('#panelFondos');
    $('#filasFondos').html('<tr><td colspan="5" class="rd-vacio">Cargando...</td></tr>');
    $.getJSON(URL_ARQ + '?op=fondos')
        .done(function (filas) {
            //Pone los puntos de miles a un valor (sin decimales)
            var html = '', formatear = function (valor) { return valor ? Math.round(valor).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') : ''; };
            for (var i = 0; i < filas.length; i++) {
                var fila = filas[i], id = parseInt(fila.ID_CENTRO_OP, 10);
                html += '<tr><td>' + rdEsc(fila.NOM_PROYECTO) + '</td><td>' + rdEsc(fila.NOM_CENTRO_OP) + '</td>' +
                     '<td><input type="text" inputmode="numeric" class="form-control input-sm rd-pesos" id="fcm' + id + '" value="' + formatear(fila.CAJA_MENOR) + '" placeholder="No maneja"></td>' +
                     '<td><input type="text" inputmode="numeric" class="form-control input-sm rd-pesos" id="fre' + id + '" value="' + formatear(fila.RECAMBIO) + '" placeholder="No maneja"></td>' +
                     '<td><button type="button" class="btn btn-success btn-xs btn-fondo" data-id="' + id + '"><i class="fa fa-save"></i> Guardar</button></td></tr>';
            }
            $('#filasFondos').html(html || '<tr><td colspan="5" class="rd-vacio">No hay centros activos.</td></tr>');
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudieron cargar los fondos.')); });
}

//Guarda el fondo autorizado (caja menor y recambio) de un peaje
function guardarFondo() {
    var id = $(this).data('id'), boton = this;
    hanaBoton(boton, true);
    $.post(URL_ARQ + '?op=guardarFondo', { centro: id, CAJA_MENOR: $('#fcm' + id).val(), RECAMBIO: $('#fre' + id).val() }, null, 'json')
        .done(function (respuesta) {
            rdAviso(respuesta.mensaje);
            //Se recarga la configuración para que el formulario use el fondo nuevo
            $.getJSON(URL_ARQ + '?op=config', function (config) { cfgArq = config; });
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el fondo.')); })
        .always(function () { hanaBoton(boton, false); });
}

//---------------------------------------------------------------------------
// Campos del arqueo de casetas (5M o 24M): agregar, renombrar, ordenar y apagar
//---------------------------------------------------------------------------
function abrirCampos() {
    mostrarPanel('#panelCampos');
    $('#cmpRol').html(Object.keys(cfgArq.roles).map(function (clave) { return '<option value="' + clave + '">' + rdEsc(cfgArq.roles[clave]) + '</option>'; }).join('')).val('SUMA');
    cargarCampos();
}

//Carga la configuración de campos de documentos del peaje elegido
function cargarCampos() {
    var centro = +($('#cmpCentro').val() || 0);
    $.getJSON(URL_ARQ + '?op=camposConfig&centro=' + centro)
        .done(function (respuesta) {
            //El selector de peajes (se llena una vez)
            if (!$('#cmpCentro option').length) {
                $('#cmpCentro').html('<option value="0">Todos los peajes (campos generales)</option>' +
                    respuesta.centros.map(function (centroOpcion) { return '<option value="' + parseInt(centroOpcion.ID_CENTRO_OP, 10) + '">' + rdEsc(centroOpcion.NOM_PROYECTO) + ' · ' + rdEsc(centroOpcion.NOM_CENTRO_OP) + '</option>'; }).join(''));
            }
            var enPeaje = centro > 0, html = '';
            $('#cmpDonde').text(enPeaje ? '(solo para ' + $('#cmpCentro option:selected').text() + ')' : '(para todos los peajes)');
            respuesta.campos.forEach(function (campo) {
                var general = campo.ID_CENTRO_OP === null, id = parseInt(campo.ID_CONCEPTO, 10);
                //Los generales se editan en "todos los peajes" y salen en todos; en un peaje solo se ven
                var editable = general ? !enPeaje : true;
                var roles = Object.keys(cfgArq.roles).map(function (clave) { return '<option value="' + clave + '"' + (clave === campo.ROL ? ' selected' : '') + '>' + rdEsc(cfgArq.roles[clave]) + '</option>'; }).join('');
                html += '<tr data-id="' + id + '"' + (+campo.ESTADO ? '' : ' class="rd-anulado"') + '>' +
                     '<td>' + (editable ? '<input type="number" class="form-control input-sm cmp-editar" data-campo="orden" value="' + parseInt(campo.ORDEN, 10) + '">' : parseInt(campo.ORDEN, 10)) + '</td>' +
                     '<td>' + (editable ? '<input type="text" class="form-control input-sm cmp-editar" data-campo="nombre" maxlength="80" value="' + rdEsc(campo.NOMBRE) + '">' : rdEsc(campo.NOMBRE)) + '</td>' +
                     '<td>' + (editable ? '<select class="form-control input-sm cmp-editar" data-campo="rol">' + roles + '</select>' : rdEsc(cfgArq.roles[campo.ROL])) + '</td>' +
                     '<td>' + (general ? 'Todos los peajes' : 'Solo este peaje') + '</td>' +
                     '<td>' + (general && enPeaje
                        ? (+campo.ESTADO ? 'Activo' : 'Apagado')
                        : '<label class="par-interruptor"><input type="checkbox" class="cmp-editar" data-campo="estado"' + (+campo.ESTADO ? ' checked' : '') + '><span class="par-riel"></span> ' + (+campo.ESTADO ? 'Activo' : 'Apagado') + '</label>') + '</td>' +
                     '<td><small class="text-muted">' + (general && enPeaje ? 'Se cambia en "Todos los peajes"' : '') + '</small></td></tr>';
            });
            $('#filasCampos').html(html || '<tr><td colspan="6" class="rd-vacio">No hay campos.</td></tr>');
            campoFilas = respuesta.campos;
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudieron cargar los campos.')); });
}
var campoFilas = [];

//Guardar un cambio en la fila (nombre, orden, comportamiento o activo)
function guardarCampoFila() {
    var filaTabla = $(this).closest('tr'), id = filaTabla.data('id');
    var campo = campoFilas.filter(function (fila) { return +fila.ID_CONCEPTO === +id; })[0];
    var datos = { id: id, centro: campo.ID_CENTRO_OP || 0,
                  nombre: filaTabla.find('[data-campo="nombre"]').val() || campo.NOMBRE, rol: filaTabla.find('[data-campo="rol"]').val() || campo.ROL,
                  orden: filaTabla.find('[data-campo="orden"]').val() || campo.ORDEN,
                  estado: filaTabla.find('[data-campo="estado"]').length ? (filaTabla.find('[data-campo="estado"]').prop('checked') ? '1' : '0') : campo.ESTADO };
    $.post(URL_ARQ + '?op=guardarCampo', datos, null, 'json')
        .done(function (respuesta) { rdAviso(respuesta.mensaje); cargarCampos(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el campo.')); cargarCampos(); });
}

//Agrega un campo nuevo a la configuración del peaje
function agregarCampo() {
    var nombre = $.trim($('#cmpNombre').val());
    if (!nombre) { $('#cmpNombre').focus(); return; }
    $.post(URL_ARQ + '?op=guardarCampo', { id: 0, centro: $('#cmpCentro').val() || 0, nombre: nombre, rol: $('#cmpRol').val(), orden: $('#cmpOrden').val(), estado: '1' }, null, 'json')
        .done(function (respuesta) { rdAviso(respuesta.mensaje); $('#cmpNombre').val(''); cargarCampos(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo agregar el campo.')); });
}
