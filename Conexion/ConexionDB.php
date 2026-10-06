<?php
    /*
      Si falta Global.php o la base no responde, antes salía el error crudo de PHP
      ("<br /><b>Fatal error</b>...") y las pantallas se rompían al leerlo (por
      ejemplo el login: "Unexpected token '<' ... is not valid JSON"). Ahora se
      responde un mensaje claro: en JSON si lo pidió una pantalla (AJAX), o en
      una página sencilla si se abrió directo en el navegador.
    */
    if (!function_exists('hanaFalloConexion')) {
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
    //para indicarle a php que si el archivo ya esta incluido no lo vuelva a incluir
    require_once __DIR__ . '/Global.php';

    //Desde PHP 8.1 mysqli lanza una excepción si no puede conectarse: se atrapa para dar un mensaje claro
    try {
        $conexion = new mysqli(DB_HOST,DB_USERNAME,DB_PASSWORD,DB_NAME);//Estan en global.php
    } catch (\Throwable $e) {
        hanaFalloConexion('No se pudo conectar a la base de datos "' . DB_NAME . '": ' . $e->getMessage()
                          . '. Revisa DB_HOST, DB_USERNAME, DB_PASSWORD y DB_NAME en Conexion/Global.php, y que la base esté importada.');
    }
    if (mysqli_connect_errno()){
        hanaFalloConexion('No se pudo conectar a la base de datos: ' . mysqli_connect_error());
    }
        
    mysqli_query( $conexion, 'SET NAMES "'.DB_ENCODE.'"');//Consulta a la base de datos insertar utf8
    //Hora de Colombia también para la base: NOW() y CURDATE() dan la misma hora que date() en PHP
    mysqli_query( $conexion, "SET time_zone = '-05:00'");

    if (!function_exists('ejecutarConsulta'))//Si la función no existe
    {
            function ejecutarConsulta($sql)//Ejecuta la función
            {
                    global $conexion;//Llama al objeto conexion
                    $query = $conexion->query($sql);//Realiza la cunsulta con la sentencia recibida ($sql)
                    return $query;//Resultado de la consulta
            }

            function ejecutarConsultaSimpleFila($sql)//Consulta de una sola fila
            {
                    global $conexion;
                    $query = $conexion->query($sql);
                    $row = $query->fetch_assoc();
                    return $row;
            }

            function ejecutarConsulta_retornarID($sql)//Consulta retorna id
            {
                    global $conexion;
                    $query = $conexion->query($sql);
                    return $conexion->insert_id;
            }

            function limpiarCadena($str)
            {
                    global $conexion;
                    $str = mysqli_real_escape_string($conexion,trim($str));//Limpia la sentencia de caracteres especiales "<", "/", "\", ">", " ' "
                    return htmlspecialchars($str);
            }
    }