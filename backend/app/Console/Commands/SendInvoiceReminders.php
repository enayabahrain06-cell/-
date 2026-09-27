<?php

namespace App\Console\Commands;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\InvoiceStatus;
use App\Enums\MessageType;
use App\Models\Alert;
use App\Models\Invoice;
use App\Services\Messaging\MessageService;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Unpaid-invoice reminders: N days before the due date and M days after (settings
 * reminders.invoice_days_before / invoice_days_after). Overdue invoices raise a dashboard alert.
 * Scheduled daily (see routes/console.php).
 */
class SendInvoiceReminders extends Command
{
    protected $signature = 'invoices:send-reminders';

    protected $description = 'Send WhatsApp reminders for unpaid invoices before and after the due date';

    public function handle(MessageService $messages): int
    {
        // Compare calendar dates in the authority's timezone, independent of the UTC storage offset.
        $today = \Carbon\Carbon::parse(now()->setTimezone(config('ahl.display_timezone'))->toDateString());
        $before = (int) setting('reminders.invoice_days_before', 3);
        $after = (int) setting('reminders.invoice_days_after', 7);

        $open = Invoice::with('student')->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::Partial->value])->get();
        $sent = 0;

        foreach ($open as $invoice) {
            $student = $invoice->student;
            if (! $student) {
                continue;
            }
            $due = \Carbon\Carbon::parse($invoice->due_date->toDateString());
            $locale = $student->locale?->value ?? 'ar';
            $vars = [
                'invoice_no' => $invoice->invoice_no,
                'amount' => Money::format($invoice->outstandingFils(), $locale),
                'date' => $due->toDateString(),
            ];

            $dueInDays = (int) round($today->diffInDays($due, false));

            if ($invoice->reminder_before_sent_at === null && $dueInDays >= 0 && $dueInDays <= $before) {
                $this->notify($messages, $student, $vars, $locale);
                $invoice->update(['reminder_before_sent_at' => now()]);
                $sent++;
            }

            if ($dueInDays < 0) {
                Alert::raise(AlertType::InvoiceOverdue, __('wallet.alerts.overdue_title', ['invoice' => $invoice->invoice_no, 'name' => $student->full_name]), null, $invoice, AlertSeverity::Danger);

                if ($invoice->reminder_after_sent_at === null && -$dueInDays >= $after) {
                    $this->notify($messages, $student, $vars, $locale);
                    $invoice->update(['reminder_after_sent_at' => now()]);
                    $sent++;
                }
            }
        }

        $this->info("Reminders queued: {$sent}");

        return self::SUCCESS;
    }

    private function notify(MessageService $messages, $student, array $vars, string $locale): void
    {
        foreach (array_unique(array_filter([$student->guardian_phone, $student->student_phone])) as $phone) {
            $messages->send($phone, MessageType::PaymentDueReminder, $vars, $locale, $student);
        }
    }
}
