<?php
/*
  Pendientes con Regency, Novedades del proyecto y Procesos disciplinarios se
  quitaron del sistema (su tabla ya no existe). Si algo llama aquí, se responde
  con un mensaje claro en vez de un error de base de datos.
*/
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('error' => 'Este módulo se quitó del sistema.'), JSON_UNESCAPED_UNICODE);
