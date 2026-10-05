<?php
/*
  HANA — Parámetros del sistema
  ---------------------------------------------------------------------------
  Las decisiones del negocio que el administrador cambia desde
  Configuración → Parámetros del sistema (tabla `configuracion`), sin tocar
  el código. Se leen una sola vez por petición.
  Si la tabla todavía no existe (antes de correr 03_AUSENTISMO_Y_PARAMETROS),
  se usan los valores por defecto que trae cada llamada: nada se rompe.

    HanaConfig::num('VACANTE_DIAS_ANS', 5)      un número
    HanaConfig::si('MODULO_ARQUEOS')            sí / no (por defecto sí)
    HanaConfig::modulo('ARQUEOS')               ¿el módulo está activo?
*/
require_once __DIR__ . "/HanaDB.php";

class HanaConfig
{
    private static $valores = null;

    private static function cargar()
    {
        if (self::$valores !== null) { return; }
        self::$valores = array();
        try {
            $f = HanaDB::q("SELECT CLAVE, VALOR FROM configuracion");
            //Si la tabla no existe (no se ha corrido el script 03), q() devuelve false:
            //se usan los valores por defecto, sin avisos en pantalla
            if (is_array($f)) {
                foreach ($f as $x) { self::$valores[$x['CLAVE']] = $x['VALOR']; }
            }
        } catch (Throwable $e) {
            //Sin la tabla (no se ha corrido el script 03): valores por defecto
            error_log('HANA - parámetros no disponibles: ' . $e->getMessage());
        }
    }

    public static function valor($clave, $defecto)
    {
        self::cargar();
        return isset(self::$valores[$clave]) ? self::$valores[$clave] : $defecto;
    }

    public static function num($clave, $defecto)
    {
        return (int)self::valor($clave, $defecto);
    }

    public static function si($clave, $defecto = true)
    {
        return (string)self::valor($clave, $defecto ? '1' : '0') === '1';
    }

    //Los módulos del reporte diario que el administrador puede apagar
    public static function modulo($nombre)
    {
        return self::si('MODULO_' . $nombre, true);
    }

    //Olvida lo leído (después de guardar parámetros)
    public static function recargar()
    {
        self::$valores = null;
    }
}
