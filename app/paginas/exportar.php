<?php
// Exporta as ocorrências filtradas. Como o arquivo leva a descrição de todas elas,
// a senha é pedida de novo mesmo com a sessão aberta.
$u = exigir_login('sindico');
$filtros = array_intersect_key($_POST, array_flip(['de', 'ate', 'categoria', 'local', 'status', 'origem']));
if (!senha_confere($u, (string) ($_POST['senha'] ?? ''))) {
    avisar_tela('error', 'Senha incorreta. A exportação não foi feita.');
    redirecionar('ocorrencias' . ($filtros ? '?' . http_build_query($filtros) : ''));
}
ob_end_clean();
exportar_csv($filtros);
