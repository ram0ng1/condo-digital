<?php
// Lista de usuários, só para o síndico.
$u = exigir_login('sindico');
$usuarios = consulta(
    'SELECT us.id, us.nome, us.email, us.perfil, us.ativo, us.bloqueado_ate, un.bloco, un.numero
       FROM usuarios us LEFT JOIN unidades un ON un.id = us.unidade_id
      ORDER BY us.ativo DESC, FIELD(us.perfil, "sindico", "portaria", "morador"), us.nome'
)->fetchAll();
$titulo = 'Usuários';
?>
<div class="PageHead">
    <div>
        <h1>Usuários</h1>
        <p class="PageHead-sub">Cada pessoa tem a própria conta. O perfil define o que ela pode ver: o morador vê só o que registrou; a portaria, o que registrou e as ocorrências do dia; o síndico, tudo.</p>
    </div>
    <div class="PageHead-actions">
        <a class="Button Button--primary" href="<?= e(url('usuarios/novo')) ?>"><?= icone('mais') ?> Novo usuário</a>
    </div>
</div>

<div class="TableWrap">
    <table class="Table is-lista">
        <thead><tr><th scope="col">Nome</th><th scope="col">E-mail</th><th scope="col">Perfil</th><th scope="col">Unidade</th><th scope="col">Situação</th><th scope="col"><span class="SoLeitor">Ações</span></th></tr></thead>
        <tbody>
        <?php foreach ($usuarios as $us): ?>
            <tr<?= $us['ativo'] ? '' : ' class="is-muted"' ?>>
                <td><div class="Cell-title"><span class="Avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($us['nome'], 0, 1))) ?></span><strong><?= e($us['nome']) ?></strong></div></td>
                <td class="c-inteira" data-rotulo="E-mail"><?= e($us['email']) ?></td>
                <td data-rotulo="Perfil"><?= e(PERFIS[$us['perfil']]) ?></td>
                <td data-rotulo="Unidade"><?= $us['bloco'] ? e("{$us['bloco']}-{$us['numero']}") : '—' ?></td>
                <td class="c-status"><?php if (!$us['ativo']): ?><span class="Pill">Desativado</span>
                    <?php elseif ($us['bloqueado_ate'] && $us['bloqueado_ate'] > agora()): ?><span class="Pill is-red">Bloqueado até <?= e(date('H:i', strtotime($us['bloqueado_ate']))) ?></span>
                    <?php else: ?><span class="Pill is-green">Ativo</span><?php endif ?></td>
                <td class="actions c-inteira"><a class="Button Button--small" href="<?= e(url("usuarios/{$us['id']}")) ?>">Editar<span class="SoLeitor"> <?= e($us['nome']) ?></span></a></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
