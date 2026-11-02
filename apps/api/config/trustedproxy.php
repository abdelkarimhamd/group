<?php

return [

    /*
    | Load balancers or reverse proxies whose X-Forwarded-* headers we trust,
    | as a comma-separated list of IPs or CIDR ranges ("*" trusts the caller).
    | Leave empty when the API is reached directly. Without this, audit logs
    | record the proxy's IP instead of the customer's.
    */
    'proxies' => env('TRUSTED_PROXIES'),

];
