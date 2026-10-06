//Configuración — Parámetros del sistema y catálogos de ausentismo
var URL_PAR = '../Control/ParametrosControl.php';
var datosPar = null;
var APLICA = { PEAJE: 'Peajes', BASCULA: 'Básculas', AMBOS: 'Peajes y básculas' };

$(function () {
    cargarPar();
    rdVigilarCambios('#formPar');
    $('#formPar').on('submit', guardarPar);
    //En los sí/no, el texto al lado del interruptor cambia con él
    $(document).on('change', '.par-si', function () { $(this).closest('label').find('.par-si-txt').text(this.checked ? 'Sí' : 'No'); });
    $(document).on('click', '[data-nuevo]', function () { editarCatalogo($(this).data('nuevo'), null); });
    $(document).on('click', '.btn-editar-cat', function () {
        var tipo = $(this).data('tipo'), i = $(this).data('i');
        editarCatalogo(tipo, (tipo === 'cargo' ? datosPar.cargos : datosPar.novedades)[i]);
    });
    $(document).on('submit', '#formCat', guardarCatalogo);
});

function cargarPar() {
    $.getJSON(URL_PAR + '?op=listar')
        .done(function (d) {
            datosPar = d;
            //Parámetros, por grupo
            var grupos = {}, orden = [];
            d.parametros.forEach(function (p) { if (!grupos[p.GRUPO]) { grupos[p.GRUPO] = []; orden.push(p.GRUPO); } grupos[p.GRUPO].push(p); });
            var h = '';
            orden.forEach(function (g) {
                h += '<h4 class="rd-subtitulo">' + rdEsc(g) + '</h4><div class="par-grupo">';
                grupos[g].forEach(function (p) {
                    var campo = p.TIPO === 'SI_NO'
                        ? '<label class="par-interruptor"><input type="checkbox" class="par-si" data-clave="' + rdEsc(p.CLAVE) + '"' + (p.VALOR === '1' ? ' checked' : '') + '>' +
                          '<span class="par-riel"></span> <span class="par-si-txt">' + (p.VALOR === '1' ? 'Sí' : 'No') + '</span></label>'
                        : '<input type="number" class="form-control par-num" data-clave="' + rdEsc(p.CLAVE) + '" value="' + rdEsc(p.VALOR) + '"' +
                          (p.MINIMO !== null ? ' min="' + parseInt(p.MINIMO, 10) + '"' : '') + (p.MAXIMO !== null ? ' max="' + parseInt(p.MAXIMO, 10) + '"' : '') + '>';
                    h += '<div class="par-fila"><div class="par-texto"><strong>' + rdEsc(p.NOMBRE) + '</strong>' +
                         (p.AYUDA ? '<div class="rd-ayuda">' + rdEsc(p.AYUDA) + '</div>' : '') +
                         (p.MODIFICO ? '<div class="par-quien">Último cambio: ' + rdEsc(p.MODIFICO) + ' · ' + rdFecha(p.FEC_MODIFICACION) + '</div>' : '') +
                         '</div><div class="par-campo">' + campo + '</div></div>';
                });
                h += '</div>';
            });
            $('#parGrupos').html(h || '<p class="rd-vacio">No hay parámetros. Corre el script 03_AUSENTISMO_Y_PARAMETROS.sql.</p>');
            rdCambiosGuardados();

            //Catálogos
            var hc = '';
            d.cargos.forEach(function (c, i) {
                hc += '<tr' + (+c.ESTADO ? '' : ' class="rd-anulado"') + '><td class="rd-num-col">' + parseInt(c.ORDEN, 10) + '</td><td>' + rdEsc(c.NOMBRE) + '</td>' +
                      '<td>' + rdEsc(APLICA[c.APLICA] || c.APLICA) + '</td><td>' + (+c.ESTADO ? 'Sí' : 'No') + '</td>' +
                      '<td><button type="button" class="btn btn-default btn-xs btn-editar-cat" data-tipo="cargo" data-i="' + i + '"><i class="fa fa-pencil"></i></button></td></tr>';
            });
            $('#tbCargos').html(hc || '<tr><td colspan="5" class="rd-vacio">Sin cargos.</td></tr>');
            var hn = '';
            d.novedades.forEach(function (n, i) {
                hn += '<tr' + (+n.ESTADO ? '' : ' class="rd-anulado"') + '><td class="rd-num-col">' + parseInt(n.ORDEN, 10) + '</td><td>' + rdEsc(n.NOMBRE) + '</td>' +
                      '<td>' + (+n.CUENTA_AUSENCIA ? 'Sí' : 'No') + '</td><td>' + rdEsc(n.CARGO || 'Todos') + '</td><td>' + (+n.ESTADO ? 'Sí' : 'No') + '</td>' +
                      '<td><button type="button" class="btn btn-default btn-xs btn-editar-cat" data-tipo="novedad" data-i="' + i + '"><i class="fa fa-pencil"></i></button></td></tr>';
            });
            $('#tbNovedades').html(hn || '<tr><td colspan="6" class="rd-vacio">Sin novedades.</td></tr>');
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron cargar los parámetros.'); });
}

function guardarPar(e) {
    e.preventDefault();
    var datos = {};
    $('.par-si').each(function () { datos['valores[' + $(this).data('clave') + ']'] = this.checked ? '1' : '0'; });
    $('.par-num').each(function () { datos['valores[' + $(this).data('clave') + ']'] = $(this).val(); });
    hanaBoton('#btnGuardarPar', true);
    $.post(URL_PAR + '?op=guardar', datos, null, 'json')
        .done(function (r) { rdCambiosGuardados(); rdAviso(r.mensaje); cargarPar(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudieron guardar los parámetros.')); })
        .always(function () { hanaBoton('#btnGuardarPar', false); });
}

//Ventana para crear o editar un cargo o una novedad
function editarCatalogo(tipo, x) {
    var esCargo = tipo === 'cargo';
    var h = '<form id="formCat" data-tipo="' + tipo + '"><input type="hidden" name="id" value="' + (x ? parseInt(esCargo ? x.ID_CARGO_AUS : x.ID_NOVEDAD_AUS, 10) : '') + '">' +
            '<div class="row"><div class="form-group col-sm-8"><label>Nombre:</label><input type="text" name="nombre" class="form-control" maxlength="80" value="' + rdEsc(x ? x.NOMBRE : '') + '"></div>' +
            '<div class="form-group col-sm-4"><label>Orden:</label><input type="number" name="orden" class="form-control" value="' + (x ? parseInt(x.ORDEN, 10) : 99) + '"></div></div>';
    if (esCargo) {
        h += '<div class="form-group"><label>Aplica en:</label><select name="aplica" class="form-control">';
        $.each(APLICA, function (k, v) { h += '<option value="' + k + '"' + (x && x.APLICA === k ? ' selected' : '') + '>' + v + '</option>'; });
        h += '</select></div>';
    } else {
        h += '<div class="form-group"><label>¿Es ausencia?</label><select name="cuenta" class="form-control">' +
             '<option value="1"' + (!x || +x.CUENTA_AUSENCIA ? ' selected' : '') + '>Sí: suma en las ausencias diarias</option>' +
             '<option value="0"' + (x && !+x.CUENTA_AUSENCIA ? ' selected' : '') + '>No: se registra aparte (como cubre recolector)</option></select></div>' +
             '<div class="form-group"><label>Solo en el cargo:</label><select name="cargo" class="form-control"><option value="0">Todos los cargos</option>';
        datosPar.cargos.forEach(function (c) { h += '<option value="' + parseInt(c.ID_CARGO_AUS, 10) + '"' + (x && +x.ID_CARGO_AUS === +c.ID_CARGO_AUS ? ' selected' : '') + '>' + rdEsc(c.NOMBRE) + '</option>'; });
        h += '</select></div>';
    }
    h += '<div class="form-group"><label>Estado:</label><select name="estado" class="form-control"><option value="1">Activo</option>' +
         '<option value="0"' + (x && !+x.ESTADO ? ' selected' : '') + '>Desactivado (no sale para registrar; lo registrado se conserva)</option></select></div>' +
         '<div class="rd-acciones"><button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Guardar</button></div></form>';
    var m = $('#modalCat');
    if (!m.length) {
        m = $('<div class="modal fade" id="modalCat" tabindex="-1" role="dialog"><div class="modal-dialog" role="document"><div class="modal-content">' +
              '<div class="modal-header rd-modal-cab"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>' +
              '<h4 class="modal-title" id="modalCatTitulo"></h4></div><div class="modal-body" id="modalCatCuerpo"></div></div></div></div>').appendTo('body');
    }
    $('#modalCatTitulo').text((x ? 'Editar ' : 'Nuevo ') + (esCargo ? 'cargo' : 'novedad'));
    $('#modalCatCuerpo').html(h);
    m.modal('show');
}

function guardarCatalogo(e) {
    e.preventDefault();
    var tipo = $(this).data('tipo');
    $.post(URL_PAR + '?op=' + tipo, $(this).serialize(), null, 'json')
        .done(function (r) { $('#modalCat').modal('hide'); rdAviso(r.mensaje); cargarPar(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar.')); });
}

//---------------------------------------------------------------------------
// Pestaña Correo: la prueba y los últimos envíos
//---------------------------------------------------------------------------
$(function () {
    $('#tabCorreoLink').on('shown.bs.tab', cargarCorreoLog);
    $('#btnProbarCorreo').on('click', probarCorreo);
});

function cargarCorreoLog() {
    $.getJSON(URL_PAR + '?op=correoLog').done(function (f) {
        var h = '';
        f.forEach(function (x) {
            h += '<tr><td class="rd-nowrap">' + rdFecha(x.FECHA) + ' ' + rdHora(String(x.FECHA).substring(11)) + '</td><td>' + rdEsc(x.MODULO) + '</td>' +
                 '<td>' + rdEsc(x.PARA) + '</td><td>' + rdEsc(x.ASUNTO) + '</td><td>' + rdEsc(x.METODO) + '</td>' +
                 '<td>' + (+x.OK ? '<span class="rd-tag rd-tag-ok">Enviado</span>' : '<span class="rd-tag rd-tag-mal">Falló</span><br><small>' + rdEsc(x.ERROR || '') + '</small>') + '</td></tr>';
        });
        $('#tbCorreoLog').html(h || '<tr><td colspan="6" class="rd-vacio">Todavía no hay envíos registrados.</td></tr>');
    });
}

function probarCorreo() {
    var para = $.trim($('#correoPrueba').val());
    if (!para) { $('#correoPrueba').focus(); return; }
    hanaBoton('#btnProbarCorreo', true);
    $('#correoResultado').html('<p class="rd-vacio">Probando... puede tardar unos segundos por cada intento.</p>');
    $.post(URL_PAR + '?op=probarCorreo', { para: para }, null, 'json')
        .done(function (r) {
            var h = '<div class="rd-alerta-dia ' + (r.ok ? 'rd-alerta-ok' : 'rd-alerta-pend') + '"><i class="fa ' + (r.ok ? 'fa-check-circle' : 'fa-exclamation-circle') + '"></i> ' +
                    (r.ok ? 'El correo salió. Revisa la bandeja de ' + rdEsc(para) + ' (y la carpeta de spam).' : 'No se pudo enviar por ninguna vía. Abajo está el motivo de cada intento.') + '</div>';
            h += '<h4 class="rd-subtitulo">Revisión del servidor</h4><ul class="par-revision">';
            r.revision.forEach(function (x) {
                h += '<li class="' + (x[1] ? 'bien' : 'mal') + '"><i class="fa ' + (x[1] ? 'fa-check' : 'fa-times') + '"></i> <strong>' + rdEsc(x[0]) + ':</strong> ' + rdEsc(x[2]) + '</li>';
            });
            h += '</ul><h4 class="rd-subtitulo">Intentos de envío</h4>';
            r.intentos.forEach(function (x, i) {
                h += '<div class="par-intento ' + (x.ok ? 'bien' : 'mal') + '"><strong>' + (i + 1) + '. ' + rdEsc(x.metodo) + '</strong> — ' + (x.ok ? 'funcionó' : rdEsc(x.error || 'falló')) +
                     (x.conversacion ? '<details><summary>Ver la conversación con el servidor</summary><pre>' + rdEsc(x.conversacion) + '</pre></details>' : '') + '</div>';
            });
            $('#correoResultado').html(h);
            cargarCorreoLog();
        })
        .fail(function (xhr) { $('#correoResultado').empty(); alert(rdError(xhr, 'No se pudo hacer la prueba.')); })
        .always(function () { hanaBoton('#btnProbarCorreo', false); });
}

//Vista previa de los recordatorios: a quién le llegaría hoy y qué, sin enviar nada
$(function () {
    $('#btnVistaRecordatorios').on('click', function () {
        $.getJSON('../Control/TareasControl.php?op=recordatorios&simular=1')
            .done(function (r) {
                var h = '<div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta"><thead><tr><th>Coordinador</th><th>Correo</th><th>Le falta</th><th>Resultado</th></tr></thead><tbody>';
                r.detalle.forEach(function (x) {
                    h += '<tr><td>' + rdEsc(x.coordinador) + '</td><td>' + rdEsc(x.correo || '') + '</td><td>' + (x.falta.length ? rdEsc(x.falta.join(' · ')) : '—') + '</td>' +
                         '<td>' + rdEsc(x.resultado) + '</td></tr>';
                });
                $('#recordatoriosResultado').html(h + '</tbody></table></div>' + (r.detalle.length ? '' : '<p class="rd-vacio">No hay coordinadores asignados.</p>'));
            })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo armar la vista previa.')); });
    });
});

//---------------------------------------------------------------------------
// Pestaña Días habilitados: abrirle a una persona un día distinto de hoy
// y cerrárselo después
//---------------------------------------------------------------------------
$(function () {
    cargarDias();
    $('#tabDiasLink').on('shown.bs.tab', cargarDias); //al volver a la pestaña se refresca
    $('#formDia').on('submit', habilitarDia);
    $(document).on('click', '.btn-deshabilitar-dia', function () { deshabilitarDia($(this).data('id'), $(this).data('texto')); });
});

function cargarDias() {
    $.getJSON(URL_PAR + '?op=diasHabilitados')
        .done(function (d) {
            //El selector se llena una sola vez, para no perder lo que ya estaba elegido
            if (!$('#diaPersona option').length) {
                var hs = '<option value="">Elige una persona</option>', grupo = '';
                d.personas.forEach(function (p) {
                    var g = p.COORDINA ? 'Coordinadores' : 'Otras personas'; //los coordinadores salen primero
                    if (g !== grupo) { hs += (grupo ? '</optgroup>' : '') + '<optgroup label="' + g + '">'; grupo = g; }
                    hs += '<option value="' + parseInt(p.ID_COLABORADOR, 10) + '">' + rdEsc(p.NOM_COLABORADOR) +
                          (p.COORDINA ? ' — ' + rdEsc(p.COORDINA) : '') + '</option>';
                });
                $('#diaPersona').html(hs + (grupo ? '</optgroup>' : ''));
            }
            var h = '';
            d.dias.forEach(function (x) {
                var abierto = +x.ESTADO === 1;
                h += '<tr' + (abierto ? '' : ' class="rd-anulado"') + '><td>' + rdEsc(x.PERSONA) + '</td>' +
                     '<td class="rd-nowrap">' + rdFecha(x.FECHA) + '</td><td>' + rdEsc(x.MOTIVO || '') + '</td>' +
                     '<td>' + rdEsc(x.HABILITO || '') + ' · ' + rdFecha(x.FEC_HABILITA) + '</td>' +
                     '<td>' + (abierto ? '<span class="rd-tag rd-tag-ok"><i class="fa fa-unlock"></i> Habilitado</span>'
                                       : '<span class="rd-tag"><i class="fa fa-lock"></i> Cerrado' + (x.DESHABILITO ? ' por ' + rdEsc(x.DESHABILITO) : '') +
                                         ' · ' + rdFecha(x.FEC_DESHABILITA) + '</span>') + '</td>' +
                     '<td>' + (abierto ? '<button type="button" class="btn btn-default btn-xs btn-deshabilitar-dia" data-id="' + parseInt(x.ID_DIA_HABILITADO, 10) +
                                         '" data-texto="' + rdEsc(x.PERSONA + ', ' + rdFecha(x.FECHA)).replace(/"/g, '&quot;') + '"><i class="fa fa-lock"></i> Deshabilitar</button>' : '') + '</td></tr>';
            });
            $('#tbDias').html(h || '<tr><td colspan="6" class="rd-vacio">No hay días habilitados. Todos registran solo el día de hoy.</td></tr>');
        })
        .fail(function (xhr) { $('#tbDias').html('<tr><td colspan="6" class="rd-vacio">' + rdEsc(rdError(xhr, 'No se pudieron cargar los días habilitados.')) + '</td></tr>'); });
}

function habilitarDia(e) {
    e.preventDefault();
    if (!$('#diaPersona').val()) { alert('Elige a quién se le habilita el día.'); return; }
    if (!$('#diaFecha').val()) { alert('Elige el día que se va a habilitar.'); return; }
    hanaBoton('#btnHabilitarDia', true);
    $.post(URL_PAR + '?op=habilitarDia', $('#formDia').serialize(), null, 'json')
        .done(function (r) { rdAviso(r.mensaje); $('#diaFecha, #diaMotivo').val(''); cargarDias(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo habilitar el día.')); })
        .always(function () { hanaBoton('#btnHabilitarDia', false); });
}

function deshabilitarDia(id, texto) {
    hanaConfirmar('¿Deshabilitar el día de ' + texto + '? Volverá a quedar solo de consulta.', function () {
        $.post(URL_PAR + '?op=deshabilitarDia', { id: id }, null, 'json')
            .done(function (r) { rdAviso(r.mensaje); cargarDias(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo deshabilitar el día.')); });
    });
}
