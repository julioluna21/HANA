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
define('PERMISO_TABLERO',             '19M'); //entrar al reporte general (el coordinador ve su proyecto)
define('PERMISO_TABLERO_TODOS',       '20M'); //RESERVADO/retirado: el alcance (qué proyectos) ya no sale de esta casilla,
                                                //sino de los peajes asignados + lo que coordina + el control total (24M). No se reutiliza.
define('PERMISO_TABLERO_PROYECTOS',   '43M'); //el tablero por proyectos dentro del reporte general (director de operaciones)
define('PERMISO_ADMIN_REPORTE',       '21M'); //consultar lo que llenan todos (listas, cronograma y ausentismo). Su pantalla de Administración se quitó: lo mismo está en el Reporte general
define('PERMISO_VER_PROYECTOS',       '22M'); //RESERVADO/retirado: igual que 20M, el alcance sale de los peajes asignados. No se reutiliza.
define('PERMISO_AUS_REABRIR',         '23M'); //ausentismo: reabrir un mes cerrado
define('PERMISO_PARAMETROS',          '24M'); //parámetros del sistema; además es el control total del ADMIN TEC:
                                                //crea, corrige y anula en todos los módulos y proyectos
define('PERMISO_PEDIR_RQ',            '41M'); //pedir RQ en sus peajes
define('PERMISO_ASIGNAR_NOVEDAD',     '42M'); //asignar una novedad a una persona
//Reporte diario: consultar. Solo ver, nunca llenar ni corregir (el director de operaciones)
define('PERMISO_HOY_CONSULTAR',       '33M'); //ver lo que registra cada coordinador en Hoy en qué estás
define('PERMISO_CRONO_CONSULTAR',     '34M'); //ver el cronograma y el vehículo de todos los proyectos

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
// QUÉ puede hacer cada quien y DÓNDE
//
//   QUÉ: las casillas de su rol (Configuración → Permisos de usuario). Cada módulo
//        tiene casillas separadas: ver, llenar y, donde aplica, editar o acciones
//        especiales (anular lo de otros, vehículos, fondos...). Ninguna casilla dice
//        "en todos los proyectos".
//   DÓNDE: los peajes (centros de operación) asignados a la persona en
//        Configuración → Usuarios. Si tiene solo los de Accenorte, ve y llena solo
//        Accenorte; si tiene todos, ve todos. El ADMIN TEC (24M) ve todos siempre,
//        para no quedarse por fuera de un peaje nuevo.
//
//   Ser el coordinador de un proyecto (Configuración → Proyectos) ya no da acceso
//   por sí solo: dice quién es el coordinador (correos, reportes, de quién es el
//   cronograma del proyecto).
//---------------------------------------------------------------------------

//Las casillas de cada módulo. Si mañana se agrega una acción, se agrega aquí y en
//Vista/RolesVista.php
function hanaCasillas()
{
    return array(
        'HOY'            => array('llenar' => '28M', 'ver' => '33M'),
        'LISTAS'         => array('llenar' => '11M', 'ver' => '21M', 'editar' => '12M', 'correos' => '44M'),
        'ARQUEOS'        => array('llenar' => '29M', 'ver' => '45M', 'fondos' => '36M', 'anular' => '46M'),
        'CRONOGRAMA'     => array('llenar' => '30M', 'ver' => '34M', 'vehiculos' => '47M'),
        'VACANTES'       => array('llenar' => '31M', 'ver' => '48M', 'compromiso' => '49M'),
        'COMUNICACIONES' => array('llenar' => '32M', 'ver' => '50M', 'otros' => '51M'),
        'AUSENTISMO'     => array('llenar' => '52M', 'ver' => '53M', 'cerrar' => '58M', 'cerrado' => '25M', 'reabrir' => '23M'),
        'PROMESA'        => array('llenar' => '54M', 'ver' => '55M', 'pasados' => '56M'),
        'REPORTE'        => array('ver' => '19M', 'tablero' => '43M', 'informe' => '37M'),
        'TAREAS'         => array('editar' => '57M')
    );
}

//¿La persona de la sesión puede hacer eso en ese módulo? El ADMIN TEC (24M) puede todo
function hanaPuede($modulo, $accion)
{
    if (hanaIdColaborador() <= 0) { return false; }
    if (hanaTienePermiso('24M')) { return true; }
    $casillas = hanaCasillas();
    return isset($casillas[$modulo][$accion]) && hanaTienePermiso($casillas[$modulo][$accion]);
}

//¿Tiene alguna casilla de ese módulo? (para el menú y para abrir la pantalla)
function hanaEntra($modulo)
{
    if (hanaIdColaborador() <= 0) { return false; }
    $casillas = hanaCasillas();
    if (!isset($casillas[$modulo])) { return false; }
    foreach (array_keys($casillas[$modulo]) as $accion) { if (hanaPuede($modulo, $accion)) { return true; } }
    return false;
}

//¿Las casillas de ese módulo son de un rol? (para recordatorios: personas que no tienen la sesión)
function hanaRolPuede($permisosRol, $modulo, $accion)
{
    $lista = is_array($permisosRol) ? $permisosRol : explode(',', (string)$permisosRol);
    if (in_array('24M', $lista, true)) { return true; }
    $casillas = hanaCasillas();
    return isset($casillas[$modulo][$accion]) && in_array($casillas[$modulo][$accion], $lista, true);
}

//Los peajes donde trabaja la persona: los asignados en Colaboradores. La de la sesión con 24M: todos.
//Cada uno con ID_CENTRO_OP, NOM_CENTRO_OP, TIPO_CENTRO, ID_PROYECTO y NOM_PROYECTO
function hanaMisCentros($idColaborador = null)
{
    static $cache = array();
    $id = $idColaborador === null ? hanaIdColaborador() : (int)$idColaborador;
    if ($id <= 0) { return array(); }
    $todos = $id === hanaIdColaborador() && hanaTienePermiso('24M');
    $llave = $id . ($todos ? 't' : '');
    if (!isset($cache[$llave])) { $cache[$llave] = $todos ? HanaDB::centrosTodos() : HanaDB::centrosUsuario($id); }
    return $cache[$llave];
}

//Los números de esos peajes, como llave: [idCentro] = true
function hanaMisCentrosIds($idColaborador = null)
{
    $ids = array();
    foreach (hanaMisCentros($idColaborador) as $centro) { $ids[(int)$centro['ID_CENTRO_OP']] = true; }
    return $ids;
}

//Los proyectos de esos peajes, sin repetir: [idProyecto] = fila (ID_PROYECTO, NOM_PROYECTO)
function hanaMisProyectos($idColaborador = null)
{
    $proyectos = array();
    foreach (hanaMisCentros($idColaborador) as $centro) {
        $proyectos[(int)$centro['ID_PROYECTO']] = array('ID_PROYECTO' => (int)$centro['ID_PROYECTO'], 'NOM_PROYECTO' => $centro['NOM_PROYECTO']);
    }
    return $proyectos;
}

//Condición SQL "este campo es uno de mis peajes" (1 = 1 para el ADMIN TEC; 0 = 1 si no tiene ninguno)
function hanaSqlMisCentros($campo)
{
    if (hanaTienePermiso('24M')) { return '1 = 1'; }
    $ids = array_keys(hanaMisCentrosIds());
    return count($ids) ? $campo . ' IN (' . implode(',', array_map('intval', $ids)) . ')' : '0 = 1';
}

//---------------------------------------------------------------------------
// Compatibilidad: estas funciones ya las usaban las pantallas del reporte diario.
// Ahora siguen la regla de arriba (casillas + peajes asignados)
//---------------------------------------------------------------------------
function hanaPermisosModulo()
{
    $lista = array();
    foreach (array('HOY', 'ARQUEOS', 'CRONOGRAMA', 'VACANTES', 'COMUNICACIONES') as $modulo) { $lista[$modulo] = hanaCasillas()[$modulo]['llenar']; }
    return $lista;
}

//Deja anotado en qué módulo está trabajando la pantalla
function hanaUsarModulo($modulo) { $GLOBALS['hanaModuloActual'] = (string)$modulo; }
function hanaModuloActual() { return isset($GLOBALS['hanaModuloActual']) ? $GLOBALS['hanaModuloActual'] : ''; }

//Solo el ADMIN TEC ve todos los peajes sin tenerlos asignados
function hanaVeTodo($idColaborador = null)
{
    $id = $idColaborador === null ? hanaIdColaborador() : (int)$idColaborador;
    return $id > 0 && $id === hanaIdColaborador() && hanaTienePermiso('24M');
}

//¿La persona de la sesión llena el módulo actual? (su casilla de llenar y al menos un peaje).
//Si se pregunta por otra persona, dice si coordina algún proyecto (es un dato, no un permiso)
function hanaEsCoordinador($idColaborador = null)
{
    $id = $idColaborador === null ? hanaIdColaborador() : (int)$idColaborador;
    if ($id !== hanaIdColaborador()) { return HanaDB::esCoordinador($id, false); }
    $modulo = hanaModuloActual();
    return $modulo !== '' && hanaPuede($modulo, 'llenar') && count(hanaMisCentros()) > 0;
}

//Los proyectos de la persona (los de sus peajes asignados)
function hanaProyectosCoordinados($idColaborador = null)
{
    return array_values(hanaMisProyectos($idColaborador));
}

//Los peajes de la persona (los asignados; el ADMIN TEC de la sesión, todos)
function hanaCentrosCoordinador($idColaborador = null)
{
    return hanaMisCentros($idColaborador);
}

//¿Puede abrir la pantalla de ese módulo? (alguna casilla del módulo y al menos un peaje)
function hanaPuedeModulo($modulo)
{
    return hanaEntra($modulo) && count(hanaMisCentros()) > 0;
}

//---------------------------------------------------------------------------
// Promesa de valor (horas por peaje, cargo y día)
//   Ver (55M) y llenar (54M) en sus peajes. Corregir días pasados, fuera del
//   plazo del parámetro (56M). Lo decide hanaPromesaDesde() por peaje.
//---------------------------------------------------------------------------
function hanaPromesaVeTodo() { return hanaTienePermiso('24M'); }

//¿Puede abrir la pantalla? Con ver o llenar, peajes y el módulo encendido
function hanaPromesaEntrar()
{
    return HanaConfig::modulo('PROMESA') && (hanaPuede('PROMESA', 'ver') || hanaPuede('PROMESA', 'llenar')) && count(hanaMisCentros()) > 0;
}

//Los proyectos que ve (los de sus peajes). PROPIO: puede llenar en alguno de sus peajes de ese proyecto
function hanaPromesaProyectos()
{
    $llena = hanaPuede('PROMESA', 'llenar');
    $lista = array();
    foreach (hanaMisProyectos() as $proyecto) { $proyecto['PROPIO'] = $llena; $lista[] = $proyecto; }
    return $lista;
}

//Desde qué día puede escribir en un peaje (null = no puede escribir).
//Con 56M, cualquier día; con 54M, desde hoy menos los días del parámetro. Solo en sus peajes
function hanaPromesaDesde($idProyecto, $idCentro = 0)
{
    if (!hanaPuede('PROMESA', 'llenar')) { return null; }
    $mios = hanaMisCentrosIds();
    if ((int)$idCentro > 0 && !isset($mios[(int)$idCentro])) { return null; }
    if ((int)$idCentro <= 0 && !isset(hanaMisProyectos()[(int)$idProyecto])) { return null; }
    if (hanaPuede('PROMESA', 'pasados')) { return '2000-01-01'; }
    $dias = HanaConfig::num('PROMESA_DIAS_CORREGIBLES', 7);
    return date('Y-m-d', strtotime(HanaFechas::hoy() . ' -' . max(0, $dias) . ' day'));
}

//¿Ese día lo puede registrar o corregir la persona de la sesión? (hoy, o un día habilitado)
function hanaEnVentana($fecha) { return HanaFechas::enVentana($fecha, hanaIdColaborador()); }

//---------------------------------------------------------------------------
// Novedades
//---------------------------------------------------------------------------

//Condición SQL sobre la tabla novedades_hallazgos (la arma el modelo)
function hanaCondNovedades($vista)
{
    return Acceso::condNovedades(hanaVista($vista), hanaIdColaborador(), hanaTienePermiso(PERMISO_VER_TODAS_NOVEDADES) || hanaTienePermiso('24M'), hanaIdRol(),
                                 hanaSqlMisCentros('novedades_hallazgos.ID_CENTRO_OP'));
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
                          hanaTienePermiso(PERMISO_VER_TODAS_RQ) || hanaTienePermiso(PERMISO_APROBAR_RQ) || hanaTienePermiso('24M'), $alias,
                          'hanaSqlMisCentros');
}

//---------------------------------------------------------------------------
// Roles disponibles para el selector "Rol a notificar"
//---------------------------------------------------------------------------
function hanaRolesActivos() { return Acceso::rolesActivos(); }

//¿Existe ese rol y está activo? Evita guardar un rol inventado
function hanaRolValido($idRol) { return Acceso::rolValido($idRol); }
