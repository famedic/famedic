<?php

namespace App\Enums;

enum LaboratoryStructuredResultPublicationApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
