<?php

declare(strict_types=1);

namespace Artemeon\Installer\Service;

class ProjectMatcher
{
    public static function closest(string $input, array $projects): string
    {
        $mappedProjects = array_map(static fn (string $project) => [
            'project' => $project,
            'similarity' => similar_text($project, $input, $percent),
            'percent' => $percent,
        ], $projects);
        $percents = array_column($mappedProjects, 'percent');
        asort($percents);
        $keys = array_reverse(array_keys($percents));
        $key = $keys[0];

        return $mappedProjects[$key]['project'];
    }
}
