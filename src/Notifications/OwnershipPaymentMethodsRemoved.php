<?php

namespace Ssuite\Core\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OwnershipPaymentMethodsRemoved extends Notification
{
    public function __construct(public string $workspaceName) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Saved payment methods removed after ownership transfer'))
            ->line(__('The previous owner’s saved payment methods for :workspace were removed and automatic renewal was turned off.', ['workspace' => $this->workspaceName]))
            ->line(__('The new owner can save their own payment method to enable automatic renewal.'));
    }
}
