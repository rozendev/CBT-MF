<?php

$env = parse_ini_file('.env');
$token = $env['CLOUDFLARE_API_TOKEN'];

$query = 'query {
  __type(name: "httpRequests1hGroupsSum") {
    name
    fields {
      name
    }
  }
}';

$ch = curl_init('https://api.cloudflare.com/client/v4/graphql');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
curl_close($ch);

echo $response;
