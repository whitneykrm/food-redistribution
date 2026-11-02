<?php
// Copy this file to includes/sms-config.php and fill it in.
return [
    'enabled'   => true,

    // Africa's Talking: https://account.africastalking.com
    'username'  => 'sandbox',
    'api_key'   => 'PASTE_YOUR_API_KEY_HERE',
    'sandbox'   => true,

    // Leave empty to use Africa's Talking's shared sender; or put an approved sender ID
    'sender_id' => '',
];