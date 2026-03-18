<?php
// app/Actions/Laboratory/ProcessNotificationAction.php

namespace App\Actions\Laboratory;

use App\Models\LaboratoryNotification;
use Illuminate\Support\Facades\Log;

class ProcessNotificationAction
{
    protected HandleSampleCollectionNotificationAction $handleSampleAction;
    protected HandleResultsNotificationAction $handleResultsAction;
    protected HandleStatusUpdateAction $handleStatusAction;

    public function __construct(
        HandleSampleCollectionNotificationAction $handleSampleAction,
        HandleResultsNotificationAction $handleResultsAction,
        HandleStatusUpdateAction $handleStatusAction
    ) {
        $this->handleSampleAction = $handleSampleAction;
        $this->handleResultsAction = $handleResultsAction;
        $this->handleStatusAction = $handleStatusAction;
    }

    public function execute(LaboratoryNotification $notification, array $data, array $references): void
    {
        try {
            match ($notification->notification_type) {
                LaboratoryNotification::TYPE_SAMPLE_COLLECTION => 
                    $this->handleSampleAction->execute($notification, $data, $references),
                
                LaboratoryNotification::TYPE_RESULTS => 
                    $this->handleResultsAction->execute($notification, $data, $references),
                
                LaboratoryNotification::TYPE_STATUS_UPDATE => 
                    $this->handleStatusAction->execute($notification, $data, $references),
                
                default => 
                    $this->handleDefault($notification, $data)
            };

            Log::info('Notification processed', [
                'notification_id' => $notification->id,
                'type' => $notification->notification_type
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing notification', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $notification->update(['status' => LaboratoryNotification::STATUS_ERROR]);
        }
    }

    protected function handleDefault(LaboratoryNotification $notification, array $data): void
    {
        Log::info('General notification processed', [
            'notification_id' => $notification->id,
            'gda_order_id' => $data['id'],
            'status' => $data['status']
        ]);

        $notification->update(['status' => LaboratoryNotification::STATUS_PROCESSED]);
    }
}