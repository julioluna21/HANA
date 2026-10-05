<?php
//Pantalla "Mis notificaciones"
//Muestra las novedades asignadas a la persona en las últimas semanas: primero las
//que no ha abierto, ordenadas por prioridad. Las ya leídas se ven más tenues

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
            Novedades asignadas a ti o a tu rol, y RQ dirigidas a tu rol, de los últimos 30 días.
            <span style="margin-left:10px;">
              <span class="hana-notif-fila" style="display:inline; padding:0; border:0; cursor:default;">
                <span class="marca alta" style="display:inline-block; vertical-align:middle;"></span> Alta
                <span class="marca media" style="display:inline-block; vertical-align:middle; margin-left:10px;"></span> Media
                <span class="marca baja" style="display:inline-block; vertical-align:middle; margin-left:10px;"></span> Aprobada
              </span>
            </span>
          </p>

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

    function seguro(t) { return $('<div>').text(t == null ? '' : String(t)).html(); }

    //Fecha legible: "17/09/2026 10:30"
    function fechaLegible(f) {
        var d = new Date(String(f).replace(' ', 'T'));
        if (isNaN(d.getTime())) { return seguro(f); }
        function dos(n) { return ('0' + n).slice(-2); }
        return dos(d.getDate()) + '/' + dos(d.getMonth() + 1) + '/' + d.getFullYear() +
               ' ' + dos(d.getHours()) + ':' + dos(d.getMinutes());
    }

    function cargar() {
        $.getJSON('../Control/CampanaControl.php?op=lista')
            .done(function (filas) {
                var cont = $('#listaNotificaciones');
                if (!filas.length) {
                    cont.html('<div class="hana-campana-vacia"><i class="fa fa-check-circle"></i>' +
                              'No tienes novedades asignadas en los últimos 30 días.</div>');
                    return;
                }
                var html = '';
                for (var i = 0; i < filas.length; i++) {
                    var f = filas[i];
                    var leida = parseInt(f.leida, 10) === 1;
                    html += '<div class="hana-notif-fila' + (leida ? ' leida' : '') + '" data-id="' +
                            parseInt(f.id, 10) + '" data-tipo="' + (f.tipo === 'rq' ? 'rq' : 'novedad') + '">' +
                            '<span class="marca ' + seguro(f.prioridad) + '"></span>' +
                            '<div class="texto">' +
                            '<div class="titulo">' + seguro(f.titulo) +
                            (leida ? '' : '<span class="nueva">NUEVA</span>') + '</div>' +
                            '<div class="detalle">' + seguro(f.estacion) + ' · Prioridad ' +
                            seguro(f.relevancia) + ' · ' + fechaLegible(f.fecha) + '</div>' +
                            '</div></div>';
                }
                cont.html(html);
            })
            .fail(function (xhr) {
                hanaErrorAjax(xhr, 'No se pudieron cargar las notificaciones.');
            });
    }

    $(function () {
        cargar();

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
