<?php

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use SimplePie\Item;
use z4kn4fein\SemVer\Version;

/**
 * @param array $project
 *
 * @return array
 * @throws GuzzleException
 */
function parseProject(array $project): array
{
    $return = [
        'title'       => $project['title'],
        'description' => $project['description'],
        'roadmap'     => $project['roadmap'],
        'epics'       => [
            'todo'  => [],
            'doing' => [],
            'done'  => [],
        ],
    ];
    debugMessage(sprintf('Start of parseProject("%s")', $project['title']));

    // count and sort all epics.
    $issues = getIssueList(sprintf('repo:firefly-iii/firefly-iii type:issue state:open -label:fixed label:epic label:%s', $project['label']));

    // grab project info (using graphQL)
    $statuses = getProjectInfo($project['id']);

    $new = [];
    foreach ($issues as $issue) {
        $issue['status'] = $statuses[$issue['number']] ?? 'No status';
        $new[]           = $issue;
    }
    // count how many tasks per epic and also mention how many already are issues.
    foreach ($new as $epic) {
        switch ($epic['status']) {
            default:
                var_dump($epic);
                die(sprintf('Cannot deal with status "%s"', $epic['status']));
            case 'Todo':
            case 'No status':
                $return['epics']['todo'][] = $epic;
                break;
            case 'In Progress':
                $return['epics']['doing'][] = $epic;
                break;
            case 'Done':
                $return['epics']['done'][] = $epic;
                break;
        }
    }

    return $return;
}

/**
 * @param string $id
 *
 * @return array
 * @throws GuzzleException
 */
function getProjectInfo(string $id): array
{
    $hash = hash('sha256', $id);
    if (hasCache($hash)) {
        return getCache($hash);
    }
    $statuses = [];
    $array    = [
        'query' => sprintf(
            'query { 
                        node(id: "%s") {
                            ... on ProjectV2 {
                                items(first: 50) { 
                                    nodes { 
                                        id 
                                        fieldValues(first: 8) { 
                                            nodes { 
                                                ... on ProjectV2ItemFieldTextValue { 
                                                    id text field { 
                                                        ... on ProjectV2FieldCommon {
                                                            id name 
                                                        }
                                                    }
                                                } 
                                                ... on ProjectV2ItemFieldDateValue { 
                                                    date field { 
                                                        ... on ProjectV2FieldCommon {
                                                            name 
                                                        } 
                                                    } 
                                                } 
                                                    ... on ProjectV2ItemFieldSingleSelectValue {
                                                     name field {
                                                        ... on ProjectV2FieldCommon {
                                                            name 
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        content {
                                            ... on DraftIssue {
                                                title body 
                                            } 
                                            ...on Issue {
                                                number title
                                            } 
                                            ...on PullRequest {
                                                number title 
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }',
            $id
        ),
    ];
    $opts     = [
        'headers' => [
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'Firefly III roadmap script/1.0',
            'Authorization' => sprintf('Bearer %s', getenv('GH_TOKEN')),
        ],
        'json'    => $array,
    ];
    $full     = 'https://api.github.com/graphql';

    $client = new Client;
    $res    = $client->post($full, $opts);
    $body   = (string)$res->getBody();
    $json   = json_decode($body, true);
    if (!array_key_exists('data', $json)) {
        var_dump($json);
        echo PHP_EOL;
        die('JSON does not have "data" key.');
    }
    if (null === $json['data'] || !array_key_exists('node', $json['data'])) {
        var_dump($json);
        echo PHP_EOL;
        die('JSON does not have ["data"]["node"] key.');
    }
    if (null === $json['data']['node'] || !array_key_exists('items', $json['data']['node'])) {
        echo PHP_EOL;
        echo PHP_EOL;
        var_dump($json);
        echo PHP_EOL;
        echo PHP_EOL;
        echo $array['query'];
        echo PHP_EOL;
        echo PHP_EOL;
        die('JSON does not have ["data"]["node"]["items"] key.');
    }
    if (null === $json['data']['node']['items'] || !array_key_exists('nodes', $json['data']['node']['items'])) {
        var_dump($json);
        echo PHP_EOL;
        die('JSON does not have ["data"]["node"]["items"]["nodes"] key.');
    }
    $info = $json['data']['node']['items']['nodes'];
    /** @var array $projectItem */
    foreach ($info as $projectItem) {
        $status  = 'No status';
        $issueId = $projectItem['content']['number'] ?? 0;
        /** @var array $fieldValue */
        foreach ($projectItem['fieldValues'] as $fieldValue) {
            /** @var array $currentFieldItem */
            foreach ($fieldValue as $currentFieldItem) {
                if (0 === count($currentFieldItem)) {
                    continue;
                }
                $fieldName = $currentFieldItem['field']['name'] ?? 'unknown';
                if ('Status' === $fieldName) {
                    $status = $currentFieldItem['name'];
                }
            }
        }
        $statuses[$issueId] = $status;
    }
    saveCache($hash, json_encode($statuses));
    return $statuses;
}

/**
 * @param string $key
 * @param array $array
 *
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
            case 'star-counter':
                $info['stars'] = starCounter($info);
                // do something with star counter.
                break;
            case 'last-release':
                // get last release info
                $data                     = lastRelease($info['data_url']);
                $info['has_last_release'] = false;


                if (null !== $data) {
                    $info['has_last_release']     = true;
                    $info['last_release_website'] = sprintf($info['website'], $data['last_release_name']);
                    $info['last_release_date']    = $data['last_release_date'];
                    $info['last_release_name']    = $data['last_release_name'];
                }
                break;
            case 'last-commit':
                // get last commit date and author.
                $data                    = lastCommit($info['data_url']);
                $info['has_last_commit'] = false;
                if (null !== $data) {
                    $info['has_last_commit']     = true;
                    $info['last_commit_date']    = $data['last_commit_date'];
                    $info['last_commit_author']  = $data['last_commit_author'];
                    $info['last_commit_website'] = $data['last_commit_website'];
                }
                break;
            case 'combined-count':
                $extra = combinedIssueCount($info);
                foreach ($extra as $label => $details) {
                    $info[$label . '_search_link'] = $info['website'] . '?' . $details['query'];
                    $info[$label . '_issue_count'] = $details['count'];
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
 * @param string $query
 *
 * @return array
 * @throws GuzzleException
 */
function getIssueList(string $query): array
{
    debugMessage(sprintf('Get issue list for "%s"', $query));
    $opts   = [
        'headers' => [
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'Firefly III roadmap script/1.0',
            'Authorization' => sprintf('Bearer %s', getenv('GH_TOKEN')),
        ],
    ];
    $params = ['q' => $query, 'limit' => 100];
    $full   = 'https://api.github.com/search/issues?' . http_build_query($params);
    $hash   = hash('sha256', $full . 'list');
    if (hasCache($hash)) {
        return getCache($hash);
    }
    $client = new Client;
    $res    = $client->get($full, $opts);
    $body   = (string)$res->getBody();
    $json   = json_decode($body, true);
    $total  = $json['total_count'] ?? 0;
    $return = [];
    debugMessage(sprintf('Found %d issue(s)', $total));
    foreach ($json['items'] as $item) {
        sleep(2);
        $current  = [
            'html_url' => $item['html_url'],
            'title'    => $item['title'],
            'number'   => $item['number'],
        ];
        $moreInfo = getIssueDetails($item['url']);

        if (!array_key_exists('from_cache', $moreInfo)) {
            sleep(2);
        }

        $current['task_count'] = $moreInfo['total'];
        $current['tasks']      = $moreInfo['todo_items'];

        // even more processing here!


        $return[] = $current;
    }

    // cache results
    saveCache($hash, json_encode($return));

    return $return;
}

function getIssueDetails(string $url): array
{
    debugMessage(sprintf('Get issue details for "%s"', $url));
    $opts = [
        'headers' => [
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'Firefly III roadmap script/1.0',
            'Authorization' => sprintf('Bearer %s', getenv('GH_TOKEN')),
        ],
    ];
    $hash = hash('sha256', $url);
    if (hasCache($hash)) {
        debugMessage('From cache...');
        $result               = getCache($hash);
        $result['from_cache'] = true;
        return $result;
    }
    $client             = new Client;
    $res                = $client->get($url, $opts);
    $body               = (string)$res->getBody();
    $json               = json_decode($body, true);
    $json['total']      = 0;
    $json['todo_items'] = [
        'todo'  => [],
        'doing' => [],
        'done'  => [],
    ];
    saveCache($hash, json_encode($json));

    $body  = $json['body'];
    $lines = explode("\n", $body);
    foreach ($lines as $line) {
        // still to do
        if (preg_match('/- \[ \] (.*)/', $line, $matches)) {
            $json['todo_items']['todo'][] = trim($matches[1]);
            $json['total']++;
        }
        // done!
        if (preg_match('/- \[x\] (.*)/', $line, $matches)) {
            $json['todo_items']['done'][] = trim($matches[1]);
            $json['total']++;
        }
    }
    return $json;
}


/**
 * @param string $query
 *
 * @return array
 */
function countIssues(string $milestone): array
{
    debugMessage(sprintf('Collect issue count for milestone "%s"', $milestone));
    $opts = [
        'headers' => [
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'Firefly III roadmap script/1.0',
            'Authorization' => sprintf('Bearer %s', getenv('GH_TOKEN')),
        ],
    ];

    $result = [
        'count'             => 0,
        'bug_count'         => 0,
        'feature_count'     => 0,
        'enhancement_count' => 0,
        'other_count'       => 0,
    ];

    $issueTypes = ['Bug', 'Enhancement', 'Feature', 'Epic', 'Task'];
    foreach ($issueTypes as $issueType) {
        // search for issues with this issue type, possibly over multiple pages.
        // Cache the first time we look for this.
        $searchResult                                        = searchForTypeInMilestone($issueType, $milestone);
        $result[sprintf('%s_count', strtolower($issueType))] = $searchResult['count'];
    }
    return $result;
}

function searchForTypeInMilestone(string $issueType, string $milestone): array
{
    $return       = [
        'count' => 0,
    ];
    $hasMorePages = true;
    $nextCursor   = '';
    while ($hasMorePages) {
        // shitty graphql query but im lazy.
        if ('' !== $nextCursor) {
            $nextCursor = sprintf('after: "%s",', $nextCursor);
        }
        $query = [
            'query' => sprintf('
                query {
                search(type: ISSUE, %s first: 50,  query: "type:%s repo:firefly-iii/firefly-iii") {
                    issueCount
                    nodes {
                      ... on Issue {
                        id
                        number
                        title
                        
                        milestone {
                         id
                                title
                            }
                        }
                      }
                  pageInfo {
                        endCursor
                        startCursor
                        hasNextPage
                        hasPreviousPage
                      }
                    }
                  }',
                               $nextCursor, $issueType),
        ];
        $hash  = hash('sha256', json_encode($query));
        $info  = [];
        if (hasCache($hash)) {
            $info = getCache($hash);
        }
        if (!hasCache($hash)) {
            // send query, copy-paste from before.
            $opts = [
                'headers' => [
                    'Accept'        => 'application/vnd.github+json',
                    'User-Agent'    => 'Firefly III roadmap script/1.0',
                    'Authorization' => sprintf('Bearer %s', getenv('GH_TOKEN')),
                ],
                'json'    => $query,
            ];
            $full = 'https://api.github.com/graphql';

            $client = new Client;
            try {
                $res = $client->post($full, $opts);
            } catch (GuzzleException $e) {
                die('error.');
            }
            $body = (string)$res->getBody();
            $json = json_decode($body, true);
            $info = $json['data']['search'] ?? false;
            saveCache($hash, json_encode($info));
        }

        foreach ($info['nodes'] as $node) {
            if (null === $node) {
                continue;
            }
            if (null === $node['milestone']) {
                continue;
            }
            if ($node['milestone']['title'] === $milestone) {
                $return['count']++;
            }
        }
        $hasMorePages = $info['pageInfo']['hasNextPage'] ?? false;
        if (true === $hasMorePages) {
            $nextCursor = $info['pageInfo']['endCursor'];
        }
    }
    debugMessage(sprintf('Search for "%s" in "%s", found: %d issue(s)', $issueType, $milestone, $return['count']));
    return $return;
}


/**
 * @param array|null $labels
 * @param string $label
 *
 * @return bool
 */
function hasLabel(?array $labels, string $label): bool
{
    if (null === $labels) {
        return false;
    }
    foreach ($labels as $current) {
        if ($current['name'] === $label) {
            return true;
        }
    }
    return false;
}

/**
 * @param array $info
 *
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
                'Authorization' => sprintf('Bearer %s', getenv('GH_TOKEN')),
            ],
        ];
        $query  = sprintf($info['query'], $label);
        $params = [
            'q' => $query,
        ];
        $full   = $info['data_url'] . '?' . http_build_query($params);
        $hash   = hash('sha256', $full);
        if (hasCache($hash)) {
            $count          = getCache($hash);
            $result[$label] = [
                'query' => http_build_query($params),
                'count' => $count,
            ];
            debugMessage(sprintf('"%s" issue count is %d (cached)', $label, $count));
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
        debugMessage(sprintf('"%s" issue count is %d', $label, $total));
        saveCache($hash, json_encode($total));
    }
    return $result;
}


/**
 * @param array $info
 *
 * @return array|null
 */
function lastDockerImage(array $info): ?array
{
    debugMessage(sprintf('Get docker image info for %s/%s', $info['namespace'], $info['repository']));
    // login
    $url     = 'https://hub.docker.com/v2/users/login';
    $repoURL = sprintf('https://hub.docker.com/v2/namespaces/%s/repositories/%s/tags', $info['namespace'], $info['repository']);

    $hash = hash('sha256', $repoURL . $info['prefix']);
    if (hasCache($hash)) {
        return getCache($hash);
    }

    $client = new Client;
    $opts   = [
        'headers'     => [
            'User-Agent' => 'Firefly III roadmap script/1.0',
        ],
        'form_params' => [
            'username' => getenv('DOCKER_HUB_USERNAME'),
            'password' => getenv('DOCKER_HUB_PASSWORD'),
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


/**
 * @param array $data
 *
 * @return string
 */
function starCounter(array $data): string
{
    $hash = hash('sha256', $data['data_url']);
    if (hasCache($hash)) {
        $result = getCache($hash);
        debugMessage(sprintf('Star count is %d (cached).', $result));
        return $result;
    }
    $client = new Client;
    $opts   = [
        'headers' => [
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'Firefly III roadmap script/1.0',
            'Authorization' => sprintf('Bearer %s', getenv('GH_TOKEN')),
        ],
    ];
    try {
        $res = $client->get($data['data_url'], $opts);
    } catch (ClientException $e) {
        $body = (string)$e->getResponse()->getBody();
        echo $body;
        echo 'Error in star counter';
        exit;
    }
    $body   = (string)$res->getBody();
    $json   = json_decode($body, true);
    $result = (int)($json['stargazers_count'] ?? 0);

    debugMessage(sprintf('Star count is %d.', $result));

    saveCache($hash, json_encode($result));

    sleep(2);
    return $result;
}

/**
 * @param string $hash
 *
 * @return mixed
 */
function getCache(string $hash): mixed
{
    $cache = sprintf('%s/cache/%s.json', __DIR__, $hash);
    return json_decode(file_get_contents($cache), true);
}

/**
 * @param string $hash
 * @param string $json
 *
 * @return void
 */
function saveCache(string $hash, string $json): void
{
    $cache = sprintf('%s/cache/%s.json', __DIR__, $hash);
    file_put_contents($cache, $json);
}

/**
 * @param string $hash
 *
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
 * @param string $url
 *
 * @return array|null
 */
function lastRelease(string $url): ?array
{
    debugMessage(sprintf('Getting last release info for %s', $url));
    $hash = hash('sha256', $url);
    if (hasCache($hash)) {
        $result = getCache($hash);
        debugMessage(sprintf('Last release was "%s" on %s (cached).', $result['last_release_name'], $result['last_release_date']));
        return $result;
    }

    // information:
    $lastDate    = Carbon::create(2000, 1, 1);
    $lastVersion = '0.0.1';
    $client      = new Client();
    try {
        $res = $client->get($url, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Firefly III roadmap script/1.0',
            ],
        ]);
    } catch (GuzzleException $e) {
        debugMessage(sprintf('Could not fetch data from GitHub: %s', $e->getMessage()));
        return null;
    }
    $body = (string)$res->getBody();
    $json = json_decode($body, true);

    /** @var array $item */
    foreach ($json as $item) {
        $version = $item['name'];

        if (str_starts_with($version, 'Development release')) {
            debugMessage(sprintf('Skip development release "%s" (%s)', $version, $item['tag_name']));
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
            $lastDate    = Carbon::parse($item['created_at'])->format('j F Y');
        }
    }
    sleep(2);
    if ('0.0.1' === $lastVersion) {
        debugMessage('Could not find last release.');
        return null;
    }
    $result
        = [
        'last_release_date' => $lastDate,
        'last_release_name' => $lastVersion,
    ];
    debugMessage(sprintf('Last release was "%s" on %s.', $lastVersion, $lastDate));
    saveCache($hash, json_encode($result));
    return $result;
}

function cleanupMilestones(array $item, Version $version)
{
    $url    = 'https://api.github.com/repos/firefly-iii/firefly-iii/milestones?per_page=100';
    $prefix = str_replace('%s', '', $item['milestone_name']);
    $client = new Client;
    debugMessage(sprintf('Clean up milestones before version "%s" in %s.', $version, $url));
    $opts = [
        'headers' => [
            'Accept'               => 'application/vnd.github+json',
            'User-Agent'           => 'Firefly III roadmap script/1.0',
            'X-GitHub-Api-Version' => '2022-11-28',
            'Authorization'        => sprintf('Bearer %s', getenv('GH_TOKEN')),
        ],
    ];
    try {
        $res = $client->get($url, $opts);
    } catch (ClientException $e) {
        $body = (string)$e->getRequest()->getBody();
        echo $body;
        echo 'Got client exception when requesting data from GitHub.' . PHP_EOL;
        echo PHP_EOL;
        echo $e->getMessage();
        die('');
    }
    $body = (string)$res->getBody();
    $json = json_decode($body, true);
    foreach ($json as $entry) {
        if (!str_starts_with($entry['title'], $prefix)) {
            debugMessage(sprintf('Skip milestone "%s"', $entry['title']));;
            continue;
        }
        $currentVersion = Version::parse(str_replace($prefix, '', $entry['title']));
        if ($currentVersion->isLessThanOrEqual($version)) {
            debugMessage(sprintf('Milestone "%s" with version "%s" will be closed.', $entry['title'], $currentVersion));;

            $patchOpts = $opts;
            $patchOpts['json'] = ['state' => 'closed'];

            $patchClient = new Client;
            $patchClient->patch($entry['url'], $opts);
        }
        if (!$currentVersion->isLessThanOrEqual($version)) {
            debugMessage(sprintf('Milestone "%s" with version "%s" will be kept.', $entry['title'], $currentVersion));;
        }
    }
}


/**
 * @param string $repository
 * @param string $key
 * @param string $version
 * @param string $title
 *
 * @return string
 * @throws GuzzleException
 */
function createOrFindMilestone(string $repository, string $key, string $version, string $title): string
{
    $url         = sprintf('https://api.github.com/repos/%s/milestones?per_page=100', $repository);
    $client      = new Client;
    $expectedKey = sprintf($key, $version);
    $hash        = hash('sha256', $url . $expectedKey);
    $cached      = hasCache($hash);
    $result      = null;
    debugMessage(sprintf('Create or find milestone "%s" in %s.', $expectedKey, $repository));
    if (!$cached) {
        debugMessage('No cache found, will fetch from GitHub.');
        $opts = [
            'headers' => [
                'Accept'        => 'application/vnd.github+json',
                'User-Agent'    => 'Firefly III roadmap script/1.0',
                'Authorization' => sprintf('Bearer %s', getenv('GH_TOKEN')),
            ],
        ];
        try {
            $res = $client->get($url, $opts);
        } catch (ClientException $e) {
            $body = (string)$e->getRequest()->getBody();
            echo $body;
            echo 'Got client exception when requesting data from GitHub.' . PHP_EOL;
            echo PHP_EOL;
            echo $e->getMessage();
            die('');
        }
        $body = (string)$res->getBody();
        $json = json_decode($body, true);
        foreach ($json as $item) {
            if ($item['title'] === $expectedKey) {
                $result = $item;
                debugMessage(sprintf('Found a milestone.  "%s" vs "%s" at %s', $item['title'], $expectedKey, $item['url']));
                break;
            }
        }
        if (null !== $result) {
            debugMessage('Saving cache.');
            saveCache($hash, json_encode($result));
        }
    }

    if ($cached) {
        $result = getCache($hash);
        debugMessage('Returning cache: ' . $result['url']);
    }
    if (null === $result) {
        debugMessage(sprintf('Create new milestone "%s"', $expectedKey));
        // create milestone
        $url          = sprintf('https://api.github.com/repos/%s/milestones', $repository);
        $client       = new Client;
        $info         = [
            // {"title":"v1.0","state":"open","description":"Tracking milestone for version 1.0","due_on":"2012-10-09T23:39:01Z"}
            'title'       => $expectedKey,
            'state'       => 'open',
            'description' => sprintf('Automatically generated milestone to track %s version v%s', $title, $version),
        ];
        $opts['json'] = $info;
        try {
            $res = $client->post($url, $opts);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body     = (string)$response->getBody();
            echo $response->getStatusCode();
            echo PHP_EOL;
            echo $body;
            exit;
        }
    }
    return $expectedKey;
}

/**
 * @param string $url
 *
 * @return array|null
 */
function lastCommit(string $url): ?array
{
    debugMessage(sprintf('Collect last commit information for "%s"', $url));
    $client = new Client;

    $hash = hash('sha256', $url);

    if (hasCache($hash)) {
        $result = getCache($hash);
        debugMessage(sprintf('Last commit was on %s by %s (cached).', $result['last_commit_date'], $result['last_commit_author']));
        return $result;
    }

    $opts = [
        'headers' => [
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'Firefly III roadmap script/1.0',
            'Authorization' => sprintf('Bearer %s', getenv('GH_TOKEN')),
        ],
    ];
    try {
        $res = $client->get($url, $opts);
    } catch (ClientException $e) {
        $body = (string)$e->getRequest()->getBody();
        echo $body;
        echo $body;
        echo 'Got client exception when requesting data from GitHub.' . PHP_EOL;
        echo PHP_EOL;
        echo $e->getMessage();
        return null;
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
    debugMessage(sprintf('Last commit was on %s by %s.', $result['last_commit_date'], $result['last_commit_author']));
    saveCache($hash, json_encode($result));
    return $result;
}

/**
 * @param string $string
 *
 * @return void
 */
function debugMessage(string $string): void
{
    echo sprintf("%s\n", $string);
}

/**
 * I think there is a better way to do this but OK.
 *
 * @param array $left
 * @param array $right
 *
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
