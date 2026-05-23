<?php

// Set the content type to JSON
header('Content-Type: application/json');

// Check the HTTP method (GET or POST)
$method = $_SERVER['REQUEST_METHOD'];

// Initialize the response array
$response = array();

if ($method === 'GET') {
    // Handle GET request
    $cardNumber = $_GET['cardNumber']; // Retrieve the cardNumber from the query parameters
    if ($cardNumber) {
        // Fetch the customer information based on cardNumber
        $customerData = getCustomerDataBycardNumber($cardNumber);
        if ($customerData) {
            $response = $customerData;
        } else {
            $response['error'] = 'cardNumber not found';
        }
    } else {
        $response['error'] = 'cardNumber parameter is miscardNumberg in the request.';
    }
} elseif ($method === 'POST') {
    // Handle POST request
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data && isset($data['cardNumber'])) {
        $cardNumber = $data['cardNumber'];
        // Store or update customer information based on the cardNumber
        $customerData = storeCustomerData($data);
        if ($customerData) {
            $response = $customerData;
        } else {
            $response['error'] = 'Failed to store customer data.';
        }
    } else {
        $response['error'] = 'Invalid JSON data or cardNumber parameter is miscardNumberg.';
    }
} else {
    // Handle other HTTP methods
    $response['error'] = 'Unsupported HTTP method.';
}

// Encode the response as JSON and send it
echo json_encode($response);

// Function to fetch customer information based on cardNumber from a text file
function getCustomerDataBycardNumber($cardNumber) {
    $file = 'customer_data.txt';

    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            $customer = json_decode($line, true);
            if ($customer && isset($customer['cardNumber']) && $customer['cardNumber'] === $cardNumber) {
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
