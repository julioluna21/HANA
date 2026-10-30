<?php
//Pantalla de inicio (bienvenida) del sistema HANA
//Muestra accesos directos a los módulos, ademas del menú lateral que siempre sigue disponible

session_start(); //se necesita la sesión para saber quien entro y que permisos tiene

//Sin sesión activa se manda al login (al final del archivo)
if (isset($_SESSION['IdUsuarios'])) {

    include('head.php'); //cabecera comun: menú lateral, CSS, topbar

    //Nombre del usuario para el saludo. Si no viene, se saluda de forma genérica
    $nombreUsuario = isset($_SESSION['Nombre']) ? $_SESSION['Nombre'] : "Usuario";

    //Saludo al azar. Se usa switch y no match() porque match() solo existe desde
    //PHP 8 y el servidor corre PHP 7: allí match() deja la pantalla en blanco
    $azar = rand(1, 10); //son 10 saludos; si el número no tuviera saludo, queda "Buenas"

    switch ($azar) {
        case 1:  $saludo = "¡Hola! Qué gusto verte"; break;
        case 2:  $saludo = "Me alegra verte"; break;
        case 3:  $saludo = "Todo listo para empezar"; break;
        case 4:  $saludo = "¡Hola de nuevo!"; break;
        case 5:  $saludo = "¡Hola! Bienvenido de vuelta"; break;
        case 6:  $saludo = "¡Hola! Comencemos con la revisión de hoy"; break;
        case 7:  $saludo = "¡Buenas! Empecemos la revisión de hoy"; break;
        case 8:  $saludo = "¡Buenas! ¿Por dónde empezamos?"; break;
        case 9:  $saludo = "¡Hola, qué tal!"; break;
        case 10: $saludo = "¡Hola, empecemos!"; break;


        default: $saludo = "Buenas"; break;
    }
    /* Catalogo de accesos directos.
       Cada elemento es: [modulo, archivo, icono, titulo, descripcion]
       El "modulo" es el mismo código de permisos que usa el menú lateral,
       así la tarjeta solo se muestra si el usuario tiene acceso a ese modulo. */
    //Las mismas etiquetas del menú lateral, en el mismo orden y con el mismo nombre.
    //El primer dato es el permiso; TODOS, REPORTE y AUSENTISMO usan las mismas reglas
    //del menú (se calculan abajo, antes de pintar las tarjetas)
    $accesos = array(
        array("TODOS", "MisNotificacionesVista.php", "fa-bell", "Mis notificaciones", "Las novedades y RQ que te llegaron"),
        array("6M", "proyectosVista.php", "fa-folder-open", "Proyectos", "Administra los proyectos y concesiones"),
        array("5M", "centroOperativoVista.php", "fa-building", "Centros de operación", "Administra los centros operativos"),
        array("2M", "RolesVista.php", "fa-key", "Permisos de usuario", "Define qué puede hacer cada rol"),
        array("24M", "ParametrosVista.php", "fa-sliders", "Parámetros del sistema", "Módulos activos, plazos y catálogos"),
        array("COLABORADORES", "ColaboradoresVista.php", "fa-user-plus", "Colaboradores", "Las personas que trabajan en los proyectos"),
        array("USUARIOS", "UsuariosVista.php", "fa-users", "Usuarios", "El acceso de cada persona a HANA"),
        array("7M", "TitulosNovedadesVista.php", "fa-tags", "Títulos de novedades", "Parametriza los títulos de novedades"),
        array("10M", "novedadesVista.php", "fa-comment", "Novedades", "Registra y consulta novedades y hallazgos"),
        array("RQ", "RQVista.php", "fa-wrench", "Requisiciones (RQ)", "REQUISICIONES"),
        array("REPORTE", "ReporteDiarioVista.php", "fa-calendar-check-o", "Reporte diario", "Hoy en qué estás, listas de chequeo y más"),
        array("19M", "ControlDiarioVista.php", "fa-list-alt", "Reporte general", "Todo lo que enviaron coordinadores y jefes, por día, módulo y proyecto"),
        array("37M", "InformeMensualVista.php", "fa-file-pdf-o", "Informe mensual", "Un PDF de un mes, una quincena o las fechas que elijas"),
        array("GERENCIA", "ReporteGerenciaVista.php", "fa-briefcase", "Reporte a la Gerencia", "El reporte del día por proyecto: flota, promesa de valor, oficios y novedades"),
        array("12M", "GruposListasVista.php", "fa-list-ul", "Editar listas", "Crea y edita las preguntas de cada lista"),
        array("12M", "NotificacionesVista.php", "fa-envelope-o", "Correos automáticos", "Recordatorios, resúmenes y avisos por correo"),
        array("AUSENTISMO", "AusentismoVista.php", "fa-user-times", "Registrar ausentismo", "El reporte de ausentismo de cada peaje"),
        array("PROMESA", "PromesaValorVista.php", "fa-clock-o", "Promesa de valor", "Horas prometidas y ejecutadas de cada peaje, y por qué no se cumplió")
    );
?>
        <!-- Contenido de la página -->
        <div class="right_col" role="main">
            <div class="">

                <!-- Banner de bienvenida -->   
                <div class="row">
                    <div class="col-md-12">
                        <div class="panel-bienvenida">
                            <h1><?php echo $saludo; ?>, <?php echo htmlspecialchars($nombreUsuario); ?></h1>
                            <p>
                                Bienvenido a <strong>HANA</strong>, el sistema de gestión de reportes diarios, gestion de novedades
                                y reportes de ausentismo. Selecciona abajo el módulo al que deseas ingresar,
                                o usa el menú lateral en cualquier momento.
                            </p>    
                        </div>
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
    if (array_intersect(array('3M', '24M'), $modulosAcceso)) { $permitidos[] = 'COLABORADORES'; } //registrar a las personas (3M)
    if (array_intersect(array('1M', '24M'), $modulosAcceso)) { $permitidos[] = 'USUARIOS'; }      //su acceso (1M)
    //Reporte diario: con alguna pantalla del reporte en el menú (head.php ya lo calculó)
    if ($esCoordMenu || !empty($menuListas) || !empty($menuEditarListas) || !empty($menuCorreos)) { $permitidos[] = 'REPORTE'; }
    if (!empty($menuAusReg)) { $permitidos[] = 'AUSENTISMO'; } //head.php ya calculó quién entra al ausentismo
    if (!empty($menuGerencia)) { $permitidos[] = 'GERENCIA'; } //head.php ya calculó quién arma el reporte a la Gerencia
    if (!empty($menuPromesa)) { $permitidos[] = 'PROMESA'; } //head.php ya calculó quién entra a la promesa de valor

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
            /* Banner superior de bienvenida. Color solido, el mismo del menú lateral */
            .panel-bienvenida{
                background:#6E1A1E;
                color:#fff; border-radius:8px; padding:25px 30px; margin:15px 0 20px 0; margin-top: 50px;
            }
            .panel-bienvenida h1{ margin:0 0 8px 0; font-size:26px; font-weight:600; }
            .panel-bienvenida p{ margin:0; opacity:.9; font-size:14px; }

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