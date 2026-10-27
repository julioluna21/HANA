//Módulo de Requisiciones (RQ)
//Listado con filtros por periodo, formulario con varios ítems y fotos, y
//detalle con la aprobación. Todo el texto que viene del servidor se escapa
//antes de pintarlo: lo escriben los usuarios
//RQ normal (amarillo) y RQ U urgente (rojo); se solicita y se aprueba
//o se rechaza; solo aprueba quien tiene el permiso 18M

var URL_RQ = '../Control/RQControl.php';
var tablaRQ = null;
var centrosRQ = [];      //peajes asignados al usuario, con su proyecto
var archivosRQ = [];     //archivos elegidos para la RQ nueva (ya comprimidos)
var rqAbierta = null;    //id de la RQ que se está viendo en el detalle
var vistaRQ = 'mias';    //"mias" = Mis RQ; "todas" = lo que mi rol puede ver
var detalleRQ = null;    //la última RQ abierta, para prellenar la edición
var configRQ = { verTodas: false, puedeAprobar: false, anios: [] };
var filasRQ = [];        //el listado del periodo, sin el filtro de estado

//---------------------------------------------------------------------------
// Utilidades
//---------------------------------------------------------------------------
function esc(texto) { return $('<div>').text(texto == null ? '' : String(texto)).html(); }

//La lista de unidades de medida (Parámetros → Listas). Si el ítem tiene una unidad vieja que no
//está en la lista (escrita a mano antes), se agrega como opción para no perderla
function htmlUnidad(valor) {
    var unidades = (configRQ && configRQ.unidades) || [['UNIDAD', 'Unidad']], html = '', esta = false;
    valor = valor || 'UNIDAD';
    unidades.forEach(function (u) {
        var igual = u[0] === valor || u[0] === String(valor).toUpperCase();
        if (igual) { esta = true; }
        html += '<option value="' + esc(u[0]) + '"' + (igual ? ' selected' : '') + '>' + esc(u[1]) + '</option>';
    });
    if (!esta) { html = '<option value="' + esc(valor) + '" selected>' + esc(valor) + '</option>' + html; }
    return '<select name="unidad[]" class="form-control">' + html + '</select>';
}

//Los peajes del mismo proyecto que el peaje de la RQ (de los que la persona tiene)
function peajesDelProyecto(idCentroRq) {
    var centro = $.grep(centrosRQ, function (c) { return String(c.ID_CENTRO_OP) === String(idCentroRq); })[0];
    if (!centro) { return []; }
    return $.grep(centrosRQ, function (c) { return String(c.ID_PROYECTO) === String(centro.ID_PROYECTO); });
}

//Las opciones del peaje de un ítem: vacío = el mismo de la RQ; o cualquier otro peaje del proyecto
function opcionesPeajeItem(idCentroRq, valor) {
    var html = '<option value="">El de la RQ</option>';
    peajesDelProyecto(idCentroRq).forEach(function (c) {
        if (String(c.ID_CENTRO_OP) === String(idCentroRq)) { return; }
        html += '<option value="' + parseInt(c.ID_CENTRO_OP, 10) + '"' + (String(c.ID_CENTRO_OP) === String(valor) ? ' selected' : '') + '>' + esc(c.NOM_CENTRO_OP) + '</option>';
    });
    return html;
}

//El campo del peaje del ítem. Solo se ve si el proyecto tiene más de un peaje
function htmlPeajeItem(idCentroRq, valor) {
    var visible = configRQ.peajePorItem && peajesDelProyecto(idCentroRq).length > 1;
    return '<div class="form-group col-md-6 col-sm-12 col-xs-12 rq-peaje-grupo"' + (visible ? '' : ' style="display:none;"') + '>' +
           '<label>Para qué peaje:</label><select name="peaje[]" class="form-control rq-peaje-item">' + opcionesPeajeItem(idCentroRq, valor) + '</select>' +
           '<small class="rq-ayuda">Si la misma RQ pide para varios peajes (por ejemplo, el agua de tres peajes), cada ítem dice el suyo.</small></div>';
}

//El peaje del ítem en el detalle y en el listado: "Peaje principal + otros"
function textoPeajes(principal, otros) {
    return esc(principal) + (otros ? ' <span class="rq-otros-peajes" title="La RQ también pide para estos peajes">+ ' + esc(otros) + '</span>' : '');
}

//"2026-09-18" -> "18/09/2026"
function fechaCorta(fecha) {
    if (!fecha) { return ''; }
    var partes = String(fecha).substring(0, 10).split('-');
    return partes.length === 3 ? partes[2] + '/' + partes[1] + '/' + partes[0] : esc(fecha);
}
//Fecha corta con la hora, por ejemplo "07/10/2026 14:30"
function fechaHora(fecha) {
    if (!fecha) { return ''; }
    return fechaCorta(fecha) + ' ' + String(fecha).substring(11, 16);
}

//Fecha de hoy en la zona del equipo (no en UTC, que adelanta el día de noche)
function hoyLocal() {
    var hoy = new Date();
    return hoy.getFullYear() + '-' + ('0' + (hoy.getMonth() + 1)).slice(-2) + '-' + ('0' + hoy.getDate()).slice(-2);
}

//Etiqueta de color con el nombre del estado
function badgeEstado(nombre, color) {
    return '<span class="rq-badge rq-badge-' + esc(color || 'gris') + '">' + esc(nombre) + '</span>';
}

//"RQ-12" o "RQ U-12"
function textoRQ(tipo, numero) {
    return (tipo === 'U' ? 'RQ U-' : 'RQ-') + numero;
}

//La etiqueta de color: amarilla la normal, roja la urgente
function etiquetaRQ(tipo, numero) {
    var esUrgente = tipo === 'U';
    return '<span class="rq-etq ' + (esUrgente ? 'rq-etq-u' : 'rq-etq-n') + '"' +
           (esUrgente ? ' title="Urgente"' : '') + '>' + esc(textoRQ(tipo, numero)) + '</span>';
}

//Los dos botones de tipo, para el formulario de edición
function htmlTipoRQ(tipo) {
    var esUrgente = tipo === 'U';
    return '<div class="rq-tipos" role="radiogroup" aria-label="Tipo de RQ">' +
           '<label class="rq-tipo-op rq-tipo-op-n"><input type="radio" name="tipo" value="N"' + (esUrgente ? '' : ' checked') + '>' +
           '<span><strong>RQ normal</strong><small>Llega en amarillo.</small></span></label>' +
           '<label class="rq-tipo-op rq-tipo-op-u"><input type="radio" name="tipo" value="U"' + (esUrgente ? ' checked' : '') + '>' +
           '<span><strong>RQ U · Urgente</strong><small>Llega en rojo.</small></span></label></div>';
}

//Muestra un aviso sin interrumpir, arriba del listado
function avisoRQ(html) {
    $('#rqAviso').remove();
    if (!html) { return; }
    $('<div class="rq-aviso" id="rqAviso">' + html +
      '<button type="button" aria-label="Cerrar">&times;</button></div>')
        .insertBefore('.rq-contadores')
        .find('button').on('click', function () { $('#rqAviso').remove(); });
}

//---------------------------------------------------------------------------
// Inicio
//---------------------------------------------------------------------------
$(function () {
    cargarCatalogos();

    $('#btnNuevaRQ').on('click', function () { mostrarFormulario(true); });
    $('#btnCancelarRQ').on('click', function () { mostrarFormulario(false); });
    $('#btnAgregarItem').on('click', function () { agregarItem(); });
    $('#formRQ').on('submit', guardarRQ);

    //Los filtros se aplican solos
    $('#fProyecto').on('change', function () { llenarFiltroCentros(); listarRQ(); });
    $('#fCentro, #fMes').on('change', listarRQ);
    //El filtro de estado no vuelve a pedir datos: filtra lo que ya está en pantalla
    $('#fEstado').on('change', function () { pintarTabla(filasRQ); });
    //Con "Todos los años" el mes no aplica
    $('#fAnio').on('change', function () {
        $('#fMes').prop('disabled', $(this).val() === '0');
        listarRQ();
    });

    //Al elegir el peaje se muestra qué número le tocaría
    $('#rqCentro').on('change', function () {
        var idCentro = $(this).val();
        //Los ítems ofrecen los peajes del proyecto del peaje elegido
        var visible = configRQ.peajePorItem && peajesDelProyecto(idCentro).length > 1;
        $('#rqItems .rq-peaje-item').each(function () { $(this).html(opcionesPeajeItem(idCentro, $(this).val())); });
        $('#rqItems .rq-peaje-grupo').toggle(visible);
        $('#rqNumero').attr('placeholder', 'Automático');
        if (!idCentro || $('#btnNuevaRQ').attr('data-puede') === '0') { return; } //quien no puede pedir RQ no necesita el siguiente número
        $.getJSON(URL_RQ + '?op=siguienteNumero&centro=' + encodeURIComponent(idCentro))
            .done(function (respuesta) { $('#rqNumero').attr('placeholder', 'Automático: ' + respuesta.numero); });
    });

    //Archivos: dos caminos, cámara o explorador
    $('#btnRqCamara').on('click', function () { $('#rqArchivosCamara').val('')[0].click(); });
    $('#btnRqArchivos').on('click', function () { $('#rqArchivos').val('')[0].click(); });
    $('#rqArchivosCamara, #rqArchivos').on('change', function () { agregarArchivos(this.files); });
    //Ver el PDF elegido antes de enviarlo (las fotos ya se abren en grande al tocarlas, con VisorAjax.js)
    $(document).on('click', '.rq-previa-pdf', function () {
        var archivo = archivosRQ[parseInt($(this).data('i'), 10)];
        if (archivo && window.hanaVerArchivoLocal) { window.hanaVerArchivoLocal(archivo); }
    });
    $(document).on('click', '.rq-previa-quitar', function () {
        archivosRQ.splice(parseInt($(this).data('i'), 10), 1);
        pintarPrevias();
    });

    //Quitar un ítem del formulario
    $(document).on('click', '.rq-item-quitar', function () {
        if ($('#rqItems .rq-item').length > 1) {
            $(this).closest('.rq-item').remove();
            renumerarItems();
        } else {
            alert('La RQ debe tener al menos un ítem.');
        }
    });

    //Detalle de una RQ
    $(document).on('click', '.btn-ver-rq', function () { verRQ($(this).data('id')); });
    $(document).on('click', '.btn-estado-rq', function () { cambiarEstadoRQ($(this).data('estado')); });
    $(document).on('click', '#btnAnularRQ', anularRQ);

    //Control de vista: Mis RQ / Las de mi rol (o todas, con el permiso)
    $('#vistaRQ').on('click', '.hana-vista-btn', function () {
        if ($(this).hasClass('activo')) { return; }
        vistaRQ = ($(this).data('vista') === 'todas') ? 'todas' : 'mias';
        $('#vistaRQ .hana-vista-btn').removeClass('activo');
        $(this).addClass('activo');
        listarRQ();
    });

    //Edición y retroceso desde el detalle
    $(document).on('click', '#btnEditarRQ', abrirEdicionRQ);
    $(document).on('click', '#btnCancelarEdicion', function () { pintarDetalle(detalleRQ); });
    $(document).on('click', '#btnAgregarItemEd', function () { $('#rqEdItems').append(htmlItemRQ({})); });
    $(document).on('click', '.rq-ed-quitar', function () {
        if ($('#rqEdItems .rq-item').length > 1) { $(this).closest('.rq-item').remove(); }
        else { alert('La RQ debe tener al menos un ítem.'); }
    });
    $(document).on('click', '#btnGuardarEdicion', guardarEdicionRQ);
});

//---------------------------------------------------------------------------
// Catálogos: peajes y estados
//---------------------------------------------------------------------------
function cargarCatalogos() {
    //El periodo abre en el mes y el año actuales
    var hoy = new Date();
    $('#fMes').val(String(hoy.getMonth() + 1));
    $('#fAnio').html('<option value="' + hoy.getFullYear() + '">' + hoy.getFullYear() + '</option><option value="0">Todos</option>');

    //Qué ve este usuario, si aprueba, y los años que tienen RQ
    var pideConfig = $.getJSON(URL_RQ + '?op=config')
        .done(function (respuesta) {
            configRQ = respuesta;
            $('#vistaRQ .hana-vista-todas').text(respuesta.verTodas ? 'Todas las RQ' : 'Las de mi rol');
            //Quien no pide RQ y solo las revisa (el director de operaciones) abre directo en "Todas las RQ"
            if (!respuesta.puedePedir && respuesta.verTodas) {
                vistaRQ = 'todas';
                $('#vistaRQ .hana-vista-btn').removeClass('activo');
                $('#vistaRQ .hana-vista-btn[data-vista="todas"]').addClass('activo');
            }
            var html = '', anios = respuesta.anios || [];
            for (var i = 0; i < anios.length; i++) {
                html += '<option value="' + parseInt(anios[i], 10) + '">' + parseInt(anios[i], 10) + '</option>';
            }
            html += '<option value="0">Todos</option>';
            $('#fAnio').html(html).val(String(hoy.getFullYear()));
        });

    $.getJSON(URL_RQ + '?op=centros')
        .done(function (filas) {
            centrosRQ = filas;
            if (!filas.length && $('#btnNuevaRQ').attr('data-puede') !== '0') { //solo le importa a quien puede pedir
                avisoRQ('No tienes peajes para pedir RQ. Pide que te asignen uno en Configuración → Colaboradores.');
                $('#btnNuevaRQ').prop('disabled', true);
            }

            //Selector del formulario: agrupado por proyecto
            var html = '<option value="">Selecciona...</option>', grupo = null;
            for (var i = 0; i < filas.length; i++) {
                if (filas[i].NOM_PROYECTO !== grupo) {
                    if (grupo !== null) { html += '</optgroup>'; }
                    grupo = filas[i].NOM_PROYECTO;
                    html += '<optgroup label="' + esc(grupo) + '">';
                }
                html += '<option value="' + parseInt(filas[i].ID_CENTRO_OP, 10) + '">' + esc(filas[i].NOM_CENTRO_OP) + '</option>';
            }
            if (grupo !== null) { html += '</optgroup>'; }
            $('#rqCentro').html(html);

            //Si solo tiene un peaje, queda elegido
            if (filas.length === 1) { $('#rqCentro').val(filas[0].ID_CENTRO_OP).trigger('change'); }

            //Filtro de proyectos, sin repetir
            var vistos = {}, htmlProyectos = '<option value="0">Todos</option>';
            for (var j = 0; j < filas.length; j++) {
                if (!vistos[filas[j].ID_PROYECTO]) {
                    vistos[filas[j].ID_PROYECTO] = true;
                    htmlProyectos += '<option value="' + parseInt(filas[j].ID_PROYECTO, 10) + '">' + esc(filas[j].NOM_PROYECTO) + '</option>';
                }
            }
            $('#fProyecto').html(htmlProyectos);
            llenarFiltroCentros();
            //El listado espera a saber los años, para no pedirlo dos veces
            pideConfig.always(listarRQ);

            //Si se llegó desde la campana (RQVista.php?abrir=ID), se abre esa RQ
            var abrirId = new URLSearchParams(window.location.search).get('abrir');
            if (abrirId && /^\d+$/.test(abrirId)) {
                verRQ(parseInt(abrirId, 10));
                if (window.history && window.history.replaceState) { window.history.replaceState(null, '', 'RQVista.php'); }
            }
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron cargar tus peajes.'); });
}

//El filtro de peajes muestra solo los del proyecto elegido
function llenarFiltroCentros() {
    var idProyecto = $('#fProyecto').val(), html = '<option value="0">Todos</option>';
    for (var i = 0; i < centrosRQ.length; i++) {
        if (idProyecto === '0' || String(centrosRQ[i].ID_PROYECTO) === idProyecto) {
            html += '<option value="' + parseInt(centrosRQ[i].ID_CENTRO_OP, 10) + '">' + esc(centrosRQ[i].NOM_CENTRO_OP) + '</option>';
        }
    }
    $('#fCentro').html(html);
}

//---------------------------------------------------------------------------
// Listado
//---------------------------------------------------------------------------
function listarRQ() {
    var filtros = '&proyecto=' + encodeURIComponent($('#fProyecto').val() || 0) +
            '&centro='   + encodeURIComponent($('#fCentro').val()   || 0) +
            '&anio='     + encodeURIComponent($('#fAnio').val()     || 0) +
            '&mes='      + encodeURIComponent($('#fMes').val()      || 0);

    $.getJSON(URL_RQ + '?op=listar&vista=' + encodeURIComponent(vistaRQ) + filtros)
        .done(function (respuesta) {
            var filas = respuesta.filas || [];

            //Contadores del periodo
            var pendientes = 0, urgentes = 0, aprobadas = 0, rechazadas = 0;
            for (var i = 0; i < filas.length; i++) {
                var rq = filas[i], estado = parseInt(rq.ID_RQ_ESTADO, 10);
                if (parseInt(rq.ESTADO, 10) !== 1) { continue; } //las anuladas no cuentan
                if (estado === 1) { pendientes++; if (rq.TIPO_RQ === 'U') { urgentes++; } }
                if (estado === 3) { aprobadas++; }
                if (estado === 7) { rechazadas++; }
            }
            $('#cntPendientes').text(pendientes);
            $('#cntUrgentes').text(urgentes);
            $('#cntAprobadas').text(aprobadas);
            $('#cntRechazadas').text(rechazadas);

            filasRQ = filas;
            pintarTabla(filas);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el listado de RQ.'); });
}

//Filtro de estado: sin aprobar (pendientes), aprobadas, rechazadas o anuladas
function filtrarEstadoRQ(filas) {
    var estadoElegido = $('#fEstado').val() || '';
    if (!estadoElegido) { return filas; }
    return $.grep(filas, function (rq) {
        var anulada = parseInt(rq.ESTADO, 10) !== 1, estado = parseInt(rq.ID_RQ_ESTADO, 10);
        if (estadoElegido === 'anuladas') { return anulada; }
        if (anulada) { return false; }
        if (estadoElegido === 'pendientes') { return estado === 1; }
        if (estadoElegido === 'aprobadas') { return estado === 3; }
        if (estadoElegido === 'rechazadas') { return estado === 7; }
        return true;
    });
}

//Pinta la tabla de RQ (con el filtro de estado aplicado)
function pintarTabla(filas) {
    filas = filtrarEstadoRQ(filas);
    if (tablaRQ) { tablaRQ.destroy(); $('#tblRQ tbody').empty(); }

    tablaRQ = $('#tblRQ').DataTable({
        data: filas,
        order: [],        //el servidor ya las manda de la más nueva a la más vieja
        pageLength: 10,   //uno de los valores del selector "Mostrar registros"
        //Las urgentes pendientes se marcan en la fila, para que se vean de lejos
        createdRow: function (filaTabla, rq) {
            if (rq.TIPO_RQ === 'U' && parseInt(rq.ID_RQ_ESTADO, 10) === 1 && parseInt(rq.ESTADO, 10) === 1) {
                $(filaTabla).addClass('rq-fila-urgente');
            }
        },
        columns: [
            { data: null, render: function (rq, tipo) {
                //Al buscar o exportar se usa el texto; en pantalla, la etiqueta de color
                if (tipo !== 'display') { return textoRQ(rq.TIPO_RQ, rq.NUMERO_RQ) + (rq.TIPO_RQ === 'U' ? ' urgente' : ''); }
                return etiquetaRQ(rq.TIPO_RQ, rq.NUMERO_RQ);
            } },
            { data: 'NOM_PROYECTO', render: function (valor) { return esc(valor); } },
            { data: null, render: function (rq, tipo) {
                if (tipo !== 'display') { return rq.NOM_CENTRO_OP + (rq.OTROS_PEAJES ? ' ' + rq.OTROS_PEAJES : ''); }
                return textoPeajes(rq.NOM_CENTRO_OP, rq.OTROS_PEAJES);
            } },
            { data: 'FECHA_RQ', render: function (valor, tipo) {
                return tipo === 'sort' ? valor : fechaCorta(valor);   //ordena bien aunque se vea dd/mm
            } },
            { data: null, render: function (rq) {
                var items = parseInt(rq.ITEMS, 10);
                return '<span class="rq-resumen">' + esc(rq.RESUMEN) + '</span>' +
                       (items > 1 ? ' <span class="rq-cant-items">' + items + ' ítems</span>' : '');
            } },
            { data: null, render: function (rq) {
                var html = badgeEstado(rq.NOM_RQ_ESTADO, rq.COLOR);
                if (parseInt(rq.ESTADO, 10) === 0) { html = badgeEstado('Anulada', 'gris'); }
                return html;
            } },
            { data: null, render: function (rq, tipo) {
                if (tipo === 'sort') { return parseInt(rq.DIAS_EN_ESTADO, 10); }
                if (parseInt(rq.ES_FINAL, 10) === 1 || parseInt(rq.ESTADO, 10) === 0) {
                    return '<span class="rq-dias-fin">' + esc(rq.DIAS_TOTALES) + ' d total</span>';
                }
                //Pendiente: cuántos días lleva esperando aprobación
                var html = esc(rq.DIAS_EN_ESTADO) + ' de ' + esc(rq.DIAS_ESPERADOS) + ' d';
                if (parseInt(rq.VENCIDA, 10) === 1) { html = '<span class="rq-vencida">' + html + ' · Sin aprobar</span>'; }
                return html;
            } },
            { data: null, orderable: false, render: function (rq) {
                //Quien aprueba ve "Revisar" en las pendientes; los demás, "Ver"
                var revisar = configRQ.puedeAprobar && parseInt(rq.ID_RQ_ESTADO, 10) === 1 && parseInt(rq.ESTADO, 10) === 1;
                return '<button type="button" class="btn btn-success btn-xs btn-ver-rq" data-id="' +
                       parseInt(rq.ID_RQ, 10) + '" title="' + (revisar ? 'Aprobar o rechazar' : 'Ver la RQ') + '">' +
                       '<i class="fa ' + (revisar ? 'fa-check-square-o' : 'fa-eye') + '"></i> ' + (revisar ? 'Revisar' : 'Ver') + '</button>';
            } }
        ]
    });
}

//---------------------------------------------------------------------------
// Formulario
//---------------------------------------------------------------------------
function mostrarFormulario(mostrar) {
    if (mostrar) {
        limpiarFormulario();
        $('#panelListado').hide();
        $('#panelFormulario').show();
    } else {
        $('#panelFormulario').hide();
        $('#panelListado').show();
    }
    $('.right_col').scrollTop(0);
    window.scrollTo(0, 0);
}

//Deja el formulario de nueva RQ en blanco (conserva el centro si solo hay uno)
function limpiarFormulario() {
    var centro = $('#rqCentro').val();
    $('#formRQ')[0].reset();
    if (centrosRQ.length === 1) { $('#rqCentro').val(centro).trigger('change'); }
    $('#rqFecha').val(hoyLocal()).attr('max', hoyLocal());
    $('#rqItems').empty();
    agregarItem();
    archivosRQ = [];
    pintarPrevias();
}

//Agrega una fila de ítem. Los nombres van con [] para que lleguen como arreglo
function agregarItem() {
    var html =
        '<div class="rq-item">' +
        '  <div class="rq-item-cab"><span class="rq-item-num"></span>' +
        '    <button type="button" class="rq-item-quitar" title="Quitar este ítem"><i class="fa fa-times"></i> Quitar</button></div>' +
        '  <div class="row">' +
        '    <div class="form-group col-md-6 col-sm-12 col-xs-12">' +
        '      <label>Qué se solicita: <span class="rq-req">*</span></label>' +
        '      <input type="text" name="descripcion[]" class="form-control rq-desc" maxlength="255" required' +
        '             placeholder="Ej: ventilador de techo para la caseta 4">' +
        '    </div>' +
        '    <div class="form-group col-md-3 col-sm-6 col-xs-6">' +
        '      <label>Cantidad:</label>' +
        '      <input type="number" name="cantidad[]" class="form-control" min="0.01" step="any" value="1">' +
        '    </div>' +
        '    <div class="form-group col-md-3 col-sm-6 col-xs-6">' +
        '      <label>Unidad de medida:</label>' + htmlUnidad('UNIDAD') +
        '    </div>' +
        '  </div>' +
        '  <div class="row">' + htmlPeajeItem($('#rqCentro').val(), '') + '</div>' +
        '  <div class="row">' +
        '    <div class="form-group col-md-6 col-sm-12 col-xs-12">' +
        '      <label>Por qué se necesita:</label>' +
        '      <textarea name="justificacion[]" class="form-control" rows="2"' +
        '                placeholder="Ej: el actual se dañó y la caseta supera los 35 °C en la tarde"></textarea>' +
        '    </div>' +
        '    <div class="form-group col-md-6 col-sm-12 col-xs-12">' +
        '      <label>Soporte de la última adquisición:</label>' +
        '      <input type="text" name="soporte[]" class="form-control" maxlength="255"' +
        '             placeholder="Opcional. Ej: factura 1234 de marzo de 2026">' +
        '    </div>' +
        '  </div>' +
        '</div>';
    $('#rqItems').append(html);
    renumerarItems();
    $('#rqItems .rq-desc').last().focus();
}

//Vuelve a numerar los ítems después de agregar o quitar uno
function renumerarItems() {
    var items = $('#rqItems .rq-item');
    items.each(function (i) { $(this).find('.rq-item-num').text('Ítem ' + (i + 1)); });
    //Con un solo ítem no tiene sentido mostrar "Quitar"
    items.find('.rq-item-quitar').toggle(items.length > 1);
}

//---------------------------------------------------------------------------
// Archivos: se reducen en el teléfono antes de enviarlos
//---------------------------------------------------------------------------
function agregarArchivos(lista) {
    var pendientes = Array.prototype.slice.call(lista || []);
    if (!pendientes.length) { return; }
    if (archivosRQ.length + pendientes.length > 10) {
        alert('Puedes adjuntar máximo 10 archivos.');
        pendientes = pendientes.slice(0, Math.max(0, 10 - archivosRQ.length));
    }

    var faltan = pendientes.length;
    for (var i = 0; i < pendientes.length; i++) {
        (function (archivo) {
            var esImagen = archivo.type.indexOf('image/') === 0;
            var esPdf = archivo.type === 'application/pdf' || /\.pdf$/i.test(archivo.name);
            if (!esImagen && !esPdf) {
                alert('"' + archivo.name + '" no es una imagen ni un PDF.');
                if (--faltan === 0) { pintarPrevias(); }
                return;
            }
            if (!esImagen) {
                archivosRQ.push(archivo);
                if (--faltan === 0) { pintarPrevias(); }
                return;
            }
            comprimirFoto(archivo, function (listo) {
                archivosRQ.push(listo);
                if (--faltan === 0) { pintarPrevias(); }
            });
        })(pendientes[i]);
    }
}

//Reduce una foto a 1600 px de lado mayor y calidad 72 %.
//Las livianas se dejan como están; si algo falla, se usa la original
function comprimirFoto(archivo, listo) {
    if (archivo.size < 400 * 1024) { listo(archivo); return; }
    var lector = new FileReader();
    //Si el archivo no se puede leer como imagen, se sube tal como viene
    lector.onerror = function () { listo(archivo); };
    lector.onload = function (evento) {
        var img = new Image();
        img.onerror = function () { listo(archivo); };
        //La foto se achica a máximo 1600 px antes de subirla, para que no pese tanto
        img.onload = function () {
            try {
                var ancho = img.width, alto = img.height, mayor = Math.max(ancho, alto);
                if (mayor > 1600) { ancho = Math.round(ancho * 1600 / mayor); alto = Math.round(alto * 1600 / mayor); }
                var lienzo = document.createElement('canvas');
                lienzo.width = ancho; lienzo.height = alto;
                lienzo.getContext('2d').drawImage(img, 0, 0, ancho, alto);
                lienzo.toBlob(function (blob) {
                    if (!blob || blob.size >= archivo.size) { listo(archivo); return; }
                    try {
                        listo(new File([blob], archivo.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }));
                    } catch (error) { listo(archivo); }
                }, 'image/jpeg', 0.72);
            } catch (error) { listo(archivo); }
        };
        img.src = evento.target.result;
    };
    lector.readAsDataURL(archivo);
}

//Muestra las miniaturas de los archivos elegidos
function pintarPrevias() {
    var cont = $('#rqPrevias').empty();
    for (var i = 0; i < archivosRQ.length; i++) {
        var archivo = archivosRQ[i];
        var kilobytes = Math.max(1, Math.round(archivo.size / 1024));
        var caja = $('<div class="rq-previa"></div>');
        if (archivo.type.indexOf('image/') === 0) {
            $('<img alt="">').attr('src', URL.createObjectURL(archivo)).appendTo(caja);
        } else {
            caja.append('<div class="rq-previa-pdf" data-i="' + i + '" title="Ver el PDF antes de enviarlo"><i class="fa fa-file-pdf-o"></i></div>');
        }
        caja.append('<div class="rq-previa-nombre">' + esc(archivo.name) + '<br><small>' + kilobytes + ' KB</small></div>');
        caja.append('<button type="button" class="rq-previa-quitar" data-i="' + i + '" title="Quitar">&times;</button>');
        cont.append(caja);
    }
}

//---------------------------------------------------------------------------
// Guardar
//---------------------------------------------------------------------------
function guardarRQ(evento) {
    evento.preventDefault();

    if (!$('#rqCentro').val()) { alert('Elige el peaje.'); $('#rqCentro').focus(); return; }
    var conDescripcion = $('#rqItems .rq-desc').filter(function () { return $.trim(this.value) !== ''; }).length;
    if (!conDescripcion) { alert('Escribe qué se solicita en al menos un ítem.'); return; }

    //El formulario ya incluye el tipo (N o U)
    var formulario = new FormData($('#formRQ')[0]);
    for (var i = 0; i < archivosRQ.length; i++) {
        formulario.append('archivos[]', archivosRQ[i], archivosRQ[i].name);
    }

    hanaBoton('#btnGuardarRQ', true);
    $.ajax({ url: URL_RQ + '?op=guardar', type: 'POST', data: formulario,
             contentType: false, processData: false, dataType: 'json' })
        .done(function (respuesta) {
            alert(respuesta.mensaje, function () {
                mostrarFormulario(false);
                listarRQ();
                if (window.hanaCampanaActualizar) { window.hanaCampanaActualizar(); }
            });
        })
        .fail(function (xhr) {
            var mensaje = 'No se pudo registrar la RQ.';
            try { mensaje = JSON.parse(xhr.responseText).error || mensaje; } catch (error) {}
            alert(mensaje); //el formulario se conserva para corregir
        })
        .always(function () { hanaBoton('#btnGuardarRQ', false); });
}

//---------------------------------------------------------------------------
// Detalle, cambio de estado y anulación
//---------------------------------------------------------------------------
function verRQ(id) {
    rqAbierta = id;
    $('#modalRQTitulo').text('Cargando...');
    $('#modalRQCuerpo').html('<div class="hana-campana-vacia"><i class="fa fa-spinner fa-spin"></i>Cargando...</div>');
    $('#modalRQ').modal('show');

    $.getJSON(URL_RQ + '?op=mostrar&id=' + encodeURIComponent(id))
        .done(pintarDetalle)
        .fail(function (xhr) {
            $('#modalRQ').modal('hide');
            var mensaje = 'No se pudo cargar la RQ.';
            try { mensaje = JSON.parse(xhr.responseText).error || mensaje; } catch (error) {}
            alert(mensaje);
        });
}

//Pinta la ventana de detalle de una RQ: datos, ítems, archivos e historial
function pintarDetalle(detalle) {
    detalleRQ = detalle;
    var rq = detalle.rq, anulada = parseInt(rq.ESTADO, 10) === 0;
    $('#modalRQTitulo').html(esc(textoRQ(rq.TIPO_RQ, rq.NUMERO_RQ)) + ' · ' + esc(rq.NOM_PROYECTO));

    var vencida = !anulada && parseInt(rq.ES_FINAL, 10) === 0 && parseInt(rq.DIAS_ESPERADOS, 10) > 0 &&
                  parseInt(rq.DIAS_EN_ESTADO, 10) > parseInt(rq.DIAS_ESPERADOS, 10);

    var html = '<div class="rq-det-cab">' +
            '<div><span class="rq-det-lbl">Tipo</span><span class="rq-etq ' + (rq.TIPO_RQ === 'U' ? 'rq-etq-u">Urgente' : 'rq-etq-n">Normal') +
                '</span></div>' +
            '<div><span class="rq-det-lbl">Peaje</span>' + textoPeajes(rq.NOM_CENTRO_OP, rq.OTROS_PEAJES) + '</div>' +
            '<div><span class="rq-det-lbl">Fecha</span>' + fechaCorta(rq.FECHA_RQ) + '</div>' +
            '<div><span class="rq-det-lbl">Solicitó</span>' + esc(rq.SOLICITA) + '</div>' +
            '<div><span class="rq-det-lbl">Estado</span>' +
                (anulada ? badgeEstado('Anulada', 'gris') : badgeEstado(rq.NOM_RQ_ESTADO, rq.COLOR)) +
                (vencida ? ' <span class="rq-vencida">Sin aprobar</span>' : '') + '</div>' +
            '</div>';


    //Ítems
    html += '<h5 class="rq-det-sub">Qué se solicita</h5><div class="rq-det-items">';
    for (var i = 0; i < detalle.items.length; i++) {
        var item = detalle.items[i];
        html += '<div class="rq-det-item"><div class="rq-det-item-cab"><strong>' + esc(item.DESCRIPCION) + '</strong>' +
             '<span>' + esc(parseFloat(item.CANTIDAD)) + ' ' + esc(item.UNIDAD_NOMBRE || item.UNIDAD) + '</span></div>' +
             (item.PEAJE_ITEM && String(item.ID_CENTRO_OP) !== String(rq.ID_CENTRO_OP) ? '<div class="rq-det-item-peaje"><i class="fa fa-map-marker"></i> Para el peaje ' + esc(item.PEAJE_ITEM) + '</div>' : '') +
             (item.JUSTIFICACION ? '<div class="rq-det-item-just">' + esc(item.JUSTIFICACION) + '</div>' : '') +
             (item.SOPORTE_ULTIMA_ADQUISICION ? '<div class="rq-det-item-sop"><i class="fa fa-paperclip"></i> Última adquisición: ' +
               esc(item.SOPORTE_ULTIMA_ADQUISICION) + '</div>' : '') +
             '</div>';
    }
    html += '</div>';

    if (rq.OBSERVACION_SST) {
        html += '<h5 class="rq-det-sub">Observación</h5><p>' + esc(rq.OBSERVACION_SST) + '</p>';
    }

    //Archivos
    if (detalle.archivos.length) {
        html += '<h5 class="rq-det-sub">Evidencias y soportes</h5><div class="rq-det-archivos">';
        for (var j = 0; j < detalle.archivos.length; j++) {
            var archivo = detalle.archivos[j], url = esc(archivo.URL);
            if (/\.pdf$/i.test(archivo.RUTA)) {
                html += '<a class="rq-det-archivo rq-det-pdf" href="' + url + '" data-visor-pdf data-nombre="' + esc(archivo.NOMBRE_ORIGINAL) + '">' + //se ve en el visor de la misma página
                     '<i class="fa fa-file-pdf-o"></i><span>' + esc(archivo.NOMBRE_ORIGINAL) + '</span></a>';
            } else {
                html += '<a class="rq-det-archivo" href="' + url + '" data-visor="rq-' + parseInt(rq.ID_RQ, 10) + '" title="' + //se abre en el visor de la misma página
                     esc(archivo.NOMBRE_ORIGINAL) + '"><img src="' + url + '" alt="' + esc(archivo.NOMBRE_ORIGINAL) + '"></a>';
            }
        }
        html += '</div>';
    }

    //Aprobar o rechazar (permiso 18M), y anular (quien la pidió o quien aprueba).
    //El servidor manda qué se puede hacer; aquí solo se pintan esos botones
    var aprobar = !anulada && detalle.siguientes.length > 0;
    if (aprobar || detalle.puedeAnular) {
        html += '<div class="rq-det-accion"><h5 class="rq-det-sub" style="margin-top:0">' +
             (aprobar ? 'Aprobación' : 'Anular la solicitud') + '</h5>' +
             '<div class="form-group"><label for="rqObsEstado">Observación:</label>' +
             '<input type="text" id="rqObsEstado" class="form-control" maxlength="500" ' +
             'placeholder="' + (aprobar ? 'Opcional al aprobar; obligatoria al rechazar o anular' : 'Obligatoria: por qué se anula') + '"></div>' +
             '<div class="rq-acciones">';
        if (detalle.puedeAnular) {
            html += '<button type="button" class="btn btn-default" id="btnAnularRQ"><i class="fa fa-ban"></i> Anular</button>';
        }
        for (var numSiguiente = 0; numSiguiente < detalle.siguientes.length; numSiguiente++) {
            var siguiente = detalle.siguientes[numSiguiente], idSig = parseInt(siguiente.ID_RQ_ESTADO, 10);
            html += '<button type="button" class="btn ' + (idSig === 7 ? 'btn-danger' : 'btn-success') + ' btn-estado-rq" ' +
                 'data-estado="' + idSig + '"><i class="fa ' + (idSig === 7 ? 'fa-times' : 'fa-check') + '"></i> ' +
                 (idSig === 7 ? 'Rechazar' : 'Aprobar') + '</button>';
        }
        html += '</div></div>';
    }

    //Editar: en "Solicitada", quien la pidió o quien aprueba. Si fue rechazada,
    //quien la pidió la corrige y la envía de nuevo (máximo d.maxReintentos veces)
    var reint = parseInt(detalle.reintentos, 10) || 0, maxR = parseInt(detalle.maxReintentos, 10) || 0;
    var rechazada = !anulada && parseInt(rq.ID_RQ_ESTADO, 10) === 7;
    if (rechazada && !detalle.retrocede) {
        html += '<div class="rq-det-editar"><p><i class="fa fa-ban"></i> <strong>Ya no se puede enviar de nuevo:</strong> ' +
             (maxR > 0 ? 'se corrigió y se reenvió ' + reint + ' de ' + maxR + ' veces permitidas. '
                       : 'las RQ rechazadas no se reenvían. ') +
             'Queda rechazada; si todavía hace falta, hay que pedir una RQ nueva.</p></div>';
    }
    if (detalle.puedeEditar) {
        html += '<div class="rq-det-editar">' +
             (detalle.retrocede
                ? '<p><i class="fa fa-undo"></i> Fue rechazada. Si la corriges, <strong>vuelve a "Solicitada"</strong> ' +
                  'y queda pendiente de aprobación otra vez. Reintento <strong>' + (reint + 1) + ' de ' + maxR + '</strong>.</p>'
                : '') +
             '<button type="button" class="btn btn-default" id="btnEditarRQ"><i class="fa ' + (detalle.retrocede ? 'fa-undo' : 'fa-pencil') + '"></i> ' +
             (detalle.retrocede ? 'Corregir y enviar de nuevo' : 'Editar RQ') + '</button></div>';
    }

    //Historial
    html += '<h5 class="rq-det-sub">Historial</h5><ul class="rq-historial">';
    for (var numPaso = 0; numPaso < detalle.historial.length; numPaso++) {
        var paso = detalle.historial[numPaso];
        html += '<li><div class="rq-hist-fecha">' + fechaHora(paso.FECHA) + '</div>' +
             '<div class="rq-hist-texto"><strong>' + (paso.ANTERIOR ? esc(paso.ANTERIOR) + ' → ' : '') + esc(paso.NUEVO) + '</strong>' +
             //Quién lo hizo: el nombre completo del colaborador y el rol de su usuario en HANA
             '<div class="rq-hist-quien"><i class="fa fa-user"></i> ' + (paso.QUIEN ? esc(paso.QUIEN) : 'Sin colaborador') +
             (paso.ROL ? ' <span>· ' + esc(paso.ROL) + '</span>' : '') + '</div>' +
             (paso.OBSERVACION ? '<div class="rq-hist-obs">' + esc(paso.OBSERVACION) + '</div>' : '') +
             '</div></li>';
    }
    html += '</ul>';

    $('#modalRQCuerpo').html(html);
}

//Cambia el estado de la RQ (rechazar exige la observación)
function cambiarEstadoRQ(nuevo) {
    nuevo = parseInt(nuevo, 10);
    var rechaza = nuevo === 7;
    var observacion = $.trim($('#rqObsEstado').val());
    if (rechaza && !observacion) { alert('Para rechazar una RQ hay que explicar el motivo en la observación.'); $('#rqObsEstado').focus(); return; }

    var boton = '.btn-estado-rq[data-estado="' + nuevo + '"]';
    var sinReintentos = detalleRQ && (parseInt(detalleRQ.reintentos, 10) || 0) >= (parseInt(detalleRQ.maxReintentos, 10) || 0);
    var textoRechazo = sinReintentos
        ? '¿Rechazar esta RQ? Ya no le quedan reintentos: quedará rechazada y no se podrá enviar de nuevo.'
        : '¿Rechazar esta RQ? Quien la pidió verá el motivo y podrá corregirla.';
    hanaConfirmar(rechaza ? textoRechazo : '¿Aprobar esta RQ?', function () {
        hanaBoton(boton, true);
        $.ajax({ url: URL_RQ + '?op=cambiarEstado', type: 'POST', dataType: 'json',
                 data: { id: rqAbierta, estado: nuevo, observacion: observacion } })
            .done(function () {
                verRQ(rqAbierta);
                listarRQ();
                if (window.hanaCampanaActualizar) { window.hanaCampanaActualizar(); }
            })
            .fail(function (xhr) {
                var mensaje = 'No se pudo guardar la decisión.';
                try { mensaje = JSON.parse(xhr.responseText).error || mensaje; } catch (error) {}
                alert(mensaje);
                hanaBoton(boton, false);
            });
    }, { aceptar: rechaza ? 'Sí, rechazar' : 'Sí, aprobar', cancelar: 'No' });
}

//Anula la RQ (exige la observación y pregunta antes)
function anularRQ() {
    var observacion = $.trim($('#rqObsEstado').val());
    if (!observacion) { alert('Para anular una RQ hay que explicar el motivo en la observación.'); $('#rqObsEstado').focus(); return; }

    hanaConfirmar('¿Anular esta RQ? No se borra: queda en el historial como anulada.', function () {
        $.ajax({ url: URL_RQ + '?op=anular', type: 'POST', dataType: 'json',
                 data: { id: rqAbierta, observacion: observacion } })
            .done(function (respuesta) { verRQ(rqAbierta); listarRQ(); })
            .fail(function (xhr) {
                var mensaje = 'No se pudo anular la RQ.';
                try { mensaje = JSON.parse(xhr.responseText).error || mensaje; } catch (error) {}
                alert(mensaje);
            });
    }, { aceptar: 'Sí, anular', cancelar: 'No' });
}

//---------------------------------------------------------------------------
// Edición y retroceso
//---------------------------------------------------------------------------

//Una fila de ítem para el editor, con valores o vacía
function htmlItemRQ(item) {
    return '<div class="rq-item">' +
        '<div class="rq-item-cab"><span class="rq-item-num">Ítem</span>' +
        '<button type="button" class="rq-item-quitar rq-ed-quitar"><i class="fa fa-times"></i> Quitar</button></div>' +
        '<div class="row"><div class="form-group col-sm-6 col-xs-12"><label>Qué se solicita: <span class="rq-req">*</span></label>' +
        '<input type="text" name="descripcion[]" class="form-control" maxlength="255" value="' + esc(item.DESCRIPCION || '') + '"></div>' +
        '<div class="form-group col-sm-3 col-xs-6"><label>Cantidad:</label>' +
        '<input type="number" name="cantidad[]" class="form-control" min="0.01" step="any" value="' + esc(item.CANTIDAD ? parseFloat(item.CANTIDAD) : 1) + '"></div>' +
        '<div class="form-group col-sm-3 col-xs-6"><label>Unidad de medida:</label>' + htmlUnidad(item.UNIDAD || 'UNIDAD') + '</div></div>' +
        '<div class="row">' + htmlPeajeItem(detalleRQ.rq.ID_CENTRO_OP,
            item.ID_CENTRO_OP && String(item.ID_CENTRO_OP) !== String(detalleRQ.rq.ID_CENTRO_OP) ? item.ID_CENTRO_OP : '') + '</div>' +
        '<div class="row"><div class="form-group col-sm-6 col-xs-12"><label>Por qué se necesita:</label>' +
        '<textarea name="justificacion[]" class="form-control" rows="2">' + esc(item.JUSTIFICACION || '') + '</textarea></div>' +
        '<div class="form-group col-sm-6 col-xs-12"><label>Soporte de la última adquisición:</label>' +
        '<input type="text" name="soporte[]" class="form-control" maxlength="255" value="' + esc(item.SOPORTE_ULTIMA_ADQUISICION || '') + '"></div></div>' +
        '</div>';
}

//Cambia el detalle por un formulario con los datos actuales
function abrirEdicionRQ() {
    var detalle = detalleRQ, rq = detalle.rq;
    var html = '<form id="formEditarRQ" autocomplete="off">' +
        (detalle.retrocede
            ? '<div class="rq-det-riesgo"><i class="fa fa-undo"></i> Al guardar, la RQ vuelve a <strong>"Solicitada"</strong> ' +
              'y queda pendiente de aprobación otra vez.</div>'
            : '') +
        '<div class="row"><div class="form-group col-sm-4 col-xs-12"><label>Fecha de la RQ:</label>' +
        '<input type="date" name="fecha" class="form-control" max="' + hoyLocal() + '" value="' + esc(String(rq.FECHA_RQ).substring(0, 10)) + '"></div></div>' +
        '<h5 class="rq-det-sub">Tipo de RQ</h5>' + htmlTipoRQ(rq.TIPO_RQ) +
        '<h5 class="rq-det-sub">Qué se solicita</h5><div id="rqEdItems">';
    for (var i = 0; i < detalle.items.length; i++) { html += htmlItemRQ(detalle.items[i]); }
    if (!detalle.items.length) { html += htmlItemRQ({}); }
    html += '</div><button type="button" class="btn btn-default btn-sm" id="btnAgregarItemEd"><i class="fa fa-plus"></i> Agregar ítem</button>' +
        '<div class="form-group" style="margin-top:14px;"><label>Observación:</label>' +
        '<textarea name="sst" class="form-control" rows="2">' + esc(rq.OBSERVACION_SST || '') + '</textarea></div>' +
        '<div class="form-group" style="margin-top:14px;"><label>' + (detalle.retrocede ? 'Qué se corrigió: <span class="rq-req">*</span>' : 'Qué se corrigió (opcional):') + '</label>' +
        '<input type="text" name="motivo" class="form-control" maxlength="500" placeholder="Ej: la cantidad estaba mal, eran 3 y no 1"></div>' +
        '<div class="rq-acciones"><button type="button" class="btn btn-default" id="btnCancelarEdicion">Cancelar</button>' +
        '<button type="button" class="btn btn-success" id="btnGuardarEdicion"><i class="fa fa-save"></i> ' +
        (detalle.retrocede ? 'Guardar y enviar de nuevo' : 'Guardar cambios') + '</button></div></form>';
    $('#modalRQCuerpo').html(html);
}

//Revisa y guarda los cambios de una RQ
function guardarEdicionRQ() {
    var form = $('#formEditarRQ');
    if (!form.find('[name="descripcion[]"]').filter(function () { return $.trim(this.value) !== ''; }).length) {
        alert('La RQ debe tener al menos un ítem con su descripción.'); return;
    }
    if (detalleRQ.retrocede && !$.trim(form.find('[name="motivo"]').val())) {
        alert('Explica qué se corrige: queda en el historial.'); form.find('[name="motivo"]').focus(); return;
    }

    //Envía el formulario de edición con sus archivos
    var enviar = function () {
        var formulario = new FormData(form[0]);
        formulario.append('id', rqAbierta);
        hanaBoton('#btnGuardarEdicion', true);
        $.ajax({ url: URL_RQ + '?op=editar', type: 'POST', data: formulario, contentType: false, processData: false, dataType: 'json' })
            .done(function (respuesta) {
                alert(respuesta.mensaje, function () {
                    verRQ(rqAbierta);
                    listarRQ();
                    if (window.hanaCampanaActualizar) { window.hanaCampanaActualizar(); }
                });
            })
            .fail(function (xhr) {
                var mensaje = 'No se pudo guardar la corrección.';
                try { mensaje = JSON.parse(xhr.responseText).error || mensaje; } catch (error) {}
                alert(mensaje);
                hanaBoton('#btnGuardarEdicion', false);
            });
    };

    //Si retrocede, se confirma: la RQ vuelve a quedar pendiente de aprobación
    if (detalleRQ.retrocede) {
        hanaConfirmar('La RQ volverá a "Solicitada" y quedará pendiente de aprobación otra vez. ¿Continuar?',
                      enviar, { aceptar: 'Sí, enviar', cancelar: 'No' });
    } else {
        enviar();
    }
}
