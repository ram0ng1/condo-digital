<?php
// RF01: autenticação. A mensagem de erro é a mesma para e-mail inexistente e senha errada.
if (usuario()) {
    redirecionar('');
}
$erro = null;
$email = '';
if ($metodo === 'POST') {
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $resultado = autenticar($email, (string) ($_POST['senha'] ?? ''));
    if (is_int($resultado)) {
        session_regenerate_id(true); // impede o reaproveitamento de uma sessão anterior ao login
        unset($_SESSION['csrf']);
        $_SESSION['usuario_id'] = $resultado;
        auditar('entrar');
        redirecionar('');
    }
    $erro = $resultado;
}
$titulo = 'Entrar';
$semCasca = true;
?>
<div class="Login">
    <section class="Login-hero" aria-hidden="true">
        <div class="Brand">
            <span class="Brand-mark"><?= icone('livro', 20) ?></span>
            <span class="Brand-text">
                <span class="Brand-name">Livro de Ocorrências</span>
                <span class="Brand-sub"><?= e(cfg('nome_condominio')) ?></span>
            </span>
        </div>
        <div class="Login-pitch">
            <strong>O livro de ocorrências do condomínio, agora digital.</strong>
            <p>Registre pelo celular, acompanhe a resposta do síndico e ajude a gestão a decidir com base em dados.</p>
        </div>
        <ul class="Login-points">
            <li><?= icone('ok') ?> Registro a qualquer hora, com foto</li>
            <li><?= icone('ok') ?> Você acompanha o andamento e a resposta</li>
            <li><?= icone('ok') ?> Só vê a ocorrência quem tem direito</li>
        </ul>
    </section>
    <section class="Login-panel">
        <form class="Login-card" method="post" action="<?= e(url('entrar')) ?>">
            <?= csrf_campo() ?>
            <h1>Entrar</h1>
            <p class="Login-lead">Use o e-mail e a senha cadastrados pelo síndico.</p>
            <?php if ($erro): ?>
                <div class="Notice is-error" role="alert"><?= icone('alerta') ?><span><?= e($erro) ?></span></div>
            <?php endif ?>
            <div class="Field">
                <label for="email">E-mail</label>
                <input class="Input" type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
            </div>
            <div class="Field">
                <label for="senha">Senha</label>
                <input class="Input" type="password" id="senha" name="senha" autocomplete="current-password" required>
                <label class="Check Check--menor"><input type="checkbox" data-mostrar-senha="senha"> Mostrar a senha</label>
            </div>
            <button class="Button Button--primary Button--lg Button--block" type="submit">Entrar</button>
            <p class="small muted">Esqueceu a senha? Peça ao síndico uma senha provisória.</p>
        </form>
    </section>
</div>
