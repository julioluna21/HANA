<?php
/*
  HANA - Conexión a la base de datos
  ---------------------------------------------------------------------------
  Abre la conexión ($conexion) con los datos de Conexion/Global.php y deja las
  funciones de consulta que usan los modelos de configuración, listas y novedades (ejecutarConsulta, limpiarCadena...).
  El reporte diario y el ausentismo usan Modelo/HanaDB.php, que trabaja con consultas preparadas.
*/
    /*
      Si falta Global.php o la base no responde, no se muestra el error crudo de PHP
      ("<br /><b>Fatal error</b>..."), que rompería las pantallas que esperan JSON
      (por ejemplo el login). Se responde un mensaje claro: en JSON si lo pidió una
      pantalla (AJAX), o en una página sencilla si se abrió directo en el navegador.
    */
    if (!function_exists('hanaFalloConexion')) {
        //Registra el error en el log y responde un mensaje entendible (JSON o página) en vez del error de PHP
        function hanaFalloConexion($mensaje)
        {
            error_log('HANA - ' . $mensaje);
            if (!headers_sent()) { http_response_code(500); }
            $ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                 || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
            if ($ajax) {
                if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
                echo json_encode(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
            } else {
                if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
                echo '<div style="font-family:Arial,sans-serif;max-width:560px;margin:40px auto;padding:18px 22px;border:1px solid #ECE6E1;border-radius:12px;">'
                   . '<h3 style="color:#6E1A1E;margin-top:0;">HANA no pudo conectarse</h3><p>' . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') . '</p></div>';
            }
            exit();
        }
    }

    //Los datos de conexión viven en Global.php (no viene en el proyecto: se copia de Global.ejemplo.php)
    if (!is_file(__DIR__ . '/Global.php')) {
        hanaFalloConexion('Falta el archivo Conexion/Global.php. Cópialo de Conexion/Global.ejemplo.php y llena los datos de la base de datos (en XAMPP: usuario root, sin clave, base bd_hana_app).');
    }
    //require_once: si Global.php ya se cargó, no se vuelve a cargar
    require_once __DIR__ . '/Global.php';

    //Desde PHP 8.1 mysqli lanza una excepción si no puede conectarse: se atrapa para dar un mensaje claro
    try {
        $conexion = new mysqli(DB_HOST,DB_USERNAME,DB_PASSWORD,DB_NAME); //los datos vienen de Global.php
    } catch (\Throwable $error) {
        hanaFalloConexion('No se pudo conectar a la base de datos "' . DB_NAME . '": ' . $error->getMessage()
                          . '. Revisa DB_HOST, DB_USERNAME, DB_PASSWORD y DB_NAME en Conexion/Global.php, y que la base esté importada.');
    }
    if (mysqli_connect_errno()){
        hanaFalloConexion('No se pudo conectar a la base de datos: ' . mysqli_connect_error());
    }
        
    mysqli_query( $conexion, 'SET NAMES "'.DB_ENCODE.'"'); //codificación de los textos (DB_ENCODE, en Global.php)
    //Hora de Colombia también para la base: NOW() y CURDATE() dan la misma hora que date() en PHP
    mysqli_query( $conexion, "SET time_zone = '-05:00'");

    //Las funciones se definen una sola vez, aunque este archivo se cargue desde varios lugares
    if (!function_exists('ejecutarConsulta'))
    {
            //Ejecuta una consulta y devuelve su resultado (false si falló)
            function ejecutarConsulta($sql)
            {
                    global $conexion; //la conexión abierta arriba
                    $resultado = $conexion->query($sql);
                    return $resultado;
            }

            //Ejecuta una consulta y devuelve solo su primera fila (null si no hay ninguna)
            function ejecutarConsultaSimpleFila($sql)
            {
                    global $conexion;
                    $resultado = $conexion->query($sql);
                    $fila = $resultado->fetch_assoc();
                    return $fila;
            }

            //Ejecuta un INSERT y devuelve el ID que la base le dio al registro nuevo
            function ejecutarConsulta_retornarID($sql)
            {
                    global $conexion;
                    $conexion->query($sql);
                    return $conexion->insert_id;
            }

            //Limpia un dato que viene del formulario antes de meterlo en una consulta:
            //quita los espacios de los extremos, escapa las comillas y las barras para la
            //consulta, y convierte < > & " en texto para que no se ejecute como HTML
            function limpiarCadena($texto)
            {
                    global $conexion;
                    $texto = mysqli_real_escape_string($conexion, trim($texto));
                    return htmlspecialchars($texto);
            }
    }