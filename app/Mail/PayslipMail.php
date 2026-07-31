<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PayslipMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $employeeName,
        public readonly string $period,
        private readonly string $pdfContent,
        private readonly string $pdfFilename,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject("Payslip for {$this->period}")
            ->view('emails.payslip')
            ->with([
                'employeeName' => $this->employeeName,
                'period' => $this->period,
            ])
            ->attachData($this->pdfContent, $this->pdfFilename, [
                'mime' => 'application/pdf',
            ]);
    }
}
