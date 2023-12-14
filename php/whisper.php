<?php
require_once("../inc/includes.php");
include('key.php');
$response = [];  
try {
    header("Access-Control-Allow-Origin: *");

    if (!isset($_FILES['audio'])) {
        throw new Exception('Audio file not received.');
    }

    $audioFilePath = $_FILES['audio']['tmp_name'];

    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($audioFilePath, 'audio/webm', 'audio.webm'),
            'model' => 'whisper-1'
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$API_KEY 
        ]
    ];

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, $curlOptions);

    $curlResponse = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception('Erro in cURL: ' . curl_error($ch));
    }

    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch); 

    if ($httpcode !== 200) {
        $apiError = json_decode($curlResponse, true);
        throw new Exception('Error Code:' . $httpcode . ' - ' . $apiError['error']['message']);
    }

    $responseData = json_decode($curlResponse, true);
    if ($responseData && isset($responseData['text'])) {
        $response['status'] = 'success';
        $response['message'] = $responseData['text'];
    } else {
        throw new Exception('Error: ' . json_encode($responseData));
    }

} catch (Exception $e) {
    $response['status'] = 'error';
    $response['message'] = 'Error Exception: ' .  $e->getMessage();
}

echo json_encode($response);
?>