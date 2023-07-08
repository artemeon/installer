<?php

declare(strict_types=1);

namespace Artemeon\Installer\Service;

use GuzzleHttp\Client;
use JsonException;
use Throwable;

class GitHub
{
    /**
     * @throws JsonException
     */
    public static function getProjects(string $token): array
    {
        $client = new Client([
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'Authorization' => 'token ' . $token,
            ],
            'verify' => false,
            'base_uri' => 'https://api.github.com/',
        ]);

        try {
            $response = $client->post('/graphql', [
                'body' => json_encode([
                    'query' => <<<GRAPHQL
query {
  organization(login: "artemeon") {
    repositories(first: 100, privacy: PRIVATE) {
      edges {
        node {
          name
          isArchived
        }
      }
    }
  }
}
GRAPHQL,
                ], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            return [];
        }

        $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $repositories = $data['data']['organization']['repositories']['edges'];
        $projects = [];
        foreach ($repositories as $repository) {
            if (!str_ends_with($repository['node']['name'], '-project')) {
                continue;
            }

            if ($repository['node']['isArchived']) {
                continue;
            }

            $projects[] = $repository['node']['name'];
        }

        asort($projects);

        return array_values($projects);
    }
}
