<?php
// Lista de ocorrências: as do próprio morador, as do dia para a portaria e a pesquisa do síndico.
// A lista mostra só categoria, local, data e status; a descrição aparece ao abrir a ocorrência.
$u = exigir_login();
$sindico = $u['perfil'] === 'sindico';
$porPagina = 50;
$pagina = max(1, (int) ($_GET['p'] ?? 1));

if ($sindico) {
    [$onde, $params] = filtro_ocorrencias($_GET);
    $titulo = 'Ocorrências';
    $sub = 'Todas as ocorrências do condomínio. Use os filtros para pesquisar.';
} elseif ($u['perfil'] === 'portaria' && ($_GET['ver'] ?? 'dia') === 'dia') {
    $onde = 'o.origem = "sistema" AND o.registrada_em >= ? AND o.registrada_em <= ?';
    $params = [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')];
    $titulo = 'Ocorrências do dia';
    $sub = 'Registradas hoje, por moradores e pela portaria. As fotos ficam visíveis só para o autor e o síndico.';
} else {
    $onde = 'o.autor_id = ?';
    $params = [$u['id']];
    $titulo = $u['perfil'] === 'morador' ? 'Minhas ocorrências' : 'Registradas por mim';
    $sub = 'Acompanhe o andamento e a resposta do síndico.';
}

$total = (int) consulta("SELECT COUNT(*) FROM ocorrencias o WHERE $onde", $params)->fetchColumn();
$linhas = consulta(
    "SELECT o.id, o.origem, o.registrada_em, o.hora_informada, o.urgencia, o.status, o.registrante, c.nome AS categoria, l.nome AS local
       FROM ocorrencias o JOIN categorias c ON c.id = o.categoria_id JOIN locais l ON l.id = o.local_id
      WHERE $onde
      ORDER BY (o.status = 'aberta') DESC, o.registrada_em DESC, o.id DESC
      LIMIT $porPagina OFFSET " . ($pagina - 1) * $porPagina,
    $params
)->fetchAll();

$categorias = $sindico ? consulta('SELECT id, nome FROM categorias ORDER BY nome')->fetchAll() : [];
$locais = $sindico ? consulta('SELECT id, nome FROM locais ORDER BY nome')->fetchAll() : [];
$filtros = array_intersect_key($_GET, array_flip(['de', 'ate', 'categoria', 'local', 'status', 'origem']));
$sel = fn(string $chave, $valor) => (string) ($_GET[$chave] ?? '') === (string) $valor ? ' selected' : '';
?>
<div class="PageHead">
    <div>
        <h1><?= e($titulo) ?></h1>
        <p class="PageHead-sub"><?= e($sub) ?></p>
    </div>
    <div class="PageHead-actions">
        <?php if ($sindico): ?>
            <button class="Button" type="button" data-abrir="exportar"><?= icone('exportar') ?> Exportar CSV</button>
        <?php endif ?>
        <a class="Button Button--primary" href="<?= e(url('ocorrencias/nova')) ?>"><?= icone('mais') ?> Registrar ocorrência</a>
    </div>
</div>

<?php if ($u['perfil'] === 'portaria'): ?>
    <div class="Segmented" role="group" aria-label="Quais ocorrências mostrar">
        <a class="<?= ($_GET['ver'] ?? 'dia') === 'dia' ? 'is-active' : '' ?>" href="<?= e(url('ocorrencias')) ?>">Do dia</a>
        <a class="<?= ($_GET['ver'] ?? 'dia') === 'minhas' ? 'is-active' : '' ?>" href="<?= e(url('ocorrencias?ver=minhas')) ?>">Registradas por mim</a>
    </div>
<?php endif ?>

<?php if ($sindico): ?>
    <form class="Toolbar" method="get" action="<?= e(url('ocorrencias')) ?>" aria-label="Filtros">
        <div class="Field"><label for="f-de">De</label><input class="Input" type="date" id="f-de" name="de" value="<?= e($_GET['de'] ?? '') ?>"></div>
        <div class="Field"><label for="f-ate">Até</label><input class="Input" type="date" id="f-ate" name="ate" value="<?= e($_GET['ate'] ?? '') ?>"></div>
        <div class="Field"><label for="f-cat">Categoria</label>
            <select class="Input" id="f-cat" name="categoria"><option value="">Todas</option>
                <?php foreach ($categorias as $c): ?><option value="<?= $c['id'] ?>"<?= $sel('categoria', $c['id']) ?>><?= e($c['nome']) ?></option><?php endforeach ?>
            </select></div>
        <div class="Field"><label for="f-local">Local</label>
            <select class="Input" id="f-local" name="local"><option value="">Todos</option>
                <?php foreach ($locais as $l): ?><option value="<?= $l['id'] ?>"<?= $sel('local', $l['id']) ?>><?= e($l['nome']) ?></option><?php endforeach ?>
            </select></div>
        <div class="Field"><label for="f-status">Status</label>
            <select class="Input" id="f-status" name="status"><option value="">Todos</option>
                <?php foreach (STATUS as $k => $nome): ?><option value="<?= $k ?>"<?= $sel('status', $k) ?>><?= e($nome) ?></option><?php endforeach ?>
            </select></div>
        <div class="Field"><label for="f-origem">Origem</label>
            <select class="Input" id="f-origem" name="origem"><option value="">Todas</option>
                <option value="sistema"<?= $sel('origem', 'sistema') ?>>Sistema</option>
                <option value="livro_fisico"<?= $sel('origem', 'livro_fisico') ?>>Livro físico</option>
            </select></div>
        <button class="Button Button--primary" type="submit"><?= icone('busca') ?> Filtrar</button>
        <?php if ($filtros): ?><a class="Button Button--ghost" href="<?= e(url('ocorrencias')) ?>">Limpar</a><?php endif ?>
    </form>
<?php endif ?>

<?php if (!$linhas): ?>
    <div class="Card"><div class="Empty">
        <?= icone('lista', 40) ?>
        <strong>Nenhuma ocorrência <?= $sindico && $filtros ? 'com esses filtros' : 'por aqui' ?></strong>
        <span><?= $sindico ? 'Ajuste os filtros ou aguarde novos registros.' : 'Quando você registrar uma ocorrência, ela aparece nesta lista.' ?></span>
    </div></div>
<?php else: ?>
    <p class="small muted" role="status"><?= $total ?> <?= $total === 1 ? 'ocorrência' : 'ocorrências' ?></p>
    <div class="TableWrap">
        <table class="Table is-lista">
            <thead><tr>
                <th scope="col">Nº</th><th scope="col">Data</th><th scope="col">Categoria</th><th scope="col">Local</th>
                <th scope="col">Urgência</th><?php if ($sindico): ?><th scope="col">Origem</th><?php endif ?><th scope="col">Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($linhas as $l): ?>
                <tr>
                    <td><a href="<?= e(url("ocorrencias/{$l['id']}")) ?>"><strong>nº <?= $l['id'] ?></strong></a></td>
                    <td data-rotulo="Data"><?= e(data_br($l['registrada_em'], (bool) $l['hora_informada'])) ?></td>
                    <td data-rotulo="Categoria"><?= e($l['categoria']) ?></td>
                    <td data-rotulo="Local"><?= e($l['local']) ?></td>
                    <td data-rotulo="Urgência"><?= e(URGENCIAS[$l['urgencia']]) ?></td>
                    <?php if ($sindico): ?><td data-rotulo="Origem"><?= $l['origem'] === 'sistema' ? e(REGISTRANTES[$l['registrante']]) : '<span class="Tag">Livro físico</span>' ?></td><?php endif ?>
                    <td class="c-status"><?= selo_status($l['status']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > $porPagina): ?>
        <nav class="Paginacao" aria-label="Páginas">
            <?php if ($pagina > 1): ?><a class="Button Button--small" href="<?= e(url('ocorrencias?' . http_build_query(['p' => $pagina - 1] + $_GET))) ?>">Anterior</a><?php endif ?>
            <span class="small muted">Página <?= $pagina ?> de <?= (int) ceil($total / $porPagina) ?></span>
            <?php if ($pagina * $porPagina < $total): ?><a class="Button Button--small" href="<?= e(url('ocorrencias?' . http_build_query(['p' => $pagina + 1] + $_GET))) ?>">Próxima</a><?php endif ?>
        </nav>
    <?php endif ?>
<?php endif ?>

<?php if ($sindico): ?>
    <dialog class="Modal" id="exportar" aria-labelledby="exportar-titulo">
        <form method="post" action="<?= e(url('exportar')) ?>">
            <?= csrf_campo() ?>
            <?php foreach ($filtros as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach ?>
            <div class="Modal-head"><div>
                <h2 id="exportar-titulo">Exportar ocorrências</h2>
                <p>O arquivo terá as <?= $total ?> ocorrências da lista atual, com a descrição completa. Confirme sua senha para continuar.</p>
            </div></div>
            <div class="Modal-body">
                <div class="Field"><label for="exp-senha">Sua senha</label>
                    <input class="Input" type="password" id="exp-senha" name="senha" autocomplete="current-password" required></div>
            </div>
            <div class="Modal-foot">
                <button class="Button" type="button" data-fechar>Cancelar</button>
                <button class="Button Button--primary" type="submit"><?= icone('exportar') ?> Exportar</button>
            </div>
        </form>
    </dialog>
<?php endif ?>
