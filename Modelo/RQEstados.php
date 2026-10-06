<?php
/*
  HANA — Estados de las requisiciones (RQ)
  ---------------------------------------------------------------------------
  El flujo de la RQ es fijo y vive aquí. La tabla rq guarda solo el número del
  estado (rq.ID_RQ_ESTADO); el nombre, el color y el orden salen de esta lista.

    Solicitada (1) -> Aprobada (3) o Rechazada (7)
    Anulada (8): la RQ queda fuera de circulación, sin borrarse.

  Los estados 2, 4, 5 y 6 son del flujo largo que se usaba antes (revisión SST,
  compra, instalación y recibido). Ya no se asignan, pero las RQ y el historial
  de esa época los siguen mostrando con su nombre.

  El plazo para aprobar una RQ es el parámetro RQ_DIAS_APROBACION
  (Configuración → Parámetros del sistema).

  El color es el final de la clase de la pantalla: rq-badge-ambar, -verde...
*/
require_once __DIR__ . "/HanaConfig.php";

class RqEstado
{
    const SOLICITADA = 1;
    const APROBADA   = 3;
    const RECHAZADA  = 7;
    const ANULADA    = 8;

    //id => nombre, color, orden, final (ya no cambia), días esperados, vigente
    const LISTA = array(
        1 => array('nombre' => 'Solicitada',      'color' => 'ambar', 'orden' => 1,  'final' => 0, 'dias' => 3,  'vigente' => 1),
        2 => array('nombre' => 'En revisión SST', 'color' => 'ambar', 'orden' => 2,  'final' => 0, 'dias' => 3,  'vigente' => 0),
        3 => array('nombre' => 'Aprobada',        'color' => 'verde', 'orden' => 3,  'final' => 1, 'dias' => 4,  'vigente' => 1),
        4 => array('nombre' => 'En compra',       'color' => 'ambar', 'orden' => 4,  'final' => 0, 'dias' => 15, 'vigente' => 0),
        5 => array('nombre' => 'Instalada',       'color' => 'verde', 'orden' => 5,  'final' => 0, 'dias' => 5,  'vigente' => 0),
        6 => array('nombre' => 'Recibida',        'color' => 'verde', 'orden' => 6,  'final' => 1, 'dias' => 0,  'vigente' => 0),
        7 => array('nombre' => 'Rechazada',       'color' => 'vino',  'orden' => 90, 'final' => 1, 'dias' => 0,  'vigente' => 1),
        8 => array('nombre' => 'Anulada',         'color' => 'gris',  'orden' => 99, 'final' => 1, 'dias' => 0,  'vigente' => 1),
    );

    //Días que puede durar una RQ en ese estado antes de quedar vencida.
    //El de "Solicitada" es el plazo de aprobación de Parámetros del sistema
    public static function dias($id)
    {
        $id = (int)$id;
        if ($id === self::SOLICITADA) {
            return HanaConfig::num('RQ_DIAS_APROBACION', self::LISTA[self::SOLICITADA]['dias']);
        }
        return isset(self::LISTA[$id]) ? self::LISTA[$id]['dias'] : 0;
    }

    //Un estado con los nombres de campo que usa la pantalla
    public static function datos($id)
    {
        $id = (int)$id;
        $e = isset(self::LISTA[$id]) ? self::LISTA[$id]
           : array('nombre' => 'Estado ' . $id, 'color' => 'gris', 'orden' => 100, 'final' => 1, 'dias' => 0, 'vigente' => 0);
        return array(
            'ID_RQ_ESTADO'   => $id,
            'NOM_RQ_ESTADO'  => $e['nombre'],
            'COLOR'          => $e['color'],
            'ORDEN'          => $e['orden'],
            'ES_FINAL'       => $e['final'],
            'DIAS_ESPERADOS' => self::dias($id),
        );
    }

    //-----------------------------------------------------------------------
    // Para las consultas: el nombre, el color, etc. salen como columnas
    // calculadas a partir de la columna con el número del estado
    // (por ejemplo r.ID_RQ_ESTADO). Los valores son de esta lista, no del
    // usuario, así que van directo en el SQL.
    //-----------------------------------------------------------------------

    //CASE <columna> WHEN 1 THEN 'Solicitada' ... END
    public static function sqlCampo($columna, $campo)
    {
        $sql = "CASE $columna";
        foreach (array_keys(self::LISTA) as $id) {
            $d = self::datos($id);
            $v = $d[$campo];
            $sql .= " WHEN $id THEN " . (is_int($v) ? $v : "'" . str_replace("'", "''", $v) . "'");
        }
        $otro = ($campo === 'NOM_RQ_ESTADO') ? "CONCAT('Estado ', $columna)"
              : (($campo === 'COLOR') ? "'gris'" : (($campo === 'ES_FINAL') ? '1' : (($campo === 'ORDEN') ? '100' : '0')));
        return $sql . " ELSE $otro END";
    }

    //Las columnas del estado de una vez: NOM_RQ_ESTADO, COLOR, ES_FINAL...
    public static function sqlColumnas($columna, $campos = array('NOM_RQ_ESTADO', 'COLOR', 'ES_FINAL', 'DIAS_ESPERADOS'))
    {
        $partes = array();
        foreach ($campos as $c) { $partes[] = self::sqlCampo($columna, $c) . " AS $c"; }
        return implode(",\n                       ", $partes);
    }

    //1 si la RQ lleva en su estado más días de los esperados (solo estados no finales)
    public static function sqlVencida($columna, $columnaFecha)
    {
        $casos = array();
        foreach (array_keys(self::LISTA) as $id) {
            $dias = self::dias($id);
            if (self::LISTA[$id]['final'] === 0 && $dias > 0) {
                $casos[] = "WHEN $columna = $id AND DATEDIFF(NOW(), $columnaFecha) > $dias THEN 1";
            }
        }
        return $casos ? "CASE " . implode(' ', $casos) . " ELSE 0 END" : "0";
    }
}
