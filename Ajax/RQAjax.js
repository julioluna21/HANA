//Módulo de Requisiciones (RQ)
//Listado con filtros por periodo, formulario con varios ítems y fotos, y
//detalle con la aprobación. Todo el texto que viene del servidor se escapa
//antes de pintarlo: lo escriben los usuarios
//Fase 1: RQ normal (amarillo) y RQ U urgente (rojo); se solicita y se aprueba
//o se rechaza; solo aprueba quien tiene el permiso 18M

var URL_RQ = '../Control/RQControl.php';
var tablaRQ = null;
var centrosRQ = [];      //peajes asignados al usuario, con su proyecto
var archivosRQ = [];     //archivos elegidos para la RQ nueva (ya comprimidos)
var rqAbierta = null;    //id de la RQ que se está viendo en el detalle
var vistaRQ = 'mias';    //"mias" = Mis RQ; "todas" = lo que mi rol puede ver
var detalleRQ = null;    //la última RQ abierta, para prellenar la edición
var configRQ = { verTodas: false, puedeAprobar: false, anios: [] };

//---------------------------------------------------------------------------
// Utilidades
//---------------------------------------------------------------------------
function esc(t) { return $('<div>').text(t == null ? '' : String(t)).html(); }

//"2026-09-18" -> "18/09/2026"
function fechaCorta(f) {
    if (!f) { return ''; }
    var p = String(f).substring(0, 10).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : esc(f);
}
function fechaHora(f) {
    if (!f) { return ''; }
    return fechaCorta(f) + ' ' + String(f).substring(11, 16);
}

//Fecha de hoy en la zona del equipo (no en UTC, que adelanta el día de noche)
function hoyLocal() {
    var d = new Date();
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
}

function badgeEstado(nombre, color) {
    return '<span class="rq-badge rq-badge-' + esc(color || 'gris') + '">' + esc(nombre) + '</span>';
}

//"RQ-12" o "RQ U-12"
function textoRQ(tipo, numero) {
    return (tipo === 'U' ? 'RQ U-' : 'RQ-') + numero;
}

//La etiqueta de color: amarilla la normal, roja la urgente
function etiquetaRQ(tipo, numero) {
    var u = tipo === 'U';
    return '<span class="rq-etq ' + (u ? 'rq-etq-u' : 'rq-etq-n') + '"' +
           (u ? ' title="Urgente"' : '') + '>' + esc(textoRQ(tipo, numero)) + '</span>';
}

//Los dos botones de tipo, para el formulario de edición
function htmlTipoRQ(tipo) {
    var u = tipo === 'U';
    return '<div class="rq-tipos" role="radiogroup" aria-label="Tipo de RQ">' +
           '<label class="rq-tipo-op rq-tipo-op-n"><input type="radio" name="tipo" value="N"' + (u ? '' : ' checked') + '>' +
           '<span><strong>RQ normal</strong><small>Llega en amarillo.</small></span></label>' +
           '<label class="rq-tipo-op rq-tipo-op-u"><input type="radio" name="tipo" value="U"' + (u ? ' checked' : '') + '>' +
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
    //Con "Todos los años" el mes no aplica
    $('#fAnio').on('change', function () {
        $('#fMes').prop('disabled', $(this).val() === '0');
        listarRQ();
    });

    //Al elegir el peaje se muestra qué número le tocaría
    $('#rqCentro').on('change', function () {
        var c = $(this).val();
        $('#rqNumero').attr('placeholder', 'Automático');
        if (!c || $('#btnNuevaRQ').attr('data-puede') === '0') { return; } //quien no puede pedir RQ no necesita el siguiente número
        $.getJSON(URL_RQ + '?op=siguienteNumero&centro=' + encodeURIComponent(c))
            .done(function (d) { $('#rqNumero').attr('placeholder', 'Automático: ' + d.numero); });
    });

    //Archivos: dos caminos, cámara o explorador
    $('#btnRqCamara').on('click', function () { $('#rqArchivosCamara').val('')[0].click(); });
    $('#btnRqArchivos').on('click', function () { $('#rqArchivos').val('')[0].click(); });
    $('#rqArchivosCamara, #rqArchivos').on('change', function () { agregarArchivos(this.files); });
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
        .done(function (d) {
            configRQ = d;
            $('#vistaRQ .hana-vista-todas').text(d.verTodas ? 'Todas las RQ' : 'Las de mi rol');
            var h = '', anios = d.anios || [];
            for (var i = 0; i < anios.length; i++) {
                h += '<option value="' + parseInt(anios[i], 10) + '">' + parseInt(anios[i], 10) + '</option>';
            }
            h += '<option value="0">Todos</option>';
            $('#fAnio').html(h).val(String(hoy.getFullYear()));
        });

    $.getJSON(URL_RQ + '?op=centros')
        .done(function (filas) {
            centrosRQ = filas;
            if (!filas.length && $('#btnNuevaRQ').attr('data-puede') !== '0') { //solo le importa a quien puede pedir
                avisoRQ('No tienes peajes para pedir RQ. Las pide el coordinador del proyecto.');
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
            var vistos = {}, hp = '<option value="0">Todos</option>';
            for (var j = 0; j < filas.length; j++) {
                if (!vistos[filas[j].ID_PROYECTO]) {
                    vistos[filas[j].ID_PROYECTO] = true;
                    hp += '<option value="' + parseInt(filas[j].ID_PROYECTO, 10) + '">' + esc(filas[j].NOM_PROYECTO) + '</option>';
                }
            }
            $('#fProyecto').html(hp);
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
    var p = $('#fProyecto').val(), h = '<option value="0">Todos</option>';
    for (var i = 0; i < centrosRQ.length; i++) {
        if (p === '0' || String(centrosRQ[i].ID_PROYECTO) === p) {
            h += '<option value="' + parseInt(centrosRQ[i].ID_CENTRO_OP, 10) + '">' + esc(centrosRQ[i].NOM_CENTRO_OP) + '</option>';
        }
    }
    $('#fCentro').html(h);
}

//---------------------------------------------------------------------------
// Listado
//---------------------------------------------------------------------------
function listarRQ() {
    var q = '&proyecto=' + encodeURIComponent($('#fProyecto').val() || 0) +
            '&centro='   + encodeURIComponent($('#fCentro').val()   || 0) +
            '&anio='     + encodeURIComponent($('#fAnio').val()     || 0) +
            '&mes='      + encodeURIComponent($('#fMes').val()      || 0);

    $.getJSON(URL_RQ + '?op=listar&vista=' + encodeURIComponent(vistaRQ) + q)
        .done(function (d) {
            var filas = d.filas || [];

            //Contadores del periodo
            var pendientes = 0, urgentes = 0, aprobadas = 0, rechazadas = 0;
            for (var i = 0; i < filas.length; i++) {
                var f = filas[i], est = parseInt(f.ID_RQ_ESTADO, 10);
                if (parseInt(f.ESTADO, 10) !== 1) { continue; } //las anuladas no cuentan
                if (est === 1) { pendientes++; if (f.TIPO_RQ === 'U') { urgentes++; } }
                if (est === 3) { aprobadas++; }
                if (est === 7) { rechazadas++; }
            }
            $('#cntPendientes').text(pendientes);
            $('#cntUrgentes').text(urgentes);
            $('#cntAprobadas').text(aprobadas);
            $('#cntRechazadas').text(rechazadas);

            pintarTabla(filas);
        })
        .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudo cargar el listado de RQ.'); });
}

function pintarTabla(filas) {
    if (tablaRQ) { tablaRQ.destroy(); $('#tblRQ tbody').empty(); }

    tablaRQ = $('#tblRQ').DataTable({
        data: filas,
        order: [],        //el servidor ya las manda de la más nueva a la más vieja
        pageLength: 10,   //uno de los valores del selector "Mostrar registros"
        //Las urgentes pendientes se marcan en la fila, para que se vean de lejos
        createdRow: function (tr, f) {
            if (f.TIPO_RQ === 'U' && parseInt(f.ID_RQ_ESTADO, 10) === 1 && parseInt(f.ESTADO, 10) === 1) {
                $(tr).addClass('rq-fila-urgente');
            }
        },
        columns: [
            { data: null, render: function (f, tipo) {
                //Al buscar o exportar se usa el texto; en pantalla, la etiqueta de color
                if (tipo !== 'display') { return textoRQ(f.TIPO_RQ, f.NUMERO_RQ) + (f.TIPO_RQ === 'U' ? ' urgente' : ''); }
                return etiquetaRQ(f.TIPO_RQ, f.NUMERO_RQ);
            } },
            { data: 'NOM_PROYECTO', render: function (v) { return esc(v); } },
            { data: 'NOM_CENTRO_OP', render: function (v) { return esc(v); } },
            { data: 'FECHA_RQ', render: function (v, tipo) {
                return tipo === 'sort' ? v : fechaCorta(v);   //ordena bien aunque se vea dd/mm
            } },
            { data: null, render: function (f) {
                var n = parseInt(f.ITEMS, 10);
                return '<span class="rq-resumen">' + esc(f.RESUMEN) + '</span>' +
                       (n > 1 ? ' <span class="rq-cant-items">' + n + ' ítems</span>' : '');
            } },
            { data: null, render: function (f) {
                var h = badgeEstado(f.NOM_RQ_ESTADO, f.COLOR);
                if (parseInt(f.ESTADO, 10) === 0) { h = badgeEstado('Anulada', 'gris'); }
                return h;
            } },
            { data: null, render: function (f, tipo) {
                if (tipo === 'sort') { return parseInt(f.DIAS_EN_ESTADO, 10); }
                if (parseInt(f.ES_FINAL, 10) === 1 || parseInt(f.ESTADO, 10) === 0) {
                    return '<span class="rq-dias-fin">' + esc(f.DIAS_TOTALES) + ' d total</span>';
                }
                //Pendiente: cuántos días lleva esperando aprobación
                var h = esc(f.DIAS_EN_ESTADO) + ' de ' + esc(f.DIAS_ESPERADOS) + ' d';
                if (parseInt(f.VENCIDA, 10) === 1) { h = '<span class="rq-vencida">' + h + ' · Sin aprobar</span>'; }
                return h;
            } },
            { data: null, orderable: false, render: function (f) {
                //Quien aprueba ve "Revisar" en las pendientes; los demás, "Ver"
                var revisar = configRQ.puedeAprobar && parseInt(f.ID_RQ_ESTADO, 10) === 1 && parseInt(f.ESTADO, 10) === 1;
                return '<button type="button" class="btn btn-success btn-xs btn-ver-rq" data-id="' +
                       parseInt(f.ID_RQ, 10) + '" title="' + (revisar ? 'Aprobar o rechazar' : 'Ver la RQ') + '">' +
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
        '      <label>Unidad:</label>' +
        '      <input type="text" name="unidad[]" class="form-control" maxlength="30" value="Unidad">' +
        '    </div>' +
        '  </div>' +
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
    lector.onerror = function () { listo(archivo); };
    lector.onload = function (e) {
        var img = new Image();
        img.onerror = function () { listo(archivo); };
        img.onload = function () {
            try {
                var w = img.width, h = img.height, mayor = Math.max(w, h);
                if (mayor > 1600) { w = Math.round(w * 1600 / mayor); h = Math.round(h * 1600 / mayor); }
                var c = document.createElement('canvas');
                c.width = w; c.height = h;
                c.getContext('2d').drawImage(img, 0, 0, w, h);
                c.toBlob(function (blob) {
                    if (!blob || blob.size >= archivo.size) { listo(archivo); return; }
                    try {
                        listo(new File([blob], archivo.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }));
                    } catch (err) { listo(archivo); }
                }, 'image/jpeg', 0.72);
            } catch (err) { listo(archivo); }
        };
        img.src = e.target.result;
    };
    lector.readAsDataURL(archivo);
}

function pintarPrevias() {
    var cont = $('#rqPrevias').empty();
    for (var i = 0; i < archivosRQ.length; i++) {
        var a = archivosRQ[i];
        var kb = Math.max(1, Math.round(a.size / 1024));
        var caja = $('<div class="rq-previa"></div>');
        if (a.type.indexOf('image/') === 0) {
            $('<img alt="">').attr('src', URL.createObjectURL(a)).appendTo(caja);
        } else {
            caja.append('<div class="rq-previa-pdf"><i class="fa fa-file-pdf-o"></i></div>');
        }
        caja.append('<div class="rq-previa-nombre">' + esc(a.name) + '<br><small>' + kb + ' KB</small></div>');
        caja.append('<button type="button" class="rq-previa-quitar" data-i="' + i + '" title="Quitar">&times;</button>');
        cont.append(caja);
    }
}

//---------------------------------------------------------------------------
// Guardar
//---------------------------------------------------------------------------
function guardarRQ(e) {
    e.preventDefault();

    if (!$('#rqCentro').val()) { alert('Elige el peaje.'); $('#rqCentro').focus(); return; }
    var conDescripcion = $('#rqItems .rq-desc').filter(function () { return $.trim(this.value) !== ''; }).length;
    if (!conDescripcion) { alert('Escribe qué se solicita en al menos un ítem.'); return; }

    //El formulario ya incluye el tipo (N o U)
    var fd = new FormData($('#formRQ')[0]);
    for (var i = 0; i < archivosRQ.length; i++) {
        fd.append('archivos[]', archivosRQ[i], archivosRQ[i].name);
    }

    hanaBoton('#btnGuardarRQ', true);
    $.ajax({ url: URL_RQ + '?op=guardar', type: 'POST', data: fd,
             contentType: false, processData: false, dataType: 'json' })
        .done(function (r) {
            alert(r.mensaje, function () {
                mostrarFormulario(false);
                listarRQ();
                if (window.hanaCampanaActualizar) { window.hanaCampanaActualizar(); }
            });
        })
        .fail(function (xhr) {
            var msg = 'No se pudo registrar la RQ.';
            try { msg = JSON.parse(xhr.responseText).error || msg; } catch (err) {}
            alert(msg); //el formulario se conserva para corregir
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
            var msg = 'No se pudo cargar la RQ.';
            try { msg = JSON.parse(xhr.responseText).error || msg; } catch (err) {}
            alert(msg);
        });
}

function pintarDetalle(d) {
    detalleRQ = d;
    var r = d.rq, anulada = parseInt(r.ESTADO, 10) === 0;
    $('#modalRQTitulo').html(esc(textoRQ(r.TIPO_RQ, r.NUMERO_RQ)) + ' · ' + esc(r.NOM_PROYECTO));

    var vencida = !anulada && parseInt(r.ES_FINAL, 10) === 0 && parseInt(r.DIAS_ESPERADOS, 10) > 0 &&
                  parseInt(r.DIAS_EN_ESTADO, 10) > parseInt(r.DIAS_ESPERADOS, 10);

    var h = '<div class="rq-det-cab">' +
            '<div><span class="rq-det-lbl">Tipo</span><span class="rq-etq ' + (r.TIPO_RQ === 'U' ? 'rq-etq-u">Urgente' : 'rq-etq-n">Normal') +
                '</span></div>' +
            '<div><span class="rq-det-lbl">Peaje</span>' + esc(r.NOM_CENTRO_OP) + '</div>' +
            '<div><span class="rq-det-lbl">Fecha</span>' + fechaCorta(r.FECHA_RQ) + '</div>' +
            '<div><span class="rq-det-lbl">Solicitó</span>' + esc(r.SOLICITA) + '</div>' +
            '<div><span class="rq-det-lbl">Estado</span>' +
                (anulada ? badgeEstado('Anulada', 'gris') : badgeEstado(r.NOM_RQ_ESTADO, r.COLOR)) +
                (vencida ? ' <span class="rq-vencida">Sin aprobar</span>' : '') + '</div>' +
            '</div>';


    //Ítems
    h += '<h5 class="rq-det-sub">Qué se solicita</h5><div class="rq-det-items">';
    for (var i = 0; i < d.items.length; i++) {
        var it = d.items[i];
        h += '<div class="rq-det-item"><div class="rq-det-item-cab"><strong>' + esc(it.DESCRIPCION) + '</strong>' +
             '<span>' + esc(parseFloat(it.CANTIDAD)) + ' ' + esc(it.UNIDAD) + '</span></div>' +
             (it.JUSTIFICACION ? '<div class="rq-det-item-just">' + esc(it.JUSTIFICACION) + '</div>' : '') +
             (it.SOPORTE_ULTIMA_ADQUISICION ? '<div class="rq-det-item-sop"><i class="fa fa-paperclip"></i> Última adquisición: ' +
               esc(it.SOPORTE_ULTIMA_ADQUISICION) + '</div>' : '') +
             '</div>';
    }
    h += '</div>';

    if (r.OBSERVACION_SST) {
        h += '<h5 class="rq-det-sub">Observación</h5><p>' + esc(r.OBSERVACION_SST) + '</p>';
    }

    //Archivos
    if (d.archivos.length) {
        h += '<h5 class="rq-det-sub">Evidencias y soportes</h5><div class="rq-det-archivos">';
        for (var j = 0; j < d.archivos.length; j++) {
            var a = d.archivos[j], url = esc(a.URL);
            if (/\.pdf$/i.test(a.RUTA)) {
                h += '<a class="rq-det-archivo rq-det-pdf" href="' + url + '" target="_blank" rel="noopener">' +
                     '<i class="fa fa-file-pdf-o"></i><span>' + esc(a.NOMBRE_ORIGINAL) + '</span></a>';
            } else {
                h += '<a class="rq-det-archivo" href="' + url + '" target="_blank" rel="noopener" title="' +
                     esc(a.NOMBRE_ORIGINAL) + '"><img src="' + url + '" alt="' + esc(a.NOMBRE_ORIGINAL) + '"></a>';
            }
        }
        h += '</div>';
    }

    //Aprobar o rechazar (permiso 18M), y anular (quien la pidió o quien aprueba).
    //El servidor manda qué se puede hacer; aquí solo se pintan esos botones
    var aprobar = !anulada && d.siguientes.length > 0;
    if (aprobar || d.puedeAnular) {
        h += '<div class="rq-det-accion"><h5 class="rq-det-sub" style="margin-top:0">' +
             (aprobar ? 'Aprobación' : 'Anular la solicitud') + '</h5>' +
             '<div class="form-group"><label for="rqObsEstado">Observación:</label>' +
             '<input type="text" id="rqObsEstado" class="form-control" maxlength="500" ' +
             'placeholder="' + (aprobar ? 'Opcional al aprobar; obligatoria al rechazar o anular' : 'Obligatoria: por qué se anula') + '"></div>' +
             '<div class="rq-acciones">';
        if (d.puedeAnular) {
            h += '<button type="button" class="btn btn-default" id="btnAnularRQ"><i class="fa fa-ban"></i> Anular</button>';
        }
        for (var k = 0; k < d.siguientes.length; k++) {
            var sig = d.siguientes[k], idSig = parseInt(sig.ID_RQ_ESTADO, 10);
            h += '<button type="button" class="btn ' + (idSig === 7 ? 'btn-danger' : 'btn-success') + ' btn-estado-rq" ' +
                 'data-estado="' + idSig + '"><i class="fa ' + (idSig === 7 ? 'fa-times' : 'fa-check') + '"></i> ' +
                 (idSig === 7 ? 'Rechazar' : 'Aprobar') + '</button>';
        }
        h += '</div></div>';
    }

    //Editar: en "Solicitada", quien la pidió o quien aprueba. Si fue rechazada,
    //quien la pidió la corrige y la envía de nuevo
    if (d.puedeEditar) {
        h += '<div class="rq-det-editar">' +
             (d.retrocede
                ? '<p><i class="fa fa-undo"></i> Fue rechazada. Si la corriges, <strong>vuelve a "Solicitada"</strong> ' +
                  'y queda pendiente de aprobación otra vez.</p>'
                : '') +
             '<button type="button" class="btn btn-default" id="btnEditarRQ"><i class="fa ' + (d.retrocede ? 'fa-undo' : 'fa-pencil') + '"></i> ' +
             (d.retrocede ? 'Corregir y enviar de nuevo' : 'Editar RQ') + '</button></div>';
    }

    //Historial
    h += '<h5 class="rq-det-sub">Historial</h5><ul class="rq-historial">';
    for (var m = 0; m < d.historial.length; m++) {
        var s = d.historial[m];
        h += '<li><div class="rq-hist-fecha">' + fechaHora(s.FECHA) + '</div>' +
             '<div class="rq-hist-texto"><strong>' + (s.ANTERIOR ? esc(s.ANTERIOR) + ' → ' : '') + esc(s.NUEVO) + '</strong>' +
             ' · ' + esc(s.QUIEN) +
             (s.OBSERVACION ? '<div class="rq-hist-obs">' + esc(s.OBSERVACION) + '</div>' : '') +
             '</div></li>';
    }
    h += '</ul>';

    $('#modalRQCuerpo').html(h);
}

function cambiarEstadoRQ(nuevo) {
    nuevo = parseInt(nuevo, 10);
    var rechaza = nuevo === 7;
    var obs = $.trim($('#rqObsEstado').val());
    if (rechaza && !obs) { alert('Para rechazar una RQ hay que explicar el motivo en la observación.'); $('#rqObsEstado').focus(); return; }

    var boton = '.btn-estado-rq[data-estado="' + nuevo + '"]';
    hanaConfirmar(rechaza ? '¿Rechazar esta RQ? Quien la pidió verá el motivo y podrá corregirla.' : '¿Aprobar esta RQ?', function () {
        hanaBoton(boton, true);
        $.ajax({ url: URL_RQ + '?op=cambiarEstado', type: 'POST', dataType: 'json',
                 data: { id: rqAbierta, estado: nuevo, observacion: obs } })
            .done(function () {
                verRQ(rqAbierta);
                listarRQ();
                if (window.hanaCampanaActualizar) { window.hanaCampanaActualizar(); }
            })
            .fail(function (xhr) {
                var msg = 'No se pudo guardar la decisión.';
                try { msg = JSON.parse(xhr.responseText).error || msg; } catch (err) {}
                alert(msg);
                hanaBoton(boton, false);
            });
    }, { aceptar: rechaza ? 'Sí, rechazar' : 'Sí, aprobar', cancelar: 'No' });
}

function anularRQ() {
    var obs = $.trim($('#rqObsEstado').val());
    if (!obs) { alert('Para anular una RQ hay que explicar el motivo en la observación.'); $('#rqObsEstado').focus(); return; }

    hanaConfirmar('¿Anular esta RQ? No se borra: queda en el historial como anulada.', function () {
        $.ajax({ url: URL_RQ + '?op=anular', type: 'POST', dataType: 'json',
                 data: { id: rqAbierta, observacion: obs } })
            .done(function (r) { verRQ(rqAbierta); listarRQ(); })
            .fail(function (xhr) {
                var msg = 'No se pudo anular la RQ.';
                try { msg = JSON.parse(xhr.responseText).error || msg; } catch (err) {}
                alert(msg);
            });
    }, { aceptar: 'Sí, anular', cancelar: 'No' });
}

//---------------------------------------------------------------------------
// Edición y retroceso
//---------------------------------------------------------------------------

//Una fila de ítem para el editor, con valores o vacía
function htmlItemRQ(v) {
    return '<div class="rq-item">' +
        '<div class="rq-item-cab"><span class="rq-item-num">Ítem</span>' +
        '<button type="button" class="rq-item-quitar rq-ed-quitar"><i class="fa fa-times"></i> Quitar</button></div>' +
        '<div class="row"><div class="form-group col-sm-6 col-xs-12"><label>Qué se solicita: <span class="rq-req">*</span></label>' +
        '<input type="text" name="descripcion[]" class="form-control" maxlength="255" value="' + esc(v.DESCRIPCION || '') + '"></div>' +
        '<div class="form-group col-sm-3 col-xs-6"><label>Cantidad:</label>' +
        '<input type="number" name="cantidad[]" class="form-control" min="0.01" step="any" value="' + esc(v.CANTIDAD ? parseFloat(v.CANTIDAD) : 1) + '"></div>' +
        '<div class="form-group col-sm-3 col-xs-6"><label>Unidad:</label>' +
        '<input type="text" name="unidad[]" class="form-control" maxlength="30" value="' + esc(v.UNIDAD || 'Unidad') + '"></div></div>' +
        '<div class="row"><div class="form-group col-sm-6 col-xs-12"><label>Por qué se necesita:</label>' +
        '<textarea name="justificacion[]" class="form-control" rows="2">' + esc(v.JUSTIFICACION || '') + '</textarea></div>' +
        '<div class="form-group col-sm-6 col-xs-12"><label>Soporte de la última adquisición:</label>' +
        '<input type="text" name="soporte[]" class="form-control" maxlength="255" value="' + esc(v.SOPORTE_ULTIMA_ADQUISICION || '') + '"></div></div>' +
        '</div>';
}

//Cambia el detalle por un formulario con los datos actuales
function abrirEdicionRQ() {
    var d = detalleRQ, r = d.rq;
    var h = '<form id="formEditarRQ" autocomplete="off">' +
        (d.retrocede
            ? '<div class="rq-det-riesgo"><i class="fa fa-undo"></i> Al guardar, la RQ vuelve a <strong>"Solicitada"</strong> ' +
              'y queda pendiente de aprobación otra vez.</div>'
            : '') +
        '<div class="row"><div class="form-group col-sm-4 col-xs-12"><label>Fecha de la RQ:</label>' +
        '<input type="date" name="fecha" class="form-control" max="' + hoyLocal() + '" value="' + esc(String(r.FECHA_RQ).substring(0, 10)) + '"></div></div>' +
        '<h5 class="rq-det-sub">Tipo de RQ</h5>' + htmlTipoRQ(r.TIPO_RQ) +
        '<h5 class="rq-det-sub">Qué se solicita</h5><div id="rqEdItems">';
    for (var i = 0; i < d.items.length; i++) { h += htmlItemRQ(d.items[i]); }
    if (!d.items.length) { h += htmlItemRQ({}); }
    h += '</div><button type="button" class="btn btn-default btn-sm" id="btnAgregarItemEd"><i class="fa fa-plus"></i> Agregar ítem</button>' +
        '<div class="form-group" style="margin-top:14px;"><label>Observación:</label>' +
        '<textarea name="sst" class="form-control" rows="2">' + esc(r.OBSERVACION_SST || '') + '</textarea></div>' +
        '<div class="form-group" style="margin-top:14px;"><label>' + (d.retrocede ? 'Qué se corrigió: <span class="rq-req">*</span>' : 'Qué se corrigió (opcional):') + '</label>' +
        '<input type="text" name="motivo" class="form-control" maxlength="500" placeholder="Ej: la cantidad estaba mal, eran 3 y no 1"></div>' +
        '<div class="rq-acciones"><button type="button" class="btn btn-default" id="btnCancelarEdicion">Cancelar</button>' +
        '<button type="button" class="btn btn-success" id="btnGuardarEdicion"><i class="fa fa-save"></i> ' +
        (d.retrocede ? 'Guardar y enviar de nuevo' : 'Guardar cambios') + '</button></div></form>';
    $('#modalRQCuerpo').html(h);
}

function guardarEdicionRQ() {
    var form = $('#formEditarRQ');
    if (!form.find('[name="descripcion[]"]').filter(function () { return $.trim(this.value) !== ''; }).length) {
        alert('La RQ debe tener al menos un ítem con su descripción.'); return;
    }
    if (detalleRQ.retrocede && !$.trim(form.find('[name="motivo"]').val())) {
        alert('Explica qué se corrige: queda en el historial.'); form.find('[name="motivo"]').focus(); return;
    }

    var enviar = function () {
        var fd = new FormData(form[0]);
        fd.append('id', rqAbierta);
        hanaBoton('#btnGuardarEdicion', true);
        $.ajax({ url: URL_RQ + '?op=editar', type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json' })
            .done(function (r) {
                alert(r.mensaje, function () {
                    verRQ(rqAbierta);
                    listarRQ();
                    if (window.hanaCampanaActualizar) { window.hanaCampanaActualizar(); }
                });
            })
            .fail(function (xhr) {
                var msg = 'No se pudo guardar la corrección.';
                try { msg = JSON.parse(xhr.responseText).error || msg; } catch (err) {}
                alert(msg);
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
