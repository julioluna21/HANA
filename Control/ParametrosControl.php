<?php
/*
  HANA - Parámetros del sistema, listas de opciones y catálogos de ausentismo
  Permiso 24M (el administrador técnico). Aquí se cambian las decisiones del
  negocio sin tocar el código.
    listar, guardar                     los parámetros (tabla configuracion)
    listas, guardarLista                las listas de opciones (HanaConfig::LISTAS, también en configuracion)
    concepto                            el nombre de un concepto fijo de arqueo (arqueo_concepto)
    cargo, novedad                      los catálogos de ausentismo
    diasHabilitados, habilitarDia...    los días que se le abren a una persona
*/
session_start();
require_once __DIR__ . "/../Modelo/HanaConfig.php";
require_once __DIR__ . "/../Modelo/HanaFechas.php"; //el día de hoy en la hora de Colombia
require_once __DIR__ . "/../Modelo/ParametrosModelo.php";
require_once __DIR__ . "/../Modelo/BitacoraModelo.php";
require_once __DIR__ . "/AccesoHelper.php"; //incluye OrganizacionModelo

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

//Responde con un código de error y un mensaje en JSON, y corta la ejecución
function parError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { parError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!hanaTienePermiso('24M')) { parError(403, 'No tienes permiso para los parámetros del sistema.'); }
$idColaborador = (int)$_SESSION['Idcolaborador'];

//Si los correos están saliendo: si la cuenta está escrita en Conexion/Global.php y cómo le fue
//al último correo. Se muestra arriba en la pantalla, para que una cuenta sin contraseña no pase
//desapercibida (sin ella no sale ningún correo, tampoco el de "Olvidé mi contraseña")
function parEstadoCorreo()
{
    require_once __DIR__ . '/CorreoHelper.php';
    $cuenta = hanaCorreoDatos();
    $ultimo = HanaDB::fila("SELECT FECHA, MODULO, OK, ERROR FROM bitacora_sistema WHERE TIPO = 'CORREO' ORDER BY ID_BITACORA DESC LIMIT 1");
    return array('lista' => $cuenta !== null, 'cuenta' => $cuenta ? $cuenta['user'] : (defined('MAIL_USER') ? MAIL_USER : ''),
                 'ultimo' => $ultimo ? $ultimo : null);
}
$ahora = date('Y-m-d H:i:s');
$modeloParametros = new Parametros();

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //Todos los parámetros, más el catálogo de cargos (lo usa la promesa de valor), para la pantalla de Parámetros.
    //El ausentismo (y sus novedades/motivos) se retiró del sistema.
    case 'listar':
        $par = HanaConfig::lista();
        $cargos = $modeloParametros->cargos();
        echo json_encode(array('parametros' => $par ? $par : array(), 'cargos' => $cargos ? $cargos : array(),
                               'correo' => parEstadoCorreo()), JSON_UNESCAPED_UNICODE);
        break;

    //Guarda los parámetros que cambiaron: valores[CLAVE] = VALOR
    case 'guardar':
        $valores = isset($_POST['valores']) && is_array($_POST['valores']) ? $_POST['valores'] : array();
        $cambios = 0;
        foreach ($valores as $clave => $valor) {
            $definicion = HanaConfig::definicion((string)$clave);
            if (!$definicion) { parError(400, 'Parámetro desconocido: ' . $clave); }
            $valor = trim((string)$valor);
            if ($definicion['TIPO'] === 'SI_NO') {
                if ($valor !== '0' && $valor !== '1') { parError(400, '"' . $definicion['NOMBRE'] . '" debe ser sí o no.'); }
            } elseif ($definicion['TIPO'] === 'CORREOS') {
                //Uno o varios correos separados por coma; vacío = no se envía
                $correos = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $valor))));
                foreach ($correos as $correo) {
                    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) { parError(400, '"' . $definicion['NOMBRE'] . '": «' . $correo . '» no es un correo válido.'); }
                }
                $valor = implode(', ', $correos);
                if (strlen($valor) > 300) { parError(400, '"' . $definicion['NOMBRE'] . '": son demasiados correos.'); }
            } else {
                if (!preg_match('/^\d{1,4}$/', $valor)) { parError(400, '"' . $definicion['NOMBRE'] . '" debe ser un número entero.'); }
                if (($definicion['MINIMO'] !== null && (int)$valor < (int)$definicion['MINIMO']) || ($definicion['MAXIMO'] !== null && (int)$valor > (int)$definicion['MAXIMO'])) {
                    parError(400, '"' . $definicion['NOMBRE'] . '" debe estar entre ' . $definicion['MINIMO'] . ' y ' . $definicion['MAXIMO'] . '.');
                }
            }
            if ($valor === (string)HanaConfig::valor($clave, $definicion['DEFECTO'])) { continue; }
            HanaConfig::guardar($clave, $valor, $idColaborador, $ahora);
            $cambios++;
        }
        echo json_encode(array('ok' => true, 'mensaje' => $cambios ? ($cambios === 1 ? 'Se guardó 1 cambio.' : "Se guardaron $cambios cambios.") : 'No había cambios.'),
                         JSON_UNESCAPED_UNICODE);
        break;

    //Catálogo de cargos (lo usa la promesa de valor): crear o editar un cargo.
    //La opción 'novedad' (motivos del ausentismo) se retiró junto con el ausentismo.
    case 'novedad':
        parError(410, 'El ausentismo se retiró del sistema.');
    case 'cargo':
        $esCargo = true;
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $crudo = isset($_POST['nombre']) ? trim((string)$_POST['nombre']) : '';
        $crudo = function_exists('mb_strtoupper') ? mb_strtoupper($crudo, 'UTF-8')
               : strtr(strtoupper($crudo), array('á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ñ' => 'Ñ'));
        $nombre = HanaVal::texto($crudo, 80);
        if ($nombre === null) { parError(400, 'Escribe el nombre.'); }
        $orden = isset($_POST['orden']) ? (int)$_POST['orden'] : 0;
        $estado = isset($_POST['estado']) && $_POST['estado'] === '0' ? 0 : 1;
        if ($modeloParametros->nombreRepetido($esCargo, $nombre, $id)) { parError(400, 'Ya existe uno con ese nombre.'); }
        if ($esCargo) {
            $aplica = isset($_POST['aplica']) ? $_POST['aplica'] : 'PEAJE';
            if (!in_array($aplica, array('PEAJE', 'BASCULA', 'AMBOS'), true)) { parError(400, 'Elige en qué centros aplica.'); }
            $ok = $modeloParametros->guardarCargo($id, $nombre, $aplica, $orden, $estado);
        } else {
            $cuenta = isset($_POST['cuenta']) && $_POST['cuenta'] === '0' ? 0 : 1;
            $cargo = isset($_POST['cargo']) && (int)$_POST['cargo'] > 0 ? (int)$_POST['cargo'] : null;
            $ok = $modeloParametros->guardarNovedad($id, $nombre, $cuenta, $cargo, $orden, $estado);
        }
        if (!$ok) { parError(500, 'No se pudo guardar.'); }
        $msg = $esCargo ? ($id ? 'Cargo actualizado.' : 'Cargo creado.') : ($id ? 'Novedad actualizada.' : 'Novedad creada.');
        echo json_encode(array('ok' => true, 'mensaje' => $msg), JSON_UNESCAPED_UNICODE);
        break;

    //-----------------------------------------------------------------------
    // Días habilitados: el reporte diario solo se llena HOY. Si alguien
    // necesita registrar o corregir otro día, el administrador se lo abre
    // aquí y después se lo cierra (tabla dia_habilitado)
    //-----------------------------------------------------------------------
    //Las listas de opciones (medios, transportes, tipos del cronograma...) y los conceptos de arqueo
    case 'listas':
        $listas = array();
        foreach (HanaConfig::LISTAS as $clave => $def) {
            $usos = $modeloParametros->usosLista($def[5]);
            $opciones = array();
            foreach (HanaConfig::opciones($clave) as $codigo => $nombre) {
                $opciones[] = array('codigo' => $codigo, 'nombre' => $nombre, 'fijo' => in_array($codigo, $def[4], true),
                                    'usos' => isset($usos[$codigo]) ? $usos[$codigo] : 0);
            }
            $guardado = HanaDB::fila("SELECT c.FEC_MODIFICACION, col.NOM_COLABORADOR AS MODIFICO FROM configuracion c
                                       LEFT JOIN colaboradores col ON col.ID_COLABORADOR = c.ID_COLABORADOR_MODIFICA WHERE c.CLAVE = ?", 's', array($clave));
            $listas[] = array('clave' => $clave, 'grupo' => $def[0], 'nombre' => $def[1], 'ayuda' => $def[2], 'opciones' => $opciones,
                              'modifico' => $guardado ? $guardado['MODIFICO'] : null, 'fecha' => $guardado ? $guardado['FEC_MODIFICACION'] : null);
        }
        echo json_encode(array('listas' => $listas, 'conceptos' => $modeloParametros->conceptosArqueo(),
                               'maxOpciones' => HanaConfig::LISTA_MAX_OPCIONES, 'largoNombre' => HanaConfig::LISTA_LARGO_NOMBRE), JSON_UNESCAPED_UNICODE);
        break;

    //Guarda una lista completa: opciones = JSON [{codigo ('' si es nueva), nombre}, ...] en el orden de la pantalla
    case 'guardarLista':
        $clave = isset($_POST['clave']) ? (string)$_POST['clave'] : '';
        if (!isset(HanaConfig::LISTAS[$clave])) { parError(400, 'Esa lista no existe.'); }
        $def = HanaConfig::LISTAS[$clave];
        $llegaron = json_decode(isset($_POST['opciones']) ? (string)$_POST['opciones'] : '', true);
        if (!is_array($llegaron) || !count($llegaron)) { parError(400, 'La lista no puede quedar vacía.'); }
        if (count($llegaron) > HanaConfig::LISTA_MAX_OPCIONES) { parError(400, 'Son demasiadas opciones (máximo ' . HanaConfig::LISTA_MAX_OPCIONES . ').'); }
        $actual = HanaConfig::opciones($clave);
        $nueva = array(); $nombresVistos = array();
        foreach ($llegaron as $opcion) {
            $nombre = trim(preg_replace('/\s+/u', ' ', isset($opcion['nombre']) ? strip_tags((string)$opcion['nombre']) : ''));
            if ($nombre === '') { parError(400, 'Hay una opción sin nombre.'); }
            if (mb_strlen($nombre, 'UTF-8') > HanaConfig::LISTA_LARGO_NOMBRE) { parError(400, '«' . $nombre . '» pasa de ' . HanaConfig::LISTA_LARGO_NOMBRE . ' caracteres.'); }
            $llaveNombre = mb_strtolower($nombre, 'UTF-8');
            if (isset($nombresVistos[$llaveNombre])) { parError(400, '«' . $nombre . '» está dos veces.'); }
            $nombresVistos[$llaveNombre] = true;
            $codigo = isset($opcion['codigo']) ? (string)$opcion['codigo'] : '';
            if ($codigo !== '' && !isset($actual[$codigo])) { parError(400, 'La lista cambió mientras la editabas. Recarga la página.'); }
            if ($codigo === '') {
                //El código de una opción nueva sale del nombre: sin tildes, en mayúsculas y del largo de su columna
                $base = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre)));
                $base = trim(substr(trim($base, '_'), 0, $def[3]), '_');
                if ($base === '') { $base = 'OPCION'; }
                $codigo = $base; $n = 2;
                while (isset($actual[$codigo]) || isset($nueva[$codigo])) { $sufijo = '_' . $n++; $codigo = substr($base, 0, $def[3] - strlen($sufijo)) . $sufijo; }
            }
            if (isset($nueva[$codigo])) { parError(400, 'Hay una opción repetida.'); }
            $nueva[$codigo] = $nombre;
        }
        //Lo que se quitó: los códigos fijos no se quitan, ni los que ya están en algún registro
        $usos = $modeloParametros->usosLista($def[5]);
        foreach ($actual as $codigo => $nombre) {
            if (isset($nueva[$codigo])) { continue; }
            if (in_array($codigo, $def[4], true)) { parError(400, '«' . $nombre . '» no se puede quitar: el sistema la usa en sus reglas. Sí se le puede cambiar el nombre.'); }
            if (!empty($usos[$codigo])) { parError(400, '«' . $nombre . '» no se puede quitar: está en ' . $usos[$codigo] . ($usos[$codigo] === 1 ? ' registro' : ' registros') . '. Cámbiale el nombre si quieres que se vea distinto.'); }
        }
        $pares = array();
        foreach ($nueva as $codigo => $nombre) { $pares[] = array((string)$codigo, $nombre); }
        $valor = json_encode($pares, JSON_UNESCAPED_UNICODE);
        if (strlen($valor) > 2000) { parError(400, 'La lista es demasiado larga. Acorta algunos nombres o quita opciones.'); }
        //La lista se guarda en configuracion.VALOR. Si no cabe en esa columna (en algunas bases es de 255
        //caracteres), en algunos servidores se cortaría sin avisar y quedaría dañada: se avisa antes de guardar
        $capacidad = HanaDB::fila("SELECT CHARACTER_MAXIMUM_LENGTH AS N FROM information_schema.COLUMNS
                                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'configuracion' AND COLUMN_NAME = 'VALOR'");
        if ($capacidad && mb_strlen($valor, 'UTF-8') > (int)$capacidad['N']) {
            parError(400, 'La lista no cabe: la base guarda hasta ' . (int)$capacidad['N'] . ' caracteres y esta lista ocupa ' . mb_strlen($valor, 'UTF-8') .
                          '. Acorta algunos nombres o quita opciones.');
        }
        if (!HanaConfig::guardar($clave, $valor, $idColaborador, $ahora)) { parError(500, 'No se pudo guardar la lista. Intenta de nuevo.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Lista guardada.'), JSON_UNESCAPED_UNICODE);
        break;

    //El nombre de un concepto fijo del arqueo de caja menor o de recambio
    case 'concepto':
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $nombre = trim(preg_replace('/\s+/u', ' ', isset($_POST['nombre']) ? strip_tags((string)$_POST['nombre']) : ''));
        if ($id <= 0 || $nombre === '') { parError(400, 'Escribe el nombre del concepto.'); }
        if (mb_strlen($nombre, 'UTF-8') > 80) { parError(400, 'El nombre pasa de 80 caracteres.'); }
        if (!$modeloParametros->nombrarConcepto($id, $nombre)) { parError(500, 'No se pudo guardar el nombre.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Nombre guardado.'), JSON_UNESCAPED_UNICODE);
        break;

    case 'diasHabilitados':
        //Las personas a las que se les puede abrir un día: primero quienes coordinan un proyecto
        $personas = Organizacion::personasConProyectos();
        //Los días: arriba los que siguen abiertos; debajo los ya cerrados, para que quede el rastro
        $dias = $modeloParametros->diasHabilitados();
        if ($dias === false) {
            error_log('HANA - no se pudo leer la tabla dia_habilitado'); //el detalle queda para el administrador
            parError(500, 'Los días habilitados no están disponibles en este momento. Avísale al administrador del sistema.');
        }
        echo json_encode(array('hoy' => HanaFechas::hoy(), 'personas' => $personas ? $personas : array(), 'dias' => $dias),
                         JSON_UNESCAPED_UNICODE);
        break;

    //Abre un día a una persona. Si ya se le había abierto y cerrado, se vuelve a abrir la misma fila
    case 'habilitarDia':
        $persona = isset($_POST['persona']) ? (int)$_POST['persona'] : 0;
        $fecha = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        $motivo = HanaVal::texto(isset($_POST['motivo']) ? $_POST['motivo'] : '', 200);
        if (!Organizacion::colaboradorActivo($persona)) {
            parError(400, 'Elige a quién se le habilita el día.');
        }
        if ($fecha === '') { parError(400, 'Elige el día que se va a habilitar.'); }
        $hoyCol = HanaFechas::hoy();
        if ($fecha === $hoyCol) { parError(400, 'Hoy siempre está abierto: no hace falta habilitarlo.'); }
        //Un tope razonable, para que un error de dedo no abra un día de otro año
        if ($fecha < date('Y-m-d', strtotime("$hoyCol -366 days")) || $fecha > date('Y-m-d', strtotime("$hoyCol +31 days"))) {
            parError(400, 'El día debe estar entre un año atrás y un mes adelante.');
        }
        if ($modeloParametros->estadoDia($persona, $fecha) === 1) { parError(400, 'Esa persona ya tiene habilitado ese día.'); }
        $ok = $modeloParametros->habilitarDia($persona, $fecha, $motivo, $idColaborador, $ahora);
        if (!$ok) { parError(500, 'No se pudo habilitar el día.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Día habilitado. La persona ya puede registrar o corregir ese día.'), JSON_UNESCAPED_UNICODE);
        break;

    //Cierra un día que estaba abierto. La fila se conserva con quién y cuándo lo cerró
    case 'deshabilitarDia':
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $estadoDia = $modeloParametros->estadoDiaPorId($id);
        if ($estadoDia === null) { parError(404, 'Ese día habilitado no existe.'); }
        if ($estadoDia === 0) { parError(400, 'Ese día ya estaba deshabilitado.'); }
        $ok = $modeloParametros->deshabilitarDia($id, $idColaborador, $ahora);
        if (!$ok) { parError(500, 'No se pudo deshabilitar el día.'); }
        echo json_encode(array('ok' => true, 'mensaje' => 'Día deshabilitado. Vuelve a quedar solo de consulta.'), JSON_UNESCAPED_UNICODE);
        break;

    default:
        parError(400, 'Operación no reconocida.');
}
