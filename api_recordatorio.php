<?php
/**
 * VITALZOO - API INTERNA DE ENVÍO DE RECORDATORIOS (SIMULADO)
 * ---------------------------------------------------------------------------
 * - Despacha alertas a través de WhatsApp y Correo Electrónico.
 * - Simula el procesamiento y respuesta de un gateway SMS/Mailing real.
 */

require_once 'config/db.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Seguridad: Solo usuarios identificados
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'No autorizado. Inicie sesión.']);
    exit;
}

if (!isset($_GET['cita_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Falta el parámetro cita_id.']);
    exit;
}

$cita_id = intval($_GET['cita_id']);
$db = getDBConnection();

try {
    // Consultar datos de la cita y el paciente
    $stmt = $db->prepare("
        SELECT c.*, u.nombre as paciente, u.email as paciente_email, doc_u.nombre as doctor
        FROM citas c
        JOIN usuarios u ON c.cliente_id = u.id
        JOIN veterinarios v ON c.veterinario_id = v.id
        JOIN usuarios doc_u ON v.usuario_id = doc_u.id
        WHERE c.id = ?
    ");
    $stmt->execute([$cita_id]);
    $cita = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cita) {
        echo json_encode(['status' => 'error', 'message' => 'La cita no existe.']);
        exit;
    }

    $paciente = $cita['paciente'];
    $fecha = date('d/m/Y', strtotime($cita['fecha']));
    $hora = substr($cita['hora'], 0, 5);
    $tipo = $cita['tipo_cita'] == 'cita estetica' ? 'Sesión de Estética / Peluquería' : 'Consulta Médica Veterinaria';
    $doctor = $cita['doctor'];
    
    // Obtener método de contacto configurado en la cita
    $metodo = $cita['metodo_notificacion'] ? $cita['metodo_notificacion'] : 'WhatsApp';
    $contacto = $cita['contacto_notificacion'] ? $cita['contacto_notificacion'] : $cita['paciente_email'];

    // Redacción personalizada del mensaje
    $mensaje_notificacion = "Hola $paciente, recuerda tu cita de $tipo en VitalZoo para el día $fecha a las $hora hrs con el Dr. $doctor. Dirección: Calle 7 # 27A-09 Barrio Magdalena, Sogamoso.";

    // Simular el retardo de envío y respuesta de la puerta de enlace (Gateway)
    // En un entorno de producción real, aquí se llamaría a cURL hacia Twilio, Mailgun, etc.
    echo json_encode([
        'status' => 'success',
        'metodo' => $metodo,
        'destinatario' => $contacto,
        'timestamp' => time(),
        'mensaje_preview' => $mensaje_notificacion,
        'gateway_response' => 'API_DISPATCH_OK_200'
    ]);
    exit;

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error de servidor: ' . $e->getMessage()]);
    exit;
}