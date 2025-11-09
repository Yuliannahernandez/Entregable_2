<?php
// Exportar marcas a CSV
require_once 'includes/auth.php';
requireRole([3]);
require_once 'config/db.php'; // Aquí se define $pdo

$identificacion = $_SESSION['identificacion'];

// Parámetros de filtro
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';
$id_area = $_GET['id_area'] ?? '';

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

// Consulta SQL
$sql = "SELECT 
            m.fecha_marca, 
            m.hora_marca, 
            m.timestamp_servidor, 
            m.tipo_marca, 
            m.detalle, 
            a.nombre_area, 
            m.pais, 
            m.ciudad, 
            m.ip_origen
        FROM marcas m
        LEFT JOIN areas a ON m.id_area = a.id_area
        WHERE $where_clause
        ORDER BY m.timestamp_servidor DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Registrar en bitácora
$bitacora_sql = "INSERT INTO bitacoras 
(fecha_accion, identificacion_usuario, accion, tabla_afectada, descripcion_accion, ip_usuario, user_agent)
VALUES (NOW(), ?, 'EXPORTAR', 'marcas', ?, ?, ?)";
$descripcion = "Exportación de marcas a CSV. Filtros: " . json_encode([
    'fecha_inicio' => $fecha_inicio,
    'fecha_fin' => $fecha_fin,
    'id_area' => $id_area,
    'total_registros' => count($result)
]);
$ip = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];

$stmt_bitacora = $pdo->prepare($bitacora_sql);
$stmt_bitacora->execute([$identificacion, $descripcion, $ip, $user_agent]);

// Configurar headers para descarga CSV
$filename = "mis_marcas_" . date('Ymd_His') . ".csv";
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Crear archivo CSV
$output = fopen('php://output', 'w');

// Escribir BOM para UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Encabezados del CSV
fputcsv($output, [
    'Fecha',
    'Hora Marca',
    'Timestamp Servidor',
    'Tipo',
    'Área',
    'País',
    'Ciudad',
    'IP Origen',
    'Detalle'
], ';');

// Datos
foreach ($result as $row) {
    fputcsv($output, [
        date('d/m/Y', strtotime($row['fecha_marca'])),
        $row['hora_marca'],
        "'" . date('Y-m-d H:i:s', strtotime($row['timestamp_servidor'])), 
        $row['tipo_marca'],
        $row['nombre_area'] ?? 'N/A',
        $row['pais'] ?? 'N/A',
        $row['ciudad'] ?? 'N/A',
        $row['ip_origen'] ?? 'N/A',
        $row['detalle'] ?? ''
    ], ';');
}


fclose($output);
exit;
