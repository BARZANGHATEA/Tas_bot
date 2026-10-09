<?php

namespace App\Enums;

enum CompletionStatus: string
{
    case Started = 'started';
    case PendingReview = 'pending_review';
    case Rewarded = 'rewarded';
    case Rejected = 'rejected';
}
