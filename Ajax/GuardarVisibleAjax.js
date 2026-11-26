/*
  HANA - El botón de guardar siempre a la vista
  ---------------------------------------------------------------------------
  En los formularios largos, el botón de Guardar queda arriba o abajo y al
  hacer scroll se pierde. Este archivo (va en footer.php, para todas las
  pantallas) muestra una copia pequeña del botón abajo a la derecha mientras
  el formulario está en pantalla y su botón no se ve. Al tocarla, hace lo mismo
  que el botón original (lo pulsa), así que las validaciones y lo que hace cada
  pantalla al guardar siguen igual.

  Qué cuenta como botón de guardar: los que tienen el ícono de guardar
  (fa-save), o los de enviar formulario que dicen «Guardar» o «Registrar».
  No se toman los de las ventanas emergentes (modal), los de dentro de una
  tabla (los de cada fila) ni los que tengan data-sin-flotante. Si la
  pantalla ya trae su propia barra (el Reporte a la Gerencia, #gerFlotante),
  no se pone otra.
*/
(function () {
    if (window.hanaGuardarVisible) { return; }
    window.hanaGuardarVisible = true;

    var barra = null, boton = null, original = null;

    function esGuardar(elemento) {
        if (elemento.closest('.modal, table, #gerFlotante, .hana-guardar-flota, [data-sin-flotante]')) { return false; }
        if (elemento.querySelector('.fa-save')) { return true; }
        var texto = (elemento.textContent || elemento.value || '').trim().toLowerCase();
        return elemento.type === 'submit' && /^(guardar|registrar)/.test(texto);
    }

    function seVe(elemento) {
        return !!(elemento.offsetWidth || elemento.offsetHeight || elemento.getClientRects().length) &&
               window.getComputedStyle(elemento).visibility !== 'hidden';
    }

    //El botón que hace falta mostrar: el del formulario que más ocupa la pantalla, si ninguno de sus
    //botones de guardar está a la vista
    function elegido() {
        if (document.getElementById('gerFlotante')) { return null; }
        var alto = window.innerHeight, grupos = [];
        Array.prototype.forEach.call(document.querySelectorAll('button, input[type="submit"]'), function (elemento) {
            if (!esGuardar(elemento) || !seVe(elemento)) { return; }
            var contenedor = elemento.closest('form') || elemento.closest('.x_panel') || elemento.parentElement;
            var grupo = grupos.filter(function (g) { return g.contenedor === contenedor; })[0];
            if (!grupo) { grupo = { contenedor: contenedor, botones: [] }; grupos.push(grupo); }
            grupo.botones.push(elemento);
        });
        var mejor = null, mejorArea = 0;
        grupos.forEach(function (grupo) {
            var aLaVista = grupo.botones.some(function (b) { var r = b.getBoundingClientRect(); return r.bottom > 0 && r.top < alto; });
            if (aLaVista) { return; }
            var caja = grupo.contenedor.getBoundingClientRect();
            var area = Math.min(caja.bottom, alto) - Math.max(caja.top, 0);
            if (area > 150 && area > mejorArea) { mejor = grupo.botones[grupo.botones.length - 1]; mejorArea = area; }
        });
        return mejor;
    }

    function crearBarra() {
        var estilo = document.createElement('style');
        estilo.textContent =
            '.hana-guardar-flota{position:fixed;right:22px;bottom:18px;z-index:1035;display:none;padding:6px;border-radius:8px;' +
            'background:rgba(255,255,255,.96);box-shadow:0 4px 16px rgba(0,0,0,.18);border:1px solid #e3e6ea;}' +
            '.hana-guardar-flota.visible{display:block;}' +
            '.hana-guardar-flota .btn{margin:0;}' +
            '@media print{.hana-guardar-flota{display:none!important;}}';
        document.head.appendChild(estilo);
        barra = document.createElement('div');
        barra.className = 'hana-guardar-flota';
        barra.setAttribute('aria-hidden', 'true'); //para los lectores de pantalla ya está el botón original
        boton = document.createElement('button');
        boton.type = 'button';
        boton.tabIndex = -1;
        boton.addEventListener('click', function () { if (original && !original.disabled) { original.click(); } });
        barra.appendChild(boton);
        document.body.appendChild(barra);
    }

    function revisar() {
        var b = elegido();
        if (!b) { if (barra) { barra.classList.remove('visible'); } original = null; return; }
        if (!barra) { crearBarra(); }
        original = b;
        //La copia: el mismo texto e ícono, en pequeño; con el color del original
        boton.className = 'btn btn-sm ' + ((b.className.match(/btn-(success|primary|danger|warning|info|default)/) || ['btn-success'])[0]);
        boton.innerHTML = b.tagName === 'INPUT' ? '' : b.innerHTML;
        if (b.tagName === 'INPUT') { boton.textContent = b.value; }
        boton.disabled = !!b.disabled;
        barra.classList.add('visible');
    }

    //En el computador, el título de la pantalla con sus botones principales (Nueva vacante, Excel...)
    //se queda pegado arriba al bajar (estilo .rd-titulo-fijo en public/css/comun.css)
    function fijarTitulos() {
        Array.prototype.forEach.call(document.querySelectorAll('.x_panel > .x_title'), function (titulo) {
            if (titulo.querySelector('.rd-botones-titulo, .rd-btn-flotante')) { titulo.classList.add('rd-titulo-fijo'); }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fijarTitulos); } else { fijarTitulos(); }

    var pendiente = false;
    function alMover() {
        if (pendiente) { return; }
        pendiente = true;
        window.requestAnimationFrame(function () { pendiente = false; revisar(); });
    }
    window.addEventListener('scroll', alMover, { passive: true });
    window.addEventListener('resize', alMover);
    //Las pantallas abren y cierran formularios sin hacer scroll: se revisa cada tanto
    setInterval(revisar, 1000);
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', revisar); } else { revisar(); }
})();
