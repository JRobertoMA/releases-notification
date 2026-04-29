<?php
if (!file_exists(__DIR__ . '/config.json')) {
    http_response_code(403);
    exit;
}

require_once 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

header('Content-Type: application/json');

$repo = trim($_GET['repo'] ?? '');
if (empty($repo)) {
    echo json_encode(['error' => 'Parámetro repo requerido']);
    exit;
}

$api_repo = preg_replace('/^_\//', 'library/', $repo);
if (strpos($api_repo, '/') === false) {
    $api_repo = 'library/' . $api_repo;
}

try {
    $response = (new Client())->get(
        "https://hub.docker.com/v2/repositories/{$api_repo}/tags/?page_size=100&ordering=last_updated"
    );
    $data = json_decode($response->getBody(), true);
    $tags = array_map(
        function ($t) {
            return ['name' => $t['name'], 'updated' => $t['last_updated'] ?? null];
        },
        $data['results'] ?? []
    );
    echo json_encode(['tags' => $tags]);
} catch (RequestException $e) {
    $code = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
    $msg  = $code === 404 ? 'Repositorio no encontrado en Docker Hub' : $e->getMessage();
    echo json_encode(['error' => $msg]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
