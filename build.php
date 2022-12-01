<?php

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require_once './vendor/autoload.php';
require_once './functions.php';

$loader = new FilesystemLoader(__DIR__.'/templates');
$twig   = new Environment($loader, [
    'cache' => __DIR__.'/cache/templates',
    'debug' => true,
]);

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// TODO make this env variable?
$content    = file_get_contents(__DIR__.'/roadmap.json');
$json       = json_decode($content, true);
$categories = [];

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
