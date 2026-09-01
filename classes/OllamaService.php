<?php
/**
 * Ollama Service Client
 * Handles connection, query building, and context injection to local Ollama LLMs.
 */

class OllamaService {
    private $host;
    private $model;
    private $timeout;

    /**
     * Constructor
     * @param string $model The default Ollama model to use (e.g., 'deepseek-r1:1.5b', 'qwen2.5:1.5b', 'mistral')
     * @param string $host The local Ollama server address
     * @param int $timeout Connection timeout in seconds
     */
    public function __construct($model = 'deepseek-r1:1.5b', $host = 'http://localhost:11434', $timeout = 60) {
        $this->model = $model;
        // Strip trailing slash if present
        $this->host = rtrim($host, '/');
        $this->timeout = $timeout;
    }

    /**
     * Check if the Ollama service is running and accessible
     * @return bool
     */
    public function isAvailable() {
        $ch = curl_init($this->host . '/api/tags');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode === 200;
    }

    /**
     * Fetch all installed Ollama models
     * @return array List of model names
     */
    public function getLocalModels() {
        $ch = curl_init($this->host . '/api/tags');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return [];
        }

        $data = json_decode($response, true);
        $models = [];
        if (isset($data['models'])) {
            foreach ($data['models'] as $m) {
                $models[] = $m['name'];
            }
        }
        return $models;
    }

    /**
     * Send a generation prompt to Ollama (/api/generate)
     * @param string $prompt The core user prompt
     * @param string|null $systemPrompt Optional system instructions
     * @param array $options Additional hyperparameters like temperature, top_k
     * @return string The generated text
     */
    public function generate($prompt, $systemPrompt = null, $options = []) {
        $url = $this->host . '/api/generate';
        
        $payload = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false
        ];

        if ($systemPrompt !== null) {
            $payload['system'] = $systemPrompt;
        }

        if (!empty($options)) {
            $payload['options'] = $options;
        }

        $rawResponse = $this->sendPostRequest($url, $payload);
        $data = json_decode($rawResponse, true);
        return $data['response'] ?? $rawResponse;
    }

    /**
     * Send a conversation thread to Ollama (/api/chat)
     * @param array $messages Thread in shape [['role' => 'user/assistant/system', 'content' => 'msg']]
     * @param array $options Hyperparameters
     * @return array Response array containing the message
     */
    public function chat($messages, $options = []) {
        $url = $this->host . '/api/chat';
        
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false
        ];

        if (!empty($options)) {
            $payload['options'] = $options;
        }

        $rawResponse = $this->sendPostRequest($url, $payload);
        return json_decode($rawResponse, true);
    }

    /**
     * Internal POST request runner using cURL
     */
    private function sendPostRequest($url, $payload) {
        $jsonData = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("Ollama HTTP Request Failed: " . $err);
        }

        if ($httpCode !== 200) {
            $errDetail = json_decode($response, true);
            $errMsg = $errDetail['error'] ?? 'Unknown Ollama API error';
            throw new Exception("Ollama API Error (HTTP $httpCode): " . $errMsg);
        }

        return $response;
    }
}
