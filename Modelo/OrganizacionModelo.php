<?php
/*
  HANA - Proyectos, centros y personas
  ---------------------------------------------------------------------------
  Las consultas de la organización que comparten varios controladores:
  quién coordina qué proyecto, los centros de cada proyecto, de qué peajes es
  jefe una persona y cuáles tiene asignados en Usuarios.
  Antes estaban escritas dentro de los controladores y de algunas vistas.
*/
require_once __DIR__ . '/HanaDB.php';

class Organizacion
{
    //Los proyectos activos con su coordinador. Con $todos = false, solo los que coordina $idColaborador
    public static function proyectos($idColaborador = 0, $todos = true)
    {
        $f = HanaDB::q("SELECT p.ID_PROYECTO, p.NOM_PROYECTO, p.ID_COLABORADOR_COORDINADOR, col.NOM_COLABORADOR AS COORDINADOR
                          FROM proyectos p LEFT JOIN colaboradores col ON col.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR
                         WHERE p.Estado = '1' AND (? = 1 OR p.ID_COLABORADOR_COORDINADOR = ?)
                         ORDER BY p.NOM_PROYECTO", 'ii', array($todos ? 1 : 0, (int)$idColaborador));
        return $f ? $f : array();
    }

    //Los ids de los proyectos activos
    public static function idsProyectosActivos()
    {
        $f = HanaDB::q("SELECT ID_PROYECTO FROM proyectos WHERE Estado = '1'");
        return array_map('intval', array_column($f ? $f : array(), 'ID_PROYECTO'));
    }

    //Quién coordina un proyecto (0 si nadie)
    public static function coordinadorDe($idProyecto)
    {
        $f = HanaDB::fila("SELECT ID_COLABORADOR_COORDINADOR AS C FROM proyectos WHERE ID_PROYECTO = ?", 'i', array((int)$idProyecto));
        return $f ? (int)$f['C'] : 0;
    }

    //Un proyecto activo (o null)
    public static function proyectoActivo($idProyecto)
    {
        return HanaDB::fila("SELECT ID_PROYECTO, NOM_PROYECTO, ID_COLABORADOR_COORDINADOR FROM proyectos WHERE ID_PROYECTO = ? AND Estado = '1'",
                            'i', array((int)$idProyecto));
    }

    //Los centros activos de un proyecto. $basculasAlFinal: primero los peajes y al final las básculas
    public static function centrosDeProyecto($idProyecto, $basculasAlFinal = true)
    {
        $orden = $basculasAlFinal ? "TIPO_CENTRO = 'BASCULA', NOM_CENTRO_OP" : "NOM_CENTRO_OP";
        $f = HanaDB::q("SELECT ID_CENTRO_OP, NOM_CENTRO_OP, TIPO_CENTRO FROM centros_operacion
                         WHERE Estado = '1' AND ID_PROYECTO_CENTRO_OP = ? ORDER BY $orden", 'i', array((int)$idProyecto));
        return $f ? $f : array();
    }

    //Todos los centros activos, con su proyecto
    public static function centrosActivos()
    {
        $f = HanaDB::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.ID_PROYECTO, p.NOM_PROYECTO
                          FROM centros_operacion c INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                         WHERE c.Estado = '1' ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP");
        return $f ? $f : array();
    }

    //¿Existe ese centro y está activo?
    public static function centroActivo($idCentro)
    {
        return (bool)HanaDB::fila("SELECT 1 AS ok FROM centros_operacion WHERE ID_CENTRO_OP = ? AND Estado = '1'", 'i', array((int)$idCentro));
    }

    //Los ids de los centros activos de los proyectos que coordina
    public static function idsCentrosCoordinados($idColaborador)
    {
        $f = HanaDB::q("SELECT c.ID_CENTRO_OP FROM centros_operacion c INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                         WHERE p.ID_COLABORADOR_COORDINADOR = ? AND c.Estado = '1'", 'i', array((int)$idColaborador));
        return array_map('intval', array_column($f ? $f : array(), 'ID_CENTRO_OP'));
    }

    //-----------------------------------------------------------------------
    // Jefes de peaje (Centros de operación) y centros asignados (Usuarios)
    //-----------------------------------------------------------------------
    //Los peajes de los que es jefe: [idCentro] = idProyecto
    public static function centrosDeJefe($idColaborador)
    {
        $res = array();
        foreach ((array)HanaDB::q("SELECT ID_CENTRO_OP, ID_PROYECTO_CENTRO_OP FROM centros_operacion WHERE Estado = '1' AND ID_COLABORADOR_JEFE = ?",
                                  'i', array((int)$idColaborador)) as $c) {
            $res[(int)$c['ID_CENTRO_OP']] = (int)$c['ID_PROYECTO_CENTRO_OP'];
        }
        return $res;
    }

    //¿Es jefe de algún peaje activo?
    public static function esJefeDePeaje($idColaborador)
    {
        return (bool)HanaDB::fila("SELECT 1 AS ok FROM centros_operacion WHERE Estado = '1' AND ID_COLABORADOR_JEFE = ? LIMIT 1", 'i', array((int)$idColaborador));
    }

    //¿Es el jefe de ese centro?
    public static function esJefeDeCentro($idCentro, $idColaborador)
    {
        return (bool)HanaDB::fila("SELECT 1 AS ok FROM centros_operacion WHERE ID_CENTRO_OP = ? AND ID_COLABORADOR_JEFE = ?",
                                  'ii', array((int)$idCentro, (int)$idColaborador));
    }

    //¿Tiene ese centro asignado en Usuarios?
    public static function centroAsignado($idUsuario, $idCentro)
    {
        return (bool)HanaDB::fila("SELECT 1 AS ok FROM asoc_usuarios_sistemas_x_cop WHERE ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = ?
                                    AND ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP = ?", 'ii', array((int)$idUsuario, (int)$idCentro));
    }

    //¿Tiene algún peaje (como jefe o asignado en Usuarios)? Con $idProyecto, solo en ese proyecto
    public static function tienePeajes($idColaborador, $idUsuario, $idProyecto = 0)
    {
        return (bool)HanaDB::fila("SELECT 1 AS ok FROM centros_operacion c
                                    WHERE c.Estado = '1' AND (? = 0 OR c.ID_PROYECTO_CENTRO_OP = ?)
                                      AND (c.ID_COLABORADOR_JEFE = ? OR c.ID_CENTRO_OP IN
                                           (SELECT ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP FROM asoc_usuarios_sistemas_x_cop
                                             WHERE ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = ?)) LIMIT 1",
                                  'iiii', array((int)$idProyecto, (int)$idProyecto, (int)$idColaborador, (int)$idUsuario));
    }

    //Los centros (con su proyecto) de los que es jefe
    public static function centrosJefeConProyecto($idColaborador)
    {
        $f = HanaDB::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.ID_PROYECTO, p.NOM_PROYECTO
                          FROM centros_operacion c INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                         WHERE p.Estado = '1' AND c.Estado = '1' AND c.ID_COLABORADOR_JEFE = ?", 'i', array((int)$idColaborador));
        return $f ? $f : array();
    }

    //Los centros (con su proyecto) que tiene asignados en Usuarios
    public static function centrosAsignadosConProyecto($idUsuario)
    {
        $f = HanaDB::q("SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, c.TIPO_CENTRO, p.ID_PROYECTO, p.NOM_PROYECTO
                          FROM centros_operacion c INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                         WHERE p.Estado = '1' AND c.Estado = '1'
                           AND c.ID_CENTRO_OP IN (SELECT ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP FROM asoc_usuarios_sistemas_x_cop
                                                   WHERE ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = ?)", 'i', array((int)$idUsuario));
        return $f ? $f : array();
    }

    //-----------------------------------------------------------------------
    // Personas
    //-----------------------------------------------------------------------
    //¿Existe ese colaborador y está activo?
    public static function colaboradorActivo($idColaborador)
    {
        return (bool)HanaDB::fila("SELECT 1 AS ok FROM colaboradores WHERE ID_COLABORADOR = ? AND ESTADO = 1", 'i', array((int)$idColaborador));
    }

    //El nombre de un colaborador ('' si no existe)
    public static function nombre($idColaborador)
    {
        $f = HanaDB::fila("SELECT NOM_COLABORADOR FROM colaboradores WHERE ID_COLABORADOR = ?", 'i', array((int)$idColaborador));
        return $f ? $f['NOM_COLABORADOR'] : '';
    }

    //El nombre de quien tiene ese correo ('' si nadie)
    public static function nombrePorCorreo($correo)
    {
        $f = HanaDB::fila("SELECT NOM_COLABORADOR FROM colaboradores WHERE MAIL_COLABORADOR = ? LIMIT 1", 's', array((string)$correo));
        return $f ? $f['NOM_COLABORADOR'] : '';
    }

    //Los coordinadores de los proyectos activos, con su correo
    public static function coordinadoresConCorreo()
    {
        $f = HanaDB::q("SELECT DISTINCT c.ID_COLABORADOR, c.NOM_COLABORADOR, c.MAIL_COLABORADOR
                          FROM proyectos p INNER JOIN colaboradores c ON c.ID_COLABORADOR = p.ID_COLABORADOR_COORDINADOR
                         WHERE p.Estado = '1'");
        return $f ? $f : array();
    }

    //Las personas que llenan el reporte del coordinador: los coordinadores actuales
    //y quien haya registrado algo en Hoy en qué estás o en el cronograma
    public static function personasDelReporte()
    {
        $f = HanaDB::q("SELECT DISTINCT c.ID_COLABORADOR, c.NOM_COLABORADOR
                          FROM colaboradores c
                         WHERE c.ID_COLABORADOR IN (SELECT ID_COLABORADOR_COORDINADOR FROM proyectos WHERE ID_COLABORADOR_COORDINADOR IS NOT NULL)
                            OR c.ID_COLABORADOR IN (SELECT ID_COLABORADOR FROM reporte_hoy)
                            OR c.ID_COLABORADOR IN (SELECT ID_COLABORADOR FROM cronograma)
                         ORDER BY c.NOM_COLABORADOR");
        return $f ? $f : array();
    }

    //Los colaboradores activos con los proyectos que coordinan (primero quienes coordinan)
    public static function personasConProyectos()
    {
        $f = HanaDB::q("SELECT c.ID_COLABORADOR, c.NOM_COLABORADOR,
                               (SELECT GROUP_CONCAT(p.NOM_PROYECTO ORDER BY p.NOM_PROYECTO SEPARATOR ', ')
                                  FROM proyectos p
                                 WHERE p.Estado = '1' AND p.ID_COLABORADOR_COORDINADOR = c.ID_COLABORADOR) AS COORDINA
                          FROM colaboradores c
                         WHERE c.ESTADO = 1
                         ORDER BY (COORDINA IS NULL), c.NOM_COLABORADOR");
        return $f ? $f : array();
    }
}
