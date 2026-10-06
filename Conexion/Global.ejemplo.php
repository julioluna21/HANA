<?php
/*
  HANA — Configuración de la conexión a la base de datos (EJEMPLO)

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
define('DB_NAME', 'BD_HANA_APP');   //la base donde importaste BD/bd_hana_app_completa.sql
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');          //en XAMPP, root no tiene contraseña
define('DB_ENCODE', 'utf8');
define('PRO_NOMBRE', 'BD_HANA_APP');

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
