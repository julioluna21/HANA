<?php
//Configuración - pantalla principal
//Reúne en mosaicos todo lo que se configura (como el Reporte diario reúne lo del día):
//proyectos, centros, permisos, parámetros, colaboradores, usuarios, títulos de novedades y arqueos.
//Cada mosaico sale solo si el rol tiene su casilla (las mismas del menú lateral)
session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $accesoConfig = explode(",", $_SESSION['Modulos']);
    $tiene = function () use ($accesoConfig) { foreach (func_get_args() as $casilla) { if (in_array($casilla, $accesoConfig, true)) { return true; } } return false; };

    /* Mosaicos: [¿lo ve?, archivo, icono, título, descripción] */
    $mosaicos = array(
        array($tiene('6M'), 'proyectosVista.php', 'fa-folder-open', 'Proyectos', 'Los proyectos o concesiones y su coordinador'),
        array($tiene('5M'), 'centroOperativoVista.php', 'fa-building', 'Centros de operación', 'Peajes, básculas, bases y oficinas de cada proyecto'),
        array($tiene('3M', '24M'), 'ColaboradoresVista.php', 'fa-user-plus', 'Colaboradores', 'Las personas que trabajan en los proyectos y sus peajes'),
        array($tiene('1M', '24M'), 'UsuariosVista.php', 'fa-users', 'Usuarios', 'El acceso de cada persona a HANA: usuario, rol y clave'),
        array($tiene('2M'), 'RolesVista.php', 'fa-key', 'Permisos de usuario', 'Qué puede hacer cada rol'),
        array($tiene('24M'), 'ParametrosVista.php', 'fa-sliders', 'Parámetros del sistema', 'Módulos activos, plazos, listas y días habilitados'),
        array($tiene('7M'), 'TitulosNovedadesVista.php', 'fa-tags', 'Títulos de novedades', 'Los tipos de novedad que se pueden reportar'),
        array($tiene('36M', '24M'), 'ArqueosVista.php#config', 'fa-money', 'Configuración de arqueos', 'Conceptos de caja menor y recambio, campos de casetas')
    );
    $visibles = array_filter($mosaicos, function ($mosaico) { return $mosaico[0]; });

    if (count($visibles)) {
        include('head.php');
        require_once __DIR__ . '/saludo.php'; //el saludo de arriba
?>
<link href="../public/css/reporte.css?v=16" rel="stylesheet">

<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">
      <?php hanaSaludo(array(
          'texto'  => 'Todo lo que se configura del sistema, en un solo lugar. Elige qué quieres revisar o cambiar.',
          'icono'  => 'fa-cog'
      )); ?>
      <div class="x_panel">
        <div class="x_title">
          <h2><i class="fa fa-cog"></i> Configuración</h2>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="rd-mosaicos">
<?php foreach ($visibles as $mosaico) { ?>
            <a href="<?php echo $mosaico[1]; ?>" class="rd-mosaico">
              <div class="rd-mosaico-icono"><i class="fa <?php echo $mosaico[2]; ?>"></i></div>
              <div class="rd-mosaico-titulo"><?php echo $mosaico[3]; ?></div>
              <div class="rd-mosaico-texto"><?php echo $mosaico[4]; ?></div>
            </a>
<?php } ?>
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
