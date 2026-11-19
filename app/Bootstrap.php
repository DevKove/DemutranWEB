<?php
declare(strict_types=1);

namespace App;

use PDO;
use Throwable;
use RuntimeException;

final class Bootstrap
{
    private PDO $db;
    private string $baseUrl;
    private string $basePath = '';
    private int $uid = 0;
    private array $permissionCache = [];

    public function __construct(private readonly array $config)
    {
        $db = require BASE_PATH . '/config/database.php';
        if (!$db['name'] || !$db['user']) throw new RuntimeException('Configure DB_NAME e DB_USER no .env.');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
        $this->db = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $timezone=new \DateTimeZone($config['timezone']);
        $offset=(new \DateTimeImmutable('now',$timezone))->format('P');
        $this->db->exec('SET time_zone = '.$this->db->quote($offset));
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        if($config['url']!==''){
            $this->baseUrl=rtrim($config['url'],'/');
            $urlPath=parse_url($config['url'],PHP_URL_PATH);
            $this->basePath=rtrim(is_string($urlPath)?$urlPath:'','/');
        }else{
            $this->basePath=$scriptDir==='/'||$scriptDir==='.'?'':rtrim($scriptDir,'/');
            $this->baseUrl=$this->basePath;
        }
    }

    public function run(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($this->basePath !== '' && ($path===$this->basePath||str_starts_with($path,$this->basePath.'/'))) $path = substr($path, strlen($this->basePath)) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->uid = (int)($_SESSION['user']['id'] ?? 0);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')header('Strict-Transport-Security: max-age=31536000');

        if ($path === '/termos-de-uso' && $method === 'GET') { $this->render('legal/termos', ['title'=>'Termos de uso']); return; }
        if ($path === '/politica-de-privacidade' && $method === 'GET') { $this->render('legal/privacidade', ['title'=>'Política de privacidade']); return; }
        if ($path === '/health') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true,'database'=>$this->db->query('SELECT 1')->fetchColumn() == 1], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($path === '/install') {
            if ($method === 'POST') { $this->csrf(); $this->installFirstAdmin(); }
            $this->installPage();
            return;
        }
        if ($path === '/login' && $method === 'POST') { $this->login(); return; }
        if ($path === '/logout' && $method === 'POST') { $this->csrf(); $this->logout(); return; }
        if ($path === '/download' && $method === 'GET') { $this->download(); return; }
        if ($path === '/relatorios/imprimir' && $method === 'GET') { $this->printReport(); return; }
        if (!$this->uid) { $this->render('auth/login', ['error'=>null]); return; }
        $last = (int)($_SESSION['last_activity'] ?? time());
        if (time() - $last > max(5,(int)$this->config['session_lifetime'])*60) { $this->logout(false); return; }
        $_SESSION['last_activity'] = time();

        if ($method === 'POST') {
            $this->csrf();
            $handlers = [
                '/usuarios/salvar'=>'saveUser','/usuarios/status'=>'changeUserStatus','/usuarios/permissoes'=>'saveUserPermissions',
                '/adm/perfil'=>'saveRole','/adm/config'=>'saveSettings',
                '/sinistro/salvar'=>'saveAccident','/escala/salvar'=>'saveShift','/escala/acao'=>'shiftAction',
                '/ordens/salvar'=>'saveOrder','/ordens/atualizacao'=>'addOrderUpdate','/ordens/status'=>'changeOrderStatus',
                '/notificacoes/ler'=>'readNotification',
            ];
            if (isset($handlers[$path])) { $this->{$handlers[$path]}(); return; }
        }

        if (preg_match('#^/(sinistro|escala|ordens)/([0-9]+)$#', $path, $m)) {
            $module = $m[1]; $id = (int)$m[2];
            $this->showRecord($module, $id); return;
        }
        if ($path === '/relatorios/exportar') { $this->exportReport(); return; }

        $routes = [
            '/' => ['dashboard','dashboard.view','Início'],
            '/sinistro' => ['sinistros','sinistros.view','Sinistro'],
            '/escala' => ['escalas','escalas.view','Escala'],
            '/ordens' => ['ordens','ordens.view','Ordem de Serviço'],
            '/usuarios' => ['usuarios','users.view','Usuários'],
            '/adm' => ['admin','admin.view','ADM'],
            '/relatorios' => ['relatorios','reports.view','Relatórios'],
        ];
        if (!isset($routes[$path])) { http_response_code(404); $this->render('errors/404',[]); return; }
        [$view,$permission,$title] = $routes[$path];
        if (!$this->allowed($permission)) { http_response_code(403); $this->render('errors/403',[]); return; }
        $data = ['title'=>$title,'stats'=>$this->stats()];
        if ($view === 'dashboard') $data += $this->dashboardData();
        if ($view === 'usuarios') $data += $this->usersData();
        if ($view === 'admin') $data += $this->adminData();
        if ($view === 'sinistros') $data += $this->accidentsData();
        if ($view === 'escalas') $data += $this->shiftsData();
        if ($view === 'ordens') $data += $this->ordersData();
        if ($view === 'relatorios') $data += $this->reportsData();
        $this->render('pages/'.$view,$data);
    }

    private function installPage(): void
    {
        $token=(string)(getenv('INSTALL_TOKEN')?:'');
        $count=(int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $completed=(string)($this->db->query("SELECT setting_value FROM system_settings WHERE setting_key='installation.completed'")->fetchColumn()?:'');
        if ($token===''||$count>0||$completed==='1') { http_response_code(404); $this->render('errors/404',[]); return; }
        $this->render('install/setup',['title'=>'Configuração inicial']);
    }

    private function installFirstAdmin(): void
    {
        $token=(string)(getenv('INSTALL_TOKEN')?:'');
        $provided=(string)($_POST['install_token']??'');
        $name=trim((string)($_POST['name']??''));
        $email=filter_var(trim((string)($_POST['email']??'')),FILTER_VALIDATE_EMAIL);
        $registration=trim((string)($_POST['registration']??''))?:null;
        $password=(string)($_POST['password']??'');
        if($token===''||!hash_equals($token,$provided)) { usleep(250000); http_response_code(403); exit('Chave de instalação inválida.'); }
        if($name===''||!$email||strlen($password)<12) $this->fail('/install','Informe nome, e-mail válido e senha com pelo menos 12 caracteres.');
        $this->db->beginTransaction();
        try {
            $role=$this->db->prepare("SELECT id FROM roles WHERE name='Administrador' FOR UPDATE");$role->execute();$roleId=(int)$role->fetchColumn();
            $completed=(string)($this->db->query("SELECT setting_value FROM system_settings WHERE setting_key='installation.completed' FOR UPDATE")->fetchColumn()?:'');
            if(!$roleId||(int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn()>0||$completed==='1') throw new RuntimeException('A instalação já foi concluída ou o perfil administrador não existe.');
            $q=$this->db->prepare("INSERT INTO users(name,registration,email,role_id,status,password_hash,created_at) VALUES(?,?,?,?,'Ativo',?,NOW())");
            $q->execute([$name,$registration,$email,$roleId,password_hash($password,PASSWORD_DEFAULT)]);
            $id=(int)$this->db->lastInsertId();
            $this->db->prepare("INSERT INTO system_settings(setting_key,setting_value,updated_at) VALUES('installation.completed','1',NOW()) ON DUPLICATE KEY UPDATE setting_value='1',updated_at=NOW()")->execute();
            $this->db->commit();
            session_regenerate_id(true);
            $_SESSION['user']=['id'=>$id,'name'=>$name,'email'=>$email,'role_id'=>$roleId];
            $_SESSION['last_activity']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));$this->uid=$id;
            $this->audit($id,'installation.admin_created','install',$id);
            $this->flash('success','Administrador inicial criado. Remova INSTALL_TOKEN do arquivo .env.');
            $this->redirect('/');
        } catch(Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            $this->log($e);
            $this->fail('/install',$e instanceof RuntimeException?$e->getMessage():'Não foi possível concluir a instalação.');
        }
    }

    private function login(): void
    {
        $this->csrf();
        $email = filter_var(trim((string)($_POST['email']??'')),FILTER_VALIDATE_EMAIL);
        $password = (string)($_POST['password']??'');
        if (!$email || $password === '') { $this->render('auth/login',['error'=>'Informe e-mail e senha.']); return; }
        $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
        $attempts=$this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE email=? AND ip_address=? AND attempted_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)');
        $attempts->execute([$email,$ip]);
        if((int)$attempts->fetchColumn()>=5){usleep(300000);$this->render('auth/login',['error'=>'Não foi possível autenticar neste momento. Aguarde alguns minutos e tente novamente.']);return;}
        $q=$this->db->prepare('SELECT id,name,email,password_hash,status,role_id FROM users WHERE email=? LIMIT 1');
        $q->execute([$email]); $u=$q->fetch();
        $valid=$u && $u['status']==='Ativo' && password_verify($password,$u['password_hash']);
        if (!$valid) {
            usleep(300000);
            $this->db->prepare('INSERT INTO login_attempts(email,ip_address,attempted_at) VALUES(?,?,NOW())')->execute([$email,$ip]);
            $this->audit(null,'auth.failed','auth',null);
            $this->render('auth/login',['error'=>'Não foi possível autenticar. Verifique os dados ou contate o administrador.']); return;
        }
        session_regenerate_id(true);
        $_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role_id'=>(int)$u['role_id']];
        $_SESSION['last_activity']=time(); $_SESSION['csrf']=bin2hex(random_bytes(32));
        $this->uid=(int)$u['id'];
        $this->db->prepare('DELETE FROM login_attempts WHERE email=? AND ip_address=?')->execute([$email,$ip]);
        $this->db->prepare('DELETE FROM login_attempts WHERE attempted_at<DATE_SUB(NOW(),INTERVAL 30 DAY)')->execute();
        $this->db->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$this->uid]);
        $this->audit($this->uid,'auth.login','auth',$this->uid);
        $this->redirect('/');
    }

    private function logout(bool $audit=true): void
    {
        if ($audit && $this->uid) $this->audit($this->uid,'auth.logout','auth',$this->uid);
        $_SESSION=[]; session_regenerate_id(true);
        $this->redirect('/login');
    }

    private function allowed(string $key): bool
    {
        if (!$this->uid) return false;
        if(array_key_exists($key,$this->permissionCache))return $this->permissionCache[$key];
        $sql='SELECT CASE WHEN up.user_id IS NOT NULL THEN up.is_allowed ELSE EXISTS(
              SELECT 1 FROM role_permissions rp JOIN users ru ON ru.role_id=rp.role_id
              WHERE rp.permission_id=p.id AND ru.id=?
              ) END AS allowed
              FROM permissions p LEFT JOIN user_permissions up ON up.permission_id=p.id AND up.user_id=?
              WHERE p.permission_key=? LIMIT 1';
        $q=$this->db->prepare($sql);$q->execute([$this->uid,$this->uid,$key]);
        return $this->permissionCache[$key]=(bool)$q->fetchColumn();
    }

    private function requirePermission(string $key): void
    {
        if (!$this->allowed($key)) { http_response_code(403); exit('Acesso não autorizado.'); }
    }

    private function csrf(): void
    {
        $token=$_POST['_csrf']??'';
        if (!is_string($token)||!hash_equals($_SESSION['csrf']??'', $token)) {
            http_response_code(419); exit('Sessão expirada. Atualize a página e tente novamente.');
        }
    }

    private function usersData(): array
    {
        $page=max(1,(int)($_GET['page']??1)); $search=trim((string)($_GET['q']??'')); $status=(string)($_GET['status']??'');
        $where=[];$params=[];
        if($search!==''){$where[]='(u.name LIKE ? OR u.email LIKE ? OR u.registration LIKE ?)';array_push($params,"%$search%","%$search%","%$search%");}
        if(in_array($status,['Ativo','Afastado','Inativo'],true)){$where[]='u.status=?';$params[]=$status;}
        $clause=$where?' WHERE '.implode(' AND ',$where):'';
        $count=$this->db->prepare('SELECT COUNT(*) FROM users u'.$clause);$count->execute($params);$total=(int)$count->fetchColumn();
        $q=$this->db->prepare('SELECT u.id,u.name,u.registration,u.email,u.phone,u.photo_path,u.position,u.department,u.status,u.last_login_at,u.role_id,r.name role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id'.$clause.' ORDER BY u.name LIMIT 25 OFFSET '.(($page-1)*25));
        $q->execute($params);
        $userEdit=null;$editId=(int)($_GET['edit']??0);if($editId){$ed=$this->db->prepare('SELECT id,name,registration,email,phone,photo_path,position,department,role_id,status FROM users WHERE id=?');$ed->execute([$editId]);$userEdit=$ed->fetch()?:null;}
        $permissionUser=null;$permissionMap=[];$permissionId=(int)($_GET['permissions']??0);if($permissionId&&$this->allowed('users.permissions')){$ed=$this->db->prepare('SELECT id,name FROM users WHERE id=?');$ed->execute([$permissionId]);$permissionUser=$ed->fetch()?:null;$ed=$this->db->prepare('SELECT p.permission_key,up.is_allowed FROM user_permissions up JOIN permissions p ON p.id=up.permission_id WHERE up.user_id=?');$ed->execute([$permissionId]);foreach($ed->fetchAll() as $row)$permissionMap[$row['permission_key']]=(int)$row['is_allowed'];}
        return ['users'=>$q->fetchAll(),'roles'=>$this->db->query('SELECT id,name FROM roles ORDER BY name')->fetchAll(),'total'=>$total,'page'=>$page,'search'=>$search,'statusFilter'=>$status,'canManageUsers'=>$this->allowed('users.manage'),'canUserPermissions'=>$this->allowed('users.permissions'),'userEdit'=>$userEdit,'permissionUser'=>$permissionUser,'userPermissionMap'=>$permissionMap,'permissionCatalog'=>$this->db->query('SELECT permission_key,module,label FROM permissions ORDER BY module,label')->fetchAll()];
    }

    private function saveUser(): void
    {
        $this->requirePermission('users.manage');
        $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$email=filter_var(trim((string)($_POST['email']??'')),FILTER_VALIDATE_EMAIL);
        $registration=trim((string)($_POST['registration']??''))?:null;$phone=trim((string)($_POST['phone']??''))?:null;
        $position=trim((string)($_POST['position']??''))?:null;$department=trim((string)($_POST['department']??''))?:null;
        $role=(int)($_POST['role_id']??0);$password=(string)($_POST['password']??'');
        if($name===''||!$email||!$role||($id===0&&strlen($password)<12)||($password!==''&&strlen($password)<12))$this->fail('/usuarios','Confira os campos obrigatórios e a senha (mínimo 12 caracteres).');
        $roleCheck=$this->db->prepare('SELECT id FROM roles WHERE id=?');$roleCheck->execute([$role]);if(!$roleCheck->fetchColumn())$this->fail('/usuarios','Perfil de acesso inválido.');
        if($id){$userCheck=$this->db->prepare('SELECT id FROM users WHERE id=?');$userCheck->execute([$id]);if(!$userCheck->fetchColumn())$this->fail('/usuarios','Usuário não encontrado.');}
        if($id===$this->uid){$q=$this->db->prepare("SELECT EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.permission_key='admin.manage')");$q->execute([$role]);if(!(bool)$q->fetchColumn())$this->fail('/usuarios','Sua conta precisa permanecer vinculada a um perfil administrador.');}
        $this->db->beginTransaction();
        try {
            if($id){
                $q=$this->db->prepare('UPDATE users SET name=?,registration=?,email=?,phone=?,position=?,department=?,role_id=? WHERE id=?');
                $q->execute([$name,$registration,$email,$phone,$position,$department,$role,$id]);
                if($password!=='')$this->db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
                $action='user.updated';
            }else{
                $q=$this->db->prepare("INSERT INTO users(name,registration,email,phone,position,department,role_id,status,password_hash,created_by,created_at) VALUES(?,?,?,?,?,?,?,'Ativo',?,?,NOW())");
                $q->execute([$name,$registration,$email,$phone,$position,$department,$role,password_hash($password,PASSWORD_DEFAULT),$this->uid]);$id=(int)$this->db->lastInsertId();$action='user.created';
            }
            if(isset($_FILES['photo'])&&$_FILES['photo']['error']!==UPLOAD_ERR_NO_FILE)$this->saveAttachment('user',$id,'photo',true);
            $this->db->commit();$this->audit($this->uid,$action,'usuarios',$id);$this->flash('success','Usuário salvo com sucesso.');
        }catch(Throwable $e){$this->db->rollBack();$this->log($e);$this->flash('error','Não foi possível salvar. Verifique e-mail, matrícula e arquivo enviado.');}
        $this->redirect('/usuarios');
    }

    private function changeUserStatus(): void
    {
        $this->requirePermission('users.manage');$id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');
        if($id===$this->uid||!in_array($status,['Ativo','Afastado','Inativo'],true))$this->fail('/usuarios','Situação inválida ou operação não permitida.');
        $exists=$this->db->prepare('SELECT id FROM users WHERE id=?');$exists->execute([$id]);if(!$exists->fetchColumn())$this->fail('/usuarios','Usuário não encontrado.');
        if($status!=='Ativo'){
            $admin=$this->db->prepare("SELECT CASE WHEN up.user_id IS NOT NULL THEN up.is_allowed ELSE (rp.permission_id IS NOT NULL) END FROM users u JOIN permissions p ON p.permission_key='admin.manage' LEFT JOIN user_permissions up ON up.user_id=u.id AND up.permission_id=p.id LEFT JOIN role_permissions rp ON rp.role_id=u.role_id AND rp.permission_id=p.id WHERE u.id=? AND u.status='Ativo'");
            $admin->execute([$id]);$isAdmin=(bool)$admin->fetchColumn();
            if($isAdmin){
                $count=(int)$this->db->query("SELECT COUNT(DISTINCT u.id) FROM users u JOIN permissions p ON p.permission_key='admin.manage' LEFT JOIN user_permissions up ON up.user_id=u.id AND up.permission_id=p.id LEFT JOIN role_permissions rp ON rp.role_id=u.role_id AND rp.permission_id=p.id WHERE u.status='Ativo' AND (CASE WHEN up.user_id IS NOT NULL THEN up.is_allowed ELSE (rp.permission_id IS NOT NULL) END)=1")->fetchColumn();
                if($count<=1)$this->fail('/usuarios','Não é permitido inativar ou afastar o último administrador ativo.');
            }
        }
        $q=$this->db->prepare('UPDATE users SET status=? WHERE id=?');$q->execute([$status,$id]);
        $this->audit($this->uid,'user.status_changed','usuarios',$id);$this->flash('success','Situação atualizada.');$this->redirect('/usuarios');
    }

    private function saveUserPermissions(): void
    {
        $this->requirePermission('users.permissions');$id=(int)($_POST['user_id']??0);
        $overrides=(array)($_POST['permission_override']??[]);
        $exists=$this->db->prepare('SELECT id FROM users WHERE id=?');$exists->execute([$id]);if(!$exists->fetchColumn())$this->fail('/usuarios','Usuário não encontrado.');
        if($id===$this->uid){
            $q=$this->db->prepare("SELECT EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=u.role_id AND p.permission_key='admin.manage') FROM users u WHERE u.id=?");
            $q->execute([$id]);$roleAllows=(bool)$q->fetchColumn();
            $effective=array_key_exists('admin.manage',$overrides)&&in_array((string)$overrides['admin.manage'],['0','1'],true)?(string)$overrides['admin.manage']==='1':$roleAllows;
            if(!$effective)$this->fail('/usuarios','Não é permitido remover sua própria permissão de administração.');
        }
        $this->db->beginTransaction();
        try{
            $this->db->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$id]);
            $ins=$this->db->prepare('INSERT INTO user_permissions(user_id,permission_id,is_allowed) VALUES(?,?,?)');
            $find=$this->db->prepare('SELECT id FROM permissions WHERE permission_key=?');
            foreach($overrides as $key=>$value){if(!is_string($key)||!in_array((string)$value,['0','1'],true))continue;$find->execute([$key]);$pid=$find->fetchColumn();if($pid)$ins->execute([$id,$pid,(int)$value]);}
            $this->db->commit();$this->audit($this->uid,'user.permissions_changed','usuarios',$id);$this->flash('success','Permissões individuais atualizadas.');
        }catch(Throwable $e){$this->db->rollBack();$this->log($e);$this->flash('error','Falha ao atualizar permissões.');}
        $this->redirect('/usuarios');
    }

    private function adminData(): array
    {
        $roles=$this->db->query('SELECT r.id,r.name,r.description,COUNT(rp.permission_id) permission_count FROM roles r LEFT JOIN role_permissions rp ON rp.role_id=r.id GROUP BY r.id ORDER BY r.name')->fetchAll();
        $permissions=$this->db->query('SELECT id,permission_key,module,label FROM permissions ORDER BY module,label')->fetchAll();
        $rolePermissions=[];foreach($this->db->query('SELECT role_id,permission_id FROM role_permissions')->fetchAll() as $x)$rolePermissions[$x['role_id']][]=(int)$x['permission_id'];
        $settings=$this->db->query('SELECT setting_key,setting_value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $audit=$this->db->query('SELECT a.id,a.action,a.module,a.record_id,a.ip_address,a.created_at,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100')->fetchAll();
        return compact('roles','permissions','rolePermissions','settings','audit');
    }

    private function saveRole(): void
    {
        $this->requirePermission('admin.manage');$id=(int)($_POST['role_id']??0);$name=trim((string)($_POST['name']??''));$description=trim((string)($_POST['description']??''));$ids=array_unique(array_map('intval',(array)($_POST['permission_ids']??[])));
        if($name==='')$this->fail('/adm','Informe o nome do perfil.');
        if($id===(int)($_SESSION['user']['role_id']??0)){$q=$this->db->prepare("SELECT id FROM permissions WHERE permission_key='admin.manage'");$q->execute();$adminPermission=(int)$q->fetchColumn();if(!$adminPermission||!in_array($adminPermission,$ids,true))$this->fail('/adm','O perfil da sua conta precisa manter a permissão de administração.');}
        $this->db->beginTransaction();
        try{
            if($id){$this->db->prepare('UPDATE roles SET name=?,description=? WHERE id=?')->execute([$name,$description,$id]);}
            else{$this->db->prepare('INSERT INTO roles(name,description) VALUES(?,?)')->execute([$name,$description]);$id=(int)$this->db->lastInsertId();}
            $this->db->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$id]);
            $ins=$this->db->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)');foreach($ids as $pid)$ins->execute([$id,$pid]);
            $this->db->commit();$this->audit($this->uid,'role.saved','adm',$id);$this->flash('success','Perfil e permissões salvos.');
        }catch(Throwable $e){$this->db->rollBack();$this->log($e);$this->flash('error','Não foi possível salvar o perfil.');}
        $this->redirect('/adm');
    }

    private function saveSettings(): void
    {
        $this->requirePermission('admin.manage');
        $allowed=['institution_name','department_name','contact_email','contact_phone','protocol_prefix','upload_max_mb'];
        $values=[];
        foreach($allowed as $key){
            $value=trim((string)($_POST[$key]??''));
            if($key==='upload_max_mb')$value=(string)max(1,min(20,(int)$value));
            if($key==='contact_email'&&$value!==''&&!filter_var($value,FILTER_VALIDATE_EMAIL))$this->fail('/adm','E-mail institucional inválido.');
            if($key==='protocol_prefix'){$value=strtoupper($value);if(!preg_match('/^[A-Z0-9-]{2,12}$/',$value))$this->fail('/adm','O prefixo deve conter de 2 a 12 letras, números ou hífens.');}
            $values[$key]=$value;
        }
        $q=$this->db->prepare('INSERT INTO system_settings(setting_key,setting_value,updated_by,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=NOW()');
        foreach($values as $key=>$value)$q->execute([$key,$value,$this->uid]);
        if(isset($_FILES['logo'])&&$_FILES['logo']['error']!==UPLOAD_ERR_NO_FILE)$this->saveAttachment('branding',1,'logo',true);
        $this->audit($this->uid,'settings.updated','adm',null);$this->flash('success','Configurações atualizadas.');$this->redirect('/adm');
    }

    private function accidentsData(): array
    {
        $q=trim((string)($_GET['q']??''));$type=trim((string)($_GET['type']??''));$from=trim((string)($_GET['from']??''));$to=trim((string)($_GET['to']??''));$victims=(string)($_GET['victims']??'');$fatalities=(string)($_GET['fatalities']??'');$support=(string)($_GET['support']??'');
        $w=[];$p=[];if($q!==''){$w[]='(a.protocol LIKE ? OR a.location LIKE ? OR a.neighborhood LIKE ?)';array_push($p,"%$q%","%$q%","%$q%");}
        if($type!==''){$w[]='a.accident_type=?';$p[]=$type;}if($from!==''&&preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$from)){$w[]='DATE(a.occurred_at)>=?';$p[]=$from;}if($to!==''&&preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$to)){$w[]='DATE(a.occurred_at)<=?';$p[]=$to;}
        if(in_array($victims,['yes','no'],true)){$w[]='a.has_victims=?';$p[]=$victims==='yes'?1:0;}
        if(in_array($fatalities,['yes','no'],true)){$w[]='a.has_fatalities=?';$p[]=$fatalities==='yes'?1:0;}
        if(in_array($support,['yes','no'],true)){$w[]='a.needs_external_support=?';$p[]=$support==='yes'?1:0;}
        $sql='SELECT a.id,a.protocol,a.occurred_at,a.location,a.neighborhood,a.accident_type,a.status,u.name creator FROM accidents a JOIN users u ON u.id=a.created_by'.($w?' WHERE '.implode(' AND ',$w):'').' ORDER BY a.occurred_at DESC LIMIT 100';
        $st=$this->db->prepare($sql);$st->execute($p);
        $editAccident=null;$editId=(int)($_GET['edit']??0);
        if($editId&&$this->allowed('sinistros.edit')){$ed=$this->db->prepare('SELECT * FROM accidents WHERE id=?');$ed->execute([$editId]);$editAccident=$ed->fetch()?:null;if($editAccident){foreach(['vehicles'=>'accident_vehicles','people'=>'accident_people','witnesses'=>'accident_witnesses'] as $key=>$table){$ed=$this->db->prepare("SELECT * FROM $table WHERE accident_id=?");$ed->execute([$editId]);$editAccident[$key]=$ed->fetchAll();}$ed=$this->db->prepare('SELECT user_id FROM accident_staff WHERE accident_id=?');$ed->execute([$editId]);$editAccident['staff']=array_map('intval',$ed->fetchAll(PDO::FETCH_COLUMN));}}
        return ['accidents'=>$st->fetchAll(),'staff'=>$this->db->query("SELECT id,name FROM users WHERE status='Ativo' ORDER BY name")->fetchAll(),'filters'=>compact('q','type','from','to','victims','fatalities','support'),'canCreate'=>$this->allowed('sinistros.create'),'canEdit'=>$this->allowed('sinistros.edit'),'editAccident'=>$editAccident];
    }

    private function saveAccident(): void
    {
        $id=(int)($_POST['id']??0);$this->requirePermission($id?'sinistros.edit':'sinistros.create');
        $date=(string)($_POST['occurred_date']??'');$time=(string)($_POST['occurred_time']??'');
        $location=trim((string)($_POST['location']??''));$type=trim((string)($_POST['accident_type']??''));$desc=trim((string)($_POST['description']??''));
        $parsedDate=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsedDate||$parsedDate->format('Y-m-d')!==$date||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time)||$location===''||$type===''||$desc==='')$this->fail('/sinistro','Preencha data, horário, local, classificação e descrição válidos.');
        $obsoleteVehicleFiles=[];$existingVehicleIds=[];$retainedVehicleIds=[];
        $this->db->beginTransaction();
        try{
            if($id){
                $exists=$this->db->prepare('SELECT id FROM accidents WHERE id=? FOR UPDATE');$exists->execute([$id]);if(!$exists->fetchColumn())throw new RuntimeException('Sinistro não encontrado.');
                $vehicleIds=$this->db->prepare('SELECT id FROM accident_vehicles WHERE accident_id=?');$vehicleIds->execute([$id]);$existingVehicleIds=array_map('intval',$vehicleIds->fetchAll(PDO::FETCH_COLUMN));
                $this->db->prepare('UPDATE accidents SET occurred_at=?,location=?,address=?,neighborhood=?,reference_point=?,road_type=?,weather=?,accident_type=?,description=?,has_victims=?,has_injuries=?,has_fatalities=?,public_property_damage=?,signage_damage=?,needs_external_support=?,external_support_notes=?,notes=?,updated_at=NOW() WHERE id=?')
                ->execute([$date.' '.$time.':00',$location,$_POST['address']??null,$_POST['neighborhood']??null,$_POST['reference_point']??null,$_POST['road_type']??null,$_POST['weather']??null,$type,$desc,isset($_POST['has_victims'])?1:0,isset($_POST['has_injuries'])?1:0,isset($_POST['has_fatalities'])?1:0,isset($_POST['public_property_damage'])?1:0,isset($_POST['signage_damage'])?1:0,isset($_POST['needs_external_support'])?1:0,$_POST['external_support_notes']??null,$_POST['notes']??null,$id]);
                $this->db->prepare('DELETE FROM accident_staff WHERE accident_id=?')->execute([$id]);
                $this->db->prepare('DELETE FROM accident_people WHERE accident_id=?')->execute([$id]);
                $this->db->prepare('DELETE FROM accident_witnesses WHERE accident_id=?')->execute([$id]);
                $action='accident.updated';
            }else{
                $prefix=$this->setting('protocol_prefix','SIN');
                $this->db->exec("INSERT INTO sequences(sequence_key,next_value,updated_at) VALUES('accident',1,NOW()) ON DUPLICATE KEY UPDATE next_value=next_value");
                $seq=$this->db->query("SELECT next_value FROM sequences WHERE sequence_key='accident' FOR UPDATE")->fetchColumn();
                $this->db->prepare("UPDATE sequences SET next_value=next_value+1,updated_at=NOW() WHERE sequence_key='accident'")->execute();
                $protocol=$prefix.'-'.date('Y').'-'.str_pad((string)$seq,6,'0',STR_PAD_LEFT);
                $this->db->prepare('INSERT INTO accidents(protocol,occurred_at,location,address,neighborhood,reference_point,road_type,weather,accident_type,description,has_victims,has_injuries,has_fatalities,public_property_damage,signage_damage,needs_external_support,external_support_notes,notes,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$protocol,$date.' '.$time.':00',$location,$_POST['address']??null,$_POST['neighborhood']??null,$_POST['reference_point']??null,$_POST['road_type']??null,$_POST['weather']??null,$type,$desc,isset($_POST['has_victims'])?1:0,isset($_POST['has_injuries'])?1:0,isset($_POST['has_fatalities'])?1:0,isset($_POST['public_property_damage'])?1:0,isset($_POST['signage_damage'])?1:0,isset($_POST['needs_external_support'])?1:0,$_POST['external_support_notes']??null,$_POST['notes']??null,$this->uid]);
                $id=(int)$this->db->lastInsertId();$action='accident.created';
            }
            $vehicleInsert=$this->db->prepare('INSERT INTO accident_vehicles(accident_id,plate,brand,model,color,category,driver_name,damage_notes) VALUES(?,?,?,?,?,?,?,?)');
            $vehicleUpdate=$this->db->prepare('UPDATE accident_vehicles SET plate=?,brand=?,model=?,color=?,category=?,driver_name=?,damage_notes=? WHERE id=? AND accident_id=?');
            foreach((array)($_POST['vehicles']??[]) as $idx=>$v){
                if(!is_array($v)||trim((string)($v['model']??''))==='')continue;
                $vehicleId=(int)($v['id']??0);
                if($vehicleId&&in_array($vehicleId,$existingVehicleIds,true)){
                    $vehicleUpdate->execute([$v['plate']??null,$v['brand']??null,$v['model']??null,$v['color']??null,$v['category']??null,$v['driver_name']??null,$v['damage_notes']??null,$vehicleId,$id]);
                }else{
                    $vehicleInsert->execute([$id,$v['plate']??null,$v['brand']??null,$v['model']??null,$v['color']??null,$v['category']??null,$v['driver_name']??null,$v['damage_notes']??null]);
                    $vehicleId=(int)$this->db->lastInsertId();
                }
                $retainedVehicleIds[]=$vehicleId;
                $this->saveAttachment('accident_vehicle',$vehicleId,'vehicle_photos_'.$idx);
            }
            $removedVehicleIds=array_values(array_diff($existingVehicleIds,$retainedVehicleIds));
            if($removedVehicleIds){
                $marks=implode(',',array_fill(0,count($removedVehicleIds),'?'));
                $files=$this->db->prepare("SELECT stored_name FROM file_attachments WHERE module='accident_vehicle' AND record_id IN ($marks)");$files->execute($removedVehicleIds);$obsoleteVehicleFiles=$files->fetchAll(PDO::FETCH_COLUMN);
                $this->db->prepare("DELETE FROM file_attachments WHERE module='accident_vehicle' AND record_id IN ($marks)")->execute($removedVehicleIds);
                $this->db->prepare("DELETE FROM accident_vehicles WHERE accident_id=? AND id IN ($marks)")->execute(array_merge([$id],$removedVehicleIds));
            }
            $staffIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['responsible_ids']??[])))));
            $staffInsert=$this->db->prepare("INSERT INTO accident_staff(accident_id,user_id,assignment_role) VALUES(?,?,'Equipe')");
            if($staffIds){$marks=implode(',',array_fill(0,count($staffIds),'?'));$validStaff=$this->db->prepare("SELECT COUNT(*) FROM users WHERE status='Ativo' AND id IN ($marks)");$validStaff->execute($staffIds);if((int)$validStaff->fetchColumn()!==count($staffIds))throw new RuntimeException('A equipe deve conter somente servidores ativos.');}
            foreach($staffIds as $staffId)$staffInsert->execute([$id,$staffId]);
            $person=$this->db->prepare('INSERT INTO accident_people(accident_id,full_name,document_ref,involvement,contact,notes) VALUES(?,?,?,?,?,?)');
            foreach((array)($_POST['people']??[]) as $v){if(!is_array($v)||trim((string)($v['full_name']??''))==='')continue;$person->execute([$id,$v['full_name'],$v['document_ref']??null,$v['involvement']??'Envolvido',$v['contact']??null,$v['notes']??null]);}
            if(count((array)($_POST['witnesses']??[]))>4)throw new RuntimeException('O limite é de quatro testemunhas por sinistro.');
            $witnesses=(array)($_POST['witnesses']??[]);$wit=$this->db->prepare('INSERT INTO accident_witnesses(accident_id,name,contact,notes) VALUES(?,?,?,?)');
            foreach($witnesses as $v){if(!is_array($v)||trim((string)($v['name']??''))==='')continue;$wit->execute([$id,$v['name'],$v['contact']??null,$v['notes']??null]);}
            if(!empty($_POST['signature_data']))$this->saveSignature($id,(string)$_POST['signature_data']);
            $this->saveAttachment('accident',$id,'attachments');
            $this->db->commit();
            foreach($obsoleteVehicleFiles as $stored) { $path=BASE_PATH.'/storage/private/files/'.basename((string)$stored); if(is_file($path))@unlink($path); }
            $this->audit($this->uid,$action,'sinistro',$id);$this->flash('success','Sinistro salvo.');$this->redirect('/sinistro/'.$id);
        }catch(Throwable $e){$this->db->rollBack();$this->log($e);$this->fail('/sinistro','Não foi possível salvar o sinistro. Confira os dados e os limites de anexos.');}
    }

    private function saveSignature(int $accidentId,string $data): void
    {
        if(!preg_match('#^data:image/png;base64,#',$data))throw new RuntimeException('Assinatura inválida.');
        $raw=base64_decode(substr($data,strpos($data,',')+1),true);if(!$raw||strlen($raw)>500000)throw new RuntimeException('Assinatura excede o limite.');
        $this->db->prepare('INSERT INTO accident_signatures(accident_id,user_id,signature_data,signed_at) VALUES(?,?,?,NOW())')->execute([$accidentId,$this->uid,$data]);
    }

    private function shiftsData(): array
    {
        $view=(string)($_GET['view']??'month');if(!in_array($view,['month','week','day'],true))$view='month';
        $raw=(string)($_GET['date']??date('Y-m-d'));$selected=\DateTimeImmutable::createFromFormat('!Y-m-d',$raw);
        if(!$selected||$selected->format('Y-m-d')!==$raw)$selected=new \DateTimeImmutable('today');
        if($view==='month'){$first=$selected->modify('first day of this month');$start=$first->modify('monday this week');$last=$selected->modify('last day of this month');$end=$last->modify('sunday this week');}
        elseif($view==='week'){$start=$selected->modify('monday this week');$end=$start->modify('+6 days');}
        else{$start=$selected;$end=$selected;}
        $from=$start->format('Y-m-d');$to=$end->format('Y-m-d');
        $q=$this->db->prepare('SELECT s.*,u.name manager_name,(SELECT COUNT(*) FROM shift_members sm WHERE sm.shift_id=s.id) member_count FROM shifts s JOIN users u ON u.id=s.manager_id WHERE s.service_date BETWEEN ? AND ? ORDER BY s.service_date,s.start_time LIMIT 300');
        $q->execute([$from,$to]);$shifts=$q->fetchAll();$byDate=[];foreach($shifts as $item)$byDate[$item['service_date']][]=$item;
        $calendarDays=[];$period=new \DatePeriod($start,new \DateInterval('P1D'),$end->modify('+1 day'));
        foreach($period as $day)$calendarDays[]=['date'=>$day->format('Y-m-d'),'label'=>$day->format('d'),'inMonth'=>$day->format('m')===$selected->format('m'),'items'=>$byDate[$day->format('Y-m-d')]??[]];
        $staff=$this->db->query("SELECT id,name FROM users WHERE status='Ativo' ORDER BY name")->fetchAll();
        $editShift=null;$editId=(int)($_GET['edit']??0);if($editId&&$this->allowed('escalas.edit')){$ed=$this->db->prepare('SELECT * FROM shifts WHERE id=?');$ed->execute([$editId]);$editShift=$ed->fetch()?:null;if($editShift){$ed=$this->db->prepare('SELECT user_id FROM shift_members WHERE shift_id=?');$ed->execute([$editId]);$editShift['members']=array_map('intval',$ed->fetchAll(PDO::FETCH_COLUMN));}}
        return ['shifts'=>$shifts,'calendarDays'=>$calendarDays,'calendarStart'=>$from,'calendarEnd'=>$to,'calendarView'=>$view,'calendarDate'=>$selected->format('Y-m-d'),'staff'=>$staff,'filters'=>compact('from','to','view'),'canCreate'=>$this->allowed('escalas.create'),'canEdit'=>$this->allowed('escalas.edit'),'canPublish'=>$this->allowed('escalas.publish'),'editShift'=>$editShift];
    }

    private function saveShift(): void
    {
        $id=(int)($_POST['id']??0);$isNew=$id===0;$this->requirePermission($id?'escalas.edit':'escalas.create');
        $date=(string)($_POST['service_date']??'');$start=(string)($_POST['start_time']??'');$end=(string)($_POST['end_time']??'');
        $title=trim((string)($_POST['title']??''));$location=trim((string)($_POST['location']??''));$type=trim((string)($_POST['service_type']??''));
        $parsedDate=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$title||!$location||!$type||!$parsedDate||$parsedDate->format('Y-m-d')!==$date||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end)||$start===$end)$this->fail('/escala','Confira os campos da escala.');
        $starts=strtotime($date.' '.$start);$ends=strtotime($date.' '.$end);if($ends<=$starts)$ends=strtotime('+1 day',$ends);
        $members=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['members']??[])))));
        if(!$members)$this->fail('/escala','Selecione ao menos um servidor.');
        if($members){
            $marks=implode(',',array_fill(0,count($members),'?'));
            $active=$this->db->prepare("SELECT COUNT(*) FROM users WHERE status='Ativo' AND id IN ($marks)");$active->execute($members);
            if((int)$active->fetchColumn()!==count($members))$this->fail('/escala','Todos os servidores escalados precisam estar ativos.');
        }
        $this->db->beginTransaction();
        try{
            if($id){$lock=$this->db->prepare('SELECT status FROM shifts WHERE id=? FOR UPDATE');$lock->execute([$id]);$current=$lock->fetchColumn();if(!$current)throw new RuntimeException('Escala não encontrada.');if($current==='Cancelada')throw new RuntimeException('Não é possível editar uma escala cancelada.');}
            foreach($members as $member){
                $sql="SELECT COUNT(*) FROM shifts s JOIN shift_members sm ON sm.shift_id=s.id WHERE sm.user_id=? AND s.status<>'Cancelada' AND s.id<>? AND COALESCE(s.starts_at,CONCAT(s.service_date,' ',s.start_time)) < ? AND COALESCE(s.ends_at,CONCAT(s.service_date,' ',s.end_time)) > ?";
                $check=$this->db->prepare($sql);$check->execute([$member,$id,date('Y-m-d H:i:s',$ends),date('Y-m-d H:i:s',$starts)]);
                if((int)$check->fetchColumn()>0)throw new RuntimeException('Conflito de horário para um dos servidores selecionados.');
            }
            if($id){
                $this->db->prepare('UPDATE shifts SET title=?,service_type=?,service_date=?,start_time=?,end_time=?,starts_at=?,ends_at=?,location=?,team_name=?,notes=?,updated_at=NOW() WHERE id=?')
                ->execute([$title,$type,$date,$start,$end,date('Y-m-d H:i:s',$starts),date('Y-m-d H:i:s',$ends),$location,$_POST['team_name']??null,$_POST['notes']??null,$id]);
                $this->db->prepare('DELETE FROM shift_members WHERE shift_id=?')->execute([$id]);
            }else{
                $this->db->prepare("INSERT INTO shifts(title,service_type,service_date,start_time,end_time,starts_at,ends_at,location,team_name,manager_id,status,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,'Rascunho',?,NOW())")
                ->execute([$title,$type,$date,$start,$end,date('Y-m-d H:i:s',$starts),date('Y-m-d H:i:s',$ends),$location,$_POST['team_name']??null,$this->uid,$_POST['notes']??null]);$id=(int)$this->db->lastInsertId();
            }
            $ins=$this->db->prepare('INSERT INTO shift_members(shift_id,user_id) VALUES(?,?)');foreach($members as $member)$ins->execute([$id,$member]);
            $this->db->prepare('INSERT INTO shift_history(shift_id,user_id,action,details,created_at) VALUES(?,?,?,?,NOW())')->execute([$id,$this->uid,$isNew?'created':'updated','Escala salva: '.$title.' ('.$date.' '.$start.'-'.$end.')']);
            $this->db->commit();$this->audit($this->uid,$isNew?'shift.created':'shift.updated','escala',$id);$this->flash('success',$isNew?'Escala criada como rascunho.':'Escala atualizada.');
        }catch(Throwable $e){$this->db->rollBack();$this->log($e);$this->flash('error',$e instanceof RuntimeException?$e->getMessage():'Não foi possível salvar a escala.');}
        $this->redirect('/escala');
    }

    private function shiftAction(): void
    {
        $id=(int)($_POST['id']??0);$action=(string)($_POST['action']??'');
        if(!in_array($action,['publish','cancel'],true))$this->fail('/escala','Ação inválida.');
        $this->requirePermission($action==='publish'?'escalas.publish':'escalas.edit');
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT status FROM shifts WHERE id=? FOR UPDATE');$q->execute([$id]);$current=$q->fetchColumn();
            if(!$current)throw new RuntimeException('Escala não encontrada.');
            if($action==='publish'&&$current!=='Rascunho')throw new RuntimeException('Somente escalas em rascunho podem ser publicadas.');
            if($action==='cancel'&&$current==='Cancelada')throw new RuntimeException('Esta escala já está cancelada.');
            $reason=trim((string)($_POST['reason']??''));
            if($action==='cancel'&&mb_strlen($reason)<8)throw new RuntimeException('Informe uma justificativa de cancelamento com pelo menos 8 caracteres.');
            $status=$action==='publish'?'Publicada':'Cancelada';
            $this->db->prepare('UPDATE shifts SET status=?,cancel_reason=?,cancelled_by=?,cancelled_at=?,updated_at=NOW() WHERE id=?')->execute([$status,$action==='cancel'?$reason:null,$action==='cancel'?$this->uid:null,$action==='cancel'?date('Y-m-d H:i:s'):null,$id]);
            $this->db->prepare('INSERT INTO shift_history(shift_id,user_id,action,details,created_at) VALUES(?,?,?,?,NOW())')->execute([$id,$this->uid,$action==='publish'?'published':'cancelled',$action==='cancel'?$reason:'Escala publicada']);
            $q=$this->db->prepare('SELECT user_id FROM shift_members WHERE shift_id=?');$q->execute([$id]);
            $notify=$this->db->prepare('INSERT INTO notifications(user_id,title,message,target_url,created_at) VALUES(?,?,?,?,NOW())');
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $target)if((int)$target!==$this->uid)$notify->execute([(int)$target,$action==='publish'?'Nova escala publicada':'Escala cancelada',$action==='publish'?'Uma escala de serviço foi publicada.':'Uma escala de serviço foi cancelada: '.$reason,'/escala/'.$id]);
            $this->db->commit();
            $this->audit($this->uid,'shift.'.$action,'escala',$id);$this->flash('success','Situação da escala atualizada.');
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            $this->log($e);$this->flash('error',$e instanceof RuntimeException?$e->getMessage():'Não foi possível atualizar a escala.');
        }
        $this->redirect('/escala');
    }

    private function ordersData(): array
    {
        $q=trim((string)($_GET['q']??''));$status=trim((string)($_GET['status']??''));$priority=trim((string)($_GET['priority']??''));
        $w=[];$p=[];if($q!==''){$w[]='(CAST(o.order_number AS CHAR) LIKE ? OR o.description LIKE ? OR o.location LIKE ?)';array_push($p,"%$q%","%$q%","%$q%");}
        if($status!==''){$w[]='o.status=?';$p[]=$status;}if($priority!==''){$w[]='o.priority=?';$p[]=$priority;}
        $sql='SELECT o.*,u.name responsible_name FROM service_orders o LEFT JOIN users u ON u.id=o.responsible_id'.($w?' WHERE '.implode(' AND ',$w):'').' ORDER BY o.id DESC LIMIT 100';
        $st=$this->db->prepare($sql);$st->execute($p);
        $editOrder=null;$editId=(int)($_GET['edit']??0);
        if($editId&&$this->allowed('ordens.edit')){$ed=$this->db->prepare("SELECT * FROM service_orders WHERE id=? AND status IN ('Solicitada','Em análise')");$ed->execute([$editId]);$editOrder=$ed->fetch()?:null;if($editOrder){$ed=$this->db->prepare('SELECT user_id FROM service_order_executors WHERE order_id=?');$ed->execute([$editId]);$editOrder['executors']=array_map('intval',$ed->fetchAll(PDO::FETCH_COLUMN));}}
        return ['orders'=>$st->fetchAll(),'staff'=>$this->db->query("SELECT id,name FROM users WHERE status='Ativo' ORDER BY name")->fetchAll(),'filters'=>compact('q','status','priority'),'canCreate'=>$this->allowed('ordens.create'),'canEdit'=>$this->allowed('ordens.edit'),'canTransition'=>$this->allowed('ordens.transition'),'editOrder'=>$editOrder];
    }

    private function saveOrder(): void
    {
        $id=(int)($_POST['id']??0);
        $this->requirePermission($id?'ordens.edit':'ordens.create');
        $requester=trim((string)($_POST['requester']??''));
        $type=trim((string)($_POST['service_type']??''));
        $description=trim((string)($_POST['description']??''));
        $location=trim((string)($_POST['location']??''));
        $priority=(string)($_POST['priority']??'Baixa');
        $allowed=['Baixa','Média','Alta','Urgente'];
        $dueInput=trim((string)($_POST['due_at']??''));
        $responsible=(int)($_POST['responsible_id']??0)?:null;
        $executors=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['executors']??[])))));
        if(!$requester||!$type||!$description||!$location||!in_array($priority,$allowed,true))$this->fail('/ordens','Preencha os campos obrigatórios.');
        $dueAt=null;
        if($dueInput!==''){
            if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',$dueInput))$this->fail('/ordens','Prazo previsto inválido.');
            $dueAt=str_replace('T',' ',$dueInput).':00';
        }
        $assigned=array_values(array_unique(array_filter(array_merge($executors,$responsible?[$responsible]:[]))));
        if($assigned){
            $marks=implode(',',array_fill(0,count($assigned),'?'));
            $check=$this->db->prepare("SELECT COUNT(*) FROM users WHERE status='Ativo' AND id IN ($marks)");
            $check->execute($assigned);
            if((int)$check->fetchColumn()!==count($assigned))$this->fail('/ordens','Um ou mais servidores selecionados não estão ativos.');
        }
        $this->db->beginTransaction();
        try{
            if($id){
                $lock=$this->db->prepare('SELECT status FROM service_orders WHERE id=? FOR UPDATE');$lock->execute([$id]);$current=$lock->fetchColumn();
                if(!$current)throw new RuntimeException('Ordem de serviço não encontrada.');
                if(!in_array($current,['Solicitada','Em análise'],true))throw new RuntimeException('Somente O.S. solicitadas ou em análise podem ser editadas.');
                $this->db->prepare('UPDATE service_orders SET requester=?,requesting_department=?,service_type=?,description=?,location=?,priority=?,due_at=?,responsible_id=?,updated_at=NOW() WHERE id=?')
                    ->execute([$requester,$_POST['requesting_department']??null,$type,$description,$location,$priority,$dueAt,$responsible,$id]);
                $this->db->prepare('DELETE FROM service_order_executors WHERE order_id=?')->execute([$id]);
                $action='order.updated';
            }else{
                $this->db->exec("INSERT INTO sequences(sequence_key,next_value,updated_at) VALUES('service_order',1,NOW()) ON DUPLICATE KEY UPDATE next_value=next_value");
                $seq=(int)$this->db->query("SELECT next_value FROM sequences WHERE sequence_key='service_order' FOR UPDATE")->fetchColumn();
                $this->db->prepare("UPDATE sequences SET next_value=next_value+1,updated_at=NOW() WHERE sequence_key='service_order'")->execute();
                $this->db->prepare("INSERT INTO service_orders(order_number,opened_at,requester,requesting_department,service_type,description,location,priority,status,due_at,responsible_id,created_by,created_at) VALUES(?,NOW(),?,?,?,?,?,?, 'Solicitada',?,?,?,NOW())")
                    ->execute([$seq,$requester,$_POST['requesting_department']??null,$type,$description,$location,$priority,$dueAt,$responsible,$this->uid]);
                $id=(int)$this->db->lastInsertId();
                $this->db->prepare("INSERT INTO service_order_history(order_id,user_id,old_status,new_status,note,created_at) VALUES(?, ?, NULL, 'Solicitada', 'Abertura da ordem de serviço', NOW())")->execute([$id,$this->uid]);
                $action='order.created';
            }
            if($executors){
                $ins=$this->db->prepare('INSERT INTO service_order_executors(order_id,user_id) VALUES(?,?)');
                foreach($executors as $uid)$ins->execute([$id,$uid]);
            }
            $this->saveAttachment('order',$id,'attachments');
            $notify=$this->db->prepare('INSERT INTO notifications(user_id,title,message,target_url,created_at) VALUES(?,?,?,?,NOW())');
            foreach($assigned as $target)if($target!==$this->uid)$notify->execute([$target,$action==='order.created'?'Nova ordem de serviço':'Ordem de serviço atualizada','Uma O.S. foi atribuída ou atualizada para você.','/ordens/'.$id]);
            $this->db->commit();
            $this->audit($this->uid,$action,'ordens',$id);
            $this->flash('success',$action==='order.created'?'Ordem de serviço criada.':'Ordem de serviço atualizada.');
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            $this->log($e);$this->flash('error',$e instanceof RuntimeException?$e->getMessage():'Não foi possível salvar a ordem de serviço.');
        }
        $this->redirect('/ordens');
    }

    private function changeOrderStatus(): void
    {
        $this->requirePermission('ordens.transition');$id=(int)($_POST['id']??0);$next=(string)($_POST['status']??'');$note=trim((string)($_POST['note']??''));
        $valid=['Solicitada','Em análise','Aprovada','Em andamento','Pendente','Concluída','Cancelada'];
        if(!in_array($next,$valid,true))$this->fail('/ordens','Status inválido.');
        if($next==='Aprovada')$this->requirePermission('ordens.approve');
        if(in_array($next,['Aprovada','Cancelada'],true)&&mb_strlen($note)<8)$this->fail('/ordens/'.$id,'Informe uma justificativa com pelo menos 8 caracteres para aprovar ou cancelar.');
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT status FROM service_orders WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetchColumn();if(!$old)throw new RuntimeException('Ordem não encontrada.');
            $allowed=['Solicitada'=>['Em análise','Aprovada','Cancelada'],'Em análise'=>['Aprovada','Pendente','Cancelada'],'Aprovada'=>['Em andamento','Pendente','Cancelada'],'Em andamento'=>['Pendente','Concluída','Cancelada'],'Pendente'=>['Em andamento','Cancelada'],'Concluída'=>[],'Cancelada'=>[]];
            if(!in_array($next,$allowed[$old]??[],true))throw new RuntimeException('Transição de status não permitida.');
            $this->db->prepare('UPDATE service_orders SET status=?,approved_by=IF(?="Aprovada",?,approved_by),approved_at=IF(?="Aprovada",NOW(),approved_at),cancelled_by=IF(?="Cancelada",?,cancelled_by),cancelled_at=IF(?="Cancelada",NOW(),cancelled_at),cancellation_reason=IF(?="Cancelada",?,cancellation_reason),started_at=IF(?="Em andamento" AND started_at IS NULL,NOW(),started_at),completed_at=IF(?="Concluída",NOW(),completed_at),updated_at=NOW() WHERE id=?')->execute([$next,$next,$this->uid,$next,$next,$this->uid,$next,$next,$note,$next,$next,$id]);
            $this->db->prepare('INSERT INTO service_order_history(order_id,user_id,old_status,new_status,note,created_at) VALUES(?,?,?,?,?,NOW())')->execute([$id,$this->uid,$old,$next,$note]);
            $targets=$this->db->prepare('SELECT created_by,responsible_id FROM service_orders WHERE id=?');$targets->execute([$id]);$recipients=$targets->fetch();
            $notify=$this->db->prepare('INSERT INTO notifications(user_id,title,message,target_url,created_at) VALUES(?,?,?,?,NOW())');
            foreach(array_unique(array_filter([(int)$recipients['created_by'],(int)$recipients['responsible_id']])) as $target)if($target!==$this->uid)$notify->execute([$target,'Atualização da ordem de serviço','A O.S. teve o status alterado para '.$next.'.','/ordens/'.$id]);
            $this->db->commit();$this->audit($this->uid,'order.status_changed','ordens',$id);$this->flash('success','Status atualizado.');
        }catch(Throwable $e){$this->db->rollBack();$this->log($e);$this->flash('error',$e instanceof RuntimeException?$e->getMessage():'Falha ao atualizar status.');}
        $this->redirect('/ordens');
    }

    private function addOrderUpdate(): void
    {
        $this->requirePermission('ordens.transition');
        $id=(int)($_POST['id']??0);$description=trim((string)($_POST['description']??''));$type=(string)($_POST['update_type']??'Execução');
        if(!$description)$this->fail('/ordens/'.$id,'Descreva a atividade realizada.');
        if(!in_array($type,['Execução','Material utilizado','Ocorrência','Conclusão'],true))$this->fail('/ordens/'.$id,'Tipo de atualização inválido.');
        $check=$this->db->prepare("SELECT status FROM service_orders WHERE id=?");$check->execute([$id]);$status=$check->fetchColumn();
        if(!$status)$this->fail('/ordens','Ordem de serviço não encontrada.');
        if(!in_array($status,['Aprovada','Em andamento','Pendente'],true))$this->fail('/ordens/'.$id,'A execução só pode ser registrada em O.S. aprovada, em andamento ou pendente.');
        $this->db->prepare('INSERT INTO service_order_updates(order_id,user_id,update_type,description,materials,created_at) VALUES(?,?,?,?,?,NOW())')->execute([$id,$this->uid,$type,$description,$_POST['materials']??null]);
        $this->audit($this->uid,'order.update_added','ordens',$id);$this->flash('success','Atualização registrada.');$this->redirect('/ordens/'.$id);
    }

    private function showRecord(string $module,int $id): void
    {
        $permission=['sinistro'=>'sinistros.view','escala'=>'escalas.view','ordens'=>'ordens.view'][$module];$this->requirePermission($permission);
        if($module==='sinistro'){$q=$this->db->prepare('SELECT a.*,u.name creator FROM accidents a JOIN users u ON u.id=a.created_by WHERE a.id=?');$q->execute([$id]);$record=$q->fetch();if(!$record){http_response_code(404);$this->render('errors/404',[]);return;}
            foreach(['vehicles'=>'accident_vehicles','people'=>'accident_people','witnesses'=>'accident_witnesses'] as $key=>$table){$q=$this->db->prepare("SELECT * FROM $table WHERE accident_id=?");$q->execute([$id]);$record[$key]=$q->fetchAll();}
            $q=$this->db->prepare("SELECT id,original_name,mime_type,file_size FROM file_attachments WHERE module='accident' AND record_id=?");$q->execute([$id]);$record['files']=$q->fetchAll();
            foreach($record['vehicles'] as &$vehicle){$q=$this->db->prepare("SELECT id,original_name FROM file_attachments WHERE module='accident_vehicle' AND record_id=?");$q->execute([$vehicle['id']]);$vehicle['files']=$q->fetchAll();}unset($vehicle);
            $q=$this->db->prepare('SELECT s.signature_data,s.signed_at,u.name FROM accident_signatures s JOIN users u ON u.id=s.user_id WHERE s.accident_id=? ORDER BY s.id DESC LIMIT 1');$q->execute([$id]);$record['signature']=$q->fetch();$q=$this->db->prepare('SELECT u.id,u.name,st.assignment_role FROM accident_staff st JOIN users u ON u.id=st.user_id WHERE st.accident_id=? ORDER BY u.name');$q->execute([$id]);$record['staff']=$q->fetchAll();
        }elseif($module==='ordens'){$q=$this->db->prepare('SELECT o.*,u.name responsible_name,ap.name approved_by_name,ca.name cancelled_by_name FROM service_orders o LEFT JOIN users u ON u.id=o.responsible_id LEFT JOIN users ap ON ap.id=o.approved_by LEFT JOIN users ca ON ca.id=o.cancelled_by WHERE o.id=?');$q->execute([$id]);$record=$q->fetch();if($record){$q=$this->db->prepare('SELECT h.*,u.name FROM service_order_history h JOIN users u ON u.id=h.user_id WHERE h.order_id=? ORDER BY h.id DESC');$q->execute([$id]);$record['history']=$q->fetchAll();$q=$this->db->prepare('SELECT x.*,u.name FROM service_order_updates x JOIN users u ON u.id=x.user_id WHERE x.order_id=? ORDER BY x.id DESC');$q->execute([$id]);$record['updates']=$q->fetchAll();$q=$this->db->prepare('SELECT u.id,u.name FROM service_order_executors x JOIN users u ON u.id=x.user_id WHERE x.order_id=? ORDER BY u.name');$q->execute([$id]);$record['executors']=$q->fetchAll();$q=$this->db->prepare("SELECT id,original_name FROM file_attachments WHERE module='order' AND record_id=?");$q->execute([$id]);$record['files']=$q->fetchAll();}}
        else{$q=$this->db->prepare('SELECT s.*,u.name manager_name FROM shifts s JOIN users u ON u.id=s.manager_id WHERE s.id=?');$q->execute([$id]);$record=$q->fetch();if($record){$q=$this->db->prepare('SELECT u.id,u.name FROM shift_members sm JOIN users u ON u.id=sm.user_id WHERE sm.shift_id=?');$q->execute([$id]);$record['members']=$q->fetchAll();$q=$this->db->prepare('SELECT h.*,u.name FROM shift_history h JOIN users u ON u.id=h.user_id WHERE h.shift_id=? ORDER BY h.id DESC');$q->execute([$id]);$record['history']=$q->fetchAll();}}
        if(!$record){http_response_code(404);$this->render('errors/404',[]);return;}
        $allowedTransitions=[];if($module==='ordens'&&$this->allowed('ordens.transition')){$map=['Solicitada'=>['Em análise','Aprovada','Cancelada'],'Em análise'=>['Aprovada','Pendente','Cancelada'],'Aprovada'=>['Em andamento','Pendente','Cancelada'],'Em andamento'=>['Pendente','Concluída','Cancelada'],'Pendente'=>['Em andamento','Cancelada'],'Concluída'=>[],'Cancelada'=>[]];$allowedTransitions=$map[$record['status']]??[];if(!$this->allowed('ordens.approve'))$allowedTransitions=array_values(array_diff($allowedTransitions,['Aprovada']));}
        $this->render('pages/detail',['title'=>'Detalhes · '.$module,'module'=>$module,'record'=>$record,'canEdit'=>$this->allowed(['sinistro'=>'sinistros.edit','escala'=>'escalas.edit','ordens'=>'ordens.edit'][$module]),'canTransition'=>$this->allowed('ordens.transition'),'allowedTransitions'=>$allowedTransitions]);
    }

    private function reportsData(): array
    {
        return ['reportCounts'=>[
            'Sinistros'=>(int)$this->db->query('SELECT COUNT(*) FROM accidents')->fetchColumn(),
            'Escalas'=>(int)$this->db->query('SELECT COUNT(*) FROM shifts')->fetchColumn(),
            'Ordens de serviço'=>(int)$this->db->query('SELECT COUNT(*) FROM service_orders')->fetchColumn(),
            'Servidores'=>(int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn()
        ],'canExport'=>$this->allowed('reports.export')];
    }

    private function reportRows(string $type): array
    {
        $from=trim((string)($_GET['from']??''));$to=trim((string)($_GET['to']??''));$status=trim((string)($_GET['status']??''));
        $spec=[
            'sinistros'=>['Sinistros','SELECT protocol AS Protocolo,occurred_at AS Data,location AS Local,accident_type AS Tipo,status AS Situacao FROM accidents','occurred_at'],
            'escalas'=>['Escalas','SELECT title AS Escala,service_date AS Data,start_time AS Inicio,end_time AS Fim,service_type AS Servico,location AS Local,status AS Situacao FROM shifts','service_date'],
            'ordens'=>['Ordens de Serviço','SELECT CONCAT("O.S. - ",LPAD(order_number,4,"0")) AS Numero,opened_at AS Abertura,requester AS Solicitante,service_type AS Servico,priority AS Prioridade,status AS Situacao,location AS Local FROM service_orders','opened_at'],
            'usuarios'=>['Usuários','SELECT name AS Nome,registration AS Matricula,email AS Email,position AS Cargo,department AS Setor,status AS Situacao FROM users','created_at']
        ];
        if(!isset($spec[$type]))throw new RuntimeException('Tipo de relatório inválido.');
        [$label,$sql,$dateField]=$spec[$type];$where=[];$params=[];
        if($from!==''&&preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$from)){$where[]="DATE($dateField)>=?";$params[]=$from;}
        if($to!==''&&preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$to)){$where[]="DATE($dateField)<=?";$params[]=$to;}
        if($status!==''&&$type!=='usuarios'){$where[]='status=?';$params[]=$status;}
        if($status!==''&&$type==='usuarios'){$where[]='status=?';$params[]=$status;}
        if($where)$sql.=' WHERE '.implode(' AND ',$where);
        $sql.=$type==='usuarios'?' ORDER BY name':' ORDER BY '.$dateField.' DESC';
        $q=$this->db->prepare($sql);$q->execute($params);
        return ['title'=>$label,'rows'=>$q->fetchAll(),'filters'=>compact('from','to','status'),'type'=>$type];
    }

    private function exportReport(): void
    {
        $this->requirePermission('reports.export');$type=(string)($_GET['type']??'');
        try{$data=$this->reportRows($type);}catch(Throwable $e){$this->fail('/relatorios','Tipo de relatório inválido.');}
        $filename=strtolower(str_replace(' ','-',$data['title'])).'.csv';
        $this->audit($this->uid,'report.exported','relatorios',null);
        header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$filename.'"');
        $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
        $safe=static function(mixed $value): string {
            $value=(string)($value??'');
            return preg_match('/^[\x00-\x20]*[=+\-@]/u',$value)?"'".$value:$value;
        };
        if($data['rows']){
            fputcsv($out,array_map($safe,array_keys($data['rows'][0])),';');
            foreach($data['rows'] as $row)fputcsv($out,array_map($safe,array_values($row)),';');
        }
        fclose($out);exit;
    }

    private function printReport(): void
    {
        $this->requirePermission('reports.export');$type=(string)($_GET['type']??'');
        try{$data=$this->reportRows($type);}catch(Throwable $e){$this->fail('/relatorios','Tipo de relatório inválido.');}
        $this->audit($this->uid,'report.printed','relatorios',null);
        $this->render('pages/report-print',['title'=>'Relatório · '.$data['title'],'reportTitle'=>$data['title'],'reportRows'=>$data['rows'],'reportFilters'=>$data['filters']]);
    }

    private function dashboardData(): array
    {
        $period=(string)($_GET['period']??'30');$days=in_array($period,['7','30','365'],true)?(int)$period:30;
        $since=date('Y-m-d 00:00:00',strtotime('-'.($days-1).' days'));
        $accCount=$this->db->prepare('SELECT COUNT(*) FROM accidents WHERE occurred_at>=?');$accCount->execute([$since]);
        $counts=[
            'active'=>(int)$this->db->query("SELECT COUNT(*) FROM users WHERE status='Ativo'")->fetchColumn(),
            'away'=>(int)$this->db->query("SELECT COUNT(*) FROM users WHERE status='Afastado'")->fetchColumn(),
            'shifts'=>(int)$this->db->query("SELECT COUNT(*) FROM shifts WHERE service_date=CURDATE() AND status='Publicada'")->fetchColumn(),
            'orders_open'=>(int)$this->db->query("SELECT COUNT(*) FROM service_orders WHERE status NOT IN ('Concluída','Cancelada')")->fetchColumn(),
            'accidents'=>(int)$accCount->fetchColumn()
        ];
        $recentAccidents=[];
        if($this->allowed('sinistros.view')){$q=$this->db->prepare('SELECT id,protocol,occurred_at,location,accident_type FROM accidents WHERE occurred_at>=? ORDER BY occurred_at DESC LIMIT 5');$q->execute([$since]);$recentAccidents=$q->fetchAll();}
        $recentOrders=$this->allowed('ordens.view')?$this->db->query('SELECT id,order_number,status,priority,service_type FROM service_orders ORDER BY id DESC LIMIT 5')->fetchAll():[];
        $notifications=$this->db->prepare('SELECT id,title,message,target_url,created_at FROM notifications WHERE user_id=? AND read_at IS NULL ORDER BY id DESC LIMIT 8');$notifications->execute([$this->uid]);
        return compact('counts','recentAccidents','recentOrders','period')+['notifications'=>$notifications->fetchAll()];
    }

    private function stats(): array
    {
        $out=[];foreach(['users'=>'users','sinistros'=>'accidents','escalas'=>'shifts','ordens'=>'service_orders'] as $k=>$t)$out[$k]=(int)$this->db->query("SELECT COUNT(*) FROM $t")->fetchColumn();return $out;
    }

    private function saveAttachment(string $module,int $recordId,string $field,bool $single=false): void
    {
        if(!isset($_FILES[$field]))return;$files=$_FILES[$field];$names=is_array($files['name'])?$files['name']:[$files['name']];
        $max=(int)$this->setting('upload_max_mb','8')*1024*1024;$allowed=($field==='photo'||$module==='accident_vehicle'||$module==='branding')?['image/jpeg','image/png','image/webp']:['image/jpeg','image/png','image/webp','application/pdf'];
        $dir=BASE_PATH.'/storage/private/files';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Não foi possível preparar armazenamento privado.');
        $finfo=new \finfo(FILEINFO_MIME_TYPE);
        $incoming=0;foreach($names as $i=>$name){$error=is_array($files['error'])?$files['error'][$i]:$files['error'];if($error!==UPLOAD_ERR_NO_FILE)$incoming++;}
        if($module==='accident_vehicle'){$current=$this->db->prepare("SELECT COUNT(*) FROM file_attachments WHERE module='accident_vehicle' AND record_id=?");$current->execute([$recordId]);if((int)$current->fetchColumn()+$incoming>10)throw new RuntimeException('São permitidas até 10 imagens por veículo.');}
        if($module!=='accident_vehicle'&&$incoming>20)throw new RuntimeException('São permitidos até 20 anexos por registro.');
        if($single&&$incoming>1)throw new RuntimeException('Envie somente uma foto de perfil por vez.');
        foreach($names as $i=>$name){$error=is_array($files['error'])?$files['error'][$i]:$files['error'];if($error===UPLOAD_ERR_NO_FILE)continue;if($error!==UPLOAD_ERR_OK)throw new RuntimeException('Falha no envio de arquivo.');
            $tmp=is_array($files['tmp_name'])?$files['tmp_name'][$i]:$files['tmp_name'];$size=is_array($files['size'])?$files['size'][$i]:$files['size'];$mime=$finfo->file($tmp);
            if($size>$max||!in_array($mime,$allowed,true))throw new RuntimeException('Arquivo inválido ou acima do limite.');
            $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'][$mime];$stored=bin2hex(random_bytes(24)).'.'.$ext;
            if(!move_uploaded_file($tmp,$dir.'/'.$stored))throw new RuntimeException('Não foi possível armazenar o arquivo.');
            $stmt=$this->db->prepare('INSERT INTO file_attachments(module,record_id,original_name,stored_name,mime_type,file_size,uploaded_by,created_at) VALUES(?,?,?,?,?,?,?,NOW())');
            $stmt->execute([$module,$recordId,mb_substr(basename((string)$name),0,255),$stored,$mime,$size,$this->uid]);
            if($module==='user')$this->db->prepare('UPDATE users SET photo_path=? WHERE id=?')->execute([$stored,$recordId]);
            if($single)break;
        }
    }

    private function download(): void
    {
        $id=(int)($_GET['id']??0);$q=$this->db->prepare('SELECT * FROM file_attachments WHERE id=?');$q->execute([$id]);$f=$q->fetch();
        if(!$f){http_response_code(404);exit;}
        $permission=match($f['module']){'order'=>'ordens.view','user'=>'users.view','branding'=>'dashboard.view',default=>'sinistros.view'};
        if($f['module']==='branding'&&!$this->uid){http_response_code(401);exit;}
        if(!in_array($f['module'],['branding'],true)&&!($f['module']==='user'&&(int)$f['record_id']===$this->uid))$this->requirePermission($permission);
        if($f['module']==='user'&&(int)$f['record_id']!==$this->uid&&!$this->allowed('users.manage')){http_response_code(403);exit;}
        if(!$f){http_response_code(404);exit;}
        $stored=(string)$f['stored_name'];
        if(!preg_match('/^[a-f0-9]{48}\\.(jpg|png|webp|pdf)$/',$stored)||!preg_match('/^[a-z0-9.+-]+\\/[a-z0-9.+-]+$/i',(string)$f['mime_type'])){http_response_code(404);exit;}
        $path=BASE_PATH.'/storage/private/files/'.$stored;if(!is_file($path)){http_response_code(404);exit;}
        header('Content-Type: '.$f['mime_type']);header('Content-Length: '.filesize($path));header('Content-Disposition: attachment; filename="'.rawurlencode($f['original_name']).'"');header('X-Content-Type-Options: nosniff');readfile($path);exit;
    }

    private function readNotification(): void
    {
        $id=(int)($_POST['id']??0);$q=$this->db->prepare('UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=?');$q->execute([$id,$this->uid]);
        $target=(string)($_POST['target_url']??'/');if(!str_starts_with($target,'/')||str_starts_with($target,'//'))$target='/';$this->redirect($target);
    }

    private function setting(string $key,string $default=''): string
    {
        $q=$this->db->prepare('SELECT setting_value FROM system_settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$default:(string)$v;
    }

    private function audit(?int $userId,string $action,string $module,?int $recordId): void
    {
        try{$q=$this->db->prepare('INSERT INTO audit_logs(user_id,action,module,record_id,ip_address,created_at) VALUES(?,?,?,?,?,NOW())');$q->execute([$userId,$action,$module,$recordId,$_SERVER['REMOTE_ADDR']??null]);}catch(Throwable $e){$this->log($e);}
    }

    private function flash(string $type,string $message): void { $_SESSION['flash_'.$type]=$message; }
    private function fail(string $url,string $message): never { $this->flash('error',$message);$this->redirect($url); }
    private function redirect(string $path): never { header('Location: '.$this->baseUrl.$path);exit; }
    private function log(Throwable $e): void { error_log('[DemutranWEB] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine()); }

    private function render(string $view,array $data): void
    {
        extract($data,EXTR_SKIP);$app=$this->config;$user=$_SESSION['user']??null;$csrf=$_SESSION['csrf']??=bin2hex(random_bytes(32));
        $flashSuccess=$_SESSION['flash_success']??null;$flashError=$_SESSION['flash_error']??null;unset($_SESSION['flash_success'],$_SESSION['flash_error']);
        $baseUrl=$this->baseUrl;$title=$title??'DemutranWEB';$photoId=null;$logoId=null;$unreadNotifications=[];$unreadCount=0;if($user){$role=$this->db->prepare('SELECT name FROM roles WHERE id=?');$role->execute([$user['role_id']??0]);$user['role']=$role->fetchColumn()?:'Servidor';$photo=$this->db->prepare("SELECT id FROM file_attachments WHERE module='user' AND record_id=? ORDER BY id DESC LIMIT 1");$photo->execute([$this->uid]);$photoId=$photo->fetchColumn()?:null;$logo=$this->db->prepare("SELECT id FROM file_attachments WHERE module='branding' AND record_id=1 ORDER BY id DESC LIMIT 1");$logo->execute();$logoId=$logo->fetchColumn()?:null;$n=$this->db->prepare('SELECT id,title,message,target_url,created_at FROM notifications WHERE user_id=? AND read_at IS NULL ORDER BY id DESC LIMIT 8');$n->execute([$this->uid]);$unreadNotifications=$n->fetchAll();$unreadCount=count($unreadNotifications);}
        if(str_starts_with($view,'auth/')||str_starts_with($view,'errors/')||str_starts_with($view,'legal/')||str_starts_with($view,'install/')){require BASE_PATH.'/resources/views/'.$view.'.php';return;}
        $contentView=$view;$currentPath=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
        if($baseUrl!==''&&str_starts_with($currentPath,$baseUrl))$currentPath=substr($currentPath,strlen($baseUrl))?:'/';
        require BASE_PATH.'/resources/views/layout.php';
    }
}
