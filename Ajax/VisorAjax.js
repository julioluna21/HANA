//Visor de imágenes y PDF de HANA (vista previa dentro de la misma página)
//Las fotos (de las RQ, de las novedades, los adjuntos, las firmas) y los PDF (soportes, adjuntos,
//PDF de un arqueo, informe mensual) se abren encima de la página, no en una pestaña nueva.
//Los PDF los dibuja el mismo visor, hoja por hoja, con pdf.js (public/js/pdfjs): así se ven
//igual en el computador y en el celular, que no sabe mostrar un PDF dentro de la página.
//Si el servidor responde un error en vez del PDF, se muestra el mensaje.
//Con varias fotos del mismo grupo se pasa de una a otra con las flechas (en pantalla o del
//teclado). Se cierra con la X, con Esc o tocando fuera. Tocar una foto la muestra en tamaño real.
//El botón "Descargar" baja el archivo. Ctrl + clic sigue abriendo otra pestaña, si alguien la quiere.
//Lo carga footer.php en todas las pantallas. No necesita jQuery.
//
//Qué abre el visor (sin tocar cada pantalla):
//  - un enlace con data-visor="grupo" (imágenes; las del mismo grupo van juntas) o data-visor-pdf
//  - la foto grande de una RQ (.rq-visor-grande), con las miniaturas de esa RQ
//  - las firmas, las fotos de las novedades y las vistas previas de lo que se va a subir
//  - cualquier enlace que abra en otra pestaña una imagen (.jpg, .png...) o un PDF (.pdf, ...PdfVista.php)
//Desde otro script:
//  hanaVerImagen(['url1', 'url2'], 0, ['nombre1', 'nombre2'])
//  hanaVerArchivo(url, nombre, 'pdf' | 'imagen')        por ejemplo, con URL.createObjectURL(archivo)
//  hanaVerArchivoLocal(archivo)                          un File elegido que todavía no se ha subido

(function () {
    var capa = null, foto = null, marco = null, hoja = null, hojas = null, pie = null, cuenta = null, btnAnt = null, btnSig = null, bajar = null, aviso = null;
    var lista = [], nombres = [], pos = 0, foco = null, modo = 'imagen', blobs = [];
    var turnoPdf = 0;                                  //cambia con cada PDF que se abre: así un dibujo viejo no pisa al nuevo
    var RUTA_PDFJS = '../public/js/pdfjs/';            //las pantallas están en Vista/

    //Crea la capa del visor la primera vez que se usa
    function crear() {
        if (capa) { return; }
        capa = document.createElement('div');
        capa.className = 'hana-visor';
        capa.setAttribute('role', 'dialog');
        capa.setAttribute('aria-modal', 'true');
        capa.setAttribute('aria-label', 'Vista previa');
        capa.innerHTML =
            '<button type="button" class="hana-visor-cerrar" aria-label="Cerrar">&times;</button>' +
            '<button type="button" class="hana-visor-flecha hana-visor-ant" aria-label="Anterior">&#8249;</button>' +
            '<div class="hana-visor-marco"><img class="hana-visor-img" alt=""><iframe class="hana-visor-pdf" title="Vista previa del PDF"></iframe>' +
            '<div class="hana-visor-hojas" role="document" aria-label="Hojas del PDF"></div></div>' +
            '<button type="button" class="hana-visor-flecha hana-visor-sig" aria-label="Siguiente">&#8250;</button>' +
            '<div class="hana-visor-pie"><span class="hana-visor-nombre"></span> <span class="hana-visor-cuenta"></span>' +
            ' <a class="hana-visor-bajar" href="#" download><i class="fa fa-download"></i> Descargar</a>' +
            '<div class="hana-visor-aviso">¿No se ve el PDF en este celular? Usa «Descargar».</div></div>';
        document.body.appendChild(capa);
        foto = capa.querySelector('.hana-visor-img');
        hoja = capa.querySelector('.hana-visor-pdf');
        hojas = capa.querySelector('.hana-visor-hojas');
        marco = capa.querySelector('.hana-visor-marco');
        pie = capa.querySelector('.hana-visor-nombre');
        cuenta = capa.querySelector('.hana-visor-cuenta');
        btnAnt = capa.querySelector('.hana-visor-ant');
        btnSig = capa.querySelector('.hana-visor-sig');
        bajar = capa.querySelector('.hana-visor-bajar');
        aviso = capa.querySelector('.hana-visor-aviso');
        capa.querySelector('.hana-visor-cerrar').addEventListener('click', cerrar);
        btnAnt.addEventListener('click', function (e) { e.stopPropagation(); mover(-1); });
        btnSig.addEventListener('click', function (e) { e.stopPropagation(); mover(1); });
        bajar.addEventListener('click', function (e) { e.stopPropagation(); }); //descargar no cierra el visor
        //Tocar fuera de la foto cierra; tocar la foto cambia entre ajustada y tamaño real
        capa.addEventListener('click', function (e) { if (e.target === capa || e.target === marco || e.target === hojas) { cerrar(); } });
        foto.addEventListener('click', function (e) { e.stopPropagation(); capa.classList.toggle('hana-visor-real'); });
        foto.addEventListener('error', function () { if (modo === 'imagen' && foto.getAttribute('src')) { pie.textContent = 'No se pudo cargar la imagen.'; } });
    }

    //La dirección para descargar el archivo (en vez de verlo)
    function descargaDe(url) {
        url = String(url || '');
        if (/^blob:/.test(url)) { return url; }
        if (/[?&]ver=1(&|$)/.test(url)) { return url.replace(/([?&])ver=1(&|$)/, function (m, a, b) { return b ? a : ''; }); } //los PDF del sistema: sin ver=1 se descargan
        if (/AdjuntoControl\.php\?op=ver/.test(url)) { return url + '&descargar=1'; }
        return url;
    }

    //Carga pdf.js la primera vez que se necesita (pesa: no se baja en todas las pantallas)
    var pdfjsPendientes = null;
    function conPdfJs(listo) {
        if (window.pdfjsLib) { listo(window.pdfjsLib); return; }
        if (pdfjsPendientes) { pdfjsPendientes.push(listo); return; }
        pdfjsPendientes = [listo];
        var sc = document.createElement('script');
        sc.src = RUTA_PDFJS + 'pdf.min.js';
        sc.onload = function () {
            var lib = window.pdfjsLib || null;
            if (lib) { lib.GlobalWorkerOptions.workerSrc = RUTA_PDFJS + 'pdf.worker.min.js'; }
            var esperando = pdfjsPendientes; pdfjsPendientes = null;
            esperando.forEach(function (f) { f(lib); });
        };
        sc.onerror = function () {
            var esperando = pdfjsPendientes; pdfjsPendientes = null;
            esperando.forEach(function (f) { f(null); });
        };
        document.head.appendChild(sc);
    }

    //Un aviso dentro del visor (cargando, o por qué no se pudo abrir)
    function avisoPdf(texto, esError) {
        hojas.innerHTML = '';
        var p = document.createElement('div');
        p.className = 'hana-visor-mensaje' + (esError ? ' hana-visor-mensaje-error' : '');
        p.textContent = texto;
        hojas.appendChild(p);
    }

    //Si pdf.js no carga, se usa el visor del navegador como antes
    function pdfEnMarco(url) {
        capa.classList.add('hana-visor-pdf-marco');
        hojas.innerHTML = '';
        hoja.src = url;
        aviso.style.display = '';
    }

    //Trae el PDF y lo dibuja hoja por hoja. Si el servidor responde otra cosa (un error,
    //o la pantalla de inicio de sesión), se muestra el mensaje en vez de una pantalla vacía
    function mostrarPdf(url) {
        var turno = ++turnoPdf;
        capa.classList.remove('hana-visor-pdf-marco');
        hoja.removeAttribute('src');
        avisoPdf('Cargando el PDF...', false);
        conPdfJs(function (lib) {
            if (turno !== turnoPdf) { return; }
            if (!lib || !window.fetch) { pdfEnMarco(url); return; }
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) {
                    var tipo = (r.headers.get('Content-Type') || '').toLowerCase();
                    if (r.ok && (tipo.indexOf('pdf') !== -1 || /^blob:/.test(url))) { return r.arrayBuffer(); }
                    return r.text().then(function (t) {
                        var sesion = /LoginUsuarios|frmAcceso/.test(t) || /login\.php/.test(r.url || '');
                        var limpio = String(t).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 300);
                        throw new Error(sesion ? 'Tu sesión terminó. Vuelve a iniciar sesión para ver el PDF.'
                                               : (limpio || 'El servidor no entregó el PDF (código ' + r.status + ').'));
                    });
                })
                .then(function (datos) { return lib.getDocument({ data: datos }).promise; })
                .then(function (doc) {
                    if (turno !== turnoPdf) { return; }
                    hojas.innerHTML = '';
                    var ancho = Math.min(hojas.clientWidth - 16, 1100);
                    var maxHojas = Math.min(doc.numPages, 60);
                    var dibujar = function (n) {
                        if (n > maxHojas || turno !== turnoPdf) { return; }
                        doc.getPage(n).then(function (pag) {
                            if (turno !== turnoPdf) { return; }
                            var base = pag.getViewport({ scale: 1 });
                            var escala = Math.max(0.5, ancho / base.width);
                            var nitidez = Math.min(window.devicePixelRatio || 1, 2);
                            var vista = pag.getViewport({ scale: escala * nitidez });
                            var lienzo = document.createElement('canvas');
                            lienzo.className = 'hana-visor-hoja';
                            lienzo.width = Math.floor(vista.width); lienzo.height = Math.floor(vista.height);
                            lienzo.style.width = Math.floor(vista.width / nitidez) + 'px';
                            lienzo.setAttribute('aria-label', 'Hoja ' + n + ' de ' + doc.numPages);
                            hojas.appendChild(lienzo);
                            pag.render({ canvasContext: lienzo.getContext('2d'), viewport: vista }).promise.then(function () { dibujar(n + 1); });
                        });
                    };
                    dibujar(1);
                })
                .catch(function (e) {
                    if (turno !== turnoPdf) { return; }
                    avisoPdf('No se pudo abrir el PDF. ' + (e && e.message ? e.message : ''), true);
                });
        });
    }

    //Muestra la imagen o el PDF que toca, con su nombre y el contador
    function pintar() {
        capa.classList.remove('hana-visor-real');
        capa.classList.toggle('hana-visor-modo-pdf', modo === 'pdf');
        if (modo === 'pdf') {
            foto.removeAttribute('src');
            mostrarPdf(lista[pos]);
        } else {
            turnoPdf++;
            hoja.removeAttribute('src');
            hojas.innerHTML = '';
            foto.src = lista[pos];
            foto.alt = nombres[pos] || 'Imagen';
        }
        pie.textContent = nombres[pos] || '';
        var varias = modo === 'imagen' && lista.length > 1;
        cuenta.textContent = varias ? (pos + 1) + ' de ' + lista.length : '';
        btnAnt.style.display = varias ? '' : 'none';
        btnSig.style.display = varias ? '' : 'none';
        bajar.href = descargaDe(lista[pos]);
        bajar.setAttribute('download', nombres[pos] || '');
        aviso.style.display = 'none';
    }

    //Pasa a la imagen anterior o siguiente (da la vuelta al llegar al final)
    function mover(paso) {
        if (lista.length < 2 || modo !== 'imagen') { return; }
        pos = (pos + paso + lista.length) % lista.length;
        pintar();
    }

    //Abre el visor con una lista de imágenes, o con un PDF
    function abrir(urls, indice, textos, tipo) {
        if (!urls || !urls.length) { return; }
        crear();
        modo = tipo === 'pdf' ? 'pdf' : 'imagen';
        lista = urls; nombres = textos || []; pos = Math.max(0, Math.min(urls.length - 1, indice || 0));
        foco = document.activeElement;
        pintar();
        capa.classList.add('abierto');
        document.documentElement.classList.add('hana-visor-activo'); //la página de atrás no se mueve
        capa.querySelector('.hana-visor-cerrar').focus();
    }

    //Cierra el visor
    function cerrar() {
        if (!capa || !capa.classList.contains('abierto')) { return; }
        capa.classList.remove('abierto');
        document.documentElement.classList.remove('hana-visor-activo');
        foto.removeAttribute('src');
        hoja.removeAttribute('src');
        turnoPdf++;
        if (hojas) { hojas.innerHTML = ''; }
        blobs.forEach(function (u) { try { URL.revokeObjectURL(u); } catch (e) {} }); //las vistas previas de archivos sin subir
        blobs = [];
        if (foco && foco.focus) { try { foco.focus(); } catch (e) {} }
    }

    document.addEventListener('keydown', function (e) {
        if (!capa || !capa.classList.contains('abierto')) { return; }
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cerrar(); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); mover(-1); }
        else if (e.key === 'ArrowRight') { e.preventDefault(); mover(1); }
    }, true);

    //¿Esta dirección es de una imagen? ¿de un PDF?
    function esImagen(url) { return /\.(jpe?g|png|gif|webp)(\?|#|$)/i.test(url || ''); }
    //¿La dirección es de un PDF? (también los PDF que genera HANA)
    function esPdf(url) { return /\.pdf(\?|#|$)/i.test(url || '') || /PdfVista\.php\?/i.test(url || ''); }
    //El nombre que se muestra para un archivo: data-nombre, title, alt o el texto del enlace
    function nombreDe(el) {
        var img = el.tagName === 'IMG' ? el : el.querySelector('img');
        return (el.getAttribute('data-nombre') || el.getAttribute('title') || (img && img.getAttribute('alt')) || el.textContent || '').trim();
    }

    //Un clic en algo que es una imagen o un PDF: se abre aquí y no en otra pestaña
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) { return; }
        var el = e.target.closest ? e.target.closest('a[data-visor], a[data-visor-pdf], .rq-visor-grande, .rd-firma, .firma-guardada img, .modal img.img-rounded, .hana-foto-previa img, .rq-previa img, a[target="_blank"]') : null;
        if (!el || (capa && capa.contains(el))) { return; }

        var urls = [], textos = [], i = 0, tipo = 'imagen';
        if (el.classList.contains('rq-visor-grande')) {
            //La foto grande de una RQ: el grupo son sus miniaturas
            var minis = el.closest('.rq-visor') ? el.closest('.rq-visor').querySelectorAll('.rq-mini[data-url]') : [];
            var actual = el.getAttribute('href');
            if (minis.length) {
                Array.prototype.forEach.call(minis, function (m, k) { urls.push(m.getAttribute('data-url')); textos.push(m.getAttribute('title') || ''); if (m.getAttribute('data-url') === actual) { i = k; } });
            } else { urls = [actual]; textos = [nombreDe(el)]; }
        } else if (el.hasAttribute('data-visor-pdf')) {
            urls = [el.getAttribute('href')]; textos = [nombreDe(el)]; tipo = 'pdf';
        } else if (el.hasAttribute('data-visor')) {
            var grupo = el.getAttribute('data-visor');
            var todos = grupo ? document.querySelectorAll('a[data-visor="' + grupo.replace(/"/g, '') + '"]') : [el];
            var vistos = {};
            Array.prototype.forEach.call(todos, function (a) {
                var u = a.getAttribute('href');
                if (vistos[u] === undefined) { vistos[u] = urls.length; urls.push(u); textos.push(nombreDe(a)); }
            });
            i = vistos[el.getAttribute('href')] || 0;
        } else if (el.tagName === 'IMG') {
            if (!el.getAttribute('src')) { return; }
            urls = [el.getAttribute('src')]; textos = [el.getAttribute('alt') || ''];
        } else {
            //Un enlace a otra pestaña: solo si es una imagen o un PDF (lo demás sigue igual)
            var href = el.getAttribute('href') || '';
            if (esImagen(href)) { tipo = 'imagen'; }
            else if (esPdf(href)) { tipo = 'pdf'; }
            else { return; }
            urls = [el.href || href]; textos = [nombreDe(el)];
        }
        e.preventDefault();
        abrir(urls, i, textos, tipo);
    }, false);

    //Lo que pueden usar las demás pantallas: ver imágenes o ver un archivo
    window.hanaVerImagen = function (urls, indice, textos) { abrir(urls, indice, textos, 'imagen'); };
    window.hanaVerArchivo = function (url, nombre, tipo) { abrir([url], 0, [nombre || ''], tipo === 'pdf' ? 'pdf' : 'imagen'); };
    //Un archivo elegido que todavía no se ha subido: imagen o PDF se ven aquí; otro tipo no se puede previsualizar
    window.hanaVerArchivoLocal = function (archivo) {
        if (!archivo) { return false; }
        var pdf = archivo.type === 'application/pdf' || /\.pdf$/i.test(archivo.name || '');
        var img = /^image\//.test(archivo.type || '');
        if (!pdf && !img) { return false; }
        var url = URL.createObjectURL(archivo); blobs.push(url);
        abrir([url], 0, [archivo.name || ''], pdf ? 'pdf' : 'imagen');
        return true;
    };
    window.hanaCerrarImagen = cerrar;
})();
