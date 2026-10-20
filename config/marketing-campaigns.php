<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Marketing campaign media
    |--------------------------------------------------------------------------
    |
    | Disk used for campaign landing uploads: hero, gallery, and product images.
    | Use "s3" in production when the site runs on Forge/AWS and local storage is
    | not shared or should not serve public campaign assets.
    |
    | Leave visibility empty for S3 buckets with ACLs disabled. In that setup,
    | public reads should be handled by bucket policy, CloudFront, or AWS_URL.
    |
    */

    'media' => [
        'disk' => env('MARKETING_CAMPAIGN_MEDIA_DISK', 'public'),
        'visibility' => env('MARKETING_CAMPAIGN_MEDIA_VISIBILITY', 'public'),
    ],

];
