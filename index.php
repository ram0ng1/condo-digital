<?php
// Livro de Ocorrências Digital — controlador frontal: toda requisição entra por aqui.

declare(strict_types=1);

require __DIR__ . '/app/nucleo.php';
require __DIR__ . '/app/ocorrencias.php';
require __DIR__ . '/app/indicadores.php';

iniciar_sessao();

// Os formulários só enviam valores simples; descartar listas evita erros com entradas forjadas (?de[]=x).
$_GET = array_filter($_GET, 'is_scalar');
$_POST = array_filter($_POST, 'is_scalar');

// método(s), padrão da rota, arquivo em app/paginas
$rotas = [
    ['GET|POST', 'entrar',              'entrar'],
    ['POST',     'sair',                'sair'],
    ['GET',      '',                    'inicio'],
    ['GET',      'ocorrencias',         'ocorrencias'],
    ['GET|POST', 'ocorrencias/nova',    'ocorrencia_nova'],
    ['GET|POST', 'ocorrencias/(\d+)',   'ocorrencia'],
    ['GET',      'fotos/(\d+)',         'foto'],
    ['GET',      'painel',              'painel'],
    ['GET|POST', 'importar',            'importar'],
    ['POST',     'exportar',            'exportar'],
    ['GET',      'usuarios',            'usuarios'],
    ['GET|POST', 'usuarios/novo',       'usuario'],
    ['GET|POST', 'usuarios/(\d+)',      'usuario'],
    ['GET',      'acessos',             'acessos'],
    ['GET|POST', 'avisos',              'avisos'],
    ['GET|POST', 'conta',               'conta'],
];

$caminho = trim(substr(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen(base())), '/');
$metodo = $_SERVER['REQUEST_METHOD'];

foreach ($rotas as [$metodos, $padrao, $pagina]) {
    if (preg_match("#^$padrao$#", $caminho, $m) && in_array($metodo, explode('|', $metodos), true)) {
        if ($metodo === 'POST') {
            exigir_csrf();
        }
        $id = isset($m[1]) ? (int) $m[1] : null;

        // Senha provisória: o usuário só segue depois de criar a própria senha.
        $logado = usuario();
        if ($logado && $logado['trocar_senha'] && !in_array($pagina, ['conta', 'sair'], true)) {
            avisar_tela('info', 'Crie uma senha nova para continuar.');
            redirecionar('conta');
        }

        $titulo = '';
        ob_start();
        require __DIR__ . "/app/paginas/$pagina.php";
        $conteudo = ob_get_clean();
        require __DIR__ . '/app/paginas/_layout.php';
        exit;
    }
}
erro_http(404, 'O endereço informado não existe.');
