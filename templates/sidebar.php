<?php
// templates/sidebar.php

// CORREGIDO: Usar 'id_rol' en lugar de 'user_role'
$currentRole = $_SESSION['id_rol'] ?? null; 
$currentPage = basename($_SERVER['PHP_SELF']);

$menuItems = [];

// Menú para FUNCIONARIO (rol = 3)
if (hasRole([3])) {
    $menuItems = array_merge($menuItems, [
        [
            'icon' => 'fas fa-fingerprint',
            'text' => 'Mis Marcas',
            'url' => 'mis-marcas.php',
            'roles' => [3]
        ],
        [
            'icon' => 'fas fa-comment-medical',
            'text' => 'Justificar Inconsistencia',
            'url' => 'justificar-inconsistencia.php',
            'roles' => [3]
        ],
        [
            'icon' => 'fas fa-file-signature',
            'text' => 'Solicitar Permiso',
            'url' => 'solicitar-permiso.php',
            'roles' => [3]
        ],
        [
            'icon' => 'fas fa-umbrella-beach',
            'text' => 'Solicitud de Vacaciones',
            'url' => 'solicitar-vacaciones.php',
            'roles' => [3]
        ]
    ]);
}

// Menú para JEFE DE ÁREA (rol = 2)
if (hasRole([2])) {
    $menuItems = array_merge($menuItems, [
        [
            'icon' => 'fas fa-tasks',
            'text' => 'Pendientes',
            'url' => 'pendientes.php',
            'roles' => [2]
        ],
        [
            'icon' => 'fas fa-search',
            'text' => 'Consultar Resoluciones',
            'url' => 'consultar-resoluciones.php',
            'roles' => [2]
        ]
    ]);
}

// Menú para ADMINISTRADOR (rol = 1)
if (hasRole([1])) {
    $menuItems = array_merge($menuItems, [
        [
            'icon' => 'fas fa-exclamation-triangle',
            'text' => 'Generar Inconsistencias',
            'url' => 'generar-inconsistencias.php',
            'roles' => [1]
        ]
    ]);
}

// Generar el menú dinámicamente
foreach ($menuItems as $item) {
    if (in_array($currentRole, $item['roles'])) {
        $isActive = ($currentPage === $item['url']) ? 'active' : '';
        echo "
        <li class='nav-item $isActive'>
            <a href='{$item['url']}' class='nav-link'>
                <i class='{$item['icon']}'></i>
                <span class='nav-text'>{$item['text']}</span>
            </a>
        </li>";
    }
}
?>