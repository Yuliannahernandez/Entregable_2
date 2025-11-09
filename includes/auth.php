<?php
// includes/auth.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

function authenticateUser($identificacion, $password) {
    global $pdo;
    
    // Buscar usuario por identificación (PK de la tabla)
    $sql = "SELECT identificacion, nombre, apellido, correo, contrasena_hash, id_rol, estado, bloqueado, intentos_login 
            FROM funcionarios 
            WHERE identificacion = ? AND estado = 'ACTIVO'";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$identificacion]);
    $user = $stmt->fetch();
    
    if (!$user) {
        return ['success' => false, 'message' => 'Usuario y/o contraseña incorrectos.'];
    }
    
    // Verificar si está bloqueado
    if ($user['bloqueado']) {
        return ['success' => false, 'message' => 'Usuario bloqueado por múltiples intentos fallidos.'];
    }
    
    // Verificar contraseña
    if (hash('sha256', $password) === $user['contrasena_hash']) {
        // Contraseña correcta - resetear intentos fallidos
        resetFailedAttempts($user['identificacion'], $pdo);
        
        // Actualizar último login
        $sql = "UPDATE funcionarios SET fecha_ultimo_login = NOW() WHERE identificacion = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user['identificacion']]);
        
        return [
            'success' => true, 
            'user' => [
                'identificacion' => $user['identificacion'],
                'nombre' => $user['nombre'],
                'apellido' => $user['apellido'],
                'correo' => $user['correo'],
                'id_rol' => $user['id_rol']
            ]
        ];
    } else {
        // Contraseña incorrecta - registrar intento fallido
        registerFailedAttempt($user['identificacion'], $pdo);
        
        // Verificar si se bloqueó después de este intento
        $sql = "SELECT bloqueado, intentos_login FROM funcionarios WHERE identificacion = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user['identificacion']]);
        $updatedUser = $stmt->fetch();
        
        if ($updatedUser['bloqueado']) {
            return ['success' => false, 'message' => 'Usuario bloqueado por múltiples intentos fallidos.'];
        } else {
            $intentosRestantes = 3 - $updatedUser['intentos_login'];
            if ($intentosRestantes > 0) {
                return ['success' => false, 'message' => 'Usuario y/o contraseña incorrectos. Le quedan ' . $intentosRestantes . ' intentos.'];
            } else {
                return ['success' => false, 'message' => 'Usuario y/o contraseña incorrectos.'];
            }
        }
    }
}

function getCurrentUserInfo() {
    if (!isAuthenticated()) {
        return null;
    }
    
    global $pdo;
    $sql = "SELECT f.identificacion, f.nombre, f.apellido, f.correo, r.nombre_rol as rol 
            FROM funcionarios f 
            INNER JOIN roles r ON f.id_rol = r.id_rol 
            WHERE f.identificacion = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$_SESSION['identificacion']]);
    return $stmt->fetch();
}

function hasRole($allowedRoles) {
    if (!isAuthenticated()) {
        return false;
    }

    if (!isset($_SESSION['id_rol'])) {
        global $pdo;
        $sql = "SELECT id_rol FROM funcionarios WHERE identificacion = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$_SESSION['identificacion']]);
        $user = $stmt->fetch();
        if ($user) {
            $_SESSION['id_rol'] = $user['id_rol'];
        } else {
            return false;
        }
    }

    // Compara por ID 
    $userRole = intval($_SESSION['id_rol']);

    if (is_array($allowedRoles)) {
        return in_array($userRole, $allowedRoles);
    } else {
        return $userRole === intval($allowedRoles);
    }
}

function requireRole($allowed_roles = []) {
    requireAuth(); // Usa la función que ya existe en session.php
    
    if (!empty($allowed_roles) && !hasRole($allowed_roles)) {
        header('Location: acceso-denegado.php');
        exit();
    }
    
    return true;
}
?>