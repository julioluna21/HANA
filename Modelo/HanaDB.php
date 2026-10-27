<?php
/*
  HANA - Consultas preparadas (HanaDB)
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
    //Ejecuta una consulta preparada. Devuelve las filas si es un SELECT, true si es otra cosa
    //y false si falla (el error queda en el log, no en pantalla)
    public static function q($sql, $tipos = '', $params = array())
    {
        global $conexion;
        try {
            $sentencia = $conexion->prepare($sql);
            if (!$sentencia) { error_log('HANA - no se pudo preparar: ' . $conexion->error); return false; }
            if ($tipos !== '') { $sentencia->bind_param($tipos, ...$params); }
            if (!$sentencia->execute()) { error_log('HANA - error SQL: ' . $sentencia->error); $sentencia->close(); return false; }
            $resultado = $sentencia->get_result();
            if ($resultado === false) { $sentencia->close(); return true; }
            $filas = $resultado->fetch_all(MYSQLI_ASSOC);
            $sentencia->close();
            return $filas;
        } catch (Throwable $error) {
            error_log('HANA - ' . $error->getMessage());
            return false;
        }
    }

    //La primera fila, o null
    public static function fila($sql, $tipos = '', $params = array())
    {
        $filas = self::q($sql, $tipos, $params);
        return ($filas && isset($filas[0])) ? $filas[0] : null;
    }

    //Id del último registro insertado y manejo de transacciones (iniciar, confirmar, deshacer)
    public static function id()        { global $conexion; return (int)$conexion->insert_id; }
    public static function iniciar()   { global $conexion; $conexion->begin_transaction(); }
    public static function confirmar() { global $conexion; $conexion->commit(); }
    public static function deshacer()  { global $conexion; $conexion->rollback(); }

    //-----------------------------------------------------------------------
    // Jerarquía. El reporte del coordinador (Hoy en qué estás, arqueos,
    // cronograma y vehículo, vacantes y comunicaciones) lo llena SOLO quien
    // está asignado como coordinador de un proyecto (en Proyectos). Los jefes
    // de peaje hacen RQ y listas de chequeo. Sus centros son los de los
    // proyectos que coordina: no hay que asignarlos uno por uno en Usuarios.
    //
    // $todos = true: la persona puede usar esa pantalla en TODOS los proyectos
    // (ADMIN TEC o la casilla del módulo). Eso lo decide el controlador con la
    // sesión (Control/AccesoHelper.php): el modelo no lee la sesión
    //-----------------------------------------------------------------------
    public static function proyectosCoordinados($idColaborador, $todos = false)
    {
        if ($todos) {
            $filas = self::q("SELECT ID_PROYECTO, NOM_PROYECTO FROM proyectos WHERE ESTADO = '1' ORDER BY NOM_PROYECTO");
            return $filas ? $filas : array();
        }
        $filas = self::q("SELECT ID_PROYECTO, NOM_PROYECTO FROM proyectos
                       WHERE ESTADO = '1' AND ID_COLABORADOR_COORDINADOR = ?
                       ORDER BY NOM_PROYECTO", 'i', array((int)$idColaborador));
        return $filas ? $filas : array();
    }

    //¿Coordina al menos un proyecto?
    public static function esCoordinador($idColaborador, $todos = false)
    {
        return (int)$idColaborador > 0 && count(self::proyectosCoordinados($idColaborador, $todos)) > 0;
    }

    //Los centros de los proyectos que coordina (misma forma que centrosUsuario)
    public static function centrosCoordinador($idColaborador, $todos = false)
    {
        $filas = self::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.ID_PROYECTO, p.NOM_PROYECTO
                        FROM centros_operacion c
                        INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                       WHERE p.ESTADO = '1' AND c.ESTADO = '1' AND (p.ID_COLABORADOR_COORDINADOR = ? OR ? = 1)
                       ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP", 'ii', array((int)$idColaborador, $todos ? 1 : 0));
        return $filas ? $filas : array();
    }

    //Todos los centros activos de proyectos activos (para el ADMIN TEC), con la forma de centrosUsuario
    public static function centrosTodos()
    {
        $filas = self::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.ID_PROYECTO, p.NOM_PROYECTO
                        FROM centros_operacion c
                        INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                       WHERE c.ESTADO = '1' AND p.ESTADO = '1'
                       ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP");
        return $filas ? $filas : array();
    }

    //Los centros asignados a la persona en Colaboradores (peajes, básculas, bases, oficinas).
    //Son los que usa para RQ y listas de chequeo
    public static function centrosUsuario($idUsuario)
    {
        $filas = self::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.ID_PROYECTO, p.NOM_PROYECTO
                        FROM centros_operacion c
                        INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                        INNER JOIN asoc_usuarios_sistemas_x_cop a
                                ON a.ID_CENTRO_OP = c.ID_CENTRO_OP
                       WHERE a.ID_COLABORADOR = ?
                         AND c.ESTADO = '1' AND p.ESTADO = '1'
                       ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP", 'i', array((int)$idUsuario));
        return $filas ? $filas : array();
    }
}

//Utilidades de validación que comparten los controladores del reporte diario
class HanaVal
{
    //Una fecha AAAA-MM-DD válida, o ''
    public static function fecha($valor)
    {
        $valor = trim((string)$valor);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $partes)) { return ''; }
        return checkdate((int)$partes[2], (int)$partes[3], (int)$partes[1]) ? $valor : '';
    }

    //Una hora HH:MM válida (con :00 de segundos), o null
    public static function hora($valor)
    {
        $valor = trim((string)$valor);
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $valor) ? $valor . ':00' : null;
    }

    //Texto recortado a un largo máximo; null si queda vacío y $nulo es true
    public static function texto($valor, $max, $nulo = true)
    {
        $texto = trim((string)$valor);
        $texto = function_exists('mb_substr') ? mb_substr($texto, 0, $max, 'UTF-8') : substr($texto, 0, $max);
        return ($texto === '' && $nulo) ? null : $texto;
    }

    //Un valor en pesos: acepta "1.500.000", "1500000" o "$ 1,500,000".
    //Devuelve un número >= 0, o null si no es un valor válido
    public static function pesos($valor)
    {
        $valor = preg_replace('/[^\d]/', '', (string)$valor);
        if ($valor === '' || strlen($valor) > 12) { return null; }
        return (float)$valor;
    }
}
