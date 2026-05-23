<?php
// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read the XML request from the input stream
    $xml_request = file_get_contents('php://input');

    // Check if the request is empty
    if (empty($xml_request)) {
        http_response_code(400);
        die("Bad Request - Empty XML");
    }

    // Parse the XML request
    $xml = simplexml_load_string($xml_request);

    // Check if the XML is valid
    if ($xml === false) {
        http_response_code(400);
        die("Bad Request - Invalid XML");
    }

    // Extract the ID from the XML
    $id = (int) $xml->id;

    // Check if the ID is valid
    if ($id <= 0) {
        http_response_code(400);
        die("Bad Request - Invalid ID");
    }

    // Read the text file and find the record with the matching ID
    $lines = file('data.txt');
    $record = null;

    foreach ($lines as $line) {
        $fields = explode('|', $line);
        if ($fields[0] == $id) {
            $record = $line;
            break;
        }
    }

    if ($record === null) {
        http_response_code(404);
        die("Record not found");
    }

    // Create the XML response
    $xml_response = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<response>' .
        '<record>' . htmlentities($record) . '</record>' .
        '</response>';

    // Set the response content type and send the response
    header('Content-Type: application/xml');
    echo $xml_response;
} else {
    // If the request method is not POST, return a 405 Method Not Allowed response
    http_response_code(405);
    die("Method Not Allowed");
}
?>
