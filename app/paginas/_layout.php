<?php
// Moldura comum a todas as páginas (design system: casca com menu lateral, barra superior e conteúdo).
// Recebe $titulo e $conteudo; $semCasca = true mostra só o conteúdo (tela de entrada).

$u = usuario();
$rota = trim(substr(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen(base())), '/');
$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

$menu = [];
if ($u) {
    if ($u['perfil'] === 'sindico') {
        $menu[] = ['painel', 'painel', 'Painel de indicadores'];
    }
    $menu[] = ['ocorrencias', 'lista', $u['perfil'] === 'morador' ? 'Minhas ocorrências' : 'Ocorrências'];
    $menu[] = ['ocorrencias/nova', 'mais', 'Registrar ocorrência'];
    $menu[] = ['avisos', 'sino', 'Avisos'];
    if ($u['perfil'] === 'sindico') {
        $menu[] = ['importar', 'importar', 'Importar livro físico'];
        $menu[] = ['usuarios', 'usuarios', 'Usuários'];
        $menu[] = ['acessos', 'acessos', 'Registro de acessos'];
    }
    $menu[] = ['conta', 'conta', 'Minha conta'];
    $naoLidos = avisos_nao_lidos((int) $u['id']);
}
$ativo = function (string $link) use ($rota): bool {
    if ($link === 'ocorrencias') {
        return $rota === 'ocorrencias' || (bool) preg_match('#^ocorrencias/\d+$#', $rota);
    }
    return $rota === $link || str_starts_with($rota, "$link/");
};
?>
<!doctype html>
<html lang="pt-BR" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo ? "$titulo · " : '') ?>Livro de Ocorrências</title>
<link rel="stylesheet" href="<?= e(url('assets/ds.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/app.css')) ?>">
<script src="<?= e(url('assets/app.js')) ?>" defer></script>
</head>
<body>
<?php if (!$u || !empty($semCasca)): ?>
    <?php foreach ($flash as [$tipo, $msg]): ?>
        <div class="Notice is-<?= e($tipo) ?> Notice--topo" role="status"><?= e($msg) ?></div>
    <?php endforeach ?>
    <?= $conteudo ?>
<?php else: ?>
<a class="PuloConteudo" href="#conteudo">Ir para o conteúdo</a>
<div class="Shell">
    <aside class="Sidebar" id="menu">
        <a class="Brand" href="<?= e(url()) ?>">
            <span class="Brand-mark"><?= icone('livro', 20) ?></span>
            <span class="Brand-text">
                <span class="Brand-name">Livro de Ocorrências</span>
                <span class="Brand-sub"><?= e(cfg('nome_condominio')) ?></span>
            </span>
        </a>
        <nav aria-label="Menu principal">
            <?php foreach ($menu as [$link, $icone, $rotulo]): ?>
                <a class="Sidebar-link<?= $ativo($link) ? ' is-active' : '' ?>" href="<?= e(url($link)) ?>"<?= $ativo($link) ? ' aria-current="page"' : '' ?>>
                    <?= icone($icone) ?><span><?= e($rotulo) ?></span>
                    <?php if ($link === 'avisos' && $naoLidos): ?>
                        <b class="Sidebar-count" aria-label="<?= $naoLidos ?> não lidos"><?= $naoLidos ?></b>
                    <?php endif ?>
                </a>
            <?php endforeach ?>
        </nav>
        <form method="post" action="<?= e(url('sair')) ?>">
            <?= csrf_campo() ?>
            <button class="Sidebar-link" type="submit"><?= icone('sair') ?><span>Sair</span></button>
        </form>
    </aside>

    <div class="Main">
        <header class="Topbar">
            <button class="IconBtn Topbar-menu" type="button" aria-controls="menu" aria-expanded="false" aria-label="Abrir o menu" data-menu><?= icone('menu', 20) ?></button>
            <span class="spacer"></span>
            <a class="IconBtn" href="<?= e(url('avisos')) ?>" aria-label="Avisos<?= $naoLidos ? ": $naoLidos não lidos" : '' ?>">
                <?= icone('sino', 20) ?>
                <?php if ($naoLidos): ?><span class="Bell-badge"><?= $naoLidos ?></span><?php endif ?>
            </a>
            <a class="UserChip" href="<?= e(url('conta')) ?>">
                <span class="Avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($u['nome'], 0, 1))) ?></span>
                <span class="UserChip-text">
                    <strong><?= e($u['nome']) ?></strong>
                    <small><?= e(PERFIS[$u['perfil']]) ?><?= $u['bloco'] ? ' · ' . e("{$u['bloco']}-{$u['numero']}") : '' ?></small>
                </span>
            </a>
        </header>

        <main class="Content" id="conteudo">
            <div class="Content-inner">
                <?php if (instalacao_demo()): ?>
                    <div class="Notice" role="note"><?= icone('info') ?><span><strong>Ambiente de demonstração.</strong> Todos os usuários e ocorrências desta instalação são fictícios.</span></div>
                <?php endif ?>
                <?php foreach ($flash as [$tipo, $msg]): ?>
                    <div class="Notice is-<?= e($tipo) ?>" role="status"><?= icone($tipo === 'error' ? 'alerta' : ($tipo === 'success' ? 'ok' : 'info')) ?><span><?= e($msg) ?></span></div>
                <?php endforeach ?>
                <?= $conteudo ?>
                <footer class="Footer">
                    <span>Livro de Ocorrências Digital · TCC de Sistemas de Informação · UDF</span>
                    <span>Sessão expira após <?= (int) cfg('sessao_minutos') ?> min sem uso</span>
                </footer>
            </div>
        </main>
    </div>
</div>
<?php endif ?>
</body>
</html>
