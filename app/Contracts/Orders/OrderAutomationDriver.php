<?php

namespace App\Contracts\Orders;

use App\DTOs\Orders\OrderAutomationContext;
use App\DTOs\Orders\OrderAutomationResult;

/**
 * Contract for order automation drivers (ActiveCampaign, Email, WhatsApp, etc.).
 * Drivers may satisfy this structurally without declaring implements
 * (ActiveCampaignOrderDriver already exposes these methods).
 */
interface OrderAutomationDriver
{
    public function handleLaboratoryOrder(OrderAutomationContext $context): OrderAutomationResult;

    public function handlePharmacyOrder(OrderAutomationContext $context): OrderAutomationResult;

    public function handleMembershipOrder(OrderAutomationContext $context): OrderAutomationResult;
}
