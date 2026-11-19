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

// Exercita os INSERTs principais usados pelos módulos, sem persistir dados de teste.
$pdo->beginTransaction();
try {
    $user=$pdo->prepare("INSERT INTO users(name,email,role_id,status,password_hash,created_at) VALUES('CI Test','ci-test@example.invalid',?,'Ativo',?,NOW())");
    $user->execute([$adminId,password_hash('temporary-test-password',PASSWORD_DEFAULT)]);
    $uid=(int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO sequences(sequence_key,next_value,updated_at) VALUES('service_order',1,NOW()) ON DUPLICATE KEY UPDATE next_value=next_value");
    $seq=(int)$pdo->query("SELECT next_value FROM sequences WHERE sequence_key='service_order' FOR UPDATE")->fetchColumn();
    $pdo->exec("UPDATE sequences SET next_value=next_value+1,updated_at=NOW() WHERE sequence_key='service_order'");
    $order=$pdo->prepare("INSERT INTO service_orders(order_number,opened_at,requester,requesting_department,service_type,description,location,priority,status,due_at,responsible_id,created_by,created_at) VALUES(?,NOW(),?,?,?,?,?,?, 'Solicitada',?,?,?,NOW())");
    $order->execute([$seq,'CI Test','Teste','Teste','Teste de integração','Endereço de teste','Baixa',null,$uid,$uid]);
    $orderId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO service_order_history(order_id,user_id,old_status,new_status,note,created_at) VALUES(?, ?, NULL, 'Solicitada', 'Teste', NOW())")->execute([$orderId,$uid]);
    $pdo->prepare('INSERT INTO service_order_executors(order_id,user_id) VALUES(?,?)')->execute([$orderId,$uid]);
    $pdo->prepare('INSERT INTO service_order_updates(order_id,user_id,update_type,description,created_at) VALUES(?,?,?,?,NOW())')->execute([$orderId,$uid,'Execução','Teste',$uid]);

    $shift=$pdo->prepare("INSERT INTO shifts(title,service_type,service_date,start_time,end_time,starts_at,ends_at,location,team_name,manager_id,status,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,'Rascunho',?,NOW())");
    $shift->execute(['CI Test','Teste',date('Y-m-d'),'08:00','09:00',date('Y-m-d').' 08:00:00',date('Y-m-d').' 09:00:00','Teste','Equipe',$uid,'Teste']);
    $shiftId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO shift_members(shift_id,user_id) VALUES(?,?)')->execute([$shiftId,$uid]);

    $accident=$pdo->prepare('INSERT INTO accidents(protocol,occurred_at,location,address,neighborhood,reference_point,road_type,weather,accident_type,description,has_victims,has_injuries,has_fatalities,public_property_damage,signage_damage,needs_external_support,external_support_notes,notes,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
    $accident->execute(['CI-'.bin2hex(random_bytes(4)),date('Y-m-d H:i:s'),'Teste',null,null,null,null,null,'Outros','Teste',0,0,0,0,0,0,null,null,$uid]);
    $accidentId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO accident_vehicles(accident_id,model) VALUES(?,?)')->execute([$accidentId,'Veículo de teste']);
    $pdo->prepare("INSERT INTO accident_staff(accident_id,user_id,assignment_role) VALUES(?,?,'Equipe')")->execute([$accidentId,$uid]);
    $pdo->prepare('INSERT INTO accident_witnesses(accident_id,name,contact,notes) VALUES(?,?,?,?)')->execute([$accidentId,'Testemunha',null,null]);
    $pdo->prepare('INSERT INTO accident_signatures(accident_id,user_id,signature_data,signed_at) VALUES(?,?,?,NOW())')->execute([$accidentId,$uid,'data:image/png;base64,dGVzdA==']);

    $pdo->rollBack();
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    throw $e;
}
echo "MySQL smoke test OK: ".count($requiredTables)." tabelas, $permissions permissões e INSERTs dos módulos validados.\n";
