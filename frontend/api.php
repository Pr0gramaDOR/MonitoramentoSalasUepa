<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

date_default_timezone_set('America/Belem');

// Em produção (Docker) o banco fica FORA da pasta pública, via variável DB_FILE.
define('DB_FILE', getenv('DB_FILE') ?: __DIR__ . '/campus_xv.sqlite');
const PAPEIS = ['admin', 'operador', 'visualizador'];
const SESSAO_SEGUNDOS = 60 * 60 * 24 * 30;   // 30 dias (a TV precisa ficar logada)

function responder($payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function falhar(string $msg, int $status = 400): void
{
    responder(['error' => $msg], $status);
}

set_exception_handler(function (Throwable $e) {
    error_log('[campus_xv] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    responder(['error' => 'Erro interno do servidor. Verifique o log do PHP.'], 500);
});
set_error_handler(function (int $no, string $str, string $file, int $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

/* ------------------------------------------------------------------ */
/* Sessão                                                              */
/* ------------------------------------------------------------------ */

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.gc_maxlifetime', (string)SESSAO_SEGUNDOS);
ini_set('session.use_strict_mode', '1');
session_name('campus_xv');
session_set_cookie_params([
    'lifetime' => SESSAO_SEGUNDOS,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $https,
]);
session_start();
if (session_id() !== '') {   // renova o cookie a cada uso (a TV não desloga sozinha)
    setcookie(session_name(), session_id(), [
        'expires' => time() + SESSAO_SEGUNDOS, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => $https,
    ]);
}

/* ------------------------------------------------------------------ */
/* Horários e capacidade (funções puras)                               */
/* ------------------------------------------------------------------ */

function horaParaMinutos(?string $h): ?int
{
    if ($h === null || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $h, $m)) {
        return null;
    }
    return (int)$m[1] * 60 + (int)$m[2];
}

function minutosParaHora(int $min): string
{
    $min = max(0, min($min, 1439));
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

// Só para dados antigos, digitados à mão ("Manhã (08h - 12h)"): tenta descobrir a faixa.
function faixaDoPeriodo(string $p): ?array
{
    $s = mb_strtolower($p);
    if (preg_match_all('/(\d{1,2})\s*(?:h|:)\s*(\d{2})?/u', $s, $m, PREG_SET_ORDER) && count($m) >= 2) {
        $h1 = (int)$m[0][1]; $m1 = (int)($m[0][2] ?? 0);
        $h2 = (int)$m[1][1]; $m2 = (int)($m[1][2] ?? 0);
        if ($h1 <= 24 && $h2 <= 24 && $m1 < 60 && $m2 < 60) {
            $ini = $h1 * 60 + $m1;
            $fim = $h2 * 60 + $m2;
            if ($fim > $ini) {
                return [$ini, min($fim, 1439)];
            }
        }
    }
    if (mb_strpos($s, 'integral') !== false) { return [360, 1380]; }
    if (mb_strpos($s, 'manhã') !== false || mb_strpos($s, 'manha') !== false) { return [360, 720]; }
    if (mb_strpos($s, 'tarde') !== false) { return [720, 1080]; }
    if (mb_strpos($s, 'noite') !== false) { return [1080, 1380]; }
    return null;
}

// Faixa de uma aula gravada: usa os campos estruturados; se faltarem (dado legado), interpreta o texto.
function faixaDaAula(array $o): ?array
{
    $hi = horaParaMinutos($o['hora_inicio'] ?? null);
    $hf = horaParaMinutos($o['hora_fim'] ?? null);
    if ($hi !== null && $hf !== null && $hf > $hi) {
        return [$hi, $hf];
    }
    return faixaDoPeriodo((string)($o['periodo'] ?? ''));
}

function faixasConflitam(?array $a, ?array $b, string $textoA, string $textoB): bool
{
    if ($a !== null && $b !== null) {
        return $a[0] < $b[1] && $b[0] < $a[1];
    }
    return mb_strtolower(trim($textoA)) === mb_strtolower(trim($textoB));
}

/* ------------------------------------------------------------------ */
/* Banco + migração                                                    */
/* ------------------------------------------------------------------ */

$pdo = new PDO('sqlite:' . DB_FILE);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->exec('PRAGMA journal_mode = WAL');

function colunasDaTabela(PDO $pdo, string $tabela): array
{
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info($tabela)") as $c) {
        $cols[] = $c['name'];
    }
    return $cols;
}

function garantirColunas(PDO $pdo, string $tabela, array $colunas): void
{
    $existentes = colunasDaTabela($pdo, $tabela);
    foreach ($colunas as $col => $def) {
        if (!in_array($col, $existentes, true)) {
            $pdo->exec("ALTER TABLE $tabela ADD COLUMN $col $def");
        }
    }
}

function migrar(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS salas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        bloco TEXT NOT NULL,
        tipo TEXT NOT NULL DEFAULT 'SalaAula',
        quantidade_cadeiras INTEGER DEFAULT 0,
        possui_projetor INTEGER DEFAULT 0,
        quantidade_computadores INTEGER DEFAULT 0,
        possui_tv INTEGER DEFAULT 0,
        problemas TEXT DEFAULT '[]',
        status_equipamento TEXT DEFAULT '',
        problema_reportado_em TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ocupacao (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sala_id INTEGER NOT NULL,
        turma TEXT, professor TEXT, contato TEXT, periodo TEXT,
        data_inicio TEXT, data_fim TEXT,
        FOREIGN KEY(sala_id) REFERENCES salas(id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS historico (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sala_id INTEGER, nome_sala TEXT, turma TEXT, professor TEXT,
        data_inicio TEXT, data_fim TEXT,
        data_registro DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        usuario TEXT NOT NULL UNIQUE COLLATE NOCASE,
        senha_hash TEXT NOT NULL,
        papel TEXT NOT NULL DEFAULT 'operador',
        ativo INTEGER NOT NULL DEFAULT 1,
        falhas INTEGER NOT NULL DEFAULT 0,
        bloqueado_ate TEXT,
        criado_em TEXT,
        ultimo_login TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id INTEGER,
        usuario_nome TEXT,
        acao TEXT NOT NULL,
        detalhes TEXT,
        ip TEXT,
        criado_em TEXT NOT NULL
    )");

    garantirColunas($pdo, 'salas', [
        'problemas'               => "TEXT DEFAULT '[]'",
        'status_equipamento'      => "TEXT DEFAULT ''",
        'problema_reportado_em'   => 'TEXT',
        'possui_tv'               => 'INTEGER DEFAULT 0',
        'quantidade_computadores' => 'INTEGER DEFAULT 0',
    ]);
    garantirColunas($pdo, 'ocupacao', [
        'contato'        => 'TEXT',
        'hora_inicio'    => 'TEXT',
        'hora_fim'       => 'TEXT',
        'tamanho_turma'  => 'INTEGER',
    ]);
    garantirColunas($pdo, 'historico', [
        'periodo'        => 'TEXT',
        'hora_inicio'    => 'TEXT',
        'hora_fim'       => 'TEXT',
        'tamanho_turma'  => 'INTEGER',
    ]);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ocupacao_sala ON ocupacao(sala_id, data_fim)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_data ON logs(criado_em)');

    // Dados antigos: converte o texto livre do período em horário estruturado (uma única vez por linha).
    $legado = $pdo->query("SELECT id, periodo FROM ocupacao WHERE hora_inicio IS NULL OR hora_inicio = ''")->fetchAll();
    if ($legado) {
        $up = $pdo->prepare('UPDATE ocupacao SET hora_inicio = ?, hora_fim = ? WHERE id = ?');
        foreach ($legado as $r) {
            $f = faixaDoPeriodo((string)$r['periodo']);
            if ($f !== null) {
                $up->execute([minutosParaHora($f[0]), minutosParaHora($f[1]), $r['id']]);
            }
        }
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM salas')->fetchColumn() === 0) {
        $iniciais = [
            ['Lab. Informática',   'Biblioteca', 'Laboratório', 0, 0, 20, 1],
            ['Sala 1',             'Bloco 1', 'SalaAula', 40, 1, 0, 0],
            ['Sala 2',             'Bloco 1', 'SalaAula', 40, 1, 0, 0],
            ['Sala 3',             'Bloco 1', 'SalaAula', 45, 1, 0, 0],
            ['Sala 4',             'Bloco 1', 'SalaAula', 45, 0, 0, 0],
            ['Lab. Eng. Software', 'Bloco 2', 'Laboratório', 0, 1, 30, 1],
            ['Lab. Eng. Produção', 'Bloco 2', 'Laboratório', 0, 1, 25, 0],
            ['Sala 5',             'Bloco 3', 'SalaAula', 50, 1, 0, 0],
            ['Sala Superior',      'Bloco 3', 'SalaAula', 30, 0, 0, 0],
            ['Lab. Informática',   'Bloco 3', 'Laboratório', 0, 0, 20, 1],
        ];
        $stmt = $pdo->prepare("INSERT INTO salas
            (nome, bloco, tipo, quantidade_cadeiras, possui_projetor, quantidade_computadores, possui_tv, problemas)
            VALUES (?, ?, ?, ?, ?, ?, ?, '[]')");
        foreach ($iniciais as $s) {
            $stmt->execute($s);
        }
    }
}

migrar($pdo);

/* ------------------------------------------------------------------ */
/* Helpers de entrada                                                  */
/* ------------------------------------------------------------------ */

function txt(array $d, string $k, int $max = 200): string
{
    $v = $d[$k] ?? '';
    $v = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($v, 0, $max);
}

function inteiro(array $d, string $k, int $padrao = 0, int $min = 0, int $max = 100000): int
{
    $v = $d[$k] ?? $padrao;
    if (!is_numeric($v)) {
        return $padrao;
    }
    return max($min, min($max, (int)$v));
}

function booleano(array $d, string $k): int
{
    $v = $d[$k] ?? 0;
    return ($v === true || $v === 1 || $v === '1' || $v === 'true') ? 1 : 0;
}

function dataValida(string $s): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $s);
    return $dt !== false && $dt->format('Y-m-d') === $s;
}

function dataBr(string $iso): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? "$m[3]/$m[2]/$m[1]" : $iso;
}

function buscarSala(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM salas WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function decodificarProblemas($json): array
{
    $arr = json_decode((string)($json ?? '[]'), true);
    return is_array($arr) ? array_values(array_filter($arr, 'is_string')) : [];
}

/* ------------------------------------------------------------------ */
/* Usuário atual + logs                                                */
/* ------------------------------------------------------------------ */

function usuarioAtual(?array $set = null, bool $limpar = false): ?array
{
    static $u = null;
    if ($limpar) { $u = null; }
    if ($set !== null) { $u = $set; }
    return $u;
}

function carregarUsuarioDaSessao(PDO $pdo): ?array
{
    $id = $_SESSION['uid'] ?? null;
    if (!$id) {
        return null;
    }
    $st = $pdo->prepare('SELECT id, nome, usuario, papel, ativo FROM usuarios WHERE id = ?');
    $st->execute([(int)$id]);
    $u = $st->fetch();
    // Papel e "ativo" são relidos do banco a cada requisição: desativar alguém vale na hora.
    if (!$u || !(int)$u['ativo'] || !in_array($u['papel'], PAPEIS, true)) {
        unset($_SESSION['uid']);
        return null;
    }
    return $u;
}

function registrar(PDO $pdo, string $acao, string $detalhes = '', ?array $usuario = null): void
{
    try {
        $u = $usuario ?? usuarioAtual();
        $pdo->prepare('INSERT INTO logs (usuario_id, usuario_nome, acao, detalhes, ip, criado_em) VALUES (?,?,?,?,?,?)')
            ->execute([
                $u['id'] ?? null,
                $u['usuario'] ?? '',
                $acao,
                mb_substr($detalhes, 0, 500),
                $_SERVER['REMOTE_ADDR'] ?? '',
                date('Y-m-d H:i:s'),
            ]);
    } catch (Throwable $e) {
        error_log('[campus_xv] falha ao gravar log: ' . $e->getMessage());
    }
}

function senhaValida(string $s): bool
{
    return strlen($s) >= 8 && strlen($s) <= 72;
}

function dadosPublicosUsuario(array $u): array
{
    return ['id' => (int)$u['id'], 'nome' => $u['nome'], 'usuario' => $u['usuario'], 'papel' => $u['papel']];
}

/* ------------------------------------------------------------------ */
/* Regras de negócio: conflito de horário e capacidade                 */
/* ------------------------------------------------------------------ */

// Devolve a aula que já ocupa a sala em faixa de horário e datas que se sobrepõem (ou null).
function temConflito(PDO $pdo, int $salaId, ?array $faixaNova, string $textoNovo, string $ini, string $fim, int $ignorarId = 0): ?array
{
    $st = $pdo->prepare('SELECT * FROM ocupacao
        WHERE sala_id = ? AND id != ? AND data_inicio <= ? AND data_fim >= ?');
    $st->execute([$salaId, $ignorarId, $fim, $ini]);
    foreach ($st->fetchAll() as $o) {
        if (faixasConflitam($faixaNova, faixaDaAula($o), $textoNovo, (string)$o['periodo'])) {
            return $o;
        }
    }
    return null;
}

function conflito409(PDO $pdo, int $salaId, array $c, string $msg): void
{
    $sala = buscarSala($pdo, $salaId);
    responder([
        'error' => $msg,
        'conflito' => [
            'sala_nome'   => (string)($sala['nome'] ?? ''),
            'professor'   => (string)$c['professor'],
            'turma'       => (string)$c['turma'],
            'periodo'     => (string)$c['periodo'],
            'data_inicio' => (string)$c['data_inicio'],
            'data_fim'    => (string)$c['data_fim'],
        ],
    ], 409);
}

function capacidadeDaSala(array $sala): array
{
    return $sala['tipo'] === 'Laboratório'
        ? [(int)$sala['quantidade_computadores'], 'computadores']
        : [(int)$sala['quantidade_cadeiras'], 'lugares'];
}

// Recusa turma maior que a sala. Capacidade 0 = sala ainda não configurada (sem limite).
function exigirCapacidade(array $sala, ?int $tamanho): void
{
    if ($tamanho === null) {
        return;
    }
    [$cap, $unidade] = capacidadeDaSala($sala);
    if ($cap > 0 && $tamanho > $cap) {
        responder([
            'error' => "A turma ($tamanho alunos) não cabe na sala {$sala['nome']} ($cap $unidade).",
            'capacidade' => [
                'sala_nome'  => (string)$sala['nome'],
                'capacidade' => $cap,
                'unidade'    => $unidade,
                'tamanho'    => $tamanho,
            ],
        ], 409);
    }
}

function resumoAula(array $a, string $sala = ''): string
{
    $partes = [];
    if ($sala !== '') { $partes[] = $sala; }
    $partes[] = trim(($a['professor'] ?? '') . ' / ' . ($a['turma'] ?? ''), ' /');
    $partes[] = (string)($a['periodo'] ?? '');
    if (!empty($a['tamanho_turma'])) { $partes[] = $a['tamanho_turma'] . ' alunos'; }
    $partes[] = dataBr((string)($a['data_inicio'] ?? '')) . ' a ' . dataBr((string)($a['data_fim'] ?? ''));
    return implode(' · ', array_filter($partes, fn($p) => $p !== ''));
}

/* ------------------------------------------------------------------ */
/* Roteamento, método e permissões                                     */
/* ------------------------------------------------------------------ */

$action = $_GET['action'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$TODOS = ['visualizador', 'operador', 'admin'];
$OPERA = ['operador', 'admin'];
$ADMIN = ['admin'];

$publicas = ['sessao', 'login', 'setup', 'logout'];
$permissoes = [
    'listar'            => $TODOS,
    'gestao_total'      => $OPERA,
    'backup'            => $OPERA,
    'salvar'            => $OPERA,
    'mover'             => $OPERA,
    'liberar'           => $OPERA,
    'salvar_manutencao' => $OPERA,
    'alterar_senha'     => $TODOS,
    'criar_sala'        => $ADMIN,
    'editar_sala'       => $ADMIN,
    'restaurar'         => $ADMIN,
    'usuarios_listar'   => $ADMIN,
    'usuario_criar'     => $ADMIN,
    'usuario_editar'    => $ADMIN,
    'usuario_senha'     => $ADMIN,
    'logs'              => $ADMIN,
];
$acoesGet = ['sessao', 'listar', 'gestao_total', 'backup', 'usuarios_listar', 'logs'];

if (!in_array($action, $publicas, true) && !isset($permissoes[$action])) {
    falhar('Ação inválida.', 404);
}
$exigePost = !in_array($action, $acoesGet, true);
if ($exigePost && $metodo !== 'POST') {
    falhar('Esta ação exige método POST.', 405);
}

$data = [];
if ($metodo === 'POST') {
    // Só aceita JSON: um formulário de outro site não consegue enviar isso (proteção contra CSRF).
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
        falhar('Content-Type deve ser application/json.', 415);
    }
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            falhar('JSON inválido no corpo da requisição.');
        }
    }
}

/* ------------------------------------------------------------------ */
/* Autenticação (ações públicas)                                       */
/* ------------------------------------------------------------------ */

if ($action === 'sessao') {
    $u = carregarUsuarioDaSessao($pdo);
    if ($u) {
        responder(['autenticado' => true, 'usuario' => dadosPublicosUsuario($u)]);
    }
    $semUsuarios = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0;
    responder(['autenticado' => false, 'precisa_setup' => $semUsuarios]);
}

if ($action === 'setup') {
    // Só funciona enquanto não existe nenhum usuário: cria o administrador supremo.
    if ((int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0) {
        falhar('O sistema já foi configurado.', 403);
    }
    $nome  = txt($data, 'nome', 80);
    $login = mb_strtolower(txt($data, 'usuario', 32));
    $senha = (string)($data['senha'] ?? '');
    if ($nome === '' || !preg_match('/^[a-z0-9._-]{3,32}$/', $login)) {
        falhar('Informe o nome e um usuário de 3 a 32 caracteres (letras, números, ponto, hífen ou _).');
    }
    if (!senhaValida($senha)) {
        falhar('A senha deve ter de 8 a 72 caracteres.');
    }
    $pdo->prepare('INSERT INTO usuarios (nome, usuario, senha_hash, papel, criado_em, ultimo_login) VALUES (?,?,?,?,?,?)')
        ->execute([$nome, $login, password_hash($senha, PASSWORD_DEFAULT), 'admin', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    $id = (int)$pdo->lastInsertId();
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
    $u = ['id' => $id, 'nome' => $nome, 'usuario' => $login, 'papel' => 'admin'];
    registrar($pdo, 'setup_admin', 'Administrador inicial criado', $u);
    responder(['success' => true, 'usuario' => dadosPublicosUsuario($u)]);
}

if ($action === 'login') {
    $login = mb_strtolower(txt($data, 'usuario', 32));
    $senha = (string)($data['senha'] ?? '');
    $st = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = ?');
    $st->execute([$login]);
    $u = $st->fetch();

    if ($u && $u['bloqueado_ate'] && $u['bloqueado_ate'] > date('Y-m-d H:i:s')) {
        registrar($pdo, 'login_bloqueado', 'Tentativa durante bloqueio', $u);
        falhar('Muitas tentativas erradas. Aguarde alguns minutos e tente de novo.', 429);
    }
    if (!$u || !password_verify($senha, (string)$u['senha_hash'])) {
        usleep(300000);
        if ($u) {
            $falhas = (int)$u['falhas'] + 1;
            $bloqueio = null;
            if ($falhas >= 5) {
                $bloqueio = date('Y-m-d H:i:s', time() + 300);
                $falhas = 0;
            }
            $pdo->prepare('UPDATE usuarios SET falhas = ?, bloqueado_ate = ? WHERE id = ?')->execute([$falhas, $bloqueio, $u['id']]);
        }
        registrar($pdo, 'login_falha', 'Usuário informado: ' . $login, $u ?: ['usuario' => $login]);
        falhar('Usuário ou senha inválidos.', 401);
    }
    if (!(int)$u['ativo']) {
        registrar($pdo, 'login_falha', 'Conta desativada', $u);
        falhar('Esta conta está desativada. Procure o administrador.', 403);
    }
    $pdo->prepare('UPDATE usuarios SET falhas = 0, bloqueado_ate = NULL, ultimo_login = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), $u['id']]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    registrar($pdo, 'login_ok', '', $u);
    responder(['success' => true, 'usuario' => dadosPublicosUsuario($u)]);
}

if ($action === 'logout') {
    $u = carregarUsuarioDaSessao($pdo);
    if ($u) {
        registrar($pdo, 'logout', '', $u);
    }
    $_SESSION = [];
    session_regenerate_id(true);
    responder(['success' => true]);
}

/* ------------------------------------------------------------------ */
/* A partir daqui: exige login e papel                                 */
/* ------------------------------------------------------------------ */

$usuario = carregarUsuarioDaSessao($pdo);
if (!$usuario) {
    falhar('Sessão expirada ou não iniciada. Faça login.', 401);
}
usuarioAtual($usuario);
if (!in_array($usuario['papel'], $permissoes[$action], true)) {
    registrar($pdo, 'acesso_negado', "Tentou a ação \"$action\"");
    falhar('Você não tem permissão para esta ação.', 403);
}

/* ------------------------------------------------------------------ */
/* Ações                                                               */
/* ------------------------------------------------------------------ */

if ($action === 'listar') {
    $hoje = date('Y-m-d');
    $salas = $pdo->query('SELECT * FROM salas ORDER BY bloco, id')->fetchAll();

    $st = $pdo->prepare('SELECT * FROM ocupacao WHERE data_fim >= ? ORDER BY data_inicio, hora_inicio, periodo');
    $st->execute([$hoje]);
    $porSala = [];
    foreach ($st->fetchAll() as $o) {
        $porSala[$o['sala_id']][] = $o;
    }
    foreach ($salas as &$s) {
        $s['problemas'] = decodificarProblemas($s['problemas']);
        $s['status_equipamento'] = $s['status_equipamento'] ?? '';
        $s['aulas'] = $porSala[$s['id']] ?? [];
    }
    unset($s);
    responder($salas);
}

if ($action === 'gestao_total') {
    $ocup = $pdo->query('SELECT o.*, s.nome AS sala_nome, s.bloco
        FROM ocupacao o JOIN salas s ON o.sala_id = s.id
        ORDER BY o.data_fim DESC')->fetchAll();
    $hist = $pdo->query('SELECT * FROM historico ORDER BY data_registro DESC LIMIT 500')->fetchAll();
    responder(['ocupacoes' => $ocup, 'historico' => $hist]);
}

if ($action === 'salvar') {
    $id        = inteiro($data, 'id');
    $salaId    = inteiro($data, 'sala_id');
    $professor = txt($data, 'professor', 120);
    $turma     = txt($data, 'turma', 160);
    $contato   = txt($data, 'contato', 120);
    $hi        = txt($data, 'hora_inicio', 5);
    $hf        = txt($data, 'hora_fim', 5);
    $ini       = txt($data, 'data_inicio', 10);
    $fim       = txt($data, 'data_fim', 10);
    $tamanho   = isset($data['tamanho_turma']) && is_numeric($data['tamanho_turma']) ? (int)$data['tamanho_turma'] : 0;

    if ($professor === '' || $turma === '') {
        falhar('Preencha professor e turma.');
    }
    $mi = horaParaMinutos($hi);
    $mf = horaParaMinutos($hf);
    if ($mi === null || $mf === null) {
        falhar('Informe horário de início e de fim válidos (HH:MM).');
    }
    if ($mf <= $mi) {
        falhar('O horário de fim deve ser depois do horário de início.');
    }
    if ($tamanho < 1 || $tamanho > 1000) {
        falhar('Informe o tamanho da turma (número de alunos, de 1 a 1000).');
    }
    if (!dataValida($ini) || !dataValida($fim)) {
        falhar('Datas inválidas.');
    }
    if ($fim < $ini) {
        falhar('A data de fim não pode ser anterior à data de início.');
    }
    $sala = buscarSala($pdo, $salaId);
    if (!$sala) {
        falhar('Sala não encontrada.', 404);
    }

    exigirCapacidade($sala, $tamanho);

    $periodo = "$hi - $hf";
    $conf = temConflito($pdo, $salaId, [$mi, $mf], $periodo, $ini, $fim, $id);
    if ($conf) {
        conflito409($pdo, $salaId, $conf, 'Já existe outra aula neste horário e nas mesmas datas nesta sala.');
    }

    $reg = ['professor' => $professor, 'turma' => $turma, 'periodo' => $periodo, 'tamanho_turma' => $tamanho, 'data_inicio' => $ini, 'data_fim' => $fim];
    if ($id > 0) {
        $existe = $pdo->prepare('SELECT 1 FROM ocupacao WHERE id = ?');
        $existe->execute([$id]);
        if (!$existe->fetchColumn()) {
            falhar('Ocupação não encontrada.', 404);
        }
        $pdo->prepare('UPDATE ocupacao SET sala_id=?, turma=?, professor=?, contato=?, periodo=?, hora_inicio=?, hora_fim=?, tamanho_turma=?, data_inicio=?, data_fim=? WHERE id=?')
            ->execute([$salaId, $turma, $professor, $contato, $periodo, $hi, $hf, $tamanho, $ini, $fim, $id]);
        registrar($pdo, 'aula_editada', resumoAula($reg, $sala['nome']));
    } else {
        $pdo->prepare('INSERT INTO ocupacao (sala_id, turma, professor, contato, periodo, hora_inicio, hora_fim, tamanho_turma, data_inicio, data_fim) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$salaId, $turma, $professor, $contato, $periodo, $hi, $hf, $tamanho, $ini, $fim]);
        registrar($pdo, 'aula_criada', resumoAula($reg, $sala['nome']));
    }
    responder(['success' => true]);
}

if ($action === 'salvar_manutencao') {
    $salaId = inteiro($data, 'sala_id');
    $sala = buscarSala($pdo, $salaId);
    if (!$sala) {
        falhar('Sala não encontrada.', 404);
    }
    $problemas = [];
    if (isset($data['problemas']) && is_array($data['problemas'])) {
        foreach ($data['problemas'] as $p) {
            if (is_string($p) && trim($p) !== '') {
                $problemas[] = mb_substr(trim($p), 0, 60);
            }
        }
    }
    $problemas = array_slice(array_values(array_unique($problemas)), 0, 20);
    $obs = txt($data, 'status_equipamento', 500);
    $temProblema = (count($problemas) > 0 || $obs !== '') ? 1 : 0;

    $pdo->prepare("UPDATE salas
        SET problemas = ?, status_equipamento = ?,
            problema_reportado_em = CASE WHEN ? = 1 THEN COALESCE(problema_reportado_em, ?) ELSE NULL END
        WHERE id = ?")
        ->execute([json_encode($problemas, JSON_UNESCAPED_UNICODE), $obs, $temProblema, date('Y-m-d H:i:s'), $salaId]);

    if ($temProblema) {
        registrar($pdo, 'problema_reportado', $sala['nome'] . ': ' . implode(', ', $problemas) . ($obs !== '' ? " — $obs" : ''));
    } else {
        registrar($pdo, 'problema_resolvido', $sala['nome']);
    }
    responder(['success' => true]);
}

if ($action === 'editar_sala') {
    $id   = inteiro($data, 'id');
    $nome = txt($data, 'nome', 80);
    if ($nome === '') {
        falhar('O nome da sala é obrigatório.');
    }
    $sala = buscarSala($pdo, $id);
    if (!$sala) {
        falhar('Sala não encontrada.', 404);
    }
    $cad = inteiro($data, 'quantidade_cadeiras', 0, 0, 1000);
    $pcs = inteiro($data, 'quantidade_computadores', 0, 0, 1000);

    // Não deixa reduzir a capacidade abaixo de uma turma que ainda está alocada na sala.
    $novoCap = $sala['tipo'] === 'Laboratório' ? $pcs : $cad;
    if ($novoCap > 0) {
        $st = $pdo->prepare('SELECT professor, turma, tamanho_turma FROM ocupacao
            WHERE sala_id = ? AND data_fim >= ? AND tamanho_turma > ? LIMIT 1');
        $st->execute([$id, date('Y-m-d'), $novoCap]);
        if ($x = $st->fetch()) {
            falhar("Não dá para reduzir para $novoCap: a turma \"{$x['turma']}\" ({$x['professor']}) tem {$x['tamanho_turma']} alunos e ainda está alocada nesta sala.", 409);
        }
    }

    $pdo->prepare('UPDATE salas SET nome=?, quantidade_cadeiras=?, possui_projetor=?, quantidade_computadores=?, possui_tv=? WHERE id=?')
        ->execute([$nome, $cad, booleano($data, 'possui_projetor'), $pcs, booleano($data, 'possui_tv'), $id]);
    registrar($pdo, 'sala_editada', "$nome (antes: {$sala['nome']}) · cadeiras $cad · computadores $pcs");
    responder(['success' => true]);
}

if ($action === 'liberar') {
    $id = inteiro($data, 'id');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT o.*, s.nome AS nome_sala FROM ocupacao o JOIN salas s ON s.id = o.sala_id WHERE o.id = ?');
        $st->execute([$id]);
        $reg = $st->fetch();
        if (!$reg) {
            $pdo->rollBack();
            falhar('Ocupação não encontrada (talvez já arquivada).', 404);
        }
        $pdo->prepare('INSERT INTO historico (sala_id, nome_sala, turma, professor, periodo, hora_inicio, hora_fim, tamanho_turma, data_inicio, data_fim) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$reg['sala_id'], $reg['nome_sala'], $reg['turma'], $reg['professor'], $reg['periodo'], $reg['hora_inicio'], $reg['hora_fim'], $reg['tamanho_turma'], $reg['data_inicio'], $reg['data_fim']]);
        $pdo->prepare('DELETE FROM ocupacao WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    registrar($pdo, 'aula_arquivada', resumoAula($reg, $reg['nome_sala']));
    responder(['success' => true]);
}

if ($action === 'mover') {
    $aulaId  = inteiro($data, 'aula_id');
    $destinoId = inteiro($data, 'destino_id');

    $st = $pdo->prepare('SELECT o.*, s.nome AS sala_origem FROM ocupacao o JOIN salas s ON s.id = o.sala_id WHERE o.id = ?');
    $st->execute([$aulaId]);
    $aula = $st->fetch();
    if (!$aula) {
        falhar('Aula não encontrada.', 404);
    }
    $destino = buscarSala($pdo, $destinoId);
    if (!$destino) {
        falhar('Sala de destino não encontrada.', 404);
    }
    if ((int)$aula['sala_id'] === $destinoId) {
        responder(['success' => true]);
    }

    exigirCapacidade($destino, $aula['tamanho_turma'] !== null ? (int)$aula['tamanho_turma'] : null);

    $conf = temConflito($pdo, $destinoId, faixaDaAula($aula), (string)$aula['periodo'], (string)$aula['data_inicio'], (string)$aula['data_fim'], $aulaId);
    if ($conf) {
        conflito409($pdo, $destinoId, $conf, 'A sala de destino já tem aula neste horário e nas mesmas datas.');
    }
    $pdo->prepare('UPDATE ocupacao SET sala_id = ? WHERE id = ?')->execute([$destinoId, $aulaId]);
    registrar($pdo, 'aula_movida', "{$aula['professor']} / {$aula['turma']} ({$aula['periodo']}): {$aula['sala_origem']} → {$destino['nome']}");
    responder(['success' => true]);
}

if ($action === 'criar_sala') {
    $nome  = txt($data, 'nome', 80);
    $bloco = txt($data, 'bloco', 80);
    if ($nome === '' || $bloco === '') {
        falhar('Informe o nome da sala e o bloco.');
    }
    $dup = $pdo->prepare('SELECT 1 FROM salas WHERE LOWER(nome) = LOWER(?) AND bloco = ?');
    $dup->execute([$nome, $bloco]);
    if ($dup->fetchColumn()) {
        falhar('Já existe uma sala com esse nome neste bloco.', 409);
    }
    if (stripos($nome, 'lab') !== false) {
        $tipo = 'Laboratório'; $cad = 0;  $proj = 1; $pcs = 20; $tv = 1;
    } else {
        $tipo = 'SalaAula';    $cad = 40; $proj = 1; $pcs = 0;  $tv = 0;
    }
    $pdo->prepare("INSERT INTO salas (nome, bloco, tipo, quantidade_cadeiras, possui_projetor, quantidade_computadores, possui_tv, problemas)
        VALUES (?,?,?,?,?,?,?,'[]')")->execute([$nome, $bloco, $tipo, $cad, $proj, $pcs, $tv]);
    registrar($pdo, 'sala_criada', "$nome ($bloco)");
    responder(['success' => true]);
}

/* ------------------------------------------------------------------ */
/* Backup e restauração                                                */
/* ------------------------------------------------------------------ */

if ($action === 'backup') {
    $dump = [
        'sistema'   => 'campus_xv',
        'versao'    => 2,
        'gerado_em' => date('c'),
        'tabelas'   => [
            'salas'     => $pdo->query('SELECT * FROM salas ORDER BY id')->fetchAll(),
            'ocupacao'  => $pdo->query('SELECT * FROM ocupacao ORDER BY id')->fetchAll(),
            'historico' => $pdo->query('SELECT * FROM historico ORDER BY id')->fetchAll(),
        ],
    ];
    registrar($pdo, 'backup_baixado', count($dump['tabelas']['salas']) . ' salas, ' . count($dump['tabelas']['ocupacao']) . ' reservas');
    $nome = 'backup-campus-xv-' . date('Ymd-His') . '.json';
    header('Content-Disposition: attachment; filename="' . $nome . '"');
    echo json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'restaurar') {
    $bk = $data['backup'] ?? null;
    if (!is_array($bk) || ($bk['sistema'] ?? '') !== 'campus_xv' || !isset($bk['tabelas']) || !is_array($bk['tabelas'])) {
        falhar('Arquivo inválido: não é um backup deste sistema.');
    }
    if (!in_array((int)($bk['versao'] ?? 0), [1, 2], true)) {
        falhar('Versão de backup não suportada.');
    }

    $esquema = [
        'salas'     => ['id', 'nome', 'bloco', 'tipo', 'quantidade_cadeiras', 'possui_projetor', 'quantidade_computadores', 'possui_tv', 'problemas', 'status_equipamento', 'problema_reportado_em'],
        'ocupacao'  => ['id', 'sala_id', 'turma', 'professor', 'contato', 'periodo', 'hora_inicio', 'hora_fim', 'tamanho_turma', 'data_inicio', 'data_fim'],
        'historico' => ['id', 'sala_id', 'nome_sala', 'turma', 'professor', 'periodo', 'hora_inicio', 'hora_fim', 'tamanho_turma', 'data_inicio', 'data_fim', 'data_registro'],
    ];
    $inteiros = ['id', 'sala_id', 'quantidade_cadeiras', 'possui_projetor', 'quantidade_computadores', 'possui_tv', 'tamanho_turma'];
    $podeSerNulo = ['id', 'sala_id', 'tamanho_turma'];

    foreach ($esquema as $tabela => $_) {
        if (!isset($bk['tabelas'][$tabela]) || !is_array($bk['tabelas'][$tabela])) {
            falhar("Backup incompleto: falta a tabela \"$tabela\".");
        }
        if (count($bk['tabelas'][$tabela]) > 100000) {
            falhar("Tabela \"$tabela\" grande demais.");
        }
    }
    if (count($bk['tabelas']['salas']) === 0) {
        falhar('O backup não contém nenhuma sala. Restauração cancelada para não apagar os dados atuais.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM ocupacao');
        $pdo->exec('DELETE FROM historico');
        $pdo->exec('DELETE FROM salas');

        $idsSalas = [];
        $contagem = [];
        foreach ($esquema as $tabela => $cols) {
            $ins = $pdo->prepare("INSERT INTO $tabela (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
            $n = 0;
            foreach ($bk['tabelas'][$tabela] as $i => $linha) {
                if (!is_array($linha)) {
                    throw new RuntimeException("Linha " . ($i + 1) . " da tabela \"$tabela\" é inválida.");
                }
                // Backup antigo (versão 1) não tinha horário estruturado: deduz do texto.
                if ($tabela !== 'salas' && empty($linha['hora_inicio']) && isset($linha['periodo'])) {
                    $f = faixaDoPeriodo((string)$linha['periodo']);
                    if ($f !== null) {
                        $linha['hora_inicio'] = minutosParaHora($f[0]);
                        $linha['hora_fim'] = minutosParaHora($f[1]);
                    }
                }
                $vals = [];
                foreach ($cols as $c) {
                    $v = $linha[$c] ?? null;
                    if ($c === 'problemas') {
                        if (is_array($v)) {
                            $v = json_encode(array_values(array_filter($v, 'is_string')), JSON_UNESCAPED_UNICODE);
                        } else {
                            $dec = is_string($v) ? json_decode($v, true) : null;
                            $v = is_array($dec) ? json_encode($dec, JSON_UNESCAPED_UNICODE) : '[]';
                        }
                    } elseif (in_array($c, $inteiros, true)) {
                        $v = is_numeric($v) ? (int)$v : null;
                        if ($v === null && !in_array($c, $podeSerNulo, true)) {
                            $v = 0;
                        }
                    } elseif ($c === 'hora_inicio' || $c === 'hora_fim') {
                        $v = (is_string($v) && horaParaMinutos($v) !== null) ? $v : null;
                    } else {
                        $v = is_scalar($v) ? mb_substr((string)$v, 0, 1000) : null;
                    }
                    $vals[] = $v;
                }
                $mapa = array_combine($cols, $vals);

                if ($tabela === 'salas') {
                    if (!$mapa['id'] || trim((string)$mapa['nome']) === '' || trim((string)$mapa['bloco']) === '') {
                        throw new RuntimeException('Há sala sem id, nome ou bloco no backup.');
                    }
                    if ($mapa['tipo'] === null || $mapa['tipo'] === '') {
                        $vals[array_search('tipo', $cols, true)] = 'SalaAula';
                    }
                    $idsSalas[$mapa['id']] = true;
                } elseif ($tabela === 'ocupacao' && !isset($idsSalas[$mapa['sala_id']])) {
                    throw new RuntimeException('Há aula que aponta para uma sala inexistente no backup.');
                }
                $ins->execute($vals);
                $n++;
            }
            $contagem[$tabela] = $n;
        }
        $pdo->commit();
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        falhar($e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    registrar($pdo, 'backup_restaurado', $contagem['salas'] . ' salas, ' . $contagem['ocupacao'] . ' reservas, ' . $contagem['historico'] . ' no histórico');
    responder(['success' => true, 'restaurado' => $contagem]);
}

/* ------------------------------------------------------------------ */
/* Usuários (administrador)                                            */
/* ------------------------------------------------------------------ */

function contarAdminsAtivos(PDO $pdo, int $excetoId = 0): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE papel = 'admin' AND ativo = 1 AND id != ?");
    $st->execute([$excetoId]);
    return (int)$st->fetchColumn();
}

if ($action === 'usuarios_listar') {
    responder($pdo->query('SELECT id, nome, usuario, papel, ativo, criado_em, ultimo_login FROM usuarios ORDER BY ativo DESC, nome')->fetchAll());
}

if ($action === 'usuario_criar') {
    $nome  = txt($data, 'nome', 80);
    $login = mb_strtolower(txt($data, 'usuario', 32));
    $senha = (string)($data['senha'] ?? '');
    $papel = txt($data, 'papel', 20);
    if ($nome === '' || !preg_match('/^[a-z0-9._-]{3,32}$/', $login)) {
        falhar('Informe o nome e um usuário de 3 a 32 caracteres (letras, números, ponto, hífen ou _).');
    }
    if (!in_array($papel, PAPEIS, true)) {
        falhar('Nível de acesso inválido.');
    }
    if (!senhaValida($senha)) {
        falhar('A senha deve ter de 8 a 72 caracteres.');
    }
    $dup = $pdo->prepare('SELECT 1 FROM usuarios WHERE usuario = ?');
    $dup->execute([$login]);
    if ($dup->fetchColumn()) {
        falhar('Já existe um usuário com esse login.', 409);
    }
    $pdo->prepare('INSERT INTO usuarios (nome, usuario, senha_hash, papel, criado_em) VALUES (?,?,?,?,?)')
        ->execute([$nome, $login, password_hash($senha, PASSWORD_DEFAULT), $papel, date('Y-m-d H:i:s')]);
    registrar($pdo, 'usuario_criado', "$login ($papel)");
    responder(['success' => true]);
}

if ($action === 'usuario_editar') {
    $id = inteiro($data, 'id');
    $st = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    $alvo = $st->fetch();
    if (!$alvo) {
        falhar('Usuário não encontrado.', 404);
    }
    $nome  = array_key_exists('nome', $data) ? txt($data, 'nome', 80) : $alvo['nome'];
    $papel = array_key_exists('papel', $data) ? txt($data, 'papel', 20) : $alvo['papel'];
    $ativo = array_key_exists('ativo', $data) ? booleano($data, 'ativo') : (int)$alvo['ativo'];
    if ($nome === '' || !in_array($papel, PAPEIS, true)) {
        falhar('Dados inválidos.');
    }
    if ($id === (int)$usuario['id'] && ($papel !== $alvo['papel'] || $ativo === 0)) {
        falhar('Você não pode alterar o seu próprio nível de acesso nem se desativar.', 409);
    }
    $deixaDeSerAdminAtivo = $alvo['papel'] === 'admin' && (int)$alvo['ativo'] === 1 && ($papel !== 'admin' || $ativo === 0);
    if ($deixaDeSerAdminAtivo && contarAdminsAtivos($pdo, $id) < 1) {
        falhar('Precisa existir pelo menos um administrador ativo.', 409);
    }
    $pdo->prepare('UPDATE usuarios SET nome = ?, papel = ?, ativo = ? WHERE id = ?')->execute([$nome, $papel, $ativo, $id]);
    registrar($pdo, 'usuario_editado', "{$alvo['usuario']}: papel {$alvo['papel']}→$papel, ativo " . (int)$alvo['ativo'] . "→$ativo");
    responder(['success' => true]);
}

if ($action === 'usuario_senha') {
    $id = inteiro($data, 'id');
    $senha = (string)($data['senha'] ?? '');
    if (!senhaValida($senha)) {
        falhar('A senha deve ter de 8 a 72 caracteres.');
    }
    $st = $pdo->prepare('SELECT usuario FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    $login = $st->fetchColumn();
    if (!$login) {
        falhar('Usuário não encontrado.', 404);
    }
    $pdo->prepare('UPDATE usuarios SET senha_hash = ?, falhas = 0, bloqueado_ate = NULL WHERE id = ?')
        ->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
    registrar($pdo, 'senha_resetada', (string)$login);
    responder(['success' => true]);
}

if ($action === 'alterar_senha') {
    $atual = (string)($data['atual'] ?? '');
    $nova  = (string)($data['nova'] ?? '');
    $st = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE id = ?');
    $st->execute([$usuario['id']]);
    if (!password_verify($atual, (string)$st->fetchColumn())) {
        falhar('A senha atual está incorreta.', 403);
    }
    if (!senhaValida($nova)) {
        falhar('A nova senha deve ter de 8 a 72 caracteres.');
    }
    $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $usuario['id']]);
    registrar($pdo, 'senha_alterada', '');
    responder(['success' => true]);
}

if ($action === 'logs') {
    $limite = max(1, min(200, (int)($_GET['limite'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $where = [];
    $args = [];
    $u = trim((string)($_GET['usuario'] ?? ''));
    $a = trim((string)($_GET['acao'] ?? ''));
    if ($u !== '') { $where[] = 'usuario_nome LIKE ?'; $args[] = '%' . $u . '%'; }
    if ($a !== '') { $where[] = 'acao = ?'; $args[] = $a; }
    $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $total = $pdo->prepare("SELECT COUNT(*) FROM logs$sql");
    $total->execute($args);
    $st = $pdo->prepare("SELECT * FROM logs$sql ORDER BY id DESC LIMIT $limite OFFSET $offset");
    $st->execute($args);
    responder(['total' => (int)$total->fetchColumn(), 'itens' => $st->fetchAll()]);
}