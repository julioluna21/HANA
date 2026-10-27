<?php
//Reporte diario - pantalla principal
//Reúne en mosaicos lo que se registra en el reporte diario:
//Hoy en qué estás, listas de chequeo y las secciones que se van sumando
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);
    require_once __DIR__ . '/../Control/PaginaControl.php'; //lo que la pantalla necesita saber de quien entra

    //Cada mosaico sale si el rol tiene alguna casilla de ese módulo (Permisos de usuario) y la persona
    //tiene peajes asignados (Colaboradores). Se marca con códigos propios: P_HOY, P_ARQUEOS, LISTAS...
    pagUsarModulo('HOY');
    $esCoordinador = pagPuede('HOY', 'llenar') && pagTieneCentros(); //llena su reporte del día
    if ($esCoordinador) { $modulosAcceso[] = 'COORD'; }
    if ((pagPuede('LISTAS', 'llenar') || pagPuede('LISTAS', 'ver')) && pagTieneCentros()) { $modulosAcceso[] = 'LISTAS'; }
    if (pagPuede('LISTAS', 'editar')) { $modulosAcceso[] = 'EDITAR_LISTAS'; }
    if (pagPuede('LISTAS', 'correos') || pagPuede('TAREAS', 'editar')) { $modulosAcceso[] = 'CORREOS'; }
    $consultaHoy = pagPuede('HOY', 'ver') && !pagPuede('HOY', 'llenar');
    foreach (array('HOY', 'ARQUEOS', 'CRONOGRAMA', 'VACANTES', 'COMUNICACIONES') as $moduloRD) { if (pagEntra($moduloRD)) { $modulosAcceso[] = 'P_' . $moduloRD; } }
    //Módulos que el administrador apagó en Parámetros del sistema: su mosaico no sale
    //OJO: head.php vuelve a leer $modulosAcceso de la sesión y borra las marcas de
    //arriba (COORD, LISTAS, P_...). Por eso los mosaicos usan su propia copia
    $accesoMosaicos = $modulosAcceso;
    $apagados = array('HoyVista.php' => 'HOY', 'ListasVista.php' => 'LISTAS', 'ArqueosVista.php' => 'ARQUEOS',
                      'CronogramaVista.php' => 'CRONOGRAMA', 'VacantesVista.php' => 'VACANTES',
                      'ComunicacionesVista.php' => 'COMUNICACIONES');
    //El ausentismo se retiró del sistema. La promesa de valor va en su propio enlace del menú, no como mosaico aquí.

    //Entra quien tenga algún mosaico (el Reporte general, 19M, va aparte)
    if ($esCoordinador || count(preg_grep('/^(P_|LISTAS$|EDITAR_LISTAS$|CORREOS$)/', $modulosAcceso)) > 0) {

        //Alertas pequeñas del día en los mosaicos: ya registrado (verde) o falta (ámbar)
        date_default_timezone_set('America/Bogota');
        $alerta = array('hoy' => '', 'listas' => '', 'arqueos' => '', 'cronograma' => '');
        if (isset($_SESSION['Idcolaborador'])) {
            $resumenDia = pagResumenDelDia(); //lo calcula el controlador (Control/PaginaControl.php)
            $estadoDia = $resumenDia['estado'];
            $ok   = function ($texto) { return '<span class="rd-tag rd-tag-ok"><i class="fa fa-check"></i> ' . $texto . '</span>'; };
            $falta = function ($texto) { return '<span class="rd-tag rd-tag-pend"><i class="fa fa-clock-o"></i> ' . $texto . '</span>'; };
            if ($estadoDia) {
                $alerta['hoy'] = $estadoDia['hoy']['ok'] ? $ok('Hoy ya está registrado') : $falta('Falta registrar hoy');
                $alerta['listas'] = $estadoDia['listas']['ok']
                    ? $ok('Hoy: ' . $estadoDia['listas']['n'] . ($estadoDia['listas']['n'] === 1 ? ' lista' : ' listas'))
                    : $falta('Hoy no has registrado listas');
                $alerta['arqueos'] = $estadoDia['arqueos']['ok']
                    ? $ok('Hoy: ' . $estadoDia['arqueos']['n'] . ($estadoDia['arqueos']['n'] === 1 ? ' arqueo' : ' arqueos'))
                    : $falta('Hoy no has registrado arqueos');
                $vhFalta = $estadoDia['cronograma']['vehiculos'] > 0 && $estadoDia['cronograma']['vehiculosReg'] < $estadoDia['cronograma']['vehiculos'];
                $alerta['cronograma'] = !$estadoDia['cronograma']['ok'] ? $falta('Falta revisarlo hoy')
                    : ($vhFalta ? $falta('Falta el estado del vehículo') : $ok('Revisado hoy a las ' . substr($estadoDia['cronograma']['revisado'], 11, 5)));
            }
        }

        /* Mosaicos: [permisos (basta uno), archivo, icono, título, descripción, ¿listo?, estado]
           Los que no están listos se muestran como "Próximamente", sin enlace */
        $mosaicos = array(
            array(array('P_HOY'), 'HoyVista.php', 'fa-map-marker', 'Hoy en qué estás',
                  $consultaHoy ? 'Dónde va a estar cada persona y lo que va a hacer' : 'Dónde vas a estar, tu horario y lo que vas a hacer hoy', true, $esCoordinador ? $alerta['hoy'] : ''),
            //Las llena quien tiene la casilla de llenar; quien solo tiene la de ver las revisa
            array(array('LISTAS'), 'ListasVista.php', 'fa-check-square-o', 'Listas de chequeo',
                  pagPuede('LISTAS', 'llenar') ? 'Diligencia las preguntas de cada lista' : 'Revisa las listas que diligenciaron', true,
                  pagPuede('LISTAS', 'llenar') ? $alerta['listas'] : ''),
            array(array('EDITAR_LISTAS'), 'GruposListasVista.php', 'fa-list-ul', 'Editar listas',
                  'Crea y edita las listas y sus preguntas', true, ''),
            array(array('CORREOS'), 'NotificacionesVista.php', 'fa-envelope-o', 'Correos automáticos',
                  'Recordatorios, resúmenes y avisos: hora, días y a quién le llegan', true, ''),
            array(array('P_ARQUEOS'), 'ArqueosVista.php', 'fa-money', 'Arqueos',
                  'Caja menor y recambio, contra el fondo autorizado', true, pagPuede('ARQUEOS', 'llenar') ? $alerta['arqueos'] : ''),
            array(array('P_CRONOGRAMA'), 'CronogramaVista.php', 'fa-calendar', 'Cronograma y vehículo',
                  'Visitas planeadas y estado del vehículo', true, pagPuede('CRONOGRAMA', 'llenar') ? $alerta['cronograma'] : ''),
            array(array('P_VACANTES'), 'VacantesVista.php', 'fa-user-plus', 'Vacantes',
                  'Cargos por cubrir y su acuerdo de servicio', true, ''),
            array(array('P_COMUNICACIONES'), 'ComunicacionesVista.php', 'fa-inbox', 'Comunicaciones y oficios',
                  'Lo que hay por atender y por enviar', true, ''),
            //El Reporte general y el informe mensual o por fechas van aparte (menú lateral e Inicio), no aquí
        );

        include('head.php');
        //Un saludo según la hora, con lo que le falta hoy (si es coordinador)
        $horaActual = (int)date('G');
        $saludo = $horaActual < 12 ? 'Buenos días' : ($horaActual < 19 ? 'Buenas tardes' : 'Buenas noches');
        $nombre = isset($_SESSION['Nombre']) ? trim(explode(' ', html_entity_decode($_SESSION['Nombre'], ENT_QUOTES, 'UTF-8'))[0]) : '';
        $faltan = array();
        if ($esCoordinador && !empty($estadoDia)) {
            if (pagModuloActivo('HOY') && !$estadoDia['hoy']['ok']) { $faltan[] = 'contar en qué estás hoy'; }
            if (pagModuloActivo('LISTAS') && pagPuede('LISTAS', 'llenar') && !$estadoDia['listas']['ok']) { $faltan[] = 'las listas de chequeo'; }
            if (pagModuloActivo('CRONOGRAMA') && pagPuede('CRONOGRAMA', 'llenar') && !$estadoDia['cronograma']['ok']) { $faltan[] = 'darle una mirada al cronograma'; }
        }
        if (!$esCoordinador) { $resumen = 'Aquí tienes todo lo del reporte diario.'; }
        elseif (!count($faltan)) { $resumen = 'Vas al día con todo lo de hoy. ¡Buen trabajo!'; }
        else {
            $ultimo = array_pop($faltan);
            $resumen = 'Para hoy te falta ' . (count($faltan) ? implode(', ', $faltan) . ' y ' : '') . $ultimo . '.';
        }
?>
<link href="../public/css/reporte.css?v=16" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-calendar-check-o"></i> Reporte diario</h2>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="hana-saludo">
            <div class="hana-sol"><i class="fa <?php echo $horaActual < 19 ? 'fa-sun-o' : 'fa-moon-o'; ?>"></i></div>
            <div><h3><?php echo htmlspecialchars($saludo . ($nombre !== '' ? ', ' . mb_convert_case(mb_strtolower($nombre, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : ''), ENT_QUOTES, 'UTF-8'); ?></h3>
              <p><?php echo htmlspecialchars($resumen, ENT_QUOTES, 'UTF-8'); ?></p></div>
          </div>
          <div class="rd-mosaicos">
<?php
        foreach ($mosaicos as $mosaico) {
            //Se muestra si tiene al menos uno de los permisos del mosaico
            if (!count(array_intersect($mosaico[0], $accesoMosaicos))) { continue; }
            if (isset($apagados[$mosaico[1]]) && !pagModuloActivo($apagados[$mosaico[1]])) { continue; } //apagado por el administrador
            if ($mosaico[5]) {
?>
            <a href="<?php echo $mosaico[1]; ?>" class="rd-mosaico">
              <div class="rd-mosaico-icono"><i class="fa <?php echo $mosaico[2]; ?>"></i></div>
              <div class="rd-mosaico-titulo"><?php echo $mosaico[3]; ?></div>
              <div class="rd-mosaico-texto"><?php echo $mosaico[4]; ?></div>
              <?php if ($mosaico[6] !== '') { echo '<div class="rd-mosaico-estado">' . $mosaico[6] . '</div>'; } ?>
            </a>
<?php       } else { ?>
            <div class="rd-mosaico rd-mosaico-pronto" aria-disabled="true">
              <div class="rd-mosaico-icono"><i class="fa <?php echo $mosaico[2]; ?>"></i></div>
              <div class="rd-mosaico-titulo"><?php echo $mosaico[3]; ?></div>
              <div class="rd-mosaico-texto"><?php echo $mosaico[4]; ?></div>
              <div class="rd-mosaico-estado"><span class="rd-tag">Próximamente</span></div>
            </div>
<?php       }
        }
?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<?php
    } else {
        echo "<script>window.location.replace('InicioVista.php');</script>";
    }
} else {
    echo "<script>window.location.replace('login.php');</script>";
}
?>
