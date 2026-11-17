//Editar listas - Aviso por respuesta de una pregunta
//«Si responden No en esta pregunta, envíale un correo a tal persona o a tal rol».
//Habla con Control/AvisoRespuestaControl.php. El botón de la campana de cada pregunta
//(columna OPCIÓN de la tabla de preguntas) llama a abrirAvisoPregunta(id)
var URL_AVISO = '../Control/AvisoRespuestaControl.php';
var avisoPreguntaId = 0;

//Escapa texto para meterlo en HTML
function avisoEsc(texto) { return $('<div>').text(texto === null || texto === undefined ? '' : String(texto)).html(); }

function abrirAvisoPregunta(idPregunta) {
    avisoPreguntaId = idPregunta;
    $.getJSON(URL_AVISO + '?op=obtener&pregunta=' + encodeURIComponent(idPregunta))
        .done(function (datos) {
            var aviso = datos.aviso;
            $('#avisoPregunta').html(avisoEsc(datos.pregunta.PREGUNTA) + ' <small class="rd-ayuda">(' + avisoEsc(datos.pregunta.TIPO_RESPUESTA) + ')</small>');
            //Las respuestas que pueden disparar el aviso
            var html = '';
            $.each(datos.opciones, function (valor, texto) {
                html += '<label class="checkbox-inline"><input type="checkbox" name="avisoRespuesta" value="' + avisoEsc(valor) + '"' +
                        (aviso.r.indexOf(String(valor)) !== -1 ? ' checked' : '') + '> ' + avisoEsc(texto) + '</label>';
            });
            $('#avisoRespuestas').html(html);
            //Personas y roles
            $('#avisoPersonas').html(datos.personas.map(function (p) {
                return '<option value="' + parseInt(p.id, 10) + '"' + (aviso.p.indexOf(parseInt(p.id, 10)) !== -1 ? ' selected' : '') + '>' + avisoEsc(p.nombre) + '</option>';
            }).join(''));
            $('#avisoRoles').html(datos.roles.map(function (r) {
                return '<option value="' + parseInt(r.id, 10) + '"' + (aviso.o.indexOf(parseInt(r.id, 10)) !== -1 ? ' selected' : '') + '>' + avisoEsc(r.nombre) + '</option>';
            }).join(''));
            if ($.fn.select2) {
                $('#avisoPersonas, #avisoRoles').each(function () {
                    if ($(this).data('select2')) { $(this).select2('destroy'); }
                    $(this).select2({ dropdownParent: $('#modalAviso'), placeholder: 'Elige...', width: '100%' });
                });
            }
            $('input[name="avisoOn"][value="' + (aviso.on ? '1' : '0') + '"]').prop('checked', true);
            avisoMostrarCampos();
            $('#avisoQuien').text(aviso.modifico ? 'Último cambio: ' + aviso.modifico + ' · ' + String(aviso.fecha || '').substring(0, 16) : '');
            $('#modalAviso').modal('show');
        })
        .fail(function (xhr) { alert((xhr.responseJSON && xhr.responseJSON.error) || 'No se pudo abrir el aviso.'); });
}

//Con «No» se esconden los campos (pero no se borran: se pueden volver a encender igual)
function avisoMostrarCampos() { $('#avisoCampos').toggle($('input[name="avisoOn"]:checked').val() === '1'); }

function guardarAviso() {
    var datos = {
        pregunta: avisoPreguntaId,
        on: $('input[name="avisoOn"]:checked').val(),
        respuestas: $('input[name="avisoRespuesta"]:checked').map(function () { return this.value; }).get(),
        personas: $('#avisoPersonas').val() || [],
        roles: $('#avisoRoles').val() || []
    };
    if (datos.on === '1' && !datos.respuestas.length) { alert('Elige con qué respuesta se envía el aviso.'); return; }
    if (datos.on === '1' && !datos.personas.length && !datos.roles.length) { alert('Elige al menos una persona o un rol que reciba el aviso.'); return; }
    $('#btnGuardarAviso').prop('disabled', true);
    $.post(URL_AVISO + '?op=guardar', datos, null, 'json')
        .done(function (respuesta) {
            $('#modalAviso').modal('hide');
            if (window.rdAviso) { rdAviso(respuesta.mensaje); } else { alert(respuesta.mensaje); }
            //La campana de la pregunta se pinta de nuevo (resaltada si quedó encendido)
            if (typeof tabla !== 'undefined' && tabla && tabla.ajax) { tabla.ajax.reload(null, false); }
        })
        .fail(function (xhr) { alert((xhr.responseJSON && xhr.responseJSON.error) || 'No se pudo guardar el aviso.'); })
        .always(function () { $('#btnGuardarAviso').prop('disabled', false); });
}

$(function () {
    $(document).on('change', 'input[name="avisoOn"]', avisoMostrarCampos);
    $('#btnGuardarAviso').on('click', guardarAviso);
});
