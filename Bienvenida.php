<?php
require_once 'config/session.php';
require_once 'includes/auth.php';

requireAuth();

// Solo roles válidos
if (!hasRole([2, 3])) {
    header('Location: login.php');
    exit;
}

$pageTitle = "Bienvenida";
require_once 'templates/header.php';
?>
<div class="welcome-container">
    <div class="welcome-content">
        <img src="otros/logo1.png" alt="Logo Empresa" class="welcome-logo">
        <h1 class="welcome-title">Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre'] . ' ' . $_SESSION['apellido']); ?></h1>
    </div>
</div>
<?php
require_once 'templates/footer.php';
?>