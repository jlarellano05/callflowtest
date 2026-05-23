<?php
declare(strict_types=1);

// customer_api.php
// Simple line-delimited JSON storage + API that returns a customer envelope
// matching the structure you provided. Supports GET (fetch by cardNumber)
// and POST (store a raw record that includes cardNumber).

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $cardNumber = $_GET['cardNumber'] ?? null;
        if (!$cardNumber) {
            http_response_code(400);
            echo json_encode(['error' => 'cardNumber parameter is missing in the request.']);
            exit;
        }

        $raw = getCustomerRawByCardNumber($cardNumber);
        if (!$raw) {
            http_response_code(404);
            echo json_encode(['error' => 'cardNumber not found']);
            exit;
        }

        $customer = buildCustomerFromRaw($raw);
        $response = buildEnvelope([$customer], 1, 25, '/v1/customer-segment');

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;

    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || !isset($data['cardNumber']) || $data['cardNumber'] === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data or cardNumber parameter is missing.']);
            exit;
        }

        if (!storeCustomerRaw($data)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to store customer data.']);
            exit;
        }

        $customer = buildCustomerFromRaw($data);
        $response = buildEnvelope([$customer], 1, 25, '/v1/customer-segment');

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Unsupported HTTP method.']);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'details' => $e->getMessage()]);
    exit;
}

/**
 * Build the final envelope matching your sample: customers/meta/links.
 */
function buildEnvelope(array $customers, int $pageNumber, int $pageSize, string $basePath): array
{
    $totalItems   = count($customers);
    $itemsPerPage = $pageSize;
    $totalPages   = (int)ceil(max(1, $totalItems) / $itemsPerPage);
    $currentPage  = max(1, min($pageNumber, $totalPages));

    // Keep encoded ampersand in links to match your sample
    $makeLink = function (int $p) use ($basePath, $itemsPerPage) {
        return sprintf('%s?pageNumber=%d&amp;pageSize=%d', $basePath, $p, $itemsPerPage);
    };

    return [
        'customers' => $customers,
        'meta' => [
            'totalItems'   => $totalItems,
            'itemCount'    => count($customers),
            'itemsPerPage' => $itemsPerPage,
            'totalPages'   => $totalPages,
            'currentPage'  => $currentPage,
        ],
        'links' => [
            'self'  => $makeLink($currentPage),
            'first' => $makeLink(1),
            'prev'  => $makeLink(max(1, $currentPage - 1)),
            'next'  => $makeLink(min($totalPages, $currentPage + 1)),
            'last'  => $makeLink($totalPages),
        ],
    ];
}

/**
 * Map a raw stored record into the exact customer object structure.
 */
function buildCustomerFromRaw(array $raw): array
{
    $name = [
        'genderCode' => $raw['name']['genderCode'] ?? ($raw['genderCode'] ?? null),
        'prefix'     => array_key_exists('prefix', $raw['name'] ?? []) ? (string)$raw['name']['prefix'] : ' ',
        'suffix'     => array_key_exists('suffix', $raw['name'] ?? []) ? (string)$raw['name']['suffix'] : ' ',
        'last'       => $raw['name']['last']   ?? ($raw['last']   ?? ''),
        'middle'     => $raw['name']['middle'] ?? ($raw['middle'] ?? ''),
        'first'      => $raw['name']['first']  ?? ($raw['first']  ?? ''),
        'title'      => $raw['name']['title']  ?? ($raw['title']  ?? ''),
    ];

    $accounts = [];
    if (!empty($raw['accounts']) && is_array($raw['accounts'])) {
        foreach ($raw['accounts'] as $a) {
            if (empty($a['accountNumber']) || empty($a['accountTypeDescription'])) continue;
            $accounts[] = [
                'accountTypeDescription' => strtoupper((string)$a['accountTypeDescription']),
                'accountNumber'          => (string)$a['accountNumber'],
            ];
        }
    }

    $segments = [];
    if (!empty($raw['customerSegments']) && is_array($raw['customerSegments'])) {
        foreach ($raw['customerSegments'] as $s) {
            $segments[] = [
                'segmentName' => (string)($s['segmentName'] ?? ''),
                'segmentCode' => isset($s['segmentCode']) ? (int)$s['segmentCode'] : 0,
            ];
        }
    }
    usort($segments, function ($x, $y) { return ($y['segmentCode'] ?? 0) <=> ($x['segmentCode'] ?? 0); });

    $highest = $segments[0] ?? ['segmentName' => null, 'segmentCode' => null];

    $cifs = [];
    if (!empty($raw['cifs']) && is_array($raw['cifs'])) {
        $cifs = array_values(array_unique(array_map('strval', $raw['cifs'])));
    } elseif (!empty($raw['sCifId'])) {
        $cifs = [ (string)$raw['sCifId'] ];
    }

    $birthDate = null;
    if (!empty($raw['birthDate'])) {
        $ts = strtotime((string)$raw['birthDate']);
        if ($ts !== false) $birthDate = date('Y-m-d', $ts);
    }

    return [
        'sCifId'                     => isset($raw['sCifId']) ? (string)$raw['sCifId'] : '',
        'mdmId'                      => isset($raw['mdmId']) ? (string)$raw['mdmId'] : null,
        'birthDate'                  => $birthDate,
        'highestCustomerSegmentName' => $highest['segmentName'],
        'highestCustomerSegmentCode' => $highest['segmentCode'],
        'customerSegments'           => $segments,
        'cifs'                       => $cifs,
        'name'                       => $name,
        'totalAccounts'              => count($accounts),
        'accounts'                   => $accounts,
    ];
}

/**
 * Lookup a raw record by cardNumber from the line-delimited JSON file.
 */
function getCustomerRawByCardNumber(string $cardNumber): ?array
{
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'customer_data.txt';
    if (!file_exists($file)) return null;

    $fh = fopen($file, 'r');
    if (!$fh) return null;

    try {
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') continue;

            $obj = json_decode($line, true);
            if (!is_array($obj)) continue;

            if (!empty($obj['cardNumber']) && (string)$obj['cardNumber'] === (string)$cardNumber) {
                fclose($fh);
                return $obj;
            }

            if (!empty($obj['accounts']) && is_array($obj['accounts'])) {
                foreach ($obj['accounts'] as $acc) {
                    if (!empty($acc['accountNumber']) && (string)$acc['accountNumber'] === (string)$cardNumber) {
                        fclose($fh);
                        return $obj;
                    }
                }
            }
        }
    } finally {
        if (is_resource($fh)) fclose($fh);
    }

    return null;
}

/**
 * Append a raw JSON line into the file (simple store).
 */
function storeCustomerRaw(array $data): bool
{
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'customer_data.txt';
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    return file_put_contents($file, $json . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
}
