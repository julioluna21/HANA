<?php
/*
  HANA — Consultas preparadas para los módulos nuevos (Fase 2 en adelante)
  ---------------------------------------------------------------------------
  Los datos viajan aparte del SQL, así que no hace falta limpiarCadena(): el
  texto se guarda tal cual y cada pantalla lo escapa al mostrarlo.

    HanaDB::q($sql, 'is', array($id, $texto))
      -> arreglo de filas (SELECT), true (INSERT/UPDATE/DELETE) o false si falló
    HanaDB::id()   el id del último INSERT

  Desde PHP 8.1, mysqli lanza una excepción cuando una consulta falla: aquí se
  atrapa y se devuelve false, para que la pantalla reciba un mensaje claro en
  vez de quedarse sin respuesta.
*/
require_once __DIR__ . "/../Conexion/ConexionDB.php";

class HanaDB
{
    public static function q($sql, $tipos = '', $params = array())
    {
        global $conexion;
        try {
            $st = $conexion->prepare($sql);
            if (!$st) { error_log('HANA - no se pudo preparar: ' . $conexion->error); return false; }
            if ($tipos !== '') { $st->bind_param($tipos, ...$params); }
            if (!$st->execute()) { error_log('HANA - error SQL: ' . $st->error); $st->close(); return false; }
            $r = $st->get_result();
            if ($r === false) { $st->close(); return true; }
            $filas = $r->fetch_all(MYSQLI_ASSOC);
            $st->close();
            return $filas;
        } catch (Throwable $e) {
            error_log('HANA - ' . $e->getMessage());
            return false;
        }
    }

    //La primera fila, o null
    public static function fila($sql, $tipos = '', $params = array())
    {
        $f = self::q($sql, $tipos, $params);
        return ($f && isset($f[0])) ? $f[0] : null;
    }

    public static function id()        { global $conexion; return (int)$conexion->insert_id; }
    public static function iniciar()   { global $conexion; $conexion->begin_transaction(); }
    public static function confirmar() { global $conexion; $conexion->commit(); }
    public static function deshacer()  { global $conexion; $conexion->rollback(); }

    //-----------------------------------------------------------------------
    // Jerarquía. El reporte del coordinador (Hoy en qué estás, arqueos,
    // cronograma y vehículo, vacantes y comunicaciones) lo llena SOLO quien
    // está asignado como coordinador de un proyecto (en Proyectos). Los jefes
    // de peaje hacen RQ y listas de chequeo. Sus centros son los de los
    // proyectos que coordina: no hay que asignarlos uno por uno en Usuarios
    //-----------------------------------------------------------------------
    //El ADMIN TEC (permiso 24M, Parámetros del sistema) puede usar las pantallas
    //del coordinador en todos los proyectos: cuenta como coordinador de todos.
    //Solo aplica a la persona que está usando el sistema (no cambia cómo se ve
    //a los demás en el tablero o en los reportes)
    //Además, cada pantalla del coordinador tiene su casilla en Roles: quien la tenga
    //la usa en todos los proyectos, como el coordinador en el suyo. La pantalla dice
    //a qué módulo pertenece con HanaDB::usarModulo('ARQUEOS'), al comenzar
    public static $PERMISO_MODULO = array(
        'HOY' => '28M', 'ARQUEOS' => '29M', 'CRONOGRAMA' => '30M', 'VACANTES' => '31M', 'COMUNICACIONES' => '32M'
        //33M, 34M y 35M eran Pendientes con Regency, Novedades del proyecto y Disciplinarios (se quitaron)
    );
    private static $moduloActual = '';

    public static function usarModulo($modulo) { self::$moduloActual = (string)$modulo; }

    public static function adminVeTodo($idColaborador)
    {
        if (!isset($_SESSION['Idcolaborador'], $_SESSION['Modulos'])) { return false; }
        if ((int)$idColaborador <= 0 || (int)$idColaborador !== (int)$_SESSION['Idcolaborador']) { return false; }
        $mios = explode(',', $_SESSION['Modulos']);
        if (in_array('24M', $mios, true)) { return true; }
        $m = self::$moduloActual;
        return $m !== '' && isset(self::$PERMISO_MODULO[$m]) && in_array(self::$PERMISO_MODULO[$m], $mios, true);
    }

    //¿La persona conectada puede usar la pantalla de ese módulo? (coordinador, ADMIN TEC o su casilla)
    public static function puedeModulo($modulo)
    {
        if (!isset($_SESSION['Idcolaborador'])) { return false; }
        $antes = self::$moduloActual;
        self::$moduloActual = (string)$modulo;
        $si = self::esCoordinador((int)$_SESSION['Idcolaborador']);
        self::$moduloActual = $antes;
        return $si;
    }

    public static function proyectosCoordinados($idColaborador)
    {
        if (self::adminVeTodo($idColaborador)) {
            $f = self::q("SELECT ID_PROYECTO, NOM_PROYECTO FROM proyectos WHERE Estado = '1' ORDER BY NOM_PROYECTO");
            return $f ? $f : array();
        }
        $f = self::q("SELECT ID_PROYECTO, NOM_PROYECTO FROM proyectos
                       WHERE Estado = '1' AND ID_COLABORADOR_COORDINADOR = ?
                       ORDER BY NOM_PROYECTO", 'i', array((int)$idColaborador));
        return $f ? $f : array();
    }

    public static function esCoordinador($idColaborador)
    {
        return (int)$idColaborador > 0 && count(self::proyectosCoordinados($idColaborador)) > 0;
    }

    //Los centros de los proyectos que coordina (misma forma que centrosUsuario)
    public static function centrosCoordinador($idColaborador)
    {
        $f = self::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.ID_PROYECTO, p.NOM_PROYECTO
                        FROM centros_operacion c
                        INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                       WHERE p.Estado = '1' AND c.Estado = '1' AND (p.ID_COLABORADOR_COORDINADOR = ? OR ? = 1)
                       ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP", 'ii', array((int)$idColaborador, self::adminVeTodo($idColaborador) ? 1 : 0));
        return $f ? $f : array();
    }

    //Los centros asignados al usuario (peajes, básculas, bases, oficinas).
    //Son los que usa para RQ y listas de chequeo
    public static function centrosUsuario($idUsuario)
    {
        $f = self::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.ID_PROYECTO, p.NOM_PROYECTO
                        FROM centros_operacion c
                        INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                        INNER JOIN asoc_usuarios_sistemas_x_cop a
                                ON a.ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP = c.ID_CENTRO_OP
                       WHERE a.ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = ?
                         AND c.Estado = '1'
                       ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP", 'i', array((int)$idUsuario));
        return $f ? $f : array();
    }
}

//Utilidades de validación que comparten los controladores del reporte diario
class HanaVal
{
    //Una fecha AAAA-MM-DD válida, o ''
    public static function fecha($v)
    {
        $v = trim((string)$v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) { return ''; }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : '';
    }

    //Una hora HH:MM válida (con :00 de segundos), o null
    public static function hora($v)
    {
        $v = trim((string)$v);
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $v) ? $v . ':00' : null;
    }

    //Texto recortado a un largo máximo; null si queda vacío y $nulo es true
    public static function texto($v, $max, $nulo = true)
    {
        $t = trim((string)$v);
        $t = function_exists('mb_substr') ? mb_substr($t, 0, $max, 'UTF-8') : substr($t, 0, $max);
        return ($t === '' && $nulo) ? null : $t;
    }

    //Un valor en pesos: acepta "1.500.000", "1500000" o "$ 1,500,000".
    //Devuelve un número >= 0, o null si no es un valor válido
    public static function pesos($v)
    {
        $v = preg_replace('/[^\d]/', '', (string)$v);
        if ($v === '' || strlen($v) > 12) { return null; }
        return (float)$v;
    }
}
