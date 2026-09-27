<?php

namespace App\Console\Commands;

use App\Services\Messaging\ScheduledMessageDispatcher;
use Illuminate\Console\Command;

class DispatchScheduledMessagesCommand extends Command
{
    protected $signature = 'messaging:dispatch';

    protected $description = 'Send planned WhatsApp messages that are due (reminders, messages held back by quiet hours)';

    public function handle(ScheduledMessageDispatcher $dispatcher): int
    {
        $counts = $dispatcher->dispatchDue();
        $this->info('Released: '.json_encode($counts ?: new \stdClass));

        return self::SUCCESS;
    }
}
