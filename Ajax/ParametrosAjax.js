//Configuración - Parámetros del sistema y catálogos de ausentismo
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

//Trae los parámetros y los catálogos y los pinta por grupos
function cargarPar() {
    $.getJSON(URL_PAR + '?op=listar')
        .done(function (respuesta) {
            datosPar = respuesta;
            pintarEstadoCorreo(respuesta.correo);
            //Parámetros, por grupo
            var grupos = {}, orden = [];
            respuesta.parametros.forEach(function (parametro) { if (!grupos[parametro.GRUPO]) { grupos[parametro.GRUPO] = []; orden.push(parametro.GRUPO); } grupos[parametro.GRUPO].push(parametro); });
            var html = '';
            orden.forEach(function (grupo) {
                html += '<h4 class="rd-subtitulo">' + rdEsc(grupo) + '</h4><div class="par-grupo">';
                grupos[grupo].forEach(function (parametro) {
                    var campo = parametro.TIPO === 'SI_NO'
                        ? '<label class="par-interruptor"><input type="checkbox" class="par-si" data-clave="' + rdEsc(parametro.CLAVE) + '"' + (parametro.VALOR === '1' ? ' checked' : '') + '>' +
                          '<span class="par-riel"></span> <span class="par-si-txt">' + (parametro.VALOR === '1' ? 'Sí' : 'No') + '</span></label>'
                        : parametro.TIPO === 'CORREOS'
                        ? '<input type="text" class="form-control par-txt" data-clave="' + rdEsc(parametro.CLAVE) + '" value="' + rdEsc(parametro.VALOR) + '" maxlength="300" placeholder="correo@empresa.com">'
                        : '<input type="number" class="form-control par-num" data-clave="' + rdEsc(parametro.CLAVE) + '" value="' + rdEsc(parametro.VALOR) + '"' +
                          (parametro.MINIMO !== null ? ' min="' + parseInt(parametro.MINIMO, 10) + '"' : '') + (parametro.MAXIMO !== null ? ' max="' + parseInt(parametro.MAXIMO, 10) + '"' : '') + '>';
                    html += '<div class="par-fila"><div class="par-texto"><strong>' + rdEsc(parametro.NOMBRE) + '</strong>' +
                         (parametro.AYUDA ? '<div class="rd-ayuda">' + rdEsc(parametro.AYUDA) + '</div>' : '') +
                         (parametro.MODIFICO ? '<div class="par-quien">Último cambio: ' + rdEsc(parametro.MODIFICO) + ' · ' + rdFecha(parametro.FEC_MODIFICACION) + '</div>' : '') +
                         '</div><div class="par-campo">' + campo + '</div></div>';
                });
                html += '</div>';
            });
            $('#parGrupos').html(html || '<p class="rd-vacio">No hay parámetros para mostrar.</p>');
            rdCambiosGuardados();

            //Catálogos
            var htmlCargos = '';
            respuesta.cargos.forEach(function (cargo, i) {
                htmlCargos += '<tr' + (+cargo.ESTADO ? '' : ' class="rd-anulado"') + '><td class="rd-num-col">' + parseInt(cargo.ORDEN, 10) + '</td><td>' + rdEsc(cargo.NOMBRE) + '</td>' +
                      '<td>' + rdEsc(APLICA[cargo.APLICA] || cargo.APLICA) + '</td><td>' + (+cargo.ESTADO ? 'Sí' : 'No') + '</td>' +
                      '<td><button type="button" class="btn btn-default btn-xs btn-editar-cat" data-tipo="cargo" data-i="' + i + '"><i class="fa fa-pencil"></i></button></td></tr>';
            });
            $('#tbCargos').html(htmlCargos || '<tr><td colspan="5" class="rd-vacio">Sin cargos.</td></tr>');
            var htmlNovedades = '';
            //El ausentismo (y sus novedades/motivos) se retiró; respuesta.novedades ya no llega
            (respuesta.novedades || []).forEach(function (novedad, i) {
                htmlNovedades += '<tr' + (+novedad.ESTADO ? '' : ' class="rd-anulado"') + '><td class="rd-num-col">' + parseInt(novedad.ORDEN, 10) + '</td><td>' + rdEsc(novedad.NOMBRE) + '</td>' +
                      '<td>' + (+novedad.CUENTA_AUSENCIA ? 'Sí' : 'No') + '</td><td>' + rdEsc(novedad.CARGO || 'Todos') + '</td><td>' + (+novedad.ESTADO ? 'Sí' : 'No') + '</td>' +
                      '<td><button type="button" class="btn btn-default btn-xs btn-editar-cat" data-tipo="novedad" data-i="' + i + '"><i class="fa fa-pencil"></i></button></td></tr>';
            });
            $('#tbNovedades').html(htmlNovedades || '<tr><td colspan="6" class="rd-vacio">Sin novedades.</td></tr>');
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron cargar los parámetros.'); });
}

//Guarda todos los parámetros de la pantalla de una vez
function guardarPar(evento) {
    evento.preventDefault();
    var datos = {};
    $('.par-si').each(function () { datos['valores[' + $(this).data('clave') + ']'] = this.checked ? '1' : '0'; });
    $('.par-num').each(function () { datos['valores[' + $(this).data('clave') + ']'] = $(this).val(); });
    $('.par-txt').each(function () { datos['valores[' + $(this).data('clave') + ']'] = $(this).val(); });
    hanaBoton('#btnGuardarPar', true);
    $.post(URL_PAR + '?op=guardar', datos, null, 'json')
        .done(function (respuesta) { rdCambiosGuardados(); rdAviso(respuesta.mensaje); cargarPar(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudieron guardar los parámetros.')); })
        .always(function () { hanaBoton('#btnGuardarPar', false); });
}

//Ventana para crear o editar un cargo o una novedad
function editarCatalogo(tipo, registro) {
    var esCargo = tipo === 'cargo';
    var html = '<form id="formCat" data-tipo="' + tipo + '"><input type="hidden" name="id" value="' + (registro ? parseInt(esCargo ? registro.ID_CARGO_AUS : registro.ID_NOVEDAD_AUS, 10) : '') + '">' +
            '<div class="row"><div class="form-group col-sm-8"><label>Nombre:</label><input type="text" name="nombre" class="form-control" maxlength="80" value="' + rdEsc(registro ? registro.NOMBRE : '') + '"></div>' +
            '<div class="form-group col-sm-4"><label>Orden:</label><input type="number" name="orden" class="form-control" value="' + (registro ? parseInt(registro.ORDEN, 10) : 99) + '"></div></div>';
    if (esCargo) {
        html += '<div class="form-group"><label>Aplica en:</label><select name="aplica" class="form-control">';
        $.each(APLICA, function (clave, nombre) { html += '<option value="' + clave + '"' + (registro && registro.APLICA === clave ? ' selected' : '') + '>' + nombre + '</option>'; });
        html += '</select></div>';
    } else {
        html += '<div class="form-group"><label>¿Es ausencia?</label><select name="cuenta" class="form-control">' +
             '<option value="1"' + (!registro || +registro.CUENTA_AUSENCIA ? ' selected' : '') + '>Sí: suma en las ausencias diarias</option>' +
             '<option value="0"' + (registro && !+registro.CUENTA_AUSENCIA ? ' selected' : '') + '>No: se muestra en su fila, sin sumar</option></select></div>' +
             '<div class="form-group"><label>Solo en el cargo:</label><select name="cargo" class="form-control"><option value="0">Todos los cargos</option>';
        datosPar.cargos.forEach(function (cargo) { html += '<option value="' + parseInt(cargo.ID_CARGO_AUS, 10) + '"' + (registro && +registro.ID_CARGO_AUS === +cargo.ID_CARGO_AUS ? ' selected' : '') + '>' + rdEsc(cargo.NOMBRE) + '</option>'; });
        html += '</select></div>';
    }
    html += '<div class="form-group"><label>Estado:</label><select name="estado" class="form-control"><option value="1">Activo</option>' +
         '<option value="0"' + (registro && !+registro.ESTADO ? ' selected' : '') + '>Desactivado (no sale para registrar; lo registrado se conserva)</option></select></div>' +
         '<div class="rd-acciones"><button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Guardar</button></div></form>';
    var ventana = $('#modalCat');
    if (!ventana.length) {
        ventana = $('<div class="modal fade" id="modalCat" tabindex="-1" role="dialog"><div class="modal-dialog" role="document"><div class="modal-content">' +
              '<div class="modal-header rd-modal-cab"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>' +
              '<h4 class="modal-title" id="modalCatTitulo"></h4></div><div class="modal-body" id="modalCatCuerpo"></div></div></div></div>').appendTo('body');
    }
    $('#modalCatTitulo').text((registro ? 'Editar ' : 'Nuevo ') + (esCargo ? 'cargo' : 'novedad'));
    $('#modalCatCuerpo').html(html);
    ventana.modal('show');
}

//Guarda un cargo o una novedad del catálogo de ausentismo
function guardarCatalogo(evento) {
    evento.preventDefault();
    var tipo = $(this).data('tipo');
    $.post(URL_PAR + '?op=' + tipo, $(this).serialize(), null, 'json')
        .done(function (respuesta) { $('#modalCat').modal('hide'); rdAviso(respuesta.mensaje); cargarPar(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar.')); });
}

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

//Trae los días habilitados a cada persona para registrar fuera de fecha
function cargarDias() {
    $.getJSON(URL_PAR + '?op=diasHabilitados')
        .done(function (respuesta) {
            //El selector se llena una sola vez, para no perder lo que ya estaba elegido
            if (!$('#diaPersona option').length) {
                var htmlPersonas = '<option value="">Elige una persona</option>', grupo = '';
                respuesta.personas.forEach(function (persona) {
                    var grupoPersona = persona.COORDINA ? 'Coordinadores' : 'Otras personas'; //los coordinadores salen primero
                    if (grupoPersona !== grupo) { htmlPersonas += (grupo ? '</optgroup>' : '') + '<optgroup label="' + grupoPersona + '">'; grupo = grupoPersona; }
                    htmlPersonas += '<option value="' + parseInt(persona.ID_COLABORADOR, 10) + '">' + rdEsc(persona.NOM_COLABORADOR) +
                          (persona.COORDINA ? ' — ' + rdEsc(persona.COORDINA) : '') + '</option>';
                });
                $('#diaPersona').html(htmlPersonas + (grupo ? '</optgroup>' : ''));
            }
            var html = '';
            respuesta.dias.forEach(function (dia) {
                var abierto = +dia.ESTADO === 1;
                html += '<tr' + (abierto ? '' : ' class="rd-anulado"') + '><td>' + rdEsc(dia.PERSONA) + '</td>' +
                     '<td class="rd-nowrap">' + rdFecha(dia.FECHA) + '</td><td>' + rdEsc(dia.MOTIVO || '') + '</td>' +
                     '<td>' + rdEsc(dia.HABILITO || '') + ' · ' + rdFecha(dia.FEC_HABILITA) + '</td>' +
                     '<td>' + (abierto ? '<span class="rd-tag rd-tag-ok"><i class="fa fa-unlock"></i> Habilitado</span>'
                                       : '<span class="rd-tag"><i class="fa fa-lock"></i> Cerrado' + (dia.DESHABILITO ? ' por ' + rdEsc(dia.DESHABILITO) : '') +
                                         ' · ' + rdFecha(dia.FEC_DESHABILITA) + '</span>') + '</td>' +
                     '<td>' + (abierto ? '<button type="button" class="btn btn-default btn-xs btn-deshabilitar-dia" data-id="' + parseInt(dia.ID_DIA_HABILITADO, 10) +
                                         '" data-texto="' + rdEsc(dia.PERSONA + ', ' + rdFecha(dia.FECHA)).replace(/"/g, '&quot;') + '"><i class="fa fa-lock"></i> Deshabilitar</button>' : '') + '</td></tr>';
            });
            $('#tbDias').html(html || '<tr><td colspan="6" class="rd-vacio">No hay días habilitados. Todos registran solo el día de hoy.</td></tr>');
        })
        .fail(function (xhr) { $('#tbDias').html('<tr><td colspan="6" class="rd-vacio">' + rdEsc(rdError(xhr, 'No se pudieron cargar los días habilitados.')) + '</td></tr>'); });
}

//Habilita un día pasado a una persona para que pueda registrar
function habilitarDia(evento) {
    evento.preventDefault();
    if (!$('#diaPersona').val()) { alert('Elige a quién se le habilita el día.'); return; }
    if (!$('#diaFecha').val()) { alert('Elige el día que se va a habilitar.'); return; }
    hanaBoton('#btnHabilitarDia', true);
    $.post(URL_PAR + '?op=habilitarDia', $('#formDia').serialize(), null, 'json')
        .done(function (respuesta) { rdAviso(respuesta.mensaje); $('#diaFecha, #diaMotivo').val(''); cargarDias(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo habilitar el día.')); })
        .always(function () { hanaBoton('#btnHabilitarDia', false); });
}

//Quita un día habilitado (pregunta antes)
function deshabilitarDia(id, texto) {
    hanaConfirmar('¿Deshabilitar el día de ' + texto + '? Volverá a quedar solo de consulta.', function () {
        $.post(URL_PAR + '?op=deshabilitarDia', { id: id }, null, 'json')
            .done(function (respuesta) { rdAviso(respuesta.mensaje); cargarDias(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo deshabilitar el día.')); });
    });
}


//Arriba de los parámetros: solo se muestra, cuando los correos están saliendo, la cuenta y el
//último envío. Los avisos de configuración del servidor (cuenta de correo en Conexion/Global.php)
//no se muestran en pantalla.
function pintarEstadoCorreo(correo)
{
    var html = '';
    if (correo && correo.lista && !(correo.ultimo && parseInt(correo.ultimo.OK, 10) !== 1)) {
        html = '<p class="rd-ayuda"><i class="fa fa-envelope-o"></i> Los correos salen con la cuenta ' + rdEsc(correo.cuenta) +
               (correo.ultimo ? ' · último enviado: ' + rdEsc(correo.ultimo.FECHA) : '') + '.</p>';
    }
    $('#parCorreo').html(html);
}
