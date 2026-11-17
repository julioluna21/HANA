//Ordenar cualquier tabla tocando el encabezado de una columna
//---------------------------------------------------------------------------
//Sirve en todas las pantallas (se carga en Vista/footer.php). Un clic en el
//encabezado ordena de menor a mayor, otro clic de mayor a menor. Reconoce:
//  - fechas 16/11/2026 (y con hora 16/11/2026 08:30) y 2026-11-16
//  - números con puntos de miles y coma decimal (1.500.000, 12,5, 98 %)
//  - texto, en orden alfabético del español (sin importar tildes ni mayúsculas)
//Si una celda tiene data-orden="...", se ordena por ese valor en vez del texto.
//
//No se mete con:
//  - las tablas de DataTables (ya traen su propio orden),
//  - las que tienen la clase «hana-sin-orden» o celdas combinadas (rowspan),
//  - las columnas sin título o con la clase «hana-sin-orden» (por ejemplo, la de botones),
//  - las tablas cuyas filas no tienen todas el mismo número de celdas (filas de detalle,
//    mensajes de «no hay datos»): ahí ordenar desarmaría la tabla.
//Como lee la tabla en el momento del clic, funciona también con las tablas que se llenan con Ajax.
(function ($) {
    'use strict';

    //De texto a algo comparable: [tipo, valor]. tipo 0 = número/fecha, 1 = texto
    function valorCelda(celda) {
        var $celda = $(celda);
        var crudo = $celda.attr('data-orden');
        var texto = $.trim(crudo !== undefined ? crudo : $celda.text());
        if (texto === '' || texto === '—' || texto === '-') { return [2, '']; } //vacíos al final
        var m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2}))?/.exec(texto); //16/11/2026 [08:30]
        if (m) { return [0, Number(m[3] + ('0' + m[2]).slice(-2) + ('0' + m[1]).slice(-2) + ('0' + (m[4] || 0)).slice(-2) + (m[5] || '00'))]; }
        m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(texto); //2026-11-16 [08:30]
        if (m) { return [0, Number(m[1] + m[2] + m[3] + (m[4] || '00') + (m[5] || '00'))]; }
        var numero = texto.replace(/[$%\s]/g, '').replace(/h$/i, '');
        if (/^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(numero)) { numero = numero.replace(/\./g, '').replace(',', '.'); } //1.500.000,5
        else if (/^-?\d+,\d+$/.test(numero)) { numero = numero.replace(',', '.'); }                              //12,5
        if (/^-?\d+(\.\d+)?$/.test(numero)) { return [0, parseFloat(numero)]; }
        m = /^(-?\d+(?:[.,]\d+)?)\s/.exec(texto);                                                                   //"5 a hoy", "3 días"
        if (m) { return [0, parseFloat(m[1].replace(',', '.'))]; }
        return [1, texto.toLocaleLowerCase('es')];
    }

    function comparar(a, b) {
        if (a[0] !== b[0]) { return a[0] - b[0]; }
        if (a[0] === 1) { return a[1].localeCompare(b[1], 'es', { sensitivity: 'base', numeric: true }); }
        return a[1] < b[1] ? -1 : (a[1] > b[1] ? 1 : 0);
    }

    //¿Esta tabla se puede ordenar?
    function ordenable($tabla) {
        if ($tabla.hasClass('dataTable') || $tabla.hasClass('hana-sin-orden') || $tabla.closest('.dataTables_wrapper').length) { return false; }
        if ($tabla.find('> thead > tr').length !== 1) { return false; } //encabezados de varias filas
        return $tabla.find('> tbody > tr > [rowspan], > thead > tr > [colspan]').length === 0;
    }

    $(document).on('click', 'table > thead > tr > th', function () {
        var $th = $(this), $tabla = $th.closest('table');
        if (!ordenable($tabla) || $th.hasClass('hana-sin-orden') || $.trim($th.text()) === '') { return; }
        var columna = $th.index(), columnas = $th.parent().children().length;
        var $cuerpo = $tabla.children('tbody').first();
        var filas = $cuerpo.children('tr').get();
        if (filas.length < 2) { return; }
        //Todas las filas deben tener una celda por columna (si no, son filas de detalle o mensajes)
        for (var i = 0; i < filas.length; i++) {
            if (filas[i].cells.length !== columnas) { return; }
            for (var j = 0; j < filas[i].cells.length; j++) { if (filas[i].cells[j].colSpan > 1) { return; } }
        }
        var ascendente = !$th.hasClass('hana-orden-asc');
        $th.siblings().removeClass('hana-orden-asc hana-orden-desc').removeAttr('aria-sort');
        $th.toggleClass('hana-orden-asc', ascendente).toggleClass('hana-orden-desc', !ascendente).attr('aria-sort', ascendente ? 'ascending' : 'descending');
        var conValor = filas.map(function (fila, posicion) { return { fila: fila, valor: valorCelda(fila.cells[columna]), posicion: posicion }; });
        conValor.sort(function (a, b) {
            var resultado = comparar(a.valor, b.valor);
            if (a.valor[0] === 2 || b.valor[0] === 2) { return resultado || a.posicion - b.posicion; } //los vacíos siempre al final
            return (ascendente ? resultado : -resultado) || a.posicion - b.posicion;                  //orden estable
        });
        var fragmento = document.createDocumentFragment();
        conValor.forEach(function (item) { fragmento.appendChild(item.fila); });
        $cuerpo[0].appendChild(fragmento);
    });

    //Marca los encabezados que se pueden tocar (la manito y la flecha los pone public/css/comun.css)
    $(document).on('mouseenter', 'table > thead > tr > th', function () {
        var $th = $(this), $tabla = $th.closest('table');
        $th.toggleClass('hana-ordenable-th', ordenable($tabla) && !$th.hasClass('hana-sin-orden') && $.trim($th.text()) !== '');
        if ($th.hasClass('hana-ordenable-th') && !$th.attr('title')) { $th.attr('title', 'Toca para ordenar'); }
    });
})(jQuery);
