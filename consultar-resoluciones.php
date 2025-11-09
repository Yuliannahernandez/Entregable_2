<?php
// consultar-resoluciones.php - HU#20 JEF2: Consulta de resoluciones emitidas
require_once 'includes/auth.php';
requireRole([2]); // Solo jefes de área

$identificacion = $_SESSION['identificacion'];
$pageTitle = "Consultar Resoluciones";

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

// Filtros
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';
$filtro_fecha_desde = $_GET['fecha_desde'] ?? '';
$filtro_fecha_hasta = $_GET['fecha_hasta'] ?? '';
$filtro_funcionario = $_GET['funcionario'] ?? '';

// Paginación
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Construir WHERE para todas las consultas
$where_conditions = [];
$params = [];
$params_count = [];

if (!empty($filtro_funcionario)) {
    $where_conditions[] = "CONCAT(f.nombre, ' ', f.apellido) LIKE ?";
    $params[] = "%$filtro_funcionario%";
    $params_count[] = "%$filtro_funcionario%";
}

// Obtener PERMISOS con resolución
$permisos = [];
if (empty($filtro_tipo) || $filtro_tipo === 'permiso') {
    $where_permisos = array_merge(["p.estado_permiso IN ('APROBADO', 'RECHAZADO')"], $where_conditions);
    $params_permisos = $params;
    $params_count_permisos = $params_count;
    
    if (!empty($filtro_estado)) {
        $where_permisos[] = "p.estado_permiso = ?";
        $params_permisos[] = $filtro_estado;
        $params_count_permisos[] = $filtro_estado;
    }
    if (!empty($filtro_fecha_desde)) {
        $where_permisos[] = "p.fecha_respuesta >= ?";
        $params_permisos[] = $filtro_fecha_desde;
        $params_count_permisos[] = $filtro_fecha_desde;
    }
    if (!empty($filtro_fecha_hasta)) {
        $where_permisos[] = "p.fecha_respuesta <= ?";
        $params_permisos[] = $filtro_fecha_hasta;
        $params_count_permisos[] = $filtro_fecha_hasta;
    }
    
    $where_clause_permisos = implode(" AND ", $where_permisos);
    
    $sql_permisos = "SELECT 'PERMISO' as tipo, p.id_permiso as id,
                            CONCAT(f.nombre, ' ', f.apellido) as solicitante,
                            p.fecha_inicio, p.fecha_fin, p.fecha_respuesta as fecha_resolucion,
                            p.estado_permiso as decision, p.comentarios_aprobacion as observacion,
                            CONCAT(aprobador.nombre, ' ', aprobador.apellido) as aprobador
                     FROM permisos p
                     INNER JOIN funcionarios f ON p.identificacion_funcionario = f.identificacion
                     LEFT JOIN funcionarios aprobador ON p.identificacion_aprobador = aprobador.identificacion
                     WHERE $where_clause_permisos";
    
    $stmt = $pdo->prepare($sql_permisos);
    $stmt->execute($params_permisos);
    $permisos = $stmt->fetchAll();
}

// Obtener VACACIONES con resolución
$vacaciones = [];
if (empty($filtro_tipo) || $filtro_tipo === 'vacacion') {
    $where_vacaciones = array_merge(["v.estado_vacacion IN ('APROBADO', 'RECHAZADO')"], $where_conditions);
    $params_vacaciones = $params;
    $params_count_vacaciones = $params_count;
    
    if (!empty($filtro_estado)) {
        $where_vacaciones[] = "v.estado_vacacion = ?";
        $params_vacaciones[] = $filtro_estado;
        $params_count_vacaciones[] = $filtro_estado;
    }
    if (!empty($filtro_fecha_desde)) {
        $where_vacaciones[] = "v.fecha_respuesta >= ?";
        $params_vacaciones[] = $filtro_fecha_desde;
        $params_count_vacaciones[] = $filtro_fecha_desde;
    }
    if (!empty($filtro_fecha_hasta)) {
        $where_vacaciones[] = "v.fecha_respuesta <= ?";
        $params_vacaciones[] = $filtro_fecha_hasta;
        $params_count_vacaciones[] = $filtro_fecha_hasta;
    }
    
    $where_clause_vacaciones = implode(" AND ", $where_vacaciones);
    
    $sql_vacaciones = "SELECT 'VACACION' as tipo, v.id_vacacion as id,
                              CONCAT(f.nombre, ' ', f.apellido) as solicitante,
                              v.fecha_inicio, v.fecha_fin, v.fecha_respuesta as fecha_resolucion,
                              v.estado_vacacion as decision, v.observaciones_revisor as observacion,
                              CONCAT(revisor.nombre, ' ', revisor.apellido) as aprobador
                       FROM vacaciones v
                       INNER JOIN funcionarios f ON v.identificacion_funcionario = f.identificacion
                       LEFT JOIN funcionarios revisor ON v.identificacion_revisor = revisor.identificacion
                       WHERE $where_clause_vacaciones";
    
    $stmt = $pdo->prepare($sql_vacaciones);
    $stmt->execute($params_vacaciones);
    $vacaciones = $stmt->fetchAll();
}

// Obtener JUSTIFICACIONES con resolución
$justificaciones = [];
if (empty($filtro_tipo) || $filtro_tipo === 'justificacion') {
    $where_justif = array_merge(["j.estado_justificacion IN ('APROBADA', 'RECHAZADA')"], $where_conditions);
    $params_justif = $params;
    $params_count_justif = $params_count;
    
    if (!empty($filtro_estado)) {
        $where_justif[] = "j.estado_justificacion = ?";
        $params_justif[] = $filtro_estado === 'APROBADO' ? 'APROBADA' : 'RECHAZADA';
        $params_count_justif[] = $filtro_estado === 'APROBADO' ? 'APROBADA' : 'RECHAZADA';
    }
    if (!empty($filtro_fecha_desde)) {
        $where_justif[] = "j.fecha_respuesta >= ?";
        $params_justif[] = $filtro_fecha_desde;
        $params_count_justif[] = $filtro_fecha_desde;
    }
    if (!empty($filtro_fecha_hasta)) {
        $where_justif[] = "j.fecha_respuesta <= ?";
        $params_justif[] = $filtro_fecha_hasta;
        $params_count_justif[] = $filtro_fecha_hasta;
    }
    
    $where_clause_justif = implode(" AND ", $where_justif);
    
    $sql_justif = "SELECT 'JUSTIFICACION' as tipo, j.id_justificacion as id,
                          CONCAT(f.nombre, ' ', f.apellido) as solicitante,
                          i.fecha_inconsistencia as fecha_inicio, 
                          i.fecha_inconsistencia as fecha_fin,
                          j.fecha_respuesta as fecha_resolucion,
                          j.estado_justificacion as decision, 
                          j.observaciones_revisor as observacion,
                          CONCAT(revisor.nombre, ' ', revisor.apellido) as aprobador
                   FROM justificaciones j
                   INNER JOIN funcionarios f ON j.identificacion_funcionario = f.identificacion
                   INNER JOIN inconsistencias i ON j.id_inconsistencia = i.id_inconsistencia
                   LEFT JOIN funcionarios revisor ON j.identificacion_revisor = revisor.identificacion
                   WHERE $where_clause_justif";
    
    $stmt = $pdo->prepare($sql_justif);
    $stmt->execute($params_justif);
    $justificaciones = $stmt->fetchAll();
}

// Combinar todos los resultados
$todas_resoluciones = array_merge($permisos, $vacaciones, $justificaciones);

// Ordenar por fecha de resolución descendente
usort($todas_resoluciones, function($a, $b) {
    return strtotime($b['fecha_resolucion']) - strtotime($a['fecha_resolucion']);
});

// Total de registros
$total_registros = count($todas_resoluciones);
$total_paginas = ceil($total_registros / $registros_por_pagina);

// Paginar resultados
$resoluciones_paginadas = array_slice($todas_resoluciones, $offset, $registros_por_pagina);

// Registrar en bitácora
registrarBitacora($pdo, $identificacion, 'SELECT', 'resoluciones', 
    "Consulta de resoluciones - Total: $total_registros - Filtros: " . json_encode([
        'tipo' => $filtro_tipo ?: 'Todos',
        'estado' => $filtro_estado ?: 'Todos',
        'fecha_desde' => $filtro_fecha_desde ?: 'N/A',
        'fecha_hasta' => $filtro_fecha_hasta ?: 'N/A',
        'funcionario' => $filtro_funcionario ?: 'Todos'
    ]));

// Exportar CSV
if (isset($_GET['exportar']) && $_GET['exportar'] === 'csv') {
    $filename = "resoluciones_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    
    // Encabezados
    fputcsv($output, [
        'ID',
        'Tipo',
        'Solicitante',
        'Fecha Inicio',
        'Fecha Fin',
        'Decisión',
        'Fecha Resolución',
        'Aprobador',
        'Observación'
    ], ';');
    
    // Datos
    foreach ($todas_resoluciones as $row) {
        fputcsv($output, [
            $row['id'],
            $row['tipo'],
            $row['solicitante'],
            date('d/m/Y', strtotime($row['fecha_inicio'])),
            date('d/m/Y', strtotime($row['fecha_fin'])),
            $row['decision'],
            date('d/m/Y H:i', strtotime($row['fecha_resolucion'])),
            $row['aprobador'] ?? 'N/A',
            $row['observacion'] ?? 'Sin observaciones'
        ], ';');
    }
    
    fclose($output);
    
    registrarBitacora($pdo, $identificacion, 'EXPORTAR', 'resoluciones', 
        "Exportación CSV de resoluciones - Total: " . count($todas_resoluciones) . " registros");
    
    exit;
}

require_once 'templates/header.php';
?>

<style>
:root {
    --primary: #111f9d;
    --primary-dark: #0d1770;
    --primary-light: #e8eaf7;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --info: #3b82f6;
    --border-radius: 12px;
    --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
    --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.page-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 2rem;
    width: 100%;
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
    justify-content: space-between;
}

.header-left {
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

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 1rem;
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.stat-icon.success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: var(--success);
}

.stat-icon.danger {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: var(--danger);
}

.stat-icon.primary {
    background: linear-gradient(135deg, var(--primary-light) 0%, #dbeafe 100%);
    color: var(--primary);
}

.stat-content h3 {
    font-size: 2rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.stat-content p {
    color: #6b7280;
    margin: 0;
    font-size: 0.9rem;
}

.filter-form {
    background: white;
    padding: 1.5rem;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    margin-bottom: 2rem;
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

.form-control-modern, .form-select-modern {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
}

.form-control-modern:focus, .form-select-modern:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px var(--primary-light);
}

.table-card {
    background: white;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-md);
    overflow: hidden;
}

.table-header {
    background: linear-gradient(to right, #f8f9fa 0%, #ffffff 100%);
    padding: 1.5rem;
    border-bottom: 2px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-modern {
    width: 100%;
    border-collapse: collapse;
}

.table-modern thead {
    background: linear-gradient(to right, #f8f9fa 0%, #f3f4f6 100%);
}

.table-modern thead th {
    padding: 1rem;
    font-weight: 600;
    color: var(--primary);
    font-size: 0.9rem;
    text-align: left;
    border: none;
}

.table-modern tbody td {
    padding: 1rem;
    vertical-align: middle;
    border-bottom: 1px solid #f3f4f6;
}

.table-modern tbody tr:hover {
    background: #f9fafb;
}

.badge {
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.875rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.badge-permiso {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
}

.badge-vacacion {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
}

.badge-justificacion {
    background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
    color: #4338ca;
}

.badge-success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
}

.badge-danger {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
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
    cursor: pointer;
    text-decoration: none;
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
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-info-modern {
    background: linear-gradient(135deg, var(--info) 0%, #2563eb 100%);
    color: white;
    padding: 0.5rem 1rem;
    border-radius: 6px;
    border: none;
    font-weight: 600;
    cursor: pointer;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
    padding: 1.5rem;
}

.pagination a, .pagination span {
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    text-decoration: none;
    color: #6b7280;
    border: 2px solid #e5e7eb;
    transition: all 0.3s ease;
}

.pagination a:hover {
    background: var(--primary-light);
    color: var(--primary);
    border-color: var(--primary);
}

.pagination .active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.empty-state {
    text-align: center;
    padding: 3rem 1rem;
}

.empty-state-icon {
    width: 80px;
    height: 80px;
    background: var(--primary-light);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    font-size: 2.5rem;
    color: var(--primary);
}

.modal-modern .modal-content {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.modal-header-modern {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    padding: 1.5rem;
    border: none;
}

.detail-section {
    margin-bottom: 1.5rem;
}

.detail-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.75rem;
}

.detail-content {
    padding: 1rem;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    color: #4b5563;
}
</style>

<div class="page-container">
    <!-- Header -->
    <div class="page-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-icon">
                    <i class="fas fa-search"></i>
                </div>
                <div>
                    <h1 class="page-title">Consultar Resoluciones</h1>
                    <p class="page-subtitle">Auditoría de decisiones tomadas sobre solicitudes</p>
                </div>
            </div>
            <div>
                <a href="?exportar=csv&<?= http_build_query($_GET) ?>" class="btn-primary-modern">
                    <i class="fas fa-file-download"></i>
                    Exportar CSV
                </a>
            </div>
        </div>
    </div>

    <!-- Estadísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon primary">
                <i class="fas fa-list-check"></i>
            </div>
            <div class="stat-content">
                <h3><?= $total_registros ?></h3>
                <p>Total Resoluciones</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-content">
                <h3><?= count(array_filter($todas_resoluciones, fn($r) => str_contains($r['decision'], 'APROBAD'))) ?></h3>
                <p>Aprobadas</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon danger">
                <i class="fas fa-times-circle"></i>
            </div>
            <div class="stat-content">
                <h3><?= count(array_filter($todas_resoluciones, fn($r) => str_contains($r['decision'], 'RECHAZAD'))) ?></h3>
                <p>Rechazadas</p>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <form method="GET" class="filter-form">
        <div class="row g-3">
            <div class="col-md-2">
                <label class="form-label-modern">
                    <i class="fas fa-layer-group"></i> Tipo
                </label>
                <select class="form-select-modern" name="tipo">
                    <option value="">Todos</option>
                    <option value="permiso" <?= $filtro_tipo === 'permiso' ? 'selected' : '' ?>>Permiso</option>
                    <option value="vacacion" <?= $filtro_tipo === 'vacacion' ? 'selected' : '' ?>>Vacación</option>
                    <option value="justificacion" <?= $filtro_tipo === 'justificacion' ? 'selected' : '' ?>>Justificación</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label-modern">
                    <i class="fas fa-circle"></i> Estado
                </label>
                <select class="form-select-modern" name="estado">
                    <option value="">Todos</option>
                    <option value="APROBADO" <?= $filtro_estado === 'APROBADO' ? 'selected' : '' ?>>Aprobado</option>
                    <option value="RECHAZADO" <?= $filtro_estado === 'RECHAZADO' ? 'selected' : '' ?>>Rechazado</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label-modern">
                    <i class="fas fa-calendar"></i> Desde
                </label>
                <input type="date" class="form-control-modern" name="fecha_desde" 
                       value="<?= htmlspecialchars($filtro_fecha_desde) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label-modern">
                    <i class="fas fa-calendar"></i> Hasta
                </label>
                <input type="date" class="form-control-modern" name="fecha_hasta" 
                       value="<?= htmlspecialchars($filtro_fecha_hasta) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label-modern">
                    <i class="fas fa-user"></i> Funcionario
                </label>
                <input type="text" class="form-control-modern" name="funcionario" 
                       value="<?= htmlspecialchars($filtro_funcionario) ?>" 
                       placeholder="Nombre">
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary-modern flex-fill">
                    <i class="fas fa-search"></i> Buscar
                </button>
                <a href="consultar-resoluciones.php" class="btn btn-secondary-modern">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </div>
    </form>

    <!-- Tabla -->
    <div class="table-card">
        <div class="table-header">
            <h5 style="margin: 0; color: var(--primary); font-weight: 600;">
                <i class="fas fa-table"></i>
                Listado de Resoluciones
            </h5>
            <span style="color: #6b7280;">
                Mostrando <?= count($resoluciones_paginadas) ?> de <?= $total_registros ?> registros
            </span>
        </div>

        <?php if (count($resoluciones_paginadas) > 0): ?>
            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Tipo</th>
                            <th>Solicitante</th>
                            <th>Fechas</th>
                            <th>Decisión</th>
                            <th>Fecha Resolución</th>
                            <th>Observación</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resoluciones_paginadas as $r): ?>
                            <tr>
                                <td><strong>#<?= $r['id'] ?></strong></td>
                                <td>
                                    <?php
                                    $badge_tipo = [
                                        'PERMISO' => 'badge-permiso',
                                        'VACACION' => 'badge-vacacion',
                                        'JUSTIFICACION' => 'badge-justificacion'
                                    ];
                                    ?>
                                    <span class="badge <?= $badge_tipo[$r['tipo']] ?>">
                                        <?= $r['tipo'] ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($r['solicitante']) ?></td>
                                <td>
                                    <small>
                                        <?= date('d/m/Y', strtotime($r['fecha_inicio'])) ?> - 
                                        <?= date('d/m/Y', strtotime($r['fecha_fin'])) ?>
                                    </small>
                                </td>
                                <td>
                                    <?php
                                    $es_aprobado = str_contains($r['decision'], 'APROBAD');
                                    ?>
                                    <span class="badge <?= $es_aprobado ? 'badge-success' : 'badge-danger' ?>">
                                        <i class="fas <?= $es_aprobado ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                        <?= $r['decision'] ?>
                                    </span>
                                </td>
                                <td>
                                    <small>
                                        <?= date('d/m/Y H:i', strtotime($r['fecha_resolucion'])) ?>
                                        <?php if ($r['aprobador']): ?>
                                            <br>
                                            <span style="color: #6b7280;">
                                                <i class="fas fa-user"></i>
                                                <?= htmlspecialchars($r['aprobador']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <small style="color: #6b7280;">
                                        <?= strlen($r['observacion']) > 50 ? substr(htmlspecialchars($r['observacion']), 0, 50) . '...' : htmlspecialchars($r['observacion']) ?>
                                    </small>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info-modern" 
                                            onclick="verDetalle('<?= htmlspecialchars($r['tipo'], ENT_QUOTES) ?>', 
                                                              '<?= htmlspecialchars($r['solicitante'], ENT_QUOTES) ?>', 
                                                              '<?= date('d/m/Y', strtotime($r['fecha_inicio'])) ?>', 
                                                              '<?= date('d/m/Y', strtotime($r['fecha_fin'])) ?>', 
                                                              '<?= htmlspecialchars($r['decision'], ENT_QUOTES) ?>', 
                                                              '<?= date('d/m/Y H:i', strtotime($r['fecha_resolucion'])) ?>', 
                                                              '<?= htmlspecialchars($r['aprobador'] ?? 'N/A', ENT_QUOTES) ?>', 
                                                              '<?= htmlspecialchars($r['observacion'], ENT_QUOTES) ?>')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <?php if ($total_paginas > 1): ?>
                <div class="pagination">
                    <?php if ($pagina_actual > 1): ?>
                        <a href="?pagina=<?= $pagina_actual - 1 ?>&<?= http_build_query(array_diff_key($_GET, ['pagina' => ''])) ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $pagina_actual - 2); $i <= min($total_paginas, $pagina_actual + 2); $i++): ?>
                        <?php if ($i == $pagina_actual): ?>
                            <span class="active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?pagina=<?= $i ?>&<?= http_build_query(array_diff_key($_GET, ['pagina' => ''])) ?>">
                                <?= $i ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($pagina_actual < $total_paginas): ?>
                        <a href="?pagina=<?= $pagina_actual + 1 ?>&<?= http_build_query(array_diff_key($_GET, ['pagina' => ''])) ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-search"></i>
                </div>
                <h3>No se encontraron resoluciones</h3>
                <p>No hay resoluciones que coincidan con los criterios de búsqueda</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Detalle -->
<div class="modal fade" id="modalDetalle" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content modal-modern">
            <div class="modal-header-modern">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle"></i>
                    Detalle de la Resolución
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="detail-section">
                    <label class="detail-label">
                        <i class="fas fa-layer-group"></i>
                        Tipo de Solicitud
                    </label>
                    <div class="detail-content" id="modal-tipo"></div>
                </div>
                <div class="detail-section">
                    <label class="detail-label">
                        <i class="fas fa-user"></i>
                        Solicitante
                    </label>
                    <div class="detail-content" id="modal-solicitante"></div>
                </div>
                <div class="detail-section">
                    <label class="detail-label">
                        <i class="fas fa-calendar-week"></i>
                        Período
                    </label>
                    <div class="detail-content" id="modal-periodo"></div>
                </div>
                <div class="detail-section">
                    <label class="detail-label">
                        <i class="fas fa-circle"></i>
                        Decisión
                    </label>
                    <div class="detail-content" id="modal-decision"></div>
                </div>
                <div class="detail-section">
                    <label class="detail-label">
                        <i class="fas fa-clock"></i>
                        Fecha de Resolución
                    </label>
                    <div class="detail-content" id="modal-fecha-resolucion"></div>
                </div>
                <div class="detail-section">
                    <label class="detail-label">
                        <i class="fas fa-user-tie"></i>
                        Aprobador/Revisor
                    </label>
                    <div class="detail-content" id="modal-aprobador"></div>
                </div>
                <div class="detail-section">
                    <label class="detail-label">
                        <i class="fas fa-comment-dots"></i>
                        Observación
                    </label>
                    <div class="detail-content" id="modal-observacion"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary-modern" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i>
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function verDetalle(tipo, solicitante, fechaInicio, fechaFin, decision, fechaResolucion, aprobador, observacion) {
    document.getElementById('modal-tipo').textContent = tipo;
    document.getElementById('modal-solicitante').textContent = solicitante;
    document.getElementById('modal-periodo').textContent = fechaInicio + ' - ' + fechaFin;
    document.getElementById('modal-decision').textContent = decision;
    document.getElementById('modal-fecha-resolucion').textContent = fechaResolucion;
    document.getElementById('modal-aprobador').textContent = aprobador;
    document.getElementById('modal-observacion').textContent = observacion || 'Sin observaciones';
    
    const modal = new bootstrap.Modal(document.getElementById('modalDetalle'));
    modal.show();
}
</script>

<?php
require_once 'templates/footer.php';
?>