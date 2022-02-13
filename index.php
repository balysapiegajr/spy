<?php
require 'vendor/autoload.php';

// Environment
$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// SleekDB configuration
$databaseDirectory = __DIR__ . '/db';
$configuration = [
    'timeout' => false,
];
$targetStore = new \SleekDB\Store('targets', $databaseDirectory, $configuration);
$visitStore = new \SleekDB\Store('visits', $databaseDirectory, $configuration);

// HTML diff configuration
$rendererName = 'Unified';
$rendererOptions = [
    'lineNumbers' => false,
];

// Telegram configuration
$botToken = $_ENV['TELEGRAM_BOT_TOKEN'];
$chatId = intval($_ENV['TELEGRAM_CHAT_ID']);
$maxMessageLength = 1000;
$sendMessageUrl = 'https://api.telegram.org/bot'.$botToken.'/';

$targets = $targetStore->findAll();
if (!empty($targets)) {
    // Show target list
    foreach($targets as $target) {
        echo '<p><b>'.$target['_id'].'</b> <a href="'.$target['url'].'" target="_blank">'.$target['url'].'</a> '.$target['selector'].'</p>';
    }

    // It's time to visit our targets
    if(isset($_GET['visit'])) {
        $currentTimestamp = (new \DateTime())->getTimestamp();
        $goutteClient = new \Goutte\Client();
        $builder = new \SebastianBergmann\Diff\Output\DiffOnlyOutputBuilder('');
        $differ = new \SebastianBergmann\Diff\Differ($builder);
        $guzzleClient = new \GuzzleHttp\Client(['base_uri' => $sendMessageUrl, 'verify' => false,]);

        foreach($targets as $target) {
            $crawler = $goutteClient->request('GET', $target['url']);
            $total = $crawler->filter($target['selector'])->count();

            // Selector success
            if ($total === 1) {
                $html = $crawler->filter($target['selector'])->first()->html();
                $oldVisit = $visitStore->findById($target['_id']);

                // Already visited
                if ($oldVisit) {

                    // Check html for changes
                    $diff = $differ->diff($oldVisit['html'], $html);

                    // Same same
                    if (!$diff) {
                        $visitStore->updateById($target['_id'], ['updatedAt' => $currentTimestamp,]);
                    }

                    // Same same but different
                    else {
                        $visitStore->updateById($target['_id'], ['html' => $html, 'updatedAt' => $currentTimestamp,]);
                        // Send telegram
                        if (strlen($diff) > $maxMessageLength) {
                            $diff = trim(substr($diff, 0, $maxMessageLength)).'...';
                        }
                        $diff = '<pre>'.htmlentities($diff).'</pre>';
                        $message = [
                            'chat_id' => $chatId,
                            'disable_web_page_preview' => true,
                            'text' => 'Changes detected: '.$target['url'],
                        ];
                        $guzzleClient->post('sendMessage', ['json' => $message,]);
                        $message = [
                            'chat_id' => $chatId,
                            'parse_mode' => 'html',
                            'text' => $diff,
                        ];
                        $guzzleClient->post('sendMessage', ['json' => $message,]);
                    } 
                }

                // First visit
                else {
                    $visit = [
                        'targetId' => $target['_id'],
                        'html' => $html,
                        'createdAt' => $currentTimestamp,
                        'updatedAt' => null,
                    ];
                    $visitStore->insert($visit);
                }
            }

            // Selector failure
            else {
                $message = [
                    'chat_id' => $chatId,
                    'disable_web_page_preview' => true,
                    'text' => 'Selector failure: '.$target['url'],
                ];
                $guzzleClient->post('sendMessage', ['json' => $message,]);
            }
        }
    }
}