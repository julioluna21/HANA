<?php
//Pantalla de inicio (bienvenida) del sistema HANA
//Muestra accesos directos a los módulos, ademas del menú lateral que siempre sigue disponible

session_start(); //se necesita la sesión para saber quien entro y que permisos tiene

//Sin sesión activa se manda al login (al final del archivo)
if (isset($_SESSION['IdUsuarios'])) {

    include('head.php'); //cabecera comun: menú lateral, CSS, topbar

    //El saludo de arriba sale al azar de los del sistema (Vista/saludo.php)
    require_once __DIR__ . '/saludo.php';
    /* Catalogo de accesos directos.
       Cada elemento es: [modulo, archivo, icono, titulo, descripcion]
       El "modulo" es el mismo código de permisos que usa el menú lateral,
       así la tarjeta solo se muestra si el usuario tiene acceso a ese modulo. */
    //Accesos unificados: lo que tiene varias pantallas va en UN solo botón que abre su
    //propia lista de accesos (Configuración, Reporte diario y Consultas), en vez de un
    //botón por cada pantalla. El primer dato es el permiso; TODOS, CONFIG, RQ, REPORTE y
    //CONSULTAS usan las mismas reglas del menú (se calculan abajo, antes de pintar las tarjetas)
    $accesos = array(
        array("TODOS", "MisNotificacionesVista.php", "fa-bell", "Mis notificaciones", "Las novedades y RQ que te llegaron"),
        array("CONFIG", "ConfiguracionVista.php", "fa-cog", "Configuración", "Proyectos, centros, colaboradores, usuarios, permisos y parámetros"),
        array("10M", "novedadesVista.php", "fa-comment", "Novedades", "Registra y consulta novedades y hallazgos"),
        array("RQ", "RQVista.php", "fa-wrench", "Requisiciones (RQ)", "Pide, revisa y aprueba requisiciones"),
        array("REPORTE", "ReporteDiarioVista.php", "fa-calendar-check-o", "Reporte diario", "Hoy en qué estás, listas de chequeo, arqueos, promesa de valor y más"),
        array("CONSULTAS", "ConsultasVista.php", "fa-line-chart", "Consultas", "Reporte general, informes, reporte a la Gerencia y trazabilidad")
    );
?>
        <!-- Contenido de la página -->
        <div class="right_col" role="main">
            <div class="">

                <!-- Saludo de bienvenida (Vista/saludo.php) -->
                <div class="row">
                    <div class="col-md-12">
                        <?php hanaSaludo(array(
                            'texto'  => 'Bienvenido a HANA, el sistema de gestión de reportes diarios, novedades y requisiciones. Selecciona abajo el módulo al que deseas ingresar, o usa el menú lateral en cualquier momento.',
                            'icono'  => hanaSaludoIcono() //sol de día, luna de noche
                        )); ?>
                    </div>
                </div>

                <div class="clearfix"></div>

                <!-- Tarjetas de acceso rápido -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2><i class="fa fa-th-large"></i> Accesos rápidos</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <!-- Grilla automatica: acomoda las tarjetas según el ancho
                                     disponible y todas quedan del mismo alto, sin huecos -->
                                <div class="grilla-modulos">
<?php
    $mostradas = 0; //contador para saber si el usuario tiene al menos un acceso

    //Se recorre el catalogo y solo se pintan las tarjetas permitidas
    //Las reglas especiales, iguales a las del menú lateral (head.php ya las calculó)
    $permitidos = $modulosAcceso;
    $permitidos[] = 'TODOS';
    $esCoordMenu = !empty($menuCoord); //head.php ya calculó si coordina un proyecto
    if (array_intersect(array('15M', '17M', '18M', '41M'), $modulosAcceso)) { $permitidos[] = 'RQ'; } //ver, ver todas, aprobar o pedir
    //Configuración: alguna de sus pantallas (las mismas casillas del menú lateral)
    if (array_intersect(array('1M', '2M', '3M', '5M', '6M', '7M', '24M'), $modulosAcceso)) { $permitidos[] = 'CONFIG'; }
    //Reporte diario: con alguna pantalla del reporte en el menú (head.php ya lo calculó), la promesa de valor incluida
    if ($esCoordMenu || !empty($menuListas) || !empty($menuEditarListas) || !empty($menuCorreos) || !empty($menuPromesa)) { $permitidos[] = 'REPORTE'; }
    //Consultas: reporte general, informe mensual, reporte a la Gerencia o trazabilidad
    if (array_intersect(array('19M', '37M'), $modulosAcceso) || !empty($menuGerencia) || !empty($menuTraza)) { $permitidos[] = 'CONSULTAS'; }

    foreach ($accesos as $acceso) {
        if (!in_array($acceso[0], $permitidos)) { continue; } //sin permiso: se salta
        $mostradas++;
?>
                                    <!-- Toda la tarjeta es un enlace, para que sea fácil de pulsar -->
                                    <a href="<?php echo $acceso[1]; ?>" class="tarjeta-modulo">
                                        <div class="tarjeta-icono"><i class="fa <?php echo $acceso[2]; ?>"></i></div>
                                        <div class="tarjeta-titulo"><?php echo $acceso[3]; ?></div>
                                        <div class="tarjeta-texto"><?php echo $acceso[4]; ?></div>
                                    </a>
<?php
    }

    //Caso extremo: usuario sin ningún modulo asignado
    if ($mostradas == 0) {
?>
                                    <div class="aviso-sin-modulos">
                                        <div class="alert alert-warning">
                                            <i class="fa fa-exclamation-triangle"></i>
                                            Tu usuario no tiene módulos asignados. Comunícate con el administrador del sistema.
                                        </div>
                                    </div>
<?php
    }
?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <style>
            /* El saludo de arriba (Vista/saludo.php, estilos en public/css/comun.css). En Inicio va un poco más abajo */
            .right_col .hana-bienvenida{ margin-top: 50px; }

            /* Grilla de tarjetas. Se reparten solas en columnas de minimo 200px:
               si caben 5 pone 5, si caben 3 pone 3, sin dejar huecos ni columnas vacías */
            .grilla-modulos{
                display:grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap:15px;
            }

            /* Tarjeta de acceso a un modulo. Es un <a>, por eso se quita el subrayado */
            .tarjeta-modulo{
                display:flex; flex-direction:column; align-items:center; justify-content:flex-start;
                text-decoration:none !important; color:#333;
                background:#fff; border:1px solid #e0e0e0; border-radius:8px;
                padding:20px 15px; height:100%; /* todas del mismo alto */
                text-align:center; transition: all .2s ease;
            }

            /* El aviso ocupa el ancho completo de la grilla */
            .aviso-sin-modulos{ grid-column: 1 / -1; }
            /* Efecto al pasar el mouse: se levanta y se tiñe del color del sistema */
            .tarjeta-modulo:hover{
                border-color:#1ABB9C; box-shadow:0 6px 14px rgba(0,0,0,.15);
                transform: translateY(-4px); color:#6E1A1E;
            }
            .tarjeta-icono{ font-size:38px; color:#1ABB9C; margin-bottom:10px; }
            .tarjeta-modulo:hover .tarjeta-icono{ color:#6E1A1E; }
            .tarjeta-titulo{ font-size:16px; font-weight:600; margin-bottom:6px; }
            .tarjeta-texto{ font-size:12px; color:#888; line-height:1.4; }

            /* En pantallas chicas se achica el minimo para que no queden tarjetas gigantes */
            @media (max-width:480px){
                .grilla-modulos{ grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
                .tarjeta-modulo{ padding:15px 10px; }
                .tarjeta-icono{ font-size:30px; }
            }
        </style>

        <?php
        include('footer.php'); //pie comun: carga jQuery, Bootstrap, etc.
        ?>
<?php
} else {
    //No hay sesion: al login
    echo "<script>
  <!--
  window.location.replace('login.php');
  //-->
  </script>";
}
?>