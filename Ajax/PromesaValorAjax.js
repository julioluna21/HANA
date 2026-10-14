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
        .done(function (c) {
            cfgPv = c;
            fechaPv = c.hoy;
            rdPeriodo('#pvMes', '#pvAnio', c.hoy);                            //el mes y el año de hoy
            $('#pvOtroDia').attr('max', c.hoy);
            $('#pvDesde').val(c.hoy);
            $('#dsDesde').val(c.hoy);
            var h = '';
            c.proyectos.forEach(function (p) {
                h += '<option value="' + parseInt(p.ID_PROYECTO, 10) + '">' + rdEsc(p.NOM_PROYECTO) + (p.COORDINA ? ' (el tuyo)' : '') + '</option>';
            });
            $('#pvProyecto').html(h).val(String(c.proyectoInicial));
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
    $(document).on('keydown', '.pv-eje', function (e) {                       //Enter y las flechas bajan o suben de cargo
        var i = $('.pv-eje').index(this), destino = e.key === 'Enter' || e.key === 'ArrowDown' ? i + 1 : (e.key === 'ArrowUp' ? i - 1 : -1);
        if (destino >= 0 && destino < $('.pv-eje').length) { e.preventDefault(); $('.pv-eje').eq(destino).focus(); }
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
    var q = new URLSearchParams(window.location.search), idCentro = parseInt(q.get('centro'), 10) || 0, idProy = parseInt(q.get('proyecto'), 10) || 0;
    cfgPv.proyectos.forEach(function (p) {
        if (+p.ID_PROYECTO === idProy) { $('#pvProyecto').val(String(idProy)); }
        p.CENTROS.forEach(function (c) { if (+c.ID_CENTRO_OP === idCentro) { $('#pvProyecto').val(String(p.ID_PROYECTO)); cfgPv.centroInicial = idCentro; } });
    });
    var f = q.get('fecha') || '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(f) && f <= cfgPv.hoy) { fechaPv = f; }
    //Desde el consolidado se llega con ?ver=mes&anio=&mes=: se abre la pestaña del mes en ese periodo
    if (q.get('ver') === 'mes') {
        if ($('#pvAnio option[value="' + q.get('anio') + '"]').length) { $('#pvAnio').val(q.get('anio')); }
        if (+q.get('mes') >= 1 && +q.get('mes') <= 12) { $('#pvMes').val(String(+q.get('mes'))); }
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
    proyPv = cfgPv.proyectos.filter(function (p) { return +p.ID_PROYECTO === id; })[0] || cfgPv.proyectos[0];
    var h = '';
    proyPv.CENTROS.forEach(function (c) { h += '<option value="' + parseInt(c.ID_CENTRO_OP, 10) + '">' + (c.TIPO_CENTRO === 'BASCULA' ? 'Báscula ' : '') + rdEsc(c.NOM_CENTRO_OP) + '</option>'; });
    $('#pvCentro').html(h);
    if (cfgPv.centroInicial) { $('#pvCentro').val(String(cfgPv.centroInicial)); cfgPv.centroInicial = 0; } //solo la primera vez
    elegirCentroPv(+($('#pvCentro').val() || 0));
}

//Al elegir un peaje: pone al día la pestaña que esté abierta
function elegirCentroPv(id) {
    centroPv = proyPv.CENTROS.filter(function (c) { return +c.ID_CENTRO_OP === id; })[0] || proyPv.CENTROS[0];
    cargarDia();
    if ($('#tabMes').hasClass('active')) { cargarMes(); }                    //la pestaña que esté abierta se pone al día
    if ($('#tabPromesa').hasClass('active')) { cargarPromesa(); }
}

//---------------------------------------------------------------------------
// El día
//---------------------------------------------------------------------------
//Los botones de los días que se pueden llenar: "Hoy" y, si hay, los anteriores permitidos o habilitados
function pintarDias() {
    var h = '';
    cfgPv.dias.forEach(function (d) {
        var f = rdAFecha(d.fecha), rotulo = d.tipo === 'HOY' ? 'Hoy' : RD_DIAS_SEMANA[f.getDay()] + ' ' + rdDos(f.getDate()) + '/' + rdDos(f.getMonth() + 1);
        var titulo = d.tipo === 'HABILITADO' ? 'El administrador te habilitó este día' : (d.tipo === 'ATRAS' ? 'Todavía se puede registrar' : 'El día de hoy');
        h += '<button type="button" class="btn btn-default btn-sm" data-fecha="' + rdEsc(d.fecha) + '" title="' + titulo + '">' +
             (d.tipo === 'HABILITADO' ? '<i class="fa fa-unlock"></i> ' : '') + rotulo + '</button>';
    });
    $('#pvDias').html(h);
}

//Cambia al día indicado y lo carga
function verDia(fecha) { fechaPv = fecha; cargarDia(); }

//Trae el día del peaje: lo prometido y lo ejecutado por cargo
function cargarDia() {
    if (!centroPv) { return; }
    $.getJSON(URL_PV + '?op=dia&centro=' + centroPv.ID_CENTRO_OP + '&fecha=' + encodeURIComponent(fechaPv))
        .done(function (d) {
            diaPv = d; rdCambiosGuardados();
            rdMarcarDia('#pvDias', d.fecha);                                  //resalta el botón del día
            $('#pvOtroDia').val($('#pvDias [data-fecha="' + d.fecha + '"]').length ? '' : d.fecha); //si no es un botón, queda en el campo
            $('#pvQuien').html('<i class="fa fa-user"></i> Coordinador: <strong>' + rdEsc(d.coordinador || 'sin asignar') + '</strong>' +
                               ' <span class="rd-tag">' + rdEsc(textoDia(d.fecha)) + '</span>');
            pintarChips(d);
            pintarNota(d);
            pintarFiltroPv(d.cargos);
            pintarFilas(d);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el día.'); });
}

//"hoy, martes 06/10/2026" o "lunes 05/10/2026"
function textoDia(fecha) {
    var nombres = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    return (fecha === cfgPv.hoy ? 'hoy, ' : '') + nombres[rdAFecha(fecha).getDay()] + ' ' + rdFecha(fecha);
}

//Un botón por peaje del proyecto: verde si ya tiene el ejecutado de ese día
function pintarChips(d) {
    var h = '';
    proyPv.CENTROS.forEach(function (c) {
        var id = parseInt(c.ID_CENTRO_OP, 10), listo = !!d.avance[id], sinPromesa = d.conPromesa.indexOf(id) === -1;
        h += '<span class="aus-centro-chip' + (id === +centroPv.ID_CENTRO_OP ? ' activo' : '') + (listo ? ' con' : '') + '" data-id="' + id + '" role="button" tabindex="0"' +
             ' title="' + (listo ? 'Ya tiene el ejecutado de ese día' : (sinPromesa ? 'Todavía no tiene promesa de valor' : 'Falta registrar el ejecutado')) + '">' +
             '<i class="fa ' + (listo ? 'fa-check' : (sinPromesa ? 'fa-minus' : 'fa-circle-o')) + '"></i> ' + rdEsc(c.NOM_CENTRO_OP) + '</span>';
    });
    $('#pvCentros').html(h);
}

//El aviso de arriba de la tabla: registrado, pendiente, solo consulta o sin promesa
function pintarNota(d) {
    var h = '';
    if (!d.filas.length) {
        h = '<div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-info-circle"></i> Este peaje todavía no tiene promesa de valor para ese día. ' +
            (d.define ? 'Defínela en la pestaña <a href="#" id="pvIrPromesa">Promesa del peaje</a>.' : 'La define ' + rdEsc(d.coordinador || 'el coordinador del proyecto') + '.') + '</div>';
    } else if (d.registrado) {
        h = '<div class="rd-alerta-dia rd-alerta-ok"><i class="fa fa-check-circle"></i> Ejecutado registrado' +
            (d.quien ? ' por ' + rdEsc(d.quien) : '') + (d.cuando ? ' el ' + rdFecha(d.cuando) + ' a las ' + rdHora(String(d.cuando).substring(11)) : '') + '.' +
            (d.editable ? ' Puedes corregirlo y volver a guardar.' : '') + '</div>';
    } else {                                                              //hay promesa pero nadie ha registrado ese día
        h = '<div class="rd-alerta-dia rd-alerta-pend"><i class="fa fa-exclamation-circle"></i> ' +
            (d.editable ? 'Falta registrar el ejecutado de ' : 'Todavía no se ha registrado el ejecutado de ') + rdEsc(textoDia(d.fecha)) + '.</div>';
    }
    if (d.filas.length && !d.editable) { h += '<div class="rd-alerta-dia pv-alerta-gris"><i class="fa fa-lock"></i> ' + rdEsc(d.motivo) + '</div>'; }
    $('#pvNotaDia').html(h);
    $('#pvIrPromesa').on('click', function (e) { e.preventDefault(); $('#tabPromesaLink').tab('show'); });
}

//La tabla del día: un renglón por cargo
function pintarFilas(d) {
    var h = '';
    d.filas.forEach(function (f) {
        var motivos = f.motivos.map(function (m) { return '<span class="' + (m.cuenta ? '' : 'aus-no-cuenta') + '">' + rdEsc(m.nombre.toLowerCase()) + ' ' + m.n + '</span>'; }).join(', ');
        h += '<tr data-cargo="' + f.id + '" data-promesa="' + f.promesa + '" data-ausencias="' + f.ausencias + '"' + (enFiltroPv(f.id) ? '' : ' class="pv-oculta" style="display:none;"') + '>' +
             '<td class="pv-cargo">' + rdEsc(f.nombre) + '</td>' +
             '<td class="rd-num-col pv-prom">' + f.promesa + '</td>' +
             '<td class="pv-col-eje">' + (d.editable
                 ? '<input type="text" inputmode="numeric" maxlength="3" class="form-control pv-eje" value="' + (f.ejecutado === null ? '' : f.ejecutado) + '" aria-label="Ejecutado de ' + rdEsc(f.nombre) + '">'
                 : '<span class="pv-eje-ver">' + (f.ejecutado === null ? '—' : f.ejecutado) + '</span>') + '</td>' +
             '<td class="rd-num-col pv-dif"></td>' +
             '<td class="pv-aus">' + (motivos ? '<strong>' + f.ausencias + '</strong> · ' + motivos : '<span class="pv-suave">Sin ausencias registradas</span>') + '<div class="pv-explica"></div></td></tr>';
    });
    $('#pvFilas').html(h || '<tr><td colspan="5" class="rd-vacio">No hay cargos con promesa de valor para ese día.</td></tr>');
    $('#pvAcciones').toggle(!!d.editable && d.filas.length > 0);
    recalcularDia();
}

//Lo que hay escrito en el ejecutado de un renglón: un número, o null si está vacío
function ejecutadoDe(tr) {
    var el = tr.find('.pv-eje'), t = el.length ? el.val() : tr.find('.pv-eje-ver').text();
    return /^\d+$/.test(t) ? parseInt(t, 10) : null;
}

//Un número con su signo a la vista: +3, −2 o 0
function conSigno(n) { return (n > 0 ? '+' : (n < 0 ? '−' : '')) + Math.abs(n); }

//La diferencia como etiqueta: −2 en vino, +1 en menta, 0 en gris
function etiquetaDif(n) {
    if (n === null) { return '<span class="pv-suave">—</span>'; }
    return '<span class="pv-difn ' + (n < 0 ? 'pv-menos' : (n > 0 ? 'pv-mas' : 'pv-igual')) + '">' + conSigno(n) + '</span>';
}

//Saca la diferencia de cada cargo, los totales y si las ausencias explican lo que faltó
function recalcularDia() {
    var tProm = 0, tEje = 0, tAus = 0, completos = 0, n = 0;
    $('#pvFilas tr[data-cargo]').each(function () {
        var tr = $(this), prom = +tr.data('promesa'), aus = +tr.data('ausencias'), eje = ejecutadoDe(tr), nota = '';
        if (tr.hasClass('pv-oculta')) { return; }                             //fuera del filtro: no entra en los totales
        n++; tProm += prom; tAus += aus;
        if (eje !== null) {
            completos++; tEje += eje;
            var falta = prom - eje;                                           //personas que faltaron frente a lo prometido
            if (falta > aus) { nota = '<i class="fa fa-exclamation-triangle"></i> ' + (falta - aus === 1 ? 'Falta 1 persona' : 'Faltan ' + (falta - aus) + ' personas') + ' por explicar en Ausentismo.'; }
            else if (falta < aus && aus > 0) { nota = 'Hay más ausencias anotadas que personas faltantes: seguramente alguien cubrió el puesto.'; }
        }
        tr.find('.pv-dif').html(etiquetaDif(eje === null ? null : eje - prom));
        tr.find('.pv-explica').html(nota);
    });
    var todo = n > 0 && completos === n;
    $('#pvTotales').html(n ? '<tr><th class="pv-cargo">' + (filtroPv.length ? 'Cargos elegidos' : 'Todo el peaje') + '</th><th class="rd-num-col pv-prom">' + tProm + '</th><th class="pv-col-eje">' + (completos ? tEje : '—') + '</th>' +
        '<th class="rd-num-col pv-dif">' + etiquetaDif(todo ? tEje - tProm : null) + '</th><th class="pv-aus">' + (tAus ? tAus + (tAus === 1 ? ' ausencia registrada' : ' ausencias registradas') : '') + '</th></tr>' : '');
}

//Al escribir el ejecutado: deja solo números y vuelve a sumar
function escribirEjecutado() {
    var v = this.value.replace(/\D/g, '').substring(0, 3);                    //solo números, máximo 999
    if (v !== this.value) { this.value = v; }
    recalcularDia();
}

//Pone en cada cargo la promesa menos las ausencias ya registradas. No guarda: deja revisar
function llenarSegunAusentismo() {
    var cambio = false;
    $('#pvFilas tr[data-cargo]').each(function () {
        var tr = $(this), sugerido = Math.max(0, +tr.data('promesa') - +tr.data('ausencias')), el = tr.find('.pv-eje');
        if (el.val() !== String(sugerido)) { el.val(sugerido).addClass('pv-sugerido'); cambio = true; }
    });
    if (cambio) { rdHayCambios = true; }
    recalcularDia();
    rdAviso(cambio ? 'Listo: revisa los números y dale Guardar.' : 'Ya estaba igual a lo esperado.');
}

//Revisa que todos los cargos tengan su ejecutado y guarda el día
function guardarDia() {
    var valores = {}, falta = null;
    $('#pvFilas tr[data-cargo]').each(function () {
        var eje = ejecutadoDe($(this));
        if (eje === null && !falta) { falta = $(this); }
        valores[$(this).data('cargo')] = eje;
    });
    if (falta) {
        if (falta.hasClass('pv-oculta')) { filtroPv = []; pintarFiltroPv(diaPv.cargos); aplicarFiltroDia(); } //estaba escondido por el filtro: se muestra todo
        falta.find('.pv-eje').focus(); alert('Falta el ejecutado de ' + falta.find('.pv-cargo').text() + '. Si no trabajó nadie, escribe 0.'); return;
    }
    hanaBoton('#btnGuardarPv', true);
    $.post(URL_PV + '?op=guardar&centro=' + centroPv.ID_CENTRO_OP, { fecha: fechaPv, valores: JSON.stringify(valores) }, null, 'json')
        .done(function (r) { rdCambiosGuardados(); rdAviso(r.mensaje); cargarDia(); })
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
    var ids = cargos.map(function (c) { return +c.id; });
    filtroPv = filtroPv.filter(function (id) { return ids.indexOf(id) !== -1; }); //los de otro peaje no cuentan
    if (cargos.length < 2) { $('#pvFiltroCargos').hide().html(''); return; }
    var h = '<span><i class="fa fa-filter"></i> Ver cargos:</span>' +
            '<button type="button" class="pv-fc pv-fc-todos' + (filtroPv.length ? '' : ' activo') + '" data-id="0" aria-pressed="' + !filtroPv.length + '">Todos</button>';
    cargos.forEach(function (c) {
        var on = filtroPv.indexOf(+c.id) !== -1;
        h += '<button type="button" class="pv-fc' + (on ? ' activo' : '') + '" data-id="' + parseInt(c.id, 10) + '" aria-pressed="' + on + '">' + rdEsc(nombreCargoPv(c.nombre)) + '</button>';
    });
    if (filtroPv.length) { h += '<span class="pv-fc-nota">Al guardar el día se guardan todos los cargos.</span>'; }
    $('#pvFiltroCargos').html(h).show();
}

//"RECOLECTOR DE PEAJE" -> "Recolector de peaje"
function nombreCargoPv(t) { t = String(t).toLowerCase(); return t.charAt(0).toUpperCase() + t.substring(1); }

//Prende o apaga un cargo del filtro (0 = todos)
function cambiarFiltroPv(id) {
    if (!id) { filtroPv = []; }
    else if (filtroPv.indexOf(id) === -1) { filtroPv.push(id); }
    else { filtroPv = filtroPv.filter(function (x) { return x !== id; }); }
    if (diaPv) { pintarFiltroPv(diaPv.cargos); }
    aplicarFiltroDia();
    if (mesPv) { pintarMes(mesPv.d, mesPv.anio, mesPv.mes); }
}

//Muestra solo los cargos elegidos en el filtro
function aplicarFiltroDia() {
    $('#pvFilas tr[data-cargo]').each(function () {
        var si = enFiltroPv($(this).data('cargo'));
        $(this).toggleClass('pv-oculta', !si).toggle(si);
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
        .done(function (d) { mesPv = { d: d, anio: anio, mes: mes }; pintarMes(d, anio, mes); })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el mes.'); });
}

//En qué semana cae un día: 0 a 3, y 4 para los días 29 a 31 (igual que en el servidor)
function semanaDe(dia) { return Math.min(4, Math.floor((dia - 1) / 7)); }

//El encabezado de un bloque: el cargo, los días (con su letra) y las semanas
function cabeceraMes(d, anio, mes, titulo) {
    var h = '<thead><tr><th class="aus-fija">' + titulo + '</th>';
    for (var dia = 1; dia <= d.diasMes; dia++) {
        var f = new Date(anio, mes - 1, dia), txt = rdATexto(f), libre = f.getDay() === 0 || f.getDay() === 6 || d.festivos.indexOf(txt) !== -1;
        h += '<th class="aus-dia' + (libre ? ' aus-finde' : '') + (txt === cfgPv.hoy ? ' aus-hoy' : '') + '"' + (d.festivos.indexOf(txt) !== -1 ? ' title="Festivo"' : '') + '>' +
             dia + '<small>' + PV_LETRA_DIA[f.getDay()] + '</small></th>';
    }
    d.semanas.forEach(function (s) { h += '<th class="aus-tot pv-sem">' + rdEsc(s) + '</th>'; });
    return h + '<th class="aus-tot">Total</th></tr></thead>';
}

//Los tres renglones de un bloque a partir de la promesa y el ejecutado por día: {dia: n}
function filasMes(d, prom, eje) {
    var fp = '<tr class="pv-f-prom"><th class="aus-fija">Promesa de valor</th>', fe = '<tr class="pv-f-eje"><th class="aus-fija">Ejecutado</th>',
        fd = '<tr class="pv-f-dif"><th class="aus-fija">Diferencia</th>', sp = [0, 0, 0, 0, 0], se = [0, 0, 0, 0, 0], hay = [false, false, false, false, false];
    for (var dia = 1; dia <= d.diasMes; dia++) {
        var p = prom[dia] || 0, e = eje[dia], reg = e !== undefined, s = semanaDe(dia);
        if (reg) { sp[s] += p; se[s] += e; hay[s] = true; }                   //las semanas solo cuentan los días registrados
        fp += '<td class="' + (reg ? '' : 'pv-plan') + '">' + (p || '') + '</td>';
        fe += '<td>' + (reg ? e : '') + '</td>';
        fd += '<td class="' + (reg ? (e - p < 0 ? 'pv-menos' : (e - p > 0 ? 'pv-mas' : 'pv-igual')) : '') + '">' + (reg ? conSigno(e - p) : '') + '</td>';
    }
    var tp = 0, te = 0, alguno = false;
    for (var i = 0; i < 5; i++) {
        tp += sp[i]; te += se[i]; alguno = alguno || hay[i];
        var dif = se[i] - sp[i];
        fp += '<td class="aus-tot">' + (hay[i] ? sp[i] : '') + '</td>';
        fe += '<td class="aus-tot">' + (hay[i] ? se[i] : '') + '</td>';
        fd += '<td class="aus-tot ' + (hay[i] ? (dif < 0 ? 'pv-menos' : (dif > 0 ? 'pv-mas' : 'pv-igual')) : '') + '">' + (hay[i] ? conSigno(dif) : '') + '</td>';
    }
    var t = te - tp;
    return fp + '<td class="aus-tot">' + (alguno ? tp : '') + '</td></tr>' + fe + '<td class="aus-tot">' + (alguno ? te : '') + '</td></tr>' +
           fd + '<td class="aus-tot ' + (alguno ? (t < 0 ? 'pv-menos' : (t > 0 ? 'pv-mas' : 'pv-igual')) : '') + '">' + (alguno ? conSigno(t) : '') + '</td></tr>';
}

//Una celda de diferencia para el resumen: vacía si no hay días registrados
function celdaDif(hay, dif) {
    return '<td class="' + (hay ? (dif < 0 ? 'pv-menos' : (dif > 0 ? 'pv-mas' : 'pv-cero')) : 'pv-cero') + '">' + (hay ? conSigno(dif) : '—') + '</td>';
}

//El renglón de un cargo en el resumen del mes: totales y diferencia por semana (solo días registrados)
function filaResumenMes(d, nombre, prom, eje, total) {
    var sp = [0, 0, 0, 0, 0], se = [0, 0, 0, 0, 0], hay = [false, false, false, false, false], tp = 0, te = 0, dias = 0;
    for (var dia = 1; dia <= d.diasMes; dia++) {
        if (eje[dia] === undefined) { continue; }
        var s = semanaDe(dia); sp[s] += prom[dia] || 0; se[s] += eje[dia]; hay[s] = true; tp += prom[dia] || 0; te += eje[dia]; dias++;
    }
    var h = '<tr class="' + (total ? 'pv-total' : '') + '"><td class="pv-nombre">' + rdEsc(nombre) + '</td><td>' + (dias ? tp : '—') + '</td><td>' + (dias ? te : '—') + '</td>' +
            celdaDif(dias > 0, te - tp) + '<td>' + (tp > 0 ? (Math.round(1000 * te / tp) / 10).toString().replace('.', ',') + ' %' : '—') + '</td>';
    for (var i = 0; i < 5; i++) { h += celdaDif(hay[i], se[i] - sp[i]); }
    return h + '<td>' + dias + '</td></tr>';
}

//Pinta la grilla del mes: prometido contra ejecutado por cargo y día
function pintarMes(d, anio, mes) {
    if (!d.cargos.length) {
        $('#pvMesGrid').html('<p class="rd-vacio">Este peaje no tiene promesa de valor ni ejecutado en ese mes.</p>');
        return;
    }
    var cargos = d.cargos.filter(function (c) { return enFiltroPv(c.id); });  //solo los cargos del filtro
    if (!cargos.length) {
        $('#pvMesGrid').html('<p class="rd-vacio">Los cargos elegidos en el filtro no tienen promesa de valor ni ejecutado en ese mes.</p>');
        return;
    }
    var nombreTotal = filtroPv.length ? 'Cargos elegidos' : 'Todo el peaje';
    var h = '', totProm = {}, totEje = {}, resumen = '';
    cargos.forEach(function (c) {
        //Se va sumando para el bloque final de todo el peaje. Un día cuenta como registrado si algún cargo lo tiene
        for (var dia = 1; dia <= d.diasMes; dia++) {
            if (c.promesa[dia]) { totProm[dia] = (totProm[dia] || 0) + c.promesa[dia]; }
            if (c.ejecutado[dia] !== undefined) { totEje[dia] = (totEje[dia] || 0) + c.ejecutado[dia]; }
        }
        resumen += filaResumenMes(d, c.nombre, c.promesa, c.ejecutado, false);
        h += '<div class="aus-bloque"><div class="table-responsive aus-scroll"><table class="aus-tabla pv-mes">' + cabeceraMes(d, anio, mes, rdEsc(c.nombre)) +
             '<tbody>' + filasMes(d, c.promesa, c.ejecutado) + '</tbody></table></div></div>';
    });
    //Primero el resumen (las semanas a la vista) y después el detalle día por día
    var cab = '<thead><tr><th class="pv-nombre">Cargo</th><th>Promesa</th><th>Ejecutado</th><th>Diferencia</th><th>% ejecución</th>';
    d.semanas.forEach(function (s) { cab += '<th class="pv-g-aus">' + rdEsc(s) + '</th>'; });
    resumen = '<h4 class="rd-subtitulo" style="margin-top:4px;">Resumen del mes</h4><div class="table-responsive"><table class="table table-bordered pv-cons">' + cab +
              '<th>Días registrados</th></tr></thead><tbody>' + resumen + filaResumenMes(d, nombreTotal, totProm, totEje, true) + '</tbody></table></div>' +
              '<h4 class="rd-subtitulo">Día por día</h4>';
    h += '<div class="aus-bloque aus-resumen"><div class="table-responsive aus-scroll"><table class="aus-tabla pv-mes">' + cabeceraMes(d, anio, mes, nombreTotal) +
         '<tbody>' + filasMes(d, totProm, totEje) + '</tbody></table></div></div>';
    $('#pvMesGrid').html(resumen + h);
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
        .done(function (d) {
            promesaPv = d;
            $('#pvDefinir').toggle(!!d.define);
            $('#pvNotaPromesa').html(d.define ? '' : '<div class="rd-alerta-dia pv-alerta-gris"><i class="fa fa-lock"></i> Solo consulta: la promesa de este peaje la define ' +
                rdEsc(d.coordDefine ? (d.coordinador || 'el coordinador del proyecto') : 'quien tenga el permiso en su rol') + '.</div>');
            //Una casilla por cargo, con lo que rige hoy ya escrito
            var h = '', op = '';
            d.cargos.forEach(function (c) {
                h += '<label class="pv-cargo-caja"><span>' + rdEsc(c.nombre) + '</span>' +
                     '<input type="text" inputmode="numeric" maxlength="3" class="form-control pv-nueva" data-cargo="' + c.id + '" data-hoy="' + (c.hoy === null ? '' : c.hoy) + '" value="' + (c.hoy === null ? '' : c.hoy) + '" placeholder="0">' +
                     '<small>' + (c.hoy === null ? 'sin definir' : 'hoy: ' + c.hoy) + '</small></label>';
                op += '<option value="' + c.id + '">' + rdEsc(c.nombre) + '</option>';
            });
            $('#pvCargosPromesa').html(h);
            $('#rgCargo').html(op);
            //Días que se repiten: una casilla por día de la semana y una caja por cargo (vacía = sigue igual)
            var sem = '', cajas = '';
            Object.keys(d.dias).forEach(function (k) {
                sem += '<label><input type="checkbox" class="pv-dia-rep" value="' + rdEsc(k) + '"><span>' + rdEsc(k === 'F' ? 'Festivos' : d.dias[k].substring(0, 3)) + '</span></label>';
            });
            d.cargos.forEach(function (c) {
                cajas += '<label class="pv-cargo-caja"><span>' + rdEsc(c.nombre) + '</span>' +
                         '<input type="text" inputmode="numeric" maxlength="3" class="form-control pv-rep" data-cargo="' + c.id + '" placeholder="igual"></label>';
            });
            $('#pvSemana').html(sem);
            $('#pvCargosDias').html(cajas);
            pintarReglas(d);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar la promesa del peaje.'); });
}

//"Viernes, sábado, domingo y festivos" a partir de "5,6,7,F"
function textoDiasPv(dias) {
    var nombres = (promesaPv && promesaPv.dias) || {}, lista = String(dias || '').split(',').map(function (k) { return k === 'F' ? 'festivos' : String(nombres[k] || k).toLowerCase(); });
    if (!lista.length) { return ''; }
    var t = lista.length > 1 ? lista.slice(0, -1).join(', ') + ' y ' + lista[lista.length - 1] : lista[0];
    return t.charAt(0).toUpperCase() + t.substring(1);
}

//¿La regla de días que se repiten aplica hoy? (el día de la semana de hoy, o hoy es festivo)
function aplicaHoyPv(r) {
    var dias = String(r.DIAS).split(','), n = rdAFecha(cfgPv.hoy).getDay();
    return dias.indexOf(String(n === 0 ? 7 : n)) !== -1 || (dias.indexOf('F') !== -1 && (promesaPv.festivos || []).indexOf(cfgPv.hoy) !== -1);
}

//La lista de lo guardado. De cada cargo, la regla sin fecha final y sin días marcados más nueva que ya empezó es la vigente
function pintarReglas(d) {
    var h = '', vigente = {};
    d.reglas.forEach(function (r) {                                           //vienen de la más nueva a la más vieja por cargo
        if (r.HASTA === null && !r.DIAS && r.DESDE <= cfgPv.hoy && vigente[r.ID_CARGO_AUS] === undefined) { vigente[r.ID_CARGO_AUS] = r.ID_PV_PROMESA; }
    });
    d.reglas.forEach(function (r) {
        var estado, rige;
        if (r.DIAS) {                                                         //días que se repiten
            rige = '<span class="pv-dias-txt"><i class="fa fa-repeat"></i> ' + rdEsc(textoDiasPv(r.DIAS)) + '</span><br>' +
                   (r.HASTA !== null ? 'Del ' + rdFecha(r.DESDE) + ' al ' + rdFecha(r.HASTA) : 'Desde el ' + rdFecha(r.DESDE)) +
                   (r.NOMBRE ? '<br><em>' + rdEsc(r.NOMBRE) + '</em>' : '');
            estado = (r.HASTA !== null && r.HASTA < cfgPv.hoy) ? '<span class="rd-tag">Ya pasó</span>'
                   : (r.DESDE > cfgPv.hoy ? '<span class="rd-tag rd-tag-pend">Empieza después</span>'
                   : (aplicaHoyPv(r) ? '<span class="rd-tag rd-tag-ok">Se repite, rige hoy</span>' : '<span class="rd-tag rd-tag-ok">Se repite</span>'));
        } else if (r.HASTA !== null) {
            rige = 'Del ' + rdFecha(r.DESDE) + ' al ' + rdFecha(r.HASTA);
            estado = r.HASTA < cfgPv.hoy ? '<span class="rd-tag">Rango que ya pasó</span>'
                   : (r.DESDE <= cfgPv.hoy ? '<span class="rd-tag rd-tag-ok">Rango especial, rige hoy</span>' : '<span class="rd-tag rd-tag-pend">Rango especial, próximo</span>');
        } else {
            rige = 'Desde el ' + rdFecha(r.DESDE);
            estado = r.DESDE > cfgPv.hoy ? '<span class="rd-tag rd-tag-pend">Empieza después</span>'
                   : (vigente[r.ID_CARGO_AUS] === r.ID_PV_PROMESA ? '<span class="rd-tag rd-tag-ok">Vigente</span>' : '<span class="rd-tag">Anterior</span>');
        }
        var texto = r.CARGO + ': ' + r.CANTIDAD + ' por día' + (r.DIAS ? ' los ' + textoDiasPv(r.DIAS).toLowerCase() : '') + ', ' + $('<div>').html(rige.split('<br>')[r.DIAS ? 1 : 0]).text().toLowerCase();
        h += '<tr><td>' + rdEsc(r.CARGO) + '</td><td class="rd-num-col"><strong>' + parseInt(r.CANTIDAD, 10) + '</strong></td><td>' + rige + '</td><td>' + estado + '</td>' +
             '<td>' + rdEsc(r.QUIEN || '') + ' <span class="pv-suave">' + rdFecha(r.FEC_MODIFICACION) + '</span></td>' +
             '<td class="rd-nowrap">' + (d.define ? '<button type="button" class="btn btn-default btn-xs btn-pv-borrar" data-id="' + parseInt(r.ID_PV_PROMESA, 10) + '" data-texto="' + rdEsc(texto) + '" title="Borrar esta regla"><i class="fa fa-trash"></i></button>' : '') + '</td></tr>';
    });
    $('#pvReglas').html(h || '<tr><td colspan="6" class="rd-vacio">Todavía no se ha definido la promesa de valor de este peaje.</td></tr>');
}

//Guarda los días que se repiten: los días marcados, cada cargo con su número
function guardarDias() {
    var dias = $('.pv-dia-rep:checked').map(function () { return this.value; }).get(), valores = {}, n = 0, malo = null;
    $('.pv-rep').each(function () {
        var v = this.value.replace(/\s/g, '');
        if (v === '') { return; }
        if (!/^\d{1,3}$/.test(v) && !malo) { malo = $(this); }
        valores[$(this).data('cargo')] = v; n++;
    });
    if (!dias.length) { alert('Marca al menos un día (por ejemplo viernes, sábado, domingo o festivos).'); return; }
    if (malo) { malo.focus(); alert('Escribe un número de 0 a 999.'); return; }
    if (!n) { alert('Escribe cuántas personas van esos días en al menos un cargo.'); return; }
    if (!$('#dsDesde').val()) { alert('Elige desde qué día rige.'); return; }
    hanaBoton('#btnGuardarDias', true);
    $.post(URL_PV + '?op=promesaDias&centro=' + centroPv.ID_CENTRO_OP,
           { dias: dias, valores: JSON.stringify(valores), desde: $('#dsDesde').val(), hasta: $('#dsHasta').val(), nombre: $('#dsNombre').val() }, null, 'json')
        .done(function (r) { rdAviso(r.mensaje); $('.pv-rep, #dsNombre, #dsHasta').val(''); $('.pv-dia-rep').prop('checked', false); cargarPromesa(); cargarDia(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudieron guardar los días que se repiten.')); })
        .always(function () { hanaBoton('#btnGuardarDias', false); });
}

//Guarda la nueva promesa de valor (solo los cargos que cambiaron)
function guardarPromesa() {
    var valores = {}, n = 0, desde = $('#pvDesde').val();
    $('.pv-nueva').each(function () {
        var v = this.value.replace(/\s/g, '');
        if (v === String($(this).data('hoy')) && desde === cfgPv.hoy) { return; } //quedó como estaba: no se manda
        if (v === '') { return; }
        valores[$(this).data('cargo')] = v; n++;
    });
    if (!desde) { alert('Elige desde qué día rige la promesa.'); return; }
    if (!n) { rdAviso('No cambiaste la promesa de ningún cargo.'); return; }
    hanaBoton('#btnGuardarPromesa', true);
    $.post(URL_PV + '?op=promesaGuardar&centro=' + centroPv.ID_CENTRO_OP, { desde: desde, valores: JSON.stringify(valores) }, null, 'json')
        .done(function (r) { rdAviso(r.mensaje); cargarPromesa(); cargarDia(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar la promesa.')); })
        .always(function () { hanaBoton('#btnGuardarPromesa', false); });
}

//Agrega una regla de promesa para un rango de fechas
function agregarRango() {
    var datos = { cargo: $('#rgCargo').val(), desde: $('#rgDesde').val(), hasta: $('#rgHasta').val(), cantidad: $('#rgCantidad').val() };
    if (!datos.desde || !datos.hasta || datos.cantidad === '') { alert('Elige el primer día, el último y cuántas personas.'); return; }
    hanaBoton('#btnAgregarRango', true);
    $.post(URL_PV + '?op=promesaRango&centro=' + centroPv.ID_CENTRO_OP, datos, null, 'json')
        .done(function (r) { rdAviso(r.mensaje); $('#rgDesde, #rgHasta, #rgCantidad').val(''); cargarPromesa(); cargarDia(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el rango.')); })
        .always(function () { hanaBoton('#btnAgregarRango', false); });
}

//Borra una regla de promesa (pregunta antes)
function borrarRegla(id, texto) {
    hanaConfirmar('¿Borrar esta regla? Vuelve a regir la anterior, y los días ya registrados se comparan con ella.', function () {
        $.post(URL_PV + '?op=promesaBorrar&centro=' + centroPv.ID_CENTRO_OP, { id: id }, null, 'json')
            .done(function (r) { rdAviso(r.mensaje); cargarPromesa(); cargarDia(); })
            .fail(function (xhr) { alert(rdError(xhr, 'No se pudo borrar la regla.')); });
    }, { aceptar: 'Sí, borrar', cancelar: 'No', titulo: texto });
}
