<?php
// logout.php
require_once 'config/session.php';
require_once 'includes/auth.php';

// Destruir todas las variables de sesión
$_SESSION = array();

// Destruir la sesión
session_destroy();

// Redirigir al login
header('Location: login.php');
exit;
?>