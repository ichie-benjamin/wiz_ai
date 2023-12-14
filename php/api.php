<?php
header('X-Accel-Buffering: no');
ini_set('output_buffering', 'off');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
require_once("../inc/includes.php");
include('key.php');

$config = $settings->get(1);
$allowed_origin = $base_url;
$total_characters = 0;

if ($config->demo_mode) {
    if ($isLogged) {
        $checkCustomer = $customers->getCustomerMessagesInfo($getCustomer->id);
        if ($checkCustomer->total_messages > 10) {
            echo 'data: {"error": "[DEMO_MODE]"}' . PHP_EOL;
            die();
        }
    }
}



function check_credits($isLogged, $userCredits, $config) {
    global $prompts;
    $checkEmbed = $prompts->get($_POST['ai_id']);

    if (!$isLogged) {
        if (isset($_SESSION['message_count']) && $_SESSION['message_count'] > $config->free_number_chats) {
            if (!$checkEmbed->allow_embed_chat) {
                if (!$config->free_mode) {
                    echo 'data: {"error": "[CHAT_LIMIT]"}' . PHP_EOL;
                    die();
                }
            }
        }
    } else {
        if ($userCredits <= 0) {
            if (!$checkEmbed->allow_embed_chat) {
                if (!$config->free_mode) {
                    echo 'data: {"error": "[NO_CREDIT]"}' . PHP_EOL;
                    die();
                }
            }
        }
    }
}

function remove_duplicate_messages($messages) {
    $temp_array = array();
    $unique_messages = array();

    foreach ($messages as $key => $message) {
        $role = $message['role'];
        $content = $message['content'];

        $keyString = $role . $content;

        if (!isset($temp_array[$keyString])) {
            $temp_array[$keyString] = true;
            $unique_messages[] = $message;
        }
    }

    return $unique_messages;
}

function createParams($isGPT, $ai_name, $chat_messages, $model, $temperature, $frequency_penalty, $presence_penalty) {
    global $config;
    return [
        "messages" => $chat_messages,
        "model" => $model,
        "temperature" => $temperature,
        "max_tokens" => (int)$config->max_tokens_gpt,
        "frequency_penalty" => $frequency_penalty,
        "presence_penalty" => $presence_penalty,
        "stream" => true
    ];
}



check_credits($isLogged, @$userCredits, $config);

$ai_id = $model = $ai_name = $ai_welcome_message = $ai_prompt = "";
$user_prompt = "";

if (isset($_POST['ai_id'])) {
    $AI = $prompts->get($_POST['ai_id']);
    $ai_id = $AI->id;
    $model = $AI->API_MODEL;
    $ai_name = $AI->name;
    $ai_welcome_message = $AI->welcome_message;
    $ai_prompt = $AI->prompt;
}

if (isset($_POST['prompt'])) {
    $user_prompt = $_POST['prompt'];
}

$temperature = (isset($AI->temperature) ? (int)$AI->temperature : 1);
$frequency_penalty = (isset($AI->frequency_penalty) ? (int)$AI->frequency_penalty : 0);
$presence_penalty = (isset($AI->presence_penalty) ? (int)$AI->presence_penalty : 0);
$chunk_buffer = "";

if ($user_prompt == "") {
    echo 'data: {"error": "[ERROR]","message":"Message field cannot be empty"}' . PHP_EOL;
    die();
}

if (!isset($_SESSION["history"][$ai_id])) {
    $_SESSION["history"][$ai_id] = [
        [
            "item_order" => 0,
            "id_message" => $id = md5(microtime()),
            "role" => "system",
            "content" => $ai_prompt,
            "datetime" => date("d/m/Y, H:i:s"),
            "saved" => false
        ]
    ];

    if (isset($ai_welcome_message) && !empty($ai_welcome_message)) {
        $_SESSION["history"][$ai_id][] = [
            "item_order" => 1,
            "id_message" => $id = md5(microtime()),
            "role" => "assistant",
            "content" => $ai_welcome_message,
            "name" => $ai_name,
            "datetime" => date("d/m/Y, H:i:s"),
            "saved" => false
        ];
    }
}


$next_item_order = count($_SESSION["history"][$ai_id]);
$_SESSION["history"][$ai_id][] = [
    "item_order" => $next_item_order,
    "id_message" => $id = md5(microtime()),
    "role" => "user",
    "content" => $user_prompt,
    "datetime" => date("d/m/Y, H:i:s"),
    "saved" => false
];



// Check if the image was sent and process the request
if (isset($_POST['image']) && $_POST['image']) {
    // Get the base64 string directly
    $base64Image = $_POST['image'];

    // Extract the image format from the base64 string
    if (preg_match('/^data:image\/(png|jpg|jpeg|gif);base64,/', $base64Image, $matches)) {
        $imageType = $matches[1];

        // Remove the prefix from the base64 string for decoding
        $filteredBase64Image = preg_replace('/^data:image\/(png|jpg|jpeg|gif);base64,/', '', $base64Image);
        $decodedImage = base64_decode($filteredBase64Image);

        // Set the image saving path
        $uploadDir = __DIR__ . '/../public_uploads/vision';
        $filename = uniqid() . '.' . $imageType;
        $filePath = $uploadDir . '/' . $filename;

        // Check if the directory exists
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Check if the file already exists and rename if necessary
        $counter = 1;
        while (file_exists($filePath)) {
            $filename = uniqid() . "_$counter." . $imageType;
            $filePath = $uploadDir . '/' . $filename;
            $counter++;
        }

        // Try to save the file
        if (file_put_contents($filePath, $decodedImage) === false) {
            echo json_encode(['error' => 'Failed to save the image.']);
        } else {
            if (isset($_POST['prompt'])) {
                // Call the processImage function with the original base64 string
                $response = processImage($base64Image, $_POST['prompt'], $API_KEY, $filename);
                if ($response) {
                    echo json_encode($response);
                }
            }
        }
    } else {
        echo json_encode(['error' => 'Unsupported image format.']);
    }
    return;
}

function processImage($base64Image, $prompt, $API_KEY,$filename) {
    global $ai_id, $ai_name, $config, $userCredits;

    // Define the API endpoint URL
    $url = 'https://api.openai.com/v1/chat/completions';

    // Prepare the data payload for the API request
    $payload = [
        "stream" => false,
        'model' => 'gpt-4-vision-preview',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $base64Image]]
                ]
            ]
        ],
        'max_tokens' => 300
    ];

    // Initialize cURL session
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $API_KEY
    ));
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

    // Execute the cURL session and close it
    $response = curl_exec($curl);
    curl_close($curl);

    // Process the response
    if ($response) {
        header('Content-Type: application/json');
        $responseArray = json_decode($response, true);

        // Handle API errors
        if (isset($responseArray['error'])) {
            echo json_encode([
                'error' => true,
                'message' => $responseArray['error']['message'],
                'isVisionResponse' => true,
                'visionImgPatch' => $filename
            ]);
            return;
        }

        // Handle valid responses
        if (is_array($responseArray)) {
            $responseArray['isVisionResponse'] = true;
            $responseArray['visionImgPatch'] = $filename;

            // Process and store the response content
            if (isset($responseArray['choices'][0]['message']['content'])) {
                $imageResponseContent = $responseArray['choices'][0]['message']['content'];
                $_SESSION["history"][$ai_id][] = [
                    "item_order" => count($_SESSION["history"][$ai_id]),
                    "id_message" => md5(microtime()),
                    "role" => "assistant",
                    "content" => $imageResponseContent,
                    "name" => $ai_name,
                    "datetime" => date("d/m/Y, H:i:s"),
                    "saved" => false,
                    "vision_img" => $filename
                ];

                // Deduct credits in non-free mode
                if (!$config->free_mode && $userCredits > 0) {
                    $customers = new Customers();
                    $customers->subtractCredits($_SESSION['id_customer'], $config->vision_spend_credits);
                }
            }

            // Output the response
            echo json_encode($responseArray);
        } else {
            // Handle decoding errors
            echo json_encode(['error' => 'Error decoding the response']);
        }
    } else {
        // Handle empty responses or cURL errors
        echo json_encode(['error' => 'Empty response or cURL error']);
    }
}


$chat_messages = $_SESSION["history"][$ai_id];
$chat_messages_head = [['role' => 'system', 'content' => $ai_prompt]];
$max_length = $AI->array_message_limit_length;
$chat_messages_tail = array_slice($chat_messages, -$AI->array_message_history, $AI->array_message_history);
$chat_messages = array_merge($chat_messages_head, $chat_messages_tail);

$chat_messages = remove_duplicate_messages($chat_messages);
$chat_messages = array_map(function ($message) use ($max_length) {
    if ($message["role"] == 'user' || $message["role"] == 'assistant') {
        return ["role" => $message["role"], "content" => mb_strimwidth($message["content"], 0, $max_length, '...')];
    }
    return $message;
}, $chat_messages);

$header = ["Authorization: Bearer " . $API_KEY, "Content-type: application/json"];
$isGPT = strpos($model, "gpt") !== false;
$url = $isGPT ? "https://api.openai.com/v1/chat/completions" : "https://api.openai.com/v1/engines/$model/completions";
$options = JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT;
$params = json_encode(createParams($isGPT, $ai_name, $chat_messages, $model, $temperature, $frequency_penalty, $presence_penalty), $options);

$chunk_buffer = '';
$curl = curl_init($url);
$options = [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $header,
    CURLOPT_POSTFIELDS => $params,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_WRITEFUNCTION => function ($curl, $data) use (&$chunk_buffer) {
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpCode != 200) {
            $r = json_decode($data);
            echo 'data: {"error": "[ERROR]","message":"' . $r->error->code . "  " . $r->error->message . '"}' . PHP_EOL;
        } else {
            $chunk_buffer .= $data;
            echo $data;
            ob_flush();
            flush();
            return strlen($data);
        }
    },
];

curl_setopt_array($curl, $options);
$response = curl_exec($curl);

if ($response === false) {
    echo 'data: {"error": "[ERROR]","message":"' . curl_error($curl) . '"}' . PHP_EOL;
} else {
    processResponse($response);
}

function processResponse($response) {
    global $isLogged, $config, $userCredits, $ai_id, $ai_name, $chunk_buffer;
    if ($isLogged) {
        $chunk_buffer = str_replace("data: [DONE]", "", $chunk_buffer);
        $lines = explode("\n", $chunk_buffer);
        $assistant_response = "";
        $total_characters = 0;

        foreach ($lines as $line) {
            if (!empty(trim($line))) {
                $response_data = json_decode(trim(substr($line, 5)), true);
                if (isset($response_data["choices"][0]["delta"]["content"])) {
                    $total_characters += mb_strlen($response_data["choices"][0]["delta"]["content"]);
                    $assistant_response .= $response_data["choices"][0]["delta"]["content"];
                } elseif (isset($response_data["choices"][0]["text"])) {
                    $total_characters += mb_strlen($response_data["choices"][0]["text"]);
                    $assistant_response .= $response_data["choices"][0]["text"];
                }
            }
        }

        $_SESSION["history"][$ai_id][] = [
            "item_order" => count($_SESSION["history"][$ai_id]),
            "id_message" => md5(microtime()),
            "role" => "assistant",
            "content" => $assistant_response,
            "name" => $ai_name,
            "datetime" => date("d/m/Y, H:i:s"),
            "total_characters" => $total_characters,
            "saved" => false
        ];

        if (!$config->free_mode && $userCredits > 0) {
            // Subtrair créditos do cliente
            $customers = new Customers();
            $customers->subtractCredits($_SESSION['id_customer'], $total_characters);
        }
    } else {
        if (isset($_SESSION['message_count'])) {
            $_SESSION['message_count']++;
        }
        unset($_SESSION["history"]);
    }
}
?>
