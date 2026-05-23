<?php
declare(strict_types=1);

// Set the content type to JSON
header('Content-Type: application/json');

// Simple router based on method
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Entry point
try {
    if ($method === 'GET') {
        $cardNumber = $_GET['cardNumber'] ?? null;
        if (!$cardNumber) {
            http_response_code(400);
            echo json_encode(['error' => 'cardNumber parameter is missing in the request.']);
            exit;
        }

        // Find raw record(s) by cardNumber
        $raw = getCustomerRawByCardNumber($cardNumber);
        if (!$raw) {
            http_response_code(404);
            echo json_encode(['error' => 'cardNumber not found']);
            exit;
        }

        // Build aligned response
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

        // Persist (append or upsert, simple approach here = append)
        $stored = storeCustomerRaw($data);
        if (!$stored) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to store customer data.']);
            exit;
        }

        // Return aligned structure immediately using the just-posted data
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
 * =========================
 * ===== Helper funcs =====
 * =========================
 */

/**
 * Build the final envelope matching your sample: customers/meta/links.
 */
function buildEnvelope(array $customers, int $pageNumber, int $pageSize, string $basePath): array
{
    $totalItems   = count($customers);
    $itemsPerPage = $pageSize;
    $totalPages   = (int)ceil(max(1, $totalItems) / $itemsPerPage);
    $currentPage  = max(1, min($pageNumber, $totalPages));

    // Note: the sample uses &amp; encoding in link values
    $makeLink = function (int $p) use ($basePath, $itemsPerPage) {
        return sprintf('%s?pageNumber=%d&amp;pageSize=%d', $basePath, $p, $itemsPerPage);
    };

    return [
        'customers' => $customers,
        'meta' => [
            'totalItems'   => $totalItems,
            'itemCount'    => count($customers),   // because we’re returning a single page in this simple example
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
 * Maps a raw stored record into the exact customer object structure.
 * Adjust field mappings here if your stored schema differs.
 */
function buildCustomerFromRaw(array $raw): array
{
    // Normalize name fields and preserve single-space for prefix/suffix if empty (as per your sample)
    $name = [
        'genderCode' => $raw['name']['genderCode'] ?? ($raw['genderCode'] ?? null),
        'prefix'     => array_key_exists('prefix', $raw['name'] ?? []) ? (string)$raw['name']['prefix'] : ' ',
        'suffix'     => array_key_exists('suffix', $raw['name'] ?? []) ? (string)$raw['name']['suffix'] : ' ',
        'last'       => $raw['name']['last']   ?? ($raw['last']   ?? ''),
        'middle'     => $raw['name']['middle'] ?? ($raw['middle'] ?? ''),
        'first'      => $raw['name']['first']  ?? ($raw['first']  ?? ''),
        'title'      => $raw['name']['title']  ?? ($raw['title']  ?? ''),
    ];

    // Accounts: ensure list of { accountTypeDescription, accountNumber }
    $accounts = [];
    if (!empty($raw['accounts']) && is_array($raw['accounts'])) {
        foreach ($raw['accounts'] as $a) {
            if (empty($a['accountNumber']) || empty($a['accountTypeDescription'])) {
                continue;
            }
            $accounts[] = [
                'accountTypeDescription' => strtoupper((string)$a['accountTypeDescription']),
                'accountNumber'          => (string)$a['accountNumber'],
            ];
        }
    }

    // Customer segments: ensure list of { segmentName, segmentCode }, sort desc by code
    $segments = [];
    if (!empty($raw['customerSegments']) && is_array($raw['customerSegments'])) {
        foreach ($raw['customerSegments'] as $s) {
            $segments[] = [
                'segmentName' => (string)($s['segmentName'] ?? ''),
                'segmentCode' => isset($s['segmentCode']) ? (int)$s['segmentCode'] : 0,
            ];
        }
    }
    usort($segments, function ($x, $y) {
        return ($y['segmentCode'] ?? 0) <=> ($x['segmentCode'] ?? 0);
    });

    $highest = $segments[0] ?? ['segmentName' => null, 'segmentCode' => null];

    // CIFs list
    $cifs = [];
    if (!empty($raw['cifs']) && is_array($raw['cifs'])) {
        $cifs = array_values(array_unique(array_map('strval', $raw['cifs'])));
    } elseif (!empty($raw['sCifId'])) {
        $cifs = [ (string)$raw['sCifId'] ];
    }

    // Birthdate normalized to YYYY-MM-DD if present
    $birthDate = null;
    if (!empty($raw['birthDate'])) {
        $ts = strtotime((string)$raw['birthDate']);
        if ($ts !== false) {
            $birthDate = date('Y-m-d', $ts);
        }
    }

    // Build final customer object
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
 * Look up a single raw record by cardNumber from the line-delimited JSON file.
 * This assumes each line is a JSON representing a customer (or a record) that includes the cardNumber
 * either at the top level or inside accounts[].
 */
function getCustomerRawByCardNumber(string $cardNumber): ?array
{
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'customer_data.txt';
    if (!file_exists($file)) {
        return null;
    }

    $fh = fopen($file, 'r');
    if (!$fh) {
        return null;
    }

    try {
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') continue;

            $obj = json_decode($line, true);
            if (!is_array($obj)) continue;

            // Match if top-level cardNumber matches
            if (!empty($obj['cardNumber']) && (string)$obj['cardNumber'] === (string)$cardNumber) {
                fclose($fh);
                return $obj;
            }

            // Or if any account matches the cardNumber
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
 * For true update/upsert, you’d read all lines, replace the matching one, then rewrite the file.
 */
function storeCustomerRaw(array $data): bool
{
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'customer_data.txt';

    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    return file_put_contents($file, $json . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
}