<?php
/*
  HANA - Contraseñas
  ---------------------------------------------------------------------------
  Todo lo de las claves en un solo sitio, para que el inicio de sesión, el
  cambio de clave, la clave temporal y "Olvidé mi contraseña" sigan las
  mismas reglas.

    cifrar($clave)        La clave nueva se guarda con password_hash (bcrypt):
                          cada clave lleva su propia "sal" y adivinarla es lento.
    coincide($clave, $guardada)
                          Revisa una clave escrita contra la guardada.
                          Acepta también el formato de antes (SHA-256 sin sal,
                          64 letras y números) para que nadie tenga que cambiarla.
    esDeAntes($guardada)  Dice si la guardada está en el formato de antes: al
                          entrar con ella, se vuelve a guardar en el nuevo.
    problema($clave)      Las reglas de una clave nueva. Devuelve el mensaje
                          para la persona, o '' si la clave sirve.

  El enlace de "Olvidé mi contraseña" (firmaEnlace / enlaceValido):
    El enlace lleva el número de la persona, hasta cuándo sirve (hora Unix) y
    una firma. La firma se calcula con un secreto del servidor y con la clave
    guardada en ese momento: en cuanto la persona pone su clave nueva, la firma
    deja de coincidir y el enlace ya no sirve (se usa una sola vez). Así no hace
    falta guardar nada en la base. urlRestablecer arma la dirección del enlace.

  También están aquí los números del freno al iniciar sesión (INTENTOS_MAXIMOS,
  MINUTOS_ESPERA, INTENTOS_POR_IP) y del límite de correos de recuperación.
  Lo usan Control/UsuariosControl.php y Control/UsuarioHelper.php.
*/

class HanaClave
{
    const LARGO_MINIMO = 8;       //caracteres de una clave nueva
    const LARGO_MAXIMO = 72;      //bcrypt solo usa los primeros 72 bytes
    const MINUTOS_ENLACE = 60;    //cuánto sirve el enlace de "Olvidé mi contraseña"
    const CORREOS_POR_HORA = 3;   //enlaces que se mandan como máximo al mismo correo en una hora

    //El freno al iniciar sesión (los intentos quedan en bitacora_sistema, TIPO 'INGRESO')
    const INTENTOS_MAXIMOS = 5;   //claves malas seguidas de un mismo usuario...
    const MINUTOS_ESPERA = 15;    //...dentro de estos minutos: hay que esperar
    const INTENTOS_POR_IP = 100;  //claves malas desde un mismo equipo, con cualquier usuario (alto: en una oficina
                                  //todos salen a internet con la misma dirección)

    //La clave lista para guardar en usuarios_sistema.CLAVE
    public static function cifrar($clave)
    {
        return password_hash((string)$clave, PASSWORD_BCRYPT);
    }

    //¿La clave escrita es la que está guardada?
    public static function coincide($clave, $guardada)
    {
        $guardada = (string)$guardada;
        if ($guardada === '') { return false; }
        if (!self::esDeAntes($guardada)) { return password_verify((string)$clave, $guardada); }
        //Formato de antes: SHA-256 de la clave tal como la dejaba limpiarCadena
        //(sin espacios a los lados y con < > " ' & convertidos). Se prueba también
        //la clave tal cual, por si se guardó sin pasar por limpiarCadena
        $comoAntes = function_exists('limpiarCadena') ? limpiarCadena((string)$clave) : trim((string)$clave);
        return hash_equals(strtolower($guardada), hash('sha256', $comoAntes))
            || hash_equals(strtolower($guardada), hash('sha256', (string)$clave));
    }

    //¿Hay que volver a guardarla? Sí cuando está en el formato de antes, o cuando PHP
    //recomienda un cifrado más fuerte que el que tiene (password_needs_rehash)
    public static function hayQueRecifrar($guardada)
    {
        return self::esDeAntes($guardada) || password_needs_rehash((string)$guardada, PASSWORD_BCRYPT);
    }

    //Para revisar la clave aunque el usuario no exista: la respuesta tarda lo mismo y así
    //no se puede saber, por el tiempo, qué usuarios existen. No es la clave de nadie
    const CLAVE_DE_RELLENO = '$2y$10$kK1I1ysi0rRe9ruYUPBsh.TmqvNb/ILIFk.gBHnZgHsp/NHrumy8W';

    //¿Está guardada en el formato de antes (SHA-256: 64 caracteres hexadecimales)?
    public static function esDeAntes($guardada)
    {
        return (bool)preg_match('/^[0-9a-f]{64}$/i', (string)$guardada);
    }

    //Las reglas de una clave nueva. '' = sirve
    public static function problema($clave)
    {
        $clave = (string)$clave;
        if (trim($clave) === '') { return 'Escribe la nueva contraseña.'; }
        if (mb_strlen($clave, 'UTF-8') < self::LARGO_MINIMO) { return 'La contraseña debe tener al menos ' . self::LARGO_MINIMO . ' caracteres.'; }
        if (strlen($clave) > self::LARGO_MAXIMO) { return 'La contraseña es demasiado larga (máximo ' . self::LARGO_MAXIMO . ' caracteres).'; }
        if (trim($clave) !== $clave) { return 'La contraseña no puede empezar ni terminar con espacios.'; }
        return '';
    }

    //-----------------------------------------------------------------------
    // Enlace de "Olvidé mi contraseña"
    //-----------------------------------------------------------------------

    //La firma del enlace de una persona. $vence = hora Unix hasta la que sirve
    public static function firmaEnlace($idPersona, $vence, $claveGuardada)
    {
        return hash_hmac('sha256', (int)$idPersona . '|' . (int)$vence, self::secreto() . '|' . (string)$claveGuardada);
    }

    //¿El enlace es auténtico y todavía sirve?
    public static function enlaceValido($idPersona, $vence, $firma, $claveGuardada)
    {
        $vence = (int)$vence;
        if ($vence < time()) { return false; }                                   //ya venció
        if ($vence > time() + self::MINUTOS_ENLACE * 60 + 60) { return false; } //una fecha inventada más allá de lo permitido
        if ((string)$claveGuardada === '') { return false; }                     //la persona no tiene clave (no tiene usuario)
        return hash_equals(self::firmaEnlace($idPersona, $vence, $claveGuardada), (string)$firma);
    }

    //La dirección completa del enlace que va en el correo.
    //No se arma con el nombre del servidor que manda el navegador (el encabezado Host),
    //porque alguien podría pedir el enlace de otra persona con un Host falso y hacer
    //que el correo apunte a su propia página. Se usa, en este orden:
    //  1. HANA_URL, si está definida en Conexion/Global.php (por ejemplo
    //     'https://hana.cloudregencyapps.com'), para instalar HANA en otra dirección;
    //  2. el servidor que llegó, solo si es el de producción o el equipo local;
    //  3. la dirección de producción.
    public static function urlRestablecer($idPersona, $vence, $firma)
    {
        $produccion = 'https://hana.cloudregencyapps.com';
        if (defined('HANA_URL') && preg_match('#^https?://[^/]+#i', (string)HANA_URL)) {
            $base = rtrim((string)HANA_URL, '/');
        } else {
            $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
            $conocidos = array('hana.cloudregencyapps.com', 'localhost', '127.0.0.1');
            if (in_array(preg_replace('/:\d+$/', '', $host), $conocidos, true) && preg_match('/^[a-z0-9.\-]+(:\d+)?$/', $host)) {
                $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
                //La carpeta donde está instalado HANA (lo que va antes de /Control/...)
                $carpeta = rtrim(dirname(dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/Control/x.php')), '/\\');
                $base = ($https ? 'https' : 'http') . '://' . $host . $carpeta;
            } else {
                $base = $produccion;
            }
        }
        return $base . '/Vista/RestablecerVista.php?u=' . (int)$idPersona . '&v=' . (int)$vence . '&f=' . rawurlencode((string)$firma);
    }

    //El secreto del servidor: sale de los datos de Conexion/Global.php, que nunca
    //salen del servidor. La firma también usa la clave guardada (ver arriba), así
    //que el enlace no se puede fabricar sin acceso a la base
    private static function secreto()
    {
        $partes = array();
        foreach (array('HANA_TOKEN_TAREAS', 'DB_PASSWORD', 'MAIL_PASSWORD', 'DB_NAME', 'DB_USERNAME', 'DB_HOST') as $constante) {
            if (defined($constante)) { $partes[] = (string)constant($constante); }
        }
        return implode('|', $partes);
    }
}
