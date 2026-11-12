<?php
// Testes de unidade das regras que não dependem do banco: indicadores (Quadro 3 do TCC),
// ciclo de status (RG1) e leitura do CSV do livro físico (RF10).
//
//   php testes/unidade.php

declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../app/nucleo.php';
require __DIR__ . '/../app/ocorrencias.php';
require __DIR__ . '/../app/indicadores.php';

$falhas = 0;
$total = 0;
function confere(string $nome, mixed $obtido, mixed $esperado): void
{
    global $falhas, $total;
    $total++;
    if ($obtido === $esperado) {
        echo "ok      $nome\n";
        return;
    }
    $falhas++;
    echo "FALHOU  $nome\n        esperado: " . json_encode($esperado, JSON_UNESCAPED_UNICODE)
        . "\n        obtido:   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n";
}

// ---------------------------------------------------------------- mediana e turnos
confere('mediana de lista ímpar', mediana([9, 1, 5]), 5.0);
confere('mediana de lista par', mediana([1, 2, 3, 10]), 2.5);
confere('mediana de lista vazia', mediana([]), null);
confere('turno 0h é madrugada', turno(0), 'Madrugada');
confere('turno 6h é manhã', turno(6), 'Manhã');
confere('turno 17h é tarde', turno(17), 'Tarde');
confere('turno 23h é noite', turno(23), 'Noite');

// ---------------------------------------------------------------- indicadores
$l = fn(string $data, string $cat, string $local, int $resp = 0, ?string $respEm = null, int $completo = 1, string $origem = 'livro_fisico') => [
    'id' => 0, 'origem' => $origem, 'registrada_em' => $data, 'hora_informada' => 1, 'categoria' => $cat, 'local' => $local,
    'status' => 'resolvida', 'houve_resposta' => $resp, 'respondida_em' => $respEm, 'completo' => $completo,
];
$linhas = [
    $l('2026-01-03 22:00:00', 'Barulho', 'Salão', 1, '2026-01-05 22:00:00'),      // sábado à noite, resposta em 2 dias
    $l('2026-01-10 23:00:00', 'Barulho', 'Salão', 1, '2026-01-14 23:00:00'),      // 7 dias depois: recorrente; resposta em 4 dias
    $l('2026-01-20 09:00:00', 'Garagem', 'Garagem', 0, null, 0),                  // sem resposta, incompleto
    $l('2026-03-15 10:00:00', 'Barulho', 'Salão', 1, '2026-03-25 10:00:00'),      // 64 dias depois: não é recorrente; 10 dias
];
$ind = indicadores($linhas);
confere('total de registros', $ind['total'], 4);
confere('meses do período (jan a mar, inclusive o mês vazio)', $ind['meses'], 3);
confere('média mensal', $ind['media_mensal'], 1.3);
confere('taxa de resposta', $ind['taxa_resposta'], 75.0);
confere('tempo até a resposta: mediana', $ind['tempo_mediana'], 4.0);
confere('tempo até a resposta: maior', $ind['tempo_maior'], 10.0);
confere('recorrência (1 de 4 em até 30 dias)', $ind['recorrencia'], 25.0);
confere('registros incompletos', $ind['incompletos'], 25.0);
confere('Pareto ordenado pela frequência', array_column($ind['pareto'], 'nome'), ['Barulho', 'Garagem']);
confere('Pareto: proporção acumulada', array_column($ind['pareto'], 'acumulado'), [75.0, 100.0]);
confere('mapa: sábado à noite', $ind['mapa'][6]['Noite'], 2);
confere('mapa: terça de manhã', $ind['mapa'][2]['Manhã'], 1);
confere('série mensal inclui o mês sem registros', array_keys(serie_mensal($ind['por_mes'])), ['2026-01', '2026-02', '2026-03']);

$semData = indicadores([$l('2026-01-03 22:00:00', 'Barulho', 'Salão'), ['registrada_em' => null] + $l('2026-01-03 00:00:00', 'Barulho', 'Salão')]);
confere('registro sem data conta no total', $semData['total'], 2);
confere('registro sem data não entra na recorrência', $semData['recorrencia'], 0.0);
confere('conjunto vazio não divide por zero', indicadores([])['taxa_resposta'], null);

// ---------------------------------------------------------------- pontos de atenção
$limites = ['dias_sem_resposta' => 7, 'recorrencia_min' => 3, 'recorrencia_dias' => 90];
$aberta = ['status' => 'aberta', 'origem' => 'sistema'] + $l('2026-03-01 08:00:00', 'Elevador', 'Elevador');
$pontos = pontos_de_atencao(indicadores([$aberta]), [$aberta], '2026-03-20 08:00:00', $limites);
confere('aberta há mais de 7 dias gera alerta', $pontos[0][1] ?? null, 'Ocorrências aguardando resposta');
$pontos = pontos_de_atencao(indicadores([$aberta]), [$aberta], '2026-03-05 08:00:00', $limites);
confere('aberta há 4 dias não gera alerta', in_array('Ocorrências aguardando resposta', array_column($pontos, 1), true), false);
$tres = [$l('2026-03-01 20:00:00', 'Barulho', 'Salão', 1), $l('2026-03-08 20:00:00', 'Barulho', 'Salão', 1), $l('2026-03-15 20:00:00', 'Barulho', 'Salão', 1)];
$pontos = pontos_de_atencao(indicadores($tres), $tres, '2026-03-20 08:00:00', $limites);
confere('três ocorrências iguais em 90 dias geram "problema recorrente"', in_array('Problema recorrente', array_column($pontos, 1), true), true);

// ---------------------------------------------------------------- ciclo de status (RG1)
confere('aberta -> em análise é permitido', transicao_valida('aberta', 'em_analise'), true);
confere('aberta -> resolvida é recusado', transicao_valida('aberta', 'resolvida'), false);
confere('em análise -> resolvida é permitido', transicao_valida('em_analise', 'resolvida'), true);
confere('em análise -> arquivada é permitido', transicao_valida('em_analise', 'arquivada'), true);
confere('resolvida -> aberta é recusado', transicao_valida('resolvida', 'aberta'), false);
confere('arquivada é final', transicao_valida('arquivada', 'em_analise'), false);

// ---------------------------------------------------------------- CSV do livro físico (RF10)
[$cab, $reg] = ler_csv("\xEF\xBB\xBFid;data;descricao_resumo\r\n1;05/01/2026;\"Som alto; vizinho reclamou\"\r\n\r\n2;06/01/2026;Goteira\r\n");
confere('CSV: cabeçalho sem BOM', $cab, ['id', 'data', 'descricao_resumo']);
confere('CSV: ponto e vírgula dentro de aspas', $reg[2]['descricao_resumo'], 'Som alto; vizinho reclamou');
confere('CSV: linha em branco é ignorada e a numeração da linha é a do arquivo', array_keys($reg), [2, 4]);
[, $reg] = ler_csv(mb_convert_encoding("id,categoria\n1,Áreas comuns\n", 'Windows-1252', 'UTF-8'));
confere('CSV salvo pelo Excel (Windows-1252) e separado por vírgula', $reg[2]['categoria'], 'Áreas comuns');
confere('data dd/mm/aaaa', data_iso('05/01/2026'), '2026-01-05');
confere('data inexistente é recusada', data_iso('31/02/2026'), false);
confere('data vazia (ilegível no livro)', data_iso(''), null);
confere('sim/não: "Não"', sim_nao('Não'), false);
confere('sim/não: valor desconhecido', sim_nao('talvez'), null);

// ---------------------------------------------------------------- apresentação
confere('número inteiro sem casas', numero(100.0), '100');
confere('número com vírgula decimal', numero(37.5), '37,5');
confere('dias no singular', dias(1.0), '1 dia');

echo "\n" . ($total - $falhas) . " de $total verificações passaram.\n";
exit($falhas ? 1 : 0);
