//Reporte diario — "Hoy en qué estás"
//Carga un día, arma los bloques por hora según el horario, guarda, y muestra
//el mes con los días que faltan. Todo texto de la base se escapa al pintarlo

var URL_HOY = '../Control/HoyControl.php';
var cfgHoy = { hoy: '', primerEditable: '', ultimoEditable: '', situaciones: {}, centros: [] };
var diaHoy = '';          //el día que se está viendo (AAAA-MM-DD)
var editableHoy = false;  //si ese día todavía se puede modificar
var textosHoras = {};     //lo escrito en cada bloque, para no perderlo al cambiar el horario

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
    $(document).on('click', '#hoyRapido [data-dia]', function () {
        irADia(sumarDias(cfgHoy.hoy, parseInt($(this).data('dia'), 10)));
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

            $('#hoyFecha').attr('max', c.ultimoEditable);

            //Se puede llegar con ?fecha=AAAA-MM-DD (desde "Mi mes" o el tablero)
            var pedida = new URLSearchParams(window.location.search).get('fecha');
            cargarDia(/^\d{4}-\d{2}-\d{2}$/.test(pedida || '') && pedida <= c.ultimoEditable ? pedida : c.hoy);
            cargarMes();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir el reporte.'); });

    //Cambiar de día
    $('#hoyFecha').on('change', function () { if (this.value) { irADia(this.value); } });
    $('#btnDiaAnterior').on('click', function () { irADia(sumarDias(diaHoy, -1)); });
    $('#btnDiaSiguiente').on('click', function () { if (diaHoy < cfgHoy.ultimoEditable) { irADia(sumarDias(diaHoy, 1)); } });

    //Situación: laboral muestra lugares y horas
    $(document).on('change', 'input[name="situacion"]', mostrarLaboral);

    //El horario define qué bloques de hora aparecen
    $('#hoyIngreso, #hoySalida').on('change', function () { guardarTextosHoras(); pintarHoras(); });
    $(document).on('input', '.hoy-hora textarea', function () {
        textosHoras[$(this).data('hora')] = this.value;
    });

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
// Bloques por hora
//---------------------------------------------------------------------------
function guardarTextosHoras() {
    $('#hoyHoras textarea').each(function () { textosHoras[$(this).data('hora')] = this.value; });
}

//De la hora de ingreso a la de salida. Sin salida: hasta la hora actual si es
//hoy, o 10 bloques si es otro día. Siempre aparecen los bloques que ya tienen texto
function pintarHoras() {
    var ing = $('#hoyIngreso').val(), sal = $('#hoySalida').val();
    var desde = ing ? parseInt(ing.substring(0, 2), 10) : 7;
    var hasta;
    if (sal) {
        hasta = parseInt(sal.substring(0, 2), 10) - (sal.substring(3, 5) === '00' ? 1 : 0);
    } else if (diaHoy === cfgHoy.hoy) {
        hasta = Math.max(desde, new Date().getHours());
    } else {
        hasta = desde + 9;
    }
    hasta = Math.min(23, Math.max(desde, hasta));

    var horas = {};
    for (var x = desde; x <= hasta; x++) { horas[x] = true; }
    $.each(textosHoras, function (hr, txt) { if ($.trim(txt) !== '') { horas[hr] = true; } });

    var lista = Object.keys(horas).map(Number).sort(function (a, b) { return a - b; });
    var h = '';
    for (var i = 0; i < lista.length; i++) {
        var hr = lista[i];
        h += '<div class="hoy-hora"><label for="hora' + hr + '">' + dos(hr) + ':00 – ' + dos(hr + 1) + ':00</label>' +
             '<textarea id="hora' + hr + '" name="horas[' + hr + ']" data-hora="' + hr + '" class="form-control" rows="2" maxlength="1000"' +
             (editableHoy ? '' : ' disabled') + '>' + esc(textosHoras[hr] || '') + '</textarea></div>';
    }
    $('#hoyHoras').html(h);
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
            $('#btnDiaSiguiente').prop('disabled', diaHoy >= cfgHoy.ultimoEditable);
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
            $('#hoyObservacion').val(r && r.OBSERVACION ? r.OBSERVACION : '');
            $('input[name="centros[]"]').prop('checked', false);
            if (r) {
                for (var i = 0; i < r.CENTROS.length; i++) {
                    $('input[name="centros[]"][value="' + parseInt(r.CENTROS[i], 10) + '"]').prop('checked', true);
                }
            }
            textosHoras = {};
            if (r && r.HORAS) { $.each(r.HORAS, function (hr, txt) { textosHoras[hr] = txt; }); }

            //Solo consulta: todo queda bloqueado y sin botón de guardar
            $('#formHoy').find('input, textarea').prop('disabled', !editableHoy);
            $('#btnGuardarHoy').toggle(editableHoy);

            mostrarLaboral();
            pintarHoras();
            $('.hoy-fila-mes').removeClass('activa').filter('[data-fecha="' + diaHoy + '"]').addClass('activa');
            //Ayer / Hoy / Mañana: marca el que se está viendo
            $('#hoyRapido [data-dia]').removeClass('active').each(function () {
                if (sumarDias(cfgHoy.hoy, parseInt($(this).data('dia'), 10)) === diaHoy) { $(this).addClass('active'); }
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
            if (ultimo > cfgHoy.ultimoEditable) { ultimo = cfgHoy.ultimoEditable; } //se muestra hasta mañana, que ya se puede adelantar

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
                         '<td>' + (lab ? parseInt(f.BLOQUES, 10) + ' h' : '') + '</td></tr>';
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
