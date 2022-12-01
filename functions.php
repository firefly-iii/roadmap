<?php

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use SimplePie\Item;

/**
 * @param  string  $key
 * @param  array  $array
 * @return array
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
            case 'ticket-task-chart':
                $info['hash'] = renderChart($info);
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
                $info['search_link'] = $info['website'].'?'.http_build_query(['q' => $info['query']]);
                $info['issue_count'] = simpleIssueCount($info);
                break;
            case 'combined-count':
                $extra = combinedIssueCount($info);
                foreach ($extra as $label => $details) {
                    $info[$label.'_search_link'] = $info['website'].'?'.$details['query'];
                    $info[$label.'_issue_count'] = $details['count'];
                }
                break;
            case 'last-docker-image':
                $data                          = lastDockerImage($info);
                $info['has_last_docker_image'] = false;
                if (null !== $data) {
                    $info['has_last_docker_image'] = true;
                    $info['tag']                   = $data['tag'];
                    $info['date']                  = $data['date'];
                }
                break;
        }
        $return[] = $info;
    }
    return $return;
}

/**
 * @param  array  $info
 * @return string
 * @throws GuzzleException
 */
function renderChart(array $info): string
{
    global $twig;
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
    $full   = $info['data_url'].'?'.http_build_query($params);
    $hash   = hash('sha256', sprintf('chart-%s', $full));
    if (hasCache($hash)) {
        $result = getCache($hash);
    }
    if (!hasCache($hash)) {
        $client = new Client;
        $res    = $client->get($full, $opts);
        $body   = (string)$res->getBody();
        $result = json_decode($body, true);
        sleep(2);
        saveCache($hash, json_encode($result));
    }
    $return = [];
    foreach ($result['items'] as $item) {
        $title = str_replace(': tracking and progress', '', $item['title']);

        $current = [
            'title'     => $title,
            'tasks'     => [],
            'completed' => [],
            'total'     => 0,
            'html_url'  => $item['html_url'],
        ];
        $body    = $item['body'];
        $lines   = explode("\n", $body);
        foreach ($lines as $line) {
            if (preg_match('/- \[ \] (.*)/', $line, $matches)) {
                $current['tasks'][] = trim($matches[1]);
                $current['total']++;
            }
            if (preg_match('/- \[x\] (.*)/', $line, $matches)) {
                $current['completed'][] = trim($matches[1]);
                $current['total']++;
            }
        }
        $return[] = $current;
    }
    $datahash  = substr(hash('sha256', json_encode($return)), 0, 12);
    $chartData = [];
    foreach ($return as $item) {
        $chartData[] = ['title' => $item['title'], 'todo' => count($item['tasks']), 'done' => count($item['completed'])];
    }
    $rendered = $twig->render('chart.twig', ['data' => $chartData, 'hash' => $datahash]);
    file_put_contents('build/'.$datahash.'.js', $rendered);
    return $datahash;
}

/**
 * @param  array  $info
 * @return array
 * @throws GuzzleException
 */
function combinedIssueCount(array $info): array
{
    $result = [];
    $labels = ['bug', 'enhancement', 'feature'];
    foreach ($labels as $label) {
        debugMessage(sprintf('Collect "%s"-issue count for "%s"', $label, $info['parent']));

        $opts   = [
            'headers' => [
                'Accept'        => 'application/vnd.github+json',
                'User-Agent'    => 'Firefly III roadmap script/1.0',
                'Authorization' => sprintf('Bearer %s', $_ENV['GITHUB_TOKEN']),
            ],
        ];
        $query  = sprintf($info['query'], $label);
        $params = [
            'q' => $query,
        ];
        $full   = $info['data_url'].'?'.http_build_query($params);
        $hash   = hash('sha256', $full);
        if (hasCache($hash)) {
            $result[$label] = [
                'query' => http_build_query($params),
                'count' => getCache($hash),
            ];
            continue;
        }
        $client = new Client;
        $res    = $client->get($full, $opts);
        $body   = (string)$res->getBody();
        $json   = json_decode($body, true);
        sleep(2);
        $total          = $json['total_count'] ?? 0;
        $result[$label] = [
            'query' => http_build_query($params),
            'count' => $total,
        ];
        saveCache($hash, json_encode($total));
    }
    return $result;
}


/**
 * @param  array  $info
 * @return array|null
 */
function lastDockerImage(array $info): ?array
{
    debugMessage(sprintf('Get docker image info for %s/%s', $info['namespace'], $info['repository']));
    // login
    $url     = 'https://hub.docker.com/v2/users/login';
    $repoURL = sprintf('https://hub.docker.com/v2/namespaces/%s/repositories/%s/tags', $info['namespace'], $info['repository']);

    $hash = hash('sha256', $repoURL);
    if (hasCache($hash)) {
        return getCache($hash);
    }

    $client = new Client;
    $opts   = [
        'headers'     => [
            'User-Agent' => 'Firefly III roadmap script/1.0',
        ],
        'form_params' => [
            'username' => $_ENV['DOCKER_HUB_USERNAME'],
            'password' => $_ENV['DOCKER_HUB_PASSWORD'],
        ],
    ];
    $res    = $client->post($url, $opts);
    $body   = (string)$res->getBody();
    $json   = json_decode($body, true);
    $token  = $json['token'];
    sleep(2);

    // get tags
    $prefix = $info['prefix'] ?? '';
    $client = new Client;
    $opts   = [
        'headers' => [
            'User-Agent'    => 'Firefly III roadmap script/1.0',
            'Authorization' => sprintf('Bearer %s', $token),
        ],
    ];
    $res    = $client->get($repoURL, $opts);
    $body   = (string)$res->getBody();
    $json   = json_decode($body, true);

    // if it has prefix, return with prefix, otherwise simply return the first one:
    if ('' === $prefix) {
        $cached = [
            'tag'  => $json['results'][0]['name'],
            'date' => Carbon::parse($json['results'][0]['tag_last_pushed'])->format('j F Y'),
        ];
        saveCache($hash, json_encode($cached));
        return $cached;
    }
    foreach ($json['results'] as $row) {
        if (str_starts_with($row['name'], $prefix)) {
            $cached = [
                'tag'  => $row['name'],
                'date' => Carbon::parse($row['tag_last_pushed'])->format('j F Y'),
            ];
            saveCache($hash, json_encode($cached));
            return $cached;
        }
    }
    return null;
}

function simpleIssueCount(array $info): string
{
    debugMessage(sprintf('Collect issue count for "%s"', $info['website']));

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
    $full   = $info['data_url'].'?'.http_build_query($params);
    $hash   = hash('sha256', $full);
    if (hasCache($hash)) {
        return getCache($hash);
    }

    $client = new Client;

    $res  = $client->get($full, $opts);
    $body = (string)$res->getBody();
    $json = json_decode($body, true);
    sleep(2);
    $total = $json['total_count'] ?? 0;
    saveCache($hash, json_encode($total));
    return $total;
}

/**
 * @param  array  $data
 * @return string
 */
function starCounter(array $data): string
{
    $hash = hash('sha256', $data['data_url']);
    if (hasCache($hash)) {
        return getCache($hash);
    }
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
    } catch (ClientException $e) {
        $body = (string)$e->getResponse()->getBody();
        echo $body;
        exit;
    }
    $body   = (string)$res->getBody();
    $json   = json_decode($body, true);
    $result = (int)($json['stargazers_count'] ?? 0);

    saveCache($hash, json_encode($result));

    sleep(2);
    return $result;
}

/**
 * @param  string  $hash
 * @return mixed
 */
function getCache(string $hash): mixed
{
    $cache = sprintf('%s/cache/%s.json', __DIR__, $hash);
    return json_decode(file_get_contents($cache), true);
}

/**
 * @param  string  $hash
 * @param  string  $json
 * @return void
 */
function saveCache(string $hash, string $json): void
{
    $cache = sprintf('%s/cache/%s.json', __DIR__, $hash);
    file_put_contents($cache, $json);
}

/**
 * @param  string  $hash
 * @return bool
 */
function hasCache(string $hash): bool
{
    $cache = sprintf('%s/cache/%s.json', __DIR__, $hash);
    if (file_exists($cache) && is_file($cache) && is_readable($cache) && filemtime($cache) > (time() - 3600)) {
        return true;
    }
    return false;
}

/**
 * @param  array  $info
 * @return array|null
 */
function lastRelease(array $info): ?array
{
    debugMessage(sprintf('Getting last release info for "%s" (prefix: "%s").', $info['parent'], $info['release_prefix']));
    $prefix = $info['release_prefix'];
    $hash   = hash('sha256', $info['data_url'].$prefix);

    if (hasCache($hash)) {
        return getCache($hash);
    }

    // information:
    $lastDate    = Carbon::create(2000, 1, 1);
    $lastVersion = '0.0.1';
    $fullVersion = $lastVersion;
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

        // replace some obvious prefixes:
        if (str_starts_with($version, 'v')) {
            $version = substr($version, 1);
        }
        // firefly-iii-stack-
        if (str_starts_with($version, 'firefly-iii-stack-')) {
            $version = substr($version, 18);
        }
        // importer-
        if (str_starts_with($version, 'importer-')) {
            $version = substr($version, 9);
        }
        // firefly-iii-
        if (str_starts_with($version, 'firefly-iii-')) {
            $version = substr($version, 12);
        }

        $result = version_compare($lastVersion, $version);
        if (-1 === $result) {
            // this one is newer!
            $lastVersion = $version;
            $fullVersion = $item->get_title();
            $lastDate    = Carbon::parse($item->get_date())->format('j F Y');
        }
    }
    sleep(2);
    if ('0.0.1' === $lastVersion) {
        return null;
    }
    $result =
        [
            'last_release_date'    => $lastDate,
            'last_release_name'    => $lastVersion,
            'last_release_website' => sprintf($info['website'], $fullVersion),
        ];
    saveCache($hash, json_encode($result));
    return $result;
}

/**
 * @param  array  $info
 * @return array|null
 */
function lastCommit(array $info): ?array
{
    debugMessage(sprintf('Collect last commit information for "%s"', $info['website']));
    $client = new Client;

    $hash = hash('sha256', $info['data_url']);

    if (hasCache($hash)) {
        return getCache($hash);
    }

    $opts = [
        'headers' => [
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'Firefly III roadmap script/1.0',
            'Authorization' => sprintf('Bearer %s', $_ENV['GITHUB_TOKEN']),
        ],
    ];
    try {
        $res = $client->get($info['data_url'], $opts);
    } catch (ClientException $e) {
        $body = (string)$e->getRequest()->getBody();
        echo $body;
        die('here we are');
        exit;
    }
    $body       = (string)$res->getBody();
    $json       = json_decode($body, true);
    $lastCommit = $json['commit'] ?? null;
    if (null === $lastCommit) {
        return null;
    }

    $result = [
        'last_commit_date'    => Carbon::parse($lastCommit['commit']['author']['date'])->format('j F Y'),
        'last_commit_author'  => $lastCommit['commit']['author']['name'],
        'last_commit_website' => $lastCommit['html_url'],
    ];
    saveCache($hash, json_encode($result));
    return $result;
}

/**
 * Quick loop to find the parent key value.
 *
 * @param  array  $categories
 * @param  mixed  $parent
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

/**
 * @param  string  $string
 * @return void
 */
function debugMessage(string $string): void
{
    echo sprintf("%s\n", $string);
}

/**
 * I think there is a better way to do this but OK.
 * @param  array  $left
 * @param  array  $right
 * @return int
 */
function customItemOrder(array $left, array $right): int
{
    $orders = [
        'badge'              => 20,
        'star-counter'       => 25,
        'simple-link'        => 30,
        'last-release'       => 35,
        'issue-count-simple' => 40,
        'combined-count'     => 45,
        'last-commit'        => 50,
    ];
    if ('issue-count-simple' === $left['type'] && 'issue-count-simple' === $right['type']) {
        return strcmp($left['name_singular'], $right['name_singular']);
    }
    $a = $orders[$left['type']] ?? 100;
    $b = $orders[$right['type']] ?? 100;
    if ($a === $b) {
        return 0;
    }
    return ($a < $b) ? -1 : 1;
}
