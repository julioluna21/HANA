<?php
    //para indicarle a php que si el archivo ya esta incluido no lo vulva a incluir
    require_once "../Conexion/Global.php";

    $conexion = new mysqli(DB_HOST,DB_USERNAME,DB_PASSWORD,DB_NAME);//Estan en global.php
        
    mysqli_query( $conexion, 'SET NAMES "'.DB_ENCODE.'"');//Consulta a la base de datos insertar utf8

    //Si tenemos un posible error en la conexión lo mostramos
    if (mysqli_connect_errno()){
            printf("Falló conexión a la base de datos: %s\n",mysqli_connect_error());
            exit();
    }

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