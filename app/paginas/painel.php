<?php
// Painel de indicadores do síndico. Os mesmos indicadores são
// calculados para o livro físico importado e para o sistema, o que permite comparar antes e depois.
$u = exigir_login('sindico');

// Categoria e local filtram tudo; período e origem filtram os indicadores, mas não o quadro "antes e depois".
[$onde, $params] = filtro_ocorrencias(array_intersect_key($_GET, array_flip(['categoria', 'local'])));
$todas = consulta(
    "SELECT o.id, o.origem, o.registrada_em, o.hora_informada, o.status, o.houve_resposta, o.respondida_em, o.completo,
            c.nome AS categoria, l.nome AS local
       FROM ocorrencias o JOIN categorias c ON c.id = o.categoria_id JOIN locais l ON l.id = o.local_id
      WHERE $onde",
    $params
)->fetchAll();

$de = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['de'] ?? '') ? $_GET['de'] : '';
$ate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ate'] ?? '') ? $_GET['ate'] : '';
$origem = in_array($_GET['origem'] ?? '', ['sistema', 'livro_fisico'], true) ? $_GET['origem'] : '';
$linhas = array_values(array_filter($todas, fn($l) => (!$origem || $l['origem'] === $origem)
    && (!$de || ($l['registrada_em'] && $l['registrada_em'] >= "$de 00:00:00"))
    && (!$ate || ($l['registrada_em'] && $l['registrada_em'] <= "$ate 23:59:59"))));

$ind = indicadores($linhas);
$pontos = pontos_de_atencao($ind, $linhas, agora(), [
    'dias_sem_resposta' => cfg('alerta_dias_sem_resposta'),
    'recorrencia_min'   => cfg('alerta_recorrencia_min'),
    'recorrencia_dias'  => cfg('alerta_recorrencia_dias'),
]);
$antes = indicadores(array_filter($todas, fn($l) => $l['origem'] === 'livro_fisico'));
$depois = indicadores(array_filter($todas, fn($l) => $l['origem'] === 'sistema'));

$situacao = array_fill_keys(array_keys(STATUS), 0);
foreach ($linhas as $l) {
    if ($l['origem'] === 'sistema') {
        $situacao[$l['status']]++;
    }
}
$serie = serie_mensal($ind['por_mes']);
$maiorMes = $serie ? max(array_map(fn($m) => max($m), $serie)) : 0;
$duasOrigens = $serie && array_sum(array_column($serie, 'livro_fisico')) && array_sum(array_column($serie, 'sistema'));
$maiorCelula = max(1, max(array_map('max', $ind['mapa'])));
$nomesMes = ['01' => 'jan', '02' => 'fev', '03' => 'mar', '04' => 'abr', '05' => 'mai', '06' => 'jun',
    '07' => 'jul', '08' => 'ago', '09' => 'set', '10' => 'out', '11' => 'nov', '12' => 'dez'];

$categorias = consulta('SELECT id, nome FROM categorias ORDER BY nome')->fetchAll();
$locais = consulta('SELECT id, nome FROM locais ORDER BY nome')->fetchAll();
$sel = fn(string $chave, $valor) => (string) ($_GET[$chave] ?? '') === (string) $valor ? ' selected' : '';
$titulo = 'Painel de indicadores';

/** Lista de barras horizontais do design system; com $acumulado, vira o diagrama de Pareto. */
$barras = function (array $itens, bool $pareto) use ($ind): void {
    $maior = max(1, ...array_column($itens, 'qtd'));
    $cortou = false;
    echo '<div class="HBar">';
    foreach ($itens as $i) {
        echo '<div class="HBar-row"><span>' . e($i['nome']) . '</span><span class="nowrap"><b>' . $i['qtd'] . '</b> · '
            . numero(percentual($i['qtd'], $ind['total'])) . ' %' . ($pareto ? ' <span class="muted">· acumulado ' . numero($i['acumulado']) . ' %</span>' : '')
            . '</span><div class="HBar-track"><div class="HBar-fill" style="width:' . round($i['qtd'] * 100 / $maior, 1) . '%"></div></div></div>';
        if ($pareto && !$cortou && $i['acumulado'] >= 80 && $i !== end($itens)) {
            echo '<div class="Pareto-corte">Até aqui: ' . numero($i['acumulado']) . ' % das ocorrências</div>';
            $cortou = true;
        }
    }
    echo '</div>';
};
?>
<div class="PageHead">
    <div>
        <h1>Painel de indicadores</h1>
        <p class="PageHead-sub">Apoio à decisão do síndico: o que mais acontece, onde, quando e quanto tempo leva a resposta.</p>
    </div>
</div>

<form class="Toolbar" method="get" action="<?= e(url('painel')) ?>" aria-label="Filtros do painel">
    <div class="Field"><label for="f-de">De</label><input class="Input" type="date" id="f-de" name="de" value="<?= e($de) ?>"></div>
    <div class="Field"><label for="f-ate">Até</label><input class="Input" type="date" id="f-ate" name="ate" value="<?= e($ate) ?>"></div>
    <div class="Field"><label for="f-cat">Categoria</label>
        <select class="Input" id="f-cat" name="categoria"><option value="">Todas</option>
            <?php foreach ($categorias as $c): ?><option value="<?= $c['id'] ?>"<?= $sel('categoria', $c['id']) ?>><?= e($c['nome']) ?></option><?php endforeach ?>
        </select></div>
    <div class="Field"><label for="f-local">Local</label>
        <select class="Input" id="f-local" name="local"><option value="">Todos</option>
            <?php foreach ($locais as $l): ?><option value="<?= $l['id'] ?>"<?= $sel('local', $l['id']) ?>><?= e($l['nome']) ?></option><?php endforeach ?>
        </select></div>
    <div class="Field"><label for="f-origem">Origem</label>
        <select class="Input" id="f-origem" name="origem"><option value="">Livro físico e sistema</option>
            <option value="sistema"<?= $sel('origem', 'sistema') ?>>Só o sistema</option>
            <option value="livro_fisico"<?= $sel('origem', 'livro_fisico') ?>>Só o livro físico</option>
        </select></div>
    <button class="Button Button--primary" type="submit"><?= icone('busca') ?> Aplicar</button>
    <?php if (array_filter($_GET)): ?><a class="Button Button--ghost" href="<?= e(url('painel')) ?>">Limpar</a><?php endif ?>
</form>

<?php if (!$ind['total']): ?>
    <div class="Card"><div class="Empty">
        <?= icone('painel', 40) ?>
        <strong>Ainda não há ocorrências para analisar</strong>
        <span>Os indicadores aparecem quando houver registros no sistema ou quando o histórico do livro físico for importado.</span>
        <a class="Button Button--primary" href="<?= e(url('importar')) ?>"><?= icone('importar') ?> Importar livro físico</a>
    </div></div>
<?php else: ?>

<div class="stack">
    <?php if ($pontos): ?>
        <section class="Card" aria-labelledby="t-pontos">
            <div class="Card-head"><h3 id="t-pontos">Pontos de atenção</h3><span class="Card-sub">Gerados pelas regras do painel a partir dos registros filtrados</span></div>
            <ul class="List">
                <?php foreach ($pontos as $ponto): [$tipo, $tituloPonto, $texto] = $ponto; $link = $ponto[3] ?? null; ?>
                    <li class="List-item Ponto<?= $tipo === 'alerta' ? ' is-alerta' : '' ?>">
                        <span class="List-icon"><?= icone($tipo === 'alerta' ? 'alerta' : 'info') ?></span>
                        <span class="List-text"><strong><?= e($tituloPonto) ?></strong><small><?= e($texto) ?></small></span>
                        <?php if ($link): ?><a class="Button Button--small" href="<?= e(url($link)) ?>">Ver</a><?php endif ?>
                    </li>
                <?php endforeach ?>
            </ul>
        </section>
    <?php endif ?>

    <section class="Grid Grid-4" aria-label="Indicadores principais">
        <div class="Kpi is-hero">
            <span class="Kpi-label">Ocorrências no período</span>
            <span class="Kpi-value"><?= $ind['total'] ?></span>
            <span class="Kpi-note">Média de <?= numero($ind['media_mensal']) ?> por mês, em <?= $ind['meses'] ?> <?= $ind['meses'] === 1 ? 'mês' : 'meses' ?></span>
        </div>
        <div class="Kpi">
            <span class="Kpi-label">Taxa de resposta</span>
            <span class="Kpi-value"><?= numero($ind['taxa_resposta']) ?> %</span>
            <span class="Kpi-note"><?= $ind['respondidas'] ?> de <?= $ind['total'] ?> com resposta registrada</span>
        </div>
        <div class="Kpi">
            <span class="Kpi-label">Tempo até a resposta</span>
            <span class="Kpi-value"><?= dias($ind['tempo_mediana']) ?></span>
            <span class="Kpi-note">Mediana de <?= $ind['com_tempo'] ?> respostas · maior: <?= dias($ind['tempo_maior']) ?></span>
        </div>
        <div class="Kpi">
            <span class="Kpi-label">Recorrência</span>
            <span class="Kpi-value"><?= numero($ind['recorrencia']) ?> %</span>
            <span class="Kpi-note">Mesma categoria e local em até 30 dias</span>
        </div>
    </section>

    <?php if (array_sum($situacao)): ?>
        <section class="Card" aria-labelledby="t-situacao">
            <div class="Card-head"><h3 id="t-situacao">Situação das ocorrências do sistema</h3></div>
            <div class="StatRow">
                <?php foreach ($situacao as $s => $n): ?>
                    <a class="Stat" href="<?= e(url("ocorrencias?origem=sistema&status=$s")) ?>">
                        <span class="Stat-label"><?= e(STATUS[$s]) ?></span><span class="Stat-value"><?= $n ?></span>
                    </a>
                <?php endforeach ?>
            </div>
        </section>
    <?php endif ?>

    <div class="Grid Grid-2">
        <section class="Card" aria-labelledby="t-pareto">
            <div class="Card-head"><h3 id="t-pareto">Categorias (diagrama de Pareto)</h3><span class="Card-sub">Da mais para a menos frequente, com a proporção acumulada</span></div>
            <?php $barras($ind['pareto'], true) ?>
        </section>

        <section class="Card" aria-labelledby="t-local">
            <div class="Card-head"><h3 id="t-local">Locais</h3><span class="Card-sub">Onde as ocorrências acontecem</span></div>
            <?php $barras(array_map(fn($nome, $qtd) => ['nome' => $nome, 'qtd' => $qtd], array_keys($ind['por_local']), $ind['por_local']), false) ?>
        </section>
    </div>

    <section class="Card" aria-labelledby="t-mes">
        <div class="Card-head"><h3 id="t-mes">Ocorrências por mês</h3><span class="Card-sub">Passe o cursor ou use Tab para ver os valores</span></div>
        <div class="Bars-rolagem">
            <div class="Bars" role="img" aria-label="Gráfico de barras com o número de ocorrências por mês. Os mesmos dados estão na tabela abaixo.">
                <?php foreach ($serie as $mes => $v): [$ano, $m] = explode('-', $mes); ?>
                    <div class="Bars-col" tabindex="0">
                        <div class="Bars-plot">
                            <?php if ($v['livro_fisico'] || !$v['sistema']): ?><div class="Bars-bar" style="height:<?= round($v['livro_fisico'] * 100 / $maiorMes, 1) ?>%"></div><?php endif ?>
                            <?php if ($v['sistema']): ?><div class="Bars-bar is-b" style="height:<?= round($v['sistema'] * 100 / $maiorMes, 1) ?>%"></div><?php endif ?>
                            <div class="Bars-tip"><b><?= $nomesMes[$m] ?>/<?= $ano ?></b><br>Livro físico: <?= $v['livro_fisico'] ?><br>Sistema: <?= $v['sistema'] ?></div>
                        </div>
                        <span class="Bars-label"><?= $nomesMes[$m] ?></span><span class="Bars-sub"><?= $ano ?></span>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
        <?php if ($duasOrigens): ?>
            <div class="Legend"><span><i style="background:var(--primary-chart)"></i>Livro físico</span><span><i style="background:var(--primary-chart-strong)"></i>Sistema</span></div>
        <?php endif ?>
        <details class="TabelaDados">
            <summary>Ver os dados em tabela</summary>
            <div class="Rolagem"><table class="Table is-compact">
                <thead><tr><th scope="col">Mês</th><th scope="col" class="num">Livro físico</th><th scope="col" class="num">Sistema</th></tr></thead>
                <tbody><?php foreach ($serie as $mes => $v): [$ano, $m] = explode('-', $mes); ?>
                    <tr><td><?= $nomesMes[$m] ?>/<?= $ano ?></td><td class="num"><?= $v['livro_fisico'] ?></td><td class="num"><?= $v['sistema'] ?></td></tr>
                <?php endforeach ?></tbody>
            </table></div>
        </details>
    </section>

    <div class="Grid Grid-2">
        <section class="Card" aria-labelledby="t-mapa">
            <div class="Card-head"><h3 id="t-mapa">Dia da semana e turno</h3><span class="Card-sub">Quanto mais escuro, mais ocorrências</span></div>
            <div class="Rolagem"><table class="Mapa">
                <thead><tr><td></td><?php foreach (TURNOS as $t): ?><th scope="col"><?= e($t) ?></th><?php endforeach ?></tr></thead>
                <tbody>
                <?php foreach ($ind['mapa'] as $dia => $turnos): ?>
                    <tr><th scope="row"><?= DIAS_SEMANA[$dia] ?></th>
                        <?php foreach ($turnos as $n): $i = $n / $maiorCelula; ?>
                            <td style="--i:<?= round($i, 2) ?>"><?= $n ?></td>
                        <?php endforeach ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <p class="small muted">Madrugada: 0h–6h · Manhã: 6h–12h · Tarde: 12h–18h · Noite: 18h–24h. Registros sem hora não entram neste quadro.</p>
        </section>

        <section class="Card" aria-labelledby="t-antes">
            <div class="Card-head"><h3 id="t-antes">Antes e depois</h3><span class="Card-sub">Livro físico importado × sistema, sem o filtro de período</span></div>
            <?php if (!$antes['total'] || !$depois['total']): ?>
                <p class="muted">A comparação aparece quando houver registros das duas origens: o histórico importado do livro físico e as ocorrências feitas no sistema.</p>
            <?php else: ?>
                <div class="Rolagem"><table class="Table is-compact">
                    <thead><tr><th scope="col">Indicador</th><th scope="col" class="num">Livro físico</th><th scope="col" class="num">Sistema</th></tr></thead>
                    <tbody>
                        <tr><td>Total de registros</td><td class="num"><?= $antes['total'] ?></td><td class="num"><?= $depois['total'] ?></td></tr>
                        <tr><td>Média por mês</td><td class="num"><?= numero($antes['media_mensal']) ?></td><td class="num"><?= numero($depois['media_mensal']) ?></td></tr>
                        <tr><td>Taxa de resposta</td><td class="num"><?= numero($antes['taxa_resposta']) ?> %</td><td class="num"><?= numero($depois['taxa_resposta']) ?> %</td></tr>
                        <tr><td>Tempo até a resposta (mediana)</td><td class="num"><?= dias($antes['tempo_mediana']) ?></td><td class="num"><?= dias($depois['tempo_mediana']) ?></td></tr>
                        <tr><td>Maior tempo até a resposta</td><td class="num"><?= dias($antes['tempo_maior']) ?></td><td class="num"><?= dias($depois['tempo_maior']) ?></td></tr>
                        <tr><td>Recorrência em 30 dias</td><td class="num"><?= numero($antes['recorrencia']) ?> %</td><td class="num"><?= numero($depois['recorrencia']) ?> %</td></tr>
                        <tr><td>Registros incompletos</td><td class="num"><?= numero($antes['incompletos']) ?> %</td><td class="num"><?= numero($depois['incompletos']) ?> %</td></tr>
                    </tbody>
                </table></div>
            <?php endif ?>
        </section>
    </div>
</div>
<?php endif ?>
