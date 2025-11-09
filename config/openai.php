<?php
return [
    // Leave empty to use the OPENAI_API_KEY environment variable
    'api_key' => getenv('OPENAI_API_KEY') ?: '',
    // Pick a current model name you’re allowed to use in your account (example placeholders below).
    // If you have "gpt-4.1-mini" or "o4-mini" etc., use that here.
    'model'   => 'gpt-4.1-mini',
    'endpoint'=> 'https://api.openai.com/v1/responses',
    'timeout' => 20,
];
