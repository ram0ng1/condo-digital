<?php
// RNF02: registro de acessos. Mostra quem leu, criou ou alterou cada ocorrência, e quando.
$u = exigir_login('sindico');

const ACOES = ['entrar' => 'Entrou no sistema', 'entrar_falha' => 'Senha incorreta', 'sair' => 'Saiu', 'ler' => 'Leu a ocorrência',
    'criar' => 'Registrou ocorrência', 'alterar_status' => 'Alterou o status', 'complementar' => 'Complementou',
    'exportar' => 'Exportou ocorrências', 'importar' => 'Importou o livro físico', 'gerenciar_usuario' => 'Gerenciou usuário',
    'trocar_senha' => 'Trocou a própria senha', 'anonimizar' => 'Anonimizou (retenção)'];

$sql = [];
$params = [];
if (ctype_digit($_GET['ocorrencia'] ?? '')) {
    $sql[] = 'r.ocorrencia_id = ?';
    $params[] = (int) $_GET['ocorrencia'];
}
if (ctype_digit($_GET['usuario'] ?? '')) {
    $sql[] = 'r.usuario_id = ?';
    $params[] = (int) $_GET['usuario'];
}
if (isset(ACOES[$_GET['acao'] ?? ''])) {
    $sql[] = 'r.acao = ?';
    $params[] = $_GET['acao'];
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['dia'] ?? '')) {
    $sql[] = 'r.ocorrido_em BETWEEN ? AND ?';
    array_push($params, $_GET['dia'] . ' 00:00:00', $_GET['dia'] . ' 23:59:59');
}
$onde = $sql ? implode(' AND ', $sql) : '1 = 1';
$porPagina = 100;
$pag = max(1, (int) ($_GET['p'] ?? 1));
$total = (int) consulta("SELECT COUNT(*) FROM registros_acesso r WHERE $onde", $params)->fetchColumn();
$registros = consulta(
    "SELECT r.*, us.nome, us.perfil FROM registros_acesso r LEFT JOIN usuarios us ON us.id = r.usuario_id
      WHERE $onde ORDER BY r.id DESC LIMIT $porPagina OFFSET " . ($pag - 1) * $porPagina,
    $params
)->fetchAll();
$usuarios = consulta('SELECT id, nome FROM usuarios ORDER BY nome')->fetchAll();
$sel = fn(string $chave, $valor) => (string) ($_GET[$chave] ?? '') === (string) $valor ? ' selected' : '';
$titulo = 'Registro de acessos';
?>
<div class="PageHead">
    <div>
        <h1>Registro de acessos</h1>
        <p class="PageHead-sub">Toda leitura, criação e alteração de ocorrência fica registrada com usuário, data e hora. Os registros não podem ser alterados nem apagados.</p>
    </div>
</div>

<form class="Toolbar" method="get" action="<?= e(url('acessos')) ?>" aria-label="Filtros">
    <div class="Field"><label for="f-oc">Ocorrência nº</label><input class="Input" type="number" min="1" id="f-oc" name="ocorrencia" value="<?= e($_GET['ocorrencia'] ?? '') ?>"></div>
    <div class="Field"><label for="f-us">Usuário</label>
        <select class="Input" id="f-us" name="usuario"><option value="">Todos</option>
            <?php foreach ($usuarios as $us): ?><option value="<?= $us['id'] ?>"<?= $sel('usuario', $us['id']) ?>><?= e($us['nome']) ?></option><?php endforeach ?>
        </select></div>
    <div class="Field"><label for="f-acao">Ação</label>
        <select class="Input" id="f-acao" name="acao"><option value="">Todas</option>
            <?php foreach (ACOES as $k => $nome): ?><option value="<?= $k ?>"<?= $sel('acao', $k) ?>><?= e($nome) ?></option><?php endforeach ?>
        </select></div>
    <div class="Field"><label for="f-dia">Dia</label><input class="Input" type="date" id="f-dia" name="dia" value="<?= e($_GET['dia'] ?? '') ?>"></div>
    <button class="Button Button--primary" type="submit"><?= icone('busca') ?> Filtrar</button>
    <?php if ($sql): ?><a class="Button Button--ghost" href="<?= e(url('acessos')) ?>">Limpar</a><?php endif ?>
</form>

<?php if (!$registros): ?>
    <div class="Card"><div class="Empty"><?= icone('acessos', 40) ?><strong>Nenhum registro com esses filtros</strong></div></div>
<?php else: ?>
    <p class="small muted" role="status"><?= $total ?> <?= $total === 1 ? 'registro' : 'registros' ?></p>
    <div class="TableWrap">
        <table class="Table is-compact is-lista">
            <thead><tr><th scope="col">Data e hora</th><th scope="col">Usuário</th><th scope="col">Ação</th><th scope="col">Ocorrência</th><th scope="col">Detalhe</th></tr></thead>
            <tbody>
            <?php foreach ($registros as $r): ?>
                <tr>
                    <td data-rotulo="Data e hora"><?= e(date('d/m/Y H:i:s', strtotime($r['ocorrido_em']))) ?></td>
                    <td data-rotulo="Usuário"><?= $r['nome'] ? e($r['nome']) . ' <small>' . e(PERFIS[$r['perfil']]) . '</small>' : '—' ?></td>
                    <td data-rotulo="Ação"><?= e(ACOES[$r['acao']]) ?></td>
                    <td data-rotulo="Ocorrência"><?= $r['ocorrencia_id'] ? '<a href="' . e(url("ocorrencias/{$r['ocorrencia_id']}")) . '">nº ' . (int) $r['ocorrencia_id'] . '</a>' : '—' ?></td>
                    <td class="wrap c-inteira" data-rotulo="Detalhe"><?= e($r['detalhe'] ?? '') ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > $porPagina): ?>
        <nav class="Paginacao" aria-label="Páginas">
            <?php if ($pag > 1): ?><a class="Button Button--small" href="<?= e(url('acessos?' . http_build_query(['p' => $pag - 1] + $_GET))) ?>">Anterior</a><?php endif ?>
            <span class="small muted">Página <?= $pag ?> de <?= (int) ceil($total / $porPagina) ?></span>
            <?php if ($pag * $porPagina < $total): ?><a class="Button Button--small" href="<?= e(url('acessos?' . http_build_query(['p' => $pag + 1] + $_GET))) ?>">Próxima</a><?php endif ?>
        </nav>
    <?php endif ?>
<?php endif ?>
