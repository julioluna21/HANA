<?php
/*
  HANA - Trazabilidad (Consultas → Trazabilidad)
  ---------------------------------------------------------------------------
    op=config                 los filtros: módulos, acciones, proyectos, peajes y personas
    op=buscar&desde&hasta[&modulo&accion&proyecto&centro&quien&texto&pagina]
                              los eventos, del más reciente al más viejo, de a 300

  Quién entra: la casilla 62M (Permisos de usuario) o el ADMIN TEC (24M).
  El ADMIN TEC ve todo; los demás, solo lo de sus peajes asignados.
  Cómo se arma la lista: Modelo/TrazabilidadModelo.php (no tiene tabla propia).
*/
session_start();
require_once __DIR__ . '/AccesoHelper.php';
require_once __DIR__ . '/../Modelo/TrazabilidadModelo.php';
require_once __DIR__ . '/../Modelo/HanaTexto.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

function trazaError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { trazaError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!hanaPuede('TRAZABILIDAD', 'ver')) { trazaError(403, 'La trazabilidad necesita la casilla 62M en tu rol.'); }

$veTodo = hanaTienePermiso('24M');
$misCentros = hanaMisCentros();
//Alcance de la persona: null = todo (ADMIN TEC); si no, sus peajes y los proyectos de esos peajes
$alcance = $veTodo ? null : array(array_keys(hanaMisCentrosIds()), array_keys(hanaMisProyectos()));
const TRAZA_POR_PAGINA = 300;

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    case 'config':
        $proyectos = array(); $centros = array();
        foreach ($misCentros as $centro) {
            $proyectos[(int)$centro['ID_PROYECTO']] = array('id' => (int)$centro['ID_PROYECTO'], 'nombre' => html_entity_decode($centro['NOM_PROYECTO'], ENT_QUOTES, 'UTF-8'));
            $centros[] = array('id' => (int)$centro['ID_CENTRO_OP'], 'proyecto' => (int)$centro['ID_PROYECTO'], 'nombre' => html_entity_decode($centro['NOM_CENTRO_OP'], ENT_QUOTES, 'UTF-8'));
        }
        $modulos = Trazabilidad::$MODULOS;
        //Lo que no es de un peaje (ingresos, parámetros, usuarios, días, reporte a la Gerencia) solo lo ve el ADMIN TEC
        if (!$veTodo) { foreach (array('INGRESOS', 'PARAMETROS', 'USUARIOS', 'DIAS', 'GERENCIA') as $soloAdmin) { unset($modulos[$soloAdmin]); } }
        echo hanaJsonTexto(array('hoy' => HanaFechas::hoy(), 'modulos' => $modulos, 'acciones' => Trazabilidad::$ACCIONES,
                                 'proyectos' => array_values($proyectos), 'centros' => $centros,
                                 'personas' => Trazabilidad::personas(), 'porPagina' => TRAZA_POR_PAGINA, 'veTodo' => $veTodo), JSON_UNESCAPED_UNICODE);
        break;

    case 'buscar':
        $f = array();
        $f['desde'] = HanaVal::fecha(isset($_GET['desde']) ? $_GET['desde'] : '');
        $f['hasta'] = HanaVal::fecha(isset($_GET['hasta']) ? $_GET['hasta'] : '');
        if ($f['desde'] === '' || $f['hasta'] === '') { trazaError(400, 'Elige desde qué día y hasta qué día.'); }
        if ($f['hasta'] < $f['desde']) { trazaError(400, 'El último día no puede ser antes del primero.'); }
        if ((strtotime($f['hasta']) - strtotime($f['desde'])) / 86400 > 92) { trazaError(400, 'Busca de a máximo tres meses (92 días) para que la consulta no sea muy pesada.'); }
        $f['modulo'] = isset($_GET['modulo']) && isset(Trazabilidad::$MODULOS[$_GET['modulo']]) ? $_GET['modulo'] : '';
        $f['accion'] = isset($_GET['accion']) && isset(Trazabilidad::$ACCIONES[$_GET['accion']]) ? $_GET['accion'] : '';
        $f['proyecto'] = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
        $f['centro'] = isset($_GET['centro']) ? (int)$_GET['centro'] : 0;
        $f['quien'] = isset($_GET['quien']) ? (int)$_GET['quien'] : 0;
        $f['texto'] = isset($_GET['texto']) ? mb_substr(trim((string)$_GET['texto']), 0, 80) : '';
        //Un peaje o proyecto que no es suyo no se busca (el alcance ya lo filtra, esto es para avisar claro)
        if (!$veTodo && $f['centro'] > 0 && !isset(hanaMisCentrosIds()[$f['centro']])) { trazaError(403, 'Ese peaje no está entre los tuyos.'); }
        $pagina = max(1, isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1);
        list($total, $filas) = Trazabilidad::buscar($f, $alcance, ($pagina - 1) * TRAZA_POR_PAGINA, TRAZA_POR_PAGINA);
        echo hanaJsonTexto(array('total' => $total, 'pagina' => $pagina, 'porPagina' => TRAZA_POR_PAGINA, 'filas' => $filas), JSON_UNESCAPED_UNICODE);
        break;

    default:
        trazaError(400, 'Operación no válida.');
}
