//Promesa de valor (menú Ausentismo): el registro del día, el mes del peaje y la definición de la promesa.
//Diferencia = ejecutado − promesa: en negativo faltó gente, en positivo hubo más de lo prometido
var URL_PV = '../Control/PromesaValorControl.php';
var cfgPv = null, proyPv = null, centroPv = null;
var fechaPv = '';           //el día que se está viendo (AAAA-MM-DD)
var diaPv = null;           //lo que respondió el servidor para ese día
var promesaPv = null;       //lo que respondió el servidor para la pestaña de la promesa
var PV_LETRA_DIA = ['D', 'L', 'M', 'M', 'J', 'V', 'S'];
var filtroPv = [];          //los cargos elegidos en el filtro (ids); vacío = todos
var mesPv = null;           //lo último que respondió el servidor para el mes (para volver a pintarlo al filtrar)

$(function () {
    $.getJSON(URL_PV + '?op=config')
        .done(function (config) {
            cfgPv = config;
            fechaPv = config.hoy;
            rdPeriodo('#pvMes', '#pvAnio', config.hoy);                            //el mes y el año de hoy
            $('#pvOtroDia').attr('max', config.hoy);
            $('#pvDesde').val(config.hoy);
            $('#dsDesde').val(config.hoy);
            var html = '';
            config.proyectos.forEach(function (proyecto) {
                html += '<option value="' + parseInt(proyecto.ID_PROYECTO, 10) + '">' + rdEsc(proyecto.NOM_PROYECTO) + (proyecto.COORDINA ? ' (el tuyo)' : '') + '</option>';
            });
            $('#pvProyecto').html(html).val(String(config.proyectoInicial));
            leerDireccion();                                                  //si viene del tablero con ?centro=&fecha=
            pintarDias();
            elegirProyectoPv();
            //Enlace a Ausentismo, solo si la persona lo tiene en su menú
            if ($('.side-menu a[href="AusentismoVista.php"]').length) {
                $('#pvIrAus').html(' Si falta una ausencia, se anota en <a href="AusentismoVista.php">Ausentismo</a>.');
            }
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo abrir la promesa de valor.'); });

    rdVigilarCambios('#pvForm');                                              //avisa si se sale con el día a medio llenar
    $('#pvProyecto').on('change', function () { if (salirDelDia()) { elegirProyectoPv(); } else { $(this).val(String(proyPv.ID_PROYECTO)); } });
    $('#pvCentro').on('change', function () { if (salirDelDia()) { elegirCentroPv(+this.value); } else { $(this).val(String(centroPv.ID_CENTRO_OP)); } });
    $(document).on('click', '.aus-centro-chip', function () { if (salirDelDia()) { $('#pvCentro').val(String($(this).data('id'))); elegirCentroPv(+$(this).data('id')); } });
    $(document).on('click', '#pvDias [data-fecha]', function () { if (salirDelDia()) { verDia($(this).data('fecha')); } });
    $('#pvOtroDia').on('change', function () {
        if (!this.value) { return; }
        if (this.value > cfgPv.hoy) { this.value = ''; rdAviso('Ese día todavía no llega.', 'error'); return; }
        if (salirDelDia()) { verDia(this.value); }
    });
    $(document).on('input', '.pv-eje', escribirEjecutado);
    $(document).on('focus', '.pv-eje', function () { this.select(); });
    $(document).on('keydown', '.pv-eje', function (evento) {                       //Enter y las flechas bajan o suben de cargo
        var i = $('.pv-eje').index(this), destino = evento.key === 'Enter' || evento.key === 'ArrowDown' ? i + 1 : (evento.key === 'ArrowUp' ? i - 1 : -1);
        if (destino >= 0 && destino < $('.pv-eje').length) { evento.preventDefault(); $('.pv-eje').eq(destino).focus(); }
    });
    $('#btnLlenar').on('click', llenarSegunAusentismo);
    $('#btnGuardarPv').on('click', guardarDia);

    $('#tabMesLink').on('shown.bs.tab', cargarMes);
    $('#pvMes, #pvAnio').on('change', cargarMes);
    $('#tabPromesaLink').on('shown.bs.tab', cargarPromesa);
    $('#btnGuardarPromesa').on('click', guardarPromesa);
    $('#btnAgregarRango').on('click', agregarRango);
    $('#btnGuardarDias').on('click', guardarDias);
    $(document).on('click', '#pvFiltroCargos .pv-fc', function () { cambiarFiltroPv(+$(this).data('id')); });
    $(document).on('click', '.btn-pv-borrar', function () { borrarRegla(+$(this).data('id'), $(this).data('texto')); });
});

//Si la dirección trae ?proyecto=, ?centro= o ?fecha= (así llega desde el tablero), se abre ahí
function leerDireccion() {
    var parametrosUrl = new URLSearchParams(window.location.search), idCentro = parseInt(parametrosUrl.get('centro'), 10) || 0, idProy = parseInt(parametrosUrl.get('proyecto'), 10) || 0;
    cfgPv.proyectos.forEach(function (proyecto) {
        if (+proyecto.ID_PROYECTO === idProy) { $('#pvProyecto').val(String(idProy)); }
        proyecto.CENTROS.forEach(function (centro) { if (+centro.ID_CENTRO_OP === idCentro) { $('#pvProyecto').val(String(proyecto.ID_PROYECTO)); cfgPv.centroInicial = idCentro; } });
    });
    var fechaUrl = parametrosUrl.get('fecha') || '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(fechaUrl) && fechaUrl <= cfgPv.hoy) { fechaPv = fechaUrl; }
    //Desde el consolidado se llega con ?ver=mes&anio=&mes=: se abre la pestaña del mes en ese periodo
    if (parametrosUrl.get('ver') === 'mes') {
        if ($('#pvAnio option[value="' + parametrosUrl.get('anio') + '"]').length) { $('#pvAnio').val(parametrosUrl.get('anio')); }
        if (+parametrosUrl.get('mes') >= 1 && +parametrosUrl.get('mes') <= 12) { $('#pvMes').val(String(+parametrosUrl.get('mes'))); }
        $('#tabMesLink').tab('show');
    }
}

//Antes de cambiar de peaje o de día: ¿hay números escritos sin guardar?
function salirDelDia() {
    if (!rdHayCambios) { return true; }
    if (window.confirm('Hay números sin guardar en este peaje. ¿Continuar y perderlos?')) { rdCambiosGuardados(); return true; }
    return false;
}

//Al cambiar de proyecto: llena el selector con sus peajes
function elegirProyectoPv() {
    var id = +($('#pvProyecto').val() || 0);
    proyPv = cfgPv.proyectos.filter(function (proyecto) { return +proyecto.ID_PROYECTO === id; })[0] || cfgPv.proyectos[0];
    var html = '';
    proyPv.CENTROS.forEach(function (centro) { html += '<option value="' + parseInt(centro.ID_CENTRO_OP, 10) + '">' + (centro.TIPO_CENTRO === 'BASCULA' ? 'Báscula ' : '') + rdEsc(centro.NOM_CENTRO_OP) + '</option>'; });
    $('#pvCentro').html(html);
    if (cfgPv.centroInicial) { $('#pvCentro').val(String(cfgPv.centroInicial)); cfgPv.centroInicial = 0; } //solo la primera vez
    elegirCentroPv(+($('#pvCentro').val() || 0));
}

//Al elegir un peaje: pone al día la pestaña que esté abierta
function elegirCentroPv(id) {
    centroPv = proyPv.CENTROS.filter(function (centro) { return +centro.ID_CENTRO_OP === id; })[0] || proyPv.CENTROS[0];
    cargarDia();
    if ($('#tabMes').hasClass('active')) { cargarMes(); }                    //la pestaña que esté abierta se pone al día
    if ($('#tabPromesa').hasClass('active')) { cargarPromesa(); }
}

//---------------------------------------------------------------------------
// El día
//---------------------------------------------------------------------------
//Los botones de los días que se pueden llenar: "Hoy" y, si hay, los anteriores permitidos o habilitados
function pintarDias() {
    var html = '';
    cfgPv.dias.forEach(function (dia) {
        var fecha = rdAFecha(dia.fecha), rotulo = dia.tipo === 'HOY' ? 'Hoy' : RD_DIAS_SEMANA[fecha.getDay()] + ' ' + rdDos(fecha.getDate()) + '/' + rdDos(fecha.getMonth() + 1);
        var titulo = dia.tipo === 'HABILITADO' ? 'El administrador te habilitó este día' : (dia.tipo === 'ATRAS' ? 'Todavía se puede registrar' : 'El día de hoy');
        html += '<button type="button" class="btn btn-default btn-sm" data-fecha="' + rdEsc(dia.fecha) + '" title="' + titulo + '">' +
             (dia.tipo === 'HABILITADO' ? '<i class="fa fa-unlock"></i> ' : '') + rotulo + '</button>';
    });
    $('#pvDias').html(html);
}

//Cambia al día indicado y lo carga
function verDia(fecha) { fechaPv = fecha; cargarDia(); }

//Trae el día del peaje: lo prometido y lo ejecutado por cargo
function cargarDia() {
    if (!centroPv) { return; }
    $.getJSON(URL_PV + '?op=dia&centro=' + centroPv.ID_CENTRO_OP + '&fecha=' + encodeURIComponent(fechaPv))
        .done(function (respuesta) {
            diaPv = respuesta; rdCambiosGuardados();
            rdMarcarDia('#pvDias', respuesta.fecha);                                  //resalta el botón del día
            $('#pvOtroDia').val($('#pvDias [data-fecha="' + respuesta.fecha + '"]').length ? '' : respuesta.fecha); //si no es un botón, queda en el campo
            $('#pvQuien').html('<i class="fa fa-user"></i> Coordinador: <strong>' + rdEsc(respuesta.coordinador || 'sin asignar') + '</strong>' +
                               ' <span class="rd-tag">' + rdEsc(textoDia(respuesta.fecha)) + '</span>');
            pintarChips(respuesta);
            pintarNota(respuesta);
            pintarFiltroPv(respuesta.cargos);
            pintarFilas(respuesta);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el día.'); });
}

//"hoy, martes 06/10/2026" o "lunes 05/10/2026"
function textoDia(fecha) {
    var nombres = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    return (fecha === cfgPv.hoy ? 'hoy, ' : '') + nombres[rdAFecha(fecha).getDay()] + ' ' + rdFecha(fecha);
}

//Un botón por peaje del proyecto: verde si ya tiene el ejecutado de ese día
function pintarChips(datos) {
    var html = '';
    proyPv.CENTROS.forEach(function (centro) {
        var id = parseInt(centro.ID_CENTRO_OP, 10), listo = !!datos.avance[id], sinPromesa = datos.conPromesa.indexOf(id) === -1;
        html += '<span class="aus-centro-chip' + (id === +centroPv.ID_CENTRO_OP ? ' activo' : '') + (listo ? ' con' : '') + '" data-id="' + id + '" role="button" tabindex="0"' +
             ' title="' + (listo ? 'Ya tiene el ejecutado de ese día' : (sinPromesa ? 'Todavía no tiene promesa de valor' : 'Falta registrar el ejecutado')) + '">' +
             '<i class="fa ' + (listo ? 'fa-check' : (sinPromesa ? 'fa-minus' : 'fa-circle-o')) + '"></i> ' + rdEsc(centro.NOM_CENTRO_OP) + '</span>';
    });
    $('#pvCentros').html(html);
}

//El aviso de arriba de la tabla: registrado, pendiente, solo consulta o sin promesa
function pintarNota(datos) {
    var html = '';
    if (!datos.filas.length) {
        html = '<div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-info-circle"></i> Este peaje todavía no tiene promesa de valor para ese día. ' +
            (datos.define ? 'Defínela en la pestaña <a href="#" id="pvIrPromesa">Promesa del peaje</a>.' : 'La define ' + rdEsc(datos.coordinador || 'el coordinador del proyecto') + '.') + '</div>';
    } else if (datos.registrado) {
        html = '<div class="rd-alerta-dia rd-alerta-ok"><i class="fa fa-check-circle"></i> Ejecutado registrado' +
            (datos.quien ? ' por ' + rdEsc(datos.quien) : '') + (datos.cuando ? ' el ' + rdFecha(datos.cuando) + ' a las ' + rdHora(String(datos.cuando).substring(11)) : '') + '.' +
            (datos.editable ? ' Puedes corregirlo y volver a guardar.' : '') + '</div>';
    } else {                                                              //hay promesa pero nadie ha registrado ese día
        html = '<div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-exclamation-circle"></i> ' +
            (datos.editable ? 'Falta registrar el ejecutado de ' : 'Todavía no se ha registrado el ejecutado de ') + rdEsc(textoDia(datos.fecha)) + '.</div>';
    }
    if (datos.filas.length && !datos.editable) { html += '<div class="rd-alerta-dia pv-alerta-gris"><i class="fa fa-lock"></i> ' + rdEsc(datos.motivo) + '</div>'; }
    $('#pvNotaDia').html(html);
    $('#pvIrPromesa').on('click', function (evento) { evento.preventDefault(); $('#tabPromesaLink').tab('show'); });
}

//La tabla del día: un renglón por cargo
function pintarFilas(datos) {
    var html = '';
    datos.filas.forEach(function (fila) {
        var motivos = fila.motivos.map(function (motivo) { return '<span class="' + (motivo.cuenta ? '' : 'aus-no-cuenta') + '">' + rdEsc(motivo.nombre.toLowerCase()) + ' ' + motivo.n + '</span>'; }).join(', ');
        html += '<tr data-cargo="' + fila.id + '" data-promesa="' + fila.promesa + '" data-ausencias="' + fila.ausencias + '"' + (enFiltroPv(fila.id) ? '' : ' class="pv-oculta" style="display:none;"') + '>' +
             '<td class="pv-cargo">' + rdEsc(fila.nombre) + '</td>' +
             '<td class="rd-num-col pv-prom">' + fila.promesa + '</td>' +
             '<td class="pv-col-eje">' + (datos.editable
                 ? '<input type="text" inputmode="numeric" maxlength="3" class="form-control pv-eje" value="' + (fila.ejecutado === null ? '' : fila.ejecutado) + '" aria-label="Ejecutado de ' + rdEsc(fila.nombre) + '">'
                 : '<span class="pv-eje-ver">' + (fila.ejecutado === null ? '—' : fila.ejecutado) + '</span>') + '</td>' +
             '<td class="rd-num-col pv-dif"></td>' +
             '<td class="pv-aus">' + (motivos ? '<strong>' + fila.ausencias + '</strong> · ' + motivos : '<span class="pv-suave">Sin ausencias registradas</span>') + '<div class="pv-explica"></div></td></tr>';
    });
    $('#pvFilas').html(html || '<tr><td colspan="5" class="rd-vacio">No hay cargos con promesa de valor para ese día.</td></tr>');
    $('#pvAcciones').toggle(!!datos.editable && datos.filas.length > 0);
    recalcularDia();
}

//Lo que hay escrito en el ejecutado de un renglón: un número, o null si está vacío
function ejecutadoDe(fila) {
    var campo = fila.find('.pv-eje'), texto = campo.length ? campo.val() : fila.find('.pv-eje-ver').text();
    return /^\d+$/.test(texto) ? parseInt(texto, 10) : null;
}

//Un número con su signo a la vista: +3, −2 o 0
function conSigno(numero) { return (numero > 0 ? '+' : (numero < 0 ? '−' : '')) + Math.abs(numero); }

//La diferencia como etiqueta: −2 en vino, +1 en menta, 0 en gris
function etiquetaDif(diferencia) {
    if (diferencia === null) { return '<span class="pv-suave">—</span>'; }
    return '<span class="pv-difn ' + (diferencia < 0 ? 'pv-menos' : (diferencia > 0 ? 'pv-mas' : 'pv-igual')) + '">' + conSigno(diferencia) + '</span>';
}

//Saca la diferencia de cada cargo, los totales y si las ausencias explican lo que faltó
function recalcularDia() {
    var tProm = 0, tEje = 0, tAus = 0, completos = 0, cargosContados = 0;
    $('#pvFilas tr[data-cargo]').each(function () {
        var fila = $(this), prom = +fila.data('promesa'), ausencias = +fila.data('ausencias'), ejecutado = ejecutadoDe(fila), nota = '';
        if (fila.hasClass('pv-oculta')) { return; }                             //fuera del filtro: no entra en los totales
        cargosContados++; tProm += prom; tAus += ausencias;
        if (ejecutado !== null) {
            completos++; tEje += ejecutado;
            var falta = prom - ejecutado;                                           //personas que faltaron frente a lo prometido
            if (falta > ausencias) { nota = '<i class="fa fa-exclamation-triangle"></i> ' + (falta - ausencias === 1 ? 'Falta 1 persona' : 'Faltan ' + (falta - ausencias) + ' personas') + ' por explicar en Ausentismo.'; }
            else if (falta < ausencias && ausencias > 0) { nota = 'Hay más ausencias anotadas que personas faltantes: seguramente alguien cubrió el puesto.'; }
        }
        fila.find('.pv-dif').html(etiquetaDif(ejecutado === null ? null : ejecutado - prom));
        fila.find('.pv-explica').html(nota);
    });
    var todo = cargosContados > 0 && completos === cargosContados;
    $('#pvTotales').html(cargosContados ? '<tr><th class="pv-cargo">' + (filtroPv.length ? 'Cargos elegidos' : 'Todo el peaje') + '</th><th class="rd-num-col pv-prom">' + tProm + '</th><th class="pv-col-eje">' + (completos ? tEje : '—') + '</th>' +
        '<th class="rd-num-col pv-dif">' + etiquetaDif(todo ? tEje - tProm : null) + '</th><th class="pv-aus">' + (tAus ? tAus + (tAus === 1 ? ' ausencia registrada' : ' ausencias registradas') : '') + '</th></tr>' : '');
}

//Al escribir el ejecutado: deja solo números y vuelve a sumar
function escribirEjecutado() {
    var valor = this.value.replace(/\D/g, '').substring(0, 3);                    //solo números, máximo 999
    if (valor !== this.value) { this.value = valor; }
    recalcularDia();
}

//Pone en cada cargo la promesa menos las ausencias ya registradas. No guarda: deja revisar
function llenarSegunAusentismo() {
    var cambio = false;
    $('#pvFilas tr[data-cargo]').each(function () {
        var fila = $(this), sugerido = Math.max(0, +fila.data('promesa') - +fila.data('ausencias')), campo = fila.find('.pv-eje');
        if (campo.val() !== String(sugerido)) { campo.val(sugerido).addClass('pv-sugerido'); cambio = true; }
    });
    if (cambio) { rdHayCambios = true; }
    recalcularDia();
    rdAviso(cambio ? 'Listo: revisa los números y dale Guardar.' : 'Ya estaba igual a lo esperado.');
}

//Revisa que todos los cargos tengan su ejecutado y guarda el día
function guardarDia() {
    var valores = {}, falta = null;
    $('#pvFilas tr[data-cargo]').each(function () {
        var ejecutado = ejecutadoDe($(this));
        if (ejecutado === null && !falta) { falta = $(this); }
        valores[$(this).data('cargo')] = ejecutado;
    });
    if (falta) {
        if (falta.hasClass('pv-oculta')) { filtroPv = []; pintarFiltroPv(diaPv.cargos); aplicarFiltroDia(); } //estaba escondido por el filtro: se muestra todo
        falta.find('.pv-eje').focus(); alert('Falta el ejecutado de ' + falta.find('.pv-cargo').text() + '. Si no trabajó nadie, escribe 0.'); return;
    }
    hanaBoton('#btnGuardarPv', true);
    $.post(URL_PV + '?op=guardar&centro=' + centroPv.ID_CENTRO_OP, { fecha: fechaPv, valores: JSON.stringify(valores) }, null, 'json')
        .done(function (respuesta) { rdCambiosGuardados(); rdAviso(respuesta.mensaje); cargarDia(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el ejecutado.')); })
        .always(function () { hanaBoton('#btnGuardarPv', false); });
}

//---------------------------------------------------------------------------
// Filtro por cargo: ver solo algunos cargos (por ejemplo, solo los recolectores)
// en el día y en el mes. Solo cambia lo que se ve: al guardar el día van todos
//---------------------------------------------------------------------------
function enFiltroPv(id) { return !filtroPv.length || filtroPv.indexOf(+id) !== -1; }

//Arma los botones para filtrar por cargo (si el peaje tiene más de uno)
function pintarFiltroPv(cargos) {
    cargos = cargos || [];
    var ids = cargos.map(function (cargo) { return +cargo.id; });
    filtroPv = filtroPv.filter(function (id) { return ids.indexOf(id) !== -1; }); //los de otro peaje no cuentan
    if (cargos.length < 2) { $('#pvFiltroCargos').hide().html(''); return; }
    var html = '<span><i class="fa fa-filter"></i> Ver cargos:</span>' +
            '<button type="button" class="pv-fc pv-fc-todos' + (filtroPv.length ? '' : ' activo') + '" data-id="0" aria-pressed="' + !filtroPv.length + '">Todos</button>';
    cargos.forEach(function (cargo) {
        var marcado = filtroPv.indexOf(+cargo.id) !== -1;
        html += '<button type="button" class="pv-fc' + (marcado ? ' activo' : '') + '" data-id="' + parseInt(cargo.id, 10) + '" aria-pressed="' + marcado + '">' + rdEsc(nombreCargoPv(cargo.nombre)) + '</button>';
    });
    if (filtroPv.length) { html += '<span class="pv-fc-nota">Al guardar el día se guardan todos los cargos.</span>'; }
    $('#pvFiltroCargos').html(html).show();
}

//"RECOLECTOR DE PEAJE" -> "Recolector de peaje"
function nombreCargoPv(texto) { texto = String(texto).toLowerCase(); return texto.charAt(0).toUpperCase() + texto.substring(1); }

//Prende o apaga un cargo del filtro (0 = todos)
function cambiarFiltroPv(id) {
    if (!id) { filtroPv = []; }
    else if (filtroPv.indexOf(id) === -1) { filtroPv.push(id); }
    else { filtroPv = filtroPv.filter(function (idCargo) { return idCargo !== id; }); }
    if (diaPv) { pintarFiltroPv(diaPv.cargos); }
    aplicarFiltroDia();
    if (mesPv) { pintarMes(mesPv.d, mesPv.anio, mesPv.mes); }
}

//Muestra solo los cargos elegidos en el filtro
function aplicarFiltroDia() {
    $('#pvFilas tr[data-cargo]').each(function () {
        var enFiltro = enFiltroPv($(this).data('cargo'));
        $(this).toggleClass('pv-oculta', !enFiltro).toggle(enFiltro);
    });
    recalcularDia();
}

//---------------------------------------------------------------------------
// El mes del peaje: un bloque por cargo con promesa, ejecutado y diferencia día por día
//---------------------------------------------------------------------------
function cargarMes() {
    if (!centroPv) { return; }
    var anio = +$('#pvAnio').val(), mes = +$('#pvMes').val();
    $.getJSON(URL_PV + '?op=mes&centro=' + centroPv.ID_CENTRO_OP + '&anio=' + anio + '&mes=' + mes)
        .done(function (respuesta) { mesPv = { d: respuesta, anio: anio, mes: mes }; pintarMes(respuesta, anio, mes); })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el mes.'); });
}

//En qué semana cae un día: 0 a 3, y 4 para los días 29 a 31 (igual que en el servidor)
function semanaDe(dia) { return Math.min(4, Math.floor((dia - 1) / 7)); }

//El encabezado de un bloque: el cargo, los días (con su letra) y las semanas
function cabeceraMes(datos, anio, mes, titulo) {
    var html = '<thead><tr><th class="aus-fija">' + titulo + '</th>';
    for (var dia = 1; dia <= datos.diasMes; dia++) {
        var fecha = new Date(anio, mes - 1, dia), texto = rdATexto(fecha), libre = fecha.getDay() === 0 || fecha.getDay() === 6 || datos.festivos.indexOf(texto) !== -1;
        html += '<th class="aus-dia' + (libre ? ' aus-finde' : '') + (texto === cfgPv.hoy ? ' aus-hoy' : '') + '"' + (datos.festivos.indexOf(texto) !== -1 ? ' title="Festivo"' : '') + '>' +
             dia + '<small>' + PV_LETRA_DIA[fecha.getDay()] + '</small></th>';
    }
    datos.semanas.forEach(function (semana) { html += '<th class="aus-tot pv-sem">' + rdEsc(semana) + '</th>'; });
    return html + '<th class="aus-tot">Total</th></tr></thead>';
}

//Los tres renglones de un bloque a partir de la promesa y el ejecutado por día: {dia: n}
function filasMes(datos, prom, ejecutado) {
    var filaPromesa = '<tr class="pv-f-prom"><th class="aus-fija">Promesa de valor</th>', filaEjecutado = '<tr class="pv-f-eje"><th class="aus-fija">Ejecutado</th>',
        filaDiferencia = '<tr class="pv-f-dif"><th class="aus-fija">Diferencia</th>', promesaSemana = [0, 0, 0, 0, 0], ejecutadoSemana = [0, 0, 0, 0, 0], hay = [false, false, false, false, false];
    for (var dia = 1; dia <= datos.diasMes; dia++) {
        var promesaDia = prom[dia] || 0, ejecutadoDia = ejecutado[dia], registrado = ejecutadoDia !== undefined, semana = semanaDe(dia);
        if (registrado) { promesaSemana[semana] += promesaDia; ejecutadoSemana[semana] += ejecutadoDia; hay[semana] = true; }                   //las semanas solo cuentan los días registrados
        filaPromesa += '<td class="' + (registrado ? '' : 'pv-plan') + '">' + (promesaDia || '') + '</td>';
        filaEjecutado += '<td>' + (registrado ? ejecutadoDia : '') + '</td>';
        filaDiferencia += '<td class="' + (registrado ? (ejecutadoDia - promesaDia < 0 ? 'pv-menos' : (ejecutadoDia - promesaDia > 0 ? 'pv-mas' : 'pv-igual')) : '') + '">' + (registrado ? conSigno(ejecutadoDia - promesaDia) : '') + '</td>';
    }
    var totalPromesa = 0, totalEjecutado = 0, alguno = false;
    for (var i = 0; i < 5; i++) {
        totalPromesa += promesaSemana[i]; totalEjecutado += ejecutadoSemana[i]; alguno = alguno || hay[i];
        var diferencia = ejecutadoSemana[i] - promesaSemana[i];
        filaPromesa += '<td class="aus-tot">' + (hay[i] ? promesaSemana[i] : '') + '</td>';
        filaEjecutado += '<td class="aus-tot">' + (hay[i] ? ejecutadoSemana[i] : '') + '</td>';
        filaDiferencia += '<td class="aus-tot ' + (hay[i] ? (diferencia < 0 ? 'pv-menos' : (diferencia > 0 ? 'pv-mas' : 'pv-igual')) : '') + '">' + (hay[i] ? conSigno(diferencia) : '') + '</td>';
    }
    var diferenciaTotal = totalEjecutado - totalPromesa;
    return filaPromesa + '<td class="aus-tot">' + (alguno ? totalPromesa : '') + '</td></tr>' + filaEjecutado + '<td class="aus-tot">' + (alguno ? totalEjecutado : '') + '</td></tr>' +
           filaDiferencia + '<td class="aus-tot ' + (alguno ? (diferenciaTotal < 0 ? 'pv-menos' : (diferenciaTotal > 0 ? 'pv-mas' : 'pv-igual')) : '') + '">' + (alguno ? conSigno(diferenciaTotal) : '') + '</td></tr>';
}

//Una celda de diferencia para el resumen: vacía si no hay días registrados
function celdaDif(hay, diferencia) {
    return '<td class="' + (hay ? (diferencia < 0 ? 'pv-menos' : (diferencia > 0 ? 'pv-mas' : 'pv-cero')) : 'pv-cero') + '">' + (hay ? conSigno(diferencia) : '—') + '</td>';
}

//El renglón de un cargo en el resumen del mes: totales y diferencia por semana (solo días registrados)
function filaResumenMes(datos, nombre, prom, ejecutado, total) {
    var promesaSemana = [0, 0, 0, 0, 0], ejecutadoSemana = [0, 0, 0, 0, 0], hay = [false, false, false, false, false], totalPromesa = 0, totalEjecutado = 0, dias = 0;
    for (var dia = 1; dia <= datos.diasMes; dia++) {
        if (ejecutado[dia] === undefined) { continue; }
        var semana = semanaDe(dia); promesaSemana[semana] += prom[dia] || 0; ejecutadoSemana[semana] += ejecutado[dia]; hay[semana] = true; totalPromesa += prom[dia] || 0; totalEjecutado += ejecutado[dia]; dias++;
    }
    var html = '<tr class="' + (total ? 'pv-total' : '') + '"><td class="pv-nombre">' + rdEsc(nombre) + '</td><td>' + (dias ? totalPromesa : '—') + '</td><td>' + (dias ? totalEjecutado : '—') + '</td>' +
            celdaDif(dias > 0, totalEjecutado - totalPromesa) + '<td>' + (totalPromesa > 0 ? (Math.round(1000 * totalEjecutado / totalPromesa) / 10).toString().replace('.', ',') + ' %' : '—') + '</td>';
    for (var i = 0; i < 5; i++) { html += celdaDif(hay[i], ejecutadoSemana[i] - promesaSemana[i]); }
    return html + '<td>' + dias + '</td></tr>';
}

//Pinta la grilla del mes: prometido contra ejecutado por cargo y día
function pintarMes(datos, anio, mes) {
    if (!datos.cargos.length) {
        $('#pvMesGrid').html('<p class="rd-vacio">Este peaje no tiene promesa de valor ni ejecutado en ese mes.</p>');
        return;
    }
    var cargos = datos.cargos.filter(function (cargo) { return enFiltroPv(cargo.id); });  //solo los cargos del filtro
    if (!cargos.length) {
        $('#pvMesGrid').html('<p class="rd-vacio">Los cargos elegidos en el filtro no tienen promesa de valor ni ejecutado en ese mes.</p>');
        return;
    }
    var nombreTotal = filtroPv.length ? 'Cargos elegidos' : 'Todo el peaje';
    var html = '', totProm = {}, totEje = {}, resumen = '';
    cargos.forEach(function (cargo) {
        //Se va sumando para el bloque final de todo el peaje. Un día cuenta como registrado si algún cargo lo tiene
        for (var dia = 1; dia <= datos.diasMes; dia++) {
            if (cargo.promesa[dia]) { totProm[dia] = (totProm[dia] || 0) + cargo.promesa[dia]; }
            if (cargo.ejecutado[dia] !== undefined) { totEje[dia] = (totEje[dia] || 0) + cargo.ejecutado[dia]; }
        }
        resumen += filaResumenMes(datos, cargo.nombre, cargo.promesa, cargo.ejecutado, false);
        html += '<div class="aus-bloque"><div class="table-responsive aus-scroll"><table class="aus-tabla pv-mes">' + cabeceraMes(datos, anio, mes, rdEsc(cargo.nombre)) +
             '<tbody>' + filasMes(datos, cargo.promesa, cargo.ejecutado) + '</tbody></table></div></div>';
    });
    //Primero el resumen (las semanas a la vista) y después el detalle día por día
    var cabecera = '<thead><tr><th class="pv-nombre">Cargo</th><th>Promesa</th><th>Ejecutado</th><th>Diferencia</th><th>% ejecución</th>';
    datos.semanas.forEach(function (semana) { cabecera += '<th class="pv-g-aus">' + rdEsc(semana) + '</th>'; });
    resumen = '<h4 class="rd-subtitulo" style="margin-top:4px;">Resumen del mes</h4><div class="table-responsive"><table class="table table-bordered pv-cons">' + cabecera +
              '<th>Días registrados</th></tr></thead><tbody>' + resumen + filaResumenMes(datos, nombreTotal, totProm, totEje, true) + '</tbody></table></div>' +
              '<h4 class="rd-subtitulo">Día por día</h4>';
    html += '<div class="aus-bloque aus-resumen"><div class="table-responsive aus-scroll"><table class="aus-tabla pv-mes">' + cabeceraMes(datos, anio, mes, nombreTotal) +
         '<tbody>' + filasMes(datos, totProm, totEje) + '</tbody></table></div></div>';
    $('#pvMesGrid').html(resumen + html);
    //Deja a la vista el día de hoy (o el final del mes si ya pasó)
    var hoyTh = $('#pvMesGrid .aus-hoy').first();
    if (hoyTh.length) { $('#pvMesGrid .aus-scroll').each(function () { this.scrollLeft = Math.max(0, hoyTh.position().left - 320); }); }
}

//---------------------------------------------------------------------------
// La promesa del peaje: cuántas personas por cargo, y los rangos especiales
//---------------------------------------------------------------------------
function cargarPromesa() {
    if (!centroPv) { return; }
    $.getJSON(URL_PV + '?op=promesa&centro=' + centroPv.ID_CENTRO_OP)
        .done(function (respuesta) {
            promesaPv = respuesta;
            $('#pvDefinir').toggle(!!respuesta.define);
            $('#pvNotaPromesa').html(respuesta.define ? '' : '<div class="rd-alerta-dia pv-alerta-gris"><i class="fa fa-lock"></i> Solo consulta: la promesa de este peaje la define ' +
                rdEsc(respuesta.coordDefine ? (respuesta.coordinador || 'el coordinador del proyecto') : 'quien tenga el permiso en su rol') + '.</div>');
            //Una casilla por cargo, con lo que rige hoy ya escrito
            var html = '', op = '';
            respuesta.cargos.forEach(function (cargo) {
                html += '<label class="pv-cargo-caja"><span>' + rdEsc(cargo.nombre) + '</span>' +
                     '<input type="text" inputmode="numeric" maxlength="3" class="form-control pv-nueva" data-cargo="' + cargo.id + '" data-hoy="' + (cargo.hoy === null ? '' : cargo.hoy) + '" value="' + (cargo.hoy === null ? '' : cargo.hoy) + '" placeholder="0">' +
                     '<small>' + (cargo.hoy === null ? 'sin definir' : 'hoy: ' + cargo.hoy) + '</small></label>';
                op += '<option value="' + cargo.id + '">' + rdEsc(cargo.nombre) + '</option>';
            });
            $('#pvCargosPromesa').html(html);
            $('#rgCargo').html(op);
            //Días que se repiten: una casilla por día de la semana y una caja por cargo (vacía = sigue igual)
            var semana = '', cajas = '';
            Object.keys(respuesta.dias).forEach(function (clave) {
                semana += '<label><input type="checkbox" class="pv-dia-rep" value="' + rdEsc(clave) + '"><span>' + rdEsc(clave === 'F' ? 'Festivos' : respuesta.dias[clave].substring(0, 3)) + '</span></label>';
            });
            respuesta.cargos.forEach(function (cargo) {
                cajas += '<label class="pv-cargo-caja"><span>' + rdEsc(cargo.nombre) + '</span>' +
                         '<input type="text" inputmode="numeric" maxlength="3" class="form-control pv-rep" data-cargo="' + cargo.id + '" placeholder="igual"></label>';
            });
            $('#pvSemana').html(semana);
            $('#pvCargosDias').html(cajas);
            pintarReglas(respuesta);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar la promesa del peaje.'); });
}

//"Viernes, sábado, domingo y festivos" a partir de "5,6,7,F"
function textoDiasPv(dias) {
    var nombres = (promesaPv && promesaPv.dias) || {}, lista = String(dias || '').split(',').map(function (clave) { return clave === 'F' ? 'festivos' : String(nombres[clave] || clave).toLowerCase(); });
    if (!lista.length) { return ''; }
    var textoDias = lista.length > 1 ? lista.slice(0, -1).join(', ') + ' y ' + lista[lista.length - 1] : lista[0];
    return textoDias.charAt(0).toUpperCase() + textoDias.substring(1);
}

//¿La regla de días que se repiten aplica hoy? (el día de la semana de hoy, o hoy es festivo)
function aplicaHoyPv(regla) {
    var dias = String(regla.DIAS).split(','), diaSemana = rdAFecha(cfgPv.hoy).getDay();
    return dias.indexOf(String(diaSemana === 0 ? 7 : diaSemana)) !== -1 || (dias.indexOf('F') !== -1 && (promesaPv.festivos || []).indexOf(cfgPv.hoy) !== -1);
}

//La lista de lo guardado. De cada cargo, la regla sin fecha final y sin días marcados más nueva que ya empezó es la vigente
function pintarReglas(datos) {
    var html = '', vigente = {};
    datos.reglas.forEach(function (regla) {                                           //vienen de la más nueva a la más vieja por cargo
        if (regla.HASTA === null && !regla.DIAS && regla.DESDE <= cfgPv.hoy && vigente[regla.ID_CARGO_AUS] === undefined) { vigente[regla.ID_CARGO_AUS] = regla.ID_PV_PROMESA; }
    });
    datos.reglas.forEach(function (regla) {
        var estado, rige;
        if (regla.DIAS) {                                                         //días que se repiten
            rige = '<span class="pv-dias-txt"><i class="fa fa-repeat"></i> ' + rdEsc(textoDiasPv(regla.DIAS)) + '</span><br>' +
                   (regla.HASTA !== null ? 'Del ' + rdFecha(regla.DESDE) + ' al ' + rdFecha(regla.HASTA) : 'Desde el ' + rdFecha(regla.DESDE)) +
                   (regla.NOMBRE ? '<br><em>' + rdEsc(regla.NOMBRE) + '</em>' : '');
            estado = (regla.HASTA !== null && regla.HASTA < cfgPv.hoy) ? '<span class="rd-tag">Ya pasó</span>'
                   : (regla.DESDE > cfgPv.hoy ? '<span class="rd-tag rd-tag-pend">Empieza después</span>'
                   : (aplicaHoyPv(regla) ? '<span class="rd-tag rd-tag-ok">Se repite, rige hoy</span>' : '<span class="rd-tag rd-tag-ok">Se repite</span>'));
        } else if (regla.HASTA !== null) {
            rige = 'Del ' + rdFecha(regla.DESDE) + ' al ' + rdFecha(regla.HASTA);
            estado = regla.HASTA < cfgPv.hoy ? '<span class="rd-tag">Rango que ya pasó</span>'
                   : (regla.DESDE <= cfgPv.hoy ? '<span class="rd-tag rd-tag-ok">Rango especial, rige hoy</span>' : '<span class="rd-tag rd-tag-pend">Rango especial, próximo</span>');
        } else {
            rige = 'Desde el ' + rdFecha(regla.DESDE);
            estado = regla.DESDE > cfgPv.hoy ? '<span class="rd-tag rd-tag-pend">Empieza después</span>'
                   : (vigente[regla.ID_CARGO_AUS] === regla.ID_PV_PROMESA ? '<span class="rd-tag rd-tag-ok">Vigente</span>' : '<span class="rd-tag">Anterior</span>');
        }
        var texto = regla.CARGO + ': ' + regla.CANTIDAD + ' por día' + (regla.DIAS ? ' los ' + textoDiasPv(regla.DIAS).toLowerCase() : '') + ', ' + $('<div>').html(rige.split('<br>')[regla.DIAS ? 1 : 0]).text().toLowerCase();
        html += '<tr><td>' + rdEsc(regla.CARGO) + '</td><td class="rd-num-col"><strong>' + parseInt(regla.CANTIDAD, 10) + '</strong></td><td>' + rige + '</td><td>' + estado + '</td>' +
             '<td>' + rdEsc(regla.QUIEN || '') + ' <span class="pv-suave">' + rdFecha(regla.FEC_MODIFICACION) + '</span></td>' +
             '<td class="rd-nowrap">' + (datos.define ? '<button type="button" class="btn btn-default btn-xs btn-pv-borrar" data-id="' + parseInt(regla.ID_PV_PROMESA, 10) + '" data-texto="' + rdEsc(texto) + '" title="Borrar esta regla"><i class="fa fa-trash"></i></button>' : '') + '</td></tr>';
    });
    $('#pvReglas').html(html || '<tr><td colspan="6" class="rd-vacio">Todavía no se ha definido la promesa de valor de este peaje.</td></tr>');
}

//Guarda los días que se repiten: los días marcados, cada cargo con su número
function guardarDias() {
    var dias = $('.pv-dia-rep:checked').map(function () { return this.value; }).get(), valores = {}, cantidad = 0, malo = null;
    $('.pv-rep').each(function () {
        var valor = this.value.replace(/\s/g, '');
        if (valor === '') { return; }
        if (!/^\d{1,3}$/.test(valor) && !malo) { malo = $(this); }
        valores[$(this).data('cargo')] = valor; cantidad++;
    });
    if (!dias.length) { alert('Marca al menos un día (por ejemplo viernes, sábado, domingo o festivos).'); return; }
    if (malo) { malo.focus(); alert('Escribe un número de 0 a 999.'); return; }
    if (!cantidad) { alert('Escribe cuántas personas van esos días en al menos un cargo.'); return; }
    if (!$('#dsDesde').val()) { alert('Elige desde qué día rige.'); return; }
    hanaBoton('#btnGuardarDias', true);
    $.post(URL_PV + '?op=promesaDias&centro=' + centroPv.ID_CENTRO_OP,
           { dias: dias, valores: JSON.stringify(valores), desde: $('#dsDesde').val(), hasta: $('#dsHasta').val(), nombre: $('#dsNombre').val() }, null, 'json')
        .done(function (respuesta) { rdAviso(respuesta.mensaje); $('.pv-rep, #dsNombre, #dsHasta').val(''); $('.pv-dia-rep').prop('checked', false); cargarPromesa(); cargarDia(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudieron guardar los días que se repiten.')); })
        .always(function () { hanaBoton('#btnGuardarDias', false); });
}

//Guarda la nueva promesa de valor (solo los cargos que cambiaron)
function guardarPromesa() {
    var valores = {}, cantidad = 0, desde = $('#pvDesde').val();
    $('.pv-nueva').each(function () {
        var valor = this.value.replace(/\s/g, '');
        if (valor === String($(this).data('hoy')) && desde === cfgPv.hoy) { return; } //quedó como estaba: no se manda
        if (valor === '') { return; }
        valores[$(this).data('cargo')] = valor; cantidad++;
    });
    if (!desde) { alert('Elige desde qué día rige la promesa.'); return; }
    if (!cantidad) { rdAviso('No cambiaste la promesa de ningún cargo.'); return; }
    hanaBoton('#btnGuardarPromesa', true);
    $.post(URL_PV + '?op=promesaGuardar&centro=' + centroPv.ID_CENTRO_OP, { desde: desde, valores: JSON.stringify(valores) }, null, 'json')
        .done(function (respuesta) { rdAviso(respuesta.mensaje); cargarPromesa(); cargarDia(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar la promesa.')); })
        .always(function () { hanaBoton('#btnGuardarPromesa', false); });
}

//Agrega una regla de promesa para un rango de fechas
function agregarRango() {
    var datos = { cargo: $('#rgCargo').val(), desde: $('#rgDesde').val(), hasta: $('#rgHasta').val(), cantidad: $('#rgCantidad').val() };
    if (!datos.desde || !datos.hasta || datos.cantidad === '') { alert('Elige el primer día, el último y cuántas personas.'); return; }
    hanaBoton('#btnAgregarRango', true);
    $.post(URL_PV + '?op=promesaRango&centro=' + centroPv.ID_CENTRO_OP, datos, null, 'json')
        .done(function (respuesta) { rdAviso(respuesta.mensaje); $('#rgDesde, #rgHasta, #rgCantidad').val(''); cargarPromesa(); cargarDia(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el rango.')); })
        .always(function () { hanaBoton('#btnAgregarRango', false); });
}

//Borra una regla de promesa (pregunta antes)
function borrarRegla(id, texto) {
    hanaConfirmar('¿Borrar esta regla? Vuelve a regir la anterior, y los días ya registrados se comparan con ella.', function () {
        $.post(URL_PV + '?op=promesaBorrar&centro=' + centroPv.ID_CENTRO_OP, { id: id }, null, 'json')
            .done(function (respuesta) { rdAviso(respuesta.mensaje); cargarPromesa(); cargarDia(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo borrar la regla.')); });
    }, { aceptar: 'Sí, borrar', cancelar: 'No', titulo: texto });
}
