<?php
/**
 * api.php — Backend REST para o Sistema de Oficina
 * Requer: PHP 7.4+ e MySQL 5.7+ (ou MariaDB 10.3+)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Helpers ────────────────────────────────────────────────────────
function responder($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function erro($msg, $status = 400) {
    responder(['error' => $msg], $status);
}

// ── Configuração ───────────────────────────────────────────────────
$configFile = __DIR__ . '/db_config.json';

if (!file_exists($configFile)) {
    erro('Banco de dados não configurado. Acesse o painel Admin para configurar.', 503);
}

$cfg = json_decode(file_get_contents($configFile), true);
if (!$cfg) erro('Arquivo de configuração inválido.', 503);

// ── Conexão MySQL ──────────────────────────────────────────────────
try {
    $porta = $cfg['porta'] ?? '3306';
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$porta};dbname={$cfg['banco']};charset=utf8mb4",
        $cfg['usuario'],
        $cfg['senha'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]
    );
} catch (PDOException $e) {
    erro('Falha na conexão com o banco: ' . $e->getMessage(), 500);
}

// ── Roteamento ─────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$col    = $_GET['col']  ?? '';
$id     = $_GET['id']   ?? '';
$slug   = $_GET['slug'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

$mapa = [
    'ordens'       => 'ordens',
    'orcamentos'   => 'orcamentos',
    'estoque'      => 'estoque',
    'caixa'        => 'caixa',
    'agendamentos' => 'agendamentos',
    'clientes'     => 'clientes',
    'usuarios'     => 'usuarios',
    'maodeobra'    => 'maodeobra',
    'config'       => 'config_dados',
];

if (!$col || !isset($mapa[$col])) {
    erro("Coleção '$col' não reconhecida.", 400);
}

$tabela = $mapa[$col];

// ── CRUD ────────────────────────────────────────────────────────────
switch ($method) {

    // ── GET ─────────────────────────────────────────────────────────
    case 'GET':
        // GET ?col=config&slug=oficina  →  carregarOficina()
        if ($slug && $tabela === 'config_dados') {
            $stmt = $pdo->prepare("SELECT * FROM config_dados WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
            $row = $stmt->fetch();
            if (!$row) { responder(null); }
            $d = json_decode($row['data'], true) ?? [];
            responder(array_merge(['id' => (string)$row['id']], $d));
        }

        // GET ?col=X&id=Y  →  registro único
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM `$tabela` WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) erro('Não encontrado', 404);
            $d = json_decode($row['data'], true) ?? [];
            responder(array_merge(['id' => (string)$row['id']], $d));
        }

        // GET ?col=X  →  lista completa
        $rows = $pdo->query("SELECT * FROM `$tabela` ORDER BY criado_em DESC")->fetchAll();
        $result = array_map(function ($r) {
            $d = json_decode($r['data'], true) ?? [];
            return array_merge(['id' => (string)$r['id']], $d);
        }, $rows);
        responder($result);
        break;

    // ── POST (adicionar) ────────────────────────────────────────────
    case 'POST':
        // config com slug especial
        if ($tabela === 'config_dados') {
            $slugVal = $body['_slug'] ?? 'default';
            unset($body['_slug']);
            $stmt = $pdo->prepare("SELECT id FROM config_dados WHERE slug = ? LIMIT 1");
            $stmt->execute([$slugVal]);
            $existId = $stmt->fetchColumn();
            if ($existId) {
                $pdo->prepare("UPDATE config_dados SET data = ?, atualizado_em = NOW() WHERE slug = ?")
                    ->execute([json_encode($body, JSON_UNESCAPED_UNICODE), $slugVal]);
                responder(array_merge(['id' => (string)$existId], $body));
            } else {
                $pdo->prepare("INSERT INTO config_dados (slug, data, criado_em) VALUES (?, ?, NOW())")
                    ->execute([$slugVal, json_encode($body, JSON_UNESCAPED_UNICODE)]);
                responder(array_merge(['id' => (string)$pdo->lastInsertId()], $body));
            }
        }

        $pdo->prepare("INSERT INTO `$tabela` (data, criado_em) VALUES (?, NOW())")
            ->execute([json_encode($body, JSON_UNESCAPED_UNICODE)]);
        responder(array_merge(['id' => (string)$pdo->lastInsertId()], $body));
        break;

    // ── PUT (atualizar / merge) ──────────────────────────────────────
    case 'PUT':
        // PUT ?col=config&slug=oficina  →  salvarOficina()
        if ($slug && $tabela === 'config_dados') {
            $stmt = $pdo->prepare("SELECT id, data FROM config_dados WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
            $row = $stmt->fetch();
            if ($row) {
                $merged = array_merge(json_decode($row['data'], true) ?? [], $body);
                $pdo->prepare("UPDATE config_dados SET data = ?, atualizado_em = NOW() WHERE slug = ?")
                    ->execute([json_encode($merged, JSON_UNESCAPED_UNICODE), $slug]);
            } else {
                $pdo->prepare("INSERT INTO config_dados (slug, data, criado_em) VALUES (?, ?, NOW())")
                    ->execute([$slug, json_encode($body, JSON_UNESCAPED_UNICODE)]);
            }
            responder(['ok' => true]);
        }

        if (!$id) erro('ID obrigatório para atualização.', 400);
        $stmt = $pdo->prepare("SELECT data FROM `$tabela` WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) erro('Registro não encontrado.', 404);
        $merged = array_merge(json_decode($row['data'], true) ?? [], $body);
        $pdo->prepare("UPDATE `$tabela` SET data = ?, atualizado_em = NOW() WHERE id = ?")
            ->execute([json_encode($merged, JSON_UNESCAPED_UNICODE), $id]);
        responder(['ok' => true]);
        break;

    // ── DELETE ──────────────────────────────────────────────────────
    case 'DELETE':
        if (!$id) erro('ID obrigatório para exclusão.', 400);
        $pdo->prepare("DELETE FROM `$tabela` WHERE id = ?")->execute([$id]);
        responder(['ok' => true]);
        break;

    default:
        erro('Método não permitido.', 405);
}
