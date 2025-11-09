<?php
// pendientes.php - HU#20 JEF1: Bandeja de pendientes para aprobar/rechazar
require_once 'includes/auth.php';
requireRole([2]); // Solo jefes de área

$identificacion = $_SESSION['identificacion'];
$pageTitle = "Pendientes de Aprobación";

// Función para registrar en bitácora
function registrarBitacora($pdo, $identificacion, $accion, $tabla, $descripcion)
{
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

// Procesar aprobación/rechazo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $tipo = $_POST['tipo'] ?? '';
    $id = $_POST['id'] ?? 0;
    $accion = $_POST['accion']; // 'aprobar' o 'rechazar'
    $observacion = trim($_POST['observacion'] ?? '');

    $errors = [];

    // Validar observación
    if (strlen($observacion) < 10) {
        $errors[] = "La observación debe tener al menos 10 caracteres";
    }

    if (empty($errors)) {
        $pdo->beginTransaction();

        try {
            $estado_nuevo = ($accion === 'aprobar') ? 'APROBADO' : 'RECHAZADO';
            $estado_final = ($accion === 'aprobar') ? 'APROBADA' : 'RECHAZADA';

            // Obtener datos anteriores para bitácora
            if ($tipo === 'permiso') {
                $sql = "SELECT * FROM permisos WHERE id_permiso = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id]);
                $datos_antes = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($datos_antes && $datos_antes['estado_permiso'] === 'PENDIENTE') {
                    // Actualizar permiso
                    $sql = "UPDATE permisos 
                            SET estado_permiso = ?, 
                                identificacion_aprobador = ?, 
                                fecha_respuesta = NOW(), 
                                comentarios_aprobacion = ?
                            WHERE id_permiso = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$estado_nuevo, $identificacion, $observacion, $id]);

                    // Obtener datos después para bitácora
                    $sql = "SELECT * FROM permisos WHERE id_permiso = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$id]);
                    $datos_despues = $stmt->fetch(PDO::FETCH_ASSOC);

                    $bitacora_desc = json_encode([
                        'antes' => $datos_antes,
                        'despues' => $datos_despues,
                        'accion_realizada' => $accion
                    ]);

                    registrarBitacora($pdo, $identificacion, 'UPDATE', 'permisos', $bitacora_desc);

                    $success_message = "Permiso " . strtolower($estado_nuevo) . " exitosamente";
                }

            } elseif ($tipo === 'vacacion') {
                $sql = "SELECT * FROM vacaciones WHERE id_vacacion = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id]);
                $datos_antes = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($datos_antes && $datos_antes['estado_vacacion'] === 'PENDIENTE') {
                    // Actualizar vacación
                    $sql = "UPDATE vacaciones 
                            SET estado_vacacion = ?, 
                                identificacion_aprobador = ?, 
                                fecha_aprobacion = NOW(), 
                                observaciones_aprobacion = ?
                            WHERE id_vacacion = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$estado_nuevo, $identificacion, $observacion, $id]);

                    // Obtener datos después
                    $sql = "SELECT * FROM vacaciones WHERE id_vacacion = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$id]);
                    $datos_despues = $stmt->fetch(PDO::FETCH_ASSOC);

                    $bitacora_desc = json_encode([
                        'antes' => $datos_antes,
                        'despues' => $datos_despues,
                        'accion_realizada' => $accion
                    ]);

                    registrarBitacora($pdo, $identificacion, 'UPDATE', 'vacaciones', $bitacora_desc);

                    $success_message = "Vacación " . strtolower($estado_final) . " exitosamente";
                }

            } elseif ($tipo === 'justificacion') {
                $sql = "SELECT * FROM justificaciones WHERE id_justificacion = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id]);
                $datos_antes = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($datos_antes && $datos_antes['estado_justificacion'] === 'ENVIADA') {
                    // Actualizar justificación
                    $sql = "UPDATE justificaciones 
                SET estado_justificacion = ?, 
                    identificacion_revisor = ?, 
                    fecha_respuesta = NOW(), 
                    observaciones_revisor = ?
                WHERE id_justificacion = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$estado_final, $identificacion, $observacion, $id]);

                    // CORREGIDO: Actualizar inconsistencia asociada con el estado correcto
                    // Las inconsistencias usan estados diferentes: JUSTIFICADA o PENDIENTE
                    $estado_inconsistencia = ($accion === 'aprobar') ? 'JUSTIFICADA' : 'PENDIENTE';

                    $sql_inc = "UPDATE inconsistencias 
                    SET estado_inconsistencia = ? 
                    WHERE id_inconsistencia = ?";
                    $stmt_inc = $pdo->prepare($sql_inc);
                    $stmt_inc->execute([$estado_inconsistencia, $datos_antes['id_inconsistencia']]);
                    // Obtener datos después
                    $sql = "SELECT * FROM justificaciones WHERE id_justificacion = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$id]);
                    $datos_despues = $stmt->fetch(PDO::FETCH_ASSOC);

                    $bitacora_desc = json_encode([
                        'antes' => $datos_antes,
                        'despues' => $datos_despues,
                        'accion_realizada' => $accion
                    ]);

                    registrarBitacora($pdo, $identificacion, 'UPDATE', 'justificaciones', $bitacora_desc);

                    $success_message = "Justificación " . strtolower($estado_final) . " exitosamente";
                }
            }

            $pdo->commit();

        } catch (Exception $e) {
            $pdo->rollBack();
            registrarBitacora(
                $pdo,
                $identificacion,
                'ERROR',
                $tipo . 's',
                "Error al procesar: " . $e->getMessage()
            );
            $errors[] = "Error al procesar la solicitud: " . $e->getMessage();
        }
    }
}

// Filtros
$filtro_funcionario = $_GET['funcionario'] ?? '';
$filtro_fecha_desde = $_GET['fecha_desde'] ?? '';
$filtro_fecha_hasta = $_GET['fecha_hasta'] ?? '';
$filtro_estado = $_GET['estado'] ?? 'PENDIENTE';

// Construir condiciones de filtro
$where_conditions = ["1=1"];
$params = [];

if (!empty($filtro_funcionario)) {
    $where_conditions[] = "CONCAT(f.nombre, ' ', f.apellido) LIKE ?";
    $params[] = "%$filtro_funcionario%";
}

if (!empty($filtro_fecha_desde)) {
    $where_conditions[] = "fecha_solicitud >= ?";
    $params[] = $filtro_fecha_desde;
}

if (!empty($filtro_fecha_hasta)) {
    $where_conditions[] = "fecha_solicitud <= ?";
    $params[] = $filtro_fecha_hasta;
}

if (!empty($filtro_estado)) {
    $where_conditions[] = "estado = ?";
    $params[] = $filtro_estado;
}

$where_clause = implode(" AND ", $where_conditions);

// PERMISOS PENDIENTES
$permisos_sql = "SELECT p.id_permiso, p.fecha_solicitud, p.fecha_inicio, p.fecha_fin,
                        p.hora_inicio, p.hora_fin, p.horas_solicitadas, p.descripcion,
                        p.estado_permiso as estado,
                        CONCAT(f.nombre, ' ', f.apellido) as funcionario,
                        ma.nombre_motivo
                 FROM permisos p
                 INNER JOIN funcionarios f ON p.identificacion_funcionario = f.identificacion
                 INNER JOIN motivos_ausencia ma ON p.id_motivo = ma.id_motivo
                 WHERE " . str_replace(
        "estado",
        "p.estado_permiso",
        str_replace("fecha_solicitud", "p.fecha_solicitud", $where_clause)
    ) . "
                 ORDER BY p.fecha_solicitud ASC";
$stmt_permisos = $pdo->prepare($permisos_sql);
$stmt_permisos->execute($params);
$permisos = $stmt_permisos->fetchAll();

// VACACIONES PENDIENTES
$vacaciones_sql = "SELECT v.id_vacacion, v.fecha_solicitud, v.fecha_inicio, v.fecha_fin,
                          v.dias_solicitados, v.observaciones,
                          v.estado_vacacion as estado,
                          CONCAT(f.nombre, ' ', f.apellido) as funcionario
                   FROM vacaciones v
                   INNER JOIN funcionarios f ON v.identificacion_funcionario = f.identificacion
                   WHERE " . str_replace(
        "estado",
        "v.estado_vacacion",
        str_replace("fecha_solicitud", "v.fecha_solicitud", $where_clause)
    ) . "
                   ORDER BY v.fecha_solicitud ASC";
$stmt_vacaciones = $pdo->prepare($vacaciones_sql);
$stmt_vacaciones->execute($params);
$vacaciones = $stmt_vacaciones->fetchAll();

// JUSTIFICACIONES PENDIENTES (estado ENVIADA)
$params_justif = [];
$where_conditions_justif = ["1=1"];

// Aplicar filtros normales excepto el estado
if (!empty($filtro_funcionario)) {
    $where_conditions_justif[] = "CONCAT(f.nombre, ' ', f.apellido) LIKE ?";
    $params_justif[] = "%$filtro_funcionario%";
}

if (!empty($filtro_fecha_desde)) {
    $where_conditions_justif[] = "j.fecha_solicitud >= ?";
    $params_justif[] = $filtro_fecha_desde;
}

if (!empty($filtro_fecha_hasta)) {
    $where_conditions_justif[] = "j.fecha_solicitud <= ?";
    $params_justif[] = $filtro_fecha_hasta;
}

// Manejar el filtro de estado específico para justificaciones
if (!empty($filtro_estado)) {
    if ($filtro_estado === 'PENDIENTE') {
        // Para justificaciones, PENDIENTE se muestra como ENVIADA
        $where_conditions_justif[] = "j.estado_justificacion = ?";
        $params_justif[] = 'ENVIADA';
    } elseif (in_array($filtro_estado, ['APROBADA', 'RECHAZADA', 'ENVIADA'])) {
        $where_conditions_justif[] = "j.estado_justificacion = ?";
        $params_justif[] = $filtro_estado;
    }
} else {
    // Sin filtro, mostrar solo ENVIADA por defecto
    $where_conditions_justif[] = "j.estado_justificacion = ?";
    $params_justif[] = 'ENVIADA';
}

$where_clause_justif = implode(" AND ", $where_conditions_justif);

$justificaciones_sql = "SELECT j.id_justificacion, j.fecha_solicitud, j.descripcion,
                               j.adjunto_path, j.estado_justificacion as estado,
                               CONCAT(f.nombre, ' ', f.apellido) as funcionario,
                               i.fecha_inconsistencia, ti.nombre_tipo,
                               ma.nombre_motivo
                        FROM justificaciones j
                        INNER JOIN funcionarios f ON j.identificacion_funcionario = f.identificacion
                        INNER JOIN inconsistencias i ON j.id_inconsistencia = i.id_inconsistencia
                        INNER JOIN tipos_inconsistencia ti ON i.id_tipo_inconsistencia = ti.id_tipo_inconsistencia
                        INNER JOIN motivos_ausencia ma ON j.id_motivo = ma.id_motivo
                        WHERE " . $where_clause_justif . "
                        ORDER BY j.fecha_solicitud ASC";

$stmt_justificaciones = $pdo->prepare($justificaciones_sql);
$stmt_justificaciones->execute($params_justif);
$justificaciones = $stmt_justificaciones->fetchAll();

registrarBitacora(
    $pdo,
    $identificacion,
    'SELECT',
    'pendientes',
    "Consulta de pendientes - Permisos: " . count($permisos) .
    ", Vacaciones: " . count($vacaciones) .
    ", Justificaciones: " . count($justificaciones)
);

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

    /* Tabs de navegación */
    .tabs-container {
        background: white;
        border-radius: var(--border-radius) var(--border-radius) 0 0;
        box-shadow: var(--shadow-sm);
        margin-bottom: 0;
    }

    .nav-tabs {
        display: flex;
        border-bottom: 2px solid #e5e7eb;
        padding: 0 1.5rem;
        gap: 0.5rem;
    }

    .nav-tab {
        padding: 1rem 1.5rem;
        background: none;
        border: none;
        color: #6b7280;
        font-weight: 600;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .nav-tab:hover {
        color: var(--primary);
        background: var(--primary-light);
    }

    .nav-tab.active {
        color: var(--primary);
        border-bottom-color: var(--primary);
        background: var(--primary-light);
    }

    .tab-badge {
        background: var(--danger);
        color: white;
        padding: 0.2rem 0.5rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 700;
    }

    .nav-tab.active .tab-badge {
        background: var(--primary);
    }

    /* Contenido de tabs */
    .tab-content {
        display: none;
        background: white;
        border-radius: 0 0 var(--border-radius) var(--border-radius);
        box-shadow: var(--shadow-md);
        padding: 2rem;
    }

    .tab-content.active {
        display: block;
    }

    /* Filtros */
    .filter-form {
        padding: 1.5rem;
        background: #f9fafb;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
        margin-bottom: 1.5rem;
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

    .form-control-modern,
    .form-select-modern {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 0.95rem;
        transition: all 0.3s ease;
    }

    .form-control-modern:focus,
    .form-select-modern:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 4px var(--primary-light);
    }

    /* Tabla */
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

    /* Botones */
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
    }

    .btn-primary-modern:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .btn-success-modern {
        background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 6px;
        border: none;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .btn-danger-modern {
        background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 6px;
        border: none;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .btn-info-modern {
        background: linear-gradient(135deg, var(--info) 0%, #2563eb 100%);
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 6px;
        border: none;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
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
    }

    .action-buttons {
        display: flex;
        gap: 0.5rem;
    }

    /* Modal */
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

    .modal-body {
        padding: 2rem;
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

    .alert {
        padding: 1.25rem;
        border-radius: var(--border-radius);
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .alert-success {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        color: #065f46;
        border-left: 4px solid var(--success);
    }

    .alert-danger {
        background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        color: #991b1b;
        border-left: 4px solid var(--danger);
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

    .badge {
        padding: 0.5rem 0.75rem;
        border-radius: 6px;
        font-weight: 600;
        font-size: 0.875rem;
    }

    .badge-warning {
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        color: #92400e;
    }
</style>

<div class="page-container">
    <!-- Header -->
    <div class="page-header">
        <div class="header-content">
            <div class="header-icon">
                <i class="fas fa-tasks"></i>
            </div>
            <div>
                <h1 class="page-title">Pendientes de Aprobación</h1>
                <p class="page-subtitle">Gestiona las solicitudes de permisos, vacaciones y justificaciones</p>
            </div>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle fa-2x"></i>
            <strong><?= $success_message ?></strong>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle fa-2x"></i>
            <div>
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filtros -->
    <form method="GET" class="filter-form mb-4">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label-modern">
                    <i class="fas fa-user"></i> Funcionario
                </label>
                <input type="text" class="form-control-modern" name="funcionario"
                    value="<?= htmlspecialchars($filtro_funcionario) ?>" placeholder="Nombre del funcionario">
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
                    <i class="fas fa-filter"></i> Estado
                </label>
                <select class="form-select-modern" name="estado">
                    <option value="">Todos</option>
                    <option value="PENDIENTE" <?= $filtro_estado === 'PENDIENTE' ? 'selected' : '' ?>>Pendiente</option>
                    <option value="APROBADO" <?= $filtro_estado === 'APROBADO' ? 'selected' : '' ?>>Aprobado</option>
                    <option value="RECHAZADO" <?= $filtro_estado === 'RECHAZADO' ? 'selected' : '' ?>>Rechazado</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary-modern flex-fill">
                    <i class="fas fa-search"></i> Filtrar
                </button>
                <a href="pendientes.php" class="btn btn-secondary-modern">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </div>
    </form>

    <!-- Tabs -->
    <div class="tabs-container">
        <div class="nav-tabs">
            <button class="nav-tab active" data-tab="permisos">
                <i class="fas fa-file-signature"></i>
                Permisos
                <?php if (count($permisos) > 0): ?>
                    <span class="tab-badge"><?= count($permisos) ?></span>
                <?php endif; ?>
            </button>
            <button class="nav-tab" data-tab="vacaciones">
                <i class="fas fa-umbrella-beach"></i>
                Vacaciones
                <?php if (count($vacaciones) > 0): ?>
                    <span class="tab-badge"><?= count($vacaciones) ?></span>
                <?php endif; ?>
            </button>
            <button class="nav-tab" data-tab="justificaciones">
                <i class="fas fa-comment-medical"></i>
                Justificaciones
                <?php if (count($justificaciones) > 0): ?>
                    <span class="tab-badge"><?= count($justificaciones) ?></span>
                <?php endif; ?>
            </button>
        </div>
    </div>

    <!-- Tab Permisos -->
    <div class="tab-content active" id="permisos">
        <?php if (count($permisos) > 0): ?>
            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Funcionario</th>
                            <th>Fecha Solicitud</th>
                            <th>Período</th>
                            <th>Horas</th>
                            <th>Motivo</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($permisos as $p): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($p['funcionario']) ?></strong></td>
                                <td><?= date('d/m/Y', strtotime($p['fecha_solicitud'])) ?></td>
                                <td>
                                    <small>
                                        <?= date('d/m/Y', strtotime($p['fecha_inicio'])) ?>
                                        <?= substr($p['hora_inicio'], 0, 5) ?> -
                                        <?= date('d/m/Y', strtotime($p['fecha_fin'])) ?>
                                        <?= substr($p['hora_fin'], 0, 5) ?>
                                    </small>
                                </td>
                                <td><?= number_format($p['horas_solicitadas'], 2) ?>h</td>
                                <td><?= htmlspecialchars($p['nombre_motivo']) ?></td>
                                <td>
                                    <span class="badge badge-warning">
                                        <?= $p['estado'] ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-sm btn-info-modern"
                                            onclick="verDetalle('permiso', <?= $p['id_permiso'] ?>, '<?= htmlspecialchars($p['descripcion'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($p['estado'] === 'PENDIENTE'): ?>
                                            <button class="btn btn-sm btn-success-modern"
                                                onclick="abrirModalDecision('permiso', <?= $p['id_permiso'] ?>, 'aprobar')">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger-modern"
                                                onclick="abrirModalDecision('permiso', <?= $p['id_permiso'] ?>, 'rechazar')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-inbox"></i>
                </div>
                <h3>No hay permisos pendientes</h3>
                <p>No se encontraron permisos con los criterios seleccionados</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab Vacaciones -->
    <div class="tab-content" id="vacaciones">
        <?php if (count($vacaciones) > 0): ?>
            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Funcionario</th>
                            <th>Fecha Solicitud</th>
                            <th>Período</th>
                            <th>Días</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vacaciones as $v): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($v['funcionario']) ?></strong></td>
                                <td><?= date('d/m/Y', strtotime($v['fecha_solicitud'])) ?></td>
                                <td>
                                    <small>
                                        <?= date('d/m/Y', strtotime($v['fecha_inicio'])) ?> -
                                        <?= date('d/m/Y', strtotime($v['fecha_fin'])) ?>
                                    </small>
                                </td>
                                <td><?= $v['dias_solicitados'] ?> días</td>
                                <td>
                                    <span class="badge badge-warning">
                                        <?= $v['estado'] ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-sm btn-info-modern"
                                            onclick="verDetalle('vacacion', <?= $v['id_vacacion'] ?>, '<?= htmlspecialchars($v['observaciones'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($v['estado'] === 'PENDIENTE'): ?>
                                            <button class="btn btn-sm btn-success-modern"
                                                onclick="abrirModalDecision('vacacion', <?= $v['id_vacacion'] ?>, 'aprobar')">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger-modern"
                                                onclick="abrirModalDecision('vacacion', <?= $v['id_vacacion'] ?>, 'rechazar')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-inbox"></i>
                </div>
                <h3>No hay vacaciones pendientes</h3>
                <p>No se encontraron vacaciones con los criterios seleccionados</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab Justificaciones -->
    <div class="tab-content" id="justificaciones">
        <?php if (count($justificaciones) > 0): ?>
            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Funcionario</th>
                            <th>Fecha Solicitud</th>
                            <th>Inconsistencia</th>
                            <th>Tipo</th>
                            <th>Motivo</th>
                            <th>Adjunto</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($justificaciones as $j): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($j['funcionario']) ?></strong></td>
                                <td><?= date('d/m/Y', strtotime($j['fecha_solicitud'])) ?></td>
                                <td><?= date('d/m/Y', strtotime($j['fecha_inconsistencia'])) ?></td>
                                <td><?= htmlspecialchars($j['nombre_tipo']) ?></td>
                                <td><?= htmlspecialchars($j['nombre_motivo']) ?></td>
                                <td>
                                    <?php if ($j['adjunto_path']): ?>
                                        <a href="<?= htmlspecialchars($j['adjunto_path']) ?>" target="_blank">
                                            <i class="fas fa-paperclip"></i> Ver
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">Sin adjunto</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-warning">
                                        <?= $j['estado'] ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-sm btn-info-modern"
                                            onclick="verDetalle('justificacion', <?= $j['id_justificacion'] ?>, '<?= htmlspecialchars($j['descripcion'], ENT_QUOTES) ?>', '<?= htmlspecialchars($j['adjunto_path'] ?? '', ENT_QUOTES) ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($j['estado'] === 'ENVIADA'): ?>
                                            <button class="btn btn-sm btn-success-modern"
                                                onclick="abrirModalDecision('justificacion', <?= $j['id_justificacion'] ?>, 'aprobar')">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger-modern"
                                                onclick="abrirModalDecision('justificacion', <?= $j['id_justificacion'] ?>, 'rechazar')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-inbox"></i>
                </div>
                <h3>No hay justificaciones pendientes</h3>
                <p>No se encontraron justificaciones con los criterios seleccionados</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Ver Detalle -->
<div class="modal fade" id="modalDetalle" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content modal-modern">
            <div class="modal-header-modern">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle"></i>
                    Detalle de la Solicitud
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="detail-section">
                    <label class="detail-label">
                        <i class="fas fa-comment"></i>
                        Descripción / Observaciones
                    </label>
                    <div class="detail-content" id="modal-detalle-desc"></div>
                </div>
                <div class="detail-section" id="modal-adjunto-section" style="display:none;">
                    <label class="detail-label">
                        <i class="fas fa-paperclip"></i>
                        Archivo Adjunto
                    </label>
                    <a id="modal-adjunto-link" href="#" target="_blank" class="btn btn-primary-modern">
                        <i class="fas fa-download"></i>
                        Descargar Adjunto
                    </a>
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

<!-- Modal Decisión (Aprobar/Rechazar) -->
<div class="modal fade" id="modalDecision" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content modal-modern">
            <form method="POST">
                <div class="modal-header-modern">
                    <h5 class="modal-title" id="modalDecisionTitle">
                        <i class="fas fa-question-circle"></i>
                        Confirmar Acción
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="tipo" id="decision-tipo">
                    <input type="hidden" name="id" id="decision-id">
                    <input type="hidden" name="accion" id="decision-accion">

                    <div class="detail-section">
                        <label class="detail-label">
                            <i class="fas fa-comment-dots"></i>
                            Observación
                            <span style="color: var(--danger);">*</span>
                        </label>
                        <textarea class="form-control-modern" name="observacion" id="decision-observacion" rows="4"
                            required minlength="10" maxlength="500"
                            placeholder="Escriba sus observaciones (mínimo 10 caracteres)"></textarea>
                        <small class="text-muted">
                            <span id="char-count">0</span> / 500 caracteres (mínimo 10)
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary-modern" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary-modern" id="btn-confirmar">
                        <i class="fas fa-check"></i>
                        Confirmar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Sistema de tabs
    document.querySelectorAll('.nav-tab').forEach(tab => {
        tab.addEventListener('click', function () {
            // Remover active de todos
            document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            // Activar el seleccionado
            this.classList.add('active');
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId).classList.add('active');
        });
    });

    // Ver detalle
    function verDetalle(tipo, id, descripcion, adjunto = '') {
        const modal = new bootstrap.Modal(document.getElementById('modalDetalle'));

        document.getElementById('modal-detalle-desc').textContent = descripcion || 'Sin descripción';

        if (adjunto && adjunto.trim() !== '') {
            document.getElementById('modal-adjunto-section').style.display = 'block';
            document.getElementById('modal-adjunto-link').href = adjunto;
        } else {
            document.getElementById('modal-adjunto-section').style.display = 'none';
        }

        modal.show();
    }

    // Abrir modal de decisión
    function abrirModalDecision(tipo, id, accion) {
        const modal = new bootstrap.Modal(document.getElementById('modalDecision'));

        document.getElementById('decision-tipo').value = tipo;
        document.getElementById('decision-id').value = id;
        document.getElementById('decision-accion').value = accion;
        document.getElementById('decision-observacion').value = '';

        const title = document.getElementById('modalDecisionTitle');
        const btnConfirmar = document.getElementById('btn-confirmar');

        if (accion === 'aprobar') {
            title.innerHTML = '<i class="fas fa-check-circle"></i> Aprobar Solicitud';
            btnConfirmar.className = 'btn btn-success-modern';
            btnConfirmar.innerHTML = '<i class="fas fa-check"></i> Aprobar';
        } else {
            title.innerHTML = '<i class="fas fa-times-circle"></i> Rechazar Solicitud';
            btnConfirmar.className = 'btn btn-danger-modern';
            btnConfirmar.innerHTML = '<i class="fas fa-times"></i> Rechazar';
        }

        modal.show();
    }

    // Contador de caracteres
    document.getElementById('decision-observacion').addEventListener('input', function () {
        const count = this.value.length;
        document.getElementById('char-count').textContent = count;
    });

    // Validación antes de enviar
    document.querySelector('#modalDecision form').addEventListener('submit', function (e) {
        const obs = document.getElementById('decision-observacion').value;
        if (obs.length < 10) {
            e.preventDefault();
            alert('La observación debe tener al menos 10 caracteres');
            return false;
        }
    });
</script>

<?php
require_once 'templates/footer.php';
?>