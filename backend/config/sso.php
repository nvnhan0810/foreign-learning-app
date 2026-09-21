<?php

return [
    'idp_url' => rtrim((string) env('SSO_IDP_URL', 'https://nvnhan0810.com'), '/'),
    'secret' => env('SSO_SECRET'),
];
