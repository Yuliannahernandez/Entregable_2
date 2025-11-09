<?php
// templates/header.php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/auth.php';

// Asegurar que el usuario esté autenticado
requireAuth();

// Obtener información del usuario actual
$userInfo = getCurrentUserInfo();

// CORREGIDO: Usar nombres de sesión consistentes
$userName = $_SESSION['nombre'] ?? ($userInfo['nombre'] ?? 'Usuario');
$userLastName = $_SESSION['apellido'] ?? ($userInfo['apellido'] ?? '');
$fullName = trim($userName . ' ' . $userLastName);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - ' : ''; ?>
        Sistema de Reloj Marcador
    </title>
    <link rel="stylesheet" href="css/template.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <img src="otros/logo1.png" alt="Logo" class="logo">
                <h1 class="system-name">Sistema de Reloj Marcador</h1>
            </div>
            
            <nav class="sidebar-nav">
                <ul class="nav-menu">
                    <?php include __DIR__ . '/sidebar.php'; ?>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Header -->
            <header class="top-header">
                <div class="header-left">
                    <button class="sidebar-toggle" id="sidebarToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
                
                <div class="header-right">
                    <div class="user-menu">
                        <div class="user-info">
                            <img src="otros/avatar.jpg" alt="Avatar" class="user-avatar">
                            <span class="user-name"><?php echo htmlspecialchars($fullName); ?></span>
                            <i class="fas fa-chevron-down dropdown-arrow"></i>
                        </div>
                        <div class="user-dropdown">
                            <div class="dropdown-divider"></div>
                            <a href="logout.php" class="dropdown-item logout">
                                <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Content Area -->
            <div class="content-wrapper">