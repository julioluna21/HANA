<?php
/*
  HANA - Textos: cómo se guardan y cómo se muestran
  ---------------------------------------------------------------------------
  En HANA conviven dos formas de guardar los textos:

    - Los módulos de siempre (proyectos, centros de operación, títulos y roles,
      novedades, ítems de las RQ...) guardan el texto ya convertido para HTML:
      «Peajes & Vías» queda «Peajes &amp; Vías» y «O'Neil» queda «O&#039;Neil».
      Sus pantallas lo pintan tal cual, y por eso es seguro.
    - Los módulos nuevos (Hoy en qué estás, comunicaciones, vacantes, usuarios...)
      guardan el texto tal como se escribió y lo convierten al mostrarlo.

  Estas funciones ayudan a que las dos formas se vean bien en todas partes:

    hanaTextoGuardado($texto) El texto en la forma de los módulos de siempre, para
                            guardarlo con consultas preparadas (es lo mismo que
                            hacía limpiarCadena, sin la parte de SQL).
    hanaTextoVisible($texto)  El texto como lo escribió la persona: deshace los
                            códigos (&amp; → &). Para PDF, Excel y respuestas JSON
                            que la pantalla escapa al pintar.
    hanaJsonTexto($datos)   json_encode de una respuesta, con todos sus textos
                            pasados por hanaTextoVisible. Lo usan las pantallas
                            nuevas, que escapan todo al pintar (rdEsc).
    hanaProblemaNombre($nombre, $max, $que)
                            Revisa un nombre de catálogo antes de guardarlo:
                            que no esté vacío y que quepa. Devuelve el mensaje
                            para la persona, o '' si sirve.
    hanaMayusculas($texto)    Pasa a mayúsculas respetando tildes y eñes
                            («Daño en vía» → «DAÑO EN VÍA»).
*/

if (!function_exists('hanaTextoGuardado')) {

    function hanaTextoGuardado($texto)
    {
        return htmlspecialchars(trim((string)$texto), ENT_QUOTES, 'UTF-8');
    }

    function hanaTextoVisible($texto)
    {
        if ($texto === null) { return null; }
        $texto = (string)$texto;
        if (strpos($texto, '&') === false) { return $texto; } //no hay nada que deshacer
        //Dos pasadas: algunos textos viejos quedaron convertidos dos veces (&amp;amp;)
        for ($i = 0; $i < 2; $i++) {
            $antes = $texto;
            $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($texto === $antes) { break; }
        }
        return $texto;
    }

    function hanaJsonTexto($datos, $opciones = 0)
    {
        return json_encode(hanaTextoVisibleTodo($datos), JSON_UNESCAPED_UNICODE | $opciones);
    }

    //hanaTextoVisible en cada texto de un arreglo (con arreglos dentro)
    function hanaTextoVisibleTodo($datos)
    {
        if (is_string($datos)) { return hanaTextoVisible($datos); }
        if (is_array($datos)) {
            foreach ($datos as $clave => $valor) { $datos[$clave] = hanaTextoVisibleTodo($valor); }
        }
        return $datos;
    }

    function hanaMayusculas($texto)
    {
        return mb_strtoupper((string)$texto, 'UTF-8');
    }

    //$nombre ya en la forma en que se va a guardar (hanaTextoGuardado); $que: "del proyecto", "del rol"...
    function hanaProblemaNombre($nombre, $max, $que)
    {
        if (trim(hanaTextoVisible($nombre)) === '') { return 'Escribe el nombre ' . $que . '.'; }
        if (mb_strlen((string)$nombre, 'UTF-8') > $max) {
            return 'El nombre ' . $que . ' es muy largo: máximo ' . $max . ' caracteres (los símbolos & < > " \' ocupan más de uno).';
        }
        return '';
    }
}
