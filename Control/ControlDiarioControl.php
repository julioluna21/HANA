<?php
/*
  HANA - Reporte general del día (control del reporte diario)
  Permiso 19M (el del tablero). Cada quien ve lo de sus peajes asignados
  (Configuración → Usuarios); el ADMIN TEC, todo.
*/
session_start();
require_once __DIR__ . "/../Modelo/ControlDiarioModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //las respuestas JSON llevan los textos como se escribieron (sin &amp;)
require_once __DIR__ . "/../Modelo/EstadoDiaModelo.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

//Responde con un código de error y un mensaje en JSON, y corta la ejecución
function cdError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { cdError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!hanaPuede('REPORTE', 'ver')) { cdError(403, 'No tienes permiso para el reporte general.'); }

$idColaborador = (int)$_SESSION['Idcolaborador'];
$verTodos = hanaVeTodo(); //el ADMIN TEC ve todo; los demás, lo de sus peajes asignados
$hoy = date('Y-m-d');
$fecha = HanaVal::fecha(isset($_GET['fecha']) ? $_GET['fecha'] : $hoy);
if ($fecha === '') { $fecha = $hoy; }
$ultimo = $hoy; //el reporte se consulta hasta hoy: no se adelanta el día de mañana
if ($fecha > $ultimo) { $fecha = $ultimo; }

$tablero  = new Tablero();
$controlDiario = new ControlDiario();
$alcance = $tablero->alcance($idColaborador, $verTodos, array_keys(hanaMisCentrosIds()));

//Filtro opcional por proyecto, siempre dentro del alcance
$filtro = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
$idsProyectos = array_keys($alcance['proyectos']);
if ($filtro > 0) { $idsProyectos = in_array($filtro, $idsProyectos) ? array($filtro) : array(); }

$proyectos = $tablero->proyectos($idsProyectos, $fecha);
$centrosTodos = $tablero->centros(array_keys($alcance['centros']), $fecha);
$centros = array();
foreach ($centrosTodos as $centro) { if (in_array((int)$centro['ID_PROYECTO'], $idsProyectos)) { $centro['NOM_PROYECTO'] = ''; $centros[] = $centro; } }
$nombreProyecto = array();
foreach ($proyectos as $proyecto) { $nombreProyecto[(int)$proyecto['ID_PROYECTO']] = $proyecto['NOM_PROYECTO']; }
foreach ($centros as $i => $centro) { $centros[$i]['NOM_PROYECTO'] = isset($nombreProyecto[(int)$centro['ID_PROYECTO']]) ? $nombreProyecto[(int)$centro['ID_PROYECTO']] : ''; }
$idsCentros = array_map(function ($centro) { return (int)$centro['ID_CENTRO_OP']; }, $centros);

//Se abre el reporte de quien trabaja en sus proyectos (los de sus peajes asignados)
$coordino = array();
$puedeVer = function ($idPersona, $idProyecto) use ($verTodos, $idColaborador, $alcance) {
    return $idPersona > 0 && ($verTodos || $idPersona === $idColaborador || isset($alcance['proyectos'][(int)$idProyecto]));
};

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //El control del día: qué registró cada persona y quién falta por registrar
    case 'dia':
        $personas = $controlDiario->personas($proyectos, $centros, $puedeVer);
        $hoyReg = $controlDiario->hoy(array_keys($personas), $fecha);

        //Hoy en qué estás: uno por persona, con quién falta
        $hoyFilas = array();
        foreach ($personas as $id => $persona) {
            $registro = isset($hoyReg[$id]) ? $hoyReg[$id] : null;
            $hoyFilas[] = array('ID' => $id, 'NOMBRE' => $persona['NOMBRE'], 'ROLES' => $persona['ROLES'], 'PROYECTOS' => $persona['PROYECTOS'], 'REGISTRO' => $registro);
        }

        $vacantes = (new Vacante())->listar($idsCentros, '', '', true, $hoy);
        $comunicaciones  = (new Comunicacion())->listar($idsProyectos, '', '', true, $hoy);

        //Lista de proyectos para el filtro (siempre todos los del alcance)
        $filtroLista = array();
        foreach ($tablero->proyectos(array_keys($alcance['proyectos']), $fecha) as $proyecto) { $filtroLista[] = array('id' => (int)$proyecto['ID_PROYECTO'], 'nombre' => $proyecto['NOM_PROYECTO']); }

        echo hanaJsonTexto(array(
            'fecha'      => $fecha,
            'hoy'        => $hoy,
            'ultimo'     => $ultimo,
            'verTodos'   => $verTodos,
            //Los nombres de las listas que edita el administrador (Parámetros del sistema → Listas)
            'etiquetas'  => array('transportes' => (object)Cronograma::$TRANSPORTES, 'pendiente' => (object)Comunicacion::$PENDIENTE,
                                  'estadosGh' => (object)Vacante::$ESTADOS_GH),
            'proyectos'  => $filtroLista,
            'hoyEnQue'   => $hoyFilas,
            'listas'     => $controlDiario->listas($idsCentros, $fecha),
            'arqueos'    => $controlDiario->arqueos($idsCentros, $fecha),
            'cronograma' => $controlDiario->cronograma($personas, $fecha, $hoy),
            //Quién abrió el cronograma ese día: [idColaborador => hora]
            'revisionCrono' => (object)(new EstadoDia())->revisaron(array_keys($personas), 'CRONOGRAMA', $fecha),
            'vehiculos'  => $controlDiario->vehiculos(array_keys($personas), $idsProyectos, $fecha),
            'vacantes'   => $vacantes,
            'comunicaciones' => $comunicaciones,
            //El ausentismo (matriz de cubrimiento) se retiró del sistema: la pestaña queda apagada
            'ausentismoDia' => (object)array(),
            'ausentismo' => array(),
            'modAusentismo' => false,
            //Cada centro con su jefe y si hizo la lista de chequeo ese día
            'centros'    => array_map(function ($centro) {
                return array('ID_CENTRO_OP' => (int)$centro['ID_CENTRO_OP'], 'ID_PROYECTO' => (int)$centro['ID_PROYECTO'],
                             'NOM_CENTRO_OP' => $centro['NOM_CENTRO_OP'], 'NOM_PROYECTO' => $centro['NOM_PROYECTO'], 'TIPO_CENTRO' => $centro['TIPO_CENTRO'],
                             'JEFE' => $centro['JEFE'], 'LISTAS' => (int)$centro['LISTAS']);
            }, $centros)
        ), JSON_UNESCAPED_UNICODE);
        break;

    //Las respuestas de una lista de chequeo. Solo de un centro que esta
    //persona ve (su alcance: sus peajes asignados)
    case 'lista':
        $lista = $controlDiario->listaRespuestas(isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if (!$lista || (!$verTodos && !isset($alcance['centros'][(int)$lista['ID_CENTRO']]))) {
            cdError(404, 'No se encontró la lista, o no es de los centros que tienes a cargo.');
        }
        echo hanaJsonTexto($lista, JSON_UNESCAPED_UNICODE);
        break;

    default:
        cdError(400, 'Operación no reconocida.');
}
