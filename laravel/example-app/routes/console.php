<?php

use App\Services\VisitorAutoReplyService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'nchat:auto-reply {conversationId} {visitorMessageId} {--origin=} {--referer=}',
    function (VisitorAutoReplyService $autoReply) {
        $autoReply->deliverAutoReply(
            (int) $this->argument('conversationId'),
            (int) $this->argument('visitorMessageId'),
            $this->option('origin') ?: null,
            $this->option('referer') ?: null
        );
    }
)->purpose('Generate and store the AI auto-reply for a visitor message.');
