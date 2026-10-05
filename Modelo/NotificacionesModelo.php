<?php
//Modelo de notificaciones automaticas por correo de listas de chequeo
require "../Conexion/ConexionDB.php";

Class Notificaciones
{
     public function __construct()
     {
     }

     //Crea una configuracion de notificacion para una lista de chequeo
     public function insertar($idGrupo, $destinatarios, $frecuencia, $asunto)
     {
             $sql = "INSERT INTO notificaciones_listas
                     (ID_GRUPO_LISTA_CHEQUEO, DESTINATARIOS, FRECUENCIA, ASUNTO, ESTADO, FEC_CREACION)
                     VALUES ('$idGrupo','$destinatarios','$frecuencia','$asunto','1', NOW())";
             return ejecutarConsulta($sql);
     }

     //Modifica una configuracion existente
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

     //Desactiva una notificacion (no se borra, queda en estado 0)
     public function anular($id)
     {
             $sql = "UPDATE notificaciones_listas SET ESTADO='0' WHERE ID_NOTIFICACION='$id'";
             return ejecutarConsulta($sql);
     }

     //Vuelve a activar una notificacion anulada
     public function activar($id)
     {
             $sql = "UPDATE notificaciones_listas SET ESTADO='1' WHERE ID_NOTIFICACION='$id'";
             return ejecutarConsulta($sql);
     }

     //Trae una notificacion puntual, para cargarla en el formulario
     public function mostrar($id)
     {
             $sql = "SELECT * FROM notificaciones_listas WHERE ID_NOTIFICACION='$id'";
             return ejecutarConsultaSimpleFila($sql);
     }

     //Listado para la tabla de la pantalla de administracion
     public function listar()
     {
             $sql = "SELECT n.*, g.NOM_GRUPO_LISTA_CHEQUEO
                     FROM notificaciones_listas n
                     JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = n.ID_GRUPO_LISTA_CHEQUEO
                     ORDER BY n.ID_NOTIFICACION DESC";
             return ejecutarConsulta($sql);
     }

     //Notificaciones activas de una lista y frecuencia dadas.
     //La usa el envio inmediato (al guardar) y tambien los resumenes
     public function activasPorGrupo($idGrupo, $frecuencia)
     {
             $sql = "SELECT * FROM notificaciones_listas
                     WHERE ID_GRUPO_LISTA_CHEQUEO='$idGrupo'
                       AND FRECUENCIA='$frecuencia'
                       AND ESTADO='1'";
             return ejecutarConsulta($sql);
     }

     //Todas las notificaciones activas de una frecuencia (para los resumenes programados)
     public function activasPorFrecuencia($frecuencia)
     {
             $sql = "SELECT n.*, g.NOM_GRUPO_LISTA_CHEQUEO
                     FROM notificaciones_listas n
                     JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = n.ID_GRUPO_LISTA_CHEQUEO
                     WHERE n.FRECUENCIA='$frecuencia' AND n.ESTADO='1'";
             return ejecutarConsulta($sql);
     }

     //Deja constancia de cuando se envio por ultima vez un resumen
     public function marcarEnvio($id)
     {
             $sql = "UPDATE notificaciones_listas SET ULTIMO_ENVIO=NOW() WHERE ID_NOTIFICACION='$id'";
             return ejecutarConsulta($sql);
     }

     //Datos de cabecera de UNA lista diligenciada: quien, donde y cuando
     public function cabeceraLista($idLista)
     {
             $sql = "SELECT lc.ID_LISTA_CHEQUEO, lc.FEC_REGISTRO_LISTA_CHEQUEO,
                            co.NOM_CENTRO_OP, c.NOM_COLABORADOR, c.MAIL_COLABORADOR,
                            g.NOM_GRUPO_LISTA_CHEQUEO, g.ID_GRUPO_LISTA_CHEQUEO
                     FROM lista_chequeo lc
                     JOIN centros_operacion co ON co.ID_CENTRO_OP = lc.ID_CENTRO_OP_LISTA_CHEQUEO
                     JOIN colaboradores c      ON c.ID_COLABORADOR = lc.ID_COLABORADOR_LISTA_CHEQUEO
                     JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = lc.ID_GRUPO_LISTA_CHEQUEO
                     WHERE lc.ID_LISTA_CHEQUEO='$idLista'";
             return ejecutarConsultaSimpleFila($sql);
     }

     //Preguntas y respuestas de UNA lista diligenciada, en el orden configurado
     public function respuestasDeLista($idLista)
     {
             $sql = "SELECT dgl.PREGUNTA_DETALLE_GRUPO_LISTA_CHEQUEO AS PREGUNTA,
                            dgl.TIPO_RESPUESTA,
                            dl.RESPUTA_DETALLE_LISTA_CHEQUEO AS RESPUESTA
                     FROM detale_lista_chequeo dl
                     JOIN detalle_grupo_lista_cheque dgl
                       ON dgl.ID_DETALLE_GRUPO_LISTA_CHEQUEO = dl.ID_DETALLE_GRUPO_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO
                     WHERE dl.ID_LISTA_CHEQUEO_DETALE_LISTA_CHEQUEO='$idLista'
                     ORDER BY dgl.ORDEN ASC, dgl.ID_DETALLE_GRUPO_LISTA_CHEQUEO ASC";
             return ejecutarConsulta($sql);
     }

     //Listas diligenciadas de un grupo dentro de un rango de fechas.
     //Es la base de los resumenes diario y semanal
     public function listasEnRango($idGrupo, $desde, $hasta)
     {
             $sql = "SELECT lc.ID_LISTA_CHEQUEO, lc.FEC_REGISTRO_LISTA_CHEQUEO,
                            co.NOM_CENTRO_OP, c.NOM_COLABORADOR
                     FROM lista_chequeo lc
                     JOIN centros_operacion co ON co.ID_CENTRO_OP = lc.ID_CENTRO_OP_LISTA_CHEQUEO
                     JOIN colaboradores c      ON c.ID_COLABORADOR = lc.ID_COLABORADOR_LISTA_CHEQUEO
                     WHERE lc.ID_GRUPO_LISTA_CHEQUEO='$idGrupo'
                       AND lc.FEC_REGISTRO_LISTA_CHEQUEO BETWEEN '$desde' AND '$hasta'
                     ORDER BY c.NOM_COLABORADOR ASC, lc.FEC_REGISTRO_LISTA_CHEQUEO ASC";
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