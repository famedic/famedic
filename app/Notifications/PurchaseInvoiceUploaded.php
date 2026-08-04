<?php

namespace App\Notifications;

use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PurchaseInvoiceUploaded extends Notification
{
    use Queueable;

    protected $purchase;

    public function __construct(Model $purchase)
    {
        $this->purchase = $purchase;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $orderIdentifier = $this->getOrderIdentifier();
        $purchaseType = $this->getPurchaseType();
        $routeName = $this->getRouteName();

        return (new MailMessage)
            ->subject('Factura disponible en Famedic')
            ->line("La factura de tu orden de {$purchaseType} {$orderIdentifier} ya está disponible en Famedic.")
            ->line('Puedes consultar y descargar los archivos de tu factura (PDF y, si aplica, XML) en el siguiente enlace:')
            ->action('Ver factura', route($routeName, $this->purchase->id))
            ->line('Gracias por confiar en Famedic.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }

    private function getOrderIdentifier(): string
    {
        if ($this->purchase instanceof LaboratoryPurchase) {
            return $this->purchase->gda_order_id;
        }

        return '#' . $this->purchase->id;
    }

    private function getPurchaseType(): string
    {
        if ($this->purchase instanceof LaboratoryPurchase) {
            return 'laboratorio';
        }

        return 'farmacia';
    }

    private function getRouteName(): string
    {
        if ($this->purchase instanceof LaboratoryPurchase) {
            return 'laboratory-purchases.show';
        }

        return 'online-pharmacy-purchases.show';
    }
}