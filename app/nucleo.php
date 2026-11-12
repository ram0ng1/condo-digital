<?php
// Núcleo: configuração, banco, sessão, autenticação, CSRF, auditoria e avisos.

declare(strict_types=1);

$GLOBALS['config'] = require __DIR__ . '/config.php';
date_default_timezone_set(cfg('fuso'));
mb_internal_encoding('UTF-8');

const PERFIS = ['morador' => 'Morador', 'portaria' => 'Portaria', 'sindico' => 'Síndico'];

// Detalhes técnicos de um erro vão para o log do servidor, nunca para a tela do usuário.
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler(function (Throwable $e): void {
        error_log('Livro de ocorrências: ' . $e);
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code(500);
        echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Não foi possível concluir</title><body style="font-family:system-ui,sans-serif;max-width:520px;margin:15vh auto;padding:0 20px;line-height:1.5">'
            . '<h1 style="font-size:22px">Não foi possível concluir agora</h1>'
            . '<p>O sistema encontrou um problema e nada foi alterado. Volte, aguarde um instante e tente de novo. '
            . 'Se continuar, avise o síndico e informe o horário: ' . date('d/m/Y H:i') . '.</p>'
            . '<p><a href="javascript:history.back()">Voltar</a></p></body></html>';
    });
}

function cfg(string $chave): mixed
{
    return $GLOBALS['config'][$chave];
}

function agora(): string
{
    return date('Y-m-d H:i:s');
}

// ----- Banco -----

function bd(): PDO
{
    static $pdo;
    if (!$pdo) {
        $b = cfg('banco');
        $pdo = new PDO($b['dsn'], $b['usuario'], $b['senha'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** Executa SQL sempre com parâmetros preparados (nunca concatenar entrada do usuário). */
function consulta(string $sql, array $params = []): PDOStatement
{
    $st = bd()->prepare($sql);
    $st->execute($params);
    return $st;
}

// ----- Saída -----

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base(): string
{
    static $base;
    return $base ??= rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
}

function url(string $caminho = ''): string
{
    return base() . '/' . ltrim($caminho, '/');
}

function redirecionar(string $caminho): never
{
    header('Location: ' . url($caminho), true, 303);
    exit;
}

function icone(string $nome, int $tamanho = 18): string
{
    static $icones;
    $icones ??= require __DIR__ . '/icones.php';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $tamanho . '" height="' . $tamanho
        . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
        . ' stroke-linejoin="round" aria-hidden="true">' . $icones[$nome] . '</svg>';
}

function data_br(?string $dataHora, bool $comHora = true): string
{
    if (!$dataHora) {
        return 'sem data';
    }
    return date($comHora ? 'd/m/Y H:i' : 'd/m/Y', strtotime($dataHora));
}

function erro_http(int $codigo, string $mensagem): never
{
    http_response_code($codigo);
    $titulo = $codigo === 404 ? 'Página não encontrada' : 'Acesso negado';
    $conteudo = '<div class="Card"><div class="Empty">' . icone('alerta', 40) . '<strong>' . e($titulo) . '</strong><span>'
        . e($mensagem) . '</span><a class="Button Button--primary" href="' . e(url()) . '">Voltar ao início</a></div></div>';
    require __DIR__ . '/paginas/_layout.php';
    exit;
}

function avisar_tela(string $tipo, string $mensagem): void
{
    $_SESSION['flash'][] = [$tipo, $mensagem];
}

// ----- Sessão e autenticação -----

function iniciar_sessao(): void
{
    $https = ($_SERVER['HTTPS'] ?? 'off') !== 'off';
    if (cfg('forcar_https') && !$https) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
        exit;
    }
    session_name('livro_ocorrencias');
    session_set_cookie_params(['path' => base() . '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    session_start();

    // Sessão parada por mais tempo que o configurado é encerrada.
    $limite = cfg('sessao_minutos') * 60;
    if (isset($_SESSION['usuario_id'], $_SESSION['ultimo_uso']) && time() - $_SESSION['ultimo_uso'] > $limite) {
        session_unset();
        avisar_tela('info', 'Sua sessão expirou por inatividade. Entre novamente.');
    }
    $_SESSION['ultimo_uso'] = time();
}

/** Usuário da sessão, relido do banco a cada requisição (perfil e bloqueio valem na hora). */
function usuario(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (isset($_SESSION['usuario_id'])) {
            $u = consulta(
                'SELECT u.id, u.nome, u.email, u.perfil, u.unidade_id, u.trocar_senha, un.bloco, un.numero
                   FROM usuarios u LEFT JOIN unidades un ON un.id = u.unidade_id
                  WHERE u.id = ? AND u.ativo = 1',
                [$_SESSION['usuario_id']]
            )->fetch() ?: null;
            if (!$u) {
                session_unset();
            }
        }
    }
    return $u;
}

function exigir_login(string ...$perfis): array
{
    $u = usuario();
    if (!$u) {
        redirecionar('entrar');
    }
    if ($perfis && !in_array($u['perfil'], $perfis, true)) {
        erro_http(403, 'Seu perfil não tem acesso a esta página.');
    }
    return $u;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function exigir_csrf(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        erro_http(403, 'Formulário expirado. Volte, recarregue a página e tente de novo.');
    }
}

/**
 * Confere e-mail e senha. Depois de várias senhas erradas seguidas, a conta fica bloqueada por um tempo.
 * Devolve o id do usuário ou uma mensagem de erro (a mesma para e-mail inexistente e senha errada).
 */
function autenticar(string $email, string $senha): int|string
{
    $generica = 'E-mail ou senha incorretos.';
    $u = consulta('SELECT id, senha_hash, ativo, tentativas_falhas, bloqueado_ate FROM usuarios WHERE email = ?', [$email])->fetch();
    if (!$u) {
        password_hash($senha, PASSWORD_DEFAULT); // gasta o mesmo tempo com ou sem conta (não revela quais e-mails existem)
        return $generica;
    }
    if ($u['bloqueado_ate'] && $u['bloqueado_ate'] > agora()) {
        return 'Conta bloqueada por tentativas seguidas de senha incorreta. Tente de novo após '
            . date('H:i', strtotime($u['bloqueado_ate'])) . '.';
    }
    if (!$u['ativo'] || !password_verify($senha, $u['senha_hash'])) {
        $falhas = $u['tentativas_falhas'] + 1;
        $bloquear = $falhas >= cfg('tentativas_login');
        consulta('UPDATE usuarios SET tentativas_falhas = ?, bloqueado_ate = ? WHERE id = ?', [
            $bloquear ? 0 : $falhas,
            $bloquear ? date('Y-m-d H:i:s', time() + cfg('bloqueio_minutos') * 60) : null,
            $u['id'],
        ]);
        auditar('entrar_falha', null, $bloquear ? 'conta bloqueada' : null, (int) $u['id']);
        return $bloquear
            ? 'Conta bloqueada por ' . cfg('bloqueio_minutos') . ' minutos, por tentativas seguidas de senha incorreta.'
            : $generica;
    }
    consulta('UPDATE usuarios SET tentativas_falhas = 0, bloqueado_ate = NULL WHERE id = ?', [$u['id']]);
    return (int) $u['id'];
}

/** Exportar dados e gerenciar usuários pedem a senha de novo, mesmo com a sessão aberta. */
function senha_confere(array $u, string $senha): bool
{
    $hash = consulta('SELECT senha_hash FROM usuarios WHERE id = ?', [$u['id']])->fetchColumn();
    return $hash && password_verify($senha, $hash);
}

function validar_senha_nova(string $senha): ?string
{
    if (mb_strlen($senha) < cfg('senha_minima')) {
        return 'A senha precisa ter pelo menos ' . cfg('senha_minima') . ' caracteres.';
    }
    return null;
}

// ----- Registro de acessos e avisos -----

function auditar(string $acao, ?int $ocorrenciaId = null, ?string $detalhe = null, ?int $usuarioId = null): void
{
    consulta(
        'INSERT INTO registros_acesso (usuario_id, ocorrencia_id, acao, detalhe, ocorrido_em) VALUES (?, ?, ?, ?, ?)',
        [$usuarioId ?? ($_SESSION['usuario_id'] ?? null), $ocorrenciaId, $acao, $detalhe, agora()]
    );
}

/** Aviso na tela e, se configurado, por e-mail. O e-mail nunca leva o texto da ocorrência. */
function avisar(int $usuarioId, ?int $ocorrenciaId, string $mensagem): void
{
    consulta(
        'INSERT INTO avisos (usuario_id, ocorrencia_id, mensagem, criado_em) VALUES (?, ?, ?, ?)',
        [$usuarioId, $ocorrenciaId, $mensagem, agora()]
    );
    if (!cfg('enviar_email')) {
        return;
    }
    $email = consulta('SELECT email FROM usuarios WHERE id = ?', [$usuarioId])->fetchColumn();
    $link = (($_SERVER['HTTPS'] ?? 'off') !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . url($ocorrenciaId ? "ocorrencias/$ocorrenciaId" : 'avisos');
    $corpo = "$mensagem\n\nAcesse o livro de ocorrências para ver os detalhes:\n$link\n";
    // Falha no envio não pode desfazer o registro: o aviso na tela já foi gravado.
    @mail($email, mb_encode_mimeheader('Livro de ocorrências: ' . $mensagem), $corpo,
        "From: " . cfg('email_remetente') . "\r\nContent-Type: text/plain; charset=UTF-8");
}

function avisos_nao_lidos(int $usuarioId): int
{
    return (int) consulta('SELECT COUNT(*) FROM avisos WHERE usuario_id = ? AND lido_em IS NULL', [$usuarioId])->fetchColumn();
}

/** A instalação só tem contas de demonstração (@demo.test)? Usado para o aviso de dados fictícios. */
function instalacao_demo(): bool
{
    static $demo;
    return $demo ??= (bool) consulta(
        "SELECT COUNT(*) > 0 AND SUM(email NOT LIKE '%@demo.test') = 0 FROM usuarios"
    )->fetchColumn();
}
