<?php
// Indicadores do Quadro 3 do TCC e pontos de atenção do painel (apoio à decisão).
// Funções puras: recebem as linhas já lidas do banco, o que permite testá-las sem banco
// (testes/unidade.php) e aplicar o mesmo cálculo ao livro físico e ao sistema.

declare(strict_types=1);

const DIAS_SEMANA = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
const DIAS_SEMANA_LONGO = [1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo'];
const TURNOS = ['Madrugada', 'Manhã', 'Tarde', 'Noite'];

/** Turnos do dicionário de dados: madrugada (0–6 h), manhã (6–12 h), tarde (12–18 h), noite (18–24 h). */
function turno(int $hora): string
{
    return TURNOS[intdiv($hora, 6)];
}

/** Mediana: metade dos valores fica até ela. Null para lista vazia. */
function mediana(array $valores): ?float
{
    if (!$valores) {
        return null;
    }
    sort($valores);
    $n = count($valores);
    $meio = intdiv($n, 2);
    return $n % 2 ? (float) $valores[$meio] : ($valores[$meio - 1] + $valores[$meio]) / 2;
}

function percentual(int|float $parte, int|float $total): ?float
{
    return $total > 0 ? round($parte * 100 / $total, 1) : null;
}

/**
 * Calcula os indicadores sobre um conjunto de ocorrências. Cada linha traz: id, origem,
 * registrada_em, hora_informada, categoria, local, status, houve_resposta, respondida_em, completo.
 */
function indicadores(array $linhas): array
{
    $total = count($linhas);
    $porCategoria = $porLocal = $porMes = $tempos = [];
    $mapa = array_fill_keys(array_keys(DIAS_SEMANA), array_fill_keys(TURNOS, 0));
    $respondidas = $completas = $comData = $recorrentes = 0;

    usort($linhas, fn($a, $b) => strcmp((string) $a['registrada_em'], (string) $b['registrada_em']));
    $ultima = []; // "categoria|local" => data da ocorrência anterior
    foreach ($linhas as $l) {
        $porCategoria[$l['categoria']] = ($porCategoria[$l['categoria']] ?? 0) + 1;
        $porLocal[$l['local']] = ($porLocal[$l['local']] ?? 0) + 1;
        $respondidas += (int) $l['houve_resposta'];
        $completas += (int) $l['completo'];
        if (!$l['registrada_em']) {
            continue; // registro do livro físico com data ilegível: entra só nas contagens acima
        }
        $comData++;
        $t = strtotime($l['registrada_em']);
        $mes = date('Y-m', $t);
        $porMes[$mes][$l['origem']] = ($porMes[$mes][$l['origem']] ?? 0) + 1;
        if ($l['hora_informada']) {
            $mapa[(int) date('N', $t)][turno((int) date('G', $t))]++;
        }
        if ($l['respondida_em']) {
            $tempos[] = (float) max(0, (strtotime($l['respondida_em']) - $t) / 86400);
        }
        // Recorrência: mesma categoria e mesmo local em até 30 dias após uma ocorrência anterior.
        $chave = $l['categoria'] . '|' . $l['local'];
        if (isset($ultima[$chave]) && $t - $ultima[$chave] <= 30 * 86400) {
            $recorrentes++;
        }
        $ultima[$chave] = $t;
    }

    arsort($porCategoria);
    arsort($porLocal);
    ksort($porMes);
    $pareto = [];
    $acumulado = 0;
    foreach ($porCategoria as $nome => $qtd) {
        $acumulado += $qtd;
        $pareto[] = ['nome' => $nome, 'qtd' => $qtd, 'pct' => percentual($qtd, $total), 'acumulado' => percentual($acumulado, $total)];
    }

    // Média mensal sobre os meses do período (inclusive os meses sem nenhum registro).
    $meses = 0;
    if ($porMes) {
        [$a1, $m1] = explode('-', array_key_first($porMes));
        [$a2, $m2] = explode('-', array_key_last($porMes));
        $meses = ($a2 - $a1) * 12 + ($m2 - $m1) + 1;
    }

    return [
        'total'           => $total,
        'meses'           => $meses,
        'media_mensal'    => $meses ? round($comData / $meses, 1) : null,
        'pareto'          => $pareto,
        'por_local'       => $porLocal,
        'por_mes'         => $porMes,
        'mapa'            => $mapa,
        'taxa_resposta'   => percentual($respondidas, $total),
        'respondidas'     => $respondidas,
        'tempo_mediana'   => mediana($tempos),
        'tempo_maior'     => $tempos ? max($tempos) : null,
        'com_tempo'       => count($tempos),
        'recorrencia'     => percentual($recorrentes, $comData),
        'incompletos'     => percentual($total - $completas, $total),
    ];
}

/** Preenche os meses sem registro entre o primeiro e o último, para a série mensal não pular meses. */
function serie_mensal(array $porMes): array
{
    if (!$porMes) {
        return [];
    }
    $serie = [];
    $mes = new DateTime(array_key_first($porMes) . '-01');
    $fim = new DateTime(array_key_last($porMes) . '-01');
    while ($mes <= $fim) {
        $chave = $mes->format('Y-m');
        $serie[$chave] = ($porMes[$chave] ?? []) + ['livro_fisico' => 0, 'sistema' => 0];
        $mes->modify('+1 month');
    }
    return $serie;
}

/**
 * Apoio à decisão: transforma os indicadores em frases que dizem ao síndico onde agir.
 * São regras fixas e explicáveis (sem inteligência artificial). Cada item: [tipo, título, texto, link?].
 */
function pontos_de_atencao(array $ind, array $linhas, string $hoje, array $limites): array
{
    $pontos = [];
    if (!$ind['total']) {
        return $pontos;
    }

    // 1. Ocorrências abertas sem resposta além do limite: é o que exige ação imediata.
    $limite = strtotime($hoje) - $limites['dias_sem_resposta'] * 86400;
    $atrasadas = array_filter($linhas, fn($l) => $l['origem'] === 'sistema' && $l['status'] === 'aberta'
        && $l['registrada_em'] && strtotime($l['registrada_em']) < $limite);
    if ($atrasadas) {
        $n = count($atrasadas);
        $pontos[] = ['alerta', 'Ocorrências aguardando resposta',
            ($n === 1 ? 'Há 1 ocorrência aberta' : "Há $n ocorrências abertas") . " há mais de {$limites['dias_sem_resposta']} dias sem resposta.",
            'ocorrencias?status=aberta'];
    }

    // 2. Problemas recorrentes: mesma categoria e local várias vezes na janela recente.
    $inicio = strtotime($hoje) - $limites['recorrencia_dias'] * 86400;
    $grupos = [];
    foreach ($linhas as $l) {
        if ($l['registrada_em'] && strtotime($l['registrada_em']) >= $inicio) {
            $chave = $l['categoria'] . ' — ' . $l['local'];
            $grupos[$chave] = ($grupos[$chave] ?? 0) + 1;
        }
    }
    arsort($grupos);
    foreach (array_slice(array_filter($grupos, fn($n) => $n >= $limites['recorrencia_min']), 0, 3, true) as $grupo => $n) {
        $pontos[] = ['alerta', 'Problema recorrente',
            "$grupo: $n ocorrências nos últimos {$limites['recorrencia_dias']} dias. Avalie uma medida preventiva em vez de tratar caso a caso."];
    }

    // 3. Pareto: as poucas categorias que somam cerca de 80 % dos registros.
    $principais = [];
    foreach ($ind['pareto'] as $c) {
        $principais[] = $c['nome'];
        if ($c['acumulado'] >= 80) {
            $acumulado = $c['acumulado'];
            break;
        }
    }
    if (count($principais) < count($ind['pareto'])) {
        $pontos[] = ['info', 'Onde está a maior parte das ocorrências',
            count($principais) . ' de ' . count($ind['pareto']) . ' categorias concentram ' . numero($acumulado) . ' % dos registros: '
            . implode(', ', $principais) . '.'];
    }

    // 4. Quando acontece: o dia e o turno de maior concentração.
    $pico = ['n' => 0];
    foreach ($ind['mapa'] as $dia => $turnos) {
        foreach ($turnos as $turno => $n) {
            if ($n > $pico['n']) {
                $pico = ['n' => $n, 'dia' => $dia, 'turno' => $turno];
            }
        }
    }
    if ($pico['n'] >= 3) {
        $pontos[] = ['info', 'Quando as ocorrências se concentram',
            'O período com mais registros é ' . DIAS_SEMANA_LONGO[$pico['dia']] . ', ' . mb_strtolower($pico['turno'])
            . " ({$pico['n']} ocorrências). Considere reforçar a atenção da portaria nesse período."];
    }

    // 5. Retorno ao morador.
    if ($ind['taxa_resposta'] !== null && $ind['taxa_resposta'] < 80) {
        $pontos[] = ['info', 'Retorno ao morador',
            'Apenas ' . numero($ind['taxa_resposta']) . ' % das ocorrências do período têm resposta registrada.'];
    }
    return $pontos;
}

/** Número no padrão brasileiro, sem casas decimais desnecessárias. */
function numero(int|float|null $v, int $casas = 1): string
{
    if ($v === null) {
        return '—';
    }
    $s = number_format($v, $casas, ',', '.');
    return str_contains($s, ',') ? rtrim(rtrim($s, '0'), ',') : $s;
}

function dias(?float $v): string
{
    if ($v === null) {
        return '—';
    }
    return numero($v) . ($v == 1 ? ' dia' : ' dias');
}
