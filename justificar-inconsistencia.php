<?php
// HU#5: Justificar inconsistencias detectadas
require_once 'includes/auth.php';
requireRole([3]);

$identificacion = $_SESSION['identificacion'];
$pageTitle = "Justificar Inconsistencia";

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

// Procesar envío de justificación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_justificacion'])) {
    $id_inconsistencia = $_POST['id_inconsistencia'] ?? 0;
    $id_motivo = $_POST['id_motivo'] ?? 0;
    $descripcion = trim($_POST['descripcion'] ?? '');
    
    $errors = [];
    
    // Validaciones
    if (empty($id_inconsistencia)) {
        $errors[] = "Debe seleccionar una inconsistencia";
    }
    
    if (empty($id_motivo)) {
        $errors[] = "Debe seleccionar un motivo";
    }
    
    if (empty($descripcion)) {
        $errors[] = "La descripción es obligatoria";
    } elseif (strlen($descripcion) > 300) {
        $errors[] = "La descripción no puede exceder 300 caracteres";
    }
    
    // Verificar que la inconsistencia sea del funcionario y esté en estado correcto
    if (empty($errors)) {
        $check_sql = "SELECT i.id_inconsistencia, i.estado_inconsistencia, i.fecha_inconsistencia
                      FROM inconsistencias i
                      WHERE i.id_inconsistencia = ? 
                      AND i.identificacion_funcionario = ?
                      AND i.estado_inconsistencia IN ('PENDIENTE', 'NO_JUSTIFICADA')";
        $stmt = $pdo->prepare($check_sql);
        $stmt->execute([$id_inconsistencia, $identificacion]);
        
        registrarBitacora($pdo, $identificacion, 'SELECT', 'inconsistencias', 
            "Consulta de inconsistencia para justificar - ID: $id_inconsistencia");
        
        $inconsistencia = $stmt->fetch();
        
        if (!$inconsistencia) {
            $errors[] = "Inconsistencia no válida o ya justificada";
        }
    }
    
    // Verificar que no exista justificación duplicada
    if (empty($errors)) {
        $dup_sql = "SELECT id_justificacion FROM justificaciones 
                    WHERE id_inconsistencia = ? AND identificacion_funcionario = ?";
        $stmt_dup = $pdo->prepare($dup_sql);
        $stmt_dup->execute([$id_inconsistencia, $identificacion]);
        
        registrarBitacora($pdo, $identificacion, 'SELECT', 'justificaciones', 
            "Verificación de justificación duplicada - ID Inconsistencia: $id_inconsistencia");
        
        if ($stmt_dup->rowCount() > 0) {
            $errors[] = "Ya existe una justificación para esta inconsistencia";
        }
    }
    
    // Procesar archivo adjunto
    $adjunto_path = null;
    if (empty($errors) && isset($_FILES['adjunto']) && $_FILES['adjunto']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['adjunto'];
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/png'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = "Solo se permiten archivos PDF, JPG o PNG";
        } elseif ($file['size'] > $max_size) {
            $errors[] = "El archivo no puede superar 5MB";
        } else {
            $upload_dir = 'uploads/justificaciones/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = $identificacion . '_' . $id_inconsistencia . '_' . time() . '.' . $extension;
            $adjunto_path = $upload_dir . $filename;
            
            if (!move_uploaded_file($file['tmp_name'], $adjunto_path)) {
                $errors[] = "Error al subir el archivo";
                $adjunto_path = null;
            }
        }
    }
    
    // Insertar justificación
    if (empty($errors)) {
        $pdo->beginTransaction();
        
        try {
            $insert_sql = "INSERT INTO justificaciones 
                          (identificacion_funcionario, id_inconsistencia, id_motivo, descripcion, 
                           adjunto_path, estado_justificacion, fecha_solicitud)
                          VALUES (?, ?, ?, ?, ?, 'ENVIADA', NOW())";
            $stmt_insert = $pdo->prepare($insert_sql);
            $stmt_insert->execute([$identificacion, $id_inconsistencia, $id_motivo, $descripcion, $adjunto_path]);
            $id_justificacion = $pdo->lastInsertId();
            
            // Actualizar estado de inconsistencia
            $update_sql = "UPDATE inconsistencias SET estado_inconsistencia = 'ENVIADA' 
                          WHERE id_inconsistencia = ?";
            $stmt_update = $pdo->prepare($update_sql);
            $stmt_update->execute([$id_inconsistencia]);
            
            // Registrar en bitácora
            $bitacora_desc = json_encode([
                'id_justificacion' => $id_justificacion,
                'id_inconsistencia' => $id_inconsistencia,
                'id_motivo' => $id_motivo,
                'tiene_adjunto' => !empty($adjunto_path),
                'fecha_inconsistencia' => $inconsistencia['fecha_inconsistencia']
            ]);
            
            registrarBitacora($pdo, $identificacion, 'INSERT', 'justificaciones', $bitacora_desc);
            registrarBitacora($pdo, $identificacion, 'UPDATE', 'inconsistencias', 
                "Estado actualizado a ENVIADA - ID: $id_inconsistencia");
            
            $pdo->commit();
            
            $success_message = "Justificación enviada exitosamente. Será evaluada por su jefatura.";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            if ($adjunto_path && file_exists($adjunto_path)) {
                unlink($adjunto_path);
            }
            registrarBitacora($pdo, $identificacion, 'ERROR', 'justificaciones', 
                "Error al guardar justificación: " . $e->getMessage());
            $errors[] = "Error al guardar la justificación: " . $e->getMessage();
        }
    }
}

// Obtener inconsistencias justificables
$inconsistencias_sql = "SELECT i.id_inconsistencia, i.fecha_inconsistencia, i.estado_inconsistencia,
                               ti.nombre_tipo, ti.descripcion as tipo_descripcion
                        FROM inconsistencias i
                        INNER JOIN tipos_inconsistencia ti ON i.id_tipo_inconsistencia = ti.id_tipo_inconsistencia
                        WHERE i.identificacion_funcionario = ?
                        AND i.estado_inconsistencia IN ('PENDIENTE', 'NO_JUSTIFICADA')
                        AND i.id_inconsistencia NOT IN (
                            SELECT id_inconsistencia FROM justificaciones 
                            WHERE identificacion_funcionario = ?
                        )
                        ORDER BY i.fecha_inconsistencia DESC";
$stmt_inc = $pdo->prepare($inconsistencias_sql);
$stmt_inc->execute([$identificacion, $identificacion]);
$inconsistencias = $stmt_inc->fetchAll();

registrarBitacora($pdo, $identificacion, 'SELECT', 'inconsistencias', 
    "Consulta de inconsistencias justificables - Cantidad: " . count($inconsistencias));

// Obtener motivos activos
$motivos_sql = "SELECT id_motivo, nombre_motivo, descripcion 
                FROM motivos_ausencia 
                WHERE estado = 'ACTIVO' 
                ORDER BY nombre_motivo";
$stmt_motivos = $pdo->query($motivos_sql);
$motivos = $stmt_motivos->fetchAll();

registrarBitacora($pdo, $identificacion, 'SELECT', 'motivos_ausencia', 
    "Consulta de motivos activos - Cantidad: " . count($motivos));

// Obtener historial de justificaciones
$historial_sql = "SELECT j.id_justificacion, j.fecha_solicitud, j.estado_justificacion,
                         j.fecha_respuesta, j.observaciones_revisor,
                         i.fecha_inconsistencia, ti.nombre_tipo,
                         ma.nombre_motivo, j.descripcion, j.adjunto_path,
                         CONCAT(f.nombre, ' ', f.apellido) as nombre_revisor
                  FROM justificaciones j
                  INNER JOIN inconsistencias i ON j.id_inconsistencia = i.id_inconsistencia
                  INNER JOIN tipos_inconsistencia ti ON i.id_tipo_inconsistencia = ti.id_tipo_inconsistencia
                  INNER JOIN motivos_ausencia ma ON j.id_motivo = ma.id_motivo
                  LEFT JOIN funcionarios f ON j.identificacion_revisor = f.identificacion
                  WHERE j.identificacion_funcionario = ?
                  ORDER BY j.fecha_solicitud DESC";
$stmt_hist = $pdo->prepare($historial_sql);
$stmt_hist->execute([$identificacion]);
$historial = $stmt_hist->fetchAll();

registrarBitacora($pdo, $identificacion, 'SELECT', 'justificaciones', 
    "Consulta de historial de justificaciones - Cantidad: " . count($historial));

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
    --secondary: #6b7280;
    --border-radius: 12px;
    --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
    --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.12);
}

/* Contenedor principal centrado */
.page-container {
    max-width: 1200px;
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

.alert {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.25rem;
    border-radius: var(--border-radius);
    border: none;
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.5rem;
}

.alert-icon {
    font-size: 1.5rem;
    flex-shrink: 0;
}

.alert-content {
    flex: 1;
}

.card-modern {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-md);
    overflow: hidden;
    background: white;
    margin-bottom: 2rem;
}

.card-header-modern {
    background: linear-gradient(to right, #f8f9fa 0%, #ffffff 100%);
    padding: 1.5rem;
    border-bottom: 2px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
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

.required {
    color: var(--danger);
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

.form-text-modern {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #6b7280;
    font-size: 0.875rem;
    margin-top: 0.5rem;
}

.char-counter {
    text-align: right;
    color: #6b7280;
    font-size: 0.875rem;
    margin-top: 0.5rem;
}

.info-box {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    background: linear-gradient(135deg, #e0f2fe 0%, #dbeafe 100%);
    border-radius: 8px;
    border-left: 4px solid var(--info);
}

.info-box-icon {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    background: white;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--info);
    font-size: 1.25rem;
}

.info-box-content {
    flex: 1;
}

.info-box-content strong {
    display: block;
    color: #1e40af;
    margin-bottom: 0.25rem;
}

.info-box-content p {
    color: #1e3a8a;
    font-size: 0.9rem;
}

.form-actions {
    display: flex;
    gap: 1rem;
    padding-top: 1rem;
    border-top: 2px solid #f3f4f6;
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

.date-info {
    display: flex;
    flex-direction: column;
}

.date-info strong {
    color: #1f2937;
    font-size: 0.95rem;
}

.date-info small {
    color: #6b7280;
    font-size: 0.85rem;
}

.inconsistencia-info {
    display: flex;
    flex-direction: column;
}

.inconsistencia-info strong {
    color: #1f2937;
    font-size: 0.95rem;
}

.inconsistencia-info small {
    color: #6b7280;
    font-size: 0.85rem;
    margin-top: 0.25rem;
}

.motivo-badge {
    display: inline-block;
    padding: 0.5rem 0.75rem;
    background: #f3f4f6;
    border-radius: 6px;
    color: #4b5563;
    font-size: 0.875rem;
    font-weight: 500;
}

.badge-warning-modern {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.badge-success-modern {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.badge-danger-modern {
    background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
    color: #991b1b;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.badge-info-modern {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
}

.estado-complete {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.estado-info {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #6b7280;
    font-size: 0.85rem;
}

.estado-info i {
    color: var(--primary);
}

.btn-info-modern {
    background: linear-gradient(135deg, var(--info) 0%, #2563eb 100%);
    color: white;
    padding: 0.5rem 1rem;
    border-radius: 6px;
    border: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    cursor: pointer;
}

.btn-info-modern:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
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

.modal-modern .modal-content {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-lg);
}

.modal-header-modern {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    padding: 1.5rem;
    border: none;
}

.modal-header-modern .modal-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-weight: 600;
}

.btn-close-white {
    filter: brightness(0) invert(1);
    opacity: 0.8;
}

.modal-body {
    padding: 2rem;
}

.detail-section {
    margin-bottom: 1.5rem;
}

.detail-section:last-child {
    margin-bottom: 0;
}

.detail-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.75rem;
    font-size: 0.95rem;
}

.detail-label i {
    color: var(--primary);
}

.detail-content {
    padding: 1rem;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    color: #4b5563;
    line-height: 1.6;
    white-space: pre-wrap;
    word-wrap: break-word;
}

.detail-content-highlight {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border-color: #fbbf24;
    color: #92400e;
}

.respuesta-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border-radius: 8px;
    color: #1e40af;
    font-size: 1rem;
    margin-bottom: 1rem;
}

.respuesta-header i {
    font-size: 1.25rem;
}

.modal-footer-modern {
    padding: 1.5rem;
    border-top: 2px solid #f3f4f6;
}

.btn-outline-primary-modern {
    background: white;
    color: var(--primary);
    border: 2px solid var(--primary);
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-outline-primary-modern:hover {
    background: var(--primary);
    color: white;
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

@media (max-width: 768px) {
    .page-container {
        padding: 0 1rem;
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
    
    .form-actions {
        flex-direction: column;
    }
    
    .btn-primary-modern,
    .btn-secondary-modern {
        width: 100%;
        justify-content: center;
    }
}
</style>


<div class="page-container">
        <!-- Header -->
        <div class="page-header">
            <div class="header-content">
                <div class="header-icon">
                    <i class="fas fa-comment-medical"></i>
                </div>
                <div>
                    <h1 class="page-title">Justificar Inconsistencias</h1>
                    <p class="page-subtitle">Envía justificaciones para tus inconsistencias de asistencia</p>
                </div>
            </div>
        </div>



        <!-- Mensajes -->
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <div class="alert-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="alert-content">
                    <strong>¡Éxito!</strong> <?= $success_message ?>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <div class="alert-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="alert-content">
                    <strong>Error:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Formulario de Justificación -->
        <div class="card card-modern mb-4">
            <div class="card-header-modern">
                <div class="card-header-content">
                    <i class="fas fa-edit"></i>
                    <h5>Nueva Justificación</h5>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($inconsistencias) > 0): ?>
                    <form method="POST" enctype="multipart/form-data" id="formJustificacion">
                        <div class="row g-4">
                            <!-- Inconsistencia -->
                            <div class="col-md-12">
                                <div class="form-group-modern">
                                    <label for="id_inconsistencia" class="form-label-modern">
                                        <i class="fas fa-exclamation-circle"></i>
                                        Inconsistencia a Justificar
                                        <span class="required">*</span>
                                    </label>
                                    <select class="form-select-modern" id="id_inconsistencia" name="id_inconsistencia" required>
                                        <option value="">Seleccione una inconsistencia</option>
                                        <?php foreach ($inconsistencias as $inc): ?>
                                            <option value="<?= $inc['id_inconsistencia'] ?>"
                                                    data-tipo="<?= htmlspecialchars($inc['nombre_tipo']) ?>"
                                                    data-desc="<?= htmlspecialchars($inc['tipo_descripcion']) ?>">
                                                <?= date('d/m/Y', strtotime($inc['fecha_inconsistencia'])) ?> - 
                                                <?= htmlspecialchars($inc['nombre_tipo']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="inconsistencia-info" class="info-box mt-3 d-none">
                                        <div class="info-box-icon">
                                            <i class="fas fa-info-circle"></i>
                                        </div>
                                        <div class="info-box-content">
                                            <strong id="tipo-nombre"></strong>
                                            <p id="tipo-desc" class="mb-0"></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Motivo -->
                            <div class="col-md-6">
                                <div class="form-group-modern">
                                    <label for="id_motivo" class="form-label-modern">
                                        <i class="fas fa-tag"></i>
                                        Motivo
                                        <span class="required">*</span>
                                    </label>
                                    <select class="form-select-modern" id="id_motivo" name="id_motivo" required>
                                        <option value="">Seleccione un motivo</option>
                                        <?php foreach ($motivos as $motivo): ?>
                                            <option value="<?= $motivo['id_motivo'] ?>"
                                                    title="<?= htmlspecialchars($motivo['descripcion']) ?>">
                                                <?= htmlspecialchars($motivo['nombre_motivo']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Adjunto -->
                            <div class="col-md-6">
                                <div class="form-group-modern">
                                    <label for="adjunto" class="form-label-modern">
                                        <i class="fas fa-paperclip"></i>
                                        Adjunto (Opcional)
                                    </label>
                                    <input type="file" class="form-control-modern" id="adjunto" name="adjunto" 
                                           accept=".pdf,.jpg,.jpeg,.png">
                                    <small class="form-text-modern">
                                        <i class="fas fa-info-circle"></i>
                                        PDF, JPG, PNG - Máx. 5MB
                                    </small>
                                </div>
                            </div>

                            <!-- Descripción -->
                            <div class="col-12">
                                <div class="form-group-modern">
                                    <label for="descripcion" class="form-label-modern">
                                        <i class="fas fa-align-left"></i>
                                        Descripción Detallada
                                        <span class="required">*</span>
                                    </label>
                                    <textarea class="form-control-modern" id="descripcion" name="descripcion" 
                                              rows="5" maxlength="300" required 
                                              placeholder="Explique detalladamente la razón de la inconsistencia"></textarea>
                                    <div class="char-counter">
                                        <span id="char-count">0</span> / 300 caracteres
                                    </div>
                                </div>
                            </div>

                            <!-- Botones -->
                            <div class="col-12">
                                <div class="form-actions">
                                    <button type="submit" name="enviar_justificacion" class="btn btn-primary-modern">
                                        <i class="fas fa-paper-plane"></i>
                                        Enviar Justificación
                                    </button>
                                    <button type="reset" class="btn btn-secondary-modern">
                                        <i class="fas fa-redo"></i>
                                        Limpiar Formulario
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3>No hay inconsistencias pendientes</h3>
                        <p>No tienes inconsistencias que requieran justificación en este momento</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Historial de Justificaciones -->
        <div class="card card-modern">
            <div class="card-header-modern">
                <div class="card-header-content">
                    <i class="fas fa-history"></i>
                    <h5>Historial de Justificaciones</h5>
                </div>
                <div class="card-header-actions">
                    <span class="badge badge-info-modern">
                        <?= count($historial) ?> registros
                    </span>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($historial) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-calendar"></i> Fecha Solicitud</th>
                                    <th><i class="fas fa-exclamation-triangle"></i> Inconsistencia</th>
                                    <th><i class="fas fa-tag"></i> Motivo</th>
                                    <th><i class="fas fa-circle"></i> Estado</th>
                                    <th><i class="fas fa-cog"></i> Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($historial as $just): ?>
                                    <tr>
                                        <td>
                                            <div class="date-info">
                                                <strong><?= date('d/m/Y', strtotime($just['fecha_solicitud'])) ?></strong>
                                                <small><?= date('H:i', strtotime($just['fecha_solicitud'])) ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="inconsistencia-info">
                                                <strong><?= date('d/m/Y', strtotime($just['fecha_inconsistencia'])) ?></strong>
                                                <small><?= htmlspecialchars($just['nombre_tipo']) ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="motivo-badge">
                                                <?= htmlspecialchars($just['nombre_motivo']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $estado = $just['estado_justificacion'];
                                            $badge_class = [
                                                'ENVIADA' => 'badge-warning-modern',
                                                'APROBADA' => 'badge-success-modern',
                                                'RECHAZADA' => 'badge-danger-modern'
                                            ];
                                            $icon_class = [
                                                'ENVIADA' => 'fa-clock',
                                                'APROBADA' => 'fa-check-circle',
                                                'RECHAZADA' => 'fa-times-circle'
                                            ];
                                            ?>
                                            <div class="estado-complete">
                                                <span class="badge <?= $badge_class[$estado] ?? 'badge-secondary-modern' ?>">
                                                    <i class="fas <?= $icon_class[$estado] ?? 'fa-question-circle' ?>"></i>
                                                    <?= $estado ?>
                                                </span>
                                                <?php if ($just['fecha_respuesta']): ?>
                                                    <small class="estado-info">
                                                        <i class="fas fa-calendar-check"></i>
                                                        <?= date('d/m/Y', strtotime($just['fecha_respuesta'])) ?>
                                                    </small>
                                                    <?php if ($just['nombre_revisor']): ?>
                                                        <small class="estado-info">
                                                            <i class="fas fa-user"></i>
                                                            <?= htmlspecialchars($just['nombre_revisor']) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <button type="button" 
                                                    class="btn btn-sm btn-info-modern btn-ver-detalle"
                                                    onclick="abrirModal('<?= htmlspecialchars($just['descripcion'], ENT_QUOTES) ?>', '<?= htmlspecialchars($just['observaciones_revisor'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($just['adjunto_path'] ?? '', ENT_QUOTES) ?>')">
                                                <i class="fas fa-eye"></i>
                                                Ver
                                            </button>
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
                        <h3>No hay justificaciones</h3>
                        <p>Aún no has enviado ninguna justificación</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal Detalle -->
<div class="modal fade" id="modalDetalle" tabindex="-1" aria-labelledby="modalDetalleLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content modal-modern">
            <div class="modal-header-modern">
                <h5 class="modal-title" id="modalDetalleLabel">
                    <i class="fas fa-file-alt"></i>
                    Detalle de Justificación
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Descripción -->
                <div class="detail-section">
                    <label class="detail-label">
                        <i class="fas fa-comment"></i>
                        Tu Descripción
                    </label>
                    <div class="detail-content" id="modal-descripcion"></div>
                </div>
                
                <!-- Observaciones -->
                <div class="detail-section" id="modal-observaciones-container" style="display:none;">
                    <div class="respuesta-header">
                        <i class="fas fa-reply"></i>
                        <strong>Respuesta de la Jefatura</strong>
                    </div>
                    <label class="detail-label">
                        <i class="fas fa-eye"></i>
                        Observaciones del Revisor
                    </label>
                    <div class="detail-content detail-content-highlight" id="modal-observaciones"></div>
                </div>
                
                <!-- Adjunto -->
                <div class="detail-section" id="modal-adjunto-container" style="display:none;">
                    <label class="detail-label">
                        <i class="fas fa-paperclip"></i>
                        Archivo Adjunto
                    </label>
                    <a id="modal-adjunto" href="#" target="_blank" class="btn btn-outline-primary-modern">
                        <i class="fas fa-download"></i>
                        Descargar archivo
                    </a>
                </div>
            </div>
            <div class="modal-footer-modern">
                <button type="button" class="btn btn-secondary-modern" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i>
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>


<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Función para abrir el modal
function abrirModal(descripcion, observaciones, adjunto) {
    // Decodificar HTML entities
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = descripcion;
    const descripcionDecoded = tempDiv.textContent;
    
    tempDiv.innerHTML = observaciones;
    const observacionesDecoded = tempDiv.textContent;
    
    tempDiv.innerHTML = adjunto;
    const adjuntoDecoded = tempDiv.textContent;
    
    // Llenar descripción
    document.getElementById('modal-descripcion').textContent = descripcionDecoded;
    
    // Observaciones
    const obsContainer = document.getElementById('modal-observaciones-container');
    if (observacionesDecoded && observacionesDecoded.trim() !== '') {
        document.getElementById('modal-observaciones').textContent = observacionesDecoded;
        obsContainer.style.display = 'block';
    } else {
        obsContainer.style.display = 'none';
    }
    
    // Adjunto
    const adjuntoContainer = document.getElementById('modal-adjunto-container');
    if (adjuntoDecoded && adjuntoDecoded.trim() !== '') {
        document.getElementById('modal-adjunto').href = adjuntoDecoded;
        adjuntoContainer.style.display = 'block';
    } else {
        adjuntoContainer.style.display = 'none';
    }
    
    // Abrir modal
    const modalEl = document.getElementById('modalDetalle');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}

// Contador de caracteres
document.getElementById('descripcion').addEventListener('input', function() {
    const count = this.value.length;
    const counter = document.getElementById('char-count');
    counter.textContent = count;
    
    if (count > 250) {
        counter.style.color = 'var(--danger)';
    } else if (count > 200) {
        counter.style.color = 'var(--warning)';
    } else {
        counter.style.color = '#6b7280';
    }
});

// Mostrar info de inconsistencia
document.getElementById('id_inconsistencia').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const info = document.getElementById('inconsistencia-info');
    
    if (this.value) {
        document.getElementById('tipo-nombre').textContent = selected.dataset.tipo;
        document.getElementById('tipo-desc').textContent = selected.dataset.desc;
        info.classList.remove('d-none');
    } else {
        info.classList.add('d-none');
    }
});

// Validación de archivo
document.getElementById('adjunto').addEventListener('change', function() {
    const maxSize = 5 * 1024 * 1024;
    const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
    
    if (this.files[0]) {
        const file = this.files[0];
        
        if (file.size > maxSize) {
            alert('El archivo no puede superar 5MB');
            this.value = '';
            return;
        }
        
        if (!allowedTypes.includes(file.type)) {
            alert('Solo se permiten archivos PDF, JPG o PNG');
            this.value = '';
            return;
        }
    }
});

// Confirmación antes de limpiar formulario
document.querySelector('button[type="reset"]').addEventListener('click', function(e) {
    const form = document.getElementById('formJustificacion');
    let hasData = false;
    
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
        if (input.value && input.type !== 'submit' && input.type !== 'reset') {
            hasData = true;
        }
    });
    
    if (hasData) {
        if (!confirm('¿Está seguro de que desea limpiar el formulario? Se perderán todos los datos ingresados.')) {
            e.preventDefault();
        }
    }
});
</script>


<?php
require_once 'templates/footer.php';
?>