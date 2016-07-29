<?php

require_once __DIR__ . '/vendor/autoload.php';

use League\CLImate\CLImate;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;
use GuzzleHttp\Client;

$cli = new CLImate;
$fsAdapter = new Local(__DIR__.'/data');
$fs = new Filesystem($fsAdapter);

/**
 * Extracts speakers from raw schedule data dump of C-SPAN's convention schedule JSON text file.
 * http://www.c-span.org/convention/?party=rnc
 * http://www.c-span.org/convention/?party=dnc
 */
function extractSpeakersFromRawSchedule($fs, $cli, $rawScheduleFileName, $speakersFileName)
{
  // Confirm raw schedule file exists
  if ( ! $fs->has($rawScheduleFileName)) {
    echo "Raw schedule file name does not exist: {$rawScheduleFileName}\n";
    return;
  }

  // Delete existing speakers file first
  if ($fs->has($speakersFileName)) {
    $fs->delete($speakersFileName);
  }

  $scheduleJson = $fs->read($rawScheduleFileName);
  $schedule = json_decode($scheduleJson);

  $speakers = [];
  $dayNum = 18;
  foreach ($schedule as $day) {
    foreach ($day->items as $speaker) {
      $speakers[] = [
        'name' => $speaker->title,
        'date' => '2016-07-' . $dayNum,
        'time' => $speaker->time,
        'link' => $speaker->link,
        'image' => array_shift((explode('/Thumbs/', $speaker->image))),
        'slug' => array_pop((explode('/', $speaker->link)))
      ];
    }
    $dayNum++;
  }

  $fs->put($speakersFileName, json_encode($speakers));

  echo "Speakers extracted from schedule.\n";
}
// extractSpeakersFromRawSchedule($fs, $cli, 'dnc-raw-schedule.txt', 'dnc-speakers.txt');

//////////////////////////////////////////////////////////////////////////////////

/**
 * Downloads images of speakers.
 */
function downloadImagesFromSpeakersFile($fs, $cli, $speakersFileName)
{
  $cli->clear()->br()->white()->bold()->out('Downloading Speaker Images')->border()->br(2);

  $speakers = $fs->read($speakersFileName);
  $speakers = array_values(json_decode($speakers));

  $totalSpeakers = count($speakers);
  $progressBar = $cli->progress()->total($totalSpeakers);

  foreach ($speakers as $key => $speaker) {
    $imageFileName = $speaker->slug . '.jpg';

    // skip if image has been downloaded
    if ($fs->has("/images/{$imageFileName}")) { 
      $progressBar->current($key, "<cyan>EXISTS  {$imageFileName}</cyan>");
      usleep(200000);
    } else {
      // attempt to download the image
      try {
        $client = new Client;
        $client->request('get', $speaker->image, [
          'sink' => __DIR__ . "/data/images/{$imageFileName}"
        ]);
        $progressBar->current($key, "<green>OK      {$imageFileName}</green>");
        usleep(rand(750000, 2500000));
      } catch (Exception $e) {
        // delete the file, show error, and prompt user to continue
        $fs->delete("/images/{$imageFileName}");
        $progressBar->current($key, "<red>ERROR   {$imageFileName}</red>");
        $cli->br()->yellow($e->getMessage());
        $continue = $cli->confirm('Continue?');
        if ( ! $continue->confirmed()) {
          $cli->yellow('Exiting...')->br();
          exit;
        }
      }
    }
  }

  $cli->green("Finished!")->br();
}
// downloadImagesFromSpeakersFile($fs, $cli, 'dnc-speakers.txt');

//////////////////////////////////////////////////////////////////////////////////

/**
 * Downloads raw transcripts of speakers.
 */
function downloadSpeakerTranscripts($fs, $cli, $speakersFileName)
{
  $cli->clear()->br()->white()->bold()->out('Downloading Raw Speaker Transcripts')->border()->br(2);

  $speakers = $fs->read($speakersFileName);
  $speakers = array_values(json_decode($speakers));

  $totalSpeakers = count($speakers);
  $progressBar = $cli->progress()->total($totalSpeakers);

  foreach ($speakers as $key => $speaker) {
    $transcriptFileName = $speaker->slug . '.htm';

    // skip if transcript has been downloaded
    if ($fs->has("/dnc-raw-transcripts/{$transcriptFileName}")) { 
      $progressBar->current($key, "<cyan>EXISTS  {$transcriptFileName}</cyan>");
      usleep(200000);
    } else {
      // attempt to download the transcript
      try {
        $client = new Client;
        $transcriptUrl = "http://www.c-span.org/{$speaker->link}&action=getTranscript&transcriptType=cc&transcriptSpeaker=&transcriptQuery=";
        $client->request('get', $transcriptUrl, [
          'sink' => __DIR__ . "/data/dnc-raw-transcripts/{$transcriptFileName}"
        ]);
        $progressBar->current($key, "<green>OK      {$transcriptFileName}</green>");
        usleep(rand(750000, 2500000));
      } catch (Exception $e) {
        // delete the file, show error, and prompt user to continue
        $fs->delete("/dnc-raw-transcripts/{$transcriptFileName}");
        $progressBar->current($key, "<red>ERROR   {$transcriptFileName}</red>");
        $cli->br()->yellow($e->getMessage());
        $continue = $cli->confirm('Continue?');
        if ( ! $continue->confirmed()) {
          $cli->yellow('Exiting...')->br();
          exit;
        }
      }
    }
  }
}
// downloadSpeakerTranscripts($fs, $cli, 'dnc-speakers.txt');

//////////////////////////////////////////////////////////////////////////////////

/**
 * Consolidate all the words into a single array and allow for user queries.
 */
function analyzeWordUsage($fs, $cli)
{
  $cli->clear()->br()->white()->bold()->out('Analyzing Word Usage')->border()->br(2);

  $rawTranscriptFiles = glob(__DIR__ . '/data/rnc-raw-transcripts/*.htm');

  $toStrip = [
    '>>', '  ', '.', ',', '?', ':', ';', '[CHEERG]', '--', '[PPLUSE]'
  ];

  $words = [];

  foreach ($rawTranscriptFiles as $transcriptFile) {
    $rawTranscript = $fs->read('/rnc-raw-transcripts/' . basename($transcriptFile));
    $rawTranscript = array_shift((explode('<script', $rawTranscript)));
    $rawTranscript = strip_tags(trim($rawTranscript));
    $rawTranscript = str_replace("\n", ' ', $rawTranscript);
    $rawTranscript = str_replace($toStrip, '', $rawTranscript);
    $rawWords = explode(' ', $rawTranscript);
    $rawWords = array_filter($rawWords, function($val) {
      return ($val != false && strtoupper($val) === $val && ctype_digit($val) == false);
    });

    foreach ($rawWords as $word) {
      array_key_exists($word, $words) ? $words[$word]++ : $words[$word] = 1;
    }
  }

  // asort($words);

  while (true) {
    $query = $cli->input('Search for word, or: "/exit", "/sortalpha", "/sortquant", "/all"]');
    $response = strtoupper($query->prompt());

    if ($response == '/EXIT') {
      $cli->yellow('Seeya!')->br();
      exit;
    } elseif ($response == '/SORTALPHA') {
      ksort($words);
      $cli->green('Sorted words in alphabetical order. Type ".all" to view the list.')->br();
    } elseif ($response == '/SORTQUANT') {
      asort($words);
      $cli->green('Sorted words by frequency of use. Type ".all" to view the list.')->br();      
    } elseif ($response == '/ALL') {
      $formatted = [];
      foreach ($words as $word => $quantity) {
        $formatted[] = [$word, $quantity];
      }
      $cli->table($formatted)->br();      
    } elseif (array_key_exists($response, $words)) {
      $cli->green("\"{$response}\" appears {$words[$response]} times.")->br();
    } elseif ($response != '') {
      $cli->red("\"{$response}\" is either misspelled in the data, stripped, or wasn't said.")->br();
    }
  }

}
// analyzeWordUsage($fs, $cli);
