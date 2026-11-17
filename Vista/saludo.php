<?php
/*
  HANA - El saludo de arriba de las pantallas principales
  ---------------------------------------------------------------------------

  Uso (después de include('head.php')):
      require_once __DIR__ . '/saludo.php';
      hanaSaludo(array(
          'texto' => 'Lo que va debajo del saludo',   //texto plano (se escapa aquí)
          'icono' => 'fa-cog'                         //el dibujo grande de la derecha
      ));
  Opcional: 'frases' => array(...) para usar otras frases en vez de las del sistema.
  En las frases:
      {nombre}  el primer nombre de quien entró ("Carla")
      {hora}    "Buenos días", "Buenas tardes" o "Buenas noches", según la hora de Colombia
  Los estilos están en public/css/comun.css (.hana-bienvenida).
*/

//Los saludos del sistema
function hanaSaludoFrases()
{
    return array(
        '{hora}, {nombre}',
        '{hora}, {nombre}. ¡Qué gusto verte!',
        '{hora}, {nombre}. Empecemos',
        '{hora}, {nombre}. Todo listo por aquí',
        '{hora}, {nombre}. Vamos con todo',
        '{hora}, {nombre}. Qué bueno tenerte aquí',
        '{hora}, {nombre}. ¿Qué tal la jornada?',
        '¡Hola, {nombre}!',
        '¡Hola, {nombre}! Qué gusto verte',
        '¡Hola, {nombre}! Empecemos',
        '¡Hola, {nombre}! ¿Por dónde empezamos?',
        '¡Hola, {nombre}! ¿Qué revisamos primero?',
        '¡Hola, {nombre}! Que tengas un gran día',
        '¡Hola, {nombre}! Todo está listo para ti',
        '¡Hola, {nombre}! Comencemos con la revisión de hoy',
        '¡Hola, {nombre}! Qué bueno verte de vuelta',
        '¡Hola, {nombre}! Aquí estamos para lo que necesites',
        '¡Hola, {nombre}! Manos a la obra',
        '¡Hola, {nombre}! Vamos paso a paso',
        '¡Hola, {nombre}! Hoy va a ser un buen día',
        '¡Hola, {nombre}! ¿Qué tenemos pendiente?',
        '¡Hola, {nombre}! Gracias por estar aquí',
        '¡Hola, {nombre}! Pongamos todo al día',
        '¡Hola de nuevo, {nombre}!',
        '¡Hola otra vez, {nombre}!',
        '¡Hola, qué tal, {nombre}!',
        '¡Qué tal, {nombre}! ¿Cómo va todo?',
        '¡Qué tal, {nombre}! Empecemos',
        '¡Qué bueno tenerte por aquí, {nombre}!',
        '¡Qué gusto saludarte, {nombre}!',
        '¡Qué alegría verte, {nombre}!',
        '¡Qué bien que llegaste, {nombre}!',
        '¡Qué bueno verte, {nombre}!',
        'Me alegra verte, {nombre}',
        'Me alegra que estés aquí, {nombre}',
        'Todo listo para empezar, {nombre}',
        'Todo en orden para empezar, {nombre}',
        'Todo preparado para ti, {nombre}',
        'Aquí tienes todo a la mano, {nombre}',
        'Aquí está todo listo, {nombre}',
        'Un gusto tenerte de vuelta, {nombre}',
        'Un saludo grande, {nombre}',
        'Un buen día para avanzar, {nombre}',
        '¡Buenas, {nombre}!',
        '¡Buenas, {nombre}! ¿Por dónde empezamos?',
        '¡Buenas, {nombre}! Empecemos la revisión de hoy',
        '¡Buenas, {nombre}! Qué gusto verte',
        '¡Buenas, {nombre}! A darle con toda',
        '¡Buenas, {nombre}! ¿Qué hay para hoy?',
        '¡Buenas, {nombre}! Todo listo por aquí',
        '¡Buenas, {nombre}! Vamos con el día',
        '¡Saludos, {nombre}!',
        '¡Saludos, {nombre}! ¿Cómo va la operación?',
        '¡Saludos, {nombre}! Empecemos',
        '¡Saludos, {nombre}! Qué bueno verte',
        '¡Saludos, {nombre}! Todo listo para trabajar',
        '¡Hey, {nombre}! ¿Cómo vas?',
        '¡Hey, {nombre}! Qué bueno verte por aquí',
        '¡Hey, {nombre}! Vamos con todo',
        '¡Hey, {nombre}! ¿Arrancamos?',
        '¡Hey, {nombre}! Todo listo',
        '¿Cómo estás, {nombre}?',
        '¿Cómo vas, {nombre}?',
        '¿Cómo te va, {nombre}?',
        '¿Qué tal todo, {nombre}?',
        '¿Qué tal tu día, {nombre}?',
        '¿Cómo va la operación, {nombre}?',
        '¿Todo listo para el día, {nombre}?',
        '¿Arrancamos, {nombre}?',
        '¿Qué hacemos hoy, {nombre}?',
        '¿Empezamos, {nombre}?',
        '¡Llegaste, {nombre}! Empecemos',
        'De nuevo por aquí, {nombre}. ¡Qué bien!',
        'Otra vez juntos en HANA, {nombre}',
        'Siempre es un gusto verte, {nombre}',
        'Listos para un buen día, {nombre}',
        'Listos para avanzar, {nombre}',
        'Vamos con toda la energía, {nombre}',
        'Vamos a hacer que hoy cuente, {nombre}',
        'Paso a paso se llega lejos, {nombre}',
        'Hoy es un buen día para avanzar, {nombre}',
        'Un día más para hacerlo bien, {nombre}',
        'Con calma y buen ritmo, {nombre}',
        'Que hoy todo salga muy bien, {nombre}',
        'Que tengas una excelente jornada, {nombre}',
        'Que tu día sea productivo, {nombre}',
        'Éxitos en la jornada de hoy, {nombre}',
        'Gracias por tu trabajo, {nombre}',
        'Tu trabajo hace la diferencia, {nombre}',
        'El equipo cuenta contigo, {nombre}',
        'A seguir sumando, {nombre}'
    );
}

//El primer nombre de quien entró, con mayúscula inicial: "CARLA PÉREZ" -> "Carla"
function hanaSaludoNombre()
{
    $nombre = isset($_SESSION['Nombre']) ? trim(html_entity_decode((string)$_SESSION['Nombre'], ENT_QUOTES, 'UTF-8')) : '';
    $partes = preg_split('/\s+/u', $nombre);
    $primero = $partes && $partes[0] !== '' ? $partes[0] : '';
    return $primero !== '' ? mb_convert_case(mb_strtolower($primero, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : '';
}

//La hora de Colombia (0 a 23)
function hanaSaludoHoraDia() { return (int)(new DateTime('now', new DateTimeZone('America/Bogota')))->format('G'); }

//"Buenos días" (de 5 a. m. a 12 m.), "Buenas tardes" (hasta las 7 p. m.) o "Buenas noches"
function hanaSaludoHora()
{
    $hora = hanaSaludoHoraDia();
    return ($hora >= 5 && $hora < 12) ? 'Buenos días' : (($hora >= 12 && $hora < 19) ? 'Buenas tardes' : 'Buenas noches');
}

//El sol de día y la luna de noche
function hanaSaludoIcono() { $hora = hanaSaludoHoraDia(); return ($hora >= 5 && $hora < 19) ? 'fa-sun-o' : 'fa-moon-o'; }

//Pinta el saludo
function hanaSaludo($opciones)
{
    $frases = isset($opciones['frases']) && count($opciones['frases']) ? array_values($opciones['frases']) : hanaSaludoFrases();
    $frase = $frases[mt_rand(0, count($frases) - 1)];
    $nombre = hanaSaludoNombre();
    //Sin nombre (sesión sin él) se quita ", {nombre}" o "{nombre}" para que no quede una coma suelta
    if ($nombre === '') { $frase = trim(str_replace(array(', {nombre}', ' {nombre}', '{nombre}'), '', $frase)); }
    $titulo = str_replace(array('{nombre}', '{hora}'), array($nombre, hanaSaludoHora()), $frase);
    $texto = isset($opciones['texto']) ? (string)$opciones['texto'] : '';
    $icono = isset($opciones['icono']) ? preg_replace('/[^a-z0-9\-]/', '', (string)$opciones['icono']) : 'fa-sun-o';
    ?>
    <div class="hana-bienvenida">
      <div class="hana-bienvenida-texto">
        <h1><?php echo htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($texto !== '') { ?><p><?php echo htmlspecialchars($texto, ENT_QUOTES, 'UTF-8'); ?></p><?php } ?>
      </div>
      <i class="fa <?php echo $icono; ?> hana-bienvenida-icono" aria-hidden="true"></i>
    </div>
    <?php
}
