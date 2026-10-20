<?php
/*
  HANA - Datos para Power BI
  ---------------------------------------------------------------------------
  Usuario de conexión (rol LECTOR POWER BI, solo lee):
      usuario:    powerbi
      contraseña: la tiene el administrador (no se escribe en el código)
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

  Qué entrega: solo el ausentismo (vw_pbi_ausentismo), la tabla "Consulta" del
  reporte AUSENTISMO_NACIONAL: Periodo ("2026-08"), PeriodoOrden, Concesión,
  Estación, TipoEstacion, Cargo, Novedad, Fecha, Anio, Mes, Día, DiaTxt ("01"),
  Quincena, Cantidad. Las novedades no van a Power BI.
  Filtros opcionales: &desde=2026-08 &hasta=2026-09 (año-mes)

  Seguridad extra: cada acceso queda en bitacora_sistema (TIPO = 'POWERBI'); con 10 intentos fallidos
  desde la misma dirección en 15 minutos, se bloquea esa dirección un rato.
*/
require_once __DIR__ . '/../Modelo/HanaConfig.php'; //incluye HanaDB y la conexión (limpiarCadena)
require_once __DIR__ . '/../Modelo/BitacoraModelo.php';
require_once __DIR__ . '/../Modelo/PowerBiModelo.php';
require_once __DIR__ . '/../Modelo/HanaClave.php';   //revisar la clave igual que al iniciar sesión
date_default_timezone_set('America/Bogota');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

//Responde con el error a Power BI y corta (si es 401 le pide usuario y clave)
function pbiFin($codigo, $mensaje)
{
    http_response_code($codigo);
    if ($codigo === 401) { header('WWW-Authenticate: Basic realm="HANA - Power BI", charset="UTF-8"'); }
    echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//Deja en la bitácora cada consulta de Power BI, haya salido bien o mal
function pbiRegistrar($usuario, $ip, $datos, $ok, $filas = null, $idColaborador = null)
{
    try {
        Bitacora::powerBi($datos, $usuario, $ip, $ok, $filas, $idColaborador);
    } catch (\Throwable $error) { error_log('HANA - bitácora Power BI: ' . $error->getMessage()); }
}

$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
//Power BI solo lee el ausentismo. ?datos= se sigue aceptando por compatibilidad, pero solo con ese valor
$datos = isset($_GET['datos']) ? strtolower($_GET['datos']) : 'ausentismo';
if ($datos !== 'ausentismo') { pbiFin(400, 'Power BI solo lee el ausentismo (datos=ausentismo).'); }

//1. ¿Está encendido?
if (!HanaConfig::si('POWERBI_ACTIVO')) { pbiFin(403, 'El acceso de Power BI está apagado en Parámetros del sistema.'); }

//2. Bloqueo por intentos fallidos (10 en 15 minutos desde la misma dirección)
if (Bitacora::fallosPowerBi($ip) >= 10) { pbiFin(429, 'Demasiados intentos fallidos. Espera 15 minutos.'); }

//3. Usuario y contraseña (autenticación Básica). En algunos hostings el encabezado
//   llega como HTTP_AUTHORIZATION o REDIRECT_HTTP_AUTHORIZATION (ver Control/.htaccess)
$usuario = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
$clave = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
if ($usuario === '') {
    foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $encabezado) {
        if (!empty($_SERVER[$encabezado]) && stripos($_SERVER[$encabezado], 'basic ') === 0) {
            $partes = explode(':', (string)base64_decode(substr($_SERVER[$encabezado], 6)), 2);
            $usuario = $partes[0]; $clave = isset($partes[1]) ? $partes[1] : '';
            break;
        }
    }
}
if ($usuario === '') { pbiFin(401, 'Conéctate con autenticación Básica: usuario y contraseña de HANA.'); }

//La misma revisión de la clave que al iniciar sesión (Modelo/HanaClave.php). Si el usuario
//no existe se revisa igual contra una clave de relleno, para que tarde lo mismo
$persona = PowerBi::usuario(trim($usuario));
$claveBien = HanaClave::coincide($clave, $persona ? $persona['CLAVE'] : HanaClave::CLAVE_DE_RELLENO) && $persona;
if (!$claveBien) { pbiRegistrar($usuario, $ip, $datos, false); pbiFin(401, 'Usuario o contraseña incorrectos, o el usuario está inactivo.'); }
//Si la clave estaba guardada en el formato de antes, se guarda en el nuevo (como al iniciar sesión)
if (HanaClave::hayQueRecifrar($persona['CLAVE'])) { PowerBi::guardarClave($persona['ID_COLABORADOR'], HanaClave::cifrar($clave)); }
if (!in_array('27M', explode(',', (string)$persona['MODULOS']), true)) {
    pbiRegistrar($usuario, $ip, $datos, false, null, $persona['ID_COLABORADOR']);
    pbiFin(403, 'Ese usuario no tiene el permiso 27M "Leer datos desde Power BI".');
}

//4. Los datos (con el periodo opcional)
$periodo = array('desde' => 0, 'hasta' => 0);
foreach (array_keys($periodo) as $limite) {
    if (!empty($_GET[$limite])) {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $_GET[$limite], $partes)) { pbiFin(400, $limite . ' debe ser año-mes, por ejemplo 2026-08.'); }
        $periodo[$limite] = (int)($partes[1] . $partes[2]);
    }
}
$filas = PowerBi::datos($datos, $periodo['desde'], $periodo['hasta']);
if ($filas === false) { pbiFin(500, 'No se pudieron leer los datos. Revisa que la vista vw_pbi_ausentismo exista en la base.'); }

//Números como números (Power BI los tipa después, pero así llegan limpios)
foreach ($filas as $i => $fila) {
    foreach (array('PeriodoOrden', 'Anio', 'Mes', 'Dia', 'Quincena', 'Cantidad') as $columna) {
        if (isset($fila[$columna])) { $filas[$i][$columna] = (int)$fila[$columna]; }
    }
}
pbiRegistrar($usuario, $ip, $datos, true, count($filas), $persona['ID_COLABORADOR']);
echo json_encode($filas, JSON_UNESCAPED_UNICODE);
