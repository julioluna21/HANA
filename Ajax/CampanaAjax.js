//Campana de notificaciones de HANA
//Se carga en footer.php, así que funciona en todas las pantallas.
//Muestra cuántas novedades sin abrir tiene la persona, separadas por color:
//rojo alta, amarillo media, blanco baja

(function ($) {
    if (!$) { return; }

    var URL = '../Control/CampanaControl.php';
    var CADA = 60000;          //se revisa una vez por minuto
    var temporizador = null;
    var ultimoTotal = -1;      //para saber si llegó algo nuevo desde la última vez

    //Arma los círculos con su número. Solo aparecen los que tienen algo
    function circulos(conteo) {
        var html = '';
        var orden = ['alta', 'media', 'baja'];
        for (var i = 0; i < orden.length; i++) {
            var n = conteo[orden[i]] || 0;
            if (n > 0) {
                //Más de 99 no cabe en el círculo: se muestra "99+"
                var texto = n > 99 ? '99+' : n;
                html += '<span class="hana-punto hana-punto-' + orden[i] + '" ' +
                        'title="' + n + ' de prioridad ' + orden[i] + '">' + texto + '</span>';
            }
        }
        return html;
    }

    //Escapa el texto antes de pintarlo: los títulos los escriben los usuarios
    function seguro(t) { return $('<div>').text(t == null ? '' : String(t)).html(); }

    //"hace 5 min", "hace 2 h", "hace 3 días"
    function haceCuanto(fecha) {
        var f = new Date(String(fecha).replace(' ', 'T'));
        if (isNaN(f.getTime())) { return ''; }
        var seg = Math.floor((new Date() - f) / 1000);
        if (seg < 60) { return 'hace un momento'; }
        if (seg < 3600) { return 'hace ' + Math.floor(seg / 60) + ' min'; }
        if (seg < 86400) { return 'hace ' + Math.floor(seg / 3600) + ' h'; }
        var d = Math.floor(seg / 86400);
        return 'hace ' + d + (d === 1 ? ' día' : ' días');
    }

    //Pinta la lista del menú desplegable
    function pintarLista(items, total) {
        var lista = $('#campanaLista');
        if (!lista.length) { return; }

        if (!items.length) {
            lista.html('<li class="hana-campana-vacia"><i class="fa fa-check-circle"></i>' +
                       'No tienes novedades nuevas.</li>');
            $('#campanaTodas').hide();
            return;
        }

        var html = '';
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            html += '<li><a class="hana-campana-item" data-id="' + parseInt(it.id, 10) + '" data-tipo="' + (it.tipo === 'rq' ? 'rq' : 'novedad') + '">' +
                    '<span class="marca ' + seguro(it.prioridad) + '"></span>' +
                    '<span class="texto">' +
                    '<div class="titulo">' + seguro(it.titulo) + '</div>' +
                    '<div class="detalle">' + seguro(it.estacion) + ' · ' +
                    seguro(it.relevancia) + ' · ' + haceCuanto(it.fecha) + '</div>' +
                    '</span></a></li>';
        }
        //Si hay más de las que caben, se avisa
        if (total > items.length) {
            html += '<li class="hana-campana-vacia" style="padding:10px">y ' +
                    (total - items.length) + ' más</li>';
        }
        lista.html(html);
        $('#campanaTodas').show();
    }

    //Pide al servidor el resumen y actualiza la campana y el menú lateral
    function actualizar() {
        $.ajax({ url: URL + '?op=resumen', dataType: 'json', global: false })
            .done(function (d) {
                var puntos = circulos(d.conteo);
                $('#puntosCampana').html(puntos);
                $('#puntosMenu').html(puntos);

                //Si el total subió, la campana suena una vez
                var campana = $('#hanaCampana');
                if (ultimoTotal >= 0 && d.total > ultimoTotal) {
                    campana.removeClass('tiene-nuevas');
                    void campana[0].offsetWidth; //reinicia la animación
                }
                campana.toggleClass('tiene-nuevas', d.total > 0);
                ultimoTotal = d.total;

                pintarLista(d.items || [], d.total);
            })
            .fail(function (xhr) {
                //401 = la sesión venció. Se deja de preguntar hasta que vuelva a entrar
                if (xhr.status === 401) { detener(); }
            });
    }

    function iniciar() {
        detener();
        actualizar();
        temporizador = setInterval(actualizar, CADA);
    }
    function detener() {
        if (temporizador) { clearInterval(temporizador); temporizador = null; }
    }

    //Abre el aviso: primero lo marca como leído. Las RQ van a su pantalla
    function abrir(id, tipo) {
        tipo = (tipo === 'rq') ? 'rq' : 'novedad';
        $.ajax({ url: URL + '?op=leer', type: 'POST', data: { id: id, tipo: tipo },
                 dataType: 'json', global: false })
            .always(function () {
                if (tipo === 'rq') {
                    if (typeof window.verRQ === 'function' && location.pathname.indexOf('RQVista') !== -1) {
                        window.verRQ(id);
                        actualizar();
                    } else {
                        location.href = 'RQVista.php?abrir=' + encodeURIComponent(id);
                    }
                    return;
                }
                //Si ya estamos en Novedades se abre ahí mismo, sin recargar
                if (typeof window.mostrar === 'function' && $('#tbllistado').length &&
                    location.pathname.indexOf('novedadesVista') !== -1) {
                    window.mostrar(id);
                    actualizar();
                } else {
                    location.href = 'novedadesVista.php?abrir=' + encodeURIComponent(id);
                }
            });
    }

    $(function () {
        if (!$('#hanaCampana').length) { return; } //pantalla sin campana (login)

        iniciar();

        //Clic en una notificación
        $(document).on('click', '.hana-campana-item', function (e) {
            e.preventDefault();
            abrir($(this).data('id'), $(this).data('tipo'));
        });

        //Marcar todas como leídas
        $(document).on('click', '#campanaTodas', function (e) {
            e.preventDefault();
            e.stopPropagation(); //que el menú no se cierre
            $.ajax({ url: URL + '?op=leerTodas', type: 'POST', dataType: 'json', global: false })
                .always(actualizar);
        });

        //Con la pestaña oculta no tiene sentido preguntar cada minuto.
        //Al volver, se actualiza de una vez
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { detener(); } else { iniciar(); }
        });
    });

    //Queda disponible para que otras pantallas pidan refrescar la campana
    window.hanaCampanaActualizar = actualizar;
    window.hanaCampanaAbrir = abrir;

})(window.jQuery);
