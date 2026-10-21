<?php
/*
  HANA - Bitácora del sistema (tabla bitacora_sistema)
  ---------------------------------------------------------------------------
  Lo que queda anotado: cada correo enviado (TIPO 'CORREO') y cada intento de
  iniciar sesión (TIPO 'INGRESO'). Las filas TIPO 'POWERBI' son de antes: Power BI
  se quitó del sistema y ya no se anotan nuevas;
  las revisiones de pantallas las anota EstadoDiaModelo (TIPO 'REVISION').
  Si anotar falla, lo demás sigue igual.

  Con qué queda enlazado cada registro:
    ID_COLABORADOR    quién: la persona con sesión que disparó el correo (vacío si
                      lo mandó una tarea programada)
    ID_NOTIFICACION   en los correos de listas: el aviso configurado que lo mandó
    ID_LISTA_CHEQUEO  en el aviso inmediato: la lista diligenciada que lo disparó
  Los dos últimos los pone quien manda el correo con Bitacora::contexto() antes de
  enviar, y Bitacora::contexto() sin datos al terminar.
*/
require_once __DIR__ . '/HanaDB.php';

class Bitacora
{
    //De qué aviso de listas y de qué lista sale el correo que se va a mandar (ver arriba)
    private static $notificacion = null;
    private static $lista = null;
    public static function contexto($idNotificacion = null, $idLista = null)
    {
        self::$notificacion = $idNotificacion ? (int)$idNotificacion : null;
        self::$lista = $idLista ? (int)$idLista : null;
    }

    //La persona con sesión abierta (vacío si no hay sesión: tarea programada, Power BI)
    private static function quienSesion()
    {
        return (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['Idcolaborador'])) ? (int)$_SESSION['Idcolaborador'] : null;
    }

    //Deja constancia de un correo enviado (o que falló) en la bitácora
    public static function correo($modulo, $para, $asunto, $metodo, $ok, $error)
    {
        return HanaDB::q("INSERT INTO bitacora_sistema (TIPO, FECHA, MODULO, QUIEN, ID_COLABORADOR, ID_NOTIFICACION, ID_LISTA_CHEQUEO, ASUNTO, DETALLE, OK, ERROR)
                          VALUES ('CORREO', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                         'ssiiissis', array(substr((string)$modulo, 0, 30), substr((string)$para, 0, 500), self::quienSesion(), self::$notificacion, self::$lista,
                                         substr((string)$asunto, 0, 250), substr((string)$metodo, 0, 60), $ok ? 1 : 0, $error === null ? null : substr((string)$error, 0, 500)));
    }

    //Los últimos envíos de correo, con su resultado
    public static function ultimosCorreos($cuantos = 60)
    {
        $filas = HanaDB::q("SELECT FECHA, MODULO, QUIEN AS PARA, ASUNTO, DETALLE AS METODO, OK, ERROR
                          FROM bitacora_sistema WHERE TIPO = 'CORREO'
                         ORDER BY FECHA DESC, ID_BITACORA DESC LIMIT ?", 'i', array((int)$cuantos));
        return $filas ? $filas : array();
    }

    //$idColaborador: la dueña del usuario con el que entró (vacío si el usuario o la clave no sirvieron)
    public static function powerBi($datos, $usuario, $ip, $ok, $filas = null, $idColaborador = null)
    {
        return HanaDB::q("INSERT INTO bitacora_sistema (TIPO, FECHA, MODULO, QUIEN, ID_COLABORADOR, DETALLE, OK, FILAS)
                          VALUES ('POWERBI', NOW(), ?, ?, ?, ?, ?, ?)",
                         'ssisii', array(substr((string)$datos, 0, 30), substr((string)$usuario, 0, 60), $idColaborador ? (int)$idColaborador : null,
                                         substr((string)$ip, 0, 45), $ok ? 1 : 0, $filas));
    }

    //Correos de "Olvidé mi contraseña" que sí salieron hacia una dirección en la última hora
    //(sirve para no mandar más de unos pocos seguidos al mismo buzón)
    public static function correosRecientes($correo, $asunto, $minutos = 60)
    {
        $fila = HanaDB::fila("SELECT COUNT(*) AS n FROM bitacora_sistema
                            WHERE TIPO = 'CORREO' AND QUIEN = ? AND ASUNTO = ? AND OK = 1 AND FECHA > NOW() - INTERVAL ? MINUTE",
                          'ssi', array((string)$correo, (string)$asunto, (int)$minutos));
        return $fila ? (int)$fila['n'] : 0;
    }

    //-----------------------------------------------------------------------
    // Inicio de sesión (TIPO 'INGRESO')
    //   MODULO   'LOGIN': un intento de entrar.
    //            'CLAVE NUEVA': la persona recibió una clave nueva (con el enlace del
    //            correo o una temporal del administrador). Va con OK = 1 y reinicia
    //            el conteo de intentos fallidos de ese usuario.
    //   QUIEN    el usuario que se escribió
    //   DETALLE  la dirección IP
    //   OK       1 entró (o recibió clave nueva), 0 no
    //-----------------------------------------------------------------------
    public static function ingreso($usuario, $ip, $ok, $idColaborador = null, $modulo = 'LOGIN')
    {
        return HanaDB::q("INSERT INTO bitacora_sistema (TIPO, FECHA, MODULO, QUIEN, ID_COLABORADOR, DETALLE, OK)
                          VALUES ('INGRESO', NOW(), ?, ?, ?, ?, ?)",
                         'ssisi', array(substr((string)$modulo, 0, 30), substr((string)$usuario, 0, 60), $idColaborador ? (int)$idColaborador : null,
                                         substr((string)$ip, 0, 45), $ok ? 1 : 0));
    }

    //Claves malas de un usuario en los últimos $minutos, contando solo las que vinieron
    //después de su último ingreso bueno (o de su última clave nueva)
    public static function fallosIngreso($usuario, $minutos)
    {
        $fila = HanaDB::fila("SELECT COUNT(*) AS n FROM bitacora_sistema b
                            WHERE b.TIPO = 'INGRESO' AND b.QUIEN = ? AND b.OK = 0 AND b.FECHA > NOW() - INTERVAL ? MINUTE
                              AND b.ID_BITACORA > COALESCE((SELECT MAX(o.ID_BITACORA) FROM bitacora_sistema o
                                                             WHERE o.TIPO = 'INGRESO' AND o.QUIEN = ? AND o.OK = 1), 0)",
                          'sis', array((string)$usuario, (int)$minutos, (string)$usuario));
        return $fila ? (int)$fila['n'] : 0;
    }

    //Claves malas desde una misma dirección IP en los últimos $minutos, con cualquier usuario
    //(frena a quien prueba muchos usuarios distintos desde el mismo equipo)
    public static function fallosIngresoIp($ip, $minutos)
    {
        $fila = HanaDB::fila("SELECT COUNT(*) AS n FROM bitacora_sistema
                            WHERE TIPO = 'INGRESO' AND DETALLE = ? AND OK = 0 AND FECHA > NOW() - INTERVAL ? MINUTE",
                          'si', array((string)$ip, (int)$minutos));
        return $fila ? (int)$fila['n'] : 0;
    }

    //Intentos fallidos de Power BI desde una dirección en los últimos 15 minutos
    public static function fallosPowerBi($ip)
    {
        $fila = HanaDB::fila("SELECT COUNT(*) AS n FROM bitacora_sistema
                            WHERE TIPO = 'POWERBI' AND DETALLE = ? AND OK = 0 AND FECHA > NOW() - INTERVAL 15 MINUTE", 's', array((string)$ip));
        return $fila ? (int)$fila['n'] : 0;
    }
}
