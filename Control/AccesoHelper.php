<?php
/*
  HANA — Quién puede ver qué
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

//Permisos que se marcan en Configuración → Roles
define('PERMISO_VER_TODAS_NOVEDADES', '16M');
define('PERMISO_VER_TODAS_RQ',        '17M');
define('PERMISO_APROBAR_RQ',          '18M'); //aprobar o rechazar RQ (Fase 1)
define('PERMISO_TABLERO',             '19M'); //entrar al tablero del reporte diario (Fase 2)
define('PERMISO_TABLERO_TODOS',       '20M'); //ver todos los proyectos en el tablero (jefe mayor)
define('PERMISO_ADMIN_REPORTE',       '21M'); //administración: consultar todo lo que llenan los coordinadores
define('PERMISO_VER_PROYECTOS',       '22M'); //consultar todos los proyectos (cronograma y ausentismo)
define('PERMISO_AUS_REABRIR',         '23M'); //ausentismo: reabrir un mes cerrado
define('PERMISO_PARAMETROS',          '24M'); //parámetros del sistema y catálogos de ausentismo

//Rol del usuario que inició sesión. La sesión no lo guardaba, así que se
//consulta una vez y queda guardado en la sesión
function hanaIdRol()
{
    if (isset($_SESSION['IdRol'])) { return (int)$_SESSION['IdRol']; }
    $idUsuario = isset($_SESSION['IdUsuarios']) ? intval($_SESSION['IdUsuarios']) : 0;
    $fila = ejecutarConsultaSimpleFila("SELECT ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA AS rol
                                          FROM usuarios_sistema
                                         WHERE ID_USUARIO_SISTEMA = $idUsuario LIMIT 1");
    $rol = $fila ? (int)$fila['rol'] : 0;
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
// Novedades
//---------------------------------------------------------------------------

//Condición SQL sobre la tabla novedades_hallazgos
function hanaCondNovedades($vista)
{
    $col = isset($_SESSION['Idcolaborador']) ? intval($_SESSION['Idcolaborador']) : 0;
    $mias = "(novedades_hallazgos.ID_COLABORADOR_NOVEDADES_HALLAZGOS = $col
              OR novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS = $col)";

    if (hanaVista($vista) === 'mias') { return $mias; }
    if (hanaTienePermiso(PERMISO_VER_TODAS_NOVEDADES)) { return '1 = 1'; }

    $rol = hanaIdRol();
    return "($mias OR novedades_hallazgos.ID_ROL_DESTINO = $rol)";
}

//¿Puede abrir esta novedad concreta? Se usa al mostrar el detalle, para que
//nadie la abra escribiendo su número a mano
function hanaPuedeVerNovedad($idNovedad)
{
    $id = intval($idNovedad);
    $fila = ejecutarConsultaSimpleFila("SELECT 1 AS ok FROM novedades_hallazgos
                                          WHERE ID_NOVEDADES_HALLAZGOS = $id
                                            AND " . hanaCondNovedades('todas') . " LIMIT 1");
    return (bool)$fila;
}

//---------------------------------------------------------------------------
// RQ
//---------------------------------------------------------------------------

//Condición SQL sobre la tabla rq (con el alias que use la consulta)
function hanaCondRQ($vista, $alias = 'r')
{
    $col = isset($_SESSION['Idcolaborador']) ? intval($_SESSION['Idcolaborador']) : 0;
    $mias = "($alias.ID_COLABORADOR_SOLICITA = $col)";

    if (hanaVista($vista) === 'mias') { return $mias; }
    //Quien aprueba tiene que poder ver todas: no se aprueba lo que no se ve
    if (hanaTienePermiso(PERMISO_VER_TODAS_RQ) || hanaTienePermiso(PERMISO_APROBAR_RQ)) { return '1 = 1'; }

    //Las RQ viejas pueden tener un rol destino; las nuevas ya no lo llevan
    $rol = hanaIdRol();
    return "($mias OR $alias.ID_ROL_DESTINO = $rol)";
}

//---------------------------------------------------------------------------
// Roles disponibles para el selector "Rol a notificar"
//---------------------------------------------------------------------------
function hanaRolesActivos()
{
    return ejecutarConsulta("SELECT ID_ROL_USUARIO_SISTEMA AS id, NOM_ROL_USUARIO_SISTEMA AS nombre
                               FROM rol_usuarios_sistemas
                              WHERE ESTADO = 1
                              ORDER BY NOM_ROL_USUARIO_SISTEMA");
}

//¿Existe ese rol y está activo? Evita guardar un rol inventado
function hanaRolValido($idRol)
{
    $id = intval($idRol);
    if ($id <= 0) { return false; }
    $fila = ejecutarConsultaSimpleFila("SELECT 1 AS ok FROM rol_usuarios_sistemas
                                          WHERE ID_ROL_USUARIO_SISTEMA = $id AND ESTADO = 1 LIMIT 1");
    return (bool)$fila;
}
