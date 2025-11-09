<?php
// HU#4: Ver listado de mis marcas más recientes
require_once 'includes/auth.php';
requireRole([3]); // Solo funcionarios

$identificacion = $_SESSION['identificacion'];
$pageTitle = "Mis Marcas";

// Función para registrar en bitácora
function registrarBitacora($pdo, $identificacion, $accion, $tabla, $descripcion) {
    try {
        $sql = "INSERT INTO bitacoras 
                (fecha_accion, identificacion_usuario, accion, tabla_afectada, 
                 descripcion_accion, ip_usuario, user_agent)
                VALUES (NOW(), ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $identificacion,
            $accion,
            $tabla,
            $descripcion,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log("Error en bitácora: " . $e->getMessage());
    }
}

// Parámetros de filtro y paginación
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';
$id_area = $_GET['id_area'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Construir consulta con filtros
$where = ["m.identificacion_funcionario = ?"];
$params = [$identificacion];

if (!empty($fecha_inicio)) {
    $where[] = "m.fecha_marca >= ?";
    $params[] = $fecha_inicio;
}

if (!empty($fecha_fin)) {
    $where[] = "m.fecha_marca <= ?";
    $params[] = $fecha_fin;
}

if (!empty($id_area)) {
    $where[] = "m.id_area = ?";
    $params[] = $id_area;
}

$where_clause = implode(" AND ", $where);

// Obtener total de registros
$count_sql = "SELECT COUNT(*) as total FROM marcas m WHERE $where_clause";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

registrarBitacora($pdo, $identificacion, 'SELECT', 'marcas', 
    "Consulta de total de marcas - Total: $total_records registros" . 
    " - Filtros: Fecha Inicio=" . ($fecha_inicio ?: 'Sin límite') . 
    ", Fecha Fin=" . ($fecha_fin ?: 'Sin límite') . 
    ", Área=" . ($id_area ?: 'Todas'));

// Obtener marcas con paginación 
$per_page = (int)$per_page;  
$offset = (int)$offset;      

$sql = "SELECT m.id_marca, m.fecha_marca, m.hora_marca, m.timestamp_servidor, 
               m.tipo_marca, m.detalle, a.nombre_area, m.pais, m.ciudad
        FROM marcas m
        LEFT JOIN areas a ON m.id_area = a.id_area
        WHERE $where_clause
        ORDER BY m.timestamp_servidor DESC
        LIMIT $per_page OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$marcas = $stmt->fetchAll();

registrarBitacora($pdo, $identificacion, 'SELECT', 'marcas', 
    "Consulta de marcas paginadas - Página: $page - Cantidad: " . count($marcas) . " registros");

// Obtener áreas para el filtro
$areas_sql = "SELECT DISTINCT a.id_area, a.nombre_area 
              FROM marcas m 
              INNER JOIN areas a ON m.id_area = a.id_area 
              WHERE m.identificacion_funcionario = ?
              ORDER BY a.nombre_area";
$stmt_areas = $pdo->prepare($areas_sql);
$stmt_areas->execute([$identificacion]);
$areas = $stmt_areas->fetchAll();

registrarBitacora($pdo, $identificacion, 'SELECT', 'areas', 
    "Consulta de áreas para filtro - Cantidad: " . count($areas));

require_once 'templates/header.php'; 


?>



<div class="page-container">
    <div class="page-header">
        <div class="header-content">
            <div class="header-icon">
                <i class="fas fa-fingerprint"></i>
            </div>
            <div>
                <h1 class="page-title">Mis Marcas</h1>
                <p class="page-subtitle">Consulta y exporta tu historial de marcas de asistencia</p>
            </div>
        </div>
    </div>

        <!-- Filtros -->
        <div class="card card-modern mb-4">
            <div class="card-header-modern">
                <div class="card-header-content">
                    <i class="fas fa-filter"></i>
                    <h5>Filtros de Búsqueda</h5>
                </div>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="filter-form">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="form-group-modern">
                                <label for="fecha_inicio" class="form-label-modern">
                                    <i class="fas fa-calendar-day"></i>
                                    Fecha Inicio
                                </label>
                                <input type="date" class="form-control-modern" id="fecha_inicio" 
                                       name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group-modern">
                                <label for="fecha_fin" class="form-label-modern">
                                    <i class="fas fa-calendar-check"></i>
                                    Fecha Fin
                                </label>
                                <input type="date" class="form-control-modern" id="fecha_fin" 
                                       name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group-modern">
                                <label for="id_area" class="form-label-modern">
                                    <i class="fas fa-building"></i>
                                    Área
                                </label>
                                <select class="form-select-modern" id="id_area" name="id_area">
                                    <option value="">Todas las áreas</option>
                                    <?php foreach ($areas as $area): ?>
                                        <option value="<?= $area['id_area'] ?>" 
                                                <?= $id_area == $area['id_area'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($area['nombre_area']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-primary-modern flex-fill">
                                <i class="fas fa-search"></i> Buscar
                            </button>
                            <a href="mis-marcas.php" class="btn btn-secondary-modern">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabla de Marcas -->
        <div class="card card-modern">
            <div class="card-header-modern">
                <div class="card-header-content">
                    <i class="fas fa-list"></i>
                    <h5>Historial de Marcas</h5>
                </div>
                <div class="card-header-actions">
                    <span class="badge badge-info-modern">
                        <?= $total_records ?> registros
                    </span>
                    <button onclick="exportarCSV()" class="btn btn-success-modern">
                        <i class="fas fa-file-csv"></i> Exportar CSV
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($marcas) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-calendar-alt"></i> Fecha y Hora</th>
                                    <th><i class="fas fa-sign-in-alt"></i> Tipo</th>
                                    <th><i class="fas fa-building"></i> Área</th>
                                    <th><i class="fas fa-map-marker-alt"></i> Ubicación</th>
                                    <th><i class="fas fa-info-circle"></i> Detalle</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($marcas as $marca): ?>
                                    <tr>
                                        <td>
                                            <div class="datetime-info">
                                                <div class="date-line">
                                                    <i class="fas fa-calendar"></i>
                                                    <strong><?= date('d/m/Y', strtotime($marca['fecha_marca'])) ?></strong>
                                                </div>
                                                <div class="time-line">
                                                    <i class="fas fa-clock"></i>
                                                    <span><?= date('H:i:s', strtotime($marca['hora_marca'])) ?></span>
                                                </div>
                                                <div class="server-time">
                                                    <i class="fas fa-server"></i>
                                                    <small>Servidor: <?= date('H:i:s', strtotime($marca['timestamp_servidor'])) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($marca['tipo_marca'] == 'ENTRADA'): ?>
                                                <span class="badge badge-success-modern badge-tipo">
                                                    <i class="fas fa-arrow-right"></i> ENTRADA
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-danger-modern badge-tipo">
                                                    <i class="fas fa-arrow-left"></i> SALIDA
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="area-info">
                                                <i class="fas fa-building"></i>
                                                <?= htmlspecialchars($marca['nombre_area'] ?? 'N/A') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="location-info">
                                                <div class="location-line">
                                                    <i class="fas fa-city"></i>
                                                    <span><?= htmlspecialchars($marca['ciudad'] ?? 'N/A') ?></span>
                                                </div>
                                                <div class="location-line">
                                                    <i class="fas fa-globe"></i>
                                                    <span><?= htmlspecialchars($marca['pais'] ?? 'N/A') ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="detalle-badge">
                                                <?= htmlspecialchars($marca['detalle'] ?? '-') ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Paginación -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Paginación" class="mt-4">
                            <ul class="pagination-modern">
                                <?php
                                $query_string = http_build_query([
                                    'fecha_inicio' => $fecha_inicio,
                                    'fecha_fin' => $fecha_fin,
                                    'id_area' => $id_area
                                ]);
                                ?>
                                
                                <li class="page-item-modern <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link-modern" href="?<?= $query_string ?>&page=<?= $page - 1 ?>">
                                        <i class="fas fa-chevron-left"></i>
                                        <span>Anterior</span>
                                    </a>
                                </li>

                                <div class="page-numbers">
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                            <a class="page-link-modern <?= $i == $page ? 'active' : '' ?>" 
                                               href="?<?= $query_string ?>&page=<?= $i ?>">
                                                <?= $i ?>
                                            </a>
                                        <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                            <span class="page-dots">...</span>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </div>

                                <li class="page-item-modern <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link-modern" href="?<?= $query_string ?>&page=<?= $page + 1 ?>">
                                        <span>Siguiente</span>
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <h3>No se encontraron marcas</h3>
                        <p>No hay marcas registradas con los filtros seleccionados</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
:root {
    --primary: #111f9d;
    --primary-dark: #0d1770;
    --primary-light: #e8eaf7;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --info: #3b82f6;
    --secondary: #6b7280;
    --border-radius: 12px;
    --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
    --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.12);
}

.main-content {
    min-height: calc(100vh - 80px);
    background: #f8f9fa;
}

.page-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 2rem;
}

.page-header {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    padding: 2rem;
    border-radius: var(--border-radius);
    margin-bottom: 2rem;
    box-shadow: var(--shadow-md);
}

.header-content {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

.header-icon {
    width: 60px;
    height: 60px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    color: white;
}

.page-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: white;
    margin: 0;
}

.page-subtitle {
    color: rgba(255, 255, 255, 0.9);
    margin: 0.25rem 0 0;
    font-size: 0.95rem;
}

.card-modern {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-md);
    overflow: hidden;
    background: white;
}

.card-header-modern {
    background: linear-gradient(to right, #f8f9fa 0%, #ffffff 100%);
    padding: 1.5rem;
    border-bottom: 2px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.card-header-content {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.card-header-content i {
    color: var(--primary);
    font-size: 1.25rem;
}

.card-header-content h5 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: #1f2937;
}

.card-header-actions {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.card-body {
    padding: 2rem;
}

.form-group-modern {
    margin-bottom: 0;
}

.form-label-modern {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.75rem;
    font-size: 0.95rem;
}

.form-label-modern i {
    color: var(--primary);
    font-size: 0.9rem;
}

.form-select-modern,
.form-control-modern {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: white;
}

.form-select-modern:focus,
.form-control-modern:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px var(--primary-light);
}

.filter-form {
    background: #f9fafb;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.btn-primary-modern {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    box-shadow: var(--shadow-sm);
    cursor: pointer;
}

.btn-primary-modern:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.btn-secondary-modern {
    background: white;
    color: #6b7280;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    border: 2px solid #e5e7eb;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    cursor: pointer;
}

.btn-secondary-modern:hover {
    background: #f9fafb;
    border-color: #d1d5db;
}

.btn-success-modern {
    background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
    color: white;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    box-shadow: var(--shadow-sm);
    cursor: pointer;
}

.btn-success-modern:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.badge-info-modern {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
}

.table-modern {
    width: 100%;
    margin: 0;
}

.table-modern thead {
    background: linear-gradient(to right, #f8f9fa 0%, #f3f4f6 100%);
}

.table-modern thead th {
    padding: 1rem;
    font-weight: 600;
    color: var(--primary);
    font-size: 0.9rem;
    border: none;
    white-space: nowrap;
}

.table-modern thead th i {
    margin-right: 0.5rem;
    opacity: 0.7;
}

.table-modern tbody td {
    padding: 1rem;
    vertical-align: middle;
    border-bottom: 1px solid #f3f4f6;
}

.table-modern tbody tr {
    transition: all 0.2s ease;
}

.table-modern tbody tr:hover {
    background: #f9fafb;
}

.datetime-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.date-line, .time-line, .server-time {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.date-line i {
    color: var(--primary);
}

.time-line i {
    color: var(--info);
}

.server-time i {
    color: var(--secondary);
}

.date-line strong {
    color: #1f2937;
    font-size: 0.95rem;
}

.time-line span {
    color: #4b5563;
    font-weight: 500;
}

.server-time small {
    color: #6b7280;
    font-size: 0.85rem;
}

.badge-tipo {
    padding: 0.6rem 1rem;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.badge-success-modern {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
    border-radius: 6px;
}

.badge-danger-modern {
    background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
    color: #991b1b;
    border-radius: 6px;
}

.area-info {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #4b5563;
    font-weight: 500;
}

.area-info i {
    color: var(--primary);
}

.location-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.location-line {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    color: #6b7280;
}

.location-line i {
    color: var(--info);
    font-size: 0.85rem;
}

.detalle-badge {
    background: #f3f4f6;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    color: #4b5563;
    font-size: 0.875rem;
}

.pagination-modern {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    padding: 1.5rem 0;
    flex-wrap: wrap;
}

.page-item-modern {
    list-style: none;
}

.page-link-modern {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    color: var(--primary);
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
}

.page-link-modern:hover {
    background: var(--primary);
    color: white;
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.page-link-modern.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.page-item-modern.disabled .page-link-modern {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

.page-numbers {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.page-dots {
    color: #6b7280;
    font-weight: 600;
}

.empty-state {
    text-align: center;
    padding: 3rem 1rem;
}

.empty-state-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, var(--primary-light) 0%, #e0e7ff 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    font-size: 2.5rem;
    color: var(--primary);
}

.empty-state h3 {
    color: #1f2937;
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: #6b7280;
    font-size: 0.95rem;
}

.content-wrapper {
    max-width: 1600px;
    margin: 0 auto;
    padding: 2rem;
    padding-bottom: 2rem 4rem;
}

@media (max-width: 768px) {
    .content-wrapper {
        padding: 1rem;
        padding-bottom: 3rem;
    }
    
    .page-header {
        padding: 1.5rem;
    }
    
    .header-content {
        flex-direction: column;
        text-align: center;
    }
    
    .page-title {
        font-size: 1.5rem;
    }
    
    .card-body {
        padding: 1.5rem;
    }
    
    .card-header-modern {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .table-modern {
        font-size: 0.875rem;
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function exportarCSV() {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('export', 'csv');
    window.location.href = 'exportar-marcas.php?' + urlParams.toString();
}

// Validación de fechas
document.getElementById('fecha_inicio')?.addEventListener('change', function() {
    const fechaFin = document.getElementById('fecha_fin');
    if (fechaFin) {
        fechaFin.min = this.value;
    }
});
</script>



<?php
require_once 'templates/footer.php';
?>