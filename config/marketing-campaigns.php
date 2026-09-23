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
    */

    'media' => [
        'disk' => env('MARKETING_CAMPAIGN_MEDIA_DISK', 'public'),
        'visibility' => env('MARKETING_CAMPAIGN_MEDIA_VISIBILITY', 'public'),
    ],

];
