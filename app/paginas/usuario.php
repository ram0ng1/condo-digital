<?php
// Cadastrar, desativar e alterar o perfil de usuários. Toda alteração pede a senha
// do síndico de novo e fica no registro de acessos.
$u = exigir_login('sindico');
$novo = $id === null;
$alvo = $novo ? null : consulta(
    'SELECT us.*, un.bloco, un.numero FROM usuarios us LEFT JOIN unidades un ON un.id = us.unidade_id WHERE us.id = ?',
    [$id]
)->fetch();
if (!$novo && !$alvo) {
    erro_http(404, 'Usuário não encontrado.');
}

$d = ['nome' => $alvo['nome'] ?? '', 'email' => $alvo['email'] ?? '', 'perfil' => $alvo['perfil'] ?? 'morador',
    'bloco' => $alvo['bloco'] ?? '', 'numero' => $alvo['numero'] ?? '', 'ativo' => $alvo['ativo'] ?? 1, 'senha_provisoria' => ''];
$erros = [];

if ($metodo === 'POST') {
    foreach (['nome', 'email', 'perfil', 'bloco', 'numero', 'senha_provisoria'] as $campo) {
        $d[$campo] = trim((string) ($_POST[$campo] ?? ''));
    }
    $d['email'] = mb_strtolower($d['email']);
    $d['ativo'] = $novo ? 1 : (int) isset($_POST['ativo']);

    if (!senha_confere($u, (string) ($_POST['senha'] ?? ''))) {
        $erros['senha'] = 'Senha incorreta. Confirme a sua senha para salvar.';
    }
    if (mb_strlen($d['nome']) < 3 || mb_strlen($d['nome']) > 100) {
        $erros['nome'] = 'Informe o nome (3 a 100 caracteres).';
    }
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['email']) > 150) {
        $erros['email'] = 'Informe um e-mail válido.';
    } elseif (consulta('SELECT 1 FROM usuarios WHERE email = ? AND id <> ?', [$d['email'], $id ?? 0])->fetchColumn()) {
        $erros['email'] = 'Já existe um usuário com este e-mail.';
    }
    if (!isset(PERFIS[$d['perfil']])) {
        $erros['perfil'] = 'Escolha o perfil.';
    }
    if ($d['perfil'] === 'morador' && ($d['bloco'] === '' || $d['numero'] === '')) {
        $erros['unidade'] = 'Informe o bloco e o número da unidade do morador.';
    } elseif (mb_strlen($d['bloco']) > 10 || mb_strlen($d['numero']) > 10) {
        $erros['unidade'] = 'Bloco e número podem ter até 10 caracteres.';
    }
    if ($novo || $d['senha_provisoria'] !== '') {
        if ($msg = validar_senha_nova($d['senha_provisoria'])) {
            $erros['senha_provisoria'] = $msg;
        }
    }
    // O síndico não pode se desativar nem trocar o próprio perfil: o condomínio ficaria sem administrador.
    if (!$novo && (int) $alvo['id'] === (int) $u['id'] && (!$d['ativo'] || $d['perfil'] !== 'sindico')) {
        $erros['perfil'] = 'Você não pode desativar a própria conta nem trocar o próprio perfil.';
    }

    if (!$erros) {
        $unidade = null;
        if ($d['perfil'] === 'morador') {
            $unidade = consulta('SELECT id FROM unidades WHERE bloco = ? AND numero = ?', [$d['bloco'], $d['numero']])->fetchColumn();
            if (!$unidade) {
                consulta('INSERT INTO unidades (bloco, numero) VALUES (?, ?)', [$d['bloco'], $d['numero']]);
                $unidade = bd()->lastInsertId();
            }
        }
        if ($novo) {
            consulta(
                'INSERT INTO usuarios (nome, email, senha_hash, perfil, unidade_id, trocar_senha, criado_em) VALUES (?, ?, ?, ?, ?, 1, ?)',
                [$d['nome'], $d['email'], password_hash($d['senha_provisoria'], PASSWORD_DEFAULT), $d['perfil'], $unidade, agora()]
            );
            auditar('gerenciar_usuario', null, "criou {$d['email']} ({$d['perfil']})");
            avisar_tela('success', "Usuário {$d['nome']} criado. No primeiro acesso, ele deverá trocar a senha provisória.");
        } else {
            consulta('UPDATE usuarios SET nome = ?, email = ?, perfil = ?, unidade_id = ?, ativo = ? WHERE id = ?',
                [$d['nome'], $d['email'], $d['perfil'], $unidade, $d['ativo'], $id]);
            $mudancas = [];
            if ($alvo['perfil'] !== $d['perfil']) {
                $mudancas[] = "perfil {$alvo['perfil']} -> {$d['perfil']}";
            }
            if ((int) $alvo['ativo'] !== $d['ativo']) {
                $mudancas[] = $d['ativo'] ? 'reativou' : 'desativou';
            }
            if ($d['senha_provisoria'] !== '') {
                consulta('UPDATE usuarios SET senha_hash = ?, trocar_senha = 1, tentativas_falhas = 0, bloqueado_ate = NULL WHERE id = ?',
                    [password_hash($d['senha_provisoria'], PASSWORD_DEFAULT), $id]);
                $mudancas[] = 'nova senha provisória';
            }
            auditar('gerenciar_usuario', null, "alterou {$d['email']}" . ($mudancas ? ': ' . implode(', ', $mudancas) : ''));
            avisar_tela('success', 'Usuário atualizado.');
        }
        redirecionar('usuarios');
    }
}

$titulo = $novo ? 'Novo usuário' : 'Editar usuário';
$erro = fn(string $campo) => isset($erros[$campo]) ? '<span class="Field-error" id="erro-' . $campo . '">' . e($erros[$campo]) . '</span>' : '';
$attr = fn(string $campo) => isset($erros[$campo]) ? ' aria-invalid="true" aria-describedby="erro-' . $campo . '"' : '';
?>
<div class="PageHead">
    <div>
        <a class="PageHead-back" href="<?= e(url('usuarios')) ?>"><?= icone('voltar', 16) ?> Usuários</a>
        <h1><?= e($titulo) ?></h1>
    </div>
</div>

<?php if ($erros): ?>
    <div class="Notice is-error" role="alert"><?= icone('alerta') ?><span>Confira os campos destacados e salve de novo.</span></div>
<?php endif ?>

<form class="Card" method="post" action="<?= e(url($novo ? 'usuarios/novo' : "usuarios/$id")) ?>">
    <?= csrf_campo() ?>
    <div class="FormGrid">
        <div class="Field" style="--span:6">
            <label for="nome">Nome</label>
            <input class="Input" id="nome" name="nome" maxlength="100" value="<?= e($d['nome']) ?>" required<?= $attr('nome') ?>>
            <?= $erro('nome') ?>
        </div>
        <div class="Field" style="--span:6">
            <label for="email">E-mail</label>
            <input class="Input" type="email" id="email" name="email" maxlength="150" value="<?= e($d['email']) ?>" required<?= $attr('email') ?>>
            <?= $erro('email') ?>
        </div>
        <div class="Field" style="--span:4">
            <label for="perfil">Perfil</label>
            <select class="Input" id="perfil" name="perfil"<?= $attr('perfil') ?>>
                <?php foreach (PERFIS as $k => $nome): ?><option value="<?= $k ?>"<?= $d['perfil'] === $k ? ' selected' : '' ?>><?= e($nome) ?></option><?php endforeach ?>
            </select>
            <?= $erro('perfil') ?>
        </div>
        <div class="Field" style="--span:4">
            <label for="bloco">Bloco</label>
            <input class="Input" id="bloco" name="bloco" maxlength="10" value="<?= e($d['bloco']) ?>"<?= $attr('unidade') ?>>
            <span class="Field-help">Obrigatório para morador.</span>
        </div>
        <div class="Field" style="--span:4">
            <label for="numero">Número da unidade</label>
            <input class="Input" id="numero" name="numero" maxlength="10" value="<?= e($d['numero']) ?>"<?= $attr('unidade') ?>>
            <?= $erro('unidade') ?>
        </div>
        <div class="Field" style="--span:6">
            <label for="senha_provisoria"><?= $novo ? 'Senha provisória' : 'Nova senha provisória (opcional)' ?></label>
            <input class="Input" type="password" id="senha_provisoria" name="senha_provisoria" autocomplete="new-password"<?= $novo ? ' required' : '' ?><?= $attr('senha_provisoria') ?>>
            <span class="Field-help">Pelo menos <?= (int) cfg('senha_minima') ?> caracteres. A pessoa será obrigada a trocá-la no primeiro acesso<?= $novo ? '' : '; também desbloqueia a conta' ?>.</span>
            <?= $erro('senha_provisoria') ?>
        </div>
        <?php if (!$novo): ?>
            <div class="Field" style="--span:6">
                <span class="Field-rotulo">Situação</span>
                <label class="Check"><input type="checkbox" name="ativo" value="1"<?= $d['ativo'] ? ' checked' : '' ?>> Conta ativa</label>
                <span class="Field-help">Conta desativada não entra no sistema; o histórico dela é mantido.</span>
            </div>
        <?php endif ?>

        <div class="FormSection">Confirmação</div>
        <div class="Field" style="--span:6">
            <label for="senha">Sua senha</label>
            <input class="Input" type="password" id="senha" name="senha" autocomplete="current-password" required<?= $attr('senha') ?>>
            <span class="Field-help">Gerenciar usuários exige confirmar a sua senha.</span>
            <?= $erro('senha') ?>
        </div>
    </div>
    <div class="Form-acoes">
        <a class="Button Button--ghost" href="<?= e(url('usuarios')) ?>">Cancelar</a>
        <button class="Button Button--primary" type="submit"><?= $novo ? 'Criar usuário' : 'Salvar alterações' ?></button>
    </div>
</form>
