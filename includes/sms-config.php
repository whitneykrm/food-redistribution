<?php
// Africa's Talking SMS settings. Keep this file out of Git (see .gitignore).
return [
    'enabled'   => true,
    'username'  => 'food-connect',   // exact app username from the dashboard
    'api_key'   => 'atsk_3a0855ce6d7f2b71bf878042c91c4d2c0b73b458d74e688930743181826de018e6d58984',
    'sandbox'   => false,            // live app
    'sender_id' => '',               // leave empty; custom sender IDs need approval
    'debug'     => false,            // true = log key length/last 4 chars to the PHP error log
];