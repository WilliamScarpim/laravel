<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountActivationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly ?string $name = null
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url("/activate/{$this->token}");

        return (new MailMessage())
            ->subject('Ative sua conta')
            ->greeting('OlÃ¡ ' . ($this->name ?: ''))
            ->line('Recebemos um cadastro em nossa plataforma.')
            ->line('Confirme seu e-mail para ativar o acesso.')
            ->action('Ativar conta', $url)
            ->line('Se vocÃª nÃ£o solicitou, ignore esta mensagem.');
    }
}
