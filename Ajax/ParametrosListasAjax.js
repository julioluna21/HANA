//Configuración - Parámetros del sistema, pestaña Listas
//Las opciones de los desplegables (medios de los oficios, «Pendiente de», transportes, tipos del
//cronograma, estados de Gestión Humana) y los nombres de los conceptos de caja menor y recambio.
//Habla con Control/ParametrosControl.php: op=listas, op=guardarLista y op=concepto
var datosListas = null;

$(function () {
    cargarListas();
    $('#tabListasLink').on('shown.bs.tab', function () { if (!rdHayCambios) { cargarListas(); } });
    rdVigilarCambios('#parListas');
    $(document).on('click', '.lst-subir, .lst-bajar', function () {
        var fila = $(this).closest('tr');
        if ($(this).hasClass('lst-subir')) { fila.prev().before(fila); } else { fila.next().after(fila); }
        rdHayCambios = true;
    });
    $(document).on('click', '.lst-quitar', function () { $(this).closest('tr').remove(); rdHayCambios = true; });
    $(document).on('click', '.lst-agregar', function () {
        var tabla = $('#lst-' + $(this).data('clave') + ' tbody');
        if (tabla.find('tr').length >= datosListas.maxOpciones) { alert('La lista ya tiene el máximo de ' + datosListas.maxOpciones + ' opciones.'); return; }
        tabla.append(filaOpcion({ codigo: '', nombre: '', fijo: false, usos: 0 }));
        tabla.find('tr:last input').focus();
        rdHayCambios = true;
    });
    $(document).on('click', '.lst-guardar', function () { guardarLista($(this).data('clave'), this); });
    $(document).on('click', '.cpt-guardar', function () { guardarConcepto($(this).closest('tr'), this); });
});

//Trae las listas y los conceptos de arqueo y los dibuja
function cargarListas() {
    $.getJSON(URL_PAR + '?op=listas')
        .done(function (respuesta) {
            datosListas = respuesta;
            var html = '', grupo = '';
            respuesta.listas.forEach(function (lista) {
                if (lista.grupo !== grupo) { grupo = lista.grupo; html += '<h4 class="rd-subtitulo">' + rdEsc(grupo) + '</h4>'; }
                html += '<div class="lst-caja" style="margin-bottom:18px;" id="lst-' + rdEsc(lista.clave) + '"><p style="margin:0 0 4px;"><strong>' + rdEsc(lista.nombre) + '</strong></p>' +
                        (lista.ayuda ? '<p class="rd-ayuda" style="margin:0 0 6px;">' + rdEsc(lista.ayuda) + '</p>' : '') +
                        '<div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta"><thead><tr><th>Nombre que se ve</th>' +
                        '<th style="width:150px;">Uso</th><th style="width:130px;"></th></tr></thead><tbody>';
                lista.opciones.forEach(function (opcion) { html += filaOpcion(opcion); });
                html += '</tbody></table></div><div class="rd-acciones">' +
                        '<button type="button" class="btn btn-default btn-sm lst-agregar" data-clave="' + rdEsc(lista.clave) + '"><i class="fa fa-plus"></i> Agregar opción</button> ' +
                        '<button type="button" class="btn btn-success btn-sm lst-guardar" data-clave="' + rdEsc(lista.clave) + '"><i class="fa fa-save"></i> Guardar esta lista</button>' +
                        (lista.modifico ? ' <span class="rd-ayuda">Último cambio: ' + rdEsc(lista.modifico) + ' · ' + rdFecha(lista.fecha) + '</span>' : '') +
                        '</div></div>';
            });
            //Los conceptos fijos del arqueo de caja menor y recambio (tabla arqueo_concepto)
            html += '<h4 class="rd-subtitulo">Arqueos</h4><div class="lst-caja" style="margin-bottom:18px;"><p style="margin:0 0 4px;"><strong>Nombres de los conceptos de caja menor y de recambio</strong></p>' +
                    '<p class="rd-ayuda" style="margin:0 0 6px;">Es el nombre que se ve en la pantalla del arqueo. El PDF conserva los títulos del formato en papel. ' +
                    'Los campos del arqueo de casetas se configuran en <a href="ArqueosVista.php#campos">Arqueos → Campos de casetas</a>.</p>' +
                    '<div class="table-responsive"><table class="table table-bordered rd-tabla rd-tabla-compacta"><thead><tr><th style="width:130px;">Arqueo</th><th>Nombre que se ve</th><th style="width:100px;"></th></tr></thead><tbody>';
            respuesta.conceptos.forEach(function (concepto) {
                html += '<tr data-id="' + parseInt(concepto.ID_CONCEPTO, 10) + '"><td>' + (concepto.TIPO_ARQUEO === 'CAJA_MENOR' ? 'Caja menor' : 'Recambio') + '</td>' +
                        '<td><input type="text" class="form-control input-sm cpt-nombre" maxlength="80" value="' + rdEsc(concepto.NOMBRE) + '" aria-label="Nombre del concepto"></td>' +
                        '<td><button type="button" class="btn btn-success btn-xs cpt-guardar"><i class="fa fa-save"></i> Guardar</button></td></tr>';
            });
            html += '</tbody></table></div></div>';
            $('#parListas').html(html);
            rdCambiosGuardados();
        })
        .fail(function (xhr) { $('#parListas').html(''); hanaErrorAjax(xhr, 'No se pudieron cargar las listas.'); });
}

//Una fila de una lista: el nombre se edita; subir, bajar y quitar (si se puede)
function filaOpcion(opcion) {
    var uso = opcion.fijo ? '<span class="rd-tag">La usa el sistema</span>'
            : (opcion.usos ? '<span class="rd-tag rd-tag-ok">En ' + opcion.usos + (opcion.usos === 1 ? ' registro' : ' registros') + '</span>'
            : (opcion.codigo ? '<span class="rd-ayuda">Sin usar</span>' : '<span class="rd-tag rd-tag-pend">Nueva</span>'));
    var sePuedeQuitar = !opcion.fijo && !opcion.usos;
    return '<tr data-codigo="' + rdEsc(opcion.codigo) + '"><td><input type="text" class="form-control input-sm lst-nombre" maxlength="' + (datosListas ? datosListas.largoNombre : 60) +
           '" value="' + rdEsc(opcion.nombre) + '" aria-label="Nombre de la opción"></td><td>' + uso + '</td><td class="rd-nowrap">' +
           '<button type="button" class="btn btn-default btn-xs lst-subir" title="Subir"><i class="fa fa-arrow-up"></i></button> ' +
           '<button type="button" class="btn btn-default btn-xs lst-bajar" title="Bajar"><i class="fa fa-arrow-down"></i></button> ' +
           (sePuedeQuitar ? '<button type="button" class="btn btn-default btn-xs lst-quitar" title="Quitar"><i class="fa fa-trash"></i></button>'
                          : '<button type="button" class="btn btn-default btn-xs" disabled title="' + (opcion.fijo ? 'La usa el sistema: solo se le cambia el nombre' : 'Ya se usó: solo se le cambia el nombre') + '"><i class="fa fa-trash"></i></button>') +
           '</td></tr>';
}

//Guarda una lista completa, en el orden en que quedó
function guardarLista(clave, boton) {
    var opciones = [], vacia = false;
    $('#lst-' + clave + ' tbody tr').each(function () {
        var nombre = $.trim($(this).find('.lst-nombre').val());
        if (!nombre) { vacia = true; }
        opciones.push({ codigo: String($(this).data('codigo') || ''), nombre: nombre });
    });
    if (vacia) { alert('Hay una opción sin nombre. Escríbelo o quítala.'); return; }
    if (!opciones.length) { alert('La lista no puede quedar vacía.'); return; }
    hanaBoton(boton, true);
    $.post(URL_PAR + '?op=guardarLista', { clave: clave, opciones: JSON.stringify(opciones) }, null, 'json')
        .done(function (respuesta) { rdAviso(respuesta.mensaje); rdCambiosGuardados(); cargarListas(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar la lista.')); hanaBoton(boton, false); });
}

//Guarda el nombre de un concepto de caja menor o recambio
function guardarConcepto(fila, boton) {
    var nombre = $.trim(fila.find('.cpt-nombre').val());
    if (!nombre) { alert('Escribe el nombre del concepto.'); return; }
    hanaBoton(boton, true);
    $.post(URL_PAR + '?op=concepto', { id: fila.data('id'), nombre: nombre }, null, 'json')
        .done(function (respuesta) { rdAviso(respuesta.mensaje); rdCambiosGuardados(); })
        .fail(function (xhr) { alert(rdError(xhr, 'No se pudo guardar el nombre.')); })
        .always(function () { hanaBoton(boton, false); });
}
