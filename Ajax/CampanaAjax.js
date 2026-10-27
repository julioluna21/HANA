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
            var cantidad = conteo[orden[i]] || 0;
            if (cantidad > 0) {
                //Más de 99 no cabe en el círculo: se muestra "99+"
                var texto = cantidad > 99 ? '99+' : cantidad;
                html += '<span class="hana-punto hana-punto-' + orden[i] + '" ' +
                        'title="' + cantidad + ' de prioridad ' + orden[i] + '">' + texto + '</span>';
            }
        }
        return html;
    }

    //Escapa el texto antes de pintarlo: los títulos los escriben los usuarios
    function seguro(texto) { return $('<div>').text(texto == null ? '' : String(texto)).html(); }

    //"hace 5 min", "hace 2 h", "hace 3 días"
    function haceCuanto(fecha) {
        var momento = new Date(String(fecha).replace(' ', 'T'));
        if (isNaN(momento.getTime())) { return ''; }
        var segundos = Math.floor((new Date() - momento) / 1000);
        if (segundos < 60) { return 'hace un momento'; }
        if (segundos < 3600) { return 'hace ' + Math.floor(segundos / 60) + ' min'; }
        if (segundos < 86400) { return 'hace ' + Math.floor(segundos / 3600) + ' h'; }
        var dias = Math.floor(segundos / 86400);
        return 'hace ' + dias + (dias === 1 ? ' día' : ' días');
    }

    //Pinta la lista del menú desplegable
    function pintarLista(items, total) {
        var lista = $('#campanaLista');
        if (!lista.length) { return; }

        if (!items.length) {
            lista.html('<li class="hana-campana-vacia"><i class="fa fa-check-circle"></i>' +
                       'No tienes avisos nuevos.</li>');
            $('#campanaTodas').hide();
            return;
        }

        var html = '';
        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            html += '<li><a class="hana-campana-item" data-id="' + parseInt(item.id, 10) + '" data-tipo="' + tipoSeguro(item.tipo) + '">' +
                    '<span class="marca ' + seguro(item.prioridad) + '"></span>' +
                    '<span class="texto">' +
                    '<div class="titulo">' + seguro(item.titulo) + '</div>' +
                    '<div class="detalle">' + seguro(item.estacion) + ' · ' +
                    seguro(item.relevancia) + ' · ' + haceCuanto(item.fecha) + '</div>' +
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
            .done(function (respuesta) {
                var puntos = circulos(respuesta.conteo);
                $('#puntosCampana').html(puntos);
                $('#puntosMenu').html(puntos);

                //Si el total subió, la campana suena una vez
                var campana = $('#hanaCampana');
                if (ultimoTotal >= 0 && respuesta.total > ultimoTotal) {
                    campana.removeClass('tiene-nuevas');
                    void campana[0].offsetWidth; //reinicia la animación
                }
                campana.toggleClass('tiene-nuevas', respuesta.total > 0);
                ultimoTotal = respuesta.total;

                pintarLista(respuesta.items || [], respuesta.total);
            })
            .fail(function (xhr) {
                //401 = la sesión venció. Se deja de preguntar hasta que vuelva a entrar
                if (xhr.status === 401) { detener(); }
            });
    }

    //Empieza a revisar los avisos cada cierto tiempo
    function iniciar() {
        detener();
        actualizar();
        temporizador = setInterval(actualizar, CADA);
    }
    //Deja de revisar (por ejemplo, cuando la pestaña no está a la vista)
    function detener() {
        if (temporizador) { clearInterval(temporizador); temporizador = null; }
    }

    //Los tipos de aviso que hay: novedad, rq y promesa
    function tipoSeguro(tipo) { return (tipo === 'rq' || tipo === 'promesa') ? tipo : 'novedad'; }

    //El aviso de la promesa es un peaje en un día: id = días desde el 1/1/2000 × 10000 + peaje
    //(ver Modelo/CampanaModelo.php). Lleva a la promesa de valor de ese peaje y ese mes
    function irAPromesa(id) {
        var numero = parseInt(id, 10), centro = numero % 10000;
        var fecha = new Date(Date.UTC(2000, 0, 1) + Math.floor(numero / 10000) * 86400000);
        location.href = 'PromesaValorVista.php?centro=' + centro + '&anio=' + fecha.getUTCFullYear() + '&mes=' + (fecha.getUTCMonth() + 1);
    }

    //Abre el aviso: primero lo marca como leído. Las RQ y la promesa van a su pantalla
    function abrir(id, tipo) {
        tipo = tipoSeguro(tipo);
        $.ajax({ url: URL + '?op=leer', type: 'POST', data: { id: id, tipo: tipo },
                 dataType: 'json', global: false })
            .always(function () {
                if (tipo === 'promesa') { irAPromesa(id); return; }
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
        $(document).on('click', '.hana-campana-item', function (evento) {
            evento.preventDefault();
            abrir($(this).data('id'), $(this).data('tipo'));
        });

        //Marcar todas como leídas
        $(document).on('click', '#campanaTodas', function (evento) {
            evento.preventDefault();
            evento.stopPropagation(); //que el menú no se cierre
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
