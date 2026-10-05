<?php
//Esta pantalla se reemplazó por el PDF del arqueo, que tiene la misma forma de
//los formatos originales. Se deja para que los enlaces viejos sigan sirviendo
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
header('Location: ArqueoPdfVista.php?ver=1&id=' . $id);
exit;
