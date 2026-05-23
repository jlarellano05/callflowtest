<?php

// Set the content type to JSON
header('Content-Type: application/json');

// Check the HTTP method (GET or POST)
$method = $_SERVER['REQUEST_METHOD'];

// Initialize the response array
$response = array();

if ($method === 'GET') {
    // Handle GET request
    $currencyCode = $_GET['currencyCode']; // Retrieve the currencyCode from the query parameters
    if ($currencyCode) {
        // Fetch the customer information based on currencyCode
        $customerData = getCustomerDataBycurrencyCode($currencyCode);
        if ($customerData) {
            $response = $customerData;
        } else {
            $response['error'] = 'currencyCode not found';
        }
    } else {
        $response['error'] = 'currencyCode parameter is miscurrencyCodeg in the request.';
    }
} elseif ($method === 'POST') {
    // Handle POST request
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data && isset($data['currencyCode'])) {
        $currencyCode = $data['currencyCode'];
        // Store or update customer information based on the currencyCode
        $customerData = storeCustomerData($data);
        if ($customerData) {
            $response = $customerData;
        } else {
            $response['error'] = 'Failed to store customer data.';
        }
    } else {
        $response['error'] = 'Invalid JSON data or currencyCode parameter is miscurrencyCodeg.';
    }
} else {
    // Handle other HTTP methods
    $response['error'] = 'Unsupported HTTP method.';
}

// Encode the response as JSON and send it
echo json_encode($response);

// Function to fetch customer information based on currencyCode from a text file
function getCustomerDataBycurrencyCode($currencyCode) {
    $file = 'customer_data.txt';

    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            $customer = json_decode($line, true);
            if ($customer && isset($customer['currencyCode']) && $customer['currencyCode'] === $currencyCode) {
                return $customer;
            }
        }
    }

    return null;
}

// Function to store or update customer information in a text file
function storeCustomerData($data) {
    $file = 'customer_data.txt';

    $customerData = json_encode($data) . PHP_EOL;

    if (file_put_contents($file, $customerData, FILE_APPEND | LOCK_EX) !== false) {
        return $data;
    } else {
        return null;
    }
}
