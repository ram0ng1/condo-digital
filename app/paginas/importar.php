<?php
// RF10: importar a transcrição anonimizada do livro físico (CSV com os campos do Apêndice C do TCC).
$u = exigir_login('sindico');
$resultado = null;

if ($metodo === 'POST') {
    $arquivo = $_FILES['arquivo'] ?? null;
    if (!$arquivo || $arquivo['error'] !== UPLOAD_ERR_OK) {
        $resultado = ['erros' => ['Arquivo' => 'Escolha o arquivo CSV da transcrição.'], 'importados' => 0, 'ignorados' => 0];
    } elseif ($arquivo['size'] > 5 * 1024 * 1024) {
        $resultado = ['erros' => ['Arquivo' => 'O arquivo pode ter até 5 MB.'], 'importados' => 0, 'ignorados' => 0];
    } else {
        $resultado = importar_livro($u, file_get_contents($arquivo['tmp_name']));
        if (!$resultado['erros']) {
            avisar_tela('success', "{$resultado['importados']} registros importados do livro físico"
                . ($resultado['ignorados'] ? " ({$resultado['ignorados']} já estavam no sistema e foram ignorados)." : '.'));
            redirecionar('painel?origem=livro_fisico');
        }
    }
}

$jaImportados = (int) consulta('SELECT COUNT(*) FROM ocorrencias WHERE origem = "livro_fisico"')->fetchColumn();
$titulo = 'Importar livro físico';
?>
<div class="PageHead">
    <div>
        <h1>Importar livro físico</h1>
        <p class="PageHead-sub">Traga para o sistema a transcrição anonimizada do livro de ocorrências em papel. Os registros importados alimentam o painel e servem de linha de base para comparar o antes e o depois.</p>
    </div>
</div>

<?php if ($resultado && $resultado['erros']): ?>
    <div class="Notice is-error" role="alert"><?= icone('alerta') ?>
        <div><strong>Nada foi importado.</strong> Corrija o arquivo e envie de novo:
            <ul class="ListaErros">
                <?php foreach (array_slice($resultado['erros'], 0, 30, true) as $onde => $msg): ?><li><strong><?= e($onde) ?>:</strong> <?= e($msg) ?></li><?php endforeach ?>
                <?php if (count($resultado['erros']) > 30): ?><li>… e mais <?= count($resultado['erros']) - 30 ?> linhas com erro.</li><?php endif ?>
            </ul>
        </div>
    </div>
<?php endif ?>

<div class="Grid Grid-main-side">
    <form class="Card" method="post" action="<?= e(url('importar')) ?>" enctype="multipart/form-data">
        <?= csrf_campo() ?>
        <div class="Card-head"><h3>Arquivo da transcrição</h3><span class="Card-sub"><?= $jaImportados ?> registros do livro já importados</span></div>
        <div class="stack">
            <div class="Field">
                <label for="arquivo">Arquivo CSV</label>
                <input class="Input Input--arquivo" type="file" id="arquivo" name="arquivo" accept=".csv,text/csv" required aria-describedby="ajuda-arquivo">
                <span class="Field-help" id="ajuda-arquivo">Separado por ponto e vírgula ou vírgula, como salvo pelo Excel ou pelo Google Planilhas. Até 5 MB.</span>
            </div>
            <div class="Notice is-info"><?= icone('info') ?><span>A importação é tudo ou nada: se alguma linha tiver erro, nenhuma é gravada. Reenviar um arquivo não duplica registros, porque a coluna <span class="mono">id</span> identifica cada registro do livro.</span></div>
            <div><button class="Button Button--primary" type="submit"><?= icone('importar') ?> Importar</button></div>
        </div>
    </form>

    <section class="Card" aria-labelledby="t-colunas">
        <div class="Card-head"><h3 id="t-colunas">Colunas esperadas</h3></div>
        <p class="small muted">A primeira linha do arquivo deve trazer estes nomes de coluna:</p>
        <ul class="Colunas">
            <?php foreach (COLUNAS_LIVRO as $c): ?><li class="mono"><?= e($c) ?></li><?php endforeach ?>
        </ul>
        <p class="small muted">Datas em dd/mm/aaaa, horas em hh:mm, <span class="mono">houve_resposta</span> e <span class="mono">completo</span> com sim ou não. Nenhuma coluna deve conter nome, número de apartamento, placa ou telefone.</p>
    </section>
</div>
