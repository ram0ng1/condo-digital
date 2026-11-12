<?php
// As fotos ficam fora do alcance direto do navegador (storage/) e só são entregues a quem
// pode vê-las: o autor da ocorrência e o síndico.
$u = exigir_login();
$anexo = consulta('SELECT ocorrencia_id, caminho_arquivo FROM anexos WHERE id = ?', [$id])->fetch();
$o = $anexo ? buscar_ocorrencia((int) $anexo['ocorrencia_id']) : null;
$arquivo = $anexo ? cfg('pasta_fotos') . '/' . basename($anexo['caminho_arquivo']) : null;
if (!$o || !pode_ver_fotos($u, $o) || !is_file($arquivo)) {
    erro_http(404, 'Foto não encontrada.');
}
ob_end_clean();
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($arquivo));
header('Cache-Control: private, no-store');
readfile($arquivo);
exit;
