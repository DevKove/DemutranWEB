<?php
declare(strict_types=1);

$dsn=sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',getenv('DB_HOST')?:'127.0.0.1',getenv('DB_PORT')?:'3306',getenv('DB_NAME')?:'demutranweb');
$pdo=new PDO($dsn,getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$requiredTables=[
    'roles','users','permissions','role_permissions','user_permissions','audit_logs',
    'accidents','accident_vehicles','accident_people','accident_witnesses','accident_staff',
    'accident_signatures','shifts','shift_members','service_orders','service_order_history',
    'service_order_executors','service_order_updates','file_attachments','notifications',
    'sequences','system_settings','login_attempts',
];
$check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
foreach($requiredTables as $table){$check->execute([$table]);if((int)$check->fetchColumn()!==1)throw new RuntimeException("Tabela ausente: $table");}
$adminId=(int)$pdo->query("SELECT id FROM roles WHERE name='Administrador'")->fetchColumn();
if(!$adminId)throw new RuntimeException('Perfil Administrador não foi criado.');
$permissions=(int)$pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();
$granted=$pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id=?');$granted->execute([$adminId]);
if((int)$granted->fetchColumn()!==$permissions)throw new RuntimeException('Administrador não possui todas as permissões.');
foreach(['escalas.publish','ordens.transition','users.permissions','reports.export'] as $key){
    $q=$pdo->prepare('SELECT COUNT(*) FROM permissions WHERE permission_key=?');$q->execute([$key]);
    if((int)$q->fetchColumn()!==1)throw new RuntimeException("Permissão ausente: $key");
}
echo "Schema MySQL validado: ".count($requiredTables)." tabelas, $permissions permissões e perfil administrador completo.\n";
