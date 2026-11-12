<?php
// Detalhe da ocorrência: descrição, fotos e histórico (RF04, RF07); resposta do síndico (RF05).
// Cada abertura fica no registro de acessos (RNF02).
$u = exigir_login();
$o = buscar_ocorrencia($id);
if (!$o || !pode_ver($u, $o)) {
    // Mesma resposta para "não existe" e "não é sua": não revela quais números existem.
    erro_http(404, 'Ocorrência não encontrada.');
}

if ($metodo === 'POST') {
    $falha = match ($_POST['acao'] ?? '') {
        'status' => mover_status($u, $o, (string) ($_POST['status'] ?? ''), (string) ($_POST['comentario'] ?? '')),
        'complemento' => complementar($u, $o, (string) ($_POST['texto'] ?? '')),
        default => 'Ação desconhecida.',
    };
    avisar_tela($falha ? 'error' : 'success', $falha ?? 'Registrado no histórico da ocorrência.');
    redirecionar("ocorrencias/$id");
}

auditar('ler', $id);
consulta('UPDATE avisos SET lido_em = ? WHERE usuario_id = ? AND ocorrencia_id = ? AND lido_em IS NULL', [agora(), $u['id'], $id]);

$historico = consulta(
    'SELECT m.*, us.nome, us.perfil FROM movimentacoes m LEFT JOIN usuarios us ON us.id = m.usuario_id
      WHERE m.ocorrencia_id = ? ORDER BY m.registrada_em, m.id',
    [$id]
)->fetchAll();
$fotos = pode_ver_fotos($u, $o) ? consulta('SELECT id FROM anexos WHERE ocorrencia_id = ? ORDER BY id', [$id])->fetchAll(PDO::FETCH_COLUMN) : [];
$temFotos = (int) consulta('SELECT COUNT(*) FROM anexos WHERE ocorrencia_id = ?', [$id])->fetchColumn();
$proximos = $u['perfil'] === 'sindico' ? TRANSICOES[$o['status']] : [];
$podeComplementar = $o['origem'] === 'sistema' && !$o['anonimizada_em']
    && ($u['perfil'] === 'sindico' || (int) $o['autor_id'] === (int) $u['id']);
$titulo = "Ocorrência nº $id";
?>
<div class="PageHead">
    <div>
        <a class="PageHead-back" href="<?= e(url('ocorrencias')) ?>"><?= icone('voltar', 16) ?> Ocorrências</a>
        <h1>Ocorrência nº <?= $id ?> <?= selo_status($o['status']) ?></h1>
        <p class="PageHead-sub"><?= e($o['categoria']) ?> · <?= e($o['local']) ?><?= $o['referencia_local'] ? ' (' . e($o['referencia_local']) . ')' : '' ?></p>
    </div>
</div>

<div class="Grid Grid-main-side">
    <div class="stack">
        <section class="Card" aria-labelledby="t-registro">
            <div class="Card-head"><h3 id="t-registro">Registro</h3>
                <?php if ($o['origem'] === 'livro_fisico'): ?><span class="Tag">Importada do livro físico (nº <?= (int) $o['numero_livro'] ?>)</span><?php endif ?>
            </div>
            <dl class="DL">
                <div><dt>Registrada em</dt><dd><?= e(data_br($o['registrada_em'], (bool) $o['hora_informada'])) ?></dd></div>
                <div><dt>Registrada por</dt><dd><?= e($o['autor'] ?? REGISTRANTES[$o['registrante']]) ?><?= $o['autor'] ? ' (' . e(REGISTRANTES[$o['registrante']]) . ')' : '' ?></dd></div>
                <?php if ($o['bloco']): ?><div><dt>Unidade envolvida</dt><dd><?= e("Bloco {$o['bloco']}, unidade {$o['numero']}") ?></dd></div><?php endif ?>
                <div><dt>Urgência</dt><dd><?= e(URGENCIAS[$o['urgencia']]) ?></dd></div>
                <?php if ($o['origem'] === 'livro_fisico'): ?><div><dt>Registro completo no livro</dt><dd><?= $o['completo'] ? 'Sim' : 'Não' ?></dd></div><?php endif ?>
            </dl>
            <hr class="Divider">
            <p class="Descricao"><?= nl2br(e($o['descricao'])) ?></p>
            <?php if ($o['anonimizada_em']): ?>
                <p class="small muted">Ocorrência anonimizada em <?= e(data_br($o['anonimizada_em'], false)) ?>, ao fim do prazo de guarda.</p>
            <?php endif ?>

            <?php if ($fotos): ?>
                <div class="Fotos">
                    <?php foreach ($fotos as $n => $fotoId): ?>
                        <a href="<?= e(url("fotos/$fotoId")) ?>" target="_blank" rel="noopener">
                            <img src="<?= e(url("fotos/$fotoId")) ?>" alt="Foto <?= $n + 1 ?> da ocorrência nº <?= $id ?>" loading="lazy">
                        </a>
                    <?php endforeach ?>
                </div>
            <?php elseif ($temFotos): ?>
                <p class="small muted"><?= icone('foto', 14) ?> <?= $temFotos ?> <?= $temFotos === 1 ? 'foto anexada, visível' : 'fotos anexadas, visíveis' ?> apenas para o autor e o síndico.</p>
            <?php endif ?>
        </section>

        <section class="Card" aria-labelledby="t-historico">
            <div class="Card-head"><h3 id="t-historico"><?= icone('historico') ?> Histórico</h3><span class="Card-sub">Nada é apagado ou editado</span></div>
            <ol class="Timeline">
                <?php foreach ($historico as $m): ?>
                    <li>
                        <strong><?= match ($m['tipo']) {
                            'registro' => 'Ocorrência registrada',
                            'status' => 'Status: ' . e(STATUS[$m['status_anterior']]) . ' → ' . e(STATUS[$m['status_novo']]),
                            'complemento' => 'Complemento',
                        } ?></strong>
                        <span class="muted"> · <?= e(data_br($m['registrada_em'])) ?><?= $m['nome'] ? ' · ' . e($m['nome']) . ' (' . e(PERFIS[$m['perfil']]) . ')' : '' ?></span>
                        <?php if ($m['tipo'] !== 'registro' || $o['origem'] === 'livro_fisico'): ?>
                            <p class="Timeline-texto"><?= nl2br(e($m['comentario'])) ?></p>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ol>
        </section>
    </div>

    <div class="stack">
        <?php if ($proximos): ?>
            <form class="Card" method="post" action="<?= e(url("ocorrencias/$id")) ?>" aria-labelledby="t-responder">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="status">
                <div class="Card-head"><h3 id="t-responder">Responder e alterar status</h3></div>
                <div class="stack">
                    <div class="Field">
                        <label for="status">Novo status</label>
                        <select class="Input" id="status" name="status" required>
                            <?php foreach ($proximos as $s): ?><option value="<?= $s ?>"><?= e(STATUS[$s]) ?></option><?php endforeach ?>
                        </select>
                    </div>
                    <div class="Field">
                        <label for="comentario">Comentário para o autor</label>
                        <textarea class="Input" id="comentario" name="comentario" rows="4" minlength="5" maxlength="2000" required aria-describedby="ajuda-comentario"></textarea>
                        <span class="Field-help" id="ajuda-comentario">O autor da ocorrência lê este comentário. Depois de "Resolvida" ou "Arquivada", o status não muda mais; novas informações entram como complemento.</span>
                    </div>
                    <button class="Button Button--primary Button--block" type="submit">Salvar resposta</button>
                </div>
            </form>
        <?php elseif ($u['perfil'] === 'sindico'): ?>
            <div class="Card"><div class="Card-head"><h3>Ocorrência encerrada</h3></div>
                <p class="muted">O status "<?= e(STATUS[$o['status']]) ?>" é final. Novas informações podem ser acrescentadas como complemento.</p></div>
        <?php endif ?>

        <?php if ($podeComplementar): ?>
            <form class="Card" method="post" action="<?= e(url("ocorrencias/$id")) ?>" aria-labelledby="t-complemento">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="complemento">
                <div class="Card-head"><h3 id="t-complemento">Acrescentar complemento</h3></div>
                <div class="stack">
                    <div class="Field">
                        <label for="texto">Nova informação ou correção</label>
                        <textarea class="Input" id="texto" name="texto" rows="3" minlength="5" maxlength="2000" required aria-describedby="ajuda-texto"></textarea>
                        <span class="Field-help" id="ajuda-texto">O registro original não muda; o complemento entra no histórico.</span>
                    </div>
                    <button class="Button Button--block" type="submit">Acrescentar ao histórico</button>
                </div>
            </form>
        <?php endif ?>

        <?php if ($u['perfil'] === 'sindico'): ?>
            <a class="Button Button--ghost" href="<?= e(url("acessos?ocorrencia=$id")) ?>"><?= icone('acessos') ?> Quem acessou esta ocorrência</a>
        <?php endif ?>
    </div>
</div>
