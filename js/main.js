//js/main.js
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const userMenu = document.querySelector('.user-menu');
    
    // Toggle sidebar
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            
            // Guardar preferencia en localStorage
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
        });
    }
    
    // Cargar preferencia del sidebar
    const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    if (sidebarCollapsed) {
        sidebar.classList.add('collapsed');
    }
    
    // Cerrar dropdown al hacer click fuera
    document.addEventListener('click', function(e) {
        if (!userMenu.contains(e.target)) {
            const dropdown = userMenu.querySelector('.user-dropdown');
            dropdown.style.opacity = '0';
            dropdown.style.visibility = 'hidden';
        }
    });
    
    // Mobile sidebar toggle
    function handleMobileSidebar() {
        if (window.innerWidth <= 768) {
            sidebar.classList.remove('collapsed');
            sidebar.classList.add('mobile-open');
        } else {
            sidebar.classList.remove('mobile-open');
        }
    }
    
    window.addEventListener('resize', handleMobileSidebar);
    handleMobileSidebar();
});