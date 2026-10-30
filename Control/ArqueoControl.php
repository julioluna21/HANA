<?php
/*
  HANA - Controlador de arqueos (Reporte diario)
    Casillas de Arqueos en Permisos de usuario, siempre en los peajes asignados a la persona:
    hacer arqueos (29M), verlos (45M), autorizar fondos y campos de casetas (36M) y anular
    los de otros (46M).
*/
session_start();
require_once __DIR__ . "/../Modelo/ArqueoModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //las respuestas JSON llevan los textos como se escribieron (sin &amp;)
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');


//Responde con un código de error y un mensaje en JSON, y corta la ejecución
function arqError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//Si el administrador apagó este módulo (Parámetros del sistema), no se registra nada
require_once __DIR__ . '/../Modelo/HanaConfig.php';
if (!HanaConfig::modulo('ARQUEOS')) { arqError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
hanaUsarModulo('ARQUEOS');
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { arqError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
//Casillas de Arqueos (Permisos de usuario), siempre en los peajes asignados a la persona:
//  hacer arqueos (29M), ver los de sus peajes (45M), autorizar fondos y campos de casetas (36M),
//  anular los arqueos que hicieron otros (46M). El ADMIN TEC (24M) todo, en todos los peajes
$idUsuario        = (int)$_SESSION['Idcolaborador']; //el usuario es la persona (colaboradores)
$idColaborador    = (int)$_SESSION['Idcolaborador'];
$puedeArquear     = hanaPuede('ARQUEOS', 'llenar');
$puedeFondos      = hanaPuede('ARQUEOS', 'fondos');
$puedeAnularOtros = hanaPuede('ARQUEOS', 'anular');
$puedeCampos      = $puedeFondos;
if (!hanaEntra('ARQUEOS')) { arqError(403, 'Tu rol no tiene acceso a Arqueos. Se da en Configuración → Permisos de usuario.'); }
if (!count(hanaMisCentros())) { arqError(403, 'No tienes peajes asignados. Pide que te los asignen en Configuración → Colaboradores.'); }
$esCoordinador    = $puedeArquear;
//Los peajes en los que puede autorizar fondos (null = todos): los suyos
$centrosFondo = hanaVeTodo() ? null : array_map('intval', array_keys(hanaMisCentrosIds()));
$modeloArqueo             = new Arqueo();
$hoy           = date('Y-m-d');
list($primerEditable, $ultimoEditable) = HanaFechas::ventana(); //solo hoy (los días habilitados van aparte)

//Sus peajes asignados, con su id como llave
$centros = array();
foreach (hanaMisCentros() as $centro) { $centros[(int)$centro['ID_CENTRO_OP']] = $centro; }

//¿Puede ver este arqueo? Solo si es de uno de sus centros
function arqCargar($modeloArqueo, $centros, $idArqueo)
{
    $arqueo = $modeloArqueo->mostrar($idArqueo);
    if (!$arqueo || !isset($centros[(int)$arqueo['ID_CENTRO_OP']])) { arqError(404, 'No se encontró el arqueo, o no es de tus centros.'); }
    return $arqueo;
}

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //-----------------------------------------------------------------------
    // Arqueo de casetas: los campos
    //-----------------------------------------------------------------------
    //Los campos del formulario para un peaje (los que ve quien arquea)
    case 'campos':
        $idCentro = isset($_GET['centro']) ? (int)$_GET['centro'] : 0;
        if (!isset($centros[$idCentro]) && !$puedeCampos) { arqError(403, 'Ese peaje no es de los tuyos.'); }
        echo hanaJsonTexto($modeloArqueo->conceptosCaseta($idCentro), JSON_UNESCAPED_UNICODE);
        break;

    //La configuración: todos los peajes y los campos del elegido (0 = los de todos los peajes)
    case 'camposConfig':
        if (!$puedeCampos) { arqError(403, 'Los campos los configura quien autoriza fondos (casilla de Arqueos en Permisos de usuario).'); }
        $idCentro = isset($_GET['centro']) ? (int)$_GET['centro'] : 0;
        echo hanaJsonTexto(array('centros' => $modeloArqueo->centrosConFondos(), 'campos' => $modeloArqueo->conceptosConfig($idCentro)), JSON_UNESCAPED_UNICODE);
        break;

    //Crea o edita un campo de documento (vales, tirillas, etc.) que se pide en el arqueo de un peaje
    case 'guardarCampo':
        if (!$puedeCampos) { arqError(403, 'No tienes permiso para configurar los campos.'); }
        $idCampo = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $idCentro = isset($_POST['centro']) ? (int)$_POST['centro'] : 0;
        $nombre = HanaVal::texto(isset($_POST['nombre']) ? (function_exists('mb_strtoupper') ? mb_strtoupper(trim($_POST['nombre']), 'UTF-8') : strtoupper(trim($_POST['nombre']))) : '', 80);
        if ($nombre === null) { arqError(400, 'Escribe el nombre del campo.'); }
        $rol = isset($_POST['rol']) ? $_POST['rol'] : '';
        if (!isset(Arqueo::$ROLES[$rol])) { arqError(400, 'Elige cómo se comporta el campo.'); }
        $estado = isset($_POST['estado']) && $_POST['estado'] === '0' ? 0 : 1;
        $modeloArqueo->guardarConcepto($idCampo, $idCentro, $nombre, $rol, isset($_POST['orden']) ? (int)$_POST['orden'] : 99, $estado);
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => $idCampo ? 'Campo actualizado.' : 'Campo agregado.'), JSON_UNESCAPED_UNICODE);
        break;

    //Lo que necesita la pantalla al abrir: los peajes de la persona con sus fondos autorizados
    case 'config':
        $fondos = $modeloArqueo->fondos(array_keys($centros));
        $lista = array();
        foreach ($centros as $id => $centro) {
            $centro['FONDOS'] = isset($fondos[$id]) ? $fondos[$id] : new stdClass();
            $lista[] = $centro;
        }
        echo hanaJsonTexto(array(
            'hoy' => $hoy, 'primerEditable' => $primerEditable, 'ultimoEditable' => $ultimoEditable,
            'habilitados' => HanaFechas::habilitados($idColaborador), //los días que el administrador le abrió
            'tipos' => Arqueo::$TIPOS, 'efectivo' => Arqueo::$EFECTIVO, 'documentos' => Arqueo::$DOCUMENTOS_ACTIVOS,
            'roles' => Arqueo::$ROLES, 'puedeCampos' => $puedeCampos,
            'centros' => $lista, 'puedeArquear' => $puedeArquear, 'puedeFondos' => $puedeFondos
        ), JSON_UNESCAPED_UNICODE);
        break;

    //Arqueos del mes elegido, solo de los peajes que la persona puede ver
    case 'listar':
        $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
        $mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
        if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) { arqError(400, 'El periodo no es válido.'); }
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-d', strtotime("$desde +1 month"));
        echo hanaJsonTexto($modeloArqueo->listar(array_keys($centros), $desde, $hasta,
                                    isset($_GET['centro']) ? (int)$_GET['centro'] : 0,
                                    isset($_GET['tipo']) ? $_GET['tipo'] : ''), JSON_UNESCAPED_UNICODE);
        break;

    //Un arqueo con todo su detalle, y si quien lo mira lo puede anular
    case 'mostrar':
        $arqueo = arqCargar($modeloArqueo, $centros, isset($_GET['id']) ? (int)$_GET['id'] : 0);
        $arqueo['PUEDE_ANULAR'] = (int)$arqueo['ESTADO'] === 1 && ((int)$arqueo['ID_COLABORADOR_ARQUEA'] === $idColaborador || $puedeAnularOtros);
        echo hanaJsonTexto($arqueo, JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Registrar un arqueo
    //-----------------------------------------------------------------------
    case 'guardar':
        if (!$puedeArquear) { arqError(403, 'No tienes permiso para hacer arqueos.'); }

        $tipo = isset($_POST['tipo']) ? $_POST['tipo'] : '';
        if (!isset(Arqueo::$TIPOS[$tipo])) { arqError(400, 'Elige el tipo de arqueo.'); }

        $idCentro = isset($_POST['centro']) ? (int)$_POST['centro'] : 0;
        if (!isset($centros[$idCentro])) { arqError(400, 'Elige uno de tus peajes.'); }

        //El arqueo de casetas no tiene fondo fijo: el valor esperado lo trae el propio arqueo
        $fondo = $tipo === 'CASETA' ? 0 : $modeloArqueo->fondo($idCentro, $tipo);
        if ($fondo === null) {
            arqError(400, $centros[$idCentro]['NOM_CENTRO_OP'] . ' no tiene fondo de ' . strtolower(Arqueo::$TIPOS[$tipo])
                          . ' autorizado. Pide que lo configuren en "Fondos autorizados".');
        }

        $fecha = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($fecha === '') { arqError(400, 'La fecha no es válida.'); }
        if (!HanaFechas::enVentana($fecha, $idColaborador)) { arqError(400, 'Solo se registran arqueos de ' . HanaFechas::textoVentana() . '.'); }

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
            $campos = $modeloArqueo->conceptosCaseta($idCentro);
            if (!count(array_filter($campos, function ($campo) { return $campo['ROL'] === 'ESPERADO'; }))) {
                arqError(400, 'Este peaje no tiene un campo de "valor esperado" (el recaudo de la caseta). Pide que lo configuren en "Campos del arqueo de casetas".');
            }
            $valores = isset($_POST['campo']) ? (array)$_POST['campo'] : array();
            $lineas = array(); $esperado = 0; $recaudo = 0;
            foreach ($campos as $campo) {
                $id = (int)$campo['ID_CONCEPTO'];
                $crudo = isset($valores[$id]) ? trim($valores[$id]) : '';
                $valor = ($crudo === '') ? 0.0 : HanaVal::pesos($crudo);
                if ($valor === null) { arqError(400, 'El valor de "' . $campo['NOMBRE'] . '" no es válido.'); }
                if ($campo['ROL'] === 'ESPERADO') { $esperado += $valor; }
                elseif ($campo['ROL'] === 'SUMA') { $recaudo += $valor; }
                elseif ($campo['ROL'] === 'RESTA') { $recaudo -= $valor; }
                //El campo va por su ID; NOMBRE y ROL guardan cómo era el campo ese día
                $lineas[] = array('idConcepto' => $id, 'nombre' => $campo['NOMBRE'], 'rol' => $campo['ROL'], 'fecha' => null, 'valor' => $valor, 'obs' => null);
            }
            if ($esperado <= 0) { arqError(400, 'Escribe el recaudo de la caseta (el valor esperado).'); }
            $diferencia = round($recaudo - $esperado, 2);
            $observacion = HanaVal::texto(isset($_POST['observacion']) ? $_POST['observacion'] : '', 2000);
            if ($diferencia != 0 && $observacion === null) { arqError(400, 'El recaudo no cuadra con la caseta. Explica la novedad en la observación.'); }

            HanaDB::iniciar();
            $id = $modeloArqueo->insertar(array(
                'tipo' => 'CASETA', 'centro' => $idCentro, 'caseta' => $caseta, 'fecha' => $fecha, 'hora' => $hora, 'horaFin' => $horaFin,
                'responsable' => $responsable, 'cargo' => 'RECOLECTORA', 'colaborador' => $idColaborador,
                //En casetas no se guarda fondo ni billetes aparte: el valor esperado y lo contado
                //son líneas del arqueo (los campos), y de ahí se calculan el total y la diferencia
                'fondo' => null, 'billetes' => null,
                'observacion' => $observacion, 'ahora' => date('Y-m-d H:i:s')
            ), $lineas);
            if (!$id) { HanaDB::deshacer(); arqError(500, 'No se pudo guardar el arqueo. No quedó nada registrado; intenta de nuevo.'); }
            HanaDB::confirmar();
            echo hanaJsonTexto(array('ok' => true, 'id' => $id,
                'mensaje' => $diferencia == 0 ? 'Arqueo de caseta registrado. Cuadra con el recaudo.'
                           : 'Arqueo de caseta registrado con un ' . ($diferencia < 0 ? 'faltante' : 'sobrante') . ' de $' . number_format(abs($diferencia), 0, ',', '.') . '.'),
                JSON_UNESCAPED_UNICODE);
            break;
        }

        //Los conceptos fijos de este tipo (arqueo_concepto con CODIGO): cada línea apunta al suyo
        $fijos = $modeloArqueo->conceptosFijos($tipo);
        $linea = function ($codigo, $fecha, $valor, $obs) use ($fijos) {
            if (!isset($fijos[$codigo])) {
                error_log('HANA - arqueo: falta el concepto ' . $codigo . ' en arqueo_concepto'); //el detalle queda para el administrador
                arqError(500, 'No se guardó el arqueo: falta un concepto en la configuración. Avísale al administrador del sistema.');
            }
            return array('idConcepto' => (int)$fijos[$codigo]['ID_CONCEPTO'], 'nombre' => $fijos[$codigo]['NOMBRE'], 'rol' => $fijos[$codigo]['ROL'],
                         'fecha' => $fecha, 'valor' => $valor, 'obs' => $obs);
        };

        //--- Efectivo: un valor por concepto del tipo. En caja menor, billetes y monedas juntos (EFECTIVO)
        //no son una línea: van en arqueo.BILLETES_MONEDAS. En el recambio todo es línea, también los
        //billetes y las monedas, cada uno por separado ---
        $lineas = array();
        $efectivo = 0;
        $billetes = 0.0;
        $valores = isset($_POST['efectivo']) ? (array)$_POST['efectivo'] : array();
        $obsEf   = isset($_POST['efectivoObs']) ? (array)$_POST['efectivoObs'] : array();
        foreach (Arqueo::$EFECTIVO[$tipo] as $codigo => $nombre) {
            $crudo = isset($valores[$codigo]) ? trim($valores[$codigo]) : '';
            $valor = ($crudo === '') ? 0.0 : HanaVal::pesos($crudo);
            if ($valor === null) { arqError(400, "El valor de \"$nombre\" no es válido."); }
            $efectivo += $valor;
            if ($codigo === 'EFECTIVO') { $billetes = $valor; continue; } //va en BILLETES_MONEDAS, no como línea
            $lineas[] = $linea($codigo, null, $valor, HanaVal::texto(isset($obsEf[$codigo]) ? $obsEf[$codigo] : '', 300));
        }

        //--- Documentos (solo caja menor): facturas, recibos de caja y faltantes ---
        $documentos = 0;
        if ($tipo === 'CAJA_MENOR') {
            $docFechas = isset($_POST['docFecha'])    ? (array)$_POST['docFecha']    : array();
            $docConceptos = isset($_POST['docConcepto']) ? (array)$_POST['docConcepto'] : array();
            $docValores = isset($_POST['docValor'])    ? (array)$_POST['docValor']    : array();
            $docObservaciones = isset($_POST['docObs'])      ? (array)$_POST['docObs']      : array();
            //Tope de documentos: más de 26 ya no caben legibles en la hoja del formato
            $conValor = count(array_filter(array_map('trim', array_map('strval', $docValores)), 'strlen'));
            if ($conValor > 26) { arqError(400, 'Un arqueo admite máximo 26 documentos. Si hay más, divídelos en dos arqueos.'); }
            foreach ($docValores as $i => $crudo) {
                if (trim((string)$crudo) === '') { continue; } //renglón vacío
                $numeroDoc = $i + 1;
                $valor = HanaVal::pesos($crudo);
                if ($valor === null || $valor <= 0) { arqError(400, "El valor del documento $numeroDoc no es válido."); }
                $concepto = isset($docConceptos[$i]) ? $docConceptos[$i] : '';
                if (!isset(Arqueo::$DOCUMENTOS_ACTIVOS[$concepto])) { arqError(400, "Elige el concepto del documento $numeroDoc."); }
                $fechaDoc = null;
                if (isset($docFechas[$i]) && trim($docFechas[$i]) !== '') {
                    $fechaDoc = HanaVal::fecha($docFechas[$i]);
                    if ($fechaDoc === '' || $fechaDoc > $fecha) { arqError(400, "La fecha del documento $numeroDoc no es válida o es posterior al arqueo."); }
                }
                $documentos += $valor;
                $lineas[] = $linea($concepto, $fechaDoc, $valor, HanaVal::texto(isset($docObservaciones[$i]) ? $docObservaciones[$i] : '', 300));
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
        $id = $modeloArqueo->insertar(array(
            'tipo' => $tipo, 'centro' => $idCentro, 'fecha' => $fecha, 'hora' => $hora, 'horaFin' => $horaFin,
            'responsable' => $responsable, 'cargo' => $cargo, 'colaborador' => $idColaborador, 'fondo' => $fondo,
            'billetes' => $billetes, //el total y la diferencia no se guardan: salen de los billetes y las líneas
            'observacion' => $observacion, 'ahora' => date('Y-m-d H:i:s')
        ), $lineas);
        if (!$id) { HanaDB::deshacer(); arqError(500, 'No se pudo guardar el arqueo. No quedó nada registrado; intenta de nuevo.'); }
        HanaDB::confirmar();

        echo hanaJsonTexto(array('ok' => true, 'id' => $id,
            'mensaje' => $diferencia == 0 ? 'Arqueo registrado. El fondo cuadra.'
                       : 'Arqueo registrado con una diferencia de $' . number_format(abs($diferencia), 0, ',', '.')
                         . ($diferencia < 0 ? ' (faltante).' : ' (sobrante).')), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Anular (no se borra: queda con el motivo)
    //-----------------------------------------------------------------------
    case 'anular':
        $arqueo = arqCargar($modeloArqueo, $centros, isset($_POST['id']) ? (int)$_POST['id'] : 0);
        if ((int)$arqueo['ESTADO'] !== 1) { arqError(400, 'Este arqueo ya estaba anulado.'); }
        if ((int)$arqueo['ID_COLABORADOR_ARQUEA'] !== $idColaborador && !$puedeAnularOtros) {
            arqError(403, 'Solo quien hizo el arqueo puede anularlo.');
        }
        $motivo = HanaVal::texto(isset($_POST['motivo']) ? $_POST['motivo'] : '', 300);
        if ($motivo === null) { arqError(400, 'Explica por qué se anula el arqueo.'); }
        if (!$modeloArqueo->anular($arqueo['ID_ARQUEO'], $motivo)) { arqError(500, 'No se pudo anular. Intenta de nuevo.'); }
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Arqueo anulado. Queda en el historial.'), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Fondos autorizados (casilla 36M)
    //-----------------------------------------------------------------------
    case 'fondos':
        if (!$puedeFondos) { arqError(403, 'Autorizar fondos necesita su casilla en Permisos de usuario.'); }
        $lista = $modeloArqueo->centrosConFondos();
        if ($centrosFondo !== null) { $lista = array_values(array_filter($lista, function ($centro) use ($centrosFondo) { return in_array((int)$centro['ID_CENTRO_OP'], $centrosFondo, true); })); }
        echo hanaJsonTexto($lista, JSON_UNESCAPED_UNICODE);
        break;

    //Guarda el fondo autorizado de un peaje (necesita el permiso 36M)
    case 'guardarFondo':
        if (!$puedeFondos) { arqError(403, 'Autorizar fondos necesita su casilla en Permisos de usuario.'); }
        $idCentro = isset($_POST['centro']) ? (int)$_POST['centro'] : 0;
        if ($centrosFondo !== null && !in_array($idCentro, $centrosFondo, true)) { arqError(403, 'Solo autorizas fondos de tus peajes.'); }
        $existe = Organizacion::centroActivo($idCentro);
        if (!$existe) { arqError(400, 'Ese centro no existe o está inactivo.'); }
        foreach (array_keys(Arqueo::$TIPOS) as $tipoFondo) {
            $crudo = isset($_POST[$tipoFondo]) ? trim($_POST[$tipoFondo]) : '';
            $valor = null;
            if ($crudo !== '') {
                $valor = HanaVal::pesos($crudo);
                if ($valor === null || $valor <= 0) { arqError(400, 'El fondo de ' . strtolower(Arqueo::$TIPOS[$tipoFondo]) . ' no es válido.'); }
            }
            if (!$modeloArqueo->guardarFondo($idCentro, $tipoFondo, $valor, $idColaborador, date('Y-m-d H:i:s'))) {
                arqError(500, 'No se pudo guardar el fondo. Intenta de nuevo.');
            }
        }
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Fondos guardados.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        arqError(400, 'Operación no reconocida.');
}
