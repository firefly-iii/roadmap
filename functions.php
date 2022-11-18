<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

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
        if ($info['parent'] === $key) {
            switch ($info['type']) {
                default:
                    throw new RuntimeException(sprintf('Cannot handle "%s"', $info['type']));
                case 'star-counter':
                    // do something with star counter.
                    $return[] = starCounter($info);
                    break;
                case 'last-release':
                    // get last release info
                    $return[] = lastRelease($info);
                    break;
                case 'last-commit':
                    // get last commit date and author.
                    $return[] = lastCommit($info);
                    break;
                case 'issue-count-simple':
                    $return[] = simpleIssueCount($info);
                    break;

            }
        }
        // other types go here:
    }
    return $return;
}

function simpleIssueCount(array $info): string
{
    $client = new Client;

    $opts = [
        'headers' => [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'Firefly III roadmap script/1.0',
        ],
    ];
    $params = [
        'q' => $info['query']
    ];
    $full = $info['url']  . '?' . http_build_query($params);
    $res   = $client->get($full, $opts);
    $body  = (string)$res->getBody();
    $json  = json_decode($body, true);
    $count = $json['total_count'] ?? 0;
    $link = $info['link'] . '?' . http_build_query($params);

    sleep(1);

    if(1 === $count) {
        return sprintf('There is currently <a href="%s">one %s</a>.',$link, $info['name_singular']);
    }
    return sprintf('There are currently <a href="%s">%d %s</a>.',$link, $count, $info['name_plural']);
}

/**
 * @param array $data
 * @return string
 * @throws GuzzleException
 */
function starCounter(array $data): string
{

    $client = new Client;

    $opts = [
        'headers' => [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'Firefly III roadmap script/1.0',
        ],
    ];

    $res   = $client->get($data['url'], $opts);
    $body  = (string)$res->getBody();
    $json  = json_decode($body, true);
    $count = $json['stargazers_count'] ?? 0;

    // anti spam measure.
    sleep(1);

    // render
    $string = 'Repository <a href="%s" title="%s">%s</a> has %d stars.';
    if (1 === $count) {
        $string = 'Repository <a href="%s" title="%s">%s</a> has %d star.';
    }

    return sprintf($string, $data['link'], $data['repo_title'], $data['repo_title'], $count);

}

/**
 * @param array $info
 * @return string
 */
function lastRelease(array $info): string
{
    $prefix      = $info['prefix'] ?? '';
    $lastVersion = '0.0.1';
    $lastDate    = \Carbon\Carbon::create(2000, 1, 1);
    $feed        = new \SimplePie\SimplePie();
    $feed->set_feed_url($info['url']);
    $feed->enable_cache(false);
    $feed->set_useragent('Firefly III get feed/1.0');
    $feed->init();
    /** @var \SimplePie\Item $item */
    foreach ($feed->get_items() as $item) {
        $version = $item->get_title();
        if ('' !== $prefix && !str_starts_with($version, $prefix)) {
            // check if version starts with prefix.
            continue;
        }
        $result = version_compare($lastVersion, $version);
        if (-1 === $result) {
            // this one is newer!
            $lastVersion = $version;
            $lastDate    = \Carbon\Carbon::parse($item->get_date());
        }
    }
    sleep(1);
    if ('0.0.1' === $lastVersion) {
        return 'No release found with this version.';
    }
    $link = sprintf($info['link'], $lastVersion);
    return sprintf('Last release is <strong><a href="%s">%s</a></strong>, released on %s', $link, $lastVersion, $lastDate->format('Y-m-d'));
}

/**
 * @param array $info
 * @return string
 */
function lastCommit(array $info): string {
    $client = new Client;

    $opts = [
        'headers' => [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'Firefly III roadmap script/1.0',
        ],
    ];

    $res   = $client->get($info['url'], $opts);
    $body  = (string)$res->getBody();
    $json  = json_decode($body, true);
    sleep(1);
    $lastCommit = $json['commit']['commit']['author'] ?? null;
    if(null === $lastCommit) {
        return 'No information on last commit.';
    }
    $date = \Carbon\Carbon::parse($lastCommit['date']);
    return sprintf('Last commit on branch <a href="%s"><code>%s</code></a> by %s on %s.', $info['link'], $info['branch'], $lastCommit['name'], $date->format('Y-m-d'));
}