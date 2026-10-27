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
    //El rol del usuario de una persona (0 si no tiene usuario). El rol está en usuarios_sistema
    public static function rolDeUsuario($idUsuario)
    {
        $fila = HanaDB::fila("SELECT ID_ROL_USUARIO_SISTEMA AS rol FROM usuarios_sistema WHERE ID_COLABORADOR = ? LIMIT 1",
                          'i', array((int)$idUsuario));
        return $fila ? (int)$fila['rol'] : 0;
    }

    //Condición SQL sobre la tabla novedades_hallazgos.
    //$vista 'mias' o 'todas'; $verTodas: tiene el permiso 16M; $idRol: su rol
    //$enMisCentros: condición SQL "la novedad es de uno de mis peajes" (la arma AccesoHelper)
    public static function condNovedades($vista, $idColaborador, $verTodas, $idRol, $enMisCentros = '1 = 1')
    {
        $idPersona = (int)$idColaborador;
        $mias = "(novedades_hallazgos.ID_COLABORADOR_REPORTA = $idPersona
                  OR novedades_hallazgos.ID_COLABORADOR_ASIGNADO = $idPersona)";
        if ($vista !== 'todas') { return $mias; }
        //Ver todas: todas las de sus peajes. Sin ese permiso: las dirigidas a su rol, también de sus peajes
        if ($verTodas) { return $enMisCentros === '1 = 1' ? '1 = 1' : "($mias OR $enMisCentros)"; }
        return "($mias OR (novedades_hallazgos.ID_ROL_DESTINO = " . (int)$idRol . " AND $enMisCentros))";
    }

    //¿Esa novedad cumple la condición de acceso?
    public static function puedeVerNovedad($idNovedad, $condicion)
    {
        $fila = HanaDB::fila("SELECT 1 AS ok FROM novedades_hallazgos WHERE ID_NOVEDADES_HALLAZGOS = ? AND $condicion LIMIT 1",
                          'i', array((int)$idNovedad));
        return (bool)$fila;
    }

    //Condición SQL sobre la tabla rq (con el alias que use la consulta).
    //$verTodas: tiene 17M (ver todas) o 18M (aprobar)
    //$enMisCentros: una función que recibe el campo y devuelve la condición "es de mis peajes"
    public static function condRQ($vista, $idColaborador, $verTodas, $alias = 'r', $enMisCentros = null)
    {
        $alias = preg_replace('/[^a-z_]/i', '', (string)$alias);
        $mias = "($alias.ID_COLABORADOR_SOLICITA = " . (int)$idColaborador . ")";
        if ($vista !== 'todas' || !$verTodas) { return $mias; }
        if (!$enMisCentros) { return '1 = 1'; }
        $centro = $enMisCentros("$alias.ID_CENTRO_OP");
        if ($centro === '1 = 1') { return '1 = 1'; }
        //Ver todas (o aprobar): las de sus peajes, por el peaje de la RQ o el de alguno de sus ítems
        $item = (class_exists('Rq') && Rq::hayPeajePorItem())
              ? " OR EXISTS (SELECT 1 FROM rq_detalle dq WHERE dq.ID_RQ = $alias.ID_RQ AND " . $enMisCentros('dq.ID_CENTRO_OP') . ")" : '';
        return "($mias OR $centro$item)";
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
        $fila = HanaDB::fila("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", 's', array((string)$tabla));
        return $fila && (int)$fila['n'] > 0;
    }

    //El rol del usuario de una persona con sus permisos: array('rol' => nombre, 'modulos' => '1M,2M,...') o null
    public static function rolYPermisos($idUsuario)
    {
        return HanaDB::fila("SELECT ro.NOM_ROL_USUARIO_SISTEMA AS rol, ro.PERMISOS AS modulos
                               FROM usuarios_sistema u
                               INNER JOIN rol_usuarios_sistemas ro ON ro.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA
                              WHERE u.ID_COLABORADOR = ? LIMIT 1", 'i', array((int)$idUsuario));
    }
}
