<?php
// Regras das ocorrências: quem pode ver cada uma, por quais status ela passa, o histórico
// que não se apaga, o tratamento das fotos e a importação e exportação em CSV.

declare(strict_types=1);

const STATUS = ['aberta' => 'Aberta', 'em_analise' => 'Em análise', 'resolvida' => 'Resolvida', 'arquivada' => 'Arquivada'];
const STATUS_COR = ['aberta' => 'is-amber', 'em_analise' => 'is-blue', 'resolvida' => 'is-green', 'arquivada' => ''];
const URGENCIAS = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta'];
const REGISTRANTES = ['morador' => 'Morador', 'portaria' => 'Portaria', 'zeladoria' => 'Zeladoria', 'sindico' => 'Síndico', 'nao_identificado' => 'Não identificado'];

// Toda ocorrência nasce "aberta", avança para "em análise" e termina "resolvida" ou "arquivada".
const TRANSICOES = ['aberta' => ['em_analise'], 'em_analise' => ['resolvida', 'arquivada'], 'resolvida' => [], 'arquivada' => []];

function transicao_valida(string $de, string $para): bool
{
    return in_array($para, TRANSICOES[$de] ?? [], true);
}

function selo_status(string $status): string
{
    return '<span class="Pill ' . STATUS_COR[$status] . '">' . e(STATUS[$status]) . '</span>';
}

// ----- Quem pode ver o quê -----

/** O morador vê só o que registrou; a portaria, o que registrou e as ocorrências do dia; o síndico, todas. */
function pode_ver(array $u, array $o): bool
{
    if ($u['perfil'] === 'sindico' || (int) $o['autor_id'] === (int) $u['id']) {
        return true;
    }
    return $u['perfil'] === 'portaria' && $o['origem'] === 'sistema'
        && $o['registrada_em'] !== null && substr($o['registrada_em'], 0, 10) === date('Y-m-d');
}

/** As fotos são só para o autor e o síndico: a portaria vê as ocorrências do dia sem elas. */
function pode_ver_fotos(array $u, array $o): bool
{
    return $u['perfil'] === 'sindico' || (int) $o['autor_id'] === (int) $u['id'];
}

function buscar_ocorrencia(int $id): ?array
{
    return consulta(
        'SELECT o.*, c.nome AS categoria, l.nome AS local, a.nome AS autor, un.bloco, un.numero
           FROM ocorrencias o
           JOIN categorias c ON c.id = o.categoria_id
           JOIN locais l ON l.id = o.local_id
           LEFT JOIN usuarios a ON a.id = o.autor_id
           LEFT JOIN unidades un ON un.id = o.unidade_id
          WHERE o.id = ?',
        [$id]
    )->fetch() ?: null;
}

/**
 * Monta o WHERE das telas do síndico (lista, exportação e painel) a partir dos filtros da URL.
 * Devolve [sql, parâmetros]; valores inválidos são ignorados.
 */
function filtro_ocorrencias(array $f): array
{
    $sql = [];
    $p = [];
    $data = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    if ($de = $data($f['de'] ?? null)) {
        $sql[] = 'o.registrada_em >= ?';
        $p[] = "$de 00:00:00";
    }
    if ($ate = $data($f['ate'] ?? null)) {
        $sql[] = 'o.registrada_em <= ?';
        $p[] = "$ate 23:59:59";
    }
    foreach (['categoria' => 'o.categoria_id', 'local' => 'o.local_id'] as $chave => $coluna) {
        if (ctype_digit((string) ($f[$chave] ?? ''))) {
            $sql[] = "$coluna = ?";
            $p[] = (int) $f[$chave];
        }
    }
    if (isset(STATUS[$f['status'] ?? ''])) {
        $sql[] = 'o.status = ?';
        $p[] = $f['status'];
    }
    if (in_array($f['origem'] ?? '', ['sistema', 'livro_fisico'], true)) {
        $sql[] = 'o.origem = ?';
        $p[] = $f['origem'];
    }
    return [$sql ? implode(' AND ', $sql) : '1 = 1', $p];
}

// ----- Registrar -----

/** Valida e grava uma ocorrência nova. Devolve o id ou a lista de erros para mostrar no formulário. */
function criar_ocorrencia(array $u, array $dados, array $fotos): int|array
{
    $erros = [];
    $categoria = (int) ($dados['categoria_id'] ?? 0);
    $local = (int) ($dados['local_id'] ?? 0);
    $descricao = trim((string) ($dados['descricao'] ?? ''));
    $urgencia = $dados['urgencia'] ?? 'media';
    $referencia = trim((string) ($dados['referencia_local'] ?? '')) ?: null;

    if (!consulta('SELECT 1 FROM categorias WHERE id = ?', [$categoria])->fetchColumn()) {
        $erros['categoria_id'] = 'Escolha a categoria.';
    }
    if (!consulta('SELECT 1 FROM locais WHERE id = ?', [$local])->fetchColumn()) {
        $erros['local_id'] = 'Escolha o local.';
    }
    if (mb_strlen($descricao) < 10) {
        $erros['descricao'] = 'Descreva o que aconteceu (pelo menos 10 caracteres).';
    } elseif (mb_strlen($descricao) > 2000) {
        $erros['descricao'] = 'A descrição pode ter até 2.000 caracteres.';
    }
    if (!isset(URGENCIAS[$urgencia])) {
        $erros['urgencia'] = 'Escolha a urgência.';
    }
    if ($referencia !== null && mb_strlen($referencia) > 60) {
        $erros['referencia_local'] = 'O complemento do local pode ter até 60 caracteres.';
    }

    // Só a portaria e o síndico indicam a unidade envolvida; o morador usa a própria.
    $unidade = $u['unidade_id'];
    if ($u['perfil'] !== 'morador') {
        $unidade = ctype_digit((string) ($dados['unidade_id'] ?? '')) ? (int) $dados['unidade_id'] : null;
        if ($unidade && !consulta('SELECT 1 FROM unidades WHERE id = ?', [$unidade])->fetchColumn()) {
            $erros['unidade_id'] = 'Unidade inválida.';
        }
    }

    $imagens = [];
    foreach ($fotos as $foto) {
        $img = ler_foto($foto);
        if (is_string($img)) {
            $erros['fotos'] = $img;
            break;
        }
        $imagens[] = $img;
    }
    if (count($fotos) > cfg('max_fotos')) {
        $erros['fotos'] = 'Envie no máximo ' . cfg('max_fotos') . ' fotos.';
    }
    if ($erros) {
        return $erros;
    }

    $agora = agora(); // data e hora do servidor, nunca do formulário
    bd()->beginTransaction();
    try {
        consulta(
            'INSERT INTO ocorrencias (origem, autor_id, registrante, unidade_id, categoria_id, local_id, referencia_local,
                                      descricao, urgencia, status, registrada_em)
             VALUES ("sistema", ?, ?, ?, ?, ?, ?, ?, ?, "aberta", ?)',
            [$u['id'], $u['perfil'], $unidade, $categoria, $local, $referencia, $descricao, $urgencia, $agora]
        );
        $id = (int) bd()->lastInsertId();
        consulta(
            'INSERT INTO movimentacoes (ocorrencia_id, usuario_id, tipo, status_novo, comentario, registrada_em)
             VALUES (?, ?, "registro", "aberta", "Ocorrência registrada.", ?)',
            [$id, $u['id'], $agora]
        );
        foreach ($imagens as $img) {
            $arquivo = bin2hex(random_bytes(16)) . '.jpg';
            if (!imagejpeg($img, cfg('pasta_fotos') . '/' . $arquivo, 82)) {
                throw new RuntimeException('Não foi possível salvar a foto.');
            }
            consulta('INSERT INTO anexos (ocorrencia_id, caminho_arquivo, enviado_em) VALUES (?, ?, ?)', [$id, $arquivo, $agora]);
        }
        auditar('criar', $id);
        bd()->commit();
    } catch (Throwable $e) {
        bd()->rollBack();
        throw $e;
    }

    // Avisa os síndicos fora da transação: se o aviso falhar, a ocorrência continua registrada.
    $sindicos = consulta('SELECT id FROM usuarios WHERE perfil = "sindico" AND ativo = 1 AND id <> ?', [$u['id']])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($sindicos as $sindicoId) {
        avisar((int) $sindicoId, $id, "Nova ocorrência nº $id registrada.");
    }
    return $id;
}

/**
 * Confere e reprocessa uma foto enviada. Regravar a imagem remove os metadados (como a
 * localização do GPS) e garante que o arquivo salvo é mesmo uma imagem. Devolve a imagem
 * ou a mensagem de erro.
 */
function ler_foto(array $arquivo): GdImage|string
{
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        return 'Não foi possível receber a foto. Tente de novo com um arquivo menor.';
    }
    if ($arquivo['size'] > cfg('max_foto_bytes')) {
        return 'Cada foto pode ter até ' . intdiv(cfg('max_foto_bytes'), 1024 * 1024) . ' MB.';
    }
    $tipo = (new finfo(FILEINFO_MIME_TYPE))->file($arquivo['tmp_name']);
    if (!in_array($tipo, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return 'Envie fotos em JPG, PNG ou WebP.';
    }
    $img = @imagecreatefromstring(file_get_contents($arquivo['tmp_name']));
    if (!$img) {
        return 'O arquivo enviado não é uma imagem válida.';
    }
    // Fotos de celular guardam a rotação nos metadados, que serão descartados.
    $orientacao = $tipo === 'image/jpeg' ? (@exif_read_data($arquivo['tmp_name'])['Orientation'] ?? 1) : 1;
    $graus = [3 => 180, 6 => -90, 8 => 90][$orientacao] ?? 0;
    if ($graus) {
        $img = imagerotate($img, $graus, 0);
    }
    $maior = max(imagesx($img), imagesy($img));
    if ($maior > 1600) {
        $img = imagescale($img, (int) round(imagesx($img) * 1600 / $maior));
    }
    return $img;
}

/** Normaliza $_FILES['fotos'] (campo múltiplo) em uma lista, ignorando campos vazios. */
function fotos_enviadas(?array $campo): array
{
    $lista = [];
    foreach ((array) ($campo['name'] ?? []) as $i => $nome) {
        if ($campo['error'][$i] !== UPLOAD_ERR_NO_FILE) {
            $lista[] = ['error' => $campo['error'][$i], 'size' => $campo['size'][$i], 'tmp_name' => $campo['tmp_name'][$i]];
        }
    }
    return $lista;
}

// ----- Responder e complementar -----

/** O síndico muda o status e é obrigado a escrever um comentário. Devolve null ou a mensagem de erro. */
function mover_status(array $u, array $o, string $novo, string $comentario): ?string
{
    $comentario = trim($comentario);
    if ($u['perfil'] !== 'sindico') {
        return 'Só o síndico altera o status.';
    }
    if (!transicao_valida($o['status'], $novo)) {
        return 'Mudança de status não permitida: de "' . STATUS[$o['status']] . '" para "' . (STATUS[$novo] ?? $novo) . '".';
    }
    if (mb_strlen($comentario) < 5) {
        return 'Escreva um comentário para o morador (pelo menos 5 caracteres).';
    }
    $agora = agora();
    bd()->beginTransaction();
    try {
        // A condição no status atual impede que duas respostas simultâneas pulem etapas.
        $st = consulta(
            'UPDATE ocorrencias SET status = ?, houve_resposta = 1, respondida_em = COALESCE(respondida_em, ?)
              WHERE id = ? AND status = ?',
            [$novo, $agora, $o['id'], $o['status']]
        );
        if ($st->rowCount() !== 1) {
            bd()->rollBack();
            return 'Esta ocorrência acabou de ser atualizada por outra pessoa. Recarregue a página.';
        }
        consulta(
            'INSERT INTO movimentacoes (ocorrencia_id, usuario_id, tipo, status_anterior, status_novo, comentario, registrada_em)
             VALUES (?, ?, "status", ?, ?, ?, ?)',
            [$o['id'], $u['id'], $o['status'], $novo, $comentario, $agora]
        );
        auditar('alterar_status', (int) $o['id'], $o['status'] . ' -> ' . $novo);
        bd()->commit();
    } catch (Throwable $e) {
        bd()->rollBack();
        throw $e;
    }
    if ($o['autor_id'] && (int) $o['autor_id'] !== (int) $u['id']) {
        avisar((int) $o['autor_id'], (int) $o['id'], "Ocorrência nº {$o['id']}: status alterado para \"" . STATUS[$novo] . '".');
    }
    return null;
}

/** Nada é editado: correções e novas informações entram como complemento no histórico. */
function complementar(array $u, array $o, string $texto): ?string
{
    $texto = trim($texto);
    $ehSindico = $u['perfil'] === 'sindico';
    if (!$ehSindico && (int) $o['autor_id'] !== (int) $u['id']) {
        return 'Só o autor e o síndico podem complementar a ocorrência.';
    }
    if (mb_strlen($texto) < 5 || mb_strlen($texto) > 2000) {
        return 'O complemento deve ter entre 5 e 2.000 caracteres.';
    }
    $agora = agora();
    bd()->beginTransaction();
    try {
        consulta(
            'INSERT INTO movimentacoes (ocorrencia_id, usuario_id, tipo, status_anterior, status_novo, comentario, registrada_em)
             VALUES (?, ?, "complemento", ?, ?, ?, ?)',
            [$o['id'], $u['id'], $o['status'], $o['status'], $texto, $agora]
        );
        if ($ehSindico) {
            consulta('UPDATE ocorrencias SET houve_resposta = 1, respondida_em = COALESCE(respondida_em, ?) WHERE id = ?', [$agora, $o['id']]);
        }
        auditar('complementar', (int) $o['id']);
        bd()->commit();
    } catch (Throwable $e) {
        bd()->rollBack();
        throw $e;
    }
    if ($ehSindico && $o['autor_id'] && (int) $o['autor_id'] !== (int) $u['id']) {
        avisar((int) $o['autor_id'], (int) $o['id'], "Ocorrência nº {$o['id']}: o síndico acrescentou uma informação.");
    } elseif (!$ehSindico) {
        foreach (consulta('SELECT id FROM usuarios WHERE perfil = "sindico" AND ativo = 1')->fetchAll(PDO::FETCH_COLUMN) as $sindicoId) {
            avisar((int) $sindicoId, (int) $o['id'], "Ocorrência nº {$o['id']}: o autor acrescentou uma informação.");
        }
    }
    return null;
}

// ----- Importar e exportar em CSV -----

const COLUNAS_LIVRO = ['id', 'data', 'hora', 'dia_semana', 'turno', 'registrante', 'local', 'bloco_andar', 'categoria',
    'descricao_resumo', 'urgencia_percebida', 'houve_resposta', 'data_resposta', 'desfecho', 'recorrencia', 'completo'];

/** Lê um CSV (separador ; ou , em UTF-8 ou Windows-1252) e devolve as linhas como arrays associativos. */
function ler_csv(string $conteudo): array
{
    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);
    if (!mb_check_encoding($conteudo, 'UTF-8')) {
        $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252'); // planilha salva pelo Excel
    }
    $linhas = preg_split('/\r\n|\r|\n/', trim($conteudo));
    $sep = substr_count($linhas[0], ';') >= substr_count($linhas[0], ',') ? ';' : ',';
    $cabecalho = array_map(fn($c) => mb_strtolower(trim($c)), str_getcsv(array_shift($linhas), $sep, '"', ''));
    $registros = [];
    foreach ($linhas as $n => $linha) {
        if (trim($linha) === '') {
            continue;
        }
        $campos = array_map('trim', str_getcsv($linha, $sep, '"', ''));
        $registros[$n + 2] = array_combine($cabecalho, array_pad(array_slice($campos, 0, count($cabecalho)), count($cabecalho), ''));
    }
    return [$cabecalho, $registros];
}

/** Converte "dd/mm/aaaa" ou "aaaa-mm-dd" em "aaaa-mm-dd"; null se vazio; false se inválida. */
function data_iso(string $texto): string|null|false
{
    if ($texto === '') {
        return null;
    }
    foreach (['d/m/Y', 'Y-m-d'] as $formato) {
        $d = DateTime::createFromFormat("!$formato", $texto);
        if ($d && $d->format($formato) === $texto) {
            return $d->format('Y-m-d');
        }
    }
    return false;
}

function sim_nao(string $texto): ?bool
{
    return match (mb_strtolower($texto)) {
        'sim', 's', '1' => true,
        'não', 'nao', 'n', '0', '' => false,
        default => null,
    };
}

/**
 * Importa a transcrição do livro físico (as colunas esperadas estão em banco/exemplo_livro_fisico.csv).
 * Tudo ou nada: se alguma linha tiver erro, nada é gravado e os erros são devolvidos por linha.
 */
function importar_livro(array $u, string $conteudo): array
{
    [$cabecalho, $registros] = ler_csv($conteudo);
    $faltando = array_diff(COLUNAS_LIVRO, $cabecalho);
    if ($faltando) {
        return ['erros' => ['Cabeçalho' => 'Faltam as colunas: ' . implode(', ', $faltando) . '.'], 'importados' => 0, 'ignorados' => 0];
    }

    $erros = [];
    $prontos = [];
    $existentes = array_flip(consulta('SELECT numero_livro FROM ocorrencias WHERE numero_livro IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
    $ignorados = 0;
    $registrantes = ['morador' => 'morador', 'portaria' => 'portaria', 'zeladoria' => 'zeladoria', 'síndico' => 'sindico',
        'sindico' => 'sindico', 'não identificado' => 'nao_identificado', 'nao identificado' => 'nao_identificado', '' => 'nao_identificado'];
    $urgencias = ['baixa' => 'baixa', 'média' => 'media', 'media' => 'media', 'alta' => 'alta', '' => 'media'];

    foreach ($registros as $n => $r) {
        $e = [];
        if (!ctype_digit($r['id'])) {
            $e[] = 'id deve ser um número inteiro';
        } elseif (isset($existentes[(int) $r['id']]) || isset($prontos[(int) $r['id']])) {
            $ignorados++; // já importado: reenviar o mesmo arquivo não duplica registros
            continue;
        }
        $data = data_iso($r['data']);
        if ($data === false) {
            $e[] = 'data inválida (use dd/mm/aaaa)';
        }
        $hora = $r['hora'] === '' ? null : (preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $r['hora']) ? $r['hora'] : false);
        if ($hora === false) {
            $e[] = 'hora inválida (use hh:mm)';
        }
        $registrante = $registrantes[mb_strtolower($r['registrante'])] ?? null;
        if ($registrante === null) {
            $e[] = 'registrante desconhecido';
        }
        $urgencia = $urgencias[mb_strtolower($r['urgencia_percebida'])] ?? null;
        if ($urgencia === null) {
            $e[] = 'urgência deve ser baixa, média ou alta';
        }
        if ($r['categoria'] === '' || $r['local'] === '') {
            $e[] = 'categoria e local são obrigatórios';
        }
        $respondeu = sim_nao($r['houve_resposta']);
        $completo = sim_nao($r['completo']);
        if ($respondeu === null || $completo === null) {
            $e[] = 'houve_resposta e completo devem ser sim ou não';
        }
        $resposta = data_iso($r['data_resposta']);
        if ($resposta === false) {
            $e[] = 'data_resposta inválida';
        } elseif ($resposta && $data && $resposta < $data) {
            $e[] = 'data_resposta anterior à data do registro';
        }
        if ($e) {
            $erros["Linha $n"] = implode('; ', $e) . '.';
            continue;
        }
        $prontos[(int) $r['id']] = [
            'data' => $data ? $data . ' ' . ($hora ?? '00:00') . ':00' : null,
            'hora_informada' => (int) ($data && $hora !== null),
            'registrante' => $registrante,
            'categoria' => $r['categoria'],
            'local' => $r['local'],
            'referencia' => $r['bloco_andar'] ?: null,
            'descricao' => $r['descricao_resumo'] ?: '(sem descrição legível)',
            'urgencia' => $urgencia,
            'respondeu' => (int) ($respondeu || $resposta),
            'resposta' => $resposta ? "$resposta 00:00:00" : null,
            'completo' => (int) $completo,
            'desfecho' => $r['desfecho'],
        ];
    }
    if ($erros) {
        return ['erros' => $erros, 'importados' => 0, 'ignorados' => $ignorados];
    }

    $idPorNome = function (string $tabela, string $nome): int {
        $id = consulta("SELECT id FROM $tabela WHERE nome = ?", [$nome])->fetchColumn();
        if (!$id) {
            consulta("INSERT INTO $tabela (nome) VALUES (?)", [$nome]);
            $id = bd()->lastInsertId();
        }
        return (int) $id;
    };
    bd()->beginTransaction();
    try {
        foreach ($prontos as $numero => $r) {
            // Registro histórico não vira tarefa pendente do síndico: o que não foi resolvido entra arquivado.
            $status = mb_strtolower($r['desfecho']) === 'resolvida' ? 'resolvida' : 'arquivada';
            consulta(
                'INSERT INTO ocorrencias (origem, numero_livro, registrante, categoria_id, local_id, referencia_local, descricao,
                                          urgencia, status, registrada_em, hora_informada, houve_resposta, respondida_em, completo)
                 VALUES ("livro_fisico", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$numero, $r['registrante'], $idPorNome('categorias', $r['categoria']), $idPorNome('locais', $r['local']),
                    $r['referencia'], $r['descricao'], $r['urgencia'], $status, $r['data'], $r['hora_informada'],
                    $r['respondeu'], $r['resposta'], $r['completo']]
            );
            consulta(
                'INSERT INTO movimentacoes (ocorrencia_id, usuario_id, tipo, status_novo, comentario, registrada_em)
                 VALUES (?, ?, "registro", ?, ?, ?)',
                [bd()->lastInsertId(), $u['id'], $status,
                    'Importada do livro físico (registro nº ' . $numero . '). Desfecho anotado: ' . ($r['desfecho'] ?: 'sem registro') . '.', agora()]
            );
        }
        auditar('importar', null, count($prontos) . ' registros do livro físico');
        bd()->commit();
    } catch (Throwable $e) {
        bd()->rollBack();
        throw $e;
    }
    return ['erros' => [], 'importados' => count($prontos), 'ignorados' => $ignorados];
}

/** Envia as ocorrências filtradas em CSV (separador ; e BOM, para abrir direto no Excel). */
function exportar_csv(array $filtros): never
{
    [$onde, $params] = filtro_ocorrencias($filtros);
    $linhas = consulta(
        "SELECT o.id, o.origem, o.registrada_em, o.registrante, c.nome AS categoria, l.nome AS local, o.referencia_local,
                o.urgencia, o.status, o.houve_resposta, o.respondida_em, o.descricao
           FROM ocorrencias o JOIN categorias c ON c.id = o.categoria_id JOIN locais l ON l.id = o.local_id
          WHERE $onde ORDER BY o.registrada_em",
        $params
    );
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ocorrencias_' . date('Y-m-d_Hi') . '.csv"');
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, ['numero', 'origem', 'registrada_em', 'registrante', 'categoria', 'local', 'complemento_local',
        'urgencia', 'status', 'houve_resposta', 'respondida_em', 'descricao'], ';', '"', '');
    $total = 0;
    foreach ($linhas as $l) {
        $l['houve_resposta'] = $l['houve_resposta'] ? 'sim' : 'não';
        // Célula iniciada por = + - @ seria executada como fórmula pela planilha de quem abrir o arquivo.
        $l['descricao'] = preg_replace('/^[=+\-@\t\r]/', "'$0", $l['descricao']);
        fputcsv($saida, $l, ';', '"', '');
        $total++;
    }
    auditar('exportar', null, "$total ocorrências");
    exit;
}
