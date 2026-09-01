<?php
require "../Conexion/ConexionDB.php";
class Novedades
{
        //Implementamos el super constructor 
        public function __construct()
        {
        }
        //Implementamos un método para insertar registros 	
        public function insertar($fechacreacion,$colaboraador,$centroopertivo,$asignado,$observador,$titulo,$descripcion,$imagen,$validez,$relevancia,$fechalimite)
        {
                $sql = "INSERT INTO novedades_hallazgos(FEC_CREACION_NOVEDADES_HALLAZGOS,ID_COLABORADOR_NOVEDADES_HALLAZGOS,ID_CENTRO_OP_NOVEDADES_HALLAZGOS,ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS,ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS,ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS,DESCR_NOVEDADES_HALLAZGOS,NOM_FOTO_INI_NOVEDADES_HALLAZGOS,VALIDEZ_NOVEDADES_HALLAZGOS,ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS,FECHA_LIMITE_NOVEDAD,ESTADO_NOVEDAD)
                            VALUES ('$fechacreacion','$colaboraador','$centroopertivo','$asignado','$observador','$titulo','$descripcion','$imagen','$validez','$relevancia','$fechalimite',1)";
                return ejecutarConsulta_retornarID($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Implementamos un método para editar registros
        public function insertarRespuesta($IDnovedad,$colaborador,$fecha,$descripcion,$estado)
        {
                $sql = "INSERT INTO respuesta_novedades_hallazgos(ID_NOVEDAD_HALLAZGOS_RESPUESTA_NOVEDADES_HALLAZGOS,ID_COLABORADOR_RESPUESTA,FEC_RESPUESTA_NOVEDAD_HALLAZGOS,DESCR_RESPUESTA_NOVEDAD_HALLAZGOS,ESTADO_NOVEDAD) VALUES ('$IDnovedad','$colaborador','$fecha','$descripcion','$estado')";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para mostrar los datos de un registro a modificar
        public function mostrar($IDnovedad)
        {
                $sql = "SELECT novedades_hallazgos.*,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS,colaboradores.NOM_COLABORADOR, T2.NOM_COLABORADOR as asignado,estados_relevancia.NOMBRE_ESTADOS_RELEVANCIA,estados_relevancia.DIAS_ESTADOS_RELEVANCIAS,centros_operacion.NOM_CENTRO_OP, observador_novedades_hallazgos.NOM_OBSERVADOR_NOVEDADES_HALLAZGOS FROM novedades_hallazgos INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_NOVEDADES_HALLAZGOS INNER JOIN colaboradores T2 on T2.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP_NOVEDADES_HALLAZGOS INNER JOIN estados_relevancia on estados_relevancia.ID_ESTADOS_RELEVANCIA=novedades_hallazgos.ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS INNER JOIN observador_novedades_hallazgos on observador_novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS WHERE novedades_hallazgos.ID_NOVEDADES_HALLAZGOS='$IDnovedad'";
                return ejecutarConsultaSimpleFila($sql);
        }
    
    
          public function mostrarRespuesta($IDnovedad)
        {
                $sql = "SELECT colaboradores.NOM_COLABORADOR, respuesta_novedades_hallazgos.* FROM respuesta_novedades_hallazgos INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=respuesta_novedades_hallazgos.ID_COLABORADOR_RESPUESTA WHERE respuesta_novedades_hallazgos.ID_NOVEDAD_HALLAZGOS_RESPUESTA_NOVEDADES_HALLAZGOS=$IDnovedad ORDER BY respuesta_novedades_hallazgos.FEC_RESPUESTA_NOVEDAD_HALLAZGOS ASC;";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para listar los registros
        public function listar()
        {
                $sql = "SELECT novedades_hallazgos.*,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS,colaboradores.NOM_COLABORADOR, T2.NOM_COLABORADOR as asignado,estados_relevancia.NOMBRE_ESTADOS_RELEVANCIA,estados_relevancia.DIAS_ESTADOS_RELEVANCIAS,centros_operacion.NOM_CENTRO_OP,centros_operacion.ID_CENTRO_OP, observador_novedades_hallazgos.NOM_OBSERVADOR_NOVEDADES_HALLAZGOS,proyectos.NOM_PROYECTO FROM novedades_hallazgos INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_NOVEDADES_HALLAZGOS INNER JOIN colaboradores T2 on T2.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP_NOVEDADES_HALLAZGOS INNER JOIN proyectos ON proyectos.ID_PROYECTO=centros_operacion.ID_PROYECTO_CENTRO_OP INNER JOIN estados_relevancia on estados_relevancia.ID_ESTADOS_RELEVANCIA=novedades_hallazgos.ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS INNER JOIN observador_novedades_hallazgos on observador_novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS";
                return ejecutarConsulta($sql);
        }
    
        public function listarCondicional($condicional)
        {
                $sql = "SELECT novedades_hallazgos.*,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS,colaboradores.NOM_COLABORADOR, T2.NOM_COLABORADOR as asignado,estados_relevancia.NOMBRE_ESTADOS_RELEVANCIA,estados_relevancia.DIAS_ESTADOS_RELEVANCIAS,centros_operacion.NOM_CENTRO_OP,centros_operacion.ID_CENTRO_OP, observador_novedades_hallazgos.NOM_OBSERVADOR_NOVEDADES_HALLAZGOS,proyectos.NOM_PROYECTO FROM novedades_hallazgos INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_NOVEDADES_HALLAZGOS INNER JOIN colaboradores T2 on T2.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP_NOVEDADES_HALLAZGOS INNER JOIN proyectos ON proyectos.ID_PROYECTO=centros_operacion.ID_PROYECTO_CENTRO_OP INNER JOIN estados_relevancia on estados_relevancia.ID_ESTADOS_RELEVANCIA=novedades_hallazgos.ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS INNER JOIN observador_novedades_hallazgos on observador_novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS $condicional";
                return ejecutarConsulta($sql);
        }
    
    
   
    
        //Implementar un método para listar los registros
        public function diaslimites($id)
        {
                $sql = "SELECT estados_relevancia.DIAS_ESTADOS_RELEVANCIAS FROM estados_relevancia WHERE estados_relevancia.ID_ESTADOS_RELEVANCIA=$id";
                return ejecutarConsultaSimpleFila($sql);
        }
    
         public function editarEstado($IDnovedad,$estado)
        {
                $sql = "UPDATE novedades_hallazgos SET ESTADO_NOVEDAD=$estado WHERE ID_NOVEDADES_HALLAZGOS=$IDnovedad";
                return ejecutarConsulta($sql);
        }
    
    
          public function editarfecha($IDnovedad,$fecha)
        {
                $sql = "UPDATE novedades_hallazgos SET  FEC_CIERRE_NOVEDAD_HALLAZGOS='$fecha' WHERE ID_NOVEDADES_HALLAZGOS=$IDnovedad";
                return ejecutarConsulta($sql);
        }
    
    
           public function imagencierre($IDnovedad,$imagen)
        {
                $sql = "UPDATE novedades_hallazgos SET NOM_FOTO_FIN_NOVEDADES_HALLAZGOS='$imagen' WHERE ID_NOVEDADES_HALLAZGOS=$IDnovedad";
                return ejecutarConsulta($sql);
        }
    
          public function centrosOP($IDusaurio)
        {
                $sql = "SELECT asoc_usuarios_sistemas_x_cop.* FROM asoc_usuarios_sistemas_x_cop WHERE asoc_usuarios_sistemas_x_cop.ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP=$IDusaurio";
                return ejecutarConsulta($sql);
        }
	
	      public function proyectos()
        {
                $sql = "SELECT * FROM proyectos WHERE proyectos.Estado=1";
                return ejecutarConsulta($sql);
        }
	
	       public function correo($id)
        {
                $sql = "SELECT colaboradores.NOM_COLABORADOR,colaboradores.ID_COLABORADOR,colaboradores.MAIL_COLABORADOR, centros_operacion.NOM_CENTRO_OP, estados_relevancia.NOMBRE_ESTADOS_RELEVANCIA,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS FROM novedades_hallazgos INNER JOIN colaboradores ON colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP_NOVEDADES_HALLAZGOS INNER JOIN estados_relevancia ON estados_relevancia.ID_ESTADOS_RELEVANCIA=novedades_hallazgos.ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS WHERE novedades_hallazgos.ID_NOVEDADES_HALLAZGOS=$id";
                return ejecutarConsultaSimpleFila($sql);
        }
        
    
		
}