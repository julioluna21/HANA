//Consultas - Reporte diario a la Gerencia
var URL_GER = '../Control/ReporteGerenciaControl.php';
var cfgG = null, repG = null;
//Días guardados: se muestran por mes. mesG es el mes que se está viendo en la lista y diasMesG sus días
var mesG = '', diasMesG = [];
var MESES_G = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
var DIAS_G = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

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
    $(document).on('click', '.ger-dias-mes a', function (evento) { evento.preventDefault(); cambiarDia($(this).data('dia')); });
    $('#gMes').on('change', function () { verMes(this.value); });
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
            //Al final, las notas adicionales: texto libre de todo el reporte (no son de un proyecto)
            html = (html || '<p class="rd-vacio">No tienes proyectos asignados.</p>') +
                '<div class="ger-otros"><h3>' + rdEsc(reporte.tituloOtros) + '</h3>' +
                '<p class="rd-ayuda">Lo que quieras agregar a lo reportado arriba: un proyecto que se está cerrando o que apenas empieza, un tema general, un aviso o un pendiente. Sale al final del PDF; si queda en blanco, no sale.</p>' +
                '<textarea class="form-control" id="gOtros" rows="3" maxlength="4000" placeholder="• Tema: lo que se quiere informar."></textarea></div>';
            $('#gProyectos').html(html);
            $('#gOtros').val(reporte.otros || '');
            ajustarAlto($('#gOtros')[0]);
            //Los textos se ponen con .val() (no van dentro del HTML)
            reporte.proyectos.forEach(function (proyecto) {
                $.each(proyecto.textos, function (codigo, texto) {
                    var campo = $('#p' + proyecto.id + '_' + codigo);
                    campo.val(texto).data('sistema', proyecto.sistema[codigo] || '');
                    ajustarAlto(campo[0]); marcarCambio(campo);
                });
            });
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
        $('#gEstado').addClass('ok').html('<i class="fa fa-check-circle"></i> Reporte del <strong>' + rdEsc(repG.diaCorto) + '</strong> guardado por ' + rdEsc(g.quien || '') +
            ' el ' + rdFecha(g.fecha) + ' a las ' + rdHora(String(g.fecha).substring(11)) + '.');
    } else {
        $('#gEstado').removeClass('ok').html('<i class="fa fa-info-circle"></i> El reporte del <strong>' + rdEsc(repG.diaCorto) + '</strong> todavía no se ha guardado: lo que ves lo propone el sistema.');
    }
}

//"2026-11" -> "noviembre 2026"
function nombreMes(mes) { return MESES_G[parseInt(mes.substring(5, 7), 10) - 1] + ' ' + mes.substring(0, 4); }

//La lista de meses (los que tienen días guardados, más el de hoy y el que se está viendo) y,
//al lado, los días guardados del mes elegido, como botoncitos "mié 4"
function pintarGuardados() {
    var meses = (cfgG.meses || []).slice();
    [cfgG.hoy.substring(0, 7), mesG].forEach(function (mes) { if (mes && meses.indexOf(mes) < 0) { meses.push(mes); } });
    meses.sort().reverse();
    $('#gMes').html(meses.map(function (mes) {
        return '<option value="' + rdEsc(mes) + '"' + (mes === mesG ? ' selected' : '') + '>' + rdEsc(nombreMes(mes)) + '</option>';
    }).join(''));
    var html = diasMesG.map(function (dia) {
        var fecha = rdAFecha(dia);
        return '<a href="#" data-dia="' + rdEsc(dia) + '"' + (repG && dia === repG.fecha ? ' class="actual"' : '') + ' title="' + rdEsc(rdFecha(dia)) + '">' +
               DIAS_G[fecha.getDay()] + ' ' + fecha.getDate() + '</a>';
    }).join('');
    $('#gDiasMes').html(html || '<span class="vacio">Ningún día guardado en este mes.</span>');
}

//Trae los días guardados de otro mes (el día abierto no cambia hasta que se elija uno)
function verMes(mes) {
    $.getJSON(URL_GER + '?op=guardados&mes=' + encodeURIComponent(mes))
        .done(function (respuesta) { mesG = respuesta.mes; diasMesG = respuesta.dias || []; pintarGuardados(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudieron traer los días guardados de ese mes.')); });
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

//Lo escrito en las notas adicionales
function leerOtros() { return $('#gOtros').length ? $('#gOtros').val() : ''; }

//Guarda el reporte del día
function guardar() {
    if (!repG) { return; }
    hanaBoton('#btnGuardar', true); hanaBoton('#btnGuardar2', true);
    $.post(URL_GER + '?op=guardar', { reporte: JSON.stringify({ fecha: repG.fecha, textos: leerTextos(), otros: leerOtros() }) }, null, 'json')
        .done(function (respuesta) {
            rdHayCambios = false; rdAviso(respuesta.mensaje);
            repG.guardado = { quien: 'ti', fecha: respuesta.fecha };
            //El día recién guardado entra a la lista de su mes (y el mes, a la lista de meses)
            if ((cfgG.meses || []).indexOf(repG.mes) < 0) { cfgG.meses = (cfgG.meses || []).concat([repG.mes]); }
            if (mesG === repG.mes && diasMesG.indexOf(repG.fecha) < 0) { diasMesG.push(repG.fecha); diasMesG.sort(); }
            pintarEstado(); pintarGuardados();
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
    var otros = $.trim(leerOtros());
    if (otros) { lineas.push('*' + repG.tituloOtros.toUpperCase() + '*', otros, ''); }
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
    var otros = $.trim(leerOtros());
    if (otros) { html += '<h2>' + rdEsc(repG.tituloOtros) + '</h2><p>' + rdEsc(otros) + '</p>'; }
    $('#gerImpresion').html(html);
}

//El PDF para la Gerencia (con colores, porcentajes y gráficas). Lleva lo que está en la pantalla en
//este momento, aunque no se haya guardado; se abre en otra pestaña, desde donde se descarga o se imprime
function abrirPdf() {
    if (!repG) { return; }
    var form = $('<form method="post" target="_blank" action="ReporteGerenciaPdfVista.php" style="display:none;"></form>');
    $('<input type="hidden" name="reporte">').val(JSON.stringify({ fecha: repG.fecha, textos: leerTextos(), otros: leerOtros() })).appendTo(form);
    form.appendTo('body').trigger('submit');
    setTimeout(function () { form.remove(); }, 1000);
}
