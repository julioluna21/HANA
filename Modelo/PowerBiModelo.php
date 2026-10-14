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
    //El usuario activo con esa clave (ya cifrada), con los permisos de su rol; o null
    public static function usuario($usuario, $claveCifrada)
    {
        return HanaDB::fila("SELECT u.ID_USUARIO_SISTEMA, u.ID_COLABORADOR_USUARIOS_SISTEMA AS ID_COLABORADOR, r.MODULOS_ROL_USUARIOS_SISTEMAS AS MODULOS
                               FROM usuarios_sistema u
                               INNER JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA
                              WHERE u.NOM_USUARIO_SISTEMA = ? AND u.CLAVE_USUARIO_SISTEMA = ? AND u.ESTADO = 1 AND r.ESTADO = 1",
                            'ss', array((string)$usuario, (string)$claveCifrada));
    }

    //Las filas del ausentismo, con el periodo opcional (año*100+mes; 0 = sin límite).
    //$datos queda por compatibilidad: solo existe 'ausentismo'. Devuelve false si no se pudieron leer
    public static function datos($datos, $desde = 0, $hasta = 0)
    {
        $donde = array(); $tipos = ''; $params = array();
        if ($desde > 0) { $donde[] = "PeriodoOrden >= ?"; $tipos .= 'i'; $params[] = (int)$desde; }
        if ($hasta > 0) { $donde[] = "PeriodoOrden <= ?"; $tipos .= 'i'; $params[] = (int)$hasta; }
        $w = count($donde) ? ' WHERE ' . implode(' AND ', $donde) : '';
        $sql = "SELECT Periodo, PeriodoOrden, Concesion, Estacion, TipoEstacion, Cargo, Novedad, Fecha, Anio, Mes, Dia, DiaTxt, Quincena, Cantidad
                  FROM vw_pbi_ausentismo" . $w . " ORDER BY PeriodoOrden, Concesion, Estacion, Fecha";
        return HanaDB::q($sql, $tipos, $params);
    }
}
