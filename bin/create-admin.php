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
    if (getenv(trim($key)) === false) putenv(trim($key) . '=' . trim(trim($value), chr(34) . chr(39)));
}
$db = require BASE_PATH . '/config/database.php';
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',$db['host'],$db['port'],$db['name'],$db['charset']),$db['user'],$db['pass'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$ask = static function(string $label): string { fwrite(STDOUT,$label); return trim((string)fgets(STDIN)); };
$name = $ask('Nome completo: ');
$email = filter_var($ask('E-mail institucional: '), FILTER_VALIDATE_EMAIL);
$registration = $ask('Matrícula (opcional): ') ?: null;
$password = $ask('Senha inicial (mínimo 12 caracteres): ');
if ($name === '' || !$email || strlen($password) < 12) { fwrite(STDERR,"Dados inválidos.\n"); exit(1); }

$pdo->beginTransaction();
try {
    $role = $pdo->prepare("SELECT id FROM roles WHERE name='Administrador' FOR UPDATE");
    $role->execute();
    $roleId = (int)$role->fetchColumn();
    $completed = (string)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='installation.completed' FOR UPDATE")->fetchColumn() ?: '');
    $exists = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if (!$roleId || $exists > 0 || $completed === '1') {
        throw new RuntimeException('Instalação concluída, perfil administrador ausente ou já existem usuários.');
    }
    $stmt = $pdo->prepare("INSERT INTO users(name,registration,email,role_id,status,password_hash,created_at) VALUES(?,?,?,?,'Ativo',?,NOW())");
    $stmt->execute([$name,$registration,$email,$roleId,password_hash($password,PASSWORD_DEFAULT)]);
    $userId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO system_settings(setting_key,setting_value,updated_at) VALUES('installation.completed','1',NOW()) ON DUPLICATE KEY UPDATE setting_value='1',updated_at=NOW()")->execute();
    $pdo->prepare("INSERT INTO audit_logs(user_id,action,module,record_id,created_at) VALUES(?, 'installation.admin_created','install',?,NOW())")->execute([$userId,$userId]);
    $pdo->commit();
    fwrite(STDOUT,"Administrador criado. Remova INSTALL_TOKEN do .env e exclua este script do servidor.\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,$e->getMessage()."\n");
    exit(1);
}
