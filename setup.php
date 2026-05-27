<?php
/**
 * setup.php — Testa a conexão, salva config e cria as tabelas
 * Chamado pelo painel Admin → Configurações MySQL
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function responder($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$acao = $body['acao'] ?? '';
$cfg  = $body['config'] ?? [];

// ── Validar campos obrigatórios ─────────────────────────────────────
if (in_array($acao, ['testar', 'salvar'])) {
    foreach (['host', 'banco', 'usuario'] as $campo) {
        if (empty($cfg[$campo])) {
            responder(['ok' => false, 'msg' => "Campo '$campo' é obrigatório."]);
        }
    }
    $cfg['porta'] = $cfg['porta'] ?: '3306';
}

// ── Testar conexão ─────────────────────────────────────────────────
if ($acao === 'testar') {
    try {
        new PDO(
            "mysql:host={$cfg['host']};port={$cfg['porta']};dbname={$cfg['banco']};charset=utf8mb4",
            $cfg['usuario'],
            $cfg['senha'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        responder(['ok' => true, 'msg' => '✅ Conexão bem-sucedida!']);
    } catch (PDOException $e) {
        responder(['ok' => false, 'msg' => '❌ ' . $e->getMessage()]);
    }
}

// ── Salvar config + criar tabelas ──────────────────────────────────
if ($acao === 'salvar') {
    // 1. Testar antes de salvar
    try {
        $pdo = new PDO(
            "mysql:host={$cfg['host']};port={$cfg['porta']};dbname={$cfg['banco']};charset=utf8mb4",
            $cfg['usuario'],
            $cfg['senha'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        responder(['ok' => false, 'msg' => 'Falha na conexão: ' . $e->getMessage()]);
    }

    // 2. Gravar db_config.json
    $salvo = file_put_contents(
        __DIR__ . '/db_config.json',
        json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    if ($salvo === false) {
        responder(['ok' => false, 'msg' => 'Não foi possível gravar db_config.json. Verifique permissões da pasta.']);
    }

    // 3. Criar tabelas (se não existirem)
    $tabelasGenericas = [
        'ordens', 'orcamentos', 'estoque', 'caixa',
        'agendamentos', 'clientes', 'usuarios', 'maodeobra',
    ];

    try {
        foreach ($tabelasGenericas as $t) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `$t` (
                    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    data        LONGTEXT     NOT NULL,
                    criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em DATETIME   NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }

        // Tabela de configurações (slug único)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `config_dados` (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug        VARCHAR(100) NOT NULL,
                data        LONGTEXT     NOT NULL,
                criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME   NULL,
                UNIQUE KEY uk_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $criadas = count($tabelasGenericas) + 1;
        responder([
            'ok'  => true,
            'msg' => "✅ Configuração salva e $criadas tabelas criadas/verificadas com sucesso!",
        ]);
    } catch (PDOException $e) {
        responder(['ok' => false, 'msg' => 'Erro ao criar tabelas: ' . $e->getMessage()]);
    }
}

responder(['ok' => false, 'msg' => 'Ação inválida.'], 400);
