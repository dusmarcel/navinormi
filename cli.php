<?php

use dokuwiki\Extension\CLIPlugin;
use splitbrain\phpcli\Options;

/**
 * DokuWiki Plugin navinormi (CLI Component)
 *
 * php bin/plugin.php navinormi status
 * php bin/plugin.php navinormi refresh [unit]
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author Marcel Keienborg
 */
class cli_plugin_navinormi extends CLIPlugin
{
    /** seconds between two requests to the same host (dejure.org answers 429 to quick series) */
    const HOST_DELAY = 20;

    /** @inheritDoc */
    protected function setup(Options $options)
    {
        $options->setHelp('Index of other websites carrying the same provisions (normally built in the background).');
        $options->registerCommand('status', 'Lists each website/regulation with the time of the last request and the number of provisions found.');
        $options->registerCommand('refresh', 'Fetches all units now, or only the given one.');
        $options->registerArgument('unit', 'Unit as listed by "status", e.g. "dejure:AufenthG" or "nano".', false, 'refresh');
    }

    /** @inheritDoc */
    protected function main(Options $options)
    {
        /** @var helper_plugin_navinormi_external $external */
        $external = plugin_load('helper', 'navinormi_external');
        $units = $external->units();
        if ($units === []) {
            $this->error('The normi plugin (with EXTERNAL_IDS) is missing or disabled.');
            return 1;
        }

        switch ($options->getCmd()) {
            case 'status':
                foreach (array_keys($units) as $unit) {
                    $this->printStatus($unit, $external->load($unit));
                }
                return 0;

            case 'refresh':
                $selected = $options->getArgs() ?: array_keys($units);
                $lastByHost = [];
                $failed = 0;
                foreach ($selected as $unit) {
                    if (!isset($units[$unit])) {
                        $this->error('Unknown unit ' . $unit);
                        $failed++;
                        continue;
                    }
                    $host = parse_url($units[$unit][1], PHP_URL_HOST);
                    $wait = ($lastByHost[$host] ?? 0) + self::HOST_DELAY - time();
                    if ($wait > 0) {
                        sleep($wait);
                    }
                    $error = $external->refresh($unit);
                    $lastByHost[$host] = time();
                    if ($error !== null) {
                        $this->error($unit . ': ' . $error);
                        $failed++;
                    } else {
                        $this->printStatus($unit, $external->load($unit));
                    }
                }
                return $failed > 0 ? 1 : 0;

            default:
                echo $options->help();
                return 0;
        }
    }

    private function printStatus(string $unit, array $state): void
    {
        $fetched = empty($state['fetched']) ? 'never' : date('Y-m-d H:i', $state['fetched']);
        if (isset($state['acts'])) {
            $found = count($state['acts']) . ' amended acts';
        } else {
            $found = [];
            foreach ($state['laws'] ?? [] as $lawId => $law) {
                $found[] = $lawId . ' ' . count($law['keys']);
            }
            $found = $found === [] ? 'nothing found' : implode(', ', $found);
        }
        $error = isset($state['error']) && ($state['tried'] ?? 0) > ($state['fetched'] ?? 0) ? ' – last try failed: ' . $state['error'] : '';
        echo str_pad($unit, 37) . ' ' . $fetched . '  ' . $found . $error . "\n";
    }
}
