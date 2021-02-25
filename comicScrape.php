#!/usr/bin/php
<?php

require_once __DIR__.'/vendor/autoload.php';

use Cocur\Slugify\Slugify;

// Set up options (run with '-h' to get this all as nice help text)
$getopt = new \GetOpt\GetOpt([
    \GetOpt\Option::create('t', 'title', \GetOpt\GetOpt::MULTIPLE_ARGUMENT)
    	->setDescription('Provide one or more titles to run instead of using the configured titles file. Can appear more than once (I.e. -t "Walking Dead" -t "Saga")'),
    \GetOpt\Option::create('s', 'silent')
    	->setDescription('Suppress all command line output.'),
    \GetOpt\Option::create('c', 'config', \GetOpt\GetOpt::REQUIRED_ARGUMENT)
    	->setDescription('Provide filename of an alternate config file.'),
    \GetOpt\Option::create('m', 'mail', \GetOpt\GetOpt::REQUIRED_ARGUMENT)
    	->setDescription('Email command line output to an address.'),
    \GetOpt\Option::create('h', 'help', \GetOpt\GetOpt::NO_ARGUMENT)
    	->setDescription('This help text.'),
]);
$getopt->process();
if ($getopt->getOption('help')) {
    echo $getopt->getHelpText();
    exit;
}

// get my file path (distinct from the user's cwd)
list($scriptPath) = get_included_files();
preg_match("/(.*\/)[^\/]+/", $scriptPath, $matches);
$filepath = $matches[1];


// configure ourselves with the command line options
$silent = $getopt->getOption('silent') ? true : false;
$configfile = $getopt->getOption('config') ? $getopt->getOption('config') : 'scrapeConfig.json';
$configfilepath = $filepath . $configfile;
if (! is_readable($configfilepath) ) {
	echo "ERROR: Config file '$configfile' is not readable.\n";
	exit(0);
}
$config = json_decode(file_get_contents($configfilepath));


// Get our titles, from CLI or from file
$titles = $getopt->getOption('title');
if (count($titles) == 0) {
	$titles = file($filepath . '/' . $config->titlesFile);
}

// anti-explosion config, for when we download issues
ini_set('memory_limit', '10G');
//configure some ansi color globals:
$bold="\033[1m";
$regular="\033[0m";

$savedIssues = [];
foreach ($titles as $title) { //Go through titles from file

	// handle case where files have to be searched with different strings
	// than the download filename ("Once & Future" has to be searched "Once and Future")
	$searchterm = rtrim($title);
	if ( stristr($title, '|')) {
		[$searchterm, $filename] = explode('|', $title);
		$title = $filename ? $filename : $searchterm;
	}

	//Clean up filename, make directory if not exist
	$title = rtrim($title);
	$targetdir = $config->comicDirectory . '/' . $title;
	if (!file_exists($targetdir)) {
	    mkdir($targetdir, 0777, true);
	}

	//Look in the directory for existing files, find the highest-numbered one.
	$files = scandir($targetdir);
	
	$latestIssueOwned = 0;
	foreach (array_reverse($files) as $file) {
		if (preg_match("/$title\s+#?v?(\d+)/i", $file, $matches)) {
			$foundIssue = (Int)$matches[1];
			$latestIssueOwned = $foundIssue > $latestIssueOwned ? $foundIssue : $latestIssueOwned;
		}
	}
	if (!$silent) echo "$bold$title$regular - last issue saved is $latestIssueOwned\n";
	$wanted = $latestIssueOwned + 1;


	//collect up our search results page contents
	$pages = '';
	$maxpages = $config->maxPageDepth;
	if (!$silent) echo " Getting search page ";
	if ($config->onlyFirstPageForExistingSeries && $wanted != 1 ) {
		$maxpages = 1;
	}
	for ($i=1; $i <= $maxpages ; $i++) { 
		//Get whatever page we're on
		$pagebase = $config->siteUrl . "/page/$i";
		$searchurl = $pagebase . '/' . $config->queryFormat . urlencode($searchterm) ;
		if (!$silent) echo "$i... ";
		$pages  .= @file_get_contents($searchurl); 
			// Fun fact! "@" in front of this command suppresses errors. It's entirely
			// valid that one of our searches might not have a second or third page, and
			// we don't need to bug the user about that.

	}
	if (!$silent) echo "\n";

	while(true) {
		// Clean up our inputs and define the issue slug we're going to searh for.
		$slugify = new Slugify();
		$titleSlug = $slugify->slugify($searchterm);
		$targeturl = $config->siteUrl . '/[^/]+/' . $titleSlug . '-' . $wanted . '[^\d"]+';
		$targeturl = str_replace('/', '\/', $targeturl);


		if (preg_match("/$targeturl/", $pages, $matches)) { //Found it
			$issueurl = $matches[0];

			// Get the issue detail page
			$issuepage = file_get_contents($issueurl);
#			file_put_contents("issue.txt", $issuepage);

			// Search that page for the download link
			$targettag = '/href="([^"]+)"[^>]+title="Download Now"/';
			preg_match($targettag, $issuepage, $matches);
			$issueURL = $matches[1];
		
			// If we didn't find our target url, break this loop and try the next page.	
			if (! $issueURL) {
				continue;
			}

			// Set up the download with good referer value (anti-antiscraping measure)
			$referer = $issueurl;
			$opts = array(
			       'http'=>array(
			           'header'=>array("Referer: $referer\r\n", "User-agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.88 Safari/537.36\r\n")
			       )
			);
			$context = stream_context_create($opts);


			if (!$silent) echo "  Found issue $wanted. Downloading... ";

			//Get the actual thing and save it.
			$issuebinary = file_get_contents($issueURL, false, $context);

			//Figure out where to save it (get filename from headers, or fall back to comicvine details)
			$filename = false;
			$filename = get_real_filename($http_response_header, $issueURL);
			if (!$filename) {
				// Getting the filename from the HTTP response header failed, so hit comicvine for details about this issue
				$filename = build_filename_from_comicvine(urlencode($searchterm . " " . $wanted), $config);

			}
			if (!$silent) echo "  Done. Filename: $filename\n";
			$fullpath = $config->comicDirectory . '/' . $title . '/' . $filename;
			$savedIssues[] = $fullpath;

			file_put_contents($fullpath, $issuebinary);

			// Set up flags and switches for next loop
			$wanted++;
			continue;
		} // end Found It 
		break; //We fell through here without finding a "next" issue, so bail.
	} //end while true
} //end foreach title

$summary = "Run Complete for config $configfile. \n" . count($savedIssues) . " downloaded issues.\n";
foreach ($savedIssues as $issuepath) {
	$summary .= " $issuepath\n";
}
if (!$silent) echo "\n------\n\n" . $summary;


if ($getopt->getOption('mail') ) {
	mail($getopt->getOption('mail'), "comicScrape results", $summary);
}


function get_real_filename($headers,$url)
{
    foreach($headers as $header)
    {
        if (strpos(strtolower($header),'location') !== false)
        {
            preg_match('/\/([^\/]+)$/', urldecode($header), $matches);
            return $matches[1];
        }
    }
}

function build_filename_from_comicvine($comicvine_search, $config) {
	$cvdoc = file_get_contents("https://comicvine.gamespot.com/api/search/?api_key=$config->ComicvineKey&format=json&sort=name:asc&resources=issue&query=" . urlencode($comicvine_search));
	$cvdata = json_decode($cvdoc);
	$issue_title = $cvdata->results[0]->name;
	if ($issue_title) {
		$issue_title = " - $issue_title";
	}
	$issue_date = $cvdata->results[0]->cover_date;
	$issue_date = substr($issue_date, 0, 4);
	$issue_date = " ($issue_date)";
	$filename = $cvdata->results[0]->volume->name . " " . sprintf("%03d", $cvdata->results[0]->issue_number) . $issue_title . $issue_date . '.cbz';
	return $filename;
}
