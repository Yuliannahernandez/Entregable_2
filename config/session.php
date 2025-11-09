<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar si el usuario está autenticado
function isAuthenticated() {
    return isset($_SESSION['identificacion']) && !empty($_SESSION['identificacion']);
}

// Redireccionar si no está autenticado
function requireAuth() {
    if (!isAuthenticated()) {
        // Evita redirección infinita si ya estás en login.php
        if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
            $_SESSION['login_message'] = "Por favor inicie sesión para utilizar el sistema";
            header('Location: login.php');
            exit;
        }
    }
}

// Registrar intento fallido de login
function registerFailedAttempt($identificacion, $pdo) {
    $sql = "UPDATE funcionarios SET intentos_login = intentos_login + 1 WHERE identificacion = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$identificacion]);

    $sql = "UPDATE funcionarios SET bloqueado = 1 WHERE identificacion = ? AND intentos_login >= 3";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$identificacion]);
}

// Resetear intentos fallidos
function resetFailedAttempts($identificacion, $pdo) {
    $sql = "UPDATE funcionarios SET intentos_login = 0, bloqueado = 0 WHERE identificacion = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$identificacion]);
}
?>