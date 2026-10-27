//Reporte diario - "Hoy en qué estás"
//Carga un día, lo guarda y muestra el mes con los días que faltan.
//Solo se registra HOY; otro día, únicamente si el administrador lo habilitó.
//Con la casilla 33M (Reporte diario: consultar) se elige un coordinador y se ve
//su día y su mes, en solo consulta: el servidor no deja guardar el día de otro.
//Todo texto de la base se escapa al pintarlo

var URL_HOY = '../Control/HoyControl.php';
var cfgHoy = { hoy: '', primerEditable: '', ultimoEditable: '', habilitados: [], situaciones: {}, centros: [] };
var diaHoy = '';          //el día que se está viendo (AAAA-MM-DD)
var editableHoy = false;  //si ese día todavía se puede modificar
var ultimoDiaHoy = '';    //hasta qué día se puede avanzar: hoy, o un día habilitado que esté más adelante
var ultimoPropioHoy = ''; //lo mismo, para el día propio (los días habilitados son solo de quien tiene la sesión)
var personaHoy = 0;       //de quién es el día que se ve: 0 = el propio; si no, el coordinador que se consulta
var avisoConsultaHoy = ''; //el texto original del aviso de solo consulta (el del día propio)

var NOMBRES_DIA = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

//---------------------------------------------------------------------------
// Utilidades
//---------------------------------------------------------------------------
function esc(texto) { return $('<div>').text(texto == null ? '' : String(texto)).html(); }
//Número con dos dígitos: 7 -> "07"
function dos(numero) { return ('0' + numero).slice(-2); }

//"2026-09-07" -> Date local (sin el desfase de UTC)
function aFecha(texto) { var partes = texto.split('-'); return new Date(+partes[0], +partes[1] - 1, +partes[2]); }
//De fecha a texto "2026-09-07", y sumar días a una fecha en texto
function aTexto(fecha) { return fecha.getFullYear() + '-' + dos(fecha.getMonth() + 1) + '-' + dos(fecha.getDate()); }
function sumarDias(texto, cantidad) { var fecha = aFecha(texto); fecha.setDate(fecha.getDate() + cantidad); return aTexto(fecha); }

//"07:40:00" -> "07:40"
function hhmm(hora) { return hora ? String(hora).substring(0, 5) : ''; }

//"2026-09-07" -> "Lun 07/09"
function diaCorto(texto) {
    var fecha = aFecha(texto);
    return NOMBRES_DIA[fecha.getDay()] + ' ' + dos(fecha.getDate()) + '/' + dos(fecha.getMonth() + 1);
}

//Un texto largo, recortado para que quepa en una celda de la tabla
function recortar(texto, max) {
    texto = String(texto == null ? '' : texto).replace(/\s+/g, ' ');
    return texto.length > max ? texto.substring(0, max - 1) + '…' : texto;
}

//El mensaje de error que mandó el servidor, o uno general si no vino ninguno
function mensajeError(xhr, base) {
    try { return JSON.parse(xhr.responseText).error || base; } catch (error) { return base; }
}

//Lo que se agrega a cada consulta para pedir el día de otro coordinador
function conPersona() { return personaHoy ? '&persona=' + personaHoy : ''; }

//El nombre del coordinador que se está consultando
function nombrePersona() {
    var lista = cfgHoy.personas || [];
    for (var i = 0; i < lista.length; i++) { if (+lista[i].ID_COLABORADOR === personaHoy) { return lista[i].NOM_COLABORADOR; } }
    return '';
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
    avisoConsultaHoy = $('#hoyConsulta').html();
    rdVigilarCambios('#formHoy');
    //Botones rápidos: "Hoy" y uno por cada día que el administrador habilitó
    $(document).on('click', '#hoyRapido [data-fecha]', function () {
        var fechaElegida = $(this).data('fecha');
        irADia(fechaElegida === 'hoy' ? cfgHoy.hoy : fechaElegida);
    });

    $.getJSON(URL_HOY + '?op=config')
        .done(function (config) {
            cfgHoy = config;
            //El aviso de "hoy no has registrado" es solo para quien llena su día
            if (config.esCoordinador) { rdAlertaDia('hoy', '#alertaDia'); }
            pintarSituaciones();
            pintarCentros(config.centros, true);

            //Año y mes de "Mi mes": del año pasado al actual
            var anioHoy = +config.hoy.substring(0, 4), html = '';
            for (var anio = anioHoy; anio >= anioHoy - 1; anio--) { html += '<option value="' + anio + '">' + anio + '</option>'; }
            $('#mesAnio').html(html).val(String(anioHoy));
            $('#mesMes').val(String(+config.hoy.substring(5, 7)));

            //Lo normal es que el último día sea hoy; si hay un día habilitado más adelante, ese
            config.habilitados = config.habilitados || [];
            ultimoPropioHoy = config.hoy;
            config.habilitados.forEach(function (fechaHabilitada) { if (fechaHabilitada > ultimoPropioHoy) { ultimoPropioHoy = fechaHabilitada; } });
            pintarHabilitados();
            if (!prepararPersonas()) { return; }
            modoPersona();

            //Se puede llegar con ?fecha=AAAA-MM-DD (desde "Mi mes" o el tablero)
            var pedida = new URLSearchParams(window.location.search).get('fecha');
            cargarDia(/^\d{4}-\d{2}-\d{2}$/.test(pedida || '') && pedida <= ultimoDiaHoy ? pedida : config.hoy);
            cargarMes();
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir el reporte.'); });

    //Cambiar de día
    $('#hoyFecha').on('change', function () { if (this.value) { irADia(this.value); } });
    $('#btnDiaAnterior').on('click', function () { irADia(sumarDias(diaHoy, -1)); });
    $('#btnDiaSiguiente').on('click', function () { if (diaHoy < ultimoDiaHoy) { irADia(sumarDias(diaHoy, 1)); } });

    //Situación: laboral muestra lugares, horario y qué va a hacer
    $(document).on('change', 'input[name="situacion"]', mostrarLaboral);

    $('#formHoy').on('submit', guardarDia);

    //Con 33M: cambiar el coordinador que se consulta
    $('#hoyPersona').on('change', function () {
        if (rdHayCambios && !window.confirm('Tienes cambios sin guardar en este día. ¿Quieres cambiar de persona y perderlos?')) {
            $(this).val(String(personaHoy));
            return;
        }
        rdCambiosGuardados();
        personaHoy = +this.value || 0;
        modoPersona();
        cargarDia(diaHoy && diaHoy <= ultimoDiaHoy ? diaHoy : cfgHoy.hoy);
        cargarMes();
    });

    $('#mesMes, #mesAnio').on('change', cargarMes);
    $(document).on('click', '.hoy-fila-mes', function () {
        irADia($(this).data('fecha'));
        $('html, body').animate({ scrollTop: 0 }, 200);
    });
});

//---------------------------------------------------------------------------
// De quién es el día (solo con 33M)
//---------------------------------------------------------------------------
//Llena la lista de coordinadores. Devuelve false si no hay nada que mostrar
function prepararPersonas() {
    if (!cfgHoy.consulta) { personaHoy = 0; return true; }
    var html = cfgHoy.esCoordinador ? '<option value="0">Mi día</option>' : '';
    (cfgHoy.personas || []).forEach(function (persona) {
        if (+persona.ID_COLABORADOR === +cfgHoy.yo) { return; } //el propio ya es "Mi día"
        html += '<option value="' + parseInt(persona.ID_COLABORADOR, 10) + '">' + esc(persona.NOM_COLABORADOR) +
             ' · ' + esc(persona.PROYECTOS || 'ya no coordina ningún proyecto') + '</option>';
    });
    $('#hoyPersona').html(html);
    $('#hoyPersonaBloque').show();
    if (!html) {
        $('#formHoy').hide();
        $('#hoyConsulta').html('<i class="fa fa-info-circle"></i> Todavía no hay coordinadores asignados a los proyectos.').show();
        $('#mesFilas').html('<tr><td colspan="5" class="rd-vacio">No hay nada que mostrar.</td></tr>');
        return false;
    }
    //Se puede llegar con ?persona=ID; quien no coordina arranca en el primer coordinador
    var pedida = parseInt(new URLSearchParams(window.location.search).get('persona') || '0', 10);
    if (pedida && $('#hoyPersona option[value="' + pedida + '"]').length) { $('#hoyPersona').val(String(pedida)); }
    personaHoy = +($('#hoyPersona').val() || 0);
    return true;
}

//Ajusta la pantalla a de quién es el día: el propio (se llena) o el de otro (solo consulta)
function modoPersona() {
    var propio = !personaHoy;
    //Al día propio se llega hasta un día habilitado más adelante; al de otro, hasta hoy
    ultimoDiaHoy = propio ? ultimoPropioHoy : cfgHoy.hoy;
    $('#hoyFecha').attr('max', ultimoDiaHoy);
    $('#hoyRapido [data-habilitado]').toggle(propio);
    $('#mesTitulo').text(propio ? 'Mi mes' : 'Mes de ' + nombrePersona());
}

//---------------------------------------------------------------------------
// Situaciones y centros
//---------------------------------------------------------------------------
function pintarSituaciones() {
    var html = '';
    $.each(cfgHoy.situaciones, function (codigo, nombre) {
        html += '<label class="hoy-sit hoy-sit-' + esc(codigo.toLowerCase()) + '">' +
             '<input type="radio" name="situacion" value="' + esc(codigo) + '"> <span>' + esc(nombre) + '</span></label>';
    });
    $('#hoySituaciones').html(html);
}

//Los centros asignados, agrupados por proyecto, como casillas grandes.
//propio: los del coordinador que tiene la sesión; si no, los del coordinador que se consulta
function pintarCentros(centros, propio) {
    centros = centros || [];
    if (!centros.length) {
        $('#hoyCentros').html(propio ? '<p class="rd-ayuda">Tus proyectos no tienen peajes activos. Puedes escribir el lugar en "Otro lugar".</p>'
                                     : '<p class="rd-ayuda">No marcó peajes de sus proyectos.</p>');
        return;
    }
    var html = '', grupo = null;
    for (var i = 0; i < centros.length; i++) {
        if (centros[i].NOM_PROYECTO !== grupo) {
            if (grupo !== null) { html += '</div>'; }
            grupo = centros[i].NOM_PROYECTO;
            html += '<div class="hoy-grupo"><span class="hoy-grupo-nom">' + esc(grupo) + '</span>';
        }
        var tipo = (centros[i].TIPO_CENTRO || 'PEAJE');
        html += '<label class="hoy-chip"><input type="checkbox" name="centros[]" value="' + parseInt(centros[i].ID_CENTRO_OP, 10) + '">' +
             '<span>' + (tipo === 'BASCULA' ? '<i class="fa fa-balance-scale"></i> ' : '') + esc(centros[i].NOM_CENTRO_OP) + '</span></label>';
    }
    html += '</div>';
    $('#hoyCentros').html(html);
}

//Muestra los campos de trabajo solo si la situación del día es LABORAL
function mostrarLaboral() {
    var laboral = $('input[name="situacion"]:checked').val() === 'LABORAL';
    $('#hoyLaboral').toggle(laboral);
}

//---------------------------------------------------------------------------
// Días habilitados: un botón por cada día que el administrador le abrió
//---------------------------------------------------------------------------
function pintarHabilitados() {
    $('#hoyRapido [data-habilitado]').remove(); //se vuelven a pintar desde cero
    var html = '';
    (cfgHoy.habilitados || []).forEach(function (fechaHabilitada) {
        html += '<button type="button" class="btn btn-default btn-sm" data-habilitado="1" data-fecha="' + esc(fechaHabilitada) + '" title="El administrador te habilitó este día">' +
             '<i class="fa fa-unlock"></i> ' + esc(diaCorto(fechaHabilitada)) + '</button>';
    });
    $('#hoyRapido').append(html);
}

//---------------------------------------------------------------------------
// Cargar un día
//---------------------------------------------------------------------------
function cargarDia(fecha) {
    $.getJSON(URL_HOY + '?op=obtener&fecha=' + encodeURIComponent(fecha) + conPersona())
        .done(function (respuesta) {
            diaHoy = respuesta.fecha;
            editableHoy = !!respuesta.editable;
            var registro = respuesta.registro;

            //Los peajes: los propios, o los de los proyectos del coordinador que se consulta
            //más los que marcó ese día (aunque ya no sean de sus proyectos)
            if (respuesta.propio === false) {
                var centrosOtro = (respuesta.centros || []).slice(), vistos = {};
                centrosOtro.forEach(function (centro) { vistos[+centro.ID_CENTRO_OP] = true; });
                ((registro && registro.CENTROS_DET) || []).forEach(function (centro) { if (!vistos[+centro.ID_CENTRO_OP]) { centrosOtro.push(centro); } });
                pintarCentros(centrosOtro, false);
                $('#hoyConsulta').html('<i class="fa fa-eye"></i> Solo consulta: es el día de <strong>' + esc(nombrePersona()) +
                                       '</strong>. Cada coordinador registra y corrige el suyo.');
            } else {
                pintarCentros(cfgHoy.centros, true);
                $('#hoyConsulta').html(avisoConsultaHoy);
            }

            $('#hoyFecha').val(diaHoy);
            $('#btnDiaSiguiente').prop('disabled', diaHoy >= ultimoDiaHoy);
            $('#hoyEstado').html(registro
                ? '<span class="rd-tag rd-tag-ok"><i class="fa fa-check"></i> Registrado</span>'
                : '<span class="rd-tag rd-tag-pend"><i class="fa fa-clock-o"></i> Sin registrar</span>');
            $('#hoyConsulta').toggle(!editableHoy);

            //Llenar el formulario
            $('#formHoy')[0].reset();
            $('input[name="situacion"][value="' + (registro ? registro.SITUACION : 'LABORAL') + '"]').prop('checked', true);
            $('#hoyIngreso').val(registro ? hhmm(registro.HORA_INGRESO) : '');
            $('#hoySalida').val(registro ? hhmm(registro.HORA_SALIDA) : '');
            $('#hoyLugarOtro').val(registro && registro.LUGAR_OTRO ? registro.LUGAR_OTRO : '');
            $('#hoyActividad').val(registro && registro.ACTIVIDAD ? registro.ACTIVIDAD : ''); //qué va a hacer en el día
            $('#hoyObservacion').val(registro && registro.OBSERVACION ? registro.OBSERVACION : '');
            $('input[name="centros[]"]').prop('checked', false);
            if (registro) {
                for (var i = 0; i < registro.CENTROS.length; i++) {
                    $('input[name="centros[]"][value="' + parseInt(registro.CENTROS[i], 10) + '"]').prop('checked', true);
                }
            }
            //Solo consulta: todo queda bloqueado y sin botón de guardar
            $('#formHoy').find('input, textarea, .rd-btn-ahora').prop('disabled', !editableHoy);
            $('#btnGuardarHoy').toggle(editableHoy);

            mostrarLaboral();
            $('.hoy-fila-mes').removeClass('activa').filter('[data-fecha="' + diaHoy + '"]').addClass('activa');
            //Hoy o un día habilitado: marca el botón del día que se está viendo
            $('#hoyRapido [data-fecha]').removeClass('active').each(function () {
                var fechaElegida = $(this).data('fecha');
                if ((fechaElegida === 'hoy' ? cfgHoy.hoy : fechaElegida) === diaHoy) { $(this).addClass('active'); }
            });
            rdCambiosGuardados(); //acaba de cargarse: todavía no hay cambios
        })
        .fail(function (xhr) { alert(mensajeError(xhr, 'No se pudo cargar el día.')); });
}

//---------------------------------------------------------------------------
// Guardar
//---------------------------------------------------------------------------
function guardarDia(evento) {
    evento.preventDefault();
    var situacion = $('input[name="situacion"]:checked').val();
    if (!situacion) { alert('Elige la situación del día.'); return; }
    if (situacion === 'LABORAL') {
        if (!$('#hoyIngreso').val()) { alert('Escribe la hora de ingreso.'); $('#hoyIngreso').focus(); return; }
        if (!$('input[name="centros[]"]:checked').length && !$.trim($('#hoyLugarOtro').val())) {
            alert('Marca al menos un peaje o escribe en qué otro lugar vas a estar.'); return;
        }
        if (!$.trim($('#hoyActividad').val())) { alert('Escribe qué vas a hacer hoy.'); $('#hoyActividad').focus(); return; }
    }

    var formulario = new FormData($('#formHoy')[0]);
    formulario.append('fecha', diaHoy);
    hanaBoton('#btnGuardarHoy', true);
    $.ajax({ url: URL_HOY + '?op=guardar', type: 'POST', data: formulario, contentType: false, processData: false, dataType: 'json' })
        .done(function (respuesta) {
            rdCambiosGuardados();
            rdAviso(respuesta.mensaje);
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
    $.getJSON(URL_HOY + '?op=mes&anio=' + anio + '&mes=' + mes + conPersona())
        .done(function (respuesta) {
            var porDia = {};
            for (var i = 0; i < respuesta.filas.length; i++) { porDia[respuesta.filas[i].FECHA] = respuesta.filas[i]; }

            var primero = anio + '-' + dos(mes) + '-01';
            var ultimo = aTexto(new Date(anio, mes, 0));
            if (ultimo > ultimoDiaHoy) { ultimo = ultimoDiaHoy; } //se muestra hasta hoy (o hasta un día habilitado más adelante)

            var html = '', registrados = 0, faltan = 0;
            for (var dia = primero; dia <= ultimo; dia = sumarDias(dia, 1)) {
                var registroDia = porDia[dia];
                if (registroDia) {
                    registrados++;
                    var situacion = cfgHoy.situaciones[registroDia.SITUACION] || registroDia.SITUACION;
                    var esLaboral = registroDia.SITUACION === 'LABORAL';
                    var donde = [registroDia.LUGARES, registroDia.LUGAR_OTRO].filter(function (lugar) { return lugar; }).join(' · ');
                    html += '<tr class="hoy-fila-mes" data-fecha="' + dia + '">' +
                         '<td>' + diaCorto(dia) + '</td>' +
                         '<td><span class="hoy-sit-tag hoy-sit-' + esc(registroDia.SITUACION.toLowerCase()) + '">' + esc(situacion) + '</span></td>' +
                         '<td>' + (esLaboral ? esc(donde) : '') + '</td>' +
                         '<td>' + (esLaboral ? esc(hhmm(registroDia.HORA_INGRESO)) + (registroDia.HORA_SALIDA ? ' – ' + esc(hhmm(registroDia.HORA_SALIDA)) : ' – …') : '') + '</td>' +
                         '<td class="hoy-mes-actividad">' + (esLaboral ? esc(recortar(registroDia.ACTIVIDAD, 110)) : '') + '</td></tr>';
                } else {
                    faltan++;
                    html += '<tr class="hoy-fila-mes hoy-falta" data-fecha="' + dia + '">' +
                         '<td>' + diaCorto(dia) + '</td><td colspan="4"><span class="rd-tag rd-tag-pend">Sin registrar</span></td></tr>';
                }
            }
            $('#mesFilas').html(html || '<tr><td colspan="5" class="rd-vacio">Este mes todavía no empieza.</td></tr>');
            $('#mesResumen').html(html ? '<span class="rd-tag rd-tag-ok">' + registrados + ' registrados</span> ' +
                                      (faltan ? '<span class="rd-tag rd-tag-pend">' + faltan + ' sin registrar</span>' : '') : '');
            $('.hoy-fila-mes[data-fecha="' + diaHoy + '"]').addClass('activa');
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el mes.'); });
}
