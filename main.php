<?php

require_once 'vendor/autoload.php';

(new RLClient())->run();

class RLClient
{
    public const CF_ORG_NAME = 'Capgemini PageGroup';

    public $client;
    public $auth_headers;

    public function __construct()
    {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->load();

        $this->client = new GuzzleHttp\Client(['base_uri' => 'https://api.cloudflare.com']);
        $this->auth_headers = [
            'X-Auth-Email' => $_ENV['CF_AUTH_EMAIL'],
            'X-Auth-Key' => $_ENV['CF_AUTH_KEY'],
        ];
    }

    public function run()
    {
        foreach ($this->getAllZones() as $zone_id) {
            $ruleset_id = $this->getRlRulesetId($zone_id);
            $this->createRateLimits($zone_id, $ruleset_id);
            //$this->deleteRuleset($ruleset_id, $zone_id);
        }
    }

    /**
     * Return array of all Zone IDs on the account.
     *
     * @return string[]
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    function getAllZones() : array
    {
        // Fetch all zones on the account.
        $res = $this->client->request('GET', "/client/v4/zones", [
            'headers' => $this->auth_headers,
            'query' =>  [
                'account.name' => self::CF_ORG_NAME,
            ]
        ]);

        $results_decoded = json_decode($res->getBody()->getContents());
        $results = $results_decoded->result;
        $result_info = $results_decoded->result_info;
        //print_r($result_info);
        //print_r($results);

        $zone_ids = [];

        foreach ($results as $result) {
            $zone_ids[] = $result->id;
        }

        // API results are paginated...
        for ($i = 2; $i <= $result_info->total_pages; $i++) {

            $res = $this->client->request('GET', "/client/v4/zones", [
                'headers' => $this->auth_headers,
                'query' =>  [
                    'account.name' => self::CF_ORG_NAME,
                    'page' => $i,
                ]
            ]);
            $results = json_decode($res->getBody()->getContents())->result;

            foreach ($results as $result) {
                $zone_ids[] = $result->id;
            }
        }

        //print_r($zone_ids);
        //return $zone_ids;

        // For dev purposes: return michaelpage.co.nz
        return ['be18ac244887f509cc3173d1628d8b56'];
    }

    /**
     * Create new Rate Limit rules, given Zone + Ruleset.
     *
     * @param $zone_id
     * @param $ruleset_id
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    function createRateLimits($zone_id, $ruleset_id)
    {
        foreach ($this->getRuleDefinitions() as $rule_definition) {

            $res = $this->client->request('POST', "/client/v4/zones/$zone_id/rulesets/$ruleset_id/rules", [
                'headers' => $this->auth_headers,
                'json' => $rule_definition,
            ]);
            echo PHP_EOL . "Created rule: {$rule_definition['description']}, response code: {$res->getStatusCode()}";
        }
    }

    /**
     * Grabs the RuleSet ID, which serves as a container for Rate Limiting rules.
     * If it does not exist, create it.
     *
     * @param String $zone_id
     * @return String
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    function getRlRulesetId(String $zone_id) : String
    {
        $res = $this->client->request('GET', "/client/v4/zones/$zone_id/rulesets", [
            'headers' => $this->auth_headers,
        ]);

        $existing_rulsets = json_decode($res->getBody()->getContents())->result;
        //print_r($existing_rulsets);

        $rl_ruleset_id = '';
        foreach ($existing_rulsets as $existing_rulset) {
            if ($existing_rulset->kind == 'zone' && $existing_rulset->phase == 'http_ratelimit') {
                $rl_ruleset_id = $existing_rulset->id;
                break;
            }
        }

        if (empty($rl_ruleset_id)) {
            echo PHP_EOL . "No RL ruleset found, creating...";
            $res = $this->client->request('POST', "/client/v4/zones/$zone_id/rulesets", [
                'headers' => $this->auth_headers,
                'json' => [
                    "name" => "Rate Limiting ruleset",
                    "kind" => "zone",
                    "phase" => "http_ratelimit",
                ]
            ]);
            echo $res->getStatusCode();
            //var_dump(json_decode($res->getBody()->getContents())->result->id);
            $rl_ruleset_id = json_decode($res->getBody()->getContents())->result->id;
            echo PHP_EOL . "Created ruleset with ID: " . $rl_ruleset_id;
        } else {
            //var_dump($rl_ruleset_id);
            echo PHP_EOL . "Ruleset found with ID: " . $rl_ruleset_id;
        }

        return $rl_ruleset_id;
    }

    /**
     * @param String $rl_ruleset_id
     * @param String $zone_id
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * For debug purposes...
     */
    function deleteRuleset(String $rl_ruleset_id, String $zone_id)
    {
        echo PHP_EOL . "Deleting ruleset...";
        $res = $this->client->request('DELETE', "/client/v4/zones/$zone_id/rulesets/$rl_ruleset_id", [
            'headers' => $this->auth_headers,
        ]);
        echo $res->getStatusCode();
    }

    /**
     * Rate Limit rule definitions.
     *
     * @return array[]
     */
    function getRuleDefinitions()
    {
        return [
            [
                "description" => "Test rule",
                "expression" => "(http.request.uri.path wildcard \"/fake-path/*\" and http.request.method eq \"POST\")",
                "action" => "managed_challenge",
                "ratelimit" => (object) [
                    "characteristics" => [
                        "ip.src",
                        "cf.colo.id"
                    ],
                    "period" => 60,
                    "requests_per_period" => 50,
                    "mitigation_timeout" => 0
                ],
                "enabled" => false
            ],
            [
                "description" => "Test rule2",
                "expression" => "(http.request.uri.path wildcard \"/fake-path2/*\" and http.request.method eq \"GET\")",
                "action" => "managed_challenge",
                "ratelimit" => (object) [
                    "characteristics" => [
                        "ip.src",
                        "cf.colo.id"
                    ],
                    "period" => 600,
                    "requests_per_period" => 50,
                    "mitigation_timeout" => 0
                ],
                "enabled" => false
            ],
        ];
    }
}
