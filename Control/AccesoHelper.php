<?php
/*
  HANA - Quién puede ver qué
  ---------------------------------------------------------------------------
  Una sola regla para novedades, RQ y la campana. Si mañana cambia la regla,
  se cambia aquí y aplica en todas partes a la vez.

  LA REGLA
    Vista "mias" (Mis registros):
        Novedades: las que la persona creó y las que tiene asignadas.
        RQ:        las que la persona solicitó.

    Vista "todas" (Todos los que puedo ver):
        Si el rol tiene el permiso de ver todas (16M novedades, 17M RQ):
            absolutamente todas.
        Si no:
            las suyas (lo de arriba) MÁS las dirigidas a su rol.

  Así, un rol no ve lo dirigido a otro rol, salvo lo que él mismo creó o
  tiene asignado. Y quien tiene el permiso ve todo.

  La discriminación se hace aquí, en el servidor. La pantalla solo elige la
  vista: aunque alguien manipule la página, el servidor nunca le entrega lo que
  su rol no puede ver.
*/

require_once __DIR__ . '/../Conexion/ConexionDB.php';
require_once __DIR__ . '/../Modelo/AccesoModelo.php';       //las consultas de esta regla viven en el modelo
require_once __DIR__ . '/../Modelo/OrganizacionModelo.php';
require_once __DIR__ . '/../Modelo/HanaConfig.php';
require_once __DIR__ . '/../Modelo/HanaFechas.php';


//Casillas que se marcan en Configuración → Permisos de usuario
define('PERMISO_VER_TODAS_NOVEDADES', '16M');
define('PERMISO_VER_TODAS_RQ',        '17M');
define('PERMISO_APROBAR_RQ',          '18M'); //aprobar o rechazar RQ
define('PERMISO_TABLERO',             '19M'); //entrar al tablero del reporte diario
define('PERMISO_TABLERO_TODOS',       '20M'); //ver todos los proyectos en el tablero (gerente de operaciones)
define('PERMISO_ADMIN_REPORTE',       '21M'); //administración: consultar todo lo que llenan los coordinadores
define('PERMISO_VER_PROYECTOS',       '22M'); //consultar todos los proyectos (cronograma y ausentismo)
define('PERMISO_AUS_REABRIR',         '23M'); //ausentismo: reabrir un mes cerrado
define('PERMISO_PARAMETROS',          '24M'); //parámetros del sistema; además es el control total del ADMIN TEC:
                                                //crea, corrige y anula en todos los módulos y proyectos
define('PERMISO_PEDIR_RQ',            '41M'); //pedir RQ en sus peajes
define('PERMISO_ASIGNAR_NOVEDAD',     '42M'); //asignar una novedad a una persona

//La persona que tiene la sesión
function hanaIdColaborador() { return isset($_SESSION['Idcolaborador']) ? (int)$_SESSION['Idcolaborador'] : 0; }
//El usuario que inició sesión. El usuario vive en la misma fila de la persona
//(colaboradores), así que es el mismo número que hanaIdColaborador()
function hanaIdUsuario()     { return hanaIdColaborador(); }

//Rol del usuario que inició sesión. La sesión no lo guardaba, así que se
//consulta una vez y queda guardado en la sesión
function hanaIdRol()
{
    if (isset($_SESSION['IdRol'])) { return (int)$_SESSION['IdRol']; }
    $rol = Acceso::rolDeUsuario(hanaIdUsuario());
    $_SESSION['IdRol'] = $rol;
    return $rol;
}

//¿El rol del usuario tiene este permiso?
function hanaTienePermiso($codigo)
{
    $modulos = isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : '';
    return in_array($codigo, explode(',', $modulos));
}

//Solo se aceptan dos valores; cualquier otra cosa es la vista personal,
//que es la más restrictiva
function hanaVista($valor)
{
    return ($valor === 'todas') ? 'todas' : 'mias';
}

//---------------------------------------------------------------------------
// El reporte del coordinador. Lo llena quien coordina un proyecto. El ADMIN
// TEC (24M) puede usar esas pantallas en todos los proyectos, y cada pantalla
// tiene su casilla en Permisos de usuario: quien la tenga la usa en todos los proyectos.
// La pantalla dice a qué módulo pertenece con hanaUsarModulo('ARQUEOS')
//---------------------------------------------------------------------------
function hanaPermisosModulo()
{
    return array(
        'HOY' => '28M', 'ARQUEOS' => '29M', 'CRONOGRAMA' => '30M', 'VACANTES' => '31M', 'COMUNICACIONES' => '32M'
        //33M, 34M y 35M quedan libres, y también 38M, 39M y 40M (eran de la promesa de valor, que se quitó)
    );
}

//Deja anotado en qué módulo está trabajando la pantalla, para revisar los permisos de ese módulo
function hanaUsarModulo($modulo) { $GLOBALS['hanaModuloActual'] = (string)$modulo; }

//¿La persona de la sesión usa la pantalla del módulo actual en TODOS los proyectos?
//(solo aplica a ella misma: no cambia cómo se ve a los demás en el tablero o en los reportes)
function hanaVeTodo($idColaborador = null)
{
    $id = $idColaborador === null ? hanaIdColaborador() : (int)$idColaborador;
    if ($id <= 0 || $id !== hanaIdColaborador() || !isset($_SESSION['Modulos'])) { return false; }
    if (hanaTienePermiso('24M')) { return true; }
    $modulo = isset($GLOBALS['hanaModuloActual']) ? $GLOBALS['hanaModuloActual'] : '';
    $casillas = hanaPermisosModulo();
    return $modulo !== '' && isset($casillas[$modulo]) && hanaTienePermiso($casillas[$modulo]);
}

//¿Esta persona coordina algún proyecto? (si no se pasa nadie, se pregunta por quien tiene la sesión)
function hanaEsCoordinador($idColaborador = null)
{
    $id = $idColaborador === null ? hanaIdColaborador() : (int)$idColaborador;
    return HanaDB::esCoordinador($id, hanaVeTodo($id));
}

//Los proyectos que coordina la persona (o todos, si su rol ve todo)
function hanaProyectosCoordinados($idColaborador = null)
{
    $id = $idColaborador === null ? hanaIdColaborador() : (int)$idColaborador;
    return HanaDB::proyectosCoordinados($id, hanaVeTodo($id));
}

//Los peajes de los proyectos que coordina la persona
function hanaCentrosCoordinador($idColaborador = null)
{
    $id = $idColaborador === null ? hanaIdColaborador() : (int)$idColaborador;
    return HanaDB::centrosCoordinador($id, hanaVeTodo($id));
}

//¿La persona de la sesión puede usar la pantalla de ese módulo? (coordinador, ADMIN TEC o su casilla)
function hanaPuedeModulo($modulo)
{
    if (hanaIdColaborador() <= 0) { return false; }
    $antes = isset($GLOBALS['hanaModuloActual']) ? $GLOBALS['hanaModuloActual'] : '';
    hanaUsarModulo($modulo);
    $esCoordinador = hanaEsCoordinador();
    hanaUsarModulo($antes);
    return $esCoordinador;
}

//¿Ese día lo puede registrar o corregir la persona de la sesión? (hoy, o un día habilitado)
function hanaEnVentana($fecha) { return HanaFechas::enVentana($fecha, hanaIdColaborador()); }

//---------------------------------------------------------------------------
// Novedades
//---------------------------------------------------------------------------

//Condición SQL sobre la tabla novedades_hallazgos (la arma el modelo)
function hanaCondNovedades($vista)
{
    return Acceso::condNovedades(hanaVista($vista), hanaIdColaborador(), hanaTienePermiso(PERMISO_VER_TODAS_NOVEDADES), hanaIdRol());
}

//¿Puede abrir esta novedad concreta? Se usa al mostrar el detalle, para que
//nadie la abra escribiendo su número a mano
function hanaPuedeVerNovedad($idNovedad)
{
    return Acceso::puedeVerNovedad($idNovedad, hanaCondNovedades('todas'));
}

//---------------------------------------------------------------------------
// RQ
//---------------------------------------------------------------------------

//Condición SQL sobre la tabla rq (con el alias que use la consulta).
//Quien aprueba tiene que poder ver todas: no se aprueba lo que no se ve
function hanaCondRQ($vista, $alias = 'r')
{
    return Acceso::condRQ(hanaVista($vista), hanaIdColaborador(),
                          hanaTienePermiso(PERMISO_VER_TODAS_RQ) || hanaTienePermiso(PERMISO_APROBAR_RQ), $alias);
}

//---------------------------------------------------------------------------
// Roles disponibles para el selector "Rol a notificar"
//---------------------------------------------------------------------------
function hanaRolesActivos() { return Acceso::rolesActivos(); }

//¿Existe ese rol y está activo? Evita guardar un rol inventado
function hanaRolValido($idRol) { return Acceso::rolValido($idRol); }
