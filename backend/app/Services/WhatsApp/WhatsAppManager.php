<?php

namespace App\Services\WhatsApp;

class WhatsAppManager
{
    private ?WhatsAppProvider $provider = null;

    public function provider(): WhatsAppProvider
    {
        return $this->provider ??= match (config('whatsapp.provider')) {
            'openwa' => new OpenWaProvider,
            'cloud' => new CloudApiProvider,
            default => new LogProvider,
        };
    }

    /** Test seam: swap the provider. */
    public function use(WhatsAppProvider $provider): void
    {
        $this->provider = $provider;
    }
}
