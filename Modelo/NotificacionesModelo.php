<?php
/*
  HANA - Correos de las listas de chequeo (modelo)
  ---------------------------------------------------------------------------
  Consultas de la tabla notificaciones_listas: a qué correos se avisa cuando
  alguien llena una lista y cada cuánto (inmediato, diario o semanal), y los
  datos que llevan esos correos. Lo usan Control/NotificacionesControl.php y
  Control/EnvioNotificacionesControl.php.
*/
require "../Conexion/ConexionDB.php";

Class Notificaciones
{
     //No necesita preparar nada al crearse
     public function __construct()
     {
     }

     //Crea una configuración de notificación para una lista de chequeo
     public function insertar($idGrupo, $destinatarios, $frecuencia, $asunto)
     {
             $sql = "INSERT INTO notificaciones_listas
                     (ID_GRUPO_LISTA_CHEQUEO, DESTINATARIOS, FRECUENCIA, ASUNTO, ESTADO, FEC_CREACION)
                     VALUES ('$idGrupo','$destinatarios','$frecuencia','$asunto','1', NOW())";
             return ejecutarConsulta($sql);
     }

     //Modifica una configuración existente
     public function editar($id, $idGrupo, $destinatarios, $frecuencia, $asunto)
     {
             $sql = "UPDATE notificaciones_listas SET
                     ID_GRUPO_LISTA_CHEQUEO='$idGrupo',
                     DESTINATARIOS='$destinatarios',
                     FRECUENCIA='$frecuencia',
                     ASUNTO='$asunto'
                     WHERE ID_NOTIFICACION=$id";
             return ejecutarConsulta($sql);
     }

     //Desactiva una notificación (no se borra, queda en estado 0)
     public function anular($id)
     {
             $sql = "UPDATE notificaciones_listas SET ESTADO='0' WHERE ID_NOTIFICACION='$id'";
             return ejecutarConsulta($sql);
     }

     //Vuelve a activar una notificación anulada
     public function activar($id)
     {
             $sql = "UPDATE notificaciones_listas SET ESTADO='1' WHERE ID_NOTIFICACION='$id'";
             return ejecutarConsulta($sql);
     }

     //Trae una notificación puntual, para cargarla en el formulario
     public function mostrar($id)
     {
             $sql = "SELECT * FROM notificaciones_listas WHERE ID_NOTIFICACION='$id'";
             return ejecutarConsultaSimpleFila($sql);
     }

     //Listado para la tabla de la pantalla de administración
     public function listar()
     {
             //El último envío sale de la bitácora: cada correo de un aviso queda ahí con su ID_NOTIFICACION
             $sql = "SELECT n.*, g.NOM_GRUPO_LISTA_CHEQUEO,
                            (SELECT MAX(b.FECHA) FROM bitacora_sistema b
                              WHERE b.TIPO = 'CORREO' AND b.ID_NOTIFICACION = n.ID_NOTIFICACION AND b.OK = 1) AS ULTIMO_ENVIO
                     FROM notificaciones_listas n
                     JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = n.ID_GRUPO_LISTA_CHEQUEO
                     ORDER BY n.ID_NOTIFICACION DESC";
             return ejecutarConsulta($sql);
     }

     //Notificaciones activas de una lista y frecuencia dadas.
     //La usa el envío inmediato (al guardar) y también los resúmenes
     public function activasPorGrupo($idGrupo, $frecuencia)
     {
             $sql = "SELECT * FROM notificaciones_listas
                     WHERE ID_GRUPO_LISTA_CHEQUEO='$idGrupo'
                       AND FRECUENCIA='$frecuencia'
                       AND ESTADO='1'";
             return ejecutarConsulta($sql);
     }

     //Todas las notificaciones activas de una frecuencia (para los resúmenes programados)
     public function activasPorFrecuencia($frecuencia)
     {
             $sql = "SELECT n.*, g.NOM_GRUPO_LISTA_CHEQUEO
                     FROM notificaciones_listas n
                     JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = n.ID_GRUPO_LISTA_CHEQUEO
                     WHERE n.FRECUENCIA='$frecuencia' AND n.ESTADO='1'";
             return ejecutarConsulta($sql);
     }

     //La constancia de cada envío ya queda en bitacora_sistema (con ID_NOTIFICACION), y de ahí
     //sale el "último envío" del listado. Se conserva el método para quien lo llama; no guarda nada aparte
     public function marcarEnvio($id)
     {
             return true;
     }

     //Datos de cabecera de UNA lista diligenciada: quién, dónde y cuándo
     public function cabeceraLista($idLista)
     {
             $sql = "SELECT lc.ID_LISTA_CHEQUEO, lc.FEC_REGISTRO,
                            co.NOM_CENTRO_OP, c.NOM_COLABORADOR, c.MAIL_COLABORADOR,
                            g.NOM_GRUPO_LISTA_CHEQUEO, g.ID_GRUPO_LISTA_CHEQUEO
                     FROM lista_chequeo lc
                     JOIN centros_operacion co ON co.ID_CENTRO_OP = lc.ID_CENTRO_OP
                     JOIN colaboradores c      ON c.ID_COLABORADOR = lc.ID_COLABORADOR
                     JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = lc.ID_GRUPO_LISTA_CHEQUEO
                     WHERE lc.ID_LISTA_CHEQUEO='$idLista'";
             return ejecutarConsultaSimpleFila($sql);
     }

     //Preguntas y respuestas de UNA lista diligenciada, en el orden configurado
     public function respuestasDeLista($idLista)
     {
             $sql = "SELECT dgl.PREGUNTA,
                            dgl.TIPO_RESPUESTA,
                            dl.RESPUESTA
                     FROM detalle_lista_chequeo dl
                     JOIN detalle_grupo_lista_chequeo dgl
                       ON dgl.ID_DETALLE_GRUPO_LISTA_CHEQUEO = dl.ID_PREGUNTA
                     WHERE dl.ID_LISTA_CHEQUEO='$idLista'
                     ORDER BY dgl.ORDEN ASC, dgl.ID_DETALLE_GRUPO_LISTA_CHEQUEO ASC";
             return ejecutarConsulta($sql);
     }

     //Listas diligenciadas de un grupo dentro de un rango de fechas.
     //Es la base de los resúmenes diario y semanal
     public function listasEnRango($idGrupo, $desde, $hasta)
     {
             $sql = "SELECT lc.ID_LISTA_CHEQUEO, lc.FEC_REGISTRO,
                            co.NOM_CENTRO_OP, c.NOM_COLABORADOR
                     FROM lista_chequeo lc
                     JOIN centros_operacion co ON co.ID_CENTRO_OP = lc.ID_CENTRO_OP
                     JOIN colaboradores c      ON c.ID_COLABORADOR = lc.ID_COLABORADOR
                     WHERE lc.ID_GRUPO_LISTA_CHEQUEO='$idGrupo'
                       AND lc.FEC_REGISTRO BETWEEN '$desde' AND '$hasta'
                     ORDER BY c.NOM_COLABORADOR ASC, lc.FEC_REGISTRO ASC";
             return ejecutarConsulta($sql);
     }

     //Colaboradores activos con correo, para el buscador de destinatarios
     public function selectColaboradores($busqueda)
     {
             $sql = "SELECT ID_COLABORADOR, NOM_COLABORADOR, MAIL_COLABORADOR
                     FROM colaboradores
                     WHERE ESTADO='1' AND MAIL_COLABORADOR <> ''
                       AND (NOM_COLABORADOR LIKE '%$busqueda%' OR MAIL_COLABORADOR LIKE '%$busqueda%')
                     ORDER BY NOM_COLABORADOR ASC LIMIT 20";
             return ejecutarConsulta($sql);
     }
}
?>