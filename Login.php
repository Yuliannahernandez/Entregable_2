<?php
// login.php
require_once 'config/session.php';
require_once 'includes/auth.php';

session_unset();
session_destroy();
session_start(); // nueva sesión limpia

$message = '';

// Procesar formulario de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identificacion = trim($_POST['identificacion'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($identificacion) || empty($password)) {
        $message = 'Usuario y/o contraseña incorrectos.';
    } else {
        $result = authenticateUser($identificacion, $password);

        
        if ($result['success']) {
            $_SESSION['identificacion'] = $result['user']['identificacion'];
            $_SESSION['nombre'] = $result['user']['nombre'];
            $_SESSION['apellido'] = $result['user']['apellido'];
            $_SESSION['correo'] = $result['user']['correo'];
            $_SESSION['id_rol'] = $result['user']['id_rol'];
            
            ob_clean(); // limpia el buffer antes de redirigir

            //Actualizado por Yuliana

             // Redirigir según el ro
            $redirect = 'Bienvenida.php';
            if ($result['user']['id_rol'] == 1) {
                // Administrador -> ir a generar inconsistencias
                $redirect = 'generar-inconsistencias.php';
            } elseif ($result['user']['id_rol'] == 2 ) {
                // Jefe de área -> ir a pendientes
                $redirect = 'pendientes.php';
            }
            
            header('Location: Bienvenida.php');
            exit;
        } else {
            $message = $result['message'];
        }
    }
}

if (isset($_SESSION['login_message'])) {
    $message = $_SESSION['login_message'];
    unset($_SESSION['login_message']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Sistema de Marcaje</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <img src="otros/logo1.png" alt="Logo Empresa">
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="identificacion">Usuario:</label>
                <input type="text" id="identificacion" name="identificacion" required 
                       placeholder="Ingrese su número de identificación"
                       value="<?php echo isset($_POST['identificacion']) ? htmlspecialchars($_POST['identificacion']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="password">Contraseña:</label>
                <input type="password" id="password" name="password" required 
                       placeholder="Ingrese su contraseña">
            </div>
            
            <button type="submit" class="btn-login">Aceptar</button>
        </form>
    </div>
</body>
</html>