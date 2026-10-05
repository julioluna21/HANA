//Alertas personalizadas de HANA
//Reemplaza las ventanas del navegador (las que dicen "localhost dice:") por ventanas con el estilo del sistema
//Tambien trae la confirmacion, el indicador de carga y el manejo de errores que usan todas las pantallas
//Funciones disponibles:
//  hanaAlerta(mensaje, alCerrar, opciones)      aviso con boton Aceptar (alert() tambien pasa por aqui)
//  hanaConfirmar(mensaje, alAceptar, opciones)  pregunta con Si / No
//  hanaCargando(true | false, texto)            ventana de "Cargando..." mientras llega una respuesta
//  hanaBoton(boton, true | false, texto)        pone un boton en "Guardando..." y lo bloquea
//  hanaErrorAjax(xhr, textoBase)                muestra el motivo real de un error del servidor
//  hanaTipoMensaje(mensaje)                     dice si un mensaje es exito, error, aviso o info

(function () {

    var cola = [];                                  //ventanas en espera, se muestran una tras otra
    var abierta = false;                            //true mientras hay una ventana en pantalla
    var abiertaEn = 0;                              //momento en que se abrio la ventana visible
    var actual = null;                              //ventana visible: {clase, mensaje, alAceptar, ...}
    var esperandoPagina = false;                    //evita registrar varias veces la espera de carga
    var cargandoCuenta = 0;                         //cuantas cargas hay en curso
    var CLAVE_PENDIENTES = 'hanaAlertasPendientes'; //nombre con el que se guardan en sessionStorage

    //Icono (Font Awesome 4.6.3) y titulo de cada tipo de ventana
    var TIPOS = {
        exito:     { icono: 'fa-check-circle',         titulo: 'Listo' },
        error:     { icono: 'fa-times-circle',         titulo: 'Error' },
        aviso:     { icono: 'fa-exclamation-triangle', titulo: 'Atenci\u00f3n' },
        info:      { icono: 'fa-info-circle',          titulo: 'Informaci\u00f3n' },
        confirmar: { icono: 'fa-question-circle',      titulo: 'Confirmar' }
    };

    //Pasa el texto a minusculas y le quita las tildes, para comparar sin importar como se escribio
    function normalizar(texto) {
        var t = String(texto).toLowerCase();
        if (t.normalize) { t = t.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } //quita tildes
        return t;
    }

    //Decide el color e icono leyendo el mensaje. El mensaje en si NO se modifica
    //El orden importa: primero avisos, luego errores y al final exitos
    function detectarTipo(mensaje) {
        var t = normalizar(mensaje);

        //Avisos: falta algo por hacer o el registro quedo a medias
        if (/pero no fue posible|pero no se pudo|faltan|selecciona|escribe|abre primero|ya tienes|ya esta en|no hay preguntas|orden logico|debes? seleccionar|debes? indicar|no es igual|no coinciden|solo se permiten/.test(t)) {
            return 'aviso';
        }
        //Errores: la operacion no se pudo completar
        if (/error|no se pudo|no se envio|no se recibio|no se guardo|no existe|invalid|algo salio mal|no hay conexion|no se ha proporcionado|no compatible|no permitido/.test(t)) {
            return 'error';
        }
        //Exitos: incluye "Exisitoso", que asi llegaba escrito desde algunos controladores
        if (/exito|exisitos|guardad|enviado|actualizad|activad|anulad|importad|correctamente|se cambio/.test(t)) {
            return 'exito';
        }
        return 'info'; //cualquier otro mensaje
    }

    //Crea la ventana una sola vez y la deja oculta en la pagina
    function construir() {
        if (document.getElementById('hanaAlerta')) { return; } //ya existe

        var fondo = document.createElement('div');
        fondo.id = 'hanaAlerta';
        fondo.className = 'hana-alerta-fondo';
        fondo.setAttribute('role', 'alertdialog');                   //los lectores de pantalla la anuncian
        fondo.setAttribute('aria-modal', 'true');
        fondo.setAttribute('aria-labelledby', 'hanaAlertaTitulo');
        fondo.setAttribute('aria-describedby', 'hanaAlertaMensaje');

        fondo.innerHTML =
            '<div class="hana-alerta-caja">' +
            '<div class="hana-alerta-icono"><i class="fa"></i></div>' +
            '<div class="hana-alerta-titulo" id="hanaAlertaTitulo"></div>' +
            '<div class="hana-alerta-mensaje" id="hanaAlertaMensaje"></div>' +
            '<div class="hana-alerta-botones">' +
            '<button type="button" class="hana-alerta-boton hana-alerta-cancelar">No</button>' +
            '<button type="button" class="hana-alerta-boton hana-alerta-aceptar">Aceptar</button>' +
            '</div>' +
            '</div>';

        document.body.appendChild(fondo);
        fondo.querySelector('.hana-alerta-aceptar').addEventListener('click', function () { cerrar(true); });
        fondo.querySelector('.hana-alerta-cancelar').addEventListener('click', function () { cerrar(false); });
    }

    //Muestra la siguiente ventana de la cola, si no hay otra abierta
    function mostrarSiguiente() {
        if (abierta || cola.length === 0) { return; }

        //Si la pagina aun no tiene <body>, se espera a que termine de cargar
        if (!document.body) {
            if (!esperandoPagina) {
                esperandoPagina = true;
                document.addEventListener('DOMContentLoaded', mostrarSiguiente);
            }
            return;
        }

        construir();
        actual = cola.shift();                                           //saca la primera en espera
        var esConfirmar = actual.clase === 'confirmar';
        var tipo = esConfirmar ? 'confirmar' : (actual.tipo || detectarTipo(actual.mensaje));
        var fondo = document.getElementById('hanaAlerta');
        var mensaje = document.getElementById('hanaAlertaMensaje');
        var aceptar = fondo.querySelector('.hana-alerta-aceptar');
        var cancelar = fondo.querySelector('.hana-alerta-cancelar');

        fondo.className = 'hana-alerta-fondo hana-alerta-' + tipo + ' hana-alerta-visible'; //color segun el tipo
        fondo.querySelector('.hana-alerta-icono i').className = 'fa ' + TIPOS[tipo].icono;
        document.getElementById('hanaAlertaTitulo').textContent = actual.titulo || TIPOS[tipo].titulo;

        //textContent muestra el mensaje como texto: si el servidor devuelve HTML, no se ejecuta
        mensaje.textContent = actual.mensaje;
        //Los mensajes de varias lineas (por ejemplo, la lista de campos faltantes) se alinean a la izquierda
        mensaje.className = 'hana-alerta-mensaje' + (actual.mensaje.indexOf('\n') !== -1 ? ' hana-alerta-multilinea' : '');
        //Detalle opcional con formato (por ejemplo, la RQ que se va a aprobar). Quien lo arma
        //debe escapar con rdEsc todo lo que venga de la base: aquí se pinta tal cual
        if (actual.detalleHtml) {
            var det = document.createElement('div');
            det.className = 'hana-alerta-detalle';
            det.innerHTML = actual.detalleHtml;
            mensaje.appendChild(det);
            mensaje.className += ' hana-alerta-con-detalle';
        }
        mensaje.scrollTop = 0;                                           //si el mensaje es largo, empieza desde arriba

        //Botones: la confirmacion muestra los dos, la alerta solo Aceptar
        aceptar.textContent = actual.textoAceptar || (esConfirmar ? 'S\u00ed' : 'Aceptar');
        cancelar.textContent = actual.textoCancelar || 'No';
        cancelar.style.display = esConfirmar ? '' : 'none';

        abierta = true;
        abiertaEn = new Date().getTime();
        aceptar.focus();                                                 //Enter responde de una vez
    }

    //Cierra la ventana visible, ejecuta lo que corresponda y muestra la siguiente
    function cerrar(acepto) {
        if (!abierta) { return; }
        var cerrada = actual;
        var fondo = document.getElementById('hanaAlerta');
        if (fondo) { fondo.className = fondo.className.replace(' hana-alerta-visible', ''); }
        abierta = false;
        actual = null;

        //Primero se ejecuta la accion y despues se muestra la siguiente ventana
        if (cerrada.clase === 'confirmar') {
            if (acepto && cerrada.alAceptar) { cerrada.alAceptar(); }
            if (!acepto && cerrada.alCancelar) { cerrada.alCancelar(); }
        } else if (cerrada.alCerrar) {
            cerrada.alCerrar();
        }
        mostrarSiguiente();
    }

    //Teclado mientras la ventana esta abierta. Se escucha en captura para que la tecla
    //no llegue a los formularios de atras (por ejemplo, un Enter que vuelva a guardar)
    document.addEventListener('keydown', function (e) {
        if (!abierta) { return; }
        var tecla = e.key || e.keyCode;
        var recien = new Date().getTime() - abiertaEn < 300; //evita que el mismo Enter que la abrio la cierre

        if (tecla === 'Enter' || tecla === 13) {
            e.preventDefault();
            //Enter usa el boton que tenga el foco (Si o No); si ninguno lo tiene, acepta
            var enfocado = document.activeElement;
            var esCancelar = enfocado && enfocado.className.indexOf('hana-alerta-cancelar') !== -1;
            if (!recien) { cerrar(!esCancelar); }
        } else if (tecla === 'Escape' || tecla === 'Esc' || tecla === 27) {
            e.preventDefault();
            if (!recien) { cerrar(actual.clase !== 'confirmar'); } //Escape en una pregunta equivale a No
        } else if (tecla === 'Tab' || tecla === 9) {
            e.preventDefault();
            //Tab solo alterna entre los botones visibles de la ventana
            var caja = document.getElementById('hanaAlerta');
            var si = caja.querySelector('.hana-alerta-aceptar');
            var no = caja.querySelector('.hana-alerta-cancelar');
            if (no.style.display !== 'none' && document.activeElement === si) { no.focus(); } else { si.focus(); }
        }
    }, true);

    //Aviso con boton Aceptar
    //opciones (opcional): { titulo: 'Texto', tipo: 'exito' | 'error' | 'aviso' | 'info' }
    window.hanaAlerta = function (mensaje, alCerrar, opciones) {
        opciones = opciones || {};
        cola.push({
            clase: 'alerta',
            mensaje: (mensaje === undefined || mensaje === null) ? '' : String(mensaje),
            alCerrar: typeof alCerrar === 'function' ? alCerrar : null,
            titulo: opciones.titulo || '',
            tipo: opciones.tipo || ''
        });
        mostrarSiguiente();
    };

    //Pregunta con dos botones. Reemplaza a confirm() y a bootbox.confirm
    //opciones (opcional): { aceptar: 'S\u00ed', cancelar: 'No', alCancelar: function(){}, titulo: 'Texto' }
    window.hanaConfirmar = function (mensaje, alAceptar, opciones) {
        opciones = opciones || {};
        cola.push({
            clase: 'confirmar',
            mensaje: String(mensaje),
            alAceptar: typeof alAceptar === 'function' ? alAceptar : null,
            alCancelar: typeof opciones.alCancelar === 'function' ? opciones.alCancelar : null,
            textoAceptar: opciones.aceptar || '',
            textoCancelar: opciones.cancelar || '',
            titulo: opciones.titulo || '',
            detalleHtml: opciones.detalleHtml || ''  //HTML ya escapado por quien llama
        });
        mostrarSiguiente();
    };

    //Todo alert() del sistema usa ahora la ventana personalizada
    //Se pasan también el callback y las opciones. Antes solo se pasaba el mensaje,
    //así que alert(texto, function(){...}) mostraba el aviso pero nunca ejecutaba
    //lo de después (por ejemplo, volver al login tras recuperar la contraseña).
    //El alert nativo recibe un solo argumento, así que los extra no estorban
    window.alert = function (mensaje, alCerrar, opciones) {
        window.hanaAlerta(arguments.length === 0 ? '' : mensaje, alCerrar, opciones);
    };

    //Dice de que tipo es un mensaje del servidor: 'exito', 'error', 'aviso' o 'info'
    window.hanaTipoMensaje = detectarTipo;

    //Ventana de "Cargando..." que bloquea la pantalla mientras llega una respuesta
    //Cada hanaCargando(true) necesita su hanaCargando(false)
    window.hanaCargando = function (mostrar, texto) {
        if (!document.body) { return; }
        var capa = document.getElementById('hanaCargando');
        if (!capa) {
            capa = document.createElement('div');
            capa.id = 'hanaCargando';
            capa.className = 'hana-cargando-fondo';
            capa.setAttribute('role', 'status');                         //anuncia que algo esta cargando
            capa.innerHTML = '<div class="hana-cargando-caja"><i class="fa fa-spinner fa-spin"></i> <span></span></div>';
            document.body.appendChild(capa);
        }
        cargandoCuenta = mostrar ? cargandoCuenta + 1 : Math.max(0, cargandoCuenta - 1);
        capa.querySelector('span').textContent = texto || 'Cargando...';
        capa.className = 'hana-cargando-fondo' + (cargandoCuenta > 0 ? ' hana-cargando-visible' : '');
    };

    //Pone un boton en estado de carga (bloqueado y con "Guardando...") o lo devuelve a la normalidad
    //boton puede ser el selector ('#btnGuardar'), el elemento o un objeto de jQuery
    window.hanaBoton = function (boton, cargando, texto) {
        var el = boton;
        if (typeof boton === 'string') { el = document.querySelector(boton); }
        else if (boton && boton.jquery) { el = boton[0]; }
        if (!el) { return; }

        if (cargando) {
            if (!el.hasAttribute('data-hana-original')) {
                el.setAttribute('data-hana-original', el.innerHTML);     //guarda el contenido para restaurarlo
            }
            el.disabled = true;
            el.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + (texto || 'Guardando...');
        } else {
            if (el.hasAttribute('data-hana-original')) {
                el.innerHTML = el.getAttribute('data-hana-original');
                el.removeAttribute('data-hana-original');
            }
            el.disabled = false;
        }
    };

    //Muestra el motivo de un error del servidor. Si el servidor no manda un texto util,
    //queda solo el mensaje base. Devuelve el texto mostrado
    window.hanaErrorAjax = function (xhr, textoBase) {
        var base = textoBase || 'No se pudo completar la operaci\u00f3n.';
        var detalle = '';

        if (xhr && xhr.status === 0) {
            //status 0: la peticion ni siquiera llego al servidor
            detalle = 'No hay conexi\u00f3n con el servidor. Revisa tu conexi\u00f3n a internet e intenta de nuevo.';
        } else if (xhr) {
            var texto = String(xhr.responseText || '').replace(/^\s+|\s+$/g, '');
            //Si el servidor respondió {"error": "..."} se muestra ese mensaje
            if (texto.charAt(0) === '{') {
                try { var js = JSON.parse(texto); if (js && (js.error || js.mensaje)) { texto = String(js.error || js.mensaje); } } catch (e) { /* no era JSON */ }
            }
            //Se ignoran respuestas que no sirven al usuario: vacias, solo numeros (codigo HTTP
            //que imprimen algunos controladores), objetos vacios o paginas de error de PHP
            var util = texto !== '' && !/^\d+$/.test(texto) && texto !== '{}' && texto.indexOf('<') === -1 && texto.length <= 300;
            if (util) { detalle = texto; }
            if (texto !== '') { console.error('Respuesta del servidor (' + xhr.status + '):', texto); }
        }

        var mensaje = detalle ? base + '\n\n' + detalle : base;
        window.hanaAlerta(mensaje, null, { tipo: detectarTipo(detalle) === 'aviso' ? 'aviso' : 'error' });
        return mensaje;
    };

    //El alert() nativo detiene el codigo hasta que se cierra; este no.
    //Si justo despues de una alerta el codigo cambia de pagina, el mensaje no alcanzaria a leerse.
    //Por eso, al salir de la pagina se guardan las alertas sin leer y se muestran en la siguiente
    function guardarPendientes() {
        var pendientes = [];
        var i;
        //Solo se guardan las alertas simples; las preguntas y las que tienen accion no se pueden repetir
        for (i = 0; i < cola.length; i++) {
            if (cola[i].clase === 'alerta' && !cola[i].alCerrar) { pendientes.push(cola[i].mensaje); }
        }
        //La visible solo se guarda si se abrio hace muy poco (salida automatica, no del usuario)
        if (abierta && actual && actual.clase === 'alerta' && !actual.alCerrar && new Date().getTime() - abiertaEn < 1500) {
            pendientes.unshift(actual.mensaje);
        }
        if (!pendientes.length) { return; }
        try { sessionStorage.setItem(CLAVE_PENDIENTES, JSON.stringify(pendientes)); } catch (e) {}
    }
    window.addEventListener('pagehide', guardarPendientes);     //funciona tambien en celulares
    window.addEventListener('beforeunload', guardarPendientes); //respaldo para navegadores viejos

    //Al cargar una pagina se revisa si quedaron alertas pendientes de la anterior
    function recuperarPendientes() {
        var guardadas = null;
        try {
            guardadas = sessionStorage.getItem(CLAVE_PENDIENTES);
            sessionStorage.removeItem(CLAVE_PENDIENTES); //se borran para no repetirlas
        } catch (e) {}
        if (!guardadas) { return; }

        try {
            var lista = JSON.parse(guardadas);
            for (var i = 0; i < lista.length; i++) { window.hanaAlerta(lista[i]); }
        } catch (e) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', recuperarPendientes);
    } else {
        recuperarPendientes();
    }

})();