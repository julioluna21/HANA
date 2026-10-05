<?php
/*
  HANA — Reporte general del día (control del reporte diario)
  Permiso 19M (el del tablero). Con 20M se ve todo; sin él, cada quien ve lo
  que tiene a cargo: el coordinador su proyecto y el jefe de peaje lo suyo.
*/
session_start();
require_once __DIR__ . "/../Modelo/ControlDiarioModelo.php";
require_once __DIR__ . "/../Modelo/EstadoDiaModelo.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

function cdError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { cdError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!hanaTienePermiso(PERMISO_TABLERO)) { cdError(403, 'No tienes permiso para el reporte general.'); }

$idColaborador = (int)$_SESSION['Idcolaborador'];
$verTodos = hanaTienePermiso(PERMISO_TABLERO_TODOS);
$hoy = date('Y-m-d');
$fecha = HanaVal::fecha(isset($_GET['fecha']) ? $_GET['fecha'] : $hoy);
if ($fecha === '') { $fecha = $hoy; }
$ultimo = date('Y-m-d', strtotime('+1 day')); //mañana ya se puede adelantar
if ($fecha > $ultimo) { $fecha = $ultimo; }

$T  = new Tablero();
$CD = new ControlDiario();
$alcance = $T->alcance($idColaborador, $verTodos);

//Filtro opcional por proyecto, siempre dentro del alcance
$filtro = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
$idsProy = array_keys($alcance['proyectos']);
if ($filtro > 0) { $idsProy = in_array($filtro, $idsProy) ? array($filtro) : array(); }

$proyectos = $T->proyectos($idsProy, $fecha);
$centrosTodos = $T->centros(array_keys($alcance['centros']), $fecha);
$centros = array();
foreach ($centrosTodos as $c) { if (in_array((int)$c['ID_PROYECTO'], $idsProy)) { $c['NOM_PROYECTO'] = ''; $centros[] = $c; } }
$nomProy = array();
foreach ($proyectos as $p) { $nomProy[(int)$p['ID_PROYECTO']] = $p['NOM_PROYECTO']; }
foreach ($centros as $i => $c) { $centros[$i]['NOM_PROYECTO'] = isset($nomProy[(int)$c['ID_PROYECTO']]) ? $nomProy[(int)$c['ID_PROYECTO']] : ''; }
$idsCentros = array_map(function ($c) { return (int)$c['ID_CENTRO_OP']; }, $centros);

//La jerarquía hacia abajo: 20M ve a todos; el coordinador, a los de su
//proyecto; cada quien se ve a sí mismo
$coordino = array();
foreach ($proyectos as $p) { if ((int)$p['ID_COLABORADOR_COORDINADOR'] === $idColaborador) { $coordino[(int)$p['ID_PROYECTO']] = true; } }
$puedeVer = function ($idPersona, $idProyecto) use ($verTodos, $idColaborador, $coordino) {
    return $idPersona > 0 && ($verTodos || $idPersona === $idColaborador || isset($coordino[(int)$idProyecto]));
};

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'dia':
        $personas = $CD->personas($proyectos, $centros, $puedeVer);
        $hoyReg = $CD->hoy(array_keys($personas), $fecha);

        //Hoy en qué estás: uno por persona, con quién falta
        $hoyFilas = array();
        foreach ($personas as $id => $p) {
            $r = isset($hoyReg[$id]) ? $hoyReg[$id] : null;
            $hoyFilas[] = array('ID' => $id, 'NOMBRE' => $p['NOMBRE'], 'ROLES' => $p['ROLES'], 'PROYECTOS' => $p['PROYECTOS'], 'REGISTRO' => $r);
        }

        $vacantes = (new Vacante())->listar($idsCentros, '', '', true, $hoy);
        $comunic  = (new Comunicacion())->listar($idsProy, '', '', true, $hoy);

        //Lista de proyectos para el filtro (siempre todos los del alcance)
        $filtroLista = array();
        foreach ($T->proyectos(array_keys($alcance['proyectos']), $fecha) as $p) { $filtroLista[] = array('id' => (int)$p['ID_PROYECTO'], 'nombre' => $p['NOM_PROYECTO']); }

        echo json_encode(array(
            'fecha'      => $fecha,
            'hoy'        => $hoy,
            'ultimo'     => $ultimo,
            'verTodos'   => $verTodos,
            'proyectos'  => $filtroLista,
            'hoyEnQue'   => $hoyFilas,
            'listas'     => $CD->listas($idsCentros, $fecha),
            'arqueos'    => $CD->arqueos($idsCentros, $fecha),
            'cronograma' => $CD->cronograma($personas, $fecha, $hoy),
            //Quién abrió el cronograma ese día: [idColaborador => hora]
            'revisionCrono' => (object)(new EstadoDia())->revisaron(array_keys($personas), 'CRONOGRAMA', $fecha),
            'vehiculos'  => $CD->vehiculos(array_keys($personas), $idsProy, $fecha),
            'vacantes'   => $vacantes,
            'comunicaciones' => $comunic,
            'rq'         => $CD->rqPendientes($idsCentros),
            //Quien tiene 18M aprueba o rechaza las RQ desde aquí mismo
            'puedeAprobar' => hanaTienePermiso('18M'),
            //Ausencias del día por proyecto (para la pestaña de vacantes)
            'ausentismoDia' => (object)$CD->ausentismoDia($idsCentros, $fecha),
            //Cada centro con su jefe y si hizo la lista de chequeo ese día
            'centros'    => array_map(function ($c) {
                return array('NOM_CENTRO_OP' => $c['NOM_CENTRO_OP'], 'NOM_PROYECTO' => $c['NOM_PROYECTO'], 'TIPO_CENTRO' => $c['TIPO_CENTRO'],
                             'JEFE' => $c['JEFE'], 'LISTAS' => (int)$c['LISTAS'], 'RQ_PENDIENTES' => (int)$c['RQ_PENDIENTES']);
            }, $centros)
        ), JSON_UNESCAPED_UNICODE);
        break;

    //Las respuestas de una lista de chequeo. Solo de un centro que esta
    //persona ve (su alcance); con 20M, de cualquiera
    case 'lista':
        $l = $CD->listaRespuestas(isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if (!$l || (!$verTodos && !isset($alcance['centros'][(int)$l['ID_CENTRO']]))) {
            cdError(404, 'No se encontró la lista, o no es de los centros que tienes a cargo.');
        }
        echo json_encode($l, JSON_UNESCAPED_UNICODE);
        break;

    default:
        cdError(400, 'Operación no reconocida.');
}
