//Reporte diario — Arqueos
//Formulario con cálculo en vivo (el servidor vuelve a calcular al guardar),
//listado del mes, detalle con impresión y configuración de fondos

var URL_ARQ = '../Control/ArqueoControl.php';
var cfgArq = null;

$(function () {
    rdAlertaDia('arqueos', '#alertaDia');
    rdVigilarCambios('#formArq');
    $(document).on('click', '#arqRapido [data-dia]', function () {
        $('#arqFecha').val(rdSumarDias(cfgArq.hoy, parseInt($(this).data('dia'), 10))).trigger('change');
    });
    $(document).on('change', '#arqFecha', marcarDiaArq);

    $.getJSON(URL_ARQ + '?op=config')
        .done(function (c) {
            cfgArq = c;
            $('#btnNuevoArq').toggle(!!c.puedeArquear);
            $('#btnFondos').toggle(!!c.puedeFondos);
            $('#btnCampos').toggle(!!c.puedeCampos);

            //Filtros
            var hc = '<option value="0">Todos</option>';
            for (var i = 0; i < c.centros.length; i++) {
                hc += '<option value="' + parseInt(c.centros[i].ID_CENTRO_OP, 10) + '">' + rdEsc(c.centros[i].NOM_CENTRO_OP) + '</option>';
            }
            $('#fCentroArq').html(hc);
            var ht = '<option value="">Todos</option>', hr = '';
            $.each(c.tipos, function (cod, nom) {
                ht += '<option value="' + rdEsc(cod) + '">' + rdEsc(nom) + '</option>';
                hr += '<label class="rd-opcion"><input type="radio" name="tipo" value="' + rdEsc(cod) + '"> <span>' + rdEsc(nom) + '</span></label>';
            });
            $('#fTipoArq').html(ht);
            $('#arqTipos').html(hr);
            rdPeriodo('#fMesArq', '#fAnioArq', c.hoy);
            listarArq();

            if (!c.centros.length && c.puedeArquear) {
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
    $(document).on('change', '.cmp-ocultar', ocultarCampoFila);
    $(document).on('input change', '#formArq .rd-pesos, #formArq select', calcular);
    $('#btnAgregarDoc').on('click', function () { agregarDoc(); });
    $(document).on('click', '.rd-quitar-doc', function () { $(this).closest('tr').remove(); calcular(); });
    $('#formArq').on('submit', guardarArq);

    $(document).on('click', '.btn-ver-arq', function () { verArq($(this).data('id')); });
    $(document).on('click', '#btnAnularArq', anularArq);
    $(document).on('click', '.btn-fondo', guardarFondo);
});

function mostrarPanel(id) {
    $('#panelListaArq, #panelFormArq, #panelFondos, #panelCampos').hide();
    $(id).show();
    window.scrollTo(0, 0);
}

//---------------------------------------------------------------------------
// Listado
//---------------------------------------------------------------------------
function listarArq() {
    var q = '&centro=' + encodeURIComponent($('#fCentroArq').val() || 0) + '&tipo=' + encodeURIComponent($('#fTipoArq').val() || '') +
            '&mes=' + encodeURIComponent($('#fMesArq').val()) + '&anio=' + encodeURIComponent($('#fAnioArq').val());
    $.getJSON(URL_ARQ + '?op=listar' + q)
        .done(function (filas) {
            var h = '', total = 0, cuadran = 0, dif = 0;
            for (var i = 0; i < filas.length; i++) {
                var a = filas[i], vigente = parseInt(a.ESTADO, 10) === 1, d = parseFloat(a.DIFERENCIA);
                if (vigente) { total++; if (d === 0) { cuadran++; } else { dif++; } }
                h += '<tr' + (vigente ? '' : ' class="rd-anulado"') + '>' +
                     '<td>' + rdFecha(a.FECHA) + ' <small>' + rdHora(a.HORA) + '</small></td>' +
                     '<td>' + rdEsc(cfgArq.tipos[a.TIPO] || a.TIPO) + (a.CASETA ? '<br><small>Caseta ' + rdEsc(a.CASETA) + '</small>' : '') + '</td>' +
                     '<td>' + rdEsc(a.NOM_CENTRO_OP) + '</td>' +
                     '<td>' + rdEsc(a.RESPONSABLE) + '</td>' +
                     '<td class="rd-num-col">' + rdPesos(a.TOTAL_ARQUEO) + '</td>' +
                     '<td class="rd-num-col">' + rdPesos(a.FONDO_AUTORIZADO) + '</td>' +
                     '<td>' + (vigente ? etiquetaDif(d) : '<span class="rd-tag">Anulado</span>') + '</td>' +
                     '<td class="rd-nowrap"><button type="button" class="btn btn-success btn-xs btn-ver-arq" data-id="' + parseInt(a.ID_ARQUEO, 10) + '">' +
                     '<i class="fa fa-eye"></i> Ver</button> ' +
                     '<a class="btn btn-default btn-xs" href="ArqueoPdfVista.php?id=' + parseInt(a.ID_ARQUEO, 10) + '" title="Descargar PDF">' +
                     '<i class="fa fa-file-pdf-o"></i> PDF</a></td></tr>';
            }
            $('#filasArq').html(h || '<tr><td colspan="8" class="rd-vacio">No hay arqueos en este periodo.</td></tr>');
            $('#cntArq').text(total); $('#cntCuadran').text(cuadran); $('#cntDif').text(dif);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron cargar los arqueos.'); });
}

//Cuadra (verde) o la diferencia: faltante si falta dinero, sobrante si sobra
function etiquetaDif(d) {
    d = parseFloat(d) || 0;
    if (d === 0) { return '<span class="rd-tag rd-tag-ok"><i class="fa fa-check"></i> Cuadra</span>'; }
    return '<span class="rd-tag rd-tag-mal">' + (d < 0 ? 'Faltante ' : 'Sobrante ') + rdPesos(Math.abs(d)) + '</span>';
}

//---------------------------------------------------------------------------
// Formulario
//---------------------------------------------------------------------------
function abrirFormulario() {
    $('#formArq')[0].reset();
    $('#arqFecha').val(cfgArq.hoy).attr({ max: cfgArq.ultimoEditable, min: cfgArq.primerEditable });
    var ahora = new Date();
    $('#arqHora').val(rdDos(ahora.getHours()) + ':' + rdDos(ahora.getMinutes()));
    $('input[name="tipo"]').first().prop('checked', true);
    pintarTipo();
    marcarDiaArq();
    rdCambiosGuardados();
    mostrarPanel('#panelFormArq');
}

//Ayer / Hoy / Mañana: marca el día elegido en la fecha del arqueo
function marcarDiaArq() {
    var f = $('#arqFecha').val();
    $('#arqRapido [data-dia]').removeClass('active').each(function () {
        if (rdSumarDias(cfgArq.hoy, parseInt($(this).data('dia'), 10)) === f) { $(this).addClass('active'); }
    });
}

//Según el tipo: los centros que tienen ese fondo, las líneas de efectivo y los documentos
function pintarTipo() {
    var tipo = $('input[name="tipo"]:checked').val();
    if (!tipo) { return; }

    var caseta = tipo === 'CASETA';
    var h = '', con = 0;
    for (var i = 0; i < cfgArq.centros.length; i++) {
        var c = cfgArq.centros[i], f = c.FONDOS[tipo];
        //El arqueo de casetas no usa fondo: salen todos los peajes
        if (caseta) { f = ''; }
        else if (f === undefined) { continue; }
        con++;
        h += '<option value="' + parseInt(c.ID_CENTRO_OP, 10) + '" data-fondo="' + parseFloat(f) + '">' +
             rdEsc(c.NOM_CENTRO_OP) + ' · ' + rdEsc(c.NOM_PROYECTO) + '</option>';
    }
    $('#arqCentro').html(con ? h : '<option value="">Ningún centro tuyo tiene este fondo autorizado</option>');

    //Efectivo: una línea por concepto
    var ef = '';
    $.each(cfgArq.efectivo[tipo], function (cod, nom) {
        ef += '<div class="row rd-linea-ef"><div class="form-group col-sm-5 col-xs-12"><label>' + rdEsc(nom) + ':</label>' +
              '<input type="text" inputmode="numeric" class="form-control rd-pesos" name="efectivo[' + rdEsc(cod) + ']" placeholder="$ 0"></div>' +
              '<div class="form-group col-sm-7 col-xs-12"><label>Observación:</label>' +
              '<input type="text" class="form-control" name="efectivoObs[' + rdEsc(cod) + ']" maxlength="300"></div></div>';
    });
    $('#arqEfectivo').html(ef);

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

function tipoArq() { return $('input[name="tipo"]:checked').val(); }

//Casetas: un renglón por cada campo configurado para el peaje elegido
var camposCaseta = [];
function pintarCampos() {
    var centro = $('#arqCentro').val();
    if (!centro) { $('#arqCampos').html('<p class="rd-vacio">Elige el peaje.</p>'); return; }
    $.getJSON(URL_ARQ + '?op=campos&centro=' + encodeURIComponent(centro))
        .done(function (f) {
            camposCaseta = f;
            var h = '';
            f.forEach(function (c) {
                var nota = { ESPERADO: 'Lo que registra el sistema de la caseta', SUMA: 'Suma al total recaudo', RESTA: 'Resta del total recaudo', INFO: 'No entra en la cuenta' }[c.ROL];
                h += '<div class="row rd-linea-ef arq-campo arq-rol-' + rdEsc(c.ROL) + '"><div class="form-group col-sm-6 col-xs-12">' +
                     '<label>' + rdEsc(c.NOMBRE) + ':</label><div class="rd-ayuda" style="margin:-3px 0 4px;">' + rdEsc(nota) + '</div>' +
                     '<input type="text" inputmode="numeric" class="form-control rd-pesos" name="campo[' + parseInt(c.ID_CONCEPTO, 10) + ']" data-rol="' + rdEsc(c.ROL) + '" placeholder="$ 0"></div></div>';
            });
            $('#arqCampos').html(h || '<p class="rd-vacio">Este peaje no tiene campos configurados. Pídelos en "Campos de casetas".</p>');
            calcular();
        })
        .fail(function (xhr) { $('#arqCampos').html('<p class="rd-ayuda">' + rdEsc(rdError(xhr, 'No se pudieron cargar los campos.')) + '</p>'); });
}

function agregarDoc() {
    var opc = '<option value="">Concepto...</option>';
    $.each(cfgArq.documentos, function (cod, nom) { opc += '<option value="' + rdEsc(cod) + '">' + rdEsc(nom) + '</option>'; });
    $('#arqDocs').append('<tr>' +
        '<td><input type="date" name="docFecha[]" class="form-control input-sm" max="' + cfgArq.ultimoEditable + '"></td>' +
        '<td><select name="docConcepto[]" class="form-control input-sm">' + opc + '</select></td>' +
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
            var v = rdLeerPesos(this.value), rol = $(this).data('rol');
            if (rol === 'ESPERADO') { esperado += v; } else if (rol === 'SUMA') { recaudo += v; } else if (rol === 'RESTA') { recaudo -= v; }
            else if (v) { info.push($(this).closest('.form-group').find('label').text().replace(/:$/, '') + ': ' + rdPesos(v)); }
        });
        var nov = recaudo - esperado;
        var hc = '<div><span>Recaudo caseta</span><strong>' + rdPesos(esperado) + '</strong></div>' +
                 '<div><span>Total recaudo</span><strong>' + rdPesos(recaudo) + '</strong></div>' +
                 '<div class="rd-total-dif"><span>Novedad</span>' + (esperado ? (nov === 0 ? etiquetaDif(0) : '<span class="rd-tag ' + (nov < 0 ? 'rd-tag-mal' : 'rd-tag-pend') + '">' +
                 (nov < 0 ? 'Faltante ' : 'Sobrante ') + rdPesos(Math.abs(nov)) + '</span>') : '<span class="rd-ayuda">Escribe el recaudo de la caseta</span>') + '</div>';
        info.forEach(function (t) { hc += '<div class="arq-info"><span>' + rdEsc(t) + '</span></div>'; });
        $('#arqFondoTxt').text('');
        $('#arqTotales').html(hc);
        return;
    }
    var efectivo = 0, documentos = 0;
    $('#arqEfectivo .rd-pesos').each(function () { efectivo += rdLeerPesos(this.value); });
    $('#arqDocs .rd-pesos').each(function () { documentos += rdLeerPesos(this.value); });
    var total = efectivo + documentos;
    var op = $('#arqCentro option:selected'), fondo = parseFloat(op.data('fondo'));
    $('#arqFondoTxt').text(isNaN(fondo) ? '' : 'Fondo autorizado: ' + rdPesos(fondo));

    var h = '<div><span>Efectivo</span><strong>' + rdPesos(efectivo) + '</strong></div>';
    if ($('#arqBloqueDocs').is(':visible')) { h += '<div><span>Documentos</span><strong>' + rdPesos(documentos) + '</strong></div>'; }
    h += '<div><span>Total arqueo</span><strong>' + rdPesos(total) + '</strong></div>';
    if (!isNaN(fondo)) {
        h += '<div><span>Fondo autorizado</span><strong>' + rdPesos(fondo) + '</strong></div>' +
             '<div class="rd-total-dif"><span>Diferencia</span>' + etiquetaDif(total - fondo) + '</div>';
    }
    $('#arqTotales').html(h);
}

function guardarArq(e) {
    e.preventDefault();
    if (!$('#arqCentro').val()) { alert('Elige el centro. Si no aparece, ese centro no tiene fondo autorizado.'); return; }
    if (!$.trim($('#arqResponsable').val())) { alert('Escribe quién es la persona responsable del dinero.'); $('#arqResponsable').focus(); return; }
    if (!$('#arqHora').val()) { alert('Escribe la hora del arqueo.'); return; }

    hanaBoton('#btnGuardarArq', true);
    $.ajax({ url: URL_ARQ + '?op=guardar', type: 'POST', data: $('#formArq').serialize(), dataType: 'json' })
        .done(function (r) {
            rdCambiosGuardados();
            mostrarPanel('#panelListaArq');
            listarArq();
            rdAviso(r.mensaje + ' Se está descargando el PDF para imprimir y firmar.');
            //El PDF del arqueo se descarga solo, con la forma del formato de siempre
            descargarPdf(r.id);
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
        .done(function (a) {
            var vigente = parseInt(a.ESTADO, 10) === 1;
            $('#modalArqTitulo').text('Arqueo de ' + (cfgArq.tipos[a.TIPO] || a.TIPO).toLowerCase() + ' · ' + a.NOM_CENTRO_OP);
            var h = '<div class="rd-det-cab">' +
                    '<div><span class="rd-det-lbl">Fecha</span>' + rdFecha(a.FECHA) + ' ' + rdHora(a.HORA) + (a.HORA_FIN ? ' – ' + rdHora(a.HORA_FIN) : '') + '</div>' +
                    '<div><span class="rd-det-lbl">Responsable</span>' + rdEsc(a.RESPONSABLE) + (a.CARGO_RESPONSABLE ? '<br><small>' + rdEsc(a.CARGO_RESPONSABLE) + '</small>' : '') + '</div>' +
                    '<div><span class="rd-det-lbl">Arqueó</span>' + rdEsc(a.ARQUEA) + '</div>' +
                    '<div><span class="rd-det-lbl">Resultado</span>' + (vigente ? etiquetaDif(a.DIFERENCIA) : '<span class="rd-tag">Anulado</span>') + '</div></div>';
            if (!vigente) { h += '<p class="rd-aviso-mal">Anulado: ' + rdEsc(a.MOTIVO_ANULACION) + '</p>'; }

            h += '<table class="table rd-tabla"><thead><tr><th>Concepto</th><th>Fecha</th><th>Observación</th><th class="rd-num-col">Valor</th></tr></thead><tbody>';
            for (var i = 0; i < a.LINEAS.length; i++) {
                var l = a.LINEAS[i];
                //Casetas: el renglón guarda el nombre que tenía el campo ese día
                var nom = l.NOMBRE || (l.CLASE === 'DOCUMENTO' ? cfgArq.documentos[l.CONCEPTO] : (cfgArq.efectivo[a.TIPO] || {})[l.CONCEPTO]);
                h += '<tr><td>' + rdEsc(nom || l.CONCEPTO) + '</td><td>' + rdFecha(l.FECHA) + '</td><td>' + rdEsc(l.OBSERVACION) + '</td>' +
                     '<td class="rd-num-col">' + rdPesos(l.VALOR) + '</td></tr>';
            }
            h += '</tbody><tfoot>' +
                 '<tr><th colspan="3">Total arqueo</th><th class="rd-num-col">' + rdPesos(a.TOTAL_ARQUEO) + '</th></tr>' +
                 '<tr><th colspan="3">Fondo autorizado</th><th class="rd-num-col">' + rdPesos(a.FONDO_AUTORIZADO) + '</th></tr>' +
                 '<tr><th colspan="3">Diferencia</th><th class="rd-num-col">' + rdPesos(a.DIFERENCIA) + '</th></tr></tfoot></table>';
            if (a.OBSERVACION) { h += '<p><strong>Observación:</strong> ' + rdEsc(a.OBSERVACION) + '</p>'; }

            h += '<div class="rd-acciones">';
            if (a.PUEDE_ANULAR) {
                h += '<input type="text" id="arqMotivo" class="form-control rd-motivo" maxlength="300" placeholder="Motivo para anular">' +
                     '<button type="button" class="btn btn-default" id="btnAnularArq" data-id="' + parseInt(a.ID_ARQUEO, 10) + '"><i class="fa fa-ban"></i> Anular</button>';
            }
            h += '<a class="btn btn-default" target="_blank" rel="noopener" href="ArqueoPdfVista.php?ver=1&id=' + parseInt(a.ID_ARQUEO, 10) + '">' +
                 '<i class="fa fa-eye"></i> Ver PDF</a>' +
                 '<a class="btn btn-success" href="ArqueoPdfVista.php?id=' + parseInt(a.ID_ARQUEO, 10) + '">' +
                 '<i class="fa fa-file-pdf-o"></i> Descargar PDF</a></div>';
            $('#modalArqCuerpo').html(h);
        })
        .fail(function (xhr) { $('#modalArq').modal('hide'); alert(rdError(xhr, 'No se pudo abrir el arqueo.')); });
}

//Descarga el PDF. El servidor lo manda como archivo adjunto, así que en el
//computador se descarga sin salir de la pantalla; en el iPhone se abre el PDF
function descargarPdf(id) {
    window.location.href = 'ArqueoPdfVista.php?id=' + parseInt(id, 10);
}

function anularArq() {
    var id = $(this).data('id'), motivo = $.trim($('#arqMotivo').val());
    if (!motivo) { alert('Escribe por qué se anula el arqueo.'); $('#arqMotivo').focus(); return; }
    hanaConfirmar('¿Anular este arqueo? No se borra: queda anulado con el motivo.', function () {
        $.post(URL_ARQ + '?op=anular', { id: id, motivo: motivo }, null, 'json')
            .done(function (r) { $('#modalArq').modal('hide'); rdAviso(r.mensaje); listarArq(); })
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
            var h = '', fmt = function (v) { return v ? Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') : ''; };
            for (var i = 0; i < filas.length; i++) {
                var f = filas[i], id = parseInt(f.ID_CENTRO_OP, 10);
                h += '<tr><td>' + rdEsc(f.NOM_PROYECTO) + '</td><td>' + rdEsc(f.NOM_CENTRO_OP) + '</td>' +
                     '<td><input type="text" inputmode="numeric" class="form-control input-sm rd-pesos" id="fcm' + id + '" value="' + fmt(f.CAJA_MENOR) + '" placeholder="No maneja"></td>' +
                     '<td><input type="text" inputmode="numeric" class="form-control input-sm rd-pesos" id="fre' + id + '" value="' + fmt(f.RECAMBIO) + '" placeholder="No maneja"></td>' +
                     '<td><button type="button" class="btn btn-success btn-xs btn-fondo" data-id="' + id + '"><i class="fa fa-save"></i> Guardar</button></td></tr>';
            }
            $('#filasFondos').html(h || '<tr><td colspan="5" class="rd-vacio">No hay centros activos.</td></tr>');
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudieron cargar los fondos.')); });
}

function guardarFondo() {
    var id = $(this).data('id'), boton = this;
    hanaBoton(boton, true);
    $.post(URL_ARQ + '?op=guardarFondo', { centro: id, CAJA_MENOR: $('#fcm' + id).val(), RECAMBIO: $('#fre' + id).val() }, null, 'json')
        .done(function (r) {
            rdAviso(r.mensaje);
            //Se recarga la configuración para que el formulario use el fondo nuevo
            $.getJSON(URL_ARQ + '?op=config', function (c) { cfgArq = c; });
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el fondo.')); })
        .always(function () { hanaBoton(boton, false); });
}

//---------------------------------------------------------------------------
// Campos del arqueo de casetas (5M o 24M): agregar, renombrar, ordenar, quitar
//---------------------------------------------------------------------------
function abrirCampos() {
    mostrarPanel('#panelCampos');
    $('#cmpRol').html(Object.keys(cfgArq.roles).map(function (k) { return '<option value="' + k + '">' + rdEsc(cfgArq.roles[k]) + '</option>'; }).join('')).val('SUMA');
    cargarCampos();
}

function cargarCampos() {
    var centro = +($('#cmpCentro').val() || 0);
    $.getJSON(URL_ARQ + '?op=camposConfig&centro=' + centro)
        .done(function (d) {
            //El selector de peajes (se llena una vez)
            if (!$('#cmpCentro option').length) {
                $('#cmpCentro').html('<option value="0">Todos los peajes (campos generales)</option>' +
                    d.centros.map(function (c) { return '<option value="' + parseInt(c.ID_CENTRO_OP, 10) + '">' + rdEsc(c.NOM_PROYECTO) + ' · ' + rdEsc(c.NOM_CENTRO_OP) + '</option>'; }).join(''));
            }
            var enPeaje = centro > 0, h = '';
            $('#cmpDonde').text(enPeaje ? '(solo para ' + $('#cmpCentro option:selected').text() + ')' : '(para todos los peajes)');
            d.campos.forEach(function (c) {
                var general = c.ID_CENTRO_OP === null, id = parseInt(c.ID_CONCEPTO, 10);
                //Los generales se editan en "todos los peajes"; en un peaje solo se quitan o se vuelven a poner
                var editable = general ? !enPeaje : true;
                var roles = Object.keys(cfgArq.roles).map(function (k) { return '<option value="' + k + '"' + (k === c.ROL ? ' selected' : '') + '>' + rdEsc(cfgArq.roles[k]) + '</option>'; }).join('');
                h += '<tr data-id="' + id + '"' + (+c.ESTADO && !+c.OCULTO ? '' : ' class="rd-anulado"') + '>' +
                     '<td>' + (editable ? '<input type="number" class="form-control input-sm cmp-editar" data-campo="orden" value="' + parseInt(c.ORDEN, 10) + '">' : parseInt(c.ORDEN, 10)) + '</td>' +
                     '<td>' + (editable ? '<input type="text" class="form-control input-sm cmp-editar" data-campo="nombre" maxlength="80" value="' + rdEsc(c.NOMBRE) + '">' : rdEsc(c.NOMBRE)) + '</td>' +
                     '<td>' + (editable ? '<select class="form-control input-sm cmp-editar" data-campo="rol">' + roles + '</select>' : rdEsc(cfgArq.roles[c.ROL])) + '</td>' +
                     '<td>' + (general ? 'Todos los peajes' : 'Solo este peaje') + '</td>' +
                     '<td>' + (general && enPeaje
                        ? '<label class="par-interruptor"><input type="checkbox" class="cmp-ocultar"' + (+c.OCULTO ? '' : ' checked') + '><span class="par-riel"></span> ' + (+c.OCULTO ? 'Quitado' : 'Sale') + '</label>'
                        : '<label class="par-interruptor"><input type="checkbox" class="cmp-editar" data-campo="estado"' + (+c.ESTADO ? ' checked' : '') + '><span class="par-riel"></span> ' + (+c.ESTADO ? 'Activo' : 'Apagado') + '</label>') + '</td>' +
                     '<td><small class="text-muted">' + (general && enPeaje ? 'Se renombra en "Todos los peajes"' : '') + '</small></td></tr>';
            });
            $('#filasCampos').html(h || '<tr><td colspan="6" class="rd-vacio">No hay campos.</td></tr>');
            campoFilas = d.campos;
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudieron cargar los campos.')); });
}
var campoFilas = [];

//Guardar un cambio en la fila (nombre, orden, comportamiento o activo)
function guardarCampoFila() {
    var tr = $(this).closest('tr'), id = tr.data('id');
    var c = campoFilas.filter(function (x) { return +x.ID_CONCEPTO === +id; })[0];
    var datos = { id: id, centro: c.ID_CENTRO_OP || 0,
                  nombre: tr.find('[data-campo="nombre"]').val() || c.NOMBRE, rol: tr.find('[data-campo="rol"]').val() || c.ROL,
                  orden: tr.find('[data-campo="orden"]').val() || c.ORDEN,
                  estado: tr.find('[data-campo="estado"]').length ? (tr.find('[data-campo="estado"]').prop('checked') ? '1' : '0') : c.ESTADO };
    $.post(URL_ARQ + '?op=guardarCampo', datos, null, 'json')
        .done(function (r) { rdAviso(r.mensaje); cargarCampos(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el campo.')); cargarCampos(); });
}

//En un peaje: quitar o volver a poner un campo general
function ocultarCampoFila() {
    var id = $(this).closest('tr').data('id');
    $.post(URL_ARQ + '?op=ocultarCampo', { id: id, centro: $('#cmpCentro').val(), ocultar: this.checked ? '0' : '1' }, null, 'json')
        .done(function (r) { rdAviso(r.mensaje); cargarCampos(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo cambiar.')); cargarCampos(); });
}

function agregarCampo() {
    var nombre = $.trim($('#cmpNombre').val());
    if (!nombre) { $('#cmpNombre').focus(); return; }
    $.post(URL_ARQ + '?op=guardarCampo', { id: 0, centro: $('#cmpCentro').val() || 0, nombre: nombre, rol: $('#cmpRol').val(), orden: $('#cmpOrden').val(), estado: '1' }, null, 'json')
        .done(function (r) { rdAviso(r.mensaje); $('#cmpNombre').val(''); cargarCampos(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo agregar el campo.')); });
}
