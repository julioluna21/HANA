//Reporte diario - Correos automáticos (parte de arriba, solo el ADMIN TEC)
//Los recordatorios, el resultado de la promesa de valor y su aviso en la campana:
//si están encendidos, la hora, los días, a quién le llegan, qué revisan y una nota.
//Habla con Control/TareasControl.php: listar, guardar, simular y ejecutar
var URL_TAR = '../Control/TareasControl.php';
var datosTar = null;
var DIAS_TAR = [[1, 'L', 'Lunes'], [2, 'M', 'Martes'], [3, 'X', 'Miércoles'], [4, 'J', 'Jueves'], [5, 'V', 'Viernes'], [6, 'S', 'Sábado'], [7, 'D', 'Domingo']];

$(function () {
    cargarTareas();
    $(document).on('click', '.ca-guardar', function () { guardarTarea($(this).data('clave'), this); });
    //El aviso de la promesa: la hora solo cuenta si aparece al día siguiente
    $(document).on('change', '.ca-momento', function () { $(this).closest('.ca-tarjeta').find('.ca-grupo-hora').toggle(this.value === 'SIGUIENTE_DIA'); });
    //Buscar una persona en la lista
    $(document).on('input', '.ca-buscar-persona', function () {
        var texto = this.value.toLowerCase();
        $(this).closest('.form-group').find('.ca-persona-fila').each(function () { $(this).toggle($(this).text().toLowerCase().indexOf(texto) !== -1); });
    });
    $(document).on('click', '.ca-simular', function () { simularTarea($(this).data('clave'), this); });
    $(document).on('click', '.ca-enviar', function () {
        var clave = $(this).data('clave'), boton = this;
        hanaConfirmar('¿Enviarlo ahora? Les llega de verdad a las personas, y hoy ya no se vuelve a enviar solo.', function () { enviarTarea(clave, boton); });
    });
});

//"9:00 a. m." / "5:00 p. m."
function horaTexto(hora) {
    var h = hora % 12 === 0 ? 12 : hora % 12;
    return h + ':00 ' + (hora < 12 ? 'a. m.' : 'p. m.');
}

function cargarTareas() {
    $.getJSON(URL_TAR + '?op=listar')
        .done(function (respuesta) {
            datosTar = respuesta;
            //Solo se muestra, cuando la hay, la última vez que el servidor hizo una tarea sola.
            //Los avisos de configuración del servidor (correo y cron) no se muestran en pantalla.
            var aviso = respuesta.ultimoProgramador
                ? '<p class="rd-ayuda">La última vez que el servidor hizo una tarea solo: ' + rdEsc(rdFecha(respuesta.ultimoProgramador)) + ' a las ' + rdEsc(String(respuesta.ultimoProgramador).substring(11, 16)) + '.</p>'
                : '';
            $('#caAvisoCron').html(aviso);
            var html = '';
            respuesta.tareas.forEach(function (tarea) { html += tarjetaTarea(tarea); });
            $('#caTareas').html(html);
        })
        .fail(function (xhr) { $('#caTareas').html(''); hanaErrorAjax(xhr, 'No se pudieron cargar los correos automáticos.'); });
}

//Una tarjeta por tarea, con solo los campos que tiene
function tarjetaTarea(tarea) {
    var c = tarea.config, clave = rdEsc(tarea.clave), campos = tarea.campos, html = '';
    var ultima = tarea.ultima ? 'Última vez: ' + rdFecha(tarea.ultima.FECHA) + ' ' + String(tarea.ultima.FECHA).substring(11, 16) + ' · ' + rdEsc(tarea.ultima.DETALLE) + ' · ' + rdEsc(tarea.ultima.QUIEN)
                              : (tarea.entrega === 'CORREO' ? 'Todavía no se ha enviado.' : '');
    html += '<div class="ca-tarjeta" id="ca-' + clave + '" style="border:1px solid #ECE6E1;border-radius:10px;padding:12px 14px;margin-bottom:14px;">' +
            '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;justify-content:space-between;">' +
            '<strong style="font-size:15px;">' + (tarea.entrega === 'CAMPANA' ? '<i class="fa fa-bell"></i> ' : '<i class="fa fa-envelope"></i> ') + rdEsc(tarea.nombre) + '</strong>' +
            '<label style="font-weight:normal;margin:0;"><input type="checkbox" class="ca-activo"' + (c.activo ? ' checked' : '') + '> Encendido</label></div>' +
            '<p class="rd-ayuda" style="margin:4px 0 10px;">' + rdEsc(tarea.descripcion) + '</p><div class="row">';
    if (campos.indexOf('momento') !== -1) {
        html += '<div class="form-group col-md-4 col-sm-6 col-xs-12"><label>Cuándo aparece:</label><select class="form-control ca-momento">' +
                '<option value="INMEDIATO"' + (c.momento !== 'SIGUIENTE_DIA' ? ' selected' : '') + '>En cuanto se registra</option>' +
                '<option value="SIGUIENTE_DIA"' + (c.momento === 'SIGUIENTE_DIA' ? ' selected' : '') + '>Al día siguiente, desde una hora</option></select></div>';
    }
    if (campos.indexOf('hora') !== -1) {
        var ocultaHora = campos.indexOf('momento') !== -1 && c.momento !== 'SIGUIENTE_DIA';
        html += '<div class="form-group col-md-3 col-sm-4 col-xs-12 ca-grupo-hora"' + (ocultaHora ? ' style="display:none;"' : '') + '><label>' + (tarea.entrega === 'CAMPANA' ? 'Aparece desde:' : 'Hora:') + '</label><select class="form-control ca-hora">';
        for (var h = 0; h < 24; h++) { html += '<option value="' + h + '"' + (h === +c.hora ? ' selected' : '') + '>' + horaTexto(h) + '</option>'; }
        html += '</select></div>';
    }
    if (campos.indexOf('dias') !== -1) {
        html += '<div class="form-group col-md-9 col-sm-8 col-xs-12"><label>Días:</label><div>';
        DIAS_TAR.forEach(function (d) {
            html += '<label title="' + d[2] + '" style="font-weight:normal;margin:0 10px 0 0;"><input type="checkbox" class="ca-dia" value="' + d[0] + '"' + (c.dias.indexOf(d[0]) !== -1 ? ' checked' : '') + '> ' + d[1] + '</label>';
        });
        html += '</div></div>';
    }
    html += '</div>';
    if (campos.indexOf('items') !== -1) {
        html += '<div class="form-group"><label>Qué revisa:</label><div>';
        $.each(tarea.items, function (codigo, nombre) {
            html += '<label style="font-weight:normal;margin:0 14px 4px 0;display:inline-block;"><input type="checkbox" class="ca-item" value="' + rdEsc(codigo) + '"' + (c.items.indexOf(codigo) !== -1 ? ' checked' : '') + '> ' + rdEsc(nombre) + '</label>';
        });
        html += '</div></div>';
    }
    if (campos.indexOf('coordinador') !== -1) {
        html += '<div class="form-group"><label style="font-weight:normal;"><input type="checkbox" class="ca-coordinador"' + (c.coordinador ? ' checked' : '') + '> Al coordinador de cada proyecto (solo sus peajes)</label></div>';
    }
    if (campos.indexOf('roles') !== -1) {
        var ayudaRoles = tarea.clave.indexOf('RECORDATORIO') === 0 ? ' <span class="rd-ayuda">(si no marcas ningún rol, no le llega a nadie)</span>'
                       : (tarea.entrega === 'CAMPANA' ? ' <span class="rd-ayuda">(todos los proyectos)</span>' : '');
        html += '<div class="form-group"><label>' + (tarea.entrega === 'CAMPANA' ? 'Lo ven estos roles (cargos):' : 'A quién le llega (roles):') + '</label>' + ayudaRoles + '<div>';
        datosTar.roles.forEach(function (rol) {
            html += '<label style="font-weight:normal;margin:0 14px 4px 0;display:inline-block;"><input type="checkbox" class="ca-rol" value="' + parseInt(rol.ID, 10) + '"' + (c.roles.indexOf(+rol.ID) !== -1 ? ' checked' : '') + '> ' + rdEsc(rol.NOMBRE) + '</label>';
        });
        html += '</div></div>';
    }
    if (campos.indexOf('personas') !== -1) {
        var marcadas = (c.personas || []).length;
        html += '<div class="form-group"><label>' + (tarea.entrega === 'CAMPANA' ? 'También lo ven estas personas:' : 'Personas a las que les llega:') + '</label>' +
                ' <span class="rd-ayuda">(además de los roles marcados' + (marcadas ? ' · ' + marcadas + ' elegida' + (marcadas === 1 ? '' : 's') : '') + ')</span>' +
                '<input type="text" class="form-control input-sm ca-buscar-persona" placeholder="Buscar por nombre o rol" style="max-width:320px;margin:4px 0 6px;">' +
                '<div style="max-height:180px;overflow:auto;border:1px solid #ECE6E1;border-radius:8px;padding:6px 10px;">';
        datosTar.personas.forEach(function (persona) {
            var elegida = (c.personas || []).indexOf(+persona.ID) !== -1;
            html += '<label class="ca-persona-fila" style="display:block;font-weight:normal;margin:2px 0;"><input type="checkbox" class="ca-persona" value="' + parseInt(persona.ID, 10) + '"' + (elegida ? ' checked' : '') + '> ' +
                    rdEsc(persona.NOMBRE) + ' <span class="rd-ayuda">· ' + rdEsc(persona.ROL) + (persona.SIN_CORREO ? ' · sin correo registrado' : '') + '</span></label>';
        });
        html += '</div></div>';
    }
    if (campos.indexOf('correo') !== -1) {
        html += '<div class="form-group"><label style="font-weight:normal;"><input type="checkbox" class="ca-correo"' + (c.correo ? ' checked' : '') + '> También por correo, en el momento en que se registra</label></div>';
    }
    if (campos.indexOf('correos') !== -1) {
        html += '<div class="form-group"><label>Correos que también lo reciben:</label><input type="text" class="form-control ca-correos" maxlength="500" placeholder="correo@empresa.com, otro@empresa.com" value="' + rdEsc(c.correos || '') + '"></div>';
    }
    if (campos.indexOf('nota') !== -1) {
        html += '<div class="form-group"><label>Nota que va al final del correo (opcional):</label><textarea class="form-control ca-nota" rows="2" maxlength="500">' + rdEsc(c.nota || '') + '</textarea></div>';
    }
    html += '<div class="rd-acciones" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">' +
            '<button type="button" class="btn btn-success btn-sm ca-guardar" data-clave="' + clave + '"><i class="fa fa-save"></i> Guardar</button>' +
            '<button type="button" class="btn btn-default btn-sm ca-simular" data-clave="' + clave + '"><i class="fa fa-eye"></i> Ver a quién le llegaría</button>' +
            (tarea.entrega === 'CORREO' ? '<button type="button" class="btn btn-default btn-sm ca-enviar" data-clave="' + clave + '"><i class="fa fa-paper-plane"></i> Enviar ahora</button>' : '') +
            '<span class="rd-ayuda">' + ultima + '</span></div>' +
            '<div class="ca-resultado" style="display:none;margin-top:10px;"></div></div>';
    return html;
}

//Lo que hay en la tarjeta, listo para guardar
function configDeTarjeta(clave) {
    var caja = $('#ca-' + clave), config = { activo: caja.find('.ca-activo').is(':checked') ? 1 : 0 };
    if (caja.find('.ca-hora').length) { config.hora = +caja.find('.ca-hora').val(); }
    if (caja.find('.ca-dia').length) { config.dias = caja.find('.ca-dia:checked').map(function () { return +this.value; }).get(); }
    if (caja.find('.ca-item').length) { config.items = caja.find('.ca-item:checked').map(function () { return this.value; }).get(); }
    if (caja.find('.ca-rol').length) { config.roles = caja.find('.ca-rol:checked').map(function () { return +this.value; }).get(); }
    if (caja.find('.ca-coordinador').length) { config.coordinador = caja.find('.ca-coordinador').is(':checked') ? 1 : 0; }
    if (caja.find('.ca-persona').length) { config.personas = caja.find('.ca-persona:checked').map(function () { return +this.value; }).get(); }
    if (caja.find('.ca-momento').length) { config.momento = caja.find('.ca-momento').val(); }
    if (caja.find('.ca-correo').length) { config.correo = caja.find('.ca-correo').is(':checked') ? 1 : 0; }
    if (caja.find('.ca-correos').length) { config.correos = caja.find('.ca-correos').val(); }
    if (caja.find('.ca-nota').length) { config.nota = caja.find('.ca-nota').val(); }
    return config;
}

function guardarTarea(clave, boton) {
    hanaBoton(boton, true);
    $.post(URL_TAR + '?op=guardar', { clave: clave, config: JSON.stringify(configDeTarjeta(clave)) }, null, 'json')
        .done(function (respuesta) { rdAviso(respuesta.mensaje); cargarTareas(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar.')); hanaBoton(boton, false); });
}

//Muestra las líneas que respondió el servidor debajo de la tarjeta
function mostrarLineas(clave, lineas) {
    $('#ca-' + clave + ' .ca-resultado').html('<div style="background:#FBF8F6;border-radius:8px;padding:10px 12px;font-size:13px;max-height:260px;overflow:auto;">' +
        lineas.map(function (linea) { return '<div>' + rdEsc(linea) + '</div>'; }).join('') + '</div>').show();
}

function simularTarea(clave, boton) {
    hanaBoton(boton, true, 'Revisando...');
    $.post(URL_TAR + '?op=simular', { clave: clave }, null, 'json')
        .done(function (respuesta) { mostrarLineas(clave, respuesta.lineas || []); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo revisar.')); })
        .always(function () { hanaBoton(boton, false); });
}

function enviarTarea(clave, boton) {
    hanaBoton(boton, true, 'Enviando...');
    $.post(URL_TAR + '?op=ejecutar', { clave: clave }, null, 'json')
        .done(function (respuesta) {
            rdAviso(respuesta.mensaje, respuesta.ok ? '' : 'error');
            cargarTareas();
            setTimeout(function () { mostrarLineas(clave, respuesta.lineas || []); }, 400);
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo enviar.')); hanaBoton(boton, false); });
}
