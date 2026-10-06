<?php
/*
  HANA — Datos para Power BI
  ---------------------------------------------------------------------------
  Usuario de conexión (lo crea BD/06_POWERBI.sql, rol LECTOR POWER BI, solo lee):
      usuario:    powerbi
      contraseña: HanaPBI-Regency-2026
  El reporte HANA_Ausentismo_Nacional ya trae estos datos escritos en su consulta.
  Si se cambia la contraseña en HANA (Configuración → Usuarios), hay que cambiarla
  también en el reporte (Transformar datos → Consulta → Editor avanzado).

  Power BI se conecta aquí (Obtener datos → Web) con autenticación "Básica":
  el usuario y la contraseña de un usuario de HANA. En cada actualización se
  verifica ese usuario, igual que en el inicio de sesión:
    - que exista, esté activo y la contraseña sea la correcta;
    - que su rol tenga el permiso 27M "Leer datos desde Power BI";
    - que el acceso esté encendido en Parámetros (POWERBI_ACTIVO).
  La base de datos no se abre al exterior: Power BI solo recibe estas tablas.

  Qué entrega (?datos=...):
    ausentismo  (por defecto) la tabla "Consulta" del reporte AUSENTISMO_NACIONAL:
                Periodo ("2026-08"), PeriodoOrden, Concesion, Estacion, TipoEstacion,
                Cargo, Novedad, Fecha, Anio, Mes, Dia, DiaTxt ("01"), Quincena, Cantidad
    novedades   las novedades registradas (vw_novedades_bi)
  Filtros opcionales: &desde=2026-08 &hasta=2026-09 (año-mes)

  Seguridad extra: cada acceso queda en bitacora_sistema (TIPO = 'POWERBI'); con 10 intentos fallidos
  desde la misma dirección en 15 minutos, se bloquea esa dirección un rato.
*/
require_once __DIR__ . '/../Modelo/HanaConfig.php'; //incluye HanaDB y la conexión (limpiarCadena)
date_default_timezone_set('America/Bogota');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pbiFin($codigo, $mensaje)
{
    http_response_code($codigo);
    if ($codigo === 401) { header('WWW-Authenticate: Basic realm="HANA - Power BI", charset="UTF-8"'); }
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

function pbiRegistrar($usuario, $ip, $datos, $ok, $filas = null)
{
    try {
        HanaDB::q("INSERT INTO bitacora_sistema (TIPO, FECHA, MODULO, QUIEN, DETALLE, OK, FILAS)
                   VALUES ('POWERBI', NOW(), ?, ?, ?, ?, ?)",
                  'sssii', array(substr($datos, 0, 30), substr($usuario, 0, 60), substr($ip, 0, 45), $ok ? 1 : 0, $filas));
    } catch (\Throwable $e) { error_log('HANA - bitácora Power BI: ' . $e->getMessage()); }
}

$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$datos = isset($_GET['datos']) ? strtolower($_GET['datos']) : 'ausentismo';
if (!in_array($datos, array('ausentismo', 'novedades'), true)) { pbiFin(400, 'datos debe ser ausentismo o novedades.'); }

//1. ¿Está encendido?
if (!HanaConfig::si('POWERBI_ACTIVO')) { pbiFin(403, 'El acceso de Power BI está apagado en Parámetros del sistema.'); }

//2. Bloqueo por intentos fallidos (10 en 15 minutos desde la misma dirección)
$fallos = HanaDB::fila("SELECT COUNT(*) AS n FROM bitacora_sistema
                         WHERE TIPO = 'POWERBI' AND DETALLE = ? AND OK = 0 AND FECHA > NOW() - INTERVAL 15 MINUTE", 's', array($ip));
if ($fallos && (int)$fallos['n'] >= 10) { pbiFin(429, 'Demasiados intentos fallidos. Espera 15 minutos.'); }

//3. Usuario y contraseña (autenticación Básica). En algunos hostings el encabezado
//   llega como HTTP_AUTHORIZATION o REDIRECT_HTTP_AUTHORIZATION (ver Control/.htaccess)
$usuario = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
$clave = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
if ($usuario === '') {
    foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $k) {
        if (!empty($_SERVER[$k]) && stripos($_SERVER[$k], 'basic ') === 0) {
            $par = explode(':', (string)base64_decode(substr($_SERVER[$k], 6)), 2);
            $usuario = $par[0]; $clave = isset($par[1]) ? $par[1] : '';
            break;
        }
    }
}
if ($usuario === '') { pbiFin(401, 'Conéctate con autenticación Básica: usuario y contraseña de HANA.'); }

//La misma verificación del inicio de sesión: la clave pasa por limpiarCadena y SHA-256
$claveHash = hash('SHA256', limpiarCadena($clave));
$u = HanaDB::fila("SELECT u.ID_USUARIO_SISTEMA, r.MODULOS_ROL_USUARIOS_SISTEMAS AS MODULOS
                     FROM usuarios_sistema u
                     INNER JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA
                    WHERE u.NOM_USUARIO_SISTEMA = ? AND u.CLAVE_USUARIO_SISTEMA = ? AND u.ESTADO = 1 AND r.ESTADO = 1",
                  'ss', array(limpiarCadena($usuario), $claveHash));
if (!$u) { pbiRegistrar($usuario, $ip, $datos, false); pbiFin(401, 'Usuario o contraseña incorrectos, o el usuario está inactivo.'); }
if (!in_array('27M', explode(',', (string)$u['MODULOS']), true)) {
    pbiRegistrar($usuario, $ip, $datos, false);
    pbiFin(403, 'Ese usuario no tiene el permiso 27M "Leer datos desde Power BI".');
}

//4. Los datos (con el periodo opcional)
$donde = ''; $tipos = ''; $params = array();
foreach (array('desde' => '>=', 'hasta' => '<=') as $k => $op) {
    if (!empty($_GET[$k])) {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $_GET[$k], $m)) { pbiFin(400, $k . ' debe ser año-mes, por ejemplo 2026-08.'); }
        $col = $datos === 'ausentismo' ? 'PeriodoOrden' : '(Anio * 100 + Mes)';
        $donde .= ($donde === '' ? ' WHERE ' : ' AND ') . "$col $op ?"; $tipos .= 'i'; $params[] = (int)($m[1] . $m[2]);
    }
}
if ($datos === 'ausentismo') {
    $sql = "SELECT Periodo, PeriodoOrden, Concesion, Estacion, TipoEstacion, Cargo, Novedad, Fecha, Anio, Mes, Dia, DiaTxt, Quincena, Cantidad
              FROM vw_pbi_ausentismo" . $donde . " ORDER BY PeriodoOrden, Concesion, Estacion, Fecha";
} else {
    $sql = "SELECT * FROM vw_novedades_bi" . $donde;
}
$filas = HanaDB::q($sql, $tipos, $params);
if ($filas === false) { pbiFin(500, 'No se pudieron leer los datos. Revisa que los scripts 03 y 06 estén corridos.'); }

//Números como números (Power BI los tipa después, pero así llegan limpios)
foreach ($filas as $i => $f) {
    foreach (array('PeriodoOrden', 'Anio', 'Mes', 'Dia', 'Quincena', 'Cantidad') as $k) {
        if (isset($f[$k])) { $filas[$i][$k] = (int)$f[$k]; }
    }
}
pbiRegistrar($usuario, $ip, $datos, true, count($filas));
echo json_encode($filas, JSON_UNESCAPED_UNICODE);
