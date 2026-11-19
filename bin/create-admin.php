<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
$env = BASE_PATH . '/.env';
if (!is_file($env)) { fwrite(STDERR, "Arquivo .env não encontrado.\n"); exit(1); }
foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key,$value] = explode('=', $line, 2);
    putenv(trim($key) . '=' . trim(trim($value), chr(34) . chr(39)));
}
$db = require BASE_PATH . '/config/database.php';
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',$db['host'],$db['port'],$db['name'],$db['charset']),$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name = trim((string)readline('Nome completo: '));
$email = filter_var(trim((string)readline('E-mail institucional: ')), FILTER_VALIDATE_EMAIL);
$registration = trim((string)readline('Matrícula (opcional): ')) ?: null;
$password = (string)readline('Senha inicial (mínimo 12 caracteres): ');
if ($name === '' || !$email || strlen($password) < 12) { fwrite(STDERR,"Dados inválidos.\n"); exit(1); }
$exists = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($exists > 0) { fwrite(STDERR,"Já existem usuários. Operação bloqueada para evitar criação indevida.\n"); exit(1); }
$roleId = (int)$pdo->query("SELECT id FROM roles WHERE name='Administrador'")->fetchColumn();
$stmt = $pdo->prepare("INSERT INTO users(name,registration,email,role_id,status,password_hash,created_at) VALUES(?,?,?,?,'Ativo',?,NOW())");
$stmt->execute([$name,$registration,$email,$roleId,password_hash($password,PASSWORD_DEFAULT)]);
fwrite(STDOUT,"Administrador criado. Remova ou renomeie este script após o uso.\n");
