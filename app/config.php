<?php
// Configuração padrão (ambiente local do RStack). Para outro ambiente, crie
// app/config.local.php devolvendo um array só com o que muda; ele não vai para o repositório.
$config = [
    'banco' => [
        'dsn'     => 'mysql:host=127.0.0.1;port=3306;dbname=tcc;charset=utf8mb4',
        'usuario' => 'root',
        'senha'   => '',
    ],
    'nome_condominio'  => 'Condomínio',
    'fuso'             => 'America/Sao_Paulo',

    // Segurança
    'forcar_https'     => false, // true em produção: redireciona todo acesso HTTP para HTTPS
    'sessao_minutos'   => 15,    // inatividade até a sessão expirar
    'tentativas_login' => 5,     // senhas erradas seguidas até bloquear a conta
    'bloqueio_minutos' => 15,
    'senha_minima'     => 8,

    // Fotos
    'max_fotos'        => 3,
    'max_foto_bytes'   => 8 * 1024 * 1024,
    'pasta_fotos'      => __DIR__ . '/../storage/fotos',

    // Avisos por e-mail
    'enviar_email'     => true,
    'email_remetente'  => 'nao-responda@tcc.test',

    // Limites que fazem o painel destacar um ponto de atenção para o síndico
    'alerta_dias_sem_resposta' => 7,
    'alerta_recorrencia_min'   => 3,  // ocorrências da mesma categoria e local...
    'alerta_recorrencia_dias'  => 90, // ...nesta janela de dias

    // Prazo de guarda: depois de quantos meses banco/retencao.php anonimiza a ocorrência
    // encerrada (null = nunca anonimiza)
    'retencao_meses'   => null,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
}
return $config;
