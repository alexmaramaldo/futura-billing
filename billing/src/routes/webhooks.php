<?php
$key = getenv('WEBHOOKS_KEY')?:'';
Route::post('/billing/webhooks/'.$key, 'Souldigital\Billing\Http\Controllers\WebhookController@handleWebhook');