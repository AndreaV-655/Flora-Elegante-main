<?php
// CLI exclusivamente. La clave llega por entorno, nunca por argumentos.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/db.php';
$email = getenv('ADMIN_EMAIL') ?: '';
$pass = getenv('ADMIN_PASSWORD') ?: '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 12) {
    fwrite(STDERR, "Define ADMIN_EMAIL y ADMIN_PASSWORD (mínimo 12 caracteres).\n"); exit(1);
}
$db = db();
$db->exec("INSERT INTO rol (nombre) VALUES ('administrador') ON CONFLICT (nombre) DO NOTHING");
$db->prepare("INSERT INTO usuario (nombre,email,password_hash,rol_id) VALUES ('Administrador',?,?,(SELECT id FROM rol WHERE nombre='administrador'))")
    ->execute([$email, password_hash($pass, PASSWORD_DEFAULT)]);
echo "Administrador creado.\n";
