//Reporte diario — "Hoy en qué estás"
//Carga un día, lo guarda y muestra el mes con los días que faltan.
//Solo se registra HOY; otro día, únicamente si el administrador lo habilitó.
//Todo texto de la base se escapa al pintarlo

var URL_HOY = '../Control/HoyControl.php';
var cfgHoy = { hoy: '', primerEditable: '', ultimoEditable: '', habilitados: [], situaciones: {}, centros: [] };
var diaHoy = '';          //el día que se está viendo (AAAA-MM-DD)
var editableHoy = false;  //si ese día todavía se puede modificar
var ultimoDiaHoy = '';    //hasta qué día se puede avanzar: hoy, o un día habilitado que esté más adelante

var NOMBRES_DIA = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

//---------------------------------------------------------------------------
// Utilidades
//---------------------------------------------------------------------------
function esc(t) { return $('<div>').text(t == null ? '' : String(t)).html(); }
function dos(n) { return ('0' + n).slice(-2); }

//"2026-09-07" -> Date local (sin el desfase de UTC)
function aFecha(txt) { var p = txt.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
function aTexto(d) { return d.getFullYear() + '-' + dos(d.getMonth() + 1) + '-' + dos(d.getDate()); }
function sumarDias(txt, n) { var d = aFecha(txt); d.setDate(d.getDate() + n); return aTexto(d); }

//"07:40:00" -> "07:40"
function hhmm(t) { return t ? String(t).substring(0, 5) : ''; }

//"2026-09-07" -> "Lun 07/09"
function diaCorto(txt) {
    var d = aFecha(txt);
    return NOMBRES_DIA[d.getDay()] + ' ' + dos(d.getDate()) + '/' + dos(d.getMonth() + 1);
}

//Un texto largo, recortado para que quepa en una celda de la tabla
function recortar(t, max) {
    t = String(t == null ? '' : t).replace(/\s+/g, ' ');
    return t.length > max ? t.substring(0, max - 1) + '…' : t;
}

function mensajeError(xhr, base) {
    try { return JSON.parse(xhr.responseText).error || base; } catch (e) { return base; }
}

//---------------------------------------------------------------------------
// Inicio
//---------------------------------------------------------------------------
//Cambia de día, avisando si hay algo escrito sin guardar
function irADia(fecha) {
    if (rdHayCambios && !window.confirm('Tienes cambios sin guardar en este día. ¿Quieres cambiar de día y perderlos?')) {
        $('#hoyFecha').val(diaHoy);
        return;
    }
    cargarDia(fecha);
}

$(function () {
    rdAlertaDia('hoy', '#alertaDia');
    rdVigilarCambios('#formHoy');
    //Botones rápidos: "Hoy" y uno por cada día que el administrador habilitó
    $(document).on('click', '#hoyRapido [data-fecha]', function () {
        var f = $(this).data('fecha');
        irADia(f === 'hoy' ? cfgHoy.hoy : f);
    });

    $.getJSON(URL_HOY + '?op=config')
        .done(function (c) {
            cfgHoy = c;
            pintarSituaciones();
            pintarCentros();

            //Año y mes de "Mi mes": del año pasado al actual
            var anioHoy = +c.hoy.substring(0, 4), h = '';
            for (var a = anioHoy; a >= anioHoy - 1; a--) { h += '<option value="' + a + '">' + a + '</option>'; }
            $('#mesAnio').html(h).val(String(anioHoy));
            $('#mesMes').val(String(+c.hoy.substring(5, 7)));

            //Lo normal es que el último día sea hoy; si hay un día habilitado más adelante, ese
            c.habilitados = c.habilitados || [];
            ultimoDiaHoy = c.hoy;
            c.habilitados.forEach(function (f) { if (f > ultimoDiaHoy) { ultimoDiaHoy = f; } });
            $('#hoyFecha').attr('max', ultimoDiaHoy);
            pintarHabilitados();

            //Se puede llegar con ?fecha=AAAA-MM-DD (desde "Mi mes" o el tablero)
            var pedida = new URLSearchParams(window.location.search).get('fecha');
            cargarDia(/^\d{4}-\d{2}-\d{2}$/.test(pedida || '') && pedida <= ultimoDiaHoy ? pedida : c.hoy);
            cargarMes();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir el reporte.'); });

    //Cambiar de día
    $('#hoyFecha').on('change', function () { if (this.value) { irADia(this.value); } });
    $('#btnDiaAnterior').on('click', function () { irADia(sumarDias(diaHoy, -1)); });
    $('#btnDiaSiguiente').on('click', function () { if (diaHoy < ultimoDiaHoy) { irADia(sumarDias(diaHoy, 1)); } });

    //Situación: laboral muestra lugares, horario y qué hizo
    $(document).on('change', 'input[name="situacion"]', mostrarLaboral);

    $('#formHoy').on('submit', guardarDia);

    $('#mesMes, #mesAnio').on('change', cargarMes);
    $(document).on('click', '.hoy-fila-mes', function () {
        irADia($(this).data('fecha'));
        $('html, body').animate({ scrollTop: 0 }, 200);
    });
});

//---------------------------------------------------------------------------
// Situaciones y centros
//---------------------------------------------------------------------------
function pintarSituaciones() {
    var h = '';
    $.each(cfgHoy.situaciones, function (codigo, nombre) {
        h += '<label class="hoy-sit hoy-sit-' + esc(codigo.toLowerCase()) + '">' +
             '<input type="radio" name="situacion" value="' + esc(codigo) + '"> <span>' + esc(nombre) + '</span></label>';
    });
    $('#hoySituaciones').html(h);
}

//Los centros asignados, agrupados por proyecto, como casillas grandes
function pintarCentros() {
    var c = cfgHoy.centros || [];
    if (!c.length) {
        $('#hoyCentros').html('<p class="rd-ayuda">Tus proyectos no tienen peajes activos. Puedes escribir el lugar en "Otro lugar".</p>');
        return;
    }
    var h = '', grupo = null;
    for (var i = 0; i < c.length; i++) {
        if (c[i].NOM_PROYECTO !== grupo) {
            if (grupo !== null) { h += '</div>'; }
            grupo = c[i].NOM_PROYECTO;
            h += '<div class="hoy-grupo"><span class="hoy-grupo-nom">' + esc(grupo) + '</span>';
        }
        var tipo = (c[i].TIPO_CENTRO || 'PEAJE');
        h += '<label class="hoy-chip"><input type="checkbox" name="centros[]" value="' + parseInt(c[i].ID_CENTRO_OP, 10) + '">' +
             '<span>' + (tipo === 'BASCULA' ? '<i class="fa fa-balance-scale"></i> ' : '') + esc(c[i].NOM_CENTRO_OP) + '</span></label>';
    }
    h += '</div>';
    $('#hoyCentros').html(h);
}

function mostrarLaboral() {
    var laboral = $('input[name="situacion"]:checked').val() === 'LABORAL';
    $('#hoyLaboral').toggle(laboral);
}

//---------------------------------------------------------------------------
// Días habilitados: un botón por cada día que el administrador le abrió
//---------------------------------------------------------------------------
function pintarHabilitados() {
    $('#hoyRapido [data-habilitado]').remove(); //se vuelven a pintar desde cero
    var h = '';
    (cfgHoy.habilitados || []).forEach(function (f) {
        h += '<button type="button" class="btn btn-default btn-sm" data-habilitado="1" data-fecha="' + esc(f) + '" title="El administrador te habilitó este día">' +
             '<i class="fa fa-unlock"></i> ' + esc(diaCorto(f)) + '</button>';
    });
    $('#hoyRapido').append(h);
}

//---------------------------------------------------------------------------
// Cargar un día
//---------------------------------------------------------------------------
function cargarDia(fecha) {
    $.getJSON(URL_HOY + '?op=obtener&fecha=' + encodeURIComponent(fecha))
        .done(function (d) {
            diaHoy = d.fecha;
            editableHoy = !!d.editable;
            var r = d.registro;

            $('#hoyFecha').val(diaHoy);
            $('#btnDiaSiguiente').prop('disabled', diaHoy >= ultimoDiaHoy);
            $('#hoyEstado').html(r
                ? '<span class="rd-tag rd-tag-ok"><i class="fa fa-check"></i> Registrado</span>'
                : '<span class="rd-tag rd-tag-pend"><i class="fa fa-clock-o"></i> Sin registrar</span>');
            $('#hoyConsulta').toggle(!editableHoy);

            //Llenar el formulario
            $('#formHoy')[0].reset();
            $('input[name="situacion"][value="' + (r ? r.SITUACION : 'LABORAL') + '"]').prop('checked', true);
            $('#hoyIngreso').val(r ? hhmm(r.HORA_INGRESO) : '');
            $('#hoySalida').val(r ? hhmm(r.HORA_SALIDA) : '');
            $('#hoyLugarOtro').val(r && r.LUGAR_OTRO ? r.LUGAR_OTRO : '');
            $('#hoyActividad').val(r && r.ACTIVIDAD ? r.ACTIVIDAD : ''); //qué hizo en el día
            $('#hoyObservacion').val(r && r.OBSERVACION ? r.OBSERVACION : '');
            $('input[name="centros[]"]').prop('checked', false);
            if (r) {
                for (var i = 0; i < r.CENTROS.length; i++) {
                    $('input[name="centros[]"][value="' + parseInt(r.CENTROS[i], 10) + '"]').prop('checked', true);
                }
            }
            //Solo consulta: todo queda bloqueado y sin botón de guardar
            $('#formHoy').find('input, textarea').prop('disabled', !editableHoy);
            $('#btnGuardarHoy').toggle(editableHoy);

            mostrarLaboral();
            $('.hoy-fila-mes').removeClass('activa').filter('[data-fecha="' + diaHoy + '"]').addClass('activa');
            //Hoy o un día habilitado: marca el botón del día que se está viendo
            $('#hoyRapido [data-fecha]').removeClass('active').each(function () {
                var f = $(this).data('fecha');
                if ((f === 'hoy' ? cfgHoy.hoy : f) === diaHoy) { $(this).addClass('active'); }
            });
            rdCambiosGuardados(); //acaba de cargarse: todavía no hay cambios
        })
        .fail(function (xhr) { alert(mensajeError(xhr, 'No se pudo cargar el día.')); });
}

//---------------------------------------------------------------------------
// Guardar
//---------------------------------------------------------------------------
function guardarDia(e) {
    e.preventDefault();
    var sit = $('input[name="situacion"]:checked').val();
    if (!sit) { alert('Elige la situación del día.'); return; }
    if (sit === 'LABORAL') {
        if (!$('#hoyIngreso').val()) { alert('Escribe la hora de ingreso.'); $('#hoyIngreso').focus(); return; }
        if (!$('input[name="centros[]"]:checked').length && !$.trim($('#hoyLugarOtro').val())) {
            alert('Marca al menos un peaje o escribe en qué otro lugar estuviste.'); return;
        }
        if (!$.trim($('#hoyActividad').val())) { alert('Escribe qué hiciste hoy.'); $('#hoyActividad').focus(); return; }
    }

    var fd = new FormData($('#formHoy')[0]);
    fd.append('fecha', diaHoy);
    hanaBoton('#btnGuardarHoy', true);
    $.ajax({ url: URL_HOY + '?op=guardar', type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json' })
        .done(function (r) {
            rdCambiosGuardados();
            rdAviso(r.mensaje);
            cargarDia(diaHoy);
            cargarMes();
        })
        .fail(function (xhr) { alert(mensajeError(xhr, 'No se pudo guardar el día.')); })
        .always(function () { hanaBoton('#btnGuardarHoy', false); });
}

//---------------------------------------------------------------------------
// Mi mes: un renglón por día, con los que faltan marcados
//---------------------------------------------------------------------------
function cargarMes() {
    var anio = +$('#mesAnio').val(), mes = +$('#mesMes').val();
    $.getJSON(URL_HOY + '?op=mes&anio=' + anio + '&mes=' + mes)
        .done(function (d) {
            var porDia = {};
            for (var i = 0; i < d.filas.length; i++) { porDia[d.filas[i].FECHA] = d.filas[i]; }

            var primero = anio + '-' + dos(mes) + '-01';
            var ultimo = aTexto(new Date(anio, mes, 0));
            if (ultimo > ultimoDiaHoy) { ultimo = ultimoDiaHoy; } //se muestra hasta hoy (o hasta un día habilitado más adelante)

            var h = '', registrados = 0, faltan = 0;
            for (var dia = primero; dia <= ultimo; dia = sumarDias(dia, 1)) {
                var f = porDia[dia];
                if (f) {
                    registrados++;
                    var sit = cfgHoy.situaciones[f.SITUACION] || f.SITUACION;
                    var lab = f.SITUACION === 'LABORAL';
                    var donde = [f.LUGARES, f.LUGAR_OTRO].filter(function (x) { return x; }).join(' · ');
                    h += '<tr class="hoy-fila-mes" data-fecha="' + dia + '">' +
                         '<td>' + diaCorto(dia) + '</td>' +
                         '<td><span class="hoy-sit-tag hoy-sit-' + esc(f.SITUACION.toLowerCase()) + '">' + esc(sit) + '</span></td>' +
                         '<td>' + (lab ? esc(donde) : '') + '</td>' +
                         '<td>' + (lab ? esc(hhmm(f.HORA_INGRESO)) + (f.HORA_SALIDA ? ' – ' + esc(hhmm(f.HORA_SALIDA)) : ' – …') : '') + '</td>' +
                         '<td class="hoy-mes-actividad">' + (lab ? esc(recortar(f.ACTIVIDAD, 110)) : '') + '</td></tr>';
                } else {
                    faltan++;
                    h += '<tr class="hoy-fila-mes hoy-falta" data-fecha="' + dia + '">' +
                         '<td>' + diaCorto(dia) + '</td><td colspan="4"><span class="rd-tag rd-tag-pend">Sin registrar</span></td></tr>';
                }
            }
            $('#mesFilas').html(h || '<tr><td colspan="5" class="rd-vacio">Este mes todavía no empieza.</td></tr>');
            $('#mesResumen').html(h ? '<span class="rd-tag rd-tag-ok">' + registrados + ' registrados</span> ' +
                                      (faltan ? '<span class="rd-tag rd-tag-pend">' + faltan + ' sin registrar</span>' : '') : '');
            $('.hoy-fila-mes[data-fecha="' + diaHoy + '"]').addClass('activa');
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el mes.'); });
}
