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
define('DB_NAME', 'bd_hana_app');   //el nombre de la base de datos de HANA.
                                    //En un servidor Linux las mayúsculas cuentan: el nombre se escribe
                                    //exactamente como está en phpMyAdmin
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');          //en XAMPP, root no tiene contraseña
define('DB_ENCODE', 'utf8mb4');     //las tablas son utf8mb4: así también se guardan bien emojis y símbolos
define('PRO_NOMBRE', 'bd_hana_app');

//Cuenta de correo del sistema: con ella salen TODOS los correos (Control/CorreoHelper.php).
//Es un dato del servidor: se escribe solo aquí, no en Parámetros del sistema ni en el código.
//La contraseña real la da el administrador del correo. Las dos formas de conectarse:
//  SSL: MAIL_PORT 465 y MAIL_SECURE 'ssl'  (la conexión va cifrada desde el primer momento)
//  TLS: MAIL_PORT 587 y MAIL_SECURE 'tls'  (se conecta y enseguida pasa a cifrado, STARTTLS)
//Las dos son seguras; se usa la que diga el proveedor del correo. Si un correo no sale, el
//motivo queda en bitacora_sistema (TIPO 'CORREO') y en el error_log de PHP.
define('MAIL_HOST', 'regencysa.net');
define('MAIL_PORT', 465);
define('MAIL_SECURE', 'ssl');
define('MAIL_USER', 'no-reply@regencysa.net');
define('MAIL_PASSWORD', 'ESCRIBE_AQUI_LA_CONTRASENA');
//Solo si el certificado del servidor de correo no coincide con su nombre (por ejemplo, el
//certificado es de mail.dominio y MAIL_HOST es dominio): quitar las dos barras de abajo.
//Es mejor corregir MAIL_HOST con el nombre del certificado que apagar la revisión.
//define('MAIL_VERIFICAR_CERTIFICADO', false);

//Clave secreta del servidor: una cadena larga y aleatoria, distinta en cada servidor.
//Con ella se firman los enlaces de "Olvidé mi contraseña". (Antes también era la clave de
//las tareas programadas por dirección; ahora las tareas las corre cron y no la necesitan.)
define('HANA_TOKEN_TAREAS', 'CAMBIA_ESTO_POR_UNA_CLAVE_LARGA_Y_ALEATORIA');

//Opcional: la dirección donde está instalado HANA, sin barra al final. La usan el enlace de
//"Olvidé mi contraseña" y el botón "Entrar a HANA" de los correos que mandan las tareas
//de cron. Si no se define, apuntan a https://hana.cloudregencyapps.com (o al equipo local,
//si se prueba en XAMPP).
//Solo hace falta si HANA se instala en otra dirección.
//define('HANA_URL', 'https://hana.cloudregencyapps.com');
