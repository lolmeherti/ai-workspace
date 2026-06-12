<?php
namespace App\Services\Tools;

use App\Config;

class TodoistApiClient
{
    public function request(string $method, string $endpoint, ?array $data = null): array
    {
        $apiKey = Config::get('TODOIST_API_KEY');
        if (empty($apiKey)) {
            throw new \Exception("Todoist API Key is not configured in your .env file.");
        }

        $ch = curl_init("https://api.todoist.com/api/v1" . $endpoint);
        $headers = [
            "Authorization: Bearer {$apiKey}",
            "Content-Type: application/json"
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 204) {
            return ['status' => 'success'];
        }

        if ($httpCode >= 400) {
            $decodedResponse = json_decode($response, true);
            $errorMsg = is_array($decodedResponse) && isset($decodedResponse['message']) ? $decodedResponse['message'] : $response;
            throw new \Exception("Todoist API returned error {$httpCode}: " . $errorMsg);
        }

        return json_decode($response, true) ?: [];
    }
}
