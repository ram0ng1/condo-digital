<?php
// Minha conta: dados do usuário e troca da própria senha (obrigatória após senha provisória).
$u = exigir_login();
$erros = [];
if ($metodo === 'POST') {
    $nova = (string) ($_POST['nova'] ?? '');
    if (!senha_confere($u, (string) ($_POST['atual'] ?? ''))) {
        $erros['atual'] = 'Senha atual incorreta.';
    }
    if ($msg = validar_senha_nova($nova)) {
        $erros['nova'] = $msg;
    } elseif ($nova !== (string) ($_POST['confirmar'] ?? '')) {
        $erros['confirmar'] = 'A confirmação não é igual à senha nova.';
    } elseif ($nova === (string) ($_POST['atual'] ?? '')) {
        $erros['nova'] = 'A senha nova precisa ser diferente da atual.';
    }
    if (!$erros) {
        consulta('UPDATE usuarios SET senha_hash = ?, trocar_senha = 0 WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        auditar('trocar_senha');
        avisar_tela('success', 'Senha alterada.');
        redirecionar('');
    }
}
$titulo = 'Minha conta';
$erro = fn(string $campo) => isset($erros[$campo]) ? '<span class="Field-error" id="erro-' . $campo . '">' . e($erros[$campo]) . '</span>' : '';
$attr = fn(string $campo) => isset($erros[$campo]) ? ' aria-invalid="true" aria-describedby="erro-' . $campo . '"' : '';
?>
<div class="PageHead">
    <div>
        <h1>Minha conta</h1>
        <p class="PageHead-sub">Para corrigir nome, e-mail ou unidade, fale com o síndico.</p>
    </div>
</div>

<div class="Grid Grid-2">
    <section class="Card" aria-labelledby="t-dados">
        <div class="Card-head"><h3 id="t-dados">Meus dados</h3></div>
        <dl class="DL">
            <div><dt>Nome</dt><dd><?= e($u['nome']) ?></dd></div>
            <div><dt>E-mail</dt><dd><?= e($u['email']) ?></dd></div>
            <div><dt>Perfil</dt><dd><?= e(PERFIS[$u['perfil']]) ?></dd></div>
            <?php if ($u['bloco']): ?><div><dt>Unidade</dt><dd><?= e("Bloco {$u['bloco']}, unidade {$u['numero']}") ?></dd></div><?php endif ?>
        </dl>
        <p class="small muted">O sistema guarda apenas estes dados sobre você, além das ocorrências que registrar.</p>
    </section>

    <form class="Card" method="post" action="<?= e(url('conta')) ?>" aria-labelledby="t-senha">
        <?= csrf_campo() ?>
        <div class="Card-head"><h3 id="t-senha">Trocar a senha</h3></div>
        <div class="stack">
            <div class="Field">
                <label for="atual">Senha atual</label>
                <input class="Input" type="password" id="atual" name="atual" autocomplete="current-password" required<?= $attr('atual') ?>>
                <?= $erro('atual') ?>
            </div>
            <div class="Field">
                <label for="nova">Senha nova</label>
                <input class="Input" type="password" id="nova" name="nova" autocomplete="new-password" required<?= $attr('nova') ?>>
                <span class="Field-help">Pelo menos <?= (int) cfg('senha_minima') ?> caracteres.</span>
                <?= $erro('nova') ?>
            </div>
            <div class="Field">
                <label for="confirmar">Repita a senha nova</label>
                <input class="Input" type="password" id="confirmar" name="confirmar" autocomplete="new-password" required<?= $attr('confirmar') ?>>
                <?= $erro('confirmar') ?>
            </div>
            <div><button class="Button Button--primary" type="submit">Salvar senha nova</button></div>
        </div>
    </form>
</div>
