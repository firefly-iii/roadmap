<?php

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use z4kn4fein\SemVer\SemverException;
use z4kn4fein\SemVer\Version;

require_once './vendor/autoload.php';
require_once './functions.php';
require_once './config.php';

/** @var array $releaseTypes */
/** @var array $releaseFunctions */
/** @var array $columnTypes */

$loader = new FilesystemLoader(__DIR__ . '/templates');
$twig   = new Environment($loader, [
    'cache' => __DIR__ . '/cache/templates',
    'debug' => true,
]);

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->safeLoad();

$content    = file_get_contents(__DIR__ . '/roadmap-v2.json');
$json       = json_decode($content, true);
$streams    = [];
$categories = [];

/**
 * All big "streams", aka the data importer and Firefly III itself.
 */
foreach ($json['streams'] as $item) {
    debugMessage(sprintf('Working on stream "%s"', $item['key']));
    $stream = $item;
    $data   = null;
    $data   = lastRelease($item['release_url']);
    if (null === $data) {
        debugMessage('No data, never mind.');
        continue;
    }
    try {
        $version = Version::parse($data['last_release_name']);
    } catch (SemverException $e) {
        debugMessage(sprintf('SemverException: "%s"', $e->getMessage()));
        exit;
    }
    $stream['last_commit_main']    = lastCommit($item['main_repo_url']);
    $stream['last_commit_develop'] = lastCommit($item['develop_repo_url']);
    $stream['info']                = [];

    // go over 3 release types, and then do three next versions?
    // also add project view and link to it.
    foreach ($releaseTypes as $index => $release) {
        $nextVersion              = $version;
        $func                     = $releaseFunctions[$index];
        $stream['info'][$release] = $stream['info'][$release] ?? [];

        debugMessage(sprintf('Now getting next versions for release "%s". Start with "%s"', $release, $nextVersion));

        for ($i = 0; $i < 3; $i++) {
            $current     = [];
            $nextVersion = $nextVersion->$func();
            $string      = $nextVersion->__toString();

            debugMessage(sprintf('[%d/%d] Next version is "%s"', $i + 1, 3, $string));

            $milestone = createOrFindMilestone($item['milestone_repos'], $item['milestone_name'], $string, $item['title']);

            // append current
            $search                       = countIssues($milestone);
            $current['version']           = $string;
            $current['count']             = array_sum($search);
            $current['bug_count']         = $search['bug_count'];
            $current['feature_count']     = $search['feature_count'];
            $current['enhancement_count'] = $search['enhancement_count'];
            $current['other_count']       = $search['task_count'] + $search['epic_count'];
            $current['url']               = sprintf('https://github.com/firefly-iii/firefly-iii/issues?q=is%%3Aissue%%20state%%3Aopen%%20milestone%%3A%%22%s%%22', $milestone); // TODO remove me.

            // add to array
            $stream['info'][$release][] = $current;
        }
        $stream['projects'] = [];
        // todo parse projects
        foreach ($item['projects'] as $project) {
            $current              = parseProject($project);
            $stream['projects'][] = $current;
        }
    }
    cleanupMilestones($item, $version);
    $streams[] = $stream;
}
unset($stream);
/**
 * All categories, AKA everything else.
 */
foreach ($json['categories'] as $category) {
    // loop items and get all info, like the old code used to do.
    $newItems = [];
    foreach ($category['items'] as $item) {
        // render info items for this category
        $metadata = renderAllInfo($item['key'], $json['info']);
        usort($metadata, 'customItemOrder');
        $item['metadata'] = $metadata;
        $newItems[]       = $item;
    }
    $category['items'] = $newItems;
    $categories[]      = $category;
}


$html = $twig->render(
    'roadmap-v2.twig',
    [
        'releaseTypes' => $releaseTypes,
        'categories'   => $categories,
        'streams'      => $streams,
        'intro_text'   => $json['intro_text'],
        'columnTypes'  => $columnTypes,
    ]
);

file_put_contents('build/index.html', $html);

exit;
