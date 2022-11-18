<?php
declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require_once './vendor/autoload.php';
require_once './functions.php';

$loader = new FilesystemLoader(__DIR__ . '/templates');
$twig   = new Environment($loader, [
    'cache' => __DIR__ . '/cache/templates',
    'debug' => true,
]);


// TODO make this env variable?
$content    = file_get_contents(__DIR__ . '/roadmap.json');
$json       = json_decode($content, true);
$categories = [];

// load categories into n-deep array (max 2 levels)
/** @var array $entry */
foreach ($json['categories'] as $entry) {
    if (null === $entry['parent']) {
        $key              = $entry['key'];
        $categories[$key] = [
            'title'       => $entry['title'],
            'description' => $entry['description'] ?? '',
            'children'    => [],
        ];

        // render info items for this category
        $categories[$key]['info'] = renderAllInfo($key, $json['info']);

    }
}
ksort($categories);

// load all subcategories
/** @var array $entry */
foreach ($json['categories'] as $entry) {
    if (null !== $entry['parent']) {
        $parent  = $entry['parent'];
        $key     = $entry['key'];
        $categories[$parent]['children'][$key] = [
            'title'       => $entry['title'],
            'description' => $entry['description'],
            'info'        => renderAllInfo($key, $json['info']),
        ];
    }
}

$html = $twig->render('roadmap.twig', ['categories' => $categories,'intro_text' => $json['intro_text']]);

file_put_contents('build/index.html', $html);