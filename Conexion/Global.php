<?php
/*
  HANA — Configuración de la conexión a la base de datos

  Este archivo tiene los datos de conexión de ESTE equipo. No se sube al
  servidor: el de producción tiene los suyos y no se debe pisar.

  Ya viene listo para XAMPP. Solo cambia MAIL_PASSWORD si quieres que salgan
  los correos.
  Si al entrar sale "Falta el archivo Conexion/Global.php" o "No se pudo
  conectar a la base de datos", revisa estos datos y que la base esté
  importada en phpMyAdmin (ver LEEME.md).
*/

//---- Base de datos (valores de XAMPP) ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'bd_hana_app');   //la base donde importaste BD/HANA_BD_completa.sql
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');          //en XAMPP, root no tiene contraseña
define('DB_ENCODE', 'utf8mb4');     //la misma codificación de las tablas (tildes, ñ y emojis)
define('PRO_NOMBRE', 'bd_hana_app');

//---- Cuenta de correo del sistema (la usan Control/CorreoConfig.php y todos los envíos) ----
//La contraseña real la da el administrador del correo. Si no la pones, el sistema funciona
//igual; solo los correos no salen (se puede revisar en Parámetros → Correo)
define('MAIL_HOST', 'regencysa.net');
define('MAIL_PORT', 465);
define('MAIL_SECURE', 'ssl');
define('MAIL_USER', 'no-reply@regencysa.net');
define('MAIL_PASSWORD', 'ESCRIBE_AQUI_LA_CONTRASENA');

//---- Clave de las tareas programadas de cPanel (resúmenes y recordatorios por correo) ----
//Una cadena larga y aleatoria, distinta en cada servidor. Se usa en la dirección de la tarea:
//  .../Control/TareasControl.php?op=recordatorios&token=ESTA_CLAVE
define('HANA_TOKEN_TAREAS', '02923c1e0ae828f154ee87017e3b09c80c1d3ea7');
