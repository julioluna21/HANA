<?php
/*
  HANA - Configuración de la conexión a la base de datos (EJEMPLO)

  Este archivo NO se usa tal cual. Para que el sistema funcione:
    1. Copia este archivo con el nombre Global.php, en esta misma carpeta.
    2. Ajusta los datos a tu entorno.

  Por qué viene como ejemplo:
    - Global.php tiene la contraseña de la base y es distinto en cada servidor.
      Si el paquete trajera uno, al subirlo al hosting pisaría el de producción
      y el sistema dejaría de conectarse.
    - Las credenciales nunca deben compartirse en un archivo que se le pasa a
      otra persona.

  Los valores de abajo son los de XAMPP en un equipo de desarrollo.
*/
define('DB_HOST', 'localhost');
define('DB_NAME', 'bd_hana_app');   //la base donde se importó BD_completa/bd_hana_app_AAAA-MM-DD.sql.
                                    //En un servidor Linux las mayúsculas cuentan: el nombre se escribe
                                    //exactamente como está en phpMyAdmin
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');          //en XAMPP, root no tiene contraseña
define('DB_ENCODE', 'utf8mb4');     //las tablas son utf8mb4: así también se guardan bien emojis y símbolos
define('PRO_NOMBRE', 'bd_hana_app');

//Cuenta de correo del sistema (la usan Control/CorreoConfig.php y todos los envíos).
//La contraseña real la da el administrador del correo: nunca se escribe en otro archivo
define('MAIL_HOST', 'regencysa.net');
define('MAIL_PORT', 465);
define('MAIL_SECURE', 'ssl');
define('MAIL_USER', 'no-reply@regencysa.net');
define('MAIL_PASSWORD', 'ESCRIBE_AQUI_LA_CONTRASENA');

//Clave de las tareas programadas de cPanel (resúmenes de correo de listas).
//Una cadena larga y aleatoria, distinta en cada servidor. Se usa en la URL:
//  .../Control/NotificacionesControl.php?op=resumenDiario&token=ESTA_CLAVE
define('HANA_TOKEN_TAREAS', 'CAMBIA_ESTO_POR_UNA_CLAVE_LARGA_Y_ALEATORIA');

//Opcional: la dirección donde está instalado HANA, sin barra al final. La usa el enlace de
//"Olvidé mi contraseña" que llega al correo. Si no se define, el enlace apunta a
//https://hana.cloudregencyapps.com (o al equipo local, si se prueba en XAMPP).
//Solo hace falta si HANA se instala en otra dirección.
//define('HANA_URL', 'https://hana.cloudregencyapps.com');
