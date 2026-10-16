<?php

// Set the content type to JSON
header('Content-Type: application/json');

// ==========================================
// CONFIGURATION (Update these per API)
// ==========================================
$dataFile   = 'employees.txt'; // Change per API (e.g., 'employees.txt', 'customer_data.txt')
$primaryKey = 'employee_id';   // Change per API (e.g., 'employee_id', 'cardNumber')

// Check the HTTP method (GET or POST)
$method = $_SERVER['REQUEST_METHOD'];
$response = array();

if ($method === 'GET') {
    // Handle GET request using the configured primary key
    $keyValue = $_GET[$primaryKey] ?? null;
    
    if ($keyValue) {
        $record = getDataByKey($dataFile, $primaryKey, $keyValue);
        if ($record) {
            $response = $record;
        } else {
            $response['error'] = ucfirst($primaryKey) . ' not found';
        }
    } else {
        $response['error'] = "Parameter '{$primaryKey}' is missing in the request.";
    }
} elseif ($method === 'POST') {
    // Handle POST request
    $data = json_decode(file_get_contents('php://input'), true);
    
    if ($data && isset($data[$primaryKey])) {
        $savedData = storeData($dataFile, $data);
        if ($savedData) {
            $response = $savedData;
        } else {
            $response['error'] = 'Failed to store data.';
        }
    } else {
        $response['error'] = "Invalid JSON data or '{$primaryKey}' parameter is missing.";
    }
} else {
    // Handle other HTTP methods
    $response['error'] = 'Unsupported HTTP method.';
}

// Encode the response as JSON and send it
echo json_encode($response);


// ==========================================
// REUSABLE HELPER FUNCTIONS
// ==========================================

/**
 * Fetch record information based on the dynamic primary key from a text file
 */
function getDataByKey($file, $primaryKey, $keyValue) {
    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if ($record && isset($record[$primaryKey]) && $record[$primaryKey] === $keyValue) {
                return $record;
            }
        }
    }
    return null;
}

/**
 * Store or append record information in a text file
 */
function storeData($file, $data) {
    $recordData = json_encode($data) . PHP_EOL;

    if (file_put_contents($file, $recordData, FILE_APPEND | LOCK_EX) !== false) {
        return $data;
    } else {
        return null;
    }
}