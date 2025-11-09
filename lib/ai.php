<?php
declare(strict_types=1);

final class OpenAIClient {
    private string $apiKey;
    private string $endpoint;
    private string $model;
    private int $timeout;

    public function __construct(array $cfg) {
        $this->apiKey   = $cfg['api_key'] ?: getenv('OPENAI_API_KEY') ?: '';
        if ($this->apiKey === '') {
            throw new RuntimeException('OpenAI API key missing. Set OPENAI_API_KEY or config/openai.php.');
        }
        $this->endpoint = $cfg['endpoint'] ?? 'https://api.openai.com/v1/responses';
        $this->model    = $cfg['model'] ?? 'gpt-4.1-mini';
        $this->timeout  = (int)($cfg['timeout'] ?? 20);
    }

    public function extractOrt(array $samples): array {
        $schema = [
            'type' => 'object',
            'properties' => [
                // NEW: one-line text for the Motiv/Ort field in the document
                'motiv' => ['type' => 'string'],

                // Existing structured fields
                'address' => ['type'=>'string'],
                'postal_code' => ['type'=>'string'],
                'city' => ['type'=>'string'],
                'country' => ['type'=>'string'],
                'contacts' => [
                    'type'=>'array',
                    'items'=> [
                        'type'=>'object',
                        'properties'=>[
                            'name'  => ['type'=>'string'],
                            'email' => ['type'=>'string'],
                            'phone' => ['type'=>'string']
                        ],
                        'required'=>[]
                    ]
                ],
                'phones' => ['type'=>'array','items'=>['type'=>'string']],
                'emails' => ['type'=>'array','items'=>['type'=>'string']]
            ],
            'required' => []
        ];

        $raw = (string)($samples['text'] ?? '');

        $system = 'Du extrahierst aus deutschsprachigem Freitext strukturierte Kontakt- und Ortsdaten '
                . 'für eine Dispositionsdatei. Liefere AUSSCHLIESSLICH ein einzelnes JSON-Objekt, '
                . 'das dem Schema entspricht. Das Feld "motiv" ist eine kurze, saubere, gut lesbare '
                . 'Orts-/Motiv-Zeile (max. 120 Zeichen, Deutsch), OHNE Telefonnummern, E-Mails, '
                . 'Personennamen oder Labels wie "Tel:", "Kontakt:".';

        $user = "Schema (JSON Schema):\n"
              . json_encode($schema, JSON_UNESCAPED_UNICODE)
              . "\n\nText:\n"
              . $raw;

        $body = [
            'model' => $this->model,
            'input' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature' => 0
        ];

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer '.$this->apiKey
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) throw new \RuntimeException('OpenAI request failed: '.curl_error($ch));
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) throw new \RuntimeException('OpenAI HTTP '.$code.': '.$resp);

        $json = json_decode($resp, true);

        // Extract model text
        $text = null;
        if (isset($json['output_text'])) {
            $text = $json['output_text'];
        } elseif (!empty($json['output'][0]['content'][0]['text'])) {
            $text = $json['output'][0]['content'][0]['text'];
        } elseif (!empty($json['choices'][0]['message']['content'])) {
            $text = $json['choices'][0]['message']['content'];
        } else {
            $text = is_string($resp) ? $resp : json_encode($json);
        }

        // Parse first JSON object
        $payload = self::firstJsonObject($text);
        return is_array($payload) ? $payload : [];
    }




    /** Extract the first top-level JSON object from arbitrary text safely. */
    private static function firstJsonObject(string $s): ?array {
        $start = strpos($s, '{');
        while ($start !== false) {
            $depth = 0;
            for ($i = $start, $len = strlen($s); $i < $len; $i++) {
                $ch = $s[$i];
                if ($ch === '{') $depth++;
                elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $candidate = substr($s, $start, $i - $start + 1);
                        $decoded = json_decode($candidate, true);
                        if (is_array($decoded)) return $decoded;
                        break;
                    }
                }
            }
            $start = strpos($s, '{', $start + 1);
        }
        return null;
    }
}
/** Convenience wrapper: parse the ${ort} text into structured fields. */
function parse_ort_to_fields(string $raw): array {
    $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252,ISO-8859-1,ISO-8859-15,latin1,UTF-8');

    $cfg = require dirname(__DIR__) . '/config/openai.php';
    $cli = new OpenAIClient($cfg);
    $out = $cli->extractOrt(['text' => $raw]);

    $address = trim(implode(' ', array_filter([
        $out['address'] ?? '',
        $out['postal_code'] ?? '',
        $out['city'] ?? '',
        $out['country'] ?? ''
    ])));

    $primary = ['name'=>'','email'=>'','phone'=>''];
    if (!empty($out['contacts'][0]) && is_array($out['contacts'][0])) {
        $c0 = $out['contacts'][0];
        $primary['name']  = trim((string)($c0['name']  ?? ''));
        $primary['email'] = trim((string)($c0['email'] ?? ''));
        $primary['phone'] = trim((string)($c0['phone'] ?? ''));
    } else {
        $primary['email'] = (string)(($out['emails'][0] ?? '') ?: '');
        $primary['phone'] = (string)(($out['phones'][0] ?? '') ?: '');
    }

    // NEW: motiv text (clean, short one-liner for ${ort})
    $motiv = trim((string)($out['motiv'] ?? ''));

    return [
        'addresse'               => $address,
        'Ansprechpartnerin'      => $primary['name'],
        'Ansprechpartnerin_mail' => $primary['email'],
        'Ansprechpartnerin_tel'  => $primary['phone'],
        'motiv'                  => $motiv,   // expose motiv for debugging/alternative mapping
        '_parsed_raw'            => $out
    ];
}

