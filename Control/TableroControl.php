<?php
/*
  HANA — Controlador del tablero del reporte diario (Fase 2)
    19M  entrar al tablero
    20M  ver todos los proyectos (jefe mayor). Sin él, cada quien ve lo suyo:
         el coordinador su proyecto, el jefe de peaje su peaje
*/
session_start();
require_once __DIR__ . "/../Modelo/TableroModelo.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

function tabError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { tabError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!hanaTienePermiso(PERMISO_TABLERO)) { tabError(403, 'No tienes permiso para el tablero del reporte diario.'); }

$idColaborador = (int)$_SESSION['Idcolaborador'];
$verTodos = hanaTienePermiso(PERMISO_TABLERO_TODOS);
$T   = new Tablero();
$hoy = date('Y-m-d');

$fecha = HanaVal::fecha(isset($_GET['fecha']) ? $_GET['fecha'] : $hoy);
if ($fecha === '' || $fecha > $hoy) { $fecha = $hoy; }

$alcance = $T->alcance($idColaborador, $verTodos);

//¿Puede abrir el reporte de esta persona? La jerarquía se mira hacia abajo:
//el jefe mayor (20M) ve a todos; el coordinador, a los jefes de su proyecto;
//cada quien se ve a sí mismo. Un jefe de peaje no abre el de su coordinador
$coordino = array();
foreach ($T->proyectos(array_keys($alcance['proyectos']), $fecha) as $p) {
    if ((int)$p['ID_COLABORADOR_COORDINADOR'] === $idColaborador) { $coordino[(int)$p['ID_PROYECTO']] = true; }
}
function tabPuedeVer($idPersona, $idProyecto, $idColaborador, $verTodos, $coordino)
{
    return $idPersona > 0 && ($verTodos || $idPersona === $idColaborador || isset($coordino[(int)$idProyecto]));
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //Nivel 1 y 2: los proyectos, cada uno con sus centros y lo del día
    case 'resumen':
        $proy = $T->proyectos(array_keys($alcance['proyectos']), $fecha);
        $cent = $T->centros(array_keys($alcance['centros']), $fecha);
        $porProyecto = array();
        foreach ($cent as $c) { $porProyecto[(int)$c['ID_PROYECTO']][] = $c; }
        foreach ($proy as $i => $p) {
            $lista = isset($porProyecto[(int)$p['ID_PROYECTO']]) ? $porProyecto[(int)$p['ID_PROYECTO']] : array();
            $conJefe = 0; $reportaron = 0; $conLista = 0; $vac = 0; $rq = 0; $arq = 0; $arqDif = 0;
            foreach ($lista as $c) {
                //Los jefes de peaje hacen la lista de chequeo; Hoy en qué estás es del coordinador
                if ($c['ID_COLABORADOR_JEFE']) { $conJefe++; if ($c['SITUACION']) { $reportaron++; } }
                if ((int)$c['LISTAS'] > 0) { $conLista++; }
                $vac += (int)$c['VACANTES']; $rq += (int)$c['RQ_PENDIENTES'];
                $arq += (int)$c['ARQUEOS']; $arqDif += (int)$c['ARQUEOS_DIF'];
            }
            foreach ($lista as $k => $c) {
                $lista[$k]['PUEDE_VER'] = tabPuedeVer((int)$c['ID_COLABORADOR_JEFE'], $p['ID_PROYECTO'], $idColaborador, $verTodos, $coordino);
            }
            $proy[$i]['COORD_PUEDE_VER'] = tabPuedeVer((int)$p['ID_COLABORADOR_COORDINADOR'], $p['ID_PROYECTO'], $idColaborador, $verTodos, $coordino);
            $proy[$i]['CENTROS'] = $lista;
            $proy[$i]['RESUMEN'] = array('centros' => count($lista), 'conJefe' => $conJefe, 'reportaron' => $reportaron, 'conLista' => $conLista,
                                         'vacantes' => $vac, 'rq' => $rq, 'arqueos' => $arq, 'arqueosDif' => $arqDif);
        }
        echo json_encode(array('fecha' => $fecha, 'hoy' => $hoy, 'verTodos' => $verTodos, 'proyectos' => $proy), JSON_UNESCAPED_UNICODE);
        break;

    //Nivel 3: el reporte de una persona, con la regla de tabPuedeVer
    case 'persona':
        $idP = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $permitido = ($idP === $idColaborador);
        if (!$permitido) {
            foreach ($T->proyectos(array_keys($alcance['proyectos']), $fecha) as $p) {
                if ((int)$p['ID_COLABORADOR_COORDINADOR'] === $idP && tabPuedeVer($idP, $p['ID_PROYECTO'], $idColaborador, $verTodos, $coordino)) { $permitido = true; break; }
            }
        }
        if (!$permitido && count($alcance['centros'])) {
            foreach ($T->centros(array_keys($alcance['centros']), $fecha) as $c) {
                if ((int)$c['ID_COLABORADOR_JEFE'] === $idP && tabPuedeVer($idP, $c['ID_PROYECTO'], $idColaborador, $verTodos, $coordino)) { $permitido = true; break; }
            }
        }
        if (!$permitido) { tabError(403, 'Esa persona no está en los proyectos que puedes ver.'); }
        $r = $T->persona($idP, $fecha, $hoy);
        if (!$r) { tabError(404, 'No se encontró a esa persona.'); }
        $r['fecha'] = $fecha;
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        break;

    default:
        tabError(400, 'Operación no reconocida.');
}
