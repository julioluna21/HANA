//Configuracion comun de todas las pantallas del sistema
//Necesita jQuery y DataTables, por eso se carga en footer.php despues de ellas

(function ($) {
    if (!$) { return; } //sin jQuery no hay nada que configurar

    //Barra delgada arriba de la pantalla mientras haya peticiones al servidor en curso
    if (window.NProgress) {
        NProgress.configure({ showSpinner: false }); //solo la barra, sin el circulo de la esquina
        $(document).ajaxStart(function () { NProgress.start(); });
        $(document).ajaxStop(function () { NProgress.done(); });
    }

    //Tablas: se configuran aqui una sola vez y aplica a todas las pantallas
    if ($.fn.dataTable) {
        $.extend(true, $.fn.dataTable.defaults, {
            processing: true,       //muestra "Cargando..." mientras llegan los datos
            autoWidth: false,       //deja que la tabla se ajuste al ancho de la pantalla
            //Textos de la tabla en espanol
            language: {
                sProcessing: '<i class="fa fa-spinner fa-spin"></i> Cargando...',
                sLengthMenu: 'Mostrar _MENU_ registros',
                sZeroRecords: 'No se encontraron resultados',
                sEmptyTable: 'No hay registros para mostrar',
                sInfo: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
                sInfoEmpty: 'Mostrando 0 a 0 de 0 registros',
                sInfoFiltered: '(filtrado de _MAX_ registros)',
                sInfoPostFix: '',
                sSearch: 'Buscar:',
                sLoadingRecords: 'Cargando...',
                sThousands: '.',
                sDecimal: ',',
                oPaginate: {
                    sFirst: 'Primero',
                    sLast: '\u00daltimo',
                    sNext: 'Siguiente',
                    sPrevious: 'Anterior'
                },
                oAria: {
                    sSortAscending: ': ordenar de menor a mayor',
                    sSortDescending: ': ordenar de mayor a menor'
                }
            }
        });

        //En celular las columnas que no caben se esconden y se ven tocando el "+" de la fila
        if ($.fn.dataTable.Responsive) {
            $.extend(true, $.fn.dataTable.defaults, {
                responsive: true,
                //La primera y la ultima columna (nombre y botones) nunca se esconden
                columnDefs: [
                    { responsivePriority: 1, targets: 0 },
                    { responsivePriority: 2, targets: -1 }
                ]
            });
        }
    }

})(window.jQuery);
