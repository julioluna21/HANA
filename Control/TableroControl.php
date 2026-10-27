<?php
/*
  HANA - Controlador del tablero por proyectos (va dentro del Reporte general)
  ---------------------------------------------------------------------------
    resumen  los mosaicos de los proyectos y de sus peajes: solo el director de
             operaciones (43M) o el administrador (24M)
    persona  el reporte de una persona ese día: lo abre también el Reporte general (19M)
    Cada quien ve lo de sus peajes asignados (Configuración → Colaboradores); el ADMIN TEC, todo
*/
session_start();
require_once __DIR__ . "/../Modelo/TableroModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //las respuestas JSON llevan los textos como se escribieron (sin &amp;)
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

//Responde con un código de error y un mensaje en JSON, y corta la ejecución
function tabError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { tabError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!hanaPuede('REPORTE', 'ver')) { tabError(403, 'No tienes permiso para el reporte general.'); }
$opTablero = isset($_GET['op']) ? $_GET['op'] : '';
if ($opTablero === 'resumen' && !hanaPuede('REPORTE', 'tablero')) {
    tabError(403, 'El tablero por proyectos es del director de operaciones.');
}

$idColaborador = (int)$_SESSION['Idcolaborador'];
$verTodos = hanaVeTodo(); //el ADMIN TEC ve todo; los demás, lo de sus peajes asignados
$tablero   = new Tablero();
$hoy = date('Y-m-d');

$fecha = HanaVal::fecha(isset($_GET['fecha']) ? $_GET['fecha'] : $hoy);
if ($fecha === '' || $fecha > $hoy) { $fecha = $hoy; }

$alcance = $tablero->alcance($idColaborador, $verTodos, array_keys(hanaMisCentrosIds()));

//¿Puede abrir el reporte de esta persona? Si trabaja en sus proyectos (los de sus peajes asignados)
$coordino = $alcance['proyectos']; //abre el reporte de quien trabaja en sus proyectos
//¿Quien mira el tablero puede ver a esta persona? Sí, si ve todo, si es ella misma o si coordina su proyecto
function tabPuedeVer($idPersona, $idProyecto, $idColaborador, $verTodos, $coordino)
{
    //$coordino ahora son los proyectos de sus peajes asignados
    return $idPersona > 0 && ($verTodos || $idPersona === $idColaborador || isset($coordino[(int)$idProyecto]));
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //Nivel 1 y 2: los proyectos, cada uno con sus centros y lo del día
    case 'resumen':
        $proyectos = $tablero->proyectos(array_keys($alcance['proyectos']), $fecha);
        $centros = $tablero->centros(array_keys($alcance['centros']), $fecha);
        $porProyecto = array();
        foreach ($centros as $centro) { $porProyecto[(int)$centro['ID_PROYECTO']][] = $centro; }
        foreach ($proyectos as $i => $proyecto) {
            $lista = isset($porProyecto[(int)$proyecto['ID_PROYECTO']]) ? $porProyecto[(int)$proyecto['ID_PROYECTO']] : array();
            $conJefe = 0; $reportaron = 0; $conLista = 0; $vacantes = 0; $arqueos = 0; $arqueosDif = 0;
            foreach ($lista as $indice => $centro) {
                //Los jefes de peaje hacen la lista de chequeo; Hoy en qué estás es del coordinador
                if ($centro['ID_COLABORADOR_JEFE']) { $conJefe++; if ($centro['SITUACION']) { $reportaron++; } }
                if ((int)$centro['LISTAS'] > 0) { $conLista++; }
                $vacantes += (int)$centro['VACANTES'];
                $arqueos += (int)$centro['ARQUEOS']; $arqueosDif += (int)$centro['ARQUEOS_DIF'];
            }
            foreach ($lista as $indice => $centro) {
                $lista[$indice]['PUEDE_VER'] = tabPuedeVer((int)$centro['ID_COLABORADOR_JEFE'], $proyecto['ID_PROYECTO'], $idColaborador, $verTodos, $coordino);
            }
            $proyectos[$i]['COORD_PUEDE_VER'] = tabPuedeVer((int)$proyecto['ID_COLABORADOR_COORDINADOR'], $proyecto['ID_PROYECTO'], $idColaborador, $verTodos, $coordino);
            $proyectos[$i]['CENTROS'] = $lista;
            $proyectos[$i]['RESUMEN'] = array('centros' => count($lista), 'conJefe' => $conJefe, 'reportaron' => $reportaron, 'conLista' => $conLista,
                                         'vacantes' => $vacantes, 'arqueos' => $arqueos, 'arqueosDif' => $arqueosDif);
        }
        echo hanaJsonTexto(array('fecha' => $fecha, 'hoy' => $hoy, 'verTodos' => $verTodos, 'proyectos' => $proyectos), JSON_UNESCAPED_UNICODE);
        break;

    //Nivel 3: el reporte de una persona, con la regla de tabPuedeVer
    case 'persona':
        $idPersonaPedida = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $permitido = ($idPersonaPedida === $idColaborador);
        if (!$permitido) {
            foreach ($tablero->proyectos(array_keys($alcance['proyectos']), $fecha) as $proyecto) {
                if ((int)$proyecto['ID_COLABORADOR_COORDINADOR'] === $idPersonaPedida && tabPuedeVer($idPersonaPedida, $proyecto['ID_PROYECTO'], $idColaborador, $verTodos, $coordino)) { $permitido = true; break; }
            }
        }
        if (!$permitido && count($alcance['centros'])) {
            foreach ($tablero->centros(array_keys($alcance['centros']), $fecha) as $centro) {
                if ((int)$centro['ID_COLABORADOR_JEFE'] === $idPersonaPedida && tabPuedeVer($idPersonaPedida, $centro['ID_PROYECTO'], $idColaborador, $verTodos, $coordino)) { $permitido = true; break; }
            }
        }
        if (!$permitido) { tabError(403, 'Esa persona no está en los proyectos que puedes ver.'); }
        $reporte = $tablero->persona($idPersonaPedida, $fecha, $hoy);
        if (!$reporte) { tabError(404, 'No se encontró a esa persona.'); }
        $reporte['fecha'] = $fecha;
        echo hanaJsonTexto($reporte, JSON_UNESCAPED_UNICODE);
        break;

    default:
        tabError(400, 'Operación no reconocida.');
}
