<?php
//Pantalla "Mis notificaciones"
//Muestra los avisos de la persona (novedades, RQ y promesa de valor): primero los
//que no ha abierto, ordenados por prioridad. Los ya leídos se ven más tenues.
//Se pueden filtrar por fecha, tipo, prioridad, peaje, si están leídos y por texto
//(Control/CampanaControl.php?op=filtrar)

session_start(); //se necesita la sesión para saber de quién son las notificaciones

//Sin sesión activa se manda al login (al final del archivo)
if (isset($_SESSION['IdUsuarios'])) {

    include('head.php'); //cabecera común: menú lateral, CSS, barra superior
?>

<!-- page content -->
<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="x_panel">

        <div class="x_title">
          <h2><i class="fa fa-bell"></i> Mis notificaciones</h2>
          <!-- Botón para dejar todo como leído -->
          <button type="button" class="btn btn-default pull-right" id="btnLeerTodas" style="margin-top:4px;">
            <i class="fa fa-check"></i> Marcar todas como leídas
          </button>
          <div class="clearfix"></div>
        </div>  

        <div class="x_content">
          <!-- Leyenda de los colores, para que nadie tenga que adivinar -->
          <p style="color:#6B7076; margin-bottom:14px;">
            Novedades asignadas a ti o a tu rol, RQ dirigidas a tu rol y avisos de la promesa de valor. Sin fechas, los de los últimos 30 días.
            <span style="margin-left:10px;">
              <span class="hana-notif-fila" style="display:inline; padding:0; border:0; cursor:default;">
                <span class="marca alta" style="display:inline-block; vertical-align:middle;"></span> Alta
                <span class="marca media" style="display:inline-block; vertical-align:middle; margin-left:10px;"></span> Media
                <span class="marca baja" style="display:inline-block; vertical-align:middle; margin-left:10px;"></span> Respondida
              </span>
            </span>
          </p>

          <!-- Filtros: se aplican solos al cambiarlos -->
          <div class="row hana-notif-filtros">
            <div class="form-group col-md-2 col-sm-4 col-xs-6">
              <label for="fDesde">Recibida desde:</label>
              <input type="date" id="fDesde" class="form-control">
            </div>
            <div class="form-group col-md-2 col-sm-4 col-xs-6">
              <label for="fHasta">Hasta:</label>
              <input type="date" id="fHasta" class="form-control">
            </div>
            <div class="form-group col-md-2 col-sm-4 col-xs-6">
              <label for="fTipo">Tipo:</label>
              <select id="fTipo" class="form-control">
                <option value="">Todos</option>
                <option value="novedad">Novedades</option>
                <option value="rq">RQ</option>
                <option value="promesa">Promesa de valor</option>
              </select>
            </div>
            <div class="form-group col-md-2 col-sm-4 col-xs-6">
              <label for="fPrioridad">Prioridad:</label>
              <select id="fPrioridad" class="form-control">
                <option value="">Todas</option>
                <option value="alta">Alta</option>
                <option value="media">Media</option>
                <option value="baja">Respondida</option>
              </select>
            </div>
            <div class="form-group col-md-2 col-sm-4 col-xs-6">
              <label for="fCentro">Peaje:</label>
              <select id="fCentro" class="form-control"><option value="0">Todos</option></select>
            </div>
            <div class="form-group col-md-2 col-sm-4 col-xs-6">
              <label for="fEstado">Estado:</label>
              <select id="fEstado" class="form-control">
                <option value="">Todas</option>
                <option value="nuevas">Sin leer</option>
                <option value="leidas">Leídas</option>
              </select>
            </div>
            <div class="form-group col-md-6 col-sm-8 col-xs-12">
              <label for="fTexto">Buscar:</label>
              <input type="search" id="fTexto" class="form-control" maxlength="60" placeholder="Parte del título, el peaje o el proyecto">
            </div>
            <div class="form-group col-md-6 col-sm-4 col-xs-12 hana-notif-pie-filtros">
              <span id="notifCuantas" class="hana-notif-cuantas"></span>
              <button type="button" class="btn btn-default btn-sm" id="btnLimpiarFiltros" style="display:none;">
                <i class="fa fa-times"></i> Quitar filtros
              </button>
            </div>
          </div>

          <!-- Aquí CampanaAjax.js y el script de abajo pintan la lista -->
          <div id="listaNotificaciones">
            <div class="hana-campana-vacia"><i class="fa fa-spinner fa-spin"></i>Cargando...</div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>
<!-- /page content -->

<?php include('footer.php'); ?>

<script>
//La lista completa de esta pantalla. Reutiliza el mismo controlador de la campana
(function ($) {

    //Escapa el texto para pintarlo como HTML sin riesgo
    function seguro(texto) { return $('<div>').text(texto == null ? '' : String(texto)).html(); }

    //Fecha legible: "17/09/2026 10:30"
    function fechaLegible(fecha) {
        var momento = new Date(String(fecha).replace(' ', 'T'));
        if (isNaN(momento.getTime())) { return seguro(fecha); }
        function dos(numero) { return ('0' + numero).slice(-2); }
        return dos(momento.getDate()) + '/' + dos(momento.getMonth() + 1) + '/' + momento.getFullYear() +
               ' ' + dos(momento.getHours()) + ':' + dos(momento.getMinutes());
    }

    //Lo que se dice de cada aviso: en las novedades, la prioridad; en la promesa, si ya se justificó
    function estadoAviso(tipo, relevancia) {
        if (tipo === 'promesa') { return relevancia === 'Justificada' ? 'Justificada' : 'Sin justificar'; }
        if (tipo === 'rq') { return relevancia; }
        return 'Prioridad ' + relevancia;
    }

    //Los filtros tal como están en pantalla
    function filtros() {
        return {
            desde: $('#fDesde').val() || '',
            hasta: $('#fHasta').val() || '',
            tipo: $('#fTipo').val() || '',
            prioridad: $('#fPrioridad').val() || '',
            centro: $('#fCentro').val() || '0',
            estado: $('#fEstado').val() || '',
            texto: $.trim($('#fTexto').val() || '')
        };
    }
    function hayFiltros(f) {
        return !!(f.desde || f.hasta || f.tipo || f.prioridad || parseInt(f.centro, 10) > 0 || f.estado || f.texto);
    }

    //El filtro de peaje: los peajes que aparecen en el periodo, agrupados por proyecto.
    //Si el que estaba elegido ya no aparece, se deja igual para no cambiarle el filtro a la persona
    function pintarPeajes(peajes) {
        var sel = $('#fCentro'), elegido = String(sel.val() || '0'), html = '<option value="0">Todos</option>', grupo = null, esta = false;
        for (var i = 0; i < peajes.length; i++) {
            var p = peajes[i], proyecto = p.proyecto || 'Sin proyecto';
            if (proyecto !== grupo) { html += (grupo !== null ? '</optgroup>' : '') + '<optgroup label="' + seguro(proyecto) + '">'; grupo = proyecto; }
            html += '<option value="' + parseInt(p.id, 10) + '">' + seguro(p.nombre) + '</option>';
            if (String(parseInt(p.id, 10)) === elegido) { esta = true; }
        }
        if (grupo !== null) { html += '</optgroup>'; }
        if (!esta && elegido !== '0') { html += '<option value="' + seguro(elegido) + '">(peaje elegido)</option>'; }
        sel.html(html).val(elegido);
    }

    //Trae los avisos con los filtros y los pinta como tarjetas
    var pedido = null, turno = 0;
    function cargar() {
        var f = filtros(), miTurno = ++turno;
        if (f.desde && f.hasta && f.desde > f.hasta) {
            $('#listaNotificaciones').html('<div class="hana-campana-vacia"><i class="fa fa-calendar-times-o"></i>La fecha «desde» es después de «hasta».</div>');
            $('#notifCuantas').text('');
            return;
        }
        $('#btnLimpiarFiltros').toggle(hayFiltros(f));
        if (pedido) { pedido.abort(); }
        pedido = $.getJSON('../Control/CampanaControl.php?op=filtrar', f)
            .done(function (r) {
                if (miTurno !== turno) { return; }
                var filas = r.items || [];
                pintarPeajes(r.peajes || []);
                var cont = $('#listaNotificaciones');
                var nuevas = 0;
                for (var j = 0; j < filas.length; j++) { if (parseInt(filas[j].leida, 10) !== 1) { nuevas++; } }
                $('#notifCuantas').text(filas.length ? (filas.length + (filas.length === 1 ? ' notificación' : ' notificaciones') +
                                        (nuevas ? ' · ' + nuevas + ' sin leer' : '') + (filas.length >= (r.limite || 500) ? ' (se muestran las primeras ' + (r.limite || 500) + ')' : '')) : '');
                if (!filas.length) {
                    cont.html('<div class="hana-campana-vacia"><i class="fa fa-check-circle"></i>' +
                              (hayFiltros(f) ? 'No hay notificaciones con esos filtros.' : 'No tienes notificaciones en los últimos 30 días.') + '</div>');
                    return;
                }
                var html = '';
                for (var i = 0; i < filas.length; i++) {
                    var aviso = filas[i];
                    var leida = parseInt(aviso.leida, 10) === 1;
                    var tipo = (aviso.tipo === 'rq' || aviso.tipo === 'promesa') ? aviso.tipo : 'novedad';
                    var nombreTipo = tipo === 'rq' ? 'RQ' : (tipo === 'promesa' ? 'Promesa de valor' : 'Novedad');
                    html += '<div class="hana-notif-fila' + (leida ? ' leida' : '') + '" data-id="' +
                            parseInt(aviso.id, 10) + '" data-tipo="' + tipo + '">' +
                            '<span class="marca ' + seguro(aviso.prioridad) + '"></span>' +
                            '<div class="texto">' +
                            '<div class="titulo">' + seguro(aviso.titulo) +
                            (leida ? '' : '<span class="nueva">NUEVA</span>') + '</div>' +
                            '<div class="detalle"><span class="hana-notif-tipo">' + nombreTipo + '</span> ' +
                            seguro(aviso.proyecto ? aviso.proyecto + ' · ' + aviso.estacion : aviso.estacion) + ' · ' +
                            seguro(estadoAviso(tipo, aviso.relevancia)) + ' · ' + fechaLegible(aviso.fecha) + '</div>' +
                            '</div></div>';
                }
                cont.html(html);
            })
            .fail(function (xhr, estado) {
                if (estado === 'abort') { return; }
                hanaErrorAjax(xhr, 'No se pudieron cargar las notificaciones.');
            });
    }

    $(function () {
        cargar();

        //Los filtros se aplican solos; el texto, cuando se deja de escribir
        $('#fDesde, #fHasta, #fTipo, #fPrioridad, #fCentro, #fEstado').on('change', cargar);
        var espera = null;
        $('#fTexto').on('input', function () { clearTimeout(espera); espera = setTimeout(cargar, 350); });
        $('#btnLimpiarFiltros').on('click', function () {
            $('#fDesde, #fHasta, #fTexto').val('');
            $('#fTipo, #fPrioridad, #fEstado').val('');
            $('#fCentro').val('0');
            cargar();
        });

        //Al hacer clic se marca como leída y se abre la novedad
        $(document).on('click', '.hana-notif-fila[data-id]', function () {
            window.hanaCampanaAbrir($(this).data('id'), $(this).data('tipo'));
        });

        $('#btnLeerTodas').on('click', function () {
            hanaConfirmar('¿Marcar todas las notificaciones como leídas?', function () {
                $.post('../Control/CampanaControl.php?op=leerTodas')
                    .done(function () {
                        cargar();
                        if (window.hanaCampanaActualizar) { window.hanaCampanaActualizar(); }
                    })
                    .fail(function (xhr) { hanaErrorAjax(xhr, 'No se pudieron marcar como leídas.'); });
            });
        });
    });

})(window.jQuery);
</script>

<?php
} else {
    //No hay sesión: al login
    echo "<script>
  <!--
  window.location.replace('login.php');
  //-->
  </script>";
}
?>
