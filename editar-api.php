<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const CONFIG_PATH = __DIR__ . DIRECTORY_SEPARATOR . 'site-config.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond([
        'success' => true,
        'data' => load_current_data(),
        'files' => list_html_files(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $data = [
        'site_name' => trim((string) ($payload['site_name'] ?? '')),
        'company_name' => trim((string) ($payload['company_name'] ?? '')),
        'cnpj' => trim((string) ($payload['cnpj'] ?? '')),
        'email' => trim((string) ($payload['email'] ?? '')),
        'address' => trim((string) ($payload['address'] ?? '')),
    ];

    $errors = [];
    foreach ($data as $field => $value) {
        if ($value === '') {
            $errors[$field] = 'Campo obrigatorio.';
        }
    }

    if ($errors !== []) {
        respond([
            'success' => false,
            'message' => 'Preencha todos os campos antes de salvar.',
            'errors' => $errors,
        ], 422);
    }

    $current = load_current_data();
    $result = apply_changes($current, $data);

    file_put_contents(
        CONFIG_PATH,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    respond([
        'success' => true,
        'message' => 'Dados atualizados com sucesso.',
        'updated_files' => $result['updated_files'],
        'updated_count' => count($result['updated_files']),
        'data' => $data,
    ]);
}

respond([
    'success' => false,
    'message' => 'Metodo nao suportado.',
], 405);

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function load_current_data(): array
{
    $defaults = [
        'site_name' => 'Orientacao Auto',
        'company_name' => 'MADEIREIRA PRIMER LTDA',
        'cnpj' => '38.821.946/0001-91',
        'email' => 'contato@orientacaoauto.com',
        'address' => 'Quadra QI 2, Setor Industrial, Taguatinga, Brasilia/DF, CEP 72135-020',
    ];

    $detected = detect_from_index();
    $config = [];

    if (is_file(CONFIG_PATH)) {
        $configRaw = json_decode((string) file_get_contents(CONFIG_PATH), true);
        if (is_array($configRaw)) {
            $config = array_intersect_key($configRaw, $defaults);
        }
    }

    return array_merge($defaults, $detected, $config);
}

function detect_from_index(): array
{
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($file)) {
        return [];
    }

    $content = (string) file_get_contents($file);
    $data = [];

    if (preg_match('/class="oa-logo">([^<]+)</u', $content, $match)) {
        $data['site_name'] = trim($match[1]);
    }

    if (preg_match('/<li><strong>CNPJ:<\/strong>\s*([^<]+)<\/li>/u', $content, $match)) {
        $data['cnpj'] = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    if (preg_match('/<li><strong>E-mail:<\/strong>\s*<a href="mailto:([^"]+)"/u', $content, $match)) {
        $data['email'] = trim($match[1]);
    }

    if (preg_match('/<li><strong>Endere[cç]o:<\/strong>\s*([^<]+)<\/li>/u', $content, $match)) {
        $data['address'] = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    if (preg_match('/<p><strong>([^<]+)<\/strong><br>\s*CNPJ\s*([^<]+)<br>/u', $content, $match)) {
        $data['company_name'] = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $data['cnpj'] = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    return $data;
}

function list_html_files(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        if (strtolower($file->getExtension()) !== 'html') {
            continue;
        }

        if (strtolower($file->getFilename()) === 'editar.html') {
            continue;
        }

        $files[] = str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $file->getPathname());
    }

    sort($files);
    return $files;
}

function apply_changes(array $current, array $new): array
{
    $updatedFiles = [];
    $files = list_html_files();

    foreach ($files as $relativePath) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . $relativePath;
        $content = (string) file_get_contents($path);
        $updated = replace_content($content, $current, $new);

        if ($updated !== $content) {
            file_put_contents($path, $updated);
            $updatedFiles[] = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        }
    }

    return ['updated_files' => $updatedFiles];
}

function replace_content(string $content, array $current, array $new): string
{
    $siteName = html_escape($new['site_name']);
    $companyName = html_escape($new['company_name']);
    $cnpj = html_escape($new['cnpj']);
    $email = html_escape($new['email']);
    $address = html_escape($new['address']);

    $addressMeta = parse_address_metadata($new['address']);

    $emailAliases = build_aliases($current['email'], [
        'contato@orientacaoauto.com',
        'contato@orientacaoauto-ms.vercel.app',
    ]);

    foreach ($emailAliases as $alias) {
        $quoted = preg_quote($alias, '/');
        $content = preg_replace('/mailto:' . $quoted . '/u', 'mailto:' . $new['email'], $content) ?? $content;
        $content = preg_replace('/https:\/\/formsubmit\.co\/' . $quoted . '/u', 'https://formsubmit.co/' . $new['email'], $content) ?? $content;
    }

    $content = preg_replace(
        '/Quadra QI 2, Lotes 35, 37, 39, 45, 47 e 49\s*[—-]\s*Setor Industrial\s*<br>\s*Taguatinga,\s*Bras[íi]lia\/DF\s*[—-]\s*CEP\s*72135-020/u',
        $address,
        $content
    ) ?? $content;

    $content = preg_replace(
        '/R MONTE CARACOL 112\s*<br>\s*Mte Alegre,\s*Camboriu\/SC\s*[—-]\s*CEP\s*88348-583/u',
        $address,
        $content
    ) ?? $content;

    $content = preg_replace(
        '/<li><strong>E-mail:<\/strong>\s*<a href="mailto:[^"]+">[^<]+<\/a><\/li>/u',
        '<li><strong>E-mail:</strong> <a href="mailto:' . $new['email'] . '">' . $email . '</a></li>',
        $content
    ) ?? $content;

    $content = preg_replace(
        '/<p><strong>E-mail:<\/strong>\s*<a href="mailto:[^"]+">[^<]+<\/a><\/p>/u',
        '<p><strong>E-mail:</strong> <a href="mailto:' . $new['email'] . '">' . $email . '</a></p>',
        $content
    ) ?? $content;

    $content = preg_replace(
        '/<li><strong>Endere[cç]o:<\/strong>\s*[^<]+<\/li>/u',
        '<li><strong>Endereço:</strong> ' . $address . '</li>',
        $content
    ) ?? $content;

    $content = preg_replace(
        '/<p><strong>Endereco:<\/strong>\s*[^<]+<\/p>/u',
        '<p><strong>Endereco:</strong> ' . $address . '</p>',
        $content
    ) ?? $content;

    $content = preg_replace(
        '/<p><strong>[^<]+<\/strong><br>\s*CNPJ\s*[^<]+<br>\s*.*?<\/p>/u',
        '<p><strong>' . $companyName . '</strong><br>' . PHP_EOL .
        '      CNPJ ' . $cnpj . '<br>' . PHP_EOL .
        '      ' . $address . '</p>',
        $content,
        1
    ) ?? $content;

    if ($addressMeta !== null) {
        $content = preg_replace('/"streetAddress":\s*"[^"]*"/u', '"streetAddress": "' . json_escape($addressMeta['street']) . '"', $content) ?? $content;
        $content = preg_replace('/"addressLocality":\s*"[^"]*"/u', '"addressLocality": "' . json_escape($addressMeta['locality']) . '"', $content) ?? $content;
        $content = preg_replace('/"addressRegion":\s*"[^"]*"/u', '"addressRegion": "' . json_escape($addressMeta['region']) . '"', $content) ?? $content;
        $content = preg_replace('/"postalCode":\s*"[^"]*"/u', '"postalCode": "' . json_escape($addressMeta['postal_code']) . '"', $content) ?? $content;
    }

    $content = generic_replace($content, build_aliases($current['site_name'], [
        'Orientacao Auto',
        'Orientação Auto',
    ]), $siteName);

    $content = generic_replace($content, build_aliases($current['company_name'], [
        'MADEIREIRA CAMARGO LTDA',
        'MADEIREIRA PRIMER LTDA',
        '(MADEIREIRA CAMARGO LTDA)',
        '(MADEIREIRA PRIMER LTDA)',
        ' (MADEIREIRA CAMARGO LTDA)',
        ' (MADEIREIRA PRIMER LTDA)',
    ]), $companyName);

    $content = generic_replace($content, build_aliases($current['cnpj'], [
        '28.625.117/0001-80',
        '38.821.946/0001-91',
    ]), $cnpj);

    $content = generic_replace($content, $emailAliases, $email);

    $content = generic_replace($content, build_aliases($current['address'], [
        'Quadra QI 2, Setor Industrial, Taguatinga, Brasilia/DF, CEP 72135-020',
        'Quadra QI 2, Setor Industrial, Taguatinga, Brasília/DF, CEP 72135-020',
        'Quadra QI 2, Lotes 35, 37, 39, 45, 47 e 49 — Setor Industrial<br>Taguatinga, Brasília/DF — CEP 72135-020',
        'R MONTE CARACOL 112<br>Mte Alegre, Camboriu/SC — CEP 88348-583',
    ]), $address);

    $content = str_replace('<strong> ' . $companyName . '</strong>', '<strong>' . $companyName . '</strong>', $content);

    return $content;
}

function build_aliases(string $currentValue, array $knownValues): array
{
    $aliases = array_merge([$currentValue], $knownValues);
    $expanded = [];

    foreach ($aliases as $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }

        $expanded[] = $value;
        $ascii = remove_accents($value);
        if ($ascii !== $value) {
            $expanded[] = $ascii;
        }
    }

    $expanded = array_values(array_unique($expanded));
    usort($expanded, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    return $expanded;
}

function generic_replace(string $content, array $aliases, string $replacement): string
{
    foreach ($aliases as $alias) {
        $content = str_replace($alias, $replacement, $content);
    }

    return $content;
}

function html_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_escape(string $value): string
{
    return addcslashes($value, "\\\"\n\r\t/");
}

function parse_address_metadata(string $address): ?array
{
    if (!preg_match('/^(.*),\s*([^,\/]+)\/([A-Z]{2}),\s*CEP\s*([0-9.\-]+)$/u', $address, $match)) {
        return null;
    }

    return [
        'street' => trim($match[1]),
        'locality' => trim($match[2]),
        'region' => trim($match[3]),
        'postal_code' => trim($match[4]),
    ];
}

function remove_accents(string $value): string
{
    $map = [
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ç' => 'C', 'ç' => 'c',
    ];

    return strtr($value, $map);
}
