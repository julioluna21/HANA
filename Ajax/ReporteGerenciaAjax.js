//Consultas - Reporte diario a la Gerencia
var URL_GER = '../Control/ReporteGerenciaControl.php';
var cfgG = null, repG = null;

$(function () {
    $.getJSON(URL_GER + '?op=config')
        .done(function (config) {
            cfgG = config;
            $('#gFecha').attr('max', config.hoy).val(config.hoy);
            abrirDia(config.hoy);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir el reporte a la Gerencia.'); });

    $('#gFecha').on('change', function () { if (this.value) { cambiarDia(this.value); } });
    $('#btnAntes').on('click', function () { cambiarDia(rdSumarDias($('#gFecha').val(), -1)); });
    $('#btnDespues').on('click', function () { var dia = rdSumarDias($('#gFecha').val(), 1); if (dia <= cfgG.hoy) { cambiarDia(dia); } });
    $('#btnGuardar, #btnGuardar2').on('click', guardar);
    $('#btnTraerTodo').on('click', function () {
        hanaConfirmar('¿Volver a llenar todos los puntos con lo que hay en el sistema? Se reemplaza lo que escribiste (lo guardado no cambia hasta que guardes).', function () {
            $('#gProyectos textarea').each(function () { traerPunto($(this)); });
        }, { aceptar: 'Sí, traer todo', cancelar: 'No' });
    });
    $('#btnCopiar').on('click', copiar);
    $('#btnPdf').on('click', abrirPdf);
    window.addEventListener('beforeprint', armarImpresion);
    $(document).on('click', '.ger-traer', function () { traerPunto($(this).closest('.ger-punto').find('textarea')); });
    $(document).on('click', '.ger-guardados a', function (evento) { evento.preventDefault(); cambiarDia($(this).data('dia')); });
    $(document).on('input', '#gProyectos textarea', function () { rdHayCambios = true; ajustarAlto(this); marcarCambio($(this)); });
    window.addEventListener('beforeunload', function (evento) { if (rdHayCambios) { evento.preventDefault(); evento.returnValue = ''; } });
});

//Cambia de día, avisando si hay cambios sin guardar
function cambiarDia(dia) {
    if (rdHayCambios && !window.confirm('El reporte tiene cambios sin guardar. ¿Cambiar de día y perderlos?')) { $('#gFecha').val(repG ? repG.fecha : cfgG.hoy); return; }
    $('#gFecha').val(dia);
    abrirDia(dia);
}

//Trae el reporte de un día y lo pinta
function abrirDia(dia) {
    $('#gEstado').removeClass('ok').text('Cargando...');
    $.getJSON(URL_GER + '?op=abrir&fecha=' + encodeURIComponent(dia))
        .done(function (reporte) {
            repG = reporte; rdHayCambios = false;
            $('#btnDespues').prop('disabled', reporte.fecha >= cfgG.hoy);
            var html = '';
            reporte.proyectos.forEach(function (proyecto) {
                html += '<div class="ger-proyecto" data-proyecto="' + parseInt(proyecto.id, 10) + '"><h3>' + rdEsc(proyecto.nombre) + '</h3>';
                var numero = 0;
                $.each(reporte.puntos, function (codigo, titulo) {
                    numero++;
                    var automatico = cfgG.automaticos.indexOf(codigo) >= 0, id = 'p' + proyecto.id + '_' + codigo;
                    html += '<div class="ger-punto"><div class="ger-punto-cab"><label for="' + rdEsc(id) + '">' + numero + '. ' + rdEsc(titulo) + '</label>' +
                            (automatico ? '<a class="ger-traer" title="Vuelve a llenar este punto con lo que hay en el sistema"><i class="fa fa-refresh"></i> Traer del sistema</a>' : '') + '</div>' +
                            '<textarea class="form-control" id="' + rdEsc(id) + '" data-codigo="' + rdEsc(codigo) + '" rows="2" maxlength="4000"></textarea></div>';
                });
                html += '</div>';
            });
            $('#gProyectos').html(html || '<p class="rd-vacio">No tienes proyectos asignados.</p>');
            //Los textos se ponen con .val() (no van dentro del HTML)
            reporte.proyectos.forEach(function (proyecto) {
                $.each(proyecto.textos, function (codigo, texto) {
                    var campo = $('#p' + proyecto.id + '_' + codigo);
                    campo.val(texto).data('sistema', proyecto.sistema[codigo] || '');
                    ajustarAlto(campo[0]); marcarCambio(campo);
                });
            });
            pintarEstado();
        })
        .fail(function (xhr) { $('#gEstado').text(rdError(xhr, 'No se pudo cargar el reporte.')); });
}

//Lo que dice la barra de estado: guardado o propuesto por el sistema
function pintarEstado() {
    var g = repG.guardado;
    if (g) {
        $('#gEstado').addClass('ok').html('<i class="fa fa-check-circle"></i> Reporte del <strong>' + rdEsc(repG.diaCorto) + '</strong> guardado por ' + rdEsc(g.quien || '') +
            ' el ' + rdFecha(g.fecha) + ' a las ' + rdHora(String(g.fecha).substring(11)) + (g.versiones > 1 ? ' (se ha guardado ' + g.versiones + ' veces)' : '') + '.');
    } else {
        $('#gEstado').removeClass('ok').html('<i class="fa fa-info-circle"></i> El reporte del <strong>' + rdEsc(repG.diaCorto) + '</strong> todavía no se ha guardado: lo que ves lo propone el sistema.');
    }
    var dias = (cfgG.diasGuardados || []).slice(0, 14), html = '';
    dias.forEach(function (dia) { html += '<a href="#" data-dia="' + rdEsc(dia) + '"' + (dia === repG.fecha ? ' class="actual"' : '') + '>' + rdFecha(dia).substring(0, 5) + '</a>'; });
    $('#gGuardados').html(html ? 'Días guardados: ' + html : '');
}

//Vuelve a poner en un punto lo que propone el sistema
function traerPunto(campo) {
    var sistema = campo.data('sistema');
    if (sistema === undefined) { return; }
    if (campo.val() !== sistema) { campo.val(sistema); rdHayCambios = true; }
    ajustarAlto(campo[0]); marcarCambio(campo);
}

//Resalta los puntos que ya no dicen lo mismo que propone el sistema (se corrigieron a mano)
function marcarCambio(campo) {
    var sistema = campo.data('sistema');
    campo.toggleClass('cambiado', sistema !== undefined && sistema !== '' && $.trim(campo.val()) !== $.trim(sistema))
         .attr('title', campo.hasClass('cambiado') ? 'Corregido a mano: no es lo mismo que propone el sistema' : '');
}

//El alto del cuadro se ajusta al texto
function ajustarAlto(campo) {
    if (!campo) { return; }
    campo.style.height = 'auto';
    campo.style.height = Math.min(Math.max(campo.scrollHeight + 2, 54), 600) + 'px';
}

//Lo escrito, por proyecto y punto
function leerTextos() {
    var textos = {};
    $('#gProyectos .ger-proyecto').each(function () {
        var id = $(this).data('proyecto'), puntos = {};
        $(this).find('textarea').each(function () { puntos[$(this).data('codigo')] = $(this).val(); });
        textos[id] = puntos;
    });
    return textos;
}

//Guarda el reporte del día
function guardar() {
    if (!repG) { return; }
    hanaBoton('#btnGuardar', true); hanaBoton('#btnGuardar2', true);
    $.post(URL_GER + '?op=guardar', { reporte: JSON.stringify({ fecha: repG.fecha, textos: leerTextos() }) }, null, 'json')
        .done(function (respuesta) {
            rdHayCambios = false; rdAviso(respuesta.mensaje);
            repG.guardado = { quien: 'ti', fecha: respuesta.fecha, versiones: repG.guardado ? repG.guardado.versiones + 1 : 1 };
            if ((cfgG.diasGuardados || []).indexOf(repG.fecha) < 0) { cfgG.diasGuardados.push(repG.fecha); cfgG.diasGuardados.sort().reverse(); }
            pintarEstado();
        })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el reporte.')); })
        .always(function () { hanaBoton('#btnGuardar', false); hanaBoton('#btnGuardar2', false); });
}

//El reporte como texto plano (para un correo o WhatsApp)
function comoTexto() {
    var lineas = ['REPORTE DIARIO A LA GERENCIA', rdFecha(repG.fecha) + ' (' + repG.diaCorto.split(' ')[0] + ')', ''];
    var textos = leerTextos();
    repG.proyectos.forEach(function (proyecto) {
        lineas.push('*' + proyecto.nombre.toUpperCase() + '*');
        var numero = 0;
        $.each(repG.puntos, function (codigo, titulo) {
            numero++;
            var texto = $.trim((textos[proyecto.id] || {})[codigo] || '');
            lineas.push(numero + '. ' + titulo + ':');
            lineas.push(texto || '—');
        });
        lineas.push('');
    });
    return lineas.join('\n');
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

//Lo que sale en el papel: el reporte limpio, sin cuadros ni botones
function armarImpresion() {
    if (!repG) { return; }
    var textos = leerTextos();
    var html = '<h1>Reporte diario a la Gerencia</h1><div class="sub">' + rdEsc(repG.diaCorto.split(' ')[0]) + ' ' + rdFecha(repG.fecha) + '</div>';
    repG.proyectos.forEach(function (proyecto) {
        html += '<h2>' + rdEsc(proyecto.nombre) + '</h2>';
        var numero = 0;
        $.each(repG.puntos, function (codigo, titulo) {
            numero++;
            html += '<h4>' + numero + '. ' + rdEsc(titulo) + '</h4><p>' + rdEsc($.trim((textos[proyecto.id] || {})[codigo] || '') || '—') + '</p>';
        });
    });
    $('#gerImpresion').html(html);
}

//El PDF para la Gerencia (con colores, porcentajes y gráficas). Lleva lo que está en la pantalla en
//este momento, aunque no se haya guardado; se abre en otra pestaña, desde donde se descarga o se imprime
function abrirPdf() {
    if (!repG) { return; }
    var form = $('<form method="post" target="_blank" action="ReporteGerenciaPdfVista.php" style="display:none;"></form>');
    $('<input type="hidden" name="reporte">').val(JSON.stringify({ fecha: repG.fecha, textos: leerTextos() })).appendTo(form);
    form.appendTo('body').trigger('submit');
    setTimeout(function () { form.remove(); }, 1000);
}
