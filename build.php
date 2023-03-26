<?php

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use z4kn4fein\SemVer\Version;

require_once './vendor/autoload.php';
require_once './functions.php';

$loader = new FilesystemLoader(__DIR__.'/templates');
$twig   = new Environment($loader, [
    'cache' => __DIR__.'/cache/templates',
    'debug' => true,
]);

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->safeLoad();

$content          = file_get_contents(__DIR__.'/roadmap-v2.json');
$json             = json_decode($content, true);
$categories       = [];
$releaseTypes     = ['patch', 'minor', 'major'];
$releaseFunctions = ['getNextPatchVersion', 'getNextMinorVersion', 'getNextMajorVersion'];

foreach ($json['categories'] as $item) {
    $category                        = $item;
    $data                            = lastRelease($item['release_url']);
    $version                         = Version::parse($data['last_release_name']);
    $category['last_commit_main']    = lastCommit($item['main_repo_url']);
    $category['last_commit_develop'] = lastCommit($item['develop_repo_url']);
    $category['info']                = [];

    // go over 3 release types, and then do three next versions?
    // also add project view and link to it.
    foreach ($releaseTypes as $index => $release) {
        $nextVersion                = $version;
        $func                       = $releaseFunctions[$index];
        $category['info'][$release] = $category['info'][$release] ?? [];

        for ($i = 0; $i < 3; $i++) {
            $current     = [];
            $nextVersion = $nextVersion->$func();
            $string      = $nextVersion->__toString();
            $milestone   = createOrFindMilestone($item['milestone_repos'], $item['milestone_name'], $string, $item['title']);

            // append current
            $search                       = countIssues(sprintf($item['milestone_search'], $milestone));
            $current['version']           = $string;
            $current['count']             = $search['count'];
            $current['bug_count']         = $search['bug_count'];
            $current['feature_count']     = $search['feature_count'];
            $current['enhancement_count'] = $search['enhancement_count'];
            $current['other_count']       = $search['other_count'];
            $current['url']               = 'https://github.com/firefly-iii/firefly-iii/issues?'.$search['query'];

            // add to array
            $category['info'][$release][] = $current;
        }

//        $category['release_info'][$release] = [
//            'next_releases' => [],
//        ];
//        for ($i = 0; $i < 3; $i++) {
//
//            $category['release_info'][$release]['next_releases'][] = [
//                'version' => $nextVersion->__toString(),
//            ];
//
//        }
////                    'version' => $version->$func(),
////                    'url' => 'https://github.com/firefly-iii/firefly-iii/issues?q=' . urlencode(sprintf($item['next_release_search'], $release)),
////                    'count'   => queryResult(sprintf($item['next_release_search'], $release)),
////                ];
///
///
    }
    /*
     * Projects
     */
//    if (array_key_exists('projects', $item)) {
//        $category['projects'] = $item['projects'];
//    }

    $categories[] = $category;
}

$html = $twig->render('roadmap-v2.twig', ['releaseTypes' => $releaseTypes, 'categories' => $categories, 'intro_text' => $json['intro_text']]);

file_put_contents('build/index.html', $html);


exit;

/*
 * Firefly III
 * patch, minor, major
 *
 * Projects
 * Layout v3
 * API v2
 */

/*
 * Data Importer
 * patch, minor, major
 *
 * Projects
 * Shared import configurations
 */

/*
 * Documentation
 *
 * Documentation
 * API documentation
 * API documentation generator
 */

/*
 * Tools and utils
 * Auto-save tool
 * PM
 * Data generator
 * Import test data repository
 */

/*
 * Libraries
 *
 * API support
 * Google 2FA
 * Google 2FA recovery
 */

/*
 * Builds and releases
 * Docker base
 * Docker FF3
 * Docker data
 * kubernetes
 *
 */


// load categories into n-deep array (max 2 levels)
/** @var array $entry */
foreach ($json['categories'] as $entry) {
    if (null === $entry['parent']) {
        $key                          = sprintf('%03d-%s', $entry['order'], $entry['key']);
        $categories[$key]             = $entry;
        $categories[$key]['children'] = [];

        // render info items for this category
        $items = renderAllInfo($entry['key'], $json['info']);

        usort($items, 'customItemOrder');
        $categories[$key]['info'] = $items;
    }
}

ksort($categories);

// load all subcategories

foreach ($json['categories'] as $entry) {
    if (null !== $entry['parent']) {
        $parentKey = findParentKey($categories, $entry['parent']);
        if (null === $parentKey) {
            continue;
        }
        if (!array_key_exists('order', $entry)) {
            var_dump($entry);
            exit;
        }
        $key = sprintf('%03d-%s', $entry['order'], $entry['key']);

        $items = renderAllInfo($entry['key'], $json['info']);
        usort($items, 'customItemOrder');
        $categories[$parentKey]['children'][$key] = [
            'title'       => $entry['title'],
            'description' => $entry['description'],
            'info'        => $items,
        ];
    }
}

foreach (array_keys($categories) as $i) {
    ksort($categories[$i]['children']);
}
$html = $twig->render('roadmap.twig', ['categories' => $categories, 'intro_text' => $json['intro_text']]);

file_put_contents('build/index.html', $html);
