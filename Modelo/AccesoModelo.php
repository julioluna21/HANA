<?php
/*
  HANA - Consultas de acceso (roles y qué registros puede ver cada quien)
  ---------------------------------------------------------------------------
  El modelo NO lee la sesión: el controlador (Control/AccesoHelper.php) le
  pasa quién es la persona y qué permisos tiene, y aquí se arma la consulta.
*/
require_once __DIR__ . '/HanaDB.php';

class Acceso
{
    //El rol de un usuario del sistema (0 si no existe)
    public static function rolDeUsuario($idUsuario)
    {
        $f = HanaDB::fila("SELECT ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA AS rol FROM usuarios_sistema WHERE ID_USUARIO_SISTEMA = ? LIMIT 1",
                          'i', array((int)$idUsuario));
        return $f ? (int)$f['rol'] : 0;
    }

    //Condición SQL sobre la tabla novedades_hallazgos.
    //$vista 'mias' o 'todas'; $verTodas: tiene el permiso 16M; $idRol: su rol
    public static function condNovedades($vista, $idColaborador, $verTodas, $idRol)
    {
        $col = (int)$idColaborador;
        $mias = "(novedades_hallazgos.ID_COLABORADOR_NOVEDADES_HALLAZGOS = $col
                  OR novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS = $col)";
        if ($vista !== 'todas') { return $mias; }
        if ($verTodas) { return '1 = 1'; }
        return "($mias OR novedades_hallazgos.ID_ROL_DESTINO = " . (int)$idRol . ")";
    }

    //¿Esa novedad cumple la condición de acceso?
    public static function puedeVerNovedad($idNovedad, $condicion)
    {
        $f = HanaDB::fila("SELECT 1 AS ok FROM novedades_hallazgos WHERE ID_NOVEDADES_HALLAZGOS = ? AND $condicion LIMIT 1",
                          'i', array((int)$idNovedad));
        return (bool)$f;
    }

    //Condición SQL sobre la tabla rq (con el alias que use la consulta).
    //$verTodas: tiene 17M (ver todas) o 18M (aprobar)
    public static function condRQ($vista, $idColaborador, $verTodas, $alias = 'r')
    {
        $alias = preg_replace('/[^a-z_]/i', '', (string)$alias);
        $mias = "($alias.ID_COLABORADOR_SOLICITA = " . (int)$idColaborador . ")";
        if ($vista !== 'todas') { return $mias; }
        return $verTodas ? '1 = 1' : $mias;
    }

    //Los roles activos, para el selector "Rol a notificar" (devuelve el resultado de mysqli)
    public static function rolesActivos()
    {
        return ejecutarConsulta("SELECT ID_ROL_USUARIO_SISTEMA AS id, NOM_ROL_USUARIO_SISTEMA AS nombre
                                   FROM rol_usuarios_sistemas
                                  WHERE ESTADO = 1
                                  ORDER BY NOM_ROL_USUARIO_SISTEMA");
    }

    //¿Existe ese rol y está activo?
    public static function rolValido($idRol)
    {
        $id = (int)$idRol;
        if ($id <= 0) { return false; }
        return (bool)HanaDB::fila("SELECT 1 AS ok FROM rol_usuarios_sistemas WHERE ID_ROL_USUARIO_SISTEMA = ? AND ESTADO = 1 LIMIT 1", 'i', array($id));
    }

    //¿Existe esa tabla en la base del sistema? (para avisar si falta correr un script)
    public static function existeTabla($tabla)
    {
        $f = HanaDB::fila("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", 's', array((string)$tabla));
        return $f && (int)$f['n'] > 0;
    }

    //El rol de un usuario con sus permisos: array('rol' => nombre, 'modulos' => '1M,2M,...') o null
    public static function rolYPermisos($idUsuario)
    {
        return HanaDB::fila("SELECT ro.NOM_ROL_USUARIO_SISTEMA AS rol, ro.MODULOS_ROL_USUARIOS_SISTEMAS AS modulos
                               FROM usuarios_sistema u
                               INNER JOIN rol_usuarios_sistemas ro ON ro.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA
                              WHERE u.ID_USUARIO_SISTEMA = ? LIMIT 1", 'i', array((int)$idUsuario));
    }
}
