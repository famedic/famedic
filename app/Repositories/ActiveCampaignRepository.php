<?php

namespace App\Repositories;

use App\Services\ActiveCampaignServiceV3;

class ActiveCampaignRepository
{
    protected ActiveCampaignServiceV3 $service;
    
    public function __construct(ActiveCampaignServiceV3 $service)
    {
        $this->service = $service;
    }
    
    public function subscribeUser(array $userData, int $listId): array
    {
        return $this->service->syncContact([
            'email' => $userData['email'],
            'firstName' => $userData['first_name'],
            'lastName' => $userData['last_name'],
            'phone' => $userData['phone'] ?? null,
        ], [$listId]);
    }
    
    public function tagUserByEmail(string $email, int $tagId): bool
    {
        $contact = $this->service->getContactByEmail($email);
        
        if (!$contact) {
            return false;
        }
        
        $this->service->tagContact($contact['id'], $tagId);
        return true;
    }
}