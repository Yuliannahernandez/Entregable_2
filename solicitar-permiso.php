<?php
// HU#6: Solicitar permiso con fecha y horario
require_once 'includes/auth.php';
requireRole([3]); // Solo funcionarios

$identificacion = $_SESSION['identificacion'];
$pageTitle = "Solicitar Permiso";

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

// Procesar envío de permiso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_permiso'])) {
    $fecha_inicio = $_POST['fecha_inicio'] ?? '';
    $fecha_fin = $_POST['fecha_fin'] ?? '';
    $hora_inicio = $_POST['hora_inicio'] ?? '';
    $hora_fin = $_POST['hora_fin'] ?? '';
    $id_motivo = $_POST['id_motivo'] ?? 0;
    $descripcion = trim($_POST['descripcion'] ?? '');
    
    $errors = [];

    // Validaciones básicas
    if (empty($fecha_inicio)) $errors[] = "La fecha de inicio es obligatoria";
    if (empty($fecha_fin)) $errors[] = "La fecha de fin es obligatoria";
    if (empty($hora_inicio)) $errors[] = "La hora de inicio es obligatoria";
    if (empty($hora_fin)) $errors[] = "La hora de fin es obligatoria";
    if (empty($id_motivo)) $errors[] = "Debe seleccionar un motivo";
    if (strlen($descripcion) > 300) $errors[] = "Las observaciones no pueden exceder 300 caracteres";

    // Validar fechas lógicas
    if (empty($errors)) {
        if (strtotime($fecha_inicio) > strtotime($fecha_fin)) {
            $errors[] = "La fecha de inicio no puede ser posterior a la fecha de fin";
        }
        if ($fecha_inicio === $fecha_fin && strtotime($hora_inicio) >= strtotime($hora_fin)) {
            $errors[] = "La hora de inicio debe ser anterior a la hora de fin";
        }
    }

    // Calcular horas solicitadas
    $horas_solicitadas = 0;
    if (empty($errors)) {
        $datetime_inicio = new DateTime("$fecha_inicio $hora_inicio");
        $datetime_fin = new DateTime("$fecha_fin $hora_fin");
        $interval = $datetime_inicio->diff($datetime_fin);
        $horas_solicitadas = ($interval->days * 24) + $interval->h + ($interval->i / 60);

        if ($horas_solicitadas <= 0) {
            $errors[] = "El período del permiso debe ser mayor a cero";
        }

        if ($horas_solicitadas > 999.99) {
            $errors[] = "El período seleccionado excede el límite de horas permitido (máx. 999.99 horas)";
        }
    }

    // Validar superposición con vacaciones aprobadas
    if (empty($errors)) {
        $vac_sql = "SELECT COUNT(*) FROM vacaciones 
                    WHERE identificacion_funcionario = ? 
                    AND estado_vacacion = 'APROBADO'
                    AND NOT (fecha_fin < ? OR fecha_inicio > ?)";
        $stmt_vac = $pdo->prepare($vac_sql);
        $stmt_vac->execute([$identificacion, $fecha_inicio, $fecha_fin]);
        $vac_result = $stmt_vac->fetchColumn();

        registrarBitacora($pdo, $identificacion, 'SELECT', 'vacaciones', 
            "Verificación de superposición con vacaciones - Resultado: " . ($vac_result > 0 ? 'Conflicto detectado' : 'Sin conflicto'));

        if ($vac_result > 0) {
            $errors[] = "El período seleccionado se superpone con vacaciones aprobadas";
        }
    }

    // Insertar permiso
    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            $insert_sql = "INSERT INTO permisos 
                          (identificacion_funcionario, id_motivo, fecha_solicitud, fecha_inicio, fecha_fin,
                           hora_inicio, hora_fin, horas_solicitadas, descripcion, 
                           estado_permiso, fecha_creacion)
                          VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, 'PENDIENTE', NOW())";
            $stmt_insert = $pdo->prepare($insert_sql);
            $stmt_insert->execute([
                $identificacion, $id_motivo, $fecha_inicio, $fecha_fin,
                $hora_inicio, $hora_fin, $horas_solicitadas, $descripcion
            ]);
            $id_permiso = $pdo->lastInsertId();

            // Registrar en bitácora
            $bitacora_desc = json_encode([
                'id_permiso' => $id_permiso,
                'id_motivo' => $id_motivo,
                'fecha_inicio' => $fecha_inicio,
                'fecha_fin' => $fecha_fin,
                'horas_solicitadas' => $horas_solicitadas
            ]);
            
            registrarBitacora($pdo, $identificacion, 'INSERT', 'permisos', $bitacora_desc);

            $pdo->commit();
            $success_message = "Permiso solicitado exitosamente. Será evaluado por su jefatura.";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            registrarBitacora($pdo, $identificacion, 'ERROR', 'permisos', 
                "Error al guardar permiso: " . $e->getMessage());
            $errors[] = "Error al guardar el permiso: " . $e->getMessage();
        }
    }
}

// Obtener motivos activos
$motivos_sql = "SELECT id_motivo, nombre_motivo, descripcion 
                FROM motivos_ausencia 
                WHERE estado = 'ACTIVO' 
                ORDER BY nombre_motivo";
$stmt_motivos = $pdo->query($motivos_sql);
$motivos = $stmt_motivos->fetchAll();

registrarBitacora($pdo, $identificacion, 'SELECT', 'motivos_ausencia', 
    "Consulta de motivos activos - Cantidad: " . count($motivos));

// Filtros para historial
$filtro_estado = $_GET['estado'] ?? '';
$filtro_fecha_desde = $_GET['fecha_desde'] ?? '';
$filtro_fecha_hasta = $_GET['fecha_hasta'] ?? '';

$historial_where = ["p.identificacion_funcionario = ?"];
$historial_params = [$identificacion];

if (!empty($filtro_estado)) {
    $historial_where[] = "p.estado_permiso = ?";
    $historial_params[] = $filtro_estado;
}
if (!empty($filtro_fecha_desde)) {
    $historial_where[] = "p.fecha_inicio >= ?";
    $historial_params[] = $filtro_fecha_desde;
}
if (!empty($filtro_fecha_hasta)) {
    $historial_where[] = "p.fecha_fin <= ?";
    $historial_params[] = $filtro_fecha_hasta;
}

$historial_where_clause = implode(" AND ", $historial_where);

// Historial de permisos
$historial_sql = "SELECT p.id_permiso, p.fecha_solicitud, p.fecha_inicio, p.fecha_fin,
                         p.hora_inicio, p.hora_fin, p.horas_solicitadas, p.estado_permiso,
                         p.fecha_respuesta, p.comentarios_aprobacion,
                         ma.nombre_motivo, p.descripcion,
                         CONCAT(f.nombre, ' ', f.apellido) as nombre_aprobador
                  FROM permisos p
                  INNER JOIN motivos_ausencia ma ON p.id_motivo = ma.id_motivo
                  LEFT JOIN funcionarios f ON p.identificacion_aprobador = f.identificacion
                  WHERE $historial_where_clause
                  ORDER BY p.fecha_solicitud DESC";
$stmt_hist = $pdo->prepare($historial_sql);
$stmt_hist->execute($historial_params);
$historial = $stmt_hist->fetchAll();

registrarBitacora($pdo, $identificacion, 'SELECT', 'permisos', 
    "Consulta de historial de permisos - Cantidad: " . count($historial) . 
    " - Filtros: Estado=" . ($filtro_estado ?: 'Todos') . 
    ", Desde=" . ($filtro_fecha_desde ?: 'Sin límite') . 
    ", Hasta=" . ($filtro_fecha_hasta ?: 'Sin límite'));


    
require_once 'templates/header.php'; 
?>



<div class="page-container">
        <!-- Header -->
        <div class="page-header">
            <div class="header-content">
                <div class="header-icon">
                    <i class="fas fa-file-signature"></i>
                </div>
                <div>
                    <h1 class="page-title">Solicitar Permiso</h1>
                    <p class="page-subtitle">Solicita permisos para ausentarte justificadamente</p>
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

        <!-- Formulario de Solicitud -->
        <div class="card card-modern mb-4">
            <div class="card-header-modern">
                <div class="card-header-content">
                    <i class="fas fa-plus-circle"></i>
                    <h5>Nueva Solicitud de Permiso</h5>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" id="formPermiso">
                    <div class="row g-4">
                        <!-- Fecha y Hora de Inicio -->
                        <div class="col-md-3">
                            <div class="form-group-modern">
                                <label for="fecha_inicio" class="form-label-modern">
                                    <i class="fas fa-calendar-day"></i>
                                    Fecha Inicio
                                    <span class="required">*</span>
                                </label>
                                <input type="date" class="form-control-modern" id="fecha_inicio" 
                                       name="fecha_inicio" required
                                       min="<?= date('Y-m-d') ?>">
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group-modern">
                                <label for="hora_inicio" class="form-label-modern">
                                    <i class="fas fa-clock"></i>
                                    Hora Inicio
                                    <span class="required">*</span>
                                </label>
                                <input type="time" class="form-control-modern" id="hora_inicio" 
                                       name="hora_inicio" required>
                            </div>
                        </div>

                        <!-- Fecha y Hora de Fin -->
                        <div class="col-md-3">
                            <div class="form-group-modern">
                                <label for="fecha_fin" class="form-label-modern">
                                    <i class="fas fa-calendar-check"></i>
                                    Fecha Fin
                                    <span class="required">*</span>
                                </label>
                                <input type="date" class="form-control-modern" id="fecha_fin" 
                                       name="fecha_fin" required
                                       min="<?= date('Y-m-d') ?>">
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group-modern">
                                <label for="hora_fin" class="form-label-modern">
                                    <i class="fas fa-clock"></i>
                                    Hora Fin
                                    <span class="required">*</span>
                                </label>
                                <input type="time" class="form-control-modern" id="hora_fin" 
                                       name="hora_fin" required>
                            </div>
                        </div>

                        <!-- Horas calculadas -->
                        <div class="col-md-12">
                            <div class="time-calculation" id="timeCalculation" style="display:none;">
                                <div class="calculation-icon">
                                    <i class="fas fa-hourglass-half"></i>
                                </div>
                                <div class="calculation-content">
                                    <strong>Duración del permiso:</strong>
                                    <span id="horasCalculadas" class="hours-badge">0 horas</span>
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

                        <!-- Observaciones -->
                        <div class="col-md-6">
                            <div class="form-group-modern">
                                <label for="descripcion" class="form-label-modern">
                                    <i class="fas fa-align-left"></i>
                                    Observaciones
                                </label>
                                <textarea class="form-control-modern" id="descripcion" name="descripcion" 
                                          rows="3" maxlength="300" 
                                          placeholder="Detalles adicionales (opcional)"></textarea>
                                <div class="char-counter">
                                    <span id="char-count">0</span> / 300 caracteres
                                </div>
                            </div>
                        </div>

                        <!-- Nota informativa -->
                        <div class="col-12">
                            <div class="info-box-special">
                                <div class="info-icon">
                                    <i class="fas fa-info-circle"></i>
                                </div>
                                <div class="info-content">
                                    <strong>Nota importante:</strong> Si su permiso es aprobado, no se generarán 
                                    inconsistencias de asistencia para las fechas y horarios del permiso.
                                </div>
                            </div>
                        </div>

                        <!-- Botones -->
                        <div class="col-12">
                            <div class="form-actions">
                                <button type="submit" name="enviar_permiso" class="btn btn-primary-modern">
                                    <i class="fas fa-paper-plane"></i>
                                    Enviar Solicitud
                                </button>
                                <button type="reset" class="btn btn-secondary-modern">
                                    <i class="fas fa-redo"></i>
                                    Limpiar Formulario
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Historial de Permisos -->
        <div class="card card-modern">
            <div class="card-header-modern">
                <div class="card-header-content">
                    <i class="fas fa-history"></i>
                    <h5>Historial de Permisos</h5>
                </div>
                <div class="card-header-actions">
                    <span class="badge badge-info-modern">
                        <?= count($historial) ?> registros
                    </span>
                </div>
            </div>
            <div class="card-body">
                <!-- Filtros -->
                <form method="GET" class="filter-form mb-4">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="estado" class="form-label-modern">
                                <i class="fas fa-filter"></i>
                                Estado
                            </label>
                            <select class="form-select-modern" id="estado" name="estado">
                                <option value="">Todos</option>
                                <option value="PENDIENTE" <?= $filtro_estado === 'PENDIENTE' ? 'selected' : '' ?>>
                                    Pendiente
                                </option>
                                <option value="APROBADO" <?= $filtro_estado === 'APROBADO' ? 'selected' : '' ?>>
                                    Aprobado
                                </option>
                                <option value="RECHAZADO" <?= $filtro_estado === 'RECHAZADO' ? 'selected' : '' ?>>
                                    Rechazado
                                </option>
                                <option value="CANCELADO" <?= $filtro_estado === 'CANCELADO' ? 'selected' : '' ?>>
                                    Cancelado
                                </option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="fecha_desde" class="form-label-modern">
                                <i class="fas fa-calendar-alt"></i>
                                Desde
                            </label>
                            <input type="date" class="form-control-modern" id="fecha_desde" name="fecha_desde" 
                                   value="<?= htmlspecialchars($filtro_fecha_desde) ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="fecha_hasta" class="form-label-modern">
                                <i class="fas fa-calendar-alt"></i>
                                Hasta
                            </label>
                            <input type="date" class="form-control-modern" id="fecha_hasta" name="fecha_hasta" 
                                   value="<?= htmlspecialchars($filtro_fecha_hasta) ?>">
                        </div>
                        <div class="col-md-3 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-primary-modern flex-fill">
                                <i class="fas fa-search"></i> Filtrar
                            </button>
                            <a href="solicitar-permiso.php" class="btn btn-secondary-modern">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                </form>

                <?php if (count($historial) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-calendar"></i> Fecha Solicitud</th>
                                    <th><i class="fas fa-calendar-week"></i> Período</th>
                                    <th><i class="fas fa-hourglass-half"></i> Horas</th>
                                    <th><i class="fas fa-tag"></i> Motivo</th>
                                    <th><i class="fas fa-circle"></i> Estado</th>
                                    <th><i class="fas fa-cog"></i> Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($historial as $perm): ?>
                                    <tr>
                                        <td>
                                            <div class="date-info">
                                                <strong><?= date('d/m/Y', strtotime($perm['fecha_solicitud'])) ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="period-info">
                                                <div class="period-line">
                                                    <i class="fas fa-play-circle text-success"></i>
                                                    <strong><?= date('d/m/Y', strtotime($perm['fecha_inicio'])) ?></strong>
                                                    <small><?= substr($perm['hora_inicio'], 0, 5) ?></small>
                                                </div>
                                                <div class="period-line">
                                                    <i class="fas fa-stop-circle text-danger"></i>
                                                    <strong><?= date('d/m/Y', strtotime($perm['fecha_fin'])) ?></strong>
                                                    <small><?= substr($perm['hora_fin'], 0, 5) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="hours-badge-table">
                                                <?= number_format($perm['horas_solicitadas'], 2) ?>h
                                            </span>
                                        </td>
                                        <td>
                                            <span class="motivo-badge">
                                                <?= htmlspecialchars($perm['nombre_motivo']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $estado = $perm['estado_permiso'];
                                            $badge_class = [
                                                'PENDIENTE' => 'badge-warning-modern',
                                                'APROBADO' => 'badge-success-modern',
                                                'RECHAZADO' => 'badge-danger-modern',
                                                'CANCELADO' => 'badge-secondary-modern'
                                            ];
                                            $icon_class = [
                                                'PENDIENTE' => 'fa-clock',
                                                'APROBADO' => 'fa-check-circle',
                                                'RECHAZADO' => 'fa-times-circle',
                                                'CANCELADO' => 'fa-ban'
                                            ];
                                            ?>
                                            <div class="estado-complete">
                                                <span class="badge <?= $badge_class[$estado] ?? 'badge-secondary-modern' ?>">
                                                    <i class="fas <?= $icon_class[$estado] ?? 'fa-question-circle' ?>"></i>
                                                    <?= $estado ?>
                                                </span>
                                                <?php if ($perm['fecha_respuesta']): ?>
                                                    <small class="estado-info">
                                                        <i class="fas fa-calendar-check"></i>
                                                        <?= date('d/m/Y', strtotime($perm['fecha_respuesta'])) ?>
                                                    </small>
                                                    <?php if ($perm['nombre_aprobador']): ?>
                                                        <small class="estado-info">
                                                            <i class="fas fa-user"></i>
                                                            <?= htmlspecialchars($perm['nombre_aprobador']) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <button type="button" 
                                                    class="btn btn-sm btn-info-modern"
                                                    onclick="abrirModal('<?= htmlspecialchars($perm['descripcion'] ?? 'Sin observaciones', ENT_QUOTES) ?>', '<?= htmlspecialchars($perm['comentarios_aprobacion'] ?? '', ENT_QUOTES) ?>')">
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
                        <h3>No hay permisos registrados</h3>
                        <p>No se encontraron permisos con los filtros seleccionados</p>
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
                    <i class="fas fa-info-circle"></i>
                    Detalle del Permiso
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Observaciones -->
                <div class="detail-section">
                    <label class="detail-label">
                        <i class="fas fa-comment"></i>
                        Tus Observaciones
                    </label>
                    <div class="detail-content" id="modal-descripcion"></div>
                </div>
                
                <!-- Comentarios del Aprobador -->
                <div class="detail-section" id="modal-comentarios-container" style="display:none;">
                    <div class="respuesta-header">
                        <i class="fas fa-reply"></i>
                        <strong>Respuesta de la Jefatura</strong>
                    </div>
                    <label class="detail-label">
                        <i class="fas fa-comment-dots"></i>
                        Comentarios del Aprobador
                    </label>
                    <div class="detail-content detail-content-highlight" id="modal-comentarios"></div>
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

.char-counter {
    text-align: right;
    color: #6b7280;
    font-size: 0.875rem;
    margin-top: 0.5rem;
}

.time-calculation {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border-radius: 8px;
    border-left: 4px solid var(--info);
    align-items: center;
}

.calculation-icon {
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

.calculation-content {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.calculation-content strong {
    color: #1e40af;
}

.hours-badge {
    background: white;
    color: var(--info);
    padding: 0.5rem 1rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 1.1rem;
}

.info-box-special {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border-radius: 8px;
    border-left: 4px solid var(--warning);
}

.info-icon {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    background: white;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--warning);
    font-size: 1.25rem;
}

.info-content {
    flex: 1;
    color: #92400e;
}

.info-content strong {
    color: #78350f;
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

.filter-form {
    padding: 1.5rem;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
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

.date-info strong {
    color: #1f2937;
    font-size: 0.95rem;
}

.period-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.period-line {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
}

.period-line strong {
    color: #1f2937;
}

.period-line small {
    color: #6b7280;
    background: #f3f4f6;
    padding: 0.1rem 0.4rem;
    border-radius: 4px;
}

.hours-badge-table {
    display: inline-block;
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.95rem;
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

.badge-secondary-modern {
    background: #f3f4f6;
    color: #6b7280;
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

.content-wrapper {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem;
    padding-bottom: 4rem;
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Función para abrir el modal
function abrirModal(descripcion, comentarios) {
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = descripcion;
    const descripcionDecoded = tempDiv.textContent;
    
    tempDiv.innerHTML = comentarios;
    const comentariosDecoded = tempDiv.textContent;
    
    document.getElementById('modal-descripcion').textContent = descripcionDecoded || 'Sin observaciones';
    
    const comentariosContainer = document.getElementById('modal-comentarios-container');
    if (comentariosDecoded && comentariosDecoded.trim() !== '') {
        document.getElementById('modal-comentarios').textContent = comentariosDecoded;
        comentariosContainer.style.display = 'block';
    } else {
        comentariosContainer.style.display = 'none';
    }
    
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

// Calcular horas del permiso
function calcularHoras() {
    const fechaInicio = document.getElementById('fecha_inicio').value;
    const horaInicio = document.getElementById('hora_inicio').value;
    const fechaFin = document.getElementById('fecha_fin').value;
    const horaFin = document.getElementById('hora_fin').value;
    
    if (fechaInicio && horaInicio && fechaFin && horaFin) {
        const inicio = new Date(fechaInicio + 'T' + horaInicio);
        const fin = new Date(fechaFin + 'T' + horaFin);
        
        const diffMs = fin - inicio;
        const diffHoras = diffMs / (1000 * 60 * 60);
        
        if (diffHoras > 0) {
            document.getElementById('timeCalculation').style.display = 'flex';
            document.getElementById('horasCalculadas').textContent = diffHoras.toFixed(2) + ' horas';
        } else {
            document.getElementById('timeCalculation').style.display = 'none';
        }
    }
}

// Event listeners para calcular horas
document.getElementById('fecha_inicio').addEventListener('change', calcularHoras);
document.getElementById('hora_inicio').addEventListener('change', calcularHoras);
document.getElementById('fecha_fin').addEventListener('change', calcularHoras);
document.getElementById('hora_fin').addEventListener('change', calcularHoras);

// Validación de fechas
document.getElementById('fecha_inicio').addEventListener('change', function() {
    document.getElementById('fecha_fin').min = this.value;
});

// Confirmación antes de limpiar formulario
document.querySelector('button[type="reset"]').addEventListener('click', function(e) {
    const form = document.getElementById('formPermiso');
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
        } else {
            document.getElementById('timeCalculation').style.display = 'none';
        }
    }
});
</script>

<?php
require_once 'templates/footer.php';
?>