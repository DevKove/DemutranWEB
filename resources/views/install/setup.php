<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Configuração inicial · DemutranWEB</title>
<link rel="stylesheet" href="<?= e($baseUrl) ?>/assets/css/app.css">
</head>
<body class="login-page">
<main class="login-shell">
<section class="login-brand"><div class="brand-mark">D</div><span class="eyebrow">CONFIGURAÇÃO PROTEGIDA</span><h1>Demutran<span>WEB</span></h1><p>Criação do primeiro administrador do sistema.</p><div class="road-art"><i></i><i></i><i></i></div><small>ACESSO RESTRITO · USO INSTITUCIONAL</small></section>
<section class="login-panel">
<div class="login-heading"><span class="eyebrow">INSTALAÇÃO INICIAL</span><h2>Criar administrador</h2><p>Esta tela fica indisponível automaticamente após a criação da primeira conta.</p></div>
<?php if(!empty($error)): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e($baseUrl) ?>/install" class="form-stack">
<input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
<label>Chave de instalação<input type="password" name="install_token" autocomplete="off" required></label>
<label>Nome completo<input name="name" maxlength="160" required autocomplete="name"></label>
<label>E-mail institucional<input type="email" name="email" maxlength="190" required autocomplete="email"></label>
<label>Matrícula (opcional)<input name="registration" maxlength="40"></label>
<label>Senha inicial (mínimo 12 caracteres)<input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
<button class="btn primary wide">Criar administrador <span>→</span></button>
</form>
<p class="muted login-foot">Após concluir, remova INSTALL_TOKEN do arquivo .env e mantenha o backup seguro.</p>
</section></main></body></html>