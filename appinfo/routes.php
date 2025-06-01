<?php

return [
    'routes' => [
        ['name' => 'settings#saveSettings', 'url' => '/settings', 'verb' => 'POST'],
        ['name' => 'settings#sendTestEmail', 'url' => '/test-email', 'verb' => 'POST'],
    ]
];