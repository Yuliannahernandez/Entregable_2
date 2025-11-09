<?php
// generar-inconsistencias.php - HU#21 PROC1: Proceso de generación de inconsistencias
require_once 'includes/auth.php';
requireRole([1]); // Solo administradores

$identificacion = $_SESSION['identificacion'];
$pageTitle = "Generar Inconsistencias";

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

// Obtener parámetros configurables (simulados - deberían venir de BD)
$TOLERANCIA_MINUTOS = 10;
$TIPOS_EVALUAR = ['ATRASO', 'SALIDA_TEMPRANA', 'AUSENCIA', 'MARCA_FALTANTE'];

// Obtener áreas para el filtro
$sql_areas = "SELECT id_area, nombre_area FROM areas WHERE estado = 'ACTIVA' ORDER BY nombre_area";
$stmt_areas = $pdo->query($sql_areas);
$areas = $stmt_areas->fetchAll();

// Obtener funcionarios para el filtro
$sql_funcionarios = "SELECT identificacion, CONCAT(nombre, ' ', apellido) as nombre_completo 
                     FROM funcionarios WHERE estado = 'ACTIVO' ORDER BY nombre, apellido";
$stmt_funcionarios = $pdo->query($sql_funcionarios);
$funcionarios = $stmt_funcionarios->fetchAll();

// Procesar generación de inconsistencias
$resultado_proceso = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generar'])) {
    $fecha_inicio = $_POST['fecha_inicio'] ?? '';
    $fecha_fin = $_POST['fecha_fin'] ?? '';
    $id_area_filtro = $_POST['id_area'] ?? '';
    $identificacion_filtro = $_POST['identificacion_funcionario'] ?? '';
    
    $errors = [];
    
    // Validaciones
    if (empty($fecha_inicio)) {
        $errors[] = "La fecha de inicio es obligatoria";
    }
    if (empty($fecha_fin)) {
        $errors[] = "La fecha de fin es obligatoria";
    }
    if (!empty($fecha_inicio) && !empty($fecha_fin) && strtotime($fecha_inicio) > strtotime($fecha_fin)) {
        $errors[] = "La fecha de inicio no puede ser posterior a la fecha de fin";
    }
    
    if (empty($errors)) {
        $pdo->beginTransaction();
        
        try {
            // Construir filtros
            $where_conditions = ["f.estado = 'ACTIVO'"];
            $params = [];
            
            if (!empty($id_area_filtro)) {
                $where_conditions[] = "f.id_area = ?";
                $params[] = $id_area_filtro;
            }
            
            if (!empty($identificacion_filtro)) {
                $where_conditions[] = "f.identificacion = ?";
                $params[] = $identificacion_filtro;
            }
            
            $where_clause = implode(" AND ", $where_conditions);
            
            // Obtener funcionarios a evaluar
            $sql_funcionarios_eval = "SELECT f.identificacion, f.id_horario, f.id_area,
                                             CONCAT(f.nombre, ' ', f.apellido) as nombre_completo
                                      FROM funcionarios f
                                      WHERE $where_clause";
            $stmt_func = $pdo->prepare($sql_funcionarios_eval);
            $stmt_func->execute($params);
            $funcionarios_eval = $stmt_func->fetchAll();
            
            $inconsistencias_generadas = [];
            $contadores = [
                'ATRASO' => 0,
                'SALIDA_TEMPRANA' => 0,
                'AUSENCIA' => 0,
                'MARCA_FALTANTE' => 0
            ];
            $funcionarios_procesados = [];
            
            // Procesar cada funcionario
            foreach ($funcionarios_eval as $func) {
                $func_id = $func['identificacion'];
                $funcionarios_procesados[$func_id] = $func['nombre_completo'];
                
                // Iterar por cada día del rango
                $fecha_actual = new DateTime($fecha_inicio);
                $fecha_limite = new DateTime($fecha_fin);
                
                while ($fecha_actual <= $fecha_limite) {
                    $fecha_str = $fecha_actual->format('Y-m-d');
                    
                    // 1. Verificar si tiene vacaciones aprobadas ese día
                    $sql_vac = "SELECT COUNT(*) FROM vacaciones 
                                WHERE identificacion_funcionario = ? 
                                AND estado_vacacion = 'APROBADO'
                                AND ? BETWEEN fecha_inicio AND fecha_fin";
                    $stmt_vac = $pdo->prepare($sql_vac);
                    $stmt_vac->execute([$func_id, $fecha_str]);
                    $tiene_vacaciones = $stmt_vac->fetchColumn() > 0;
                    
                    // 2. Verificar si tiene permisos aprobados ese día
                    $sql_perm = "SELECT COUNT(*) FROM permisos 
                                 WHERE identificacion_funcionario = ? 
                                 AND estado_permiso = 'APROBADO'
                                 AND ? BETWEEN fecha_inicio AND fecha_fin";
                    $stmt_perm = $pdo->prepare($sql_perm);
                    $stmt_perm->execute([$func_id, $fecha_str]);
                    $tiene_permiso = $stmt_perm->fetchColumn() > 0;
                    
                    // Si tiene vacaciones o permiso aprobado, no generar inconsistencias
                    if ($tiene_vacaciones || $tiene_permiso) {
                        $fecha_actual->modify('+1 day');
                        continue;
                    }
                    
                    // 3. Obtener marcas del día
                    $sql_marcas = "SELECT hora_marca, tipo_marca 
                                   FROM marcas 
                                   WHERE identificacion_funcionario = ? 
                                   AND fecha_marca = ?
                                   ORDER BY hora_marca";
                    $stmt_marcas = $pdo->prepare($sql_marcas);
                    $stmt_marcas->execute([$func_id, $fecha_str]);
                    $marcas_dia = $stmt_marcas->fetchAll();
                    
                    // 4. Obtener horario del funcionario (simulado - debería venir de BD)
                    // Asumimos horario estándar: 08:00 - 17:00
                    $hora_entrada_esperada = '08:00:00';
                    $hora_salida_esperada = '17:00:00';
                    
                    // 5. Evaluar inconsistencias
                    if (count($marcas_dia) == 0) {
                        // AUSENCIA - sin marcas del día
                        $inconsistencias_generadas[] = [
                            'tipo' => 'AUSENCIA',
                            'funcionario' => $func_id,
                            'fecha' => $fecha_str,
                            'detalle' => 'No se registraron marcas durante el día'
                        ];
                        $contadores['AUSENCIA']++;
                        
                    } elseif (count($marcas_dia) == 1) {
                        // MARCA_FALTANTE - solo entrada o solo salida
                        $tipo_marca = $marcas_dia[0]['tipo_marca'];
                        $detalle = $tipo_marca === 'ENTRADA' ? 
                            'Falta marca de salida' : 'Falta marca de entrada';
                        
                        $inconsistencias_generadas[] = [
                            'tipo' => 'MARCA_FALTANTE',
                            'funcionario' => $func_id,
                            'fecha' => $fecha_str,
                            'detalle' => $detalle
                        ];
                        $contadores['MARCA_FALTANTE']++;
                        
                    } else {
                        // Tiene entrada y salida - evaluar atrasos y salidas tempranas
                        $entrada = null;
                        $salida = null;
                        
                        foreach ($marcas_dia as $marca) {
                            if ($marca['tipo_marca'] === 'ENTRADA' && $entrada === null) {
                                $entrada = $marca['hora_marca'];
                            } elseif ($marca['tipo_marca'] === 'SALIDA') {
                                $salida = $marca['hora_marca'];
                            }
                        }
                        
                        // Evaluar ATRASO
                        if ($entrada) {
                            $entrada_time = strtotime($entrada);
                            $esperada_time = strtotime($hora_entrada_esperada);
                            $diferencia_minutos = ($entrada_time - $esperada_time) / 60;
                            
                            if ($diferencia_minutos > $TOLERANCIA_MINUTOS) {
                                $inconsistencias_generadas[] = [
                                    'tipo' => 'ATRASO',
                                    'funcionario' => $func_id,
                                    'fecha' => $fecha_str,
                                    'detalle' => sprintf('Atraso de %.0f minutos (entrada: %s, esperada: %s)', 
                                                        $diferencia_minutos, 
                                                        substr($entrada, 0, 5), 
                                                        substr($hora_entrada_esperada, 0, 5))
                                ];
                                $contadores['ATRASO']++;
                            }
                        }
                        
                        // Evaluar SALIDA_TEMPRANA
                        if ($salida) {
                            $salida_time = strtotime($salida);
                            $esperada_time = strtotime($hora_salida_esperada);
                            $diferencia_minutos = ($esperada_time - $salida_time) / 60;
                            
                            if ($diferencia_minutos > $TOLERANCIA_MINUTOS) {
                                $inconsistencias_generadas[] = [
                                    'tipo' => 'SALIDA_TEMPRANA',
                                    'funcionario' => $func_id,
                                    'fecha' => $fecha_str,
                                    'detalle' => sprintf('Salida temprana de %.0f minutos (salida: %s, esperada: %s)', 
                                                        $diferencia_minutos, 
                                                        substr($salida, 0, 5), 
                                                        substr($hora_salida_esperada, 0, 5))
                                ];
                                $contadores['SALIDA_TEMPRANA']++;
                            }
                        }
                    }
                    
                    $fecha_actual->modify('+1 day');
                }
            }
            
            // Insertar inconsistencias en la base de datos
            if (count($inconsistencias_generadas) > 0) {
                // Obtener IDs de tipos de inconsistencia
                $sql_tipos = "SELECT id_tipo_inconsistencia, codigo FROM tipos_inconsistencia";
                $stmt_tipos = $pdo->query($sql_tipos);
                $tipos_map = [];
                while ($row = $stmt_tipos->fetch()) {
                    $tipos_map[$row['codigo']] = $row['id_tipo_inconsistencia'];
                }
                
                foreach ($inconsistencias_generadas as $inc) {
                    // Verificar si ya existe
                    $sql_check = "SELECT id_inconsistencia FROM inconsistencias 
                                  WHERE identificacion_funcionario = ? 
                                  AND fecha_inconsistencia = ? 
                                  AND id_tipo_inconsistencia = ?";
                    $stmt_check = $pdo->prepare($sql_check);
                    $id_tipo = $tipos_map[$inc['tipo']] ?? null;
                    
                    if ($id_tipo) {
                        $stmt_check->execute([$inc['funcionario'], $inc['fecha'], $id_tipo]);
                        
                        if ($stmt_check->rowCount() == 0) {
                            // No existe, insertar
                            $sql_insert = "INSERT INTO inconsistencias 
                                          (identificacion_funcionario, fecha_inconsistencia, 
                                           id_tipo_inconsistencia, detalle, estado_inconsistencia)
                                          VALUES (?, ?, ?, ?, 'NO_JUSTIFICADA')";
                            $stmt_insert = $pdo->prepare($sql_insert);
                            $stmt_insert->execute([
                                $inc['funcionario'],
                                $inc['fecha'],
                                $id_tipo,
                                $inc['detalle']
                            ]);
                        }
                    }
                }
            }
            
            // Registrar en bitácora
            $bitacora_desc = json_encode([
                'fecha_inicio' => $fecha_inicio,
                'fecha_fin' => $fecha_fin,
                'area_filtro' => $id_area_filtro ?: 'Todas',
                'funcionario_filtro' => $identificacion_filtro ?: 'Todos',
                'funcionarios_procesados' => count($funcionarios_procesados),
                'inconsistencias_detectadas' => count($inconsistencias_generadas),
                'contadores_por_tipo' => $contadores,
                'tolerancia_minutos' => $TOLERANCIA_MINUTOS
            ]);
            
            registrarBitacora($pdo, $identificacion, 'PROCESO', 'inconsistencias', 
                "Proceso de generación de inconsistencias ejecutado - " . $bitacora_desc);
            
            $pdo->commit();
            
            $resultado_proceso = [
                'success' => true,
                'total_inconsistencias' => count($inconsistencias_generadas),
                'funcionarios_procesados' => count($funcionarios_procesados),
                'contadores' => $contadores,
                'rango' => "$fecha_inicio - $fecha_fin"
            ];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            registrarBitacora($pdo, $identificacion, 'ERROR', 'inconsistencias', 
                "Error en proceso de generación: " . $e->getMessage());
            $errors[] = "Error al generar inconsistencias: " . $e->getMessage();
        }
    }
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

.alert-info {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
    border-left: 4px solid var(--info);
}

.info-banner {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    padding: 1.5rem;
    border-radius: var(--border-radius);
    border-left: 4px solid var(--warning);
    margin-bottom: 2rem;
}

.info-banner h4 {
    color: #92400e;
    margin: 0 0 0.5rem 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.info-banner ul {
    color: #78350f;
    margin: 0.5rem 0 0 1.5rem;
}

.config-card {
    background: white;
    padding: 1.5rem;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    margin-bottom: 2rem;
}

.config-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: var(--primary);
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e5e7eb;
}

.config-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.config-item {
    background: #f9fafb;
    padding: 1rem;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.config-item label {
    display: block;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
}

.config-item .value {
    color: var(--primary);
    font-size: 1.25rem;
    font-weight: 700;
}

.form-card {
    background: white;
    padding: 2rem;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-md);
    margin-bottom: 2rem;
}

.form-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: var(--primary);
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e5e7eb;
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

.required {
    color: var(--danger);
}

.btn-primary-modern {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    padding: 0.75rem 2rem;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    transition: all 0.3s ease;
    cursor: pointer;
    font-size: 1rem;
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
}

.results-card {
    background: white;
    padding: 2rem;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-md);
    margin-bottom: 2rem;
}

.results-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e5e7eb;
}

.results-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: var(--success);
    font-size: 1.25rem;
    font-weight: 600;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%);
    padding: 1.5rem;
    border-radius: var(--border-radius);
    border: 2px solid #e5e7eb;
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

.stat-icon.primary {
    background: linear-gradient(135deg, var(--primary-light) 0%, #dbeafe 100%);
    color: var(--primary);
}

.stat-icon.success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: var(--success);
}

.stat-icon.warning {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: var(--warning);
}

.stat-icon.danger {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: var(--danger);
}

.stat-icon.info {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: var(--info);
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

.detail-list {
    background: #f9fafb;
    padding: 1rem;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.detail-list-item {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem;
    border-bottom: 1px solid #e5e7eb;
}

.detail-list-item:last-child {
    border-bottom: none;
}

.detail-list-item strong {
    color: #374151;
}

.detail-list-item span {
    color: #6b7280;
}
</style>

<div class="page-container">
    <!-- Header -->
    <div class="page-header">
        <div class="header-content">
            <div class="header-icon">
                <i class="fas fa-cogs"></i>
            </div>
            <div>
                <h1 class="page-title">Generar Inconsistencias</h1>
                <p class="page-subtitle">Proceso automático de detección de inconsistencias de asistencia</p>
            </div>
        </div>
    </div>

    <!-- Mensajes -->
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

    <?php if ($resultado_proceso && $resultado_proceso['success']): ?>
        <div class="results-card">
            <div class="results-header">
                <div class="results-title">
                    <i class="fas fa-check-circle"></i>
                    Proceso Completado Exitosamente
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon success">
                        <i class="fas fa-check-double"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $resultado_proceso['total_inconsistencias'] ?></h3>
                        <p>Inconsistencias Detectadas</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $resultado_proceso['funcionarios_procesados'] ?></h3>
                        <p>Funcionarios Procesados</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon info">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-content">
                        <p style="font-weight: 600; font-size: 0.9rem; margin-bottom: 0.25rem;">Período</p>
                        <p style="font-size: 0.85rem;"><?= htmlspecialchars($resultado_proceso['rango']) ?></p>
                    </div>
                </div>
            </div>

            <h5 style="color: #374151; margin-bottom: 1rem;">
                <i class="fas fa-chart-bar"></i>
                Desglose por Tipo de Inconsistencia
            </h5>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon danger">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $resultado_proceso['contadores']['ATRASO'] ?></h3>
                        <p>Atrasos</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="fas fa-door-open"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $resultado_proceso['contadores']['SALIDA_TEMPRANA'] ?></h3>
                        <p>Salidas Tempranas</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon danger">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $resultado_proceso['contadores']['AUSENCIA'] ?></h3>
                        <p>Ausencias</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $resultado_proceso['contadores']['MARCA_FALTANTE'] ?></h3>
                        <p>Marcas Faltantes</p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Información del Proceso -->
    <div class="info-banner">
        <h4>
            <i class="fas fa-info-circle"></i>
            ¿Cómo funciona este proceso?
        </h4>
        <ul>
            <li>Compara las marcas registradas vs. los horarios asignados a cada funcionario</li>
            <li></li>