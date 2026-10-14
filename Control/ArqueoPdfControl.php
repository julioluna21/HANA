<?php
/*
  HANA - Arqueo en PDF: quién puede descargarlo y con qué datos
  ---------------------------------------------------------------------------
  Lo usa Vista/ArqueoPdfVista.php: este controlador revisa la sesión, trae el
  arqueo del modelo y deja $arqueo listo para dibujar.
  Ver o descargar el PDF lo puede cualquier persona con sesión, sea o no de su
  peaje o de su proyecto. Crear o anular un arqueo sigue con sus permisos
  (Control/ArqueoControl.php).
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Bogota');
require_once __DIR__ . '/AccesoHelper.php';
require_once __DIR__ . '/../Modelo/ArqueoModelo.php';

if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { header('Location: login.php'); exit; }

//Respuesta cuando el arqueo no existe
function arqPdfNegar()
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se encontró el arqueo.';
    exit;
}

$arqueo = (new Arqueo())->mostrar(isset($_GET['id']) ? (int)$_GET['id'] : 0);
if (!$arqueo) { arqPdfNegar(); }
