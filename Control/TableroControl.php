<?php
/*
  HANA - Controlador del tablero del reporte diario
  ---------------------------------------------------------------------------
    19M  entrar al tablero
    20M  ver todos los proyectos (gerente de operaciones). Sin él, cada quien ve lo suyo:
         el coordinador su proyecto, el jefe de peaje su peaje
*/
session_start();
require_once __DIR__ . "/../Modelo/TableroModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //las respuestas JSON llevan los textos como se escribieron (sin &amp;)
require_once __DIR__ . "/../Modelo/PromesaValorModelo.php"; //la promesa de valor y el ejecutado del día de cada peaje
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
if (!hanaTienePermiso(PERMISO_TABLERO)) { tabError(403, 'No tienes permiso para el tablero del reporte diario.'); }

$idColaborador = (int)$_SESSION['Idcolaborador'];
$verTodos = hanaTienePermiso(PERMISO_TABLERO_TODOS);
$tablero   = new Tablero();
$hoy = date('Y-m-d');

$fecha = HanaVal::fecha(isset($_GET['fecha']) ? $_GET['fecha'] : $hoy);
if ($fecha === '' || $fecha > $hoy) { $fecha = $hoy; }

$alcance = $tablero->alcance($idColaborador, $verTodos);

//¿Puede abrir el reporte de esta persona? La jerarquía se mira hacia abajo:
//el gerente de operaciones (20M) ve a todos; el coordinador, a los jefes de su proyecto;
//cada quien se ve a sí mismo. Un jefe de peaje no abre el de su coordinador
$coordino = array();
foreach ($tablero->proyectos(array_keys($alcance['proyectos']), $fecha) as $proyecto) {
    if ((int)$proyecto['ID_COLABORADOR_COORDINADOR'] === $idColaborador) { $coordino[(int)$proyecto['ID_PROYECTO']] = true; }
}
//¿Quien mira el tablero puede ver a esta persona? Sí, si ve todo, si es ella misma o si coordina su proyecto
function tabPuedeVer($idPersona, $idProyecto, $idColaborador, $verTodos, $coordino)
{
    return $idPersona > 0 && ($verTodos || $idPersona === $idColaborador || isset($coordino[(int)$idProyecto]));
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //Nivel 1 y 2: los proyectos, cada uno con sus centros y lo del día
    case 'resumen':
        $proyectos = $tablero->proyectos(array_keys($alcance['proyectos']), $fecha);
        $centros = $tablero->centros(array_keys($alcance['centros']), $fecha);
        //Promesa de valor: [idCentro] = [promesa, ejecutado]. Vacío si el administrador apagó el módulo
        $promesaPorCentro = HanaConfig::modulo('PROMESA') ? (new PromesaValor())->resumenDia(array_keys($alcance['centros']), $fecha) : array();
        $porProyecto = array();
        foreach ($centros as $centro) { $porProyecto[(int)$centro['ID_PROYECTO']][] = $centro; }
        foreach ($proyectos as $i => $proyecto) {
            $lista = isset($porProyecto[(int)$proyecto['ID_PROYECTO']]) ? $porProyecto[(int)$proyecto['ID_PROYECTO']] : array();
            $conJefe = 0; $reportaron = 0; $conLista = 0; $vacantes = 0; $rqPendientes = 0; $arqueos = 0; $arqueosDif = 0;
            $pvConPromesa = 0; $pvListos = 0; $pvDif = 0; //peajes con promesa, los que ya tienen ejecutado y lo que les faltó o sobró
            foreach ($lista as $indice => $centro) {
                $promesaCentro = isset($promesaPorCentro[(int)$centro['ID_CENTRO_OP']]) ? $promesaPorCentro[(int)$centro['ID_CENTRO_OP']] : null;
                $lista[$indice]['PV_PROMESA'] = $promesaCentro ? $promesaCentro['promesa'] : null;       //null: ese peaje no tiene promesa definida
                $lista[$indice]['PV_EJECUTADO'] = $promesaCentro ? $promesaCentro['ejecutado'] : null;   //null: falta registrar el ejecutado
                if ($promesaCentro && $promesaCentro['promesa'] > 0) { $pvConPromesa++; if ($promesaCentro['ejecutado'] !== null) { $pvListos++; $pvDif += $promesaCentro['ejecutado'] - $promesaCentro['promesa']; } }
                //Los jefes de peaje hacen la lista de chequeo; Hoy en qué estás es del coordinador
                if ($centro['ID_COLABORADOR_JEFE']) { $conJefe++; if ($centro['SITUACION']) { $reportaron++; } }
                if ((int)$centro['LISTAS'] > 0) { $conLista++; }
                $vacantes += (int)$centro['VACANTES']; $rqPendientes += (int)$centro['RQ_PENDIENTES'];
                $arqueos += (int)$centro['ARQUEOS']; $arqueosDif += (int)$centro['ARQUEOS_DIF'];
            }
            foreach ($lista as $indice => $centro) {
                $lista[$indice]['PUEDE_VER'] = tabPuedeVer((int)$centro['ID_COLABORADOR_JEFE'], $proyecto['ID_PROYECTO'], $idColaborador, $verTodos, $coordino);
            }
            $proyectos[$i]['COORD_PUEDE_VER'] = tabPuedeVer((int)$proyecto['ID_COLABORADOR_COORDINADOR'], $proyecto['ID_PROYECTO'], $idColaborador, $verTodos, $coordino);
            $proyectos[$i]['CENTROS'] = $lista;
            $proyectos[$i]['RESUMEN'] = array('centros' => count($lista), 'conJefe' => $conJefe, 'reportaron' => $reportaron, 'conLista' => $conLista,
                                         'vacantes' => $vacantes, 'rq' => $rqPendientes, 'arqueos' => $arqueos, 'arqueosDif' => $arqueosDif,
                                         'pvCon' => $pvConPromesa, 'pvListos' => $pvListos, 'pvDif' => $pvDif);
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
