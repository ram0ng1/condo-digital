<?php
// Instalação: cria as tabelas, os cadastros básicos e o primeiro síndico.
//
//   php banco/instalar.php "Nome do síndico" email@exemplo.com "senha provisória"
//
// Só roda em banco vazio; não apaga nada. Para dados de demonstração, use banco/demo.php.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Execute pela linha de comando.');
}
require __DIR__ . '/../app/nucleo.php';

const CATEGORIAS = ['Barulho', 'Vazamento ou infiltração', 'Garagem e veículos', 'Animais', 'Obras e reformas', 'Limpeza',
    'Elevador', 'Segurança e portaria', 'Áreas comuns', 'Encomendas', 'Outros'];
const LOCAIS = ['Garagem' => 'garagem', 'Elevador' => 'comum', 'Hall e corredores' => 'comum', 'Salão de festas' => 'comum',
    'Área de lazer' => 'comum', 'Portaria' => 'comum', 'Fachada' => 'comum', 'Unidade' => 'unidade', 'Outro' => 'comum'];

/** Cria o esquema e os cadastros básicos (categorias da análise de conteúdo do TCC e locais). */
function criar_esquema(): void
{
    // Cada comando do esquema.sql termina em ";" seguido de linha em branco.
    foreach (preg_split('/;\s*\R\s*\R/', file_get_contents(__DIR__ . '/esquema.sql')) as $comando) {
        if (trim($comando) !== '') {
            bd()->exec($comando);
        }
    }
    foreach (CATEGORIAS as $nome) {
        consulta('INSERT INTO categorias (nome) VALUES (?)', [$nome]);
    }
    foreach (LOCAIS as $nome => $tipo) {
        consulta('INSERT INTO locais (nome, tipo) VALUES (?, ?)', [$nome, $tipo]);
    }
    if (!is_dir(cfg('pasta_fotos'))) {
        mkdir(cfg('pasta_fotos'), 0770, true);
    }
}

if (realpath($argv[0]) === __FILE__) {
    [, $nome, $email, $senha] = $argv + [1 => '', 2 => '', 3 => ''];
    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || ($erro = validar_senha_nova($senha))) {
        exit("Uso: php banco/instalar.php \"Nome do síndico\" email@exemplo.com \"senha provisória\"\n" . ($erro ?? '') . "\n");
    }
    if (bd()->query('SHOW TABLES')->fetchColumn()) {
        exit("O banco já tem tabelas. A instalação só roda em banco vazio.\n");
    }
    criar_esquema();
    consulta(
        'INSERT INTO usuarios (nome, email, senha_hash, perfil, trocar_senha, criado_em) VALUES (?, ?, ?, "sindico", 1, ?)',
        [$nome, mb_strtolower($email), password_hash($senha, PASSWORD_DEFAULT), agora()]
    );
    echo "Instalado. Entre com $email; o sistema pedirá uma senha nova no primeiro acesso.\n";
}
