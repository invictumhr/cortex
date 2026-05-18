<?php

namespace Database\Seeders;

use App\Models\AiProvider;
use Illuminate\Database\Seeder;

class AiProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['slug' => 'anthropic', 'name' => 'Anthropic', 'url' => 'https://api.anthropic.com', 'env' => 'CORTEX_ANTHROPIC_API_KEY'],
            ['slug' => 'openai', 'name' => 'OpenAI', 'url' => 'https://api.openai.com', 'env' => 'CORTEX_OPENAI_API_KEY'],
            ['slug' => 'xai', 'name' => 'xAI', 'url' => 'https://api.x.ai', 'env' => 'CORTEX_XAI_API_KEY'],
            ['slug' => 'google', 'name' => 'Google', 'url' => 'https://generativelanguage.googleapis.com', 'env' => 'CORTEX_GOOGLE_API_KEY'],
            ['slug' => 'mistral', 'name' => 'Mistral', 'url' => 'https://api.mistral.ai', 'env' => 'CORTEX_MISTRAL_API_KEY'],
            ['slug' => 'deepseek', 'name' => 'DeepSeek', 'url' => 'https://api.deepseek.com', 'env' => 'CORTEX_DEEPSEEK_API_KEY'],
        ];

        foreach ($providers as $data) {
            $provider = AiProvider::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'api_base_url' => $data['url'],
                    'is_active' => true,
                ]
            );

            // Only overwrite the key when an env value is present, so a key
            // configured later via Filament is never wiped by a re-seed.
            $key = env($data['env']);
            if (filled($key)) {
                $provider->api_key = $key;
                $provider->save();
            }
        }
    }
}
