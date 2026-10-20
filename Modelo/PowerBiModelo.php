<?php
/*
  HANA - Datos para Power BI: solo el ausentismo (vista vw_pbi_ausentismo)
  ---------------------------------------------------------------------------
  Solo lectura. Quién entra y el registro en la bitácora los maneja
  Control/PowerBiControl.php.
*/
require_once __DIR__ . '/HanaDB.php';

class PowerBi
{
    //La persona activa (y con rol activo) que tiene ese usuario: su número, los permisos de
    //su rol y la clave guardada, que el controlador revisa con HanaClave. null si no hay
    public static function usuario($usuario)
    {
        return HanaDB::fila("SELECT c.ID_COLABORADOR, c.CLAVE, r.PERMISOS AS MODULOS
                               FROM colaboradores c
                               INNER JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = c.ID_ROL_USUARIO_SISTEMA
                              WHERE c.USUARIO = ? AND c.ESTADO = 1 AND r.ESTADO = 1",
                            's', array((string)$usuario));
    }

    //Vuelve a guardar la clave en el formato nuevo (ya cifrada con HanaClave::cifrar)
    public static function guardarClave($idPersona, $claveCifrada)
    {
        return HanaDB::q("UPDATE colaboradores SET CLAVE = ? WHERE ID_COLABORADOR = ?", 'si', array((string)$claveCifrada, (int)$idPersona));
    }

    //Las filas del ausentismo, con el periodo opcional (año*100+mes; 0 = sin límite).
    //$datos queda por compatibilidad: solo existe 'ausentismo'. Devuelve false si no se pudieron leer
    public static function datos($datos, $desde = 0, $hasta = 0)
    {
        $donde = array(); $tipos = ''; $params = array();
        if ($desde > 0) { $donde[] = "PeriodoOrden >= ?"; $tipos .= 'i'; $params[] = (int)$desde; }
        if ($hasta > 0) { $donde[] = "PeriodoOrden <= ?"; $tipos .= 'i'; $params[] = (int)$hasta; }
        $condicion = count($donde) ? ' WHERE ' . implode(' AND ', $donde) : '';
        $sql = "SELECT Periodo, PeriodoOrden, Concesion, Estacion, TipoEstacion, Cargo, Novedad, Fecha, Anio, Mes, Dia, DiaTxt, Quincena, Cantidad
                  FROM vw_pbi_ausentismo" . $condicion . " ORDER BY PeriodoOrden, Concesion, Estacion, Fecha";
        return HanaDB::q($sql, $tipos, $params);
    }
}
