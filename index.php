<?php

declare(strict_types=1);

/**
 * Espelho do migrar_cd_export — publicado em /api/cd_export/
 * para não sobrescrever o document root. Usa libs do agent em produção.
 *
 * Auth: X-Api-Key = CURSOR_WORKSPACE_API_KEY
 * ?action=meta | lotes | composicao | produtos
 */

require_once dirname(__DIR__) . '/agent/lib/agent_consultas_api_lib.php';

$conn = agent_ws_bootstrap();
$input = agent_ws_read_input();
$action = trim((string) ($input['action'] ?? $_GET['action'] ?? 'meta'));

try {
    $out = match ($action) {
        'meta' => migrar_cd_meta($conn),
        'lotes' => migrar_cd_lotes($conn, $input),
        'composicao' => migrar_cd_composicao($conn),
        'produtos' => migrar_cd_produtos($conn),
        default => throw new InvalidArgumentException('action inválida (meta|lotes|composicao|produtos)'),
    };
    app_api_json_response(200, $out);
} catch (InvalidArgumentException $e) {
    app_api_json_error(400, $e->getMessage());
} catch (Throwable $e) {
    app_api_json_error(500, $e->getMessage());
}

function migrar_cd_tabela(mysqli $conn, string $t): bool
{
    $t = $conn->real_escape_string($t);
    $r = $conn->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
}

function migrar_cd_meta(mysqli $conn): array
{
    $counts = [
        'lotes' => 0,
        'produtos_cd' => 0,
        'produtos_cd_composicao' => 0,
        'estoque_cd' => 0,
    ];
    if (migrar_cd_tabela($conn, 'lotes')) {
        $r = $conn->query('SELECT COUNT(*) c FROM lotes');
        $counts['lotes'] = $r ? (int) ($r->fetch_assoc()['c'] ?? 0) : 0;
    }
    if (migrar_cd_tabela($conn, 'produtos_cd')) {
        $r = $conn->query('SELECT COUNT(*) c FROM produtos_cd');
        $counts['produtos_cd'] = $r ? (int) ($r->fetch_assoc()['c'] ?? 0) : 0;
    }
    if (migrar_cd_tabela($conn, 'produtos_cd_composicao')) {
        $r = $conn->query('SELECT COUNT(*) c FROM produtos_cd_composicao');
        $counts['produtos_cd_composicao'] = $r ? (int) ($r->fetch_assoc()['c'] ?? 0) : 0;
    }
    if (migrar_cd_tabela($conn, 'estoque_cd')) {
        $r = $conn->query('SELECT COUNT(*) c FROM estoque_cd');
        $counts['estoque_cd'] = $r ? (int) ($r->fetch_assoc()['c'] ?? 0) : 0;
    }
    return ['ok' => true, 'counts' => $counts];
}

function migrar_cd_lotes(mysqli $conn, array $input): array
{
    if (!migrar_cd_tabela($conn, 'lotes')) {
        return ['ok' => true, 'lotes' => [], 'aviso' => 'tabela lotes ausente'];
    }
    $limite = max(1, min(5000, (int) ($input['limite'] ?? $_GET['limite'] ?? 2000)));
    $sql = "SELECT l.id, l.produto_id, COALESCE(l.nome_produto, p.nome) AS produto,
                   l.lote_codigo, l.kg, l.qtd_etiquetas, l.turno,
                   l.data_fabricacao, l.data_validade, l.responsavel_id
            FROM lotes l
            LEFT JOIN produtos p ON p.id = l.produto_id
            ORDER BY l.id DESC
            LIMIT {$limite}";
    $r = $conn->query($sql);
    $lotes = [];
    while ($r && ($row = $r->fetch_assoc())) {
        $lotes[] = [
            'rederwp_id' => (int) $row['id'],
            'produto' => (string) ($row['produto'] ?? ''),
            'lote' => (string) ($row['lote_codigo'] ?? ''),
            'codigo' => (string) ($row['lote_codigo'] ?? ''),
            'kg' => $row['kg'] !== null ? (float) $row['kg'] : null,
            'qtd_etiquetas' => $row['qtd_etiquetas'] !== null ? (int) $row['qtd_etiquetas'] : null,
            'turno' => $row['turno'] !== null ? (string) $row['turno'] : null,
            'fabricacao' => $row['data_fabricacao'] ? substr((string) $row['data_fabricacao'], 0, 10) : null,
            'validade' => $row['data_validade'] ? substr((string) $row['data_validade'], 0, 10) : null,
        ];
    }
    return ['ok' => true, 'lotes' => $lotes, 'total' => count($lotes)];
}

function migrar_cd_produtos(mysqli $conn): array
{
    if (!migrar_cd_tabela($conn, 'produtos_cd')) {
        return ['ok' => true, 'produtos' => [], 'aviso' => 'tabela produtos_cd ausente'];
    }
    $r = $conn->query(
        'SELECT id, nome, unidade, categoria, ativo, izzyway_item_id, preco_venda
         FROM produtos_cd ORDER BY id ASC LIMIT 5000'
    );
    $produtos = [];
    while ($r && ($row = $r->fetch_assoc())) {
        $produtos[] = [
            'rederwp_id' => (int) $row['id'],
            'nome' => (string) $row['nome'],
            'unidade' => (string) ($row['unidade'] ?? 'un'),
            'categoria' => (string) ($row['categoria'] ?? ''),
            'ativo' => (int) ($row['ativo'] ?? 1) === 1,
            'izzyway_item_id' => $row['izzyway_item_id'] !== null ? (int) $row['izzyway_item_id'] : null,
            'preco' => $row['preco_venda'] !== null ? (float) $row['preco_venda'] : null,
        ];
    }
    return ['ok' => true, 'produtos' => $produtos, 'total' => count($produtos)];
}

function migrar_cd_composicao(mysqli $conn): array
{
    if (!migrar_cd_tabela($conn, 'produtos_cd_composicao')) {
        return ['ok' => true, 'itens' => [], 'aviso' => 'tabela produtos_cd_composicao ausente'];
    }
    $r = $conn->query(
        'SELECT c.produto_cd_id, c.componente_produto_cd_id, c.quantidade, c.ordem,
                p.nome AS produto_nome, m.nome AS componente_nome, m.unidade AS componente_unidade
         FROM produtos_cd_composicao c
         JOIN produtos_cd p ON p.id = c.produto_cd_id
         JOIN produtos_cd m ON m.id = c.componente_produto_cd_id
         ORDER BY c.produto_cd_id, c.ordem, c.id
         LIMIT 10000'
    );
    $itens = [];
    while ($r && ($row = $r->fetch_assoc())) {
        $itens[] = [
            'produto_rederwp_id' => (int) $row['produto_cd_id'],
            'produto_nome' => (string) $row['produto_nome'],
            'componente_rederwp_id' => (int) $row['componente_produto_cd_id'],
            'componente_nome' => (string) $row['componente_nome'],
            'componente_unidade' => (string) ($row['componente_unidade'] ?? 'un'),
            'quantidade' => (float) $row['quantidade'],
            'ordem' => (int) $row['ordem'],
        ];
    }
    return ['ok' => true, 'itens' => $itens, 'total' => count($itens)];
}
