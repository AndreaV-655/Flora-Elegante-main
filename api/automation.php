<?php
// Outbox transaccional: el pedido y sus mensajes se guardan juntos en PostgreSQL.
// Ninguna llamada externa se hace durante la compra; worker.py envía y reintenta.
require_once __DIR__ . '/db.php';
function automation_enqueue(PDO $db, string $key, string $channel, array $payload): void {
    $db->prepare('INSERT INTO fe_job (clave, canal, payload) VALUES (?, ?, ?) ON CONFLICT (clave) DO NOTHING')
       ->execute([$key, $channel, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
}

// Puente privado PHP -> FastAPI. El navegador nunca recibe el secreto interno.
function engine_request(string $path, array $payload): array {
    $token = getenv('ENGINE_TOKEN') ?: '';
    if (strlen($token) < 32 || !function_exists('curl_init')) {
        throw new RuntimeException('Motor no configurado');
    }
    $ch = curl_init(rtrim(getenv('ENGINE_URL') ?: 'http://127.0.0.1:8000', '/') . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 100]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $result = is_string($raw) ? json_decode($raw, true) : null;
    if ($code !== 200 || !is_array($result)) throw new RuntimeException('Motor temporalmente no disponible');
    return $result;
}
