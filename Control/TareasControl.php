<?php
/*
  HANA - Correos automáticos: configuración y prueba (Reporte diario → Correos automáticos)
  ---------------------------------------------------------------------------
  Con sesión y la casilla de recordatorios y avisos automáticos (57M; el ADMIN TEC también):
    op=listar             las tareas, su configuración, los roles y cuándo corrió cada una
    op=guardar (POST)     la configuración de una tarea: clave + config (JSON)
    op=simular (POST)     a quién le llegaría hoy, sin enviar nada
    op=ejecutar (POST)    la envía ya (queda en la bitácora)

  Sin sesión, para un servicio externo o el cron de cPanel que llama una dirección:
    op=programador&token=LA_CLAVE   hace lo mismo que tareas/programador.php
  LA_CLAVE es HANA_TOKEN_TAREAS de Conexion/Global.php (mínimo 16 caracteres).
  Lo que hace cada tarea está en tareas/libreria.php; su configuración, en Modelo/TareasModelo.php.
*/
session_start();
require_once __DIR__ . '/../Modelo/HanaTexto.php';
require_once __DIR__ . '/AccesoHelper.php';
chdir(__DIR__); //la librería de tareas carga archivos con rutas relativas a Control
require_once __DIR__ . '/../tareas/libreria.php';
header('Content-Type: application/json; charset=utf-8');

function tarError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

$op = isset($_GET['op']) ? (string)$_GET['op'] : '';

//El programador por dirección: solo con la clave de Global.php
if ($op === 'programador') {
    $token = isset($_GET['token']) ? (string)$_GET['token'] : '';
    $clave = defined('HANA_TOKEN_TAREAS') ? (string)HANA_TOKEN_TAREAS : '';
    if (strlen($clave) < 16 || stripos($clave, 'CAMBIA_ESTO') !== false || !hash_equals($clave, $token)) { tarError(403, 'Acceso no permitido.'); }
    $hechas = array();
    foreach (array_keys(Tareas::DEFINICIONES) as $tarea) {
        if (!Tareas::leToca($tarea, time())) { continue; }
        $resultado = tareaEjecutar($tarea, false, 'programador (dirección)');
        $hechas[$tarea] = array('enviados' => $resultado['enviados'], 'fallos' => $resultado['fallos']);
    }
    echo hanaJsonTexto(array('ok' => true, 'hechas' => (object)$hechas), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { tarError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
if (!hanaPuede('TAREAS', 'editar')) { tarError(403, 'Los recordatorios y avisos automáticos necesitan su casilla en Permisos de usuario.'); }
$idColaborador = (int)$_SESSION['Idcolaborador'];
$nombreSesion = isset($_SESSION['Nombre']) ? html_entity_decode($_SESSION['Nombre'], ENT_QUOTES, 'UTF-8') : '';

//La tarea pedida (o 400)
function tarClave()
{
    $clave = isset($_POST['clave']) ? (string)$_POST['clave'] : '';
    if (!isset(Tareas::DEFINICIONES[$clave])) { tarError(400, 'Esa tarea no existe.'); }
    return $clave;
}

switch ($op) {
    case 'listar':
        $ultimas = Tareas::ultimas();
        $tareas = array();
        foreach (Tareas::DEFINICIONES as $clave => $def) {
            $tareas[] = array('clave' => $clave, 'nombre' => $def['nombre'], 'descripcion' => $def['descripcion'], 'entrega' => $def['entrega'],
                              'campos' => $def['campos'], 'items' => isset($def['items']) ? $def['items'] : null,
                              'config' => Tareas::config($clave), 'ultima' => isset($ultimas[$clave]) ? $ultimas[$clave] : null);
        }
        //Si el programador está corriendo: la última vez que alguna tarea se hizo sola
        $ultimoProgramador = HanaDB::fila("SELECT MAX(FECHA) AS F FROM bitacora_sistema WHERE TIPO = 'TAREA' AND QUIEN LIKE 'programador%'");
        $personas = array_map(function ($p) { return array('ID' => (int)$p['ID'], 'NOMBRE' => html_entity_decode($p['NOMBRE'], ENT_QUOTES, 'UTF-8'),
                                                         'ROL' => $p['ROL'], 'SIN_CORREO' => !filter_var(trim((string)$p['CORREO']), FILTER_VALIDATE_EMAIL)); }, Tareas::personasActivas());
        echo hanaJsonTexto(array('tareas' => $tareas, 'roles' => Tareas::roles(), 'personas' => $personas, 'correoListo' => hanaCorreoDatos() !== null,
                                 'ultimoProgramador' => $ultimoProgramador ? $ultimoProgramador['F'] : null), JSON_UNESCAPED_UNICODE);
        break;

    case 'guardar':
        $clave = tarClave();
        $def = Tareas::DEFINICIONES[$clave];
        $llega = json_decode(isset($_POST['config']) ? (string)$_POST['config'] : '', true);
        if (!is_array($llega)) { tarError(400, 'No llegó la configuración.'); }
        $config = Tareas::config($clave);
        $config['activo'] = !empty($llega['activo']) ? 1 : 0;
        if (in_array('hora', $def['campos'], true)) {
            $hora = isset($llega['hora']) ? (int)$llega['hora'] : -1;
            if ($hora < 0 || $hora > 23) { tarError(400, 'Elige la hora (de 0 a 23).'); }
            $config['hora'] = $hora;
        }
        if (in_array('dias', $def['campos'], true)) {
            $config['dias'] = array_values(array_intersect(array(1, 2, 3, 4, 5, 6, 7), array_map('intval', (array)(isset($llega['dias']) ? $llega['dias'] : array()))));
            if (!count($config['dias']) && $config['activo']) { tarError(400, 'Marca al menos un día, o apágala.'); }
        }
        if (in_array('roles', $def['campos'], true)) {
            $validos = array_map('intval', array_column(Tareas::roles(), 'ID'));
            $config['roles'] = array_values(array_intersect($validos, array_map('intval', (array)(isset($llega['roles']) ? $llega['roles'] : array()))));
        }
        if (in_array('items', $def['campos'], true)) {
            $config['items'] = array_values(array_intersect(array_keys($def['items']), (array)(isset($llega['items']) ? $llega['items'] : array())));
            if (!count($config['items']) && $config['activo']) { tarError(400, 'Marca al menos una cosa que se revise, o apágalo.'); }
        }
        if (in_array('personas', $def['campos'], true)) {
            $validas = array_map(function ($p) { return (int)$p['ID']; }, Tareas::personasActivas());
            $config['personas'] = array_values(array_intersect($validas, array_map('intval', (array)(isset($llega['personas']) ? $llega['personas'] : array()))));
        }
        if (in_array('momento', $def['campos'], true)) { $config['momento'] = (isset($llega['momento']) && $llega['momento'] === 'SIGUIENTE_DIA') ? 'SIGUIENTE_DIA' : 'INMEDIATO'; }
        if (in_array('correo', $def['campos'], true)) { $config['correo'] = !empty($llega['correo']) ? 1 : 0; }
        if (in_array('coordinador', $def['campos'], true)) { $config['coordinador'] = !empty($llega['coordinador']) ? 1 : 0; }
        if ($clave === 'PROMESA_AVISO' && $config['activo'] && !$config['coordinador'] && !count($config['roles']) && !count($config['personas'])) {
            tarError(400, 'Elige a quién le llega el aviso (personas, roles o el coordinador del proyecto), o apágalo.');
        }
        if (in_array('correos', $def['campos'], true)) {
            $correos = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', isset($llega['correos']) ? (string)$llega['correos'] : ''))));
            foreach ($correos as $correo) { if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) { tarError(400, '«' . $correo . '» no es un correo válido.'); } }
            $config['correos'] = implode(', ', $correos);
        }
        if (in_array('nota', $def['campos'], true)) {
            $nota = trim(strip_tags(isset($llega['nota']) ? (string)$llega['nota'] : ''));
            if (mb_strlen($nota, 'UTF-8') > 500) { tarError(400, 'La nota pasa de 500 caracteres.'); }
            $config['nota'] = $nota;
        }
        if (!Tareas::guardar($clave, $config, $idColaborador, date('Y-m-d H:i:s'))) { tarError(500, 'No se pudo guardar. ¿Ya se corrió el script 42?'); }
        echo hanaJsonTexto(array('ok' => true, 'mensaje' => 'Guardado.'), JSON_UNESCAPED_UNICODE);
        break;

    case 'simular':
        $clave = tarClave();
        if ($clave === 'PROMESA_AVISO') {
            //El aviso de la campana no se envía: se dice quién lo vería
            $config = Tareas::config($clave);
            $lineas = array(Tareas::DEFINICIONES[$clave]['nombre'] . ($config['activo'] ? '' : ' (está apagado)'),
                            $config['momento'] === 'INMEDIATO' ? 'Aparece en la campana en cuanto se registra la promesa por debajo.'
                                                               : 'Aparece en la campana desde las ' . $config['hora'] . ':00 del día siguiente.');
            $lineas[] = $config['correo'] ? 'También llega por correo en ese momento.' : 'Solo en la campana (sin correo).';
            if ($config['coordinador']) { $lineas[] = 'Lo ve el coordinador de cada proyecto, de sus peajes.'; }
            foreach (Tareas::destinatarios($config) as $persona) {
                $correo = trim((string)$persona['MAIL_COLABORADOR']);
                $lineas[] = 'Lo ve ' . html_entity_decode($persona['NOM_COLABORADOR'], ENT_QUOTES, 'UTF-8') . ', de todos los proyectos'
                          . ($config['correo'] ? (filter_var($correo, FILTER_VALIDATE_EMAIL) ? ' (correo: ' . $correo . ')' : ' (no tiene correo registrado)') : '') . '.';
            }
            echo hanaJsonTexto(array('lineas' => $lineas), JSON_UNESCAPED_UNICODE);
            break;
        }
        $resultado = tareaEjecutar($clave, true, 'simulación');
        echo hanaJsonTexto(array('lineas' => $resultado['lineas']), JSON_UNESCAPED_UNICODE);
        break;

    case 'ejecutar':
        $clave = tarClave();
        if (Tareas::DEFINICIONES[$clave]['entrega'] !== 'CORREO') { tarError(400, 'Esta tarea no envía correos.'); }
        if (!Tareas::config($clave)['activo']) { tarError(400, 'Está apagada. Enciéndela y guarda antes de enviarla.'); }
        $resultado = tareaEjecutar($clave, false, 'Enviar ahora: ' . $nombreSesion);
        echo hanaJsonTexto(array('ok' => !$resultado['fallos'], 'lineas' => $resultado['lineas'],
                                 'mensaje' => $resultado['enviados'] . ' correo(s) enviado(s)' . ($resultado['fallos'] ? ', ' . $resultado['fallos'] . ' con error.' : '.')), JSON_UNESCAPED_UNICODE);
        break;

    default:
        tarError(400, 'Operación no reconocida.');
}
