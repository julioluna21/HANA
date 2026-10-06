<?php
/*
  HANA — Controlador de arqueos (Reporte diario, Fase 2)
    - Hacer arqueos: permiso 11M, solo en los centros asignados al usuario.
    - Configurar los fondos autorizados: permiso 5M (el de centros de operación).
*/
session_start();
require_once __DIR__ . "/../Modelo/ArqueoModelo.php";
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');


function arqError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//Si el administrador apagó este módulo (Parámetros del sistema), no se registra nada
require_once __DIR__ . '/../Modelo/HanaConfig.php';
if (!HanaConfig::modulo('ARQUEOS')) { arqError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
HanaDB::usarModulo('ARQUEOS'); //la casilla ARQUEOS de Roles da acceso en todos los proyectos
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { arqError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
//Los arqueos los hace el coordinador; los fondos los configura quien administra centros (5M)
$esCoordinador = HanaDB::esCoordinador((int)$_SESSION['Idcolaborador']);
if (!$esCoordinador && !hanaTienePermiso('5M')) { arqError(403, 'Los arqueos los hace el coordinador del proyecto. Si eres coordinador, pide que te asignen en Configuración → Proyectos.'); }

$idUsuario     = (int)$_SESSION['IdUsuarios'];
$idColaborador = (int)$_SESSION['Idcolaborador'];
$puedeArquear  = $esCoordinador;
//Autorizar fondos: casilla 36M en Roles. Si quien la tiene es coordinador, solo en los peajes
//de su proyecto; el ADMIN TEC (24M) o quien no coordine, en todos
$puedeFondos   = hanaTienePermiso('36M');
$puedeAnularOtros = hanaTienePermiso('5M'); //anular arqueos de otros: como antes, quien administra centros
//Los peajes en los que puede autorizar fondos (null = todos)
$centrosFondo = null;
if ($puedeFondos && !hanaTienePermiso('24M')) {
    $mios = HanaDB::q("SELECT c.ID_CENTRO_OP FROM centros_operacion c INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                        WHERE p.ID_COLABORADOR_COORDINADOR = ? AND c.Estado = '1'", 'i', array($idColaborador));
    if ($mios) { $centrosFondo = array_map('intval', array_column($mios, 'ID_CENTRO_OP')); } //es coordinador: solo los suyos
}
//Los campos del arqueo de casetas los configura quien maneja fondos (5M) o el administrador (24M)
$puedeCampos   = $puedeFondos || hanaTienePermiso('24M');
$A             = new Arqueo();
$hoy           = date('Y-m-d');
list($primerEditable, $ultimoEditable) = HanaFechas::ventana(); //solo hoy (los días habilitados van aparte)

//Los centros de los proyectos que coordina, con su id como llave
$centros = array();
foreach (HanaDB::centrosCoordinador($idColaborador) as $c) { $centros[(int)$c['ID_CENTRO_OP']] = $c; }

//¿Puede ver este arqueo? Solo si es de uno de sus centros
function arqCargar($A, $centros, $idArqueo)
{
    $a = $A->mostrar($idArqueo);
    if (!$a || !isset($centros[(int)$a['ID_CENTRO_OP']])) { arqError(404, 'No se encontró el arqueo, o no es de tus centros.'); }
    return $a;
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //-----------------------------------------------------------------------
    // Arqueo de casetas: los campos
    //-----------------------------------------------------------------------
    //Los campos del formulario para un peaje (los que ve quien arquea)
    case 'campos':
        $idCentro = isset($_GET['centro']) ? (int)$_GET['centro'] : 0;
        if (!isset($centros[$idCentro]) && !$puedeCampos) { arqError(403, 'Ese peaje no es de los tuyos.'); }
        echo json_encode($A->conceptosCaseta($idCentro), JSON_UNESCAPED_UNICODE);
        break;

    //La configuración: todos los peajes y los campos del elegido (0 = los de todos los peajes)
    case 'camposConfig':
        if (!$puedeCampos) { arqError(403, 'Los campos los configura quien administra los fondos (5M) o los parámetros (24M).'); }
        $idCentro = isset($_GET['centro']) ? (int)$_GET['centro'] : 0;
        echo json_encode(array('centros' => $A->centrosConFondos(), 'campos' => $A->conceptosConfig($idCentro)), JSON_UNESCAPED_UNICODE);
        break;

    case 'guardarCampo':
        if (!$puedeCampos) { arqError(403, 'No tienes permiso para configurar los campos.'); }
        $idCampo = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $idCentro = isset($_POST['centro']) ? (int)$_POST['centro'] : 0;
        $nombre = HanaVal::texto(isset($_POST['nombre']) ? (function_exists('mb_strtoupper') ? mb_strtoupper(trim($_POST['nombre']), 'UTF-8') : strtoupper(trim($_POST['nombre']))) : '', 80);
        if ($nombre === null) { arqError(400, 'Escribe el nombre del campo.'); }
        $rol = isset($_POST['rol']) ? $_POST['rol'] : '';
        if (!isset(Arqueo::$ROLES[$rol])) { arqError(400, 'Elige cómo se comporta el campo.'); }
        $estado = isset($_POST['estado']) && $_POST['estado'] === '0' ? 0 : 1;
        $A->guardarConcepto($idCampo, $idCentro, $nombre, $rol, isset($_POST['orden']) ? (int)$_POST['orden'] : 99, $estado);
        echo json_encode(array('ok' => true, 'mensaje' => $idCampo ? 'Campo actualizado.' : 'Campo agregado.'), JSON_UNESCAPED_UNICODE);
        break;

    //Quitar o volver a poner en un peaje un campo que es de todos
    case 'ocultarCampo':
        if (!$puedeCampos) { arqError(403, 'No tienes permiso para configurar los campos.'); }
        $idCentro = isset($_POST['centro']) ? (int)$_POST['centro'] : 0;
        if ($idCentro <= 0) { arqError(400, 'Elige el peaje.'); }
        $ocultar = isset($_POST['ocultar']) && $_POST['ocultar'] === '1';
        $A->ocultarConcepto(isset($_POST['id']) ? (int)$_POST['id'] : 0, $idCentro, $ocultar);
        echo json_encode(array('ok' => true, 'mensaje' => $ocultar ? 'El campo ya no sale en este peaje.' : 'El campo vuelve a salir en este peaje.'), JSON_UNESCAPED_UNICODE);
        break;

    case 'config':
        $fondos = $A->fondos(array_keys($centros));
        $lista = array();
        foreach ($centros as $id => $c) {
            $c['FONDOS'] = isset($fondos[$id]) ? $fondos[$id] : new stdClass();
            $lista[] = $c;
        }
        echo json_encode(array(
            'hoy' => $hoy, 'primerEditable' => $primerEditable, 'ultimoEditable' => $ultimoEditable,
            'habilitados' => HanaFechas::habilitados($idColaborador), //los días que el administrador le abrió
            'tipos' => Arqueo::$TIPOS, 'efectivo' => Arqueo::$EFECTIVO, 'documentos' => Arqueo::$DOCUMENTOS,
            'roles' => Arqueo::$ROLES, 'puedeCampos' => $puedeCampos,
            'centros' => $lista, 'puedeArquear' => $puedeArquear, 'puedeFondos' => $puedeFondos
        ), JSON_UNESCAPED_UNICODE);
        break;

    case 'listar':
        $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
        $mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
        if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) { arqError(400, 'El periodo no es válido.'); }
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-d', strtotime("$desde +1 month"));
        echo json_encode($A->listar(array_keys($centros), $desde, $hasta,
                                    isset($_GET['centro']) ? (int)$_GET['centro'] : 0,
                                    isset($_GET['tipo']) ? $_GET['tipo'] : ''), JSON_UNESCAPED_UNICODE);
        break;

    case 'mostrar':
        $a = arqCargar($A, $centros, isset($_GET['id']) ? (int)$_GET['id'] : 0);
        $a['PUEDE_ANULAR'] = (int)$a['ESTADO'] === 1 && ((int)$a['ID_COLABORADOR_ARQUEA'] === $idColaborador || $puedeAnularOtros);
        echo json_encode($a, JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Registrar un arqueo
    //-----------------------------------------------------------------------
    case 'guardar':
        if (!$puedeArquear) { arqError(403, 'No tienes permiso para hacer arqueos.'); }

        $tipo = isset($_POST['tipo']) ? $_POST['tipo'] : '';
        if (!isset(Arqueo::$TIPOS[$tipo])) { arqError(400, 'Elige el tipo de arqueo.'); }

        $idCentro = isset($_POST['centro']) ? (int)$_POST['centro'] : 0;
        if (!isset($centros[$idCentro])) { arqError(400, 'Elige uno de los centros de los proyectos que coordinas.'); }

        //El arqueo de casetas no tiene fondo fijo: el valor esperado lo trae el propio arqueo
        $fondo = $tipo === 'CASETA' ? 0 : $A->fondo($idCentro, $tipo);
        if ($fondo === null) {
            arqError(400, $centros[$idCentro]['NOM_CENTRO_OP'] . ' no tiene fondo de ' . strtolower(Arqueo::$TIPOS[$tipo])
                          . ' autorizado. Pide que lo configuren en "Fondos autorizados".');
        }

        $fecha = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($fecha === '') { arqError(400, 'La fecha no es válida.'); }
        if (!HanaFechas::enVentana($fecha)) { arqError(400, 'Solo se registran arqueos de ' . HanaFechas::textoVentana() . '.'); }

        $hora = HanaVal::hora(isset($_POST['hora']) ? $_POST['hora'] : '');
        if ($hora === null) { arqError(400, 'Escribe la hora del arqueo.'); }
        $horaFin = HanaVal::hora(isset($_POST['horaFin']) ? $_POST['horaFin'] : '');
        if ($horaFin !== null && $horaFin <= $hora) { arqError(400, 'La hora de terminación debe ser después de la hora del arqueo.'); }

        $responsable = HanaVal::texto(isset($_POST['responsable']) ? $_POST['responsable'] : '', 120);
        if ($responsable === null) { arqError(400, $tipo === 'CASETA' ? 'Escribe el nombre de la recolectora.' : 'Escribe quién es la persona responsable del dinero.'); }
        $cargo = HanaVal::texto(isset($_POST['cargo']) ? $_POST['cargo'] : '', 80);

        //--- Arqueo de casetas: los campos configurados para ese peaje ---
        //Total recaudo = campos que suman - campos que restan. Novedad = total recaudo - valor esperado
        if ($tipo === 'CASETA') {
            $caseta = HanaVal::texto(isset($_POST['caseta']) ? $_POST['caseta'] : '', 30);
            if ($caseta === null) { arqError(400, 'Escribe la caseta.'); }
            $campos = $A->conceptosCaseta($idCentro);
            if (!count(array_filter($campos, function ($c) { return $c['ROL'] === 'ESPERADO'; }))) {
                arqError(400, 'Este peaje no tiene un campo de "valor esperado" (el recaudo de la caseta). Pide que lo configuren en "Campos del arqueo de casetas".');
            }
            $valores = isset($_POST['campo']) ? (array)$_POST['campo'] : array();
            $lineas = array(); $esperado = 0; $recaudo = 0;
            foreach ($campos as $c) {
                $id = (int)$c['ID_CONCEPTO'];
                $crudo = isset($valores[$id]) ? trim($valores[$id]) : '';
                $valor = ($crudo === '') ? 0.0 : HanaVal::pesos($crudo);
                if ($valor === null) { arqError(400, 'El valor de "' . $c['NOMBRE'] . '" no es válido.'); }
                if ($c['ROL'] === 'ESPERADO') { $esperado += $valor; }
                elseif ($c['ROL'] === 'SUMA') { $recaudo += $valor; }
                elseif ($c['ROL'] === 'RESTA') { $recaudo -= $valor; }
                $lineas[] = array('clase' => 'CASETA', 'concepto' => $c['ROL'] . '-' . $id, 'nombre' => $c['NOMBRE'], 'fecha' => null, 'valor' => $valor, 'obs' => null);
            }
            if ($esperado <= 0) { arqError(400, 'Escribe el recaudo de la caseta (el valor esperado).'); }
            $diferencia = round($recaudo - $esperado, 2);
            $observacion = HanaVal::texto(isset($_POST['observacion']) ? $_POST['observacion'] : '', 2000);
            if ($diferencia != 0 && $observacion === null) { arqError(400, 'El recaudo no cuadra con la caseta. Explica la novedad en la observación.'); }

            HanaDB::iniciar();
            $id = $A->insertar(array(
                'tipo' => 'CASETA', 'centro' => $idCentro, 'caseta' => $caseta, 'fecha' => $fecha, 'hora' => $hora, 'horaFin' => $horaFin,
                'responsable' => $responsable, 'cargo' => 'RECOLECTORA', 'colaborador' => $idColaborador, 'fondo' => $esperado,
                'efectivo' => $recaudo, 'documentos' => 0, 'total' => $recaudo, 'diferencia' => $diferencia,
                'observacion' => $observacion, 'ahora' => date('Y-m-d H:i:s')
            ), $lineas);
            if (!$id) { HanaDB::deshacer(); arqError(500, 'No se pudo guardar el arqueo. No quedó nada registrado; intenta de nuevo.'); }
            HanaDB::confirmar();
            echo json_encode(array('ok' => true, 'id' => $id,
                'mensaje' => $diferencia == 0 ? 'Arqueo de caseta registrado. Cuadra con el recaudo.'
                           : 'Arqueo de caseta registrado con un ' . ($diferencia < 0 ? 'faltante' : 'sobrante') . ' de $' . number_format(abs($diferencia), 0, ',', '.') . '.'),
                JSON_UNESCAPED_UNICODE);
            break;
        }

        //--- Efectivo: una línea por concepto del tipo ---
        $lineas = array();
        $efectivo = 0;
        $valores = isset($_POST['efectivo']) ? (array)$_POST['efectivo'] : array();
        $obsEf   = isset($_POST['efectivoObs']) ? (array)$_POST['efectivoObs'] : array();
        foreach (Arqueo::$EFECTIVO[$tipo] as $codigo => $nombre) {
            $crudo = isset($valores[$codigo]) ? trim($valores[$codigo]) : '';
            $valor = ($crudo === '') ? 0.0 : HanaVal::pesos($crudo);
            if ($valor === null) { arqError(400, "El valor de \"$nombre\" no es válido."); }
            $efectivo += $valor;
            $lineas[] = array('clase' => 'EFECTIVO', 'concepto' => $codigo, 'fecha' => null, 'valor' => $valor,
                              'obs' => HanaVal::texto(isset($obsEf[$codigo]) ? $obsEf[$codigo] : '', 300));
        }

        //--- Documentos (solo caja menor): facturas, reintegros, recibos y faltantes ---
        $documentos = 0;
        if ($tipo === 'CAJA_MENOR') {
            $dF = isset($_POST['docFecha'])    ? (array)$_POST['docFecha']    : array();
            $dC = isset($_POST['docConcepto']) ? (array)$_POST['docConcepto'] : array();
            $dV = isset($_POST['docValor'])    ? (array)$_POST['docValor']    : array();
            $dO = isset($_POST['docObs'])      ? (array)$_POST['docObs']      : array();
            //Tope de documentos: más de 26 ya no caben legibles en la hoja del formato
            $conValor = count(array_filter(array_map('trim', array_map('strval', $dV)), 'strlen'));
            if ($conValor > 26) { arqError(400, 'Un arqueo admite máximo 26 documentos. Si hay más, divídelos en dos arqueos.'); }
            foreach ($dV as $i => $crudo) {
                if (trim((string)$crudo) === '') { continue; } //renglón vacío
                $n = $i + 1;
                $valor = HanaVal::pesos($crudo);
                if ($valor === null || $valor <= 0) { arqError(400, "El valor del documento $n no es válido."); }
                $concepto = isset($dC[$i]) ? $dC[$i] : '';
                if (!isset(Arqueo::$DOCUMENTOS[$concepto])) { arqError(400, "Elige el concepto del documento $n."); }
                $fDoc = null;
                if (isset($dF[$i]) && trim($dF[$i]) !== '') {
                    $fDoc = HanaVal::fecha($dF[$i]);
                    if ($fDoc === '' || $fDoc > $fecha) { arqError(400, "La fecha del documento $n no es válida o es posterior al arqueo."); }
                }
                $documentos += $valor;
                $lineas[] = array('clase' => 'DOCUMENTO', 'concepto' => $concepto, 'fecha' => $fDoc, 'valor' => $valor,
                                  'obs' => HanaVal::texto(isset($dO[$i]) ? $dO[$i] : '', 300));
            }
        }

        $total = $efectivo + $documentos;
        $diferencia = round($total - $fondo, 2);
        $observacion = HanaVal::texto(isset($_POST['observacion']) ? $_POST['observacion'] : '', 2000);
        //Si no cuadra, se exige explicar por qué
        if ($diferencia != 0 && $observacion === null) {
            arqError(400, 'El arqueo no cuadra con el fondo autorizado. Explica la diferencia en la observación.');
        }
        if ($total <= 0) { arqError(400, 'El arqueo no tiene ningún valor contado.'); }

        HanaDB::iniciar();
        $id = $A->insertar(array(
            'tipo' => $tipo, 'centro' => $idCentro, 'fecha' => $fecha, 'hora' => $hora, 'horaFin' => $horaFin,
            'responsable' => $responsable, 'cargo' => $cargo, 'colaborador' => $idColaborador, 'fondo' => $fondo,
            'efectivo' => $efectivo, 'documentos' => $documentos, 'total' => $total, 'diferencia' => $diferencia,
            'observacion' => $observacion, 'ahora' => date('Y-m-d H:i:s')
        ), $lineas);
        if (!$id) { HanaDB::deshacer(); arqError(500, 'No se pudo guardar el arqueo. No quedó nada registrado; intenta de nuevo.'); }
        HanaDB::confirmar();

        echo json_encode(array('ok' => true, 'id' => $id,
            'mensaje' => $diferencia == 0 ? 'Arqueo registrado. El fondo cuadra.'
                       : 'Arqueo registrado con una diferencia de $' . number_format(abs($diferencia), 0, ',', '.')
                         . ($diferencia < 0 ? ' (faltante).' : ' (sobrante).')), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Anular (no se borra: queda con el motivo)
    //-----------------------------------------------------------------------
    case 'anular':
        $a = arqCargar($A, $centros, isset($_POST['id']) ? (int)$_POST['id'] : 0);
        if ((int)$a['ESTADO'] !== 1) { arqError(400, 'Este arqueo ya estaba anulado.'); }
        if ((int)$a['ID_COLABORADOR_ARQUEA'] !== $idColaborador && !$puedeAnularOtros) {
            arqError(403, 'Solo quien hizo el arqueo puede anularlo.');
        }
        $motivo = HanaVal::texto(isset($_POST['motivo']) ? $_POST['motivo'] : '', 300);
        if ($motivo === null) { arqError(400, 'Explica por qué se anula el arqueo.'); }
        if (!$A->anular($a['ID_ARQUEO'], $motivo)) { arqError(500, 'No se pudo anular. Intenta de nuevo.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Arqueo anulado. Queda en el historial.'), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Fondos autorizados (permiso 5M)
    //-----------------------------------------------------------------------
    case 'fondos':
        if (!$puedeFondos) { arqError(403, 'Autorizar fondos necesita el permiso 36M en tu rol.'); }
        $lista = $A->centrosConFondos();
        if ($centrosFondo !== null) { $lista = array_values(array_filter($lista, function ($c) use ($centrosFondo) { return in_array((int)$c['ID_CENTRO_OP'], $centrosFondo, true); })); }
        echo json_encode($lista, JSON_UNESCAPED_UNICODE);
        break;

    case 'guardarFondo':
        if (!$puedeFondos) { arqError(403, 'Autorizar fondos necesita el permiso 36M en tu rol.'); }
        $idCentro = isset($_POST['centro']) ? (int)$_POST['centro'] : 0;
        if ($centrosFondo !== null && !in_array($idCentro, $centrosFondo, true)) { arqError(403, 'Solo autorizas fondos de los peajes de tu proyecto.'); }
        $existe = HanaDB::fila("SELECT 1 AS ok FROM centros_operacion WHERE ID_CENTRO_OP = ? AND Estado = '1'", 'i', array($idCentro));
        if (!$existe) { arqError(400, 'Ese centro no existe o está inactivo.'); }
        foreach (array_keys(Arqueo::$TIPOS) as $t) {
            $crudo = isset($_POST[$t]) ? trim($_POST[$t]) : '';
            $valor = null;
            if ($crudo !== '') {
                $valor = HanaVal::pesos($crudo);
                if ($valor === null || $valor <= 0) { arqError(400, 'El fondo de ' . strtolower(Arqueo::$TIPOS[$t]) . ' no es válido.'); }
            }
            if (!$A->guardarFondo($idCentro, $t, $valor, $idColaborador, date('Y-m-d H:i:s'))) {
                arqError(500, 'No se pudo guardar el fondo. Intenta de nuevo.');
            }
        }
        echo json_encode(array('ok' => true, 'mensaje' => 'Fondos guardados.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        arqError(400, 'Operación no reconocida.');
}
