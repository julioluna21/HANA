<?php
/*
  HANA - Guardia de sesión y permisos para los controladores
  ---------------------------------------------------------------------------
  Los controladores de Roles, Colaboradores, Cargos, Títulos, Estados, Observador,
  Listas, Preguntas, Novedades y Notificaciones pasan por aquí: sin sesión o sin
  el permiso de la pantalla no se atiende la petición (nadie cambia permisos ni
  crea registros sin haber entrado al sistema).

  Uso, justo después de session_start():
      require_once __DIR__ . '/Guardia.php';
      hanaGuardia(array('2M'), array('select'));

    $permisos     el permiso de la pantalla (basta tener uno de la lista)
    $opsLectura   operaciones de solo lectura que usan OTRAS pantallas (los
                  selectores): basta con haber iniciado sesión, o tener uno
                  de $permisosLectura si se indica
    $opsLibres    operaciones que no piden sesión (las tareas programadas,
                  que se protegen con su propio token)
*/

if (session_status() === PHP_SESSION_NONE) { session_start(); }

//Revisa los permisos antes de atender la petición. Las operaciones de solo lectura pueden pedir otros permisos y las "libres" no piden ninguno
function hanaGuardia($permisos, $opsLectura = array(), $opsLibres = array(), $permisosLectura = null)
{
    $op = isset($_GET['op']) ? $_GET['op'] : '';
    if (in_array($op, $opsLibres, true)) { return; }

    if (!isset($_SESSION['IdUsuarios'])) {
        http_response_code(401);
        echo 'Tu sesión terminó. Vuelve a iniciar sesión.';
        exit;
    }
    $mios = explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : '');
    if (in_array($op, $opsLectura, true)) {
        if ($permisosLectura === null) { return; }
        $permisos = $permisosLectura;
    }
    foreach ((array)$permisos as $p) { if (in_array($p, $mios, true)) { return; } }

    http_response_code(403);
    echo 'No permitido: tu rol no tiene acceso a esta acción.';
    exit;
}

//Para los archivos que no usa ninguna pantalla: se conservan, pero no atienden
//peticiones
function hanaArchivoRetirado()
{
    http_response_code(410);
    echo 'Este archivo ya no se usa. Si llegaste aquí desde una pantalla, avísale al administrador.';
    exit;
}
