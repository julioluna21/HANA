//Reporte diario — Informe de gestión (mensual o por rango de fechas)
//La pantalla solo elige el periodo: el PDF lo arma el servidor (Vista/InformeMensualPdfVista.php)

var hoyInf = ''; //"2026-10-06": lo manda el servidor, en la hora de Colombia

$(function () {
    var caja = $('#infGenerar');
    if (!caja.length) { return; }                 //sin proyectos no hay nada que generar
    hoyInf = String(caja.data('hoy'));
    $('#iDesde, #iHasta').attr('max', hoyInf);    //no se piden días que no han llegado
    $('#iPeriodo').on('change', aplicarPeriodo);
    //Si cambian una fecha a mano, el periodo pasa a "Otras fechas"
    $('#iDesde, #iHasta').on('change', function () { $('#iPeriodo').val('otro'); enlacesInforme(); });
    aplicarPeriodo();                             //arranca en "Este mes"
});

//Fecha -> "AAAA-MM-DD"
function isoInf(d) { return d.getFullYear() + '-' + rdDos(d.getMonth() + 1) + '-' + rdDos(d.getDate()); }

//Cada opción del selector llena las dos fechas. "Otras fechas" deja las que estén
function aplicarPeriodo() {
    var h = rdAFecha(hoyInf), a = h.getFullYear(), m = h.getMonth(), d = h.getDate();
    var desde, hasta;
    switch ($('#iPeriodo').val()) {
        case 'mes':              desde = new Date(a, m, 1);     hasta = new Date(a, m + 1, 0); break;
        case 'mesAnterior':      desde = new Date(a, m - 1, 1); hasta = new Date(a, m, 0); break;
        case 'quincena1':        desde = new Date(a, m, 1);     hasta = new Date(a, m, 15); break;
        case 'quincena2':        desde = new Date(a, m, 16);    hasta = new Date(a, m + 1, 0); break;
        case 'quincenaAnterior': //la que acaba de terminar: del 1 al 15 de este mes, o la segunda del mes pasado
            if (d > 15) { desde = new Date(a, m, 1); hasta = new Date(a, m, 15); }
            else        { desde = new Date(a, m - 1, 16); hasta = new Date(a, m, 0); }
            break;
        case 'meses2':           desde = new Date(a, m - 1, 1); hasta = new Date(a, m + 1, 0); break;
        case 'meses3':           desde = new Date(a, m - 2, 1); hasta = new Date(a, m + 1, 0); break;
        case 'meses6':           desde = new Date(a, m - 5, 1); hasta = new Date(a, m + 1, 0); break;
        case 'anio':             desde = new Date(a, 0, 1);     hasta = new Date(a, m + 1, 0); break;
        default:                 enlacesInforme(); return;      //"Otras fechas": no se tocan
    }
    //Los meses van completos para que el informe se llame "Octubre de 2026"; en el campo se muestra hasta hoy
    $('#iDesde').val(isoInf(desde)).data('real', isoInf(desde));
    $('#iHasta').val(isoInf(hasta) > hoyInf ? hoyInf : isoInf(hasta)).data('real', isoInf(hasta));
    enlacesInforme();
}

//Arma los dos enlaces con las fechas elegidas y explica qué va a salir
function enlacesInforme() {
    var atajo = $('#iPeriodo').val() !== 'otro';
    var desde = atajo ? $('#iDesde').data('real') : $('#iDesde').val();
    var hasta = atajo ? $('#iHasta').data('real') : $('#iHasta').val();
    var error = '';
    if (!desde || !hasta) { error = 'Elige las dos fechas.'; }
    else if (hasta < desde) { error = 'La fecha final no puede ser anterior a la inicial.'; }
    else if (desde > hoyInf) { error = 'Ese periodo todavía no empieza.'; }
    else if ((rdAFecha(hasta) - rdAFecha(desde)) / 86400000 > 366) { error = 'El periodo no puede pasar de 12 meses.'; }

    var url = 'InformeMensualPdfVista.php?desde=' + desde + '&hasta=' + hasta;
    $('#btnVerInforme').attr('href', error ? '#' : url + '&ver=1');
    $('#btnBajarInforme').attr('href', error ? '#' : url);
    $('#btnVerInforme, #btnBajarInforme').toggleClass('disabled', !!error).attr('aria-disabled', error ? 'true' : 'false');
    if (error) { $('#infNota').text(error); return; }
    var corte = hasta > hoyInf ? hoyInf : hasta;
    var dias = Math.round((rdAFecha(corte) - rdAFecha(desde)) / 86400000) + 1;
    $('#infNota').text('El informe trae lo registrado del ' + rdFecha(desde) + ' al ' + rdFecha(corte) + ' (' + dias + (dias === 1 ? ' día' : ' días') + ')' +
                       (hasta > hoyInf ? '. El periodo va en curso: llega hasta hoy.' : '.'));
}

//Un enlace desactivado no debe llevar a ningún lado
$(document).on('click', '#btnVerInforme.disabled, #btnBajarInforme.disabled', function (e) { e.preventDefault(); });
