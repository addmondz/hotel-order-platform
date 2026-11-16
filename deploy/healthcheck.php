<?php

$port = getenv('PORT') ?: '8080';
$context = stream_context_create(['http' => ['timeout' => 3, 'follow_location' => 0]]);
$headers = @get_headers("http://127.0.0.1:{$port}/up", false, $context);
exit(is_array($headers) && preg_match('/^HTTP\/[0-9.]+ 200(?: |$)/', $headers[0]) ? 0 : 1);
