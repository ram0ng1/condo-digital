<?php
// Retenção: anonimiza as ocorrências encerradas há mais tempo que o prazo de guarda
// definido pelo condomínio (retencao_meses em app/config.local.php). A ocorrência continua
// contando nos indicadores (categoria, local, datas, status), mas perde a descrição, os
// comentários, as fotos e o vínculo com o autor e com a unidade.
//
//   php banco/retencao.php        (agendar para rodar uma vez por dia)

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Execute pela linha de comando.');
}
require __DIR__ . '/../app/nucleo.php';

$meses = cfg('retencao_meses');
if (!$meses) {
    exit("Prazo de guarda não definido (retencao_meses). Nada foi anonimizado.\n");
}
$limite = date('Y-m-d H:i:s', strtotime("-$meses months"));
$ids = consulta(
    'SELECT id FROM ocorrencias
      WHERE origem = "sistema" AND anonimizada_em IS NULL AND status IN ("resolvida", "arquivada") AND registrada_em < ?',
    [$limite]
)->fetchAll(PDO::FETCH_COLUMN);

bd()->exec('SET @retencao = 1'); // única situação em que os gatilhos do banco deixam alterar o histórico
foreach ($ids as $id) {
    $fotos = consulta('SELECT caminho_arquivo FROM anexos WHERE ocorrencia_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
    bd()->beginTransaction();
    try {
        consulta(
            'UPDATE ocorrencias SET descricao = "[Ocorrência anonimizada ao fim do prazo de guarda]", autor_id = NULL,
                    unidade_id = NULL, referencia_local = NULL, anonimizada_em = ? WHERE id = ?',
            [agora(), $id]
        );
        consulta('UPDATE movimentacoes SET comentario = "[anonimizado]", usuario_id = NULL WHERE ocorrencia_id = ?', [$id]);
        consulta('DELETE FROM anexos WHERE ocorrencia_id = ?', [$id]);
        auditar('anonimizar', (int) $id, "prazo de guarda de $meses meses");
        bd()->commit();
    } catch (Throwable $e) {
        bd()->rollBack();
        throw $e;
    }
    foreach ($fotos as $foto) {
        @unlink(cfg('pasta_fotos') . '/' . basename($foto)); // só depois de o banco confirmar
    }
}
echo count($ids) . " ocorrências anonimizadas (encerradas e registradas antes de $limite).\n";
