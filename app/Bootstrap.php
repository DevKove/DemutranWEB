<?php
declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

final class Bootstrap
{
    private PDO $db;
    public function __construct(private readonly array $config)
    {
        $db = require BASE_PATH . '/config/database.php';
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
        $this->db = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function run(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = parse_url($this->config['url'], PHP_URL_PATH) ?: '';
        if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base)) ?: '/';
        $path = '/' . trim($path, '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($path === '/health') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'app' => $this->config['name'], 'database' => $this->db->query('SELECT 1')->fetchColumn() === 1]);
            return;
        }
        if ($path === '/login' && $method === 'POST') { $this->login(); return; }
        if ($path === '/logout' && $method === 'POST') {
            $this->csrf(); $_SESSION = []; session_regenerate_id(true); header('Location: ' . $this->url('/login')); return;
        }
        if (empty($_SESSION['user'])) { $this->render('auth/login', ['error' => null]); return; }

        $routes = [
            '/' => ['dashboard', 'dashboard.view', 'Início'],
            '/sinistro' => ['sinistros', 'sinistros.view', 'Sinistro'],
            '/escala' => ['escalas', 'escalas.view', 'Escala'],
            '/ordens' => ['ordens', 'ordens.view', 'Ordem de Serviço'],
            '/usuarios' => ['usuarios', 'users.view', 'Usuários'],
            '/adm' => ['admin', 'admin.view', 'ADM'],
            '/relatorios' => ['relatorios', 'reports.view', 'Relatórios'],
        ];
        if (!isset($routes[$path])) { http_response_code(404); $this->render('errors/404', []); return; }
        [$view, $permission, $title] = $routes[$path];
        if (!$this->allowed($permission)) { http_response_code(403); $this->render('errors/403', []); return; }
        $this->render('pages/' . $view, ['title' => $title, 'stats' => $this->stats()]);
    }

    private function login(): void
    {
        $this->csrf();
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');
        if (!$email || $password === '') { $this->render('auth/login', ['error' => 'Informe e-mail e senha.']); return; }
        $q = $this->db->prepare("SELECT u.id,u.name,u.email,u.password_hash,u.status,r.name role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.email=? LIMIT 1");
        $q->execute([$email]); $user = $q->fetch();
        if (!$user || $user['status'] !== 'Ativo' || !password_verify($password, $user['password_hash'])) {
            usleep(250000); $this->audit(null, 'auth.failed', 'auth', null); $this->render('auth/login', ['error' => 'Não foi possível autenticar. Verifique os dados ou contate o administrador.']); return;
        }
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role_name']];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $this->db->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);
        $this->audit((int)$user['id'], 'auth.login', 'auth', (int)$user['id']);
        header('Location: ' . $this->url('/')); exit;
    }

    private function allowed(string $permission): bool
    {
        if (($_SESSION['user']['role'] ?? '') === 'Administrador') return true;
        $q = $this->db->prepare("SELECT 1 FROM permissions p LEFT JOIN user_permissions up ON up.permission_id=p.id AND up.user_id=? LEFT JOIN role_permissions rp ON rp.permission_id=p.id LEFT JOIN users u ON u.role_id=rp.role_id AND u.id=? WHERE p.permission_key=? AND (up.user_id IS NOT NULL OR u.id IS NOT NULL) LIMIT 1");
        $q->execute([$_SESSION['user']['id'], $_SESSION['user']['id'], $permission]);
        return (bool)$q->fetchColumn();
    }
    private function csrf(): void
    {
        $token = $_POST['_csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) { http_response_code(419); exit('Sessão expirada. Atualize a página.'); }
    }
    private function stats(): array
    {
        $tables = ['users' => 'users', 'sinistros' => 'accidents', 'escalas' => 'shifts', 'ordens' => 'service_orders'];
        $out = [];
        foreach ($tables as $label => $table) {
            try { $out[$label] = (int)$this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(); }
            catch (Throwable) { $out[$label] = 0; }
        }
        return $out;
    }
    private function audit(?int $userId, string $action, string $module, ?int $recordId): void
    {
        try {
            $q = $this->db->prepare('INSERT INTO audit_logs (user_id,action,module,record_id,ip_address,created_at) VALUES (?,?,?,?,?,NOW())');
            $q->execute([$userId, $action, $module, $recordId, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Throwable) { /* Auditoria não deve expor detalhes internos ao cliente. */ }
    }
    private function render(string $view, array $data): void
    {
        extract($data, EXTR_SKIP);
        $app = $this->config;
        $user = $_SESSION['user'] ?? null;
        $csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        if (str_starts_with($view, 'auth/')) {
            require BASE_PATH . '/resources/views/' . $view . '.php';
            return;
        }
        $contentView = $view;
        $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = parse_url($this->config['url'], PHP_URL_PATH) ?: '';
        if ($base !== '' && str_starts_with($currentPath, $base)) $currentPath = substr($currentPath, strlen($base)) ?: '/';
        require BASE_PATH . '/resources/views/layout.php';
    }
    private function url(string $path): string { return ($this->config['url'] ?: '') . $path; }
}
