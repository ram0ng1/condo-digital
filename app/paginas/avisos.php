<?php
// RF06: avisos na tela (nova ocorrência para o síndico; mudança de status para o autor).
$u = exigir_login();
if ($metodo === 'POST') {
    consulta('UPDATE avisos SET lido_em = ? WHERE usuario_id = ? AND lido_em IS NULL', [agora(), $u['id']]);
    redirecionar('avisos');
}
$avisos = consulta('SELECT * FROM avisos WHERE usuario_id = ? ORDER BY id DESC LIMIT 100', [$u['id']])->fetchAll();
$naoLidosAqui = count(array_filter($avisos, fn($a) => !$a['lido_em']));
$titulo = 'Avisos';
?>
<div class="PageHead">
    <div>
        <h1>Avisos</h1>
        <p class="PageHead-sub">Você também recebe estes avisos por e-mail. Abrir a ocorrência marca o aviso como lido.</p>
    </div>
    <?php if ($naoLidosAqui): ?>
        <form class="PageHead-actions" method="post" action="<?= e(url('avisos')) ?>">
            <?= csrf_campo() ?>
            <button class="Button" type="submit"><?= icone('ok') ?> Marcar todos como lidos</button>
        </form>
    <?php endif ?>
</div>

<div class="Card">
    <?php if (!$avisos): ?>
        <div class="Empty"><?= icone('sino', 40) ?><strong>Nenhum aviso por enquanto</strong>
            <span>Quando houver novidade em uma ocorrência, ela aparece aqui.</span></div>
    <?php else: ?>
        <ul class="List">
            <?php foreach ($avisos as $a): ?>
                <li>
                    <a class="List-item is-link" href="<?= e(url($a['ocorrencia_id'] ? "ocorrencias/{$a['ocorrencia_id']}" : 'avisos')) ?>">
                        <span class="List-icon"><?= icone('sino') ?></span>
                        <span class="List-text">
                            <strong><?= e($a['mensagem']) ?></strong>
                            <small><?= e(data_br($a['criado_em'])) ?></small>
                        </span>
                        <?php if (!$a['lido_em']): ?><span class="Pill is-green">Novo</span><?php endif ?>
                    </a>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</div>
