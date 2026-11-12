<?php
// Registrar ocorrência, com até três fotos. A data e a hora são as do servidor, não as do aparelho.
$u = exigir_login();
$erros = [];
$d = ['categoria_id' => '', 'local_id' => '', 'referencia_local' => '', 'unidade_id' => '', 'urgencia' => 'media', 'descricao' => ''];

if ($metodo === 'POST') {
    $d = array_merge($d, array_intersect_key($_POST, $d));
    $resultado = criar_ocorrencia($u, $d, fotos_enviadas($_FILES['fotos'] ?? null));
    if (is_int($resultado)) {
        avisar_tela('success', "Ocorrência nº $resultado registrada. O síndico foi avisado.");
        redirecionar("ocorrencias/$resultado");
    }
    $erros = $resultado;
}

$categorias = consulta('SELECT id, nome FROM categorias ORDER BY (nome = "Outros"), nome')->fetchAll();
$locais = consulta('SELECT id, nome FROM locais ORDER BY (nome = "Outro"), nome')->fetchAll();
$unidades = $u['perfil'] === 'morador' ? [] : consulta('SELECT id, bloco, numero FROM unidades ORDER BY bloco, numero')->fetchAll();
$titulo = 'Registrar ocorrência';
$erro = fn(string $campo) => isset($erros[$campo])
    ? '<span class="Field-error" id="erro-' . $campo . '">' . e($erros[$campo]) . '</span>' : '';
$attr = fn(string $campo) => isset($erros[$campo]) ? ' aria-invalid="true" aria-describedby="erro-' . $campo . '"' : '';
?>
<div class="PageHead">
    <div>
        <a class="PageHead-back" href="<?= e(url('ocorrencias')) ?>"><?= icone('voltar', 16) ?> Ocorrências</a>
        <h1>Registrar ocorrência</h1>
        <p class="PageHead-sub">A data e a hora são registradas automaticamente. Depois de enviada, a ocorrência não pode ser apagada; se precisar corrigir algo, acrescente um complemento.</p>
    </div>
</div>

<?php if ($erros): ?>
    <div class="Notice is-error" role="alert"><?= icone('alerta') ?><span>Confira os campos destacados e envie de novo.</span></div>
<?php endif ?>

<form class="Card" method="post" action="<?= e(url('ocorrencias/nova')) ?>" enctype="multipart/form-data">
    <?= csrf_campo() ?>
    <div class="FormGrid">
        <div class="Field" style="--span:6">
            <label for="categoria_id">Categoria</label>
            <select class="Input" id="categoria_id" name="categoria_id" required<?= $attr('categoria_id') ?>>
                <option value="">Escolha…</option>
                <?php foreach ($categorias as $c): ?>
                    <option value="<?= $c['id'] ?>"<?= (string) $d['categoria_id'] === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['nome']) ?></option>
                <?php endforeach ?>
            </select>
            <?= $erro('categoria_id') ?>
        </div>
        <div class="Field" style="--span:6">
            <label for="local_id">Local</label>
            <select class="Input" id="local_id" name="local_id" required<?= $attr('local_id') ?>>
                <option value="">Escolha…</option>
                <?php foreach ($locais as $l): ?>
                    <option value="<?= $l['id'] ?>"<?= (string) $d['local_id'] === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['nome']) ?></option>
                <?php endforeach ?>
            </select>
            <?= $erro('local_id') ?>
        </div>
        <div class="Field" style="--span:6">
            <label for="referencia_local">Complemento do local (opcional)</label>
            <input class="Input" id="referencia_local" name="referencia_local" maxlength="60" value="<?= e($d['referencia_local']) ?>" placeholder="Ex.: Bloco B, 3º andar"<?= $attr('referencia_local') ?>>
            <?= $erro('referencia_local') ?>
        </div>
        <div class="Field" style="--span:6">
            <label for="urgencia">Urgência</label>
            <select class="Input" id="urgencia" name="urgencia"<?= $attr('urgencia') ?>>
                <?php foreach (URGENCIAS as $k => $nome): ?>
                    <option value="<?= $k ?>"<?= $d['urgencia'] === $k ? ' selected' : '' ?>><?= e($nome) ?></option>
                <?php endforeach ?>
            </select>
            <?= $erro('urgencia') ?>
        </div>
        <?php if ($u['perfil'] !== 'morador'): ?>
            <div class="Field" style="--span:12">
                <label for="unidade_id">Unidade envolvida (opcional)</label>
                <select class="Input" id="unidade_id" name="unidade_id"<?= $attr('unidade_id') ?>>
                    <option value="">Nenhuma</option>
                    <?php foreach ($unidades as $un): ?>
                        <option value="<?= $un['id'] ?>"<?= (string) $d['unidade_id'] === (string) $un['id'] ? ' selected' : '' ?>><?= e("Bloco {$un['bloco']}, unidade {$un['numero']}") ?></option>
                    <?php endforeach ?>
                </select>
                <span class="Field-help">Use quando o registro for feito em nome de um morador ou envolver uma unidade.</span>
                <?= $erro('unidade_id') ?>
            </div>
        <?php endif ?>
        <div class="Field" style="--span:12">
            <label for="descricao">O que aconteceu?</label>
            <textarea class="Input" id="descricao" name="descricao" rows="5" minlength="10" maxlength="2000" required data-contador aria-describedby="ajuda-descricao<?= isset($erros['descricao']) ? ' erro-descricao' : '' ?>"<?= isset($erros['descricao']) ? ' aria-invalid="true"' : '' ?>><?= e($d['descricao']) ?></textarea>
            <span class="Field-help" id="ajuda-descricao">Descreva o fato. Evite citar nomes e detalhes íntimos de outras pessoas além do necessário.</span>
            <?= $erro('descricao') ?>
        </div>
        <div class="Field" style="--span:12">
            <label for="fotos">Fotos (opcional, até <?= (int) cfg('max_fotos') ?>)</label>
            <input class="Input Input--arquivo" type="file" id="fotos" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple data-max="<?= (int) cfg('max_fotos') ?>"<?= $attr('fotos') ?>>
            <span class="Field-help">JPG, PNG ou WebP. A localização gravada na foto pelo celular é removida.</span>
            <?= $erro('fotos') ?>
        </div>
    </div>
    <div class="Form-acoes">
        <a class="Button Button--ghost" href="<?= e(url('ocorrencias')) ?>">Cancelar</a>
        <button class="Button Button--primary" type="submit">Registrar ocorrência</button>
    </div>
</form>
