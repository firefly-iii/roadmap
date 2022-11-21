<?php

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SimplePie\Item;

/**
 * @param string $key
 * @param array $array
 * @return array
 * @throws GuzzleException
 */
function renderAllInfo(string $key, array $array): array
{
    $return = [];
    // loop each info byte and render it.
    /** @var array $info */
    foreach ($array as $info) {
        if ($info['parent'] !== $key) {
            continue;
        }
        switch ($info['type']) {
            default:
                //throw new RuntimeException(sprintf('Cannot handle "%s"', $info['type']));
                echo sprintf("Skip %s\n", $info['type']);
                break;
            case 'simple-link':
                // do nothing
                break;
            case 'star-counter':
                $info['stars'] = starCounter($info);
                // do something with star counter.
                break;
            case 'last-release':
                // get last release info
                $data                     = lastRelease($info);
                $info['has_last_release'] = false;
                if (null !== $data) {
                    $info['has_last_release']     = true;
                    $info['last_release_date']    = $data['last_release_date'];
                    $info['last_release_name']    = $data['last_release_name'];
                    $info['last_release_website'] = $data['last_release_website'];
                }
                break;
            case 'last-commit':
                // get last commit date and author.
                $data                    = lastCommit($info);
                $info['has_last_commit'] = false;
                if (null !== $data) {
                    $info['has_last_commit']     = true;
                    $info['last_commit_date']    = $data['last_commit_date'];
                    $info['last_commit_author']  = $data['last_commit_author'];
                    $info['last_commit_website'] = $data['last_commit_website'];
                }
                break;
            case 'issue-count-simple':
                $info['search_link'] = $info['website'] . '?' . http_build_query(['q' => $info['query']]);
                $info['issue_count'] = simpleIssueCount($info);
                break;

        }
        $return[] = $info;
    }
    return $return;
}

function simpleIssueCount(array $info): string
{
    $client = new Client;

    $opts   = [
        'headers' => [
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'Firefly III roadmap script/1.0',
            'Authorization' => sprintf('Bearer %s', $_ENV['GITHUB_TOKEN']),
        ],
    ];
    $params = [
        'q' => $info['query'],
    ];
    $full   = $info['data_url'] . '?' . http_build_query($params);
    $res    = $client->get($full, $opts);
    $body   = (string)$res->getBody();
    $json   = json_decode($body, true);
    //sleep(1);
    return $json['total_count'] ?? 0;
}

/**
 * @param array $data
 * @return string
 */
function starCounter(array $data): string
{
    $client = new Client;
    $opts   = [
        'headers' => [
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'Firefly III roadmap script/1.0',
            'Authorization' => sprintf('Bearer %s', $_ENV['GITHUB_TOKEN']),
        ],
    ];
    try {
        $res = $client->get($data['data_url'], $opts);
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        $body = (string)$e->getResponse()->getBody();
        echo $body;
        exit;
    }
    $body = (string)$res->getBody();
    $json = json_decode($body, true);
    //sleep(1);
    return (int)($json['stargazers_count'] ?? 0);
}

/**
 * @param array $info
 * @return array|null
 */
function lastRelease(array $info): ?array
{
    $prefix = $info['release_prefix'];

    // information:
    $lastDate    = Carbon::create(2000, 1, 1);
    $lastVersion = '0.0.1';
    $feed        = new \SimplePie\SimplePie();
    $feed->set_feed_url($info['data_url']);
    $feed->enable_cache(false);
    $feed->set_useragent('Firefly III get feed/1.0');
    $feed->init();
    /** @var Item $item */
    foreach ($feed->get_items() as $item) {
        $version = $item->get_title();
        if ('' !== $prefix && !str_starts_with($version, $prefix)) {
            // check if version starts with prefix.
            continue;
        }
        if (str_starts_with($version, 'v')) {
            $version = substr($version, 1);
        }
        $result = version_compare($lastVersion, $version);
        if (-1 === $result) {
            // this one is newer!
            $lastVersion = $version;
            $lastDate    = Carbon::parse($item->get_date());
        }
    }
    //sleep(1);
    if ('0.0.1' === $lastVersion) {
        return null;
    }
    return
        [
            'last_release_date'    => $lastDate,
            'last_release_name'    => $lastVersion,
            'last_release_website' => sprintf($info['website'], $lastVersion),
        ];

}

/**
 * @param array $info
 * @return array|null
 */
function lastCommit(array $info): ?array
{
    $client = new Client;

    $opts = [
        'headers' => [
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'Firefly III roadmap script/1.0',
            'Authorization' => sprintf('Bearer %s', $_ENV['GITHUB_TOKEN']),
        ],
    ];
    try {
        $res = $client->get($info['data_url'], $opts);
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        $body = (string)$e->getRequest()->getBody();
        echo $body;
        exit;
    }
    $body = (string)$res->getBody();
    $json = json_decode($body, true);
    //sleep(1);
    $lastCommit = $json['commit'] ?? null;
    if (null === $lastCommit) {
        return null;
    }

    return [
        'last_commit_date'    => Carbon::parse($lastCommit['commit']['author']['date']),
        'last_commit_author'  => $lastCommit['commit']['author']['name'],
        'last_commit_website' => $lastCommit['html_url'],
    ];
}

/**
 * Quick loop to find the parent key value.
 *
 * @param array $categories
 * @param mixed $parent
 * @return string|null
 */
function findParentKey(array $categories, mixed $parent): ?string
{
    foreach ($categories as $key => $entry) {
        if ($entry['key'] === $parent) {
            return $key;
        }
    }
    return null;
}