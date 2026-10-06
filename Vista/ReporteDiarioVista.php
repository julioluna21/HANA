<?php
//Reporte diario — pantalla principal
//Reúne todo lo que antes iba en el Excel del reporte diario, en mosaicos:
//Hoy en qué estás, listas de chequeo y las secciones que se van sumando
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);

    //El reporte del coordinador (Hoy en qué estás, arqueos, cronograma, vacantes y
    //comunicaciones) es solo de quien coordina un proyecto. En los mosaicos se
    //marca con el código "COORD"
    require_once __DIR__ . '/../Modelo/HanaDB.php';
    $esCoordinador = isset($_SESSION['Idcolaborador']) && HanaDB::esCoordinador((int)$_SESSION['Idcolaborador']);
    if ($esCoordinador) { $modulosAcceso[] = 'COORD'; }
    //Listas de chequeo: las llena el coordinador (con 11M), o cualquiera con 11M si el
    //administrador apagó LISTAS_SOLO_COORDINADOR; las ven también 12M y 21M
    require_once __DIR__ . '/../Modelo/HanaConfig.php';
    if ((($esCoordinador || !HanaConfig::si('LISTAS_SOLO_COORDINADOR')) && in_array('11M', $modulosAcceso)) || in_array('12M', $modulosAcceso) || in_array('21M', $modulosAcceso)) { $modulosAcceso[] = 'LISTAS'; }
    if (in_array('22M', $modulosAcceso)) { $modulosAcceso[] = 'VER_TODO'; }
    //Cada pantalla del coordinador: coordinador, ADMIN TEC o su casilla en Roles
    foreach (array_keys(HanaDB::$PERMISO_MODULO) as $mm) { if (HanaDB::puedeModulo($mm)) { $modulosAcceso[] = 'P_' . $mm; } }
    //Oficios: también quien tenga peajes asignados (jefe de peaje o en Usuarios)
    if (isset($_SESSION['Idcolaborador'], $_SESSION['IdUsuarios']) && HanaDB::fila("SELECT 1 AS ok FROM centros_operacion c WHERE c.Estado = '1' AND (c.ID_COLABORADOR_JEFE = ? OR c.ID_CENTRO_OP IN
           (SELECT ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP FROM asoc_usuarios_sistemas_x_cop WHERE ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = ?)) LIMIT 1",
        'ii', array((int)$_SESSION['Idcolaborador'], (int)$_SESSION['IdUsuarios']))) { $modulosAcceso[] = 'P_COMUNICACIONES'; }
    //Módulos que el administrador apagó en Parámetros del sistema: su mosaico no sale
    //OJO: head.php vuelve a leer $modulosAcceso de la sesión y borra las marcas de
    //arriba (COORD, LISTAS, VER_TODO). Por eso los mosaicos usan su propia copia
    $accesoMosaicos = $modulosAcceso;
    $apagados = array('HoyVista.php' => 'HOY', 'ListasVista.php' => 'LISTAS', 'ArqueosVista.php' => 'ARQUEOS',
                      'CronogramaVista.php' => 'CRONOGRAMA', 'VacantesVista.php' => 'VACANTES',
                      'ComunicacionesVista.php' => 'COMUNICACIONES', 'AusentismoVista.php' => 'AUSENTISMO');

    //Entra quien diligencia listas (11M), las edita (12M), administra centros (5M),
    //controla (19M) o coordina un proyecto
    if (in_array("11M", $modulosAcceso) || in_array("12M", $modulosAcceso) || in_array("5M", $modulosAcceso) || in_array("19M", $modulosAcceso) || in_array("15M", $modulosAcceso) || in_array("21M", $modulosAcceso) || in_array("37M", $modulosAcceso) || $esCoordinador
        || count(preg_grep('/^P_/', $modulosAcceso)) > 0) { //o alguna casilla de las pantallas del coordinador

        //Alertas pequeñas del día en los mosaicos: ya registrado (verde) o falta (ámbar)
        date_default_timezone_set('America/Bogota');
        $alerta = array('hoy' => '', 'listas' => '', 'arqueos' => '', 'cronograma' => '');
        if (isset($_SESSION['Idcolaborador'])) {
            require_once __DIR__ . '/../Modelo/EstadoDiaModelo.php';
            $ED = new EstadoDia();
            $e = $ED->resumen((int)$_SESSION['Idcolaborador'], date('Y-m-d'));
            $ok   = function ($t) { return '<span class="rd-tag rd-tag-ok"><i class="fa fa-check"></i> ' . $t . '</span>'; };
            $falta = function ($t) { return '<span class="rd-tag rd-tag-pend"><i class="fa fa-clock-o"></i> ' . $t . '</span>'; };
            if ($e) {
                $alerta['hoy'] = $e['hoy']['ok'] ? $ok('Hoy ya está registrado') : $falta('Falta registrar hoy');
                $alerta['listas'] = $e['listas']['ok']
                    ? $ok('Hoy: ' . $e['listas']['n'] . ($e['listas']['n'] === 1 ? ' lista' : ' listas'))
                    : $falta('Hoy no has registrado listas');
                $alerta['arqueos'] = $e['arqueos']['ok']
                    ? $ok('Hoy: ' . $e['arqueos']['n'] . ($e['arqueos']['n'] === 1 ? ' arqueo' : ' arqueos'))
                    : $falta('Hoy no has registrado arqueos');
                $vhFalta = $e['cronograma']['vehiculos'] > 0 && $e['cronograma']['vehiculosReg'] < $e['cronograma']['vehiculos'];
                $alerta['cronograma'] = !$e['cronograma']['ok'] ? $falta('Falta revisarlo hoy')
                    : ($vhFalta ? $falta('Falta el estado del vehículo') : $ok('Revisado hoy a las ' . substr($e['cronograma']['revisado'], 11, 5)));
            }
        }

        /* Mosaicos: [permisos (basta uno), archivo, icono, título, descripción, ¿listo?, estado]
           Los que no están listos se muestran como "Próximamente", sin enlace */
        $mosaicos = array(
            array(array('P_HOY'), 'HoyVista.php', 'fa-map-marker', 'Hoy en qué estás',
                  'Dónde estás, tu horario y lo que hiciste hoy', true, $esCoordinador ? $alerta['hoy'] : ''),
            array(array('15M'), 'RQVista.php', 'fa-wrench', 'Requisiciones (RQ)',
                  $esCoordinador ? 'Las RQ de tus peajes' : 'Pide lo que necesita tu peaje', true, ''),
            array(array('LISTAS'), 'ListasVista.php', 'fa-check-square-o', 'Listas de chequeo',
                  'Diligencia las preguntas de cada lista', true, $esCoordinador ? $alerta['listas'] : ''),
            array(array('12M'), 'GruposListasVista.php', 'fa-list-ul', 'Editar listas',
                  'Crea y edita las listas y sus preguntas', true, ''),
            array(array('12M'), 'NotificacionesVista.php', 'fa-envelope-o', 'Correos de listas',
                  'Configura los reportes automáticos por correo', true, ''),
            array(array('P_ARQUEOS', '5M', '36M'), 'ArqueosVista.php', 'fa-money', 'Arqueos',
                  'Caja menor y recambio, contra el fondo autorizado', true, $esCoordinador ? $alerta['arqueos'] : ''),
            array(array('P_CRONOGRAMA', '5M', 'VER_TODO'), 'CronogramaVista.php', 'fa-calendar', 'Cronograma y vehículo',
                  'Visitas planeadas y estado del vehículo', true, $esCoordinador ? $alerta['cronograma'] : ''),
            array(array('P_VACANTES'), 'VacantesVista.php', 'fa-user-plus', 'Vacantes',
                  'Cargos por cubrir y su acuerdo de servicio', true, ''),
            array(array('P_COMUNICACIONES'), 'ComunicacionesVista.php', 'fa-inbox', 'Comunicaciones y oficios',
                  'Lo que hay por atender y por enviar', true, ''),
            array(array('21M'), 'AdminReporteVista.php', 'fa-shield', 'Administración',
                  'Consulta todo lo que llenan los coordinadores', true, ''),
            array(array('19M'), 'ControlDiarioVista.php', 'fa-list-alt', 'Reporte general',
                  'Todo lo que enviaron coordinadores y jefes, por módulo', true, ''),
            array(array('19M'), 'TableroVista.php', 'fa-th-large', 'Tablero por proyectos',
                  'El reporte de cada coordinador y jefe de peaje', true, ''),
            array(array('37M'), 'InformeMensualVista.php', 'fa-file-pdf-o', 'Informe mensual',
                  'Todo lo del mes en un PDF: general y por proyecto', true, '')
        );

        include('head.php');
        //Un saludo según la hora, con lo que le falta hoy (si es coordinador)
        $h = (int)date('G');
        $saludo = $h < 12 ? 'Buenos días' : ($h < 19 ? 'Buenas tardes' : 'Buenas noches');
        $nombre = isset($_SESSION['Nombre']) ? trim(explode(' ', html_entity_decode($_SESSION['Nombre'], ENT_QUOTES, 'UTF-8'))[0]) : '';
        $faltan = array();
        if ($esCoordinador && !empty($e)) {
            if (HanaConfig::modulo('HOY') && !$e['hoy']['ok']) { $faltan[] = 'contar en qué estás hoy'; }
            if (HanaConfig::modulo('LISTAS') && !$e['listas']['ok']) { $faltan[] = 'las listas de chequeo'; }
            if (HanaConfig::modulo('CRONOGRAMA') && !$e['cronograma']['ok']) { $faltan[] = 'darle una mirada al cronograma'; }
        }
        if (!$esCoordinador) { $resumen = 'Aquí tienes todo lo del reporte diario.'; }
        elseif (!count($faltan)) { $resumen = 'Vas al día con todo lo de hoy. ¡Buen trabajo!'; }
        else {
            $ultimo = array_pop($faltan);
            $resumen = 'Para hoy te falta ' . (count($faltan) ? implode(', ', $faltan) . ' y ' : '') . $ultimo . '.';
        }
?>
<link href="../public/css/reporte.css?v=15" rel="stylesheet">

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
            <div class="hana-sol"><i class="fa <?php echo $h < 19 ? 'fa-sun-o' : 'fa-moon-o'; ?>"></i></div>
            <div><h3><?php echo htmlspecialchars($saludo . ($nombre !== '' ? ', ' . ucfirst(strtolower($nombre)) : ''), ENT_QUOTES, 'UTF-8'); ?></h3>
              <p><?php echo htmlspecialchars($resumen, ENT_QUOTES, 'UTF-8'); ?></p></div>
          </div>
          <div class="rd-mosaicos">
<?php
        foreach ($mosaicos as $m) {
            //Se muestra si tiene al menos uno de los permisos del mosaico
            if (!count(array_intersect($m[0], $accesoMosaicos))) { continue; }
            if (isset($apagados[$m[1]]) && !HanaConfig::modulo($apagados[$m[1]])) { continue; } //apagado por el administrador
            if ($m[5]) {
?>
            <a href="<?php echo $m[1]; ?>" class="rd-mosaico">
              <div class="rd-mosaico-icono"><i class="fa <?php echo $m[2]; ?>"></i></div>
              <div class="rd-mosaico-titulo"><?php echo $m[3]; ?></div>
              <div class="rd-mosaico-texto"><?php echo $m[4]; ?></div>
              <?php if ($m[6] !== '') { echo '<div class="rd-mosaico-estado">' . $m[6] . '</div>'; } ?>
            </a>
<?php       } else { ?>
            <div class="rd-mosaico rd-mosaico-pronto" aria-disabled="true">
              <div class="rd-mosaico-icono"><i class="fa <?php echo $m[2]; ?>"></i></div>
              <div class="rd-mosaico-titulo"><?php echo $m[3]; ?></div>
              <div class="rd-mosaico-texto"><?php echo $m[4]; ?></div>
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
