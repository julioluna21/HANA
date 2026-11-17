//Consultas - Trazabilidad
//Qué se registró, cambió, anuló o envió, dónde, quién y cuándo, con filtros.
//Los datos los da Control/TrazabilidadControl.php (op=config y op=buscar)
var URL_TZ = '../Control/TrazabilidadControl.php';
var cfgTz = null, tzPagina = 1, tzUltimo = null;

//Colores de cada tipo de cambio (las clases de reporte.css)
var TZ_COLOR = { REGISTRO: 'rd-tag-ok', MODIFICACION: 'rd-tag-pend', ESTADO: 'rd-tag-pend', SEGUIMIENTO: '', CIERRE: 'rd-tag-ok',
                 ANULACION: 'rd-tag-mal', CORREO: '', INGRESO: '', CONFIGURACION: 'rd-tag-pend' };

$(function () {
    $.getJSON(URL_TZ + '?op=config')
        .done(function (config) {
            cfgTz = config;
            var html = '<option value="">Todos los módulos</option>';
            $.each(config.modulos, function (codigo, nombre) { html += '<option value="' + rdEsc(codigo) + '">' + rdEsc(nombre) + '</option>'; });
            $('#tzModulo').html(html);
            html = '<option value="">Todos</option>';
            $.each(config.acciones, function (codigo, nombre) { html += '<option value="' + rdEsc(codigo) + '">' + rdEsc(nombre) + '</option>'; });
            $('#tzAccion').html(html);
            html = '<option value="0">Todos</option>';
            config.proyectos.forEach(function (proyecto) { html += '<option value="' + proyecto.id + '">' + rdEsc(proyecto.nombre) + '</option>'; });
            $('#tzProyecto').html(html);
            tzPintarCentros();
            html = '<option value="0">Cualquier persona</option>';
            config.personas.forEach(function (persona) { html += '<option value="' + persona.id + '">' + rdEsc(persona.nombre) + '</option>'; });
            $('#tzQuien').html(html);
            if ($.fn.select2) { $('#tzQuien').select2({ width: '100%' }); }
            //Por defecto: los últimos 7 días
            $('#tzHasta').val(config.hoy); $('#tzDesde').val(rdSumarDias(config.hoy, -6));
            tzBuscar(1);
        })
        .fail(function (xhr) { $('#tzFilas').html('<tr><td colspan="7" class="rd-vacio">' + rdEsc(rdError(xhr, 'No se pudo abrir la trazabilidad.')) + '</td></tr>'); });

    $('#tzProyecto').on('change', tzPintarCentros);
    $('#tzFiltros').on('submit', function (evento) { evento.preventDefault(); tzBuscar(1); });
    //Los selectores buscan solos al cambiar; el texto, al pulsar Buscar o Enter
    $('#tzModulo, #tzAccion, #tzProyecto, #tzCentro, #tzQuien, #tzDesde, #tzHasta').on('change', function () { tzBuscar(1); });
    $('#tzLimpiar').on('click', function () {
        $('#tzModulo, #tzAccion').val(''); $('#tzProyecto').val('0'); tzPintarCentros(); $('#tzQuien').val('0').trigger('change.select2'); $('#tzTexto').val('');
        $('#tzHasta').val(cfgTz.hoy); $('#tzDesde').val(rdSumarDias(cfgTz.hoy, -6)); tzBuscar(1);
    });
    $(document).on('click', '[data-tz-pagina]', function () { tzBuscar(parseInt($(this).data('tz-pagina'), 10)); });
    $('#tzCsv').on('click', tzDescargar);
});

//Los peajes del proyecto elegido (o todos)
function tzPintarCentros() {
    var proyecto = parseInt($('#tzProyecto').val(), 10) || 0, html = '<option value="0">Todos</option>';
    (cfgTz ? cfgTz.centros : []).forEach(function (centro) {
        if (!proyecto || centro.proyecto === proyecto) { html += '<option value="' + centro.id + '">' + rdEsc(centro.nombre) + '</option>'; }
    });
    $('#tzCentro').html(html);
}

function tzFiltros() {
    return { desde: $('#tzDesde').val(), hasta: $('#tzHasta').val(), modulo: $('#tzModulo').val() || '', accion: $('#tzAccion').val() || '',
             proyecto: $('#tzProyecto').val() || 0, centro: $('#tzCentro').val() || 0, quien: $('#tzQuien').val() || 0, texto: $.trim($('#tzTexto').val()) };
}

function tzBuscar(pagina) {
    var f = tzFiltros();
    if (!f.desde || !f.hasta) { return; }
    if (f.hasta < f.desde) { alert('El último día no puede ser antes del primero.'); return; }
    tzPagina = pagina || 1;
    $('#tzFilas').html('<tr><td colspan="7" class="rd-vacio"><i class="fa fa-spinner fa-spin"></i> Buscando...</td></tr>');
    $.getJSON(URL_TZ + '?op=buscar&' + $.param($.extend({ pagina: tzPagina }, f)))
        .done(function (respuesta) { tzUltimo = { filtros: f, respuesta: respuesta }; tzPintar(respuesta); })
        .fail(function (xhr) { $('#tzFilas').html('<tr><td colspan="7" class="rd-vacio">' + rdEsc(rdError(xhr, 'No se pudo buscar.')) + '</td></tr>'); });
}

function tzPintar(respuesta) {
    var html = '', porModulo = {};
    respuesta.filas.forEach(function (fila) {
        porModulo[fila.MODULO] = (porModulo[fila.MODULO] || 0) + 1;
        var accion = (cfgTz.acciones[fila.ACCION] ? '<span class="rd-tag ' + (TZ_COLOR[fila.ACCION] || '') + '">' + rdEsc(fila.TEXTO_ACCION) + '</span>' : rdEsc(fila.TEXTO_ACCION));
        html += '<tr><td class="tz-fecha" data-orden="' + rdEsc(fila.FECHA) + '">' + rdFecha(fila.FECHA) + ' ' + rdHora(String(fila.FECHA).substring(11)) + '</td>' +
                '<td>' + rdEsc(cfgTz.modulos[fila.MODULO] || fila.MODULO) + '</td>' +
                '<td class="tz-accion">' + accion + '</td>' +
                '<td>' + rdEsc(fila.PROYECTO || '') + '</td><td>' + rdEsc(fila.CENTRO || '') + '</td>' +
                '<td>' + (fila.QUIEN ? rdEsc(fila.QUIEN) : '<span class="tz-sin-quien">sin dato</span>') + '</td>' +
                '<td class="tz-detalle">' + rdEsc(fila.DETALLE || '') + '</td></tr>';
    });
    $('#tzFilas').html(html || '<tr><td colspan="7" class="rd-vacio">No hay movimientos con esos filtros.</td></tr>');
    $('#tzResumen').html('<div class="rd-contador rd-cnt-ok"><span class="rd-num">' + respuesta.total + '</span><span class="rd-lbl">Movimientos encontrados</span></div>');
    //Páginas (de a 300)
    var paginas = Math.max(1, Math.ceil(respuesta.total / respuesta.porPagina)), botones = '';
    if (paginas > 1) {
        botones = '<span class="rd-ayuda">Página ' + respuesta.pagina + ' de ' + paginas + '</span>' +
                  (respuesta.pagina > 1 ? '<button type="button" class="btn btn-default btn-sm" data-tz-pagina="' + (respuesta.pagina - 1) + '"><i class="fa fa-chevron-left"></i> Anteriores</button>' : '') +
                  (respuesta.pagina < paginas ? '<button type="button" class="btn btn-default btn-sm" data-tz-pagina="' + (respuesta.pagina + 1) + '">Siguientes <i class="fa fa-chevron-right"></i></button>' : '');
    }
    $('#tzPaginas').html(botones);
    $('#tzCsv').prop('disabled', !respuesta.filas.length);
    var f = tzUltimo.filtros;
    $('#tzTituloImprimir').text('Trazabilidad del ' + rdFecha(f.desde) + ' al ' + rdFecha(f.hasta) +
                                (f.modulo ? ' · ' + (cfgTz.modulos[f.modulo] || '') : '') + (f.texto ? ' · «' + f.texto + '»' : ''));
}

//Descarga lo que se está viendo (la página actual) como CSV para Excel
function tzDescargar() {
    if (!tzUltimo || !tzUltimo.respuesta.filas.length) { return; }
    var celda = function (valor) { return '"' + String(valor === null || valor === undefined ? '' : valor).replace(/"/g, '""') + '"'; };
    var lineas = [['Fecha', 'Módulo', 'Qué pasó', 'Proyecto', 'Peaje', 'Quién', 'Detalle'].map(celda).join(';')];
    tzUltimo.respuesta.filas.forEach(function (fila) {
        lineas.push([fila.FECHA, cfgTz.modulos[fila.MODULO] || fila.MODULO, fila.TEXTO_ACCION, fila.PROYECTO, fila.CENTRO, fila.QUIEN || 'sin dato', fila.DETALLE].map(celda).join(';'));
    });
    var archivo = new Blob(['﻿' + lineas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    var enlace = document.createElement('a');
    enlace.href = URL.createObjectURL(archivo);
    enlace.download = 'Trazabilidad_' + tzUltimo.filtros.desde + '_a_' + tzUltimo.filtros.hasta + '.csv';
    document.body.appendChild(enlace); enlace.click(); document.body.removeChild(enlace);
}
