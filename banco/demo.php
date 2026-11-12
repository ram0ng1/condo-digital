<?php
// Dados de DEMONSTRAÇÃO, todos fictícios: servem para apresentar o sistema, rodar os testes e
// gerar as capturas de tela. NÃO são dados do condomínio estudado e não devem ser usados como
// resultado do TCC.
//
//   php banco/demo.php            recria o banco de demonstração
//   php banco/demo.php --forcar   idem, mesmo se o banco tiver contas reais (APAGA TUDO)
//
// Contas criadas (senha Demo@2026): sindico@demo.test, portaria@demo.test, morador1@demo.test ... morador8@demo.test

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Execute pela linha de comando.');
}
require __DIR__ . '/instalar.php';
require __DIR__ . '/../app/ocorrencias.php';

$temTabelas = (bool) bd()->query('SHOW TABLES')->fetchColumn();
if ($temTabelas && !instalacao_demo() && !in_array('--forcar', $argv, true)) {
    exit("Este banco tem contas que não são de demonstração. Nada foi apagado.\n"
        . "Para apagar tudo e recriar a demonstração, repita com --forcar.\n");
}

// Recria do zero. DROP não dispara os gatilhos de imutabilidade.
bd()->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (bd()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabela) {
    bd()->exec("DROP TABLE `$tabela`");
}
bd()->exec('SET FOREIGN_KEY_CHECKS = 1');
array_map('unlink', glob(cfg('pasta_fotos') . '/*.jpg') ?: []);
criar_esquema();
$GLOBALS['config']['enviar_email'] = false;

mt_srand(2026); // mesma demonstração a cada execução
$sorteio = function (array $pesos): string {
    $r = mt_rand(1, array_sum($pesos));
    foreach ($pesos as $valor => $peso) {
        if (($r -= $peso) <= 0) {
            return (string) $valor;
        }
    }
};

// ------------------------------------------------------------------ usuários

$hash = password_hash('Demo@2026', PASSWORD_DEFAULT);
$usuario = function (string $nome, string $email, string $perfil, ?array $unidade = null) use ($hash): int {
    $unidadeId = null;
    if ($unidade) {
        consulta('INSERT INTO unidades (bloco, numero) VALUES (?, ?)', $unidade);
        $unidadeId = bd()->lastInsertId();
    }
    consulta('INSERT INTO usuarios (nome, email, senha_hash, perfil, unidade_id, criado_em) VALUES (?, ?, ?, ?, ?, ?)',
        [$nome, $email, $hash, $perfil, $unidadeId, '2026-10-01 09:00:00']);
    return (int) bd()->lastInsertId();
};
$sindico = $usuario('Síndico Demonstração', 'sindico@demo.test', 'sindico');
$porteiro = $usuario('Portaria Demonstração', 'portaria@demo.test', 'portaria');
$moradores = [];
foreach ([['A', '101'], ['A', '204'], ['A', '302'], ['B', '103'], ['B', '201'], ['B', '305'], ['C', '102'], ['C', '303']] as $i => $un) {
    $moradores[] = $usuario('Morador Demo ' . ($i + 1), 'morador' . ($i + 1) . '@demo.test', 'morador', $un);
}

// ------------------------------------------------------------------ livro físico (18 meses, importado como no uso real)

$categorias = ['Barulho' => 30, 'Garagem e veículos' => 18, 'Vazamento ou infiltração' => 14, 'Animais' => 9, 'Áreas comuns' => 8,
    'Limpeza' => 6, 'Elevador' => 5, 'Obras e reformas' => 4, 'Segurança e portaria' => 3, 'Encomendas' => 2, 'Outros' => 1];
$locaisDe = [
    'Barulho' => ['Unidade' => 6, 'Salão de festas' => 3, 'Área de lazer' => 2],
    'Garagem e veículos' => ['Garagem' => 1],
    'Vazamento ou infiltração' => ['Unidade' => 3, 'Garagem' => 2, 'Hall e corredores' => 1],
    'Animais' => ['Hall e corredores' => 2, 'Área de lazer' => 2, 'Elevador' => 1],
    'Áreas comuns' => ['Salão de festas' => 2, 'Área de lazer' => 3, 'Hall e corredores' => 1],
    'Limpeza' => ['Hall e corredores' => 3, 'Garagem' => 1, 'Elevador' => 1],
    'Elevador' => ['Elevador' => 1],
    'Obras e reformas' => ['Unidade' => 3, 'Fachada' => 1],
    'Segurança e portaria' => ['Portaria' => 3, 'Garagem' => 1],
    'Encomendas' => ['Portaria' => 1],
    'Outros' => ['Outro' => 1],
];
$textos = [
    'Barulho' => 'Som alto após as 22h, com queixa de vizinhos.',
    'Garagem e veículos' => 'Veículo estacionado fora da vaga, impedindo a passagem.',
    'Vazamento ou infiltração' => 'Vazamento de água com mancha no teto.',
    'Animais' => 'Animal circulando sem guia em área comum.',
    'Áreas comuns' => 'Dano em equipamento de uso comum.',
    'Limpeza' => 'Lixo deixado fora do local de coleta.',
    'Elevador' => 'Elevador parado entre andares.',
    'Obras e reformas' => 'Obra com ruído fora do horário permitido.',
    'Segurança e portaria' => 'Portão de pedestres ficou aberto.',
    'Encomendas' => 'Encomenda não localizada na portaria.',
    'Outros' => 'Solicitação diversa ao síndico.',
];
$horaDe = fn(string $cat) => match ($cat) {
    'Barulho' => (int) $sorteio([19 => 2, 20 => 3, 21 => 5, 22 => 8, 23 => 8, 0 => 4, 1 => 2]),
    'Obras e reformas' => mt_rand(7, 18),
    default => (int) $sorteio([7 => 2, 8 => 4, 9 => 3, 11 => 2, 14 => 2, 17 => 3, 18 => 5, 19 => 5, 20 => 4, 21 => 2]),
};

$csv = [COLUNAS_LIVRO];
$dia = new DateTime('2025-04-01');
$fim = new DateTime('2026-09-30');
$n = 0;
while ($dia <= $fim) {
    // Cerca de 7 registros por mês, com mais barulho nos fins de semana.
    $fds = (int) $dia->format('N') >= 5;
    if (mt_rand(1, 100) <= ($fds ? 34 : 17)) {
        $n++;
        $cat = $sorteio($fds ? ['Barulho' => 60] + $categorias : $categorias);
        $hora = $horaDe($cat);
        $semHora = mt_rand(1, 100) <= 12;
        $respondeu = mt_rand(1, 100) <= 38;
        $comDataResposta = $respondeu && mt_rand(1, 100) <= 75;
        $completo = !$semHora && mt_rand(1, 100) <= 82;
        $resposta = $comDataResposta ? (clone $dia)->modify('+' . (int) $sorteio([1 => 2, 3 => 3, 6 => 4, 9 => 4, 14 => 3, 21 => 2, 35 => 1]) . ' days') : null;
        $csv[] = [
            $n, $dia->format('d/m/Y'), $semHora ? '' : sprintf('%02d:%02d', $hora, mt_rand(0, 5) * 10),
            '', '', $sorteio(['morador' => 6, 'portaria' => 3, 'zeladoria' => 1, 'não identificado' => 1]),
            $sorteio($locaisDe[$cat]), '', $cat, $textos[$cat], $sorteio(['baixa' => 3, 'média' => 5, 'alta' => 2]),
            $respondeu ? 'sim' : 'não', $resposta?->format('d/m/Y') ?? '',
            $respondeu ? $sorteio(['resolvida' => 3, 'pendente' => 1]) : 'sem registro', '', $completo ? 'sim' : 'não',
        ];
    }
    $dia->modify('+1 day');
}
$arquivo = fopen(__DIR__ . '/exemplo_livro_fisico.csv', 'w');
fwrite($arquivo, "\xEF\xBB\xBF");
foreach ($csv as $linha) {
    fputcsv($arquivo, $linha, ';', '"', '');
}
fclose($arquivo);

$_SESSION['usuario_id'] = $sindico; // autoria do registro de importação
$importacao = importar_livro(['id' => $sindico], file_get_contents(__DIR__ . '/exemplo_livro_fisico.csv'));
if ($importacao['erros']) {
    exit("Falha ao importar o livro de exemplo:\n" . print_r($importacao['erros'], true));
}

// ------------------------------------------------------------------ uso no sistema (últimas 6 semanas)

$ocorrencia = function (string $quando, int $autor, string $perfil, string $cat, string $local, string $texto, string $urgencia,
    array $passos = []) use ($sindico): int {
    $idDe = fn(string $tabela, string $nome) => consulta("SELECT id FROM $tabela WHERE nome = ?", [$nome])->fetchColumn();
    $unidade = consulta('SELECT unidade_id FROM usuarios WHERE id = ?', [$autor])->fetchColumn() ?: null;
    consulta('INSERT INTO ocorrencias (origem, autor_id, registrante, unidade_id, categoria_id, local_id, descricao, urgencia, registrada_em)
              VALUES ("sistema", ?, ?, ?, ?, ?, ?, ?, ?)',
        [$autor, $perfil, $unidade, $idDe('categorias', $cat), $idDe('locais', $local), $texto, $urgencia, $quando]);
    $id = (int) bd()->lastInsertId();
    consulta('INSERT INTO movimentacoes (ocorrencia_id, usuario_id, tipo, status_novo, comentario, registrada_em)
              VALUES (?, ?, "registro", "aberta", "Ocorrência registrada.", ?)', [$id, $autor, $quando]);
    consulta('INSERT INTO registros_acesso (usuario_id, ocorrencia_id, acao, ocorrido_em) VALUES (?, ?, "criar", ?)', [$autor, $id, $quando]);
    $status = 'aberta';
    foreach ($passos as [$horasDepois, $novo, $comentario]) {
        $momento = date('Y-m-d H:i:s', strtotime($quando) + (int) ($horasDepois * 3600));
        consulta('INSERT INTO movimentacoes (ocorrencia_id, usuario_id, tipo, status_anterior, status_novo, comentario, registrada_em)
                  VALUES (?, ?, "status", ?, ?, ?, ?)', [$id, $sindico, $status, $novo, $comentario, $momento]);
        consulta('UPDATE ocorrencias SET status = ?, houve_resposta = 1, respondida_em = COALESCE(respondida_em, ?) WHERE id = ?', [$novo, $momento, $id]);
        consulta('INSERT INTO registros_acesso (usuario_id, ocorrencia_id, acao, detalhe, ocorrido_em) VALUES (?, ?, "alterar_status", ?, ?)',
            [$sindico, $id, "$status -> $novo", $momento]);
        consulta('INSERT INTO avisos (usuario_id, ocorrencia_id, mensagem, criado_em, lido_em) VALUES (?, ?, ?, ?, ?)',
            [$autor, $id, "Ocorrência nº $id: status alterado para \"" . STATUS[$novo] . '".', $momento, $momento]);
        $status = $novo;
    }
    return $id;
};

$hoje = strtotime(date('Y-m-d'));
$em = fn(int $diasAtras, string $hora) => date('Y-m-d', $hoje - $diasAtras * 86400) . " $hora:00";
$analise = 'Recebido. Vou verificar com a equipe e retorno por aqui.';
$m = $moradores;
$registros = [
    [40, '22:40', $m[0], 'morador', 'Barulho', 'Salão de festas', 'Festa no salão com som alto depois das 22h.', 'media', [[10, 'em_analise', $analise], [36, 'resolvida', 'Conversei com o responsável pela reserva e reforcei o horário de silêncio do regimento.']]],
    [38, '08:15', $porteiro, 'portaria', 'Garagem e veículos', 'Garagem', 'Carro parado na frente do portão da garagem por cerca de 20 minutos.', 'alta', [[2, 'em_analise', $analise], [20, 'resolvida', 'O condutor foi identificado e orientado. Sinalização do portão será reforçada.']]],
    [36, '19:05', $m[3], 'morador', 'Vazamento ou infiltração', 'Garagem', 'Goteira constante no teto da garagem, perto da entrada do bloco B.', 'alta', [[14, 'em_analise', 'Chamei a empresa de manutenção para avaliar a tubulação.'], [120, 'resolvida', 'Tubulação reparada. Por favor, avise se a goteira voltar.']]],
    [33, '23:10', $m[1], 'morador', 'Barulho', 'Salão de festas', 'Música alta no salão de festas até tarde da noite.', 'media', [[12, 'em_analise', $analise], [40, 'resolvida', 'Responsável notificado conforme o regimento interno.']]],
    [31, '07:50', $m[5], 'morador', 'Limpeza', 'Hall e corredores', 'Sacos de lixo deixados no corredor do terceiro andar.', 'baixa', [[5, 'em_analise', $analise], [28, 'resolvida', 'A equipe de limpeza recolheu e colocamos um aviso sobre o local de descarte.']]],
    [29, '18:30', $m[2], 'morador', 'Elevador', 'Elevador', 'Elevador do bloco A parou entre andares e voltou a funcionar sozinho.', 'alta', [[1, 'em_analise', 'Acionei a empresa de manutenção do elevador.'], [22, 'resolvida', 'Técnico trocou o sensor da porta. Elevador liberado.']]],
    [27, '21:45', $m[6], 'morador', 'Animais', 'Área de lazer', 'Cachorro solto na área de lazer, sem guia.', 'media', [[18, 'em_analise', $analise], [44, 'resolvida', 'Tutor orientado sobre o uso da guia nas áreas comuns.']]],
    [24, '22:55', $m[4], 'morador', 'Barulho', 'Salão de festas', 'Som alto no salão de festas depois do horário permitido.', 'media', [[9, 'em_analise', $analise], [30, 'resolvida', 'Vamos propor na próxima assembleia limite de horário na reserva do salão.']]],
    [22, '09:20', $porteiro, 'portaria', 'Encomendas', 'Portaria', 'Morador procurou encomenda que não consta no caderno de recebimento.', 'baixa', [[4, 'em_analise', $analise], [26, 'arquivada', 'A transportadora confirmou que a entrega ainda não tinha sido feita.']]],
    [20, '17:40', $m[7], 'morador', 'Garagem e veículos', 'Garagem', 'Vaga de visitante ocupada pelo mesmo carro há vários dias.', 'media', [[20, 'em_analise', $analise], [70, 'resolvida', 'Proprietário localizado e veículo retirado.']]],
    [17, '20:10', $m[0], 'morador', 'Áreas comuns', 'Área de lazer', 'Brinquedo do parquinho com parafuso solto.', 'alta', [[3, 'em_analise', 'Brinquedo interditado até o conserto.'], [50, 'resolvida', 'Brinquedo consertado e liberado.']]],
    [15, '23:30', $m[3], 'morador', 'Barulho', 'Unidade', 'Barulho de móveis sendo arrastados de madrugada.', 'media', [[11, 'em_analise', $analise], [34, 'resolvida', 'Conversei com o morador da unidade, que se comprometeu a evitar o barulho.']]],
    [12, '08:05', $m[1], 'morador', 'Vazamento ou infiltração', 'Hall e corredores', 'Infiltração na parede do corredor, com pintura descascando.', 'media', [[6, 'em_analise', 'Vistoria marcada com a empresa de manutenção.']]],
    [9, '22:20', $m[5], 'morador', 'Barulho', 'Salão de festas', 'Evento no salão de festas com som alto.', 'media', [[13, 'em_analise', $analise], [38, 'resolvida', 'Responsável advertido por escrito.']]],
    [8, '18:50', $m[2], 'morador', 'Obras e reformas', 'Unidade', 'Obra com furadeira depois das 18h.', 'baixa', []],
    [6, '10:30', $porteiro, 'portaria', 'Segurança e portaria', 'Portaria', 'Portão de pedestres não está fechando sozinho.', 'alta', [[2, 'em_analise', 'Técnico do portão chamado para hoje.'], [8, 'resolvida', 'Mola do portão trocada.']]],
    [4, '21:15', $m[6], 'morador', 'Garagem e veículos', 'Garagem', 'Moto estacionada em vaga de carro que não é dela.', 'baixa', [[15, 'em_analise', $analise]]],
    [3, '19:40', $m[4], 'morador', 'Limpeza', 'Elevador', 'Elevador de serviço sujo depois de uma mudança.', 'baixa', [[14, 'em_analise', $analise], [20, 'resolvida', 'Limpeza feita na manhã seguinte.']]],
    [1, '22:05', $m[7], 'morador', 'Barulho', 'Área de lazer', 'Grupo conversando alto na área de lazer depois das 22h.', 'media', []],
    [0, '07:30', $m[0], 'morador', 'Elevador', 'Elevador', 'Elevador do bloco A fazendo barulho forte ao subir.', 'alta', []],
    [0, '08:10', $porteiro, 'portaria', 'Encomendas', 'Portaria', 'Encomenda grande recebida sem identificação da unidade.', 'baixa', []],
];
foreach ($registros as [$diasAtras, $hora, $autor, $perfil, $cat, $local, $texto, $urgencia, $passos]) {
    $id = $ocorrencia($em($diasAtras, $hora), $autor, $perfil, $cat, $local, $texto, $urgencia, $passos);
    if (!$passos) {
        consulta('INSERT INTO avisos (usuario_id, ocorrencia_id, mensagem, criado_em) VALUES (?, ?, ?, ?)',
            [$sindico, $id, "Nova ocorrência nº $id registrada.", $em($diasAtras, $hora)]);
    }
}

$total = bd()->query('SELECT origem, COUNT(*) FROM ocorrencias GROUP BY origem')->fetchAll(PDO::FETCH_KEY_PAIR);
echo "Demonstração criada (dados fictícios): {$total['livro_fisico']} registros do livro físico e {$total['sistema']} ocorrências no sistema.\n";
echo "Contas (senha Demo@2026): sindico@demo.test, portaria@demo.test, morador1@demo.test a morador8@demo.test\n";
