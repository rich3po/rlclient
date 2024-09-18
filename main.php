<?php

require_once 'vendor/autoload.php';

(new RLClient())->run();

class RLClient
{
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
            //$this->deleteRuleset($rl_ruleset_id, $zone_id);
        }
    }

    function getAllZones() : array
    {
        // rich-jones.net
        return ['5d003482c28b729c3aceb518f25befcb'];

        // @TODO.
    }

    function createRateLimits($zone_id, $ruleset_id)
    {
    }

    function getRlRulesetId(String $zone_id) : int
    {
        $res = $this->client->request('GET', "/client/v4/zones/$zone_id/rulesets", [
            'headers' => $this->auth_headers,
        ]);

        $existing_rulsets = json_decode($res->getBody()->getContents())->result;
        print_r($existing_rulsets);

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
}
