<?php
declare(strict_types=1);

/** Минимальный клиент OpenAI Responses API со Structured Outputs. */
final class OpenAiClient
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private ?string $reasoningEffort = null,
        private string $baseUrl = 'https://api.openai.com/v1',
        private string $proxy = '',
    ) {
    }

    public static function fromConfig(): self
    {
        $key = (string)cfg('openai_api_key', '');
        if ($key === '' || $key === 'sk-...') {
            throw new RuntimeException('Не задан openai_api_key в config.php');
        }
        return new self(
            $key,
            (string)cfg('model', 'gpt-5.6-sol'),
            cfg('reasoning_effort') ?: null,
            rtrim((string)cfg('openai_base_url', 'https://api.openai.com/v1'), '/'),
            (string)cfg('proxy', ''),
        );
    }

    /**
     * @param string[] $imageDataUrls data:image/jpeg;base64,...
     * @return array{data: array, usage: ?array, model: string}
     */
    public function extract(string $instructions, string $userText, array $imageDataUrls, string $schemaName, array $schema): array
    {
        $content = [['type' => 'input_text', 'text' => $userText]];
        foreach ($imageDataUrls as $u) {
            $content[] = ['type' => 'input_image', 'image_url' => $u, 'detail' => 'high'];
        }
        $body = [
            'model' => $this->model,
            'instructions' => $instructions,
            'input' => [['role' => 'user', 'content' => $content]],
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => $schemaName,
                'strict' => true,
                'schema' => $schema,
            ]],
            'store' => false,
        ];
        if ($this->reasoningEffort) {
            $body['reasoning'] = ['effort' => $this->reasoningEffort];
        }

        $resp = $this->post('/responses', $body);

        if (($resp['status'] ?? '') === 'incomplete') {
            $why = $resp['incomplete_details']['reason'] ?? 'unknown';
            throw new RuntimeException("Модель не закончила ответ ($why). Попробуйте ещё раз.");
        }
        $text = null;
        foreach ($resp['output'] ?? [] as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'output_text') {
                    $text = ($text ?? '') . $c['text'];
                } elseif (($c['type'] ?? '') === 'refusal') {
                    throw new RuntimeException('Модель отказалась: ' . ($c['refusal'] ?? ''));
                }
            }
        }
        if ($text === null) {
            throw new RuntimeException('Пустой ответ модели');
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new RuntimeException('Ответ модели не JSON');
        }
        return ['data' => $data, 'usage' => $resp['usage'] ?? null, 'model' => $resp['model'] ?? $this->model];
    }

    private function post(string $path, array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $attempt = 0;
        while (true) {
            $attempt++;
            $ch = curl_init($this->baseUrl . $path);
            $opts = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 280,
            ];
            if ($this->proxy !== '') {
                $opts[CURLOPT_PROXY] = $this->proxy;
            }
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                if ($attempt < 3) {
                    sleep(2 * $attempt);
                    continue;
                }
                throw new RuntimeException('Нет связи с OpenAI: ' . $err);
            }
            $json = json_decode((string)$raw, true);
            if ($code >= 200 && $code < 300 && is_array($json)) {
                return $json;
            }
            if (in_array($code, [429, 500, 502, 503, 504], true) && $attempt < 4) {
                sleep(3 * $attempt);
                continue;
            }
            $msg = $json['error']['message'] ?? substr((string)$raw, 0, 300);
            if ($code === 403 && str_contains((string)$msg, 'country')) {
                $msg .= ' — сервер в неподдерживаемой стране, задайте proxy в config.php';
            }
            throw new RuntimeException("OpenAI HTTP $code: $msg");
        }
    }
}
