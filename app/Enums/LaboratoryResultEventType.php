<?php

namespace App\Enums;

enum LaboratoryResultEventType: string
{
    case WebhookReceived = 'WEBHOOK_RECEIVED';
    case PdfFetched = 'PDF_FETCHED';
    case PdfUnchanged = 'PDF_UNCHANGED';
    case PdfChanged = 'PDF_CHANGED';
    case Classified = 'CLASSIFIED';
    case InterpretationPending = 'INTERPRETATION_PENDING';
    case ResultComplete = 'RESULT_COMPLETE';
    case ClassificationFailed = 'CLASSIFICATION_FAILED';
    case RefreshScheduled = 'REFRESH_SCHEDULED';
    case RefreshChecked = 'REFRESH_CHECKED';
    case RefreshFailed = 'REFRESH_FAILED';
    case RefreshAttemptsExhausted = 'REFRESH_ATTEMPTS_EXHAUSTED';
    case CompletionGateEvaluated = 'COMPLETION_GATE_EVALUATED';
    case NotificationSuppressed = 'NOTIFICATION_SUPPRESSED';
    case PurchaseResultsComplete = 'PURCHASE_RESULTS_COMPLETE';
    case PatientNotificationRequested = 'PATIENT_NOTIFICATION_REQUESTED';
    case AdminRefreshRequested = 'ADMIN_REFRESH_REQUESTED';
    case AdminNotificationRequested = 'ADMIN_NOTIFICATION_REQUESTED';
    case AdminNotificationSent = 'ADMIN_NOTIFICATION_SENT';
    case AdminNotificationFailed = 'ADMIN_NOTIFICATION_FAILED';
    case LegacyAnalysisRequested = 'LEGACY_ANALYSIS_REQUESTED';
}
