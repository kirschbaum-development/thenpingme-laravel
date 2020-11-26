<?php

namespace Thenpingme;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

class ThenpingmePingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $url;

    public $headers = [];

    public $payload = [];

    public $queue;

    public $tries = 1;

    public function handle()
    {
        try {
            Http::timeout(5)
                ->retry(3, 250)
                ->withHeaders($this->headers)
                ->acceptJson()
                ->asJson()
                ->post($this->url, $this->payload);
        } catch (Throwable $e) {
            logger('Could not reach '.parse_url($this->url, PHP_URL_HOST), [
                'status' => $e->response->status() ?? null,
                'response' => $e->response->json('message') ?? null,
            ]);
        }
    }
}
