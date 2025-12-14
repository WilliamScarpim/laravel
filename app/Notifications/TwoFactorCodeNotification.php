<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly int $expiresInMinutes = 10
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Seu código de verificação')
            ->line('Use o código abaixo para concluir o login com segurança.')
            ->line(sprintf('Código: **%s**', $this->code))
            ->line(sprintf('Ele expira em %d minutos.', $this->expiresInMinutes))
            ->line('Se você não solicitou este acesso, ignore esta mensagem.');
    }
}
